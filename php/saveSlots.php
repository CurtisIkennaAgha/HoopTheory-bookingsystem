<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
$data = file_get_contents('php://input');
$slotsFile = '../data/availableSlots.json';
if (!is_writable(dirname($slotsFile))) {
	error_log('Directory not writable for availableSlots.json in saveSlots.php');
	http_response_code(400);
	echo json_encode(["status"=>"error","message"=>"Directory not writable for availableSlots.json"]);
	exit;
}
clearstatcache(true, $slotsFile);
$write = @file_put_contents($slotsFile, $data, LOCK_EX);
if ($write === false) {
	error_log('Failed to write availableSlots.json in saveSlots.php');
	http_response_code(400);
	echo json_encode(["status"=>"error","message"=>"Failed to write availableSlots.json"]);
	exit;
}
echo json_encode(["status"=>"ok"]);
?>
