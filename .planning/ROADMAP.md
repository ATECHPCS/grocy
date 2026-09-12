# Roadmap: grocy_AI

## Overview

This milestone turns the deployed product-enrichment baseline into a dependable, review-controlled mobile inventory workflow, then adds a stable household food taxonomy, deterministic reusable conversions, and a shared preview/apply/recovery engine before changing existing inventory. The work finishes by proving that the complete system can be promoted and recovered across both maintained Grocy branches without risking persistent household data. Previously deployed name/image enrichment is treated as brownfield context; roadmap Phase 1 begins with the new milestone's safety baseline and mobile diagnostics.

**Planning mode:** Vertical MVP
**Granularity:** Fine — all seven research-supported natural delivery boundaries remain distinct; none are compressed into a broader phase.

## Phases

**Phase Numbering:**

- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions created after planning

- [ ] **Phase 1: Safety Baseline & Mobile Diagnostics** - Make the deployed enrichment path measurable, failure-tolerant, and verifiable on a real phone.
- [ ] **Phase 2: Enrichment Contract, Barcode Handoff & Secure Media** - Deliver duplicate-safe, review-before-save structured enrichment and hardened package imagery.
- [x] **Phase 3: Food Taxonomy & Categorization Pilot** - Establish and validate stable household food identities before any bulk classification. (completed 2026-08-20)
- [ ] **Phase 4: Reusable Conversion Model** - Provide deterministic universal and food-type conversions without breaking existing Grocy quantity behavior.
- [ ] **Phase 5: Bulk Maintenance & Recovery Engine** - Give users one immutable, auditable, conflict-safe workflow for reviewed bulk changes and rollback.
- [ ] **Phase 6: Inventory Categorization** - Group and classify existing household inventory via reviewed bulk passes; conversion cleanup closed by verification (0 targets in prod).
- [ ] **Phase 7: Upstream & Stable Release Sustainment** - Make the completed milestone reproducibly portable, deployable, identifiable, and recoverable.
- [ ] **Phase 8: Purchase Capture & Deferred Stock Intake** - Let a user rapid-scan a shopping trip, review it later, and commit selected items to stock as one native Grocy purchase batch. *(added 2026-09-01, post-milestone)*

## Phase Details

### Phase 1: Safety Baseline & Mobile Diagnostics

**Goal**: Users can dependably operate the deployed enrichment workflow from a phone while maintainers can localize failures and enforce measured latency budgets.
**Mode:** mvp
**Depends on**: Nothing (first phase; deployed enrichment functionality is the validated brownfield baseline)
**Requirements**: MOB-01, MOB-02, MOB-03, MOB-04, MOB-05, MOB-06, MOB-07, MOB-08
**Success Criteria** (what must be TRUE):

  1. User can scan or manually enter a GTIN on a phone and receives immediate length and checksum validation.
  2. User sees distinct success and failure states with bounded cancel/retry behavior, while stale responses and repeated taps or scans cannot replace the current result or create duplicate effects.
  3. Operator can follow one privacy-safe trace across browser, Grocy, companion, and provider stages, and the user can copy a redacted diagnostic report containing versions, stage outcomes, and timings.
  4. User can continue normal product and inventory work when the companion or any metadata, search, or image provider is unavailable.
  5. Maintainer can verify explicit LAN latency budgets and degraded-path behavior through automated mobile-browser coverage and a recorded physical-phone acceptance pass.

**Plans**: 10 plans

Plans:
**Wave 1**

- [x] 01-01-PLAN.md — Verify and authorize the official Playwright test package.

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 01-02-PLAN.md — Create the isolated mobile harness and failing happy-path E2E contract.

**Wave 3** *(blocked on Wave 2 completion)*

- [x] 01-03-PLAN.md — Deliver the thin phone validation-to-preview enrichment happy path.

**Wave 4** *(blocked on Wave 3 completion)*

- [x] 01-04-PLAN.md — Add bounded companion provider outcomes, tracing, and timings in grocy-mcp.

**Wave 5** *(blocked on Wave 4 completion)*

- [x] 01-05-PLAN.md — Add Grocy's authenticated redacted diagnostic and timeout boundary.

**Wave 6** *(blocked on Wave 5 completion)*

- [x] 01-06-PLAN.md — Complete race-safe states, diagnostics copy, and degraded-path preservation.

**Wave 7** *(blocked on Wave 6 completion)*

- [x] 01-07-PLAN.md — Add mobile/a11y, latency-evidence, and dual-branch release gates.

**Wave 8** *(blocked on Wave 7 completion)*

- [x] 01-08-PLAN.md — Mirror and commit the byte-portable Phase 1 stable baseline.

**Wave 9** *(blocked on Wave 8 completion)*

- [x] 01-09-PLAN.md — Adapt stable seams, deploy the immutable image, and verify persistent-data continuity.

**Wave 10** *(blocked on Wave 9 completion)*

- [ ] 01-10-PLAN.md — Complete physical-phone acceptance and the normal-Save restoration spine.

**UI hint:** yes

### Phase 2: Enrichment Contract, Barcode Handoff & Secure Media

**Goal**: Users can review trustworthy structured suggestions, hand off barcodes without duplicates, and select real package images without hidden persistence or unsafe media access.
**Mode:** mvp
**Depends on**: Phase 1
**Requirements**: ENR-01, ENR-02, ENR-03, ENR-04, ENR-05, ENR-06, ENR-07, ENR-08, ENR-09
**Success Criteria** (what must be TRUE):

  1. User sees current values beside strictly validated, versioned suggestions for every supported product field, each with source, confidence band, reason, and freshness.
  2. User sees the originally scanned barcode while Grocy checks canonical equivalents, routes an existing owner correctly, or stages a new barcode exactly once for persistence only after normal Save.
  3. User can review one final diff and save only selected suggestions; search, preview, cancel, timeout, and failed media retrieval leave products, barcodes, categories, stock, conversions, and files unchanged.
  4. User sees an exact structured-source front image before clearly unverified search alternatives and can demand-load/select same-origin media through short-lived handles with URL, redirect, byte, time, MIME, signature, and pixel safeguards.

**Plans**: 20 plans

Plans:

**Wave 1**

- [x] 02-01-PLAN.md — Lock Phase 2's resolved runtime facts and create trustworthy RED contract tests before production changes.

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 02-02-PLAN.md — Deliver the closed v2 contract and first trustworthy name-review vertical slice.

**Wave 3** *(blocked on Wave 2 completion)*

- [x] 02-03-PLAN.md — Expand the first contract row into the complete seven-family side-by-side review, final selected diff, stale-current protection, and explicit staging interaction.

**Wave 4** *(blocked on Wave 3 completion)*

- [x] 02-04-PLAN.md — Deliver the read-only barcode ownership slice with canonical local resolution, existing-owner routing, and transient staging for one unused barcode.

**Wave 5** *(blocked on Wave 4 completion)*

- [x] 02-05-PLAN.md — Complete the unused-barcode Save slice with atomic canonical uniqueness, one normal-Save continuation, and barcode-only partial-creation recovery.

**Wave 6** *(blocked on Wave 5 completion)*

- [x] 02-06-PLAN.md — Specify secure media completely before changing network or proxy implementation.

**Wave 7** *(blocked on Wave 6 completion)*

- [x] 02-07-PLAN.md — Implement the complete companion-to-Grocy-to-browser secure-media slice against Plan 02-06's gates.

**Wave 8** *(blocked on Wave 7 completion)*

- [x] 02-08-PLAN.md — Construct the integrated zero-write, selected-only Save, real-Blade, mobile, and accessibility gates.

**Wave 9** *(blocked on Wave 8 completion)*

- [x] 02-09-PLAN.md — Harden only concrete failures exposed by the integrated Phase 2 acceptance matrix.

**Wave 10** *(blocked on Wave 9 completion)*

- [x] 02-10-PLAN.md — Freeze and mirror the portable Phase 2 bytes without adapting stable framework seams.

**Wave 11** *(blocked on Wave 10 completion)*

- [x] 02-11-PLAN.md — Adapt only stable framework seams and prove behavior and parity without changing portable blobs.

**Wave 12** *(blocked on Wave 11 completion)*

- [x] 02-12-PLAN.md — Create executable release and deployment evidence gates before any deployment.

**Wave 13** *(blocked on Wave 12 completion)*

- [x] 02-13-PLAN.md — Deploy the immutable companion and stable Grocy release candidate, then automate production evidence without changing household data.

**Wave 14** *(blocked on Wave 13 completion)*

- [x] 02-14-PLAN.md — Complete the manual evidence automation cannot supply for deployed userfield readability, owner routing, normal Save, and package-media interaction.

**Wave 15** *(blocked on Wave 14 completion)*

- [x] 02-15-PLAN.md — Restore exact immutable release replay and exercise the production variant-bound companion media route.

**Wave 16** *(blocked on Wave 15 completion)*

- [x] 02-16-PLAN.md — Close duplicate-field, media-provenance, and bounded companion JSON contract gaps with red-first regressions.

**Wave 17** *(blocked on Wave 16 completion)*

- [x] 02-17-PLAN.md — Commit the fixed main and companion candidate inputs with new immutable identities.

**Wave 18** *(blocked on Wave 17 completion)*

- [x] 02-18-PLAN.md — Create the 12-path stable portable mirror from the fixed main candidate.

**Wave 19** *(blocked on Wave 18 completion)*

- [x] 02-19-PLAN.md — Reapply the stable adapter, replace manifest provenance, and replay release gates.

**Wave 20** *(blocked on Wave 19 completion)*

- [x] 02-20-PLAN.md — Deploy replacement images and refresh redacted deployment evidence.

**Wave 21** *(hotfix reconciliation)*

- [x] 02-21-PLAN.md — Certify the bounded conversion runtime hotfix before Phase 03.

**UI hint:** yes

### Phase 3: Food Taxonomy & Categorization Pilot

**Goal**: Users can classify one product against a safe, explainable household taxonomy whose identities and exclusions are stable enough for later rules and bulk work.
**Mode:** mvp
**Depends on**: Phase 2
**Requirements**: TAX-01, TAX-02, TAX-03, TAX-04, TAX-05, TAX-06, TAX-07
**Success Criteria** (what must be TRUE):

  1. Maintainer can define, migrate, and version a small two-level taxonomy with stable IDs/slugs in namespaced module schema that does not collide with upstream migrations.
  2. Baby-food and pet-food types are absent and provider mappings cannot silently reintroduce either exclusion.
  3. User can explicitly leave uncertain products Unclassified instead of accepting absent, conflicting, or low-confidence evidence.
  4. User can review and assign exactly one current taxonomy leaf without changing stock, units, recipes, prices, history, or location.
  5. User can inspect provider evidence, mapping/ruleset version, confidence, and reason, while the maintainer can validate taxonomy v1 against all in-scope products and record the frozen/preserved identity decision.

**Plans**: 8 plans

Plans:

- [ ] 04-01-PLAN.md — Characterize dual-branch cache, triggers, and protected consumer behavior before selecting a projection.
- [ ] 04-02-PLAN.md — Deliver validated exact universal mass/volume rules through native Grocy Save.
- [ ] 04-03-PLAN.md — Add explicit-taxonomy approximate profiles and deterministic conflict-free resolution.
- [ ] 04-04-PLAN.md — Explain effective conversion source in the native product conversion area.
- [ ] 04-05-PLAN.md — Explain source and outcome in the native resolved-conversions view.
- [ ] 04-06-PLAN.md — Add read-only coverage diagnostics and portable/stable release proof.

**UI hint:** yes

### Phase 4: Reusable Conversion Model

**Goal**: Users receive reusable, explainable conversions with one deterministic effective result while existing Grocy stock, recipe, purchase, and display behavior remains equivalent.
**Mode:** mvp
**Depends on**: Phase 3
**Requirements**: CONV-01, CONV-02, CONV-03, CONV-04, CONV-05, CONV-06, CONV-07, CONV-08, CONV-09
**Success Criteria** (what must be TRUE):

  1. Maintainer can assign units to explicit dimensions, and invalid cross-dimension universal rules are rejected.
  2. User receives authoritative same-dimension mass/volume conversions and only narrow, sourced, explicitly approximate food-type mass/volume profiles, while package/count rules remain product- or barcode-bound.
  3. User can see the winning source from deterministic precedence of product override over food type over universal conversion.
  4. Maintainer can inspect coverage, missing paths, sources, redundancy, cycles, and conflicts, and is blocked by competing paths, reciprocal inconsistency, dimension mismatch, or out-of-tolerance factors.
  5. Maintainer can use a dual-branch characterization result to select the smallest safe resolved/cache projection and verify unchanged stock, recipe, purchase, consumption, price, and quantity-display behavior.

**Plans**: 10 plans

Plans:
**Wave 1**

- [ ] 04-01-PLAN.md — Characterize dual-branch cache and protected-consumer behavior.

**Wave 2** *(blocked on Wave 1 completion)*

- [ ] 04-02-PLAN.md — Build inactive reusable catalog and enforce scope-aware AddObject/EditObject pre-save validation.

**Wave 3** *(blocked on Wave 2 completion)*

- [ ] 04-03-PLAN.md — Add inactive sourced profiles and deterministic conflict-free inspection resolution.

**Wave 4** *(blocked on Wave 3 completion)*

- [ ] 04-04-PLAN.md — Add revision-safe native form status while retaining normal product-scoped Save.

**Wave 5** *(blocked on Wave 4 completion)*

- [ ] 04-05-PLAN.md — Explain product-level conversion status without writes.

**Wave 6** *(blocked on Wave 5 completion)*

- [x] 04-09-PLAN.md — Render product conversion provenance without a mutation or activation path.

**Wave 7** *(blocked on Wave 6 completion)*

- [x] 04-06-PLAN.md — Explain resolved-conversion provenance in the native table.

**Wave 8** *(blocked on Wave 7 completion)*

- [x] 04-07-PLAN.md — Add strictly read-only coverage diagnostics.

**Wave 9** *(blocked on Wave 8 completion)*

- [x] 04-08-PLAN.md — Gate the sole reusable activation/projection transaction on immutable dual-branch proof.

**Wave 10** *(blocked on Wave 9 completion)*

- [x] 04-10-PLAN.md — Add the maintainer-only, evidence-gated command as the sole operational promotion path.

**Cross-cutting constraints:**

- A product editor can distinguish product override, approximate profile, universal default, unavailable, and blocked states beside native conversions.
- Package-derived facts can be reviewed but only the existing native Save action persists a product conversion.

**UI hint:** yes

### Phase 5: Bulk Maintenance & Recovery Engine

**Goal**: Users can preview, approve, apply, audit, export, and safely reverse bounded bulk changes without stale writes or arbitrary mutation authority.
**Mode:** mvp
**Depends on**: Phase 4
**Requirements**: BULK-01, BULK-02, BULK-03, BULK-04, BULK-05, BULK-06, BULK-07, BULK-08, BULK-09, BULK-10
**Success Criteria** (what must be TRUE):

  1. User can create a zero-mutation bounded plan with exact scope/outcome counts and immutable item identities, before/proposed values, provenance, reasons, ruleset version, and checksum.
  2. User can select or reject individual items and inspect the complete selected diff before approval.
  3. Apply rejects stale or conflicting before-images and accepts only named typed operations, never browser- or companion-supplied arbitrary CRUD or SQL.
  4. An approved plan applies once through one short network-free `BEGIN IMMEDIATE` transaction, and repeat application or retry cannot duplicate mutations.
  5. Maintainer can audit exact actors, versions, times, outcomes, and before/after values; user can export a redacted preview and can preview a rollback that refuses to overwrite later manual edits.

**Plans**: 11 plans — COMPLETE 2026-08-30 (all shipped on `codex/phase5-bulk-engine`, each adversarially verified; + a generation-surface endpoint/UI added to close the BULK-01 reachability gap). Pending only the maintainer human-verify checkpoint (05-11 Task 3).

- [ ] 05-01-PLAN.md — Lock the bulk engine contract with RED tests (DTOs, typed-op registry, checksum, closed vocab).
- [ ] 05-02-PLAN.md — Inactive namespaced bulk schema/migration (grocy_ai_bulk_plans/_items/_audit).
- [ ] 05-03-PLAN.md — Zero-mutation dry-run GeneratePlan with exact counts and checksum.
- [ ] 05-04-PLAN.md — Closed named-typed-operation registry (taxonomy ops) + AssignProductTaxonomy nesting guard.
- [ ] 05-05-PLAN.md — Per-item select/reject, selected diff, review UI, service wiring.
- [ ] 05-06-PLAN.md — Optimistic-concurrency stale/conflicting before-image refusal.
- [ ] 05-07-PLAN.md — Single BEGIN IMMEDIATE idempotent apply transaction.
- [ ] 05-08-PLAN.md — Append-only audit ledger (actor, versions, times, before/after).
- [ ] 05-09-PLAN.md — Guarded rollback preview + execute endpoints.
- [ ] 05-10-PLAN.md — Redacted JSON/CSV export (non-authoritative on re-import).
- [ ] 05-11-PLAN.md — Routes, dual-branch parity, bulk release gate, human-verify checkpoint.

**UI hint:** yes

### Phase 6: Inventory Categorization

**Goal**: Users can apply only reviewed product-group and food-classification assignments to existing inventory while preserving all unrelated Grocy behavior and retaining guarded recovery. Conversion cleanup is closed by verification.
**Mode:** mvp
**Depends on**: Phase 5 (bulk engine), Phase 3 (taxonomy scorer + storage)
**Requirements**: DATA-01, DATA-02, DATA-03, DATA-04, DATA-05, DATA-06, DATA-07

**Re-scoped 2026-09-08 (grilled) — live-prod fact-find (434 products) invalidated the original premise:**
- 0 product-specific conversions exist (all 62 conversion rows are global); the "~101 unwanted conversions" figure is stale. DATA-03/04/05 have no targets → closed by a no-op audit.
- Classification lives in module tables (`grocy_ai_taxonomy_classifications` + `_evidence`), not a Grocy userfield; the Phase 3 scorer + schema are intact but 0 products classified (pilot never bulk-applied). Phase 6 bulk-fills it.
- The real data gap is 214 ungrouped products (~49%); no baby/pet/non-food groups and 0 inactive products exist, so the exclusion filter guards only ~2 Supplements items (kept as config + tripwire).

**Design decisions (grilled 2026-09-08):**

| Q | Decision |
|---|----------|
| Test data | Clone live prod → light scrub (secrets only) → local gitignored snapshot + documented refresh command |
| Pass structure | Three independent reviewed passes, each own preview/apply/rollback + DATA-06 zero-diff verify |
| Pass order | (1) product-group suggestion for the 214 ungrouped → (2) food classification → (3) conversion audit |
| Scope filter | Grocy product-group + active flag, mapping in module config constant, per-product override userfield (migration-seeded); near-vestigial on current data, kept as tripwire |
| Engine | Reuse the Phase 5 apply/rollback/idempotency/export spine unchanged; add three profilers/plan-generators only |
| Classification store | Bulk-populate existing `grocy_ai_taxonomy_classifications` via the Phase 3 scorer + evidence; no new schema, no Grocy userfield |
| Classify conflicts | Reuse Phase 3 evidence scoring; conflict = suggestion contradicts existing group signal OR two candidate types tie; review order conflicts → low-confidence → confident; below threshold → Unclassified |
| Conversion pass | No-op profiler: assert 0 product-specific conversions, read-only audit the 62 globals; closes DATA-03/04/05 by verification + leaves a regression tripwire; no deletion code built |
| DATA-06 verify | Per-table row-count + content-hash before/after each pass; only the approved rows/columns may change, any other delta fails the pass |
| DATA-07 rollback | Per-pass atomic `BEGIN IMMEDIATE` + pre-apply snapshot of every touched row into the audit record; rollback restores from that record (works with no full DB backup); both passes rehearsed on the local snapshot before prod |
| Profiler output | Emit standard BULK plan rows; confidence/evidence rides along as structured audit payload into export/rollback; no engine changes |

**Success Criteria** (what must be TRUE):

  1. Maintainer can profile every in-scope food product and generate product-group and classification suggestions while excluding the configured non-food groups (Supplements), inactive, and per-product override-excluded records.
  2. User can review conflicting and low-confidence classifications first, retain Unclassified where appropriate, and apply only explicitly approved product-group and classification assignments.
  3. Maintainer can audit the existing conversion set and confirm there are no product-specific conversions to remove (verification-closed), leaving the 62 global conversions untouched.
  4. Conversion-removal machinery is not built; a regression tripwire fails the audit if product-specific conversions ever reappear.
  5. Maintainer can verify that all unrelated product, stock, history, recipe, price, due-date, authentication, and normal Grocy behavior is unchanged (per-table checksum), then rerun with zero additional diffs and rehearse guarded rollback on the local production-shaped snapshot.

**Plans**: 06-01 snapshot gate · 06-02 DATA-06/07 checksum+rollback harness · 06-03 exclusion scope+override · 06-04 suggest_product_group op (214 ungrouped) · 06-05 classification conflict-first hardening · 06-06 conversion no-op audit+tripwire · 06-07 UI+full-phase acceptance. Waves: 1=[01], 2=[02,03,06], 3=[04], 4=[05], 5=[07]. Design + plans in `.planning/phases/06-inventory-categorization/`.
**UI hint:** yes

### Phase 7: Upstream & Stable Release Sustainment

**Goal**: Maintainers can promote the complete module across both maintained branches into a stable, identifiable deployment and recover the prior system and data if promotion fails.
**Mode:** mvp
**Depends on**: Phase 6
**Requirements**: REL-01, REL-02, REL-03, REL-04, REL-05, REL-06, REL-07
**Success Criteria** (what must be TRUE):

  1. Maintainer can identify every ATECHPCS core hook and verify that feature code remains in the custom module boundaries wherever possible.
  2. Maintainer can prove portable-file parity and expected adapter differences on both `atech-main` and `atech-release` before promotion.
  3. Maintainer can run module migration and upgrade tests against production-shaped data on both maintained branches.
  4. Maintainer can build and deploy an immutable-digest stable image with exact source/version metadata and fresh route/view assets while preserving `/etc/komodo/grocy` data, images, routes, flags, and module state across restart.
  5. User can complete the end-to-end mobile product workflow on the promoted image, and the maintainer can execute the rehearsed prior-image and database recovery procedure if acceptance or migration fails.

**Plans**: TBD
**UI hint:** no

### Phase 8: Purchase Capture & Deferred Stock Intake

**Goal**: A user can rapid-scan groceries into a discrete shopping trip over a live connection, review and adjust the trip later, and commit the selected known items to stock as one auditable native Grocy purchase batch — with unknown barcodes flagged and handed off to the existing enrichment flow.
**Mode:** mvp (lean rigor — build + test + deploy; portable/stable mirroring deferred to Phase 7)
**Depends on**: Phase 2 (barcode/enrichment services). Independent of Phases 4–6; may ship before them.
**Requirements**: CAP-01, CAP-02, CAP-03, CAP-04, CAP-05, CAP-06, CAP-07, CAP-08
**Success Criteria** (what must be TRUE):

  1. User can start/close a trip and rapid-scan over a live connection; duplicate barcodes coalesce into one incrementing line; each scan resolves known/unknown with no prompts.
  2. User can review a trip with lean per-line edits (quantity, optional price, select/deselect, delete) plus trip-level location/store defaults and auto-computed best-before.
  3. Unknown lines hand off to the existing `/product/new` enrichment flow and auto re-resolve to known by barcode when the trip is reopened.
  4. Commit writes only selected known lines as one native `AddProduct` purchase transaction with the purchase→stock factor and barcode overrides applied; unresolved/deselected lines remain in the trip.
  5. Commit is idempotent and conflict-safe (checksum + per-item `applied_at` ledger + in-lock re-resolve); committed trips archive read-only with the Grocy `transaction_id`; nothing but the approved commit path writes stock.

**Design decisions (grilled 2026-09-01):** build fresh in module; live-connection server-side queue (no offline); discrete trips `open→reviewing→committed`; coalesce+increment; purchase-units+factor at commit; dedicated pages + sidebar tile; reuse enrichment scan component; `STOCK_PURCHASE` gate; partial commit; auto re-resolve unknowns; lean review + trip defaults; mirror the Phase 5 `BEGIN IMMEDIATE`/checksum/idempotency safety pattern in a NEW stock-writing service (first module code allowed to write stock).

**Reuses:** `GrocyAiBarcodeService::ResolveOwner` (known/unknown), `GrocyAiService::EnrichByUpc` (unknowns), `GrocyAiBulkMigration` schema pattern, native `StockService::AddProduct` (commit).

**Plans**: 6 (lean)

Plans:
**Wave 1**

- [ ] 08-01-PLAN.md — RED contract tests + inactive namespaced `grocy_ai_capture_*` schema/migration (trips/lines/audit/migrations ledger).

**Wave 2** *(blocked on Wave 1)*

- [ ] 08-02-PLAN.md — Start/close trip + live scan-into-trip with immediate ownership resolution and same-barcode coalescing.

**Wave 3** *(blocked on Wave 2)*

- [ ] 08-03-PLAN.md — Mobile capture page (scan loop reusing the enrichment scanner) + sidebar menu tile.

**Wave 4** *(blocked on Wave 3)*

- [ ] 08-04-PLAN.md — Review page: lean per-line edits, trip-level defaults, unknown handoff link, and auto re-resolve on open.

**Wave 5** *(blocked on Wave 4)*

- [ ] 08-05-PLAN.md — Commit: single `BEGIN IMMEDIATE` native purchase batch, purchase→stock factor, partial commit, checksum + idempotency ledger + in-lock conflict re-check, audit + read-only archive.

**Wave 6** *(blocked on Wave 5)*

- [ ] 08-06-PLAN.md — Acceptance: native + mobile-browser tests (stock-write safety, idempotency, partial commit, unknown lifecycle) and deploy.

**Cross-cutting constraints:**

- No capture, scan, or review action may write stock; only the single approved commit path calls native `AddProduct`.
- The module's existing "never disturb `stock`/`purchase`" invariant is intentionally superseded for this phase ONLY through the audited commit path; all other protected-consumer guarantees remain.

**UI hint:** yes

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3 → 4 → 5 → 6 → 7. Dual-branch and stable-image checks established in Phase 1 remain exit gates throughout; Phase 7 consolidates and rehearses final promotion and recovery. Phase 8 is a post-milestone addition (added 2026-09-01) that depends only on Phase 2's barcode/enrichment services and may be scheduled independently of Phases 4–6; its portable/stable mirroring folds into Phase 7.

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Safety Baseline & Mobile Diagnostics | 9/10 | In Progress|  |
| 2. Enrichment Contract, Barcode Handoff & Secure Media | 17/20 | In Progress|  |
| 3. Food Taxonomy & Categorization Pilot | 3/3 plans + gap closure | Complete | 2026-08-20 |
| 4. Reusable Conversion Model | 8/10 | In Progress | - |
| 5. Bulk Maintenance & Recovery Engine | 11/11 | Complete (pending human-verify) | 2026-08-30 |
| 6. Inventory Categorization & Conversion Cleanup | 0/TBD | Not started | - |
| 7. Upstream & Stable Release Sustainment | 0/TBD | Not started | - |
| 8. Purchase Capture & Deferred Stock Intake | 0/6 | Not started | - |
