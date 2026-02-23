<?php
session_start();
require_once "../config/database.php";
require_once "verification.php";

$database = new Database();
$db = $database->connect();

if (isset($_SESSION['verification_email'])) {
    $email = $_SESSION['verification_email'];
    
    // Get user
    $query = "SELECT * FROM users WHERE email = :email AND email_verified = 0";
    $stmt = $db->prepare($query);
    $stmt->bindParam(":email", $email);
    $stmt->execute();
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        // Generate new token
        $token = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        // Update token
        $update = "UPDATE users SET verification_token = :token, token_expiry = :expiry WHERE id = :id";
        $updateStmt = $db->prepare($update);
        $updateStmt->bindParam(":token", $token);
        $updateStmt->bindParam(":expiry", $expiry);
        $updateStmt->bindParam(":id", $user['id']);
        $updateStmt->execute();
        
        // Send email
        sendVerificationEmail($email, $user['name'], $token);
        
        $_SESSION['message'] = "Verification email resent successfully!";
    }
}

header("Location: verify-notice.php");
exit();
?>