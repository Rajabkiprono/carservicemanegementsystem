<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Google OAuth Configuration
$clientID = '411524485490-1ogt6ddng6qsjsii64c5qeaj1vi7r700.apps.googleusercontent.com';
$redirectUri = 'http://localhost/carmanegement/google-callback.php';

// Generate a random state for security
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;

// Google OAuth URL
$authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id' => $clientID,
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'scope' => 'email profile',
    'state' => $state,
    'access_type' => 'online',
    'prompt' => 'select_account'
]);

// Redirect to Google
header("Location: $authUrl");
exit();
?>