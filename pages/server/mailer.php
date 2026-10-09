<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once __DIR__ . '/vendor/autoload.php';
function sendMail($email, $subject, $html)
{
    $mail = new PHPMailer(true);

    try {
        // SMTP configuration
        $mail->isSMTP();
        $mail->Host       = 'mail.oracleofchrist.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'your-email@oracleofchrist.com';
        $mail->Password   = 'YOUR_EMAIL_PASSWORD';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;

        // Sender and recipient
        $mail->setFrom(
            'your-email@oracleofchrist.com',
            'Oracle of Christ'
        );

        $mail->addAddress($email);

        // Email content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = strip_tags($html);

        $mail->send();

        return true;

    } catch (Exception $e) {
        error_log('Email sending failed: ' . $mail->ErrorInfo);
        return false;
    }
}
