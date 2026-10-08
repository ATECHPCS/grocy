# Mobile purchase flow verification

The Capture → Review → Confirm purchase stages use the existing Blade, plain JavaScript and Bootstrap runtime. Capture and review assets are synchronized at `2.6.18`; the customization marker is `ATECHPCS-grocy_AI-41`. The portable module version remains unchanged.

## Automated evidence

Task 3 focused summary verification passed 60 browser cases in Chromium and WebKit. The full serial release gate passed all 546 browser cases (18.9 minutes, exit 0) on 2026-10-08. PHP module checks initially passed 272, both node controller contracts passed 8 combined, and all 15 named standalone PHP contracts passed serially under PHP 8.5. Task reports in the plan's SDD workspace record exact commands, initial red failures, green counts and screenshots. The final Task 3 report records the release browser suite, PHP module checks, node controller contracts and all named standalone PHP capture/receipt contracts. Viewport simulation covers 320, 390, 430 and 1280 pixels in light and night modes; screenshots are produced by the repository's Playwright fixture tests.

Task 3 review fixes reran the expanded summary suite: 62 passed in both engines, and the PHP marker/asset gate passed 274 checks. Capture fixture CSS/controller URLs are exactly `2.6.18`. A confirmed commit after returning to review survives a failed follow-up read, restores the committed summary and keeps editors read-only. Focused tests ran with inherited `NO_COLOR` and `FORCE_COLOR` unset, without the color warning. The prior full release result belongs to the initial Task 3 commit; the targeted review fixes reran the affected summary and complete PHP gates.

Summary entry/back performs zero writes and retains the mounted receipt/research editors. Same-trip reload retains summary state and unsaved drafts. Quantities come from active included item allocations, grouped by product and its catalog purchase unit; reviewed prices stay separate. Paired scans do not duplicate allocation quantities. Receipt-only items are included; ignored amounts, tax/savings adjustments, accepted differences and full receipt audit totals are distinct from purchases. Missing product/unit metadata is explicitly unavailable.

The server readiness and checksum remain authoritative. Commit uses the existing confirmed-checksum request and confirmation, with a separate in-flight guard. Errors and ambiguous results require reload/recheck before another attempt; success cannot recommit. Late responses remain owned by the original trip. Product creation means catalog creation; stock changes happen only through Commit purchase.

## Physical phone acceptance — pending

No physical-phone acceptance is claimed from browser viewport simulation. Before rollout, check a real phone camera decode/confirmation, leading-zero UPC entry, native keyboard and focused-field reachability, safe-area bottom spacing, swipe versus vertical scroll and input gestures, light/night readability, and receipt/research draft return navigation. Check refreshed production assets and read-only trip visibility after rollout. Do not approve real products or commit household stock as smoke testing.

## Integration

Task review and whole-branch review precede integration into `atech-release`. The controller handles the authorized release; this implementation does not push or deploy.
