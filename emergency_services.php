<?php
session_start();
if(!isset($_SESSION['user_id'])){
    header("Location: index.php");
    exit();
}

require_once "database.php";

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$db = (new Database())->connect();
$error = '';
$success = '';

// Handle emergency service booking
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_emergency'])){
    try {
        // Validate CSRF token
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid security token");
        }

        // Validate required fields
        if(empty($_POST['vehicle_id']) || empty($_POST['service_type']) || empty($_POST['contact_number'])) {
            throw new Exception("Please fill in all required fields");
        }

        // Check if vehicle belongs to user
        $checkStmt = $db->prepare("SELECT id, brand, model, license_plate FROM vehicles WHERE id = ? AND user_id = ?");
        $checkStmt->execute([$_POST['vehicle_id'], $_SESSION['user_id']]);
        $vehicle = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if(!$vehicle) {
            throw new Exception("Invalid vehicle selected");
        }

        // Check for existing emergency request
        $existingStmt = $db->prepare("
            SELECT id FROM emergency_services 
            WHERE user_id = ? AND status IN ('pending', 'dispatched') 
            ORDER BY created_at DESC LIMIT 1
        ");
        $existingStmt->execute([$_SESSION['user_id']]);
        if($existingStmt->fetch()) {
            throw new Exception("You already have an active emergency request. Please wait for assistance.");
        }

        // Handle image uploads
        $imagePaths = [];
        if(isset($_FILES['vehicle_images'])) {
            $uploadDir = 'uploads/emergency/';
            
            // Create directory if it doesn't exist
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif'];
            $maxSize = 5 * 1024 * 1024; // 5MB
            
            foreach($_FILES['vehicle_images']['tmp_name'] as $key => $tmp_name) {
                if($_FILES['vehicle_images']['error'][$key] === UPLOAD_ERR_OK) {
                    $fileType = $_FILES['vehicle_images']['type'][$key];
                    $fileSize = $_FILES['vehicle_images']['size'][$key];
                    
                    if(in_array($fileType, $allowedTypes) && $fileSize <= $maxSize) {
                        $extension = pathinfo($_FILES['vehicle_images']['name'][$key], PATHINFO_EXTENSION);
                        $filename = uniqid() . '_' . time() . '.' . $extension;
                        $filepath = $uploadDir . $filename;
                        
                        if(move_uploaded_file($_FILES['vehicle_images']['tmp_name'][$key], $filepath)) {
                            $imagePaths[] = $filepath;
                        }
                    }
                }
            }
        }

        // Begin transaction
        $db->beginTransaction();

        // Generate unique request ID
        $requestId = 'EMG-' . strtoupper(uniqid());

        // Get coordinates from location input (if provided as lat,lng)
        $coordinates = null;
        $location = $_POST['location'];
        if(preg_match('/^([-+]?[\d.]+),\s*([-+]?[\d.]+)$/', $location, $matches)) {
            $coordinates = $matches[1] . ',' . $matches[2];
        }

        // Insert emergency service
        $stmt = $db->prepare("
            INSERT INTO emergency_services (
                request_id, user_id, vehicle_id, service_type, 
                contact_number, location, coordinates, description, urgency_level,
                vehicle_images, status, created_at, estimated_arrival
            ) VALUES (
                :request_id, :user_id, :vehicle_id, :service_type,
                :contact_number, :location, :coordinates, :description, :urgency_level,
                :vehicle_images, 'pending', NOW(), DATE_ADD(NOW(), INTERVAL 30 MINUTE)
            )
        ");

        $result = $stmt->execute([
            ":request_id" => $requestId,
            ":user_id" => $_SESSION['user_id'],
            ":vehicle_id" => $_POST['vehicle_id'],
            ":service_type" => $_POST['service_type'],
            ":contact_number" => $_POST['contact_number'],
            ":location" => $location,
            ":coordinates" => $coordinates,
            ":description" => $_POST['description'] ?? null,
            ":urgency_level" => $_POST['urgency_level'] ?? 'high',
            ":vehicle_images" => !empty($imagePaths) ? json_encode($imagePaths) : null
        ]);

        $emergencyId = $db->lastInsertId();

        if($result) {
            // Create notification
            $notifStmt = $db->prepare("
                INSERT INTO notifications (
                    user_id, type, title, message, data, created_at
                ) VALUES (
                    :user_id, 'emergency', '🚨 Emergency Service Requested', 
                    :message, :data, NOW()
                )
            ");

            $message = "Emergency " . $_POST['service_type'] . " requested for " . $vehicle['brand'] . " " . $vehicle['model'] . " (" . $vehicle['license_plate'] . ")";
            $data = json_encode([
                'emergency_id' => $emergencyId,
                'request_id' => $requestId,
                'vehicle_id' => $_POST['vehicle_id'],
                'service_type' => $_POST['service_type'],
                'location' => $_POST['location'],
                'coordinates' => $coordinates,
                'has_images' => !empty($imagePaths)
            ]);

            $notifStmt->execute([
                ":user_id" => $_SESSION['user_id'],
                ":message" => $message,
                ":data" => $data
            ]);

            // Create admin notification
            $adminNotifStmt = $db->prepare("
                INSERT INTO notifications (user_id, type, title, message, data, created_at)
                SELECT id, 'emergency', '🚨 New Emergency Request', 
                       CONCAT('Emergency request from ', ?, ' for ', ?, ' at ', ?),
                       ?, NOW()
                FROM users WHERE role = 'admin'
            ");
            
            $adminData = json_encode([
                'emergency_id' => $emergencyId,
                'user_id' => $_SESSION['user_id'],
                'user_name' => $_SESSION['user_name'] ?? 'User',
                'request_id' => $requestId
            ]);
            
            $adminNotifStmt->execute([
                $_SESSION['user_name'] ?? 'User',
                $_POST['service_type'],
                $_POST['location'],
                $adminData
            ]);

            $db->commit();
            
            $_SESSION['emergency_success'] = "Emergency service booked successfully! Our team will contact you shortly.";
            header("Location: emergency_services.php?success=1&request=" . $requestId);
            exit();
        } else {
            throw new Exception("Failed to book emergency service");
        }
    } catch (Exception $e) {
        $db->rollBack();
        $error = $e->getMessage();
        error_log("Emergency booking error: " . $e->getMessage());
    }
}

// Handle cancel emergency request
if(isset($_POST['cancel_emergency'])) {
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid security token");
        }

        // Get emergency details for notification
        $getStmt = $db->prepare("SELECT * FROM emergency_services WHERE id = ? AND user_id = ?");
        $getStmt->execute([$_POST['emergency_id'], $_SESSION['user_id']]);
        $emergency = $getStmt->fetch(PDO::FETCH_ASSOC);

        if($emergency) {
            $stmt = $db->prepare("
                UPDATE emergency_services 
                SET status = 'cancelled', updated_at = NOW() 
                WHERE id = ? AND user_id = ? AND status IN ('pending', 'dispatched')
            ");
            $stmt->execute([$_POST['emergency_id'], $_SESSION['user_id']]);

            // Create cancellation notification
            $notifStmt = $db->prepare("
                INSERT INTO notifications (user_id, type, title, message, data, created_at)
                VALUES (?, 'warning', 'Emergency Request Cancelled', ?, ?, NOW())
            ");
            
            $message = "Your emergency request #" . $emergency['request_id'] . " has been cancelled";
            $data = json_encode([
                'emergency_id' => $_POST['emergency_id'],
                'request_id' => $emergency['request_id']
            ]);
            
            $notifStmt->execute([$_SESSION['user_id'], $message, $data]);

            $_SESSION['success'] = "Emergency request cancelled successfully";
        }
        header("Location: emergency_services.php");
        exit();
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle AJAX request for notifications
if(isset($_GET['ajax']) && $_GET['ajax'] == 'get_notifications') {
    header('Content-Type: application/json');
    
    try {
        // Get unread notifications count
        $countStmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
        $countStmt->execute([$_SESSION['user_id']]);
        $count = $countStmt->fetch(PDO::FETCH_ASSOC)['count'];

        // Get recent notifications
        $notifStmt = $db->prepare("
            SELECT * FROM notifications 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT 5
        ");
        $notifStmt->execute([$_SESSION['user_id']]);
        $notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get active emergency
        $emergencyStmt = $db->prepare("
            SELECT * FROM emergency_services 
            WHERE user_id = ? AND status IN ('pending', 'dispatched')
            ORDER BY created_at DESC LIMIT 1
        ");
        $emergencyStmt->execute([$_SESSION['user_id']]);
        $activeEmergency = $emergencyStmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'unread_count' => $count,
            'notifications' => $notifications,
            'active_emergency' => $activeEmergency
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Handle mark notification as read
if(isset($_POST['mark_read'])) {
    try {
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND id = ?");
        $stmt->execute([$_SESSION['user_id'], $_POST['notification_id']]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false]);
    }
    exit();
}

// Handle mark all as read
if(isset($_POST['mark_all_read'])) {
    try {
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false]);
    }
    exit();
}

// Fetch user's vehicles
try {
    $vehicleStmt = $db->prepare("SELECT id, brand, model, year, license_plate FROM vehicles WHERE user_id = ? ORDER BY brand, model");
    $vehicleStmt->execute([$_SESSION['user_id']]);
    $userVehicles = $vehicleStmt->fetchAll(PDO::FETCH_ASSOC);
    $totalVehicles = count($userVehicles);
} catch (PDOException $e) {
    $userVehicles = [];
    $totalVehicles = 0;
}

// Fetch active emergency requests
try {
    $activeStmt = $db->prepare("
        SELECT * FROM emergency_services 
        WHERE user_id = ? AND status IN ('pending', 'dispatched', 'arrived')
        ORDER BY created_at DESC
    ");
    $activeStmt->execute([$_SESSION['user_id']]);
    $activeEmergencies = $activeStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $activeEmergencies = [];
}

// Fetch emergency history
try {
    $historyStmt = $db->prepare("
        SELECT es.*, v.brand, v.model, v.license_plate 
        FROM emergency_services es
        JOIN vehicles v ON es.vehicle_id = v.id
        WHERE es.user_id = ? 
        ORDER BY es.created_at DESC
        LIMIT 10
    ");
    $historyStmt->execute([$_SESSION['user_id']]);
    $emergencyHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $emergencyHistory = [];
}

// Get unread notifications count
try {
    $notifCountStmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
    $notifCountStmt->execute([$_SESSION['user_id']]);
    $unreadNotifications = $notifCountStmt->fetch(PDO::FETCH_ASSOC)['count'];
} catch (PDOException $e) {
    $unreadNotifications = 0;
}

// Get recent notifications
try {
    $recentNotifStmt = $db->prepare("
        SELECT * FROM notifications 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $recentNotifStmt->execute([$_SESSION['user_id']]);
    $recentNotifications = $recentNotifStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recentNotifications = [];
}

// Get user details
try {
    $userStmt = $db->prepare("SELECT name, email, phone FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = ['name' => $_SESSION['user_name'] ?? 'User', 'email' => '', 'phone' => ''];
}

// Get greeting
$currentHour = (int)date('H');
$greeting = $currentHour < 12 ? 'Good Morning' : ($currentHour < 17 ? 'Good Afternoon' : 'Good Evening');

// Service types
$emergencyServices = [
    'Towing' => ['icon' => '🚛', 'desc' => 'Vehicle breakdown or accident', 'color' => '#dc2626'],
    'Flat Tire' => ['icon' => '🛞', 'desc' => 'Puncture or tire damage', 'color' => '#f59e0b'],
    'Battery Jump' => ['icon' => '🔋', 'desc' => 'Dead battery assistance', 'color' => '#10b981'],
    'Lockout' => ['icon' => '🔑', 'desc' => 'Keys locked inside vehicle', 'color' => '#3b82f6'],
    'Fuel Delivery' => ['icon' => '⛽', 'desc' => 'Ran out of fuel', 'color' => '#8b5cf6'],
    'Engine Failure' => ['icon' => '⚙️', 'desc' => 'Engine won\'t start', 'color' => '#ef4444'],
    'Brake Failure' => ['icon' => '🛑', 'desc' => 'Brake system issue', 'color' => '#b91c1c'],
    'Accident' => ['icon' => '💥', 'desc' => 'Vehicle involved in accident', 'color' => '#7f1d1d'],
    'Overheating' => ['icon' => '🌡️', 'desc' => 'Engine overheating', 'color' => '#f97316'],
    'Electrical' => ['icon' => '⚡', 'desc' => 'Electrical system failure', 'color' => '#fbbf24']
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CASMS | Emergency Services</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
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
            transition: background-color 0.3s, color 0.3s;
        }

        /* Dark mode styles */
        body.dark {
            background-color: #0f172a;
            color: #e2e8f0;
        }

        body.dark .sidebar {
            background-color: #1e293b;
            border-right: 1px solid #334155;
        }

        body.dark .navbar {
            background: #1e293b !important;
            border: 1px solid #334155 !important;
        }

        body.dark .navbar .greeting-badge {
            background: rgba(239, 68, 68, 0.2);
        }

        body.dark .stat-chip {
            background: #0f172a !important;
            color: #94a3b8 !important;
        }

        body.dark .status-message {
            background: #0f172a !important;
            border-left-color: #ef4444 !important;
        }

        body.dark .btn-icon {
            border-color: #334155 !important;
        }

        body.dark .btn-icon:hover {
            background: #0f172a !important;
        }

        body.dark .user-profile {
            background: #0f172a !important;
        }

        body.dark .user-profile:hover {
            background: #1e293b !important;
            border-color: #ef4444 !important;
        }

        body.dark .emergency-card {
            background: #1e293b;
            border-color: #334155;
        }

        body.dark .emergency-header {
            background: linear-gradient(135deg, #991b1b 0%, #7f1d1d 100%);
        }

        body.dark .service-option {
            background: #0f172a;
            border-color: #334155;
        }

        body.dark .service-option:hover {
            border-color: #ef4444;
        }

        body.dark .service-option.selected {
            background: #ef4444;
            border-color: #ef4444;
        }

        body.dark .active-emergency {
            background: #991b1b;
            border-color: #7f1d1d;
        }

        body.dark .timeline-item {
            border-color: #334155;
        }

        body.dark .dropdown-menu {
            background-color: #1e293b;
            border-color: #334155;
        }

        body.dark .dropdown-item {
            color: #e2e8f0;
        }

        body.dark .dropdown-item:hover {
            background-color: #0f172a;
        }

        body.dark .notification-item {
            border-color: #334155;
        }

        body.dark .notification-item:hover {
            background: #0f172a;
        }

        body.dark .notification-item.unread {
            background: #1e3a8a;
        }

        body.dark .map-container {
            background: #1e293b;
            border-color: #334155;
        }

        body.dark .image-preview {
            background: #0f172a;
            border-color: #334155;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 280px;
            background-color: #ffffff;
            border-right: 1px solid #e2e8f0;
            padding: 2rem 1.5rem;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: all 0.3s;
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
            background-color: #ef4444;
            color: #ffffff;
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
        }

        .navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.25rem 2rem;
            background: #ffffff;
            border-radius: 24px;
            margin-bottom: 2rem;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .navbar-left {
            flex: 1;
        }

        .welcome-section {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .welcome-header {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            flex-wrap: wrap;
        }

        .title-wrapper {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .greeting-badge {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #ef4444;
            background: rgba(239, 68, 68, 0.1);
            padding: 0.2rem 0.75rem;
            border-radius: 20px;
            display: inline-block;
            width: fit-content;
        }

        .welcome-title {
            font-size: 1.5rem;
            font-weight: 500;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .greeting-word {
            color: #64748b;
            font-weight: 400;
        }

        .user-name {
            font-weight: 700;
            color: #1e293b;
        }

        .quick-stats {
            display: flex;
            gap: 0.75rem;
        }

        .stat-chip {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.4rem 1rem;
            background: #f1f5f9;
            border-radius: 40px;
            font-size: 0.85rem;
            color: #475569;
        }

        .status-message {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.5rem 1rem;
            background: #f8fafc;
            border-radius: 12px;
            border-left: 3px solid #ef4444;
            margin-top: 0.25rem;
        }

        .status-icon {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            font-weight: 500;
            color: #475569;
        }

        .pulse-dot {
            width: 8px;
            height: 8px;
            background: #10b981;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        .emergency-pulse {
            width: 8px;
            height: 8px;
            background: #ef4444;
            border-radius: 50%;
            animation: emergencyPulse 1.5s infinite;
        }

        .navbar-right {
            display: flex;
            align-items: center;
            position: relative;
        }

        .action-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-icon {
            width: 44px;
            height: 44px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }

        .btn-icon:hover {
            background: #f1f5f9;
            transform: translateY(-2px);
            border-color: #ef4444;
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            width: 20px;
            height: 20px;
            background: #ef4444;
            color: white;
            font-size: 0.7rem;
            font-weight: 600;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid white;
            animation: bounce 1s infinite;
        }

        .mode-icon {
            font-size: 1.2rem;
        }

        .user-profile-container {
            position: relative;
            margin-left: 0.5rem;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.4rem 1rem 0.4rem 0.4rem;
            background: #f8fafc;
            border-radius: 40px;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid transparent;
        }

        .user-profile:hover {
            background: #f1f5f9;
            border-color: #ef4444;
        }

        .avatar {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .user-info {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
        }

        .user-info .user-name {
            font-size: 0.9rem;
            font-weight: 600;
            color: #1e293b;
        }

        .user-role {
            font-size: 0.7rem;
            color: #64748b;
        }

        .dropdown-icon {
            width: 18px;
            height: 18px;
            fill: #64748b;
            transition: transform 0.2s;
        }

        .user-profile.active .dropdown-icon {
            transform: rotate(180deg);
        }

        .dropdown-menu {
            position: absolute;
            top: calc(100% + 0.5rem);
            right: 0;
            width: 350px;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.2s;
            z-index: 1000;
        }

        .dropdown-menu.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .dropdown-header {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .dropdown-header .user-email {
            font-size: 0.8rem;
            color: #64748b;
            margin-top: 0.25rem;
        }

        .notifications-list {
            max-height: 300px;
            overflow-y: auto;
        }

        .notification-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #e2e8f0;
            transition: all 0.2s;
            cursor: pointer;
        }

        .notification-item:hover {
            background: #f8fafc;
        }

        .notification-item.unread {
            background: #eff6ff;
        }

        .notification-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .notification-icon.emergency {
            background: #fee2e2;
            color: #dc2626;
        }

        .notification-icon.info {
            background: #dbeafe;
            color: #2563eb;
        }

        .notification-icon.success {
            background: #d1fae5;
            color: #059669;
        }

        .notification-content {
            flex: 1;
        }

        .notification-title {
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .notification-time {
            font-size: 0.7rem;
            color: #64748b;
        }

        .dropdown-items {
            padding: 0.5rem;
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            color: #1e293b;
            text-decoration: none;
            border-radius: 12px;
            transition: all 0.2s;
            font-size: 0.9rem;
        }

        .dropdown-item:hover {
            background: #f1f5f9;
        }

        .dropdown-item.logout {
            color: #ef4444;
        }

        .dropdown-item.logout:hover {
            background: #fef2f2;
        }

        .dropdown-divider {
            height: 1px;
            background: #e2e8f0;
            margin: 0.5rem;
        }

        /* Emergency Cards */
        .emergency-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .emergency-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 32px;
            overflow: hidden;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }

        .emergency-header {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            padding: 2rem;
            color: white;
            text-align: center;
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
            font-size: 2.5rem;
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255, 255, 255, 0.3);
            animation: emergencyPulse 2s infinite;
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

        .emergency-body {
            padding: 2rem;
        }

        .service-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .service-option {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            padding: 1.25rem;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
        }

        .service-option:hover {
            border-color: #ef4444;
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(239, 68, 68, 0.2);
        }

        .service-option.selected {
            background: #ef4444;
            border-color: #ef4444;
            color: white;
        }

        .service-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .service-name {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #475569;
        }

        .form-label .required {
            color: #ef4444;
        }

        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-family: 'Inter', sans-serif;
            font-size: 0.95rem;
            transition: all 0.2s;
        }

        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            outline: none;
            border-color: #ef4444;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
        }

        .form-textarea {
            min-height: 100px;
            resize: vertical;
        }

        /* GPS Location Styles */
        .location-group {
            position: relative;
        }

        .location-input-wrapper {
            display: flex;
            gap: 0.5rem;
        }

        .location-input-wrapper .form-input {
            flex: 1;
        }

        .btn-location {
            padding: 0.75rem 1.5rem;
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            white-space: nowrap;
        }

        .btn-location:hover {
            background: #2563eb;
            transform: translateY(-2px);
        }

        .btn-location:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .map-container {
            margin-top: 1rem;
            height: 200px;
            border-radius: 12px;
            overflow: hidden;
            border: 2px solid #e2e8f0;
            display: none;
        }

        .map-container.show {
            display: block;
        }

        /* Image Upload Styles */
        .image-upload-container {
            border: 2px dashed #e2e8f0;
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            margin-bottom: 1rem;
        }

        .image-upload-container:hover {
            border-color: #ef4444;
            background: #fef2f2;
        }

        .image-upload-icon {
            font-size: 3rem;
            color: #ef4444;
            margin-bottom: 1rem;
        }

        .image-upload-text {
            color: #64748b;
            margin-bottom: 0.5rem;
        }

        .image-upload-hint {
            font-size: 0.75rem;
            color: #94a3b8;
        }

        .image-preview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .image-preview-item {
            position: relative;
            aspect-ratio: 1;
            border-radius: 8px;
            overflow: hidden;
            border: 2px solid #e2e8f0;
        }

        .image-preview-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .image-preview-remove {
            position: absolute;
            top: 4px;
            right: 4px;
            width: 20px;
            height: 20px;
            background: #ef4444;
            color: white;
            border: none;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 12px;
        }

        .btn-emergency {
            width: 100%;
            padding: 1rem;
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            border: none;
            border-radius: 16px;
            color: white;
            font-weight: 600;
            font-size: 1.125rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.3s;
            animation: emergencyPulse 2s infinite;
        }

        .btn-emergency:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(220, 38, 38, 0.5);
        }

        .btn-emergency:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            animation: none;
        }

        /* Active Emergency */
        .active-emergency {
            background: #fee2e2;
            border: 2px solid #fecaca;
            border-radius: 24px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .emergency-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1.5rem;
            background: #dc2626;
            color: white;
            border-radius: 40px;
            font-weight: 600;
            font-size: 0.875rem;
            margin-bottom: 1rem;
        }

        .emergency-timeline {
            position: relative;
            padding-left: 2rem;
        }

        .timeline-item {
            position: relative;
            padding-bottom: 1.5rem;
            border-left: 2px solid #e2e8f0;
            padding-left: 1.5rem;
        }

        .timeline-item:last-child {
            padding-bottom: 0;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -6px;
            top: 0;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #ef4444;
        }

        .timeline-item.completed::before {
            background: #10b981;
        }

        .timeline-item.pending::before {
            background: #f59e0b;
            animation: pulse 2s infinite;
        }

        .timeline-time {
            font-size: 0.75rem;
            color: #64748b;
            margin-bottom: 0.25rem;
        }

        .timeline-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .timeline-desc {
            font-size: 0.875rem;
            color: #64748b;
        }

        /* History Card */
        .history-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 24px;
            overflow: hidden;
        }

        .history-header {
            padding: 1.25rem 1.5rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .history-title {
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .history-list {
            padding: 1.5rem;
        }

        .history-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            margin-bottom: 1rem;
            transition: all 0.2s;
        }

        .history-item:hover {
            background: #f8fafc;
        }

        .history-icon {
            width: 48px;
            height: 48px;
            background: #fee2e2;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: #dc2626;
        }

        .history-details {
            flex: 1;
        }

        .history-service {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .history-vehicle {
            font-size: 0.875rem;
            color: #64748b;
            margin-bottom: 0.25rem;
        }

        .history-time {
            font-size: 0.75rem;
            color: #94a3b8;
        }

        .history-status {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .status-completed {
            background: #d1fae5;
            color: #065f46;
        }

        .status-cancelled {
            background: #fee2e2;
            color: #b91c1c;
        }

        .status-dispatched {
            background: #dbeafe;
            color: #1e40af;
        }

        /* Messages */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideDown 0.3s;
        }

        .alert-success {
            background: #f0fdf4;
            border: 1px solid #dcfce7;
            color: #166534;
        }

        .alert-error {
            background: #fef2f2;
            border: 1px solid #fee2e2;
            color: #b91c1c;
        }

        /* Animations */
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        @keyframes emergencyPulse {
            0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(239, 68, 68, 0); }
            100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }

        @keyframes bounce {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.2); }
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
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

        /* Responsive */
        @media (max-width: 1024px) {
            .emergency-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
                padding: 1rem;
            }
            
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            
            .service-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .emergency-header {
                padding: 1.5rem;
            }
            
            .emergency-title {
                font-size: 1.5rem;
            }
            
            .location-input-wrapper {
                flex-direction: column;
            }
        }

        @media (max-width: 480px) {
            .service-grid {
                grid-template-columns: 1fr;
            }
            
            .dropdown-menu {
                width: 300px;
                right: -50px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <h2>CASMS</h2>
            <nav>
                <a href="dashboard.php">Dashboard</a>
                <a href="vehicles.php">Vehicles</a>
                <a href="services.php">Services</a>
                <a href="book_service.php">Book Service</a>
                <a href="service_history.php">Service History</a>
                <a href="spareparts.php">Spare Parts</a>
                <a href="emergency_services.php" class="active">🚨 Emergency</a>
                <a href="profile.php">Profile</a>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Navbar with Notifications -->
            <div class="navbar">
                <div class="navbar-left">
                    <div class="welcome-section">
                        <div class="welcome-header">
                            <div class="title-wrapper">
                                <span class="greeting-badge">EMERGENCY SERVICES</span>
                                <h1 class="welcome-title">
                                    <span class="greeting-word"><?php echo htmlspecialchars($greeting); ?>,</span>
                                    <span class="user-name"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?></span>
                                </h1>
                            </div>
                            <div class="quick-stats">
                                <div class="stat-chip">
                                    <span class="chip-icon">📅</span>
                                    <span class="chip-text"><?php echo date('M d, Y'); ?></span>
                                </div>
                                <div class="stat-chip">
                                    <span class="chip-icon">⏰</span>
                                    <span class="chip-text" id="liveClock"><?php echo date('h:i:s A'); ?></span>
                                </div>
                                <div class="stat-chip">
                                    <span class="chip-icon">🚨</span>
                                    <span class="chip-text" id="activeEmergencyCount"><?php echo count($activeEmergencies); ?> Active</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="status-message">
                            <div class="status-icon">
                                <span class="emergency-pulse"></span>
                                <span>Emergency Status</span>
                            </div>
                            <div class="message-text">
                                <?php if(!empty($activeEmergencies)): ?>
                                    <span class="highlight" style="color: #dc2626;">Active emergency request</span> - Help is on the way
                                <?php else: ?>
                                    <span class="highlight">24/7 Emergency assistance</span> available for all vehicles
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="navbar-right">
                    <div class="action-group">
                        <button class="btn-icon" onclick="toggleMode()" title="Toggle Dark Mode">
                            <span class="mode-icon" id="modeIcon">🌙</span>
                        </button>
                        
                        <!-- Notifications Dropdown -->
                        <div class="user-profile-container">
                            <button class="btn-icon" onclick="toggleNotifications()" id="notificationBtn" title="Notifications">
                                <span class="notification-badge" id="notificationBadge"><?php echo $unreadNotifications; ?></span>
                                <i class="fas fa-bell"></i>
                            </button>
                            
                            <div class="dropdown-menu" id="notificationMenu">
                                <div class="dropdown-header">
                                    <div>
                                        <div class="user-name">Notifications</div>
                                        <div class="user-email" id="notificationCount"><?php echo $unreadNotifications; ?> unread</div>
                                    </div>
                                    <span class="mark-all-read" onclick="markAllAsRead()">Mark all read</span>
                                </div>
                                <div class="notifications-list" id="notificationsList">
                                    <?php if(empty($recentNotifications)): ?>
                                        <div style="text-align: center; padding: 2rem; color: #64748b;">
                                            <i class="fas fa-bell-slash" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                                            <p>No notifications</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach($recentNotifications as $notif): 
                                            $icon = $notif['type'] == 'emergency' ? 'fa-exclamation-triangle' : 
                                                   ($notif['type'] == 'success' ? 'fa-check-circle' : 
                                                   ($notif['type'] == 'warning' ? 'fa-exclamation-circle' : 'fa-info-circle'));
                                            $bgColor = $notif['type'] == 'emergency' ? '#fee2e2' : 
                                                      ($notif['type'] == 'success' ? '#d1fae5' : 
                                                      ($notif['type'] == 'warning' ? '#fef3c7' : '#dbeafe'));
                                            $textColor = $notif['type'] == 'emergency' ? '#b91c1c' : 
                                                        ($notif['type'] == 'success' ? '#065f46' : 
                                                        ($notif['type'] == 'warning' ? '#92400e' : '#1e40af'));
                                            $time = strtotime($notif['created_at']);
                                            $now = time();
                                            $diff = $now - $time;
                                            
                                            if($diff < 60) {
                                                $timeText = 'Just now';
                                            } elseif($diff < 3600) {
                                                $timeText = floor($diff / 60) . ' minutes ago';
                                            } elseif($diff < 86400) {
                                                $timeText = floor($diff / 3600) . ' hours ago';
                                            } else {
                                                $timeText = date('M j', $time);
                                            }
                                        ?>
                                            <div class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>" onclick="markAsRead(<?php echo $notif['id']; ?>)">
                                                <div class="notification-icon" style="background: <?php echo $bgColor; ?>; color: <?php echo $textColor; ?>;">
                                                    <i class="fas <?php echo $icon; ?>"></i>
                                                </div>
                                                <div class="notification-content">
                                                    <div class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                                    <div class="notification-message"><?php echo htmlspecialchars(substr($notif['message'], 0, 50)) . (strlen($notif['message']) > 50 ? '...' : ''); ?></div>
                                                    <div class="notification-time"><?php echo $timeText; ?></div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="dropdown-items">
                                    <a href="notifications.php" class="dropdown-item">
                                        <span class="item-icon">🔔</span>
                                        View All Notifications
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- User Profile -->
                        <div class="user-profile-container">
                            <div class="user-profile" onclick="toggleDropdown()" id="userProfile">
                                <div class="avatar">
                                    <span><?php echo strtoupper(substr(htmlspecialchars($_SESSION['user_name'] ?? 'U'), 0, 1)); ?></span>
                                </div>
                                <div class="user-info">
                                    <span class="user-name"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?></span>
                                    <span class="user-role"><?php echo ucfirst($user['role'] ?? 'User'); ?></span>
                                </div>
                                <svg class="dropdown-icon" viewBox="0 0 24 24">
                                    <path d="M7 10L12 15L17 10H7Z"/>
                                </svg>
                            </div>
                            
                            <div class="dropdown-menu" id="dropdownMenu" style="width: 240px;">
                                <div class="dropdown-header">
                                    <div class="user-name"><?php echo htmlspecialchars($user['name']); ?></div>
                                    <div class="user-email"><?php echo htmlspecialchars($user['email']); ?></div>
                                </div>
                                <div class="dropdown-items">
                                    <a href="profile.php" class="dropdown-item">
                                        <span class="item-icon">👤</span>
                                        My Profile
                                    </a>
                                    <div class="dropdown-divider"></div>
                                    <a href="logout.php" class="dropdown-item logout">
                                        <span class="item-icon">🚪</span>
                                        Logout
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Messages -->
            <?php if(!empty($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if(isset($_SESSION['emergency_success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['emergency_success']); unset($_SESSION['emergency_success']); ?>
                </div>
            <?php endif; ?>

            <!-- Active Emergency -->
            <?php if(!empty($activeEmergencies)): 
                $emergency = $activeEmergencies[0];
                $vehicle = null;
                foreach($userVehicles as $v) {
                    if($v['id'] == $emergency['vehicle_id']) {
                        $vehicle = $v;
                        break;
                    }
                }
            ?>
                <div class="active-emergency">
                    <div class="emergency-badge">
                        <span class="emergency-pulse"></span>
                        ACTIVE EMERGENCY REQUEST
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 1.5rem;">
                        <div>
                            <h3 style="margin-bottom: 1rem;">Request Details</h3>
                            <p><strong>Request ID:</strong> <?php echo htmlspecialchars($emergency['request_id']); ?></p>
                            <p><strong>Service:</strong> <?php echo htmlspecialchars($emergency['service_type']); ?></p>
                            <p><strong>Vehicle:</strong> <?php echo htmlspecialchars($vehicle['brand'] . ' ' . $vehicle['model'] . ' (' . $vehicle['license_plate'] . ')'); ?></p>
                            <p><strong>Location:</strong> <?php echo htmlspecialchars($emergency['location']); ?></p>
                            <?php if($emergency['coordinates']): ?>
                                <p><strong>Coordinates:</strong> <?php echo htmlspecialchars($emergency['coordinates']); ?></p>
                            <?php endif; ?>
                            <p><strong>Contact:</strong> <?php echo htmlspecialchars($emergency['contact_number']); ?></p>
                            
                            <?php if($emergency['vehicle_images']): 
                                $images = json_decode($emergency['vehicle_images'], true);
                            ?>
                                <div style="margin-top: 1rem;">
                                    <strong>Vehicle Images:</strong>
                                    <div style="display: flex; gap: 0.5rem; margin-top: 0.5rem; flex-wrap: wrap;">
                                        <?php foreach($images as $image): ?>
                                            <img src="<?php echo htmlspecialchars($image); ?>" alt="Vehicle" style="width: 80px; height: 80px; object-fit: cover; border-radius: 8px; border: 2px solid #e2e8f0;">
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <h3 style="margin-bottom: 1rem;">Estimated Arrival</h3>
                            <div style="font-size: 2rem; font-weight: 700; color: #dc2626; margin-bottom: 0.5rem;">
                                <i class="fas fa-clock"></i>
                                <span id="countdownTimer"></span>
                            </div>
                            <p><?php echo date('h:i A', strtotime($emergency['estimated_arrival'])); ?></p>
                            
                            <div style="margin-top: 1.5rem;">
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="emergency_id" value="<?php echo $emergency['id']; ?>">
                                    <button type="submit" name="cancel_emergency" class="btn-outline" style="border-color: #dc2626; color: #dc2626;" onclick="return confirm('Are you sure you want to cancel this emergency request?')">
                                        <i class="fas fa-times"></i> Cancel Request
                                    </button>
                                </form>
                            </div>

                            <?php if($emergency['coordinates']): ?>
                                <div id="activeEmergencyMap" style="height: 200px; margin-top: 1rem; border-radius: 12px; overflow: hidden;"></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <h3 style="margin: 1.5rem 0 1rem;">Status Timeline</h3>
                    <div class="emergency-timeline">
                        <div class="timeline-item completed">
                            <div class="timeline-time"><?php echo date('h:i A', strtotime($emergency['created_at'])); ?></div>
                            <div class="timeline-title">Emergency Requested</div>
                            <div class="timeline-desc">Your emergency request has been received</div>
                        </div>
                        <?php if($emergency['status'] == 'dispatched'): ?>
                            <div class="timeline-item pending">
                                <div class="timeline-time"><?php echo date('h:i A', strtotime($emergency['updated_at'] ?? $emergency['created_at'])); ?></div>
                                <div class="timeline-title">Service Dispatched</div>
                                <div class="timeline-desc">A service provider has been dispatched to your location</div>
                            </div>
                        <?php endif; ?>
                        <?php if($emergency['status'] == 'dispatched'): ?>
                            <div class="timeline-item pending">
                                <div class="timeline-time"><?php echo date('h:i A', strtotime($emergency['estimated_arrival'])); ?></div>
                                <div class="timeline-title">Estimated Arrival</div>
                                <div class="timeline-desc">Service provider expected to arrive</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Emergency Booking Form -->
            <div class="emergency-grid">
                <div class="emergency-card">
                    <div class="emergency-header">
                        <div class="emergency-icon">🚨</div>
                        <h1 class="emergency-title">Emergency Assistance</h1>
                        <p class="emergency-subtitle">24/7 Roadside Support • Immediate Response</p>
                    </div>

                    <div class="emergency-body">
                        <?php if(empty($userVehicles)): ?>
                            <div style="text-align: center; padding: 3rem;">
                                <div style="font-size: 4rem; margin-bottom: 1rem;">🚗</div>
                                <h3 style="margin-bottom: 1rem;">No Vehicles Found</h3>
                                <p style="color: #64748b; margin-bottom: 2rem;">You need to add a vehicle before requesting emergency service.</p>
                                <a href="vehicles.php" class="btn-primary" style="text-decoration: none;">Add Your First Vehicle</a>
                            </div>
                        <?php else: ?>
                            <form method="POST" id="emergencyForm" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                
                                <!-- Service Type Selection -->
                                <div style="margin-bottom: 2rem;">
                                    <h3 style="margin-bottom: 1rem;">Select Service Type</h3>
                                    <div class="service-grid" id="serviceGrid">
                                        <?php foreach($emergencyServices as $service => $details): ?>
                                            <div class="service-option" onclick="selectService(this, '<?php echo htmlspecialchars($service); ?>')" style="border-top: 4px solid <?php echo $details['color']; ?>;">
                                                <div class="service-icon"><?php echo $details['icon']; ?></div>
                                                <div class="service-name"><?php echo $service; ?></div>
                                                <div style="font-size: 0.7rem; color: #64748b;"><?php echo $details['desc']; ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <input type="hidden" name="service_type" id="selectedService" required>
                                </div>

                                <!-- Vehicle Selection -->
                                <div class="form-group">
                                    <label class="form-label">Select Vehicle <span class="required">*</span></label>
                                    <select name="vehicle_id" class="form-select" required>
                                        <option value="" disabled selected>Choose your vehicle</option>
                                        <?php foreach($userVehicles as $vehicle): ?>
                                            <option value="<?php echo $vehicle['id']; ?>">
                                                <?php echo htmlspecialchars($vehicle['brand'] . ' ' . $vehicle['model'] . ' (' . $vehicle['year'] . ') - ' . $vehicle['license_plate']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Contact Number -->
                                <div class="form-group">
                                    <label class="form-label">Contact Number <span class="required">*</span></label>
                                    <input type="tel" name="contact_number" class="form-input" 
                                           value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" 
                                           placeholder="Your phone number" required>
                                </div>

                                <!-- GPS Location -->
                                <div class="form-group location-group">
                                    <label class="form-label">Your Location <span class="required">*</span></label>
                                    <div class="location-input-wrapper">
                                        <input type="text" name="location" id="locationInput" class="form-input" 
                                               placeholder="Enter address or use GPS" required>
                                        <button type="button" class="btn-location" onclick="getCurrentLocation()" id="gpsBtn">
                                            <i class="fas fa-location-dot"></i> Use GPS
                                        </button>
                                    </div>
                                    <div id="locationMap" class="map-container"></div>
                                    <small style="color: #64748b; margin-top: 0.25rem; display: block;">
                                        <i class="fas fa-info-circle"></i> Click "Use GPS" to get your current location automatically
                                    </small>
                                </div>

                                <!-- Image Upload -->
                                <div class="form-group">
                                    <label class="form-label">Vehicle Images (Optional)</label>
                                    <div class="image-upload-container" onclick="document.getElementById('imageInput').click()">
                                        <div class="image-upload-icon">
                                            <i class="fas fa-camera"></i>
                                        </div>
                                        <div class="image-upload-text">Click to upload photos of your vehicle</div>
                                        <div class="image-upload-hint">JPG, PNG, GIF up to 5MB each (max 5 images)</div>
                                    </div>
                                    <input type="file" name="vehicle_images[]" id="imageInput" multiple accept="image/*" style="display: none;" onchange="previewImages(this)">
                                    <div id="imagePreview" class="image-preview-grid"></div>
                                </div>

                                <!-- Description -->
                                <div class="form-group">
                                    <label class="form-label">Description</label>
                                    <textarea name="description" class="form-textarea" 
                                              placeholder="Describe the issue in detail..."></textarea>
                                </div>

                                <!-- Urgency Level -->
                                <div class="form-group">
                                    <label class="form-label">Urgency Level</label>
                                    <select name="urgency_level" class="form-select">
                                        <option value="high">🔴 High - Immediate assistance needed</option>
                                        <option value="medium">🟡 Medium - Can wait 30 minutes</option>
                                        <option value="low">🟢 Low - Not urgent</option>
                                    </select>
                                </div>

                                <!-- Submit Button -->
                                <button type="submit" name="book_emergency" class="btn-emergency" id="emergencySubmitBtn" disabled>
                                    <i class="fas fa-exclamation-triangle"></i>
                                    REQUEST EMERGENCY ASSISTANCE
                                </button>

                                <p style="text-align: center; margin-top: 1rem; font-size: 0.875rem; color: #64748b;">
                                    <i class="fas fa-clock"></i> Estimated response time: 15-30 minutes
                                </p>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Emergency History -->
                <div>
                    <div class="history-card">
                        <div class="history-header">
                            <div class="history-title">
                                <i class="fas fa-history"></i>
                                Recent Emergency Requests
                            </div>
                            <span class="stat-chip"><?php echo count($emergencyHistory); ?> total</span>
                        </div>
                        
                        <div class="history-list">
                            <?php if(empty($emergencyHistory)): ?>
                                <div style="text-align: center; padding: 2rem; color: #64748b;">
                                    <i class="fas fa-clock" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                    <p>No emergency history found</p>
                                </div>
                            <?php else: ?>
                                <?php foreach($emergencyHistory as $history): ?>
                                    <div class="history-item">
                                        <div class="history-icon">
                                            <?php echo $emergencyServices[$history['service_type']]['icon'] ?? '🚨'; ?>
                                        </div>
                                        <div class="history-details">
                                            <div class="history-service"><?php echo htmlspecialchars($history['service_type']); ?></div>
                                            <div class="history-vehicle">
                                                <?php echo htmlspecialchars($history['brand'] . ' ' . $history['model']); ?>
                                            </div>
                                            <div class="history-time">
                                                <i class="far fa-calendar"></i> 
                                                <?php echo date('M j, Y h:i A', strtotime($history['created_at'])); ?>
                                            </div>
                                            <?php if($history['vehicle_images']): ?>
                                                <div style="margin-top: 0.5rem;">
                                                    <i class="fas fa-images"></i> Images attached
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <span class="history-status status-<?php echo $history['status']; ?>">
                                            <?php echo ucfirst($history['status']); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Emergency Tips -->
                    <div class="history-card" style="margin-top: 1.5rem;">
                        <div class="history-header">
                            <div class="history-title">
                                <i class="fas fa-lightbulb"></i>
                                Emergency Tips
                            </div>
                        </div>
                        <div style="padding: 1.5rem;">
                            <ul style="list-style: none; display: flex; flex-direction: column; gap: 1rem;">
                                <li style="display: flex; align-items: center; gap: 0.75rem;">
                                    <span style="width: 24px; height: 24px; background: #fee2e2; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #dc2626;">⚠️</span>
                                    <span>Stay with your vehicle and remain calm</span>
                                </li>
                                <li style="display: flex; align-items: center; gap: 0.75rem;">
                                    <span style="width: 24px; height: 24px; background: #dbeafe; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #2563eb;">📍</span>
                                    <span>Share your exact location using the GPS button</span>
                                </li>
                                <li style="display: flex; align-items: center; gap: 0.75rem;">
                                    <span style="width: 24px; height: 24px; background: #d1fae5; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #059669;">📞</span>
                                    <span>Keep your phone charged and accessible</span>
                                </li>
                                <li style="display: flex; align-items: center; gap: 0.75rem;">
                                    <span style="width: 24px; height: 24px; background: #fef3c7; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #d97706;">📷</span>
                                    <span>Take photos of your vehicle condition for documentation</span>
                                </li>
                                <li style="display: flex; align-items: center; gap: 0.75rem;">
                                    <span style="width: 24px; height: 24px; background: #fef3c7; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #d97706;">🚨</span>
                                    <span>Turn on hazard lights if safe to do so</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Real-time clock
        function updateClock() {
            const now = new Date();
            let hours = now.getHours();
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            const ampm = hours >= 12 ? 'PM' : 'AM';
            
            hours = hours % 12;
            hours = hours ? hours : 12;
            hours = String(hours).padStart(2, '0');
            
            document.getElementById('liveClock').textContent = `${hours}:${minutes}:${seconds} ${ampm}`;
        }
        setInterval(updateClock, 1000);

        // Notification auto-refresh
        let notificationCheckInterval;

        function initializeNotifications() {
            fetchNotifications();
            notificationCheckInterval = setInterval(fetchNotifications, 30000);
        }

        function fetchNotifications() {
            fetch('emergency_services.php?ajax=get_notifications')
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        const badge = document.getElementById('notificationBadge');
                        badge.textContent = data.unread_count;
                        
                        if(data.unread_count > 0) {
                            badge.style.display = 'flex';
                            badge.style.animation = 'bounce 1s infinite';
                        } else {
                            badge.style.display = 'none';
                        }

                        document.getElementById('notificationCount').textContent = data.unread_count + ' unread';
                        
                        if(data.active_emergency) {
                            document.getElementById('activeEmergencyCount').textContent = '1 Active';
                        } else {
                            document.getElementById('activeEmergencyCount').textContent = '0 Active';
                        }
                    }
                });
        }

        function markAsRead(id) {
            fetch('emergency_services.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'mark_read=1&notification_id=' + id
            })
            .then(response => response.json())
            .then(data => {
                if(data.success) {
                    fetchNotifications();
                }
            });
        }

        function markAllAsRead() {
            fetch('emergency_services.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'mark_all_read=1'
            })
            .then(response => response.json())
            .then(data => {
                if(data.success) {
                    fetchNotifications();
                }
            });
        }

        function toggleNotifications() {
            document.getElementById('notificationMenu').classList.toggle('show');
        }

        // GPS Location Functions
        let map = null;
        let marker = null;
        let activeEmergencyMap = null;

        function getCurrentLocation() {
            const gpsBtn = document.getElementById('gpsBtn');
            gpsBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Getting location...';
            gpsBtn.disabled = true;

            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        const lat = position.coords.latitude;
                        const lng = position.coords.longitude;
                        const locationStr = lat + ', ' + lng;
                        
                        document.getElementById('locationInput').value = locationStr;
                        
                        // Show map
                        const mapContainer = document.getElementById('locationMap');
                        mapContainer.classList.add('show');
                        
                        if (!map) {
                            map = L.map('locationMap').setView([lat, lng], 15);
                            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                                attribution: '© OpenStreetMap contributors'
                            }).addTo(map);
                        } else {
                            map.setView([lat, lng], 15);
                        }
                        
                        if (marker) {
                            marker.setLatLng([lat, lng]);
                        } else {
                            marker = L.marker([lat, lng]).addTo(map);
                        }
                        
                        marker.bindPopup('Your location').openPopup();
                        
                        gpsBtn.innerHTML = '<i class="fas fa-location-dot"></i> Update GPS';
                        gpsBtn.disabled = false;
                        
                        checkFormValidity();
                    },
                    function(error) {
                        let errorMessage = 'Unable to get your location. ';
                        switch(error.code) {
                            case error.PERMISSION_DENIED:
                                errorMessage += 'Please enable location access.';
                                break;
                            case error.POSITION_UNAVAILABLE:
                                errorMessage += 'Location information is unavailable.';
                                break;
                            case error.TIMEOUT:
                                errorMessage += 'Location request timed out.';
                                break;
                        }
                        alert(errorMessage);
                        gpsBtn.innerHTML = '<i class="fas fa-location-dot"></i> Use GPS';
                        gpsBtn.disabled = false;
                    }
                );
            } else {
                alert('Geolocation is not supported by your browser');
                gpsBtn.innerHTML = '<i class="fas fa-location-dot"></i> Use GPS';
                gpsBtn.disabled = false;
            }
        }

        // Image preview
        function previewImages(input) {
            const preview = document.getElementById('imagePreview');
            preview.innerHTML = '';
            
            if (input.files) {
                const files = Array.from(input.files);
                
                if (files.length > 5) {
                    alert('You can only upload up to 5 images');
                    input.value = '';
                    return;
                }
                
                files.forEach((file, index) => {
                    if (file.size > 5 * 1024 * 1024) {
                        alert('File ' + file.name + ' is too large. Max size is 5MB');
                        return;
                    }
                    
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const previewItem = document.createElement('div');
                        previewItem.className = 'image-preview-item';
                        previewItem.innerHTML = `
                            <img src="${e.target.result}" alt="Preview ${index + 1}">
                            <button class="image-preview-remove" onclick="removeImage(this, ${index})">×</button>
                        `;
                        preview.appendChild(previewItem);
                    };
                    reader.readAsDataURL(file);
                });
            }
        }

        function removeImage(button, index) {
            button.closest('.image-preview-item').remove();
            // Note: This doesn't remove from the actual file input
            // You might want to handle this differently in production
        }

        // Service selection
        function selectService(element, serviceName) {
            document.querySelectorAll('.service-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            
            element.classList.add('selected');
            document.getElementById('selectedService').value = serviceName;
            
            checkFormValidity();
        }

        // Check form validity
        function checkFormValidity() {
            const vehicle = document.querySelector('select[name="vehicle_id"]')?.value;
            const service = document.getElementById('selectedService')?.value;
            const contact = document.querySelector('input[name="contact_number"]')?.value;
            const location = document.querySelector('input[name="location"]')?.value;
            const submitBtn = document.getElementById('emergencySubmitBtn');
            
            if(vehicle && service && contact && location) {
                submitBtn.disabled = false;
            } else {
                submitBtn.disabled = true;
            }
        }

        // Add event listeners for form validation
        document.querySelector('select[name="vehicle_id"]')?.addEventListener('change', checkFormValidity);
        document.querySelector('input[name="contact_number"]')?.addEventListener('input', checkFormValidity);
        document.querySelector('input[name="location"]')?.addEventListener('input', checkFormValidity);

        // Countdown timer for active emergency
        <?php if(!empty($activeEmergencies)): ?>
        function updateCountdown() {
            const arrivalTime = new Date('<?php echo $emergency['estimated_arrival']; ?>').getTime();
            const now = new Date().getTime();
            const distance = arrivalTime - now;

            if(distance < 0) {
                document.getElementById('countdownTimer').innerHTML = 'Arrived';
                return;
            }

            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);

            document.getElementById('countdownTimer').innerHTML = `${minutes}:${seconds.toString().padStart(2, '0')}`;
        }

        updateCountdown();
        setInterval(updateCountdown, 1000);

        // Initialize active emergency map if coordinates exist
        <?php if($emergency['coordinates']): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const coords = '<?php echo $emergency['coordinates']; ?>'.split(',');
            const lat = parseFloat(coords[0]);
            const lng = parseFloat(coords[1]);
            
            activeEmergencyMap = L.map('activeEmergencyMap').setView([lat, lng], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(activeEmergencyMap);
            
            L.marker([lat, lng]).addTo(activeEmergencyMap)
                .bindPopup('Your location')
                .openPopup();
        });
        <?php endif; ?>
        <?php endif; ?>

        // Dropdown
        function toggleDropdown() {
            document.getElementById('dropdownMenu').classList.toggle('show');
            document.getElementById('userProfile').classList.toggle('active');
        }

        window.addEventListener('click', function(e) {
            const container = document.querySelector('.user-profile-container:last-child');
            const dropdown = document.getElementById('dropdownMenu');
            const profile = document.getElementById('userProfile');
            
            if (container && !container.contains(e.target)) {
                dropdown.classList.remove('show');
                profile.classList.remove('active');
            }
        });

        // Close notifications when clicking outside
        window.addEventListener('click', function(e) {
            const btn = document.getElementById('notificationBtn');
            const menu = document.getElementById('notificationMenu');
            
            if (btn && menu && !btn.contains(e.target) && !menu.contains(e.target)) {
                menu.classList.remove('show');
            }
        });

        // Dark mode
        function toggleMode() {
            document.body.classList.toggle('dark');
            const modeIcon = document.getElementById('modeIcon');
            modeIcon.textContent = document.body.classList.contains('dark') ? '☀️' : '🌙';
            localStorage.setItem('darkMode', document.body.classList.contains('dark'));
        }

        if (localStorage.getItem('darkMode') === 'true') {
            document.body.classList.add('dark');
            document.getElementById('modeIcon').textContent = '☀️';
        }

        // Initialize notifications on page load
        document.addEventListener('DOMContentLoaded', initializeNotifications);

        // Clean up interval on page unload
        window.addEventListener('beforeunload', function() {
            if(notificationCheckInterval) {
                clearInterval(notificationCheckInterval);
            }
        });
    </script>
</body>
</html>