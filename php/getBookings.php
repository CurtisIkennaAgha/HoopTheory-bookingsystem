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

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
echo json_encode($bookings, JSON_UNESCAPED_SLASHES);
?>