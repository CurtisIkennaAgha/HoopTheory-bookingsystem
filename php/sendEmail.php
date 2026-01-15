<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

// Load environment from .env file (native PHP, no Composer required)
function loadEnv($filePath) {
    if (!file_exists($filePath)) return;
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') === false || $line[0] === '#') continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, ' "\'');
        if (!getenv($key)) putenv("$key=$value");
    }
}
loadEnv(__DIR__ . '/.env');

// Get data from AJAX JSON
$data = json_decode(file_get_contents('php://input'), true);
$email = $data['email'] ?? '';
$slot = $data['slot'] ?? '';
$date = $data['date'] ?? '';

// Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo "Invalid email address";
    exit;
}

// Sanitize other inputs
$slot = htmlspecialchars($slot);
$date = htmlspecialchars($date);

$mail = new PHPMailer(true);
try {
    // Enable SMTP debug output
    $mail->SMTPDebug = getenv('MAIL_DEBUG') !== false ? (int)getenv('MAIL_DEBUG') : 0;
    $mail->Debugoutput = getenv('MAIL_DEBUG_OUTPUT') ?: 'html';

    // Load SMTP config from environment (safer than hardcoding credentials)
    $smtpHost = getenv('MAIL_HOST') ?: 'smtp.gmail.com';
    $smtpAuth = getenv('MAIL_AUTH') !== false ? (bool)getenv('MAIL_AUTH') : true;
    $smtpUser = getenv('MAIL_USERNAME') ?: '';
    $smtpPass = getenv('MAIL_PASSWORD') ?: '';
    $smtpSecure = getenv('MAIL_ENCRYPTION') ?: 'tls';
    $smtpPort = getenv('MAIL_PORT') ?: 587;

    if (empty($smtpUser) || empty($smtpPass)) {
        echo "SMTP credentials not set. Please set MAIL_USERNAME and MAIL_PASSWORD environment variables.";
        exit;
    }

    //Server settings
    $mail->isSMTP();
    $mail->Host = $smtpHost;
    $mail->SMTPAuth = $smtpAuth;
    $mail->Username = $smtpUser;
    $mail->Password = $smtpPass;
    $mail->SMTPSecure = $smtpSecure;
    $mail->Port = $smtpPort;

    // Optional: allow self-signed certs for testing behind some firewalls
    if (getenv('MAIL_ALLOW_SELF_SIGNED') === '1') {
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];
    }

    //Recipients
    $fromAddress = getenv('MAIL_FROM_ADDRESS') ?: $smtpUser;
    $fromName = getenv('MAIL_FROM_NAME') ?: 'Hoop Theory';
    $mail->setFrom($fromAddress, $fromName);
    $mail->addAddress($email);

    //Content
    $mail->isHTML(true);
    $mail->Subject = "Session Confirmed: $slot on $date";
    $mail->Body = "
    <html>
    <body style='font-family:Arial,sans-serif;'>
        <div style='max-width:600px;margin:0 auto;padding:20px;border:1px solid #ddd;border-radius:10px;text-align:center;'>
            <h2 style='color:#000;'>Thank You for Booking!</h2>
            <p>Your session is <strong>confirmed</strong>.</p>
            <p><strong>Date & Time:</strong> $slot on $date</p>
            <p>We are excited to see you!</p>
            <p style='margin-top:30px;color:#888;font-size:0.85rem;'>Your Company Name</p>
        </div>
    </body>
    </html>
    ";

    $mail->send();
    echo "Email sent to $email";
} catch (Exception $e) {
    echo "Email could not be sent. Mailer Error: {$mail->ErrorInfo}";
}
?>
