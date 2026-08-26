from __future__ import annotations

import contextlib
import hashlib
import io
import json
import re
import shutil
import sys
import tempfile
import unittest
import urllib.parse
import urllib.request
from email.parser import BytesParser
from email.policy import default
from email.message import Message
from pathlib import Path
from typing import Any, Dict, List, Tuple
from unittest.mock import patch


TOOLS = Path(__file__).resolve().parents[1]
if str(TOOLS) not in sys.path:
    sys.path.insert(0, str(TOOLS))

from content_factory_converter.cli import CONFIRM_PHRASE, main
from content_factory_converter.contract import ContractError, contract_hash, occurrence, validate_contract
from content_factory_converter.converter import ConversionError, convert, stable_source_id
from content_factory_converter.http import HttpError, SameOriginRedirectHandler, WordPressClient
from content_factory_converter.markdown import article_nodes, parse_document
from content_factory_converter.schema import validate as validate_schema
from test_support import FIXTURES, make_contract


def tree_hashes(root: Path) -> Dict[str, str]:
    return {
        path.relative_to(root).as_posix(): hashlib.sha256(path.read_bytes()).hexdigest()
        for path in sorted(root.rglob("*")) if path.is_file()
    }


def copy_source(target: Path) -> Path:
    source = target / "source"
    shutil.copytree(str(FIXTURES / "source"), str(source))
    return source


class MarkdownParserTests(unittest.TestCase):
    def test_category_fixture_separates_metadata_service_text_and_export_links(self) -> None:
        path = next((FIXTURES / "source").glob("Категория *.md"))
        document = parse_document(path, path.name)
        self.assertEqual("Категория услуг", document.fields["post_title"])
        self.assertEqual("Категория услуг", document.seo_fields["h1"])
        self.assertIn("Первый публичный вводный абзац", document.public_text)
        self.assertNotIn("Это задание не должно попасть", document.public_text)
        self.assertNotIn("Экспортная ссылка", document.public_text)
        self.assertEqual(1, len(document.export_links))

    def test_detail_fixture_keeps_all_public_markdown(self) -> None:
        path = next((FIXTURES / "source").rglob("Деталь *.md"))
        document = parse_document(path, path.name)
        self.assertIn("СИНИЙ МАЯК", document.public_text)
        self.assertIn("Первый пункт списка без сокращения", document.public_text)
        self.assertIn("Полный текст второго шага", document.public_text)

    def test_blank_lines_between_list_items_keep_one_list_node(self) -> None:
        nodes, excluded = article_nodes(
            "Вводный текст.\n\n- кухня;\n\n- ванная;\n\n- прихожая.\n\n1. первый шаг\n\n2. второй шаг\n\nЗавершение.",
            lambda _label, _target: None,
            lambda label, _target: label,
            lambda alt, _target: alt,
        )
        self.assertEqual([], excluded)
        self.assertEqual(["paragraph", "list", "list", "paragraph"], [node["type"] for node in nodes])
        self.assertEqual("unordered", nodes[1]["style"])
        self.assertEqual(["кухня;", "ванная;", "прихожая."], nodes[1]["items"])
        self.assertEqual("ordered", nodes[2]["style"])
        self.assertEqual(["первый шаг", "второй шаг"], nodes[2]["items"])

    def test_missing_uuid_uses_deterministic_fallback(self) -> None:
        first, external = stable_source_id("nested/page.md", "page.md")
        second, _ = stable_source_id("nested/page.md", "page.md")
        changed_content_same_path, _ = stable_source_id("nested/page.md", "page.md")
        self.assertIsNone(external)
        self.assertEqual(first, second)
        self.assertEqual(first, changed_content_same_path)
        self.assertRegex(first, r"^md-[a-f0-9]{32}$")
        explicit, external = stable_source_id("renamed/page.md", "page.md", "AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA")
        self.assertEqual("seo-aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa", explicit)
        self.assertEqual("aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa", external)

    def test_uuid_must_be_v4_and_match_filename(self) -> None:
        with self.assertRaisesRegex(ConversionError, "UUID v4"):
            stable_source_id("page.md", "page.md", "aaaaaaaa-aaaa-1aaa-8aaa-aaaaaaaaaaaa")
        with self.assertRaisesRegex(ConversionError, "Filename UUID suffix"):
            stable_source_id(
                "page-aaaaaaaa-aaaa-1aaa-8aaa-aaaaaaaaaaaa.md",
                "page-aaaaaaaa-aaaa-1aaa-8aaa-aaaaaaaaaaaa.md",
            )
        with self.assertRaisesRegex(ConversionError, "must match"):
            stable_source_id(
                "page-bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb.md",
                "page-bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb.md",
                "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa",
            )


class ContractTests(unittest.TestCase):
    def test_contract_hash_and_self_check_are_verified(self) -> None:
        bundle = make_contract()
        self.assertEqual(bundle, validate_contract(bundle))
        tampered = json.loads(json.dumps(bundle))
        tampered["identity"]["profileVersion"] = "9.9.9"
        with self.assertRaises(ContractError):
            validate_contract(tampered)
        incompatible = make_contract()
        incompatible["selfCheck"] = {"status": "incompatible", "issues": [{"severity": "error"}]}
        incompatible["contractHash"] = contract_hash(incompatible)
        with self.assertRaises(ContractError):
            validate_contract(incompatible)
        weak_schema = make_contract()
        weak_schema["pageSpecSchema"]["properties"]["schemaVersion"].pop("const")
        weak_schema["contractHash"] = contract_hash(weak_schema)
        with self.assertRaises(ContractError):
            validate_contract(weak_schema)

    def test_modal_trigger_path_must_be_a_plain_trailing_slash_path(self) -> None:
        for value in ("/forma-obratnoj-svyaz", "https://example.test/forma-obratnoj-svyaz/", "/forma-obratnoj-svyaz/?from=hero"):
            bundle = make_contract()
            bundle["policies"]["modalTriggerPath"] = value
            bundle["contractHash"] = contract_hash(bundle)
            with self.assertRaisesRegex(ContractError, "modalTriggerPath"):
                validate_contract(bundle)

    def test_missing_max_means_no_upper_limit(self) -> None:
        bundle = make_contract()
        self.assertIsNone(occurrence(bundle, "detail-page", "article")["max"])
        self.assertIsNone(occurrence(bundle, "detail-page", "steps")["max"])
        self.assertIsNone(occurrence(bundle, "detail-page", "faq")["max"])
        self.assertIsNone(occurrence(bundle, "detail-page", "catalog")["max"])
        self.assertEqual(0, occurrence(bundle, "detail-page", "unknown")["max"])


class ConversionTests(unittest.TestCase):
    def test_offline_cli_uses_contract_file_and_creates_expected_artifacts(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            source = copy_source(root)
            contract_file = root / "contract.json"
            contract_file.write_text(json.dumps(make_contract(), ensure_ascii=False), encoding="utf-8")
            output = root / "output"
            stdout = io.StringIO()
            with contextlib.redirect_stdout(stdout):
                code = main([
                    "convert", "--source", str(source), "--output", str(output),
                    "--contract-file", str(contract_file),
                ])
            self.assertEqual(0, code)
            self.assertTrue((output / "pagespec.zip").is_file())
            self.assertTrue((output / "conversion-report.json").is_file())
            self.assertIn('"wordpressWrites": false', stdout.getvalue())

    def test_category_and_detail_convert_to_schema_valid_pages_and_deterministic_zip(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            source = copy_source(root)
            first = root / "first"
            second = root / "second"
            report1 = convert(source, first, make_contract())
            report2 = convert(source, second, make_contract())
            self.assertEqual("compatible", report1["status"])
            self.assertEqual(2, report1["pageCount"])
            self.assertEqual(report1, report2)
            self.assertEqual((first / "pagespec.zip").read_bytes(), (second / "pagespec.zip").read_bytes())
            bundle = make_contract()
            pages = []
            for path in sorted(first.glob("*.json")):
                if path.name in ("conversion-report.json", "contract-bundle.json"):
                    continue
                page = json.loads(path.read_text(encoding="utf-8"))
                pages.append(page)
                self.assertEqual([], validate_schema(page, bundle["pageSpecSchema"]))
            detail = next(page for page in pages if page["pageType"] == "detail-page")
            rendered = json.dumps(detail, ensure_ascii=False)
            self.assertIn("СИНИЙ МАЯК", rendered)
            self.assertIn("Первый пункт списка без сокращения", rendered)
            self.assertNotIn("контент-менеджеру", rendered.lower())
            self.assertNotIn("Техническое задание", rendered)
            category = next(page for page in pages if page["pageType"] == "category-page")
            hero = category["sections"][0]
            self.assertEqual("hero", hero["type"])
            self.assertEqual(3, len(hero["data"]["lead"]))
            self.assertIn("Третий публичный вводный абзац", hero["data"]["lead"][2])
            self.assertEqual(
                {"kind": "path", "path": "/forma-obratnoj-svyaz/"},
                hero["data"]["primaryAction"]["link"],
            )
            detail_cta = next(section for section in detail["sections"] if section["type"] == "cta")
            self.assertEqual("form", detail_cta["data"]["variant"])
            self.assertNotIn("link", detail_cta["data"]["primaryAction"])
            self.assertEqual({"sourceId": category["sourceId"]}, detail["post"]["parent"])

    def test_unbounded_sections_and_items_preserve_markdown_order_without_merging(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            source = copy_source(root)
            detail_path = next(source.rglob("Деталь *.md"))
            text = detail_path.read_text(encoding="utf-8")
            extra_articles = "\n\n".join(
                "# ТЕМА %02d\n\nПолный текст темы %02d." % (index, index)
                for index in range(1, 13)
            )
            extra_steps = "\n\n".join(
                "## %d. Шаг %d\n\nПолный текст шага %d." % (index, index, index)
                for index in range(1, 10)
            )
            extra_faq = "\n\n".join(
                "## Вопрос %d?\n\nПолный ответ %d." % (index, index)
                for index in range(1, 12)
            )
            text = re.sub(
                r"# ПОДРОБНОЕ ОПИСАНИЕ.*?(?=\n# КАК ПРОХОДИТ РАБОТА)",
                extra_articles + "\n\n# ПОДБОР РЕШЕНИЯ\n\n## КАРТОЧКА 1 — Вариант\n\n**Заголовок:**\nВариант для детали\n\n**Описание:**\nПолное описание варианта.\n\n**Кнопка:**\nПодробнее\n\n**URL:**\n`/catalog/`\n\n**Изображение:**\n`themeAsset:card-detail`\n\n**Alt:**\nИллюстрация варианта\n",
                text,
                flags=re.S,
            )
            text = re.sub(
                r"# КАК ПРОХОДИТ РАБОТА.*?(?=\n# ЧАСТЫЕ ВОПРОСЫ)",
                "# КАК ПРОХОДИТ РАБОТА\n\n" + extra_steps,
                text,
                flags=re.S,
            )
            text = re.sub(
                r"# ЧАСТЫЕ ВОПРОСЫ.*?(?=\n# ФИНАЛЬНЫЙ БЛОК)",
                "# ЧАСТЫЕ ВОПРОСЫ\n\n" + extra_faq,
                text,
                flags=re.S,
            )
            cta = re.search(r"# ФИНАЛЬНЫЙ БЛОК.*$", text, re.S)
            self.assertIsNotNone(cta)
            before_cta = text[:cta.start()]
            content_start = before_cta.index("# ТЕМА 01")
            text = before_cta[:content_start] + cta.group(0) + "\n\n" + before_cta[content_start:]
            detail_path.write_text(text, encoding="utf-8")

            report = convert(source, root / "output", make_contract())
            self.assertEqual("compatible", report["status"])
            page_path = next((root / "output").glob("seo-22222222-*.json"))
            page = json.loads(page_path.read_text(encoding="utf-8"))
            types = [section["type"] for section in page["sections"]]
            self.assertEqual(["hero", "cta"] + ["article"] * 12 + ["catalog", "steps", "faq"], types)
            article_titles = [section["data"]["title"] for section in page["sections"] if section["type"] == "article"]
            self.assertEqual(["ТЕМА %02d" % index for index in range(1, 13)], article_titles)
            steps = next(section for section in page["sections"] if section["type"] == "steps")
            faq = next(section for section in page["sections"] if section["type"] == "faq")
            catalog = next(section for section in page["sections"] if section["type"] == "catalog")
            self.assertEqual(9, len(steps["data"]["items"]))
            self.assertEqual(11, len(faq["data"]["items"]))
            self.assertEqual("Вариант для детали", catalog["data"]["items"][0]["title"])

    def test_unknown_link_is_preserved_for_diagnostics_while_unknown_asset_is_not_invented(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            source = copy_source(root)
            category = next(source.glob("Категория *.md"))
            category.write_text(category.read_text(encoding="utf-8").replace("themeAsset:card-detail", "themeAsset:not-in-contract"), encoding="utf-8")
            detail = next(source.rglob("Деталь *.md"))
            detail.write_text(detail.read_text(encoding="utf-8").replace(
                "# КАК ПРОХОДИТ РАБОТА",
                "# ДОПОЛНИТЕЛЬНОЕ ДЕЙСТВИЕ\n\n**Кнопка:**\nНеизвестная страница\n\n**URL:**\n`https://example.test/does-not-exist/`\n\n# КАК ПРОХОДИТ РАБОТА",
            ), encoding="utf-8")
            report = convert(source, root / "output", make_contract())
            self.assertEqual("incompatible", report["status"])
            self.assertGreater(report["gapCounts"]["LINK_GAP"], 0)
            self.assertGreater(report["gapCounts"]["ASSET_GAP"], 0)
            all_json = "\n".join(path.read_text(encoding="utf-8") for path in (root / "output").glob("seo-*.json"))
            self.assertNotIn('"ref": "not-in-contract"', all_json)
            self.assertIn('"path": "/does-not-exist/"', all_json)
            self.assertIn("Неизвестная страница", all_json)

    def test_repeat_requires_force_and_preserves_sources_and_unrelated_wordpress_file(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            source = copy_source(root)
            wordpress_sentinel = root / "wordpress-posts.snapshot"
            wordpress_sentinel.write_text("posts must remain unchanged", encoding="utf-8")
            before = tree_hashes(source)
            output = root / "output"
            first = convert(source, output, make_contract())
            with self.assertRaises(ConversionError):
                convert(source, output, make_contract())
            self.assertEqual(before, tree_hashes(source))
            self.assertEqual("posts must remain unchanged", wordpress_sentinel.read_text(encoding="utf-8"))
            second = convert(source, output, make_contract(), force=True)
            self.assertEqual(first, second)
            self.assertEqual(before, tree_hashes(source))
            self.assertFalse(second["safety"]["wordpressWrites"])

    def test_force_refuses_unowned_existing_directory(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            source = copy_source(root)
            output = root / "unrelated"
            output.mkdir()
            sentinel = output / "sentinel.txt"
            sentinel.write_text("keep", encoding="utf-8")
            with self.assertRaisesRegex(ConversionError, "not marked"):
                convert(source, output, make_contract(), force=True)
            self.assertEqual("keep", sentinel.read_text(encoding="utf-8"))

    def test_force_refuses_output_symlink_without_touching_target(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            source = copy_source(root)
            protected = root / "protected"
            protected.mkdir()
            sentinel = protected / "sentinel.txt"
            sentinel.write_text("keep", encoding="utf-8")
            output_link = root / "output"
            try:
                output_link.symlink_to(protected, target_is_directory=True)
            except (OSError, NotImplementedError):
                self.skipTest("symlinks are unavailable")
            with self.assertRaises(ConversionError):
                convert(source, output_link, make_contract(), force=True)
            self.assertEqual("keep", sentinel.read_text(encoding="utf-8"))

    def test_converter_has_no_exact_49_file_limit(self) -> None:
        template = """# ПОДСКАЗКА ПО СОЗДАНИЮ СТРАНИЦЫ
\n**Название страницы в WordPress:**\nPage {i}\n\n**URL страницы:**\n`https://example.test/page-{i}/`\n\n**Slug:**\n`page-{i}`\n\n# SEO-НАСТРОЙКИ\n\n**Title:**\nPage {i} title\n\n**Description:**\nPage {i} description\n\n**H1:**\nPage {i}\n\n# КОНТЕНТ СТРАНИЦЫ\n\n# Page {i}\n\nPublic lead for page {i}.\n\n# ARTICLE\n\nComplete public article for page {i}.\n\n# ЧАСТЫЕ ВОПРОСЫ\n\n## Q1?\n\nA1.\n\n## Q2?\n\nA2.\n\n## Q3?\n\nA3.\n\n# ФИНАЛЬНЫЙ БЛОК\n\n## Contact\n\nPublic CTA text.\n\n**Форма:**\nForm\n\n**Кнопка:**\nSend\n"""
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            source = root / "source"
            source.mkdir()
            for index in range(50):
                (source / ("page-%02d.md" % index)).write_text(template.format(i=index), encoding="utf-8")
            report = convert(source, root / "output", make_contract())
            self.assertEqual(50, report["sourceCount"])
            self.assertEqual(50, report["pageCount"])
            self.assertEqual("compatible", report["status"])

    def test_public_important_and_comment_headings_are_not_excluded_by_prefix(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            source = copy_source(root)
            detail = next(source.rglob("Деталь *.md"))
            detail.write_text(
                detail.read_text(encoding="utf-8").replace(
                    "# КАК ПРОХОДИТ РАБОТА",
                    "# ВАЖНО: правила ухода\n\nПУБЛИЧНЫЙ ТЕКСТ О ПРАВИЛАХ УХОДА.\n\n# КОММЕНТАРИЙ ПО МОНТАЖУ\n\nПУБЛИЧНЫЙ КОММЕНТАРИЙ ДЛЯ ЧИТАТЕЛЯ.\n\n# КАК ПРОХОДИТ РАБОТА",
                ),
                encoding="utf-8",
            )
            report = convert(source, root / "output", make_contract())
            self.assertEqual("compatible", report["status"])
            rendered = "\n".join(path.read_text(encoding="utf-8") for path in (root / "output").glob("seo-*.json"))
            self.assertIn("ПУБЛИЧНЫЙ ТЕКСТ О ПРАВИЛАХ УХОДА", rendered)
            self.assertIn("ПУБЛИЧНЫЙ КОММЕНТАРИЙ ДЛЯ ЧИТАТЕЛЯ", rendered)

    def test_single_link_cta_preserves_url_and_blocks_unsupported_variant(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            source = copy_source(root)
            detail = next(source.rglob("Деталь *.md"))
            detail.write_text(
                detail.read_text(encoding="utf-8").replace(
                    "**Форма:**\nСтандартная форма сайта\n\n**Кнопка:**\nОтправить заявку",
                    "**Кнопка:**\nПерейти в каталог\n\n**URL:**\n`/catalog/`",
                ),
                encoding="utf-8",
            )
            report = convert(source, root / "output", make_contract())
            self.assertEqual("incompatible", report["status"])
            self.assertGreater(report["gapCounts"]["ADAPTER_GAP"], 0)
            page = json.loads(next((root / "output").glob("seo-22222222-*.json")).read_text(encoding="utf-8"))
            cta = next(section for section in page["sections"] if section["type"] == "cta")
            self.assertEqual("links", cta["data"]["variant"])
            self.assertEqual({"kind": "page", "sourceId": "seo-11111111-1111-4111-8111-111111111111"}, cta["data"]["primaryAction"]["link"])

    def test_unresolved_internal_cta_paths_are_preserved_for_runtime_diagnostics(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            source = copy_source(root)
            category = next(source.glob("Категория *.md"))
            category.write_text(
                category.read_text(encoding="utf-8").replace(
                    "**Форма:**\nСтандартная форма сайта\n\n**Кнопка:**\nПолучить консультацию",
                    "**Основная кнопка:**\nПосмотреть цены\n\n**URL:**\n`/ceny/`\n\n**Вторая кнопка:**\nБесплатный замер\n\n**URL:**\n`/besplatnyj-zamer/`",
                ),
                encoding="utf-8",
            )
            report = convert(source, root / "output", make_contract())
            self.assertEqual("incompatible", report["status"])
            link_gaps = [gap for gap in report["gaps"] if gap["type"] == "LINK_GAP"]
            self.assertEqual({"/ceny/", "/besplatnyj-zamer/"}, {gap.get("target") for gap in link_gaps})
            page = json.loads(next((root / "output").glob("seo-11111111-*.json")).read_text(encoding="utf-8"))
            cta = next(section for section in page["sections"] if section["type"] == "cta")
            self.assertEqual({"kind": "path", "path": "/ceny/"}, cta["data"]["primaryAction"]["link"])
            self.assertEqual({"kind": "path", "path": "/besplatnyj-zamer/"}, cta["data"]["secondaryAction"]["link"])

    def test_inline_form_ignores_legacy_modal_route_without_opening_modal(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            source = copy_source(root)
            detail = next(source.rglob("Деталь *.md"))
            detail.write_text(
                detail.read_text(encoding="utf-8").replace(
                    "**Кнопка:**\nОтправить заявку",
                    "**Кнопка:**\nОтправить заявку\n\n**Ссылка / действие формы:**\n`/forma-obratnoj-svyaz/`",
                ),
                encoding="utf-8",
            )
            report = convert(source, root / "output", make_contract())
            self.assertEqual("compatible", report["status"])
            self.assertEqual(0, report["gapCounts"]["ADAPTER_GAP"])
            page = json.loads(next((root / "output").glob("seo-22222222-*.json")).read_text(encoding="utf-8"))
            cta = next(section for section in page["sections"] if section["type"] == "cta")
            self.assertEqual("form", cta["data"]["variant"])
            self.assertEqual({"label": "Отправить заявку"}, cta["data"]["primaryAction"])

    def test_inline_form_keeps_other_action_urls_blocking(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            source = copy_source(root)
            detail = next(source.rglob("Деталь *.md"))
            detail.write_text(
                detail.read_text(encoding="utf-8").replace(
                    "**Кнопка:**\nОтправить заявку",
                    "**Кнопка:**\nОтправить заявку\n\n**Ссылка / действие формы:**\n`/custom-form-handler/`",
                ),
                encoding="utf-8",
            )
            report = convert(source, root / "output", make_contract())
            self.assertEqual("incompatible", report["status"])
            gaps = [gap for gap in report["gaps"] if gap["type"] == "ADAPTER_GAP"]
            self.assertEqual(1, len(gaps))
            self.assertEqual("/custom-form-handler/", gaps[0]["target"])

    def test_third_cta_action_is_reported_instead_of_silently_dropped(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            source = copy_source(root)
            detail = next(source.rglob("Деталь *.md"))
            detail.write_text(
                detail.read_text(encoding="utf-8").replace(
                    "**Форма:**\nСтандартная форма сайта\n\n**Кнопка:**\nОтправить заявку",
                    "**Основная кнопка:**\nКаталог\n\n**URL:**\n`/catalog/`\n\n**Вторая кнопка:**\nПозвонить\n\n**URL:**\n`tel:+70000000000`\n\n**Кнопка:**\nНаписать\n\n**URL:**\n`mailto:test@example.test`",
                ),
                encoding="utf-8",
            )
            report = convert(source, root / "output", make_contract())
            extra = [gap for gap in report["gaps"] if gap.get("label") == "Написать"]
            self.assertEqual(1, len(extra))
            self.assertEqual("mailto:test@example.test", extra[0]["target"])

    def test_duplicate_expected_path_is_rejected_before_link_mapping(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            source = copy_source(root)
            detail = next(source.rglob("Деталь *.md"))
            duplicate_uuid = "33333333-3333-4333-8333-333333333333"
            duplicate = detail.with_name("Дубликат %s.md" % duplicate_uuid)
            duplicate.write_text(
                detail.read_text(encoding="utf-8")
                .replace("22222222-2222-4222-8222-222222222222", duplicate_uuid)
                .replace("https://example.test/catalog/detail/", "https://example.test/catalog/detail-copy/"),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(ConversionError, "Duplicate expected path"):
                convert(source, root / "output", make_contract())


class FakeWordPressClient:
    def __init__(self, bundle: Dict[str, Any], wordpress_url: str = "https://example.test"):
        self.bundle = bundle
        self.wordpress_url = wordpress_url
        self.calls: List[Tuple[str, Any]] = []

    def fetch_contract(self, site_key: str = "potolkinaveka40", profile_id: str = "potolki-inner") -> Tuple[Dict[str, Any], str]:
        self.calls.append(("fetch_contract", None))
        return self.bundle, '"%s"' % self.bundle["contractHash"]

    def validate_zip(self, path: Path) -> Dict[str, Any]:
        self.calls.append(("validate", path.read_bytes()))
        package_hash = "sha256:" + hashlib.sha256(path.read_bytes()).hexdigest()
        return {
            "detail": "summary",
            "packageHash": package_hash,
            "counts": {"total": 2, "compatible": 2, "compatible_with_warnings": 0, "incompatible": 0},
            "results": [{"status": "compatible"}, {"status": "compatible"}],
        }

    def import_zip(self, path: Path, validated_hash: str) -> Dict[str, Any]:
        self.calls.append(("import", {"bytes": path.read_bytes(), "validatedHash": validated_hash, "mode": "atomic", "confirmed": True}))
        return {
            "mode": "atomic",
            "counts": {"total": 2, "created": 2, "updated": 0, "no_change": 0, "failed": 0},
            "results": [{"action": "created"}, {"action": "created"}],
        }


class NetworkSafetyTests(unittest.TestCase):
    def test_online_conversion_only_fetches_contract_and_calls_read_only_validation(self) -> None:
        bundle = make_contract()
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            source = copy_source(root)
            client = FakeWordPressClient(bundle)
            fetched, etag = client.fetch_contract()
            report = convert(source, root / "output", validate_contract(fetched, etag), client=client, etag=etag)
            self.assertEqual("compatible", report["status"])
            self.assertEqual(["fetch_contract", "validate"], [name for name, _ in client.calls])
            self.assertFalse(report["safety"]["confirmedBatchCreated"])

    def test_import_is_dry_run_by_default_and_atomic_only_after_exact_confirmation(self) -> None:
        bundle = make_contract()
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            source = copy_source(root)
            convert(source, root / "output", bundle)
            zip_path = root / "output/pagespec.zip"
            validated_hash = "sha256:" + hashlib.sha256(zip_path.read_bytes()).hexdigest()
            wordpress = FakeWordPressClient(bundle)
            with patch("content_factory_converter.cli.WordPressClient", return_value=wordpress):
                stdout = io.StringIO()
                with contextlib.redirect_stdout(stdout):
                    code = main(["import", "--zip", str(zip_path), "--wordpress-url", wordpress.wordpress_url, "--validated-hash", validated_hash])
                self.assertEqual(0, code)
                self.assertIn('"confirmed": false', stdout.getvalue())
                self.assertEqual(["validate"], [name for name, _ in wordpress.calls])
            wordpress = FakeWordPressClient(bundle)
            with patch("content_factory_converter.cli.WordPressClient", return_value=wordpress):
                stdout = io.StringIO()
                with contextlib.redirect_stdout(stdout):
                    code = main([
                        "import", "--zip", str(zip_path), "--wordpress-url", wordpress.wordpress_url,
                        "--validated-hash", validated_hash, "--execute", "--confirm-import", CONFIRM_PHRASE,
                    ])
                self.assertEqual(0, code)
                self.assertEqual(["validate", "import"], [name for name, _ in wordpress.calls])
                import_call = wordpress.calls[-1][1]
                self.assertTrue(import_call["confirmed"])
                self.assertEqual("atomic", import_call["mode"])
                self.assertEqual(validated_hash, import_call["validatedHash"])

    def test_import_rejects_malformed_success_response(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            source = copy_source(root)
            convert(source, root / "output", make_contract())
            zip_path = root / "output/pagespec.zip"
            validated_hash = "sha256:" + hashlib.sha256(zip_path.read_bytes()).hexdigest()
            wordpress = FakeWordPressClient(make_contract())
            wordpress.import_zip = lambda path, digest: {"status": "error", "message": "database write failed"}  # type: ignore[method-assign]
            stderr = io.StringIO()
            with patch("content_factory_converter.cli.WordPressClient", return_value=wordpress), contextlib.redirect_stderr(stderr):
                code = main([
                    "import", "--zip", str(zip_path), "--wordpress-url", wordpress.wordpress_url,
                    "--validated-hash", validated_hash, "--execute", "--confirm-import", CONFIRM_PHRASE,
                ])
            self.assertEqual(2, code)
            self.assertIn("unsuccessful status", stderr.getvalue())

    def test_source_tree_addition_during_runtime_validation_blocks_install(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            source = copy_source(root)
            wordpress = FakeWordPressClient(make_contract())
            original_validate = wordpress.validate_zip

            def mutate_then_validate(path: Path) -> Dict[str, Any]:
                (source / "added.md").write_text("new source", encoding="utf-8")
                return original_validate(path)

            wordpress.validate_zip = mutate_then_validate  # type: ignore[method-assign]
            with self.assertRaisesRegex(ConversionError, "source tree changed"):
                convert(source, root / "output", make_contract(), client=wordpress)
            self.assertFalse((root / "output").exists())

    def test_http_transport_rejects_remote_plain_http_and_cross_origin_discovery(self) -> None:
        with self.assertRaisesRegex(HttpError, "Plain HTTP"):
            WordPressClient("http://example.test")
        self.assertEqual("http://localhost:8080/", WordPressClient("http://localhost:8080").base_url)

        for body, link in (
            (b"", '<https://attacker.example/wp-json/>; rel="https://api.w.org/"'),
            (b'<link rel="https://api.w.org/" href="https://attacker.example/wp-json/">', ""),
        ):
            client = WordPressClient("https://example.test")
            headers = Message()
            if link:
                headers.add_header("Link", link)
            client._request = lambda request, body=body, headers=headers: (body, headers)  # type: ignore[assignment]
            with self.assertRaisesRegex(HttpError, "same scheme, host, and port"):
                client._discover_rest_root()

    def test_redirect_handler_rejects_cross_origin_target(self) -> None:
        handler = SameOriginRedirectHandler(("https", "example.test", 443))
        request = urllib.request.Request("https://example.test/wp-json/")
        request.add_header("Authorization", "Basic SECRET")
        with self.assertRaisesRegex(HttpError, "cross-origin redirect"):
            handler.redirect_request(request, None, 302, "Found", Message(), "https://attacker.example/collect")

    def test_real_rest_client_uses_summary_validate_and_confirmed_multipart(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            zip_path = Path(raw) / "pagespec.zip"
            zip_path.write_bytes(b"exact zip bytes")
            client = WordPressClient("https://example.test")
            client._rest_root = "https://example.test/index.php?rest_route=/"
            requests = []

            def intercepted(request: Any) -> Tuple[bytes, Message]:
                requests.append(request)
                return json.dumps({"packageHash": "sha256:" + "a" * 64, "counts": {"incompatible": 0}}).encode("utf-8"), Message()

            client._request = intercepted  # type: ignore[assignment]
            client.validate_zip(zip_path)
            client.import_zip(zip_path, "sha256:" + "a" * 64)
            validate_request, import_request = requests
            validate_query = urllib.parse.parse_qs(urllib.parse.urlparse(validate_request.full_url).query)
            import_query = urllib.parse.parse_qs(urllib.parse.urlparse(import_request.full_url).query)
            self.assertEqual(["summary"], validate_query["detail"])
            self.assertEqual("/content-factory/v1/validate", validate_query["rest_route"][0])
            self.assertEqual("/content-factory/v1/pages/batch", import_query["rest_route"][0])
            self.assertNotIn(b"confirmed", validate_request.data)
            message = BytesParser(policy=default).parsebytes(
                ("Content-Type: %s\r\nMIME-Version: 1.0\r\n\r\n" % import_request.headers["Content-type"]).encode("ascii") + import_request.data
            )
            fields = {}
            for part in message.iter_parts():
                name = part.get_param("name", header="content-disposition")
                if name and name != "file":
                    fields[name] = part.get_payload(decode=True).decode("utf-8")
            self.assertEqual("true", fields["confirmed"])
            self.assertNotIn("mode", fields)
            self.assertEqual("sha256:" + "a" * 64, fields["validatedHash"])


if __name__ == "__main__":
    unittest.main()
