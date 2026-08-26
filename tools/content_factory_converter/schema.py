"""Small dependency-free JSON Schema validator for advertised PageSpec contracts.

The converter validates the strict PageSpec schema supplied by the runtime.  The
implementation intentionally supports the assertion keywords used by Content
Factory's Draft 2020-12 schemas and rejects unknown local references.
"""

from __future__ import annotations

import re
from typing import Any, Dict, List, Optional
from urllib.parse import urlparse


def _pointer(path: str, part: Any) -> str:
    escaped = str(part).replace("~", "~0").replace("/", "~1")
    return path + "/" + escaped


def _type_matches(value: Any, expected: str) -> bool:
    if expected == "object":
        return isinstance(value, dict)
    if expected == "array":
        return isinstance(value, list)
    if expected == "string":
        return isinstance(value, str)
    if expected == "integer":
        return isinstance(value, int) and not isinstance(value, bool)
    if expected == "number":
        return isinstance(value, (int, float)) and not isinstance(value, bool)
    if expected == "boolean":
        return isinstance(value, bool)
    if expected == "null":
        return value is None
    return False


def _resolve_ref(root: Dict[str, Any], ref: str) -> Optional[Dict[str, Any]]:
    if not ref.startswith("#/"):
        return None
    value: Any = root
    for raw in ref[2:].split("/"):
        key = raw.replace("~1", "/").replace("~0", "~")
        if not isinstance(value, dict) or key not in value:
            return None
        value = value[key]
    return value if isinstance(value, dict) else None


def validate(instance: Any, schema: Dict[str, Any], root: Optional[Dict[str, Any]] = None, path: str = "") -> List[Dict[str, str]]:
    """Return deterministic validation issues for *instance*."""

    root = schema if root is None else root
    issues: List[Dict[str, str]] = []

    def error(code: str, message: str, at: str = path) -> None:
        issues.append({"code": code, "path": at or "/", "message": message})

    if "$ref" in schema:
        target = _resolve_ref(root, str(schema["$ref"]))
        if target is None:
            error("UNSUPPORTED_SCHEMA_REF", "Schema contains an unresolved or non-local $ref.")
            return issues
        return validate(instance, target, root, path)

    for subschema in schema.get("allOf", []):
        if isinstance(subschema, dict):
            issues.extend(validate(instance, subschema, root, path))
    if "anyOf" in schema:
        branches = [validate(instance, item, root, path) for item in schema["anyOf"] if isinstance(item, dict)]
        if not any(not branch for branch in branches):
            error("ANY_OF", "Value does not match any allowed schema branch.")
    if "oneOf" in schema:
        branches = [validate(instance, item, root, path) for item in schema["oneOf"] if isinstance(item, dict)]
        if sum(1 for branch in branches if not branch) != 1:
            error("ONE_OF", "Value must match exactly one allowed schema branch.")
    if isinstance(schema.get("not"), dict) and not validate(instance, schema["not"], root, path):
        error("NOT", "Value matches a forbidden schema branch.")

    if "const" in schema and instance != schema["const"]:
        error("CONST", "Value does not match the required constant.")
    if "enum" in schema and instance not in schema["enum"]:
        error("ENUM", "Value is not one of the allowed values.")

    expected = schema.get("type")
    if expected is not None:
        expected_types = expected if isinstance(expected, list) else [expected]
        if not any(_type_matches(instance, str(item)) for item in expected_types):
            error("TYPE", "Value has an invalid JSON type.")
            return issues

    if isinstance(instance, dict):
        required = schema.get("required", [])
        if isinstance(required, list):
            for key in required:
                if key not in instance:
                    error("REQUIRED", "Required property is missing.", _pointer(path, key))
        properties = schema.get("properties", {})
        if not isinstance(properties, dict):
            properties = {}
        pattern_properties = schema.get("patternProperties", {})
        if not isinstance(pattern_properties, dict):
            pattern_properties = {}
        for key, value in instance.items():
            matched = False
            if key in properties and isinstance(properties[key], dict):
                matched = True
                issues.extend(validate(value, properties[key], root, _pointer(path, key)))
            for pattern, subschema in pattern_properties.items():
                if re.search(pattern, str(key)) and isinstance(subschema, dict):
                    matched = True
                    issues.extend(validate(value, subschema, root, _pointer(path, key)))
            if not matched:
                additional = schema.get("additionalProperties", True)
                if additional is False:
                    error("ADDITIONAL_PROPERTY", "Additional property is not allowed.", _pointer(path, key))
                elif isinstance(additional, dict):
                    issues.extend(validate(value, additional, root, _pointer(path, key)))
        if isinstance(schema.get("minProperties"), int) and len(instance) < schema["minProperties"]:
            error("MIN_PROPERTIES", "Object has too few properties.")
        if isinstance(schema.get("maxProperties"), int) and len(instance) > schema["maxProperties"]:
            error("MAX_PROPERTIES", "Object has too many properties.")

    if isinstance(instance, list):
        if isinstance(schema.get("minItems"), int) and len(instance) < schema["minItems"]:
            error("MIN_ITEMS", "Array has too few items.")
        if isinstance(schema.get("maxItems"), int) and len(instance) > schema["maxItems"]:
            error("MAX_ITEMS", "Array has too many items.")
        if schema.get("uniqueItems") is True:
            rendered = [repr(item) for item in instance]
            if len(rendered) != len(set(rendered)):
                error("UNIQUE_ITEMS", "Array items must be unique.")
        items = schema.get("items")
        if isinstance(items, dict):
            for index, value in enumerate(instance):
                issues.extend(validate(value, items, root, _pointer(path, index)))

    if isinstance(instance, str):
        if isinstance(schema.get("minLength"), int) and len(instance) < schema["minLength"]:
            error("MIN_LENGTH", "String is shorter than allowed.")
        if isinstance(schema.get("maxLength"), int) and len(instance) > schema["maxLength"]:
            error("MAX_LENGTH", "String is longer than allowed.")
        if isinstance(schema.get("pattern"), str) and re.search(schema["pattern"], instance) is None:
            error("PATTERN", "String does not match the required pattern.")
        fmt = schema.get("format")
        if fmt == "uri":
            parsed = urlparse(instance)
            if not parsed.scheme or (parsed.scheme in ("http", "https") and not parsed.netloc):
                error("FORMAT_URI", "String is not an absolute URI.")
        elif fmt == "email" and re.fullmatch(r"[^@\s]+@[^@\s]+\.[^@\s]+", instance) is None:
            error("FORMAT_EMAIL", "String is not a valid email address.")

    if isinstance(instance, (int, float)) and not isinstance(instance, bool):
        if isinstance(schema.get("minimum"), (int, float)) and instance < schema["minimum"]:
            error("MINIMUM", "Number is below the minimum.")
        if isinstance(schema.get("maximum"), (int, float)) and instance > schema["maximum"]:
            error("MAXIMUM", "Number is above the maximum.")

    return issues

