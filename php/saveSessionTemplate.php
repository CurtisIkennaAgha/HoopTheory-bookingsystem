<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['name'], $input['type'], $input['formFields'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

$templateFile = __DIR__ . '/../data/sessionTemplates.json';
clearstatcache(true, $templateFile);
$templates = file_exists($templateFile) ? json_decode(file_get_contents($templateFile), true) : [];

$template = [
    'templateId' => uniqid('template-', true),
    'name' => $input['name'],
    'type' => $input['type'],
    'attributes' => isset($input['attributes']) ? $input['attributes'] : [],
    'formFields' => $input['formFields']
];

$templates[] = $template;
file_put_contents($templateFile, json_encode($templates, JSON_PRETTY_PRINT), LOCK_EX);
echo json_encode(['success' => true, 'template' => $template]);
