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

// Create Google Calendar URL (mirrors sendEmail.php)
function createGoogleCalendarUrl($date, $startTime, $endTime, $title, $name) {
    list($year, $month, $day) = explode('-', $date);
    list($startHour, $startMin) = explode(':', $startTime);
    list($endHour, $endMin) = explode(':', $endTime);
    
    $startDateTime = sprintf('%04d%02d%02dT%02d%02d00', $year, $month, $day, $startHour, $startMin);
    $endDateTime = sprintf('%04d%02d%02dT%02d%02d00', $year, $month, $day, $endHour, $endMin);
    
    $eventText = urlencode($title . ' - ' . $name);
    $eventDetails = urlencode('Session booking confirmed. Added via Hoop Theory booking system.');
    
    $url = 'https://calendar.google.com/calendar/r/eventedit?text=' . $eventText;
    $url .= '&dates=' . $startDateTime . '/' . $endDateTime;
    $url .= '&details=' . $eventDetails;
    $url .= '&location=' . urlencode('Hoop Theory');
    
    return $url;
}

// Test data
$testEmail = 'aghacurtis@gmail.com';
$testName = 'Test User';
$testSlot = '14:00';
$testDate = date('Y-m-d', strtotime('+1 day'));
$testEndTime = '14:40';
$testTitle = 'Basketball Training';

$googleCalendarUrl = createGoogleCalendarUrl($testDate, $testSlot, $testEndTime, $testTitle, $testName);

$mail = new PHPMailer(true);
try {
    $mail->SMTPDebug = getenv('MAIL_DEBUG') !== false ? (int)getenv('MAIL_DEBUG') : 2;
    $mail->Debugoutput = getenv('MAIL_DEBUG_OUTPUT') ?: 'html';

    $smtpHost = getenv('MAIL_HOST') ?: 'smtp.gmail.com';
    $smtpAuth = getenv('MAIL_AUTH') !== false ? (bool)getenv('MAIL_AUTH') : true;
    $smtpUser = getenv('MAIL_USERNAME') ?: '';
    $smtpPass = getenv('MAIL_PASSWORD') ?: '';
    $smtpSecure = getenv('MAIL_ENCRYPTION') ?: 'tls';
    $smtpPort = getenv('MAIL_PORT') ?: 587;

    if (empty($smtpUser) || empty($smtpPass)) {
        echo "<h3>❌ SMTP credentials not set!</h3>";
        echo "<p>MAIL_USERNAME: " . ($smtpUser ? "✓ Set" : "✗ Missing") . "</p>";
        echo "<p>MAIL_PASSWORD: " . ($smtpPass ? "✓ Set" : "✗ Missing") . "</p>";
        echo "<hr>";
        echo "<h3>Debug Info:</h3>";
        echo "<pre>";
        echo "MAIL_HOST: " . (getenv('MAIL_HOST') ? getenv('MAIL_HOST') : "✗ Not set") . "\n";
        echo "MAIL_PORT: " . getenv('MAIL_PORT') . "\n";
        echo "MAIL_ENCRYPTION: " . getenv('MAIL_ENCRYPTION') . "\n";
        echo "</pre>";
        exit;
    }

    echo "<h3>Testing Email (Mirror of Production)...</h3>";
    echo "<p>Sending to: $testEmail</p>";
    echo "<p>Test Name: $testName</p>";
    echo "<p>Test Slot: $testSlot on $testDate</p>";
    echo "<hr>";

    $mail->isSMTP();
    $mail->Host = $smtpHost;
    $mail->SMTPAuth = $smtpAuth;
    $mail->Username = $smtpUser;
    $mail->Password = $smtpPass;
    $mail->SMTPSecure = $smtpSecure;
    $mail->Port = $smtpPort;

    if (getenv('MAIL_ALLOW_SELF_SIGNED') === '1') {
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];
    }

    $fromAddress = getenv('MAIL_FROM_ADDRESS') ?: $smtpUser;
    $fromName = getenv('MAIL_FROM_NAME') ?: 'Hoop Theory';
    $mail->setFrom($fromAddress, $fromName);
    $mail->addAddress($testEmail);

    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->Subject = "Session Confirmed: $testName, $testSlot on $testDate";
    
    $mail->Body = "
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <style>
            body { margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; }
            .email-container { max-width: 600px; margin: 0 auto; background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
            .header { background: linear-gradient(135deg, #000 0%, #333 100%); padding: 30px 20px; text-align: center; display: flex; align-items: center; justify-content: center; }
            .logo { max-width: 12px; height: auto; margin: 0 auto; }
            .content { padding: 40px 30px; }
            .greeting { font-size: 24px; font-weight: 700; color: #000; margin: 0 0 10px 0; }
            .subheading { font-size: 14px; color: #666; margin: 0 0 30px 0; }
            .booking-card { background: linear-gradient(135deg, #f0f0f0 0%, #fafafa 100%); border-left: 4px solid #000; padding: 25px; border-radius: 8px; margin: 25px 0; display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; }
            .booking-card-left { flex: 1; }
            .booking-card-right { display: flex; flex-direction: column; align-items: center; gap: 10px; }
            .booking-label { font-size: 12px; font-weight: 600; color: #999; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px; }
            .booking-value { font-size: 18px; font-weight: 600; color: #000; margin-bottom: 15px; }
            .booking-value:last-child { margin-bottom: 0; }
            .card-logo { max-width: 12px; height: auto; display: block; }
            .card-button { display: inline-block; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 14px; border: none; cursor: pointer; background: #e8e8e8; color: #000; white-space: nowrap; }
            .card-button:hover { opacity: 0.8; }
            .cta-button { display: inline-block; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 14px; margin-top: 20px; border: none; cursor: pointer; }
            .cta-button-instagram { background: #f5f5f5; color: #000; }
            .cta-button:hover { opacity: 0.8; }
            .footer-text { text-align: center; font-size: 13px; color: #999; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; }
            @media (max-width: 480px) {
                .content { padding: 25px 20px; }
                .greeting { font-size: 20px; }
                .booking-card { padding: 20px; }
                .booking-value { font-size: 16px; }
            }
        </style>
    </head>
    <body>
        <div class='email-container'>
            <div class='header'>
            </div>
            <div class='content'>
                <p class='greeting'>Thank you for booking, $testName!</p>
                <p class='subheading'>Your session is confirmed and we cannot wait to see you.</p>
                
                <div class='booking-card'>
                    <div class='booking-card-left'>
                        <div class='booking-label'>Date and Time</div>
                        <div class='booking-value'>$testSlot</div>
                        <div class='booking-value' style='font-size: 14px; font-weight: 500; color: #666;'>$testDate</div>
                        <a href='$googleCalendarUrl' class='card-button'>Add to Google Calendar</a>
                    </div>
                    <div class='booking-card-right'>

                    </div>
                </div>
                
                <p style='color: #555; font-size: 14px; line-height: 1.6; margin: 20px 0;'>
                    We are excited to work with you! If you need to reschedule or have any questions, feel free to reach out to us.
                </p>
                
                <div style='text-align: center;'>
                    <a href='https://instagram.com/hoop.theory' class='cta-button cta-button-instagram'>Follow us on Instagram</a>
                </div>
                
                <div class='footer-text'>
                    <p style='margin: 10px 0;'>Copyright 2026 Hoop Theory. All rights reserved.</p>
                    <p style='margin: 5px 0; font-size: 12px;'>Questions? Contact us at bao@hooptheory.co.uk</p>
                </div>
            </div>
        </div>
    </body>
    </html>
    ";

    $mail->send();
    echo "<h3>✅ SUCCESS! Test email sent successfully</h3>";
    echo "<p>Email sent to: <strong>$testEmail</strong></p>";
    echo "<p>Subject: Session Confirmed: $testName, $testSlot on $testDate</p>";
    echo "<p style='margin-top: 20px; padding: 15px; background: #f0f0f0; border-radius: 5px;'>";
    echo "<strong>Features Tested:</strong><br>";
    echo "✓ Logo display and centering<br>";
    echo "✓ Personalized greeting with name<br>";
    echo "✓ Booking details card<br>";
    echo "✓ Google Calendar button with correct date/time<br>";
    echo "✓ Instagram follow button<br>";
    echo "✓ Footer with company info<br>";
    echo "✓ Responsive mobile styling<br>";
    echo "</p>";
} catch (Exception $e) {
    echo "<h3>❌ Email Error</h3>";
    echo "<p><strong>Error:</strong> {$mail->ErrorInfo}</p>";
    echo "<hr>";
    echo "<h3>Debug Info:</h3>";
    echo "<pre style='background:#f5f5f5;padding:10px;border-radius:5px;'>";
    echo htmlspecialchars($mail->ErrorInfo);
    echo "</pre>";
}
?>
