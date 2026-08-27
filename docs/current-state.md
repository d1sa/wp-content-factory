# Content Factory integration boundaries

This page is the compact handoff between the theme and Content Factory. Detailed workflow rules live in the linked owner documents.

## Ownership

- Theme `block.json` files own Gutenberg block names, attributes, types, `parent`, and `allowedBlocks`.
- `adapters/<profile-id>/profile.json` owns profile identity, semantic schemas, page recipes, mappings, defaults, assets, and policies.
- `ProfileCompiler` validates that authoring source and produces the request-local `CompiledProfile` plus its deterministic `manifestHash`.
- The Contract Bundle is the only public conversion contract. See [versioning.md](versioning.md) for its version sources.
- The adapter validates profile semantics, resolves semantic links, and builds a Block Tree. `ContentPipeline` orchestrates per-page context, hierarchy/conflict checks, serialization, round-trip/render checks, and a `BuildPlan`; `BatchRunner` owns complete-graph validation, atomic writes, and rollback. See [adapter-development.md](adapter-development.md).

## Write boundary

Read-only validation checks the complete dependency graph. Import creates or updates managed drafts atomically and cannot overwrite published managed pages. Once imported, a page's content and status are controlled by WordPress and its administrators; Content Factory does not intercept native publication or later saves. Its confirmed bulk-publish action remains available as an optional checked workflow for newly imported drafts. Manual WordPress changes only make the stored Content Factory validation state stale.

For Markdown grammar and package readiness, use [content-conversion.md](content-conversion.md). For commands and artifacts, use [`tools/README.md`](../tools/README.md).
