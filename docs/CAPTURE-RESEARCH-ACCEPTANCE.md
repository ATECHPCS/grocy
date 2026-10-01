# Capture research release and acceptance

Record commands, timestamps, commit SHAs, and immutable image digests in the operator's private release log. Keep worker keys, receipt contents, barcode values, and image bytes out of the log.

1. Record tested companion and Grocy revisions. Run the companion full suite, Grocy module suite, six receipt scripts, focused research backfill test, and browser release suite against those revisions. Review the cross-repository diff for secret handling and product/stock write boundaries.
2. Make a full backup of the persistent `/etc/komodo/grocy` data path, including database, configuration, receipt images, and storage. Restore that backup into a separate rehearsal path and verify the database opens and receipt #3's image metadata and file exist there. Keep the production mount unchanged.
3. Configure the same `GROCY_AI_RESEARCH_WORKER_KEY` on the companion and Grocy outside Git and logs. Deploy the companion first, then Grocy. Confirm the Grocy image contains customization marker `ATECHPCS-grocy_AI-27` and record both immutable image digests.
4. Before backfill, record trip #12 status, saved scan count (expected 17), receipt #3 presence, product count, stock count, and stock log count. Stop if the trip is canceled or committed, or if the expected data is absent. Do not record receipt contents.
5. Run the CLI `--trip=12 --dry-run` from the README. Review the bounded candidate IDs, blocker reasons, and checksum. Apply only that exact checksum after review. Keep the preview and apply JSON in the private release log; the command omits barcode values.
6. Confirm that only namespaced research jobs, drafts, and audit entries were added. Trip #12 still has 17 scans and receipt #3. Product, barcode, receipt, stock, and stock log counts and rows remain unchanged before any explicit approval. A repeat apply reports zero new drafts.
7. On a physical phone, review one provider hit and one provider miss. Confirm provenance and editable fields are legible, an approved barcode resolves to the selected product on reload, and the stock log remains unchanged until the user explicitly commits the purchase. Record the outcome without product or receipt details.

If any gate fails, leave the trip uncommitted. Revert the application images to the recorded tested digests while preserving the complete data path; investigate the namespaced research rows before any data restore. Never apply an old checksum after a trip edit.
