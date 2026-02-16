<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
// Read input
$input = json_decode(file_get_contents('php://input'), true);
$slotsFile = '../data/availableSlots.json';
if (!is_writable(dirname($slotsFile))) {
    error_log('Directory not writable for availableSlots.json in saveSlotsEdit.php');
    http_response_code(400);
    echo json_encode(["status"=>"error","message"=>"Directory not writable for availableSlots.json"]);
    exit;
}
clearstatcache(true, $slotsFile);
$slots = file_exists($slotsFile) ? json_decode(file_get_contents($slotsFile), true) : [];

// Edit a single slot by originalDate, originalTime, originalTitle
$origDate = $input['originalDate'] ?? null;
$origTime = $input['originalTime'] ?? null;
$origTitle = $input['originalTitle'] ?? null;
if (!$origDate || !$origTime || !$origTitle) {
    http_response_code(400);
    echo json_encode(["status"=>"error","message"=>"Missing original slot keys"]);
    exit;
}

// Find and update the slot
$found = false;
if (isset($slots[$origDate]) && is_array($slots[$origDate])) {
    foreach ($slots[$origDate] as $i => $slot) {
        if (
            isset($slot['time'], $slot['title']) &&
            $slot['time'] === $origTime &&
            $slot['title'] === $origTitle
        ) {
            // Update slot fields
            $fields = ['time','duration','title','capacity','sessionType','price','location','blockId','blockDates'];
            foreach ($fields as $field) {
                if (array_key_exists($field, $input)) {
                    $slots[$origDate][$i][$field] = $input[$field];
                }
            }
            // Remove any original* keys if present
            unset($slots[$origDate][$i]['originalDate'], $slots[$origDate][$i]['originalTime'], $slots[$origDate][$i]['originalTitle']);
            $found = true;
            // If date changed, move slot to new date
            if (isset($input['date']) && $input['date'] !== $origDate) {
                $movedSlot = $slots[$origDate][$i];
                unset($slots[$origDate][$i]);
                $slots[$input['date']] = $slots[$input['date']] ?? [];
                $slots[$input['date']][] = $movedSlot;
                // Clean up old date if empty
                $slots[$origDate] = array_values(array_filter($slots[$origDate]));
                if (empty($slots[$origDate])) unset($slots[$origDate]);
            }
            break;
        }
    }
}
if (!$found) {
    http_response_code(404);
    echo json_encode(["status"=>"error","message"=>"Slot not found for edit"]);
    exit;
}
// Remove any lingering original* keys from all slots before saving
foreach ($slots as $date => &$slotArr) {
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
$write = @file_put_contents($slotsFile, json_encode($slots, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
if ($write === false) {
    error_log('Failed to write availableSlots.json in saveSlotsEdit.php');
    http_response_code(400);
    echo json_encode(["status"=>"error","message"=>"Failed to write availableSlots.json"]);
    exit;
}
echo json_encode(["status"=>"ok"]);
exit;
