<?php
/* ------------------------------------------------------------
   Offline reading-rewards sync. The client (js/offline_queue.js)
   queues raw heartbeat-shaped signals locally while it can't
   reach api/bible_reading_heartbeat.php, then POSTs the whole
   queue here once back online:
     { device_id, batch_id, events: [{client_ts, events_count, scroll_delta}, ...] }

   Unlike the live heartbeat, elapsed time here can't be measured
   against a server clock - these events already happened while
   offline. So each consecutive pair is validated using the CLIENT's
   own timestamps against the exact same thresholds the live
   endpoint uses (READING_* constants in db.php), and the resulting
   total is then clipped against a ceiling the server DOES know for
   certain: nowMs() - bible_reading_progress.last_heartbeat_at, i.e.
   the real wall-clock time since this device last successfully
   talked to the server. A device with a fast/tampered clock can
   inflate its own interval deltas, but it cannot fake how long the
   server has actually been waiting to hear from it - so no matter
   how many plausible-looking fabricated ticks are queued, credited
   time can never exceed genuine elapsed real time.

   batch_id makes a retried sync (e.g. the response was lost on a
   flaky reconnect) safe to resend: if it matches the last
   successfully processed batch for this device, nothing is
   re-credited.
   ------------------------------------------------------------ */
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { jsonOut([]); }

$input    = getInput();
$deviceId = trim($input['device_id'] ?? '');
$batchId  = trim($input['batch_id'] ?? '');
$events   = is_array($input['events'] ?? null) ? $input['events'] : [];

if (!$deviceId || !$batchId) jsonOut(['success' => false, 'error' => 'Missing device_id or batch_id'], 400);

$db  = getDB();
$now = nowMs();

$db->prepare("INSERT IGNORE INTO bible_reading_progress (device_id, active_ms_accum, last_heartbeat_at) VALUES (?, 0, ?)")
   ->execute([$deviceId, $now]);

$db->beginTransaction();
$sel = $db->prepare("SELECT last_heartbeat_at, last_synced_batch_id FROM bible_reading_progress WHERE device_id = ? FOR UPDATE");
$sel->execute([$deviceId]);
$progress = $sel->fetch();

if ($progress && $progress['last_synced_batch_id'] !== null && $progress['last_synced_batch_id'] === $batchId) {
    // Already processed this exact batch (a retried request) - report the
    // current state without crediting anything a second time.
    $db->commit();
    $walletStmt = $db->prepare("SELECT wallet FROM profiles WHERE device_id = ?");
    $walletStmt->execute([$deviceId]);
    jsonOut([
        'success'        => true,
        'duplicate'      => true,
        'credited_points'=> 0,
        'wallet'         => (int)$walletStmt->fetchColumn(),
        'batch_valid_ms' => 0,
        'credited_ms'    => 0,
        'skipped_ms'     => 0,
    ]);
}

$lastHeartbeatAt = (int)($progress['last_heartbeat_at'] ?? $now);

// Sort defensively by client timestamp - IndexedDB already returns them in
// insertion order, but never trust client-provided ordering blindly.
usort($events, fn($a, $b) => (int)($a['client_ts'] ?? 0) <=> (int)($b['client_ts'] ?? 0));

$batchValidMs = 0;
$prevTs = null;
foreach ($events as $ev) {
    $ts = (int)($ev['client_ts'] ?? 0);
    $ec = (int)($ev['events_count'] ?? 0);
    $sd = (int)($ev['scroll_delta'] ?? 0);
    if ($prevTs !== null && $ts > $prevTs) {
        $gap = $ts - $prevTs;
        if ($gap >= READING_MIN_GAP_MS && $gap <= READING_MAX_GAP_MS
            && $ec >= READING_MIN_EVENTS && $sd >= READING_MIN_SCROLL_PX) {
            $batchValidMs += $gap;
        }
    }
    $prevTs = $ts;
}

$wallClockCeilingMs = max(0, $now - $lastHeartbeatAt);
$creditableMs = min($batchValidMs, $wallClockCeilingMs);
$skippedMs    = $batchValidMs - $creditableMs;

$db->prepare("
    UPDATE bible_reading_progress
    SET active_ms_accum = active_ms_accum + ?,
        last_heartbeat_at = ?,
        last_synced_batch_id = ?
    WHERE device_id = ?
")->execute([$creditableMs, $now, $batchId, $deviceId]);
$db->commit();

$creditedPoints = creditReadingAccum($db, $deviceId, $now);

$walletStmt = $db->prepare("SELECT wallet FROM profiles WHERE device_id = ?");
$walletStmt->execute([$deviceId]);

jsonOut([
    'success'         => true,
    'duplicate'       => false,
    'credited_points' => $creditedPoints,
    'wallet'          => (int)$walletStmt->fetchColumn(),
    'batch_valid_ms'  => $batchValidMs,
    'credited_ms'     => $creditableMs,
    'skipped_ms'      => $skippedMs,
]);
