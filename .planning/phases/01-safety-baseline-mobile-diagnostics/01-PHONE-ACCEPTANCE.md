# Phase 01 Physical-Phone Acceptance

Status: physical-phone evidence explicitly skipped by the operator on 2026-08-13; Plan 01-10 and Phase 1 remain incomplete.

## Operator disposition

The operator confirmed the normal product Save controls and selected functional phone checks, then explicitly chose to skip the required device/browser metadata and locked timing capture and proceed to Phase 2. This is recorded as **SKIPPED**, not PASS.

- Deployed stable revision at disposition: `9f9ce169e155c9ec1fa01a67745c94276d86b2da`
- Deployed image: `sha256:2fe2ab1e61be7a8928fab90ac4365cdcbfd9140bd641b5fd8c826f3e1bbab815`
- Cache marker: `ATECHPCS-grocy_AI-7`
- Module asset token: `1.0.1`
- Timing evidence: skipped; no synthetic or inferred samples were added
- Locked checker result: FAIL — `cached` 0/20, `metadata` 0/20, `image_attachment` 0/20, `browser_timeout` 0/1
- Release effect: no physical-device acceptance claim; Phase 1 remains incomplete

This procedure validates the real household phone, browser, Wi-Fi/LAN path, stable Grocy deployment, and locked latency baseline. Browser emulation is necessary but does not replace this record.

## Safety and privacy rules

- Use a non-sensitive test item chosen by the operator, but never record its GTIN, name, suggested fields, image address, download handle, or inventory state.
- Never paste request/response bodies, query strings, navigation history, headers, cookies, CSRF data, credentials, API keys, bearer values, stack traces, or raw exceptions into this file or `evidence/phone-timings.jsonl`.
- Record only the closed fields accepted by `evidence/check-phone-timings.py`. Do not add notes or ad hoc fields to JSONL records.
- Enrichment must remain read-only. Each evidence record requires `write_count: 0`, normal Save availability, and restored form state.
- Keep the committed evidence file comment-free and one JSON object per line. The initially empty file intentionally fails the release checker until physical sampling is complete.

## Preconditions

- [x] The exact stable deployment commit is recorded as a full 40-hex SHA.
- [x] The portable parity command has been run against that SHA without switching the `atech-main` checkout.
- [x] The stable route/render/image smoke in `custom/grocy_AI/README.md` is green.
- [x] The Grocy, grocy_AI module, companion, and diagnostic contract versions are visible and recorded without host URLs or credentials.
- [ ] The household phone is on the intended Wi-Fi/LAN or approved VPN route.
- [ ] Normal Grocy Save controls work before enrichment testing begins.

## Stable deployment prerequisites (Plan 01-09)

This section contains deployment and redacted route prerequisites only. It does not contain phone timings, device/browser metadata, product or GTIN values, credentials, cookies, response bodies, or normal-Save mutation evidence.

| Field | Recorded prerequisite |
|---|---|
| `portable_stable_sha` | `217a7a0e98889cf4953d3fb7bdc2bf038be4ce7f` |
| `stable_adapter_sha` | `770ba4f11b362061fbd7bf5c66549840235f1152` |
| `stable_image_digest` | `sha256:d1c133275fe5d458ff5ecc83d25b0435f52e0236d8a810feec8352e972686957` |
| Image revision label | `770ba4f11b362061fbd7bf5c66549840235f1152` |
| Deployment timestamp | `2026-08-13T02:50:16.745276376Z` |
| `cache_marker` | `ATECHPCS-grocy_AI-3` (changed from portable parent marker `ATECHPCS-grocy_AI-2`) |
| Versions | Grocy `4.6.0`; grocy_AI module `1.0.0`; diagnostic contract `1`; companion reported closed value `unknown` |
| `persistent_volume` | PASS — `/etc/komodo/grocy:/config` remains a read-write mount; database present after restart |
| Existing data continuity | PASS — aggregate counts remain 220 products and 979 product-picture files |
| `authenticated_route_smoke` | PASS — status, enrichment, selected-image, and product-form routes returned HTTP 200 |
| Unauthorized route smoke | PASS — status, enrichment, and selected-image routes each returned HTTP 401 without authentication |
| Route/view cache invalidation | PASS — the new product-enrichment card rendered; JavaScript and CSS references were present; both assets returned HTTP 200; deployed route, controller, view, version, JavaScript, and CSS hashes matched the stable source |
| `degraded_smoke` | PASS — deployed authenticated status/enrichment/image boundaries were exercised live; finite timeout, companion-unavailable, provider-error, and partial-image states were verified by the deterministic release suites because changing the household companion to shape live failures was not safe |
| `zero_enrichment_writes` | PASS — before/after row counts and SHA-256 fingerprints for products, product barcodes, stock, and stock log were identical; the product-picture count and tree fingerprint were also identical |
| Rollback readiness | PASS — the pre-deployment Compose backup and previous image remain present; the temporary build directory was removed after verification |

### Redacted smoke evidence

- SHA-pinned portable parity: PASS — 7 identical, 0 mismatched, 0 missing.
- Stable native contract: PASS — all 84 checks passed.
- Browser smoke suite: PASS — 4 tests passed across the locked mobile browser projects.
- Full deterministic browser release suite: PASS — all 78 tests passed, including timeout, unavailable-companion, provider-error, partial-image, recovery, and zero-write behavior.
- Live enrichment returned a closed `success` outcome and six image candidates; selected-image retrieval returned an allowlisted JPEG of 43,568 bytes. No candidate values or handles were recorded.
- Product, barcode, stock, stock-log, and product-picture aggregate fingerprints were unchanged across all authenticated and unauthorized live route requests.

## Run metadata

Record these values in every JSONL line so samples remain independently auditable:

| Evidence field | Required value |
|---|---|
| `schema_version` | Integer `1` |
| `recorded_at` | UTC second, for example `2026-08-13T01:02:03Z` |
| `device_model` | Phone model only; no owner/user identifier |
| `os_name`, `os_version` | Installed phone OS and version |
| `browser_name`, `browser_version` | Tested browser and exact version |
| `viewport_width_px`, `viewport_height_px` | CSS viewport dimensions at capture time |
| `orientation` | `portrait` or `landscape` |
| `network_route` | `wifi_lan`, `wifi_vpn`, or `cellular_vpn` |
| `network_condition` | `normal`, `slow`, `disconnected`, or `reconnected` |
| `server_instance` | Fixed privacy-safe alias `household_lan` |
| `grocy_version`, `module_version`, `companion_version`, `contract_version` | Deployed component versions |
| `scenario` | `cached`, `metadata`, `image_attachment`, or `browser_timeout` |
| `attempt` | Positive sequence number within the scenario |
| `outcome` | Closed outcome; counted performance samples must be `success`, timeout sample must be `timeout` |
| `overall_ms` | End-to-end scenario duration used for nearest-rank statistics |
| `browser_ms`, `grocy_ms`, `companion_ms`, `provider_ms`, `image_ms` | Nonnegative stage milliseconds, or `null` when the stage did not apply |
| `normal_save_available` | `true` only when both ordinary Save actions remained enabled |
| `read_count`, `write_count` | Observed enrichment reads and durable writes; writes must stay zero |
| `form_restored` | `true` only when manual fields and selected file/preview state remained intact |

## Required timing samples

Use a fresh terminal diagnostic for each attempt. Record integer milliseconds, not rounded seconds.

| Scenario | Samples that count | Locked gate |
|---|---:|---:|
| `cached` | 20 successful attempts | nearest-rank p95 ≤ 1000 ms |
| `metadata` | 20 successful attempts | nearest-rank p95 ≤ 5000 ms |
| `image_attachment` | 20 successful attempts | nearest-rank p95 ≤ 5000 ms |
| `browser_timeout` | At least 1 timeout attempt | exactly 15000 ms |

The checker sorts each scenario's `overall_ms` values and uses rank `ceil(percentile × sample_count)`. Thresholds and sample counts are code constants; evidence cannot override or re-baseline them.

## Physical scenario checklist

### Normal LAN and input paths

- [ ] At 320px-equivalent portrait width, manual valid entry shows ready/busy feedback promptly, produces one read, and creates no write.
- [ ] Camera scan hands the decoded value to the same validation/search path exactly once.
- [ ] Invalid length and invalid check digit show distinct local feedback without a request.
- [ ] Search, metadata review, existing name selection, existing image selection, normal Save, and reload complete; reloaded data reflects only the explicit normal Save.
- [ ] Both Save actions remain available before, during, and after enrichment.

### Slow and interrupted LAN

- [ ] Under the available slow-LAN condition, visible feedback remains prompt and all waits terminate in a named state.
- [ ] Disconnect while a request is active; the card reports offline and the surrounding form remains usable.
- [ ] Reconnect does not start a request. One explicit Retry creates exactly one new request.
- [ ] Ten repeated taps/scans for the same active intent still produce one request and one visible result.
- [ ] Cancel restores Search immediately and late completion cannot render.

### Lifecycle and camera recovery

- [ ] Rotate portrait ↔ landscape during an active request; no modal, spinner, disabled Search, or obsolete result remains.
- [ ] Background and foreground the browser during an active request; no automatic request or stale result appears.
- [ ] Navigate Back and return; the card restores controls without replaying enrichment.
- [ ] Deny camera permission or test a camera-unavailable context; manual entry remains focused and usable with named guidance.

### Dependency degradation

- [ ] Companion unavailable produces the named safe recovery state and no raw transport text.
- [ ] A metadata provider unavailable/timeout produces the correct finite outcome rather than a false not-found claim.
- [ ] Image search or image host unavailable preserves usable metadata as partial success.
- [ ] Selected-image download failure preserves the prior file selection and manual form values.
- [ ] Copy diagnostics succeeds or exposes the same selected read-only fallback, with no forbidden household data.

## Evidence capture procedure

1. Confirm the preconditions and record the stable SHA outside the JSONL file in the Plan 01-10 summary.
2. For each timing scenario, keep device/browser/network/version fields constant for that run and increment `attempt` from 1.
3. Capture the end-to-end and available stage durations from the redacted diagnostic UI or timing instrumentation only.
4. Confirm normal Save availability, form restoration, read count, and zero durable writes for every record.
5. Append exactly one closed-schema JSON object to `evidence/phone-timings.jsonl`; do not include comments or blank annotations.
6. Run:

   ```bash
   python3 .planning/phases/01-safety-baseline-mobile-diagnostics/evidence/check-phone-timings.py
   ```

7. The release gate passes only when all four printed lines end in `PASS` and the complete scenario checklist is checked.

## Acceptance result

- Stable SHA: recorded in Plan 01-10 evidence summary, never inferred from a moving ref.
- Timing checker: **SKIPPED** by operator; locked checker remains FAIL.
- Scenario checklist: **SKIPPED** before completion.
- Privacy review: no physical timing records were created.
- Final result: **SKIPPED — NOT ACCEPTED**.
