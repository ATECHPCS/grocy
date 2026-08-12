# Phase 01: Safety Baseline & Mobile Diagnostics - Research

**Researched:** 2026-08-12
**Domain:** Mobile request lifecycle, privacy-safe distributed diagnostics, and brownfield enrichment hardening
**Confidence:** HIGH

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- **D-01:** Use the approved Phase 1 UI contract without reopening its visual, copy, state-machine, timing, accessibility, diagnostics, or degraded-path decisions.
- **D-02:** Preserve Grocy's Bootstrap 4/Roboto/Font Awesome design system and the current enrichment-card placement; do not introduce a frontend framework or a second persistence flow.
- **D-03:** Keep the 15-second browser deadline and initial internal timing budgets as the release baseline. Any re-baseline must come from a recorded physical-phone run with device/browser/network metadata and p50/p95 evidence.
- **D-04:** Diagnostics are request-scoped, collapsed by default, locally generated, redacted by allowlist, and never persisted as inventory data. They must exclude GTIN values/history, product data, credentials, headers, cookies, payloads, inventory contents, and image tokens.
- **D-05:** Do not automatically retry after reconnect or failure. The user receives a named state and explicitly chooses Retry or continues editing manually.
- **D-06:** Existing form values, image selections, and normal product Save controls remain usable across cancel, timeout, offline, provider error, partial results, and diagnostics actions.

### User Direction From Discussion
- **D-07:** The user does not want additional focus or product-level deliberation on client selection, diagnostic breadth, release-threshold policy, or network-scenario selection. These are planning/execution details to resolve pragmatically within the approved requirements and UI contract.
- **D-08:** Phase planning should proceed without blocking on a preferred phone model, browser brand, VPN path, or custom performance-policy choice. Use available household devices and the actual LAN topology to produce the required evidence.

### the agent's Discretion
- Choose the smallest test matrix that covers the supported responsive browser paths, current household phone, required viewport widths, and the UI-SPEC degraded scenarios.
- Decide the internal trace propagation and structured-log mechanism, provided the report contract, privacy exclusions, and browser/Grocy/companion/provider stage visibility are preserved.
- Decide how timing thresholds are encoded in automated checks and release output, provided the approved baseline is enforced and evidence is readable.
- Decide whether nearby same-request Grocy timings are included to localize failures; do not expand this phase into general application-wide monitoring.
- Choose deterministic provider fakes, network shaping, and live LAN smoke-test balance for automation. Live external providers must not make the core test suite flaky.

### Deferred Ideas (OUT OF SCOPE)

None — discussion stayed within phase scope.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| MOB-01 | User can start product enrichment from a phone by camera scan or manual GTIN entry and receives immediate length/checksum validation. | Use one pure GTIN-8/12/13/14 validator in the browser, mirror it at the PHP and companion boundaries, and connect the existing camera component's `Grocy.BarcodeScanned` event to the same path. [VERIFIED: `.planning/REQUIREMENTS.md`, codebase grep] |
| MOB-02 | User sees distinct invalid, not-found, timeout, offline, provider-error, and success states with bounded waits and an available cancel or retry action. | Implement the UI-SPEC state table as an explicit renderer, use a cancellable direct XHR with a 15-second deadline, and expose only explicit Cancel/Retry actions. [VERIFIED: `01-UI-SPEC.md`, codebase grep] |
| MOB-03 | User never receives or applies a stale response after changing the GTIN, navigating back, cancelling, or starting a newer request. | Guard every callback by an incrementing request token and normalized GTIN; invalidate before abort on edit, cancel, page hide, or replacement. [CITED: https://developer.mozilla.org/en-US/docs/Web/API/XMLHttpRequest/abort] |
| MOB-04 | Repeated taps, scans, or retries cannot cause duplicate requests to create duplicate visible results or duplicate persisted changes. | Coalesce the same active intent, replace different intents, render from one current response, and assert that enrichment never invokes Grocy Save. [VERIFIED: `01-UI-SPEC.md`, codebase grep] |
| MOB-05 | Operator can correlate browser, Grocy, companion, and provider stages using a request/trace identifier and privacy-safe timing data. | Propagate a strictly validated W3C `traceparent` only across owned browser/Grocy/companion boundaries; report coarse named stage timings without forwarding trace context to third-party providers. [CITED: https://www.w3.org/TR/trace-context/] |
| MOB-06 | User can copy a redacted diagnostic report containing versions, correlation ID, stage statuses, and timings without credentials, cookies, payload bodies, UPC history, or image tokens. | Build the report from a closed diagnostic DTO and a second browser-side allowlist serializer; test forbidden canaries at every boundary. [CITED: https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html] |
| MOB-07 | User can continue normal Grocy product and inventory workflows when the companion, SearXNG, Open Food Facts, or an image host is unavailable. | Keep enrichment zero-write, never disable normal fields/Save, return partial metadata when optional image work fails, and make each dependency failure deterministic in browser tests. [VERIFIED: `01-UI-SPEC.md`, codebase grep] |
| MOB-08 | Maintainer can verify explicit LAN/mobile latency budgets and failure behavior through automated mobile-browser coverage plus a physical-phone acceptance pass. | Add deterministic Chromium/WebKit mobile tests and a versioned physical-phone acceptance record containing device/browser/network metadata plus p50/p95 results. [VERIFIED: `01-UI-SPEC.md`] |
</phase_requirements>

## Summary

Phase 1 should be planned as a vertical reliability slice through **two repositories**: the Grocy fork owns validation feedback, request lifecycle, API error normalization, the copyable report, and the 15-second browser deadline; the separate `grocy-mcp` companion owns provider-stage classification and timing inside its shorter budget. The checked-out Grocy code currently has only length validation, uses the non-cancellable `Grocy.Api.Get` callback helper, exposes no trace or timing DTO, and has no browser test harness. The companion currently performs metadata and image work sequentially with per-call timeouts that can exceed the UI deadline and converts several provider failures into empty results. [VERIFIED: codebase grep in `public/custom/grocy_AI/product-enrichment.js`, `custom/grocy_AI/src/GrocyAiService.php`, and `/Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/`]

The smallest dependable design is an explicit browser state machine with one active request record, a direct cancellable XHR local to the module, mirrored GTIN checksum validation, finite error/outcome enums, and a closed diagnostic schema shared by Grocy and the companion. Generate a W3C trace ID in the browser, validate or replace it at trust boundaries, time only allowlisted stages, and never serialize an XHR, exception, header set, provider URL, or product payload into the report. [CITED: https://www.w3.org/TR/trace-context/] [CITED: https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html]

Validation should combine the existing deterministic PHP callable seam, the companion's standard-library `unittest` suite, and a new isolated Playwright dev harness that loads the real feature JavaScript/CSS against a synthetic product-form fixture. Core automation must fake network outcomes; a separate LAN/physical-phone release gate records actual household-device performance. This split makes the suite deterministic while still producing evidence for the deployed topology. [VERIFIED: `.planning/codebase/TESTING.md`, `01-UI-SPEC.md`] [CITED: https://playwright.dev/docs/network]

**Primary recommendation:** Implement one request-scoped contract—`intent token + validated trace context + finite outcome/stage DTO`—from browser through Grocy to companion, then prove it with deterministic two-engine mobile tests and one recorded physical-phone gate.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|--------------|----------------|-----------|
| Immediate GTIN feedback and camera/manual intent normalization | Browser / Client | API / Backend | Feedback must be synchronous on the phone; PHP and companion repeat validation because browser input is untrusted. [VERIFIED: `01-UI-SPEC.md`] |
| Cancel, timeout, coalescing, stale-response suppression | Browser / Client | API / Backend | Only the browser knows the current user intent; upstream deadlines must still release server/provider work. [VERIFIED: `01-UI-SPEC.md`] |
| Authentication, permission, error-envelope normalization | API / Backend (Grocy) | — | The existing controller owns `MASTER_DATA_EDIT` checks and HTTP mapping. [VERIFIED: codebase grep] |
| Companion and provider budget enforcement | API / Backend (companion) | API / Backend (Grocy) | The companion owns provider calls; Grocy owns the outer companion connect/total timeout. [VERIFIED: codebase grep] |
| Trace correlation and stage timing | Browser + Grocy + companion | Providers (measured only) | Each owned tier adds its own stage record; third-party services are measured but receive no local trace context. [CITED: https://www.w3.org/TR/trace-context/] |
| Redacted diagnostic presentation/copy | Browser / Client | Grocy + companion | Browser renders/copies a closed allowlist assembled from validated server DTOs. [VERIFIED: `01-UI-SPEC.md`] |
| Product persistence | Existing Grocy Save workflow | Database / Storage | Enrichment only stages suggestions and a selected normal file input; Phase 1 adds no write path. [VERIFIED: codebase grep, `01-CONTEXT.md`] |
| Automated mobile coverage | Test harness | Browser / Client | Playwright can emulate viewport/input/network behavior and intercept endpoints deterministically. [CITED: https://playwright.dev/docs/emulation] [CITED: https://playwright.dev/docs/network] |
| Physical latency evidence | Deployed LAN | Household phone | Only a real device and topology can establish release evidence for the locked baseline. [VERIFIED: `01-UI-SPEC.md`] |

## Project Constraints (from AGENTS.md)

- Keep project-specific behavior under `custom/grocy_AI/` and `public/custom/grocy_AI/`; minimize and document any upstream hook in `CUSTOMIZATIONS.md`. [VERIFIED: `AGENTS.md`]
- Preserve the tested stable release and persistent `/etc/komodo/grocy` data across image rebuilds. [VERIFIED: `AGENTS.md`]
- Treat external metadata and images as suggestions; require explicit user action before normal Grocy persistence. [VERIFIED: `AGENTS.md`]
- Never place secrets in Git, build URLs, copied diagnostics, or logs; preserve image-fetch bounds. [VERIFIED: `AGENTS.md`]
- Make mobile/LAN failures clear and bounded. [VERIFIED: `AGENTS.md`]
- Use tabs, Allman braces, single-quoted PHP/JavaScript strings where practical, semicolons, and an IIFE for isolated browser code; no formatter or linter is configured. [VERIFIED: `AGENTS.md`, codebase grep]
- Keep the `GrocyAI` namespace and explicit module `require_once` loading unless deliberately changing the module bootstrap. [VERIFIED: `AGENTS.md`, codebase grep]
- Validate untrusted data at service boundaries, map typed exceptions, restore UI state on every callback, and safely handle malformed error bodies. [VERIFIED: `AGENTS.md`, codebase grep]
- Never log configured secrets; test secret non-disclosure. [VERIFIED: `AGENTS.md`, codebase grep]
- Maintain the injected transport callable as the deterministic PHP test seam. [VERIFIED: `AGENTS.md`, codebase grep]
- Bump the custom production version marker when route or view integration changes so persisted caches invalidate; mirror portable changes into `atech-release`. [VERIFIED: `AGENTS.md`, codebase grep]

## Current Baseline and Planning Delta

| Area | Current behavior | Phase 1 delta |
|------|------------------|---------------|
| Browser validation | Accepts only an 8/12/13/14 digit length regex; no check-digit calculation. [VERIFIED: codebase grep] | Add the GS1 modulo-10 check-digit algorithm and expose distinct invalid-length/checksum states. [CITED: https://www.gs1.org/services/how-calculate-check-digit-manually] |
| Browser request control | Uses `Grocy.Api.Get`, which does not return its XHR; the feature has no timeout, abort, active-token, or coalescing mechanism. [VERIFIED: codebase grep] | Use a phase-local direct XHR helper with `timeout = 15000`, `abort()`, and one current intent record. [CITED: https://developer.mozilla.org/en-US/docs/Web/API/XMLHttpRequest/abort] |
| Error states | Uses a generic feature error and may display a server-provided message. [VERIFIED: codebase grep] | Map a finite response enum to the exact UI-SPEC copy; never display raw exception text. [VERIFIED: `01-UI-SPEC.md`] |
| Diagnostics | No request identifier, stage timing, structured log, or copy report exists. [VERIFIED: codebase grep] | Add a closed versioned DTO and collapsed report with clipboard fallback. [VERIFIED: `01-UI-SPEC.md`] |
| Grocy timeout | `GrocyAiService` defaults to 20 seconds and clamps 1–60; connect timeout is at most 5 seconds. [VERIFIED: codebase grep] | Enforce the locked 12-second total and 2-second connect baseline regardless of a larger legacy setting. [VERIFIED: `01-UI-SPEC.md`] |
| Companion lookup | Federation and Open Food Facts run concurrently, but provider helpers commonly reduce failures to empty results; image search follows as a separate step with independent timeouts. [VERIFIED: codebase grep in `grocy-mcp`] | Retain provider outcomes, enforce one outer budget, and distinguish true not-found, provider error, and metadata-without-image partial success. |
| Tests | Grocy has 21 native PHP checks and no HTTP/browser suite; companion has deterministic `unittest` coverage. [VERIFIED: test execution and codebase grep] | Extend both contract suites and add an isolated two-engine Playwright harness. |
| Deployment parity | Portable module assets are shared conceptually, while controller/routes/config differ between `atech-main` and `atech-release`; stable has a custom production version marker. [VERIFIED: git diff/codebase grep] | Plan explicit main/stable adaptation, cache-marker bump, and parity verification. |

## Standard Stack

### Core

| Library / API | Version | Purpose | Why Standard |
|---------------|---------|---------|--------------|
| Existing plain JavaScript + jQuery | jQuery 3.7.1 | State rendering and DOM integration | Already loaded by Grocy; the locked contract forbids a new frontend framework. [VERIFIED: `yarn.lock`, `01-CONTEXT.md`] |
| `XMLHttpRequest` | Browser built-in | Abortable same-origin enrichment and selected-image requests | It matches the existing application and provides standard `timeout`, `abort`, and lifecycle events without a production dependency. [CITED: https://developer.mozilla.org/en-US/docs/Web/API/XMLHttpRequest/abort] |
| Guzzle | 7.15.1 | Grocy-to-companion transport and transfer timing | Already locked by Grocy; `timeout`, `connect_timeout`, and `on_stats` cover the required outer budget and coarse timing. [VERIFIED: `composer.lock`] [CITED: https://docs.guzzlephp.org/en/stable/request-options.html] |
| HTTPX | 0.28.1 in companion venv | Companion-to-provider transport with fine-grained budgets | Already used by the companion and supports connect/read/write/pool timeout configuration. [VERIFIED: companion environment/codebase grep] [CITED: https://www.python-httpx.org/advanced/timeouts/] |
| `asyncio.timeout` | Python 3.13.13 runtime | Enforce an outer companion request budget | It bounds the complete async block rather than resetting the deadline per provider call. [VERIFIED: companion environment] [CITED: https://docs.python.org/3.13/library/asyncio-task.html#asyncio.timeout] |
| Existing native PHP harness | PHP 8.5.9 available | Grocy service/DTO/timeout contract tests | It is the established zero-dependency module test seam. [VERIFIED: test execution and `.planning/codebase/TESTING.md`] |
| Python `unittest` | Python 3.13.13 available | Companion provider/outcome/diagnostic contract tests | It is already used by the companion and adds no package. [VERIFIED: companion test execution/codebase grep] |

### Supporting

| Library / API | Version | Purpose | When to Use |
|---------------|---------|---------|-------------|
| `@playwright/test` [ASSUMED] | 1.62.1 registry version checked 2026-08-12 | Deterministic Chromium/WebKit mobile behavior, interception, offline mode, and timer control | Test browser state, concurrency, privacy, responsive layout, and accessibility semantics in an isolated dev-only package. The name is official, but slopcheck was unavailable, so the planner must add `checkpoint:human-verify` before install. [CITED: https://playwright.dev/docs/intro] |
| Web Crypto `getRandomValues` | Browser built-in | Generate trace and parent IDs | Use for browser trace context; never use `Math.random`. [CITED: https://developer.mozilla.org/en-US/docs/Web/API/Crypto/getRandomValues] |
| PHP `random_bytes` | PHP 8.5 built-in | Replace invalid/missing trace IDs at the Grocy boundary | Use for cryptographically secure 16-byte trace IDs and 8-byte parent IDs. [CITED: https://www.php.net/manual/en/function.random-bytes.php] |
| W3C `traceparent` | Trace Context Recommendation | Cross-component correlation between owned services | Use only the strict v00 header across browser, Grocy, and companion; omit `tracestate` and do not forward to providers. [CITED: https://www.w3.org/TR/trace-context/] |
| `Server-Timing` | Web standard | Expose coarse same-request timing to browser/devtools | Use as a supplementary view, not as the diagnostic source of truth. [CITED: https://www.w3.org/TR/server-timing/] |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Phase-local XHR | Modify shared `Grocy.Api.Get` | A core-helper change would widen regression scope across Grocy; Phase 1 needs cancellation only in the custom module. [VERIFIED: codebase grep] |
| Closed diagnostic DTO | OpenTelemetry SDK | An SDK and exporter would expand a local, request-scoped requirement into a telemetry platform and increase privacy/configuration surface. [VERIFIED: `01-CONTEXT.md`] |
| Playwright route fakes | Live provider E2E | Live providers cannot deterministically reproduce timeout/offline/partial paths and would make the core suite flaky. [VERIFIED: `01-CONTEXT.md`] |
| Browser + service contract tests | Full Grocy application bootstrap | This checkout lacks installed Composer assets and an existing HTTP integration harness; add only a stable-image smoke gate for true integration. [VERIFIED: environment audit/codebase grep] |

**Installation (only after the required human package checkpoint):**

```bash
npm --prefix custom/grocy_AI/tests/browser install --save-dev @playwright/test@1.62.1
npx --prefix custom/grocy_AI/tests/browser playwright install chromium webkit
```

Keep this nested dev harness outside Grocy's root production Yarn package tree. [VERIFIED: `.yarnrc`, `package.json`] The official installation docs use `@playwright/test`, but because slopcheck could not run, the package remains `[ASSUMED]` for planning purposes. [CITED: https://playwright.dev/docs/intro]

## Package Legitimacy Audit

The Package Legitimacy Gate was attempted. `slopcheck` could not be installed or executed, so the protocol requires every proposed external package to remain `[ASSUMED]` and every install to be gated by a human verification checkpoint. [VERIFIED: local command execution]

| Package | Registry | Age | Downloads | Source Repo | slopcheck | Disposition |
|---------|----------|-----|-----------|-------------|-----------|-------------|
| `@playwright/test` [ASSUMED] | npm | Created 2020-09-24 | Not accepted as proof because slopcheck is unavailable | `github.com/microsoft/playwright` | Unavailable | Flagged — planner must add `checkpoint:human-verify`; registry version 1.62.1 and no package `postinstall` value were observed. [VERIFIED: npm registry] [CITED: https://playwright.dev/docs/intro] |

**Packages removed due to slopcheck [SLOP] verdict:** none; no verdict was available.

**Packages flagged as suspicious [SUS]:** none received a SUS verdict. `@playwright/test` is nevertheless `[ASSUMED]` because the gate could not complete.

## Architecture Patterns

### System Architecture Diagram

```text
Manual GTIN / existing camera scan
              |
              v
Browser validation -- invalid --> named local state (no request)
              |
              v valid
Current-intent gate -- same active intent --> coalesce (no second request)
              |
              v new intent
15 s XHR + browser traceparent
              |
              v
Grocy route: auth/permission -> trace validation -> 12 s/2 s Guzzle budget
              |                                      |
              | named Grocy failure                  v
              |                              Companion contract
              |                                      |
              |                         outer budget + stage timers
              |                              /               \
              |                             v                 v
              |                      metadata providers   image provider/host
              |                         (no trace header leaves owned boundary)
              |                              \               /
              |                               v             v
              +---------------------- finite outcome + allowlisted diagnostic DTO
                                                     |
                                                     v
                         Current-intent + GTIN guard -- stale --> ignore
                                                     |
                                                     v current
                           exact UI state + optional suggestions + collapsed report
                                                     |
                                  explicit user choice only; normal Grocy Save persists
```

The browser, Grocy, and companion are owned trace participants; external providers are timed dependencies, not trace participants, to prevent leaking a household correlation identifier. [CITED: https://www.w3.org/TR/trace-context/]

### Recommended Project Structure

```text
grocy/
├── custom/grocy_AI/
│   ├── module-version.json                 # module/diagnostic contract version
│   ├── src/
│   │   ├── GrocyAiDiagnostic.php           # closed DTO, trace validation, redaction
│   │   ├── GrocyAiService.php              # 12 s/2 s outer transport budget
│   │   └── GrocyAiApiController.php        # permission + finite HTTP envelope
│   └── tests/
│       ├── run.php                         # existing and new PHP contract checks
│       └── browser/
│           ├── package.json
│           ├── package-lock.json
│           ├── playwright.config.js
│           ├── fixtures/productform.html
│           ├── support/server.mjs
│           └── specs/*.spec.js
├── public/custom/grocy_AI/
│   ├── product-enrichment.js               # state machine/request/report behavior
│   └── grocy-ai.css                        # contract styling
├── views/productform.blade.php              # minimal documented module hook
└── .planning/phases/01-safety-baseline-mobile-diagnostics/
    ├── 01-PHONE-ACCEPTANCE.md
    └── evidence/phone-timings.jsonl

grocy-mcp/
├── grocy_mcp/
│   ├── enrichment.py                       # outer deadline + aggregate outcome
│   ├── lookup.py                           # per-provider status/timing
│   ├── images.py                           # optional image status/timing
│   └── diagnostics.py                      # trace/DTO helpers
└── tests/
    ├── test_enrichment.py
    ├── test_http_api.py
    └── test_diagnostics.py
```

This layout keeps Grocy-specific behavior within the existing module and acknowledges that provider instrumentation is owned by a separate repository. [VERIFIED: codebase grep]

### Pattern 1: One Current Intent, Separate from Trace Identity

**What:** Store one `activeRequest` with a monotonic sequence, normalized GTIN, XHR, reason, start time, and trace ID. The sequence decides UI ownership; the trace ID correlates diagnostics. Never use a trace ID as the stale-response guard.

**When to use:** Every search, scan, explicit retry, cancellation, input edit, visibility change, page navigation, and callback.

```javascript
// Source: project pattern derived from the UI contract and XHR abort semantics.
var requestSequence = 0;
var activeRequest = null;

function invalidateActiveRequest(reason)
{
	requestSequence++;
	if (activeRequest !== null)
	{
		activeRequest.reason = reason;
		activeRequest.xhr.abort();
		activeRequest = null;
	}
}

function isCurrent(request)
{
	return activeRequest === request
		&& request.sequence === requestSequence
		&& normalizeGtin(gtinInput.value) === request.gtin;
}
```

Invalidate before abort so a synchronous/queued abort callback cannot render obsolete state. `XMLHttpRequest.abort()` transitions the request and dispatches abort-related events. [CITED: https://developer.mozilla.org/en-US/docs/Web/API/XMLHttpRequest/abort]

### Pattern 2: Layered Deadlines, Not Additive Per-Call Timeouts

**What:** Enforce one browser deadline (15 s), one Grocy-to-companion total/connect budget (12 s/2 s), and one companion outer provider-work deadline (no more than about 10–11 s) containing individual HTTPX connect/read limits (2 s and 5–6 s). The companion must compute remaining time before later optional stages. [VERIFIED: `01-UI-SPEC.md`] [CITED: https://docs.guzzlephp.org/en/stable/request-options.html] [CITED: https://www.python-httpx.org/advanced/timeouts/]

**When to use:** All metadata and image provider paths. Do not give every sequential stage the full overall timeout; the existing sequence can otherwise exceed the browser budget. [VERIFIED: companion codebase grep]

### Pattern 3: Closed Diagnostic Contract

**What:** Create the diagnostic object from allowed primitives only. Suggested v1 shape:

```json
{
  "schema_version": 1,
  "generated_at": "2026-08-12T12:00:00Z",
  "versions": {"grocy": "4.6.0", "module": "...", "companion": "...", "contract": "1"},
  "trace_id": "32-lowercase-hex-characters",
  "outcome": "timeout",
  "online_state": "online|offline|unknown|cancelled",
  "stages": [
    {"name": "companion", "status": "timeout", "error_code": "deadline", "cache": "unknown", "duration_ms": 12001}
  ],
  "overall_duration_ms": 15000,
  "browser_deadline_reached": true
}
```

Allowed stage names and field enums must be constants. The browser copy function reconstructs this shape field-by-field and never calls `JSON.stringify` on the raw response, XHR, exception, or provider object. That is the principal privacy boundary, not a regex scrubber. [VERIFIED: `01-UI-SPEC.md`] [CITED: https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html]

### Pattern 4: Strict Owned-Boundary Trace Propagation

**What:** The browser generates a valid W3C v00 `traceparent`; Grocy and the companion strictly parse it, reject all-zero IDs/invalid formats, create a new parent ID, and replace invalid input. Do not accept/emit `tracestate`, and do not forward either header to Open Food Facts, SearXNG, Federation, or image hosts. [CITED: https://www.w3.org/TR/trace-context/]

**When to use:** Browser → Grocy → companion calls only. Provider stages attach timing to the owned request's trace ID locally.

### Pattern 5: Partial Is a Success Variant

**What:** A useful structured metadata response remains visible when optional image search/load fails. Return a finite `partial_image` outcome and a failed/skipped image stage; preserve the current form, suggestions, and selected image. [VERIFIED: `01-UI-SPEC.md`]

**When to use:** Metadata is usable but SearXNG, an image host, or selected-image proxy fails. A true not-found means every applicable metadata provider completed without a match; provider errors/timeouts must not masquerade as not-found.

### Anti-Patterns to Avoid

- **Using `navigator.onLine` as proof of reachability:** Browsers can report network connectivity while the companion/LAN path is unreachable; use it only as a hint and also classify XHR failures. [CITED: https://developer.mozilla.org/en-US/docs/Web/API/Navigator/onLine]
- **Rendering from every callback:** Abort, timeout, success, and late error callbacks race; require the current-token/current-GTIN guard in all of them. [CITED: https://developer.mozilla.org/en-US/docs/Web/API/XMLHttpRequest/abort]
- **Automatically searching on reconnect or `pageshow`:** This violates explicit retry and can resurrect an obsolete intent. [VERIFIED: `01-CONTEXT.md`, `01-UI-SPEC.md`]
- **Treating provider failure as empty/not-found:** It destroys the distinction required by MOB-02 and makes diagnostics misleading. [VERIFIED: companion codebase grep, `.planning/REQUIREMENTS.md`]
- **Regex-redacting an arbitrary log object:** Unknown future keys can bypass the regex; construct from a constant allowlist and include negative canary tests. [CITED: https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html]
- **Forwarding household trace context to external providers:** Correlation headers can reveal or amplify tracking; terminate local propagation at the companion. [CITED: https://www.w3.org/TR/trace-context/]
- **Disabling Grocy's Save controls during enrichment:** The enrichment request is optional and must not block the existing workflow. [VERIFIED: `01-UI-SPEC.md`]
- **Adding retries/circuit breakers:** Phase 1 requires explicit retry; circuit-breaker policy is beyond the request-scoped MVP. [VERIFIED: `01-CONTEXT.md`]

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Browser engine automation | A DOM-only fake test runner | `@playwright/test` [ASSUMED], after human verification | Real Chromium/WebKit behavior is needed for focus, lifecycle, layout, network, and abort semantics. [CITED: https://playwright.dev/docs/intro] |
| Network scenario server | A large mock-service framework | Playwright `page.route` and the Node built-in HTTP server | Route interception can fulfill, delay, or abort requests without another dependency. [CITED: https://playwright.dev/docs/network] |
| Timer waiting | Real 15-second sleeps in each test | Playwright Clock | Clock installation and `fastForward` make deadline behavior deterministic. [CITED: https://playwright.dev/docs/clock] |
| Cross-service correlation format | A proprietary header | Strict W3C `traceparent` | It defines interoperable trace/parent IDs, validation, and privacy considerations. [CITED: https://www.w3.org/TR/trace-context/] |
| Secure random IDs | `Math.random`, timestamps, or PHP `uniqid` | `crypto.getRandomValues` and `random_bytes` | Both provide cryptographically strong randomness from built-in APIs. [CITED: https://developer.mozilla.org/en-US/docs/Web/API/Crypto/getRandomValues] [CITED: https://www.php.net/manual/en/function.random-bytes.php] |
| GTIN check digit | An ad-hoc per-length checksum | One GS1 modulo-10 implementation with shared vectors | The same alternating-weight algorithm covers the accepted GTIN lengths. [CITED: https://www.gs1.org/services/how-calculate-check-digit-manually] |
| Timeout bookkeeping | Independent full timeout for every provider step | HTTPX fine-grained timeouts inside `asyncio.timeout` | An outer deadline bounds the entire sequence. [CITED: https://www.python-httpx.org/advanced/timeouts/] [CITED: https://docs.python.org/3.13/library/asyncio-task.html#asyncio.timeout] |

**Key insight:** The difficult part is not issuing an HTTP call; it is preserving one user intent and one privacy-safe diagnostic contract across races, deadlines, and independent failure domains.

## Common Pitfalls

### Pitfall 1: Abort Callback Overwrites the Replacement Request

**What goes wrong:** Cancelling request A triggers a callback that renders “cancelled” after request B has already entered “searching.”

**Why it happens:** Aborting does not erase queued event handlers, and UI ownership is inferred from “there is an XHR” rather than a monotonic intent token. [CITED: https://developer.mozilla.org/en-US/docs/Web/API/XMLHttpRequest/abort]

**How to avoid:** Increment/invalidate first, abort second, and guard every callback with both request identity and normalized GTIN.

**Warning signs:** Flickering state, a spinner disappearing during a newer request, or a result whose trace suffix differs from the active request.

### Pitfall 2: Internal Budgets Add Past 15 Seconds

**What goes wrong:** Each provider receives a full timeout and sequential metadata/image work exceeds the browser deadline; the phone reports timeout while servers keep working.

**Why it happens:** Per-operation timeout settings are mistaken for an end-to-end deadline. The current companion performs image work after lookup. [VERIFIED: companion codebase grep]

**How to avoid:** Wrap the whole companion operation in an outer deadline, use remaining-budget calculations, and skip/partial optional work when insufficient time remains.

**Warning signs:** Browser timeout at 15 seconds while companion logs a later success; sum of stage durations exceeds the reported overall duration.

### Pitfall 3: Not-Found Masks Provider Failure

**What goes wrong:** A timeout or exception becomes an empty provider result and the UI incorrectly says no match exists.

**Why it happens:** Current provider helpers intentionally swallow several failures into empty values. [VERIFIED: companion codebase grep]

**How to avoid:** Return a typed provider result (`ok`, `not_found`, `timeout`, `unavailable`, `error`, `skipped`) and aggregate it into the finite overall outcome.

**Warning signs:** “Not found” with a failed provider stage, or an empty response with no completed provider stage.

### Pitfall 4: Diagnostics Leak Through an Unexpected Field

**What goes wrong:** A copied report includes the GTIN in a URL, raw exception, payload fragment, header, or image token even though obvious fields were deleted.

**Why it happens:** Redaction is applied to an open object after collection.

**How to avoid:** Never collect forbidden data into the DTO; serialize only known keys/enums and run canary strings through PHP, companion, and browser copy tests. OWASP explicitly identifies credentials, tokens, session identifiers, keys, and sensitive personal data as data to exclude or mask in logs. [CITED: https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html]

**Warning signs:** Generic `JSON.stringify(response)`, exception messages in the UI, arbitrary headers, or provider URLs in stage details.

### Pitfall 5: Mobile Page Lifecycle Leaves a Stuck UI

**What goes wrong:** Backgrounding, navigating back, or page-cache restoration leaves the card busy, a modal open, or an obsolete request capable of rendering.

**Why it happens:** Mobile browsers suspend pages and `visibilitychange` to hidden is often the last reliably observable event. [CITED: https://developer.mozilla.org/en-US/docs/Web/API/Document/visibilitychange_event]

**How to avoid:** Invalidate/abort on `visibilitychange` hidden and `pagehide`; on `pageshow`, restore an idle/validated state without retrying. Ensure camera and enrichment busy state are separately restored.

**Warning signs:** Disabled controls after returning to the tab, two results after back/forward, or an old trace appearing after resume.

### Pitfall 6: Production Cache Keeps Old Route/View Integration

**What goes wrong:** Source files are deployed but stable Grocy continues using cached view/route integration.

**Why it happens:** Grocy's cache key uses the production version marker; stable carries an additional custom marker. [VERIFIED: codebase grep]

**How to avoid:** Make the stable marker bump and route/view smoke test explicit plan tasks whenever those integration files change.

**Warning signs:** Direct asset loads show new code while rendered markup or route behavior remains old.

### Pitfall 7: Image Failure Accidentally Clears Existing Form State

**What goes wrong:** A failed candidate load or selected-image proxy replaces/clears the user's current file selection or metadata result.

**Why it happens:** “Busy reset” logic resets the entire card/form instead of only the image action.

**How to avoid:** Keep the normal file input authoritative, change it only after a validated successful blob, and test preservation with a preselected synthetic file and edited form fields.

**Warning signs:** File name disappears, normal Save becomes disabled, or metadata suggestions vanish after an image-host error.

## Code Examples

Verified patterns from official sources, adapted to this phase:

### Fine-Grained Companion Timeout Inside One Outer Deadline

```python
# Sources:
# https://www.python-httpx.org/advanced/timeouts/
# https://docs.python.org/3.13/library/asyncio-task.html#asyncio.timeout
timeout = httpx.Timeout(6.0, connect=2.0)

async with asyncio.timeout(10.5):
    async with httpx.AsyncClient(timeout=timeout, follow_redirects=False) as client:
        result = await lookup_metadata(client, gtin, diagnostics)
```

Use one client per request where practical, keep redirects disabled unless an existing bounded image policy explicitly handles them, and translate timeout/error categories before returning to Grocy. [CITED: https://www.python-httpx.org/advanced/timeouts/]

### Guzzle Transfer Timing Without Logging the Request

```php
// Source: https://docs.guzzlephp.org/en/stable/request-options.html
$transferMs = null;
$response = $client->request('GET', $url, [
	'connect_timeout' => 2.0,
	'timeout' => 12.0,
	'allow_redirects' => false,
	'on_stats' => function (\GuzzleHttp\TransferStats $stats) use (&$transferMs): void
	{
		$transferMs = (int) round($stats->getTransferTime() * 1000);
	}
]);
```

`getHandlerStats()` is handler-dependent; treat connect timing as nullable rather than inventing a number when the active handler does not expose it. [CITED: https://docs.guzzlephp.org/en/stable/request-options.html]

### Deterministic Browser Failure

```javascript
// Source: https://playwright.dev/docs/network
await page.route('**/api/objects/grocy-ai/enrich/**', async function(route)
{
	await route.fulfill({
		status: 504,
		contentType: 'application/json',
		body: JSON.stringify({ outcome: 'timeout', diagnostics: timeoutDiagnostic })
	});
});
```

Use a synthetic forbidden-data canary in the raw fixture response and assert it never appears in the visible or copied report. [CITED: https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html]

### Offline Is a Hint Plus an Observed Transport Outcome

```javascript
// Source: https://playwright.dev/docs/api/class-browsercontext#browser-context-set-offline
await context.setOffline(true);
await page.getByRole('button', { name: 'Search' }).click();
await expect(page.getByRole('alert')).toContainText('offline');
```

Do not assert that an `online` event triggers a request; assert that Retry remains explicit. [VERIFIED: `01-CONTEXT.md`]

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Callback-only browser helper without an abort handle | Abortable request plus current-intent guard | Phase 1 design | Prevents stale results and supports explicit cancellation. [CITED: https://developer.mozilla.org/en-US/docs/Web/API/XMLHttpRequest/abort] |
| One scalar timeout per HTTP client | Layered end-to-end deadline plus connect/read limits | Supported by current Guzzle/HTTPX/asyncio APIs | Keeps the server path inside the UI deadline. [CITED: https://docs.guzzlephp.org/en/stable/request-options.html] [CITED: https://www.python-httpx.org/advanced/timeouts/] |
| Raw/debug object copying | Versioned allowlist diagnostic DTO | Phase 1 privacy contract | Makes forbidden-data absence testable. [VERIFIED: `01-UI-SPEC.md`] |
| Live-provider browser testing | Deterministic route interception plus separate physical acceptance | Supported by current Playwright APIs | Covers failures without flaky core automation. [CITED: https://playwright.dev/docs/network] |
| Generic “failed/not found” | Finite provider and overall outcomes | Phase 1 requirement | Makes recovery actionable and diagnostics localizable. [VERIFIED: `.planning/REQUIREMENTS.md`] |

**Deprecated/outdated for this phase:**

- The current feature use of fire-and-forget `Grocy.Api.Get` is insufficient for MOB-02/MOB-03 because the caller cannot retain/abort the XHR. [VERIFIED: codebase grep]
- The current 20-second Grocy default and up-to-5-second connect budget conflict with the approved 12-second/2-second internal baseline. [VERIFIED: codebase grep, `01-UI-SPEC.md`]
- Treating empty companion data as enough to claim not-found is incompatible with distinct provider-error and timeout states. [VERIFIED: companion codebase grep, `.planning/REQUIREMENTS.md`]

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `@playwright/test` 1.62.1 is safe to install after human verification; slopcheck could not establish legitimacy. | Standard Stack / Package Audit | A compromised or inappropriate dev dependency could be installed; planner must gate before install. |
| A2 | Twenty successful physical samples per performance path are the smallest useful local p50/p95 baseline. | Validation Architecture | A stricter organizational sampling policy could require more runs; no such policy exists in project decisions. |
| A3 | A new `custom/grocy_AI/module-version.json` is the smallest durable source for the module version in copied diagnostics. | Architecture Patterns | The implementer may find an existing stable marker can safely serve both branches; the report still needs a consistent value. |

## Open Questions

1. **When will the deployed LAN be reachable for the physical gate?**
   - What we know: Research-time probes to the documented Grocy, companion, and SearXNG LAN endpoints could not connect. [VERIFIED: local network probes]
   - What's unclear: Whether this Mac was off the household LAN/VPN or the services were temporarily down.
   - Recommendation: Do not block implementation; make restored LAN access a named prerequisite of the final physical-phone acceptance task.

2. **Which existing version value should be authoritative for the module?**
   - What we know: Grocy exposes `version.json`; `atech-release` has a custom production marker, while the portable module on `atech-main` lacks a dedicated manifest. [VERIFIED: codebase grep]
   - What's unclear: Whether stable's cache marker is intended to be a public module semantic version.
   - Recommendation: Add a portable module manifest used by both branches, and continue bumping the stable marker independently for cache invalidation. [ASSUMED]

## Environment Availability

| Dependency | Required By | Available | Version / Evidence | Fallback |
|------------|-------------|-----------|--------------------|----------|
| PHP | Grocy contract/syntax tests | ✓ | 8.5.9; 21 module checks passed. [VERIFIED: local execution] | — |
| Node.js | Browser harness/server | ✓ | v25.9.0. [VERIFIED: local execution] | — |
| npm | Browser dev dependency | ✓ | 11.12.1. [VERIFIED: local execution] | — |
| Python companion venv | Companion tests | ✓ | Python 3.13.13; targeted 13 tests passed. [VERIFIED: local execution] | — |
| HTTPX / Starlette | Companion implementation/tests | ✓ | HTTPX 0.28.1; Starlette 1.6.0. [VERIFIED: companion environment] | — |
| Composer dependencies | Full local Grocy HTTP bootstrap | ✗ | `composer` and `packages/autoload.php` absent. [VERIFIED: local execution/codebase grep] | Use native PHP contract suite; use deployed stable image for integration smoke. |
| Yarn dependencies | Full local Grocy frontend | ✗ | `yarn` absent and runtime assets not installed. [VERIFIED: local execution] | Synthetic browser fixture loads only phase-owned assets; deployed image smoke covers integration. |
| Docker CLI | Local production-image smoke | ✗ | Command absent. [VERIFIED: local execution] | Execute image gate on the established build/deploy host. |
| Playwright package/browser cache | Automated mobile coverage | ✗ | Package/cache not installed. [VERIFIED: local execution] | Wave 0 install after human verification; no equivalent built-in browser harness. |
| Household Grocy LAN endpoint | Physical acceptance | ✗ at research time | Documented endpoint did not connect. [VERIFIED: local network probe] | Restore LAN/VPN before phase gate. |
| Companion `10.10.0.156:3061` | Physical acceptance | ✗ at research time | Health probe did not connect. [VERIFIED: local network probe] | Deterministic fakes for implementation; restore LAN/VPN before phase gate. |
| SearXNG LAN endpoint | Live degraded-path smoke | ✗ at research time | Documented endpoint did not connect. [VERIFIED: local network probe] | Deterministic provider fake for core suite; live scenario at final gate. |

**Missing dependencies with no fallback:** Playwright and its Chromium/WebKit browsers are required for MOB-08 automation after the legitimacy checkpoint; deployed LAN access is required for final physical acceptance.

**Missing dependencies with fallback:** Local Composer/Yarn/Docker and live providers are not required for deterministic implementation tests; use native suites, a synthetic browser fixture, and the existing build/deploy host. [VERIFIED: codebase/environment audit]

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Grocy service framework | Existing native PHP CLI harness; no external package. [VERIFIED: `.planning/codebase/TESTING.md`] |
| Companion framework | Python standard-library `unittest`. [VERIFIED: companion codebase grep] |
| Browser framework | `@playwright/test` 1.62.1 `[ASSUMED]`, gated by human verification. [CITED: https://playwright.dev/docs/intro] |
| Browser config | `custom/grocy_AI/tests/browser/playwright.config.js` — ❌ Wave 0 |
| Quick run command | `npm --prefix custom/grocy_AI/tests/browser run test:smoke` |
| Full suite command | `php custom/grocy_AI/tests/run.php && (cd ../grocy-mcp && .venv/bin/python -m unittest tests.test_enrichment tests.test_http_api tests.test_diagnostics) && npm --prefix custom/grocy_AI/tests/browser test` |

### Browser Matrix and Fixture Strategy

- Configure exactly two Playwright projects: `chromium-mobile` and `webkit-mobile`. Run all functional tests at a 390 px phone viewport in both engines. [CITED: https://playwright.dev/docs/emulation]
- Loop layout/accessibility assertions at 320, 375, 390, and 768 px in both projects, matching the approved contract without adding Firefox or a broad device farm. [VERIFIED: `01-UI-SPEC.md`]
- Serve a synthetic product-form page from a Node built-in HTTP server, but load the **actual** `product-enrichment.js` and `grocy-ai.css`; include representative existing form fields, file input, Save controls, and camera event target. [VERIFIED: codebase grep]
- Intercept the enrichment/image endpoints with Playwright `page.route` for success, delay, malformed body, not-found, provider error, partial image, connection abort, and duplicate-request counting. [CITED: https://playwright.dev/docs/network]
- Use Playwright Clock to advance the 15-second deadline rather than sleeping, and `browserContext.setOffline(true)` for the offline transport path. [CITED: https://playwright.dev/docs/clock] [CITED: https://playwright.dev/docs/api/class-browsercontext#browser-context-set-offline]
- Keep one small deployed-LAN smoke outside the deterministic core; provider uptime must never decide the ordinary test result. [VERIFIED: `01-CONTEXT.md`]

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| MOB-01 | Manual and `Grocy.BarcodeScanned` inputs share GTIN-8/12/13/14 length/checksum validation; leading zeros survive; valid scan starts exactly once after the specified delay. | PHP/Python unit + browser | `php custom/grocy_AI/tests/run.php && npm --prefix custom/grocy_AI/tests/browser test -- --grep @mob01` | PHP file ✅; browser specs ❌ Wave 0 |
| MOB-02 | Exact invalid/not-found/timeout/offline/provider-error/partial/success states, 15 s deadline, Cancel and explicit Retry, no reconnect retry. | Browser | `npm --prefix custom/grocy_AI/tests/browser test -- --grep @mob02` | ❌ Wave 0 `specs/states.spec.js` |
| MOB-03 | Input edit, cancel, replacement scan, hidden/pagehide/back, and late callbacks cannot render/apply stale results. | Browser | `npm --prefix custom/grocy_AI/tests/browser test -- --grep @mob03` | ❌ Wave 0 `specs/concurrency.spec.js` |
| MOB-04 | Same active intent coalesces; repeated taps/scans create one request/one result; enrichment never invokes normal Save or persistence endpoints. | Browser request-count/negative integration | `npm --prefix custom/grocy_AI/tests/browser test -- --grep @mob04` | ❌ Wave 0 `specs/concurrency.spec.js`, `specs/preservation.spec.js` |
| MOB-05 | Valid/invalid trace propagation, replacement, one trace across owned stages, finite stage timings, no trace headers to providers. | PHP + companion contract + browser | `php custom/grocy_AI/tests/run.php && (cd ../grocy-mcp && .venv/bin/python -m unittest tests.test_diagnostics) && npm --prefix custom/grocy_AI/tests/browser test -- --grep @mob05` | PHP extend ✅; companion/browser ❌ Wave 0 |
| MOB-06 | Copy report includes only required versions/trace/outcome/stages/timings; raw GTIN, canary secrets, cookies, URLs, payloads, tokens, headers, and exceptions are absent; textarea fallback works. | Contract + browser privacy | `npm --prefix custom/grocy_AI/tests/browser test -- --grep @mob06` plus PHP/companion suites | ❌ Wave 0 `specs/diagnostics.spec.js`, companion test |
| MOB-07 | Existing typed fields, selected file, and normal Save stay usable during companion/OFF/SearXNG/image-host failure and partial response. | Browser degraded-path | `npm --prefix custom/grocy_AI/tests/browser test -- --grep @mob07` | ❌ Wave 0 `specs/preservation.spec.js` |
| MOB-08 | No horizontal scroll; 44 px targets; ARIA live/busy/error behavior; reduced motion; Chromium/WebKit matrix; automated budgets; release evidence fields. | Browser + physical acceptance | `npm --prefix custom/grocy_AI/tests/browser test -- --grep @mob08` | ❌ Wave 0 `specs/responsive-a11y.spec.js`; physical template ❌ |

### Required Behavior-Level Assertions

1. **Validation vectors:** include valid and invalid examples for all accepted GTIN lengths, leading-zero strings, non-digits, and a digit edit that changes only the checksum result. Mirror vectors in JavaScript, PHP, and Python to prevent drift. [CITED: https://www.gs1.org/services/how-calculate-check-digit-manually]
2. **Immediate response:** after an input/scan event, assert the ready/invalid UI and `aria-*` state within 250 ms without network activity. [VERIFIED: `01-UI-SPEC.md`]
3. **Concurrency:** hold request A, start B, resolve B, then resolve A; only B may render. Repeat for input edit, explicit cancel, `visibilitychange`, `pagehide`, and history return. [VERIFIED: `01-UI-SPEC.md`]
4. **Coalescing:** ten same-GTIN taps/scans while active produce exactly one route request. After terminal failure, one explicit Retry produces exactly one new request/trace. [VERIFIED: `01-UI-SPEC.md`]
5. **Zero write:** register route counters for product/barcode/stock/save endpoints and assert zero calls through every search/cancel/error/diagnostics case. [VERIFIED: `01-CONTEXT.md`]
6. **Privacy canary:** place a synthetic GTIN, API key, cookie, bearer token, query URL, image token, raw payload marker, and exception text in the intercepted raw response; assert none appears in DOM, clipboard/fallback, `console`, or structured terminal log. [CITED: https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html]
7. **Preservation:** prefill ordinary product fields and a synthetic selected file, then run every degraded path; values and Save enabled state must be unchanged. [VERIFIED: `01-UI-SPEC.md`]
8. **Accessibility/layout:** assert `role=alert`/assertive invalid feedback, polite status, `aria-busy`, keyboard Enter/Escape, focus movement, 44 px targets, reduced motion, no horizontal overflow, and the locked placement above Picture. [VERIFIED: `01-UI-SPEC.md`]

### Physical-Phone Acceptance and Threshold Encoding

Create `01-PHONE-ACCEPTANCE.md` plus a redacted `evidence/phone-timings.jsonl`. Record date/time, Grocy/module/companion/contract versions, phone model/OS/browser versions, viewport/orientation, Wi-Fi/LAN/VPN route, server host, scenario, attempt number, outcome, and overall/stage milliseconds. Never record GTIN or product data. [VERIFIED: `01-UI-SPEC.md`]

Use the locked release thresholds directly. [VERIFIED: `01-UI-SPEC.md`]

| Path | Release threshold | Evidence |
|------|-------------------|----------|
| Browser feedback | ≤250 ms | Automated assertion + physical observation. [VERIFIED: `01-UI-SPEC.md`] |
| Existing/cached Grocy result | p95 ≤1 s | 20 successful physical samples `[ASSUMED]`; record p50/p95. |
| Metadata enrichment | p95 ≤5 s | 20 successful physical samples `[ASSUMED]`; record p50/p95. |
| Image attachment | p95 ≤5 s | 20 successful physical samples `[ASSUMED]`; record p50/p95. |
| Browser deadline | exactly 15 s baseline | Automated clock assertion and one physical timeout case. [VERIFIED: `01-UI-SPEC.md`] |
| Grocy companion budget | 12 s total, 2 s connect | PHP transport option assertions. [VERIFIED: `01-UI-SPEC.md`] |
| Provider budget | 2 s connect, 5–6 s read, never >10 s | Companion fake-clock/timeout assertions. [VERIFIED: `01-UI-SPEC.md`] |

Calculate p50/p95 with a checked-in deterministic script using nearest-rank over the redacted durations; fail the phase gate if any locked threshold is exceeded. Twenty samples are a pragmatic smallest baseline, not a user decision; do not silently re-baseline. [ASSUMED]

The physical pass must cover at least: valid manual entry, camera scan, timeout/cancel/retry, background/foreground or back navigation, companion unavailable, one metadata provider unavailable, image host unavailable/partial, and normal Save after a degraded enrichment attempt. [VERIFIED: `01-UI-SPEC.md`]

### Sampling Rate

- **Per task commit:** Run the changed tier's targeted suite plus `php -l` or `node --check`; browser-facing tasks also run `npm --prefix custom/grocy_AI/tests/browser run test:smoke` in Chromium. [VERIFIED: `.planning/codebase/TESTING.md`]
- **Per wave merge:** Run the full PHP and companion suites and both Playwright projects. [VERIFIED: `01-UI-SPEC.md`]
- **Phase gate:** Full suites green; portable-file parity checked between branches; stable route/render/image smoke green; physical-phone record complete and all locked p95/deadline thresholds green. [VERIFIED: `01-UI-SPEC.md`, `AGENTS.md`]

### Wave 0 Gaps

- [ ] `custom/grocy_AI/tests/browser/package.json` and lockfile — isolated Playwright scripts; install only after `checkpoint:human-verify` for `[ASSUMED] @playwright/test`.
- [ ] `custom/grocy_AI/tests/browser/playwright.config.js` — Chromium/WebKit mobile projects and deterministic web server. [VERIFIED: file absence/codebase grep]
- [ ] `custom/grocy_AI/tests/browser/fixtures/productform.html` — representative form loading actual phase assets. [VERIFIED: file absence/codebase grep]
- [ ] `custom/grocy_AI/tests/browser/support/server.mjs` — Node built-in static server; no extra dependency. [VERIFIED: file absence/codebase grep]
- [ ] `custom/grocy_AI/tests/browser/specs/gtin-validation.spec.js` — MOB-01. [VERIFIED: file absence/codebase grep]
- [ ] `custom/grocy_AI/tests/browser/specs/states.spec.js` — MOB-02. [VERIFIED: file absence/codebase grep]
- [ ] `custom/grocy_AI/tests/browser/specs/concurrency.spec.js` — MOB-03/MOB-04. [VERIFIED: file absence/codebase grep]
- [ ] `custom/grocy_AI/tests/browser/specs/diagnostics.spec.js` — MOB-05/MOB-06. [VERIFIED: file absence/codebase grep]
- [ ] `custom/grocy_AI/tests/browser/specs/preservation.spec.js` — MOB-04/MOB-07. [VERIFIED: file absence/codebase grep]
- [ ] `custom/grocy_AI/tests/browser/specs/responsive-a11y.spec.js` — MOB-08. [VERIFIED: file absence/codebase grep]
- [ ] Extend `custom/grocy_AI/tests/run.php` with checksum, trace, timeout, DTO, error-enum, and forbidden-canary contract checks. [VERIFIED: codebase grep]
- [ ] `/Users/ian/Documents/Repos/grocy-mcp/tests/test_diagnostics.py` and extensions to existing tests — provider status/timing/trace/privacy contracts. [VERIFIED: file absence/codebase grep]
- [ ] `.planning/phases/01-safety-baseline-mobile-diagnostics/01-PHONE-ACCEPTANCE.md` and evidence script/schema — physical release gate. [VERIFIED: file absence/codebase grep]

## Security Domain

OWASP ASVS 5.0.0 is the current stable ASVS release referenced for these categories. [CITED: https://owasp.org/www-project-application-security-verification-standard/]

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | Yes, unchanged | Keep same-origin Grocy authentication and companion API-key configuration; diagnostics never contain the key. [VERIFIED: codebase grep] |
| V3 Session Management | Yes, unchanged | Do not read, copy, or log session cookies/headers; normal Grocy middleware remains authoritative. [VERIFIED: codebase grep] |
| V4 Access Control | Yes | Preserve the existing `MASTER_DATA_EDIT` permission check before enrichment and selected-image operations. [VERIFIED: codebase grep] |
| V5 Input Validation | Yes | Validate GTIN/checksum at browser, PHP, and Python boundaries; validate trace syntax; allowlist DTO fields/enums; use safe text insertion. [VERIFIED: `01-UI-SPEC.md`, codebase grep] |
| V6 Cryptography | Yes, narrow | Generate trace identifiers with Web Crypto/PHP secure random; never hand-roll randomness. [CITED: https://developer.mozilla.org/en-US/docs/Web/API/Crypto/getRandomValues] [CITED: https://www.php.net/manual/en/function.random-bytes.php] |

### Known Threat Patterns for This Stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Diagnostic/report contains GTIN, token, URL, cookie, key, payload, or raw exception | Information Disclosure | Closed DTO, second copy allowlist, forbidden-canary tests at all three tiers, no raw object logging. [CITED: https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html] |
| Malformed trace header injects arbitrary content or creates misleading correlation | Tampering / Spoofing | Strict v00 parse, reject zero/invalid IDs, generate a new trace at trust boundary, never echo raw invalid input. [CITED: https://www.w3.org/TR/trace-context/] |
| Local trace identifier leaks to third-party provider | Information Disclosure | Do not forward `traceparent`/`tracestate` beyond the owned companion boundary. [CITED: https://www.w3.org/TR/trace-context/] |
| Late response overwrites current user intent | Tampering | Monotonic intent token plus normalized-GTIN guard on every callback. [VERIFIED: `01-UI-SPEC.md`] |
| Repeated scan/tap or automatic retry amplifies provider work | Denial of Service | Same-intent coalescing, no auto retry, explicit cancellation, layered deadlines. [VERIFIED: `01-CONTEXT.md`] |
| Raw backend exception reaches the user | Information Disclosure | Finite error enum and fixed UI copy; raw exception stays out of response/log/report. [VERIFIED: `01-UI-SPEC.md`] |
| Enrichment path performs hidden product or stock writes | Tampering / Repudiation | Retain existing permission boundary, zero-write search contract, and negative endpoint-count tests; persistence remains normal Save. [VERIFIED: `01-CONTEXT.md`] |

## Planning Recommendations

Plan the phase in four dependency waves:

1. **Wave 0 — Contract and validation scaffolding:** add shared test vectors, diagnostic schema/enums, module version source, browser harness, companion diagnostic helpers, and acceptance schema. This eliminates drift before UI/provider work begins.
2. **Wave 1 — Owned service boundaries:** implement companion provider outcomes/outer budgets/timings first, then Grocy trace validation, bounded Guzzle transport, error normalization, and DTO redaction. The Grocy browser contract should target the completed envelope.
3. **Wave 2 — Browser/UI vertical slice:** implement markup/CSS contract, camera hookup, local validation, state machine, cancellation/coalescing/lifecycle handling, partial/image preservation, and copy/fallback diagnostics against deterministic routes.
4. **Wave 3 — Release evidence and parity:** adapt/merge portable changes into `atech-release`, bump stable cache marker, run full two-engine suites and stable image smoke, then execute the physical-phone/LAN matrix and publish p50/p95 evidence.

Each task that changes behavior must include its focused automated command and an explicit negative assertion for “normal form/Save remains usable” or “forbidden data absent,” whichever applies. [VERIFIED: `01-CONTEXT.md`]

## Sources

### Primary (HIGH confidence)

- [W3C Trace Context](https://www.w3.org/TR/trace-context/) — strict trace format, propagation, privacy, and security.
- [W3C Server Timing](https://www.w3.org/TR/server-timing/) — response timing header semantics.
- [Playwright installation](https://playwright.dev/docs/intro) — official package and engine support.
- [Playwright network mocking](https://playwright.dev/docs/network) — route interception and deterministic responses.
- [Playwright emulation](https://playwright.dev/docs/emulation) — viewport/device emulation.
- [Playwright Clock](https://playwright.dev/docs/clock) — deterministic timer control.
- [Playwright BrowserContext](https://playwright.dev/docs/api/class-browsercontext#browser-context-set-offline) — offline emulation.
- [Guzzle request options](https://docs.guzzlephp.org/en/stable/request-options.html) — total/connect timeouts and `on_stats`.
- [HTTPX timeouts](https://www.python-httpx.org/advanced/timeouts/) — connect/read/write/pool timeout semantics.
- [Python `asyncio.timeout`](https://docs.python.org/3.13/library/asyncio-task.html#asyncio.timeout) — outer async deadline.
- [GS1 check-digit calculation](https://www.gs1.org/services/how-calculate-check-digit-manually) — GTIN modulo-10 method.
- [OWASP Logging Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html) — sensitive data exclusion.
- [OWASP ASVS](https://owasp.org/www-project-application-security-verification-standard/) — security verification categories/version.
- [MDN XHR abort](https://developer.mozilla.org/en-US/docs/Web/API/XMLHttpRequest/abort), [Navigator online](https://developer.mozilla.org/en-US/docs/Web/API/Navigator/onLine), [visibility change](https://developer.mozilla.org/en-US/docs/Web/API/Document/visibilitychange_event), and [Web Crypto random](https://developer.mozilla.org/en-US/docs/Web/API/Crypto/getRandomValues) — browser lifecycle and platform APIs.
- [PHP `random_bytes`](https://www.php.net/manual/en/function.random-bytes.php) — secure server-side random bytes.
- Project `01-CONTEXT.md`, `01-UI-SPEC.md`, `REQUIREMENTS.md`, `ROADMAP.md`, `AGENTS.md`, codebase maps, and inspected source/tests — locked contract and current implementation.

### Secondary (MEDIUM confidence)

- npm registry metadata for `@playwright/test` — version/date/repository existence only; package legitimacy remains `[ASSUMED]` because slopcheck was unavailable.

### Tertiary (LOW confidence)

- None. All unverified design choices are explicitly listed in the Assumptions Log.

## Metadata

**Confidence breakdown:**

- Standard stack: HIGH for existing runtime/API choices; MEDIUM for the Playwright addition solely because the required slopcheck gate was unavailable.
- Architecture: HIGH — derived from locked UI/context contracts, inspected Grocy/companion boundaries, and official platform specifications.
- Pitfalls: HIGH — most are directly observable in the current code or specified by official lifecycle/privacy documentation.
- Validation: HIGH for behavior mapping and commands; MEDIUM for the assumed 20-sample physical p95 baseline.

**Research date:** 2026-08-12
**Valid until:** 2026-09-11 for the stable architecture; re-check Playwright version/legitimacy and deployed LAN availability at execution.
