<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once '../PHPMailer/Exception.php';
require_once '../PHPMailer/PHPMailer.php';
require_once '../PHPMailer/SMTP.php';

function sendVerificationEmail($to, $name, $token) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'rajabkiprono@gmail.com'; // REPLACE WITH YOUR GMAIL
        $mail->Password   = 'gwgz ylly lksx suub'; // YOUR APP PASSWORD
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->Timeout    = 500; // Increase timeout
        
        // Recipients
        $mail->setFrom('rajabkiprono@gmail.com', 'CASMS System');
        $mail->addAddress($to, $name);
        
        // Content
        $verificationLink = "http://localhost/carmagement/teamproject/auth/verify-email.php?token=" . $token;
        
        $mail->isHTML(true);
        $mail->Subject = 'Verify Your Email - Car Auto Service Management System';
        $mail->Body    = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: 'Inter', sans-serif; background: #f5f7fa; margin: 0; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
                .header { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); padding: 30px; text-align: center; color: white; }
                .content { padding: 30px; }
                .button { display: inline-block; padding: 12px 30px; background: #2563eb; color: white; text-decoration: none; border-radius: 10px; font-weight: 600; margin: 20px 0; }
                .footer { text-align: center; padding: 20px; color: #64748b; font-size: 0.8rem; border-top: 1px solid #e2e8f0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>Welcome to CASMS!</h2>
                </div>
                <div class='content'>
                    <h3>Hello $name,</h3>
                    <p>Thank you for registering with CASMS (Car Auto Service Management System). Please verify your email address to access your dashboard.</p>
                    <p style='text-align: center;'>
                        <a href='$verificationLink' class='button'>Verify Email Address</a>
                    </p>
                    <p>Or copy and paste this link in your browser:</p>
                    <p style='word-break: break-all; color: #2563eb;'>$verificationLink</p>
                    <p>This link will expire in 24 hours.</p>
                </div>
                <div class='footer'>
                    &copy; 2026 CASMS. All rights reserved.
                </div>
            </div>
        </body>
        </html>
        ";
        
        $mail->send();
        return ['success' => true, 'message' => 'Email sent'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $mail->ErrorInfo];
    }
}
?>