# Phase 01 Multi-Source Coverage Audit

| SOURCE | ID | Feature / Requirement | Plan | Status | Notes |
|---|---|---|---|---|---|
| GOAL | — | Dependable phone enrichment plus failure localization and measured latency budgets | 01-03 through 01-08 | COVERED | Happy path, diagnostics, degraded states, automation, stable smoke, and physical evidence form one reachable flow. |
| REQ | MOB-01 | Camera/manual GTIN intent with immediate length/checksum validation | 01-03, 01-05 | COVERED | Shared GS1 vectors at browser and server boundaries. |
| REQ | MOB-02 | Named bounded states with cancel/retry | 01-03, 01-04, 01-05, 01-06 | COVERED | Exact UI copy, 15 s browser deadline, 12 s/2 s Grocy, and ≤10–11 s companion/provider work. |
| REQ | MOB-03 | Stale responses never render/apply | 01-06 | COVERED | Monotonic intent token plus normalized-GTIN guard across edit/cancel/navigation/lifecycle cases. |
| REQ | MOB-04 | Duplicate taps/scans/retries have no duplicate request/result/write effect | 01-03, 01-06 | COVERED | Coalescing, request counters, one current result, and zero-write endpoint assertions. |
| REQ | MOB-05 | Privacy-safe browser/Grocy/companion/provider correlation | 01-04, 01-05, 01-06 | COVERED | W3C propagation stops at owned companion boundary; provider stages are measured only. |
| REQ | MOB-06 | Copyable redacted diagnostic report | 01-05, 01-06 | COVERED | Closed DTO plus browser reconstruction and forbidden-canary tests. |
| REQ | MOB-07 | Normal Grocy workflow remains available during provider outages | 01-03, 01-04, 01-05, 01-06, 01-08 | COVERED | Partial/error envelopes, preservation specs, deployed Save/reload proof. |
| REQ | MOB-08 | Automated mobile coverage and physical-phone budget evidence | 01-01, 01-02, 01-07, 01-08 | COVERED | Gated Playwright install, two engines/viewports, deterministic threshold checker, live evidence. |
| RESEARCH | — | One current intent distinct from trace identity | 01-06 | COVERED | UI ownership uses monotonic token; trace is correlation only. |
| RESEARCH | — | Layered 15 s / 12 s+2 s / ≤10–11 s deadlines | 01-03, 01-04, 01-05, 01-07 | COVERED | Automated fake-clock/transport assertions and release gate. |
| RESEARCH | — | Closed diagnostic DTO and forbidden-canary privacy tests | 01-04, 01-05, 01-06 | COVERED | No regex-after-collection redaction. |
| RESEARCH | — | Strict W3C owned-boundary propagation, never providers | 01-04, 01-05 | COVERED | Invalid/zero trace values are replaced, not echoed. |
| RESEARCH | — | Partial image failure remains usable metadata | 01-04, 01-06 | COVERED | `partial_image` remains a success variant. |
| RESEARCH | — | Deterministic Playwright fakes plus separate live LAN evidence | 01-02, 01-07, 01-08 | COVERED | Live providers do not determine ordinary suite results. |
| RESEARCH | — | Dual-branch/cache-marker/stable-image release gate | 01-07, 01-08 | COVERED | Read-only branch comparison plus explicit external stable adaptation/deploy checkpoint. |
| RESEARCH | — | No schema push/migration for Phase 1 | all | COVERED | No plan adds persistence or schema work. |
| CONTEXT | D-01 | Approved UI contract is non-negotiable | 01-02, 01-03, 01-06, 01-07, 01-08 | COVERED | Exact states/copy/timing/a11y/degraded behavior are plan acceptance gates. |
| CONTEXT | D-02 | Preserve Bootstrap/Roboto/Font Awesome/card placement/one Save flow | 01-03, 01-06, 01-08 | COVERED | No new UI framework or persistence path. |
| CONTEXT | D-03 | 15 s baseline and measured re-baseline only | 01-03, 01-04, 01-05, 01-07, 01-08 | COVERED | Locked thresholds encoded and verified. |
| CONTEXT | D-04 | Request-local collapsed allowlisted diagnostics; forbidden data excluded | 01-04, 01-05, 01-06, 01-07, 01-08 | COVERED | DTO/copy/log/evidence schemas are closed. |
| CONTEXT | D-05 | No automatic retry | 01-03, 01-04, 01-06 | COVERED | Only explicit Retry creates a new trace/request. |
| CONTEXT | D-06 | Preserve fields/image/Save through every degraded state | 01-03, 01-06, 01-08 | COVERED | Automated preservation plus real Save/reload proof. |
| CONTEXT | D-07 | Resolve matrix/diagnostics/threshold/network details pragmatically | 01-01, 01-02, 01-07 | COVERED | Exact two-engine/viewport matrix and nearest-rank checker are selected. |
| CONTEXT | D-08 | Do not block planning on a preferred device/browser/VPN | 01-07, 01-08 | COVERED | Available household device and actual LAN topology are recorded at execution. |

Deferred ideas: none. Items assigned to later phases are excluded rather than omitted.

