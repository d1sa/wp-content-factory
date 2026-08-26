from __future__ import annotations

import copy
import hashlib
import json
from pathlib import Path
from typing import Any, Dict

from content_factory_converter.contract import (
    SUPPORTED_CONTRACT_VERSION,
    SUPPORTED_PAGE_SPEC_VERSION,
)


PLUGIN_ROOT = Path(__file__).resolve().parents[2]
FIXTURES = Path(__file__).resolve().parent / "fixtures"


def _sha(value: bytes) -> str:
    return "sha256:" + hashlib.sha256(value).hexdigest()


def make_contract() -> Dict[str, Any]:
    page_schema = json.loads((PLUGIN_ROOT / ("schemas/pagespec-%s.schema.json" % SUPPORTED_PAGE_SPEC_VERSION)).read_text(encoding="utf-8"))
    semantic = {
        "type": "object",
        "additionalProperties": False,
        "properties": {
            "hero": {
                "type": "object", "additionalProperties": False,
                "required": ["title", "lead", "primaryAction"],
                "properties": {
                    "title": {"type": "string", "minLength": 1},
                    "lead": {"type": "array", "minItems": 1, "items": {"type": "string", "minLength": 1}},
                    "primaryAction": {"type": "object", "required": ["label", "link"], "properties": {"label": {"type": "string", "minLength": 1}, "link": {"type": "object"}}, "additionalProperties": False},
                    "image": {"type": "object"},
                },
            },
            "article": {
                "type": "object", "additionalProperties": False,
                "required": ["title", "body"],
                "properties": {
                    "title": {"type": "string", "minLength": 1},
                    "body": {"type": "array", "minItems": 1, "items": {"type": "object", "required": ["type"], "additionalProperties": True}},
                },
            },
            "catalog": {
                "type": "object", "additionalProperties": False,
                "required": ["title", "items"],
                "properties": {
                    "title": {"type": "string", "minLength": 1},
                    "items": {"type": "array", "minItems": 1, "items": {
                        "type": "object", "additionalProperties": False,
                        "required": ["title", "text", "action", "image"],
                        "properties": {
                            "title": {"type": "string", "minLength": 1},
                            "text": {"type": "string", "minLength": 1},
                            "action": {"type": "object", "additionalProperties": False, "required": ["label", "link"], "properties": {"label": {"type": "string", "minLength": 1}, "link": {"type": "object"}}},
                            "image": {"type": "object"},
                        },
                    }},
                },
            },
            "steps": {
                "type": "object", "additionalProperties": False,
                "required": ["title", "items"],
                "properties": {
                    "title": {"type": "string", "minLength": 1},
                    "items": {
                        "type": "array", "minItems": 2,
                        "items": {
                            "type": "object", "additionalProperties": False,
                            "required": ["title", "text"],
                            "properties": {
                                "title": {"type": "string", "minLength": 1},
                                "text": {"type": "string", "minLength": 1},
                            },
                        },
                    },
                },
            },
            "faq": {
                "type": "object", "additionalProperties": False,
                "required": ["title", "items"],
                "properties": {
                    "title": {"type": "string", "minLength": 1},
                    "items": {
                        "type": "array", "minItems": 3,
                        "items": {
                            "type": "object", "additionalProperties": False,
                            "required": ["question", "answer"],
                            "properties": {
                                "question": {"type": "string", "minLength": 1},
                                "answer": {"type": "string", "minLength": 1},
                            },
                        },
                    },
                },
            },
            "cta": {
                "type": "object", "additionalProperties": False,
                "required": ["variant", "title", "text", "primaryAction"],
                "properties": {
                    "variant": {"type": "string", "enum": ["form", "links"]},
                    "title": {"type": "string", "minLength": 1},
                    "text": {"type": "string", "minLength": 1},
                    "primaryAction": {"type": "object"},
                    "secondaryAction": {"type": "object"},
                },
            },
        },
    }
    page_types = {
        "category-page": {"occurrences": {"hero": {"min": 1, "max": 1}, "catalog": {"min": 1}, "article": {"min": 0}, "steps": {"min": 0}, "faq": {"min": 1}, "cta": {"min": 1, "max": 1}}},
        "detail-page": {"occurrences": {"hero": {"min": 1, "max": 1}, "catalog": {"min": 0}, "article": {"min": 1}, "steps": {"min": 0}, "faq": {"min": 1}, "cta": {"min": 1, "max": 1}}},
    }
    identity = {
        "siteKey": "fixture-site",
        "profileId": "fixture-profile",
        "profileVersion": "1.2.0",
        "siteDefaultsVersion": "1.0.0",
        "manifestHash": _sha(b"fixture manifest"),
    }
    def example(page_type: str, types: list) -> Dict[str, Any]:
        return {
            "schemaVersion": SUPPORTED_PAGE_SPEC_VERSION, "sourceId": "example-" + page_type,
            "pageType": page_type,
            "generatedAgainst": {"profileId": identity["profileId"], "profileVersion": identity["profileVersion"], "manifestHash": identity["manifestHash"]},
            "target": {"siteKey": identity["siteKey"], "profileId": identity["profileId"]},
            "post": {"title": "Example", "slug": "example"},
            "seo": {"title": "Example", "description": "Example"},
            "sections": [{"id": "s-%d" % index, "type": value, "data": {}} for index, value in enumerate(types)],
        }
    bundle = {
        "contractVersion": SUPPORTED_CONTRACT_VERSION,
        "pageSpecVersion": SUPPORTED_PAGE_SPEC_VERSION,
        "identity": identity,
        "pageSpecSchema": page_schema,
        "semanticProfileSchema": semantic,
        "pageTypes": page_types,
        "siteDefaults": {"version": "1.0.0"},
        "assets": {
            "hero-fallback": {"path": "assets/hero.jpg", "label": "Hero"},
            "card-detail": {"path": "assets/detail.jpg", "label": "Detail"},
        },
        "policies": {"heroImageFallback": "hero-fallback", "externalAssets": False, "externalLinks": True},
        "examples": [
            example("category-page", ["hero", "catalog", "article", "faq", "cta"]),
            example("detail-page", ["hero", "article", "steps", "faq", "cta"]),
        ],
        "conversionGuidance": ["Use only this test Contract Bundle."],
        "selfCheck": {"status": "compatible", "issues": []},
    }
    from content_factory_converter.contract import contract_hash
    bundle["contractHash"] = contract_hash(bundle)
    return bundle


def clone_contract() -> Dict[str, Any]:
    return copy.deepcopy(make_contract())
