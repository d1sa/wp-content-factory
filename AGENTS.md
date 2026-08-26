# Content Factory agent instructions

These rules apply to `wp-content/plugins/content-factory`.

## Read the document that owns the topic

- Versions and synchronization: [docs/versioning.md](docs/versioning.md).
- Markdown grammar, conversion, links, assets, and readiness criteria: [docs/content-conversion.md](docs/content-conversion.md).
- Profile authoring and adapter/runtime boundaries: [docs/adapter-development.md](docs/adapter-development.md).
- Converter commands and artifacts: [`tools/README.md`](../../../../tools/README.md).
- Cross-theme integration boundaries: [docs/current-state.md](docs/current-state.md).

Do not restate these rules in another document. Update the owning document and verify its incoming links.

## Contract guardrails

- Support only the PageSpec version declared by `VersionRegistry::PAGE_SPEC` and a fresh Contract Bundle. Never put numeric protocol/profile versions or copied hashes in reusable prompts.
- Require exact `target.siteKey` and `target.profileId`; never select the first or active profile as a fallback.
- Copy `generatedAgainst` from the same Contract Bundle used for conversion.
- Treat `adapters/<profile-id>/profile.json` as the only profile authoring source and `CompiledProfile` as the only runtime representation.
- Do not add a second manifest, schema endpoint, profile normalizer, alias, engine selector, shadow path, or old-version branch.
- Keep `manifestHash` and `_content_factory_manifest_hash`; they are current public/storage field names for the compiled contract hash.

## Runtime guardrails

- Keep profile compilation deterministic and request-local.
- Give every semantic field an executable consumer or an explicit classification.
- Validate Registry block names, attributes and types, `parent`, `allowedBlocks`, theme assets, page template, links, and Gutenberg round-trip.
- Validate a complete package before any write. Batch import is atomic.
- Import creates or updates managed drafts only. Publication remains a separate guarded action.
- Do not rewrite unrelated user content or existing published pages during contract work.
- Treat `generatedAgainst` drift as diagnostic for stored drafts; always validate them against the full current contract.

## Required verification

Run the version checker, relevant Python converter tests, PHP lint, and:

```bash
php wp-content/plugins/content-factory/tests/run.php
```

Refresh snapshots only for an intentional output change. For public workflow or contract changes, also verify the Contract Bundle, read-only validation, atomic import behavior, the admin upload flow, and a public preview. Update only the documentation that owns the changed behavior.
