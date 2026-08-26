# Content Factory

Content Factory validates semantic PageSpec packages and creates reviewable Gutenberg drafts. It does not generate copy, and no import endpoint publishes pages.

## Safe workflow

1. Run the version checker and fetch a fresh Contract Bundle from the exact target WordPress runtime.
2. Convert the complete Markdown tree with the repository converter.
3. Review its report and read-only validation result.
4. Import the exact validated ZIP only after explicit confirmation.
5. Review drafts; publish through the separate guarded workflow.

The Contract Bundle is the only public source for the current schema, profile identity, page types, semantic sections, defaults, assets, and policies. Every PageSpec must use its exact target and `generatedAgainst` values. Packages are atomic: one blocking failure prevents or rolls back the whole batch.

The dependent converter is colocated in [`tools/`](tools/). Use [`tools/README.md`](tools/README.md) for converter and import commands. Do not assemble version fields or packages by hand.

## Main REST routes

All routes use the namespace declared by `VersionRegistry::REST_NAMESPACE` and require the plugin capabilities.

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/contract?siteKey=…&profileId=…` | Current public conversion contract |
| POST | `/validate` | Read-only validation of JSON or ZIP |
| POST | `/pages/batch` | Atomic creation/update of a confirmed draft package |
| GET | `/pages` and `/pages/{sourceId}` | Managed-page status and details |
| POST | `/pages/{sourceId}/revalidate` | Revalidate stored source and output |
| POST | `/pages/publish-selected` | Guarded publication after confirmation |
| GET | `/operations` | Operation audit log |

`/contract` replaces separate public manifest and schema endpoints. Published WordPress pages remain ordinary Gutenberg content.

## Documentation map

| Document | Responsibility |
| --- | --- |
| [Versioning](docs/versioning.md) | Version owners, synchronization, checks, and bump procedure |
| [Content conversion](docs/content-conversion.md) | Supported Markdown dialect, mapping, links/assets, and package readiness |
| [Reusable article prompt](docs/article-generation-prompt.md) | Inputs and instructions for new articles or strict conversion |
| [Adapter development](docs/adapter-development.md) | Profile authoring, mappers, adapter boundaries, and tests |
| [Integration boundaries](docs/current-state.md) | Compact theme/plugin responsibility map |
| [Converter CLI](tools/README.md) | Commands, outputs, offline mode, and confirmed draft import |

## Verification

Run inside the WordPress runtime:

```bash
php wp-content/plugins/content-factory/tests/run.php
```

Snapshots are immutable during normal tests. Refresh them only after an intentional contract/output change:

```bash
php wp-content/plugins/content-factory/tests/update-snapshots.php --update
```
