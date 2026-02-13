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
    if (!$email || !$date || !$time || !$title) {
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
    if (isset($bookings[$date])) {
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
        if (
            (isset($mapping['date']) && $mapping['date'] === $date) &&
            (isset($mapping['slot']) && $mapping['slot'] === $time) &&
            (isset($mapping['title']) && $mapping['title'] === $title)
        ) {
            $mapping['name'] = $name;
            $mapping['email'] = $email;
            $mapping['status'] = $status;
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
