<?php
session_start();

// Redirect if already logged in
if(isset($_SESSION['user_id'])){
    header("Location: dashboard.php");
    exit();
}

require_once "database.php";

$database = new Database();
$db = $database->connect();

$error = '';
$email = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if(empty($email) || empty($password)) {
        $error = "Please fill in all fields";
    } else {
        try {
            $query = "SELECT * FROM users WHERE email = :email";
            $stmt = $db->prepare($query);
            $stmt->bindParam(":email", $email);
            $stmt->execute();

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];

                header("Location: dashboard.php");
                exit();
            } else {
                $error = "Invalid email or password";
            }
        } catch (PDOException $e) {
            $error = "Login failed. Please try again.";
            error_log("Login error: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CASMS | Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        /* Main Container */
        .login-container {
            width: 100%;
            max-width: 450px;
            animation: slideUp 0.5s ease;
        }

        /* Login Card */
        .login-card {
            background: white;
            border-radius: 32px;
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }

        /* Header */
        .login-header {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            padding: 2.5rem 2rem;
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .login-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }

        .header-icon {
            width: 70px;
            height: 70px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .header-icon svg {
            width: 35px;
            height: 35px;
            fill: white;
        }

        .login-header h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            position: relative;
        }

        .login-header p {
            font-size: 0.95rem;
            opacity: 0.9;
            position: relative;
        }

        /* Form */
        .login-form {
            padding: 2rem;
        }

        /* Error Alert */
        .error-alert {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem;
            background: #fef2f2;
            border: 1px solid #fee2e2;
            border-radius: 16px;
            color: #b91c1c;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }

        .error-alert::before {
            content: '⚠️';
            font-size: 1.1rem;
        }

        /* Form Groups */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .label-icon {
            font-size: 1.1rem;
        }

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 1.1rem;
        }

        .form-control {
            width: 100%;
            padding: 1rem 1rem 1rem 2.8rem;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            font-family: 'Inter', sans-serif;
            font-size: 1rem;
            transition: all 0.3s;
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
        }

        .form-control::placeholder {
            color: #94a3b8;
            font-size: 0.95rem;
        }

        /* Password Toggle */
        .password-toggle {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #64748b;
            font-size: 1.1rem;
            user-select: none;
        }

        .password-toggle:hover {
            color: #2563eb;
        }

        /* Remember Me & Forgot Password */
        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .remember-me {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }

        .checkbox-custom {
            width: 18px;
            height: 18px;
            border: 2px solid #cbd5e1;
            border-radius: 5px;
            position: relative;
            transition: all 0.2s;
        }

        input[type="checkbox"] {
            display: none;
        }

        input[type="checkbox"]:checked + .checkbox-custom {
            background: #2563eb;
            border-color: #2563eb;
        }

        input[type="checkbox"]:checked + .checkbox-custom::after {
            content: '✓';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: 12px;
        }

        .remember-text {
            font-size: 0.9rem;
            color: #475569;
        }

        .forgot-link {
            font-size: 0.9rem;
            color: #2563eb;
            text-decoration: none;
            font-weight: 500;
        }

        .forgot-link:hover {
            text-decoration: underline;
        }

        /* Login Button */
        .login-btn {
            width: 100%;
            padding: 1rem;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            border: none;
            border-radius: 16px;
            color: white;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            position: relative;
            overflow: hidden;
            transition: all 0.3s;
            margin-bottom: 1.5rem;
            font-family: 'Inter', sans-serif;
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(37, 99, 235, 0.5);
        }

        .login-btn:active {
            transform: translateY(0);
        }

        .login-btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .login-btn:hover::before {
            width: 300px;
            height: 300px;
        }

        .btn-icon {
            transition: transform 0.3s;
        }

        .login-btn:hover .btn-icon {
            transform: translateX(5px);
        }

        .login-btn.loading {
            opacity: 0.7;
            cursor: not-allowed;
        }

        .login-btn.loading .btn-text {
            display: none;
        }

        .login-btn.loading .btn-loader {
            display: block;
        }

        .btn-loader {
            display: none;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        /* Social Login Section */
        .social-section {
            position: relative;
            margin: 1.5rem 0;
        }

        .social-divider {
            text-align: center;
            position: relative;
        }

        .social-divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: #e2e8f0;
            z-index: 1;
        }

        .social-divider span {
            background: white;
            padding: 0 1rem;
            color: #64748b;
            font-size: 0.85rem;
            position: relative;
            z-index: 2;
        }

        .social-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .social-btn {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.875rem;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            background: white;
            color: #1e293b;
            font-weight: 500;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }

        .social-btn:hover {
            transform: translateY(-2px);
            border-color: #2563eb;
        }

        .social-btn.google:hover {
            border-color: #DB4437;
        }

        .social-btn.facebook:hover {
            border-color: #4267B2;
        }

        .social-icon {
            font-size: 1.2rem;
        }

        /* Register Link */
        .register-section {
            text-align: center;
            border-top: 1px solid #e2e8f0;
            padding-top: 1.5rem;
            margin-top: 1rem;
        }

        .register-text {
            color: #64748b;
            font-size: 0.95rem;
        }

        .register-link {
            color: #2563eb;
            text-decoration: none;
            font-weight: 600;
            margin-left: 0.25rem;
        }

        .register-link:hover {
            text-decoration: underline;
        }

        /* Animations */
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Dark mode support */
        @media (prefers-color-scheme: dark) {
            body {
                background: linear-gradient(135deg, #1e1b4b 0%, #2e1065 100%);
            }

            .login-card {
                background: #1e293b;
            }

            .form-label {
                color: #e2e8f0;
            }

            .form-control {
                background: #0f172a;
                border-color: #334155;
                color: #f1f5f9;
            }

            .form-control::placeholder {
                color: #64748b;
            }

            .remember-text {
                color: #94a3b8;
            }

            .social-divider::before {
                background: #334155;
            }

            .social-divider span {
                background: #1e293b;
                color: #94a3b8;
            }

            .social-btn {
                background: #0f172a;
                border-color: #334155;
                color: #e2e8f0;
            }

            .social-btn:hover {
                background: #1e293b;
            }

            .register-text {
                color: #94a3b8;
            }

            .register-section {
                border-top-color: #334155;
            }
        }

        /* Responsive */
        @media (max-width: 480px) {
            .login-header {
                padding: 2rem 1.5rem;
            }

            .login-header h1 {
                font-size: 1.75rem;
            }

            .login-form {
                padding: 1.5rem;
            }

            .form-options {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }

            .social-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <!-- Header -->
            <div class="login-header">
                <div class="header-icon">
                    <svg viewBox="0 0 24 24">
                        <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                    </svg>
                </div>
                <h1>Welcome Back</h1>
                <p>Sign in to your CASMS account</p>
            </div>

            <!-- Form -->
            <div class="login-form">
                <?php if ($error): ?>
                    <div class="error-alert">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" id="loginForm">
                    <!-- Email Field -->
                    <div class="form-group">
                        <label class="form-label">
                            <span class="label-icon">📧</span>
                            Email Address
                        </label>
                        <div class="input-wrapper">
                            <span class="input-icon">✉️</span>
                            <input type="email" 
                                   name="email" 
                                   class="form-control" 
                                   placeholder="Enter your email"
                                   value="<?php echo htmlspecialchars($email); ?>"
                                   required>
                        </div>
                    </div>

                    <!-- Password Field -->
                    <div class="form-group">
                        <label class="form-label">
                            <span class="label-icon">🔒</span>
                            Password
                        </label>
                        <div class="input-wrapper">
                            <span class="input-icon">🔑</span>
                            <input type="password" 
                                   name="password" 
                                   class="form-control" 
                                   placeholder="Enter your password"
                                   id="password"
                                   required>
                            <span class="password-toggle" onclick="togglePassword()" id="toggleIcon">👁️</span>
                        </div>
                    </div>

                    <!-- Options -->
                    <div class="form-options">
                        <label class="remember-me">
                            <input type="checkbox" name="remember" hidden>
                            <span class="checkbox-custom"></span>
                            <span class="remember-text">Remember me</span>
                        </label>
                        <a href="forgot-password.php" class="forgot-link">Forgot password?</a>
                    </div>

                    <!-- Login Button -->
                    <button type="submit" name="login" class="login-btn" id="loginBtn">
                        <span class="btn-text">Sign In</span>
                        <span class="btn-icon">→</span>
                        <div class="btn-loader"></div>
                    </button>
                </form>

                <!-- Social Login Section -->
                <div class="social-section">
                    <div class="social-divider">
                        <span>Or continue with</span>
                    </div>
                    
                    <div class="social-buttons">
                        <!-- Google Sign In -->
                        <a href="google.php" class="social-btn google">
                            <span class="social-icon">G</span>
                            <span>Google</span>
                        </a>
                        
                        <!-- Facebook Sign In -->
                        <a href="facebook.php" class="social-btn facebook">
                            <span class="social-icon">f</span>
                            <span>Facebook</span>
                        </a>
                    </div>
                </div>

                <!-- Register Link -->
                <div class="register-section">
                    <p class="register-text">
                        Don't have an account?
                        <a href="register.php" class="register-link">Create Account</a>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Password visibility toggle
        function togglePassword() {
            const password = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (password.type === 'password') {
                password.type = 'text';
                toggleIcon.textContent = '🔓';
            } else {
                password.type = 'password';
                toggleIcon.textContent = '👁️';
            }
        }

        // Form submission with loading state
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('loginBtn');
            btn.classList.add('loading');
            // Form will submit normally
        });

        // Add smooth transitions
        document.querySelectorAll('.form-control').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.querySelector('.input-icon').style.color = '#2563eb';
            });
            input.addEventListener('blur', function() {
                this.parentElement.querySelector('.input-icon').style.color = '#64748b';
            });
        });
    </script>
</body>
</html>