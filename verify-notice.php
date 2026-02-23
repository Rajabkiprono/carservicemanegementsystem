<?php
session_start();
if(!isset($_SESSION['verification_email'])) {
    header("Location: login.php");
    exit();
}
$email = $_SESSION['verification_email'];
?>

<!DOCTYPE html>
<html>
<head>
    <title>Verify Your Email - CASMS</title>
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
        .email {
            background: #f1f5f9;
            padding: 1rem;
            border-radius: 12px;
            font-weight: 600;
            color: #2563eb;
            margin: 1.5rem 0;
            word-break: break-all;
        }
        .text {
            color: #64748b;
            line-height: 1.6;
            margin-bottom: 2rem;
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
            margin: 0.5rem;
        }
        .btn-outline {
            background: transparent;
            border: 2px solid #2563eb;
            color: #2563eb;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(37, 99, 235, 0.5);
        }
        .resend {
            margin-top: 2rem;
            font-size: 0.9rem;
        }
        .resend a {
            color: #2563eb;
            text-decoration: none;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="icon">📧</div>
            <h1 class="title">Verify Your Email</h1>
            <div class="text">
                We've sent a verification link to:
            </div>
            <div class="email">
                <?php echo htmlspecialchars($email); ?>
            </div>
            <div class="text">
                Please check your email and click the verification link to activate your account. The link will expire in 24 hours.
            </div>
            <a href="login.php" class="btn">Go to Login</a>
            <a href="resend-verification.php" class="btn btn-outline">Resend Email</a>
            <div class="resend">
                Didn't receive the email? <a href="resend-verification.php">Click to resend</a>
            </div>
        </div>
    </div>
</body>
</html>