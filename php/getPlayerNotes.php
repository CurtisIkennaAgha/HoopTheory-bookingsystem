<?php
// php/getPlayerNotes.php
// GET by ?email= or ?token=
require_once __DIR__ . '/playerProfiles.php';

// Prefer token (public/read-only) if provided
if (isset($_GET['token'])) {
    $token = trim($_GET['token']);
    if ($token === '') { jsonResponse(['success' => false, 'errors' => ['token is required']], 400); exit; }
    $res = getNotesByToken($token);
    if ($res['success']) jsonResponse(['success' => true, 'notes' => $res['notes'], 'player' => $res['player']]);
    else jsonResponse(['success' => false, 'errors' => $res['errors']], 404);
    exit;
}

// Fallback: admin-style fetch by email
if (isset($_GET['email'])) {
    $email = $_GET['email'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { jsonResponse(['success' => false, 'errors' => ['Valid email is required']], 400); exit; }
    $res = getNotesForPlayer($email);
    if ($res['success']) jsonResponse(['success' => true, 'notes' => $res['notes']]);
    else jsonResponse(['success' => false, 'errors' => $res['errors']], 404);
    exit;
}

jsonResponse(['success' => false, 'errors' => ['email or token required']], 400);
