# Capture research release and acceptance

Record commands, timestamps, commit SHAs, and immutable image digests in the operator's private release log. Keep worker keys, receipt contents, barcode values, and image bytes out of the log.

1. Record tested companion and Grocy revisions. Run the companion full suite, Grocy module suite, six receipt scripts, focused research backfill test, and browser release suite against those revisions. Review the cross-repository diff for secret handling and product/stock write boundaries.
2. Make a full backup of the persistent `/etc/komodo/grocy` data path, including database, configuration, receipt images, and storage. Restore that backup into a separate rehearsal path and verify the database opens and receipt #3's image metadata and file exist there. Keep the production mount unchanged.
3. Configure the same `GROCY_AI_RESEARCH_WORKER_KEY` on the companion and Grocy outside Git and logs. Deploy the companion first, then Grocy. Confirm the Grocy image contains customization marker `ATECHPCS-grocy_AI-29` and record both immutable image digests.
4. Before backfill, record trip #12 status, saved scan count (expected 17), receipt #3 presence, product count, stock count, and stock log count. Stop if the trip is canceled or committed, or if the expected data is absent. Do not record receipt contents.
5. For enrollment of missing drafts only, run the CLI `--trip=12 --dry-run` from the README. Review the bounded candidate line IDs, blocker reasons, and checksum. Apply enrollment only after review of that exact checksum. Keep the preview and apply JSON in the private release log; the command omits barcode values. For paid requeue of existing provisional jobs, follow the separate release blocker below.
6. Confirm that only namespaced research jobs, drafts, and audit entries were added. Trip #12 still has 17 scans and receipt #3. Product, barcode, receipt, stock, and stock log counts and rows remain unchanged before any explicit approval. A repeat apply reports zero new drafts.
7. On a physical phone, review one provider hit and one provider miss. Confirm provenance and editable fields are legible, an approved barcode resolves to the selected product on reload, and the stock log remains unchanged until the user explicitly commits the purchase. Record the outcome without product or receipt details.

If any gate fails, leave the trip uncommitted. Revert the application images to the recorded tested digests while preserving the complete data path; investigate the namespaced research rows before any data restore. Never apply an old checksum after a trip edit.

## OpenAI fallback rollout

Deploy both compatible revisions with the companion `OPENAI_API_KEY` empty and
Grocy `GROCY_AI_CAPTURE_WEB_SEARCH_DAILY_LIMIT=0`. This preserves provider
research without paid searches. Deploy companion first in this disabled mode,
then Grocy marker 29 (v2 evidence and namespaced reservation migration v3).
Only after both are verified, configure the dedicated project key in companion
Komodo secret management and raise the Grocy UTC reservation ceiling to the
approved value (default 20). `data/config.php` constants take precedence over
environment; check the effective setting privately. Do not give Grocy the
OpenAI key. Companion defaults: model `gpt-4.1-mini`, 600 output tokens
(clamped 128–1000), 25-second web timeout (clamped 5–25), low search context.
Configure and verify external project spend controls; do not assume a budget
alert is a hard request cutoff. A failed or ambiguous call consumes its durable
reservation. One automatic generation and one explicit retry generation per
canonical GTIN bound repeat spending across trips.

Secret-free reachability check on the companion host:

```sh
curl --fail --silent http://127.0.0.1:3061/health
```

Expected body: `{"status":"ok","service":"grocy-mcp"}`. This does not prove
worker authentication, OpenAI key validity, model access or source accuracy.
Never dump container environment, rendered Compose configuration, prompts,
provider bodies, barcodes or receipt text into release logs.

For a consistent full-data backup, stop Grocy and the companion worker using
their managed stack controls, then on the data host run:

```sh
umask 077
release_backup="/etc/komodo/grocy-backup-$(date -u +%Y%m%dT%H%M%SZ).tar.gz"
tar -C /etc/komodo -czf "$release_backup" grocy
sha256sum "$release_backup"
restore_rehearsal=$(mktemp -d /tmp/grocy-restore.XXXXXX)
tar -C "$restore_rehearsal" -xzf "$release_backup"
sqlite3 "$restore_rehearsal/grocy/grocy.db" 'PRAGMA integrity_check;'
```

Check restored receipt #3 metadata and its opaque image file privately before
restarting managed stacks. Keep backup files private: they include secrets and
household data. Record the backup digest and tested immutable image digests.

The existing enrollment preview remains read-only:

```sh
GROCY_DATAPATH=/etc/komodo/grocy php8.5 custom/grocy_AI/bin/capture-research-backfill.php --trip=12 --dry-run
```

**Release blocker for paid trip #12 requeue:** this command previews eligible
unknown capture **line IDs** and enrollment checksum. It does not list existing
provisional job IDs, calculate remaining paid reservations, or retry settled
jobs. Its `--apply` enrolls drafts and is not a paid-requeue command. Before
paid backfill, a separately tested bounded procedure must preview unique job
IDs, active/selected/unapproved eligibility, retry generation, existing
reservations, UTC remaining capacity and maximum additional chargeable calls.
The paid-requeue dry-run must produce a checksum binding the exact bounded job
set and this relevant state. This checksum is distinct from the enrollment
checksum. Apply must require the reviewed paid-requeue checksum, revalidate the
bound job set and state before mutation, and reject any mismatch; obtain a new
dry-run and review after a mismatch. Apply only that reviewed set with an
auditable actor through the service retry boundary, never direct SQL queue
updates. Keep the worker paused until preview is approved. Do not use enrollment
apply for settled jobs.

At 375px on a physical phone verify source title/domain, safe external links,
“OpenAI web suggestion — verify UPC”, and “Verify against package”. Compare
one cited code with its package and source. No product, barcode ownership,
receipt allocation or stock persistence occurs until its respective explicit
user action. Keep trip #12 reviewing and uncommitted during requeue acceptance.

For rollback, empty the companion OpenAI key and set the Grocy ceiling zero,
then redeploy recorded prior immutable images through managed stack controls.
Preserve the complete data mount and append-only reservations. Do not reset
reservations to recover spent calls. If data restoration is required, stop both
writers, preserve the current full-data copy for investigation, and restore the
verified full backup as one unit; never restore just SQLite while retaining
mismatched receipt/storage files. Recheck integrity, trip/receipt presence and
native counts before reopening the household UI.

### Settled provider misses: bounded web-search requeue

The enrollment backfill (`capture-research-backfill.php`) only enrolls missing drafts. It does not requeue settled provisional jobs. Use the separate tool below for trip #12 after deploying the tested worker/fallback. Before apply, take and verify a **full Grocy data-directory backup**, including SQLite, uploaded files, and configuration. This is an operator runbook gate; the CLI does not create a backup.

```sh
GROCY_DATAPATH=/etc/komodo/grocy php8.5 custom/grocy_AI/bin/capture-web-search-requeue.php --trip=12 --dry-run
GROCY_DATAPATH=/etc/komodo/grocy php8.5 custom/grocy_AI/bin/capture-web-search-requeue.php --trip=12 --apply --checksum=<SHA256_FROM_REQUEUE_DRY_RUN>
# Explicit fixture/deployment database paths are also supported:
php8.5 custom/grocy_AI/bin/capture-web-search-requeue.php --trip=12 --dry-run --db=/absolute/path/grocy.db
php8.5 custom/grocy_AI/bin/capture-web-search-requeue.php --trip=12 --apply --checksum=<SHA256_FROM_REQUEUE_DRY_RUN> --db=/absolute/path/grocy.db
```

Review exact candidate job IDs, safe blocker codes, UTC day, daily reservation count, remaining slots, and `max_chargeable_calls` before apply. Trips over 100 lines are refused; apply requires 1–20 eligible jobs. The daily limit follows configuration constants in the database data directory's `config.php`, then `settingoverrides/AI_CAPTURE_WEB_SEARCH_DAILY_LIMIT.txt`, then `GROCY_AI_CAPTURE_WEB_SEARCH_DAILY_LIMIT`, with default 20. Invalid values fail closed; accepted values are integers from 0 through 100000. Zero disables new paid reservations. The shown maximum is based on current UTC slots; other workers can consume slots before queued work runs.

The **requeue checksum is distinct** from enrollment, capture booking, and receipt approval checksums. It covers the full trip/cancellation and relevant line/draft/job state (including revisions and user edits), related drafts/trip activity, existing reservations and their generations, today's reservation IDs/count, UTC day, candidate IDs, and configured limit. Changes require a new preview. Dry-run uses SQLite query-only mode and emits no barcodes or receipt text.

Eligible jobs are unreserved generation-zero settled `needs_input` jobs with a definitive stored v1 provider miss, linked to selected unapplied checksum-valid unresolved unknown lines in the active trip. Finalized drafts, provider errors, and jobs shared with active drafts in another trip are excluded. Apply uses `BEGIN IMMEDIATE`, resets attempts/retry timing/lease/safe error, increments job revision, and appends `grocy_ai:web_search_requeue` audits with actor and job ID. It preserves draft edits, receipt evidence, native rows, and stock, makes no OpenAI request, and does not advance the paid retry generation or reserve payment. Repeated apply is refused. The worker still controls paid reservations and explicit review controls product/link/stock writes.

Fixture verification: `php8.5 custom/grocy_AI/tests/capture_web_search_requeue.php`.
