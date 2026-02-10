<?php
// php/deletePlayerNote.php
// POST { email, noteId }
require_once __DIR__ . '/playerProfiles.php';

$raw = file_get_contents('php://input');
if (!$raw) { jsonResponse(['success' => false, 'errors' => ['Empty request body']], 400); exit; }
$body = json_decode($raw, true);
if ($body === null) { jsonResponse(['success' => false, 'errors' => ['Invalid JSON']], 400); exit; }

$email = $body['email'] ?? '';
$noteId = $body['noteId'] ?? '';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { jsonResponse(['success' => false, 'errors' => ['Valid email is required']], 400); exit; }
if (!is_string($noteId) || trim($noteId) === '') { jsonResponse(['success' => false, 'errors' => ['noteId is required']], 400); exit; }

$players = readPlayers();
$key = strtolower(trim($email));
if (!isset($players[$key])) { jsonResponse(['success' => false, 'errors' => ['Player not found']], 404); exit; }

$notes = $players[$key]['notes'] ?? [];
if (!is_array($notes) || empty($notes)) { jsonResponse(['success' => false, 'errors' => ['No notes for player']], 404); exit; }

$found = false;
foreach ($notes as $idx => $n) {
    if (isset($n['noteId']) && $n['noteId'] === $noteId) {
        $found = true;
        array_splice($notes, $idx, 1);
        break;
    }
}
if (!$found) { jsonResponse(['success' => false, 'errors' => ['Note not found']], 404); exit; }

$players[$key]['notes'] = array_values($notes);
$players[$key]['updated_at'] = date(DATE_ATOM);

if (!persistPlayers($players)) {
    error_log('Failed to persist note deletion for: ' . $key);
    jsonResponse(['success' => false, 'errors' => ['Failed to persist changes']], 500);
    exit;
}

error_log('Deleted note ' . $noteId . ' from player ' . $key);
jsonResponse(['success' => true]);
