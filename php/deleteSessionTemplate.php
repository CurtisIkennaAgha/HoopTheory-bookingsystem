<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['templateId'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing templateId']);
    exit;
}

$templateFile = __DIR__ . '/../data/sessionTemplates.json';
clearstatcache(true, $templateFile);
$templates = file_exists($templateFile) ? json_decode(file_get_contents($templateFile), true) : [];

$templates = array_values(array_filter($templates, function($tpl) use ($input) {
    return $tpl['templateId'] !== $input['templateId'];
}));
file_put_contents($templateFile, json_encode($templates, JSON_PRETTY_PRINT), LOCK_EX);
echo json_encode(['success' => true]);
