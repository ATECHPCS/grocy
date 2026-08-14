---
gsd_state_version: 1.0
milestone: v1.0
milestone_name: milestone
status: executing
stopped_at: Completed 02-12-PLAN.md
last_updated: "2026-08-14T18:10:07.403Z"
last_activity: 2026-08-14
progress:
  total_phases: 7
  completed_phases: 0
  total_plans: 24
  completed_plans: 21
  percent: 88
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-08-12)

**Core value:** Adding and maintaining real household food inventory must be fast, accurate, and dependable from a phone without surrendering control of the data to automatic guesses.
**Current focus:** Phase 02 — Enrichment Contract, Barcode Handoff & Secure Media

## Current Position

Phase: 02 (Enrichment Contract, Barcode Handoff & Secure Media) — EXECUTING
Plan: 13 of 14
Status: Ready to execute
Last activity: 2026-08-14

Progress: [█████████░] 88%

## Performance Metrics

*Updated after each plan completion*
| Phase 01 P01 | 3 min | 1 tasks | 1 files |
| Phase 01 P02 | 10 min | 1 tasks | 7 files |
| Phase 01 P03 | 9 min | 2 tasks | 6 files |
| Phase 01 P04 | 11 min | 2 tasks | 8 files |
| Phase 01 P05 | 10 min | 2 tasks | 7 files |
| Phase 01 P06 | 24 min | 3 tasks | 9 files |
| Phase 01 P07 | 17 min | 3 tasks | 10 files |
| Phase 01 P08 | 3 min | 2 tasks | 7 files |
| Phase 01 P09 | 43 min | 2 tasks | 7 files |
| Phase 02 P01 | 13 min | 2 tasks | 8 files |
| Phase 02 P02 | 16 min | 2 tasks | 16 files |
| Phase 02 P03 | 20min | 2 tasks | 8 files |
| Phase 02 P04 | 20 min | 3 tasks | 17 files |
| Phase 02 P05 | 11 min | 2 tasks | 10 files |
| Phase 02 P06 | 11 min | 2 tasks | 7 files |
| Phase 02 P07 | 18 min | 2 tasks | 16 files |
| Phase 02 P08 | 27 min | 2 tasks | 10 files |
| Phase 02 P09 | 7 min | 2 tasks | 0 files |
| Phase 02 P10 | 5h33m | 3 tasks | 16 files |

## Accumulated Context

### Decisions

Decisions are logged in PROJECT.md Key Decisions table.
Recent decisions affecting current work:

- [Milestone]: Use seven dependency-ordered vertical MVP phases with fine granularity; each v1 requirement belongs to exactly one phase.
- [Phase 1]: Treat deployed enrichment/name/image behavior as validated brownfield context, while beginning this milestone with safety, diagnostics, mobile verification, and the recurring dual-branch gate.
- [Phase 4]: Require a dual-branch characterization spike before choosing the resolved/cache projection for food-type conversions.
- [All phases]: External data remains reviewable evidence; Grocy remains the sole durable mutation authority.
- [Phase 01]: Authorized only the official @playwright/test package at exact version 1.62.1 for installation by Plan 01-02. — Registry identity, Microsoft source, exact dependency pin, and absence of a postinstall script were verified before the user replied approved.
- [Phase 01]: Kept Plan 01-01 approval-only with no dependency or package-file changes. — Installation and lockfile creation remain scoped to Plan 01-02.
- [Phase 01]: Keep @playwright/test pinned exactly to 1.62.1 in a private nested workspace — Root dependency files remain untouched.
- [Phase 01]: Use a deny-by-default loopback browser fixture server and deterministic page.route provider envelopes — The ordinary browser suite must not expose repository files or contact live providers.
- [Phase 01]: Stop Plan 01-02 at the intentional RED gate — The plan prohibits production changes and later Phase 1 plans implement the missing phone behavior.
- [Phase 01]: Treat every GTIN as text and validate accepted lengths with one GS1 modulo-10 algorithm so leading zeroes survive manual and camera paths. — One string-preserving validator covers every supported length and intent source.
- [Phase 01]: Own enrichment transport inside the module with a direct same-origin XMLHttpRequest and exact 15,000ms timeout; leave shared Grocy.Api.Get and productform Save handlers unchanged. — The module needs a cancellable bounded read without changing application-wide transport behavior.
- [Phase 01]: Keep enrichment output review-only; preview actions stage the existing form and durable writes remain behind normal Grocy Save controls. — This preserves Grocy as the sole mutation authority and keeps enrichment optional.
- [Phase 01]: Validate string-preserving GS1 GTINs at the companion endpoint and orchestration entry before provider work. — This rejects invalid inputs before any federation, Open Food Facts, or image work and preserves leading zeroes.
- [Phase 01]: Terminate strict W3C trace context at the companion. — External providers are timed locally but never receive traceparent or tracestate.
- [Phase 01]: Use closed diagnostics with a 10.5-second outer provider budget, 2-second connect, and 6-second read limits. — Finite allowlisted values localize failures without leaking provider details or overrunning Grocy's boundary.
- [Phase 01]: Hard-cap Grocy companion requests at 12 seconds total and 2 seconds connect — The approved mobile deadline overrides any larger legacy timeout setting.
- [Phase 01]: Validate or replace strict W3C v00 trace context at Grocy and forward only a fresh-parent traceparent — Owned correlation stays trustworthy while tracestate and third-party propagation remain excluded.
- [Phase 01]: Construct Grocy diagnostics and Server-Timing only from closed enums, portable versions, and bounded or nullable durations — Field-by-field normalization prevents exception, credential, URL, GTIN, header, payload, cookie, and image-token disclosure.
- [Phase 01]: Use request sequence plus normalized GTIN—not trace identity—as the sole browser UI ownership guard. — Trace IDs correlate requests across owned services; sequence/object/GTIN equality alone grants current DOM ownership.
- [Phase 01]: Treat server diagnostics as untrusted and reconstruct the browser report from closed allowlisted primitives. — A second browser allowlist prevents future raw server fields, secrets, URLs, payloads, and exceptions from reaching DOM or clipboard.
- [Phase 01]: Reconnect and pageshow restore controls only; explicit Retry alone creates a new request and trace. — This prevents hidden provider work and request amplification after network or lifecycle events.
- [Phase 01]: Require one supplied full 40-hex stable commit and read blobs with git show; never infer or move a stable ref during parity. — This keeps parity reproducible and prevents the release gate from mutating the main checkout.
- [Phase 01]: Keep physical evidence empty and failing until stable deployment and real phone sampling provide all locked samples. — Synthetic or partial evidence must not satisfy the physical-device release gate.
- [Phase 01]: Treat orientation change as request invalidation and the diagnostics disclosure as a touch action subject to the 44px contract. — Both interactions participate in the locked mobile lifecycle and accessibility requirements.
- [Phase 01]: Mirror and commit the exact seven-file portable manifest before any stable-only controller, route, view, cache-marker, customization, deployment, or phone-evidence work. — This keeps stable framework adaptation reviewable and separate from portable bytes.
- [Phase 01]: Pin downstream stable parity and adaptation to portable commit 217a7a0e98889cf4953d3fb7bdc2bf038be4ce7f. — A full immutable commit reproduces the seven-file baseline without moving either checkout.
- [Phase 01]: Preserve stable framework seams across exactly five adapter paths while keeping the seven portable files byte-identical. — This keeps stable release integration reviewable without weakening the portable parity gate.
- [Phase 01]: Combine live deployed boundary smoke with deterministic forced-degradation fixtures when changing the household companion would be unsafe. — Live status, enrichment, image, form, and asset boundaries were exercised without risking household provider availability; deterministic suites prove the forced finite outcomes.
- [Phase 01]: Record only aggregate counts, hashes, HTTP statuses, finite outcomes, and versions in deployment evidence; physical phone and normal-Save evidence remain Plan 01-10. — This satisfies deployment provenance and zero-write evidence while preserving the locked privacy boundary and physical acceptance ownership.
- [Phase 02]: Preserve raw contract fixture bytes until duplicate-aware validation — Ordinary JSON decoding collapses repeated top-level, nested, and escaped-equivalent member names.
- [Phase 02]: Pin Phase 2 HTTP behavior to the exact deployed companion constraints — The 69-distribution hash and Python/HTTPX/Starlette/Uvicorn anchors make runtime drift blocking rather than implicit.
- [Phase 02]: Walk raw JSON recursively and compare decoded member-name tokens per object scope before full-document decode. — Ordinary JSON decoding collapses duplicate and escaped-equivalent keys.
- [Phase 02]: Expose only the closed v2 review DTO and owned trace ID. — Provider dictionaries, URLs, deferred nutrition families, and parser details stay behind the boundary.
- [Phase 02]: Preselect a name only for blank content plus high structured-direct canonical evidence. — Review remains reversible and zero-write; normal Save is still the sole persistence authority.
- [Phase 02]: Resolve Brand only through one server-confirmed products.brand text-single-line userfield — Any duplicate, type, ID, label, or destination drift fails closed.
- [Phase 02]: Re-read selected native controls before diff and staging — Changed rows are stale and require a fresh explicit decision before staging.
- [Phase 02]: Keep barcode and product-image staging inactive until their assigned plans — Normal Grocy Save remains the sole persistence authority.
- [Phase 02]: Canonicalize only checksum-valid digit strings of exact GTIN-8/12/13/14 lengths; preserve the exact scan and leave invalid numeric-looking values outside enrichment ownership. — One shared PHP and generated SQLite predicate prevents drift while preserving Grocy's arbitrary barcode support.
- [Phase 02]: Resolve MASTER_DATA_EDIT and local ownership before provider work; owner navigation uses only the bounded database product ID. — This suppresses duplicate provider work and prevents browser or companion input from controlling local navigation.
- [Phase 02]: Keep unused barcode staging in reducer state only; Plan 02-05 owns normal-Save attachment and uniqueness enforcement. — Plan 02-04 is read-only and preserves Grocy's unchanged Save authority.
- [Phase 02]: Use only the generated checksum-valid GTIN expression for lookup, collision audit, and migration uniqueness — Nonzero collisions block transactionally without deleting, rewriting, or reassigning rows.
- [Phase 02]: Attach the staged barcode only after normal Save establishes a trusted product ID — Duplicate continuations coalesce and existing Grocy object APIs remain the sole write boundary.
- [Phase 02]: Use barcode-only recovery after partial product creation — Same-product ownership is success; another owner clears staging and routes only from the database-owned ID.
- [Phase 02]: Keep Plan 02-06 test-only; secure-media production behavior remains owned by Plan 02-07. — This plan specifies RED acceptance only and must not introduce production behavior.
- [Phase 02]: Inject resolver, peer-aware streaming transport, clock, and token source for secure media. — Every DNS, peer, redirect, deadline, handle, and byte case stays deterministic and offline.
- [Phase 02]: Require separate same-origin thumbnail/full actions and reducer-only File state until staging and normal Save. — Explicit actions and transient state preserve zero-write review authority.
- [Phase 02]: Bind every companion media hop to a freshly approved public IP and verify the actual peer before redirect or body handling. — This closes DNS rebinding and proxy-re-resolution gaps while retaining the original Host header and TLS SNI.
- [Phase 02]: Default-deny every unclassified non-read fixture request with distinct counters for all nine protected mutation families. — Unknown writes must fail before handling so zero-write evidence cannot omit a durable family.
- [Phase 02]: Migrate retired Phase 1 fixture payloads to the closed v2 DTO while preserving their safety guarantees. — The v2 shape intentionally supersedes the permissive summary payload; concurrency, diagnostics, GTIN, lifecycle, preservation, and zero-write claims remain authoritative.
- [Phase 02]: Keep Phase 1 physical evidence read-only and unaccepted during Plan 02-08. — Deterministic Blade/browser evidence does not replace the separately skipped physical timing gate.
- [Phase 02]: Close Plan 02-09 without production or companion changes when the complete acceptance matrix is green. — Hardening remains failure-backed; speculative changes, empty commits, version bumps, and dependency substitution would violate the locked no-churn contract.
- [Phase 02]: Define the stable portable candidate as the complete 12-path runtime/browser/harness/fixture/documentation set. — Exact diff-tree scope and full committed parity must agree; adapter paths remain excluded.
- [Phase 02]: Defer full stable-native execution until Plan 02-11 introduces the required stable Blade, migration, and preflight adapters. — Plan 02-10 proves portable contract, syntax, scope, and immutable byte parity without scope-smuggling adapters.
- [Phase 02]: Keep the stable Phase 2 framework adapter as one exact eight-path commit directly atop the immutable portable commit. — Direct parent equality, exact diff-tree scope, and portable blob parity keep stable framework differences independently reproducible without weakening the portable contract.
- [Phase 02]: Derive Phase 2 release and deployment approval only from executable immutable Git, live container, mount, schema, auth, media, and protected-fingerprint comparisons. — Direct comparisons prevent self-authored evidence prose, mutable refs, and private household details from becoming release authority.

### Pending Todos

None yet.

### Blockers/Concerns

- [Phase 1]: Actual supported phone/browser versions and acceptable LAN latency thresholds require recorded physical-device measurement.
- [Phase 2]: Companion provider concurrency, timeout, cache, authentication, and secure-media behavior require direct inspection during planning.
- [Phase 3]: Taxonomy v1 and the frozen/preserved boundary require validation against the full in-scope inventory.
- [Phase 4]: Final conversion projection is intentionally unresolved until the mandatory dual-branch characterization spike.
- [Phase 6]: Cleanup planning requires a scrubbed production-shaped snapshot to confirm conversion and inventory edge cases.

### Quick Tasks Completed

| # | Description | Date | Commit | Directory |
|---|-------------|------|--------|-----------|
| 260813-1bt | Fix Phase 1 GTIN touch target, invalid state, camera recovery, and stable deployment | 2026-08-13 | f3df5049 | [260813-1bt-fix-phase-1-gtin-touch-target-invalid-st](./quick/260813-1bt-fix-phase-1-gtin-touch-target-invalid-st/) |
| Phase 02 P11 | 10 min | 2 tasks | 10 files |
| Phase 02 P12 | 20 min | 2 tasks | 4 files |

## Deferred Items

Items acknowledged for v2 after the preview/audit model is proven:

| Category | Item | Status | Deferred At |
|----------|------|--------|-------------|
| Assisted learning | User-reviewed local mapping hints | v2 | Roadmap creation |
| Visualization | Interactive conversion graph | v2 | Roadmap creation |
| Snapshot workflow | Preview snapshot re-import after fresh conflict check | v2 | Roadmap creation |

## Session Continuity

Last session: 2026-08-14T18:09:55.633Z
Stopped at: Completed 02-12-PLAN.md
Resume file: None
