# Content Factory agent instructions

These rules apply to `wp-content/plugins/content-factory`.

## Contract boundaries

- Work only with PageSpec `1.1`.
- Obtain a fresh Contract Bundle immediately before conversion or fixture generation.
- Require exact `target.siteKey` + `target.profileId`; never select the first/active profile as an import fallback.
- Copy `generatedAgainst` from the same Contract Bundle response. Do not copy hashes from documentation.
- Treat `adapters/<id>/profile.json` as the only profile authoring source and `CompiledProfile` as the only runtime representation.
- Do not add a second manifest file, schema endpoint, profile normalizer, aliases, engine selector, shadow path, or old-version branch.
- Keep `manifestHash` and `_content_factory_manifest_hash`: these are current PageSpec/storage field names for the compiled contract hash.

## Conversion behavior

- Preserve Markdown order and section boundaries. One logical Markdown section remains one semantic section.
- Hero lead includes all content after H1 and before the next major heading.
- Do not merge adjacent headings or impose page/section/item count limits unless the current profile schema explicitly declares one.
- Choose `catalog`, `steps`, `faq`, `cta`, or `parent-link` when the content meaning matches; otherwise use `article`.
- The main form action targets `/forma-obratnoj-svyaz`; the site JavaScript opens the modal from that URL.
- Validate the whole package before any draft write. Batch import is always atomic.

## Implementation rules

- Keep profile compilation deterministic and request-local.
- Semantic fields must have an executable consumer or an explicit classification.
- Validate Registry block names, attributes, types, parent/allowedBlocks, theme assets, page template, links, and Gutenberg round-trip.
- Never publish from import code. Managed pages are created as drafts and published only through the guarded workflow.
- Do not rewrite unrelated user content or existing published pages during a contract refactor.
- A `generatedAgainst` mismatch is diagnostic because stored managed drafts may have an older snapshot; the current contract is still validated in full.

## Required verification

Run PHP lint and:

```bash
php wp-content/plugins/content-factory/tests/run.php
```

For intentional output changes, refresh snapshots explicitly and rerun the suite. Also verify `/contract`, `/validate`, atomic batch behavior, the admin upload flow, and at least one public preview on the local site.

Update `README.md`, `docs/current-state.md`, `docs/content-conversion.md`, and `docs/adapter-development.md` whenever the public contract or workflow changes.
