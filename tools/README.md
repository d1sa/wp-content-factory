# Content Factory Markdown converter

`content_factory.py` converts editorial Markdown exports into separate strict
JSON files for the plugin's current PageSpec contract and a deterministic `pagespec.zip`. Conversion never
imports or publishes WordPress content.

The converter treats the target runtime's Contract Bundle as the only source of
PageSpec schema, identity, profile version, manifest hash, page types, semantic
sections, assets, defaults, and policies. It does not read a second local
contract representation.

Run the commands below from `wordpress/wp-content/plugins/content-factory`, the plugin root. The tools are dependent development/operations utilities shipped beside the plugin; plugin runtime code does not load them.

## Online conversion

Authentication is read only from environment variables and is never written to
PageSpec, reports, or commands:

```sh
export CONTENT_FACTORY_USER='admin'
export CONTENT_FACTORY_APP_PASSWORD='set-outside-the-repository'

python3 tools/content_factory.py convert \
  --source 'SEO pages' \
  --output '/tmp/content-factory-pagespec' \
  --wordpress-url 'http://localhost:8080'
```

The command discovers the WordPress REST root, fetches the Contract Bundle through
the REST namespace checked against the local plugin registry for the exact default target
`potolkinaveka40/potolki-inner`, creates JSON and ZIP files, validates every JSON
against the advertised PageSpec schema and semantic profile, then submits
the ZIP only to read-only `/validate?detail=summary`.

Remote WordPress URLs must use HTTPS. Plain HTTP is accepted only for loopback hosts such as `localhost`, `127.0.0.1`, and `::1`. REST discovery and redirects must remain on the exact original scheme, host, and port so an Application Password cannot be forwarded to another origin.

An existing output directory is never removed implicitly. `--force` replaces only a directory carrying the converter-owned `.content-factory-output` marker from an earlier successful run; it refuses unrelated directories:

```sh
python3 tools/content_factory.py convert \
  --source 'SEO pages' \
  --output '/tmp/content-factory-pagespec' \
  --wordpress-url 'http://localhost:8080' \
  --force
```

## Offline conversion

Use a freshly saved, unchanged Contract Bundle obtained through a trusted channel. Its canonical `contractHash`,
PageSpec version, identity, self-check, and absence of secret-like data are
verified before conversion.

`contractHash` verifies internal integrity, not origin or authenticity: anyone who can replace an offline bundle can also recalculate its hash. Runtime read-only validation of the exact generated ZIP remains required before import.

```sh
python3 tools/content_factory.py convert \
  --source 'SEO pages' \
  --output '/tmp/content-factory-pagespec' \
  --contract-file '/secure/input/contract-bundle.json'
```

Offline mode cannot perform runtime validation, so the report records
`skipped_offline`. Do not import until the exact ZIP has passed the target
runtime's read-only validation.

## Outputs

- `<sourceId>.json` — one deterministic current-PageSpec document per source;
- `pagespec.zip` — deterministic ZIP containing only PageSpec JSON entries;
- `conversion-report.json` — source registry, graph, schema/semantic results,
  excluded service content, all gaps, hashes, and read-only validation summary;
- `contract-bundle.json` — the verified runtime bundle used for conversion.
- `.content-factory-output` — ownership marker required for a later `--force` replacement; it is not included in the ZIP.

`CONTENT_GAP`, `LINK_GAP`, `ASSET_GAP`, and `ADAPTER_GAP` are blocking. The
converter preserves public labels/text for diagnosis and never manufactures a
URL, asset, fact, missing FAQ item, or compatible page type.

The plugin REST validator classifies warnings as non-blocking. The repository
`import` command is intentionally stricter: it refuses draft import while the
validated package contains either incompatible pages or pages with warnings.

Catalog images must be explicit and must name a ref advertised by the Contract
Bundle:

```md
**Изображение:**
`themeAsset:type-matte`

**Alt:**
Матовый белый натяжной потолок
```

The hero may use an explicit directive in its first content section. If it is
absent, only `policies.heroImageFallback` from the Contract Bundle may be used.

## Separate safe draft import

The import command accepts the exact previously validated ZIP and
`packageHash`. Its default is another read-only validation:

```sh
python3 tools/content_factory.py import \
  --zip '/tmp/content-factory-pagespec/pagespec.zip' \
  --wordpress-url 'http://localhost:8080' \
  --validated-hash 'sha256:...'
```

Only a separate command with both execution and the exact confirmation phrase
sends `confirmed=true`; import is unconditionally atomic and publication is unavailable:

```sh
python3 tools/content_factory.py import \
  --zip '/tmp/content-factory-pagespec/pagespec.zip' \
  --wordpress-url 'http://localhost:8080' \
  --validated-hash 'sha256:...' \
  --execute \
  --confirm-import CREATE_OR_UPDATE_DRAFTS_ATOMICALLY
```

## Tests

```sh
python3 -m unittest discover -s tools/tests -v
```

The converter intentionally supports only the versions checked against the current plugin registry. Run `python3 tools/check_content_factory_versions.py` before conversion.

The retired `build_local_import_pack.py` workflow is intentionally unavailable: rewriting previously generated JSON bypassed the current Contract Bundle and validation pipeline. Regenerate from Markdown with this converter for every target runtime.
