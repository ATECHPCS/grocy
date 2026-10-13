# Capture dashboard implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Open Capture on a read-only personal dashboard, with explicit session creation, existing-session navigation, and useful statistics.

**Architecture:** Keep the existing PHP/Blade/plain JavaScript/Bootstrap module. The existing Capture controller selects dashboard or scanner based on an explicit valid trip query. A separate small dashboard service supplies actor-scoped read-only data; session creation reuses the existing POST endpoint.

**Tech Stack:** PHP8.5, SQLite, Blade, plain JavaScript, Bootstrap4 and existing browser/contract fixtures.

**Spec:** User-approved in-chat design, recorded below (approval October13,2026).

## Approved design

`/grocyai/capture` becomes the dashboard and never starts or resumes a session automatically. New session creates a trip only when tapped and opens its scanner. Continue sessions displays date, status, captured-item and receipt counts, with Resume/Review links. Completed sessions opens past purchase review. Explicit `?trip=12` still opens that trip. Personal statistics show open sessions, sessions needing review, completed purchases in the last30days, and clearly labeled receipt totals for those completed purchases. Capture and review have Back to dashboard actions and retain the current mobile styling.

## Global constraints

- Keep feature behavior in `custom/grocy_AI/` and `public/custom/grocy_AI/`, using the existing Blade/controller hooks. No new frontend runtime, dependency, provider call or schema change.
- Dashboard reads and links create no trip, catalog product, research job or stock booking. New session is one explicit POST; product approval and Commit purchase safeguards remain intact.
- Scope dashboard lists and stats to `GROCY_USER_ID` server-side via trip `created_by`. Do not accept an actor ID from the client. Existing review/API access policy is unchanged.
- Exclude audited cancelled trips. Include empty open sessions so an ambiguous creation response can be recovered by refreshing the list.
- Existing trip links never auto-create a replacement. Malformed trip queries display a safe error, and a failed resume remains an error rather than creating a trip.
- Use 44px touch targets, 8px action gaps, existing scoped tokens, keyboard focus and light/night themes at320/390/430/1280px. Dates use the browser locale and money uses Grocy currency/price-display settings.
- New session disables during its request. After an ambiguous result, show refresh/review guidance and do not automatically retry the POST.
- Back navigation performs no saves; review warns before discarding dirty drafts and blocks leaving while commit or mutation operations are unsettled. Capture retains existing pending/ambiguous-save safeguards.
- Release capture/review/dashboard and fixture assets at2.6.22; customization marker ATECHPCS-grocy_AI-45; portable module version unchanged.

## Statistics and list contract

`GrocyAiCaptureDashboardService::ReadForUser(string $actor, ?int $activeBefore = null, ?int $completedBefore = null, ?DateTimeImmutable $now = null): array` reads already-bootstrapped tables. The controller bootstraps existing module migrations using established behavior; the read method itself never mutates data.

`GET /api/grocy-ai/capture/dashboard` requires STOCK_PURCHASE. It reads actor only from the authenticated user. Optional active_before/completed_before are positive decimal trip IDs, max10digits; reject other shapes with400. No SQL interpolation of actor/cursors.

Return contract_version1, period_start/period_end UTC ISO timestamps, stats `{open_sessions, reviewing_sessions, completed_sessions_30d, receipt_printed_total_30d, receipts_with_total_30d, receipts_missing_total_30d, completed_without_receipts_30d}`, and active/completed sections `{sessions, next_before_id}`. Each section uses25 newest IDs per page with a limit+1 read; next_before_id is null on the final page. Sessions expose only `{id,status,created_at,committed_at,item_count,receipt_count}`. Counts are distinct capture lines, not individual camera reads or a sum of unlike quantity units.

The30-day window is rolling, based on committed_at: start inclusive, end inclusive; use normalized UTC datetime comparisons because stored timestamps have more than one format. Only committed trips count. Receipt totals sum available printed_total values once per receipt, independent of included-stock subtotal, ignored items and accepted differences. No fallback to entered totals. Return null when no printed totals are available. Label the total as Printed receipt totals and show missing-total/receipt notices rather than a misleading complete spending figure. Cancelled/other-actor/unfinished/partial trips never inflate this figure. All session/history counts remain independent of page size.

## Review focus

- Browser refresh, bookmarks and malformed queries must never create a session: Task2 zero-write and routing tests.
- Other users, cancelled trips and multiple lines/receipts must not leak or multiply totals: Task1 fixture assertions.
- Missing printed totals and legacy completion dates must not present invented spending: Task1 date/missing-data cases and Task2 copy tests.
- Double taps and lost create responses must not cause silent repeat POSTs: Task2 delayed/error fixtures.
- Returning to dashboard must not silently discard drafts or leave a pending stock mutation: Task2 navigation guards.

### Task1: Personal read model and landing routing

**Files:** Create `custom/grocy_AI/src/GrocyAiCaptureDashboardService.php`, `custom/grocy_AI/tests/capture_dashboard.php`. Modify `custom/grocy_AI/src/GrocyAiCaptureController.php`, `custom/grocy_AI/src/GrocyAiApiController.php`, `custom/grocy_AI/routes.php`.

**Interfaces:** Produce ReadForUser above and DashboardCapture API action. Capture renders `grocyai_capture_dashboard` without a trip query, `grocyai_capture` with a valid query; remove implicit default-trip selection. Invalid explicit query is a400 safe response. Publish dashboard/capture/review URLs through existing U helper; no new mutation endpoint.

- [ ] Write failing PHP cases for empty dashboard, actor isolation, cancelled exclusion, open/reviewing/committed counts, joins with3lines+2receipts, boundary/legacy date formats, missing totals, noncommitted allocations,25-row paging and invalid API cursor types/permission denial. Verify full table snapshots before/after reads match.
- [ ] Run `php8.5 custom/grocy_AI/tests/capture_dashboard.php`; witness missing service/action failures.
- [ ] Implement service queries, typed bounded response, authenticated API validation and capture landing routing. Keep cancelled filtering and actor scoping on every page and aggregate query.
- [ ] Run the PHP dashboard tests, `php8.5 custom/grocy_AI/tests/run.php`, PHP lint and diff check. Update obsolete resume-default tests to the new approved dashboard behavior while retaining explicit trip-resume assertions.
- [ ] Commit `feat: add personal purchase session dashboard data` and obtain independent task review before Task2.

### Task2: Dashboard UI, explicit creation and safe navigation

**Files:** Create `views/grocyai_capture_dashboard.blade.php`, `public/custom/grocy_AI/capture-dashboard.js`, `custom/grocy_AI/tests/browser/fixtures/capture-dashboard.html`, `custom/grocy_AI/tests/browser/specs/capture-dashboard.spec.js`. Modify capture/review Blade views and scripts for Back to dashboard, `public/custom/grocy_AI/grocy-ai.css`, matching capture fixtures/specs, `custom/grocy_AI/tests/browser/support/server.mjs` only if new assets need registering, module version marker/exact assertions, README/CUSTOMIZATIONS. Add verification document `docs/superpowers/plans/2026-10-13-capture-dashboard-verification.md`.

**Interfaces:** Consume Task1 dashboard response. Dashboard includes explicit New session, four statistics cards, active sessions, completed history, empty/loading/error states and per-section Show more. New session uses existing `POST /capture/trips` with `{}` and navigates to capture `?trip=<validated id>` after success. Resume/Review links use the existing routes; committed sessions have review only. Dashboard does not load camera/scanner or research scripts. Back to dashboard links use the bare capture URL and guard existing dirty/pending state.

- [ ] Add failing browser cases for zero writes on open/refresh/revisit, one POST on double tap, validated creation navigation, empty session recovery after ambiguous create, load failure+GET retry, active/completed link targets, safe text/date/money rendering, missing-total guidance, paging deduplication and unknown/malformed payload rejection.
- [ ] Add routing/scan integration cases proving explicit trip resume remains GET-only and a completed review returns to dashboard without starting a trip. Pin unsaved research/receipt leave confirmation, cancelled leave, pending mutation/commit leave blocking, and capture save ambiguity protection.
- [ ] Run focused dashboard/navigation cases to witness red; implement new view/script/style and safe Back actions. Scanner starts only from an explicit new-session action or resumes the requested session; opening the dashboard cannot invoke capture.startTrip.
- [ ] Synchronize2.6.22 assets/marker45 and exact PHP assertions; keep receipt/research/queue/controller script ordering and native scanner preceding-input contract.
- [ ] Run new dashboard PHP contract, focused dashboard/navigation browser cases, full `npm --prefix custom/grocy_AI/tests/browser run test:release -- --workers=1`, module274+current checks, Node capture/review tests and native `php8.5 custom/grocy_AI/tests/automated_trip_rehearsal.php`; lint changed PHP/JS and diff check. Use isolated fixtures only, no paid calls/live stock commits.
- [ ] Record actual results and light/night populated screenshots. Physical phone acceptance remains separate. Commit `feat: make purchase capture open on a session dashboard`; obtain task review and whole-branch review, resolve important findings and covering tests.

## Integration

Use the preserved subagent-driven method in the existing isolated feature branch; no extra worktrees. After approval of this saved plan, execute both tasks sequentially with per-task and final review. Prepare/link PR to atech-release and carry out the previously authorized rollout with SQLite online backup, persistent data preserved and read-only asset/trip verification. Do not create a real session or commit stock as deployment smoke testing.
