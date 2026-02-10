<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Get admin credentials from environment variables
$username = getenv('ADMIN_USERNAME') ?: 'bao@hooptheory.co.uk';
$password = getenv('ADMIN_PASSWORD') ?: 'Dangbongro.72';

echo json_encode([
    'username' => $username,
    'password' => $password
]);
