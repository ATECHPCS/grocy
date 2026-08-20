# grocy_AI

## What This Is

`grocy_AI` is the ATECHPCS-maintained extension boundary for a local Grocy household inventory system. It keeps Grocy's proven PHP/SQLite core and upstream compatibility while adding reviewable AI- and search-assisted workflows for product creation, real package imagery, food classification, quantity conversions, and inventory maintenance.

The project serves a private household deployment on the LAN, primarily through Grocy's responsive web interface and mobile usage. Fork-specific behavior remains segregated under `custom/grocy_AI/` and `public/custom/grocy_AI/`, with only small, documented hooks in upstream files.

## Core Value

Adding and maintaining real household food inventory must be fast, accurate, and dependable from a phone without surrendering control of the data to automatic guesses.

## Requirements

### Validated

- ✓ A feature-flagged `grocy_AI` module can be disabled independently and remains isolated from upstream core code — deployed Phase 1.
- ✓ A user can search a valid UPC and preview structured product metadata plus real package-image candidates — deployed Phase 1.
- ✓ A user can explicitly apply a suggested product name and select a real product image, with nothing persisted until Grocy's normal Save action — deployed Phase 1.
- ✓ Selected images travel through authenticated, short-lived opaque handles and are validated as bounded PNG, JPEG, or WebP files — deployed Phase 1.
- ✓ The production fork can track upstream on `atech-main` while deploying a stable Grocy 4.6 image from `atech-release` without replacing persistent inventory data — deployed Phase 1.
- ✓ Chores and batteries can remain disabled without deleting their upstream implementation — deployed Phase 1.
- ✓ A user can inspect explainable food-taxonomy evidence and explicitly assign one household food classification or leave it Unclassified, without altering unrelated Grocy product data — deployed Phase 3.

### Active

- [ ] Verify the complete product-enrichment workflow on mobile, including search, review, selection, save, reload, failure handling, and acceptable LAN latency.
- [ ] Hand a searched UPC into Grocy's product-barcode workflow without duplicate or hidden writes.
- [ ] Suggest structured product fields such as brand, package size, product group, quantity unit, and food type while requiring human review before persistence.
- [ ] Categorize existing household food through reviewed bulk work, excluding baby food and pet food.
- [ ] Replace the unwanted product-specific conversion sprawl with reusable universal and food-type conversion rules, including common additional conversions.
- [ ] Provide preview, confidence, conflict reporting, and rollback-safe execution for bulk categorization and conversion cleanup.
- [ ] Capture enough latency and failure telemetry to distinguish Grocy, companion-service, provider, image-host, and LAN/mobile connection problems.
- [ ] Preserve a low-conflict, documented upstream synchronization and stable-release process for all custom work.

### Out of Scope

- Rewriting Grocy in Rust, TypeScript, Python, or another language — the maintenance cost and compatibility loss outweigh the expected benefit; extend the existing PHP system.
- Baby-food and pet-food taxonomy or categorization — these products will not be inventoried in this household.
- Chores and battery workflows — retain upstream code but keep both features disabled.
- AI-generated images for products with valid UPCs or verifiable retail packaging — prefer Open Food Facts and SearXNG-discovered real product imagery.
- Autonomous product, stock, image, category, or conversion writes without an explicit preview and user-approved action — household data remains user-controlled.
- A separate native mobile application — improve and verify the existing responsive Grocy experience first.

## Context

- The local fork is `ATECHPCS/grocy`; `atech-main` tracks upstream development and `atech-release` is based on upstream Grocy 4.6.0 for production stability.
- The live Grocy instance runs at `10.10.0.156:9283` in Docker/Komodo with persistent data mounted from `/etc/komodo/grocy`.
- The Python `ATECHPCS/grocy-mcp` companion runs at `10.10.0.156:3061` and provides UPC enrichment using Barcode Buddy Federation, Open Food Facts, and local SearXNG.
- Local SearXNG runs at `10.10.0.162:8095` and is used for real-package image discovery when structured sources do not provide a suitable front image.
- The application is a Slim 4 modular monolith using PHP 8.5, Blade, browser JavaScript, LessQL, and SQLite. Product persistence and picture upload already have established Grocy form/API flows that custom features should reuse.
- Phase 1 is deployed and visually confirmed. It supplies review-before-save product-name and image controls, authenticated proxying, content validation, automated contract tests, and documented extension hooks.
- Earlier inventory work identified approximately 101 unwanted product-specific conversions. The desired replacement is a controlled universal/food-type model rather than more per-product duplication.
- The original investigation was motivated partly by intermittent mobile connection errors and slow LAN behavior, so operational visibility and mobile-path testing are first-class requirements rather than afterthoughts.

## Constraints

- **Upstream compatibility**: Keep ATECHPCS implementation under `custom/grocy_AI/` and `public/custom/grocy_AI/`; minimize and document unavoidable changes to upstream files.
- **Deployment stability**: Production remains pinned to a tested stable Grocy release branch and must preserve `/etc/komodo/grocy` data across image rebuilds.
- **Data safety**: Bulk changes require a dry-run preview, bounded scope, conflict reporting, and an auditable result even when the user elects not to make a database backup.
- **Human control**: External metadata and image search results are suggestions; explicit user action is required before normal Grocy persistence.
- **Security**: Secrets remain in deployment configuration, never in Git/build URLs/logs; external image fetching uses allowlisted formats, bounded sizes, and server-issued opaque handles.
- **Performance**: Mobile and LAN workflows should degrade clearly when companion providers or image hosts are slow instead of presenting unexplained hangs or connection drops.
- **Compatibility**: Continue using Grocy's established PHP, Blade, JavaScript, REST, file-upload, permissions, and SQLite migration patterns.

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Fork Grocy under ATECHPCS instead of rewriting it | Retains upstream behavior, data compatibility, mature inventory logic, and lower maintenance cost | ✓ Good |
| Isolate custom behavior as `grocy_AI` | Keeps the merge surface small and makes local features feature-flagged and reviewable | ✓ Good |
| Maintain `atech-main` and a stable `atech-release` deployment branch | Upstream master and stable 4.6 differed substantially; production needs predictable releases | ✓ Good |
| Use the Python `grocy-mcp` service as the companion boundary | Reuses existing UPC, Open Food Facts, SearXNG, and AI integrations without moving Grocy's core persistence | ✓ Good |
| Prefer real package images for valid products | Inventory images should identify the actual store product, not an invented approximation | ✓ Good |
| Require review-before-save interactions | Provider data can be wrong; the user must retain control over household inventory | ✓ Good |
| Disable rather than remove chores and batteries | Avoids unnecessary fork divergence while removing unused UI surface | ✓ Good |
| Build reusable universal/food-type conversions | Product-specific conversions created excessive duplication and maintenance burden | — Pending |
| Exclude baby food and pet food | Those categories will never be used in this household | ✓ Good |
| Keep Grocy product groups as high-confidence taxonomy evidence, not taxonomy storage | Existing groups such as Seafood improve suggestions, while module-owned assignment keeps classification explicit and reversible | ✓ Good |

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition**:
1. Requirements invalidated? → Move to Out of Scope with reason
2. Requirements validated? → Move to Validated with phase reference
3. New requirements emerged? → Add to Active
4. Decisions to log? → Add to Key Decisions
5. "What This Is" still accurate? → Update if drifted

**After each milestone**:
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-08-20 after Phase 3*
