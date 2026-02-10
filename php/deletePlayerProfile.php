<?php
// deletePlayerProfile.php
// Deletes a player from players.json and playerProfiles.json by email

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


$profilesFile = __DIR__ . '/../data/playerProfiles.json';

function removePlayerById($file, $playerId) {
    clearstatcache(true, $file);
    $data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    if (isset($data[$playerId])) {
        unset($data[$playerId]);
        $fp = fopen($file, 'w');
        if (flock($fp, LOCK_EX)) {
            fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
            fflush($fp);
            flock($fp, LOCK_UN);
        } else {
            fclose($fp);
            return false;
        }
        fclose($fp);
        return true;
    }
    return false;
}

$removedProfiles = removePlayerById($profilesFile, $playerId);

if ($removedProfiles) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Player not found']);
}
