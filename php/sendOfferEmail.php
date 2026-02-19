<?php
// Set timezone to Europe/London to ensure correct timestamps
date_default_timezone_set('Europe/London');
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

function loadEnv($filePath) {
    if (!file_exists($filePath)) return;
    foreach (file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        putenv(trim($k) . '=' . trim($v, " \"'"));
    }
}

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

loadEnv(__DIR__ . '/.env');

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$email = $data['email'] ?? '';
$name = $data['name'] ?? 'Guest';
$date = $data['date'] ?? '';
$time = $data['time'] ?? '';
$title = $data['title'] ?? 'Session';
$sessionKey = $data['sessionKey'] ?? '';
$blockDates = $data['blockDates'] ?? []; // Array of dates for block sessions
$blockId = $data['blockId'] ?? null;

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid email']));
}

if (!$email || !$date || !$time || !$title) {
    http_response_code(400);
    exit(json_encode(['error' => 'Missing required data']));
}

// Check if this is a block session
$isBlockSession = !empty($blockDates) && count($blockDates) > 1;

// Generate unique token
$token = bin2hex(random_bytes(32));

// Store offer in JSON file
$offersFile = __DIR__ . '/../data/offers.json';
$offers = [];
if (file_exists($offersFile)) {
    $offers = json_decode(file_get_contents($offersFile), true) ?? [];
}




// Load expirySeconds from offer config (separate from booking expiry)
$offerExpiryConfigFile = __DIR__ . '/../data/offerExpiryConfig.json';
$expirySeconds = 86400; // default 24h
if (file_exists($offerExpiryConfigFile)) {
    $config = json_decode(file_get_contents($offerExpiryConfigFile), true);
    if (isset($config['expirySeconds']) && is_numeric($config['expirySeconds'])) {
        $expirySeconds = intval($config['expirySeconds']);
    }
}

// Set $now as close as possible to usage
$now = time();
// Debug: log the exact PHP time when $now is set
file_put_contents(__DIR__ . '/../data/debug_time.txt', date('Y-m-d H:i:s', $now) . "\n", FILE_APPEND);

$offers[$token] = [
    'email' => $email,
    'name' => $name,
    'date' => $date,
    'time' => $time,
    'title' => $title,
    'sessionKey' => $sessionKey,
    'blockId' => $blockId,
    'blockDates' => $blockDates,
    'createdAt' => date('Y-m-d H:i:s', $now),
    'expiresAt' => date('Y-m-d H:i:s', $now + $expirySeconds),
    'status' => 'pending'
];

if (!file_put_contents($offersFile, json_encode($offers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
    http_response_code(500);
    exit(json_encode(['error' => 'Failed to save offer']));
}

// Add user to tracking
addUserToTracking($name, $email);

// Send email
$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host = getenv('MAIL_HOST');
    $mail->SMTPAuth = true;
    $mail->Username = getenv('MAIL_USERNAME');
    $mail->Password = getenv('MAIL_PASSWORD');
    $mail->SMTPSecure = 'tls';
    $mail->Port = 587;

    $mail->setFrom(getenv('MAIL_FROM_ADDRESS'), 'Hoop Theory');
    $mail->addAddress($email);

    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->Subject = "Space Available: $title on $date";

    // Get base URL for the links
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $baseUrl = $protocol . '://' . $host;
    $phpPath = dirname($_SERVER['SCRIPT_NAME']);
    
    $confirmUrl = $baseUrl . $phpPath . '/reserveOffer.php?token=' . urlencode($token);
    $declineUrl = $baseUrl . $phpPath . '/declineOffer.php?token=' . urlencode($token);

    // Build session details HTML
    $sessionDetailsHtml = '';
    if ($isBlockSession) {
        $sessionDetailsHtml = '
        <div style="background: #fff8f0; border-left: 6px solid #ff9800; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <p style="margin: 0 0 15px 0; font-weight: 600; color: #e65100; text-transform: uppercase; font-size: 12px;">4-Week Block Session</p>
            <p style="margin: 0 0 15px 0; font-size: 16px; font-weight: 600; color: #333;"><i style="color: #ff9800;">📅</i> ' . htmlspecialchars($title) . '</p>
            <p style="margin: 0 0 15px 0; font-size: 14px; color: #666;"><i style="color: #ff9800;">🕐</i> ' . htmlspecialchars($time) . '</p>
            <p style="margin: 0 0 15px 0; font-weight: 600; color: #333; font-size: 13px;">All Session Dates:</p>
            <div style="background: white; border-radius: 6px; overflow: hidden;">';
        
        foreach ($blockDates as $blockDate) {
            $sessionDetailsHtml .= '
                <div style="padding: 10px 15px; border-bottom: 1px solid #ffe0b2; display: flex; align-items: center; gap: 10px;">
                    <span style="color: #ff9800; font-weight: 600;">✓</span>
                    <span style="color: #333;">' . htmlspecialchars($blockDate) . '</span>
                </div>';
        }
        
        $sessionDetailsHtml .= '
            </div>
        </div>';
    } else {
        $sessionDetailsHtml = '
        <div style="background: #f0fdf4; border-left: 4px solid #10b981; padding: 15px; margin: 20px 0; border-radius: 6px;">
            <div style="margin: 0;">
                <p style="margin: 0 0 8px 0; font-weight: 600; color: #065f46;"><i style="color: #10b981;">📅</i> ' . htmlspecialchars($title) . '</p>
                <p style="margin: 0 0 8px 0; font-size: 14px; color: #666;"><i style="color: #10b981;">🕐</i> ' . htmlspecialchars($time) . '</p>
                <p style="margin: 0; font-size: 14px; color: #666;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle; margin-right:6px;"><path d="M12 21s7-4.5 7-10a7 7 0 1 0-14 0c0 5.5 7 10 7 10z" stroke="#10b981" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg> ' . htmlspecialchars($date) . '</p>
            </div>
        </div>';
    }

    $mail->Body = "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <style>
        body { font-family: Arial, sans-serif; color: #333; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 0 auto; background: #f9f9f9; }
        .header { background: #000; color: white; padding: 30px 20px; text-align: center; }
        .header h2 { margin: 0; font-size: 28px; }
        .content { background: white; padding: 30px 20px; }
        .footer { background: #f0f0f0; padding: 20px; text-align: center; font-size: 12px; color: #666; }
        .session-details { margin: 20px 0; }
        .session-details p { margin: 8px 0; }
        .session-details strong { color: #000; }
        @media only screen and (max-width: 600px) {
            .container { width: 100%; }
            .content { padding: 20px 15px; }
            .header { padding: 20px 15px; }
            .button-table { width: 100% !important; }
            .button-cell { width: 50% !important; padding: 8px 5px !important; }
        }
    </style>
</head>
<body>
<div class='container'>
    <div class='header'>
        <h2 style='margin: 0; font-size: 28px;'>Space Available!</h2>
    </div>
    <div class='content'>
        <p>Hi <strong>$name</strong>,</p>
        
        <p>Great news! A spot has opened up for the session you were waitlisted for.</p>
        
        $sessionDetailsHtml
        
        <p>Would you like to confirm your spot or decline the offer?</p>
        
        <table class='button-table' width='100%' cellpadding='0' cellspacing='0' style='margin: 30px 0;'>
            <tr>
                <td class='button-cell' width='50%' style='padding: 10px 8px; text-align: center;'>
                    <a href='$confirmUrl' style='display: inline-block; padding: 14px 24px; background: #10b981; color: white; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 15px; min-width: 140px; box-sizing: border-box;'>Reserve Spot</a>
                </td>
                <td class='button-cell' width='50%' style='padding: 10px 8px; text-align: center;'>
                    <a href='$declineUrl' style='display: inline-block; padding: 14px 24px; background: #f44336; color: white; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 15px; min-width: 140px; box-sizing: border-box;'>Decline Spot</a>
                </td>
            </tr>
        </table>
        
        <p style='font-size: 12px; color: #666;'>
            This offer expires in 24 hours. If you don't respond by then, the spot will be offered to the next person on the waitlist.
        </p>
    </div>
    <div class='footer'>
        <p style='margin: 0;'>Hoop Theory Booking System</p>
    </div>
</div>
</body>
</html>
    ";
    // Attach branded header image
    $mail->addEmbeddedImage(__DIR__ . '/../EMAILHEADER.png', 'EMAILHEADER.png');

    $mail->send();
    
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Offer email sent']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Email failed: ' . $mail->ErrorInfo]);
}
?>
