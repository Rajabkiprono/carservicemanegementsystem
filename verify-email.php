<?php
session_start();
require_once "../config/database.php";

$database = new Database();
$db = $database->connect();

$message = '';
$messageType = '';

if (isset($_GET['token'])) {
    $token = $_GET['token'];
    
    // Find user with this token
    $query = "SELECT * FROM users WHERE verification_token = :token AND token_expiry > NOW()";
    $stmt = $db->prepare($query);
    $stmt->bindParam(":token", $token);
    $stmt->execute();
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        // Verify email
        $update = "UPDATE users SET email_verified = TRUE, verification_token = NULL, token_expiry = NULL WHERE id = :id";
        $updateStmt = $db->prepare($update);
        $updateStmt->bindParam(":id", $user['id']);
        
        if ($updateStmt->execute()) {
            $message = "Email verified successfully! You can now login.";
            $messageType = "success";
        } else {
            $message = "Verification failed. Please try again.";
            $messageType = "error";
        }
    } else {
        $message = "Invalid or expired verification link.";
        $messageType = "error";
    }
} else {
    header("Location: login.php");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Email Verification - CASMS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            max-width: 500px;
            width: 90%;
        }
        .card {
            background: white;
            border-radius: 24px;
            padding: 2.5rem;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
        }
        .icon {
            font-size: 4rem;
            margin-bottom: 1rem;
        }
        .title {
            font-size: 1.8rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 1rem;
        }
        .message {
            padding: 1rem;
            border-radius: 12px;
            margin: 1.5rem 0;
        }
        .success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fee2e2;
        }
        .btn {
            display: inline-block;
            padding: 0.875rem 2rem;
            background: #2563eb;
            color: white;
            text-decoration: none;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(37, 99, 235, 0.5);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="icon">
                <?php echo $messageType == 'success' ? '✅' : '❌'; ?>
            </div>
            <h1 class="title">Email Verification</h1>
            <div class="message <?php echo $messageType; ?>">
                <?php echo $message; ?>
            </div>
            <?php if($messageType == 'success'): ?>
                <a href="login.php" class="btn">Login to Dashboard</a>
            <?php else: ?>
                <a href="login.php" class="btn">Back to Login</a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>