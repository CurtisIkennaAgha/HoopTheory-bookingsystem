<?php
header('Content-Type: application/json');

try {
  $rawInput = file_get_contents('php://input');
  
  if ($rawInput === '') {
    throw new Exception('No input data received');
  }
  
  $input = json_decode($rawInput, true);
  
  if ($input === null) {
    throw new Exception('Invalid JSON received');
  }
  
  $waitlistFile = '../data/waitlist.json';
  
  // Load existing waitlist
  $waitlist = file_exists($waitlistFile) ? json_decode(file_get_contents($waitlistFile), true) : [];
  if (!is_array($waitlist)) {
    $waitlist = [];
  }
  
  $date = $input['date'] ?? null;
  $email = $input['email'] ?? null;
  $name = $input['name'] ?? null;
  $blockId = $input['blockId'] ?? null;
  $blockDates = $input['blockDates'] ?? null;
  
  if (!$email) {
    throw new Exception('Missing email');
  }
  
  // If blockId provided, remove from first date only (where block entries are stored)
  if ($blockId) {
    // For block entries, they're only stored under the first date
    if (is_array($blockDates) && count($blockDates) > 0) {
      $firstDate = $blockDates[0];
      if (isset($waitlist[$firstDate])) {
        $waitlist[$firstDate] = array_filter($waitlist[$firstDate], function($entry) use ($email, $blockId) {
          $emailMatch = isset($entry['email']) && $entry['email'] === $email;
          $blockMatch = isset($entry['blockId']) && $entry['blockId'] === $blockId;
          return !($emailMatch && $blockMatch);
        });
        $waitlist[$firstDate] = array_values($waitlist[$firstDate]);
        if (count($waitlist[$firstDate]) === 0) {
          unset($waitlist[$firstDate]);
        }
      }
    } else if ($date) {
      // Fallback: if blockDates not provided, try the given date
      if (isset($waitlist[$date])) {
        $waitlist[$date] = array_filter($waitlist[$date], function($entry) use ($email, $blockId) {
          $emailMatch = isset($entry['email']) && $entry['email'] === $email;
          $blockMatch = isset($entry['blockId']) && $entry['blockId'] === $blockId;
          return !($emailMatch && $blockMatch);
        });
        $waitlist[$date] = array_values($waitlist[$date]);
        if (count($waitlist[$date]) === 0) {
          unset($waitlist[$date]);
        }
      }
    }
  } else if ($date) {
    // Remove the person from the waitlist for this date (single session)
    if (isset($waitlist[$date])) {
      $waitlist[$date] = array_filter($waitlist[$date], function($entry) use ($email) {
        return $entry['email'] !== $email;
      });
      
      // Reindex array
      $waitlist[$date] = array_values($waitlist[$date]);
      
      // Remove date if no more entries
      if (count($waitlist[$date]) === 0) {
        unset($waitlist[$date]);
      }
    }
  } else {
    throw new Exception('Missing date or blockId');
  }
  
  // Save updated waitlist
  if (!file_put_contents($waitlistFile, json_encode($waitlist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
    throw new Exception('Failed to write waitlist.json');
  }
  
  // Update Bridge State for this slot (waitlist was modified)
  $slotsFile = '../data/availableSlots.json';
  $bookingsFile = '../data/bookings.json';
  $slots = file_exists($slotsFile) ? json_decode(file_get_contents($slotsFile), true) : [];
  $bookings = file_exists($bookingsFile) ? json_decode(file_get_contents($bookingsFile), true) : [];
  require_once __DIR__ . '/lib_bridge_state.php';
  
  // Get time and title from the first date's entries to find matching slot
  $datesToCheck = [];
  if ($blockId && is_array($blockDates)) {
    $datesToCheck = $blockDates;
    $primaryDate = $blockDates[0];
  } else if ($date) {
    $datesToCheck = [$date];
    $primaryDate = $date;
  }
  
  // Find the slot to update bridge state
  if (isset($slots[$primaryDate])) {
    foreach ($slots[$primaryDate] as &$slot) {
      // Check if this could be the slot we're interested in
      // We'll update all slots just to be safe (multiple slots at same time are rare)
      $slotTime = $slot['time'] ?? null;
      $slotTitle = $slot['title'] ?? null;
      if ($slotTime && $slotTitle) {
        updateBridgeState($slots, $primaryDate, $slotTime, $slotTitle, $bookings, $waitlist, null);
      }
    }
    file_put_contents($slotsFile, json_encode($slots, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
  }
  
  echo json_encode(['status' => 'ok', 'message' => 'Removed from waitlist'], JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
  error_log('DeleteFromWaitlist error: ' . $e->getMessage());
  http_response_code(400);
  echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}
?>
