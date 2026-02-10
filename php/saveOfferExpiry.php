<?php
// saveOfferExpiry.php: Update expiry for all pending offers and set new default expirySeconds
$offerExpiryConfigFile = '../data/offerExpiryConfig.json';
$offersFile = '../data/offers.json';
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['expirySeconds']) || !is_numeric($input['expirySeconds'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid expirySeconds']);
    exit;
}
$expirySeconds = intval($input['expirySeconds']);

if (!file_exists($offersFile)) {
    echo json_encode(['success' => false, 'error' => 'offers.json not found']);
    exit;
}

$offers = json_decode(file_get_contents($offersFile), true);
if (!$offers) $offers = [];

$now = time();
foreach ($offers as $token => &$offer) {
    if ($offer['status'] === 'pending') {
        $createdAt = isset($offer['createdAt']) ? strtotime($offer['createdAt']) : $now;
        $offer['expiresAt'] = date('Y-m-d H:i:s', $createdAt + $expirySeconds);
    }
}
file_put_contents($offersFile, json_encode($offers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
clearstatcache(true, $offersFile);
echo json_encode(['success' => true, 'expirySeconds' => $expirySeconds]);

// Save expirySeconds to offer config for future offers
$expiryConfig = [ 'expirySeconds' => $expirySeconds ];
file_put_contents($offerExpiryConfigFile, json_encode($expiryConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
clearstatcache(true, $offerExpiryConfigFile);
