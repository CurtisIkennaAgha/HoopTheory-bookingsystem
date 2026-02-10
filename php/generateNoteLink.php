<?php
// php/generateNoteLink.php
// POST { email, scope?: 'player'|'note', noteId?: string, expiresInDays?: int }
require_once __DIR__ . '/playerProfiles.php';

$raw = file_get_contents('php://input');
if (!$raw) { jsonResponse(['success' => false, 'errors' => ['Empty request body']], 400); exit; }
$body = json_decode($raw, true);
if ($body === null) { jsonResponse(['success' => false, 'errors' => ['Invalid JSON']], 400); exit; }

$email = $body['email'] ?? '';
$scope = $body['scope'] ?? 'player';
$noteId = $body['noteId'] ?? null;
$expires = isset($body['expiresInDays']) ? intval($body['expiresInDays']) : null;

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { jsonResponse(['success' => false, 'errors' => ['Valid email is required']], 400); exit; }
if (!in_array($scope, ['player','note'], true)) { jsonResponse(['success' => false, 'errors' => ['Invalid scope']], 400); exit; }
if ($scope === 'note' && (!$noteId || !is_string($noteId))) { jsonResponse(['success' => false, 'errors' => ['noteId required for scope=note']], 400); exit; }

$res = generateNotesShareToken($email, $scope, $noteId, $expires);
if (!$res['success']) { jsonResponse(['success' => false, 'errors' => $res['errors'] ?? ['Unable to create token']], 500); exit; }

$token = $res['token'];
// Return a relative, shareable URL (frontend can make absolute if needed)
$url = 'php/getPlayerNotes.php?token=' . urlencode($token);
jsonResponse(['success' => true, 'token' => $token, 'url' => $url, 'meta' => $res['meta']]);
