"""Minimal authenticated WordPress REST client with no credential persistence."""

from __future__ import annotations

import base64
import ipaddress
import json
import mimetypes
import os
import re
import urllib.error
import urllib.parse
import urllib.request
import uuid
from pathlib import Path
from typing import Any, Dict, Optional, Tuple


class HttpError(RuntimeError):
    pass


def _origin(url: str) -> Tuple[str, str, int]:
    parsed = urllib.parse.urlparse(url)
    scheme = parsed.scheme.lower()
    host = (parsed.hostname or "").lower().rstrip(".")
    try:
        port = parsed.port
    except ValueError as exc:
        raise HttpError("WordPress URL contains an invalid port.") from exc
    return scheme, host, port or (443 if scheme == "https" else 80)


def _is_loopback(host: str) -> bool:
    normalized = host.lower().rstrip(".")
    if normalized == "localhost" or normalized.endswith(".localhost"):
        return True
    try:
        return ipaddress.ip_address(normalized).is_loopback
    except ValueError:
        return False


class SameOriginRedirectHandler(urllib.request.HTTPRedirectHandler):
    """Reject redirects that could move an authenticated request off origin."""

    def __init__(self, allowed_origin: Tuple[str, str, int]):
        super().__init__()
        self.allowed_origin = allowed_origin

    def redirect_request(self, req: urllib.request.Request, fp: Any, code: int, msg: str, headers: Any, newurl: str) -> Optional[urllib.request.Request]:
        target = urllib.parse.urljoin(req.full_url, newurl)
        if _origin(target) != self.allowed_origin:
            raise HttpError("WordPress REST request refused a cross-origin redirect.")
        return super().redirect_request(req, fp, code, msg, headers, target)


def _auth_header() -> Optional[str]:
    explicit = os.environ.get("CONTENT_FACTORY_AUTHORIZATION", "").strip()
    if explicit:
        return explicit
    user = os.environ.get("CONTENT_FACTORY_USER", os.environ.get("WP_USER", ""))
    password = os.environ.get("CONTENT_FACTORY_APP_PASSWORD", os.environ.get("WP_APP_PASSWORD", ""))
    if bool(user) != bool(password):
        raise HttpError("Both Content Factory username and Application Password environment variables are required.")
    if not user:
        return None
    token = base64.b64encode((user + ":" + password).encode("utf-8")).decode("ascii")
    return "Basic " + token


class WordPressClient:
    def __init__(self, wordpress_url: str, timeout: float = 30.0):
        parsed = urllib.parse.urlparse(wordpress_url)
        if parsed.scheme not in ("http", "https") or not parsed.netloc:
            raise HttpError("--wordpress-url must be an absolute http(s) URL.")
        if parsed.username or parsed.password:
            raise HttpError("Credentials must not be embedded in --wordpress-url; use environment variables.")
        if parsed.query or parsed.fragment:
            raise HttpError("--wordpress-url must not contain a query string or fragment.")
        host = (parsed.hostname or "").lower().rstrip(".")
        if parsed.scheme == "http" and not _is_loopback(host):
            raise HttpError("Plain HTTP is allowed only for loopback WordPress URLs; use HTTPS for remote hosts.")
        self.base_url = wordpress_url.rstrip("/") + "/"
        self.origin = _origin(self.base_url)
        self.timeout = timeout
        self.authorization = _auth_header()
        self._rest_root: Optional[str] = None
        self._opener = urllib.request.build_opener(SameOriginRedirectHandler(self.origin))

    def _same_origin(self, url: str, source: str) -> str:
        parsed = urllib.parse.urlparse(url)
        if parsed.username or parsed.password:
            raise HttpError("%s must not contain embedded credentials." % source)
        if _origin(url) != self.origin:
            raise HttpError("%s must use the same scheme, host, and port as --wordpress-url." % source)
        return url

    def _request(self, request: urllib.request.Request) -> Tuple[bytes, Any]:
        self._same_origin(request.full_url, "WordPress REST request")
        request.add_header("Accept", "application/json")
        request.add_header("User-Agent", "content-factory-pagespec-converter/1")
        if self.authorization:
            request.add_header("Authorization", self.authorization)
        try:
            with self._opener.open(request, timeout=self.timeout) as response:
                return response.read(), response.headers
        except urllib.error.HTTPError as exc:
            body = exc.read().decode("utf-8", errors="replace")[:1000]
            raise HttpError("WordPress REST request failed with HTTP %d: %s" % (exc.code, body)) from exc
        except urllib.error.URLError as exc:
            raise HttpError("WordPress REST request failed: %s" % exc.reason) from exc

    def _discover_rest_root(self) -> str:
        if self._rest_root:
            return self._rest_root
        body, headers = self._request(urllib.request.Request(self.base_url, method="GET"))
        link_values = headers.get_all("Link", []) if hasattr(headers, "get_all") else []
        for value in link_values:
            match = re.search(r'<([^>]+)>\s*;\s*rel=["\']https://api\.w\.org/["\']', value, re.I)
            if match:
                self._rest_root = self._same_origin(
                    urllib.parse.urljoin(self.base_url, match.group(1)),
                    "Discovered WordPress REST root",
                )
                return self._rest_root
        html = body.decode("utf-8", errors="replace")
        match = re.search(r'<link[^>]+rel=["\']https://api\.w\.org/["\'][^>]+href=["\']([^"\']+)', html, re.I)
        if not match:
            match = re.search(r'<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']https://api\.w\.org/["\']', html, re.I)
        if match:
            self._rest_root = self._same_origin(
                urllib.parse.urljoin(self.base_url, match.group(1).replace("&amp;", "&")),
                "Discovered WordPress REST root",
            )
        else:
            self._rest_root = urllib.parse.urljoin(self.base_url, "index.php?rest_route=/")
        return self._rest_root

    def endpoint(self, route: str, query: Optional[Dict[str, str]] = None) -> str:
        root = self._discover_rest_root()
        parsed = urllib.parse.urlparse(root)
        params = dict(urllib.parse.parse_qsl(parsed.query, keep_blank_values=True))
        if "rest_route" in params:
            params["rest_route"] = "/" + route.strip("/")
            if query:
                params.update(query)
            return urllib.parse.urlunparse(parsed._replace(query=urllib.parse.urlencode(params)))
        url = urllib.parse.urljoin(root.rstrip("/") + "/", route.strip("/"))
        if query:
            url += "?" + urllib.parse.urlencode(query)
        return url

    def fetch_contract(self, site_key: str, profile_id: str) -> Tuple[Dict[str, Any], str]:
        query = {"siteKey": site_key, "profileId": profile_id}
        data, headers = self._request(urllib.request.Request(self.endpoint("content-factory/v1/contract", query), method="GET"))
        try:
            value = json.loads(data.decode("utf-8"))
        except (UnicodeError, json.JSONDecodeError) as exc:
            raise HttpError("Contract endpoint did not return UTF-8 JSON.") from exc
        if not isinstance(value, dict):
            raise HttpError("Contract endpoint did not return a JSON object.")
        return value, str(headers.get("ETag", ""))

    @staticmethod
    def _multipart(zip_path: Path, fields: Dict[str, str]) -> Tuple[bytes, str]:
        boundary = "----ContentFactory" + uuid.uuid4().hex
        chunks = []
        for name in sorted(fields):
            chunks.append(("--%s\r\nContent-Disposition: form-data; name=\"%s\"\r\n\r\n%s\r\n" % (boundary, name, fields[name])).encode("utf-8"))
        filename = zip_path.name.replace('"', "")
        content_type = mimetypes.guess_type(filename)[0] or "application/zip"
        chunks.append(("--%s\r\nContent-Disposition: form-data; name=\"file\"; filename=\"%s\"\r\nContent-Type: %s\r\n\r\n" % (boundary, filename, content_type)).encode("utf-8"))
        chunks.append(zip_path.read_bytes())
        chunks.append(("\r\n--%s--\r\n" % boundary).encode("ascii"))
        return b"".join(chunks), "multipart/form-data; boundary=" + boundary

    def post_zip(self, route: str, zip_path: Path, fields: Optional[Dict[str, str]] = None, summary: bool = True) -> Dict[str, Any]:
        body, content_type = self._multipart(zip_path, fields or {})
        query = {"detail": "summary"} if summary else None
        request = urllib.request.Request(self.endpoint(route, query), data=body, method="POST")
        request.add_header("Content-Type", content_type)
        data, _ = self._request(request)
        try:
            value = json.loads(data.decode("utf-8"))
        except (UnicodeError, json.JSONDecodeError) as exc:
            raise HttpError("WordPress REST endpoint did not return UTF-8 JSON.") from exc
        if not isinstance(value, dict):
            raise HttpError("WordPress REST endpoint did not return a JSON object.")
        return value

    def validate_zip(self, zip_path: Path) -> Dict[str, Any]:
        return self.post_zip("content-factory/v1/validate", zip_path, fields={}, summary=True)

    def import_zip(self, zip_path: Path, validated_hash: str) -> Dict[str, Any]:
        return self.post_zip(
            "content-factory/v1/pages/batch",
            zip_path,
            fields={"confirmed": "true", "validatedHash": validated_hash},
            summary=True,
        )
