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
Apply only the reviewed set with an auditable actor and revalidate eligibility;
use the service retry boundary, never direct SQL queue updates. Keep the worker
paused until preview is approved. Do not use enrollment apply for settled jobs.

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
