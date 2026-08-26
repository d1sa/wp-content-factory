#!/usr/bin/env python3
"""Verify Content Factory version sources against plugin-owned artifacts."""

from __future__ import annotations

import json
import re
from pathlib import Path
from typing import Dict

from content_factory_converter.contract import (
    SUPPORTED_CONTRACT_VERSION,
    SUPPORTED_PAGE_SPEC_VERSION,
)


PLUGIN_ROOT = Path(__file__).resolve().parents[1]
REGISTRY_FILE = PLUGIN_ROOT / "src/VersionRegistry.php"
PLUGIN_FILE = PLUGIN_ROOT / "content-factory.php"
PROFILE_FILE = PLUGIN_ROOT / "adapters/potolki-inner/profile.json"


def php_constants(path: Path) -> Dict[str, str]:
    source = path.read_text(encoding="utf-8")
    return dict(re.findall(r"public const\s+([A-Z0-9_]+)\s*=\s*'([^']+)'\s*;", source))


def plugin_header(source: str, name: str) -> str:
    match = re.search(r"^\s*\*\s*" + re.escape(name) + r":\s*(\S+)\s*$", source, re.MULTILINE)
    return match.group(1) if match else ""


def load_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def main() -> int:
    versions = php_constants(REGISTRY_FILE)
    required = {
        "PLUGIN",
        "REST_NAMESPACE",
        "CONTRACT_BUNDLE",
        "PAGE_SPEC",
        "THEME_PROFILE_SCHEMA",
        "OPERATION_LOG_DB",
    }
    errors = []
    missing = sorted(required - versions.keys())
    if missing:
        errors.append("VersionRegistry is missing: " + ", ".join(missing))

    plugin_source = PLUGIN_FILE.read_text(encoding="utf-8")
    if versions.get("PLUGIN") != plugin_header(plugin_source, "Version"):
        errors.append("WordPress plugin header Version does not match VersionRegistry::PLUGIN.")

    page_spec_version = versions.get("PAGE_SPEC", "")
    page_spec_path = PLUGIN_ROOT / f"schemas/pagespec-{page_spec_version}.schema.json"
    if not page_spec_path.is_file():
        errors.append(f"PageSpec schema is missing: {page_spec_path.name}")
    elif load_json(page_spec_path).get("properties", {}).get("schemaVersion", {}).get("const") != page_spec_version:
        errors.append("PageSpec schema identity does not match VersionRegistry::PAGE_SPEC.")

    contract_version = versions.get("CONTRACT_BUNDLE", "")
    contract_path = PLUGIN_ROOT / f"schemas/contract-bundle-{contract_version}.schema.json"
    if not contract_path.is_file():
        errors.append(f"Contract Bundle schema is missing: {contract_path.name}")
    else:
        properties = load_json(contract_path).get("properties", {})
        if properties.get("contractVersion", {}).get("const") != contract_version:
            errors.append("Contract Bundle schema identity does not match VersionRegistry::CONTRACT_BUNDLE.")
        if properties.get("pageSpecVersion", {}).get("const") != page_spec_version:
            errors.append("Contract Bundle schema PageSpec version does not match VersionRegistry::PAGE_SPEC.")

    profile_schema_version = versions.get("THEME_PROFILE_SCHEMA", "")
    profile_schema_path = PLUGIN_ROOT / f"schemas/theme-profile-{profile_schema_version}.schema.json"
    if not profile_schema_path.is_file():
        errors.append(f"Theme profile schema is missing: {profile_schema_path.name}")
    elif load_json(profile_schema_path).get("properties", {}).get("profileSchemaVersion", {}).get("const") != profile_schema_version:
        errors.append("Theme profile schema identity does not match VersionRegistry::THEME_PROFILE_SCHEMA.")

    profile = load_json(PROFILE_FILE)
    if profile.get("profileSchemaVersion") != profile_schema_version:
        errors.append("Profile definition does not match VersionRegistry::THEME_PROFILE_SCHEMA.")

    semver = re.compile(r"^[0-9]+\.[0-9]+\.[0-9]+$")
    data_versions = {
        "profile": str(profile.get("identity", {}).get("profileVersion", "")),
        "siteDefaults": str(profile.get("siteDefaults", {}).get("version", "")),
        "minimumTheme": str(profile.get("compatibility", {}).get("theme", {}).get("minVersion", "")),
        "operationLogDb": versions.get("OPERATION_LOG_DB", ""),
    }
    for name, value in data_versions.items():
        if semver.fullmatch(value) is None:
            errors.append(f"{name} version is not semantic: {value!r}")

    if SUPPORTED_CONTRACT_VERSION != contract_version:
        errors.append("Python converter Contract Bundle compatibility does not match the plugin registry.")
    if SUPPORTED_PAGE_SPEC_VERSION != page_spec_version:
        errors.append("Python converter PageSpec compatibility does not match the plugin registry.")

    report = {
        "status": "ok" if not errors else "error",
        "versions": {
            "plugin": versions.get("PLUGIN", ""),
            "restNamespace": versions.get("REST_NAMESPACE", ""),
            "contractBundle": contract_version,
            "pageSpec": page_spec_version,
            "themeProfileSchema": profile_schema_version,
            **data_versions,
            "minimumWordPress": plugin_header(plugin_source, "Requires at least"),
            "minimumPhp": plugin_header(plugin_source, "Requires PHP"),
        },
        "errors": errors,
    }
    print(json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True))
    return 0 if not errors else 1


if __name__ == "__main__":
    raise SystemExit(main())
