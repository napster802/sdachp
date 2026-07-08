<?php
/* ------------------------------------------------------------
   Admin dashboard backend.
   All actions require the correct passcode in the POST body.
   ------------------------------------------------------------ */
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { jsonOut([]); }

$input    = getInput();
$action   = trim($input['action']   ?? '');
$passcode = trim($input['passcode'] ?? '');

if ($passcode !== '12345678') {
    jsonOut(['success' => false, 'error' => 'Unauthorized'], 401);
}

$db = getDB();

// ------------------------------------------------------------------ get_stats
if ($action === 'get_stats') {
    $totalPlayers = (int)$db->query("SELECT COUNT(*) FROM profiles")->fetchColumn();
    $totalGames   = (int)$db->query("SELECT COUNT(*) FROM rooms")->fetchColumn();

    $onlineCutoff = nowMs() - 30000;
    $stmt = $db->prepare("SELECT COUNT(DISTINCT device_id) FROM players WHERE last_ping > ?");
    $stmt->execute([$onlineCutoff]);
    $onlinePlayers = (int)$stmt->fetchColumn();

    $totalWallet = (int)$db->query("SELECT COALESCE(SUM(wallet), 0) FROM profiles")->fetchColumn();

    jsonOut([
        'success'         => true,
        'total_players'   => $totalPlayers,
        'total_games'     => $totalGames,
        'online_players'  => $onlinePlayers,
        'total_wallet'    => $totalWallet,
    ]);
}

// ----------------------------------------------------------------- get_players
if ($action === 'get_players') {
    $stmt = $db->query("
        SELECT
            p.device_id,
            p.name,
            p.avatar,
            p.avatar_type,
            p.wallet,
            p.updated_at,
            (SELECT COUNT(*) FROM players pl WHERE pl.device_id = p.device_id) AS total_games,
            (SELECT MAX(pl2.last_ping) FROM players pl2 WHERE pl2.device_id = p.device_id) AS last_ping
        FROM profiles p
        ORDER BY p.wallet DESC
        LIMIT 200
    ");
    $rows = $stmt->fetchAll();

    // Cast numeric fields so JSON encodes them as numbers, not strings.
    foreach ($rows as &$row) {
        $row['wallet']      = (int)$row['wallet'];
        $row['updated_at']  = (int)$row['updated_at'];
        $row['total_games'] = (int)$row['total_games'];
        $row['last_ping']   = $row['last_ping'] !== null ? (int)$row['last_ping'] : null;
    }
    unset($row);

    jsonOut(['success' => true, 'players' => $rows]);
}

// --------------------------------------------------------------- adjust_points
if ($action === 'adjust_points') {
    $deviceId = trim($input['device_id'] ?? '');
    $amount   = isset($input['amount']) ? (int)$input['amount'] : null;

    if (!$deviceId)        jsonOut(['success' => false, 'error' => 'Missing device_id'], 400);
    if ($amount === null)  jsonOut(['success' => false, 'error' => 'Missing amount'],    400);

    $stmt = $db->prepare("UPDATE profiles SET wallet = GREATEST(0, wallet + ?) WHERE device_id = ?");
    $stmt->execute([$amount, $deviceId]);

    if ($stmt->rowCount() === 0) {
        jsonOut(['success' => false, 'error' => 'Player not found'], 404);
    }

    $walletStmt = $db->prepare("SELECT wallet FROM profiles WHERE device_id = ?");
    $walletStmt->execute([$deviceId]);
    $newWallet = (int)$walletStmt->fetchColumn();

    jsonOut(['success' => true, 'wallet' => $newWallet]);
}

// --------------------------------------------------------------- delete_player
if ($action === 'delete_player') {
    $deviceId = trim($input['device_id'] ?? '');

    if (!$deviceId) jsonOut(['success' => false, 'error' => 'Missing device_id'], 400);

    $db->prepare("DELETE FROM profiles WHERE device_id = ?")->execute([$deviceId]);
    $db->prepare("DELETE FROM players  WHERE device_id = ?")->execute([$deviceId]);

    jsonOut(['success' => true]);
}

// ------------------------------------------------------------- export_database
// Dumps every table's schema (SHOW CREATE TABLE) and data (SELECT *) to a
// timestamped .sql file under database/exported/. Pure-PHP (no mysqldump
// binary dependency) since shared/Android KSWEB PHP builds usually can't
// shell out. The file is only ever served back out through
// admin_export_download.php, which re-checks the passcode - it is never
// linked from a public URL.
if ($action === 'export_database') {
    $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    $sql  = "-- Bible Challenge Arena - Database export\n";
    $sql .= "-- Generated " . date('Y-m-d H:i:s') . "\n\n";
    $sql .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        $createRow = $db->query("SHOW CREATE TABLE `$table`")->fetch();
        $sql .= "DROP TABLE IF EXISTS `$table`;\n" . $createRow['Create Table'] . ";\n\n";

        $stmt  = $db->query("SELECT * FROM `$table`");
        $cols  = null;
        $batch = [];
        while ($row = $stmt->fetch()) {
            if ($cols === null) $cols = array_keys($row);
            $vals = array_map(function ($v) use ($db) {
                return $v === null ? 'NULL' : $db->quote((string)$v);
            }, array_values($row));
            $batch[] = '(' . implode(',', $vals) . ')';
            if (count($batch) >= 500) {
                $sql .= "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES\n" . implode(",\n", $batch) . ";\n";
                $batch = [];
            }
        }
        if ($batch) {
            $sql .= "INSERT INTO `$table` (`" . implode('`,`', $cols ?? []) . "`) VALUES\n" . implode(",\n", $batch) . ";\n";
        }
        $sql .= "\n";
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

    $dir = __DIR__ . '/../database/exported';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $filename = 'biblegame_export_' . date('Ymd_His') . '.sql';
    if (file_put_contents($dir . '/' . $filename, $sql) === false) {
        jsonOut(['success' => false, 'error' => 'Could not write export file. Check database/exported/ folder permissions.'], 500);
    }

    jsonOut(['success' => true, 'filename' => $filename, 'size_bytes' => strlen($sql), 'table_count' => count($tables)]);
}

// ---------------------------------------------------------------- list_exports
if ($action === 'list_exports') {
    $dir = __DIR__ . '/../database/exported';
    $files = [];
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            if (!preg_match('/^biblegame_export_[0-9_]+\.sql$/', $f)) continue;
            $files[] = [
                'filename'    => $f,
                'size_bytes'  => filesize($dir . '/' . $f),
                'modified_at' => filemtime($dir . '/' . $f) * 1000,
            ];
        }
    }
    usort($files, fn($a, $b) => $b['modified_at'] <=> $a['modified_at']);
    jsonOut(['success' => true, 'files' => $files]);
}

// -------------------------------------------------------------- import_database
// Explicitly (re)creates every runtime table in the current database. getDB()
// already runs initDB() (CREATE TABLE IF NOT EXISTS for everything) on every
// connection, so this is normally a no-op safety net - but after pointing the
// app at a brand-new empty MySQL/MariaDB database (e.g. following a manual
// backup/restore), this gives the admin a visible, on-demand way to trigger
// it and confirm every table landed instead of relying on it happening
// silently behind the next API call.
if ($action === 'import_database') {
    initDB($db);
    $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    sort($tables);
    jsonOut(['success' => true, 'tables' => $tables, 'table_count' => count($tables)]);
}

// ----------------------------------------------------------------- unknown action
jsonOut(['success' => false, 'error' => 'Unknown action'], 400);
