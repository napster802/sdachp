<?php
/* ------------------------------------------------------------
   Reading-rewards heartbeat. The client (js/bible_reader.js)
   calls this every ~5s while the Bible reader screen is open and
   visible, reporting how many scroll/touch events it observed
   and how much the scroll position actually moved since the last
   call. The server never trusts a client-claimed duration -
   elapsed time is always (server now) - (server's own record of
   the last heartbeat), so no amount of spamming this endpoint can
   accrue more than real wall-clock time.

   An interval only counts toward the reward if:
     - the gap since the last heartbeat is a plausible ~5s tick
       (too small = duplicate/spam call, too large = the tab was
       hidden/backgrounded and time passed without the page open)
     - the client reported real scroll/touch activity in that
       window (idle "leave the tab open" earns nothing)
   Every 60,000ms of validated reading credits 20 points to the
   wallet; the remainder carries over to the next heartbeat.
   ------------------------------------------------------------ */
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { jsonOut([]); }

const READING_MIN_GAP_MS    = 500;    // faster than this = duplicate/spam call, ignore
const READING_MAX_GAP_MS    = 8000;   // slower than this = tab was hidden/suspended, ignore
const READING_MIN_EVENTS    = 2;      // must show at least this many scroll/touch events
const READING_MIN_SCROLL_PX = 10;     // ...and at least this much actual scroll movement
const READING_MS_PER_REWARD = 60000;  // 1 minute of validated reading...
const READING_POINTS_PER_REWARD = 20; // ...= 20 points

$input       = getInput();
$deviceId    = trim($input['device_id'] ?? '');
$eventsCount = max(0, (int)($input['events_count'] ?? 0));
$scrollDelta = max(0, (int)($input['scroll_delta'] ?? 0));

if (!$deviceId) jsonOut(['success' => false, 'error' => 'Missing device_id'], 400);

$db  = getDB();
$now = nowMs();

$db->prepare("INSERT IGNORE INTO bible_reading_progress (device_id, active_ms_accum, last_heartbeat_at) VALUES (?, 0, ?)")
   ->execute([$deviceId, $now]);

// Atomic: fold this interval into the accumulator only if it looks like a
// genuine ~5s tick of real reading activity, in one UPDATE so a read-then-write
// race can't double count between concurrent requests from the same device.
$db->prepare("
    UPDATE bible_reading_progress
    SET active_ms_accum = active_ms_accum + CASE
            WHEN (? - last_heartbeat_at) BETWEEN ? AND ?
                 AND ? >= ? AND ? >= ?
            THEN (? - last_heartbeat_at)
            ELSE 0
        END,
        last_heartbeat_at = ?
    WHERE device_id = ?
")->execute([
    $now, READING_MIN_GAP_MS, READING_MAX_GAP_MS,
    $eventsCount, READING_MIN_EVENTS, $scrollDelta, READING_MIN_SCROLL_PX,
    $now,
    $now, $deviceId,
]);

$db->beginTransaction();
$sel = $db->prepare("SELECT active_ms_accum FROM bible_reading_progress WHERE device_id = ? FOR UPDATE");
$sel->execute([$deviceId]);
$accum = (int)$sel->fetchColumn();

$creditedPoints = 0;
$wholeRewards   = intdiv($accum, READING_MS_PER_REWARD);
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

$walletStmt = $db->prepare("SELECT wallet FROM profiles WHERE device_id = ?");
$walletStmt->execute([$deviceId]);
$wallet = (int)$walletStmt->fetchColumn();

jsonOut([
    'success'         => true,
    'credited_points' => $creditedPoints,
    'wallet'          => $wallet,
    'progress_pct'    => min(100, (int)round($accum / READING_MS_PER_REWARD * 100)),
]);
