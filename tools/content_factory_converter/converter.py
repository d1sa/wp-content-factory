"""Contract-driven Markdown to the plugin-advertised PageSpec pipeline."""

from __future__ import annotations

import hashlib
import json
import os
import re
import shutil
import tempfile
import unicodedata
import uuid
import zipfile
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Dict, List, Optional, Set, Tuple
from urllib.parse import unquote, unquote_plus, urlparse

from .contract import (
    item_minimum,
    occurrence,
    section_schemas,
    sha256_bytes,
)
from .http import WordPressClient
from .markdown import (
    TECH_H2_HEADINGS,
    TECH_TOP_HEADINGS,
    UUID_RE,
    UUID_SUFFIX_RE,
    MarkdownDocument,
    TopSection,
    article_nodes,
    card_sections,
    clean_value,
    extract_asset_directive,
    field_value,
    paragraph_blocks,
    parse_document,
    sanitize_inline,
)
from .schema import validate as validate_schema


class ConversionError(RuntimeError):
    pass


GAP_TYPES = ("CONTENT_GAP", "LINK_GAP", "ASSET_GAP", "ADAPTER_GAP")
OUTPUT_MARKER = ".content-factory-output"
OUTPUT_MARKER_VALUE = {"format": "content-factory-converter-output", "version": 1}
UUID_V4_RE = re.compile(r"[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}", re.I)


def pretty_json(value: Any) -> str:
    return json.dumps(value, ensure_ascii=False, sort_keys=True, indent=2) + "\n"


def normalize_path(value: str) -> str:
    value = clean_value(value)
    if not value:
        return ""
    parsed = urlparse(value)
    path = parsed.path if parsed.scheme or parsed.netloc else value.split("?", 1)[0].split("#", 1)[0]
    return "/" + path.strip("/") + "/" if path.strip("/") else "/"


def modal_trigger_path(bundle: Dict[str, Any]) -> str:
    value = clean_value(bundle.get("policies", {}).get("modalTriggerPath", ""))
    parsed = urlparse(value)
    if (not value.startswith("/") or not value.endswith("/") or parsed.scheme or parsed.netloc
            or parsed.params or parsed.query or parsed.fragment):
        raise ConversionError("Contract Bundle policies.modalTriggerPath must be a plain absolute path ending with '/'.")
    return normalize_path(value)


def is_inline_form_modal_target(value: str, bundle: Dict[str, Any]) -> bool:
    """Recognize the profile-declared modal route only when it is a plain internal path."""
    parsed = urlparse(clean_value(value))
    if parsed.scheme or parsed.netloc or parsed.params or parsed.query or parsed.fragment:
        return False
    return normalize_path(parsed.path) == modal_trigger_path(bundle)


def stable_source_id(relative_path: str, filename: str, explicit_uuid: str = "") -> Tuple[str, Optional[str]]:
    explicit = explicit_uuid.strip()
    explicit_match = UUID_V4_RE.fullmatch(explicit) if explicit else None
    if explicit and not explicit_match:
        raise ConversionError("Metadata UUID must be a valid UUID v4 in %s." % relative_path)
    filename_candidate = UUID_SUFFIX_RE.search(filename)
    filename_match = UUID_RE.search(filename)
    if filename_candidate and not filename_match:
        raise ConversionError("Filename UUID suffix must be a valid UUID v4 in %s." % relative_path)
    if explicit_match and filename_match and explicit_match.group(0).lower() != filename_match.group(1).lower():
        raise ConversionError("Metadata UUID and filename UUID must match in %s." % relative_path)
    selected = explicit_match.group(0) if explicit_match else (filename_match.group(1) if filename_match else "")
    if selected:
        external_id = selected.lower()
        return "seo-" + external_id, external_id
    normalized = unicodedata.normalize("NFC", relative_path.replace(os.sep, "/")).casefold().encode("utf-8")
    return "md-" + hashlib.sha256(normalized).hexdigest()[:32], None


@dataclass
class SourceRecord:
    document: MarkdownDocument
    source_id: str
    external_id: Optional[str]
    source_hash: str
    slug: str
    declared_path: str
    parent_path: str
    parent: Optional["SourceRecord"] = None
    children: List["SourceRecord"] = field(default_factory=list)
    expected_path: str = ""
    page_type: str = ""


class GapRegistry:
    def __init__(self) -> None:
        self.items: List[Dict[str, Any]] = []
        self._seen: Set[str] = set()

    def add(self, gap_type: str, source_id: str, message: str, **context: Any) -> None:
        row: Dict[str, Any] = {"type": gap_type, "sourceId": source_id, "message": message}
        row.update({key: value for key, value in context.items() if value not in (None, "")})
        key = json.dumps(row, ensure_ascii=False, sort_keys=True)
        if key not in self._seen:
            self._seen.add(key)
            self.items.append(row)


class DescriptorResolver:
    def __init__(self, bundle: Dict[str, Any], source_by_path: Dict[str, str], internal_hosts: Set[str], gaps: GapRegistry, source_id: str):
        self.bundle = bundle
        self.source_by_path = source_by_path
        self.internal_hosts = internal_hosts
        self.gaps = gaps
        self.source_id = source_id
        self.link_schema = bundle["pageSpecSchema"].get("$defs", {}).get("link", {})
        variants = self.link_schema.get("oneOf", []) if isinstance(self.link_schema, dict) else []
        self.link_kinds = {
            item.get("properties", {}).get("kind", {}).get("const")
            for item in variants if isinstance(item, dict)
        }

    def unresolved_internal_path(self, target: str) -> str:
        """Keep a safe unresolved route so runtime diagnostics can name it."""
        parsed = urlparse(target)
        is_internal = bool(parsed.hostname and parsed.hostname.lower() in self.internal_hosts)
        is_relative_path = not parsed.scheme and not parsed.netloc and target.startswith("/") and not target.startswith("//")
        if not is_internal and not is_relative_path:
            return ""
        path = normalize_path(parsed.path)
        if not path or re.match(r"^/(?:javascript|data|vbscript):", path, re.I):
            return ""
        if parsed.query:
            path += "?" + parsed.query
        if parsed.fragment:
            path += "#" + parsed.fragment
        return path

    def link(self, label: str, target: str, section: str = "") -> Optional[Dict[str, Any]]:
        target = clean_value(target)
        descriptor: Optional[Dict[str, Any]] = None
        unresolved_path = ""
        if not target or target == "#":
            descriptor = None
        elif target.startswith("tel:") and "tel" in self.link_kinds:
            descriptor = {"kind": "tel", "value": target[4:]}
        elif target.startswith("mailto:") and "mailto" in self.link_kinds:
            descriptor = {"kind": "mailto", "value": target[7:]}
        elif target.startswith("#") and target[1:] == "request" and "anchor" in self.link_kinds:
            descriptor = {"kind": "anchor", "anchor": "request"}
        else:
            path = normalize_path(target)
            parsed = urlparse(target)
            is_internal = bool(parsed.hostname and parsed.hostname.lower() in self.internal_hosts)
            is_relative = not parsed.scheme and not parsed.netloc
            if path in self.source_by_path and (is_relative or is_internal) and "page" in self.link_kinds:
                descriptor = {"kind": "page", "sourceId": self.source_by_path[path]}
            elif path == modal_trigger_path(self.bundle) and is_relative and "path" in self.link_kinds:
                descriptor = {"kind": "path", "path": path}
            else:
                external_allowed = self.bundle["policies"].get("externalLinks", True) is not False
                if parsed.scheme in ("http", "https") and parsed.netloc and not is_internal and external_allowed and "external" in self.link_kinds:
                    descriptor = {"kind": "external", "url": target, "newTab": False}
                elif "path" in self.link_kinds:
                    unresolved_path = self.unresolved_internal_path(target)
                    if unresolved_path:
                        descriptor = {"kind": "path", "path": unresolved_path}
        if descriptor and not validate_schema(descriptor, self.link_schema):
            if unresolved_path:
                self.gaps.add(
                    "LINK_GAP", self.source_id,
                    "Internal link target was preserved in PageSpec but is not resolvable through the current batch.",
                    section=section, label=label, target=target,
                )
            return descriptor
        self.gaps.add(
            "LINK_GAP", self.source_id,
            "Link target is not resolvable through the current batch and Contract Bundle policies.",
            section=section, label=label, target=target,
        )
        return None

    def inline(self, label: str, target: str, section: str = "") -> Optional[str]:
        target = clean_value(target)
        if target.lower().split("#", 1)[0].endswith(".md"):
            return label
        parsed = urlparse(target)
        is_internal = bool(parsed.hostname and parsed.hostname.lower() in self.internal_hosts)
        is_relative = not parsed.scheme and not parsed.netloc
        path = normalize_path(target)
        if path in self.source_by_path and (is_relative or is_internal):
            return "[%s](%s)" % (label, path)
        if path == modal_trigger_path(self.bundle) and is_relative:
            return "[%s](%s)" % (label, path)
        if target == "#request":
            return "[%s](#request)" % label
        if target.startswith(("tel:", "mailto:")):
            return "[%s](%s)" % (label, target)
        if parsed.scheme in ("http", "https") and parsed.netloc and not is_internal and self.bundle["policies"].get("externalLinks", True) is not False:
            return "[%s](%s)" % (label, target)
        unresolved_path = self.unresolved_internal_path(target)
        if unresolved_path:
            self.gaps.add(
                "LINK_GAP", self.source_id,
                "Unknown internal inline link was preserved so runtime diagnostics can name its path.",
                section=section, label=label, target=target,
            )
            return "[%s](%s)" % (label, unresolved_path)
        self.gaps.add(
            "LINK_GAP", self.source_id,
            "Inline link target is unknown; its public label was preserved without a link.",
            section=section, label=label, target=target,
        )
        return None

    def image_text(self, alt: str, target: str, section: str = "") -> str:
        ref = target[len("themeAsset:"):] if target.startswith("themeAsset:") else ""
        if not ref:
            matching = [key for key, row in self.bundle["assets"].items() if isinstance(row, dict) and row.get("path") == target]
            ref = matching[0] if len(matching) == 1 else ""
        gap_type = "ADAPTER_GAP" if ref in self.bundle["assets"] else "ASSET_GAP"
        self.gaps.add(
            gap_type, self.source_id,
            "Markdown images cannot be represented inside this semantic article; alt text was preserved.",
            section=section, target=target, ref=ref,
        )
        return alt


def _discover_sources(source_root: Path, output_root: Path) -> List[Path]:
    result: List[Path] = []
    output_resolved = output_root.resolve()
    for path in source_root.rglob("*.md"):
        try:
            resolved = path.resolve()
        except OSError:
            continue
        if resolved == output_resolved or output_resolved in resolved.parents:
            continue
        if path.is_file():
            result.append(path)
    return sorted(result, key=lambda item: item.relative_to(source_root).as_posix())


def _source_snapshot(source_root: Path, output_root: Path) -> Dict[str, str]:
    return {
        path.relative_to(source_root).as_posix(): sha256_bytes(path.read_bytes())
        for path in _discover_sources(source_root, output_root)
    }


def _assert_unique_paths(records: List[SourceRecord], attribute: str, label: str) -> None:
    owners: Dict[str, SourceRecord] = {}
    for record in records:
        value = str(getattr(record, attribute))
        if not value:
            continue
        if value in owners:
            raise ConversionError(
                "Duplicate %s %s from %s and %s."
                % (label, value, owners[value].document.relative_path, record.document.relative_path)
            )
        owners[value] = record


def _build_registry(source_root: Path, output_root: Path, bundle: Dict[str, Any], gaps: GapRegistry) -> List[SourceRecord]:
    records: List[SourceRecord] = []
    by_id: Dict[str, SourceRecord] = {}
    by_file: Dict[Path, SourceRecord] = {}
    for path in _discover_sources(source_root, output_root):
        relative = path.relative_to(source_root).as_posix()
        source_bytes = path.read_bytes()
        document = parse_document(path, relative, source_bytes)
        source_id, external_id = stable_source_id(relative, path.name, document.fields["source_uuid"])
        if source_id in by_id:
            raise ConversionError("Duplicate sourceId %s from %s and %s." % (source_id, by_id[source_id].document.relative_path, relative))
        slug = document.fields["slug"]
        if not slug:
            gaps.add("CONTENT_GAP", source_id, "WordPress slug is missing.", sourceFile=relative)
        record = SourceRecord(
            document=document,
            source_id=source_id,
            external_id=external_id,
            source_hash=sha256_bytes(source_bytes),
            slug=slug,
            declared_path=normalize_path(document.fields["url"] or document.fields["canonical"]),
            parent_path=normalize_path(document.fields["parent_url"]),
        )
        records.append(record)
        by_id[source_id] = record
        by_file[path.resolve()] = record

    _assert_unique_paths(records, "declared_path", "declared path")
    by_declared = {record.declared_path: record for record in records if record.declared_path}
    for record in records:
        path = record.document.path
        convention = path.parent.parent / (path.parent.name + ".md")
        parent = by_file.get(convention.resolve()) if convention != path else None
        if parent is None and record.parent_path and record.parent_path not in ("/", record.declared_path):
            parent = by_declared.get(record.parent_path)
        if parent is record:
            raise ConversionError("Source cannot be its own parent: %s" % record.document.relative_path)
        record.parent = parent
        if parent:
            parent.children.append(record)
        elif record.parent_path not in ("", "/"):
            gaps.add(
                "LINK_GAP", record.source_id,
                "Declared parent is outside the batch and was not verified on the target runtime.",
                target=record.parent_path,
            )

    visiting: Set[str] = set()
    done: Set[str] = set()

    def expected(record: SourceRecord) -> str:
        if record.source_id in done:
            return record.expected_path
        if record.source_id in visiting:
            raise ConversionError("Parent/child graph contains a cycle at %s." % record.source_id)
        visiting.add(record.source_id)
        if record.parent:
            record.expected_path = expected(record.parent).rstrip("/") + "/" + record.slug + "/"
        else:
            record.expected_path = "/" + record.slug.strip("/") + "/" if record.slug else ""
        visiting.remove(record.source_id)
        done.add(record.source_id)
        return record.expected_path

    for record in records:
        expected(record)
        if record.declared_path and record.expected_path and record.declared_path != record.expected_path:
            gaps.add(
                "CONTENT_GAP", record.source_id,
                "Declared URL does not match the deterministic parent/child graph.",
                declaredPath=record.declared_path, expectedPath=record.expected_path,
            )

    _assert_unique_paths(records, "expected_path", "expected path")

    for record in records:
        base = record.document.path.parent
        for link in record.document.export_links:
            encoded_target = link["target"].split("#", 1)[0]
            candidates = {
                (base / unquote(encoded_target)).resolve(),
                (base / unquote_plus(encoded_target)).resolve(),
            }
            if not any(target in by_file for target in candidates):
                gaps.add(
                    "LINK_GAP", record.source_id,
                    "Exported Markdown dependency does not resolve to a source file.",
                    label=link["label"], target=link["target"],
                )

    for record in records:
        record.page_type = _select_page_type(bundle, bool(record.children), record, gaps)
    return records


def _select_page_type(bundle: Dict[str, Any], has_children: bool, record: SourceRecord, gaps: GapRegistry) -> str:
    candidates: List[str] = []
    for name in sorted(bundle["pageTypes"]):
        catalog = occurrence(bundle, name, "catalog")
        article = occurrence(bundle, name, "article")
        if has_children and catalog["min"] >= 1:
            candidates.append(name)
        elif not has_children and catalog["min"] == 0 and article["max"] != 0:
            candidates.append(name)
    if len(candidates) == 1:
        return candidates[0]
    gaps.add(
        "ADAPTER_GAP", record.source_id,
        "Contract Bundle does not provide one unambiguous page type for this graph node.",
        graphRole="parent" if has_children else "leaf", candidates=candidates,
    )
    return ""


def _build_steps(title: str, body: str) -> Optional[Dict[str, Any]]:
    matches = list(re.finditer(r"^##\s+\d+\.\s+(.+?)\s*$", body, re.M))
    if len(matches) < 2:
        return None
    items: List[Dict[str, str]] = []
    for index, match in enumerate(matches):
        end = matches[index + 1].start() if index + 1 < len(matches) else len(body)
        text = "\n\n".join(paragraph_blocks(body[match.end():end]))
        if text:
            items.append({"title": match.group(1).strip(), "text": text})
    return {"id": "steps", "type": "steps", "data": {"title": title, "items": items}}


def _build_faq(title: str, body: str) -> Dict[str, Any]:
    matches = list(re.finditer(r"^##\s+(.+?)\s*$", body, re.M))
    items: List[Dict[str, str]] = []
    for index, match in enumerate(matches):
        question = match.group(1).strip()
        if question.upper() in TECH_H2_HEADINGS:
            continue
        end = matches[index + 1].start() if index + 1 < len(matches) else len(body)
        answer = "\n\n".join(paragraph_blocks(body[match.end():end]))
        if answer:
            items.append({"question": question, "answer": answer})
    return {"id": "faq", "type": "faq", "data": {"title": title, "items": items}}


def _build_cta(top: TopSection, resolver: DescriptorResolver, gaps: GapRegistry, source_id: str) -> Dict[str, Any]:
    heading = re.search(r"^##\s+(.+?)\s*$", top.body, re.M)
    title = heading.group(1).strip() if heading else top.title
    remainder = top.body[heading.end():] if heading else top.body
    stop = re.search(r"^\*\*(?:Форма|Кнопка|Основная кнопка|Вторая кнопка|Ссылка / действие формы|URL):\*\*", remainder, re.M | re.I)
    text = "\n\n".join(paragraph_blocks(remainder[:stop.start()] if stop else remainder))
    action_matches = list(re.finditer(r"^\*\*(Кнопка|Основная кнопка|Вторая кнопка):\*\*\s*(?:\n\s*)?([^\n]+)", top.body, re.M | re.I))
    actions: List[Tuple[str, str]] = []
    for index, match in enumerate(action_matches):
        end = action_matches[index + 1].start() if index + 1 < len(action_matches) else len(top.body)
        segment = top.body[match.end():end]
        target = field_value(segment, ["URL", "Ссылка", "Ссылка / действие формы"])
        actions.append((clean_value(match.group(2)), target))
    is_form = bool(re.search(r"^\*\*Форма:\*\*|Ссылка / действие формы", top.body, re.M | re.I))
    has_link_target = any(clean_value(target) for _, target in actions)
    variant = "form" if is_form or not has_link_target else "links"
    data: Dict[str, Any] = {"variant": variant, "title": title, "text": text}
    if not actions:
        gaps.add("CONTENT_GAP", source_id, "Final CTA has no public action label.", section=top.title)
        data["primaryAction"] = {"label": ""}
    else:
        data["primaryAction"] = {"label": actions[0][0]}
        if variant == "links":
            primary = resolver.link(actions[0][0], actions[0][1], top.title)
            if primary:
                data["primaryAction"]["link"] = primary
            if len(actions) > 1:
                data["secondaryAction"] = {"label": actions[1][0]}
                secondary = resolver.link(actions[1][0], actions[1][1], top.title)
                if secondary:
                    data["secondaryAction"]["link"] = secondary
            else:
                gaps.add(
                    "ADAPTER_GAP", source_id,
                    "The current plugin links CTA requires a second public action; the first action URL was preserved.",
                    section=top.title, label=actions[0][0], target=actions[0][1],
                )
        else:
            for label, target in actions:
                if clean_value(target) and not is_inline_form_modal_target(target, resolver.bundle):
                    gaps.add(
                        "ADAPTER_GAP", source_id,
                        "The current plugin form CTA cannot consume an action URL; the URL was retained in this blocking gap.",
                        section=top.title, label=label, target=target,
                    )
    unsupported_actions = actions[2:] if variant == "links" else actions[1:]
    for label, target in unsupported_actions:
        gaps.add(
            "ADAPTER_GAP", source_id,
            "The current plugin CTA variant cannot represent this additional public action.",
            section=top.title, label=label, target=target,
        )
    return {"id": "request", "type": "cta", "data": data}


def _build_catalog(top: TopSection, resolver: DescriptorResolver, bundle: Dict[str, Any], gaps: GapRegistry, source_id: str) -> Dict[str, Any]:
    items: List[Dict[str, Any]] = []
    for block in card_sections(top.body):
        title = field_value(block, ["Заголовок", "Заголовок карточки", "Название"])
        text = field_value(block, ["Описание", "Текст", "Текст карточки"])
        label = field_value(block, ["Кнопка"])
        target = field_value(block, ["URL", "Ссылка"])
        _, asset = extract_asset_directive(block)
        row: Dict[str, Any] = {"title": title, "text": text, "action": {"label": label}}
        link = resolver.link(label, target, top.title)
        if link:
            row["action"]["link"] = link
        if asset and asset["ref"] in bundle["assets"]:
            descriptor = {"source": "themeAsset", "ref": asset["ref"], "alt": asset["alt"] or title}
            asset_schema = bundle["pageSpecSchema"].get("$defs", {}).get("asset", {})
            if isinstance(asset_schema, dict) and not validate_schema(descriptor, asset_schema):
                row["image"] = descriptor
            else:
                gaps.add(
                    "ADAPTER_GAP", source_id,
                    "Contract Bundle asset exists but PageSpec schema does not allow its descriptor.",
                    section=top.title, card=title, ref=asset["ref"],
                )
        else:
            gaps.add(
                "ASSET_GAP", source_id,
                "Catalog card has no explicit verified themeAsset from Contract Bundle.",
                section=top.title, card=title, ref=asset["ref"] if asset else "",
            )
        if not title or not text or not label:
            gaps.add("CONTENT_GAP", source_id, "Catalog card is missing public text or its action label.", section=top.title, card=title)
        items.append(row)
    return {"id": "catalog", "type": "catalog", "data": {"title": top.title, "items": items}}


def _page_validation(page: Dict[str, Any], bundle: Dict[str, Any]) -> Tuple[List[Dict[str, str]], List[Dict[str, str]]]:
    base = validate_schema(page, bundle["pageSpecSchema"])
    semantic: List[Dict[str, str]] = []
    schemas = section_schemas(bundle)
    page_type = page.get("pageType", "")
    counts: Dict[str, int] = {}
    for index, section in enumerate(page.get("sections", [])):
        section_type = section.get("type", "")
        counts[section_type] = counts.get(section_type, 0) + 1
        if section_type not in schemas:
            semantic.append({"code": "UNKNOWN_SECTION", "path": "/sections/%d/type" % index, "message": "Section is absent from Contract Bundle."})
        else:
            semantic.extend(validate_schema(section.get("data"), schemas[section_type], schemas[section_type], "/sections/%d/data" % index))
    if page_type not in bundle["pageTypes"]:
        semantic.append({"code": "UNKNOWN_PAGE_TYPE", "path": "/pageType", "message": "Page type is absent from Contract Bundle."})
    else:
        recipe = bundle["pageTypes"][page_type]
        occurrences = recipe.get("occurrences", {}) if isinstance(recipe, dict) else {}
        for section_type, limits in occurrences.items():
            if not isinstance(limits, dict):
                continue
            count = counts.get(section_type, 0)
            maximum = limits.get("max")
            if count < int(limits.get("min", 0)) or (isinstance(maximum, int) and count > maximum):
                semantic.append({"code": "SECTION_OCCURRENCE", "path": "/sections", "message": "%s occurrence is outside Contract Bundle limits." % section_type})
        for section_type in counts:
            if section_type not in occurrences:
                semantic.append({"code": "SECTION_NOT_ALLOWED", "path": "/sections", "message": "%s is not allowed for this page type." % section_type})
    defs = bundle["pageSpecSchema"].get("$defs", {})
    link_schema = defs.get("link", {}) if isinstance(defs, dict) else {}
    asset_schema = defs.get("asset", {}) if isinstance(defs, dict) else {}

    def descriptors(value: Any, path: str) -> None:
        if isinstance(value, dict):
            if "kind" in value and value.get("kind") in ("anchor", "page", "path", "external", "tel", "mailto") and isinstance(link_schema, dict):
                for issue in validate_schema(value, link_schema, link_schema, path):
                    semantic.append({"code": "LINK_DESCRIPTOR_" + issue["code"], "path": issue["path"], "message": issue["message"]})
            if "source" in value and value.get("source") in ("themeAsset", "mediaId", "mediaUrl", "externalUrl", "none") and isinstance(asset_schema, dict):
                for issue in validate_schema(value, asset_schema, asset_schema, path):
                    semantic.append({"code": "ASSET_DESCRIPTOR_" + issue["code"], "path": issue["path"], "message": issue["message"]})
            for key, child in value.items():
                descriptors(child, path + "/" + str(key).replace("~", "~0").replace("/", "~1"))
        elif isinstance(value, list):
            for index, child in enumerate(value):
                descriptors(child, path + "/" + str(index))

    descriptors(page.get("sections", []), "/sections")
    return base, semantic


def _build_page(record: SourceRecord, bundle: Dict[str, Any], source_by_path: Dict[str, str], internal_hosts: Set[str], gaps: GapRegistry) -> Tuple[Optional[Dict[str, Any]], Dict[str, Any]]:
    document = record.document
    excluded = list(document.excluded)
    warnings: List[Dict[str, Any]] = []
    if not document.sections:
        gaps.add("CONTENT_GAP", record.source_id, "Public content marker or top-level content sections are missing.")
        return None, {"excluded": excluded, "warnings": warnings}
    if re.search(r"^```|^~~~", document.public_text, re.M) or re.search(r"<\/?[A-Za-z][^>]*>", document.public_text):
        gaps.add(
            "ADAPTER_GAP", record.source_id,
            "Public source contains fenced code or raw HTML that the semantic profile cannot represent safely.",
        )
    schemas = section_schemas(bundle)
    for required_type in ("hero", "article", "catalog", "steps", "faq", "cta"):
        if required_type not in schemas and occurrence(bundle, record.page_type, required_type)["max"] != 0:
            gaps.add("ADAPTER_GAP", record.source_id, "Semantic section is declared by page recipe but absent from Contract Bundle schema.", sectionType=required_type)

    resolver = DescriptorResolver(bundle, source_by_path, internal_hosts, gaps, record.source_id)

    def rich(value: str, section: str) -> str:
        return sanitize_inline(
            value,
            lambda label, target: resolver.inline(label, target, section),
            lambda alt, target: resolver.image_text(alt, target, section),
        )

    def plain(value: str, section: str) -> str:
        def reject_link(label: str, target: str) -> Optional[str]:
            gaps.add(
                "ADAPTER_GAP", record.source_id,
                "This semantic plain-text field cannot contain a Markdown link; its label was preserved.",
                section=section, label=label, target=target,
            )
            return None
        return sanitize_inline(value, reject_link, lambda alt, target: resolver.image_text(alt, target, section))
    hero_source = document.sections[0]
    hero_body, explicit_hero_asset = extract_asset_directive(hero_source.body)
    intro = paragraph_blocks(hero_body)
    h1 = document.seo_fields["h1"] or hero_source.title
    if not document.seo_fields["h1"]:
        gaps.add("CONTENT_GAP", record.source_id, "SEO H1 is missing; content heading was retained for diagnosis.")
    converted_sections: List[Dict[str, Any]] = []
    section_counts: Dict[str, int] = {}
    first_cta_label = ""
    faq_min = item_minimum(schemas.get("faq", {}), "items")

    def append_section(section: Dict[str, Any]) -> None:
        section_type = section["type"]
        section_counts[section_type] = section_counts.get(section_type, 0) + 1
        number = section_counts[section_type]
        base_id = "request" if section_type == "cta" else section_type
        section["id"] = base_id if number == 1 else "%s-%02d" % (base_id, number)
        converted_sections.append(section)

    for top in document.sections[1:]:
        upper = top.title.upper()
        if upper in TECH_TOP_HEADINGS:
            excluded.append({"kind": "service-section", "title": top.title})
            continue
        if "ФИНАЛЬН" in upper or upper.startswith("CTA") or "ЗАЯВК" in upper:
            cta = _build_cta(top, resolver, gaps, record.source_id)
            cta["data"]["title"] = plain(cta["data"]["title"], top.title)
            cta["data"]["text"] = rich(cta["data"]["text"], top.title)
            cta["data"]["primaryAction"]["label"] = plain(cta["data"]["primaryAction"]["label"], top.title)
            if isinstance(cta["data"].get("secondaryAction"), dict):
                cta["data"]["secondaryAction"]["label"] = plain(cta["data"]["secondaryAction"].get("label", ""), top.title)
            if not first_cta_label:
                first_cta_label = cta["data"]["primaryAction"].get("label", "")
            append_section(cta)
            continue
        if upper.startswith("ЧАСТЫЕ ВОПРОСЫ") or upper == "FAQ":
            faq_section = _build_faq(top.title, top.body)
            faq_section["data"]["title"] = plain(faq_section["data"]["title"], top.title)
            for item in faq_section["data"]["items"]:
                item["question"] = plain(item["question"], top.title)
                item["answer"] = rich(item["answer"], top.title)
            if len(faq_section["data"]["items"]) < faq_min:
                gaps.add("CONTENT_GAP", record.source_id, "FAQ has fewer public items than required by Contract Bundle.", actual=len(faq_section["data"]["items"]), expected=faq_min)
            append_section(faq_section)
            continue
        possible_steps = _build_steps(top.title, top.body)
        if possible_steps:
            steps_section = possible_steps
            steps_section["data"]["title"] = plain(steps_section["data"]["title"], top.title)
            for item in steps_section["data"]["items"]:
                item["title"] = plain(item["title"], top.title)
                item["text"] = rich(item["text"], top.title)
            append_section(steps_section)
            continue
        if card_sections(top.body):
            catalog_section = _build_catalog(top, resolver, bundle, gaps, record.source_id)
            catalog_section["data"]["title"] = plain(catalog_section["data"]["title"], top.title)
            for item in catalog_section["data"]["items"]:
                item["title"] = plain(item["title"], top.title)
                item["text"] = plain(item["text"], top.title)
                item["action"]["label"] = plain(item["action"]["label"], top.title)
                if isinstance(item.get("image"), dict):
                    item["image"]["alt"] = plain(item["image"].get("alt", ""), top.title)
            append_section(catalog_section)
            continue
        nodes, technical = article_nodes(
            top.body,
            lambda label, target: resolver.link(label, target, top.title),
            lambda label, target: resolver.inline(label, target, top.title),
            lambda alt, target: resolver.image_text(alt, target, top.title),
        )
        excluded.extend({"kind": "service-subsection", "title": title} for title in technical)
        if nodes:
            append_section({"id": "", "type": "article", "data": {"title": plain(top.title, top.title), "body": nodes}})

    if section_counts.get("faq", 0) == 0 and occurrence(bundle, record.page_type, "faq")["min"] > 0:
        gaps.add("CONTENT_GAP", record.source_id, "Required FAQ section is missing.")
    if section_counts.get("cta", 0) == 0 and occurrence(bundle, record.page_type, "cta")["min"] > 0:
        gaps.add("CONTENT_GAP", record.source_id, "Required final CTA section is missing.")

    hero_data: Dict[str, Any] = {
        "title": plain(h1, hero_source.title),
        "lead": [sanitize_inline(text, lambda label, target: resolver.inline(label, target, hero_source.title), lambda alt, target: resolver.image_text(alt, target, hero_source.title)) for text in intro],
        "primaryAction": {
            "label": first_cta_label,
            "link": {"kind": "path", "path": modal_trigger_path(bundle)},
        },
    }
    hero_ref = explicit_hero_asset["ref"] if explicit_hero_asset else str(bundle["policies"].get("heroImageFallback", ""))
    if hero_ref:
        if hero_ref in bundle["assets"]:
            descriptor = {"source": "themeAsset", "ref": hero_ref, "alt": plain((explicit_hero_asset or {}).get("alt") or h1, hero_source.title)}
            asset_schema = bundle["pageSpecSchema"].get("$defs", {}).get("asset", {})
            if isinstance(asset_schema, dict) and not validate_schema(descriptor, asset_schema):
                hero_data["image"] = descriptor
                if not explicit_hero_asset:
                    warnings.append({"code": "ASSET_FALLBACK", "section": "hero", "ref": hero_ref})
            else:
                gaps.add("ADAPTER_GAP", record.source_id, "Hero asset exists but PageSpec schema does not allow its descriptor.", section="hero", ref=hero_ref)
        else:
            gaps.add("ASSET_GAP", record.source_id, "Hero asset ref is absent from Contract Bundle.", section="hero", ref=hero_ref)

    sections: List[Dict[str, Any]] = [{"id": "hero", "type": "hero", "data": hero_data}]
    sections.extend(converted_sections)

    post: Dict[str, Any] = {
        "title": document.fields["post_title"],
        "slug": record.slug,
        "categoryLabel": record.parent.document.fields["post_title"] if record.parent else document.fields["post_title"],
    }
    if record.parent:
        post["parent"] = {"sourceId": record.parent.source_id}
    for label, value in (("post title", post["title"]), ("SEO title", document.seo_fields["title"]), ("SEO description", document.seo_fields["description"])):
        if not value:
            gaps.add("CONTENT_GAP", record.source_id, "%s is missing." % label.capitalize())
    seo: Dict[str, Any] = {"title": document.seo_fields["title"], "description": document.seo_fields["description"]}
    if document.seo_fields["primary_keyword"]:
        seo["primaryKeyword"] = document.seo_fields["primary_keyword"]
    identity = bundle["identity"]
    page = {
        "schemaVersion": bundle["pageSpecVersion"],
        "sourceId": record.source_id,
        "pageType": record.page_type,
        "generatedAgainst": {
            "profileId": identity["profileId"],
            "profileVersion": identity["profileVersion"],
            "manifestHash": identity["manifestHash"],
        },
        "target": {"siteKey": identity["siteKey"], "profileId": identity["profileId"]},
        "post": post,
        "seo": seo,
        "sections": sections,
    }
    return page, {"excluded": excluded, "warnings": warnings}


def _write_zip(path: Path, pages: List[Tuple[str, bytes]]) -> None:
    with zipfile.ZipFile(path, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for name, payload in sorted(pages):
            info = zipfile.ZipInfo(name, date_time=(1980, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            info.create_system = 3
            archive.writestr(info, payload)


def _summary_validation(response: Dict[str, Any]) -> Dict[str, Any]:
    if not isinstance(response, dict):
        raise ConversionError("Read-only validation response must be a JSON object.")
    counts = response.get("counts")
    fields = ("total", "compatible", "compatible_with_warnings", "incompatible")
    if not isinstance(counts, dict) or any(
        not isinstance(counts.get(field), int) or isinstance(counts.get(field), bool) or counts[field] < 0
        for field in fields
    ):
        raise ConversionError("Read-only validation response has invalid counts.")
    if counts["total"] <= 0 or counts["total"] != sum(counts[field] for field in fields[1:]):
        raise ConversionError("Read-only validation response counts are inconsistent.")
    results = response.get("results")
    if not isinstance(results, list) or len(results) != counts["total"]:
        raise ConversionError("Read-only validation response results do not match counts.total.")
    observed = {field: 0 for field in fields[1:]}
    for row in results:
        status = row.get("status") if isinstance(row, dict) else None
        if status not in observed:
            raise ConversionError("Read-only validation response contains an unknown result status.")
        observed[status] += 1
    if any(observed[field] != counts[field] for field in observed):
        raise ConversionError("Read-only validation response result statuses do not match counts.")
    package_hash = response.get("packageHash", "")
    if re.fullmatch(r"sha256:[a-f0-9]{64}", package_hash) is None:
        raise ConversionError("Read-only validation response has an invalid packageHash.")
    rows = []
    for row in results:
        if not isinstance(row, dict):
            continue
        rows.append({key: row[key] for key in ("filename", "sourceId", "status", "plannedAction", "issues") if key in row})
    return {
        "detail": response.get("detail"),
        "packageHash": response.get("packageHash", ""),
        "counts": counts,
        "results": rows,
    }


def _validate_output_path(source_root: Path, output_root: Path) -> None:
    source = source_root.resolve()
    output = output_root.resolve()
    if output == source or output in source.parents:
        raise ConversionError("--output must not be the source directory or an ancestor of it.")
    if output == Path(output.anchor):
        raise ConversionError("Refusing to use a filesystem root as --output.")
    if output == Path.home().resolve():
        raise ConversionError("Refusing to use the user home directory as --output.")


def _owned_output(path: Path) -> bool:
    marker = path / OUTPUT_MARKER
    if not marker.is_file() or marker.is_symlink():
        return False
    try:
        value = json.loads(marker.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError):
        return False
    return value == OUTPUT_MARKER_VALUE


def _install_output(temp_root: Path, output_root: Path) -> None:
    if not output_root.exists():
        temp_root.rename(output_root)
        return
    backup = output_root.parent / (".%s.backup-%s" % (output_root.name, uuid.uuid4().hex))
    output_root.rename(backup)
    try:
        temp_root.rename(output_root)
    except Exception:
        backup.rename(output_root)
        raise
    shutil.rmtree(str(backup), ignore_errors=True)


def convert(
    source_root: Path,
    output_root: Path,
    bundle: Dict[str, Any],
    force: bool = False,
    client: Optional[WordPressClient] = None,
    etag: str = "",
) -> Dict[str, Any]:
    raw_output = output_root.expanduser().absolute()
    if raw_output.is_symlink():
        raise ConversionError("Refusing to use a symbolic link as --output.")
    source_root = source_root.resolve()
    output_root = raw_output.resolve()
    if not source_root.is_dir():
        raise ConversionError("--source must be an existing directory.")
    _validate_output_path(source_root, output_root)
    if output_root.exists() and not force:
        raise ConversionError("Output already exists; pass --force to replace it explicitly.")
    if output_root.exists() and not output_root.is_dir():
        raise ConversionError("Existing --output is not a directory.")
    if output_root.exists() and force and not _owned_output(output_root):
        raise ConversionError(
            "Refusing to replace an existing directory not marked as Content Factory converter output."
        )
    output_root.parent.mkdir(parents=True, exist_ok=True)
    temp_root = Path(tempfile.mkdtemp(prefix=".%s.tmp-" % output_root.name, dir=str(output_root.parent)))
    try:
        gaps = GapRegistry()
        records = _build_registry(source_root, output_root, bundle, gaps)
        if not records:
            raise ConversionError("No Markdown source files were found.")
        initial_source_snapshot = {
            record.document.relative_path: record.source_hash for record in records
        }
        source_by_path = {record.expected_path: record.source_id for record in records if record.expected_path}
        internal_hosts = {
            parsed.hostname.lower()
            for record in records
            for parsed in [urlparse(clean_value(record.document.fields["url"] or record.document.fields["canonical"]))]
            if parsed.hostname
        }
        page_payloads: List[Tuple[str, bytes]] = []
        report_rows: List[Dict[str, Any]] = []
        for record in records:
            page, notes = _build_page(record, bundle, source_by_path, internal_hosts, gaps)
            base_issues: List[Dict[str, str]] = []
            semantic_issues: List[Dict[str, str]] = []
            output_file = ""
            output_hash = ""
            if page is not None:
                base_issues, semantic_issues = _page_validation(page, bundle)
                output_file = record.source_id + ".json"
                payload = pretty_json(page).encode("utf-8")
                (temp_root / output_file).write_bytes(payload)
                output_hash = sha256_bytes(payload)
                page_payloads.append((output_file, payload))
            else:
                base_issues = [{"code": "NO_PAGESPEC", "path": "/", "message": "Source could not produce a PageSpec document."}]
                semantic_issues = [{"code": "NO_PAGESPEC", "path": "/", "message": "Semantic validation could not run without a PageSpec document."}]
            report_rows.append({
                "sourceFile": record.document.relative_path,
                "externalId": record.external_id,
                "sourceId": record.source_id,
                "sourceSha256": record.source_hash,
                "pageType": record.page_type,
                "postTitle": record.document.fields["post_title"],
                "slug": record.slug,
                "parentSourceId": record.parent.source_id if record.parent else None,
                "expectedPath": record.expected_path,
                "canonicalSource": record.document.fields["canonical"],
                "outputFile": output_file,
                "outputSha256": output_hash,
                "schemaValidation": {"status": "valid" if not base_issues else "invalid", "issues": base_issues},
                "semanticValidation": {"status": "valid" if not semantic_issues else "invalid", "issues": semantic_issues},
                "excludedServiceContent": notes["excluded"],
                "warnings": notes["warnings"],
            })
        if not page_payloads:
            raise ConversionError("No PageSpec documents could be produced; inspect source content markers and Contract Bundle.")
        zip_path = temp_root / "pagespec.zip"
        _write_zip(zip_path, page_payloads)
        zip_hash = sha256_bytes(zip_path.read_bytes())
        remote: Dict[str, Any] = {"status": "skipped_offline", "readOnly": True, "published": False, "imported": False}
        if client:
            response = client.validate_zip(zip_path)
            summary = _summary_validation(response)
            if summary["packageHash"] != zip_hash:
                raise ConversionError("Runtime validation packageHash does not match the uploaded ZIP.")
            counts = summary.get("counts", {})
            incompatible = counts.get("incompatible") if isinstance(counts, dict) else None
            remote = {
                "status": "compatible" if incompatible == 0 else "incompatible",
                "readOnly": True,
                "published": False,
                "imported": False,
                "summary": summary,
            }
        gap_counts = {name: sum(1 for row in gaps.items if row["type"] == name) for name in GAP_TYPES}
        invalid_base = sum(row["schemaValidation"]["status"] != "valid" for row in report_rows)
        invalid_semantic = sum(row["semanticValidation"]["status"] != "valid" for row in report_rows)
        remote_incompatible = remote["status"] == "incompatible"
        source_files_modified = _source_snapshot(source_root, output_root) != initial_source_snapshot
        if source_files_modified:
            raise ConversionError("The Markdown source tree changed during conversion; output was not replaced.")
        status = "compatible" if not any(gap_counts.values()) and not invalid_base and not invalid_semantic and not remote_incompatible else "incompatible"
        for row in report_rows:
            page_gaps = [gap for gap in gaps.items if gap.get("sourceId") == row["sourceId"]]
            row["linksStatus"] = "gap" if any(gap["type"] == "LINK_GAP" for gap in page_gaps) else "resolved"
            row["assetsStatus"] = "gap" if any(gap["type"] == "ASSET_GAP" for gap in page_gaps) else "verified"
            row["validationStatus"] = "valid" if row["schemaValidation"]["status"] == "valid" and row["semanticValidation"]["status"] == "valid" else "invalid"
            row["postId"] = None
            row["importAction"] = None
        self_check_warnings = sum(1 for issue in bundle["selfCheck"].get("issues", []) if isinstance(issue, dict) and issue.get("severity") == "warning")
        page_warnings = sum(len(row["warnings"]) for row in report_rows)
        remote_warning_rows = remote.get("summary", {}).get("counts", {}).get("compatible_with_warnings", 0) if isinstance(remote.get("summary"), dict) else 0
        warning_count = self_check_warnings + page_warnings + (remote_warning_rows if isinstance(remote_warning_rows, int) else 0)
        error_count = sum(len(row["schemaValidation"]["issues"]) + len(row["semanticValidation"]["issues"]) for row in report_rows) + sum(gap_counts.values())
        identity = bundle["identity"]
        report = {
            "status": status,
            "target": {
                "siteKey": identity["siteKey"],
                "profileId": identity["profileId"],
                "profileVersion": identity["profileVersion"],
                "manifestHash": identity["manifestHash"],
                "siteDefaultsVersion": identity["siteDefaultsVersion"],
                "contractHash": bundle["contractHash"],
                "etag": etag,
                "selfCheck": bundle["selfCheck"],
            },
            "sourceCount": len(records),
            "pageCount": len(page_payloads),
            "schemaValidCount": len(report_rows) - invalid_base,
            "semanticValidCount": len(report_rows) - invalid_semantic,
            "errorCount": error_count,
            "warningCount": warning_count,
            "zip": {"file": "pagespec.zip", "sha256": zip_hash},
            "validation": remote,
            "gapCounts": gap_counts,
            "gaps": gaps.items,
            "pages": report_rows,
            "safety": {
                "conversionOnly": True,
                "wordpressWrites": False,
                "published": False,
                "confirmedBatchCreated": False,
                "sourceFilesModified": source_files_modified,
                "previewPerformed": False,
                "revalidatePerformed": False,
            },
        }
        (temp_root / OUTPUT_MARKER).write_text(pretty_json(OUTPUT_MARKER_VALUE), encoding="utf-8")
        (temp_root / "contract-bundle.json").write_text(pretty_json(bundle), encoding="utf-8")
        (temp_root / "conversion-report.json").write_text(pretty_json(report), encoding="utf-8")
        _install_output(temp_root, output_root)
        return report
    except Exception:
        shutil.rmtree(str(temp_root), ignore_errors=True)
        raise
