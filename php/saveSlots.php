<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
// Read input
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
error_log('[saveSlots.php] Raw input: ' . $rawInput);
error_log('[saveSlots.php] Decoded input: ' . json_encode($input));
$slotsFile = '../data/availableSlots.json';
if (!is_writable(dirname($slotsFile))) {
	error_log('Directory not writable for availableSlots.json in saveSlots.php');
	http_response_code(400);
	echo json_encode(["status"=>"error","message"=>"Directory not writable for availableSlots.json", "php_error"=>"Directory not writable for availableSlots.json in saveSlots.php"]);
	exit;
}
// Only allow full slots structure creation
if (!is_array($input) && !is_object($input)) {
	http_response_code(400);
	echo json_encode(["status"=>"error","message"=>"Input must be a slots object or array", "php_error"=>"Input must be a slots object or array"]);
	exit;
}
// Allow empty object (no slots) for full deletion
if ((is_array($input) && count($input) === 0) || (is_object($input) && count((array)$input) === 0)) {
	// Save empty object to file
	$write = @file_put_contents($slotsFile, json_encode(new stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    echo json_encode(["status"=>"ok", "php_debug"=>"Empty slots object saved"]);
	exit;
}
// Clean up slot fields and save as 'capacity'
foreach ($input as $date => &$slotArr) {
	if (is_array($slotArr)) {
		foreach ($slotArr as &$slot) {
			unset($slot['originalDate'], $slot['originalTime'], $slot['originalTitle']);
			if (isset($slot['numberOfSpots']) && !isset($slot['capacity'])) {
				$slot['capacity'] = $slot['numberOfSpots'];
			}
			if (isset($slot['capacity'])) {
				$slot['capacity'] = (int)$slot['capacity'];
			}
			unset($slot['numberOfSpots']);
		}
	}
}
unset($slotArr, $slot);
error_log('[saveSlots.php] Writing to availableSlots.json: ' . json_encode($input));
$write = @file_put_contents($slotsFile, json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
error_log('[saveSlots.php] Write result: ' . ($write === false ? 'FAIL' : 'OK'));
if ($write === false) {
	error_log('Failed to write availableSlots.json in saveSlots.php');
	http_response_code(400);
	echo json_encode(["status"=>"error","message"=>"Failed to write availableSlots.json", "php_error"=>"Failed to write availableSlots.json in saveSlots.php"]);
	exit;
}
echo json_encode(["status"=>"ok", "php_debug"=>"Slots saved successfully"]);
exit;
