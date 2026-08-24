# Current state: Content Factory 2.0

## Supported format

The plugin accepts only PageSpec 1.1. `target` and `generatedAgainst` are mandatory. Profile selection is exact and has no configured/first-compatible fallback.

The single contract source is:

`GET /wp-json/content-factory/v1/contract?siteKey=potolkinaveka40&profileId=potolki-inner`

The response contains the PageSpec schema, semantic schemas, page types, safe defaults, assets, policies, examples, self-check, compiled profile identity, `manifestHash`, and `contractHash`.

## Profile runtime

`adapters/potolki-inner/profile.json` is validated by `theme-profile-1.0.schema.json` and compiled directly into `CompiledProfile`. The authoring-schema version and plugin/profile versions are separate concepts; the current plugin and profile release is 2.0.0.

Compilation derives:

- normalized section schemas and bindings;
- root composition and Registry contracts;
- public contract projection;
- deterministic canonical `manifestHash`.

There is one validation/build runtime. Named mappings handle hero, article, parent link, CTA, and content composition; generic profile bindings handle catalog, steps, and FAQ. All section data also passes the semantic schema validator.

## Validation and import

The pipeline checks:

- PageSpec 1.1 envelope and strict unknown fields;
- exact target and profile/theme compatibility;
- page type, occurrence rules, section schemas, content-specific rules;
- links, parent resolution, assets, block Registry, page template, and Yoast availability;
- WordPress sourceId/slug/path/canonical conflicts;
- Block Tree serialization, parse round-trip, and server render.

`generatedAgainst` drift is reported as a warning while the current contract is fully revalidated. This preserves revalidation of existing managed drafts without accepting an old schema or profile shape.

Batch import is unconditional atomic import. A validation error blocks every item. A runtime failure rolls back successful writes from the same batch and marks the operation failed. There is no PageSpec/page-count ceiling in JSON, ZIP, import, or selected publication; transport size guards remain in place for resource safety.

## Managed pages

Imports create or update drafts only. Idempotency compares source hash, compiled profile identity/hash/defaults, generated content, hierarchy, template, and Yoast metadata. Published managed pages cannot be overwritten by import. Publication is allowed only after revalidation and explicit confirmation.

## Removed compatibility surface

The codebase has no PageSpec 1.0 schema, manifest v1 file/schema/normalizer, optional compiled-profile interface, active-profile fallback, alias migration, engine mode, shadow comparison, partial batch mode, separate manifest/schema REST routes, or pilot profile plugin. The old `PageSpec1.0/` import artifacts were removed after the data load.

## Verification

The main suite is `tests/run.php`. Frozen snapshots cover both golden page types and a 49-page PageSpec 1.1 regression corpus. `tests/update-snapshots.php --update` is the only supported snapshot refresh path.
