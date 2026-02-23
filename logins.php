<?php
session_start();

// If already logged in, redirect to finance dashboard
if(isset($_SESSION['user_id']) && isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'finance'])) {
    header("Location: finance.php");
    exit();
}

require_once "databases.php";

$error = '';
$success = '';

// Check for timeout or logout messages
if(isset($_GET['timeout'])) {
    $error = "Your session has expired. Please login again.";
} elseif(isset($_GET['logout'])) {
    $success = "You have been successfully logged out.";
} elseif(isset($_GET['error']) && $_GET['error'] === 'unauthorized') {
    $error = "You are not authorized to access this page.";
} elseif(isset($_GET['error']) && $_GET['error'] === 'session_expired') {
    $error = "Your session has been terminated. Please login again.";
}

// Handle login form submission
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // Validate input
    if(empty($username) || empty($password)) {
        $error = "Please enter both username and password";
    } else {
        try {
            $db = (new Database())->connect();
            
            // Check for too many failed attempts (rate limiting)
            $checkAttempts = $db->prepare("
                SELECT COUNT(*) as attempt_count 
                FROM login_attempts 
                WHERE (username = ? OR username IN (SELECT username FROM users WHERE email = ?))
                AND ip_address = ? 
                AND attempt_time > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                AND success = 0
            ");
            $checkAttempts->execute([$username, $username, $ip_address]);
            $failedAttempts = $checkAttempts->fetch(PDO::FETCH_ASSOC)['attempt_count'];
            
            if($failedAttempts >= 5) {
                $error = "Too many failed attempts. Please try again after 15 minutes.";
            } else {
                // Get user by username or email
                $stmt = $db->prepare("
                    SELECT id, username, email, password, full_name, role, is_active 
                    FROM users 
                    WHERE (username = ? OR email = ?) 
                    AND role IN ('admin', 'finance')
                ");
                $stmt->execute([$username, $username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Record login attempt
                $attemptStmt = $db->prepare("
                    INSERT INTO login_attempts (username, ip_address, user_agent, attempt_time, success) 
                    VALUES (?, ?, ?, NOW(), ?)
                ");
                
                if($user && password_verify($password, $user['password'])) {
                    if($user['is_active'] == 0) {
                        $error = "Your account has been deactivated. Please contact administrator.";
                        $attemptStmt->execute([$username, $ip_address, $user_agent, 0]);
                    } else {
                        // Login successful
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['full_name'] = $user['full_name'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['login_time'] = time();
                        
                        // Generate CSRF token
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        
                        // Update last login
                        $updateStmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                        $updateStmt->execute([$user['id']]);
                        
                        // Record successful login attempt
                        $attemptStmt->execute([$username, $ip_address, $user_agent, 1]);
                        
                        // Create session record
                        $session_token = bin2hex(random_bytes(32));
                        
                        // Deactivate any existing sessions for this user
                        $deactivateStmt = $db->prepare("UPDATE user_sessions SET is_active = 0 WHERE user_id = ?");
                        $deactivateStmt->execute([$user['id']]);
                        
                        // Create new session
                        $sessionStmt = $db->prepare("
                            INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, login_time, last_activity, is_active) 
                            VALUES (?, ?, ?, ?, NOW(), NOW(), 1)
                        ");
                        $sessionStmt->execute([$user['id'], $session_token, $ip_address, $user_agent]);
                        
                        $_SESSION['session_token'] = $session_token;
                        
                        // Set remember me cookie if requested
                        if($remember) {
                            $cookie_token = bin2hex(random_bytes(32));
                            $expiry = time() + (30 * 24 * 60 * 60); // 30 days
                            
                            setcookie('remember_token', $cookie_token, [
                                'expires' => $expiry,
                                'path' => '/',
                                'secure' => false, // Set to true if using HTTPS
                                'httponly' => true,
                                'samesite' => 'Strict'
                            ]);
                            
                            // Store remember token
                            $rememberStmt = $db->prepare("
                                INSERT INTO remember_tokens (user_id, token, expiry, created_at) 
                                VALUES (?, ?, FROM_UNIXTIME(?), NOW())
                            ");
                            $rememberStmt->execute([$user['id'], $cookie_token, $expiry]);
                        }
                        
                        // Redirect to finance dashboard
                        header("Location: finance.php");
                        exit();
                    }
                } else {
                    $error = "Invalid username or password";
                    $attemptStmt->execute([$username, $ip_address, $user_agent, 0]);
                }
            }
        } catch(PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            $error = "An error occurred. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>CASMS Finance | Secure Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
            width: 100%;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            position: relative;
            overflow-x: hidden;
            overflow-y: auto;
        }

        /* Animated background */
        .bg-shapes {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 0;
            pointer-events: none;
        }

        .shape {
            position: absolute;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
            animation: float 20s infinite;
        }

        .shape1 {
            width: min(500px, 80vw);
            height: min(500px, 80vw);
            top: -250px;
            right: -100px;
            animation-delay: 0s;
        }

        .shape2 {
            width: min(400px, 70vw);
            height: min(400px, 70vw);
            bottom: -200px;
            left: -100px;
            animation-delay: 5s;
        }

        .shape3 {
            width: min(300px, 60vw);
            height: min(300px, 60vw);
            bottom: 50px;
            right: 200px;
            animation-delay: 10s;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-30px) rotate(180deg); }
        }

        .login-container {
            max-width: 440px;
            width: 100%;
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-radius: 32px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            overflow: hidden;
            position: relative;
            z-index: 1;
            animation: slideUp 0.6s ease-out;
            border: 1px solid rgba(255, 255, 255, 0.2);
            margin: auto;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-header {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            padding: clamp(30px, 8vw, 40px) clamp(20px, 5vw, 30px);
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .login-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .login-header h1 {
            font-size: clamp(24px, 6vw, 32px);
            font-weight: 700;
            margin-bottom: 10px;
            letter-spacing: -0.5px;
            position: relative;
            word-break: break-word;
        }

        .login-header p {
            font-size: clamp(12px, 3vw, 14px);
            opacity: 0.9;
            position: relative;
        }

        .login-header .icon {
            font-size: clamp(48px, 10vw, 56px);
            margin-bottom: 15px;
            display: block;
            animation: bounce 2s infinite;
            position: relative;
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        .login-form {
            padding: clamp(30px, 6vw, 40px) clamp(20px, 5vw, 30px);
        }

        .form-group {
            margin-bottom: 24px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
            font-size: clamp(12px, 3vw, 13px);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-group label i {
            margin-right: 6px;
            font-size: 14px;
        }

        .input-group {
            position: relative;
        }

        .input-group i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 18px;
            transition: color 0.3s;
            z-index: 1;
        }

        .form-control {
            width: 100%;
            padding: 16px 16px 16px 48px;
            border: 2px solid #e5e7eb;
            border-radius: 16px;
            font-size: clamp(14px, 3.5vw, 15px);
            transition: all 0.3s;
            font-family: 'Inter', sans-serif;
            background: rgba(255, 255, 255, 0.9);
            -webkit-appearance: none;
            appearance: none;
        }

        .form-control:focus {
            outline: none;
            border-color: #10b981;
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1);
            background: white;
        }

        .form-control:focus + i {
            color: #10b981;
        }

        .form-control::placeholder {
            color: #9ca3af;
            font-size: clamp(12px, 3vw, 14px);
        }

        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #9ca3af;
            font-size: 18px;
            transition: color 0.3s;
            z-index: 2;
            background: transparent;
            border: none;
            padding: 4px;
        }

        .password-toggle:hover {
            color: #10b981;
        }

        .password-toggle:active {
            transform: translateY(-50%) scale(0.95);
        }

        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
            padding: 0 4px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .remember-me {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
        }

        .remember-me input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #10b981;
            flex-shrink: 0;
        }

        .remember-me span {
            font-size: clamp(13px, 3vw, 14px);
            color: #4b5563;
            font-weight: 500;
        }

        .btn-login {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border: none;
            border-radius: 16px;
            font-size: clamp(14px, 3.5vw, 16px);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-family: 'Inter', sans-serif;
            position: relative;
            overflow: hidden;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            -webkit-tap-highlight-color: transparent;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 20px 30px -10px rgba(16, 185, 129, 0.5);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .btn-login.loading {
            pointer-events: none;
            opacity: 0.7;
        }

        .btn-login.loading .btn-text {
            visibility: hidden;
        }

        .btn-login.loading::after {
            content: '';
            position: absolute;
            width: 24px;
            height: 24px;
            top: 50%;
            left: 50%;
            margin-left: -12px;
            margin-top: -12px;
            border: 3px solid white;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .alert {
            padding: 16px 20px;
            border-radius: 16px;
            margin-bottom: 24px;
            font-size: clamp(13px, 3vw, 14px);
            display: flex;
            align-items: center;
            gap: 12px;
            animation: shake 0.5s ease-in-out;
            border-left: 4px solid transparent;
            word-break: break-word;
        }

        .alert i {
            flex-shrink: 0;
            font-size: 18px;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }

        .alert-error {
            background: #fef2f2;
            border-left-color: #ef4444;
            color: #b91c1c;
        }

        .alert-error i {
            color: #ef4444;
        }

        .alert-success {
            background: #f0fdf4;
            border-left-color: #10b981;
            color: #065f46;
        }

        .alert-success i {
            color: #10b981;
        }

        .login-footer {
            text-align: center;
            padding: clamp(20px, 5vw, 24px) clamp(20px, 5vw, 30px);
            background: #f9fafb;
            border-top: 1px solid #e5e7eb;
        }

        .login-footer p {
            color: #6b7280;
            font-size: clamp(12px, 3vw, 13px);
            margin-bottom: 12px;
        }

        .security-badges {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: clamp(12px, 3vw, 20px);
            margin: 15px 0;
            flex-wrap: wrap;
        }

        .security-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: clamp(11px, 2.5vw, 12px);
            color: #4b5563;
            background: #f3f4f6;
            padding: 6px 12px;
            border-radius: 40px;
            white-space: nowrap;
        }

        .security-badge i {
            color: #10b981;
            font-size: 14px;
        }

        .role-badges {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 15px;
            flex-wrap: wrap;
        }

        .role-badge {
            padding: 6px 16px;
            background: #f3f4f6;
            border-radius: 40px;
            font-size: clamp(11px, 2.5vw, 12px);
            font-weight: 600;
            color: #4b5563;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .role-badge.admin {
            background: #fee2e2;
            color: #b91c1c;
        }

        .role-badge.finance {
            background: #dbeafe;
            color: #1e40af;
        }

        .copyright {
            margin-top: 15px;
            font-size: clamp(10px, 2.5vw, 11px);
            color: #9ca3af;
        }

        /* Touch device optimizations */
        @media (hover: none) and (pointer: coarse) {
            .btn-login:hover {
                transform: none;
            }
            
            .password-toggle {
                padding: 8px;
            }
        }

        /* Small screen adjustments */
        @media (max-width: 380px) {
            .security-badges {
                flex-direction: column;
                align-items: stretch;
            }
            
            .security-badge {
                justify-content: center;
            }
            
            .form-options {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        /* Landscape mode */
        @media (max-height: 600px) and (orientation: landscape) {
            body {
                padding: 8px;
                align-items: flex-start;
            }
            
            .login-container {
                margin: 8px auto;
            }
            
            .login-header {
                padding: 20px;
            }
            
            .login-form {
                padding: 20px;
            }
            
            .form-group {
                margin-bottom: 16px;
            }
        }

        /* High contrast mode support */
        @media (prefers-contrast: high) {
            .form-control {
                border-width: 3px;
            }
            
            .btn-login {
                border: 2px solid black;
            }
        }

        /* Reduced motion preference */
        @media (prefers-reduced-motion: reduce) {
            .shape,
            .login-header::before,
            .login-header .icon,
            .btn-login,
            .alert {
                animation: none;
                transition: none;
            }
        }
    </style>
</head>
<body>
    <!-- Animated background shapes -->
    <div class="bg-shapes" aria-hidden="true">
        <div class="shape shape1"></div>
        <div class="shape shape2"></div>
        <div class="shape shape3"></div>
    </div>

    <div class="login-container" role="main" aria-label="Login form">
        <div class="login-header">
            <span class="icon" aria-hidden="true">💰</span>
            <h1>CASMS Finance Portal</h1>
            <p>Secure access for finance officers and administrators</p>
        </div>

        <div class="login-form">
            <?php if($error): ?>
                <div class="alert alert-error" role="alert">
                    <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <?php if($success): ?>
                <div class="alert alert-success" role="alert">
                    <i class="fas fa-check-circle" aria-hidden="true"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="loginForm" autocomplete="off" novalidate>
                <div class="form-group">
                    <label for="username">
                        <i class="fas fa-user" aria-hidden="true"></i> Username or Email
                    </label>
                    <div class="input-group">
                        <i class="fas fa-user" aria-hidden="true"></i>
                        <input type="text" 
                               class="form-control" 
                               id="username" 
                               name="username" 
                               placeholder="Enter your username or email"
                               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                               required 
                               autofocus
                               autocomplete="username"
                               aria-required="true">
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">
                        <i class="fas fa-lock" aria-hidden="true"></i> Password
                    </label>
                    <div class="input-group">
                        <i class="fas fa-lock" aria-hidden="true"></i>
                        <input type="password" 
                               class="form-control" 
                               id="password" 
                               name="password" 
                               placeholder="Enter your password"
                               required
                               autocomplete="current-password"
                               aria-required="true">
                        <button type="button" 
                                class="password-toggle" 
                                onclick="togglePassword()" 
                                aria-label="Toggle password visibility">
                            <i class="fas fa-eye" id="toggleIcon" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>

                <div class="form-options">
                    <label class="remember-me">
                        <input type="checkbox" name="remember" <?php echo isset($_POST['remember']) ? 'checked' : ''; ?>>
                        <span>Remember me for 30 days</span>
                    </label>
                </div>

                <button type="submit" name="login" class="btn-login" id="loginBtn">
                    <span class="btn-text">Sign In to Dashboard</span>
                </button>
            </form>
        </div>

        <div class="login-footer">
            <div class="security-badges">
                <span class="security-badge">
                    <i class="fas fa-shield-alt"></i> 256-bit SSL
                </span>
                <span class="security-badge">
                    <i class="fas fa-clock"></i> 30 min timeout
                </span>
                <span class="security-badge">
                    <i class="fas fa-history"></i> Activity log
                </span>
            </div>
            
            <p>Authorized personnel only. All access is monitored and logged.</p>
            
            <div class="role-badges">
                <span class="role-badge admin">
                    <i class="fas fa-crown"></i> Administrator
                </span>
                <span class="role-badge finance">
                    <i class="fas fa-chart-line"></i> Finance Officer
                </span>
            </div>
            
            <div class="copyright">
                &copy; <?php echo date('Y'); ?> CASMS. All rights reserved.
            </div>
        </div>
    </div>

    <script>
        // Toggle password visibility
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }

        // Form submission with loading state
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('loginBtn');
            btn.classList.add('loading');
            // Don't change button text, it's hidden by CSS
        });

        // Prevent multiple form submissions
        let formSubmitted = false;
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            if (formSubmitted) {
                e.preventDefault();
                return;
            }
            formSubmitted = true;
        });

        // Add keyboard shortcut (Ctrl+Enter) to submit
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('loginForm').submit();
            }
        });

        // Clear sensitive data on page hide
        window.addEventListener('pagehide', function() {
            document.getElementById('password').value = '';
        });

        // Input validation - prevent HTML tags
        document.getElementById('username').addEventListener('input', function(e) {
            this.value = this.value.replace(/<[^>]*>/g, '');
        });

        // Show/Hide password with keyboard (Ctrl+H)
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'h') {
                e.preventDefault();
                togglePassword();
            }
        });

        // Auto-focus username field
        window.addEventListener('load', function() {
            document.getElementById('username').focus();
        });

        // Prevent right-click context menu on password field
        document.getElementById('password').addEventListener('contextmenu', function(e) {
            e.preventDefault();
        });

        // Touch device optimizations
        if ('ontouchstart' in window) {
            document.body.classList.add('touch-device');
        }

        // Handle orientation change
        window.addEventListener('orientationchange', function() {
            // Ensure the form is centered after orientation change
            setTimeout(function() {
                window.scrollTo(0, 0);
            }, 100);
        });

        // Disable autocorrect on mobile
        document.getElementById('username').setAttribute('autocorrect', 'off');
        document.getElementById('username').setAttribute('autocapitalize', 'none');
        document.getElementById('password').setAttribute('autocorrect', 'off');
        document.getElementById('password').setAttribute('autocapitalize', 'none');
    </script>
</body>
</html>