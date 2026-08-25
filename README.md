# Content Factory

Content Factory 2.0 validates semantic PageSpec 1.1 JSON and creates reviewable Gutenberg drafts. The plugin does not generate copy and never publishes from an import endpoint.

## Current contract

- Input format: PageSpec `1.1` only.
- Every PageSpec must contain exact `target.siteKey` and `target.profileId`.
- Every PageSpec must contain `generatedAgainst.profileId`, `profileVersion`, and `manifestHash` copied from one Contract Bundle snapshot.
- The only production profile source is `adapters/<profile-id>/profile.json`; it is compiled request-locally into `CompiledProfile`.
- `manifestHash` is the canonical hash of the compiled public profile contract. The field name remains part of PageSpec 1.1.
- Batches are always atomic: any validation or runtime failure prevents or rolls back the entire package.
- The runtime does not impose a PageSpec/page-count limit. File-size and ZIP uncompressed-size guards remain transport safety limits.

The bundled profile is `potolki-inner` 2.1.0 for `siteKey=potolkinaveka40`. Its hero contract includes the editable `noteUrl` card action, defaulting to `/forma-obratnoj-svyaz`.

## Workflow

1. Fetch the current Contract Bundle:

   `GET /wp-json/content-factory/v1/contract?siteKey=potolkinaveka40&profileId=potolki-inner`

2. Convert Markdown to PageSpec 1.1 using `pageSpecSchema`, `semanticProfileSchema`, `pageTypes`, defaults, assets, examples, and guidance from that response.
3. Validate the complete JSON/ZIP package with `POST /validate`.
4. Review the summary. Use `detail=full` only for a technical report.
5. Create drafts with `POST /pages/batch` and `confirmed=true`. The same uploaded file and its validated package hash are sent again.
6. Review drafts and publish only through the guarded publish endpoint.

## REST API

All routes use namespace `content-factory/v1` and require the plugin capabilities.

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/contract?siteKey=…&profileId=…` | Current PageSpec schema and compiled profile contract |
| POST | `/validate` | Read-only validation of JSON or multipart JSON/ZIP |
| POST | `/pages` | Create/update one managed draft |
| POST | `/pages/batch` | Atomic create/update of a confirmed package |
| GET | `/pages` | List managed pages |
| GET | `/pages/{sourceId}` | Managed page details |
| POST | `/pages/{sourceId}/revalidate` | Revalidate stored source and generated content |
| POST | `/pages/publish-selected` | Guarded publication after confirmation |
| GET | `/operations` | Operation audit log |

`/contract` is the sole public contract source. PageSpec schemas and profile configuration are not served through separate compatibility endpoints.

## Architecture

- `ProfileCompiler` validates the authoring profile, derives block contracts, and computes a deterministic canonical hash.
- `ProfileSelector` selects one compatible adapter only by exact PageSpec target.
- `CorePageSpecValidator` validates the PageSpec 1.1 envelope.
- `PotolkiInnerAdapter` performs profile validation and builds the Block Tree through one runtime.
- `ContentPipeline` resolves links, hierarchy, WordPress conflicts, Gutenberg serialization, render checks, and build plan.
- `BatchRunner` validates the full dependency graph and applies it atomically.
- `DraftManager` and `PublishManager` protect managed-page state and idempotency.

Published WordPress pages are ordinary Gutenberg content. Removing support for old input formats does not rewrite existing pages.

## Tests

Run inside the local WordPress container:

```bash
php wp-content/plugins/content-factory/tests/run.php
```

The suite covers strict PageSpec 1.1 validation, exact target selection, profile compilation, Contract Bundle safety, Registry audit, Gutenberg parse/serialize/render, atomic rollback, draft idempotency, publish guards, and a 49-page single-runtime regression corpus.

Snapshots are immutable during normal tests. Refresh them only after an intentional contract/output change:

```bash
php wp-content/plugins/content-factory/tests/update-snapshots.php --update
```

See [current-state.md](docs/current-state.md), [content-conversion.md](docs/content-conversion.md), and [adapter-development.md](docs/adapter-development.md).
