# Domain Pitfalls

**Domain:** Local-first Grocy product enrichment, food taxonomy, reusable quantity conversions, and bulk inventory maintenance
**Project:** grocy_AI
**Researched:** 2026-08-12
**Overall confidence:** HIGH for codebase/schema and security findings; MEDIUM for mobile-device behavior until tested on the household's actual phones and browsers

## Recommended Phase Ownership

The roadmap should make the following ownership explicit. The names are recommendations; phase numbers can be assigned after the other research dimensions are synthesized.

| Recommended phase | Owns |
|---|---|
| **Safety Baseline & Mobile Diagnostics** | Dual-branch test/release gate, trace IDs, privacy-safe telemetry, mobile workflow benchmark, failure taxonomy |
| **Enrichment Contract & Secure Media** | Barcode handoff, structured suggestions, provider adapters, contract versioning, secure image fetch/preview |
| **Food Taxonomy & Categorization Pilot** | Local taxonomy, provider-to-local mappings, provenance, exclusions, confidence review, small pilot |
| **Reusable Conversion Model** | Dimensional rules, precedence, density/package semantics, conflict detection, invariant tests |
| **Bulk Maintenance & Recovery** | Immutable preview plans, concurrency checks, transactional apply, audit journal, rollback, reconciliation |
| **Upstream & Release Sustainment** | Rebase/merge playbook, custom schema migration discipline, branch parity, stable image promotion and rollback; establish the gate in the Safety Baseline and enforce it in every later phase |

## Critical Pitfalls

Mistakes in this section can corrupt household inventory, expose the LAN, or force a rewrite.

### Pitfall 1: Treating enrichment suggestions as authoritative product state

**What goes wrong:** A provider result overwrites a correct local name, category, unit, factor, or image; a stale UPC result is applied to the wrong form; or the barcode handoff creates data before the user presses Grocy's normal Save button. Package-size text is especially dangerous when it is interpreted as an inventory quantity or conversion factor.

**Why it happens:** Open Food Facts explicitly says its volunteer-contributed records have no assurance of accuracy, completeness, or reliability. The product form mixes harmless descriptive fields with fields that control inventory arithmetic. Grocy also enforces unique product names and unique barcodes, so a plausible provider result can conflict with an existing record even when the request itself succeeded.

**Consequences:** Wrong stock counts, duplicate-product attempts, failed saves, a UPC attached to the wrong product, or hidden writes that violate the project's human-review boundary. Changing `qu_id_stock` on an established product is not a cosmetic edit: current Grocy triggers can rescale stock, stock history, recipe amounts, chores, meal plans, shopping lists, prices, calories, and tare values.

**Prevention:**

- Keep provider output in a typed suggestion model separate from the editable form and persisted product model.
- Preserve the searched normalized UPC as authoritative for the request; never accept a different UPC from the provider payload.
- Classify fields by risk: descriptive fields may have an individual Apply action; taxonomy fields require an explicit mapping review; stock/purchase units and factors require a separate high-friction confirmation and must never be included in a generic “Apply all.”
- On barcode handoff, first query the existing barcode owner. Route to the existing product when found; otherwise carry a pending barcode intent into Grocy's established post-save barcode flow. Do not create the barcode during search or preview.
- Apply only fields the user selected, and only to the form. Persistence remains Grocy's normal Save action.
- Attach `provider`, `source field`, `fetched_at`, and mapping rationale to suggestions so conflicts can be explained.
- Add contract tests for conflicting product names, already-owned barcodes, missing fields, conflicting providers, and a provider UPC that does not equal the requested UPC.

**Detection / early warning signs:**

- Product or barcode rows change after search but before Save.
- A single Apply action changes quantity-unit controls.
- Save begins returning uniqueness errors after enrichment is enabled.
- A reload shows provider data the user did not explicitly select.
- Provider UPC and requested UPC differ, or the form's UPC changes after a late response.

**Roadmap owner:** **Enrichment Contract & Secure Media**, with regression coverage retained in **Bulk Maintenance & Recovery**.

**Confidence:** HIGH — verified against `.planning/PROJECT.md`, current Grocy schema/migrations, and official Open Food Facts API guidance.

### Pitfall 2: Modeling universal and food-type conversions as one undifferentiated factor graph

**What goes wrong:** A technically valid factor is applied where its meaning is invalid: volume to mass without a density, package to each without a package size, cooked to dry weight, or a food-type average to a product whose density differs materially. Multiple paths then resolve to different factors, and callers silently use whichever row happens to be returned.

**Why it happens:** “Conversion” hides distinct semantics. Metric conversions within one dimension are universal; mass/volume conversions are substance-dependent; count/package conversions are usually product-dependent. Grocy's existing resolved view already combines product purchase factors, product overrides, direct defaults, and indirect defaults. The browser code contains an explicit warning that contradictory definitions can yield multiple conversions. Adding another scope without a declared precedence rule magnifies that ambiguity.

**Consequences:** Incorrect purchases, consumption, recipe scaling, price-per-unit calculations, stock valuation, and cleanup decisions. The error can remain invisible because every individual number looks plausible.

**Prevention:**

- Define a dimensional model before adding data: unit dimension, canonical direction, exact factor or rational representation, scope, provenance, uncertainty, and effective dates/version.
- Permit universal rules only within the same physical dimension. Require explicit food-type density rules for mass/volume and product/package rules for count or package conversions.
- Use one documented precedence order, for example: product override → food-type rule → universal same-dimension rule. Reject rather than choose arbitrarily when two applicable rules at the same precedence disagree.
- Store one canonical logical edge and derive its inverse; never ask users or bulk code to maintain both directions independently.
- Validate positivity, non-zero factors, reciprocal consistency, dimensional compatibility, cycle consistency, and uniqueness of the effective path for every affected product.
- Display assumptions such as “1 cup = X g for uncooked rolled oats” rather than presenting a context-dependent value as universal truth.
- Keep a small explicit exception mechanism. Forcing all package/count cases into a generic food-type rule merely recreates product-specific sprawl in a less visible form.

**Detection / early warning signs:**

- The same from/to unit pair appears with different effective factors for one product.
- A resolved query returns more than one candidate and UI code de-duplicates by first match.
- A rule crosses dimensions but has no density/substance/context metadata.
- Reciprocal products differ materially from 1, or two paths around a cycle disagree beyond the declared tolerance.
- Stock, recipe, or price values change after “cleanup” even though no base inventory unit was intentionally changed.

**Roadmap owner:** **Reusable Conversion Model**. Do not begin bulk cleanup until these invariants pass against a production-shaped copy.

**Confidence:** HIGH — verified against `migrations/0188.sql`, `migrations/0189.sql`, `migrations/0254.sql`, and `public/viewjs/components/productamountpicker.js`.

### Pitfall 3: Deleting or rewriting physical conversion rows without respecting Grocy's triggers

**What goes wrong:** A cleanup script counts a logical conversion as one row even though Grocy creates and deletes reciprocal rows through triggers. It deletes the wrong half, recreates pairs twice, collides with the custom uniqueness trigger, or mistakes a product's purchase-to-stock factor for a removable conversion.

**Why it happens:** The database's physical representation is not the user-visible domain model. `quantity_unit_conversions` has inverse insert/update/delete triggers, while `quantity_unit_conversions_resolved` synthesizes additional rows from product settings and default conversions.

**Consequences:** Partial cleanup, constraint failures midway through a batch, duplicate or missing effective rules, and misleading before/after counts. A rollback based only on row IDs can recreate a different logical graph.

**Prevention:**

- Inventory current rules as canonical unordered unit pairs plus scope, not raw row count.
- Classify each resolved rule by its `source`; do not delete a synthesized purchase-to-stock rule from the conversion table.
- Drive writes through one tested domain service that understands reciprocal triggers. Never issue ad hoc SQL from the browser.
- Test insert, update, delete, and rollback with triggers enabled on the same Grocy release used in production.
- In preview and audit output, show both logical-rule count and affected physical-row count.
- Compare resolved effective factors before and after cleanup for every affected product, not merely table row totals.

**Detection / early warning signs:**

- The reported “approximately 101” unwanted rules turn into roughly twice as many database rows.
- `QU conversion already exists` errors occur during a supposedly idempotent apply.
- The cleanup preview and apply report different counts without intervening user edits.
- A rule disappears from the base table but remains in the resolved view, or vice versa.

**Roadmap owner:** **Reusable Conversion Model** for the canonical representation and **Bulk Maintenance & Recovery** for execution/reconciliation.

**Confidence:** HIGH — directly verified in current migration SQL.

### Pitfall 4: Bulk preview and apply are not the same immutable operation

**What goes wrong:** The user reviews one set of proposed changes, but apply recomputes against newer provider data or newer database rows. A partial failure leaves half the products categorized. A retry applies some changes twice. A rollback overwrites legitimate manual edits made after the batch.

**Why it happens:** Preview and execution are often implemented as two calls that rerun the algorithm. SQLite provides atomic transactions, but atomicity alone does not detect stale previews, make retries idempotent, or create a safe logical rollback after later edits.

**Consequences:** Silent data loss, mixed taxonomy versions, partial cleanup, and no trustworthy answer to “what changed and why?”

**Prevention:**

- Materialize preview as an immutable plan containing plan ID, algorithm/taxonomy/rule versions, normalized input set, per-row before values, proposed after values, confidence, reasons, conflicts, and a checksum.
- At apply time, verify each row still matches its previewed before-state (row version or canonical value hash). Stop and report conflicts; do not silently re-preview.
- For household-scale bounded batches, use one explicit SQLite write transaction and roll back on every exception. Fail fast if a write lock cannot be obtained rather than holding the UI indefinitely.
- Use an idempotency key/plan ID and record a durable batch state (`previewed`, `applying`, `applied`, `failed`, `rolled_back`). A repeated apply of an already-applied plan must return its prior result.
- Store an append-only per-field audit journal with before/after values and the actor. Rollback is a new guarded batch: restore only when the current value still equals the original batch's after value; otherwise report a conflict.
- Strongly offer a verified SQLite snapshot before mutation. If the user declines, state clearly that the journal can reverse logical changes but cannot recover database-file corruption. Use SQLite's Online Backup API or `VACUUM INTO`, not an uncoordinated copy of a live file.
- After apply and rollback, run domain reconciliation plus `PRAGMA integrity_check` and `PRAGMA foreign_key_check`; the former does not check foreign-key violations.

**Detection / early warning signs:**

- Preview has no stable ID/checksum or does not store exact before values.
- Apply calls external providers again.
- Refreshing or double-tapping Apply changes the result.
- A process crash leaves no durable batch status or per-record result.
- Rollback is implemented as “run the classifier again” or blindly restore every old value.
- Counts balance, but resolved conversions or product-category distributions do not reconcile.

**Roadmap owner:** **Bulk Maintenance & Recovery**. The phase is not complete until interrupted apply, idempotent retry, stale-plan conflict, and guarded rollback are tested.

**Confidence:** HIGH — SQLite transaction, backup, and integrity behavior is documented officially; application-specific controls follow directly from the project constraints.

### Pitfall 5: The image proxy becomes an SSRF and content-processing boundary in name only

**What goes wrong:** A provider-controlled image URL makes the companion service request loopback, RFC1918, link-local, multicast, IPv6-local, or metadata addresses; DNS changes between validation and connection; a redirect escapes an allowlist; or an oversized/decompression-bomb image exhausts PHP, companion, or mobile memory. An opaque token hides the URL from the browser but does not make the server-side fetch safe.

**Why it happens:** URL parsing, DNS resolution, redirects, and content validation are separate stages. The current Grocy layer validates final MIME, magic bytes, and a 3 MB string only after buffering the response. Candidate thumbnails are also currently loaded directly from external hosts, which discloses household request timing/IP and bypasses the trusted-origin boundary.

**Consequences:** LAN service access, credential exposure, denial of service, privacy leakage to image hosts, mixed-content failure, or persistent unsafe files.

**Prevention:**

- Resolve and fetch external URLs only in the companion service; Grocy accepts opaque, short-lived server-issued handles and never an arbitrary fetch URL.
- Prefer an allowlist of known provider/image origins. Where arbitrary search-result hosts are required, permit only HTTPS and ports 443/80, block userinfo and non-HTTP schemes, resolve all A/AAAA records, reject every non-public address, pin the validated address for the connection, and repeat validation after any DNS/redirect transition. Disabling redirects is safest.
- Apply the same controls when issuing a handle and again when redeeming it. Bind handles to an authenticated user/session and candidate, give them a short TTL, and reject replay after expiry.
- Stream into a bounded temporary sink; reject excessive `Content-Length` early and abort once byte/time limits are crossed. Cap decoded width, height, total pixels, frames, and decompressed size.
- Decode and re-encode accepted JPEG/PNG/WebP through a trusted image library, stripping metadata; magic bytes alone do not prove the payload is safe or cheap to decode.
- Serve thumbnails and selected images from the authenticated same-origin proxy. Do not eagerly contact six third-party hosts from the phone.
- Keep redirect behavior, private-address rejection, oversized declared/undeclared bodies, slow streams, malformed images, and DNS-rebinding simulations in deterministic tests.

**Detection / early warning signs:**

- Logs show requests to `127.0.0.1`, `::1`, `10/8`, `172.16/12`, `192.168/16`, link-local, or unexpected ports.
- Provider URLs are stored in or returned by the opaque token.
- Memory rises with rejected images, indicating limits occur after buffering/decoding.
- The phone contacts third-party image domains directly or displays mixed-content warnings.
- A redirect is followed even though the original URL passed validation.

**Roadmap owner:** **Enrichment Contract & Secure Media**; security regression tests remain a release gate in **Upstream & Release Sustainment**.

**Confidence:** HIGH — OWASP explicitly recommends allowlisting, validating resolved addresses, protecting against DNS pinning, and disabling redirects; current buffering and direct-preview behavior are verified in the codebase audit.

### Pitfall 6: Custom schema work collides with upstream migrations or core table rebuilds

**What goes wrong:** grocy_AI adds a numbered file to Grocy's global `migrations/` ledger, and a later upstream release uses the same integer. Alternatively, custom columns added to a core table disappear when an upstream SQLite migration rebuilds that table from a fixed column list.

**Why it happens:** `DatabaseMigrationService` keys migrations only by integer in one global `migrations` table. Grocy frequently evolves tables by rename/create/copy/drop. The fork simultaneously tracks development and stable branches whose framework and migration surfaces already differ substantially.

**Consequences:** A required migration is skipped, an upstream migration is falsely marked applied, custom data disappears on upgrade, or the stable database can no longer move cleanly between releases.

**Prevention:**

- Put new grocy_AI state in module-owned tables with a `grocy_ai_` prefix and foreign-key/reference checks, rather than adding provider/taxonomy/audit columns to `products` or other core tables.
- Maintain a separate module schema-version ledger and idempotent transactional migration runner. Do not consume upstream numeric migration IDs.
- Before every upstream merge, diff new migration files and every rebuilt core table against the production base.
- Test upgrade on a scrubbed production-shaped database, then run core and module schema checks, row-count reconciliation, and rollback/redeploy rehearsal.
- Keep deploy artifacts compatible with the external `/config` data volume; never use the bundled `update.sh`, which the codebase audit confirms removes fork customizations.

**Detection / early warning signs:**

- A custom migration is named like `0255.sql` in the upstream migration directory.
- `ApplicationService` reports a high database version even though module tables/columns are missing.
- Upstream adds a migration with an ID already present locally.
- Product metadata disappears only after an upstream upgrade or branch switch.
- A deployment works with a fresh database but fails against the long-lived household database.

**Roadmap owner:** Establish in **Safety Baseline & Mobile Diagnostics** before adding persistent taxonomy/audit data; enforce in **Upstream & Release Sustainment**.

**Confidence:** HIGH — verified directly against `services/DatabaseMigrationService.php`, current migrations, and the codebase concerns audit.

### Pitfall 7: Development passes while the production branch or image is stale

**What goes wrong:** Portable core files are updated on `atech-main`, but stable route/controller adapters, Docker overlay, customization marker, or cached routes are not updated on `atech-release`. The wrong branch is built, or a cache survives the new code.

**Why it happens:** The branches differ across framework surfaces by design, current documentation disagrees about which is deployable, and the production cache marker exists only on the stable branch. The existing test harness does not boot Slim, build the container, or verify route/permission behavior.

**Consequences:** Local tests are green while production has missing routes/assets, stale behavior, incorrect authorization, or a partial feature port.

**Prevention:**

- Define a portable-core manifest and an explicit adapter manifest. Assert portable files are byte-identical across branches while adapter differences match reviewed expectations.
- For every change, run a two-branch matrix: PHP/JS syntax, service harness, route boot, authentication and `MASTER_DATA_EDIT`, view render, stable container build, custom marker/cache invalidation, and smoke requests for status/enrichment/media.
- Record upstream base SHA, fork SHA, image base digest, module schema version, and customization marker in each release artifact.
- Make `atech-release` the only production source of truth and guard/disable the upstream destructive updater in fork deployments.
- Promote by tested image digest, not a mutable tag alone; keep the prior image and a validated database rollback procedure.

**Detection / early warning signs:**

- Portable file hashes differ between branches without a documented reason.
- `/api/grocy-ai/status` reports the old phase/contract after deployment.
- Routes work on `atech-main` but 404 or have different middleware on stable.
- The built image lacks `custom/grocy_AI/version.json` or serves old assets after restart.
- Operators cannot state the exact upstream base, fork commit, and image digest in production.

**Roadmap owner:** **Safety Baseline & Mobile Diagnostics** creates the gate; **Upstream & Release Sustainment** owns the recurring merge/promotion process.

**Confidence:** HIGH — current dual-branch drift, adapter differences, cache marker, and test gaps are documented in `.planning/codebase/CONCERNS.md`.

## Moderate Pitfalls

### Pitfall 1: Provider failure is amplified into a Grocy or LAN outage

**What goes wrong:** Slow or rate-limited Open Food Facts, SearXNG, image hosts, or companion calls occupy PHP workers. Automatic retries multiply load, the mobile UI appears hung, and users blame Grocy because all failures collapse into one generic message.

**Prevention:**

- Set separate connect, first-byte, and total deadlines per hop; the browser also needs cancellation and a visible deadline.
- Retry only idempotent reads, only for classified transient failures, at one layer, with a small capped budget, exponential backoff plus jitter, and respect for `Retry-After`. Never retry validation errors or a user-approved write blindly.
- Add a short circuit breaker and small bounded cache keyed by normalized UPC and provider contract version; label cached data with age.
- Enforce provider-specific concurrency/rate budgets. Open Food Facts currently documents 15 product reads/min/IP and 10 searches/min/IP and warns that excess traffic can receive 503 or an IP ban.
- Return partial results with per-provider status when safe; enrichment failure must never block manual product save or ordinary inventory operations.
- Record stage timings and outcome categories so Grocy, companion, provider, image host, and phone/LAN failures are distinguishable.

**Detection / early warning signs:** p95/p99 enrichment latency grows while core pages remain fast; PHP workers remain busy after the user leaves; 429/503 rates climb; multiple identical calls occur for one tap; retry volume exceeds original request volume.

**Roadmap owner:** Telemetry and baselines in **Safety Baseline & Mobile Diagnostics**; resilience implementation in **Enrichment Contract & Secure Media**.

**Confidence:** HIGH for provider limits and retry behavior; MEDIUM for final timeout values until LAN measurements exist.

### Pitfall 2: Mobile async behavior applies stale results or loses the user's work

**What goes wrong:** A second UPC search finishes before the first, then the late first response replaces the current suggestions. Double taps submit twice. A network transition or image download failure clears manual edits. Browser-specific file-input behavior works on desktop but fails on the actual phone.

**Prevention:**

- Give every search a monotonically increasing request token; cancel the prior fetch and ignore responses that do not match the current form/UPC token.
- Disable only the action in progress, show an immediate busy state, provide a cancel/retry path, and preserve all manual form values on every failure.
- Announce success/error/progress through visible text and an appropriate ARIA live region; do not rely on a spinner or toast alone.
- Keep tap targets and candidate controls usable at phone widths; load bounded same-origin thumbnails on demand and avoid retaining multiple full-size blobs.
- Test on the household's real iOS/Android browser matrix for create/edit, barcode scan handoff, orientation change, background/foreground, slow Wi-Fi, disconnect, timeout, duplicate tap, save, reload, and image attachment.
- Make “continue without enrichment” obvious. Core Grocy must remain usable when every provider is down.

**Detection / early warning signs:** selected suggestions change after the UPC field changed; more than one request is active per form; the Save button remains disabled after failure; status is visible but not announced; desktop passes while actual phones cannot attach the fetched file.

**Roadmap owner:** **Safety Baseline & Mobile Diagnostics**, with regression scenarios carried into **Enrichment Contract & Secure Media**.

**Confidence:** MEDIUM until real-device verification; the absence of browser/E2E coverage and the current DOM/DataTransfer coupling are HIGH-confidence codebase facts.

### Pitfall 3: External taxonomy is copied directly into a single local category field

**What goes wrong:** Multilingual provider strings, synonyms, and a multi-parent external hierarchy create duplicate local categories or unstable mappings. Products jump category when the provider taxonomy changes. Baby-food/pet-food tags leak into an explicitly excluded household taxonomy.

**Prevention:**

- Define a small, versioned local household taxonomy with stable opaque IDs, labels/aliases, explicit parentage, and explicit excluded roots. Treat it as the authority for local categorization.
- Store mappings from provider canonical IDs to local IDs with provider, taxonomy version, mapping version, and reviewer; do not use translated display strings as identity.
- Recognize that Open Food Facts taxonomies are multilingual DAGs with possible multiple parents, while a Grocy product currently has one `product_group_id`. Make the lossy choice explicit: select one reviewed primary household food type and preserve external tags/provenance separately.
- Start with a representative pilot and an `uncategorized`/`needs review` state. Do not force low-confidence items into the nearest category.
- Make taxonomy merges/splits explicit migrations with previews; never silently rename an ID by editing its label.
- Test exclusion rules with adversarial labels and ancestors, not only exact string matches.

**Detection / early warning signs:** categories are keyed by English display name; the same food appears under singular/plural or translated names; provider recrawl changes local classification without a user action; one provider tag maps to different local types without a versioned conflict.

**Roadmap owner:** **Food Taxonomy & Categorization Pilot**, before any bulk recategorization.

**Confidence:** HIGH for external taxonomy structure and current Grocy cardinality; MEDIUM for the final household taxonomy until user review.

### Pitfall 4: Provider contract and schema drift leak through the companion boundary

**What goes wrong:** Grocy browser/PHP code begins depending on raw Open Food Facts or SearXNG fields. A provider adds, removes, renames, or changes nullability and the UI silently loses fields or misinterprets data. Different providers emit incomparable confidence values.

**Prevention:**

- Keep the existing companion boundary: each provider gets an adapter, and Grocy consumes a small versioned grocy_AI contract only.
- Pin the requested provider API version and request only needed fields. Parse additively, reject wrong types, and preserve unknown fields only outside the Grocy contract.
- Return normalized values plus provenance, original evidence, completeness, and confidence semantics; never merge provider confidence numbers without calibration.
- Add fixture/contract tests for every provider, including missing/null/renamed fields, new unknown fields, 404, 429, 503, malformed data, and conflicting values.
- Track provider/API schema version in telemetry and cached records so regressions can be correlated with a rollout.
- Schedule fixture refresh/review against official changelogs. Open Food Facts v3 is actively changing; its May 2026 v3.6 tag schema removed previous hierarchy/language fields from current reads.

**Detection / early warning signs:** provider field names appear in Blade/browser code; an upstream schema version changes without a fixture update; suggestion completeness drops abruptly; all provider confidence is displayed on one 0–100 scale with no definition.

**Roadmap owner:** **Enrichment Contract & Secure Media**; monitored by **Upstream & Release Sustainment**.

**Confidence:** HIGH — official Open Food Facts documentation explicitly describes active schema evolution and versioned breaking changes.

### Pitfall 5: Telemetry solves latency diagnosis by creating a privacy, secret, or disk problem

**What goes wrong:** Logs contain API keys, session cookies, full provider/image URLs, query strings, product names, barcodes, household IP/device identifiers, or response bodies. High-cardinality URLs/UPCs make metrics unusable, and unbounded local logs fill the same persistent volume as the SQLite database.

**Prevention:**

- Define an allowlist schema, not a blacklist: trace ID, component, operation, provider enum, outcome class, HTTP status class, bounded duration/size buckets, cache state, app version, and sanitized error code.
- Do not log credentials, cookies, headers, bodies, full external URLs, product names, or raw user-entered values. If per-UPC correlation is essential, use a rotating keyed pseudonym rather than the raw UPC and keep it out of metrics labels.
- Use low-cardinality enums for metrics. Keep diagnostic detail in access-controlled structured logs with retention, rotation, size caps, and free-space alerts separate from product image/database storage where practical.
- Sanitize CR/LF and delimiters from all externally sourced log fields to prevent log injection.
- Propagate one correlation ID through browser → Grocy → companion → provider, but do not use a session ID as the correlation ID.
- Measure durations with a monotonic clock at each hop; use trace IDs rather than assuming wall clocks on LAN hosts are perfectly synchronized.
- Keep collection local by default and document any future export/retention choice before enabling it.

**Detection / early warning signs:** searching logs reveals `X-API-Key`, cookies, tokens, full image URLs, or recognizable product names; time-series cardinality grows with product count; logs consume the `/config` volume; trace IDs are missing between Grocy and companion.

**Roadmap owner:** **Safety Baseline & Mobile Diagnostics**, before broad instrumentation is added.

**Confidence:** HIGH — OWASP logging guidance explicitly calls for interaction IDs, sanitization, access protection, retention limits, and removal/masking of tokens, session IDs, PII, and sensitive data.

### Pitfall 6: A taxonomy or conversion preview races with normal household edits

**What goes wrong:** A product is edited, renamed, moved, or assigned a unit after preview but before apply. The bulk job overwrites the newer value because both actions are individually valid.

**Prevention:** Include canonical before-state hashes in plans, use field-level optimistic concurrency, report rather than overwrite conflicts, and keep batch locks short. A batch rollback must use the same after-state guard. UI should show “skipped because changed since preview” as a first-class result.

**Detection / early warning signs:** last-write-wins behavior, apply success counts lower/higher than preview with no conflict report, or a manual edit disappears immediately after a batch.

**Roadmap owner:** **Bulk Maintenance & Recovery**.

**Confidence:** HIGH.

## Minor Pitfalls

### Pitfall 1: Losing image/data provenance and reuse rights

**What goes wrong:** A locally stored image cannot be traced to its source, or a SearXNG-discovered image with unknown rights is later uploaded to Open Food Facts, included in documentation, or redistributed with the fork.

**Prevention:** Store provider/source URL, fetch time, and known license metadata alongside the local selection; keep unknown-license search results local; do not upload scraped images to Open Food Facts. Open Food Facts documents its database/content/image licenses and says uploaded images must be owned by the uploader or compatibly licensed.

**Roadmap owner:** **Enrichment Contract & Secure Media**.

**Confidence:** HIGH for Open Food Facts rules; rights for arbitrary search-result hosts are necessarily source-specific.

### Pitfall 2: Negative caching makes corrected provider data appear permanently missing

**What goes wrong:** A UPC not found or a temporary provider failure is cached too long, so newly added/corrected data does not appear.

**Prevention:** Use shorter TTLs for not-found/transient failures than for successful normalized records, never cache authorization/configuration errors, label cache age, and provide a bounded user-triggered refresh that still honors provider rate limits.

**Roadmap owner:** **Enrichment Contract & Secure Media**.

**Confidence:** MEDIUM — exact TTLs require observed provider behavior.

### Pitfall 3: “Confidence” becomes false precision

**What goes wrong:** A score such as 87 is shown without telling the user whether it represents provider certainty, mapping quality, source agreement, or an internal heuristic.

**Prevention:** Use named evidence bands with reasons and conflicts; preserve provider-specific raw signals separately; calibrate only against reviewed household examples. Low confidence must route to review, never automatic write.

**Roadmap owner:** **Food Taxonomy & Categorization Pilot**.

**Confidence:** HIGH as a design risk; calibration thresholds remain an empirical question.

## Phase-Specific Warnings

| Phase topic | Likely pitfall | Required exit evidence |
|---|---|---|
| Safety baseline and mobile diagnostics | Instrumentation leaks secrets or mobile tests remain desktop-only | Redaction tests; bounded log retention; trace across all hops; actual-device workflow results under normal/slow/disconnected LAN conditions |
| Enrichment contract and barcode handoff | Hidden writes, stale async response, duplicate barcode ownership, provider payload leaks | No database delta before Save; existing-barcode routing test; stale-response cancellation test; versioned provider fixtures |
| Secure image preview/selection | SSRF, DNS rebinding, redirect escape, post-buffer size limits, third-party preview leakage | Private-address/redirect/slow-stream/oversize/decode tests; same-origin thumbnails; bounded streaming and pixel limits |
| Food taxonomy | External strings become identities; multi-parent provider DAG is flattened accidentally; exclusions leak | Versioned local IDs; reviewed mapping table; explicit primary-type rule; exclusion tests; representative pilot confusion report |
| Reusable conversions | Cross-dimension ambiguity, conflicting paths, reciprocal trigger mistakes | Dimensional model; precedence contract; cycle/reciprocal tests; exactly one effective factor per product/unit pair; unchanged stock-domain reconciliation |
| Bulk categorization and cleanup | Preview/apply drift, partial commit, unsafe retry, rollback clobbers later edits | Immutable signed/checksummed plan; stale-row conflicts; interrupted apply test; idempotent retry; guarded rollback; audit export; integrity and domain checks |
| Upstream synchronization | Custom migration collision, stable adapter omission, stale route/view cache, destructive updater | Separate module ledger; production-shaped upgrade test; dual-branch parity manifest; stable container smoke; marker/cache verification; prior-image rollback rehearsal |

## Roadmap Ordering Implications

1. **Build the safety baseline first.** Without privacy-safe correlation telemetry, real-device checks, and a dual-branch release gate, later failures cannot be localized and production can silently lag development.
2. **Stabilize the enrichment/security contract before adding more suggested fields.** This establishes the human-review and untrusted-provider boundary once.
3. **Define and pilot the taxonomy before categorizing inventory.** Bulk work must consume stable local IDs and reviewed mappings, not invent them during apply.
4. **Define and validate conversion semantics before cleanup.** The bulk phase cannot safely replace product-specific rows until precedence, dimensionality, and trigger behavior are proven.
5. **Build bulk execution after both models are stable.** Preview, apply, audit, and rollback should be one reusable engine used by categorization and conversion cleanup.
6. **Treat upstream sustainment as a continuous gate.** The dedicated phase documents and rehearses the process, but every earlier phase must pass both branch adapters and the stable image smoke test.

## What Might Still Be Missed

- Actual mobile browser/device versions and the household's acceptable latency thresholds are not recorded; these require real-device measurement.
- The final local food taxonomy and density/conversion reference dataset need user/domain review; external provider categories are evidence, not the household authority.
- The live production database was not inspected during this research. Counts, malformed legacy data, factor distributions, and product/category edge cases must be profiled on a scrubbed snapshot before migration or bulk plans are finalized.
- SearXNG result-host policy and companion implementation were not part of this repository review. The secure-media phase must inspect that service's resolver, redirect, streaming, and token behavior directly.
- Recovery from logical batch errors can be implemented in-app; recovery from filesystem/database corruption still depends on a separately tested backup/restore procedure.

## Sources

### Project and codebase evidence

- `.planning/PROJECT.md` — active requirements, constraints, deployed Phase 1 behavior, production topology
- `.planning/codebase/CONCERNS.md` — dual-branch drift, SSRF/content concerns, synchronous latency, mobile/browser gaps, cache marker, updater and rollback risks
- `.planning/codebase/TESTING.md` — current 21-check service harness and missing HTTP/browser/container coverage
- `services/DatabaseMigrationService.php` — global integer migration ledger and transactional SQL migration behavior
- `migrations/0128.sql` — globally unique barcode index
- `migrations/0188.sql`, `migrations/0189.sql`, `migrations/0254.sql` — reciprocal conversion triggers, resolved-source rules, stock-unit cascade behavior
- `public/viewjs/components/productamountpicker.js` — current handling note for contradictory conversion definitions

### Authoritative external guidance

- [Open Food Facts API introduction](https://openfoodfacts.github.io/documentation/docs/Product-Opener/api/) — community-data reliability warning, licensing, current API status, rate limits, required User-Agent
- [Open Food Facts API and product schema change log](https://openfoodfacts.github.io/documentation/docs/Product-Opener/api/ref-api-and-product-schema-change-log/) — active schema evolution and breaking version changes
- [Open Food Facts taxonomy suggestions](https://openfoodfacts.github.io/documentation/docs/Product-Opener/v3/taxonomy/get-api-v3-taxonomy_suggestions-taxonomy/) — canonical multilingual taxonomy behavior and synonyms
- [Open Food Facts taxonomy model](https://openfoodfacts.github.io/search-a-licious/users/explain-taxonomies/) — stable canonical IDs, translations/synonyms, and multi-parent DAG structure
- [Open Food Facts image upload guidance](https://openfoodfacts.github.io/openfoodfacts-server/api/tutorial-uploading-photo-to-a-product/) — image ownership and compatible-license requirements
- [OWASP SSRF Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html) — allowlists, resolved-address checks, DNS pinning protection, redirect controls
- [OWASP Logging Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html) — correlation identifiers, sensitive-data exclusion, sanitization, protection, monitoring, and retention
- [SQLite transactions](https://www.sqlite.org/lang_transaction.html) — atomic transaction behavior, single-writer constraint, `SQLITE_BUSY`, and rollback considerations
- [SQLite Backup API](https://www.sqlite.org/backup.html) — consistent live snapshots and why raw live-file copying is weaker
- [SQLite PRAGMA reference](https://www.sqlite.org/pragma.html) — `integrity_check`, separate `foreign_key_check`, and their limitations
- [AWS Builders' Library: Timeouts, retries, and backoff with jitter](https://aws.amazon.com/builders-library/timeouts-retries-and-backoff-with-jitter/) — bounded timeouts, retry amplification, idempotency, backoff, and jitter
- [W3C form user notifications](https://www.w3.org/WAI/tutorials/forms/notifications/) and [WCAG status messages](https://www.w3.org/WAI/WCAG22/Understanding/status-messages.html) — clear dynamic success/error/progress feedback accessible to assistive technology

---

*Pitfalls research: 2026-08-12*
