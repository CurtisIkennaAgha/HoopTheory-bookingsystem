<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$playersFile = __DIR__ . '/../data/playerProfiles.json';

// Handle clear_all action
if (isset($_GET['action']) && $_GET['action'] === 'clear_all') {
    $dir = dirname($playersFile);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $tmp = tempnam($dir, 'players_');
    $written = @file_put_contents($tmp, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if ($written === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to clear players']);
        exit;
    }
    if (!@rename($tmp, $playersFile)) {
        @unlink($tmp);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to clear players']);
        exit;
    }
    echo json_encode(['success' => true, 'message' => 'All players cleared']);
    exit;
}

$players = file_exists($playersFile) ? json_decode(file_get_contents($playersFile), true) : [];

// If email query provided, return single profile (or 404)
if (isset($_GET['email'])) {
    $email = $_GET['email'];
    // players.json is keyed by email in this project; support both keyed and indexed shapes
    if (isset($players[$email])) {
        echo json_encode($players[$email], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // fallback: search values for a matching email field
    foreach ($players as $k => $p) {
        if (is_array($p) && (isset($p['email']) && $p['email'] === $email)) {
            echo json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }

    http_response_code(404);
    echo json_encode(['error' => 'Player not found']);
    exit;
}

// Return full players map
echo json_encode($players ?? new stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
