<?php
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { jsonOut([]); }

$input    = getInput();
$deviceId = trim($input['device_id'] ?? '');
$name     = trim($input['name'] ?? '');
$avatar   = trim($input['avatar'] ?? '😊');

if (!$deviceId || !$name) jsonOut(['success' => false, 'error' => 'Missing device_id or name'], 400);

$db = getDB();
if (!checkRateLimit($db, 'create_room:' . clientIp(), 10, 60000)) {
    jsonOut(['success' => false, 'error' => 'Too many rooms created, please wait a bit.'], 429);
}
cleanStale($db);

// Generate a unique room code
do {
    $code = generateCode();
    $checkStmt = $db->prepare("SELECT 1 FROM rooms WHERE code = ?");
    $checkStmt->execute([$code]);
    $exists = (bool)$checkStmt->fetchColumn();
} while ($exists);

$now = nowMs();
// Never broadcast to other players (unlike device_id, which room_state.php
// does share with everyone in the room for legitimate in-room targeting -
// votes, turn order, etc). host_action.php requires this in addition to
// device_id+is_host so a player who only knows the host's device_id (e.g.
// from the room's player list) can't forge host-only requests.
$hostSecret = bin2hex(random_bytes(16));

$db->beginTransaction();
$db->prepare("INSERT INTO rooms (code, host_device_id, host_secret, status, created_at, updated_at) VALUES (?, ?, ?, 'lobby', ?, ?)")
   ->execute([$code, $deviceId, $hostSecret, $now, $now]);

$db->prepare("REPLACE INTO players (device_id, room_code, name, avatar, is_host, joined_at, last_ping) VALUES (?, ?, ?, ?, 1, ?, ?)")
   ->execute([$deviceId, $code, $name, $avatar, $now, $now]);

$db->commit();

jsonOut(['success' => true, 'room_code' => $code, 'host_secret' => $hostSecret]);
