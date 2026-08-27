# Compiled profile development

## Source of truth

Each production adapter has one authoring file:

`adapters/<profile-id>/profile.json`

It is validated by the schema selected through `VersionRegistry::THEME_PROFILE_SCHEMA` and compiled by `ProfileCompiler`. Do not create a parallel runtime manifest or a second compatibility representation. See [versioning.md](versioning.md) before changing any version axis.

The profile defines:

1. `profileSchemaVersion`;
2. exact `identity` (`siteKey`, `profileId`, semantic `profileVersion`);
3. theme compatibility;
4. post defaults;
5. page types and section occurrences;
6. semantic section schemas;
7. executable bindings/mappers;
8. root composition;
9. versioned site defaults;
10. public theme assets and policies.

PageSpec version support is global and comes from `VersionRegistry::PAGE_SPEC`; it is not repeated in each profile.

## Adapter interface

`ThemeAdapterInterface` requires identity, theme compatibility, `compiled_profile()`, self-check, semantic validation, and Block Tree build. Every registered adapter must expose the same current runtime contract; adapters without a compiled profile are invalid.

Profile selection for imports uses only exact `target.siteKey` + `target.profileId`. Do not add options, constants, filters, or registration order as a fallback selector.

## Bindings

A section binding declares its root block, mapper, root attributes, optional repeated child block/attributes, and allowed children. Generic mappings use registered transforms. Named mappings must be executable code and must declare attribute inventory through `MapperDefinitionRegistry`.

Every semantic field must be consumed by a mapper or explicitly classified as validation-only/control/extension. The contract auditor rejects silent-loss drift, unknown Registry attributes, type/enum mismatches, invalid parent relations, and allowedBlocks drift.

Theme-facing attributes such as the inner hero's `noteUrl` must be present in the named mapper inventory, emitted by the adapter, and represented by a versioned site default when existing generated pages need a stable fallback.

## Versioning and hashes

Bump `identity.profileVersion` for a public semantic/profile contract change. `ProfileCompiler` computes `manifestHash` from the canonical public projection; object-key order does not change the hash, while meaningful list/order/schema changes do.

The Contract Bundle adds its own `contractHash` and ETag. Generators copy the compiled profile identity and hash into PageSpec `generatedAgainst`.

## Runtime boundaries

An adapter owns profile-specific semantic validation and Block Tree construction: section data, content-node structure, FAQ uniqueness, occurrence/order rules, asset policy, mapper/Registry compatibility, and semantic link resolution through `LinkResolver`.

`ContentPipeline` orchestrates core PageSpec validation, target context, hierarchy and WordPress conflict checks, page-template checks, serialization, Gutenberg parse round-trip, server rendering, and creation of one page's `BuildPlan`. `BatchRunner` validates the complete dependency graph and owns atomic writes and rollback. Adapter build must reject invalid semantic input; the pipeline verifies the resulting output.

Keep current content defaults and declared asset fallbacks separate from compatibility code; they are normal profile semantics.

## Testing checklist

- profile authoring schema passes;
- compile result and hash are deterministic;
- exact target selects one adapter; missing/wrong target fails;
- a non-current PageSpec version fails explicitly;
- Contract Bundle contains no secrets/private paths and examples validate;
- every section and CTA variant builds correctly;
- Registry attributes/parents/allowedBlocks match;
- golden Block Tree and post-content snapshots pass;
- regression corpus hashes pass;
- batch validation and runtime failure leave no partial writes;
- draft idempotency, rollback, revalidation, unrestricted native publication, and confirmed bulk publication pass.

Run:

```bash
php wp-content/plugins/content-factory/tests/run.php
```

Refresh snapshots only for intentional output changes:

```bash
php wp-content/plugins/content-factory/tests/update-snapshots.php --update
```
