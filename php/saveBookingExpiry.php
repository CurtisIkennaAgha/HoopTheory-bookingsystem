<?php
$expiryConfigFile = '../data/bookingExpiryConfig.json';
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

$bookingMappingsFile = '../data/bookingMappings.json';

$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['expirySeconds']) || !is_numeric($input['expirySeconds'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid expirySeconds']);
    exit;
}
$expirySeconds = intval($input['expirySeconds']);

if (!file_exists($bookingMappingsFile)) {
    echo json_encode(['success' => false, 'error' => 'bookingMappings.json not found']);
    exit;
}

$bookingMappings = json_decode(file_get_contents($bookingMappingsFile), true);
if (!$bookingMappings) $bookingMappings = [];

foreach ($bookingMappings as $bookingId => &$booking) {
    if (isset($booking['reservationTimestamp'])) {
        $booking['expiryTimestamp'] = $booking['reservationTimestamp'] + $expirySeconds;
    } else {
        $booking['expiryTimestamp'] = time() + $expirySeconds;
    }
}

file_put_contents($bookingMappingsFile, json_encode($bookingMappings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
clearstatcache(true, $bookingMappingsFile);
echo json_encode(['success' => true, 'expirySeconds' => $expirySeconds]);

// Save expirySeconds to config for future bookings
$expiryConfig = [ 'expirySeconds' => $expirySeconds ];
file_put_contents($expiryConfigFile, json_encode($expiryConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
clearstatcache(true, $expiryConfigFile);
