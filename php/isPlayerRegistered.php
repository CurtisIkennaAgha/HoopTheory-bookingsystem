<?php
// php/isPlayerRegistered.php
require_once __DIR__ . '/playerProfiles.php';

$raw = file_get_contents('php://input');
if (!$raw) {
    jsonResponse(['registered' => false, 'error' => 'Empty request body'], 400);
    exit;
}
$data = json_decode($raw, true);
if ($data === null) {
    jsonResponse(['registered' => false, 'error' => 'Invalid JSON'], 400);
    exit;
}
$name = $data['name'] ?? '';
$email = $data['email'] ?? '';
$registered = isPlayerRegistered($name, $email);

// Check if email exists (now returns array of all matching profiles)
$emailExists = false;
$existingName = null;
if (!empty($email)) {
  $profiles = findPlayerByEmail($email);
  if (!empty($profiles)) {
    $emailExists = true;
    // For compatibility, return first matching name
    $existingName = $profiles[0]['name'] ?? null;
  }
}

// Check if name exists (now returns array of all matching profiles)
$nameExists = false;
$existingEmail = null;
if (!empty($name)) {
  $profiles = findPlayerByName($name);
  if (!empty($profiles)) {
    foreach ($profiles as $profile) {
      if (!empty($profile['email'])) {
        $nameExists = true;
        // For compatibility, return first matching email
        $existingEmail = $profile['email'];
        break;
      }
    }
  }
}

error_log("Checking profile for: {$name}, {$email}, registered: " . ($registered ? 'true' : 'false') . ", emailExists: " . ($emailExists ? 'true' : 'false') . ", nameExists: " . ($nameExists ? 'true' : 'false'));
jsonResponse(['registered' => (bool)$registered, 'emailExists' => $emailExists, 'existingName' => $existingName, 'nameExists' => $nameExists, 'existingEmail' => $existingEmail]);
