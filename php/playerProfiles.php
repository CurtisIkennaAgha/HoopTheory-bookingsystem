<?php
// php/playerProfiles.php
// Modular backend logic for managing player registrations.
// IMPORTANT: Uses UUID-based keys to allow multiple profiles with same email or same name
// Functions:
// - readPlayers(): array
// - persistPlayers($data): bool
// - findPlayerByEmail($email): array (returns all matching profiles)
// - findPlayerByName($name): array (returns all matching profiles)
// - validatePlayerProfile($profile, $requireComplete=false): array (valid, errors)
// - savePlayerProfile($profile): array (success, errors, profile)
// - isPlayerRegistered($name, $email): bool (checks for exact name+email match)

define('PLAYERS_FILE', __DIR__ . '/../data/playerProfiles.json');

function readPlayers() {
    $file = PLAYERS_FILE;
    if (!file_exists($file)) {
        return [];
    }
    $contents = @file_get_contents($file);
    if ($contents === false || trim($contents) === '') return [];
    $data = json_decode($contents, true);
    if (!is_array($data)) return [];
    return $data;
}

function persistPlayers($data) {
    $file = PLAYERS_FILE;
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $tmp = tempnam($dir, 'players_');
    $written = @file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    error_log('persistPlayers: wrote ' . ($written === false ? 'FAILED' : $written . ' bytes') . ' to temp file: ' . $tmp);
    if ($written === false) {
        error_log('persistPlayers: file_put_contents failed');
        return false;
    }
    if (!@rename($tmp, $file)) {
        error_log('persistPlayers: rename failed from ' . $tmp . ' to ' . $file);
        @unlink($tmp);
        // Fallback: write directly (helps on Windows/OneDrive where rename can fail)
        $directWritten = @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($directWritten === false) {
            error_log('persistPlayers: direct write failed');
            return false;
        }
        error_log('persistPlayers: direct write succeeded (' . $directWritten . ' bytes) to ' . $file);
        return true;
    }
    error_log('persistPlayers: successfully saved ' . count($data) . ' player records to ' . $file);
    return true;
}

function normalizeEmailKey($email) {
    return $email ? strtolower(trim($email)) : '';
}

function normalizeNameKey($name) {
    return $name ? preg_replace('/\s+/', ' ', trim($name)) : '';
}

/**
 * Find all players by email (case-insensitive)
 * @param string $email
 * @return array Array of matching profiles
 */
function findPlayerByEmail($email) {
    $key = normalizeEmailKey($email);
    error_log('findPlayerByEmail: normalized email "' . $email . '" to key "' . $key . '"');
    if ($key === '') return [];
    $players = readPlayers();
    error_log('findPlayerByEmail: readPlayers returned ' . count($players) . ' players');
    
    $matches = [];
    // Check all records for matching email
    foreach ($players as $id => $profile) {
        if (isset($profile['email']) && normalizeEmailKey($profile['email']) === $key) {
            $profile['profile_id'] = $id; // Ensure profile_id is included
            $matches[] = $profile;
        }
    }
    
    error_log('findPlayerByEmail: found ' . count($matches) . ' matching profiles');
    return $matches;
}

/**
 * Find all players by name (case-insensitive)
 * @param string $name
 * @return array Array of matching profiles
 */
function findPlayerByName($name) {
    $n = normalizeNameKey($name);
    if ($n === '') return [];
    $players = readPlayers();
    
    $matches = [];
    foreach ($players as $id => $profile) {
        if (isset($profile['name']) && strcasecmp(trim($profile['name']), $n) === 0) {
            $profile['profile_id'] = $id; // Ensure profile_id is included
            $matches[] = $profile;
        }
    }
    
    error_log('findPlayerByName: found ' . count($matches) . ' matching profiles');
    return $matches;
}

function validatePlayerProfile($profile, $requireComplete=false) {
    $errors = [];

    // Name
    if (empty($profile['name']) || !is_string($profile['name'])) {
        $errors[] = 'Name is required';
    }

    // Email
    if (empty($profile['email']) || !filter_var($profile['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid email is required';
    }

    // Age
    if (!isset($profile['age']) || $profile['age'] === '') {
        $errors[] = 'Age is required';
    } else {
        if (!is_numeric($profile['age']) || intval($profile['age']) < 0) $errors[] = 'Age must be a non-negative number';
        else $profile['age'] = intval($profile['age']);
    }

    // Experience
    $allowed = [
        'No experience', 'Beginner', 'Regular player but no team', 'Local league', 'National league'
    ];
    if (!isset($profile['experience']) || !in_array($profile['experience'], $allowed, true)) {
        $errors[] = 'Experience must be one of the allowed values';
    }

    // Medical info optional but should be string if present
    if (isset($profile['medical']) && !is_string($profile['medical'])) $errors[] = 'Medical information must be text';

    // Emergency contact
    if (empty($profile['emergency']) || !is_array($profile['emergency'])) {
        $errors[] = 'Emergency contact is required';
    } else {
        $e = $profile['emergency'];
        if (empty($e['name'])) $errors[] = 'Emergency contact name is required';
        if (empty($e['phone'])) $errors[] = 'Emergency contact phone is required';
        if (empty($e['relationship'])) $errors[] = 'Emergency contact relationship is required';
    }

    // Media consent and waiver
    if (!isset($profile['media_consent']) || !is_bool($profile['media_consent'])) {
        $errors[] = 'Media consent must be true/false';
    } else {
        if ($profile['media_consent'] && empty($profile['media_consent_text'])) $errors[] = 'If media consent is true, media_consent_text must be provided';
    }

    if (!isset($profile['waiver_acknowledged']) || !is_bool($profile['waiver_acknowledged'])) {
        $errors[] = 'Waiver acknowledgement must be true/false';
    } else {
        if ($profile['waiver_acknowledged'] && empty($profile['waiver_text'])) $errors[] = 'If waiver is acknowledged, waiver_text must be provided';
    }

    $valid = count($errors) === 0;

    // If requireComplete is true, ensure consent & waiver are true
    if ($requireComplete) {
        if (!$valid) return ['valid' => false, 'errors' => $errors];
        if (!$profile['media_consent']) $errors[] = 'Media consent must be accepted to complete registration';
        if (!$profile['waiver_acknowledged']) $errors[] = 'Waiver must be acknowledged to complete registration';
        $valid = count($errors) === 0;
    }

    return ['valid' => $valid, 'errors' => $errors];
}

function savePlayerProfile($profile) {
    error_log('>>> savePlayerProfile() called');
    
    // Ensure array
    if (!is_array($profile)) {
        error_log('!!! Profile is not an array');
        return ['success' => false, 'errors' => ['Profile must be an object']];
    }

    // Normalize email
    $email = normalizeEmailKey($profile['email'] ?? '');
    $name = normalizeNameKey($profile['name'] ?? '');
    
    error_log('>>> Normalized: email="' . $email . '", name="' . $name . '"');

    if ($email === '' && $name === '') {
        error_log('!!! Both email and name are empty');
        return ['success' => false, 'errors' => ['Name or email is required']];
    }

    // Validate fields, but allow incomplete saves (drafts)
    $validation = validatePlayerProfile($profile, false);
    $errors = $validation['errors'];
    error_log('>>> Validation errors: ' . json_encode($errors));

    // Load existing
    $players = readPlayers();
    error_log('>>> Loaded ' . count($players) . ' existing players');

    // Use UUID as key - either existing profile_id or generate new one
    $key = $profile['profile_id'] ?? null;
    
    // If no profile_id provided, check if this is an update to an existing profile
    if (!$key && $email !== '') {
        // Look for existing profile with same email AND name (exact match = update)
        foreach ($players as $id => $p) {
            if (isset($p['email']) && normalizeEmailKey($p['email']) === $email &&
                isset($p['name']) && strcasecmp($p['name'], $name) === 0) {
                $key = $id;
                error_log('>>> Found existing profile to update: ' . $key);
                break;
            }
        }
    }
    
    // Generate new UUID if still no key
    if (!$key) {
        $key = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
        error_log('>>> Generated new profile ID: ' . $key);
    }
    
    error_log('>>> Using profile key: ' . $key);

    $now = date(DATE_ATOM);

    $existing = $players[$key] ?? null;
    error_log('>>> Existing profile: ' . ($existing ? 'FOUND' : 'NOT FOUND'));

    // Merge existing
    $merged = $existing ? array_merge($existing, $profile) : $profile;
    $merged['profile_id'] = $key;
    // Ensure canonical fields
    $merged['name'] = $profile['name'] ?? ($existing['name'] ?? '');
    $merged['email'] = $profile['email'] ?? ($existing['email'] ?? '');
    $merged['age'] = isset($profile['age']) ? intval($profile['age']) : ($existing['age'] ?? null);
    $merged['experience'] = $profile['experience'] ?? ($existing['experience'] ?? null);
    $merged['medical'] = $profile['medical'] ?? ($existing['medical'] ?? '');
    $merged['emergency'] = $profile['emergency'] ?? ($existing['emergency'] ?? []);
    $merged['media_consent'] = isset($profile['media_consent']) ? (bool)$profile['media_consent'] : ($existing['media_consent'] ?? false);
    $merged['media_consent_text'] = $profile['media_consent_text'] ?? ($existing['media_consent_text'] ?? '');
    $merged['waiver_acknowledged'] = isset($profile['waiver_acknowledged']) ? (bool)$profile['waiver_acknowledged'] : ($existing['waiver_acknowledged'] ?? false);
    $merged['waiver_text'] = $profile['waiver_text'] ?? ($existing['waiver_text'] ?? '');

    // registration_complete: determine if profile is complete
    $completeValidation = validatePlayerProfile($merged, true);
    $merged['registration_complete'] = $completeValidation['valid'];
    if (!$merged['registration_complete']) {
        // store errors for visibility
        $merged['_registration_errors'] = $completeValidation['errors'];
    } else {
        unset($merged['_registration_errors']);
    }

    $merged['updated_at'] = $now;
    if (!isset($merged['created_at'])) $merged['created_at'] = $now;
    
    error_log('>>> Merged profile complete: ' . ($merged['registration_complete'] ? 'YES' : 'NO'));

    // Save under UUID key - allows multiple profiles with same email or name
    $players[$key] = $merged;
    error_log('>>> Saved profile under UUID key: ' . $key);

    error_log('>>> About to persist ' . count($players) . ' players to file');
    $saved = persistPlayers($players);
    error_log('>>> persistPlayers returned: ' . ($saved ? 'TRUE' : 'FALSE'));
    
    if (!$saved) {
        error_log('!!! Failed to persist player profile for: ' . ($merged['email'] ?? $merged['name'] ?? 'unknown'));
        return ['success' => false, 'errors' => ['Failed to persist data']];
    }

    // Log create vs update for debugging
    $action = $existing ? 'updated' : 'created';
    error_log("✅ Player profile {$action}: " . ($merged['name'] ?? '') . " <" . ($merged['email'] ?? '') . "> registration_complete: " . ($merged['registration_complete'] ? 'true' : 'false'));

    return ['success' => true, 'profile' => $merged, 'errors' => $errors];
}

// ------------------------- Player notes (backend) -------------------------
// Notes are stored on the player profile under the `notes` array. Each note:
// - noteId: unique id
// - content: text
// - author: admin identifier
// - date: ISO8601 timestamp
//
// Share tokens are stored under `note_share_tokens` on the player record and
// map token -> { scope: 'player'|'note', noteId?, created_at, expires_at? }

function addNoteToPlayerProfile($email, $content, $author) {
    $emailKey = normalizeEmailKey($email);
    if ($emailKey === '') return ['success' => false, 'errors' => ['Valid email is required']];
    if (!is_string($content) || trim($content) === '') return ['success' => false, 'errors' => ['Note content is required']];
    if (!is_string($author) || trim($author) === '') return ['success' => false, 'errors' => ['Author is required']];

    $players = readPlayers();
    if (!isset($players[$emailKey])) return ['success' => false, 'errors' => ['Player not found']];

    $now = date(DATE_ATOM);
    try {
        $noteId = bin2hex(random_bytes(12));
    } catch (Exception $e) {
        $noteId = uniqid('n_', true);
    }

    $note = [
        'noteId' => $noteId,
        'content' => trim($content),
        'author' => trim($author),
        'date' => $now
    ];

    if (!isset($players[$emailKey]['notes']) || !is_array($players[$emailKey]['notes'])) {
        $players[$emailKey]['notes'] = [];
    }

    // Prepend so newest-first by default; we'll still sort on read to be safe
    array_unshift($players[$emailKey]['notes'], $note);
    $players[$emailKey]['updated_at'] = $now;

    if (!persistPlayers($players)) {
        error_log('Failed to persist note for player: ' . $emailKey);
        return ['success' => false, 'errors' => ['Failed to persist note']];
    }

    error_log('Added note ' . $noteId . ' to player ' . $emailKey . ' by ' . $author);
    return ['success' => true, 'note' => $note];
}

function getNotesForPlayer($email) {
    $emailKey = normalizeEmailKey($email);
    if ($emailKey === '') return ['success' => false, 'errors' => ['Valid email is required']];
    $players = readPlayers();
    if (!isset($players[$emailKey])) return ['success' => false, 'errors' => ['Player not found']];
    $notes = $players[$emailKey]['notes'] ?? [];
    if (!is_array($notes)) $notes = [];

    usort($notes, function($a, $b){
        $ta = isset($a['date']) ? strtotime($a['date']) : 0;
        $tb = isset($b['date']) ? strtotime($b['date']) : 0;
        return $tb <=> $ta; // newest first
    });

    return ['success' => true, 'notes' => $notes];
}

function generateNotesShareToken($email, $scope = 'player', $noteId = null, $expiresInDays = null) {
    $emailKey = normalizeEmailKey($email);
    if ($emailKey === '') return ['success' => false, 'errors' => ['Valid email is required']];
    if (!in_array($scope, ['player','note'], true)) return ['success' => false, 'errors' => ['Invalid scope']];
    if ($scope === 'note' && (!$noteId || !is_string($noteId))) return ['success' => false, 'errors' => ['noteId is required for scope=\'note\'']];

    $players = readPlayers();
    if (!isset($players[$emailKey])) return ['success' => false, 'errors' => ['Player not found']];

    // Optionally validate note exists when scope=note
    if ($scope === 'note') {
        $found = false;
        foreach (($players[$emailKey]['notes'] ?? []) as $n) {
            if (isset($n['noteId']) && $n['noteId'] === $noteId) { $found = true; break; }
        }
        if (!$found) return ['success' => false, 'errors' => ['Note not found for player']];
    }

    try {
        $token = bin2hex(random_bytes(20));
    } catch (Exception $e) {
        $token = bin2hex(openssl_random_pseudo_bytes(20));
    }

    $now = date(DATE_ATOM);
    $meta = ['scope' => $scope, 'noteId' => $noteId, 'created_at' => $now];
    if (is_int($expiresInDays) && $expiresInDays > 0) {
        $meta['expires_at'] = date(DATE_ATOM, strtotime("+{$expiresInDays} days"));
    }

    if (!isset($players[$emailKey]['note_share_tokens']) || !is_array($players[$emailKey]['note_share_tokens'])) {
        $players[$emailKey]['note_share_tokens'] = [];
    }
    $players[$emailKey]['note_share_tokens'][$token] = $meta;
    $players[$emailKey]['updated_at'] = $now;

    if (!persistPlayers($players)) {
        error_log('Failed to persist share token for player: ' . $emailKey);
        return ['success' => false, 'errors' => ['Failed to persist token']];
    }

    return ['success' => true, 'token' => $token, 'meta' => $meta];
}

function findPlayerByShareToken($token) {
    if (!$token || !is_string($token)) return null;
    $players = readPlayers();
    foreach ($players as $email => $p) {
        if (!isset($p['note_share_tokens']) || !is_array($p['note_share_tokens'])) continue;
        if (!isset($p['note_share_tokens'][$token])) continue;
        $meta = $p['note_share_tokens'][$token];
        // check expiry
        if (isset($meta['expires_at']) && strtotime($meta['expires_at']) < time()) return null;
        return ['email' => $email, 'profile' => $p, 'meta' => $meta];
    }
    return null;
}

function getNotesByToken($token) {
    $found = findPlayerByShareToken($token);
    if (!$found) return ['success' => false, 'errors' => ['Invalid or expired token']];
    $email = $found['email'];
    $meta = $found['meta'];
    $players = readPlayers();
    $player = $players[$email] ?? null;
    if (!$player) return ['success' => false, 'errors' => ['Player not found']];

    $notes = $player['notes'] ?? [];
    if (!is_array($notes)) $notes = [];

    if ($meta['scope'] === 'note') {
        $result = array_values(array_filter($notes, function($n) use ($meta) { return isset($n['noteId']) && $n['noteId'] === $meta['noteId']; }));
    } else {
        $result = $notes;
    }

    usort($result, function($a, $b){ return (isset($b['date'])?strtotime($b['date']):0) <=> (isset($a['date'])?strtotime($a['date']):0); });

    return ['success' => true, 'notes' => $result, 'player' => ['name' => $player['name'] ?? null, 'email' => $player['email'] ?? null]];
}

function isPlayerRegistered($name, $email) {
    // Both name and email must match for a player to be considered registered
    if (empty($name) || empty($email)) return false;
    
    error_log('isPlayerRegistered: checking ' . $name . ' / ' . $email);
    
    $profiles = findPlayerByEmail($email);
    error_log('isPlayerRegistered: findPlayerByEmail returned ' . count($profiles) . ' profiles');
    
    if (empty($profiles)) return false;
    
    // Check if ANY profile has matching name and email
    foreach ($profiles as $profile) {
        $nameMatch = strcasecmp($profile['name'], $name) === 0;
        error_log('isPlayerRegistered: checking profile - stored="' . ($profile['name'] ?? '') . '" vs input="' . $name . '" => ' . ($nameMatch ? 'MATCH' : 'NO MATCH'));
        
        if ($nameMatch) {
            // Both name and email match - player is registered
            error_log('isPlayerRegistered: returning TRUE for ' . $name);
            return true;
        }
    }
    
    error_log('isPlayerRegistered: no matching profile found, returning FALSE');
    return false;
}

// Simple helper to centralize JSON response for endpoints
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
}

// End of playerProfiles.php
