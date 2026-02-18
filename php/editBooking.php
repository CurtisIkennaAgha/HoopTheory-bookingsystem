<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Edit booking: expects { name, email, status, date, time, title, action: 'update' }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['action']) || $input['action'] !== 'update') {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
    }
    $name = $input['name'] ?? '';
    $email = $input['email'] ?? '';
    $status = $input['status'] ?? '';
    $date = $input['date'] ?? '';
    $time = $input['time'] ?? '';
    $title = $input['title'] ?? '';
    $blockId = $input['blockId'] ?? null;
    $blockDates = $input['blockDates'] ?? null;
    // For block bookings, allow date to be null, require blockId and blockDates
    $isBlock = $blockId && is_array($blockDates) && count($blockDates) > 0;
    if (!$email || !$time || !$title || (!$isBlock && !$date) || ($isBlock && (!$blockId || !is_array($blockDates) || count($blockDates) === 0))) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }
    $slotsFile = __DIR__ . '/../data/availableSlots.json';
    $bookingsFile = __DIR__ . '/../data/bookings.json';
    $mappingsFile = __DIR__ . '/../data/bookingMappings.json';
    $slots = file_exists($slotsFile) ? json_decode(file_get_contents($slotsFile), true) : [];
    $bookings = file_exists($bookingsFile) ? json_decode(file_get_contents($bookingsFile), true) : [];
    $mappings = file_exists($mappingsFile) ? json_decode(file_get_contents($mappingsFile), true) : [];
    $updated = false;
    // Update availableSlots.json
    if (isset($slots[$date])) {
        foreach ($slots[$date] as &$slot) {
            if ($slot['time'] === $time && $slot['title'] === $title) {
                // Update bookedUsers
                if (isset($slot['bookedUsers']) && is_array($slot['bookedUsers']) && count($slot['bookedUsers']) > 0) {
                    $slot['bookedUsers'][0]['name'] = $name;
                    $slot['bookedUsers'][0]['email'] = $email;
                } else {
                    $slot['bookedUsers'] = [['name' => $name, 'email' => $email]];
                }
                // Update status
                $slot['status'] = $status;
                $updated = true;
                break;
            }
        }
        unset($slot);
    }
    // Update bookings.json (legacy string format)
    if ($isBlock && is_array($blockDates)) {
        foreach ($blockDates as $blockDate) {
            if (isset($bookings[$blockDate])) {
                foreach ($bookings[$blockDate] as &$bookingStr) {
                    // Match by slot, name, and email (ignore old title)
                    $pattern = '/^' . preg_quote($time, '/') . ' - [^\(]+ \(' . preg_quote($name, '/') . '\) \(' . preg_quote($email, '/') . '\)/';
                    if (preg_match($pattern, $bookingStr)) {
                        $bookingStr = "$time - $title ($name) ($email)";
                        $updated = true;
                    }
                }
                unset($bookingStr);
            }
        }
    } else if (isset($bookings[$date])) {
        foreach ($bookings[$date] as &$bookingStr) {
            // Format: "HH:MM - Title (Name) (Email)"
            if (strpos($bookingStr, "$time - $title ") === 0) {
                $bookingStr = "$time - $title ($name) ($email)";
                $updated = true;
                break;
            }
        }
        unset($bookingStr);
    }
    // Update bookingMappings.json (if mapping exists)
    foreach ($mappings as $key => &$mapping) {
        // Block booking: match by blockId and email (ignore title)
        if ($isBlock && isset($mapping['blockId']) && $mapping['blockId'] === $blockId && isset($mapping['email']) && $mapping['email'] === $email) {
            $mapping['name'] = $name;
            $mapping['email'] = $email;
            $mapping['status'] = $status;
            // Also update title, slot, blockDates if provided
            if ($title) $mapping['title'] = $title;
            if ($time) $mapping['slot'] = $time;
            if ($blockDates && is_array($blockDates)) $mapping['blockDates'] = $blockDates;
            $updated = true;
        }
        // Single booking: match by date, slot, title
        elseif (
            !$isBlock && isset($mapping['date']) && $mapping['date'] === $date &&
            isset($mapping['slot']) && $mapping['slot'] === $time &&
            isset($mapping['title']) && $mapping['title'] === $title &&
            isset($mapping['email']) && $mapping['email'] === $email
        ) {
            $mapping['name'] = $name;
            $mapping['email'] = $email;
            $mapping['status'] = $status;
            if ($title) $mapping['title'] = $title;
            if ($time) $mapping['slot'] = $time;
            $updated = true;
        }
    }
    unset($mapping);
    // Save all
    file_put_contents($slotsFile, json_encode($slots, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    file_put_contents($bookingsFile, json_encode($bookings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    file_put_contents($mappingsFile, json_encode($mappings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    if ($updated) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Booking not found']);
    }
    exit;
}
echo json_encode(['success' => false, 'message' => 'Invalid request']);
