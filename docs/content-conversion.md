# Markdown conversion

This document owns the Markdown dialect and the readiness rules for repository-generated PageSpec packages. CLI syntax and artifact descriptions live in [`tools/README.md`](../tools/README.md); version rules live in [versioning.md](versioning.md).

## Contract setup

Before conversion, run the version checker and let the repository converter fetch a fresh Contract Bundle from the exact target runtime. The converter uses that response for:

- `schemaVersion` from `pageSpecVersion`;
- exact `target.siteKey` and `target.profileId`;
- `generatedAgainst.profileId`, `profileVersion`, and `manifestHash`;
- page types, semantic schemas, defaults, assets, and policies.

Do not infer fields absent from the advertised PageSpec or semantic schemas. Do not copy versions or hashes from documentation, examples, earlier packages, or an old Contract Bundle.

## Source file structure

The converter reads a single service area followed by public content:

```md
# ПОДСКАЗКА ПО СОЗДАНИЮ СТРАНИЦЫ

**UUID:**
`<UUID_V4>`

**Название страницы в WordPress:**
<TITLE>

**Родительская страница:**
<PARENT_TITLE_OR_НЕТ>

**Родительский URL:**
`<PARENT_PATH>`

**URL страницы:**
`<DECLARED_PATH_OR_URL>`

**Slug:**
`<SLUG>`

# SEO-НАСТРОЙКИ

**Title:**
<SEO_TITLE>

**Description:**
<SEO_DESCRIPTION>

**H1:**
<PUBLIC_H1>

**Основной запрос:**
<PRIMARY_QUERY>

# СТРУКТУРА СТРАНИЦЫ

# <PUBLIC_H1>

<INTRODUCTORY_PARAGRAPHS>
```

`# КОНТЕНТ СТРАНИЦЫ` is also accepted as the public-content marker. The UUID in metadata and the UUID immediately before `.md`, when both are present, must be identical. Keep it stable across revisions.

The directory tree defines hierarchy. A child normally lives in the directory named after its parent Markdown file; the converter may also resolve `Родительский URL` against another source in the same complete tree. `URL страницы` or `Canonical` is used only to derive and diagnose the declared source path. The current converter does not emit `seo.canonical` into PageSpec.

## Supported public Markdown dialect

The converter is syntax-driven; headings must use the forms below. It does not classify a section from prose meaning alone.

| Source syntax | Output |
| --- | --- |
| First public `#` heading and all following paragraphs up to the next `#` | `hero`; every paragraph becomes one `lead` item |
| Any later ordinary `#` section | `article` |
| A section containing at least two `## 1. …`, `## 2. …` headings | `steps` |
| `# ЧАСТЫЕ ВОПРОСЫ…` or exactly `# FAQ`, with questions as `##` | `faq` |
| A section containing `## КАРТОЧКА …` blocks | `catalog` |
| A `#` heading containing `ФИНАЛЬН`, starting with `CTA`, or containing `ЗАЯВК` | `cta` |

Inside an article, paragraphs, `##`–`####` headings, ordered/unordered lists, and recognized button fields are supported. Raw HTML, fenced code, and Markdown images cannot be represented safely in article content and produce gaps. Inline links are restricted by the current resolver and contract; the only supported same-page anchor is `#request`.

Catalog cards use named fields such as `Заголовок`, `Описание`, `Кнопка`, `URL`/`Ссылка`, plus an explicit advertised asset:

```md
## КАРТОЧКА 1

**Заголовок:**
<TITLE>

**Описание:**
<TEXT>

**Кнопка:**
<LABEL>

**URL:**
<TARGET>

**Изображение:**
`themeAsset:<CONTRACT_ASSET_REF>`

**Alt:**
<ALT_TEXT>
```

The converter creates no `parent-link` semantic section. When `post.parent` exists, the profile supplies parent navigation during Block Tree construction.

## Content preservation and exclusions

For existing articles, preserve all ready public wording and its order. Do not shorten, summarize, rewrite, merge sections, or truncate paragraphs, lists, cards, steps, or FAQ items. Normal Markdown line wrapping may be normalized during parsing.

The service area before the public marker is not publishable. Recognized SEO assignments and standalone Markdown export links are excluded; export links are still checked as source dependencies.

Inside public content, exclusion is deliberately narrow. Only the exact top-level headings `МИНИМАЛЬНАЯ ПЕРЕЛИНКОВКА`, `ПЕРЕЛИНКОВКА НА РОДИТЕЛЬСКОЙ СТРАНИЦЕ`, `ДОПОЛНИТЕЛЬНАЯ ПЕРЕЛИНКОВКА В КОНЦЕ СТРАНИЦЫ`, `СВЯЗАННЫЕ РАЗДЕЛЫ`, `ВАЖНО ДЛЯ SEO`, `ВАЖНО ПО СКРЫТОЙ НИШЕ`, and `ВАЖНО: НЕ СОЗДАЁМ SEO-ДУБЛИ` are service sections. The exact level-two headings `ТЕХНИЧЕСКОЕ ЗАДАНИЕ`, `ТЕХНИЧЕСКОЕ ЗАДАНИЕ НА БЛОК`, `ВАЖНО ДЛЯ КОНТЕНТ-МЕНЕДЖЕРА`, and `ВАЖНО ДЛЯ SEO` are service subsections. Other headings beginning with words such as `ВАЖНО` or `КОММЕНТАР` remain public. Inspect every explicit exclusion in `excludedServiceContent`.

A CTA with `Форма` is converted to the plugin's `form` variant and cannot carry action URLs. The `links` variant requires two actions with resolvable links. A single linked action, a URL on a form action, or a third action is retained in a blocking gap instead of being silently discarded.

Do not invent facts, prices, guarantees, contacts, URLs, assets, or missing content. Record unresolved requirements as `CONTENT_GAP`, `LINK_GAP`, `ASSET_GAP`, or `ADAPTER_GAP`.

## Links and assets

- Prefer links to another source in the same complete tree; they resolve by `page.sourceId`.
- A relative/internal path must resolve to the same batch. Unknown internal routes produce `LINK_GAP`.
- Use external links only for intentional cross-site destinations allowed by the contract.
- Use only asset refs advertised by the fresh Contract Bundle. Catalog images must be explicit; hero may use the contract's fallback.
- The generated hero action targets `/forma-obratnoj-svyaz`. That path must resolve on the target WordPress runtime; JavaScript interception alone does not satisfy server-side validation.

Fix the earliest root dependency error first: a broken parent can cascade into child incompatibilities.

## Package readiness

The repository converter emits one PageSpec JSON per source and a deterministic `pagespec.zip` containing only those JSON files. Validate the complete ZIP, not dependent pages individually.

A new package is ready for the repository CLI import workflow only when:

- JSON/schema and semantic validation pass;
- every gap count is zero;
- runtime validation reports zero incompatible pages;
- parent and page-link dependencies resolve;
- the source tree was not modified during conversion;
- the exact ZIP and returned `packageHash` are retained unchanged.

The plugin REST API treats warnings as non-blocking. The repository CLI intentionally applies a stricter safety policy and refuses import while `compatible_with_warnings` is nonzero. Review every warning and regenerate the package after fixing avoidable ones; never patch generated JSON by hand.

Conversion and validation are read-only. Draft import and publication require separate explicit actions. The reusable end-to-end prompt is [article-generation-prompt.md](article-generation-prompt.md).
