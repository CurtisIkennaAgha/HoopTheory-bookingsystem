<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

// Optional: load environment from php/.env using vlucas/phpdotenv if available
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
    if (class_exists('Dotenv\\Dotenv')) {
        $dotenv = Dotenv\\Dotenv::createImmutable(__DIR__);
        $dotenv->safeLoad();
    }
}

$mail = new PHPMailer(true);
try {
    $mail->SMTPDebug = getenv('MAIL_DEBUG') !== false ? (int)getenv('MAIL_DEBUG') : 0;
    $mail->Debugoutput = getenv('MAIL_DEBUG_OUTPUT') ?: 'html';

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

    $mail->isSMTP();
    $mail->Host = $smtpHost;
    $mail->SMTPAuth = $smtpAuth;
    $mail->Username = $smtpUser;
    $mail->Password = $smtpPass;
    $mail->SMTPSecure = $smtpSecure;
    $mail->Port = $smtpPort;

    $fromAddress = getenv('MAIL_FROM_ADDRESS') ?: $smtpUser;
    $fromName = getenv('MAIL_FROM_NAME') ?: 'Test';
    $mail->setFrom($fromAddress, $fromName);
    $mail->addAddress(getenv('MAIL_TEST_TO') ?: 'youremail@gmail.com');
    $mail->isHTML(true);
    $mail->Subject = 'Test';
    $mail->Body = 'Hello, this is a test';
    $mail->send();
    echo "Mail sent";
} catch (Exception $e) {
    echo "Mailer Error: {$mail->ErrorInfo}";
}
