<?php
// logActivity.php - Centralized activity logger for notifications/analytics
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$logFile = __DIR__ . '/../data/activityLog.json';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed"]);
    exit;
}

// Get input (expects JSON)
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];

$type = $input['type'] ?? 'info';
$action = $input['action'] ?? '';
$title = $input['title'] ?? '';
$message = $input['message'] ?? '';
$player = $input['player'] ?? null;
$session = $input['session'] ?? null;
$meta = $input['meta'] ?? [];

if (!$action || !$title) {
    http_response_code(400);
    echo json_encode(["error" => "Missing required fields: action, title"]);
    exit;
}

// Build log entry
$entry = [
    'id' => uniqid(),
    'type' => $type,
    'action' => $action,
    'title' => $title,
    'message' => $message,
    'timestamp' => gmdate('c'),
    'player' => $player,
    'session' => $session,
    'meta' => $meta,
    'seen' => false
];

// Read, append, and save
clearstatcache(true, $logFile);
$logData = file_exists($logFile) ? file_get_contents($logFile) : '[]';
$logs = json_decode($logData, true);
if (!is_array($logs)) $logs = [];
$logs[] = $entry;
file_put_contents($logFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo json_encode(["success" => true, "entry" => $entry]);
