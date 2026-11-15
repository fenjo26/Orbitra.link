<?php
// cli/postback_replay.php <incoming_postbacks_log.id>
//
// Replays ONE postback that arrived before its click reached the database
// (see core/PostbackPending.php). Started by cli/click_spool_cron.php, which
// has already claimed the row (result = 'processing').
//
// The request is rebuilt from the log row and handed to postback.php itself,
// so the delayed conversion goes through exactly the same code as a live one.
// postback.php sees $orbitraPostbackReplay and: reuses this log row instead of
// creating a new one, stamps the conversion with the time the postback was
// RECEIVED, and steps aside if a newer postback for the same click has
// already been applied.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$orbitraReplayLogId = (int) ($argv[1] ?? 0);
if ($orbitraReplayLogId <= 0) {
    fwrite(STDERR, "Usage: php cli/postback_replay.php <incoming_postbacks_log id>\n");
    exit(2);
}

chdir(dirname(__DIR__));
require_once dirname(__DIR__) . '/config.php';

$orbitraReplayStmt = $pdo->prepare("
    SELECT id, created_at, remote_ip, request_uri, params_json, source, result
    FROM incoming_postbacks_log WHERE id = ?
");
$orbitraReplayStmt->execute([$orbitraReplayLogId]);
$orbitraReplayRow = $orbitraReplayStmt->fetch(PDO::FETCH_ASSOC);
$orbitraReplayStmt->closeCursor();

if (!$orbitraReplayRow || $orbitraReplayRow['result'] !== 'processing') {
    exit(0); // not claimed for replay — nothing to do
}

$orbitraReplayParams = json_decode((string) $orbitraReplayRow['params_json'], true);
if (!is_array($orbitraReplayParams)) {
    $pdo->prepare("UPDATE incoming_postbacks_log SET result = 'rejected', error = 'Stored request is unreadable' WHERE id = ?")
        ->execute([$orbitraReplayLogId]);
    exit(0);
}

$_GET = $orbitraReplayParams;
$_REQUEST = $orbitraReplayParams;
$_SERVER['REMOTE_ADDR'] = (string) ($orbitraReplayRow['remote_ip'] ?: '0.0.0.0');
$_SERVER['REQUEST_URI'] = (string) ($orbitraReplayRow['request_uri'] ?: '/postback.php');
$_SERVER['REQUEST_METHOD'] = 'GET';

$orbitraPostbackReplay = [
    'log_id' => $orbitraReplayLogId,
    // created_at is stored in UTC (datetime('now')).
    'received_at' => (string) $orbitraReplayRow['created_at'],
    'received_ts' => (int) strtotime($orbitraReplayRow['created_at'] . ' UTC') ?: time(),
];

ob_start();
try {
    require dirname(__DIR__) . '/postback.php';
} finally {
    ob_end_clean();
}
exit(0);
