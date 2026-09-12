---
phase: 04-reusable-conversion-model
plan: "10"
subsystem: conversion-activation
tags: [php, cli, maintainer-auth, fail-closed, release-gate]
provides:
  - CLI-only maintainer-authenticated promotion command
  - pinned immutable characterization facts
  - sole-promotion-path release gate checks
affects: [06]
key-files:
  created: [custom/grocy_AI/bin/activate-verified-conversion-ruleset.php]
  modified:
    - custom/grocy_AI/src/GrocyAiConversionService.php
    - custom/grocy_AI/tests/conversions.php
    - custom/grocy_AI/tests/run.php
    - custom/grocy_AI/tests/release-gate.sh
    - custom/grocy_AI/portable-files.txt
    - custom/grocy_AI/README.md
    - CUSTOMIZATIONS.md
key-decisions:
  - "The immutable characterization facts are pinned as a constant in the service, so editing the document alone cannot widen the gate."
  - "The maintainer presents the deployment-owned secret on standard input; it is a deliberate-action check, not a second secret, and the documentation says so."
  - "GROCY_AI_MAINTAINER_AUTH_FILE is /etc/komodo/grocy/maintainer-auth on this deployment."
requirements-completed: [CONV-02, CONV-03, CONV-05, CONV-08, CONV-09]
---

# Phase 04 Plan 10: Maintainer-Only Promotion Command Summary

**One documented CLI command is now the only operational path that can promote a reusable conversion
revision, and it is a thin delegate to the single activation transaction.**

## Accomplishments

### Task 1 — the CLI-only authenticated promotion boundary

- Added `custom/grocy_AI/bin/activate-verified-conversion-ruleset.php`. It refuses any non-CLI SAPI,
  accepts exactly `--revision`, `--main-proof-artifact`, and `--stable-proof-artifact` (each once, no
  positional arguments, closed identifier formats), authenticates the operator against the
  deployment-owned secret file, then calls `ActivateVerifiedRuleset()` exactly once.
- The auth file must be an absolute path to a readable regular file that is not at or below
  `GROCY_DATAPATH`, is not group- or world-accessible, and holds exactly one 64-hex secret. The
  operator presents the same secret on standard input and it is compared with `hash_equals`.
- Added three service helpers the command delegates to: `ImmutableProofArtifacts()`,
  `RevisionIsPromotable()`, and `ActivationBundleFromCharacterization()`. The command resolves no
  adapter, factor, cache key, projection, or path of its own.
- Fixed exit codes `0/1/2/3/4` with bounded redacted reasons. Success prints one JSON line whose
  proof and evidence references are 12-character digests, never complete identifiers. Refusals print
  one `{"status":"refused","reason":"…"}` line on stderr and nothing on stdout.
- Added the `conversion-activation-command` suite: the documented success path, 34 fail-closed cases
  (authentication, configuration, argument shape, closed identifier formats, unknown/already-active
  revision, mismatched or swapped proof artifacts, absent and altered characterization), structural
  sole-caller assertions, and sole-promotion-path fixtures run against the CLI-promoted database.

### Strengthening found by the tests

The altered-document case initially **passed** promotion: the command built its bundle from the same
document `ActivateVerifiedRuleset()` re-read, so a tampered document was self-consistent. Fixed by
pinning `CHARACTERIZATION_FACTS_SHA256` in `GrocyAiConversionService` — a checksum over both branch
revisions, the characterized migration hashes, the cache objects, the cache key schema, the
query-plan checksum, and all eight protected-consumer outputs. The selected projection is
deliberately excluded, because that is the one field the gate exists to let change. Editing the
document now requires changing the pinned constant too, and the release gate re-derives those facts
from the two immutable git revisions. Four direct tamper cases assert the new
`characterization_facts_mismatch` blocker.

### Task 2 — release parity and maintainer documentation

- Extended `release-gate.sh conversions` with the sole-promotion-command contract: the command
  exists, calls `ActivateVerifiedRuleset` exactly once, refuses non-CLI SAPI, contains no SQL, names
  no native/cache/module relation, exposes no adapter/factor/cache/SQL/projection/path option, has no
  HTTP route or API path reaching it, is the only file under `bin/` that calls the transaction, and
  commits neither a secret nor a secret path. Verified fail-closed by adding SQL to the command
  (`conversions_promotion_no_sql`), adding an activate route (`conversions_promotion_no_http_route`),
  and adding a second promotion command (`conversions_promotion_sole_command`).
- Added the command to `portable-files.txt` (35 → 36 paths) and to the gate's required Phase 4 set.
- Documented the one-time deployment setup, the exact invocation and closed argument schema, the
  redacted success and failure output, the exit-code table with every bounded reason, and a
  five-step retry procedure in `custom/grocy_AI/README.md`, plus the ownership and no-browser-toggle
  statement in `CUSTOMIZATIONS.md`.

### Release gate portability repaired

`release-gate.sh taxonomy` had been failing on this workspace since before Phase 4. Two causes, both
fixed: the stable tree was read from `HEAD` even when the fallback resolved the stable side to this
same checkout (now a `stable_ref` that defaults to `atech-release` in single-checkout mode), and
every `run_quiet` invoked a bare `php` (now the hoisted `$php_runner`, honouring `GROCY_AI_PHP`).
`release-gate.sh taxonomy` now passes end to end.

## Verification

- `bash custom/grocy_AI/tests/release-gate.sh conversions` — PASS (25 checks).
- `bash custom/grocy_AI/tests/release-gate.sh taxonomy` — PASS.
- `php custom/grocy_AI/tests/run.php conversion-activation-command` — passed.
- `php custom/grocy_AI/tests/run.php conversion-release-gate` — passed.
- `php custom/grocy_AI/tests/run.php conversion-post-activation-bypass` — passed.
- `php custom/grocy_AI/tests/run.php conversion-native-save-hook` — passed.
- `php custom/grocy_AI/tests/run.php` — all 122 grocy_AI checks passed.
- `npm --prefix custom/grocy_AI/tests/browser test` — 184/184 passed.
- `node --test public/custom/grocy_AI/conversion-*.test.js` — 36 passed.
- `php -l` on all four changed PHP files, `sh -n` on the release gate — clean.

## Decisions and deviations

- **Standard input carries the presented secret.** The plan forbids browser input, query parameters,
  standard output, and command-line arguments, and requires a constant-time validation — which needs
  two values. Standard input is the only remaining channel that keeps the secret out of `argv`, the
  environment, and the process listing. The README states plainly that this is a deliberate-action
  check rather than a second factor: an operator who can read the auth file can also supply it.
- **`GROCY_AI_CHARACTERIZATION_FILE` was added** as deployment configuration for the evidence
  document path, defaulting to the in-repo `04-CHARACTERIZATION.md`. `.planning/` is not deployed, so
  without it a deployment would refuse for the wrong reason. It names an evidence document only; it
  cannot supply an adapter, factor, or cache detail, and the pinned facts constant bounds what any
  document at that path can assert.
- **Promotion is one revision per invocation**, each recording its own evidence row. Coverage
  diagnostics were updated in this plan to accept several evidence rows as long as they name one
  supported adapter, so repeated promotions still report a single coherent gate state.
- **Production still cannot promote anything.** The characterization records no selected projection,
  so the documented command exits 4 with `activation_refused`. That is the intended state.

## Known follow-ups

- `release-gate.sh` still hardcodes `[ "$portable_count" -eq 12 ]` in its Phase 2 `candidate`/
  `predeploy`/`evidence` modes; the manifest now has 36 paths. It fails closed rather than passing
  falsely, and belongs with the Phase 4 stable mirroring work.
- The Phase 4 module files are not yet mirrored to `atech-release`.
- Deployment still needs `/etc/komodo/grocy/maintainer-auth` created and
  `GROCY_AI_MAINTAINER_AUTH_FILE` set before the command can be used there.

## Next step

Phase 4 is complete. Phase 5 is next per the roadmap; Phase 6 owns all conversion cleanup and needs a
scrubbed production-shaped snapshot.
