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
$blockEditFields = ['price','location','capacity','sessionType','title'];
if (isset($slots[$origDate]) && is_array($slots[$origDate])) {
    foreach ($slots[$origDate] as $i => $slot) {
        if (
            isset($slot['time'], $slot['title']) &&
            $slot['time'] === $origTime &&
            $slot['title'] === $origTitle
        ) {
            // If this is a block session, update all slots with same blockId and blockDates
            if (!empty($slot['blockId']) && !empty($slot['blockDates']) && is_array($slot['blockDates'])) {
                $blockId = $slot['blockId'];
                $blockDates = $slot['blockDates'];
                foreach ($blockDates as $bDate) {
                    if (isset($slots[$bDate]) && is_array($slots[$bDate])) {
                        foreach ($slots[$bDate] as $j => $bSlot) {
                            if (isset($bSlot['blockId']) && $bSlot['blockId'] === $blockId) {
                                // Only update block-wide fields
                                foreach ($blockEditFields as $field) {
                                    if (array_key_exists($field, $input)) {
                                        $slots[$bDate][$j][$field] = $input[$field];
                                    }
                                }
                            }
                        }
                    }
                }
            }
            // Always update the original slot fields (including time/duration etc)
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

// --- Propagate edits to bookings.json and bookingMappings.json ---
// --- Propagate edits to waitlist.json ---
$waitlistFile = '../data/waitlist.json';
$waitlist = file_exists($waitlistFile) ? json_decode(file_get_contents($waitlistFile), true) : [];

// Block session: update waitlist entries for all blockDates
if (isset($input['blockId']) && isset($input['blockDates']) && is_array($input['blockDates'])) {
    $blockId = $input['blockId'];
    $blockDates = $input['blockDates'];
    // Waitlist entries for block sessions are stored under the first date
    $firstDate = $blockDates[0];
    if (isset($waitlist[$firstDate]) && is_array($waitlist[$firstDate])) {
        foreach ($waitlist[$firstDate] as &$entry) {
            if (isset($entry['blockId']) && $entry['blockId'] === $blockId) {
                if (isset($input['title'])) $entry['title'] = $input['title'];
                if (isset($input['time'])) $entry['time'] = $input['time'];
                if (isset($input['location'])) $entry['location'] = $input['location'];
                if (isset($input['price'])) $entry['price'] = $input['price'];
            }
        }
        unset($entry);
    }
}
// Single session: update waitlist entries for the original date
else if (isset($origDate) && isset($origTime) && isset($origTitle)) {
    if (isset($waitlist[$origDate]) && is_array($waitlist[$origDate])) {
        foreach ($waitlist[$origDate] as &$entry) {
            if (
                isset($entry['time']) && $entry['time'] === $origTime &&
                isset($entry['title']) && $entry['title'] === $origTitle
            ) {
                if (isset($input['title'])) $entry['title'] = $input['title'];
                if (isset($input['time'])) $entry['time'] = $input['time'];
                if (isset($input['location'])) $entry['location'] = $input['location'];
                if (isset($input['price'])) $entry['price'] = $input['price'];
            }
        }
        unset($entry);
    }
}

file_put_contents($waitlistFile, json_encode($waitlist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
$bookingsFile = '../data/bookings.json';
$mappingsFile = '../data/bookingMappings.json';
$bookings = file_exists($bookingsFile) ? json_decode(file_get_contents($bookingsFile), true) : [];
$mappings = file_exists($mappingsFile) ? json_decode(file_get_contents($mappingsFile), true) : [];

// Update bookings.json
foreach ($bookings as $date => &$bookingArr) {
    foreach ($bookingArr as &$bookingStr) {
        // Match block bookings by blockId, or single bookings by date/time/title
        $parts = preg_match('/^(.+?)\s*-\s*(.+?)\s*\(([^)]+)\)\s*\(([^)]+)\)$/', $bookingStr, $matches) ? $matches : null;
        if ($parts) {
            $bTime = $parts[1];
            $bTitle = $parts[2];
            $bName = $parts[3];
            $bEmail = $parts[4];
            // Find slot for this booking
            $slot = isset($slots[$date]) ? array_values(array_filter($slots[$date], function($s) use ($bTime, $bTitle) { return $s['time'] === $bTime && $s['title'] === $bTitle; })) : [];
            $slot = $slot && count($slot) > 0 ? $slot[0] : null;
            // If slot matches original session (by blockId or date/time/title), update booking string
            $isBlock = $slot && isset($slot['blockId']) && $slot['blockId'] && isset($input['blockId']) && $slot['blockId'] === $input['blockId'];
            $isSingle = !$isBlock && $bTime === $origTime && $bTitle === $origTitle && $date === $origDate;
            if ($isBlock || $isSingle) {
                $newTime = $slot && isset($slot['time']) ? $slot['time'] : $bTime;
                $newTitle = $slot && isset($slot['title']) ? $slot['title'] : $bTitle;
                $bookingStr = "$newTime - $newTitle ($bName) ($bEmail)";
            }
        }
    }
}
unset($bookingArr, $bookingStr);

// Update bookingMappings.json
foreach ($mappings as $key => &$mapping) {
    // Block booking: match by blockId
    if (isset($mapping['blockId']) && $mapping['blockId'] && isset($input['blockId']) && $mapping['blockId'] === $input['blockId']) {
        if (isset($input['title'])) $mapping['title'] = $input['title'];
        if (isset($input['time'])) $mapping['slot'] = $input['time'];
        if (isset($input['location'])) $mapping['location'] = $input['location'];
        if (isset($input['price'])) $mapping['price'] = $input['price'];
    }
    // Single booking: match by date/time/title
    else if (
        isset($mapping['date']) && $mapping['date'] === $origDate &&
        isset($mapping['slot']) && $mapping['slot'] === $origTime &&
        isset($mapping['title']) && $mapping['title'] === $origTitle
    ) {
        if (isset($input['title'])) $mapping['title'] = $input['title'];
        if (isset($input['time'])) $mapping['slot'] = $input['time'];
        if (isset($input['location'])) $mapping['location'] = $input['location'];
        if (isset($input['price'])) $mapping['price'] = $input['price'];
    }
}
unset($mapping);

file_put_contents($bookingsFile, json_encode($bookings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
file_put_contents($mappingsFile, json_encode($mappings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

echo json_encode(["status"=>"ok"]);
exit;
