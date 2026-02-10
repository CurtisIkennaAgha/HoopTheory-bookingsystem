<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
// Always return fresh, uncached data
$file = '../data/bookings.json';
clearstatcache(true, $file);
$data = @file_get_contents($file);
if ($data === false) {
    error_log('Failed to read bookings.json in getBookings.php');
    http_response_code(500);
    echo json_encode(["status"=>"error","message"=>"Failed to read bookings.json"]);
    exit;
}

$bookings = json_decode($data, true);
$mappingsFile = '../data/bookingMappings.json';
$mappings = file_exists($mappingsFile) ? json_decode(file_get_contents($mappingsFile), true) : [];
$now = time();
$expiredBookingIds = [];

// Remove expired bookings from both bookings and bookingMappings
foreach ($mappings as $bookingId => $details) {
    $isConfirmed = (isset($details['status']) && $details['status'] === 'Confirmed') || isset($details['confirmedAt']);
    if (!$isConfirmed && isset($details['expiryTimestamp']) && $now > $details['expiryTimestamp']) {
        $expiredBookingIds[] = $bookingId;
        // Remove from bookings.json
        $date = $details['date'] ?? null;
        $slot = $details['slot'] ?? null;
        $title = $details['title'] ?? null;
        if ($date && isset($bookings[$date])) {
            $bookings[$date] = array_filter($bookings[$date], function($bookingStr) use ($slot, $title, $details) {
                // Match booking string format
                return strpos($bookingStr, "{$slot} - {$title} ({$details['name']}) ({$details['email']})") === false;
            });
        }
    }
}

// Remove expired from bookingMappings
if (!empty($expiredBookingIds)) {
    foreach ($expiredBookingIds as $bookingId) {
        unset($mappings[$bookingId]);
    }
    // Save updated files
    file_put_contents($file, json_encode($bookings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents($mappingsFile, json_encode($mappings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
echo json_encode($bookings, JSON_UNESCAPED_SLASHES);
?>