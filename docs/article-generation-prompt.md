# Reusable prompt for articles and Content Factory packages

Use the prompt below for either new article generation or strict conversion of existing Markdown. Replace every value in `<ANGLE_BRACKETS>`. Protocol and profile versions are deliberately absent: [versioning.md](versioning.md) and the fresh Contract Bundle own them.

````text
You are working in the `potolki-wp` project. Prepare Russian-language editorial Markdown and a validated Content Factory PageSpec ZIP. Stop before draft import and publication.

## Inputs

- Project root: `<PROJECT_ROOT>`
- Mode: `<GENERATE_NEW_OR_CONVERT_EXISTING>`
- Read-only source Markdown tree: `<SOURCE_MARKDOWN_DIR>`
- Complete writable Markdown working tree: `<MARKDOWN_WORKTREE_DIR>`
- New PageSpec output directory: `<PAGESPEC_OUTPUT_DIR>`
- Exact target WordPress runtime: `<TARGET_WORDPRESS_URL>`
- Exact Content Factory target: `siteKey=<SITE_KEY>`, `profileId=<PROFILE_ID>`
- Article brief or conversion scope: `<BRIEF_OR_FILE_LIST>`
- Parent page/path: `<PARENT_CONTEXT_OR_NONE>`
- Primary and supporting queries: `<QUERY_CLUSTER>`
- Verified facts, offers, locations, and restrictions: `<VERIFIED_FACTS>`
- Required internal links: `<REQUIRED_INTERNAL_LINKS>`
- Approved asset refs, if already known: `<APPROVED_ASSET_REFS_OR_NONE>`

If a required value cannot be derived safely from these inputs, the repository, or the target runtime, report the exact gap. Do not invent facts, prices, guarantees, certifications, contacts, statistics, URLs, links, or assets.

## Required sources and workflow

1. Read:
   - `wordpress/wp-content/plugins/content-factory/AGENTS.md`
   - `wordpress/wp-content/plugins/content-factory/docs/versioning.md`
   - `wordpress/wp-content/plugins/content-factory/docs/content-conversion.md`
   - `tools/README.md`
2. Run the version checker required by `versioning.md`. Stop on drift.
3. Follow `content-conversion.md` as the normative source for Markdown syntax, hierarchy, content exclusions, links, assets, gaps, and readiness.
4. Follow `tools/README.md` for converter commands and artifacts. Let the repository converter obtain a fresh Contract Bundle from the exact target runtime. Do not type, infer, or copy protocol versions, profile versions, hashes, REST namespaces, schemas, page types, defaults, assets, or policies.
5. Work only in a complete copy at `<MARKDOWN_WORKTREE_DIR>`. Keep `<SOURCE_MARKDOWN_DIR>` unchanged. Do not replace an existing working or output directory unless replacement of that exact path was explicitly requested.

## Mode-specific behavior

When mode is `GENERATE_NEW`:

- use the existing corpus only as a reference for format, hierarchy, tone, and depth;
- create one stable UUID v4 per new page and put the identical UUID in metadata and immediately before `.md` in its filename;
- write for the requested search intent without competing with an existing page;
- use only verified claims from the inputs;
- keep the SEO title concise, avoid keyword stuffing, and align metadata H1 with the first public H1;
- add any necessary parent-page links only to the working copy.

When mode is `CONVERT_EXISTING`:

- preserve every piece of ready public text, its order, and its logical section boundaries;
- do not shorten, summarize, paraphrase, polish, merge, or truncate it;
- exclude only the service material recognized by `content-conversion.md`: SEO assignments, technical instructions, content-manager comments, and standalone Markdown export links;
- retain the source UUID as the stable source identity. If no UUID exists, use the converter's deterministic fallback; do not invent a replacement merely to change identity.

In both modes, keep service metadata before the public-content marker and publishable text after it. Use only the supported Markdown dialect. Do not put raw HTML, Gutenberg markup, fenced code, or Markdown images into public article content.

## Conversion and acceptance

Run the repository converter against the complete working tree and a new output directory. Inspect `conversion-report.json` and the read-only runtime validation summary.

Fix source Markdown or verified inputs, then regenerate, until:

- each source produced one syntactically valid strict PageSpec JSON;
- `target` and `generatedAgainst` came from the same fresh Contract Bundle;
- all source IDs and planned paths are unique;
- every GAP count is zero;
- local schema and semantic validation pass;
- runtime validation reports zero incompatible pages;
- `compatible_with_warnings` is zero for a CLI-ready package; if this run ends at read-only validation with warnings, list them and mark the package as not ready for CLI import;
- the generated ZIP contains only PageSpec JSON files;
- source files were unchanged, WordPress writes are false, and publication is false.

Never repair generated JSON manually. Fix the source or converter, generate a new output, and validate the complete ZIP again. Diagnose root parent/link failures before their descendant cascade errors.

## Deliverables

Return:

1. created or changed Markdown files inside the working copy;
2. PageSpec output directory;
3. exact `pagespec.zip` path;
4. exact `conversion-report.json` path;
5. Contract Bundle identity and validated `packageHash`;
6. compatible, compatible-with-warnings, and incompatible counts;
7. remaining warnings or blockers with source files;
8. confirmation that original sources were unchanged and that no drafts were created or published.

Stop after successful read-only validation. Import drafts or publish only after a separate explicit user request.
````

Run generation separately for different target runtimes or output destinations. A validated package is immutable; do not rewrite it in place for another environment.
