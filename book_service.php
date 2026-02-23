<?php
session_start();
if(!isset($_SESSION['user_id'])){
    header("Location: index.php");
    exit();
}

require_once "database.php";

// Configuration constants
define('TAX_RATE', 0.10); // 10% tax
define('MIN_HOUR', 9); // 9 AM
define('MAX_HOUR', 17); // 5 PM

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$db = (new Database())->connect();
$error = '';
$success = '';

// Handle form submission
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_service'])){
    try {
        // Validate CSRF token
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid security token. Please try again.");
        }

        // Validate required fields
        if(empty($_POST['vehicle_id']) || empty($_POST['service_type']) || empty($_POST['appointment_date'])) {
            throw new Exception("Please fill in all required fields");
        }

        // Validate appointment date (can't be in the past)
        $appointmentDate = $_POST['appointment_date'];
        $today = date('Y-m-d');
        if ($appointmentDate < $today) {
            throw new Exception("Appointment date cannot be in the past");
        }

        // Begin transaction
        $db->beginTransaction();

        // Check if vehicle belongs to user
        $checkStmt = $db->prepare("SELECT id, brand, model, license_plate FROM vehicles WHERE id = ? AND user_id = ?");
        $checkStmt->execute([$_POST['vehicle_id'], $_SESSION['user_id']]);
        $vehicle = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if(!$vehicle) {
            throw new Exception("Invalid vehicle selected");
        }

        // Insert booking
        $stmt = $db->prepare("INSERT INTO book_service 
            (user_id, vehicle_id, service_type, appointment_date, notes, status, created_at)
            VALUES (:user_id, :vehicle_id, :service_type, :appointment_date, :notes, :status, NOW())");

        $result = $stmt->execute([
            ":user_id" => $_SESSION['user_id'],
            ":vehicle_id" => $_POST['vehicle_id'],
            ":service_type" => $_POST['service_type'],
            ":appointment_date" => $appointmentDate,
            ":notes" => !empty($_POST['notes']) ? trim($_POST['notes']) : null,
            ":status" => 'Pending'
        ]);

        $bookingId = $db->lastInsertId();

        if($result) {
            // Create notification for successful booking
            $notifStmt = $db->prepare("
                INSERT INTO notifications (user_id, type, title, message, data, created_at)
                VALUES (?, 'info', 'Service Booked', ?, ?, NOW())
            ");
            
            $vehicleName = $vehicle['brand'] . ' ' . $vehicle['model'] . ' (' . $vehicle['license_plate'] . ')';
            $formattedDate = date('F j, Y', strtotime($appointmentDate));
            $message = "Your " . $_POST['service_type'] . " service for " . $vehicleName . " has been booked for " . $formattedDate . ".";
            $data = json_encode([
                'booking_id' => $bookingId,
                'vehicle_id' => $_POST['vehicle_id'],
                'service_type' => $_POST['service_type'],
                'appointment_date' => $appointmentDate
            ]);
            
            $notifStmt->execute([$_SESSION['user_id'], $message, $data]);
            
            $db->commit();
            $_SESSION['success'] = "Appointment booked successfully!";
            header("Location: service_history.php");
            exit();
        } else {
            throw new Exception("Failed to book appointment");
        }
    } catch (Exception $e) {
        $db->rollBack();
        $error = $e->getMessage();
        error_log("Booking error: " . $e->getMessage() . " | User ID: " . $_SESSION['user_id']);
    } catch (PDOException $e) {
        $db->rollBack();
        $error = "Database error. Please try again.";
        error_log("PDO Booking error: " . $e->getMessage());
    }
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
    error_log("Error fetching vehicles: " . $e->getMessage());
}

// Fetch available services (with fallback)
try {
    // Check if services table exists
    $tableCheck = $db->query("SHOW TABLES LIKE 'services'");
    if ($tableCheck->rowCount() > 0) {
        $servicesStmt = $db->query("SELECT service_name, price FROM services ORDER BY service_name");
        $availableServices = $servicesStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $availableServices = [];
    }
} catch (PDOException $e) {
    $availableServices = [];
    error_log("Error fetching services: " . $e->getMessage());
}

// Get user details
try {
    $userStmt = $db->prepare("SELECT name, email FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = ['name' => $_SESSION['user_name'] ?? 'User', 'email' => ''];
    error_log("Error fetching user: " . $e->getMessage());
}

// Get unread notifications count for the bell icon
try {
    $notifCountStmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
    $notifCountStmt->execute([$_SESSION['user_id']]);
    $unreadNotifications = $notifCountStmt->fetch(PDO::FETCH_ASSOC)['count'];
} catch (PDOException $e) {
    $unreadNotifications = 0;
    error_log("Error fetching notification count: " . $e->getMessage());
}

// Get recent notifications for dropdown
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
    error_log("Error fetching recent notifications: " . $e->getMessage());
}

// Get greeting based on time
$currentHour = (int)date('H');
$greeting = $currentHour < 12 ? 'Good Morning' : ($currentHour < 17 ? 'Good Afternoon' : 'Good Evening');

// Default services (used as fallback)
$defaultServices = [
    'Oil Change' => 49.99,
    'Brake Repair' => 129.99,
    'Engine Check' => 89.99,
    'Tire Rotation' => 39.99,
    'AC Service' => 79.99,
    'Battery Check' => 29.99,
    'Wheel Alignment' => 69.99,
    'Transmission Service' => 199.99,
    'Coolant Flush' => 89.99,
    'Spark Plug Replacement' => 79.99,
    'Timing Belt Replacement' => 399.99,
    'Fuel System Cleaning' => 129.99,
    'Suspension Check' => 49.99,
    'Headlight Restoration' => 59.99,
    'Full Car Detailing' => 199.99
];

// Service icons mapping
$serviceIcons = [
    'Oil Change' => '🛢️',
    'Brake Repair' => '🔨',
    'Engine Check' => '⚙️',
    'Tire Rotation' => '🔄',
    'AC Service' => '❄️',
    'Battery Check' => '🔋',
    'Wheel Alignment' => '📐',
    'Transmission Service' => '⚙️',
    'Coolant Flush' => '🌡️',
    'Spark Plug Replacement' => '⚡',
    'Timing Belt Replacement' => '⏱️',
    'Fuel System Cleaning' => '⛽',
    'Suspension Check' => '🔧',
    'Headlight Restoration' => '💡',
    'Full Car Detailing' => '🧼'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CASMS | Book Service Appointment</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Your existing CSS remains exactly the same */
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
            background: rgba(37, 99, 235, 0.2);
        }

        body.dark .stat-chip {
            background: #0f172a !important;
            color: #94a3b8 !important;
        }

        body.dark .status-message {
            background: #0f172a !important;
            border-left-color: #2563eb !important;
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
            border-color: #2563eb !important;
        }

        body.dark .booking-card {
            background: #1e293b;
            border-color: #334155;
        }

        body.dark .booking-header {
            background: linear-gradient(135deg, #1e40af 0%, #1e3a8a 100%);
        }

        body.dark .form-section {
            border-color: #334155;
        }

        body.dark .section-label {
            color: #f1f5f9;
        }

        body.dark .service-option {
            background: #0f172a;
            border-color: #334155;
            color: #e2e8f0;
        }

        body.dark .service-option:hover {
            border-color: #2563eb;
        }

        body.dark .service-option.selected {
            background: #2563eb;
            border-color: #2563eb;
        }

        body.dark .service-option.selected .service-price {
            color: white;
        }

        body.dark .service-price {
            color: #2563eb;
        }

        body.dark .vehicle-select {
            background: #0f172a;
            border-color: #334155;
            color: #f1f5f9;
        }

        body.dark .date-input {
            background: #0f172a;
            border-color: #334155;
            color: #f1f5f9;
        }

        body.dark .time-slot {
            background: #0f172a;
            border-color: #334155;
            color: #94a3b8;
        }

        body.dark .time-slot.selected {
            background: #2563eb;
            color: white;
        }

        body.dark .notes-input {
            background: #0f172a;
            border-color: #334155;
            color: #f1f5f9;
        }

        body.dark .price-summary {
            background: #0f172a;
        }

        body.dark .summary-row {
            color: #94a3b8;
        }

        body.dark .summary-row.total {
            color: #f1f5f9;
        }

        body.dark .back-section {
            background: #0f172a;
            border-color: #334155;
        }

        body.dark .btn-back {
            border-color: #334155;
            color: #94a3b8;
        }

        body.dark .btn-back:hover {
            background: #1e293b;
            border-color: #2563eb;
            color: #2563eb;
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

        body.dark .notification-item.unread:hover {
            background: #1e40af;
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
            background-color: #2563eb;
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
            color: #2563eb;
            background: rgba(37, 99, 235, 0.1);
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
            border-left: 3px solid #2563eb;
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
            border-color: #2563eb;
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
            border-color: #2563eb;
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
            max-height: 400px;
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

        .notification-item.unread:hover {
            background: #dbeafe;
        }

        .notification-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .notification-content {
            flex: 1;
            min-width: 0;
        }

        .notification-title {
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .notification-message {
            font-size: 0.8rem;
            color: #64748b;
            margin-bottom: 0.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .notification-time {
            font-size: 0.7rem;
            color: #94a3b8;
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

        .item-icon {
            font-size: 1.1rem;
        }

        .booking-card {
            background: white;
            border-radius: 32px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            animation: slideUp 0.5s ease;
        }

        .booking-header {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            padding: 2.5rem;
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .booking-header::before {
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

        .booking-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            position: relative;
        }

        .booking-subtitle {
            font-size: 1rem;
            opacity: 0.9;
            position: relative;
        }

        .booking-form {
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

        .section-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 1.25rem;
            font-size: 1rem;
        }

        .label-icon {
            font-size: 1.25rem;
        }

        .service-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
        }

        .service-option {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            padding: 1.25rem;
            cursor: pointer;
            transition: all 0.3s;
        }

        .service-option:hover {
            border-color: #2563eb;
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(37, 99, 235, 0.2);
        }

        .service-option.selected {
            background: #2563eb;
            border-color: #2563eb;
            color: white;
        }

        .service-option.selected .service-price {
            color: white;
        }

        .service-icon {
            font-size: 2rem;
            margin-bottom: 0.75rem;
        }

        .service-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .service-price {
            font-weight: 700;
            color: #2563eb;
        }

        .vehicle-select {
            width: 100%;
            padding: 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            font-family: 'Inter', sans-serif;
            font-size: 1rem;
            background: white;
            cursor: pointer;
            margin-bottom: 1rem;
        }

        .vehicle-select:focus {
            outline: none;
            border-color: #2563eb;
        }

        .no-vehicles-message {
            text-align: center;
            padding: 3rem;
            background: #f8fafc;
            border-radius: 16px;
        }

        .no-vehicles-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        .add-vehicle-btn {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background: #2563eb;
            color: white;
            text-decoration: none;
            border-radius: 40px;
            font-weight: 500;
            margin-top: 1rem;
        }

        .vehicle-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.75rem;
            background: #dbeafe;
            color: #1e40af;
            border-radius: 20px;
            font-size: 0.875rem;
        }

        .date-input-wrapper {
            position: relative;
            margin-bottom: 1rem;
        }

        .date-input {
            width: 100%;
            padding: 1rem 1rem 1rem 3rem;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            font-family: 'Inter', sans-serif;
            font-size: 1rem;
        }

        .date-input:focus {
            outline: none;
            border-color: #2563eb;
        }

        .date-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.25rem;
        }

        .notes-input {
            width: 100%;
            padding: 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            font-family: 'Inter', sans-serif;
            font-size: 0.875rem;
            resize: vertical;
            min-height: 100px;
        }

        .notes-input:focus {
            outline: none;
            border-color: #2563eb;
        }

        .price-summary {
            background: #f8fafc;
            border-radius: 16px;
            padding: 1.5rem;
            margin: 2rem 0;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            color: #64748b;
        }

        .summary-row.total {
            border-top: 2px dashed #cbd5e1;
            margin-top: 0.5rem;
            padding-top: 1rem;
            font-weight: 700;
            color: #1e293b;
            font-size: 1.125rem;
        }

        .total-price {
            color: #2563eb;
        }

        .submit-btn {
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
            transition: all 0.3s;
            font-family: 'Inter', sans-serif;
        }

        .submit-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(37, 99, 235, 0.5);
        }

        .submit-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .back-section {
            padding: 1.5rem 2rem;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            text-align: center;
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.875rem 2rem;
            background: transparent;
            border: 2px solid #e2e8f0;
            border-radius: 40px;
            color: #475569;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-back:hover {
            background: white;
            border-color: #2563eb;
            color: #2563eb;
        }

        .error-message {
            margin: 1rem 2rem 0;
            padding: 1rem;
            background: #fef2f2;
            border: 1px solid #fee2e2;
            border-radius: 12px;
            color: #b91c1c;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .error-message::before {
            content: '⚠️';
            font-size: 1.1rem;
        }

        .success-message {
            margin: 1rem 2rem 0;
            padding: 1rem;
            background: #f0fdf4;
            border: 1px solid #dcfce7;
            border-radius: 12px;
            color: #166534;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .success-message::before {
            content: '✅';
            font-size: 1.1rem;
        }

        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        @keyframes bounce {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.2); }
        }

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
                grid-template-columns: 1fr;
            }
            
            .booking-header {
                padding: 1.5rem;
            }
            
            .booking-title {
                font-size: 1.5rem;
            }
            
            .dropdown-menu {
                width: 300px;
                right: -50px;
            }
        }

        @media (max-width: 480px) {
            .dropdown-menu {
                width: 280px;
                right: -80px;
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
                <a href="book_service.php" class="active">Book Service</a>
                <a href="service_history.php">Service History</a>
                <a href="spare_parts.php">Spare Parts</a>
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
                                <span class="greeting-badge">BOOK SERVICE</span>
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
                                    <span class="chip-icon">🚗</span>
                                    <span class="chip-text"><?php echo $totalVehicles; ?> Vehicles</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="status-message">
                            <div class="status-icon">
                                <span class="pulse-dot"></span>
                                <span>Booking Status</span>
                            </div>
                            <div class="message-text">
                                <span class="highlight">Select your vehicle</span> and preferred service
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="navbar-right">
                    <div class="action-group">
                        <button class="btn-icon" onclick="toggleMode()" title="Toggle Dark Mode" aria-label="Toggle dark mode">
                            <span class="mode-icon" id="modeIcon">🌙</span>
                        </button>
                        
                        <!-- Notification Bell with Dropdown -->
                        <div class="user-profile-container">
                            <button class="btn-icon" onclick="toggleNotifications()" id="notificationBtn" title="Notifications">
                                <span class="notification-badge" id="notificationBadge" style="<?php echo $unreadNotifications > 0 ? 'display: flex;' : 'display: none;'; ?>">
                                    <?php echo $unreadNotifications; ?>
                                </span>
                                <i class="fas fa-bell"></i>
                            </button>
                            
                            <div class="dropdown-menu" id="notificationMenu">
                                <div class="dropdown-header">
                                    <div>
                                        <div class="user-name">Notifications</div>
                                        <div class="user-email" id="notificationCount"><?php echo $unreadNotifications; ?> unread</div>
                                    </div>
                                    <a href="notifications.php" style="font-size: 0.75rem; color: #2563eb;">View All</a>
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
                                            <div class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>" onclick="window.location.href='notifications.php?id=<?php echo $notif['id']; ?>'">
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
                            <div class="user-profile" onclick="toggleDropdown()" id="userProfile" aria-haspopup="true" aria-expanded="false">
                                <div class="avatar">
                                    <span><?php echo strtoupper(substr(htmlspecialchars($_SESSION['user_name'] ?? 'U'), 0, 1)); ?></span>
                                </div>
                                <div class="user-info">
                                    <span class="user-name"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?></span>
                                    <span class="user-role">Garage Manager</span>
                                </div>
                                <svg class="dropdown-icon" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                    <path d="M7 10L12 15L17 10H7Z"/>
                                </svg>
                            </div>
                            
                            <div class="dropdown-menu" id="dropdownMenu" role="menu" style="width: 240px;">
                                <div class="dropdown-header">
                                    <div class="user-name"><?php echo htmlspecialchars($user['name'] ?? 'User'); ?></div>
                                    <div class="user-email"><?php echo htmlspecialchars($user['email'] ?? 'user@example.com'); ?></div>
                                </div>
                                <div class="dropdown-items">
                                    <a href="profile.php" class="dropdown-item" role="menuitem">
                                        <span class="item-icon" aria-hidden="true">👤</span>
                                        My Profile
                                    </a>
                                    <div class="dropdown-divider"></div>
                                    <a href="logout.php" class="dropdown-item logout" role="menuitem">
                                        <span class="item-icon" aria-hidden="true">🚪</span>
                                        Logout
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Booking Card -->
            <div class="booking-card">
                <div class="booking-header">
                    <div class="header-icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M20 3h-1V1h-2v2H7V1H5v2H4c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 18H4V8h16v13z"/>
                            <path d="M7 10h5v2H7zm0 4h10v2H7zm0 4h10v2H7z"/>
                        </svg>
                    </div>
                    <h1 class="booking-title">Book Your Service</h1>
                    <p class="booking-subtitle">Schedule your vehicle maintenance appointment</p>
                </div>

                <?php if(isset($error) && !empty($error)): ?>
                    <div class="error-message" role="alert">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if(isset($_SESSION['success'])): ?>
                    <div class="success-message" role="alert">
                        <?php 
                        echo htmlspecialchars($_SESSION['success']); 
                        unset($_SESSION['success']);
                        ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="booking-form" id="bookingForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    
                    <!-- Service Selection -->
                    <div class="form-section">
                        <label class="section-label" id="service-label">
                            <span class="label-icon" aria-hidden="true">🔧</span>
                            Select Service Type <span class="required" aria-hidden="true">*</span>
                        </label>
                        
                        <div class="service-grid" id="serviceGrid" role="radiogroup" aria-labelledby="service-label">
                            <?php
                            $serviceList = !empty($availableServices) ? array_column($availableServices, 'service_name') : array_keys($defaultServices);
                            foreach($serviceList as $index => $service): 
                                $price = !empty($availableServices) && isset($availableServices[$index]['price']) 
                                    ? (float)$availableServices[$index]['price'] 
                                    : (float)($defaultServices[$service] ?? 49.99);
                                $icon = $serviceIcons[$service] ?? '🔧';
                            ?>
                                <div class="service-option" 
                                     onclick="selectService(this, '<?php echo htmlspecialchars($service); ?>', <?php echo $price; ?>)"
                                     role="radio"
                                     aria-checked="false"
                                     tabindex="0"
                                     onkeydown="if(event.key==='Enter'||event.key===' ') selectService(this, '<?php echo htmlspecialchars($service); ?>', <?php echo $price; ?>)">
                                    <div class="service-icon" aria-hidden="true"><?php echo $icon; ?></div>
                                    <div class="service-name"><?php echo htmlspecialchars($service); ?></div>
                                    <div class="service-price">Ksh <?php echo number_format($price, 2); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="service_type" id="selectedService" required>
                    </div>

                    <!-- Vehicle Selection -->
                    <div class="form-section">
                        <label class="section-label" for="vehicleSelect" id="vehicle-label">
                            <span class="label-icon" aria-hidden="true">🚗</span>
                            Select Vehicle <span class="required" aria-hidden="true">*</span>
                        </label>
                        
                        <?php if(empty($userVehicles)): ?>
                            <div class="no-vehicles-message">
                                <div class="no-vehicles-icon" aria-hidden="true">🚗</div>
                                <h3>No Vehicles Found</h3>
                                <p>You need to add a vehicle before booking a service.</p>
                                <a href="vehicles.php" class="add-vehicle-btn">Add Your First Vehicle</a>
                            </div>
                        <?php else: ?>
                            <select name="vehicle_id" id="vehicleSelect" class="vehicle-select" required aria-labelledby="vehicle-label">
                                <option value="" disabled selected>Choose your vehicle</option>
                                <?php foreach($userVehicles as $vehicle): ?>
                                    <option value="<?php echo (int)$vehicle['id']; ?>" <?php echo (isset($_POST['vehicle_id']) && $_POST['vehicle_id'] == $vehicle['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($vehicle['brand'] . ' ' . $vehicle['model'] . ' (' . $vehicle['year'] . ') - ' . $vehicle['license_plate']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <div class="vehicle-badge">
                                <span aria-hidden="true">📋</span> <?php echo $totalVehicles; ?> vehicle(s) registered
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Date Selection -->
                    <div class="form-section">
                        <label class="section-label" for="appointmentDate" id="date-label">
                            <span class="label-icon" aria-hidden="true">📅</span>
                            Select Appointment Date <span class="required" aria-hidden="true">*</span>
                        </label>
                        
                        <div class="date-input-wrapper">
                            <span class="date-icon" aria-hidden="true">📆</span>
                            <input type="date" 
                                   name="appointment_date" 
                                   id="appointmentDate"
                                   class="date-input" 
                                   required 
                                   min="<?php echo date('Y-m-d'); ?>"
                                   value="<?php echo isset($_POST['appointment_date']) ? htmlspecialchars($_POST['appointment_date']) : date('Y-m-d', strtotime('+1 day')); ?>"
                                   aria-labelledby="date-label">
                        </div>
                        
                        <p style="font-size: 0.875rem; color: #64748b; margin-top: 0.5rem;">
                            <span aria-hidden="true">⏰</span> Please arrive between <?php echo MIN_HOUR; ?>:00 AM - <?php echo MAX_HOUR; ?>:00 PM on your selected date
                        </p>
                    </div>

                    <!-- Additional Notes -->
                    <div class="form-section">
                        <label class="section-label" for="notes">
                            <span class="label-icon" aria-hidden="true">📝</span>
                            Additional Notes (Optional)
                        </label>
                        
                        <textarea name="notes" 
                                  id="notes"
                                  class="notes-input" 
                                  placeholder="Any specific issues or requests? (e.g., unusual noises, specific concerns, etc.)"
                                  aria-describedby="notes-help"><?php echo isset($_POST['notes']) ? htmlspecialchars($_POST['notes']) : ''; ?></textarea>
                        <small id="notes-help" style="display: none;">Optional: Add any specific details about your service needs</small>
                    </div>

                    <!-- Price Summary -->
                    <div class="price-summary" aria-live="polite" aria-atomic="true">
                        <div class="summary-row">
                            <span>Service Charge</span>
                            <span class="service-price-display">Ksh 0.00</span>
                        </div>
                        <div class="summary-row">
                            <span>Tax (<?php echo TAX_RATE * 100; ?>%)</span>
                            <span class="tax-display">Ksh 0.00</span>
                        </div>
                        <div class="summary-row total">
                            <span>Total Amount</span>
                            <span class="total-price">Ksh 0.00</span>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <button type="submit" name="book_service" class="submit-btn" id="submitBtn" disabled>
                        <span class="btn-text">Confirm Booking</span>
                        <span class="btn-arrow" aria-hidden="true">→</span>
                    </button>
                </form>

                <!-- Back Button -->
                <div class="back-section">
                    <a href="dashboard.php" class="btn-back">
                        <span class="back-icon" aria-hidden="true">←</span>
                        Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Real-time clock update
        function updateClock() {
            const now = new Date();
            let hours = now.getHours();
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            const ampm = hours >= 12 ? 'PM' : 'AM';
            
            hours = hours % 12;
            hours = hours ? hours : 12;
            hours = String(hours).padStart(2, '0');
            
            const timeString = `${hours}:${minutes}:${seconds} ${ampm}`;
            document.getElementById('liveClock').textContent = timeString;
        }
        
        setInterval(updateClock, 1000);

        // Dropdown menu
        function toggleDropdown() {
            const dropdown = document.getElementById('dropdownMenu');
            const profile = document.getElementById('userProfile');
            const expanded = profile.getAttribute('aria-expanded') === 'true' ? false : true;
            
            dropdown.classList.toggle('show');
            profile.classList.toggle('active');
            profile.setAttribute('aria-expanded', expanded);
        }

        window.addEventListener('click', function(event) {
            const dropdown = document.getElementById('dropdownMenu');
            const profile = document.getElementById('userProfile');
            const container = document.querySelector('.user-profile-container:last-child');
            
            if (!container.contains(event.target)) {
                dropdown.classList.remove('show');
                profile.classList.remove('active');
                profile.setAttribute('aria-expanded', 'false');
            }
        });

        // Notifications dropdown
        function toggleNotifications() {
            document.getElementById('notificationMenu').classList.toggle('show');
        }

        // Close notifications when clicking outside
        window.addEventListener('click', function(e) {
            const btn = document.getElementById('notificationBtn');
            const menu = document.getElementById('notificationMenu');
            
            if (btn && menu && !btn.contains(e.target) && !menu.contains(e.target)) {
                menu.classList.remove('show');
            }
        });

        // Dark mode toggle
        function toggleMode() {
            document.body.classList.toggle('dark');
            const modeIcon = document.getElementById('modeIcon');
            const isDark = document.body.classList.contains('dark');
            modeIcon.textContent = isDark ? '☀️' : '🌙';
            localStorage.setItem('darkMode', isDark);
        }

        // Load dark mode preference
        if (localStorage.getItem('darkMode') === 'true') {
            document.body.classList.add('dark');
            document.getElementById('modeIcon').textContent = '☀️';
        }

        // Notification auto-refresh
        let notificationCheckInterval;

        function initializeNotifications() {
            fetchNotifications();
            // Check for new notifications every 30 seconds
            notificationCheckInterval = setInterval(fetchNotifications, 30000);
        }

        function fetchNotifications() {
            fetch('get_notifications.php')
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        updateNotificationBadge(data.unread_count);
                        updateNotificationDropdown(data.notifications);
                    }
                })
                .catch(error => console.error('Error fetching notifications:', error));
        }

        function updateNotificationBadge(count) {
            const badge = document.getElementById('notificationBadge');
            if(badge) {
                if(count > 0) {
                    badge.textContent = count;
                    badge.style.display = 'flex';
                    badge.style.animation = 'bounce 1s infinite';
                } else {
                    badge.style.display = 'none';
                }
            }
        }

        function updateNotificationDropdown(notifications) {
            const list = document.getElementById('notificationsList');
            const countText = document.getElementById('notificationCount');
            
            if(countText) {
                const unreadCount = notifications.filter(n => !n.is_read).length;
                countText.textContent = unreadCount + ' unread';
            }
            
            if(notifications.length === 0) {
                list.innerHTML = `
                    <div style="text-align: center; padding: 2rem; color: #64748b;">
                        <i class="fas fa-bell-slash" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                        <p>No notifications</p>
                    </div>
                `;
            } else {
                let html = '';
                notifications.slice(0, 5).forEach(notif => {
                    const time = getTimeAgo(notif.created_at);
                    const icon = getNotificationIcon(notif.type);
                    const bgColor = getNotificationColor(notif.type);
                    
                    html += `
                        <div class="notification-item ${notif.is_read == 0 ? 'unread' : ''}" onclick="window.location.href='notifications.php?id=${notif.id}'">
                            <div class="notification-icon" style="background: ${bgColor.bg}; color: ${bgColor.color};">
                                <i class="fas ${icon}"></i>
                            </div>
                            <div class="notification-content">
                                <div class="notification-title">${escapeHtml(notif.title)}</div>
                                <div class="notification-message">${escapeHtml(notif.message.substring(0, 50))}${notif.message.length > 50 ? '...' : ''}</div>
                                <div class="notification-time">${time}</div>
                            </div>
                        </div>
                    `;
                });
                
                if(notifications.length > 5) {
                    html += `
                        <div style="text-align: center; padding: 0.75rem; border-top: 1px solid #e2e8f0;">
                            <a href="notifications.php" style="color: #2563eb; text-decoration: none; font-size: 0.9rem;">
                                View all ${notifications.length} notifications
                            </a>
                        </div>
                    `;
                }
                
                list.innerHTML = html;
            }
        }

        function getNotificationIcon(type) {
            switch(type) {
                case 'emergency': return 'fa-exclamation-triangle';
                case 'success': return 'fa-check-circle';
                case 'warning': return 'fa-exclamation-circle';
                case 'info':
                default: return 'fa-info-circle';
            }
        }

        function getNotificationColor(type) {
            switch(type) {
                case 'emergency': return { bg: '#fee2e2', color: '#b91c1c' };
                case 'success': return { bg: '#d1fae5', color: '#065f46' };
                case 'warning': return { bg: '#fef3c7', color: '#92400e' };
                case 'info':
                default: return { bg: '#dbeafe', color: '#1e40af' };
            }
        }

        function getTimeAgo(timestamp) {
            const now = new Date();
            const past = new Date(timestamp);
            const diff = Math.floor((now - past) / 1000); // seconds
            
            if(diff < 60) return 'Just now';
            if(diff < 3600) return Math.floor(diff / 60) + ' minutes ago';
            if(diff < 86400) return Math.floor(diff / 3600) + ' hours ago';
            if(diff < 2592000) return Math.floor(diff / 86400) + ' days ago';
            return past.toLocaleDateString();
        }

        function escapeHtml(unsafe) {
            return unsafe
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        // Initialize notifications when page loads
        document.addEventListener('DOMContentLoaded', function() {
            initializeNotifications();
            
            // Check if there was a previous submission with errors
            <?php if(isset($_POST['service_type']) && !empty($_POST['service_type'])): ?>
            const serviceOptions = document.querySelectorAll('.service-option');
            serviceOptions.forEach(opt => {
                const serviceName = opt.querySelector('.service-name').textContent;
                if(serviceName === '<?php echo htmlspecialchars($_POST['service_type']); ?>') {
                    const priceText = opt.querySelector('.service-price').textContent;
                    const price = parseFloat(priceText.replace('Ksh', ''));
                    selectService(opt, serviceName, price);
                }
            });
            <?php endif; ?>
        });

        // Clean up interval when page unloads
        window.addEventListener('beforeunload', function() {
            if(notificationCheckInterval) {
                clearInterval(notificationCheckInterval);
            }
        });

        // Service selection with price calculation
        let selectedServicePrice = 0;
        const TAX_RATE = <?php echo TAX_RATE; ?>;

        function selectService(element, serviceName, price) {
            // Remove selected class from all options and update ARIA
            document.querySelectorAll('.service-option').forEach(opt => {
                opt.classList.remove('selected');
                opt.setAttribute('aria-checked', 'false');
            });
            
            // Add selected class to clicked option
            element.classList.add('selected');
            element.setAttribute('aria-checked', 'true');
            
            // Update hidden input
            document.getElementById('selectedService').value = serviceName;
            
            // Update price
            selectedServicePrice = parseFloat(price);
            
            // Calculate tax and total
            const tax = selectedServicePrice * TAX_RATE;
            const total = selectedServicePrice + tax;
            
            // Update display with animation
            animateValue('.service-price-display', 0, selectedServicePrice, 300);
            animateValue('.tax-display', 0, tax, 300);
            animateValue('.total-price', 0, total, 300);
            
            // Enable submit button if vehicle is also selected
            checkFormValidity();
        }

        // Animate price changes
        function animateValue(selector, start, end, duration) {
            const element = document.querySelector(selector);
            if (!element) return;
            
            const startTime = performance.now();
            
            function update(currentTime) {
                const elapsed = currentTime - startTime;
                const progress = Math.min(elapsed / duration, 1);
                
                const current = start + (end - start) * progress;
                element.textContent = `Ksh ${current.toFixed(2)}`;
                
                if (progress < 1) {
                    requestAnimationFrame(update);
                }
            }
            
            requestAnimationFrame(update);
        }

        // Vehicle selection
        document.getElementById('vehicleSelect')?.addEventListener('change', function() {
            checkFormValidity();
        });

        // Check form validity
        function checkFormValidity() {
            const vehicleSelect = document.getElementById('vehicleSelect');
            const vehicleSelected = vehicleSelect ? vehicleSelect.value : false;
            const serviceSelected = document.getElementById('selectedService').value;
            const submitBtn = document.getElementById('submitBtn');
            
            if(vehicleSelected && serviceSelected) {
                submitBtn.disabled = false;
            } else {
                submitBtn.disabled = true;
            }
        }

        // Form submission with loading state
        document.getElementById('bookingForm').addEventListener('submit', function(e) {
            const vehicleSelect = document.getElementById('vehicleSelect');
            const serviceInput = document.getElementById('selectedService');
            const submitBtn = document.getElementById('submitBtn');
            
            if(!vehicleSelect || !vehicleSelect.value) {
                e.preventDefault();
                alert('Please select a vehicle');
                vehicleSelect?.focus();
                return;
            }
            
            if(!serviceInput.value) {
                e.preventDefault();
                alert('Please select a service');
                document.querySelector('.service-option')?.focus();
                return;
            }

            // Show loading state
            submitBtn.disabled = true;
            const btnText = submitBtn.querySelector('.btn-text');
            btnText.innerHTML = '<span class="loading-spinner"></span> Booking...';
        });

        // Date input validation
        const dateInput = document.getElementById('appointmentDate');
        if(dateInput) {
            const today = new Date().toISOString().split('T')[0];
            dateInput.min = today;
            
            dateInput.addEventListener('change', function() {
                if(this.value < today) {
                    alert('Please select a future date');
                    this.value = today;
                }
            });
        }
    </script>
</body>
</html>