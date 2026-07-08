<?php
/* ------------------------------------------------------------
   Turn ANY uncaught error/exception/fatal into a clean JSON
   response instead of a raw PHP error page. Without this, a
   crash (e.g. pdo_mysql missing, or MySQL unreachable) makes
   fetch().then(r => r.json()) throw a parse error on the client,
   which looks exactly like a network failure ("Could not reach
   the host server") and hides the real cause.
   ------------------------------------------------------------ */
// Restricts which origins get a CORS response instead of a wildcard "*" -
// this app's own pages only ever call these endpoints same-origin (which
// browsers don't apply CORS to at all), so the allow-list only matters for
// blocking a foreign website's script from reading responses cross-origin.
// Almost every POST endpoint here sends Content-Type: application/json,
// which forces a CORS preflight - a non-matching Origin means the browser
// never even sends the real request. Override via the ALLOWED_ORIGINS env
// var (comma-separated) if the deployment domain differs.
function corsOrigin(): string {
    static $allowed = null;
    if ($allowed === null) {
        $envList = getenv('ALLOWED_ORIGINS');
        $allowed = $envList !== false
            ? array_map('trim', explode(',', $envList))
            : ['https://sdachp.click', 'https://www.sdachp.click'];
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    return in_array($origin, $allowed, true) ? $origin : $allowed[0];
}

function sendJsonError(string $message, int $code = 500): void {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: ' . corsOrigin());
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        http_response_code($code);
    }
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// Real error detail (which can include DB connection strings and full
// server file paths) goes to the server-side PHP error log only - the
// client only ever sees a generic message. Also stops stray PHP warnings/
// notices from printing into a response body ahead of the JSON and
// corrupting it.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_exception_handler(function ($e) {
    error_log('Uncaught exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    sendJsonError('Server error, please try again.');
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('Fatal error: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        sendJsonError('Server error, please try again.');
    }
});

// Optional, git-ignored local config file for secrets that can't be set as
// real environment variables on control-panel-only hosting (most shared PHP
// hosts don't expose an env-var panel for classic PHP the way they do for
// Node/Python apps). Create api/config.local.php directly on the server
// (copy api/config.local.php.example, fill in real values, never commit it)
// to set DB_USER/DB_PASS/ADMIN_PASSCODE via putenv() before the getenv()
// calls below run.
$localConfigFile = __DIR__ . '/config.local.php';
if (file_exists($localConfigFile)) require_once $localConfigFile;

// MySQL connection settings. Host/port/name have safe fallbacks for local/LAN
// dev (KSWEB's bundled MySQL on Android, or a fresh local install). DB_USER
// and DB_PASS deliberately have NO fallback - defaulting to root/no-password
// is fine on a throwaway local dev database but dangerous to carry into a
// real internet-facing deployment by accident, so a missing credential fails
// closed with a clear error instead of silently connecting as root.
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'biblegame');
$envDbUser = getenv('DB_USER');
$envDbPass = getenv('DB_PASS');
define('DB_USER', $envDbUser !== false ? $envDbUser : null);
define('DB_PASS', $envDbPass !== false ? $envDbPass : null);

// Same "no insecure fallback" treatment for the admin passcode - see
// api/admin.php. Null means "not configured", handled there.
$envAdminPasscode = getenv('ADMIN_PASSCODE');
define('ADMIN_PASSCODE', $envAdminPasscode !== false ? $envAdminPasscode : null);

function getDB(): PDO {
    static $db = null;
    if ($db === null) {
        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException('The pdo_mysql PHP extension is not enabled. Enable it in your PHP settings.');
        }
        if (DB_USER === null || DB_PASS === null) {
            throw new RuntimeException('Database not configured: set the DB_USER and DB_PASS environment variables (or create api/config.local.php - see api/config.local.php.example).');
        }
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            // MYSQL_ATTR_FOUND_ROWS: without it, PDOStatement::rowCount() after an
            // UPDATE reports rows actually *changed*, not rows *matched* - unlike
            // SQLite, which always counts matched rows. Several callers (e.g.
            // admin.php's adjust_points, hs_bet.php) use rowCount() === 0 to mean
            // "no matching row", which would misfire as a false negative whenever
            // an UPDATE happens to write back the same value it already had.
            $db = new PDO($dsn, DB_USER, DB_PASS, [PDO::MYSQL_ATTR_FOUND_ROWS => true]);
        } catch (PDOException $e) {
            throw new RuntimeException('Cannot connect to MySQL database: ' . $e->getMessage());
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        initDB($db);
    }
    return $db;
}

// Column types: identifier columns (room codes, device-id UUIDs) are
// VARCHAR so they can be used in PRIMARY KEY/UNIQUE indexes (MySQL requires
// a bounded key length there, unlike SQLite's untyped TEXT). All other
// integer columns are BIGINT rather than INT because created_at/updated_at/
// last_ping/*_start_time store nowMs() millisecond epoch timestamps, which
// overflow a 32-bit INT.
function initDB(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS rooms (
            code VARCHAR(10) PRIMARY KEY,
            host_device_id VARCHAR(36) NOT NULL,
            difficulty VARCHAR(40) DEFAULT 'easy',
            question_count BIGINT DEFAULT 10,
            status VARCHAR(40) DEFAULT 'lobby',
            current_q_idx BIGINT DEFAULT -1,
            q_start_time BIGINT DEFAULT 0,
            q_indices TEXT DEFAULT '[]',
            time_limit BIGINT DEFAULT 30,
            points_awarded BIGINT DEFAULT 0,
            quiz_mode VARCHAR(40) DEFAULT 'difficulty',
            book VARCHAR(100),
            category VARCHAR(100),
            testament VARCHAR(40) DEFAULT 'all',
            pool_size BIGINT DEFAULT 50,
            game_format VARCHAR(40) DEFAULT 'classic',
            created_at BIGINT NOT NULL,
            updated_at BIGINT NOT NULL,
            impostor_word_pair_idx BIGINT DEFAULT -1,
            impostor_id VARCHAR(36),
            impostor_id_2 VARCHAR(36),
            impostor_round BIGINT DEFAULT 1,
            impostor_result VARCHAR(40),
            impostor_last_elim_id VARCHAR(36),
            impostor_last_skipped BIGINT DEFAULT 0,
            draw_turn_order TEXT DEFAULT '[]',
            draw_round BIGINT DEFAULT 1,
            draw_rounds_total BIGINT DEFAULT 1,
            draw_word_choice_indices TEXT DEFAULT '[]',
            draw_word_idx BIGINT DEFAULT -1,
            draw_round_start_time BIGINT DEFAULT 0,
            scrab_turn_order TEXT DEFAULT '[]',
            scrab_round BIGINT DEFAULT 1,
            scrab_board TEXT DEFAULT '[]',
            scrab_bag TEXT DEFAULT '[]',
            scrab_turn_start_time BIGINT DEFAULT 0,
            scrab_pass_streak BIGINT DEFAULT 0,
            scrab_start_time BIGINT DEFAULT 0,
            scrab_time_limit BIGINT DEFAULT 90,
            wordhunt_mode VARCHAR(40) DEFAULT 'race',
            wordhunt_turn_order TEXT DEFAULT '[]',
            wordhunt_round BIGINT DEFAULT 0,
            wordhunt_rounds_total BIGINT DEFAULT 3,
            wordhunt_grid TEXT DEFAULT '[]',
            wordhunt_words TEXT DEFAULT '[]',
            wordhunt_round_start BIGINT DEFAULT 0,
            wordhunt_time_limit BIGINT DEFAULT 180,
            wordhunt_turn_start BIGINT DEFAULT 0,
            wordhunt_pass_streak BIGINT DEFAULT 0,
            wordhunt_turn_idx BIGINT DEFAULT 0,
            blitz_start_time BIGINT DEFAULT 0,
            hs_seat_order TEXT DEFAULT '[]',
            hs_seat_idx BIGINT DEFAULT 0,
            hs_q_count BIGINT DEFAULT 3,
            hs_q_start_time BIGINT DEFAULT 0,
            hs_time_limit BIGINT DEFAULT 25,
            imp_classes_enabled BIGINT DEFAULT 0,
            imp_shielded_id VARCHAR(36),
            imp_spotlight_id VARCHAR(36),
            imp_nullified_vote_id VARCHAR(36),
            imp_shadow_new_id VARCHAR(36),
            impostor_last_phantom BIGINT DEFAULT 0,
            impostor_last_shepherd BIGINT DEFAULT 0,
            impostor_last_healer BIGINT DEFAULT 0,
            host_secret VARCHAR(32)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE TABLE IF NOT EXISTS players (
            device_id VARCHAR(36) NOT NULL,
            room_code VARCHAR(10) NOT NULL,
            name TEXT NOT NULL,
            avatar TEXT NOT NULL,
            score BIGINT DEFAULT 0,
            correct_count BIGINT DEFAULT 0,
            wrong_count BIGINT DEFAULT 0,
            total_time DOUBLE DEFAULT 0,
            is_host BIGINT DEFAULT 0,
            joined_at BIGINT NOT NULL,
            last_ping BIGINT NOT NULL,
            has_shield BIGINT DEFAULT 0,
            streak BIGINT DEFAULT 0,
            best_streak BIGINT DEFAULT 0,
            used_powerups TEXT DEFAULT '[]',
            double_q_idx BIGINT DEFAULT -1,
            frozen_until BIGINT DEFAULT 0,
            eliminated BIGINT DEFAULT 0,
            scrab_rack TEXT DEFAULT '[]',
            blitz_q_idx BIGINT DEFAULT 0,
            team_id BIGINT DEFAULT 0,
            imp_class VARCHAR(40),
            imp_class_used BIGINT DEFAULT 0,
            PRIMARY KEY (device_id, room_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE TABLE IF NOT EXISTS profiles (
            device_id VARCHAR(36) PRIMARY KEY,
            name TEXT NOT NULL,
            avatar TEXT NOT NULL,
            avatar_type VARCHAR(40) DEFAULT 'emoji',
            wallet BIGINT DEFAULT 0,
            equipped_name_effect VARCHAR(100),
            equipped_border VARCHAR(100),
            owned_name_effects TEXT DEFAULT '[]',
            owned_borders TEXT DEFAULT '[]',
            owned_titles TEXT DEFAULT '[]',
            equipped_title VARCHAR(100),
            owned_answer_skins TEXT DEFAULT '[]',
            equipped_answer_skin VARCHAR(100),
            owned_clue_themes TEXT DEFAULT '[]',
            equipped_clue_theme VARCHAR(100),
            owned_anim_borders TEXT DEFAULT '[]',
            equipped_anim_border VARCHAR(100),
            owned_nick_colors TEXT DEFAULT '[]',
            equipped_nick_color VARCHAR(100),
            owned_emoji_frames TEXT DEFAULT '[]',
            equipped_emoji_frame VARCHAR(100),
            booster_count BIGINT DEFAULT 0,
            updated_at BIGINT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE TABLE IF NOT EXISTS answers (
            room_code VARCHAR(10) NOT NULL,
            device_id VARCHAR(36) NOT NULL,
            q_idx BIGINT NOT NULL,
            choice_idx BIGINT NOT NULL,
            is_correct BIGINT NOT NULL,
            points BIGINT NOT NULL,
            time_taken DOUBLE NOT NULL,
            submitted_at BIGINT NOT NULL,
            PRIMARY KEY (room_code, device_id, q_idx)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE TABLE IF NOT EXISTS custom_questions (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            book TEXT NOT NULL,
            category TEXT NOT NULL,
            difficulty VARCHAR(40) NOT NULL,
            question TEXT NOT NULL,
            choice1 TEXT NOT NULL,
            choice2 TEXT NOT NULL,
            choice3 TEXT NOT NULL,
            choice4 TEXT NOT NULL,
            answer TEXT NOT NULL,
            reference TEXT DEFAULT '',
            created_at BIGINT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE TABLE IF NOT EXISTS room_events (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            room_code VARCHAR(10) NOT NULL,
            device_id VARCHAR(36) NOT NULL,
            name TEXT NOT NULL,
            avatar TEXT NOT NULL,
            type VARCHAR(40) NOT NULL,
            payload TEXT NOT NULL,
            created_at BIGINT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE INDEX IF NOT EXISTS idx_room_events_room ON room_events(room_code, id);
        CREATE TABLE IF NOT EXISTS impostor_clues (
            room_code VARCHAR(10) NOT NULL,
            device_id VARCHAR(36) NOT NULL,
            round BIGINT NOT NULL,
            clue TEXT NOT NULL,
            submitted_at BIGINT NOT NULL,
            PRIMARY KEY (room_code, device_id, round)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE TABLE IF NOT EXISTS impostor_votes (
            room_code VARCHAR(10) NOT NULL,
            device_id VARCHAR(36) NOT NULL,
            round BIGINT NOT NULL,
            target_device_id VARCHAR(36) NOT NULL,
            vote_weight BIGINT DEFAULT 1,
            submitted_at BIGINT NOT NULL,
            PRIMARY KEY (room_code, device_id, round)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE TABLE IF NOT EXISTS leaderboard_stats (
            device_id VARCHAR(36) NOT NULL,
            game_format VARCHAR(40) NOT NULL,
            total_points BIGINT DEFAULT 0,
            updated_at BIGINT NOT NULL,
            PRIMARY KEY (device_id, game_format)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE TABLE IF NOT EXISTS drawing_strokes (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            room_code VARCHAR(10) NOT NULL,
            round BIGINT NOT NULL,
            drawer_device_id VARCHAR(36) NOT NULL,
            points TEXT NOT NULL,
            color VARCHAR(40) NOT NULL,
            line_width BIGINT NOT NULL,
            created_at BIGINT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE INDEX IF NOT EXISTS idx_drawing_strokes_room ON drawing_strokes(room_code, round, id);
        CREATE TABLE IF NOT EXISTS drawing_guesses (
            room_code VARCHAR(10) NOT NULL,
            device_id VARCHAR(36) NOT NULL,
            round BIGINT NOT NULL,
            guess_text TEXT NOT NULL,
            is_correct BIGINT NOT NULL,
            rank BIGINT,
            submitted_at BIGINT NOT NULL,
            PRIMARY KEY (room_code, device_id, round)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE TABLE IF NOT EXISTS drawing_guess_log (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            room_code VARCHAR(10) NOT NULL,
            device_id VARCHAR(36) NOT NULL,
            round BIGINT NOT NULL,
            guess_text TEXT NOT NULL,
            is_correct BIGINT NOT NULL,
            created_at BIGINT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE INDEX IF NOT EXISTS idx_drawing_guess_log_room ON drawing_guess_log(room_code, round, id);
        CREATE TABLE IF NOT EXISTS scrab_plays (
            id          BIGINT PRIMARY KEY AUTO_INCREMENT,
            room_code   VARCHAR(10) NOT NULL,
            device_id   VARCHAR(36) NOT NULL,
            turn        BIGINT NOT NULL,
            word        TEXT NOT NULL,
            score       BIGINT NOT NULL,
            cells       TEXT NOT NULL,
            bonus       TEXT,
            created_at  BIGINT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE INDEX IF NOT EXISTS idx_scrab_plays_room ON scrab_plays(room_code, id);
        CREATE TABLE IF NOT EXISTS wordhunt_claims (
            id          BIGINT PRIMARY KEY AUTO_INCREMENT,
            room_code   VARCHAR(10) NOT NULL,
            device_id   VARCHAR(36) NOT NULL,
            round       BIGINT NOT NULL,
            word        VARCHAR(100) NOT NULL,
            score       BIGINT NOT NULL,
            cells       TEXT NOT NULL,
            bonus       TEXT,
            created_at  BIGINT NOT NULL,
            UNIQUE(room_code, round, word)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE INDEX IF NOT EXISTS idx_wordhunt_claims_room ON wordhunt_claims(room_code, id);
        CREATE TABLE IF NOT EXISTS hs_bets (
            id            BIGINT PRIMARY KEY AUTO_INCREMENT,
            room_code     VARCHAR(10) NOT NULL,
            seater_id     VARCHAR(36) NOT NULL,
            bettor_id     VARCHAR(36) NOT NULL,
            q_idx         BIGINT NOT NULL,
            bet_correct   BIGINT NOT NULL,
            bet_amount    BIGINT DEFAULT 0,
            created_at    BIGINT NOT NULL,
            UNIQUE(room_code, bettor_id, q_idx)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE INDEX IF NOT EXISTS idx_hs_bets_room ON hs_bets(room_code, q_idx);
        CREATE TABLE IF NOT EXISTS imp_class_peeks (
            id          BIGINT PRIMARY KEY AUTO_INCREMENT,
            room_code   VARCHAR(10) NOT NULL,
            peeker_id   VARCHAR(36) NOT NULL,
            target_id   VARCHAR(36) NOT NULL,
            is_impostor BIGINT NOT NULL,
            created_at  BIGINT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE UNIQUE INDEX IF NOT EXISTS idx_imp_class_peeks_uniq ON imp_class_peeks(room_code, peeker_id, target_id);
        CREATE TABLE IF NOT EXISTS imp_mimic_peeks (
            id          BIGINT PRIMARY KEY AUTO_INCREMENT,
            room_code   VARCHAR(10) NOT NULL,
            mimic_id    VARCHAR(36) NOT NULL,
            target_id   VARCHAR(36) NOT NULL,
            clue        TEXT NOT NULL,
            round       BIGINT NOT NULL,
            created_at  BIGINT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE UNIQUE INDEX IF NOT EXISTS idx_imp_mimic_peeks_uniq ON imp_mimic_peeks(room_code, mimic_id);
        CREATE TABLE IF NOT EXISTS bible_kjv (
            id        BIGINT PRIMARY KEY AUTO_INCREMENT,
            book_num  BIGINT NOT NULL,
            book_name VARCHAR(100) NOT NULL,
            testament VARCHAR(10) NOT NULL,
            chapter   BIGINT NOT NULL,
            verse     BIGINT NOT NULL,
            text      TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE INDEX IF NOT EXISTS idx_bible_book_ch ON bible_kjv(book_num, chapter);
        CREATE TABLE IF NOT EXISTS bible_abhil82 (
            id        BIGINT PRIMARY KEY AUTO_INCREMENT,
            book_num  BIGINT NOT NULL,
            book_name VARCHAR(100) NOT NULL,
            testament VARCHAR(10) NOT NULL,
            chapter   BIGINT NOT NULL,
            verse     BIGINT NOT NULL,
            text      TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE INDEX IF NOT EXISTS idx_bible_abhil82_book_ch ON bible_abhil82(book_num, chapter);
        CREATE TABLE IF NOT EXISTS bible_reading_progress (
            device_id         VARCHAR(36) PRIMARY KEY,
            active_ms_accum   BIGINT DEFAULT 0,
            last_heartbeat_at BIGINT DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE TABLE IF NOT EXISTS admin_sessions (
            token      VARCHAR(64) PRIMARY KEY,
            created_at BIGINT NOT NULL,
            expires_at BIGINT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE TABLE IF NOT EXISTS login_attempts (
            id           BIGINT PRIMARY KEY AUTO_INCREMENT,
            ip           VARCHAR(45) NOT NULL,
            attempted_at BIGINT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE INDEX IF NOT EXISTS idx_login_attempts_ip ON login_attempts(ip, attempted_at);
        CREATE TABLE IF NOT EXISTS rate_limits (
            rl_key       VARCHAR(80) PRIMARY KEY,
            window_start BIGINT NOT NULL,
            count        BIGINT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Migration: `rooms` already existed (created before host_secret was
    // introduced) on some deployments - CREATE TABLE IF NOT EXISTS above only
    // applies to brand-new tables, not new columns on an existing one. MySQL
    // (unlike MariaDB) has no ADD COLUMN IF NOT EXISTS, so check first.
    $hasHostSecret = $db->query("
        SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'rooms' AND column_name = 'host_secret'
    ")->fetchColumn();
    if (!$hasHostSecret) {
        $db->exec("ALTER TABLE rooms ADD COLUMN host_secret VARCHAR(32)");
    }

    // Same pattern: bible_reading_progress predates offline reading-rewards
    // sync (see api/sync_offline_reading.php), which needs a column to
    // remember the last processed sync batch so a retried/duplicated sync
    // request can't double-credit.
    $hasBatchId = $db->query("
        SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'bible_reading_progress' AND column_name = 'last_synced_batch_id'
    ")->fetchColumn();
    if (!$hasBatchId) {
        $db->exec("ALTER TABLE bible_reading_progress ADD COLUMN last_synced_batch_id VARCHAR(36)");
    }
}

function jsonOut(array $data, int $code = 200): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: ' . corsOrigin());
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getInput(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

function nowMs(): int {
    return (int)(microtime(true) * 1000);
}

function generateCode(): string {
    return str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
}

/* ------------------------------------------------------------
   ADMIN SESSION TOKENS
   Replaces the old "resend the hardcoded passcode on every
   request" model. api/admin.php's login action issues a random
   token after checking ADMIN_PASSCODE once; every other admin
   action (admin.php, admin_export_download.php,
   upload_questions.php, scrape_abhil82.php) just validates that
   token here instead of re-checking a shared secret.
   ------------------------------------------------------------ */
/* ------------------------------------------------------------
   Basic per-key rate limiting (fixed window), backed by the
   rate_limits table. Good enough insurance against a bored script
   hammering an endpoint - not meant to withstand a determined
   distributed attacker, which is out of scope for this app's
   friends-and-family deployment scale.
   ------------------------------------------------------------ */
function checkRateLimit(PDO $db, string $key, int $maxCount, int $windowMs): bool {
    $now = nowMs();
    $stmt = $db->prepare("SELECT window_start, count FROM rate_limits WHERE rl_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();

    if (!$row || $now - (int)$row['window_start'] >= $windowMs) {
        $db->prepare("REPLACE INTO rate_limits (rl_key, window_start, count) VALUES (?, ?, 1)")->execute([$key, $now]);
        return true;
    }
    if ((int)$row['count'] >= $maxCount) return false;

    $db->prepare("UPDATE rate_limits SET count = count + 1 WHERE rl_key = ?")->execute([$key]);
    return true;
}

function clientIp(): string {
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function requireAdminToken(PDO $db, string $token): void {
    if ($token === '') jsonOut(['success' => false, 'error' => 'Unauthorized'], 401);
    $stmt = $db->prepare("SELECT expires_at FROM admin_sessions WHERE token = ?");
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row || (int)$row['expires_at'] < nowMs()) {
        jsonOut(['success' => false, 'error' => 'Session expired. Please log in again.'], 401);
    }
}

function cleanStale(PDO $db): void {
    $cutoff = nowMs() - 86400000; // 24 hours
    $db->prepare("DELETE FROM answers WHERE room_code IN (SELECT code FROM rooms WHERE created_at < ?)")->execute([$cutoff]);
    $db->prepare("DELETE FROM players WHERE room_code IN (SELECT code FROM rooms WHERE created_at < ?)")->execute([$cutoff]);
    $db->prepare("DELETE FROM room_events WHERE room_code IN (SELECT code FROM rooms WHERE created_at < ?)")->execute([$cutoff]);
    $db->prepare("DELETE FROM impostor_clues WHERE room_code IN (SELECT code FROM rooms WHERE created_at < ?)")->execute([$cutoff]);
    $db->prepare("DELETE FROM impostor_votes WHERE room_code IN (SELECT code FROM rooms WHERE created_at < ?)")->execute([$cutoff]);
    $db->prepare("DELETE FROM drawing_strokes WHERE room_code IN (SELECT code FROM rooms WHERE created_at < ?)")->execute([$cutoff]);
    $db->prepare("DELETE FROM drawing_guesses WHERE room_code IN (SELECT code FROM rooms WHERE created_at < ?)")->execute([$cutoff]);
    $db->prepare("DELETE FROM drawing_guess_log WHERE room_code IN (SELECT code FROM rooms WHERE created_at < ?)")->execute([$cutoff]);
    $db->prepare("DELETE FROM scrab_plays WHERE room_code IN (SELECT code FROM rooms WHERE created_at < ?)")->execute([$cutoff]);
    $db->prepare("DELETE FROM wordhunt_claims WHERE room_code IN (SELECT code FROM rooms WHERE created_at < ?)")->execute([$cutoff]);
    $db->prepare("DELETE FROM hs_bets WHERE room_code IN (SELECT code FROM rooms WHERE created_at < ?)")->execute([$cutoff]);
    $db->prepare("DELETE FROM imp_class_peeks WHERE room_code IN (SELECT code FROM rooms WHERE created_at < ?)")->execute([$cutoff]);
    $db->prepare("DELETE FROM imp_mimic_peeks WHERE room_code IN (SELECT code FROM rooms WHERE created_at < ?)")->execute([$cutoff]);
    $db->prepare("DELETE FROM rooms WHERE created_at < ?")->execute([$cutoff]);
}

// A lobby whose host hasn't polled in 90s has been abandoned (tab closed or
// backgrounded long enough for the browser to throttle JS timers — browsers
// can slow setInterval to once per minute or more in hidden tabs). 90s gives
// the host a comfortable buffer after switching away and returning.
function cleanAbandonedLobbies(PDO $db): void {
    $cutoff = nowMs() - 90000;
    $stmt = $db->prepare("
        SELECT code FROM rooms
        WHERE status = 'lobby'
        AND code NOT IN (SELECT room_code FROM players WHERE is_host = 1 AND last_ping >= ?)
    ");
    $stmt->execute([$cutoff]);
    $codes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$codes) return;

    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    foreach ([
        'answers', 'players', 'room_events', 'impostor_clues', 'impostor_votes',
        'drawing_strokes', 'drawing_guesses', 'drawing_guess_log', 'scrab_plays', 'wordhunt_claims', 'hs_bets',
        'imp_class_peeks', 'imp_mimic_peeks',
    ] as $table) {
        $db->prepare("DELETE FROM $table WHERE room_code IN ($placeholders)")->execute($codes);
    }
    $db->prepare("DELETE FROM rooms WHERE code IN ($placeholders)")->execute($codes);
}

function markStalePlayers(PDO $db, string $roomCode): void {
    // Remove non-host players who haven't pinged in 30 seconds
    $cutoff = nowMs() - 30000;
    $db->prepare("DELETE FROM players WHERE room_code = ? AND is_host = 0 AND last_ping < ?")->execute([$roomCode, $cutoff]);
}

function getTimeLimitForDifficulty(string $diff): int {
    switch ($diff) {
        case 'easy': return 30;
        case 'medium': return 25;
        case 'hard': return 20;
        case 'expert': return 15;
        default: return 30;
    }
}

/* ------------------------------------------------------------
   WORD IMPOSTOR - shared helpers
   No timer in this format: every round either auto-advances once
   every alive contestant has acted, or waits on an explicit host
   action (host_action.php's impostor_* cases). Both room_state.php
   (auto-advance) and host_action.php (tiebreak resolution / force
   advance) call into this same elimination logic so a round only
   ever ends one way, however it got triggered.
   ------------------------------------------------------------ */
// Point economy rebalance: a full Word/Sketch Impostor game can run several
// clue-or-sketch + vote rounds over many minutes, but used to pay out only
// 100-250 total - a small fraction of what a single 30-second trivia
// question earns (500-1500+ with streak). Scaled up roughly 8x so a win
// is worth an amount of time actually invested, without changing the
// well-tuned trivia-family scoring formula in submit_answer.php.
const IMPOSTOR_CREW_WIN_POINTS = 800;
const IMPOSTOR_VOTE_BONUS = 400;
const IMPOSTOR_WIN_POINTS = 2000;

function impostorAliveContestants(PDO $db, string $code): array {
    $stmt = $db->prepare("SELECT device_id FROM players WHERE room_code = ? AND is_host = 0 AND eliminated = 0");
    $stmt->execute([$code]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Returns the 1 or 2 impostor device_ids for a room (impostor_id_2 is only
// set for 8+ player games - see host_action.php's start_game).
function impostorIdsOf(array $room): array {
    $ids = [];
    if (!empty($room['impostor_id'])) $ids[] = $room['impostor_id'];
    if (!empty($room['impostor_id_2'])) $ids[] = $room['impostor_id_2'];
    return $ids;
}

// Eliminates $eliminatedId (or, if null, records a skipped round - the
// tiebreak host chose not to eliminate anyone) then checks both win
// conditions: the crew catching every impostor, or the impostor side
// surviving down to a headcount where they outnumber/match the crew
// (alive <= alive impostors + 1). Falls through to the next round otherwise.
function applyImpostorElimination(PDO $db, string $code, ?string $eliminatedId): void {
    $now = nowMs();
    $roomStmt = $db->prepare("SELECT * FROM rooms WHERE code = ?");
    $roomStmt->execute([$code]);
    $room = $roomStmt->fetch();
    if (!$room) return;
    $impostorIds = impostorIdsOf($room);
    $round = (int)$room['impostor_round'];

    // Class ability checks (only when someone would actually be eliminated)
    $savedByHealer   = false;
    $phantomDeceived = false;
    $shepherdReveal  = false;
    if ($eliminatedId !== null) {
        // 1. Guardian shield
        if ($room['imp_shielded_id'] === $eliminatedId) {
            $eliminatedId = null;
            $db->prepare("UPDATE rooms SET imp_shielded_id = NULL WHERE code = ?")->execute([$code]);
        }
    }
    if ($eliminatedId !== null) {
        $playerStmt = $db->prepare("SELECT imp_class, imp_class_used FROM players WHERE room_code = ? AND device_id = ?");
        $playerStmt->execute([$code, $eliminatedId]);
        $elPlayer = $playerStmt->fetch();
        $cls  = $elPlayer['imp_class']      ?? null;
        $used = (int)($elPlayer['imp_class_used'] ?? 1);

        // 2. Healer: auto-survive elimination once
        if ($cls === 'healer' && $used === 0) {
            $db->prepare("UPDATE players SET imp_class_used = 1 WHERE room_code = ? AND device_id = ?")->execute([$code, $eliminatedId]);
            $savedByHealer = true;
            $eliminatedId  = null;
        }
        // 3. Phantom: eliminate but deceive about role (only impostors have Phantom)
        if ($eliminatedId !== null && $cls === 'phantom' && $used === 0) {
            $db->prepare("UPDATE players SET imp_class_used = 1 WHERE room_code = ? AND device_id = ?")->execute([$code, $eliminatedId]);
            $phantomDeceived = true;
        }
        // 4. Shepherd: reveal role when eliminated
        if ($eliminatedId !== null && $cls === 'shepherd') {
            $shepherdReveal = true;
        }
    }

    // Clear shield regardless of whether it triggered
    if (!empty($room['imp_shielded_id'])) {
        $db->prepare("UPDATE rooms SET imp_shielded_id = NULL WHERE code = ?")->execute([$code]);
    }

    if ($eliminatedId !== null) {
        $db->prepare("UPDATE players SET eliminated = 1 WHERE room_code = ? AND device_id = ?")->execute([$code, $eliminatedId]);
        $db->prepare("UPDATE rooms SET impostor_last_elim_id = ?, impostor_last_skipped = 0, impostor_last_phantom = ?, impostor_last_shepherd = ? WHERE code = ?")->execute([$eliminatedId, $phantomDeceived ? 1 : 0, $shepherdReveal ? 1 : 0, $code]);
    } else {
        $db->prepare("UPDATE rooms SET impostor_last_elim_id = NULL, impostor_last_skipped = ?, impostor_last_healer = ?, impostor_last_phantom = 0, impostor_last_shepherd = 0 WHERE code = ?")->execute([$savedByHealer ? 0 : 1, $savedByHealer ? 1 : 0, $code]);
    }

    $aliveIds = impostorAliveContestants($db, $code);
    $aliveImpostorIds = array_values(array_intersect($impostorIds, $aliveIds));
    $crewWin = ($eliminatedId !== null && in_array($eliminatedId, $impostorIds, true) && empty($aliveImpostorIds));
    $impostorWin = (!$crewWin && !empty($aliveImpostorIds) && count($aliveIds) <= count($aliveImpostorIds) + 1);

    if ($crewWin) {
        foreach ($aliveIds as $pid) {
            $voteStmt = $db->prepare("SELECT target_device_id FROM impostor_votes WHERE room_code = ? AND device_id = ? AND round = ?");
            $voteStmt->execute([$code, $pid, $round]);
            $votedForImpostor = in_array($voteStmt->fetchColumn(), $impostorIds, true);
            $points = IMPOSTOR_CREW_WIN_POINTS + ($votedForImpostor ? IMPOSTOR_VOTE_BONUS : 0);
            $db->prepare("UPDATE players SET score = score + ? WHERE room_code = ? AND device_id = ?")
               ->execute([$points, $code, $pid]);
        }
        $db->prepare("UPDATE rooms SET status = 'finished', impostor_result = 'crew_win', updated_at = ? WHERE code = ?")->execute([$now, $code]);
    } elseif ($impostorWin) {
        foreach ($aliveImpostorIds as $pid) {
            $db->prepare("UPDATE players SET score = score + ? WHERE room_code = ? AND device_id = ?")
               ->execute([IMPOSTOR_WIN_POINTS, $code, $pid]);
        }
        $db->prepare("UPDATE rooms SET status = 'finished', impostor_result = 'impostor_win', updated_at = ? WHERE code = ?")->execute([$now, $code]);
    } else {
        $db->prepare("UPDATE rooms SET status = 'imp_elim', updated_at = ? WHERE code = ?")->execute([$now, $code]);
    }
}

// Tallies this round's votes and either eliminates the sole top-voted
// player, or - on a tie for most votes - hands the decision to the host
// via the imp_tiebreak state instead of guessing.
// Saboteur: excludes the nullified voter's vote (imp_nullified_vote_id).
// Elder: uses SUM(vote_weight) so Elder's vote counts as 2.
function resolveImpostorVotes(PDO $db, string $code, int $round): void {
    $roomStmt = $db->prepare("SELECT imp_nullified_vote_id FROM rooms WHERE code = ?");
    $roomStmt->execute([$code]);
    $room = $roomStmt->fetch();
    $nullifiedId = $room['imp_nullified_vote_id'] ?? null;

    if ($nullifiedId) {
        $tallyStmt = $db->prepare("SELECT target_device_id, SUM(vote_weight) AS cnt FROM impostor_votes WHERE room_code = ? AND round = ? AND device_id != ? GROUP BY target_device_id");
        $tallyStmt->execute([$code, $round, $nullifiedId]);
    } else {
        $tallyStmt = $db->prepare("SELECT target_device_id, SUM(vote_weight) AS cnt FROM impostor_votes WHERE room_code = ? AND round = ? GROUP BY target_device_id");
        $tallyStmt->execute([$code, $round]);
    }
    $rows = $tallyStmt->fetchAll();
    if (empty($rows)) {
        applyImpostorElimination($db, $code, null);
        return;
    }
    $maxCount = max(array_map(fn($r) => (int)$r['cnt'], $rows));
    $topRows = array_values(array_filter($rows, fn($r) => (int)$r['cnt'] === $maxCount));
    if (count($topRows) === 1) {
        applyImpostorElimination($db, $code, $topRows[0]['target_device_id']);
    } else {
        $db->prepare("UPDATE rooms SET status = 'imp_tiebreak', updated_at = ? WHERE code = ?")->execute([nowMs(), $code]);
    }
}

/* ------------------------------------------------------------
   SKETCH & GUESS - shared helpers
   Turn order is the join order of contestants, fixed at start_game
   and never re-shuffled per round. The current drawer is derived
   from draw_round (1-based, monotonic across the whole game) rather
   than storing a separate turn-index column - draw_round also
   doubles as the score-feed/round number shown to players.
   Like submit_answer.php's is_correct flag, guess correctness is
   judged client-side (against js/drawing_words.js, which every
   client already has) and the server simply trusts and records it -
   the word index is never secret in a way that matters since the
   full word bank ships to every client regardless of role.
   ------------------------------------------------------------ */
// Point economy rebalance: scaled 4x alongside Bible Scrabble/Word Hunt/Hot
// Seat's flat bettor payout, bringing a well-played round's total closer to
// the trivia-family's few-thousand-point scale instead of a few hundred.
const DRAW_GUESS_POINTS = [1200, 800, 400]; // rank 1/2/3; rank 4+ gets nothing
const DRAW_DRAWER_BONUS = 200; // per correct guesser, capped at the first 3
const DRAW_ROUND_TIME_LIMIT = 75; // seconds for draw_active before auto-reveal

function drawTurnOrderOf(array $room): array {
    return json_decode($room['draw_turn_order'] ?? '[]', true) ?: [];
}

function currentDrawerId(array $room): ?string {
    $order = drawTurnOrderOf($room);
    if (empty($order)) return null;
    $idx = ((int)$room['draw_round'] - 1) % count($order);
    return $order[$idx];
}

// Sketch Impostor stores every player's strokes under this one fixed round
// for the whole game (rather than a per-turn value) so a player's sketch
// carries over and keeps growing across every elimination round they get a
// turn in, instead of starting over each round - drawer_device_id (already
// stored per stroke) is what disambiguates whose sketch is whose, not round.
const SKETCHIMP_STROKE_ROUND = 1;

// Ends the current drawing round (called once draw_active should advance to
// draw_reveal, whether triggered by the timer, by 3 correct guesses already
// recorded, or by the host's force-advance) and decides whether the whole
// game is finished or there's another turn to take.
function finishDrawRound(PDO $db, string $code): void {
    $db->prepare("UPDATE rooms SET status = 'draw_reveal', updated_at = ? WHERE code = ?")->execute([nowMs(), $code]);
}

function advanceDrawTurn(PDO $db, string $code): void {
    $now = nowMs();
    $roomStmt = $db->prepare("SELECT * FROM rooms WHERE code = ?");
    $roomStmt->execute([$code]);
    $room = $roomStmt->fetch();
    if (!$room) return;
    $order = drawTurnOrderOf($room);
    $nextRound = (int)$room['draw_round'] + 1;
    $totalTurns = count($order) * max(1, (int)$room['draw_rounds_total']);
    if (empty($order) || $nextRound > $totalTurns) {
        $db->prepare("UPDATE rooms SET status = 'finished', updated_at = ? WHERE code = ?")->execute([$now, $code]);
        return;
    }
    $choiceIndices = pickDrawWordChoices();
    $db->prepare("UPDATE rooms SET status = 'draw_choose', draw_round = ?, draw_word_choice_indices = ?, draw_word_idx = -1, updated_at = ? WHERE code = ?")
       ->execute([$nextRound, json_encode($choiceIndices), $now, $code]);
}

// js/drawing_words.js DrawingWords.WORDS currently has 240 entries; kept in
// sync manually with that file and api/drawing_words.php's DRAW_WORDS mirror.
const DRAW_WORD_BANK_SIZE = 240;

function pickDrawWordChoices(): array {
    $pool = range(0, DRAW_WORD_BANK_SIZE - 1);
    shuffle($pool);
    return array_slice($pool, 0, 4);
}

/* ------------------------------------------------------------
   BIBLE READING REWARDS - shared rules
   Used by both api/bible_reading_heartbeat.php (real-time, ~5s
   ticks while the connection is live) and
   api/sync_offline_reading.php (replays a batch of ticks that
   happened while offline, using client-reported timestamps
   instead of a server-clock diff, then clips the total against a
   wall-clock ceiling only the server can attest to - see that
   file for the anti-fraud reasoning). Both endpoints apply these
   exact thresholds so an interval either counts or doesn't for
   the same reasons regardless of which path validated it.
   ------------------------------------------------------------ */
const READING_MIN_GAP_MS        = 500;   // faster than this = duplicate/spam call, ignore
const READING_MAX_GAP_MS        = 8000;  // slower than this = tab was hidden/suspended, ignore
const READING_MIN_EVENTS        = 2;     // must show at least this many scroll/touch events
const READING_MIN_SCROLL_PX     = 10;    // ...and at least this much actual scroll movement
const READING_MS_PER_REWARD     = 60000; // 1 minute of validated reading...
const READING_POINTS_PER_REWARD = 20;    // ...= 20 points

// Converts this device's CURRENT active_ms_accum into whole reward chunks,
// credits the resulting points to the wallet, and leaves the remainder in
// place. Callers are responsible for having already folded any newly-
// validated reading time into active_ms_accum before calling this.
function creditReadingAccum(PDO $db, string $deviceId, int $now): int {
    $db->beginTransaction();
    $sel = $db->prepare("SELECT active_ms_accum FROM bible_reading_progress WHERE device_id = ? FOR UPDATE");
    $sel->execute([$deviceId]);
    $accum = (int)$sel->fetchColumn();

    $creditedPoints = 0;
    $wholeRewards = intdiv($accum, READING_MS_PER_REWARD);
    if ($wholeRewards > 0) {
        $creditedPoints = $wholeRewards * READING_POINTS_PER_REWARD;
        $accum = $accum % READING_MS_PER_REWARD;

        $db->prepare("UPDATE bible_reading_progress SET active_ms_accum = ? WHERE device_id = ?")
           ->execute([$accum, $deviceId]);

        $db->prepare("INSERT INTO profiles (device_id, name, avatar, wallet, updated_at) VALUES (?, '', '', ?, ?)
                      ON DUPLICATE KEY UPDATE wallet = wallet + VALUES(wallet), updated_at = VALUES(updated_at)")
           ->execute([$deviceId, $creditedPoints, $now]);
    }
    $db->commit();
    return $creditedPoints;
}
