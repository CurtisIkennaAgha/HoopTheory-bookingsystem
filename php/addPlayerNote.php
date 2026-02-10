<?php
// php/addPlayerNote.php
// POST { email, content, author }
require_once __DIR__ . '/playerProfiles.php';

$raw = file_get_contents('php://input');
if (!$raw) {
    jsonResponse(['success' => false, 'errors' => ['Empty request body']], 400);
    exit;
}
$body = json_decode($raw, true);
if ($body === null) {
    jsonResponse(['success' => false, 'errors' => ['Invalid JSON']], 400);
    exit;
}

$email = $body['email'] ?? '';
$content = $body['content'] ?? '';
$author = $body['author'] ?? '';

// Basic validation
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['success' => false, 'errors' => ['Valid email is required']], 400);
    exit;
}
if (!is_string($content) || trim($content) === '') {
    jsonResponse(['success' => false, 'errors' => ['Note content is required']], 400);
    exit;
}
if (!is_string($author) || trim($author) === '') {
    jsonResponse(['success' => false, 'errors' => ['Author is required']], 400);
    exit;
}

$result = addNoteToPlayerProfile($email, $content, $author);
if ($result['success']) {
    http_response_code(201);
    jsonResponse(['success' => true, 'note' => $result['note']]);
} else {
    jsonResponse(['success' => false, 'errors' => $result['errors'] ?? ['Unknown error']], 500);
}
