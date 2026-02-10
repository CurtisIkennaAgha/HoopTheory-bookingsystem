<?php
// editPlayerProfile.php
// Updates a player profile in playerProfiles.json by playerId
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$playerId = isset($_POST['playerId']) ? trim($_POST['playerId']) : '';
if (!$playerId) {
    echo json_encode(['success' => false, 'error' => 'Missing playerId']);
    exit;
}

$fields = ['name', 'email', 'age', 'experience', 'medical', 'emergency'];
$profile = [];
foreach ($fields as $field) {
    $profile[$field] = isset($_POST[$field]) ? trim($_POST[$field]) : '';
}
$profile['lastUpdated'] = date('Y-m-d H:i:s');

$file = __DIR__ . '/../data/playerProfiles.json';
clearstatcache(true, $file);
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

$data[$playerId] = $profile;

$fp = fopen($file, 'w');
if (flock($fp, LOCK_EX)) {
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    echo json_encode(['success' => true]);
} else {
    fclose($fp);
    echo json_encode(['success' => false, 'error' => 'File lock failed']);
}
