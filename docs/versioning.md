# Version sources and synchronization

Content Factory has several independent version axes. They must not be inferred from one another or copied into reusable prompts.

## Sources of truth

| Version axis | Source of truth | Runtime consumer |
| --- | --- | --- |
| Plugin release | `VersionRegistry::PLUGIN` | `CONTENT_FACTORY_VERSION`; the WordPress plugin header mirrors it |
| REST namespace | `VersionRegistry::REST_NAMESPACE` | REST route registration |
| Contract Bundle format | `VersionRegistry::CONTRACT_BUNDLE` | `ContractBundleBuilder` and the matching JSON Schema |
| PageSpec format | `VersionRegistry::PAGE_SPEC` | `PageSpecSchemaRegistry`, adapters, examples, and the matching JSON Schema |
| Theme-profile authoring schema | `VersionRegistry::THEME_PROFILE_SCHEMA` | `ProfileCompiler` and the matching JSON Schema |
| Operation-log storage schema | `VersionRegistry::OPERATION_LOG_DB` | `OperationLogger` migrations |
| Adapter profile release | `adapters/<profile-id>/profile.json` → `identity.profileVersion` | Compiled profile identity and `generatedAgainst` |
| Site-defaults release | `adapters/<profile-id>/profile.json` → `siteDefaults.version` | Compiled profile identity and draft idempotency |
| Minimum compatible theme release | `adapters/<profile-id>/profile.json` → `compatibility.theme.minVersion` | Adapter self-check |
| Minimum WordPress and PHP | `content-factory.php` plugin headers | WordPress plugin requirements |

`src/VersionRegistry.php` is the central registry for versions owned by plugin code. Profile releases and site-default versions remain in the profile authoring file because they are semantic data, not plugin protocol versions. Do not introduce a second manifest or duplicate profile identity in PHP.

## Generator rule

Reusable prompts and generated-content instructions must not contain numeric plugin, REST, Contract Bundle, PageSpec, profile-schema, adapter-profile, or site-default versions.

They must instead:

1. read this document and `src/VersionRegistry.php`;
2. run the version checker;
3. fetch a fresh Contract Bundle from the exact target runtime through the repository converter;
4. use `contractVersion`, `pageSpecVersion`, `identity.profileVersion`, `identity.siteDefaultsVersion`, and `identity.manifestHash` from that same response;
5. reject the run if the converter compatibility or any local schema identity differs from the plugin registry.

Do not copy versions from README files, examples, snapshots, prior generated JSON, or an earlier Contract Bundle.

## Required check

Run from the project root:

```sh
python3 tools/check_content_factory_versions.py
```

The command compares the central registry with:

- the WordPress plugin header;
- PageSpec, Contract Bundle, and profile-authoring schemas;
- the active profile definition;
- the Python converter compatibility boundary;
- semantic formatting of profile, site-defaults, theme-minimum, and operation-log versions.

It prints the resolved version inventory and exits non-zero on drift. The PHP suite performs the corresponding runtime assertions.

## Change procedure

1. Decide which version axis changed. Do not bump unrelated axes merely to keep numbers equal.
2. Change the authoritative value only at its source listed above.
3. Rename or update a versioned schema only when that schema format actually changes.
4. Update frozen fixtures and snapshots only when their public contract or generated output intentionally changes.
5. Run the version checker, Python converter tests, PHP lint, and the plugin suite.
6. Fetch `/contract` from the target runtime and verify that its advertised versions and identity match the checked local plugin.
7. Regenerate content packages from the fresh Contract Bundle; never patch version fields in generated JSON by hand.

The WordPress `Version:` header is an unavoidable mirror required for plugin discovery. The automated checks ensure it remains equal to `VersionRegistry::PLUGIN`.
