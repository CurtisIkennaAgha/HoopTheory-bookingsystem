<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

try {
  $waitlistFile = '../data/waitlist.json';
  
  if (file_exists($waitlistFile)) {
    $waitlist = json_decode(file_get_contents($waitlistFile), true);
  } else {
    $waitlist = [];
  }
  
  echo json_encode($waitlist, JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
  error_log('GetWaitlist error: ' . $e->getMessage());
  http_response_code(400);
  echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}
?>
