<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$logFile = __DIR__ . '/../data/activityLog.json';
if (!file_exists($logFile)) {
    echo json_encode([]);
    exit;
}

clearstatcache(true, $logFile);
$logData = file_get_contents($logFile);
if ($logData === false) {
    http_response_code(500);
    echo json_encode(["error" => "Could not read activity log."]);
    exit;
}

$logs = json_decode($logData, true);
if (!is_array($logs)) $logs = [];

// Mark all as seen if POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($logs as &$entry) {
        $entry['seen'] = true;
    }
    file_put_contents($logFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo json_encode(["success" => true]);
    exit;
}

echo json_encode($logs);
