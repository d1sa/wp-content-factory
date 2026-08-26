"""Parser for editorial Markdown exports used as Content Factory sources."""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Callable, Dict, List, Optional, Tuple


UUID_RE = re.compile(r"([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})(?=\.md$)", re.I)
UUID_SUFFIX_RE = re.compile(r"([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?=\.md$)", re.I)
CONTENT_MARKER_RE = re.compile(r"^#\s+(?:СТРУКТУРА|КОНТЕНТ)\s+СТРАНИЦЫ\s*$", re.M | re.I)
TOP_SECTION_RE = re.compile(r"^#\s+(?!#)(.+?)\s*$", re.M)
EXPORT_LINK_RE = re.compile(r"^\s*\[([^\]\n]+)\]\(([^\n)]*\.md(?:#[^\n)]*)?)\)\s*$", re.M | re.I)
INLINE_IMAGE_RE = re.compile(r"!\[([^\]]*)\]\(([^)]+)\)")
INLINE_LINK_RE = re.compile(r"(?<!!)\[([^\]]+)\]\(([^)]+)\)")

TECH_TOP_HEADINGS = (
    "МИНИМАЛЬНАЯ ПЕРЕЛИНКОВКА",
    "ПЕРЕЛИНКОВКА НА РОДИТЕЛЬСКОЙ СТРАНИЦЕ",
    "ДОПОЛНИТЕЛЬНАЯ ПЕРЕЛИНКОВКА В КОНЦЕ СТРАНИЦЫ",
    "СВЯЗАННЫЕ РАЗДЕЛЫ",
    "ВАЖНО ДЛЯ SEO",
    "ВАЖНО ПО СКРЫТОЙ НИШЕ",
    "ВАЖНО: НЕ СОЗДАЁМ SEO-ДУБЛИ",
)
TECH_H2_HEADINGS = (
    "ТЕХНИЧЕСКОЕ ЗАДАНИЕ",
    "ТЕХНИЧЕСКОЕ ЗАДАНИЕ НА БЛОК",
    "ВАЖНО ДЛЯ КОНТЕНТ-МЕНЕДЖЕРА",
    "ВАЖНО ДЛЯ SEO",
)
TECH_HEADING_ONLY = ("CTA-КАРТОЧКА", "ОТДЕЛЬНАЯ КАРТОЧКА", "ДВЕ ВСПОМОГАТЕЛЬНЫЕ КАРТОЧКИ")


@dataclass
class TopSection:
    title: str
    body: str


@dataclass
class MarkdownDocument:
    path: Path
    relative_path: str
    source_text: str
    metadata_text: str
    public_text: str
    sections: List[TopSection]
    fields: Dict[str, str]
    seo_fields: Dict[str, str]
    export_links: List[Dict[str, str]] = field(default_factory=list)
    excluded: List[Dict[str, str]] = field(default_factory=list)


def clean_value(value: str) -> str:
    result = value.strip()
    if len(result) >= 2 and result[0] == "`" and result[-1] == "`":
        result = result[1:-1]
    return result.strip()


def field_value(text: str, labels: List[str]) -> str:
    choices = "|".join(re.escape(label) for label in labels)
    match = re.search(r"\*\*(?:%s):\*\*\s*(?:\n\s*)?([^\n]+)" % choices, text, re.I)
    return clean_value(match.group(1)) if match else ""


def split_top_sections(body: str) -> List[TopSection]:
    matches = list(TOP_SECTION_RE.finditer(body))
    result: List[TopSection] = []
    for index, match in enumerate(matches):
        end = matches[index + 1].start() if index + 1 < len(matches) else len(body)
        result.append(TopSection(match.group(1).strip(), body[match.end():end].strip()))
    return result


def parse_document(path: Path, relative_path: str, source_bytes: Optional[bytes] = None) -> MarkdownDocument:
    raw = path.read_bytes() if source_bytes is None else source_bytes
    source = raw.decode("utf-8")
    marker = CONTENT_MARKER_RE.search(source)
    if marker:
        metadata = source[:marker.start()]
        public = source[marker.end():]
    else:
        metadata = source
        public = ""
    duplicate = re.search(r"^#\s+ПОДСКАЗКА ПО СОЗДАНИЮ СТРАНИЦЫ\s*$", public, re.M | re.I)
    excluded: List[Dict[str, str]] = []
    if duplicate:
        public = public[:duplicate.start()]
        excluded.append({"kind": "duplicate-export", "title": "Repeated document export"})
    export_links = [
        {"label": match.group(1).strip(), "target": clean_value(match.group(2))}
        for match in EXPORT_LINK_RE.finditer(source)
    ]
    public = EXPORT_LINK_RE.sub("", public).strip()
    seo_marker = re.search(r"^#\s+SEO-НАСТРОЙКИ\s*$", metadata, re.M | re.I)
    seo = metadata[seo_marker.end():] if seo_marker else metadata
    fields = {
        "post_title": field_value(metadata, ["Название страницы в WordPress"]),
        "slug": field_value(metadata, ["Slug"]),
        "url": field_value(metadata, ["URL страницы", "URL"]),
        "parent_url": field_value(metadata, ["Родительский URL"]),
        "parent_title": field_value(metadata, ["Родительская страница"]),
        "canonical": field_value(metadata, ["Canonical"]),
        "source_uuid": field_value(metadata, ["UUID", "Source UUID", "ID источника"]),
    }
    seo_fields = {
        "title": field_value(seo, ["Title"]),
        "description": field_value(seo, ["Description"]),
        "h1": field_value(seo, ["H1"]),
        "primary_keyword": field_value(seo, ["Основной запрос", "Primary keyword"]),
    }
    return MarkdownDocument(
        path=path,
        relative_path=relative_path,
        source_text=source,
        metadata_text=metadata,
        public_text=public,
        sections=split_top_sections(public),
        fields=fields,
        seo_fields=seo_fields,
        export_links=export_links,
        excluded=excluded,
    )


def paragraph_blocks(text: str) -> List[str]:
    blocks: List[str] = []
    for raw in re.split(r"\n\s*\n", text.strip()):
        lines = [line.strip() for line in raw.splitlines() if line.strip() and line.strip() != "---"]
        value = " ".join(lines)
        if value and not value.startswith("#") and not EXPORT_LINK_RE.fullmatch(value):
            blocks.append(value)
    return blocks


def extract_asset_directive(text: str) -> Tuple[str, Optional[Dict[str, str]]]:
    pattern = re.compile(r"^\s*\*\*(?:Изображение|Asset):\*\*\s*(?:\n\s*)?`?(?:themeAsset:)?([a-zA-Z0-9._-]+)`?\s*$", re.M | re.I)
    match = pattern.search(text)
    if not match:
        return text, None
    alt = field_value(text[match.end():match.end() + 300], ["Alt", "Альтернативный текст"])
    return (text[:match.start()] + text[match.end():]).strip(), {"ref": match.group(1), "alt": alt}


def card_sections(segment: str) -> List[str]:
    matches = list(re.finditer(r"^##\s+КАРТОЧКА\b.*$", segment, re.M | re.I))
    result: List[str] = []
    for index, match in enumerate(matches):
        end = matches[index + 1].start() if index + 1 < len(matches) else len(segment)
        result.append(segment[match.start():end])
    return result


def sanitize_inline(
    text: str,
    link_handler: Callable[[str, str], Optional[str]],
    image_handler: Callable[[str, str], str],
) -> str:
    text = INLINE_IMAGE_RE.sub(lambda match: image_handler(match.group(1), clean_value(match.group(2))), text)

    def link(match: re.Match) -> str:
        replacement = link_handler(match.group(1), clean_value(match.group(2)))
        return replacement if replacement is not None else match.group(1)

    return INLINE_LINK_RE.sub(link, text)


def article_nodes(
    raw: str,
    link_handler: Callable[[str, str], Optional[Dict[str, Any]]],
    inline_link_handler: Callable[[str, str], Optional[str]],
    image_handler: Callable[[str, str], str],
) -> Tuple[List[Dict[str, Any]], List[str]]:
    lines = raw.splitlines()
    nodes: List[Dict[str, Any]] = []
    excluded: List[str] = []
    paragraph: List[str] = []
    list_items: List[str] = []
    list_style = "unordered"
    skip_technical = False

    def inline(value: str) -> str:
        return sanitize_inline(value, inline_link_handler, image_handler)

    def flush_paragraph() -> None:
        if paragraph:
            value = " ".join(part.strip() for part in paragraph if part.strip())
            if value:
                nodes.append({"type": "paragraph", "text": inline(value)})
            paragraph[:] = []

    def flush_list() -> None:
        if list_items:
            nodes.append({"type": "list", "style": list_style, "items": [inline(item) for item in list_items]})
            list_items[:] = []

    index = 0
    while index < len(lines):
        line = lines[index].strip()
        heading = re.match(r"^(#{2,4})\s+(.+)$", line)
        if heading:
            heading_text = heading.group(2).strip()
            upper = heading_text.upper()
            if len(heading.group(1)) == 2 and upper in TECH_H2_HEADINGS:
                flush_paragraph()
                flush_list()
                skip_technical = True
                excluded.append(heading_text)
                index += 1
                continue
            if skip_technical and len(heading.group(1)) > 2:
                index += 1
                continue
            skip_technical = False
            if upper in TECH_HEADING_ONLY:
                excluded.append(heading_text)
                index += 1
                continue
            flush_paragraph()
            flush_list()
            level = 3 if len(heading.group(1)) <= 2 else 4
            nodes.append({"type": "heading", "level": level, "text": inline(heading_text)})
            index += 1
            continue
        if skip_technical:
            index += 1
            continue
        if not line:
            flush_paragraph()
            if list_items:
                probe = index + 1
                while probe < len(lines) and not lines[probe].strip():
                    probe += 1
                following = re.match(r"^([-*]|\d+\.)\s+(.+)$", lines[probe].strip()) if probe < len(lines) else None
                following_style = "ordered" if following and following.group(1)[0].isdigit() else "unordered"
                if not following or following_style != list_style:
                    flush_list()
            index += 1
            continue
        if line == "---":
            flush_paragraph()
            flush_list()
            index += 1
            continue
        list_match = re.match(r"^([-*]|\d+\.)\s+(.+)$", line)
        if list_match:
            flush_paragraph()
            style = "ordered" if list_match.group(1)[0].isdigit() else "unordered"
            if list_items and style != list_style:
                flush_list()
            list_style = style
            list_items.append(list_match.group(2).strip())
            index += 1
            continue
        label = re.match(r"^\*\*(.+?):\*\*$", line)
        if label:
            name = label.group(1).strip().upper()
            if name in ("КНОПКА", "ОСНОВНАЯ КНОПКА", "ВТОРАЯ КНОПКА"):
                flush_paragraph()
                flush_list()
                action_label = ""
                target = ""
                consumed = index
                probe = index + 1
                while probe < len(lines) and not lines[probe].strip():
                    probe += 1
                if probe < len(lines):
                    action_label = clean_value(lines[probe])
                    consumed = probe
                    probe += 1
                while probe < min(len(lines), index + 10):
                    candidate = lines[probe].strip()
                    if re.match(r"^\*\*(?:URL|Ссылка):\*\*$", candidate, re.I):
                        probe += 1
                        while probe < len(lines) and not lines[probe].strip():
                            probe += 1
                        target = clean_value(lines[probe]) if probe < len(lines) else ""
                        consumed = probe
                        break
                    if candidate.startswith("##"):
                        break
                    probe += 1
                descriptor = link_handler(action_label, target)
                if descriptor:
                    nodes.append({"type": "buttons", "items": [{"label": action_label, "link": descriptor}]})
                elif action_label:
                    nodes.append({"type": "paragraph", "text": action_label})
                index = consumed + 1
                continue
            if name in ("ЗАГОЛОВОК", "ЗАГОЛОВОК КАРТОЧКИ", "НАЗВАНИЕ"):
                flush_paragraph()
                flush_list()
                value_index = index + 1
                while value_index < len(lines) and not lines[value_index].strip():
                    value_index += 1
                if value_index < len(lines):
                    nodes.append({"type": "heading", "level": 3, "text": inline(clean_value(lines[value_index]))})
                index = value_index + 1
                continue
            if name in ("ОПИСАНИЕ", "ТЕКСТ", "ТЕКСТ КАРТОЧКИ"):
                value_index = index + 1
                while value_index < len(lines) and not lines[value_index].strip():
                    value_index += 1
                if value_index < len(lines):
                    paragraph.append(clean_value(lines[value_index]))
                index = value_index + 1
                continue
            if name in ("URL", "ССЫЛКА", "ССЫЛКА / ДЕЙСТВИЕ ФОРМЫ", "ФОРМА", "ТЕЛЕФОН", "ИЗОБРАЖЕНИЕ", "ASSET", "ALT", "АЛЬТЕРНАТИВНЫЙ ТЕКСТ"):
                value_index = index + 1
                while value_index < len(lines) and not lines[value_index].strip():
                    value_index += 1
                index = value_index + 1
                continue
        flush_list()
        paragraph.append(line)
        index += 1
    flush_paragraph()
    flush_list()
    return nodes, excluded
