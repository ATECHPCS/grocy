# Personal capture dashboard verification

Task 2 implements the approved dashboard UI and safe navigation on the isolated `codex/capture-dashboard` branch. No provider call, schema change, dependency, production session, catalog write or stock commit was used. Native commit verification uses disposable fixture data only.

## Behavior covered

- Dashboard open, browser refresh, revisit and GET retry produce zero capture mutations. Dashboard loads no camera, capture, receipt or research script.
- New session is a single explicit `{}` POST. Double taps cannot produce another request; only an integer ID for an open trip permits navigation. Failed or malformed responses retain creation lock and request Refresh sessions/review of recoverable empty sessions. No POST retry occurs automatically.
- Open sessions offer Resume and Review; reviewing sessions offer Review. Existing scanner resume only accepts open sessions, and closed/failed resume remains GET-only without creating a replacement. Completed history offers Review only.
- Four statistics cards use server aggregate counts, browser locale dates, and Grocy currency and price display precision. Printed totals include available printed amounts and explicit missing total/receipt guidance. Null totals remain Unavailable; legacy dates remain Date unavailable. Completed history distinguishes Created and Completed dates.
- Active/completed lists have independent Show more cursors and deduplicate sessions. Unknown contract versions, extra private fields, malformed dates/counts/statuses/cursors reject the payload safely, using text nodes for dynamic output.
- Review Back to dashboard asks before discarding research/receipt drafts, cancellation preserves edits, pending direct mutation/commit blocks leaving, and completion returns to the dashboard without creating a trip. Capture blocks pending/ambiguous camera saves and confirms discarding unsaved input.
- Scoped purchase flow tokens support 320/390/430/1280px, light/night mode, keyboard focus, 44px targets and 8px action gaps. Receipt/research/queue/controller script order and native camera preceding-input relationship remain intact.
- Capture/review/dashboard and fixtures use `2.6.22`; customization marker is `ATECHPCS-grocy_AI-45`. Portable module version remains unchanged.

## Red and green evidence

Representative dashboard red: `env -u NO_COLOR -u FORCE_COLOR npm --prefix custom/grocy_AI/tests/browser run test -- --project=chromium-mobile capture-dashboard.spec.js --grep 'open refresh' --workers=1` failed because Session #12 was absent from the existing fixture DOM before dashboard script implementation. `/tmp/dashboard-red-valid.log` records the expected assertion. Initial fixture-alias root setup was corrected before this valid red.

Navigation red: `env -u NO_COLOR -u FORCE_COLOR npm --prefix custom/grocy_AI/tests/browser run test -- --project=chromium-mobile purchase-capture.spec.js --grep '@dashboardnav' --max-failures=1 --workers=1` failed waiting for the absent Back to dashboard link. `/tmp/dashboard-nav-red.log` records that missing feature.

Completed date copy red: the focused safe links/date case failed expecting Created: while metadata ambiguously labeled the creation date Completed. `/tmp/dashboard-date-red.log` records that assertion before the copy fix.

Initial implementation focused run passed 19/21; the remaining two failures were missing test trip selection and an ambiguous selector after adding the completed-summary Back action. Both were corrected with exact link IDs and explicit trip selection; guards and assertions were retained. Dashboard/navigation/resume subsequently passed 50/50 across Chromium and WebKit (`/tmp/dashboard-focused.log`).

Final commands all exited 0:

- `env -u NO_COLOR -u FORCE_COLOR npm --prefix custom/grocy_AI/tests/browser run test -- --grep '@dashboard' --workers=1` — 42/42 dashboard/navigation cases (`/tmp/dashboard-focused-final.log`).
- `env -u NO_COLOR -u FORCE_COLOR npm --prefix custom/grocy_AI/tests/browser run test:release -- --workers=1` — 625/625 in 21.2 minutes (`/tmp/dashboard-release-final.log`). The entire log was scanned for numbered failure blocks, Error:, FAIL:, failed counts, timeouts and warnings; zero markers.
- `php8.5 custom/grocy_AI/tests/capture_dashboard.php` — dashboard contract passed (`/tmp/dashboard-php.log`).
- `php8.5 custom/grocy_AI/tests/run.php` — 281/281 checks (`/tmp/dashboard-module.log`). Standard absent real-data snapshots were skipped as reported by the suite.
- `node --test public/custom/grocy_AI/*.test.js` — 78 passed, 0 failed/skipped (`/tmp/dashboard-node.log`).
- `php8.5 custom/grocy_AI/tests/automated_trip_rehearsal.php` — isolated native rehearsal PASS, idempotent retry with zero duplicate writes (`/tmp/dashboard-rehearsal.log`).
- `node --check` for changed dashboard/capture/review scripts, dashboard/purchase specs and fixture server; `php8.5 -l` for all three changed Blade views and run.php; `git diff --check` — all passed, rerun fresh after the browser gate.

 An earlier full run was intentionally interrupted for the completed-date clarification after 69 passes; it is not counted as a passing gate. An overlapping launch stopped at the occupied fixture port and is likewise not counted.

## Visual artifacts and limits

Repository automated tests generated populated screenshots at `/tmp/grocy-dashboard-390-light.png` and `/tmp/grocy-dashboard-390-dark.png`; both were inspected for readable text, action spacing and light/night surfaces. They cover simulated browser viewports, not a physical phone.

The controller attempted the available native T3 preview after required status/open initialization. Navigation returned a generic client error for both the environment port URL and direct localhost URL while the fixture HTTP endpoint returned 200. No alternate browser automation was used. Automated screenshots remain the visual evidence; interactive native preview and physical phone acceptance remain separate limitations. Task review and whole-branch review are the controller's next gates before rollout.
