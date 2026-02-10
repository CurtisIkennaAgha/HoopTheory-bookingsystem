<?php
header('Content-Type: application/json');

function addUserToTracking($name, $email) {
  $usersFile = __DIR__ . '/../data/users.json';
  $users = [];
  
  if (file_exists($usersFile)) {
    $users = json_decode(file_get_contents($usersFile), true) ?? [];
  }
  
  if (!isset($users[$email])) {
    $users[$email] = [
      'email' => $email,
      'name' => $name,
      'bookings' => [],
      'waitlist' => [],
      'offers' => [],
      'addedAt' => date('Y-m-d H:i:s')
    ];
  } else {
    // Update name if different
    $users[$email]['name'] = $name;
  }
  
  file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

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
  
  // Handle replace_all - if input has a special action flag
  if (isset($input['action']) && $input['action'] === 'replace_all') {
    $newWaitlist = $input['data'] ?? [];
    file_put_contents($waitlistFile, json_encode($newWaitlist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo json_encode(['status' => 'ok', 'message' => 'Waitlist replaced'], JSON_UNESCAPED_SLASHES);
    exit;
  }
  
  // Handle clear_all - if input is empty object/array
  if (empty($input)) {
    file_put_contents($waitlistFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo json_encode(['status' => 'ok', 'message' => 'Waitlist cleared'], JSON_UNESCAPED_SLASHES);
    exit;
  }
  
  // Load existing waitlist
  $waitlist = file_exists($waitlistFile) ? json_decode(file_get_contents($waitlistFile), true) : [];
  if (!is_array($waitlist)) {
    $waitlist = [];
  }
  
  $name = $input['name'] ?? 'Unknown';
  $email = $input['email'] ?? 'N/A';
  $slot = $input['slot'] ?? [];
  $slotTitle = $slot['title'] ?? 'Unknown Session';
  $slotTime = $slot['time'] ?? 'Unknown Time';
  $waitlistPosition = null;
  
  // Check if this is a block session
  $blockId = $input['blockId'] ?? null;
  $blockDates = $input['blockDates'] ?? null;
  $isBlockSession = !empty($blockId) && is_array($blockDates) && count($blockDates) > 0;
  
  // For block sessions, ALWAYS use the first date regardless of what date was sent
  if ($isBlockSession) {
    $date = $blockDates[0];
    error_log('Block session detected - forcing date to first date: ' . $date);
  } else {
    $date = $input['date'] ?? null;
  }
  
  // Single session waitlist entry
  if (!$isBlockSession && isset($date)) {
    $date = $input['date'];
    
    if (!isset($waitlist[$date])) {
      $waitlist[$date] = [];
    }
    
    // Add to waitlist for this date/slot
    $waitlistEntry = [
      'time' => $slotTime,
      'title' => $slotTitle,
      'sessionType' => $slot['sessionType'] ?? 'unknown',
      'name' => $name,
      'email' => $email,
      'blockId' => $input['blockId'] ?? null,
      'joinedAt' => date('Y-m-d H:i:s')
    ];
    
    $waitlist[$date][] = $waitlistEntry;

    // Debug: log all entries for this date
    error_log('Waitlist entries for date ' . $date . ': ' . json_encode($waitlist[$date]));
    error_log('Looking for matches - time: ' . $slotTime . ', title: ' . $slotTitle . ', blockId: ' . ($input['blockId'] ?? 'null'));

    $matches = array_filter($waitlist[$date], function($entry) use ($slotTime, $slotTitle) {
      $timeMatch = ($entry['time'] ?? '') === $slotTime;
      $titleMatch = ($entry['title'] ?? '') === $slotTitle;
      return $timeMatch && $titleMatch;
    });
    error_log('Matched entries count: ' . count($matches));
    $waitlistPosition = count($matches);
  }
  // Block session waitlist entry - store ONLY under first date with blockId and blockDates
  else if ($isBlockSession) {
    $firstDate = $blockDates[0];
    
    if (!isset($waitlist[$firstDate])) {
      $waitlist[$firstDate] = [];
    }
    
    // Check if this user already on waitlist for this block
    $alreadyOnWaitlist = false;
    foreach ($waitlist[$firstDate] as $existing) {
      if (($existing['email'] ?? '') === $email && 
          ($existing['blockId'] ?? null) === $blockId &&
          ($existing['time'] ?? '') === $slotTime &&
          ($existing['title'] ?? '') === $slotTitle) {
        $alreadyOnWaitlist = true;
        break;
      }
    }
    
    if ($alreadyOnWaitlist) {
      throw new Exception('Already on waitlist for this block session');
    }
    
    // Add single entry for the entire block
    $waitlistEntry = [
      'time' => $slotTime,
      'title' => $slotTitle,
      'sessionType' => $slot['sessionType'] ?? 'unknown',
      'name' => $name,
      'email' => $email,
      'blockId' => $blockId,
      'blockDates' => $blockDates,
      'isBlock' => true,
      'joinedAt' => date('Y-m-d H:i:s')
    ];
    
    $waitlist[$firstDate][] = $waitlistEntry;
    
    error_log('Block waitlist entry added to first date ' . $firstDate . ': ' . json_encode($waitlistEntry));
    
    // Calculate position (count only entries for this specific block)
    $matches = array_filter($waitlist[$firstDate] ?? [], function($entry) use ($slotTime, $slotTitle, $blockId) {
      $timeMatch = ($entry['time'] ?? '') === $slotTime;
      $titleMatch = ($entry['title'] ?? '') === $slotTitle;
      $blockMatch = ($entry['blockId'] ?? null) === $blockId;
      return $timeMatch && $titleMatch && $blockMatch;
    });
    error_log('Block matched entries count: ' . count($matches));
    $waitlistPosition = count($matches);
  }
  
  // Save waitlist
  // Normalize legacy block entries to first date and dedupe
  $normalized = [];
  foreach ($waitlist as $wDate => $entries) {
    if (!is_array($entries)) continue;
    foreach ($entries as $entry) {
      if (!is_array($entry)) continue;
      $targetDate = $wDate;
      if (!empty($entry['blockId']) && !empty($entry['blockDates']) && is_array($entry['blockDates']) && count($entry['blockDates']) > 0) {
        $targetDate = $entry['blockDates'][0];
      }
      if (!isset($normalized[$targetDate])) $normalized[$targetDate] = [];
      $normalized[$targetDate][] = $entry;
    }
  }

  // Deduplicate per date/time/title/email/blockId
  foreach ($normalized as $nDate => $entries) {
    $seen = [];
    $deduped = [];
    foreach ($entries as $entry) {
      $key = strtolower(trim(($entry['email'] ?? '') . '|' . ($entry['time'] ?? '') . '|' . ($entry['title'] ?? '') . '|' . ($entry['blockId'] ?? '')));
      if (isset($seen[$key])) continue;
      $seen[$key] = true;
      $deduped[] = $entry;
    }
    $normalized[$nDate] = $deduped;
  }

  $waitlist = $normalized;

  if (!file_put_contents($waitlistFile, json_encode($waitlist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX)) {
    throw new Exception('Failed to write waitlist.json');
  }

  // Update Bridge State for this slot (waitlist was modified)
  $slotsFile = __DIR__ . '/../data/availableSlots.json';
  $bookingsFile = __DIR__ . '/../data/bookings.json';
  $slots = file_exists($slotsFile) ? json_decode(file_get_contents($slotsFile), true) : [];
  $bookings = file_exists($bookingsFile) ? json_decode(file_get_contents($bookingsFile), true) : [];
  require_once __DIR__ . '/lib_bridge_state.php';

  $primaryDate = $isBlockSession ? ($blockDates[0] ?? null) : ($date ?? null);
  if ($primaryDate) {
    updateBridgeState($slots, $primaryDate, $slotTime, $slotTitle, $bookings, $waitlist, $blockId);
    file_put_contents($slotsFile, json_encode($slots, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
  }
  
  // Add user to tracking
  addUserToTracking($name, $email);
  
  echo json_encode([
    'status' => 'ok',
    'message' => 'Added to waitlist',
    'waitlistPosition' => $waitlistPosition
  ], JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
  error_log('SaveWaitlist error: ' . $e->getMessage());
  http_response_code(400);
  echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}
?>
