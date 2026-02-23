<?php
function sendVerificationEmail($email, $name, $token) {
    $subject = "Verify Your Email - CASMS";
    $verificationLink = "http://localhost/carmagement/teamproject/auth/verify-email.php?token=" . $token;
    
    $message = "
    <html>
    <head>
        <title>Email Verification</title>
        <style>
            body { font-family: 'Inter', sans-serif; background: #f5f7fa; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: white; padding: 30px; text-align: center; border-radius: 20px 20px 0 0; }
            .content { background: white; padding: 30px; border-radius: 0 0 20px 20px; }
            .button { display: inline-block; padding: 12px 30px; background: #2563eb; color: white; text-decoration: none; border-radius: 10px; font-weight: 600; margin: 20px 0; }
            .footer { text-align: center; color: #64748b; font-size: 0.8rem; margin-top: 20px; }
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
                <p>Click the button below to verify your email:</p>
                <p style='text-align: center;'>
                    <a href='$verificationLink' class='button'>Verify Email Address</a>
                </p>
                <p>Or copy and paste this link in your browser:</p>
                <p style='word-break: break-all; color: #2563eb;'>$verificationLink</p>
                <p>This link will expire in 24 hours.</p>
                <p>If you didn't create an account, please ignore this email.</p>
            </div>
            <div class='footer'>
                &copy; 2026 CASMS. All rights reserved.
            </div>
        </div>
    </body>
    </html>
    ";
    
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: noreply@casms.com" . "\r\n";
    
    return mail($email, $subject, $message, $headers);
}
?>