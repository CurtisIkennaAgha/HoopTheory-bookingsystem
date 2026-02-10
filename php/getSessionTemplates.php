<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$type = isset($_GET['type']) ? $_GET['type'] : null;
$templateFile = __DIR__ . '/../data/sessionTemplates.json';
clearstatcache(true, $templateFile);
$templates = file_exists($templateFile) ? json_decode(file_get_contents($templateFile), true) : [];

if ($type) {
    $templates = array_values(array_filter($templates, function($tpl) use ($type) {
        return isset($tpl['type']) && $tpl['type'] === $type;
    }));
}
echo json_encode($templates);
