<?php
// php/savePlayerProfile.php
// Endpoint to accept a POSTed JSON profile and save via playerProfiles library
require_once __DIR__ . '/playerProfiles.php';

// Accept only POST with JSON
$raw = file_get_contents('php://input');
if (!$raw) {
    jsonResponse(['success' => false, 'errors' => ['Empty request body']], 400);
    exit;
}
$data = json_decode($raw, true);
if ($data === null) {
    jsonResponse(['success' => false, 'errors' => ['Invalid JSON']], 400);
    exit;
}

// Handle clear_all action
if (isset($data['action']) && $data['action'] === 'clear_all') {
    if (persistPlayers([])) {
        jsonResponse(['success' => true, 'message' => 'All player profiles cleared']);
    } else {
        jsonResponse(['success' => false, 'errors' => ['Failed to clear profiles']], 500);
    }
    exit;
}

// Debug log incoming payload
error_log('========== savePlayerProfile.php called ==========');
error_log('Raw input length: ' . strlen($raw));
error_log('Decoded data: ' . json_encode($data));
error_log('Name: ' . ($data['name'] ?? 'NOT SET'));
error_log('Email: ' . ($data['email'] ?? 'NOT SET'));
error_log('PLAYERS_FILE location: ' . PLAYERS_FILE);
error_log('File exists: ' . (file_exists(PLAYERS_FILE) ? 'yes' : 'no'));
error_log('File writable: ' . (is_writable(PLAYERS_FILE) ? 'yes' : (is_writable(dirname(PLAYERS_FILE)) ? 'directory writable' : 'NOT WRITABLE')));

$result = savePlayerProfile($data);

error_log('savePlayerProfile() returned: ' . json_encode($result));

// Log result with created/updated info if available
if (isset($result['profile'])) {
    $p = $result['profile'];
    error_log('Profile details - Name: ' . ($p['name'] ?? 'MISSING') . ', Email: ' . ($p['email'] ?? 'MISSING'));
    error_log('Registration complete: ' . (isset($p['registration_complete']) && $p['registration_complete'] ? 'YES' : 'NO'));
}
error_log('Success status: ' . ($result['success'] ? 'TRUE' : 'FALSE'));
error_log('========== savePlayerProfile.php complete ==========');

if ($result['success']) {
    jsonResponse(['success' => true, 'profile' => $result['profile']]);
} else {
    error_log('savePlayerProfile failed: ' . json_encode($result['errors']));
    jsonResponse(['success' => false, 'errors' => $result['errors']], 500);
}

