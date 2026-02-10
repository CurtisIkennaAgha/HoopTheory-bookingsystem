<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

try {
  $offersFile = '../data/offers.json';
  
  if (file_exists($offersFile)) {
    $offers = json_decode(file_get_contents($offersFile), true);
  } else {
    $offers = [];
  }
  
  echo json_encode($offers, JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
  error_log('GetOffers error: ' . $e->getMessage());
  http_response_code(400);
  echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}
?>
