---
phase: 08-purchase-capture-stock-intake
plan: "06"
status: deployed-with-human-acceptance-pending
---

# Plan 08-06 summary

The capture browser suite already covered rapid scan/coalescing, known and unknown resolution, review edits and defaults, product handoff, partial commit, idempotency, and the stock-write boundary. The full release run passed 206/206 Chromium/WebKit mobile tests and 272/272 PHP checks on both development and stable candidates. Focused native transaction and scrubbed-snapshot purchase tests passed after two production-shaped commit fixes: stock compaction joins the caller transaction, and the purchase factor comes from `uihelper_product_details`.

The feature is deployed on pinned Grocy 4.6 as stable commit `87677993f514edbabcd1579752268769ece567a1`, image `sha256:d22abaeff119680fcbb62f3b5a72b70fc3302b5bdf3ebfc9941fb10f4abb207c`. The live authenticated capture and review pages and capture-trip GET route returned 200, as did prior product, bulk, and conversion pages. The database mount was preserved and protected native tables were unchanged except the documented scope userfield and API-key smoke timestamp.

The original plan deferred portable mirroring to Phase 7. The production target was later expanded to include Phase 8, so quick task 260924-r1 completed that mirror before deployment: 92/92 portable files match the stable commit.

Physical-phone capture and one deliberate household purchase still require maintainer observation. No live stock write was made solely to satisfy a deployment test.
