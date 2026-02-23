<?php
session_start();
require_once " database.php";

$database = new Database();
$db = $database->connect();

// Facebook OAuth Configuration
$appId = 'YOUR_FACEBOOK_APP_ID';
$appSecret = 'YOUR_FACEBOOK_APP_SECRET';
$redirectUri = 'http://localhost/casms/auth/facebook-callback.php';

if (isset($_GET['code'])) {
    $code = $_GET['code'];
    
    // Exchange authorization code for access token
    $tokenUrl = 'https://graph.facebook.com/v18.0/oauth/access_token';
    $params = [
        'client_id' => $appId,
        'client_secret' => $appSecret,
        'redirect_uri' => $redirectUri,
        'code' => $code
    ];
    
    $tokenUrl .= '?' . http_build_query($params);
    
    // Initialize cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $tokenUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $tokenData = json_decode($response, true);
    curl_close($ch);
    
    if (isset($tokenData['access_token'])) {
        // Get user info from Facebook
        $userInfoUrl = 'https://graph.facebook.com/me?fields=id,name,email&access_token=' . $tokenData['access_token'];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $userInfoUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $userInfo = curl_exec($ch);
        $userData = json_decode($userInfo, true);
        curl_close($ch);
        
        if (isset($userData['email'])) {
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
                $_SESSION['login_type'] = 'facebook';
                
                header("Location: dashboard.php");
                exit();
            } else {
                // Create new user
                $name = $userData['name'] ?? $userData['email'];
                $password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT); // Random password
                
                $insertQuery = "INSERT INTO users (name, email, password, created_at) VALUES (:name, :email, :password, NOW())";
                $insertStmt = $db->prepare($insertQuery);
                $insertStmt->bindParam(":name", $name);
                $insertStmt->bindParam(":email", $userData['email']);
                $insertStmt->bindParam(":password", $password);
                
                if ($insertStmt->execute()) {
                    $userId = $db->lastInsertId();
                    
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['user_name'] = $name;
                    $_SESSION['user_email'] = $userData['email'];
                    $_SESSION['login_type'] = 'facebook';
                    
                    header("Location: dashboard.php");
                    exit();
                } else {
                    $_SESSION['error'] = "Failed to create account. Please try again.";
                    header("Location: login.php");
                    exit();
                }
            }
        } else {
            // If email not available, try to get name only
            $userInfoUrl = 'https://graph.facebook.com/me?fields=id,name&access_token=' . $tokenData['access_token'];
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $userInfoUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $userInfo = curl_exec($ch);
            $userData = json_decode($userInfo, true);
            curl_close($ch);
            
            if (isset($userData['id'])) {
                // Create a placeholder email
                $email = $userData['id'] . '@facebook.com';
                $name = $userData['name'] ?? 'Facebook User';
                
                // Check if user exists
                $query = "SELECT * FROM users WHERE email = :email";
                $stmt = $db->prepare($query);
                $stmt->bindParam(":email", $email);
                $stmt->execute();
                
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['login_type'] = 'facebook';
                    
                    header("Location: dashboard.php");
                    exit();
                } else {
                    $password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                    
                    $insertQuery = "INSERT INTO users (name, email, password, created_at) VALUES (:name, :email, :password, NOW())";
                    $insertStmt = $db->prepare($insertQuery);
                    $insertStmt->bindParam(":name", $name);
                    $insertStmt->bindParam(":email", $email);
                    $insertStmt->bindParam(":password", $password);
                    
                    if ($insertStmt->execute()) {
                        $userId = $db->lastInsertId();
                        
                        $_SESSION['user_id'] = $userId;
                        $_SESSION['user_name'] = $name;
                        $_SESSION['user_email'] = $email;
                        $_SESSION['login_type'] = 'facebook';
                        
                        header("Location: dashboard.php");
                        exit();
                    }
                }
            }
            
            $_SESSION['error'] = "Failed to get user information from Facebook.";
            header("Location: login.php");
            exit();
        }
    } else {
        $_SESSION['error'] = "Failed to authenticate with Facebook.";
        header("Location: login.php");
        exit();
    }
} else {
    $_SESSION['error'] = "No authorization code received.";
    header("Location: login.php");
    exit();
}
?>