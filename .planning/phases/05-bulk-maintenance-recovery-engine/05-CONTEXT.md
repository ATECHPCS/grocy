# Phase 5: Bulk Maintenance & Recovery Engine - Context

**Gathered:** 2026-08-29
**Status:** Ready for planning

<domain>
## Phase Boundary

This phase generalizes Phase 2's zero-write preview/diff/selected-Save review model and Phase 4's single evidence-gated activation transaction into one reusable bulk workflow: create a bounded zero-mutation dry-run plan, review and select individual items, apply the approved plan once through a short conflict-safe transaction, audit exactly what changed, export a redacted snapshot, and preview a guarded rollback.

Phase 5 delivers the engine and its closed catalog of named typed operations. It proves the engine end-to-end against the already-shipped Phase 3 taxonomy assignment write, but it does not perform the full existing-inventory sweep and does not add conversion-cleanup operations — those belong to Phase 6. Grocy remains the sole durable mutation authority; the engine never becomes a parallel write path, never accepts browser- or companion-supplied CRUD or SQL, and never calls a provider while a write lock is held.

</domain>

<decisions>
## Implementation Decisions

### Dry-Run Plan Generation
- **D-01:** `GeneratePlan` produces a bounded, zero-mutation dry-run plan reporting exact included, excluded, skipped, conflicted, changed, and unchanged counts. Zero-write is proven the same way Phase 4 proves its read-only endpoints: schema, state, and `total_changes` assertions plus a before/after snapshot. (BULK-01)
- **D-02:** Every plan item records a stable object identity, an immutable before-image, the proposed value, a named reason, provenance, and the ruleset version that produced it. Before-images are captured at generation time and never re-derived. (BULK-02)
- **D-03:** Each plan carries a checksum computed over its immutable content — item identities, before/proposed values, operation types, and ruleset version — so the reviewed plan and the applied plan are provably the same artifact. (BULK-02)

### Review, Selection & Named Operations
- **D-04:** The user can select or reject each proposed item individually and inspect the complete selected diff before approving. Selection state lives with the plan; the apply set is exactly the selected, non-conflicted items. (BULK-03)
- **D-05:** Apply executes only named typed operations drawn from a closed server-side registry. It can never execute arbitrary CRUD payloads, free-form entity/field targets, or SQL supplied by the browser or companion; unknown operations fail closed with one bounded blocker. (BULK-05)
- **D-06:** The engine is proven against the shipped Phase 3 `GrocyAiTaxonomyService::AssignProductTaxonomy` write (typed operations such as `assign_taxonomy_leaf` / `set_unclassified`). Conversion-cleanup and other operations are registered later in Phase 6. Each registered operation delegates to an existing native or module write path — it introduces no new low-level write. (BULK-05, [NEW registry])

### Apply Transaction Integrity
- **D-07:** Before writing each item, apply re-reads the current field values and refuses any item whose current value no longer matches the reviewed before-image (optimistic concurrency), recording a per-item `conflict` outcome without partial write. (BULK-04)
- **D-08:** An approved plan applies through one short `BEGIN IMMEDIATE` transaction (generalizing `ActivateVerifiedRuleset`'s single guarded transaction) with no network, provider, or companion calls while the write lock is held. (BULK-06)
- **D-09:** Apply and retry are idempotent: an application ledger keyed on the plan checksum (mirroring the `evidence_hash` existence check in `ActivateVerifiedRuleset`) prevents any completed mutation from being repeated, so re-running or resuming an interrupted apply cannot duplicate effects. (BULK-07)

### Audit, Rollback & Export
- **D-10:** Every apply appends an immutable audit record set — actor, previewed/applied timestamps, module/version, per-item outcome, and exact before/after values — modeled on the append-only `grocy_ai_conversion_activation_evidence` ledger. The actor is the authenticated Grocy session user (RESOLVED 2026-08-29). (BULK-08)
- **D-11:** Rollback preview is zero-write and refuses to overwrite any field changed manually after the original apply (it re-reads current values against the audited after-image). Rollback execution reuses the same named-typed-operation, optimistic-concurrency, single-transaction path as forward apply. (BULK-09)
- **D-12:** The user can export a redacted JSON or CSV snapshot of a plan or preview for independent review and recovery evidence. The snapshot is explicitly non-authoritative on re-import; re-import as a proposal is deferred to v2 (V2-03). (BULK-10)

### Authority & Compatibility
- **D-13:** Preview generation, selection, the selected diff, export, and rollback preview are user-facing reads under `PERMISSION_MASTER_DATA_EDIT`. The durable apply is a user-facing, authenticated, audited in-app API action under the same permission (RESOLVED 2026-08-29 — matches the roadmap's "User can apply"); it reuses `ActivateVerifiedRuleset`'s single-transaction, fail-closed, idempotent conventions but is NOT gated behind the maintainer-only CLI. No maintainer CLI apply path is added in Phase 5. (BULK-08)
- **D-14:** Grocy remains the sole durable mutation authority. The engine adds no parallel write path and no ad-hoc cache SQL; typed operations delegate to existing native Save and shipped module write methods so Grocy's triggers, cache, and consumer behavior stay unchanged. ([carried constraint])
- **D-15:** Keep all feature code under `custom/grocy_AI/` and `public/custom/grocy_AI/` with a namespaced, idempotent migration; maintain portable parity and the dual-branch release gate; run PHP as `php8.5`/`GROCY_AI_PHP`. Phase 5 is additive — it modifies no existing native or module write path, and Phase 6 owns the actual inventory sweep. ([carried constraint])

### the agent's Discretion
- Choose the exact plan-checksum serialization within the established SHA-256 idiom (`CharacterizationFactsHash`/`EvidenceHash`), the JSON/CSV snapshot column set and redaction list, the audit and plan/plan-item table schema shapes, the approval-token mechanism, and the compact Bootstrap 4 review controls — provided the immutable-plan, named-operation, optimistic-concurrency, single-transaction, idempotency, audit, and non-authoritative-export contracts hold.
- Stale-plan safety is per-item optimistic concurrency only (RESOLVED 2026-08-29): no plan-level TTL and no fixed item-count cap are required, though `GeneratePlan` still produces a bounded scope per BULK-01.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase Contract and Prior Decisions
- `.planning/ROADMAP.md` — Phase 5 goal, five success criteria, dependency on Phase 4, and the Phase 6 inventory-sweep boundary.
- `.planning/REQUIREMENTS.md` — Authoritative `BULK-01` through `BULK-10`, the review-before-save rule, and the v2 snapshot-reimport deferral (`V2-03`).
- `.planning/PROJECT.md` — Human-control, native-Grocy, custom-module, deployment, and data-safety constraints (dry-run preview, bounded scope, conflict reporting, auditable result even without a DB backup).
- `.planning/STATE.md` — Current position, the Phase 4 activation authority, and the open concerns (no projection selected; maintainer-auth file setup; stable mirroring pending).
- `.planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-CONTEXT.md` — The zero-write preview/diff/selected-Save review model Phase 5 generalizes.
- `.planning/phases/04-reusable-conversion-model/04-CONTEXT.md` — Evidence-gated activation, native-authority, and dual-branch decisions this phase reuses.

### Phase 4 Activation and Gating Patterns to Reuse
- `.planning/phases/04-reusable-conversion-model/04-08-SUMMARY.md` — The sole evidence-gated `ActivateVerifiedRuleset` transaction, the immutable ledger table, idempotency via `evidence_hash`, and fail-closed blocker vocabulary.
- `.planning/phases/04-reusable-conversion-model/04-10-SUMMARY.md` — The maintainer-only CLI authorization boundary, pinned facts constant, redacted output, and sole-path release-gate checks.
- `.planning/phases/04-reusable-conversion-model/04-CHARACTERIZATION.md` — The dual-branch evidence model and the caveat that Grocy triggers derive inverses and the resolved cache is product-scoped.

### Existing Implementation and Integration
- `custom/grocy_AI/src/GrocyAiConversionService.php` — `ActivateVerifiedRuleset` (single `beginTransaction`/`commit`/`rollBack`, `hash_equals`, evidence-existence idempotency, bounded blockers) — the transaction template for bulk apply.
- `custom/grocy_AI/src/GrocyAiTaxonomyService.php` — `AssignProductTaxonomy` (closed argument-key allowlist, ruleset-version check, single transaction, `ON CONFLICT` upsert) — the proving typed operation and single-item write model.
- `custom/grocy_AI/bin/activate-verified-conversion-ruleset.php` — CLI-only SAPI refusal, deployment-owned secret file, `hash_equals`, closed argument formats, redacted JSON, bounded exit codes.
- `custom/grocy_AI/src/GrocyAiApiController.php` — `User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT)`, `DiagnosticResponse`, and closed error-reason mapping for API endpoints.
- `controllers/Api/GenericEntityApiController.php` — `ValidateQuantityUnitConversionBeforeWrite` → `ValidateNativeConversionBeforeWrite`, the native pre-save hook boundary that must stay fail-closed.
- `custom/grocy_AI/routes.php` and `routes.php` — Module route group, `require_once` service wiring, and the standalone controller route.
- `custom/grocy_AI/tests/run.php`, `tests/conversions.php`, `tests/taxonomy.php`, `tests/release-gate.sh`, `tests/browser/` — Test harness, fixture-spy conventions, and the `conversions`/`taxonomy` release-gate modes to extend with a `bulk` mode.
- `custom/grocy_AI/portable-files.txt` — 36-path manifest every new Phase 5 file must join for parity.
- `MISTAKES.md` — Run PHP as `php8.5`; grep `conversions.php` for same-named spy tables before adding any `grocy_ai_*` table; never assert cache/row counts from reasoning.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `GrocyAiConversionService::ActivateVerifiedRuleset()`: a working single-transaction, fail-closed, idempotent (evidence-hash existence check), before/after-snapshot-tested mutation authority — the direct template for the bulk apply transaction.
- `grocy_ai_conversion_activation_evidence`: an append-only immutable ledger the bulk audit table can mirror in shape.
- `GrocyAiTaxonomyService::AssignProductTaxonomy()`: a closed-allowlist, single-item, ruleset-versioned typed write the engine can register and exercise without inventing a new write path.
- `bin/activate-verified-conversion-ruleset.php`: the maintainer-auth CLI pattern (secret file 0600/64-hex outside `GROCY_DATAPATH`, `hash_equals`, redacted output, bounded exit codes).
- `release-gate.sh` / `run.php` / `browser/`: parity, unit, and browser harnesses with established `conversions`/`taxonomy` modes to copy.

### Established Patterns
- Custom behavior stays under `custom/grocy_AI/` and `public/custom/grocy_AI/`; migrations are namespaced and idempotent; every module file joins `portable-files.txt` and the dual-branch gate.
- External and inferred data is reviewable evidence, not a durable write; the durable mutation is a deliberate, audited, named-operation action.
- Boundaries use closed allowlists, named reasons/blockers, explicit errors, and deterministic fixtures; new tables must not collide with earlier fixture-spy tables of the same name.
- SHA-256 checksums (`CharacterizationFactsHash`, `EvidenceHash`, pinned facts constant) already anchor cross-file integrity — reuse the idiom for the plan checksum.

### Integration Points
- Add a `GrocyAiBulkService` and `GrocyAiBulkMigration` [NEW] wired through `custom/grocy_AI/routes.php`, delegating durable writes to `AssignProductTaxonomy` and (in Phase 6) native conversion Save.
- Add read-only preview/selection/export/rollback-preview API endpoints under the existing `MASTER_DATA_EDIT`-gated `/api/grocy-ai` group; keep the apply action authenticated and audited.
- Preserve `ValidateNativeConversionBeforeWrite`'s zero-write guarantee; the engine must not weaken the native pre-save hook.
- Read current object values through existing Grocy/module read paths at both generation and apply time; never issue ad-hoc cache SQL.

</code_context>

<specifics>
## Specific Ideas

- The user must be able to trust that what they reviewed is exactly what applies: the plan checksum binds review to apply, and stale-before-image refusal binds apply to reality.
- Data safety must hold even when the user skips a database backup, so the audit trail and the guarded, resumable, idempotent apply are first-class, not optional add-ons.
- The redacted export is for independent human review and recovery evidence, not a re-import authority; re-import belongs to v2 after a fresh conflict check.

</specifics>

<deferred>
## Deferred Ideas

- The full existing-inventory classification sweep and redundant-conversion cleanup operations belong to Phase 6 (DATA-01 through DATA-07); Phase 5 provides only the engine and the closed operation registry.
- Re-importing an exported snapshot as a proposal after a mandatory fresh conflict check is the v2 item `V2-03`.
- An interactive conversion/impact graph over plans remains the v2 visualization item `V2-02`.

</deferred>

---

*Phase: 05-bulk-maintenance-recovery-engine*
*Context gathered: 2026-08-29*
