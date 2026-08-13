# Phase 02: Enrichment Contract, Barcode Handoff & Secure Media - Research

**Researched:** 2026-08-13  
**Domain:** Versioned enrichment contracts, canonical GTIN ownership, review-before-save UI, and SSRF-resistant media proxying  
**Confidence:** HIGH. The five planning questions were resolved on 2026-08-13 from repository contracts plus read-only inspection of the running Grocy and companion containers; execution still rechecks those facts and fails closed on drift.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

## Implementation Decisions

### Suggestion Review and Selection
- **D-01:** Present each supported field as a phone-friendly side-by-side comparison of its current value and suggested value. Show the suggestion's source, confidence band, reason, and freshness directly with that field rather than in a detached summary.
- **D-02:** High-confidence suggestions may be preselected so product creation stays fast, but every preselection remains visible and reversible before the final diff and normal Save.
- **D-03:** A suggestion qualifies for high-confidence preselection only when a validated structured provider supplies that field directly for the canonical barcode. Search-derived, inferred, transformed, or otherwise indirect suggestions remain unselected even if they carry a high numeric score.
- **D-04:** Preselection applies only when the current product field is blank. A suggestion that would replace any existing non-empty value always requires an explicit user selection.
- **D-05:** The final diff must distinguish automatic preselection from explicit user selection without weakening the rule that normal Grocy Save is the only persistence action.

### Carried-Forward Safety Contract
- **D-06:** Preserve Phase 1's bounded request lifecycle, stale/duplicate suppression, named recovery states, usable normal Save controls, privacy-safe diagnostics, and zero enrichment writes.
- **D-07:** Keep feature code within `custom/grocy_AI/` and `public/custom/grocy_AI/`; minimize and document core hooks and maintain portable parity with the stable branch.
- **D-08:** Prefer real structured-source front-package imagery. Search-discovered imagery is an explicitly unverified fallback, never equivalent evidence.

### the agent's Discretion
- Choose the exact compact control (checkbox, toggle, or select button) used to represent a selected suggestion, provided selection state is accessible, touch-friendly, and clear in the final diff.
- Choose labels and visual treatment for confidence bands, source, reason, freshness, and automatic-preselection badges within the existing Bootstrap 4/Roboto/Font Awesome design system.
- Resolve barcode-owner routing, canonical-equivalent presentation, stale-current-value handling, and secure-media implementation details according to ENR-01 through ENR-09 and established Grocy patterns; the user did not request further discussion of those areas.

### Deferred Ideas (OUT OF SCOPE)
- **Nutrition Facts enrichment:** On a valid barcode during product creation, stage factual nutrition fields for review and allow them to persist only through normal Save. This is a new suggestion family outside ENR-01 through ENR-09. It must not be interpreted as nutrition, allergen, dietary, or medical recommendations, and requires its own requirements, source/schema validation, unit/serving normalization, provenance, and UI contract before implementation.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| ENR-01 | Every enrichment response follows a strictly validated, versioned response contract, and every external suggestion carries its source, confidence band, reason, and freshness. | Use the closed `contract_version: 2` envelope and suggestion discriminated union described below; reject the whole response on unknown versions, fields, enum values, or incomplete provenance. `[VERIFIED: codebase inspection — custom/grocy_AI/src/GrocyAiService.php currently performs permissive normalization]` |
| ENR-02 | The add-product flow preserves and displays the originally scanned barcode while Grocy checks canonical UPC/EAN/GTIN equivalents for duplicate ownership. | Keep `scanned_gtin` immutable for display and compare validated values by their zero-padded 14-digit canonical form. `[CITED: https://ref.gs1.org/guidelines/2d-in-retail/]` |
| ENR-03 | When the scanned barcode or a canonical equivalent is already assigned, Grocy routes the user to the owning product instead of creating a duplicate. | Resolve ownership server-side before external enrichment and return a trusted product ID, never a client-provided route. Enforce the same canonical rule in SQLite. `[VERIFIED: codebase inspection — product_barcodes currently has only an exact-text unique index]` |
| ENR-04 | When the barcode is unused, Grocy stages it in the normal product/barcode workflow and writes it exactly once only after the user presses Save. | Add staged barcode state to the existing product form Save continuation; use the canonical DB uniqueness guard for idempotent conflict handling. No enrichment endpoint writes. `[VERIFIED: codebase inspection — public/viewjs/productform.js and public/viewjs/productbarcodes.js]` |
| ENR-05 | Name, brand, package size, product group, quantity unit, food type, and product image suggestions can be reviewed independently alongside current values. | Render one independently selectable comparison row per closed field ID. Local-ID fields require an active local mapping before selection; direct versus transformed evidence remains explicit. |
| ENR-06 | Before saving, the user sees a final diff of selected enrichment changes; selected changes persist through normal Grocy Save, while unselected suggestions leave current values unchanged. | Keep selection in module state, compare current-value snapshots for staleness, then stage only confirmed rows into native controls/userfields/file input. The existing Save remains the sole persistence gesture. `[VERIFIED: codebase inspection — existing product form and UserfieldsForm Save paths]` |
| ENR-07 | Image suggestions prioritize exact structured-product matches, preferably a front-package image, and present SearXNG results only as clearly labeled unverified alternatives. | Rank validated `structured_direct/front` candidates first; force every SearXNG candidate to `confidence_band: unverified`, `evidence_kind: search`, and never auto-select it. `[VERIFIED: codebase inspection — companion enrichment.py exposes structured and SearXNG candidates]` |
| ENR-08 | Image thumbnails and full images are demand-loaded and selected through authenticated same-origin Grocy proxy routes using short-lived opaque handles, with URL, redirect, byte, time, MIME, signature, and pixel/dimension safeguards. | Never return external image URLs to the browser. Bind an opaque TTL handle to candidate/variant; stream through the companion and validate again in Grocy before returning `private, no-store` bytes. `[CITED: https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html]` |
| ENR-09 | Search, preview, cancellation, timeout, and failed retrieval create no product, barcode, category, stock, conversion, or file persistence before Save. | Add write counters/route traps to browser fixtures and assert all non-Save paths produce zero mutation calls; retain Phase 1 lifecycle invalidation and revoke preview object URLs. `[VERIFIED: codebase inspection — public/custom/grocy_AI/js/product-enrichment.js]` |
</phase_requirements>

## Summary

Phase 2 should be planned as four vertical slices: a strict end-to-end contract, canonical barcode ownership and normal-Save handoff, independent field review/final staging, and secure same-origin media selection. The current module already has a bounded request lifecycle, closed diagnostic outcomes, permission-checked Grocy proxy routes, and file-input staging, but its response normalization is permissive, its preview exposes external image URLs, and it has no canonical barcode ownership or per-field review state. `[VERIFIED: codebase inspection — custom/grocy_AI/src and public/custom/grocy_AI/js/product-enrichment.js]`

The barcode guarantee needs both an application resolver and a database race guard. Preserve the exact scanned text for display, canonicalize only checksum-valid GTIN-8/12/13/14 values by left-padding to 14 digits, resolve the owner locally before calling providers, and add a unique SQLite expression index whose `CASE` predicate applies the same GS1 checksum algorithm and returns `NULL` for every arbitrary numeric-looking non-GTIN. `GrocyAiGtin` owns both the PHP predicate and the generated SQL expression used by lookup, collision audit, and migration 0256.php. The deployment task must audit pre-existing canonical collisions before creating that index; it must never delete or silently reassign existing barcodes. `[CITED: https://ref.gs1.org/guidelines/2d-in-retail/]` `[VERIFIED: codebase inspection — migrations/0128.sql and product_barcodes schema; read-only production audit found 3 valid GTIN rows, 0 excluded invalid numeric rows, and 0 canonical collision groups]`

Secure media is an anti-SSRF/file-validation boundary, not a UI convenience. The browser must receive only same-origin paths containing opaque, expiring handles. The companion must stream with bounded time/bytes and revalidate every redirect target; Grocy must independently verify content type, magic signature, and decoded dimensions before exposing bytes or attaching a `File` to the existing product-picture input. `[CITED: https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html]` `[CITED: https://www.python-httpx.org/quickstart/#streaming-responses]` `[CITED: https://www.php.net/manual/en/function.getimagesizefromstring.php]`

**Primary recommendation:** Plan one tested vertical slice per boundary, keep all preview activity read-only, and make the existing product Save continuation the only place that writes selected fields, image bytes, and a newly staged barcode.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Strict enrichment response validation | API / Backend (Grocy PHP) | Companion API (Python) | Python produces the contract; PHP is the trust boundary that must reject malformed external-service data before it reaches the DOM. `[VERIFIED: codebase inspection — GrocyAiService.php and grocy_mcp/http_api.py]` |
| Canonical GTIN and owner resolution | API / Backend | Database / Storage | The browser displays the scan, while the server owns canonicalization/lookup and SQLite owns the final concurrency invariant. |
| Suggestion comparison, selection origin, stale-current checks, final diff | Browser / Client | API / Backend | These are transient review states; only validated server data may enter them, and no selection alone persists anything. |
| Product/userfield staging and normal Save | Browser / Client | Existing Grocy API / Database | Existing Grocy form controls and Save orchestration remain the persistence path. `[VERIFIED: codebase inspection — public/viewjs/productform.js and public/viewjs/userfieldsform.js]` |
| Opaque media handle issuance and upstream fetch | Companion API (Python) | Grocy PHP proxy | Only the companion knows external URLs; both tiers enforce bounds, and the browser stays same-origin. |
| Image attachment | Browser / Client | Existing Grocy file-upload API | A validated blob becomes a `File` in `#product-picture`; upload occurs through the existing normal Save continuation. `[VERIFIED: codebase inspection — product-enrichment.js and productform.js]` |
| Canonical duplicate prevention | Database / Storage | API / Backend | A unique expression index closes concurrent insertion races that a preflight query cannot close. `[VERIFIED: codebase inspection — current exact-only unique index]` |

## Project Constraints (from AGENTS.md)

- Keep fork-owned behavior under `custom/grocy_AI/` and `public/custom/grocy_AI/`; any unavoidable core hook must be minimal, documented in `CUSTOMIZATIONS.md`, and portable to the stable branch. `[VERIFIED: AGENTS.md]`
- Treat the production deployment as stable and preserve `/etc/komodo/grocy`; do not invent deployment paths or rewrite unrelated configuration. `[VERIFIED: AGENTS.md]`
- Preserve human control, reversible workflows, no hidden writes, LAN/mobile degradation, and bounded/allowlisted external data handling. `[VERIFIED: AGENTS.md]`
- Never put secrets in URLs, DOM text, logs, diagnostics, or committed files; keep secrets in runtime configuration. `[VERIFIED: AGENTS.md]`
- Use the existing PHP/Slim/Blade/plain-JavaScript/Bootstrap/jQuery/Guzzle stack, tabs and next-line braces in PHP, IIFEs and lower-camel local functions in JavaScript, and avoid formatting churn. `[VERIFIED: AGENTS.md]`
- Manually `require_once` custom classes where routes need them; this custom module has no separate bundler or module loader. `[VERIFIED: AGENTS.md]`
- Check permission before the operation and map failures through the established API error responses; render untrusted values through text-safe DOM APIs. `[VERIFIED: AGENTS.md]`
- Normalize and allowlist external values at the PHP boundary; keep external I/O behind injected callables where practical so unit tests remain deterministic. `[VERIFIED: AGENTS.md]`
- Put schema changes in numbered migrations; do not mutate committed schema snapshots or create ad hoc runtime schema. `[VERIFIED: AGENTS.md]`
- Run `php -l` on changed PHP, module harness tests, browser tests, companion tests, portable-manifest parity, stable-branch parity, and deployment verification appropriate to the touched slice. `[VERIFIED: AGENTS.md]`

## Existing Baseline and Evidence Boundary

The module currently validates GS1 check digits for 8-, 12-, 13-, and 14-digit inputs, calls the companion with 12-second overall/2-second connect bounds and redirects disabled, validates selected image MIME/byte/magic constraints, and returns private/no-store image responses behind `MASTER_DATA_EDIT`. `[VERIFIED: codebase inspection — custom/grocy_AI/src/GrocyAiService.php and GrocyAiApiController.php]`

The browser currently owns requests by sequence/GTIN, invalidates stale work on lifecycle changes, exposes named outcomes, and attaches a selected blob to the normal product-picture file input. It currently renders external image URLs in `href` and `img.src`; Phase 2 must remove both exposures. `[VERIFIED: codebase inspection — public/custom/grocy_AI/js/product-enrichment.js]`

Local baseline tests are green: the PHP harness passed 90 checks, the Chromium-mobile browser suite passed 47 tests in 23.5 seconds, and the companion unit suites passed 19 tests. `[VERIFIED: local test runs on 2026-08-13]`

Phase 1 is not fully accepted: its physical timing sampler and phone acceptance checklist were explicitly skipped, and the final acceptance artifact says `SKIPPED — NOT ACCEPTED`. Phase 2 plans must not mark that evidence complete or use Phase 2 tests as retroactive proof of Phase 1 physical timing. `[VERIFIED: .planning/phases/01-phone-first-enrichment-shell/01-PHONE-ACCEPTANCE.md and .planning/STATE.md]`

Nutrition Facts, allergen, dietary, and medical enrichment remain outside this phase even if a provider response contains such fields; strict validation should reject or discard them before the Phase 2 suggestion model is built. `[VERIFIED: .planning/REQUIREMENTS.md and 02-CONTEXT.md]`

## Standard Stack

### Core

| Library / Platform | Version | Purpose | Why Standard |
|--------------------|---------|---------|--------------|
| PHP | Production target 8.5; local 8.5.9 | Grocy trust boundary, permission checks, strict contract/media validation | Existing project runtime and module implementation. `[VERIFIED: AGENTS.md; local environment probe]` |
| Slim | 4.15.2 | Existing authenticated API routes | Already locked by Grocy Composer metadata; no new router. `[VERIFIED: composer.lock]` |
| Guzzle | 7.15.1 | Bounded Grocy-to-companion HTTP | Existing module client supports timeouts and redirect policy. `[VERIFIED: composer.lock and GrocyAiService.php]` |
| SQLite | Production minimum 3.40+; local 3.51.0 | Barcode ownership and atomic canonical uniqueness | Existing Grocy datastore; expression indexes are supported by the deployed minimum. `[VERIFIED: AGENTS.md and local environment probe]` |
| Plain JavaScript + jQuery | jQuery 3.7.1 | Transient selection reducer, DOM staging, existing Grocy events | Matches the existing frontend and avoids a second state framework. `[VERIFIED: package-lock.json and AGENTS.md]` |
| Bootstrap | 4.6.2 | Phone-first comparison rows, badges, accessible controls | Existing design system selected by the project. `[VERIFIED: package-lock.json and AGENTS.md]` |
| HTTPX | Local companion 0.28.1; project minimum `>=0.27` | Streamed upstream image requests and explicit timeout phases | Existing companion dependency; streaming and four timeout dimensions are documented APIs. `[VERIFIED: grocy-mcp/pyproject.toml and local venv]` `[CITED: https://www.python-httpx.org/advanced/timeouts/]` |

### Supporting

| Library / API | Version | Purpose | When to Use |
|---------------|---------|---------|-------------|
| PHP `getimagesizefromstring` | PHP 8.5 built-in | Decode width/height/type from validated bytes | Use after byte and magic checks; PHP explicitly warns it is not sufficient by itself. `[CITED: https://www.php.net/manual/en/function.getimagesizefromstring.php]` `[CITED: https://www.php.net/manual/en/function.getimagesize.php]` |
| Browser `DataTransfer` / `File` / blob URLs | Browser platform | Attach a validated image to the existing file input and preview it without an external URL | Use only after authenticated same-origin fetch; revoke blob URLs during clear/replacement. `[VERIFIED: existing product-enrichment.js implementation]` |
| Playwright | 1.62.1 | Chromium-mobile and WebKit regression/E2E tests | Use for review, barcode, no-write, media, lifecycle, and Save-path behavior. `[VERIFIED: browser-tests/package-lock.json]` |
| Python `ipaddress`, `urllib.parse`, `secrets`, `time` | Python 3.12+ standard library | URL/IP policy and opaque handle state | Use rather than a new package for network-target classification and tokens. `[CITED: https://docs.python.org/3/library/ipaddress.html]` |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Closed versioned DTO validation in existing PHP | Add a schema-validation package | A new package adds supply-chain and integration work; the contract is small enough for explicit allowlisted validators matching current module patterns. `[VERIFIED: codebase inspection — existing NormalizeResponse boundary]` |
| SQLite canonical expression index | Browser/server preflight only | Preflight alone has a check-then-insert race and cannot guarantee ENR-03/04 under concurrent saves. |
| Same-origin authenticated blob fetch | Direct external `<img>`/`<a>` | Direct URLs disclose browser/network metadata and bypass the authenticated proxy and byte/pixel checks. `[VERIFIED: current browser implementation exposes these URLs]` |
| PHP built-in decoded-dimension check | Add Pillow to the companion | Built-in PHP validation avoids a new dependency while still protecting every byte response before it reaches preview or selection. |

**Installation:** No new production package is recommended. Reuse repository lockfiles and the companion environment; do not run an unpinned install during implementation. `[VERIFIED: repository inspection]`

## Package Legitimacy Audit

No external package installation is recommended for Phase 2, so the package-legitimacy gate is not triggered. Existing PHP, JavaScript, and Python dependencies are reused from the two repositories rather than newly selected. `[VERIFIED: repository inspection]`

**Packages removed due to slopcheck `[SLOP]` verdict:** none.  
**Packages flagged as suspicious `[SUS]`:** none.  
**Planner action:** If implementation later introduces any package, add a human verification checkpoint and run the full registry, source, postinstall, and slopcheck audit before install.

## Architecture Patterns

### System Architecture Diagram

```text
scan / typed GTIN
       |
       v
Grocy PHP: validate check digit + retain exact scan
       |
       +--> canonical owner found ----> trusted /product/{id} route
       |
       '--> unused
              |
              v
       companion structured lookup
              |
              v
       strict contract-v2 validation at PHP boundary
              |
              v
       browser comparison rows
       [current snapshot | suggestion | provenance | selector]
              |
              +--> cancel/stale/timeout/failure --> clear transient state; zero writes
              |
              v
       final diff (automatic vs explicit)
              |
              v
       stage selected native/userfield/image controls + staged barcode
              |
              v
       existing normal product Save
              |
              +--> product/userfield/picture APIs
              '--> product_barcodes insert once, protected by canonical unique index

image candidate metadata (no URL in browser contract)
       |
       v
explicit thumbnail/full request to same-origin Grocy route
       |
       v
Grocy permission check --> companion opaque handle --> validated upstream target
       |                                              |
       |                                  stream / bounded redirects / byte cap
       v                                              |
MIME + signature + decoded dimensions <--------------'
       |
       v
private,no-store bytes --> browser blob URL / File --> normal Save only
```

### Recommended Project Structure

```text
custom/grocy_AI/
├── src/
│   ├── GrocyAiApiController.php       # authenticated read/proxy routes
│   ├── GrocyAiBarcodeService.php      # pure canonicalization + owner resolution
│   ├── GrocyAiContract.php            # closed v2 validator/DTO builder
│   └── GrocyAiService.php             # bounded companion calls/media validation
├── tests/
│   ├── run.php                         # contract, GTIN, media unit harness
│   ├── fixtures/                       # valid and adversarial contract fixtures
│   └── barcode-index.sql               # temp-DB uniqueness test fixture
└── README.md                           # contract and portable hook notes
public/custom/grocy_AI/
├── js/product-enrichment.js            # review reducer, diff, secure media demand-load
└── css/product-enrichment.css           # phone-first comparison layout
public/viewjs/productform.js             # minimal documented Save continuation hook
migrations/<next>.sql                    # canonical GTIN unique expression index
browser-tests/specs/grocy-ai/
├── contract-review.spec.js
├── barcode-handoff.spec.js
├── secure-media.spec.js
└── zero-write.spec.js

../grocy-mcp/src/grocy_mcp/
├── enrichment.py                        # direct/search evidence and opaque handles
├── enrichment_contract.py               # v2 response producer
└── secure_media.py                      # URL/IP/redirect/stream policy
../grocy-mcp/tests/
├── test_enrichment_contract.py
└── test_secure_media.py
```

File names for new units are recommendations, not existing files; keep responsibilities separate even if the planner chooses adjacent names.

### Pattern 1: Closed, Versioned Anti-Corruption Contract

**What:** Produce a versioned Python response and validate the complete shape again in PHP. Use closed field IDs and enums. Do not pass through provider-specific dictionaries or silently drop malformed required members.

**When to use:** Every enrichment response, including not-found, provider failure, owner-found, and image candidate outcomes.

**Recommended shape:**

```json
{
  "contract_version": 2,
  "outcome": "found",
  "barcode": {
    "scanned_gtin": "012345678905",
    "canonical_gtin": "00012345678905",
    "equivalents_checked": ["012345678905", "00012345678905"],
    "status": "unused",
    "owner_product_id": null
  },
  "suggestions": [
    {
      "id": "name:openfoodfacts:0",
      "field": "name",
      "value": "Example cereal",
      "display_value": "Example cereal",
      "source": {"id": "openfoodfacts", "label": "Open Food Facts"},
      "confidence_band": "high",
      "reason_code": "canonical_structured_match",
      "evidence_kind": "structured_direct",
      "retrieved_at": "2026-08-13T12:00:00Z",
      "source_updated_at": null,
      "target": null
    }
  ],
  "media": [
    {
      "id": "image:openfoodfacts:front",
      "kind": "front_package",
      "thumbnail_handle": "opaque-token",
      "full_handle": "opaque-token",
      "source": {"id": "openfoodfacts", "label": "Open Food Facts"},
      "confidence_band": "high",
      "reason_code": "canonical_structured_front_image",
      "evidence_kind": "structured_direct",
      "retrieved_at": "2026-08-13T12:00:00Z"
    }
  ],
  "warnings": [],
  "diagnostics": {"trace_id": "bounded-opaque-id"}
}
```

`contract_version: 2` is locked. The deployed response has no version field, and Phase 2 replaces that implicit v1 shape with an incompatible closed envelope, so integer 2 is the first explicit version and cannot be confused with the deployed payload. Freshness distinguishes “retrieved at” from a provider-supplied “record updated at”; absence of the latter must not be presented as current source data. `[VERIFIED: current companion/Grocy contract inspection]`

The PHP validator should inspect the raw response with a duplicate-aware JSON tokenizer before ordinary decoding, decoding member-name escapes and tracking keys independently at every object depth. It rejects repeated top-level or nested keys before `json_decode` can collapse them, then rejects the response as `contract_invalid` when the version, members, field IDs, enums, timestamps, suggestion IDs, value type, source allowlist, target shape, or media shape is invalid. It also rejects duplicate suggestion IDs and any raw image URL. Provider nutrition fields never become Phase 2 suggestions.

### Pattern 2: Canonical GTIN Resolver Plus Database Invariant

**What:** Validate the GS1 check digit, retain the original string, and derive a comparison key by left-padding the valid GTIN to 14 characters. Resolve all stored 8/12/13/14 numeric barcodes by that expression; route on owner before enrichment.

**When to use:** Initial scan, pre-Save recheck, insert-conflict recovery, and every product-barcode creation path.

```php
// Source: GS1 canonical representation guidance
private static function CanonicalGtin(string $gtin): string
{
	// Call the existing GS1 length/check-digit validator first.
	return str_pad($gtin, 14, '0', STR_PAD_LEFT);
}
```

`[CITED: https://ref.gs1.org/guidelines/2d-in-retail/]`

Use a migration expression index after a read-only collision audit. The following abbreviated shape is illustrative only; `GrocyAiGtin::CanonicalSqlExpression('barcode')` must emit the complete length, numeric, and GS1 checksum predicate used by migration 0256.php and every owner query:

```sql
CREATE UNIQUE INDEX ix_product_barcodes_canonical_gtin
ON product_barcodes (
	CASE
		WHEN length(barcode) IN (8, 12, 13, 14)
			AND barcode NOT GLOB '*[^0-9]*'
			AND /* exact GS1 alternating-weight checksum predicate */
		THEN substr('00000000000000' || barcode, -14, 14)
		ELSE NULL
	END
);
```

The exact predicate returns a canonical key only for checksum-valid GTINs and `NULL` for arbitrary text plus invalid numeric-looking 8/12/13/14-character barcodes. Before applying it, query/group by the same generated expression and stop with an actionable report if multiple rows map to one key; never auto-resolve those rows. PHP lookup and database enforcement therefore share one implementation artifact rather than merely similar prose. `[VERIFIED: Grocy supports arbitrary text barcodes; production read-only audit found no collision; temporary tests must include equivalent invalid numeric-looking rows]`

The earlier length-only prototype was discarded because it also constrained checksum-invalid numeric-looking barcodes. Planning now requires the shared checksum-valid PHP/SQL predicate and direct equivalence tests for valid and invalid numeric-looking pairs before migration. The read-only production audit found three valid GTIN rows, zero excluded invalid numeric rows, and zero canonical collision groups. `[RESOLVED: checker correction plus runtime audit on 2026-08-13]`

### Pattern 3: Review Reducer, Current-Value Snapshot, and Explicit Staging

**What:** Keep a module-owned state object with immutable validated suggestions, current-value snapshots, selection state, and selection origin. Automatic selection is permitted only for blank snapshots plus `high + structured_direct + canonical match`. A user click changes origin to `explicit` even if it re-selects an automatically selected row.

**When to use:** All field and image suggestions.

```javascript
function canAutoSelect(suggestion, currentValue)
{
	return currentValue === ''
		&& suggestion.confidence_band === 'high'
		&& suggestion.evidence_kind === 'structured_direct'
		&& suggestion.reason_code === 'canonical_structured_match';
}

function selectionOrigin(row)
{
	return row.wasUserChanged ? 'explicit' : 'automatic';
}
```

Before rendering the final diff or staging controls, re-read every current control and compare it with the snapshot. If it changed, mark that row stale, clear automatic selection, and require explicit re-review. Do not overwrite the new value with an old suggestion.

The final-diff confirmation stages selected values into existing controls and dispatches the same change/input behavior Grocy uses. This is especially important for product userfields, because `UserfieldsForm.Save` persists only inputs marked dirty. `[VERIFIED: codebase inspection — public/viewjs/userfieldsform.js and public/viewjs/grocy.js]`

### Pattern 4: Save Continuation With Idempotent Barcode Attachment

**What:** Extend the existing product Save promise/callback chain with a narrow module hook. Product creation happens first; after a product ID exists, re-resolve the canonical owner and issue one normal `objects/product_barcodes` insert if still unused. If an insert loses a race, re-resolve: same-product ownership is success, other-product ownership is an explicit conflict/route.

**When to use:** Only after the user presses normal Save and only when a barcode is staged.

Do not invoke the existing standalone barcode modal Save during preview; it writes immediately. `[VERIFIED: codebase inspection — public/viewjs/productbarcodes.js]` Do not create a hidden enrichment write endpoint. Keep the product form visible if barcode attachment fails after product creation, explain the partial outcome, and allow a safe barcode-only retry without creating a second product.

### Pattern 5: Opaque Capability Handles and Two-Layer Media Validation

**What:** Keep external URLs only in companion process state keyed by cryptographically random, expiring handles. Bind each handle to image identity and allowed variant. The browser explicitly requests a thumbnail; selection explicitly requests full bytes. Both routes are authenticated same-origin Grocy routes.

**When to use:** Every thumbnail, preview, and selected product image.

Companion policy:

1. Parse and allow only HTTP/HTTPS, no userinfo, no fragments, and approved ports.
2. Resolve the hostname and reject loopback, private, link-local, multicast, unspecified, reserved, and metadata endpoints for IPv4 and IPv6.
3. Do not automatically follow redirects. Follow at most two hops, repeat the full URL/DNS/IP/actual-peer policy on every hop, and forbid HTTPS-to-HTTP downgrade. Two is locked because the current fetcher follows redirects without a cap and no allowlisted deployed corpus proving a larger requirement exists; execution fixture sampling may only confirm this value or block, never raise it silently. `[RESOLVED: security-default decision plus current-code inspection]`
4. Stream bytes, reject an oversized `Content-Length`, abort immediately after 3 MB, and keep the existing 2 KB lower bound and bounded connect/total time.
5. Allow only JPEG, PNG, and WebP MIME/signatures.

OWASP documents redirect-based SSRF bypasses and recommends allowlisting where destinations are known. `[CITED: https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html]` HTTPX documents that redirects are opt-in and that streaming avoids loading the entire body at once. `[CITED: https://www.python-httpx.org/quickstart/#redirection-and-history]` `[CITED: https://www.python-httpx.org/quickstart/#streaming-responses]`

Grocy policy:

1. Require `MASTER_DATA_EDIT` before handle redemption.
2. Validate the token and variant as closed values.
3. Re-apply byte, exact MIME, magic signature, and decoded width/height/pixel limits.
4. Return fixed content disposition plus `Cache-Control: private, no-store` and `X-Content-Type-Options: nosniff`.
5. Never include raw external URLs or tokens in diagnostics.

Use 32–4096 pixels per dimension and at most 16 megapixels. These bounds are locked alongside the existing 2,000–3,000,000-byte JPEG/PNG/WebP envelope; execution fixture sampling may demonstrate incompatibility and block for a new decision, but must not widen them. PHP warns that dimension parsing is not itself proof of a valid image, so retain the MIME and signature checks. `[RESOLVED: existing deployed byte/type envelope plus conservative decoder bounds]` `[CITED: https://www.php.net/manual/en/function.getimagesize.php]`

### Anti-Patterns to Avoid

- **Permissive partial normalization:** Silently dropping malformed suggestions makes contract drift invisible. Reject the response and show the named recovery state.
- **Numeric GTIN coercion:** Integers destroy leading zeros. Use strings throughout.
- **UI-only duplicate detection:** It cannot close a race or protect other barcode entry paths.
- **Auto-selecting mapped local IDs:** Product-group/quantity-unit mapping is transformed evidence and D-03 forbids automatic selection.
- **Treating search rank as trust:** SearXNG stays unverified regardless of numeric score.
- **Direct external image DOM nodes:** No external URL belongs in `src`, `href`, CSS, logs, or the browser contract.
- **Whole-body upstream reads:** Checking size after `response.content` is too late for memory exhaustion; stream and abort.
- **Automatic redirect following:** Each hop is a new SSRF target and must be revalidated.
- **Writing during review:** No product, barcode, userfield, category, stock, conversion, or file call is allowed before normal Save.
- **Claiming Phase 1 timing acceptance:** That evidence remains skipped and incomplete. `[VERIFIED: 01-PHONE-ACCEPTANCE.md]`

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| GTIN equivalence | Ad hoc substring/removal rules in JavaScript | Existing GS1 check-digit validator plus one shared server canonical-14 helper | Preserves leading zeros and one rule across owner lookup, display, and Save. `[CITED: https://ref.gs1.org/guidelines/2d-in-retail/]` |
| Concurrency-safe duplicates | A preflight-only flag | SQLite unique expression index plus conflict recovery | The database is the only tier that can atomically reject concurrent equivalent inserts. |
| Product persistence | A new enrichment write API | Existing Grocy product, userfield, picture, and product-barcode Save APIs orchestrated by normal Save | Retains authorization, audit behavior, and the user-controlled Save contract. `[VERIFIED: codebase inspection]` |
| Image parsing as security proof | Extension/MIME header checks alone | Existing magic checks plus `getimagesizefromstring` and byte/pixel bounds | Headers are attacker-controlled and dimension parsing alone is also insufficient. `[CITED: https://www.php.net/manual/en/function.getimagesize.php]` |
| SSRF protection | Regex host blocking | Structured URL parsing, DNS/IP classification, per-hop validation, and bounded streaming | Encoded/IPv6/redirect/DNS cases defeat simple string tests. `[CITED: https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html]` |
| Transient UI framework | A new state library | A small reducer/state object inside the existing IIFE | The feature has a closed state space and the project intentionally uses plain JavaScript. `[VERIFIED: AGENTS.md]` |
| HTML sanitization by convention | String-built HTML containing provider values | `textContent`/jQuery `.text()` and fixed DOM templates | Prevents provider-controlled markup execution. `[VERIFIED: AGENTS.md]` |

**Key insight:** The difficult parts are cross-tier invariants—provenance, ownership, persistence timing, and media trust. Each must have one authoritative server/database rule and tests at every adapter; duplicating only UI checks does not satisfy the contract.

## Common Pitfalls

### Pitfall 1: A “strict” producer with a permissive consumer

**What goes wrong:** The companion changes a field or enum and PHP silently omits it, leaving a plausible but incomplete review.  
**Why it happens:** Current normalization validates individual pieces and continues. `[VERIFIED: GrocyAiService.php]`  
**How to avoid:** Validate the whole versioned envelope and fail closed with `contract_invalid`. Add a fixture for every missing/unknown/type/duplicate case.  
**Warning signs:** Provider suggestions disappear without a named error or contract-version test failure.

### Pitfall 2: Conflating scan text, canonical key, and product display barcode

**What goes wrong:** Leading zeros disappear, the UI shows a value the user did not scan, or an equivalent reaches a different owner.  
**Why it happens:** Numeric coercion or one mutable `upc` variable is used for all three roles.  
**How to avoid:** Keep immutable `scanned_gtin`, derived `canonical_gtin`, and bounded `equivalents_checked`; show the first and compare by the second.  
**Warning signs:** JSON numbers, `parseInt`, or database casts around a barcode.

### Pitfall 3: Preflight without atomic uniqueness

**What goes wrong:** Two tabs both see “unused” and save canonical equivalents.  
**Why it happens:** The current unique index compares raw text only. `[VERIFIED: migration 0128.sql]`  
**How to avoid:** Audit collisions, add the canonical expression unique index, and re-resolve after any insert conflict.  
**Warning signs:** ENR-03/04 tests pass serially but no two-context/race or direct-SQL uniqueness test exists.

### Pitfall 4: Partial product Save followed by barcode failure

**What goes wrong:** The product exists, attachment fails, and a retry creates a second product.  
**Why it happens:** Existing create Save redirects after its continuation and was not designed for a staged barcode. `[VERIFIED: productform.js]`  
**How to avoid:** Retain the created product ID, suppress redirect until attachment resolves, and expose a barcode-only retry that first checks ownership.  
**Warning signs:** Barcode errors are handled by the same handler that repeats the product POST.

### Pitfall 5: Auto-selecting “high score” indirect evidence

**What goes wrong:** A search result or local taxonomy mapping overwrites a field automatically.  
**Why it happens:** Numeric provider score is mistaken for evidence type.  
**How to avoid:** Auto-selection requires all four checks: blank current value, high band, `structured_direct`, canonical match.  
**Warning signs:** A single `score >= threshold` condition.

### Pitfall 6: Stale current values after enrichment completes

**What goes wrong:** The user edits a field while a request is in flight, then the old suggestion overwrites it.  
**Why it happens:** Selection uses the value captured only at request start.  
**How to avoid:** Snapshot at render and compare again before diff/staging; clear automatic selection on mismatch.  
**Warning signs:** Tests cover stale responses but not user edits after a valid response.

### Pitfall 7: “Proxying” while still leaking the origin URL

**What goes wrong:** Thumbnails are proxied for selection but external URLs remain in links, image attributes, or diagnostics.  
**Why it happens:** The existing candidate model contains both `url` and `download_token`. `[VERIFIED: GrocyAiService.php and product-enrichment.js]`  
**How to avoid:** Remove URL from the browser contract entirely and make all media display demand-loaded through handles.  
**Warning signs:** `http` appears in serialized image candidates or rendered attributes.

### Pitfall 8: Redirect/DNS bypass or decompression/pixel bomb

**What goes wrong:** An allowed-looking URL reaches a private service or a tiny compressed file decodes to unsafe dimensions.  
**Why it happens:** Only the first hostname and compressed byte count are checked.  
**How to avoid:** Validate every redirect target/IP and enforce decoded dimension/pixel limits in Grocy. `[CITED: https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html]`  
**Warning signs:** `follow_redirects=True`, `response.content`, or no decoded-size tests.

### Pitfall 9: Dependency drift in the companion

**What goes wrong:** A clean environment resolves newer major transitive versions and test behavior changes.  
**Why it happens:** The companion uses lower bounds without a committed lock; the current local test run emits a Starlette TestClient deprecation warning. `[VERIFIED: grocy-mcp/pyproject.toml and local test run]`  
**How to avoid:** Record the tested container dependency set or add a reproducible lock/constraints artifact as a Wave 0 task before changing HTTP behavior.  
**Warning signs:** Local and container HTTPX/Starlette versions differ without an explicit compatibility run.

### Pitfall 10: Accidentally broadening Phase 2 into nutrition or taxonomy

**What goes wrong:** Provider nutrition/categories become new persisted fields without their own normalization and product model.  
**Why it happens:** Those fields are available in upstream payloads.  
**How to avoid:** Close the Phase 2 field allowlist and treat Nutrition Facts as deferred. Food-type persistence requires the explicit resolution documented under Open Questions.  
**Warning signs:** nutrition, allergen, dietary, medical, serving, calorie, or nutrient keys in the v2 suggestion union.

## Code Examples

### Streaming an upstream response with independent timeout phases

```python
# Source: HTTPX official timeout and streaming documentation
timeout = httpx.Timeout(connect=2.0, read=6.0, write=6.0, pool=2.0)
async with client.stream("GET", validated_url, timeout=timeout, follow_redirects=False) as response:
    response.raise_for_status()
    total = 0
    chunks = []
    async for chunk in response.aiter_bytes():
        total += len(chunk)
        if total > MAX_IMAGE_BYTES:
            raise MediaTooLarge()
        chunks.append(chunk)
```

`[CITED: https://www.python-httpx.org/advanced/timeouts/]` `[CITED: https://www.python-httpx.org/quickstart/#streaming-responses]`

The implementation should hash/validate while streaming or use a bounded temporary stream rather than retaining multiple copies of the 3 MB buffer.

### Checking decoded dimensions after signature/MIME validation

```php
// Source: PHP getimagesizefromstring documentation
$imageInfo = @getimagesizefromstring($bytes);
if ($imageInfo === false)
{
	throw new RuntimeException('Image dimensions could not be decoded');
}

[$width, $height] = $imageInfo;
if ($width < $minDimension || $height < $minDimension
	|| $width > $maxDimension || $height > $maxDimension
	|| ($width * $height) > $maxPixels)
{
	throw new RuntimeException('Image dimensions are outside the allowed bounds');
}
```

`[CITED: https://www.php.net/manual/en/function.getimagesizefromstring.php]`

### Text-safe phone comparison row

```javascript
function setSuggestionText(row, currentValue, suggestion)
{
	row.querySelector('[data-role="current"]').textContent = currentValue || 'Blank';
	row.querySelector('[data-role="suggested"]').textContent = suggestion.display_value;
	row.querySelector('[data-role="source"]').textContent = suggestion.source.label;
	row.querySelector('[data-role="reason"]').textContent = reasonLabels[suggestion.reason_code];
}
```

`[VERIFIED: AGENTS.md requires text-safe DOM APIs for untrusted external data]`

### Canonical collision audit before migration

```sql
SELECT canonical_gtin, COUNT(*) AS row_count
FROM (
	SELECT id,
		/* GrocyAiGtin::CanonicalSqlExpression('barcode') */ AS canonical_gtin
	FROM product_barcodes
)
WHERE canonical_gtin IS NOT NULL
GROUP BY canonical_gtin
HAVING COUNT(*) > 1;
```

Generate the expression from the same helper used by owner lookup and migration, and run this as a read-only deployment preflight. Record only the aggregate collision-group count/hash, never canonical values or row IDs. Any result is a human data-resolution checkpoint, not permission to delete records.

## State of the Art

| Old / Current Approach | Phase 2 Approach | Impact |
|------------------------|------------------|--------|
| Permissive response normalization | Closed version plus all-or-nothing boundary validation | Contract drift becomes a named, testable failure. |
| One summary and “apply name” | Independent field comparison rows plus final diff | ENR-05/06 become visible and reversible. |
| Numeric score influences presentation | Evidence-kind and confidence-band policy | Search/mapped/inferred suggestions cannot masquerade as direct evidence. |
| Raw external thumbnail/full URL in browser | Same-origin demand-load by opaque handles | Removes direct media leakage and centralizes safeguards. |
| Whole-body image read before final size check in companion | Streamed bounded read plus decoded-dimension gate | Limits memory and pixel-bomb exposure. `[CITED: https://www.python-httpx.org/quickstart/#streaming-responses]` |
| Exact-text barcode uniqueness only | Canonical GS1 lookup plus canonical expression uniqueness | Prevents equivalent GTIN duplicates while retaining the scanned string. |

**Deprecated/outdated for this phase:**

- The current `images[].url` browser contract must be removed, not merely ignored by one UI path. `[VERIFIED: current contract inspection]`
- A high numeric score alone must not drive automatic selection. `[VERIFIED: D-03]`
- The standalone barcode modal Save is not a valid staging path for ENR-04 because it persists before the product form's normal Save. `[VERIFIED: productbarcodes.js]`

## Resolved Decisions Log

| ID | Locked result | Evidence and execution guard |
|---|---|---|
| R-01 | Integer `contract_version: 2`. | Current deployed payload is unversioned/implicit v1; producer and raw duplicate-aware consumer tests lock exact v2. |
| R-02 | Maximum two fully revalidated redirects; no HTTPS downgrade. | Current fetcher is uncapped. Wave 0 measures allowlisted fixtures and blocks if incompatible; it may not silently widen the limit. |
| R-03 | Decoded width/height 32–4096 and at most 16MP, retaining 2KB–3MB JPEG/PNG/WebP bounds. | Current deployed byte/type bounds plus conservative decode limits; Wave 0 boundary fixtures lock exact inclusive/exclusive behavior. |
| R-04 | Brand stages only to the active `products.brand` text-single-line userfield. Package size has no deployed destination and remains visible but disabled with the UI-SPEC unavailable copy; Phase 2 creates no userfield. | Read-only production inventory found `brand|Brand|text-single-line` and no package-size userfield. Execution rechecks name/type exactly and fails closed on drift. |
| R-05 | Food type is contract/UI evidence only and non-stageable with `No local food type is configured.` Phase 3 owns taxonomy identity/mapping/persistence. Nutrition Facts remains rejected/deferred. | REQUIREMENTS/ROADMAP/CONTEXT phase boundary plus live inventory showing no food-type target. |
| R-06 | Only checksum-valid GTINs receive canonical keys; invalid numeric-looking barcodes remain arbitrary Grocy barcodes and are excluded from the index. Current deployment has zero canonical collision groups. | Shared `GrocyAiGtin` PHP/SQL expression, temporary SQLite vectors, and read-only production audit (3 valid, 0 invalid-numeric, 0 collision groups). Execution repeats the aggregate audit and blocks on nonzero. |
| R-07 | Reproduce the deployed companion set before HTTP changes: Python 3.12.13, HTTPX 0.28.1, Starlette 1.6.0, Uvicorn 0.52.1, plus the complete redacted installed-distribution hash captured into a committed constraints artifact. | Read-only container metadata. Execution exports sorted package names/versions only, verifies the four anchors, writes a hash/constraints file, and builds/tests from it; drift blocks rather than floating to new versions. |

## Open Questions (RESOLVED)

All five original questions are resolved by R-01 through R-07. No user decision remains open. Runtime-dependent facts are rechecked by autonomous, read-only preflight tasks; a changed destination, nonzero collision count, media-bound incompatibility, or dependency mismatch is a fail-closed execution checkpoint, not permission to guess, widen security limits, create fields, delete data, or cross the Phase 3 boundary.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|-------------|-----------|---------|----------|
| PHP CLI | PHP lint/harness | ✓ | 8.5.9 | Production container verification remains required. `[VERIFIED: local probe]` |
| SQLite CLI | collision/index tests | ✓ | 3.51.0 | PHP PDO SQLite temp-DB harness if CLI unavailable in CI. `[VERIFIED: local probe]` |
| Node.js | Playwright tests | ✓ | 25.9.0 | Use repository/container supported Node if production differs. `[VERIFIED: local probe]` |
| npm | Playwright scripts | ✓ | 11.12.1 | — `[VERIFIED: local probe]` |
| Playwright browser binaries | Chromium-mobile/WebKit | ✓ | Chromium 1234, WebKit 2336 cache entries | Install only from repository lock if CI cache is missing. `[VERIFIED: local filesystem probe]` |
| Companion Python venv | companion unit tests | ✓ | Python 3.13.13 | Dockerfile target is Python 3.12; run both before deployment. `[VERIFIED: local probe and Dockerfile]` |
| `uv` | companion environment management | ✓ | 0.11.7 | Existing venv can run tests directly. `[VERIFIED: local probe]` |
| Docker CLI/daemon | container parity | ✗ locally | — | Run container build/test on deployment host or CI; repository unit tests remain available locally. `[VERIFIED: local probe]` |
| Composer CLI / installed Grocy vendor tree | full PHP application integration | ✗ in this checkout | — | Existing isolated PHP module harness and deployed-container integration run. `[VERIFIED: local probe and repository inspection]` |
| Live Grocy/companion/SearXNG services | deployment smoke/UAT | Not probed | — | Planner must include authenticated read-only status followed by controlled UAT; no repository test may claim live acceptance. |

**Missing dependencies with no fallback:** none for repository planning and isolated implementation tests. A deployment/container environment is required for final integration proof.  
**Missing dependencies with fallback:** Docker and full vendor installation are absent locally; use isolated harnesses now and the deployment/CI environment for the phase gate.

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| PHP unit/harness | Custom deterministic harness, `custom/grocy_AI/tests/run.php`; existing baseline 90/90. `[VERIFIED: local test run]` |
| Browser/E2E | Playwright 1.62.1, config `browser-tests/playwright.config.js`; existing Chromium-mobile baseline 47/47. `[VERIFIED: local test run]` |
| Companion unit/API | Python `unittest`; existing `tests.test_enrichment` and `tests.test_http_api` baseline 19/19. `[VERIFIED: local test run]` |
| Quick run command | `php custom/grocy_AI/tests/run.php` |
| Focused browser command | `cd browser-tests && npm test -- --project=chromium-mobile --grep "@enr"` |
| Focused companion command | `cd ../grocy-mcp && .venv/bin/python -m unittest tests.test_enrichment_contract tests.test_secure_media` |
| Full Grocy browser suite | `cd browser-tests && npm test` |
| Full companion suite | `cd ../grocy-mcp && .venv/bin/python -m unittest discover -s tests` |

### Test Layer Design

1. **Pure contract/GTIN/media units:** Fast PHP and Python cases for every valid/invalid member, checksum/canonical form, evidence/preselection class, MIME/magic/dimension limit, handle expiry, URL/IP class, redirect hop, and byte boundary.
2. **Temporary SQLite invariant:** Apply the exact migration expression index to a temporary database. Assert raw equivalents conflict, arbitrary non-GTIN barcodes still coexist, and the collision audit detects pre-existing duplicates before index creation.
3. **Mocked companion integration:** Grocy PHP receives valid and adversarial v2 fixtures; assert all-or-nothing normalization and no external URL escape.
4. **Browser vertical slices:** The existing fixture server records mutation calls and serves same-origin media bytes. Each spec drives actual compare/select/diff/Save/lifecycle behavior on a mobile viewport.
5. **Dual-repository and deployment gate:** Run Grocy and companion suites, portable-manifest parity, stable-branch adapter tests, then authenticated live smoke/UAT. Do not merge Phase 1 timing acceptance into this phase's evidence.

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| ENR-01 | Accept only v2 closed envelopes; require provenance/freshness; reject unknown/missing/type/duplicate/raw-URL/nutrition members | PHP + Python unit/fixture integration | `php custom/grocy_AI/tests/run.php` and `cd ../grocy-mcp && .venv/bin/python -m unittest tests.test_enrichment_contract` | ❌ Wave 0 contract fixtures/classes |
| ENR-02 | Preserve exact scan and compute/display canonical equivalents without numeric coercion | PHP unit + browser | `php custom/grocy_AI/tests/run.php` and `cd browser-tests && npm test -- --project=chromium-mobile --grep "@enr02"` | ❌ Wave 0 |
| ENR-03 | Existing raw or canonical-equivalent barcode routes to trusted owning product; DB rejects equivalent duplicate | PHP/temp-SQLite + browser | `php custom/grocy_AI/tests/barcode-handoff.php` and `cd browser-tests && npm test -- --project=chromium-mobile --grep "@enr03"` | ❌ Wave 0 |
| ENR-04 | Unused barcode remains transient until normal Save and is inserted once; retries/insert conflicts are idempotent | Browser integration + temp-SQLite | `cd browser-tests && npm test -- --project=chromium-mobile --grep "@enr04"` | ❌ Wave 0 |
| ENR-05 | Seven supported suggestion families render independent current/suggested/provenance controls; unmapped locals cannot be selected | Browser + PHP contract | `cd browser-tests && npm test -- --project=chromium-mobile --grep "@enr05"` | ❌ Wave 0 |
| ENR-06 | Automatic versus explicit selection appears in final diff; only selected/live rows stage and normal Save persists them | Browser integration | `cd browser-tests && npm test -- --project=chromium-mobile --grep "@enr06"` | ❌ Wave 0 |
| ENR-07 | Structured front-package image ranks first; every SearXNG candidate is unverified and never auto-selected | Python unit + browser | `cd ../grocy-mcp && .venv/bin/python -m unittest tests.test_enrichment_contract` and focused browser grep `@enr07` | ❌ Wave 0 |
| ENR-08 | No external URL reaches browser; explicit same-origin demand-load enforces auth, handle TTL/variant, SSRF, redirect, byte/time, MIME/magic/dimension limits | Python + PHP + browser integration | `cd ../grocy-mcp && .venv/bin/python -m unittest tests.test_secure_media` and focused browser grep `@enr08` | ❌ Wave 0 |
| ENR-09 | Preview/cancel/stale/timeout/fetch failure make zero product/barcode/category/stock/conversion/file writes | Browser route-counter integration | `cd browser-tests && npm test -- --project=chromium-mobile --grep "@enr09"` | ❌ Wave 0 extension of existing lifecycle specs |

### Required Adversarial Fixtures

- Contract: wrong version, unknown field/source/evidence/reason, missing freshness, duplicate ID, wrong value/target type, HTML-bearing strings, raw media URL, and provider nutrition keys.
- GTIN: valid 8/12/13/14, leading-zero equivalents, invalid checksum, numeric-looking arbitrary barcode, exact owner, equivalent owner, different packaging indicator, pre-existing collision, and concurrent/simulated insert conflict.
- Selection: blank versus whitespace/non-empty, automatic deselect/reselect, explicit overwrite, current value edited after response, stale request, duplicate response, and selection cleared on GTIN change.
- Media: loopback/private/link-local/multicast/IPv6/metadata hosts, DNS resolution rejection, redirect to blocked IP, redirect loop/hop overflow/downgrade, oversized `Content-Length`, streamed overflow, too-small file, MIME/signature mismatch, malformed dimensions, zero/huge dimensions, expired/replayed wrong-variant handle, cancellation, and object-URL revocation.
- Persistence: zero writes for every pre-Save outcome; one product-barcode insert after normal Save; no second product creation after partial attachment failure; unselected userfields remain clean.

### Sampling Rate

- **Per task commit:** Run the smallest PHP/Python unit file plus one focused Chromium-mobile `@enrXX` spec; keep feedback under 30 seconds.
- **Per wave merge:** Run `php custom/grocy_AI/tests/run.php`, the full companion unittest discovery, and all Chromium-mobile Grocy-AI specs.
- **Phase gate:** Full Chromium-mobile and WebKit suites, full companion suite, PHP lint/harness, temp-SQLite migration test, portable-manifest parity, stable-branch parity at an explicit SHA, and authenticated deployment smoke/UAT must be green before `$gsd-verify-work`.
- **Evidence boundary:** Phase 2 may record its own mobile/security/save-path evidence, but Phase 1 remains incomplete until its separately specified physical timing/phone acceptance is actually performed. `[VERIFIED: STATE.md and 01-PHONE-ACCEPTANCE.md]`

### Wave 0 Gaps

- [ ] `custom/grocy_AI/src/GrocyAiContract.php` and adversarial JSON fixtures — strict ENR-01 boundary.
- [ ] `custom/grocy_AI/src/GrocyAiBarcodeService.php` plus pure canonical/owner fixtures — ENR-02/03.
- [ ] `custom/grocy_AI/tests/barcode-handoff.php` (or equivalent temp-SQLite harness) — collision audit/index/concurrency invariant.
- [ ] `browser-tests/specs/grocy-ai/contract-review.spec.js` — ENR-01/05/06.
- [ ] `browser-tests/specs/grocy-ai/barcode-handoff.spec.js` — ENR-02/03/04.
- [ ] `browser-tests/specs/grocy-ai/secure-media.spec.js` — ENR-07/08.
- [ ] `browser-tests/specs/grocy-ai/zero-write.spec.js` plus fixture route counters for all mutation families — ENR-09.
- [ ] `../grocy-mcp/tests/test_enrichment_contract.py` — producer contract/provenance/ranking.
- [ ] `../grocy-mcp/tests/test_secure_media.py` — handle, URL/IP, redirect, streaming, byte/MIME policy.
- [ ] Reproducible companion dependency record/constraints or container-version capture before HTTP refactor.
- [ ] Read-only deployed userfield, canonical-collision, image-corpus, and dependency inventory checkpoints.

## Security Domain

OWASP ASVS 5.0 is the current published ASVS release and provides a basis for testing application security controls. `[CITED: https://owasp.org/www-project-application-security-verification-standard/]`

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|------------------|
| V2 Authentication | Inherited | No new login mechanism; authenticated Grocy session remains required for proxy/API calls. `[VERIFIED: existing Grocy route/controller behavior]` |
| V3 Session Management | Inherited | Same-origin session cookies and existing Grocy middleware; never place credentials/tokens in external URLs. |
| V4 Access Control | Yes | Require `MASTER_DATA_EDIT` before owner/enrichment/media operations and existing object API permissions for Save. `[VERIFIED: current GrocyAiApiController.php]` |
| V5 Input Validation | Yes | Closed DTO, GS1 checksum/string handling, local-ID existence/activity checks, text-safe DOM rendering. |
| V6 Cryptography | Limited | Use standard cryptographically random opaque handles; do not invent encryption or signatures. |
| V8 Data Protection | Yes | No raw external URL, secret, provider payload, or sensitive request content in DOM/diagnostics/logs; private/no-store media responses. |
| V12 / ASVS 5 File Handling | Yes | Bounded bytes, exact supported content types, signature plus decoded-dimension validation, trusted internal handle paths. OWASP's file-handling controls call for upload bounds, type/content agreement, and image size/pixel safeguards. `[CITED: https://cornucopia.owasp.org/taxonomy/asvs-5.0/05-file-handling/02-file-upload-and-content]` |
| V13 API and Web Service | Yes | Versioned contract, closed enums, bounded upstream client, status/error mapping, no mass assignment. |

### Known Threat Patterns for This Stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| External URL reaches loopback/private/metadata service | Spoofing / Information Disclosure | Structured URL parsing, DNS/IP classification, per-hop validation, approved schemes/ports, no automatic redirects. `[CITED: OWASP SSRF Cheat Sheet]` |
| Redirect changes an approved public host to a blocked target | Spoofing | Manual bounded redirects with full revalidation, or reject redirects when not needed. |
| Oversized or slow upstream response | Denial of Service | Connect/read/pool/total bounds, streamed byte cap, cancellation, candidate/handle limits. `[CITED: HTTPX timeout and streaming docs]` |
| MIME spoof / polyglot / pixel flood | Tampering / Denial of Service | Exact allowlist, signature, decoded dimension and total-pixel cap; dimension parsing is not the sole validity check. `[CITED: PHP image docs and OWASP file handling]` |
| Provider markup in UI | Tampering / Elevation of Privilege | Fixed templates and `.text()`/`textContent`, no provider HTML. |
| Opaque handle guessing/replay/wrong variant | Information Disclosure | Cryptographic random token, TTL, bounded store, variant binding, permission check, generic expiry response, no token logging. |
| Canonical barcode check-then-insert race | Tampering / Repudiation | SQLite unique canonical expression plus re-resolve/idempotent conflict handling. |
| Preview path mutates data | Tampering | Read-only routes, browser write counters, mutation only after normal Save. |
| Diagnostics reveal secrets/URLs/product data | Information Disclosure | Closed bounded diagnostic codes and trace IDs; never raw URLs, response bodies, headers, or secrets. |

## Sources

### Primary (HIGH confidence)

- Repository code and tests at Grocy `6b3925618f16a52bd195fd13f9d791a1ef8f2038` — current PHP/JS/schema/test behavior. `[VERIFIED: local git/code inspection]`
- Companion repository at `521c9b0cce522095867600f9da22913d4bd790d3` — producer, handles, HTTP behavior, and tests. `[VERIFIED: local git/code inspection]`
- `.planning/REQUIREMENTS.md`, `.planning/STATE.md`, `02-CONTEXT.md`, and Phase 1 acceptance artifacts — locked scope and evidence boundary. `[VERIFIED: local document inspection]`
- [GS1 2D Barcodes in Retail guideline](https://ref.gs1.org/guidelines/2d-in-retail/) — canonical 14-digit GTIN representation.
- [OWASP SSRF Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html) — URL/IP/redirect threat controls.
- [HTTPX Timeouts](https://www.python-httpx.org/advanced/timeouts/) and [QuickStart streaming/redirects](https://www.python-httpx.org/quickstart/) — explicit timeout phases, streaming, and redirect behavior.
- [PHP getimagesizefromstring](https://www.php.net/manual/en/function.getimagesizefromstring.php) and [getimagesize warning](https://www.php.net/manual/en/function.getimagesize.php) — decoded dimensions and limitations.
- [OWASP ASVS](https://owasp.org/www-project-application-security-verification-standard/) and [OWASP ASVS 5 file-handling controls](https://cornucopia.owasp.org/taxonomy/asvs-5.0/05-file-handling/02-file-upload-and-content) — validation/security test categories.

### Secondary (MEDIUM confidence)

- None required; implementation recommendations are derived from the authoritative sources and inspected codebase.

### Tertiary (LOW confidence)

- None. All former planning assumptions are resolved in R-01 through R-07; execution-time drift is handled by fail-closed preflight rather than a guessed default.

## Metadata

**Confidence breakdown:**

- Standard stack: HIGH — existing locked/runtime dependencies and local versions were inspected; no new package is proposed.
- Architecture: HIGH for contract, barcode, Save, and media tier boundaries — derived from current code and authoritative GS1/OWASP/HTTPX/PHP guidance.
- Product-model destinations: HIGH — read-only production inventory resolved `products.brand` as the sole stageable userfield and confirmed no package-size or food-type destination; execution rechecks these closed facts before rendering target metadata.
- Pitfalls: HIGH — most are directly visible in current code/schema or are documented SSRF/file-handling threats.
- Validation architecture: HIGH — all three current test layers were executed locally and gaps are mapped one-to-one to ENR-01 through ENR-09.

**Research date:** 2026-08-13  
**Valid until:** 2026-09-12 for repository architecture; re-check live dependency versions and deployment data immediately before execution.
