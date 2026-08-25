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

The hero note card uses the same modal route through the generated `noteUrl` block attribute. Content Factory supplies `/forma-obratnoj-svyaz` from the profile default; editors may replace that URL in the block toolbar later.

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

## Target-runtime preflight

Treat a package as environment-specific. Before building or importing it, record the exact WordPress base URL that will run validation, including its scheme, host, and port. Fetch the Contract Bundle from that runtime whenever possible.

Apply these checks before sending the ZIP:

1. Compute every hierarchical permalink from the planned parent and slug graph.
2. Set `seo.canonical` to the exact permalink expected by the validation runtime. Scheme, host, port, path, and trailing slash must match. A production canonical such as `https://example.com/path/` is not valid for a package being checked by `http://localhost:8080`.
3. Resolve every `path` descriptor against the target WordPress site or the same batch. A route handled only by theme JavaScript is still unresolved unless the corresponding WordPress path exists or is created by the batch.
4. Use `page.sourceId` for links and parents supplied by the same package. Verify that every referenced source ID exists exactly once and that all planned paths are unique.
5. Check external destinations intentionally. Use `external` only for a real cross-site link allowed by the current contract; do not use it merely to hide a missing internal route.
6. Validate the complete ZIP in one request. Do not validate pages individually when they contain parent or link dependencies.
7. Require zero incompatible pages before import. Warnings are non-blocking, but review them before confirming the import.
8. Import the exact unchanged file returned by validation together with its `packageHash`. Any package edit requires a new validation request.

The main modal action `/forma-obratnoj-svyaz` follows the same resolution rule. Confirm that this route exists on the target runtime before conversion. The theme's click interception does not by itself satisfy server-side link validation.

Do not overwrite a production artifact to make it pass on localhost. Create a separate environment-specific output directory and ZIP from the same source, then validate that copy against the local runtime. Keep the original Markdown and production package unchanged.

When many pages fail, fix the earliest root issue first. `CANONICAL_MISMATCH` and `UNRESOLVED_LINK` on a parent can cascade into `BATCH_PARENT_INCOMPATIBLE` or `BATCH_LINK_TARGET_INCOMPATIBLE` on otherwise valid descendants.

## Package workflow

Output one PageSpec object, a `{ "pages": [...] }` envelope, or a ZIP containing JSON files. Validate the complete package first. Resolve duplicate source IDs, paths, broken dependencies, unknown assets, and all blocking issues before confirmation. Import is atomic and always creates/updates drafts only.

## Agent prompt

A concise conversion request can be:

> Fetch the current Content Factory Contract Bundle for `potolkinaveka40/potolki-inner`. Convert the supplied Markdown articles to strict PageSpec 1.1. Preserve heading-to-heading section boundaries and source order; do not merge or truncate sections/items. Map specialized content only when its meaning matches catalog, steps, FAQ, CTA, or parent-link, otherwise use article. Put all text after H1 and before the next major heading into hero.lead. Use `/forma-obratnoj-svyaz` for the main modal-form action. Copy target and generatedAgainst from the same Contract Bundle, validate the complete package, and return JSON/ZIP without publishing.
