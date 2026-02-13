<?php
// editSession.php - Update session details in all relevant JSON files
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

function respond($success, $msg, $data = null) {
    echo json_encode(['success' => $success, 'message' => $msg, 'data' => $data]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) respond(false, 'Invalid JSON payload');

// Required fields
$required = ['date', 'time', 'title', 'capacity', 'sessionType', 'price', 'location', 'originalTitle', 'originalDate', 'originalTime'];
foreach ($required as $field) {
    if (!isset($input[$field])) respond(false, "Missing field: $field");
}

$date = $input['date'];
$time = $input['time'];
$title = $input['title'];
$capacity = $input['capacity'];
$sessionType = $input['sessionType'];
$price = $input['price'];
$location = $input['location'];
$blockId = isset($input['blockId']) ? $input['blockId'] : null;
$blockDates = isset($input['blockDates']) ? $input['blockDates'] : null;
$originalTitle = $input['originalTitle'];
$originalDate = $input['originalDate'];
$originalTime = $input['originalTime'];
$originalBlockId = isset($input['originalBlockId']) ? $input['originalBlockId'] : null;

$dataDir = realpath(__DIR__ . '/../data');

// --- Update availableSlots.json ---
$slotsFile = "$dataDir/availableSlots.json";
$slots = json_decode(file_get_contents($slotsFile), true);
if (!$slots) $slots = [];

$updated = false;
if ($blockId && $blockDates) {
    // Block session: update all dates in blockDates
    foreach ($blockDates as $blockDate) {
        if (isset($slots[$blockDate])) {
            foreach ($slots[$blockDate] as &$slot) {
                if (isset($slot['blockId']) && $slot['blockId'] === $originalBlockId) {
                    $slot['time'] = $time;
                    $slot['title'] = $title;
                    $slot['capacity'] = $capacity;
                    $slot['sessionType'] = $sessionType;
                    $slot['price'] = $price;
                    $slot['location'] = $location;
                    $slot['blockDates'] = $blockDates;
                    $updated = true;
                }
            }
        }
    }
} else {
    // Single session
    if (isset($slots[$originalDate])) {
        foreach ($slots[$originalDate] as $idx => &$slot) {
            if ($slot['time'] === $originalTime && $slot['title'] === $originalTitle) {
                // If date changed, move slot to new date key
                $slot['time'] = $time;
                $slot['title'] = $title;
                $slot['capacity'] = $capacity;
                $slot['numberOfSpots'] = $capacity;
                $slot['sessionType'] = $sessionType;
                $slot['price'] = $price;
                $slot['location'] = $location;
                if ($date !== $originalDate) {
                    // Remove from old date
                    $movedSlot = $slot;
                    unset($slots[$originalDate][$idx]);
                    // Add to new date
                    $slots[$date] = isset($slots[$date]) ? $slots[$date] : [];
                    $slots[$date][] = $movedSlot;
                }
                $updated = true;
            }
        }
        // Clean up if old date array is empty
        if (count($slots[$originalDate]) === 0) unset($slots[$originalDate]);
    }
}
file_put_contents($slotsFile, json_encode($slots, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

// --- Update bookings.json ---
$bookingsFile = "$dataDir/bookings.json";
$bookings = json_decode(file_get_contents($bookingsFile), true);
if (!$bookings) $bookings = [];
if ($blockId && $blockDates) {
    foreach ($blockDates as $blockDate) {
        if (isset($bookings[$blockDate])) {
            foreach ($bookings[$blockDate] as &$booking) {
                // Format: "HH:MM - Title (Name) (Email)"
                if (preg_match('/^(.+?)\s*-\s*(.+?)\s*\(([^)]+)\)\s*\(([^)]+)\)$/', $booking, $m)) {
                    if ($m[1] === $originalTime && $m[2] === $originalTitle) {
                        $booking = "$time - $title ($m[3]) ($m[4])";
                        $updated = true;
                    }
                }
            }
        }
    }
} else {
    if (isset($bookings[$originalDate])) {
        foreach ($bookings[$originalDate] as $idx => &$booking) {
            if (preg_match('/^(.+?)\s*-\s*(.+?)\s*\(([^)]+)\)\s*\(([^)]+)\)$/', $booking, $m)) {
                if ($m[1] === $originalTime && $m[2] === $originalTitle) {
                    $booking = "$time - $title ($m[3]) ($m[4])";
                    if ($date !== $originalDate) {
                        // Move booking to new date
                        $movedBooking = $booking;
                        unset($bookings[$originalDate][$idx]);
                        $bookings[$date] = isset($bookings[$date]) ? $bookings[$date] : [];
                        $bookings[$date][] = $movedBooking;
                    }
                    $updated = true;
                }
            }
        }
        // Clean up if old date array is empty
        if (count($bookings[$originalDate]) === 0) unset($bookings[$originalDate]);
    }
}
file_put_contents($bookingsFile, json_encode($bookings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

// --- Update bookingMappings.json ---
$mappingsFile = "$dataDir/bookingMappings.json";
$mappings = json_decode(file_get_contents($mappingsFile), true);
if (!$mappings) $mappings = [];
foreach ($mappings as $email => &$mapping) {
    if ($blockId && $blockDates) {
        foreach ($blockDates as $blockDate) {
            $oldKey = $blockDate . '_' . $originalTime . '_' . $originalTitle;
            $newKey = $blockDate . '_' . $time . '_' . $title;
            if (isset($mapping[$oldKey])) {
                $mapping[$newKey] = $mapping[$oldKey];
                unset($mapping[$oldKey]);
                $updated = true;
            }
        }
    } else {
        $oldKey = $originalDate . '_' . $originalTime . '_' . $originalTitle;
        $newKey = $date . '_' . $time . '_' . $title;
        if (isset($mapping[$oldKey])) {
            $mapping[$newKey] = $mapping[$oldKey];
            unset($mapping[$oldKey]);
            $updated = true;
        }
    }
}
file_put_contents($mappingsFile, json_encode($mappings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

// --- Update waitlist.json ---
$waitlistFile = "$dataDir/waitlist.json";
$waitlist = json_decode(file_get_contents($waitlistFile), true);
if (!$waitlist) $waitlist = [];
if ($blockId && $blockDates) {
    foreach ($blockDates as $blockDate) {
        if (isset($waitlist[$blockDate])) {
            foreach ($waitlist[$blockDate] as &$entry) {
                if ($entry['time'] === $originalTime && $entry['title'] === $originalTitle) {
                    $entry['time'] = $time;
                    $entry['title'] = $title;
                    $entry['capacity'] = $capacity;
                    $entry['sessionType'] = $sessionType;
                    $entry['price'] = $price;
                    $entry['location'] = $location;
                    $updated = true;
                }
            }
        }
    }
} else {
    if (isset($waitlist[$originalDate])) {
        foreach ($waitlist[$originalDate] as &$entry) {
            if ($entry['time'] === $originalTime && $entry['title'] === $originalTitle) {
                $entry['time'] = $time;
                $entry['title'] = $title;
                $entry['capacity'] = $capacity;
                $entry['sessionType'] = $sessionType;
                $entry['price'] = $price;
                $entry['location'] = $location;
                $updated = true;
            }
        }
    }
}
file_put_contents($waitlistFile, json_encode($waitlist, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

respond(true, $updated ? 'Session updated.' : 'No matching session found.', $input);
