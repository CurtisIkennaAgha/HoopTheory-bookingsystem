<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
// Always return fresh, uncached data
$file = '../data/availableSlots.json';
clearstatcache(true, $file);
$data = @file_get_contents($file);
if ($data === false) {
    error_log('Failed to read availableSlots.json in getSlots.php');
    http_response_code(500);
    echo json_encode(["status"=>"error","message"=>"Failed to read availableSlots.json"]);
    exit;
}
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
echo $data;
?>