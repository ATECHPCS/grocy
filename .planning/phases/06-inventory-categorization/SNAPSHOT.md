# Phase 6 — Production snapshot (06-01)

The gate for all Phase 6 profiling, DATA-06 checksum work, and DATA-07 rollback
rehearsal: a local, gitignored, secret-scrubbed copy of the production Grocy
SQLite. Real prod data, secrets stripped, re-runnable, never committable.

## Where it lives

| File | What |
|---|---|
| `custom/grocy_AI/.snapshots/grocy-prod-raw.sqlite` | unscrubbed consistent copy (intermediate) |
| `custom/grocy_AI/.snapshots/grocy-prod.sqlite` | **scrubbed** snapshot — run everything against this |

Both are under `custom/grocy_AI/.snapshots/` which is gitignored
(`.gitignore` also ignores `custom/grocy_AI/**/*.sqlite`), so `git status` never
shows them. Tests read these via:

```
export GROCY_AI_SNAPSHOT=custom/grocy_AI/.snapshots/grocy-prod.sqlite
export GROCY_AI_SNAPSHOT_RAW=custom/grocy_AI/.snapshots/grocy-prod-raw.sqlite
```

## Refresh it

```bash
custom/grocy_AI/bin/snapshot-refresh.sh            # create (refuses to clobber)
custom/grocy_AI/bin/snapshot-refresh.sh --force    # overwrite an existing snapshot
custom/grocy_AI/bin/snapshot-refresh.sh --dry-run  # show what it would do, touch nothing
```

Needs the `op` (1Password) CLI signed in, plus `ssh`, `scp`, `sqlite3`,
`python3` on PATH. One command yields the scrubbed, production-shaped snapshot.

## Access reality (why it works this way)

Production Grocy (container `grocy`, image `atechpcs/grocy-ai:4.6`, Grocy 4.6.0)
runs on the Komodo **periphery** host **`10.10.0.156`** = "Personal Docker (102)"
(LXC 102), *not* on the Komodo core host. The DB is bind-mounted
`/etc/komodo/grocy:/config`, so it is `/config/data/grocy.db` in the container.

**Direct SSH to `10.10.0.156` DOES exist** — 1Password item
**`ssh:Personal Docker (102)`** (vault `API/SSH/Tokens`), an ed25519 key that logs
in as **`root@10.10.0.156`**. (An earlier plan note claimed "no SSH key to .156";
that was wrong — the key is in the vault.)

### Exact acquisition command (what the script runs)

1. Fetch the key from 1Password into tmpfs (`/dev/shm`), never to disk.
2. SSH `root@10.10.0.156`; inside the `grocy` container make a **transactionally
   consistent** copy with PHP PDO (Grocy's PHP has `pdo_sqlite` only — no `SQLite3`
   class, and there is no `sqlite3` CLI on host or container):

   ```php
   $pdo = new PDO('sqlite:/config/data/grocy.db');
   $pdo->exec("VACUUM INTO '/tmp/grocy-snap.db'");   // reads source only; prod never written
   ```

   The script verifies `md5_file()` of the source is identical before/after, so a
   production write can never slip through. (Do **not** set `PRAGMA query_only` —
   it makes SQLite reject `VACUUM INTO`.)
3. `docker cp grocy:/tmp/grocy-snap.db /tmp/grocy-snap.db` onto the host, then
   `scp` it down to the raw snapshot path, and delete the host temp.
4. Copy raw → final and apply `custom/grocy_AI/bin/snapshot-scrub.sql`.

### Transport caveat (learned the hard way)

Do **not** move the DB through a Komodo "Server terminal" (`/terminal/execute`).
A terminal's scrollback counts against the core↔periphery websocket's **16 MiB**
message cap; dumping ~20 MB of base64 through it overflows that cap and wedges the
whole core↔periphery link (server shows **NotOk** in Komodo; containers keep
running). Recovery is `systemctl restart periphery.service` on the host. The
script avoids this entirely by using `scp`.

## What gets scrubbed

`custom/grocy_AI/bin/snapshot-scrub.sql` removes **only** secrets (idempotent):

| Table.column | Action | Why |
|---|---|---|
| `users.password` | replace with `$scrubbed$no-login` (not a valid hash) | login hash |
| `api_keys` | delete all rows | REST + iCal API tokens |
| `sessions` | delete all rows | live session tokens |

Enumerated from the live 4.6 schema (no webauthn/2FA tables exist; the
`grocy_ai_*` module tables hold no secrets; the companion API key is an env var,
not in the DB). Everything else is preserved byte-for-byte: products,
product_groups, quantity_unit_conversions, stock, stock_log, and all history.

## Current snapshot facts (captured 2026-09-12)

444 products · 224 ungrouped · 21 product groups · 80 quantity_unit_conversions ·
12 stock · 41 stock_log. (The design doc's 434/214/62 were earlier figures.)
After scrub: `api_keys`=0, `sessions`=0, single user password blanked; all
preserved tables unchanged.
</content>
