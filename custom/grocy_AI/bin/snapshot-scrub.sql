-- snapshot-scrub.sql — light-scrub of a LOCAL Grocy prod snapshot (Phase 6 / 06-01).
--
-- Removes ONLY secret-bearing values. Touches nothing else: products,
-- product_groups, quantity_unit_conversions, stock, stock_log, and all history
-- rows are preserved byte-for-byte so real edge cases survive profiling and
-- rollback rehearsal.
--
-- Secret-bearing columns (enumerated from the live 4.6 schema, not guessed):
--   users.password      bcrypt/argon2id hash      -> replaced with a non-verifiable placeholder
--   api_keys.api_key     REST + iCal API tokens    -> rows deleted (whole row is the secret)
--   sessions.session_key logged-in session tokens  -> rows deleted (ephemeral auth state)
--
-- Idempotent: applying it a second time changes nothing (0 rows affected).
-- Never run this against a production database — it is for the local snapshot only.

BEGIN IMMEDIATE;

-- users: keep the row (username / names are not secrets), blank the password hash.
-- The placeholder is not a valid password_hash(), so password_verify() always fails.
UPDATE users
SET password = '$scrubbed$no-login'
WHERE password <> '$scrubbed$no-login';

-- api_keys: every row carries a live API token -> remove them all.
DELETE FROM api_keys;

-- sessions: every row is a live session token -> remove them all.
DELETE FROM sessions;

COMMIT;

-- Reclaim the freed pages so the scrubbed file carries no secret remnants.
VACUUM;
