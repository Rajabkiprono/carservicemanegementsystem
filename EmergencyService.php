<?php
session_start();
if(!isset($_SESSION['user_id'])){
    header("Location: auth/login.php");
    exit();
}

require_once "config/database.php";
$db = (new Database())->connect();

$stmt = $db->prepare("SELECT * FROM cars WHERE user_id=:uid");
$stmt->execute([":uid"=>$_SESSION['user_id']]);
$cars = $stmt->fetchAll(PDO::FETCH_ASSOC);

if(isset($_POST['emergency'])){
    $stmt = $db->prepare("INSERT INTO emergency_services(user_id,car_id,service_type,location,status)
                          VALUES(:uid,:car,:type,:location,'Pending')");
    $stmt->execute([
        ":uid"=>$_SESSION['user_id'],
        ":car"=>$_POST['car_id'],
        ":type"=>$_POST['service_type'],
        ":location"=>$_POST['location']
    ]);
    $success = "Emergency request sent!";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Corporate Garage | Emergency Service</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #f5f7fa;
            color: #1e293b;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            transition: background-color 0.3s;
        }

        /* Dark mode */
        body.dark {
            background-color: #0f172a;
            color: #e2e8f0;
        }

        /* Main Container - Same as dashboard */
        .dashboard-container {
            display: flex;
            width: 100%;
            max-width: 1400px;
            background: transparent;
            gap: 1.5rem;
        }

        /* Sidebar - Exactly like dashboard */
        .sidebar {
            width: 280px;
            background-color: #ffffff;
            border-radius: 24px;
            padding: 2rem 1.5rem;
            height: fit-content;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
            transition: all 0.3s;
        }

        body.dark .sidebar {
            background-color: #1e293b;
            border-color: #334155;
        }

        .sidebar h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #2563eb;
            margin-bottom: 2rem;
            letter-spacing: -0.5px;
            text-align: center;
        }

        .sidebar nav {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .sidebar a {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.875rem 1rem;
            color: #64748b;
            text-decoration: none;
            border-radius: 12px;
            font-weight: 500;
            transition: all 0.2s;
            text-align: center;
        }

        .sidebar a:hover {
            background-color: #f1f5f9;
            color: #2563eb;
        }

        .sidebar a.active {
            background-color: #2563eb;
            color: #ffffff;
        }

        body.dark .sidebar a:hover {
            background-color: #334155;
            color: #60a5fa;
        }

        body.dark .sidebar a.active {
            background-color: #2563eb;
            color: #ffffff;
        }

        /* Main Content Area */
        .main-content {
            flex: 1;
            min-width: 0;
        }

        /* Navbar - Same as dashboard */
        .navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            background-color: #ffffff;
            border-radius: 24px;
            margin-bottom: 1.5rem;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            transition: all 0.3s;
        }

        body.dark .navbar {
            background-color: #1e293b;
            border-color: #334155;
        }

        .navbar-left h3 {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1e293b;
        }

        body.dark .navbar-left h3 {
            color: #f1f5f9;
        }

        .navbar-right {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 1rem;
            background-color: #f8fafc;
            border-radius: 12px;
        }

        body.dark .user-profile {
            background-color: #0f172a;
        }

        .user-profile span {
            font-weight: 600;
            color: #1e293b;
        }

        body.dark .user-profile span {
            color: #f1f5f9;
        }

        .btn {
            padding: 0.625rem 1.25rem;
            border: none;
            border-radius: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.875rem;
            font-family: 'Inter', sans-serif;
        }

        .btn-outline {
            background-color: transparent;
            border: 1px solid #cbd5e1;
            color: #475569;
        }

        .btn-outline:hover {
            background-color: #f1f5f9;
        }

        body.dark .btn-outline {
            border-color: #475569;
            color: #e2e8f0;
        }

        body.dark .btn-outline:hover {
            background-color: #334155;
        }

        /* Emergency Container - Main Card */
        .emergency-container {
            background-color: #ffffff;
            border-radius: 32px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            transition: all 0.3s;
        }

        body.dark .emergency-container {
            background-color: #1e293b;
            border-color: #334155;
        }

        /* Emergency Header - Blue gradient to match dashboard */
        .emergency-header {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            padding: 2.5rem;
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .emergency-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }

        .emergency-icon {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .emergency-icon svg {
            width: 40px;
            height: 40px;
            fill: white;
        }

        .emergency-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            position: relative;
        }

        .emergency-subtitle {
            font-size: 1rem;
            opacity: 0.9;
            position: relative;
        }

        .emergency-badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.875rem;
            font-weight: 600;
            margin-top: 1rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        /* Alert Messages */
        .alert {
            margin: 1.5rem;
            padding: 1rem 1.5rem;
            border-radius: 16px;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideIn 0.3s ease;
        }

        .alert-success {
            background-color: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
        }

        .alert-success::before {
            content: '✓';
            display: flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            background: #10b981;
            color: white;
            border-radius: 50%;
            font-weight: bold;
        }

        body.dark .alert-success {
            background-color: #065f46;
            border-color: #10b981;
            color: #ecfdf5;
        }

        /* Warning Message */
        .warning-message {
            background-color: #fef3c7;
            border: 1px solid #fde68a;
            border-radius: 16px;
            padding: 1rem 1.5rem;
            margin: 0 1.5rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: #92400e;
            font-size: 0.875rem;
        }

        body.dark .warning-message {
            background-color: #92400e;
            border-color: #f59e0b;
            color: #fef3c7;
        }

        .warning-icon {
            font-size: 1.25rem;
        }

        /* Form */
        .emergency-form {
            padding: 2rem;
        }

        .form-section {
            margin-bottom: 2rem;
            padding-bottom: 2rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .form-section:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        body.dark .form-section {
            border-bottom-color: #334155;
        }

        .form-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 1rem;
            font-size: 0.95rem;
        }

        body.dark .form-label {
            color: #e2e8f0;
        }

        .label-icon {
            font-size: 1.25rem;
        }

        /* Select Styling */
        .select-wrapper {
            position: relative;
        }

        .form-select {
            width: 100%;
            padding: 1rem 1.25rem;
            padding-right: 3rem;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            font-family: 'Inter', sans-serif;
            font-size: 1rem;
            color: #1e293b;
            background: white;
            cursor: pointer;
            appearance: none;
            transition: all 0.3s;
        }

        body.dark .form-select {
            background-color: #0f172a;
            border-color: #334155;
            color: #f1f5f9;
        }

        body.dark .form-select option {
            background-color: #0f172a;
            color: #f1f5f9;
        }

        .form-select:hover {
            border-color: #2563eb;
        }

        .form-select:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
        }

        .select-arrow {
            position: absolute;
            right: 1.25rem;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            pointer-events: none;
        }

        /* Input Styling */
        .input-wrapper {
            position: relative;
        }

        .form-input {
            width: 100%;
            padding: 1rem 1.25rem;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            font-family: 'Inter', sans-serif;
            font-size: 1rem;
            transition: all 0.3s;
            background: white;
            color: #1e293b;
        }

        body.dark .form-input {
            background-color: #0f172a;
            border-color: #334155;
            color: #f1f5f9;
        }

        .form-input:hover {
            border-color: #2563eb;
        }

        .form-input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
        }

        .input-icon {
            position: absolute;
            right: 1.25rem;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            pointer-events: none;
        }

        /* Location Input with Icon */
        .location-input {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z'%3E%3C/path%3E%3Ccircle cx='12' cy='10' r='3'%3E%3C/circle%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1.25rem center;
            background-size: 1.25rem;
        }

        /* Quick Location Buttons */
        .quick-locations {
            display: flex;
            gap: 0.75rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        .quick-location-btn {
            flex: 1;
            padding: 0.75rem;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: 0.875rem;
            font-weight: 500;
            color: #475569;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            min-width: 120px;
        }

        body.dark .quick-location-btn {
            background: #0f172a;
            border-color: #334155;
            color: #94a3b8;
        }

        .quick-location-btn:hover {
            background: #2563eb;
            border-color: #2563eb;
            color: white;
        }

        .quick-location-btn:hover svg {
            stroke: white;
        }

        /* Emergency Button - Blue to match dashboard */
        .emergency-btn {
            width: 100%;
            padding: 1.25rem;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            border: none;
            border-radius: 16px;
            color: white;
            font-weight: 700;
            font-size: 1.125rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            position: relative;
            overflow: hidden;
            transition: all 0.3s;
            margin: 1.5rem 0 1rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-family: 'Inter', sans-serif;
        }

        .emergency-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(37, 99, 235, 0.5);
        }

        .emergency-btn:active {
            transform: translateY(0);
        }

        .emergency-btn::before {
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

        .emergency-btn:hover::before {
            width: 300px;
            height: 300px;
        }

        .btn-icon {
            font-size: 1.25rem;
        }

        /* Back Button */
        .back-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.875rem 1.5rem;
            background: transparent;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            color: #475569;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.3s;
            width: 100%;
        }

        body.dark .back-btn {
            border-color: #334155;
            color: #94a3b8;
        }

        .back-btn:hover {
            background: #f8fafc;
            border-color: #2563eb;
            color: #2563eb;
            transform: translateX(-5px);
        }

        body.dark .back-btn:hover {
            background: #0f172a;
        }

        .back-icon {
            font-size: 1.25rem;
            transition: transform 0.3s;
        }

        .back-btn:hover .back-icon {
            transform: translateX(-3px);
        }

        /* Car Info Card */
        .car-info-card {
            background: #f8fafc;
            border-radius: 12px;
            padding: 0.75rem 1rem;
            margin-top: 0.75rem;
            font-size: 0.875rem;
            color: #475569;
            border: 1px solid #e2e8f0;
        }

        body.dark .car-info-card {
            background: #0f172a;
            border-color: #334155;
            color: #94a3b8;
        }

        /* Animations */
        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Responsive */
        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }

            .dashboard-container {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
            }

            .quick-locations {
                flex-direction: column;
            }

            .quick-location-btn {
                width: 100%;
            }

            .emergency-header {
                padding: 1.5rem;
            }

            .emergency-title {
                font-size: 1.5rem;
            }

            .navbar {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar - Same as dashboard -->
        <div class="sidebar">
            <h2>Corporate Garage</h2>
            <nav>
                <a href="dashboard.php">Dashboard</a>
                <a href="vehicles.php">Vehicles</a>
                <a href="services.php">Services & Pricing</a>
                <a href="book_service.php">Book Appointment</a>
                <a href="emergency_services.php" class="active">Emergency Services</a>
                <a href="spareparts.php">Spare Parts</a>
                <a href="profile.php">My Profile</a>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Navbar - Same as dashboard -->
            <div class="navbar">
                <div class="navbar-left">
                    <h3>Welcome, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?></h3>
                </div>
                <div class="navbar-right">
                    <button class="btn btn-outline" onclick="toggleDarkMode()">
                        <span id="modeIcon">🌙</span> Dark Mode
                    </button>
                    <div class="user-profile">
                        <span><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Emergency Container - Main Form Card -->
            <div class="emergency-container">
                <!-- Emergency Header - Blue gradient -->
                <div class="emergency-header">
                    <div class="emergency-icon">
                        <svg viewBox="0 0 24 24">
                            <path d="M12 2L1 21h22L12 2zm0 4l8 13H4l8-13zm1 5h-2v4h2v-4zm0 6h-2v2h2v-2z"/>
                        </svg>
                    </div>
                    <h1 class="emergency-title">Emergency Service</h1>
                    <p class="emergency-subtitle">24/7 roadside assistance at your location</p>
                    <span class="emergency-badge">🚨 Immediate Response</span>
                </div>

                <!-- Alert Message -->
                <?php if(isset($success)): ?>
                    <div class="alert alert-success">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <!-- Warning Message -->
                <div class="warning-message">
                    <span class="warning-icon">⚠️</span>
                    <span>Emergency services will be dispatched immediately. Please ensure your location is accurate.</span>
                </div>

                <!-- Emergency Form -->
                <form method="POST" class="emergency-form" id="emergencyForm">
                    <!-- Car Selection -->
                    <div class="form-section">
                        <label class="form-label">
                            <span class="label-icon">🚗</span>
                            Select Vehicle
                        </label>
                        <div class="select-wrapper">
                            <select name="car_id" class="form-select" required>
                                <option value="" disabled selected>Choose your vehicle</option>
                                <?php if(isset($cars) && !empty($cars)): ?>
                                    <?php foreach($cars as $c): ?>
                                        <option value="<?= htmlspecialchars($c['id']) ?>">
                                            <?= htmlspecialchars($c['brand']." ".$c['model']." (".$c['year'].")") ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="" disabled>No vehicles found. Please add a vehicle first.</option>
                                <?php endif; ?>
                            </select>
                            <span class="select-arrow">▼</span>
                        </div>
                        
                        <?php if(isset($cars) && !empty($cars)): ?>
                        <div class="car-info-card">
                            <strong>Quick info:</strong> Your selected vehicle will be prioritized for emergency service
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Issue Type -->
                    <div class="form-section">
                        <label class="form-label">
                            <span class="label-icon">🔧</span>
                            Issue Type
                        </label>
                        <div class="input-wrapper">
                            <input type="text" 
                                   name="service_type" 
                                   class="form-input" 
                                   placeholder="e.g., Engine failure, Flat tire, Battery dead"
                                   required
                                   list="common-issues">
                            <span class="input-icon">⚡</span>
                        </div>
                        
                        <!-- Common Issues Datalist -->
                        <datalist id="common-issues">
                            <option value="Engine Failure">
                            <option value="Flat Tire">
                            <option value="Dead Battery">
                            <option value="Overheating">
                            <option value="Accident">
                            <option value="Locked Out">
                            <option value="Fuel Empty">
                            <option value="Brake Failure">
                            <option value="Electrical Issue">
                        </datalist>
                    </div>

                    <!-- Location -->
                    <div class="form-section">
                        <label class="form-label">
                            <span class="label-icon">📍</span>
                            Your Location
                        </label>
                        <div class="input-wrapper">
                            <input type="text" 
                                   name="location" 
                                   class="form-input location-input" 
                                   placeholder="Enter your current address or location"
                                   required
                                   id="locationInput">
                        </div>

                        <!-- Quick Location Buttons -->
                        <div class="quick-locations">
                            <button type="button" class="quick-location-btn" onclick="setLocation('current')">
                                <span>📍</span> Use My Location
                            </button>
                            <button type="button" class="quick-location-btn" onclick="setLocation('home')">
                                <span>🏠</span> Home
                            </button>
                            <button type="button" class="quick-location-btn" onclick="setLocation('work')">
                                <span>💼</span> Work
                            </button>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <button type="submit" name="emergency" class="emergency-btn">
                        <span class="btn-icon">🚨</span>
                        Request Emergency Service
                        <span class="btn-icon">🚨</span>
                    </button>

                    <!-- Back Button -->
                    <a href="dashboard.php" class="back-btn">
                        <span class="back-icon">←</span>
                        Back to Dashboard
                    </a>

                    <!-- Emergency Note -->
                    <p style="text-align: center; margin-top: 1.5rem; font-size: 0.75rem; color: #94a3b8;">
                        ⏱️ Estimated response time: 15-30 minutes
                    </p>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Dark mode toggle
        function toggleDarkMode() {
            document.body.classList.toggle('dark');
            const modeIcon = document.getElementById('modeIcon');
            if (document.body.classList.contains('dark')) {
                modeIcon.textContent = '☀️';
            } else {
                modeIcon.textContent = '🌙';
            }
            localStorage.setItem('darkMode', document.body.classList.contains('dark'));
        }

        // Check for saved dark mode
        if (localStorage.getItem('darkMode') === 'true') {
            document.body.classList.add('dark');
            document.getElementById('modeIcon').textContent = '☀️';
        }

        // Location functions
        function setLocation(type) {
            const locationInput = document.getElementById('locationInput');
            
            if (type === 'current') {
                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(function(position) {
                        const lat = position.coords.latitude;
                        const lng = position.coords.longitude;
                        locationInput.value = `Current location (${lat.toFixed(4)}, ${lng.toFixed(4)})`;
                        showMessage('📍 Location detected successfully!', 'success');
                    }, function() {
                        showMessage('❌ Unable to get location. Please enter manually.', 'error');
                    });
                } else {
                    showMessage('❌ Geolocation not supported. Please enter manually.', 'error');
                }
            } else if (type === 'home') {
                locationInput.value = 'Home Address (Saved)';
                showMessage('🏠 Home address selected', 'success');
            } else if (type === 'work') {
                locationInput.value = 'Work Address (Saved)';
                showMessage('💼 Work address selected', 'success');
            }
        }

        // Show message function
        function showMessage(msg, type) {
            const messageDiv = document.createElement('div');
            messageDiv.className = `alert alert-${type === 'success' ? 'success' : 'error'}`;
            messageDiv.textContent = msg;
            messageDiv.style.position = 'fixed';
            messageDiv.style.top = '20px';
            messageDiv.style.right = '20px';
            messageDiv.style.zIndex = '1000';
            messageDiv.style.animation = 'slideIn 0.3s ease';
            document.body.appendChild(messageDiv);
            
            setTimeout(() => {
                messageDiv.remove();
            }, 3000);
        }

        // Form validation
        document.getElementById('emergencyForm').addEventListener('submit', function(e) {
            const carSelect = document.querySelector('select[name="car_id"]');
            const issueInput = document.querySelector('input[name="service_type"]');
            const locationInput = document.querySelector('input[name="location"]');
            
            if (!carSelect.value) {
                e.preventDefault();
                showMessage('Please select a vehicle', 'error');
                carSelect.focus();
                return;
            }
            
            if (!issueInput.value.trim()) {
                e.preventDefault();
                showMessage('Please describe the issue', 'error');
                issueInput.focus();
                return;
            }
            
            if (!locationInput.value.trim()) {
                e.preventDefault();
                showMessage('Please enter your location', 'error');
                locationInput.focus();
                return;
            }
            
            if (!confirm('🚨 Emergency services will be dispatched immediately. Continue?')) {
                e.preventDefault();
            }
        });

        // Add loading state to button
        document.querySelector('.emergency-btn').addEventListener('click', function(e) {
            if (document.getElementById('emergencyForm').checkValidity()) {
                this.innerHTML = '<span class="btn-loader"></span> Dispatching Emergency Services...';
                this.disabled = true;
            }
        });
    </script>

    <!-- Add loader style -->
    <style>
        .btn-loader {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin-right: 0.5rem;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .emergency-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }

        .alert-error {
            background-color: #fef2f2;
            border: 1px solid #fee2e2;
            color: #b91c1c;
        }

        .alert-error::before {
            content: '⚠️';
            margin-right: 0.5rem;
        }

        body.dark .alert-error {
            background-color: #7f1d1d;
            border-color: #b91c1c;
            color: #fee2e2;
        }
    </style>
</body>
</html>