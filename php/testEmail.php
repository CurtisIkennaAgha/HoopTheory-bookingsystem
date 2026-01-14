<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

$mail = new PHPMailer(true);
try {
    $mail->SMTPDebug = 2;
    $mail->Debugoutput = 'html';
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'curtisikennaagha@gmail.com';
    $mail->Password = 'Topsy999';
    $mail->SMTPSecure = 'tls';
    $mail->Port = 587;

    $mail->setFrom('curtisikennaagha@gmail.com','Test');
    $mail->addAddress('youremail@gmail.com');
    $mail->isHTML(true);
    $mail->Subject = 'Test';
    $mail->Body = 'Hello, this is a test';
    $mail->send();
    echo "Mail sent";
} catch (Exception $e) {
    echo "Mailer Error: {$mail->ErrorInfo}";
}
