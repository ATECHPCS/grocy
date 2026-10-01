# Purchase receipt release and acceptance

This runbook applies to the purchase receipt audit in the Grocy 4.6 stable image and the `grocy-mcp` receipt OCR companion. Record exact Grocy and companion commits, image digests, test results, and the operator for each release. Keep receipt images, OCR text, API keys, and household inventory out of the release record.

## Local gate

From the Grocy checkout, run `php8.5 custom/grocy_AI/tests/run.php`, `php8.5 custom/grocy_AI/tests/receipt_rehearsal.php`, and `npm --prefix custom/grocy_AI/tests/browser run test:release`. From the companion checkout, run `GROCY_BASE_URL=http://grocy.invalid GROCY_API_KEY=test-grocy-key .venv/bin/python -m pytest tests -q`. The dummy URL and key are only for test collection; no household service is contacted. Verify the browser fixture is bound to loopback and that the receipt rehearsal reports zero stock rows, zero stock calls, and zero commit audit rows. The rehearsal creates two in-memory receipt records, one ignored purchased item, and an accepted total difference. It deliberately stops at readiness and does not call `CommitTrip`.

## Persistent data and recovery

The database is `GROCY_DATAPATH/grocy.db` in normal mode; receipt images are `GROCY_DATAPATH/grocy_ai/receipts/<trip-id>/<opaque-id>`. Capture and receipt migrations are bootstrapped in that SQLite database, with receipt schema version `v4` and append-only audit triggers. The image store writes private mode 0700 directories and 0600 files. Production must keep the entire `/etc/komodo/grocy` data mount across image replacement, including database, image files, configuration, and existing uploads. Confirm the actual container `GROCY_DATAPATH` and mount mapping before release; a local checkout cannot prove the live mount.

Before replacing the Grocy image, stop or quiesce writes and take a consistent backup of the entire data path. Check the archive contains both the SQLite database and `grocy_ai/receipts/`, record a checksum, and perform a restore rehearsal in an isolated location. If SQLite is copied while Grocy remains live, use SQLite's online backup facility and preserve the matching receipt image tree at a consistent point. For rollback, stop Grocy, restore the whole saved data path and prior image together, then check database integrity and authenticated receipt image reads before reopening writes. An older image may not understand new receipt state; image rollback alone is not a data rollback.

## Release order

1. Record the reviewed companion and Grocy commit SHAs, image digests, full test results, and independent whole-branch review findings. Complete stable-branch adaptation and SHA-pinned portable parity before building the Grocy image.
2. Verify the companion environment has `MCP_API_KEYS`, `GEMINI_API_KEY`, and the intended `RECEIPT_OCR_PROVIDER`/`RECEIPT_OCR_MODEL` values. Its Compose file supplies the inbound key and Gemini key; provider/model currently use the code defaults unless explicitly set. Keep values in deployment secrets, not Git. Confirm Grocy's `GROCY_AI_SERVICE_URL` and `GROCY_AI_SERVICE_API_KEY` reach the companion with the same inbound key.
3. Release the companion image first. Check its authenticated receipt endpoint with a bounded test image and a request ID, confirm a structured suggestion response, then exercise a provider failure and confirm the generic error. Do not use a household receipt in logs or command arguments.
4. Quiesce Grocy writes, back up and verify the persistent data path, then release the stable Grocy image. Confirm the deployed version marker is `ATECHPCS-grocy_AI-19`, the mount remains the same, and receipt schema bootstrap completes without changing existing stock.
5. Confirm authenticated upload, private image read, OCR suggestion import, manual fallback, edit audit, difference acceptance, finish/reopen, readiness blockers, and stale-checksum rejection. Never call a synthetic stock commit against production.

## User-observed phone acceptance

On the household phone, create a real purchase trip with two receipts from the intended stores. Include and match a scanned purchased item with confirmed quantity and price. Mark one purchased receipt-only item Ignore and confirm it stays visible in the audit without an allocation. On the second receipt, leave a visible total difference and explicitly accept it; confirm the audit shows amount, actor, and time. Confirm the trip remains blocked while either receipt is unfinished and becomes ready only after both are finished. Try OCR once and verify manual transcription remains usable if it fails. The household user decides whether and when to invoke **Commit purchase** for this real trip; compare stock and audit before and after only if that user performs the normal commit. Record redacted results and any blocker, then close the release gate.
