<?php
/* ------------------------------------------------------------
   Streams a previously generated database export back to the
   admin. Kept as a separate GET endpoint (rather than folding
   into admin.php's JSON API) so the browser can navigate/download
   it directly as a file instead of a JSON blob.
   ------------------------------------------------------------ */
require_once __DIR__ . '/db.php';

$passcode = trim($_GET['passcode'] ?? '');
if ($passcode !== '12345678') {
    http_response_code(401);
    header('Content-Type: text/plain');
    exit('Unauthorized');
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
