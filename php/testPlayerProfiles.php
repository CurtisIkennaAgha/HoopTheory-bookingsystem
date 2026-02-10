<?php
// Simple CLI tests for playerProfiles
require_once __DIR__ . '/playerProfiles.php';

function pretty($v){echo json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";}

echo "== Test: Save incomplete profile (draft) ==\n";
$draft = [
    'name' => 'Alice Test',
    'email' => 'alice@example.com',
    'age' => 28,
    'experience' => 'Beginner',
    'medical' => 'None',
    'emergency' => ['name' => 'Bob', 'phone' => '0123456', 'relationship' => 'Friend'],
    'media_consent' => true,
    'media_consent_text' => 'I consent',
    'waiver_acknowledged' => false,
    'waiver_text' => '',
];
$r = savePlayerProfile($draft);
pretty($r);

echo "== Test: isPlayerRegistered (should be false) ==\n";
pretty(['registered' => isPlayerRegistered('Alice Test', 'alice@example.com')]);

echo "== Test: Complete registration by acknowledging waiver ==\n";
$draft['waiver_acknowledged'] = true;
$draft['waiver_text'] = 'I accept the waiver';
$r2 = savePlayerProfile($draft);
pretty($r2);

echo "== Test: isPlayerRegistered (should be true) ==\n";
pretty(['registered' => isPlayerRegistered('Alice Test', 'alice@example.com')]);

echo "== Test: Invalid profile (bad email) ==\n";
$bad = $draft; $bad['email'] = 'not-an-email';
$r3 = savePlayerProfile($bad);
pretty($r3);

echo "== Done ==\n";
