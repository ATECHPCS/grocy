# 06-05 — conflict-first classification ordering + confidence banding + post-grouping re-run (SUMMARY)

**Status:** complete, GREEN. `php8.5 custom/grocy_AI/tests/run.php` → **All 222 checks passed** (was 194; +28 from the new `taxonomy-review` block). All arg-based `taxonomy-*` suites (schema/api/assignment/validation/production-paths) and `bulk-*` suites (contract, invariants, schema, generate, generate-endpoint, registry, selection, conflict, apply, audit, rollback, export) still pass unchanged.

This plan is SCORING/ORDERING + real-data confirmation — **no new write code**. `AssignProductTaxonomy`'s write shape is byte-identical, classification still lands in `grocy_ai_taxonomy_classifications` only, and no native table is written by this plan.

## The two conflict definitions (Q7 / DATA-02)

Both derive deterministically from stored evidence + the Grocy product-group signal (no network, no volatile input). Candidate leaves are scored: the group signal scores as a high band; provider evidence scores by its stored band.

- **(a) `group_signal_contradiction`** — the product carries a Grocy product-group signal implying one leaf while its provider evidence implies a **different** leaf. (e.g. group *Seafood* → `meat-seafood` vs provider `produce` → `produce`.)
- **(b) `candidate_tie`** — the top two **distinct** candidate leaves score within the named tie margin. (e.g. group *Seafood* (score 3) vs provider `dairy` medium (score 2), gap 1 ≤ margin.)

A review can flag both at once (equal-score disagreement). Below-threshold confidence with no conflict → `low_confidence`; no accepted evidence → `unclassified`.

## The named constants (all on `GrocyAiTaxonomyService`)

| Constant | Value | Meaning |
|---|---|---|
| `GROUP_SIGNAL_SCORE` | `3` | the Grocy product-group signal scores as a high band |
| `EVIDENCE_BAND_SCORES` (private) | `high=3, medium=2, low=1, unverified=0` | provider evidence candidate scores by band |
| `CONFIDENCE_THRESHOLD` | `2` | winner score ≥ this (≥ a medium band) ⇒ reviewed **confident** |
| `CANDIDATE_TIE_MARGIN` | `1` | two distinct candidate leaves within this score gap ⇒ **candidate_tie** |

A 2-point gap (e.g. group high vs provider low) is therefore a contradiction but **not** a tie — the margin is honored, proven by a dedicated assertion.

## What was added (no method mutated)

- **`GrocyAiTaxonomyService::ReviewProductTaxonomy(int): array`** — the deterministic review DTO: `review_band` (`conflict`|`low_confidence`|`confident`|`unclassified`), `conflict` bool, `conflict_reasons` (subset of the two forms), winning `suggested_leaf`/`evidence_source`/`confidence_band`/`confidence_score`, and `candidates`. Reuses the shipped `ProductGroupEvidence` read and the mapping-rule lookup via two new private helpers (`GroupSignalCandidate`, `ProviderCandidate`). The private provider helper keeps low/unverified bands as scored candidates (unlike `Evidence()`, which drops them) so a weak signal surfaces as `low_confidence` rather than vanishing. **The closed 8-key `ReadProductTaxonomy` shape and the single `INSERT INTO grocy_ai_taxonomy_classifications` are untouched.**
- **`GrocyAiBulkService::GenerateClassificationPlan(array): array`** — a sibling of `GeneratePlan`/`GenerateGroupPlan` (NOT a mutation of the 05-03-pinned `GeneratePlan`). Emits the full reviewable set ordered **conflicts → low-confidence → confident**: conflicts propose the winning leaf via `assign_taxonomy_leaf` but emitted DESELECTED for human review; below-threshold products emit a DESELECTED `set_unclassified` (Unclassified RETAINED, never a forced leaf); confident items are `assign_taxonomy_leaf`, pre-selected only when they change the current leaf. Write shape byte-identical to the taxonomy plan (`operation_type = taxonomy_assignment`, `{"leaf_slug":…}` images, deterministic SHA-256 checksum, zero native mutation).

## Why a sibling generator (deviation, same shape as 06-04)

`GeneratePlan`'s counts + item set are HARD-pinned by `bulk-generate`/`bulk-generate-endpoint` to exactly `{included:2, excluded:1, skipped:3, …}` with 2 items (low-confidence/unclassified/conflicting products are SKIPPED). Adding `set_unclassified` emission or conflict-first ordering there would break those green suites and mis-state the 05-03 contract. So the new behavior lives in `GenerateClassificationPlan`, mirroring 06-04's decision to add `GenerateGroupPlan` beside `GeneratePlan` and to dispatch the group op via `ResolveOperation()` rather than `RegisteredOperations()`. `RegisteredOperations()` remains the closed two-taxonomy-op contract; this plan did not touch it.

## Post-grouping re-run on the 06-01 prod snapshot (real data)

Run on a temp COPY of `custom/grocy_AI/.snapshots/grocy-prod.sqlite` (symlinked, read-only; skipped gracefully if absent — the snapshot is never mutated).

- **Before grouping: 57 confident classifications. After grouping: 63.** (+6, attributable solely to grouping.)
- The post-grouping classification plan **applies + rolls back clean** via `bin/verify-inventory-diff.php`: apply changes ONLY `grocy_ai_taxonomy_classifications` + the bulk ledger (`inventoryDiffCompare` violations `[]`); after rollback native `products` are byte-identical (DATA-07).

**Note (deviation):** the raw snapshot predates enrichment capture — it has **0 `grocy_ai_taxonomy_evidence` rows**, and product names don't contain group names, so the 06-04 group-suggestion op yields **0 suggestions** on it as-is. To exercise the evidence → grouping → stronger-classification chain end-to-end on production-shaped data, the re-run seeds representative **below-threshold** (`low`) provider evidence (`Produce`/`Seafood`/`Beverages`) for 6 ungrouped in-scope products on the temp copy. Low-band provider evidence does NOT classify a product on its own (it stays `low_confidence`), but it DOES drive a confident group suggestion — so the confident count rises only after `products.product_group_id` is set, isolating grouping's effect. The seed touches only the temp copy; the snapshot is untouched.

## Suite growth

`taxonomy-review` (28 checks) runs in the default no-arg suite via the shared `check()` (registered in `run.php` after the `group_suggestion` block; requires its own `src/*` + `bin/verify-inventory-diff.php` deps guarded by `is_file`). RED-first verified: with `GenerateClassificationPlan` absent the suite fails on `taxonomy-review: ReviewProductTaxonomy + GenerateClassificationPlan are available`, GREEN once restored (194 → 222).

## Files

- **Changed:** `custom/grocy_AI/src/GrocyAiTaxonomyService.php` (candidate-scoring constants + `ReviewProductTaxonomy` + `GroupSignalCandidate`/`ProviderCandidate` helpers; read-only, no write-path change); `custom/grocy_AI/src/GrocyAiBulkService.php` (`GenerateClassificationPlan` sibling generator, placed between `GenerateGroupPlan` and `RegisteredOperations` — outside every method-body grep slice); `custom/grocy_AI/tests/taxonomy.php` (the `taxonomy-review` suite); `custom/grocy_AI/tests/run.php` (register the suite after `group_suggestion`); `MISTAKES.md` (GREP-01 hits 6→7; two new observations on the pinned `GeneratePlan` counts and the pinned `ReadProductTaxonomy` shape).
- **New src files:** none (no `portable-files.txt` change; test file omitted per the 06-02/03/04 precedent).

## Deviations / notes

- Sibling generator instead of mutating `GeneratePlan` (above); `ReviewProductTaxonomy` as a new method instead of extending the pinned `ReadProductTaxonomy` DTO.
- The snapshot re-run seeds low-band evidence on a temp copy (above) — the only way to demonstrate the causal chain on a snapshot that predates enrichment capture.
- **Uncertainty:** none material — the full pre-existing `bulk-*`/`taxonomy-*` suites (which pin the exact bytes/checksums/counts/shape) pass unchanged, and the real-data re-run confirms the improvement + clean apply/rollback.
