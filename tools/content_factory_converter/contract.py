"""Contract Bundle loading, integrity checks, and runtime-only projections."""

from __future__ import annotations

import hashlib
import json
import re
from pathlib import Path
from typing import Any, Dict, Optional
from urllib.parse import urlparse


class ContractError(ValueError):
    pass


SECRET_KEYS = {
    "password", "passwd", "cookie", "nonce", "applicationpassword", "token",
    "secret", "authorization", "credential", "privatekey", "privatepath",
    "apikey", "accesskey", "authkey", "accesstoken", "refreshtoken",
    "sessiontoken", "clientsecret", "webhooksecret", "bearertoken",
}

SUPPORTED_CONTRACT_VERSION = "1.0"
SUPPORTED_PAGE_SPEC_VERSION = "1.1"


def canonical_json(value: Any) -> bytes:
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")


def sha256_bytes(value: bytes) -> str:
    return "sha256:" + hashlib.sha256(value).hexdigest()


def contract_hash(bundle: Dict[str, Any]) -> str:
    payload = dict(bundle)
    payload.pop("contractHash", None)
    return sha256_bytes(canonical_json(payload))


def _unsafe_path(value: Any, path: str = "") -> Optional[str]:
    if isinstance(value, dict):
        for key, child in value.items():
            normalized = re.sub(r"[^a-z0-9]", "", str(key).lower())
            child_path = path + "/" + str(key).replace("~", "~0").replace("/", "~1")
            if (normalized in SECRET_KEYS or "password" in normalized or "secret" in normalized
                    or "credential" in normalized or normalized.endswith("token")
                    or normalized.endswith("apikey") or normalized.endswith("privatekey")):
                return child_path
            found = _unsafe_path(child, child_path)
            if found:
                return found
    elif isinstance(value, list):
        for index, child in enumerate(value):
            found = _unsafe_path(child, path + "/" + str(index))
            if found:
                return found
    elif isinstance(value, str):
        if re.search(r'(?:^|["\s])/(?:Users|home|private|var/www|srv)/', value, re.I):
            return path or "/"
        parsed = urlparse(value)
        if parsed.username or parsed.password:
            return path or "/"
    return None


def load_contract(path: Path) -> Dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        raise ContractError("Contract Bundle cannot be read as UTF-8 JSON: %s" % exc) from exc
    return validate_contract(value)


def validate_contract(bundle: Any, etag: str = "") -> Dict[str, Any]:
    if not isinstance(bundle, dict):
        raise ContractError("Contract Bundle must be a JSON object.")
    required = (
        "contractVersion", "contractHash", "pageSpecVersion", "identity",
        "pageSpecSchema", "semanticProfileSchema", "pageTypes", "siteDefaults",
        "assets", "policies", "examples", "conversionGuidance", "selfCheck",
    )
    missing = [key for key in required if key not in bundle]
    if missing:
        raise ContractError("Contract Bundle is incomplete: %s" % ", ".join(missing))
    if (bundle.get("contractVersion") != SUPPORTED_CONTRACT_VERSION
            or bundle.get("pageSpecVersion") != SUPPORTED_PAGE_SPEC_VERSION):
        raise ContractError(
            "Converter compatibility does not match the Contract Bundle versions advertised by the plugin."
        )
    actual_hash = contract_hash(bundle)
    if bundle.get("contractHash") != actual_hash:
        raise ContractError("Contract Bundle hash does not match its canonical contents.")
    if etag:
        normalized_etag = etag.strip()
        if normalized_etag[:2].upper() == "W/":
            normalized_etag = normalized_etag[2:].strip()
        if len(normalized_etag) >= 2 and normalized_etag[0] == '"' and normalized_etag[-1] == '"':
            normalized_etag = normalized_etag[1:-1]
        if normalized_etag != actual_hash:
            raise ContractError("Contract Bundle ETag does not match contractHash.")
    unsafe = _unsafe_path(bundle)
    if unsafe:
        raise ContractError("Contract Bundle contains a secret-like field or private path at %s." % unsafe)
    identity = bundle.get("identity")
    identity_fields = ("siteKey", "profileId", "profileVersion", "siteDefaultsVersion", "manifestHash")
    if not isinstance(identity, dict) or any(not isinstance(identity.get(key), str) or not identity[key] for key in identity_fields):
        raise ContractError("Contract Bundle identity is incomplete.")
    if re.fullmatch(r"sha256:[a-f0-9]{64}", identity["manifestHash"]) is None:
        raise ContractError("Contract Bundle manifestHash has an invalid format.")
    self_check = bundle.get("selfCheck")
    if not isinstance(self_check, dict) or self_check.get("status") not in ("compatible", "compatible_with_warnings", "incompatible"):
        raise ContractError("Contract Bundle selfCheck is missing or invalid.")
    issues = self_check.get("issues")
    if not isinstance(issues, list):
        raise ContractError("Contract Bundle selfCheck issues must be an array.")
    if self_check["status"] == "incompatible" or any(isinstance(item, dict) and item.get("severity") == "error" for item in issues):
        raise ContractError("Runtime Contract Bundle selfCheck is incompatible.")
    schema = bundle.get("pageSpecSchema")
    if not isinstance(schema, dict):
        raise ContractError("Contract Bundle pageSpecSchema must be an object.")
    version_schema = schema.get("properties", {}).get("schemaVersion", {}) if isinstance(schema.get("properties"), dict) else {}
    required_fields = schema.get("required", [])
    if (not isinstance(version_schema, dict)
            or version_schema.get("const") != bundle["pageSpecVersion"]
            or not isinstance(required_fields, list)
            or "schemaVersion" not in required_fields):
        raise ContractError("Advertised PageSpec schema does not enforce the plugin's current PageSpec version.")
    if not isinstance(bundle.get("pageTypes"), dict) or not bundle["pageTypes"]:
        raise ContractError("Contract Bundle has no page types.")
    if not isinstance(bundle.get("semanticProfileSchema"), dict):
        raise ContractError("Contract Bundle semanticProfileSchema must be an object.")
    if not isinstance(bundle.get("assets"), dict) or not isinstance(bundle.get("policies"), dict):
        raise ContractError("Contract Bundle assets and policies must be objects.")
    modal_path = bundle["policies"].get("modalTriggerPath")
    if modal_path is not None:
        parsed_modal_path = urlparse(modal_path) if isinstance(modal_path, str) else None
        if (not isinstance(modal_path, str) or not modal_path.startswith("/") or not modal_path.endswith("/")
                or parsed_modal_path.scheme or parsed_modal_path.netloc or parsed_modal_path.params
                or parsed_modal_path.query or parsed_modal_path.fragment):
            raise ContractError("Contract Bundle policies.modalTriggerPath must be a plain absolute path ending with '/'.")
    return bundle


def section_schemas(bundle: Dict[str, Any]) -> Dict[str, Dict[str, Any]]:
    properties = bundle["semanticProfileSchema"].get("properties", {})
    return {str(key): value for key, value in properties.items() if isinstance(value, dict)} if isinstance(properties, dict) else {}


def occurrence(bundle: Dict[str, Any], page_type: str, section_type: str) -> Dict[str, Optional[int]]:
    page = bundle["pageTypes"].get(page_type, {})
    occurrences = page.get("occurrences", {}) if isinstance(page, dict) else {}
    row = occurrences.get(section_type) if isinstance(occurrences, dict) else None
    if not isinstance(row, dict):
        return {"min": 0, "max": 0}
    maximum = row.get("max")
    return {
        "min": int(row.get("min", 0)),
        "max": int(maximum) if isinstance(maximum, int) else None,
    }


def item_limit(schema: Dict[str, Any], property_name: str, default: Optional[int] = None) -> Optional[int]:
    props = schema.get("properties", {})
    row = props.get(property_name, {}) if isinstance(props, dict) else {}
    value = row.get("maxItems") if isinstance(row, dict) else None
    return int(value) if isinstance(value, int) else default


def item_minimum(schema: Dict[str, Any], property_name: str, default: int = 0) -> int:
    props = schema.get("properties", {})
    row = props.get(property_name, {}) if isinstance(props, dict) else {}
    value = row.get("minItems") if isinstance(row, dict) else None
    return int(value) if isinstance(value, int) else default
