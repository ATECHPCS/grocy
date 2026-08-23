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
- [ ] **Phase 6: Inventory Categorization & Conversion Cleanup** - Apply the proven models safely to existing household inventory and redundant conversions.
- [ ] **Phase 7: Upstream & Stable Release Sustainment** - Make the completed milestone reproducibly portable, deployable, identifiable, and recoverable.

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

- [ ] 04-09-PLAN.md — Render product conversion provenance without a mutation or activation path.

**Wave 7** *(blocked on Wave 6 completion)*

- [ ] 04-06-PLAN.md — Explain resolved-conversion provenance in the native table.

**Wave 8** *(blocked on Wave 7 completion)*

- [ ] 04-07-PLAN.md — Add strictly read-only coverage diagnostics.

**Wave 9** *(blocked on Wave 8 completion)*

- [ ] 04-08-PLAN.md — Gate the sole reusable activation/projection transaction on immutable dual-branch proof.

**Wave 10** *(blocked on Wave 9 completion)*

- [ ] 04-10-PLAN.md — Add the maintainer-only, evidence-gated command as the sole operational promotion path.

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

**Plans**: TBD
**UI hint:** yes

### Phase 6: Inventory Categorization & Conversion Cleanup

**Goal**: Users can apply only reviewed classifications and redundant-conversion removals to existing inventory while preserving all unrelated Grocy behavior and retaining guarded recovery.
**Mode:** mvp
**Depends on**: Phase 5
**Requirements**: DATA-01, DATA-02, DATA-03, DATA-04, DATA-05, DATA-06, DATA-07
**Success Criteria** (what must be TRUE):

  1. Maintainer can profile every in-scope food product and generate suggestions while excluding baby food, pet food, inactive/non-food exceptions, and explicitly out-of-scope records.
  2. User can review conflicting and low-confidence classifications first, retain Unclassified where appropriate, and apply only explicitly approved assignments.
  3. Maintainer can profile existing conversions as logical unit pairs with origin, factor, usage, duplicates, malformed rows, and dependencies.
  4. User can remove only reviewed redundant conversions after before/after effective-path comparison proves equivalent coverage and retains package/count, measured-density, purchase-to-stock, and named exceptions.
  5. Maintainer can verify that all unrelated product, stock, history, recipe, price, due-date, authentication, and normal Grocy behavior is unchanged, then rerun with zero additional diffs and rehearse guarded rollback on production-shaped data.

**Plans**: TBD
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

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3 → 4 → 5 → 6 → 7. Dual-branch and stable-image checks established in Phase 1 remain exit gates throughout; Phase 7 consolidates and rehearses final promotion and recovery.

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Safety Baseline & Mobile Diagnostics | 9/10 | In Progress|  |
| 2. Enrichment Contract, Barcode Handoff & Secure Media | 17/20 | In Progress|  |
| 3. Food Taxonomy & Categorization Pilot | 3/3 plans + gap closure | Complete | 2026-08-20 |
| 4. Reusable Conversion Model | 0/TBD | Not started | - |
| 5. Bulk Maintenance & Recovery Engine | 0/TBD | Not started | - |
| 6. Inventory Categorization & Conversion Cleanup | 0/TBD | Not started | - |
| 7. Upstream & Stable Release Sustainment | 0/TBD | Not started | - |
