<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once "database.php";

$database = new Database();
$db = $database->connect();

// Google OAuth Configuration
$clientID = '411524485490-1ogt6ddng6qsjsii64c5qeaj1vi7r700.apps.googleusercontent.com';
$clientSecret = 'GOCSPX-gbh2dFaRZVlKML9Rkz1zR8cJ_tz';
$redirectUri = 'http://localhost/carmanegement/google-callback.php';
$baseUrl = 'http://localhost/carmanegement/';

// Check for error from Google
if (isset($_GET['error'])) {
    $_SESSION['error'] = "Google authentication failed: " . $_GET['error'];
    header("Location: " . $baseUrl . "login.php");
    exit();
}

// Verify state to prevent CSRF attacks
if (!isset($_GET['state']) || !isset($_SESSION['oauth_state']) || $_GET['state'] !== $_SESSION['oauth_state']) {
    $_SESSION['error'] = "Invalid state parameter. Please try again.";
    header("Location: " . $baseUrl . "login.php");
    exit();
}

// Clear the state from session
unset($_SESSION['oauth_state']);

if (isset($_GET['code'])) {
    $code = $_GET['code'];
    
    // Exchange code for token
    $tokenUrl = 'https://oauth2.googleapis.com/token';
    $postData = [
        'code' => $code,
        'client_id' => $clientID,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code'
    ];
    
    // Initialize cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $tokenUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    
    if (curl_error($ch)) {
        $_SESSION['error'] = "Connection error: " . curl_error($ch);
        header("Location: " . $baseUrl . "login.php");
        exit();
    }
    
    $tokenData = json_decode($response, true);
    
    if (isset($tokenData['access_token'])) {
        // Get user info from Google
        $userInfoUrl = 'https://www.googleapis.com/oauth2/v2/userinfo';
        curl_setopt($ch, CURLOPT_URL, $userInfoUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $tokenData['access_token']
        ]);
        curl_setopt($ch, CURLOPT_POST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $userInfo = curl_exec($ch);
        
        if (curl_error($ch)) {
            $_SESSION['error'] = "Failed to get user information: " . curl_error($ch);
            header("Location: " . $baseUrl . "login.php");
            exit();
        }
        
        curl_close($ch);
        
        $userData = json_decode($userInfo, true);
        
        if (isset($userData['email']) && isset($userData['id'])) {
            // Check if user exists in database
            $query = "SELECT * FROM users WHERE email = :email";
            $stmt = $db->prepare($query);
            $stmt->bindParam(":email", $userData['email']);
            $stmt->execute();
            
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                // User exists - log them in
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['logged_in'] = true;
                $_SESSION['login_type'] = 'google';
                
                // Update last login
                $updateQuery = "UPDATE users SET last_login = NOW() WHERE id = :id";
                $updateStmt = $db->prepare($updateQuery);
                $updateStmt->bindParam(":id", $user['id']);
                $updateStmt->execute();
                
                // Redirect to dashboard
                header("Location: " . $baseUrl . "dashboard.php");
                exit();
            } else {
                // Create new user
                $name = isset($userData['name']) ? $userData['name'] : explode('@', $userData['email'])[0];
                $randomPassword = bin2hex(random_bytes(16));
                $hashedPassword = password_hash($randomPassword, PASSWORD_DEFAULT);
                
                // Insert new user
                $insertQuery = "INSERT INTO users (name, email, password, created_at, last_login) 
                               VALUES (:name, :email, :password, NOW(), NOW())";
                $insertStmt = $db->prepare($insertQuery);
                $insertStmt->bindParam(":name", $name);
                $insertStmt->bindParam(":email", $userData['email']);
                $insertStmt->bindParam(":password", $hashedPassword);
                
                if ($insertStmt->execute()) {
                    $userId = $db->lastInsertId();
                    
                    // Log the user in immediately
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['user_name'] = $name;
                    $_SESSION['user_email'] = $userData['email'];
                    $_SESSION['logged_in'] = true;
                    $_SESSION['login_type'] = 'google';
                    
                    // Redirect to dashboard
                    header("Location: " . $baseUrl . "dashboard.php");
                    exit();
                } else {
                    $_SESSION['error'] = "Failed to create account. Please try again.";
                    header("Location: " . $baseUrl . "login.php");
                    exit();
                }
            }
        } else {
            $_SESSION['error'] = "Failed to get complete user information from Google.";
            header("Location: " . $baseUrl . "login.php");
            exit();
        }
    } else {
        $errorMsg = isset($tokenData['error_description']) ? $tokenData['error_description'] : 'Unknown error';
        $_SESSION['error'] = "Failed to authenticate with Google: " . $errorMsg;
        header("Location: " . $baseUrl . "login.php");
        exit();
    }
} else {
    $_SESSION['error'] = "No authorization code received.";
    header("Location: " . $baseUrl . "login.php");
    exit();
}
?>