"""Command-line interface for safe Content Factory conversion and draft import."""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path
from typing import Optional, Sequence

from .contract import ContractError, load_contract, validate_contract
from .converter import ConversionError, convert
from .http import HttpError, WordPressClient


CONFIRM_PHRASE = "CREATE_OR_UPDATE_DRAFTS_ATOMICALLY"


def _validated_counts(payload: object, fields: Sequence[str], response_name: str) -> dict:
    if not isinstance(payload, dict):
        raise ConversionError("%s did not return a JSON object." % response_name)
    counts = payload.get("counts")
    if not isinstance(counts, dict):
        raise ConversionError("%s is missing the required counts object." % response_name)
    values = {}
    for field in fields:
        value = counts.get(field)
        if not isinstance(value, int) or isinstance(value, bool) or value < 0:
            raise ConversionError("%s counts.%s must be a non-negative integer." % (response_name, field))
        values[field] = value
    if values["total"] <= 0 or values["total"] != sum(values[field] for field in fields if field != "total"):
        raise ConversionError("%s counts are incomplete or inconsistent." % response_name)
    results = payload.get("results")
    if not isinstance(results, list) or len(results) != values["total"]:
        raise ConversionError("%s results do not match counts.total." % response_name)
    return values


def _validation_counts(payload: object) -> dict:
    counts = _validated_counts(
        payload,
        ("total", "compatible", "compatible_with_warnings", "incompatible"),
        "Read-only validation response",
    )
    observed = {field: 0 for field in ("compatible", "compatible_with_warnings", "incompatible")}
    for row in payload["results"]:  # type: ignore[index]
        status = row.get("status") if isinstance(row, dict) else None
        if status not in observed:
            raise ConversionError("Read-only validation response contains an unknown result status.")
        observed[status] += 1
    if any(observed[field] != counts[field] for field in observed):
        raise ConversionError("Read-only validation response result statuses do not match counts.")
    return counts


def _import_counts(payload: object) -> dict:
    if isinstance(payload, dict) and payload.get("status") in ("error", "failed", "incompatible"):
        raise ConversionError("Draft import response reports an unsuccessful status.")
    counts = _validated_counts(
        payload,
        ("total", "created", "updated", "no_change", "failed"),
        "Draft import response",
    )
    observed = {field: 0 for field in ("created", "updated", "no_change", "failed")}
    for row in payload["results"]:  # type: ignore[index]
        action = row.get("action") if isinstance(row, dict) else None
        observed[action if action in ("created", "updated", "no_change") else "failed"] += 1
    if any(observed[field] != counts[field] for field in observed):
        raise ConversionError("Draft import response result actions do not match counts.")
    return counts


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="content_factory.py",
        description="Contract-driven Markdown to the current Content Factory PageSpec converter.",
    )
    subparsers = parser.add_subparsers(dest="command", required=True)

    convert_parser = subparsers.add_parser("convert", help="Convert Markdown without importing or publishing.")
    convert_parser.add_argument("--source", type=Path, required=True, help="Directory containing Markdown source files.")
    convert_parser.add_argument("--output", type=Path, required=True, help="New output directory for PageSpec JSON, ZIP, and reports.")
    contract = convert_parser.add_mutually_exclusive_group(required=True)
    contract.add_argument("--wordpress-url", help="Target WordPress base URL; Contract Bundle is fetched at runtime.")
    contract.add_argument("--contract-file", type=Path, help="Previously fetched Contract Bundle for offline conversion.")
    convert_parser.add_argument("--site-key", default="potolkinaveka40", help="Exact Contract Bundle siteKey.")
    convert_parser.add_argument("--profile-id", default="potolki-inner", help="Exact Contract Bundle profileId.")
    convert_parser.add_argument("--force", action="store_true", help="Explicitly replace an existing output directory after conversion succeeds.")

    import_parser = subparsers.add_parser("import", help="Validate a prepared ZIP; import drafts only after explicit confirmation.")
    import_parser.add_argument("--zip", dest="zip_path", type=Path, required=True, help="The exact ZIP previously validated by the converter.")
    import_parser.add_argument("--wordpress-url", required=True, help="Target WordPress base URL.")
    import_parser.add_argument("--validated-hash", required=True, help="packageHash returned by read-only validation.")
    import_parser.add_argument("--execute", action="store_true", help="Allow the separate draft-import request after revalidation.")
    import_parser.add_argument(
        "--confirm-import",
        metavar="PHRASE",
        help="Required with --execute; exact phrase: %s" % CONFIRM_PHRASE,
    )
    return parser


def _convert(args: argparse.Namespace) -> int:
    client: Optional[WordPressClient] = None
    etag = ""
    if args.contract_file:
        bundle = load_contract(args.contract_file.resolve())
    else:
        client = WordPressClient(args.wordpress_url)
        raw, etag = client.fetch_contract(args.site_key, args.profile_id)
        bundle = validate_contract(raw, etag)
    report = convert(args.source, args.output, bundle, force=args.force, client=client, etag=etag)
    summary = {
        "status": report["status"],
        "sources": report["sourceCount"],
        "pages": report["pageCount"],
        "gaps": report["gapCounts"],
        "output": str(args.output.resolve()),
        "zip": str((args.output.resolve() / "pagespec.zip")),
        "validatedHash": report.get("validation", {}).get("summary", {}).get("packageHash", ""),
        "wordpressWrites": False,
        "published": False,
    }
    print(json.dumps(summary, ensure_ascii=False, sort_keys=True, indent=2))
    return 0 if report["status"] == "compatible" else 2


def _import(args: argparse.Namespace) -> int:
    zip_path = args.zip_path.resolve()
    if not zip_path.is_file() or not zipfile_is_valid(zip_path):
        raise ConversionError("--zip must be an existing readable ZIP file.")
    if re.fullmatch(r"sha256:[a-f0-9]{64}", args.validated_hash) is None:
        raise ConversionError("--validated-hash must be a sha256: value returned by /validate.")
    if args.execute and args.confirm_import != CONFIRM_PHRASE:
        raise ConversionError("Draft import requires the exact --confirm-import phrase shown in --help.")
    if not args.execute and args.confirm_import:
        raise ConversionError("--confirm-import is only accepted together with --execute.")
    client = WordPressClient(args.wordpress_url)
    validation = client.validate_zip(zip_path)
    if not isinstance(validation, dict):
        raise ConversionError("Read-only validation response did not return a JSON object.")
    actual_hash = validation.get("packageHash", "")
    if actual_hash != args.validated_hash:
        raise ConversionError("The ZIP packageHash differs from --validated-hash; import is blocked.")
    counts = _validation_counts(validation)
    if counts["incompatible"] != 0 or counts["compatible_with_warnings"] != 0:
        raise ConversionError("Read-only validation reports incompatible pages or unresolved warnings; import is blocked.")
    if not args.execute:
        print(json.dumps({
            "status": "dry-run",
            "validatedHash": actual_hash,
            "mode": "atomic",
            "confirmed": False,
            "wordpressWrites": False,
            "validationCounts": counts,
        }, ensure_ascii=False, sort_keys=True, indent=2))
        return 0
    result = client.import_zip(zip_path, args.validated_hash)
    result_counts = _import_counts(result)
    failed = result_counts["failed"]
    print(json.dumps({
        "status": "draft-import-request-completed",
        "validatedHash": args.validated_hash,
        "mode": "atomic",
        "confirmed": True,
        "published": False,
        "result": result,
    }, ensure_ascii=False, sort_keys=True, indent=2))
    return 0 if failed == 0 else 2


def zipfile_is_valid(path: Path) -> bool:
    import zipfile
    try:
        with zipfile.ZipFile(str(path), "r") as archive:
            return archive.testzip() is None
    except (OSError, zipfile.BadZipFile):
        return False


def main(argv: Optional[Sequence[str]] = None) -> int:
    args = _parser().parse_args(argv)
    try:
        if args.command == "convert":
            return _convert(args)
        if args.command == "import":
            return _import(args)
        raise ConversionError("Unknown command.")
    except (ContractError, ConversionError, HttpError, OSError, UnicodeError, json.JSONDecodeError) as exc:
        print("error: %s" % exc, file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
