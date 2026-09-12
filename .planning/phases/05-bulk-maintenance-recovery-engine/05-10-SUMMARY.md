# Plan 05-10 Summary — Redacted JSON/CSV export snapshot (BULK-10)

**Status:** complete. `bulk-export` passes; `bulk-invariants` is now GREEN (all four engine
methods `GeneratePlan`/`ApplyPlan`/`PreviewRollback`/`ExportPlan` exist).

## What was built

A redacted, explicitly non-authoritative JSON and CSV snapshot of a bulk plan/preview, served as a
permission-checked zero-write read for independent human review and recovery evidence.

### Files modified (module repo `custom/grocy_AI/`)
- `src/GrocyAiBulkService.php` — new public `ExportPlan(int|string $planId, string $format): array|string`
  plus private helpers `ExportItemRow`, `ExportProductNames`, `ExportCsv`, `ExportCsvRow`, and the closed
  allowlist constants `EXPORT_ITEM_FIELDS`, `EXPORT_SNAPSHOT_VERSION`, `EXPORT_NON_AUTHORITATIVE_NOTE`.
- `src/GrocyAiApiController.php` — new `ExportBulkPlan` read endpoint method (MASTER_DATA_EDIT-gated).
- `tests/bulk.php` — new `runBulkExport` (mode `bulk-export`) + helpers `bulkExportStateSnapshot`,
  `bulkExportByObject`.
- `tests/run.php` — `bulk-export` dispatch.

### Deliberately NOT modified (per Senior Dev scope override)
- `routes.php` — route binding stays with Plan 05-11 (endpoint method exposed; route not registered here).
- `public/custom/grocy_AI/bulk-review.js` / `bulk-review.test.js` — the consolidated UI pass owns the
  export button; only the endpoint + contract are provided.
- `portable-files.txt` — owned by Plan 05-11.

## Export endpoint contract (for the UI to call)
- **Method / path:** `GET /api/grocy-ai/bulk/plans/{planId}/export` (to be bound in 05-11).
- **Auth:** `PERMISSION_MASTER_DATA_EDIT`, checked before any read. Zero-write.
- **Query param:** `format` = `json` (default) | `csv`. Any other value → bounded `400`
  `{"error_message":"Invalid export request"}`. Non-integer `planId` → `400`. Unknown plan → `404`
  `{"error_message":"Plan unavailable"}`.
- **JSON response:** `Content-Type: application/json`, `Content-Disposition: attachment;
  filename="grocy-ai-bulk-plan-<id>-non-authoritative.json"`, `Cache-Control: private, no-store`,
  `X-Content-Type-Options: nosniff`. Body = the snapshot envelope (below).
- **CSV response:** `Content-Type: text/csv; charset=utf-8`, `Content-Disposition: attachment;
  filename="grocy-ai-bulk-plan-<id>-non-authoritative.csv"`, same cache/nosniff headers. Body = RFC-4180
  CSV: 4 leading `#` comment rows (non-authoritative marker + checksum-binding metadata + counts), one
  header row, one row per item.
- **No re-import surface:** there is no endpoint (and no service method) that consumes an uploaded
  snapshot. Re-import as authority is deferred to V2-03.

### JSON envelope shape
```
{ snapshot_version, authoritative:false, reimport_supported:false, non_authoritative_note,
  plan: { plan_checksum, operation_type, ruleset_version, generated_at, module_version,
          counts:{included,excluded,skipped,conflicted,changed,unchanged} },
  items: [ <allowlisted item>, ... ] }
```

## Redaction — closed default-deny allowlist

**Emitted per-item fields (the ONLY fields, in this exact order, in both JSON and CSV):**
`plan_checksum, object_type, object_id, product_name, operation, before_value,
proposed_or_after_value, reason, provenance, ruleset_version, selection_state, outcome`
(object identity = `object_type` + `object_id`). Plus the plan metadata header + the six closed counts
+ the non-authoritative marker.

**Explicitly redacted (never emitted):** companion API keys, service secrets/tokens, opaque media/image
handles, session identifiers, raw actor credentials, and unrelated household detail (stock levels,
prices, purchase/consumption history, locations).

**Why it is closed (a future DTO field cannot auto-leak):**
- Plan header read field-by-named-field — never the raw `SELECT *` row.
- Plan-item rows read via an EXPLICIT column list — never `SELECT *`.
- Each row projected through the `EXPORT_ITEM_FIELDS` constant.
- Product names via a bounded read-only `SELECT id, name FROM products WHERE id IN (...)` — no other
  product column is ever read.
- The audit ledger is not dumped.

**How the test proves redaction:** `runBulkExport` injects canary columns/values into every source
table a naive exporter might dump — `grocy_ai_bulk_plans.service_secret`,
`grocy_ai_bulk_plan_items.companion_api_key`/`media_handle`, `grocy_ai_bulk_audit.session_id`, and
`products.purchase_price`/`stock_level`/`location_name`/`api_key`/`image_handle` — then asserts each
canary string is ABSENT from both the JSON (serialized) and CSV outputs, while asserting the allowlisted
reviewed content (product name, checksum, leaf values, outcome, non-authoritative marker) IS present so
redaction is not vacuous.

## Guarantees verified by `bulk-export`
- JSON + CSV emit only allowlisted fields in the closed order; CSV is RFC-4180 quoted/escaped (proved
  with a hostile `Weird, "name"` product name).
- Non-authoritative / no-re-import marker present in both formats; snapshot bound to the plan checksum.
- Injected secrets/tokens/media/session/price/stock absent from both formats.
- Zero-write: schema + rows + `total_changes()` byte-identical across json+csv exports (service and
  endpoint, incl. under `PRAGMA query_only = ON`).
- Unsupported format → bounded error, no write; unknown plan → fail closed, no write.
- No import-as-authority path (no `ImportPlan`/`ImportSnapshot` service method, no `ImportBulkPlan`
  controller method, and `ExportBulkPlan` reads no request body).
- Endpoint permission-checked before any read; unauthorized call writes nothing.

## Decision / note
- **`product_name` vs D-14:** the mandated `product_name` allowlist field is not carried in the
  plan/plan-item/audit rows and no existing read path returns it, so it is sourced via a bounded,
  read-only `id, name` lookup on `products` (mirroring the direct `products` read `GeneratePlan`
  already performs). This is a pure read (no write, no cache SQL, no other column) and preserves the
  zero-write proof; it is the deliberate reconciliation of the required `product_name` field with the
  "reads plan/plan-item/audit rows / no ad-hoc cache SQL" constraint.

## Test results
- `bulk-export` → `Bulk export tests passed`
- `bulk-invariants` → `Bulk engine invariants passed` (GREEN)
- All other bulk modes, all `taxonomy-*` modes, and the default suite (`All 122 grocy_AI checks
  passed`) pass.
- `conversion-characterization` fails with the pre-existing environmental `branch_commit_mismatch`; no
  characterization file was touched.
