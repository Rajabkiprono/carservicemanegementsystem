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
$success = '';
$name = '';
$email = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Validation
    $errors = [];
    
    if(empty($name)) {
        $errors[] = "Name is required";
    }
    
    if(empty($email)) {
        $errors[] = "Email is required";
    } elseif(!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }
    
    if(empty($password)) {
        $errors[] = "Password is required";
    } elseif(strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters";
    }
    
    if($password !== $confirm_password) {
        $errors[] = "Passwords do not match";
    }
    
    // Check if email already exists
    if(empty($errors)) {
        try {
            $checkQuery = "SELECT id FROM users WHERE email = :email";
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->bindParam(":email", $email);
            $checkStmt->execute();
            
            if($checkStmt->rowCount() > 0) {
                $errors[] = "Email already registered";
            }
        } catch (PDOException $e) {
            $errors[] = "Registration failed. Please try again.";
            error_log("Registration check error: " . $e->getMessage());
        }
    }
    
    // Insert user if no errors
    if(empty($errors)) {
        try {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            $query = "INSERT INTO users (name, email, password, created_at) VALUES (:name, :email, :password, NOW())";
            $stmt = $db->prepare($query);
            
            $stmt->bindParam(":name", $name);
            $stmt->bindParam(":email", $email);
            $stmt->bindParam(":password", $hashed_password);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = "Registration successful! Please login.";
                header("Location: login.php");
                exit();
            } else {
                $errors[] = "Registration failed. Please try again.";
            }
        } catch (PDOException $e) {
            $errors[] = "Registration failed. Please try again.";
            error_log("Registration error: " . $e->getMessage());
        }
    }
    
    if(!empty($errors)) {
        $error = implode("<br>", $errors);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CASMS | Create Account</title>
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
        .register-container {
            width: 100%;
            max-width: 500px;
            animation: slideUp 0.5s ease;
        }

        /* Register Card */
        .register-card {
            background: white;
            border-radius: 32px;
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }

        /* Header */
        .register-header {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            padding: 2.5rem 2rem;
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .register-header::before {
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

        .register-header h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            position: relative;
        }

        .register-header p {
            font-size: 0.95rem;
            opacity: 0.9;
            position: relative;
        }

        /* Form */
        .register-form {
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

        /* Success Alert */
        .success-alert {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem;
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            border-radius: 16px;
            color: #065f46;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }

        .success-alert::before {
            content: '✓';
            font-size: 1.1rem;
            font-weight: bold;
        }

        /* Form Groups */
        .form-group {
            margin-bottom: 1.25rem;
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

        /* Password Requirements */
        .password-requirements {
            margin-top: 0.5rem;
            padding: 0.75rem;
            background: #f8fafc;
            border-radius: 12px;
            font-size: 0.8rem;
        }

        .requirement-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #64748b;
            margin-top: 0.25rem;
        }

        .requirement-item.valid {
            color: #10b981;
        }

        .requirement-item.invalid {
            color: #ef4444;
        }

        .requirement-item:first-child {
            margin-top: 0;
        }

        /* Terms Checkbox */
        .terms-group {
            margin: 1.5rem 0;
        }

        .terms-label {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            cursor: pointer;
        }

        .checkbox-custom {
            width: 20px;
            height: 20px;
            border: 2px solid #cbd5e1;
            border-radius: 6px;
            position: relative;
            transition: all 0.2s;
            flex-shrink: 0;
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

        .terms-text {
            font-size: 0.9rem;
            color: #475569;
        }

        .terms-text a {
            color: #2563eb;
            text-decoration: none;
            font-weight: 500;
        }

        .terms-text a:hover {
            text-decoration: underline;
        }

        /* Register Button */
        .register-btn {
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

        .register-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(37, 99, 235, 0.5);
        }

        .register-btn:active {
            transform: translateY(0);
        }

        .register-btn::before {
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

        .register-btn:hover::before {
            width: 300px;
            height: 300px;
        }

        .btn-icon {
            transition: transform 0.3s;
        }

        .register-btn:hover .btn-icon {
            transform: translateX(5px);
        }

        .register-btn.loading {
            opacity: 0.7;
            cursor: not-allowed;
        }

        .register-btn.loading .btn-text {
            display: none;
        }

        .register-btn.loading .btn-loader {
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

        /* Social Signup Section */
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

        /* Login Link */
        .login-section {
            text-align: center;
            border-top: 1px solid #e2e8f0;
            padding-top: 1.5rem;
            margin-top: 1rem;
        }

        .login-text {
            color: #64748b;
            font-size: 0.95rem;
        }

        .login-link {
            color: #2563eb;
            text-decoration: none;
            font-weight: 600;
            margin-left: 0.25rem;
        }

        .login-link:hover {
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

            .register-card {
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

            .password-requirements {
                background: #0f172a;
            }

            .requirement-item {
                color: #94a3b8;
            }

            .terms-text {
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

            .login-section {
                border-top-color: #334155;
            }

            .login-text {
                color: #94a3b8;
            }
        }

        /* Responsive */
        @media (max-width: 480px) {
            .register-header {
                padding: 2rem 1.5rem;
            }

            .register-header h1 {
                font-size: 1.75rem;
            }

            .register-form {
                padding: 1.5rem;
            }

            .social-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-card">
            <!-- Header -->
            <div class="register-header">
                <div class="header-icon">
                    <svg viewBox="0 0 24 24">
                        <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                    </svg>
                </div>
                <h1>Create Account</h1>
                <p>Join CASMS to manage your vehicles</p>
            </div>

            <!-- Form -->
            <div class="register-form">
                <?php if ($error): ?>
                    <div class="error-alert">
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" id="registerForm">
                    <!-- Name Field -->
                    <div class="form-group">
                        <label class="form-label">
                            <span class="label-icon">👤</span>
                            Full Name
                        </label>
                        <div class="input-wrapper">
                            <span class="input-icon">📝</span>
                            <input type="text" 
                                   name="name" 
                                   class="form-control" 
                                   placeholder="Enter your full name"
                                   value="<?php echo htmlspecialchars($name); ?>"
                                   required>
                        </div>
                    </div>

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
                                   placeholder="Create a password"
                                   id="password"
                                   required>
                            <span class="password-toggle" onclick="togglePassword('password')">👁️</span>
                        </div>
                        <div class="password-requirements" id="passwordRequirements">
                            <div class="requirement-item" id="lengthReq">
                                <span>•</span> At least 6 characters
                            </div>
                        </div>
                    </div>

                    <!-- Confirm Password Field -->
                    <div class="form-group">
                        <label class="form-label">
                            <span class="label-icon">✓</span>
                            Confirm Password
                        </label>
                        <div class="input-wrapper">
                            <span class="input-icon">🔒</span>
                            <input type="password" 
                                   name="confirm_password" 
                                   class="form-control" 
                                   placeholder="Confirm your password"
                                   id="confirm_password"
                                   required>
                            <span class="password-toggle" onclick="togglePassword('confirm_password')">👁️</span>
                        </div>
                        <div class="password-requirements">
                            <div class="requirement-item" id="matchReq">
                                <span>•</span> Passwords match
                            </div>
                        </div>
                    </div>

                    <!-- Terms Checkbox -->
                    <div class="terms-group">
                        <label class="terms-label">
                            <input type="checkbox" name="terms" id="terms" required hidden>
                            <span class="checkbox-custom"></span>
                            <span class="terms-text">
                                I agree to the <a href="#">Terms of Service</a> and 
                                <a href="#">Privacy Policy</a>
                            </span>
                        </label>
                    </div>

                    <!-- Register Button -->
                    <button type="submit" name="register" class="register-btn" id="registerBtn">
                        <span class="btn-text">Create Account</span>
                        <span class="btn-icon">→</span>
                        <div class="btn-loader"></div>
                    </button>
                </form>

                <!-- Social Signup Section -->
                <div class="social-section">
                    <div class="social-divider">
                        <span>Or sign up with</span>
                    </div>
                    
                    <div class="social-buttons">
                        <!-- Google Sign Up -->
                        <a href="google.php" class="social-btn google">
                            <span class="social-icon">G</span>
                            <span>Google</span>
                        </a>
                        
                        <!-- Facebook Sign Up -->
                        <a href="facebook.php" class="social-btn facebook">
                            <span class="social-icon">f</span>
                            <span>Facebook</span>
                        </a>
                    </div>
                </div>

                <!-- Login Link -->
                <div class="login-section">
                    <p class="login-text">
                        Already have an account?
                        <a href="login.php" class="login-link">Sign In</a>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Password visibility toggle
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const toggleIcon = field.nextElementSibling;
            
            if (field.type === 'password') {
                field.type = 'text';
                toggleIcon.textContent = '🔓';
            } else {
                field.type = 'password';
                toggleIcon.textContent = '👁️';
            }
        }

        // Password validation
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirm_password');
        const lengthReq = document.getElementById('lengthReq');
        const matchReq = document.getElementById('matchReq');

        function validatePassword() {
            // Check length
            if(password.value.length >= 6) {
                lengthReq.classList.add('valid');
                lengthReq.classList.remove('invalid');
            } else {
                lengthReq.classList.add('invalid');
                lengthReq.classList.remove('valid');
            }
            
            // Check match
            if(password.value && confirmPassword.value && password.value === confirmPassword.value) {
                matchReq.classList.add('valid');
                matchReq.classList.remove('invalid');
            } else {
                matchReq.classList.add('invalid');
                matchReq.classList.remove('valid');
            }
        }

        password.addEventListener('input', validatePassword);
        confirmPassword.addEventListener('input', validatePassword);

        // Form submission with loading state
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('registerBtn');
            
            // Validate terms
            if(!document.getElementById('terms').checked) {
                e.preventDefault();
                alert('Please agree to the Terms of Service and Privacy Policy');
                return;
            }
            
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