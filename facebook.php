<?php
session_start();
require_once "database.php";

// Facebook OAuth Configuration
$appId = 'YOUR_FACEBOOK_APP_ID'; // Replace with your Facebook App ID
$appSecret = 'YOUR_FACEBOOK_APP_SECRET'; // Replace with your Facebook App Secret
$redirectUri = 'http://localhost/casms/auth/facebook-callback.php'; // Update with your domain

// If this is the initial login request, redirect to Facebook
if (!isset($_GET['code'])) {
    $params = [
        'client_id' => $appId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'email,public_profile'
    ];
    
    $authUrl = 'https://www.facebook.com/v18.0/dialog/oauth?' . http_build_query($params);
    header('Location: ' . $authUrl);
    exit();
}
?>