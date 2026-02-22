<?php
header('Content-Type: application/json');
$file = __DIR__ . '/../data/bookingMappings.json';
if (!file_exists($file)) {
  echo json_encode([]);
  exit;
}
clearstatcache(true, $file);
$data = json_decode(file_get_contents($file), true);
echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
