-- ============================================================
-- Bible Challenge Arena - Migration: security hardening + offline mode
-- ============================================================
-- Adds the 5 schema changes introduced by the admin-passcode/host-
-- impersonation security hardening and the offline single-player/PWA
-- feature: 3 new tables and 2 new columns on existing tables. Nothing
-- is dropped, renamed, or altered destructively - safe to run against
-- a live database that already has real player/room data.
--
-- Optional: api/db.php's initDB() already applies these same changes
-- automatically the moment your live site handles its first request
-- after you deploy the updated code - this script exists only if you'd
-- rather apply it yourself via phpMyAdmin instead of relying on that.
--
-- Safe to run more than once (fully idempotent): CREATE TABLE IF NOT
-- EXISTS and ADD COLUMN IF NOT EXISTS both no-op if already applied.
-- ADD COLUMN IF NOT EXISTS requires MariaDB (or MySQL 8.0.29+) - on an
-- older plain-MySQL host without it, a "duplicate column" error on
-- either ALTER TABLE line below is harmless and means it was already
-- applied; just continue with the rest of the script.
-- ============================================================

-- 1. Host-impersonation fix: a per-room secret, generated at room
--    creation and returned only to the host's own browser, required
--    by host_action.php/set_room_code.php alongside device_id so a
--    player who merely reads the room's player list can no longer
--    forge host-only actions.
ALTER TABLE rooms ADD COLUMN IF NOT EXISTS host_secret VARCHAR(32);

-- 2. Offline reading-rewards sync idempotency: remembers the last
--    processed sync batch id so a retried/duplicated sync request
--    (api/sync_offline_reading.php) can't double-credit the wallet.
ALTER TABLE bible_reading_progress ADD COLUMN IF NOT EXISTS last_synced_batch_id VARCHAR(36);

-- 3. Admin login sessions: issued by api/admin.php's login action after
--    checking the real (server-only) admin passcode, replacing the old
--    model of resending a shared passcode on every request.
CREATE TABLE IF NOT EXISTS admin_sessions (
    token      VARCHAR(64) PRIMARY KEY,
    created_at BIGINT NOT NULL,
    expires_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Admin login brute-force lockout tracking (5 failed attempts per
--    IP per 15 minutes).
CREATE TABLE IF NOT EXISTS login_attempts (
    id           BIGINT PRIMARY KEY AUTO_INCREMENT,
    ip           VARCHAR(45) NOT NULL,
    attempted_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX IF NOT EXISTS idx_login_attempts_ip ON login_attempts(ip, attempted_at);

-- 5. Generic per-IP rate limiting (room creation, joining, admin
--    exports, etc.)
CREATE TABLE IF NOT EXISTS rate_limits (
    rl_key       VARCHAR(80) PRIMARY KEY,
    window_start BIGINT NOT NULL,
    count        BIGINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
