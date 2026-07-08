<?php
/* ------------------------------------------------------------
   Streams a previously generated database export back to the
   admin. Kept as a separate GET endpoint (rather than folding
   into admin.php's JSON API) so the browser can navigate/download
   it directly as a file instead of a JSON blob.
   ------------------------------------------------------------ */
require_once __DIR__ . '/db.php';

$db = getDB();
$token = trim($_GET['token'] ?? '');
if ($token === '') {
    http_response_code(401);
    header('Content-Type: text/plain');
    exit('Unauthorized');
}
$stmt = $db->prepare("SELECT expires_at FROM admin_sessions WHERE token = ?");
$stmt->execute([$token]);
$row = $stmt->fetch();
if (!$row || (int)$row['expires_at'] < nowMs()) {
    http_response_code(401);
    header('Content-Type: text/plain');
    exit('Session expired. Please log in again.');
}

$filename = basename(trim($_GET['file'] ?? ''));
if (!preg_match('/^biblegame_export_[0-9_]+\.sql$/', $filename)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    exit('Invalid file');
}

$path = __DIR__ . '/../database/exported/' . $filename;
if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    exit('Not found');
}

header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
