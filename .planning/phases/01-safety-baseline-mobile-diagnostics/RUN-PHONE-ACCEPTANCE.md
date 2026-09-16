# Phone acceptance runbook (Plan 01-10)

Do-it-once checklist to close Phase 1. ~45–60 min with a phone on the household LAN.
Everything you record is redacted metadata + timings only — never a GTIN, product, URL, or token.

Deliverables this produces:
- `evidence/phone-timings.jsonl` — 20 `cached` + 20 `metadata` + 20 `image_attachment` success samples + 1 exact 15000 ms timeout.
- Filled scenario matrix + normal-Save spine written into `01-PHONE-ACCEPTANCE.md`.
- Your typed `approved`.

The number you read every time is the Diagnostics summary line:
`Diagnostics: <outcome> · …<trace> · <NNN> ms` — that `NNN` is `overall_duration_ms`.

---

## Step 0 — Confirm you're testing the exact deployed artifact (blocking)

On the deployed host, confirm all three match Plan 01-09 before sampling. If any differ, STOP — you'd be testing the wrong build.

- [ ] Running image digest = `sha256:d1c133275fe5d458ff5ecc83d25b0435f52e0236d8a810feec8352e972686957`
- [ ] Stable adapter revision = `770ba4f11b362061fbd7bf5c66549840235f1152`
- [ ] Route/view cache marker = `ATECHPCS-grocy_AI-3` (from `version.json`)
- [ ] Continuity sanity: ~220 products, ~979 product-picture files still present

## Step 1 — One-time device profile

```
cd .planning/phases/01-safety-baseline-mobile-diagnostics/evidence
cp device-profile.example.json device-profile.json
# edit device-profile.json with your real phone/session values.
# grocy/module/companion/contract versions come from the Copy-diagnostic report's `versions` block.
```
Rules: no GTIN/UPC, no URLs, no `://`, no 8+ digit runs, no tokens. The helper rejects any of these.

## Step 2 — Capture 60 timing samples + 1 timeout

Open a product form on the phone (`/product/new`). For each run: type/scan a GTIN → **Search product** → expand **Diagnostics** → read the `NNN ms`.

Classify each run from the copied report's `stages[]`:
- **cached** — re-run the *same* GTIN right after a fresh one; a stage shows `cache: hit`. Expect fast (< 1000 ms).
- **metadata** — a *cold* GTIN (cache miss), result is text-only or you ignore the image. Expect < 5000 ms.
- **image_attachment** — a lookup whose result includes a package image and an image stage runs. Expect < 5000 ms.

Log them as you go (space-separate many at once):
```
python3 record-samples.py add --scenario metadata --ms 2450 2610 2380 ...
python3 record-samples.py add --scenario cached   --ms 640 590 710 ...
python3 record-samples.py add --scenario image_attachment --ms 3100 2950 ...
python3 record-samples.py status      # shows X/20 for each until you hit 20/20/20
```
Then force ONE timeout (companion down or airplane-mode mid-request; wait for the named Timeout state at 15 s):
```
python3 record-samples.py timeout
```
The helper validates every line with the release checker's own rules, so a bad value is refused before it's written — you never hand-edit JSON.

## Step 3 — Run the UI-SPEC scenario matrix (once each, mark PASS/FAIL)

Each must show its exact named state; none may auto-retry; the rest of the form + Save stay usable throughout.

- [ ] Manual valid GTIN (EAN-8 / UPC-A / EAN-13 / GTIN-14) → validates < 250 ms, Search enabled
- [ ] Manual invalid length → `is-invalid`, no network request
- [ ] Manual invalid check digit → checksum message, no network request
- [ ] Camera scan success → GTIN populates, modal closes, one search starts
- [ ] Camera permission denied → "Camera scanning is unavailable" fallback
- [ ] Normal LAN success → success heading + preview
- [ ] Shaped slow LAN → still succeeds or names a state; no auto-retry
- [ ] Disconnect → Offline state; Reconnect → NO automatic retry (explicit Retry only)
- [ ] Exact 15 s timeout → named Timeout state
- [ ] Cancel mid-search → neutral "no changes made", result stays hidden
- [ ] Explicit Retry → one new trace/request
- [ ] Repeat tap/scan → no duplicate effect; newest intent wins
- [ ] Background/foreground → no stuck spinner/modal/disabled control
- [ ] Browser Back → clean state, no obsolete result
- [ ] Not found → "no exact match", no empty grid
- [ ] Companion unavailable → named safe stage, recovery offered
- [ ] Metadata provider unavailable → named safe stage
- [ ] Image host unavailable / partial → partial-image warning, metadata still usable
- [ ] Field review + name staging works
- [ ] Image staging (structured before search alternatives) works
- [ ] Copy diagnostic report → success toast, or fallback textarea if clipboard blocked
- [ ] Reload → clean

## Step 4 — Normal-Save restoration spine (once)

Pick any test product. Do NOT record its ID/name/value.
1. [ ] Read it (authenticated) — `operator_selected_product_read`
2. [ ] Change one reversible field via normal **Save** — `normal_save_write`
3. [ ] Reload, confirm the change — `reload_confirmed`
4. [ ] Restore the original via normal **Save** — `restoration_save`
5. [ ] Reload, confirm restored — `final_reload_confirmed`

No SQL, no enrichment write endpoint, no auto-retry. Enrichment stays zero-write throughout.

## Step 5 — Finalize

```
python3 evidence/check-phone-timings.py evidence/phone-timings.jsonl   # must exit 0, four PASS lines
```
Then in `01-PHONE-ACCEPTANCE.md`, replace the recorded SKIP with PASS results:
- Paste the four checker PASS lines (counts + p50/p95).
- Mark every Step 3 scenario PASS with device/browser/network metadata.
- Record the five Step 4 booleans as PASS, `zero_enrichment_writes` PASS, `normal_save_restore` PASS.
- Add the Step 0 `stable_adapter_sha` / `stable_image_digest` / `portable_parity` linkage.

Reply here with **`approved`** and I'll write `01-10-SUMMARY.md` and flip Phase 1 to complete.
Any FAIL → tell me which scenario; thresholds do not move.
