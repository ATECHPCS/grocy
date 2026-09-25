# Phase 8 acceptance

**Automated and deployment gates: PASS. Human device and real purchase acceptance: PENDING.**

| Gate | Evidence |
| --- | --- |
| PHP native/module | 272/272 checks on development and stable candidates; focused caller-transaction, checksum, and scrubbed-snapshot native purchase tests pass |
| Mobile browser | 206/206 Chromium/WebKit release tests; capture specs include coalescing, unknown lifecycle, review, partial commit, idempotency, and zero stock writes before Commit |
| Stable parity | 92/92 portable paths identical at stable commit `87677993f514edbabcd1579752268769ece567a1` |
| Disposable HTTP | Capture, review, bulk, conversion, product, and required assets return 200 after namespaced migration bootstrap |
| Live HTTP | Authenticated capture, review, trips, conversion, bulk, and product GETs return 200; unauthenticated status returns 401 |
| Deployment | Running image `sha256:d22abaeff119680fcbb62f3b5a72b70fc3302b5bdf3ebfc9941fb10f4abb207c`; `/etc/komodo/grocy:/config` preserved; predeploy backup retained; SQLite integrity `ok` |
| Protected data | No live capture commit or bulk apply; stock and other protected native rows match the predeploy backup. One userfield definition was added; API-key `last_used` changed during smoke. |

**Maintainer acceptance still required:** On a physical phone, scan or enter a known GTIN rapidly, confirm coalescing and quantity; scan an unknown GTIN, use product creation and return to the trip; edit quantity, price, and trip defaults; review a mixed trip and make one intentional purchase commit; confirm the Grocy stock log and a second Commit's idempotent result. Also finish Phase 1 Plan 01-10 mobile timing/degraded-path and normal-Save checks, and Phase 5 Plan 05-11 human bulk-review check. Record only redacted outcomes and timings in the existing acceptance artifacts.
