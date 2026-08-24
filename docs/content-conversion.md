# Markdown to PageSpec 1.1 conversion

## Input contract

Fetch the current Contract Bundle immediately before conversion. Build every page against that exact response:

```text
GET /wp-json/content-factory/v1/contract?siteKey=potolkinaveka40&profileId=potolki-inner
```

Set:

- `schemaVersion` to `1.1`;
- `target` from `identity.siteKey` and `identity.profileId`;
- `generatedAgainst` from `identity.profileId`, `identity.profileVersion`, and `identity.manifestHash`.

Do not infer fields not declared by `pageSpecSchema` or the semantic schema of the chosen section.

## Structural mapping

Preserve the Markdown document order and its logical section boundaries.

| Markdown meaning | PageSpec section | Rule |
| --- | --- | --- |
| H1 and content before the next major heading | `hero` | `lead` contains every introductory paragraph in order |
| Explanatory thematic section | `article` | Keep its heading and body nodes as one section |
| Collection of linked service/product cards | `catalog` | Each source card becomes one item |
| Ordered process or sequence | `steps` | Each source step becomes one item |
| Explicit questions and answers | `faq` | Each source Q/A pair becomes one item |
| Final or contextual conversion block | `cta` | Use the source position; do not force it to the end |
| Explicit navigation to the parent | `parent-link` | Add only when the Markdown contains it; automatic parent navigation is profile behavior |

Use `article` for a section that does not semantically fit a specialized block. Do not merge adjacent headings, regroup chapters, truncate lists, or impose conversion limits. Limits exist only when explicitly present in the current Contract Bundle schema.

## Hero and actions

`hero.data.lead` is an array containing all introductory paragraphs after H1 up to the next major heading. It is not limited to one or two paragraphs.

The primary form action should resolve to `/forma-obratnoj-svyaz`. The theme JavaScript intercepts this URL and opens the modal. Use a path link descriptor, not an anchor invented by the converter.

## Article body

Represent article content with supported structured nodes:

- `paragraph` with inline text;
- `heading` using profile-allowed levels;
- `list` with ordered/unordered style and source items;
- `buttons` with explicit action descriptors.

Preserve paragraph, heading, list, and button order. Inline emphasis and links use the supported text syntax; raw HTML, fenced code, and Markdown image syntax are rejected.

## Links and assets

Use typed link descriptors: `page`, `path`, `anchor`, `external`, `tel`, or `mailto`. Prefer `page.sourceId` for another page in the same package and `path` for an existing site route. Every anchor must match a section ID in the same page.

Use only asset descriptors and references advertised by the current contract. Catalog cards require images. Hero may use the profile's declared theme-asset fallback. Do not add arbitrary external images when the policy forbids them.

## Package workflow

Output one PageSpec object, a `{ "pages": [...] }` envelope, or a ZIP containing JSON files. Validate the complete package first. Resolve duplicate source IDs, paths, broken dependencies, unknown assets, and all blocking issues before confirmation. Import is atomic and always creates/updates drafts only.

## Agent prompt

A concise conversion request can be:

> Fetch the current Content Factory Contract Bundle for `potolkinaveka40/potolki-inner`. Convert the supplied Markdown articles to strict PageSpec 1.1. Preserve heading-to-heading section boundaries and source order; do not merge or truncate sections/items. Map specialized content only when its meaning matches catalog, steps, FAQ, CTA, or parent-link, otherwise use article. Put all text after H1 and before the next major heading into hero.lead. Use `/forma-obratnoj-svyaz` for the main modal-form action. Copy target and generatedAgainst from the same Contract Bundle, validate the complete package, and return JSON/ZIP without publishing.
