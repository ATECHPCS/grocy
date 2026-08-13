# Phase 01 Physical-Phone Acceptance

Status: awaiting stable deployment and physical evidence in Plans 01-09 and 01-10.

This procedure validates the real household phone, browser, Wi-Fi/LAN path, stable Grocy deployment, and locked latency baseline. Browser emulation is necessary but does not replace this record.

## Safety and privacy rules

- Use a non-sensitive test item chosen by the operator, but never record its GTIN, name, suggested fields, image address, download handle, or inventory state.
- Never paste request/response bodies, query strings, navigation history, headers, cookies, CSRF data, credentials, API keys, bearer values, stack traces, or raw exceptions into this file or `evidence/phone-timings.jsonl`.
- Record only the closed fields accepted by `evidence/check-phone-timings.py`. Do not add notes or ad hoc fields to JSONL records.
- Enrichment must remain read-only. Each evidence record requires `write_count: 0`, normal Save availability, and restored form state.
- Keep the committed evidence file comment-free and one JSON object per line. The initially empty file intentionally fails the release checker until physical sampling is complete.

## Preconditions

- [ ] The exact stable deployment commit is recorded as a full 40-hex SHA.
- [ ] The portable parity command has been run against that SHA without switching the `atech-main` checkout.
- [ ] The stable route/render/image smoke in `custom/grocy_AI/README.md` is green.
- [ ] The Grocy, grocy_AI module, companion, and diagnostic contract versions are visible and recorded without host URLs or credentials.
- [ ] The household phone is on the intended Wi-Fi/LAN or approved VPN route.
- [ ] Normal Grocy Save controls work before enrichment testing begins.

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
- Timing checker: pending physical samples.
- Scenario checklist: pending stable deployment.
- Privacy review: pending final evidence scan.
- Final result: **PENDING**.
