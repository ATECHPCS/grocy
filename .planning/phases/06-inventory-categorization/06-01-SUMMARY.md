# 06-01 — Snapshot tooling + refresh script + gitignore (SUMMARY)

**Status:** complete. A single command yields a local, gitignored, secret-scrubbed,
production-shaped Grocy SQLite snapshot. No production write occurred.

## Artifacts

| Path | Provides |
|---|---|
| `custom/grocy_AI/bin/snapshot-refresh.sh` | SSH → in-container PDO `VACUUM INTO` (read-only) → `docker cp` → `scp` down → apply scrub → land gitignored snapshot. Idempotent; `--force`/`--dry-run`; refuses to clobber; prints final path + product count. |
| `custom/grocy_AI/bin/snapshot-scrub.sql` | Light-scrub: blank `users.password`, empty `api_keys`, empty `sessions`. Idempotent. Touches no product/group/conversion/stock/history data. |
| `.planning/phases/06-inventory-categorization/SNAPSHOT.md` | How to obtain the prod DB, refresh usage, where the snapshot lives, access + transport notes. |
| `.gitignore` (repo root) | Ignores `custom/grocy_AI/.snapshots/` and `custom/grocy_AI/**/*.sqlite`. |

Produced (gitignored, not committed):
`custom/grocy_AI/.snapshots/grocy-prod.sqlite` (scrubbed) and `…/grocy-prod-raw.sqlite` (raw).

## Pinned acquisition command

Prod Grocy (`grocy` container, `atechpcs/grocy-ai:4.6`) runs on periphery host
**`10.10.0.156`** ("Personal Docker (102)"/LXC 102); DB bind-mounted at
`/config/data/grocy.db`. Reached via **direct SSH** with 1Password key
**`ssh:Personal Docker (102)`** (vault `API/SSH/Tokens`) as `root@10.10.0.156`.
Consistent copy via PHP PDO `VACUUM INTO` (Grocy PHP has `pdo_sqlite` only; no
`sqlite3` CLI anywhere); source `md5_file()` asserted unchanged before/after.
Transferred with `scp` — **never** through a Komodo terminal (16 MiB core↔periphery
frame cap; see SNAPSHOT.md + the incident note below).

## Scrubbed columns (enumerated from the live 4.6 schema)

`users.password` → `$scrubbed$no-login` (non-verifiable) · `api_keys` → 0 rows ·
`sessions` → 0 rows. No webauthn/2FA tables; `grocy_ai_*` tables hold no secrets;
companion API key is an env var, not in the DB.

## Verification (all green)

- Raw snapshot opens; `products`=444 (numeric, >0). Source DB md5 unchanged → zero prod writes.
- Scrubbed snapshot: `products`=444 (== raw), `api_keys`=0, `sessions`=0, all user passwords blanked.
- Preserved unchanged: product_groups=21, quantity_unit_conversions=80, stock=12, stock_log=41.
- Scrub re-applied → 0 changes (idempotent). Refresh without `--force` refuses to overwrite.
- `git check-ignore` confirms both snapshot files ignored; `git status` shows only the two `bin/` scripts.
- Komodo server 102 = `Ok`, 0 lingering terminals.

Snapshot facts 2026-09-12: 444 products · 224 ungrouped (~50%) · 21 groups · 80
conversions. (Design doc's 434/214/62 were earlier figures; downstream plans 06-02…06-07
should use the live counts.)

## Incident (caused + resolved during this plan)

First transport attempt streamed ~27 MB of base64 through a Komodo Server terminal.
That exceeded periphery's 16 MiB core↔periphery websocket frame cap and wedged the
link — Komodo reported host 102 **NotOk** (containers kept running; only Komodo
management/monitoring affected). `DeleteAllTerminals` could not fix it (same choked
channel). **Resolved** by `systemctl restart periphery.service` over SSH to
`root@10.10.0.156`; server flipped back to `Ok` in ~6 s. The committed script uses
`scp` so this cannot recur. Lesson recorded to memory.
</content>
