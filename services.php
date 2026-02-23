<?php
session_start();
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin'){ 
    header("Location: login.php"); 
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

// Handle mark as completed
if(isset($_GET['complete'])){
    try {
        if (!isset($_GET['csrf_token']) || $_GET['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid security token");
        }

        $serviceId = intval($_GET['complete']);
        
        // Begin transaction
        $db->beginTransaction();
        
        // Get service details for notification
        $serviceStmt = $db->prepare("
            SELECT s.*, u.id as user_id, u.name as user_name, c.brand, c.model, c.license_plate 
            FROM services s 
            JOIN users u ON s.user_id = u.id
            JOIN cars c ON s.car_id = c.id
            WHERE s.id = ?
        ");
        $serviceStmt->execute([$serviceId]);
        $service = $serviceStmt->fetch(PDO::FETCH_ASSOC);
        
        if(!$service) {
            throw new Exception("Service not found");
        }

        // Update service status
        $stmt = $db->prepare("UPDATE services SET status = 'Completed', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$serviceId]);

        // Create notification for user
        $notifStmt = $db->prepare("
            INSERT INTO notifications (user_id, type, title, message, data, created_at)
            VALUES (?, 'success', 'Service Completed', ?, ?, NOW())
        ");
        
        $vehicleName = $service['brand'] . ' ' . $service['model'] . ' (' . $service['license_plate'] . ')';
        $message = "Your " . $service['service_type'] . " service for " . $vehicleName . " has been completed successfully.";
        $data = json_encode([
            'service_id' => $serviceId,
            'user_id' => $service['user_id'],
            'service_type' => $service['service_type'],
            'vehicle' => $vehicleName
        ]);
        
        $notifStmt->execute([$service['user_id'], $message, $data]);

        $db->commit();
        
        $_SESSION['success'] = "Service marked as completed successfully!";
        header("Location: admin_services.php");
        exit();
        
    } catch (Exception $e) {
        $db->rollBack();
        $error = $e->getMessage();
        error_log("Complete service error: " . $e->getMessage());
    }
}

// Handle add new service
if(isset($_POST['add_service'])) {
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid security token");
        }

        if(empty($_POST['service_name']) || empty($_POST['price'])) {
            throw new Exception("Service name and price are required");
        }

        $stmt = $db->prepare("
            INSERT INTO services (service_name, description, category, price, duration, is_active, created_at)
            VALUES (?, ?, ?, ?, ?, 1, NOW())
        ");
        
        $stmt->execute([
            $_POST['service_name'],
            $_POST['description'] ?? null,
            $_POST['category'] ?? 'General',
            floatval($_POST['price']),
            $_POST['duration'] ?? 60
        ]);

        $_SESSION['success'] = "Service added successfully!";
        header("Location: admin_services.php");
        exit();

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle update service
if(isset($_POST['update_service'])) {
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid security token");
        }

        $stmt = $db->prepare("
            UPDATE services 
            SET service_name = ?, description = ?, category = ?, price = ?, duration = ?, is_active = ?
            WHERE id = ?
        ");
        
        $stmt->execute([
            $_POST['service_name'],
            $_POST['description'] ?? null,
            $_POST['category'] ?? 'General',
            floatval($_POST['price']),
            $_POST['duration'] ?? 60,
            isset($_POST['is_active']) ? 1 : 0,
            $_POST['service_id']
        ]);

        $_SESSION['success'] = "Service updated successfully!";
        header("Location: admin_services.php");
        exit();

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle delete service
if(isset($_POST['delete_service'])) {
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid security token");
        }

        // Check if service is used in any bookings
        $checkStmt = $db->prepare("SELECT COUNT(*) as count FROM book_service WHERE service_type = (SELECT service_name FROM services WHERE id = ?)");
        $checkStmt->execute([$_POST['service_id']]);
        $result = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if($result['count'] > 0) {
            // Soft delete - just deactivate
            $stmt = $db->prepare("UPDATE services SET is_active = 0 WHERE id = ?");
            $stmt->execute([$_POST['service_id']]);
            $_SESSION['success'] = "Service deactivated successfully (has booking history)";
        } else {
            // Hard delete - no bookings
            $stmt = $db->prepare("DELETE FROM services WHERE id = ?");
            $stmt->execute([$_POST['service_id']]);
            $_SESSION['success'] = "Service deleted successfully";
        }
        
        header("Location: admin_services.php");
        exit();

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get filter parameters
$category = isset($_GET['category']) ? $_GET['category'] : 'all';
$status = isset($_GET['status']) ? $_GET['status'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query for services
$query = "SELECT * FROM services WHERE 1=1";
$params = [];

if($category !== 'all') {
    $query .= " AND category = ?";
    $params[] = $category;
}

if($status !== 'all') {
    $isActive = $status === 'active' ? 1 : 0;
    $query .= " AND is_active = ?";
    $params[] = $isActive;
}

if(!empty($search)) {
    $query .= " AND (service_name LIKE ? OR description LIKE ? OR category LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$query .= " ORDER BY category, service_name";

$stmt = $db->prepare($query);
$stmt->execute($params);
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories for filter
$catStmt = $db->query("SELECT DISTINCT category FROM services ORDER BY category");
$categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

// Get service bookings
$bookingsStmt = $db->query("
    SELECT s.id, COUNT(bs.id) as booking_count 
    FROM services s 
    LEFT JOIN book_service bs ON s.service_name = bs.service_type 
    GROUP BY s.id
");
$bookings = [];
while($row = $bookingsStmt->fetch(PDO::FETCH_ASSOC)) {
    $bookings[$row['id']] = $row['booking_count'];
}

// Get statistics
$statsStmt = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive,
        COUNT(DISTINCT category) as categories,
        AVG(price) as avg_price,
        MIN(price) as min_price,
        MAX(price) as max_price
    FROM services
");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Get user details
try {
    $userStmt = $db->prepare("SELECT name, email FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = ['name' => $_SESSION['user_name'] ?? 'Admin', 'email' => ''];
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

// Get greeting
$currentHour = (int)date('H');
$greeting = $currentHour < 12 ? 'Good Morning' : ($currentHour < 17 ? 'Good Afternoon' : 'Good Evening');

// Predefined 30 services (in case table is empty)
$predefinedServices = [
    // Oil & Fluids
    ['Oil Change', 'Engine oil change with filter replacement', 'Oil & Fluids', 3500, 45],
    ['Synthetic Oil Change', 'Full synthetic oil change for premium engines', 'Oil & Fluids', 5500, 60],
    ['Transmission Fluid Change', 'Automatic transmission fluid flush and refill', 'Oil & Fluids', 6500, 90],
    ['Coolant Flush', 'Complete cooling system flush and refill', 'Oil & Fluids', 4500, 60],
    ['Brake Fluid Flush', 'Brake system fluid replacement', 'Oil & Fluids', 3500, 45],
    ['Power Steering Fluid', 'Power steering fluid change', 'Oil & Fluids', 2500, 30],
    ['Differential Oil Change', 'Front/rear differential oil service', 'Oil & Fluids', 5500, 60],

    // Brake Service
    ['Brake Pad Replacement', 'Front or rear brake pad replacement', 'Brake Service', 6500, 60],
    ['Brake Rotor Replacement', 'Brake disc rotor replacement (pair)', 'Brake Service', 12000, 90],
    ['Complete Brake Service', 'Full brake inspection and service', 'Brake Service', 8500, 120],
    ['Brake Caliper Repair', 'Brake caliper rebuild or replacement', 'Brake Service', 9500, 120],
    ['Parking Brake Adjustment', 'Handbrake cable adjustment', 'Brake Service', 2500, 30],

    // Engine Service
    ['Engine Tune-Up', 'Complete engine tune-up including spark plugs', 'Engine Service', 8500, 120],
    ['Timing Belt Replacement', 'Timing belt and tensioner replacement', 'Engine Service', 15000, 240],
    ['Water Pump Replacement', 'Coolant pump replacement', 'Engine Service', 8500, 120],
    ['Engine Diagnostic', 'Computer diagnostic scan', 'Engine Service', 3500, 45],
    ['Check Engine Light', 'Diagnostic and repair', 'Engine Service', 4500, 60],
    ['Valve Adjustment', 'Engine valve clearance adjustment', 'Engine Service', 9500, 180],

    // Electrical
    ['Battery Replacement', 'Car battery testing and replacement', 'Electrical', 2500, 30],
    ['Alternator Repair', 'Alternator diagnostic and repair', 'Electrical', 7500, 120],
    ['Starter Replacement', 'Starter motor replacement', 'Electrical', 8500, 90],
    ['AC Recharge', 'Air conditioning system recharge', 'Electrical', 6500, 60],
    ['AC Compressor', 'AC compressor replacement', 'Electrical', 18500, 180],
    ['Lighting Repair', 'Headlight/taillight repair', 'Electrical', 3500, 45],

    // Suspension & Steering
    ['Wheel Alignment', 'Computerized wheel alignment', 'Suspension', 3500, 45],
    ['Tire Rotation', 'Tire rotation and balance', 'Suspension', 2500, 30],
    ['Shock Absorber Replacement', 'Shock/strut replacement (pair)', 'Suspension', 12000, 120],
    ['Wheel Bearing Replacement', 'Wheel bearing replacement', 'Suspension', 8500, 90],
    ['Power Steering Repair', 'Power steering system repair', 'Suspension', 9500, 120],

    // General
    ['Full Vehicle Inspection', 'Comprehensive vehicle check', 'General', 4500, 90],
    ['Pre-Purchase Inspection', 'Used car inspection before buying', 'General', 6500, 120]
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CASMS | Admin - Service Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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

        body.dark .stats-card {
            background: #1e293b;
            border-color: #334155;
        }

        body.dark .filter-section {
            background: #1e293b;
            border-color: #334155;
        }

        body.dark .filter-select,
        body.dark .filter-input {
            background: #0f172a;
            border-color: #334155;
            color: #e2e8f0;
        }

        body.dark .services-grid {
            background: #1e293b;
            border-color: #334155;
        }

        body.dark .service-card {
            background: #1e293b;
            border-color: #334155;
        }

        body.dark .service-card:hover {
            border-color: #2563eb;
        }

        body.dark .service-card.inactive {
            background: #0f172a;
            opacity: 0.7;
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

        body.dark .modal-content {
            background: #1e293b;
        }

        body.dark .form-input,
        body.dark .form-select,
        body.dark .form-textarea {
            background: #0f172a;
            border-color: #334155;
            color: #e2e8f0;
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

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stats-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            padding: 1.5rem;
            transition: all 0.3s;
        }

        .stats-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px -5px rgba(37, 99, 235, 0.2);
            border-color: #2563eb;
        }

        .stats-icon {
            width: 48px;
            height: 48px;
            background: #dbeafe;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: #2563eb;
            margin-bottom: 1rem;
        }

        .stats-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .stats-label {
            color: #64748b;
            font-size: 0.875rem;
        }

        .stats-trend {
            font-size: 0.75rem;
            color: #10b981;
            margin-top: 0.5rem;
        }

        /* Filter Section */
        .filter-section {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .filter-group {
            flex: 1;
            min-width: 150px;
        }

        .filter-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 0.25rem;
            text-transform: uppercase;
        }

        .filter-select,
        .filter-input {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-family: 'Inter', sans-serif;
            font-size: 0.95rem;
            transition: all 0.2s;
        }

        .filter-select:focus,
        .filter-input:focus {
            outline: none;
            border-color: #2563eb;
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
            align-items: flex-end;
        }

        .btn-filter {
            padding: 0.75rem 1.5rem;
            background: #2563eb;
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-filter:hover {
            background: #1d4ed8;
            transform: translateY(-2px);
        }

        .btn-reset {
            padding: 0.75rem 1.5rem;
            background: transparent;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            color: #64748b;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }

        .btn-reset:hover {
            border-color: #2563eb;
            color: #2563eb;
        }

        .btn-add {
            padding: 0.75rem 1.5rem;
            background: #10b981;
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-add:hover {
            background: #059669;
            transform: translateY(-2px);
        }

        /* Services Grid */
        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .service-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            overflow: hidden;
            transition: all 0.3s;
            position: relative;
        }

        .service-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
            border-color: #2563eb;
        }

        .service-card.inactive {
            opacity: 0.7;
            background: #f8fafc;
        }

        .service-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            padding: 0.25rem 0.75rem;
            border-radius: 40px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .service-badge.active {
            background: #d1fae5;
            color: #065f46;
        }

        .service-badge.inactive {
            background: #fee2e2;
            color: #b91c1c;
        }

        .service-header {
            padding: 1.5rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }

        .service-category {
            font-size: 0.75rem;
            color: #2563eb;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .service-name {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .service-description {
            color: #64748b;
            font-size: 0.875rem;
            line-height: 1.5;
        }

        .service-body {
            padding: 1.5rem;
        }

        .service-details {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .service-price {
            flex: 1;
            text-align: center;
            padding: 1rem;
            background: #dbeafe;
            border-radius: 12px;
        }

        .price-label {
            font-size: 0.75rem;
            color: #1e40af;
            text-transform: uppercase;
            margin-bottom: 0.25rem;
        }

        .price-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e40af;
        }

        .service-duration {
            flex: 1;
            text-align: center;
            padding: 1rem;
            background: #f1f5f9;
            border-radius: 12px;
        }

        .duration-label {
            font-size: 0.75rem;
            color: #475569;
            text-transform: uppercase;
            margin-bottom: 0.25rem;
        }

        .duration-value {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1e293b;
        }

        .service-stats {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .booking-count {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #64748b;
            font-size: 0.875rem;
        }

        .service-actions {
            display: flex;
            gap: 0.5rem;
        }

        .btn-action {
            flex: 1;
            padding: 0.5rem;
            border: none;
            border-radius: 10px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.875rem;
        }

        .btn-action.edit {
            background: #f1f5f9;
            color: #475569;
        }

        .btn-action.edit:hover {
            background: #2563eb;
            color: white;
        }

        .btn-action.delete {
            background: #fee2e2;
            color: #b91c1c;
        }

        .btn-action.delete:hover {
            background: #ef4444;
            color: white;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            align-items: center;
            justify-content: center;
            z-index: 1000;
            backdrop-filter: blur(4px);
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 24px;
            padding: 2rem;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #64748b;
        }

        .form-group {
            margin-bottom: 1.25rem;
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
            border-color: #2563eb;
        }

        .form-textarea {
            min-height: 100px;
            resize: vertical;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
        }

        .modal-footer {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid #e2e8f0;
        }

        /* Toast */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 1rem 1.5rem;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            z-index: 2000;
            transform: translateX(400px);
            transition: transform 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            border-left: 4px solid #10b981;
            min-width: 300px;
        }

        .toast.show {
            transform: translateX(0);
        }

        .toast.error {
            border-left-color: #ef4444;
        }

        .toast-progress {
            position: absolute;
            bottom: 0;
            left: 0;
            height: 3px;
            background: #10b981;
            width: 100%;
            transform-origin: left;
            animation: progress 3s linear;
        }

        .toast.error .toast-progress {
            background: #ef4444;
        }

        @keyframes progress {
            from { transform: scaleX(1); }
            to { transform: scaleX(0); }
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

        /* Responsive */
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
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .services-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-section {
                flex-direction: column;
            }
            
            .service-details {
                flex-direction: column;
                gap: 0.75rem;
            }
            
            .dropdown-menu {
                width: 300px;
                right: -50px;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
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
            <h2>CASMS Admin</h2>
            <nav>
                <a href="admin_dashboard.php">Dashboard</a>
                <a href="admin_users.php">Users</a>
                <a href="admin_vehicles.php">Vehicles</a>
                <a href="admin_services.php" class="active">Services</a>
                <a href="admin_bookings.php">Bookings</a>
                <a href="admin_emergency.php">Emergency</a>
                <a href="admin_orders.php">Orders</a>
                <a href="admin_reports.php">Reports</a>
                <a href="admin_settings.php">Settings</a>
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
                                <span class="greeting-badge">ADMIN PANEL</span>
                                <h1 class="welcome-title">
                                    <span class="greeting-word"><?php echo htmlspecialchars($greeting); ?>,</span>
                                    <span class="user-name"><?php echo htmlspecialchars($user['name']); ?></span>
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
                                    <span class="chip-icon">🔧</span>
                                    <span class="chip-text"><?php echo $stats['total']; ?> Services</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="status-message">
                            <div class="status-icon">
                                <span class="pulse-dot"></span>
                                <span>Service Management</span>
                            </div>
                            <div class="message-text">
                                <span class="highlight"><?php echo $stats['active']; ?> active</span> services · 
                                <span class="highlight"><?php echo $stats['categories']; ?></span> categories
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
                                    <a href="admin_notifications.php" style="font-size: 0.75rem; color: #2563eb;">View All</a>
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
                                            <div class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>" onclick="window.location.href='admin_notifications.php?id=<?php echo $notif['id']; ?>'">
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
                                    <a href="admin_notifications.php" class="dropdown-item">
                                        <span class="item-icon">🔔</span>
                                        View All Notifications
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Admin Profile -->
                        <div class="user-profile-container">
                            <div class="user-profile" onclick="toggleDropdown()" id="userProfile">
                                <div class="avatar">
                                    <span><?php echo strtoupper(substr(htmlspecialchars($user['name']), 0, 1)); ?></span>
                                </div>
                                <div class="user-info">
                                    <span class="user-name"><?php echo htmlspecialchars($user['name']); ?></span>
                                    <span class="user-role">Administrator</span>
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
                                    <a href="admin_profile.php" class="dropdown-item">
                                        <span class="item-icon">👤</span>
                                        My Profile
                                    </a>
                                    <a href="admin_settings.php" class="dropdown-item">
                                        <span class="item-icon">⚙️</span>
                                        Settings
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

            <?php if(isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stats-card">
                    <div class="stats-icon">
                        <i class="fas fa-tools"></i>
                    </div>
                    <div class="stats-value"><?php echo $stats['total']; ?></div>
                    <div class="stats-label">Total Services</div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stats-value"><?php echo $stats['active']; ?></div>
                    <div class="stats-label">Active Services</div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">
                        <i class="fas fa-tag"></i>
                    </div>
                    <div class="stats-value"><?php echo $stats['categories']; ?></div>
                    <div class="stats-label">Categories</div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stats-value">Ksh <?php echo number_format($stats['avg_price'], 0); ?></div>
                    <div class="stats-label">Average Price</div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">
                        <i class="fas fa-arrow-up"></i>
                    </div>
                    <div class="stats-value">Ksh <?php echo number_format($stats['max_price'], 0); ?></div>
                    <div class="stats-label">Highest Price</div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">
                        <i class="fas fa-arrow-down"></i>
                    </div>
                    <div class="stats-value">Ksh <?php echo number_format($stats['min_price'], 0); ?></div>
                    <div class="stats-label">Lowest Price</div>
                </div>
            </div>

            <!-- Filter and Add Section -->
            <div class="filter-section">
                <form method="GET" style="display: contents;">
                    <div class="filter-group">
                        <span class="filter-label">Category</span>
                        <select name="category" class="filter-select">
                            <option value="all" <?php echo $category === 'all' ? 'selected' : ''; ?>>All Categories</option>
                            <?php foreach($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category === $cat ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <span class="filter-label">Status</span>
                        <select name="status" class="filter-select">
                            <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <span class="filter-label">Search</span>
                        <input type="text" name="search" class="filter-input" placeholder="Search services..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn-filter">
                            <i class="fas fa-filter"></i> Apply
                        </button>
                        <a href="admin_services.php" class="btn-reset">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </form>
                <button class="btn-add" onclick="openAddServiceModal()">
                    <i class="fas fa-plus"></i> Add New Service
                </button>
            </div>

            <!-- Services Grid -->
            <?php if(empty($services) && empty($predefinedServices)): ?>
                <div class="empty-state">
                    <div class="empty-icon">🔧</div>
                    <div class="empty-title">No Services Found</div>
                    <div class="empty-text">Click "Add New Service" to create your first service.</div>
                </div>
            <?php else: ?>
                <div class="services-grid">
                    <?php foreach($services as $service): 
                        $bookingCount = $bookings[$service['id']] ?? 0;
                    ?>
                        <div class="service-card <?php echo $service['is_active'] ? '' : 'inactive'; ?>">
                            <span class="service-badge <?php echo $service['is_active'] ? 'active' : 'inactive'; ?>">
                                <?php echo $service['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                            <div class="service-header">
                                <div class="service-category"><?php echo htmlspecialchars($service['category']); ?></div>
                                <div class="service-name"><?php echo htmlspecialchars($service['service_name']); ?></div>
                                <div class="service-description"><?php echo htmlspecialchars($service['description']); ?></div>
                            </div>
                            <div class="service-body">
                                <div class="service-details">
                                    <div class="service-price">
                                        <div class="price-label">Price</div>
                                        <div class="price-value">Ksh <?php echo number_format($service['price'], 2); ?></div>
                                    </div>
                                    <div class="service-duration">
                                        <div class="duration-label">Duration</div>
                                        <div class="duration-value"><?php echo $service['duration']; ?> min</div>
                                    </div>
                                </div>
                                <div class="service-stats">
                                    <div class="booking-count">
                                        <i class="fas fa-calendar-check"></i>
                                        <span><?php echo $bookingCount; ?> bookings</span>
                                    </div>
                                </div>
                                <div class="service-actions">
                                    <button class="btn-action edit" onclick="editService(<?php echo $service['id']; ?>)">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button class="btn-action delete" onclick="deleteService(<?php echo $service['id']; ?>, '<?php echo htmlspecialchars($service['service_name']); ?>')">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Service Modal -->
    <div class="modal" id="addServiceModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Add New Service</h3>
                <button class="modal-close" onclick="closeModal('addServiceModal')">&times;</button>
            </div>
            <form method="POST" id="addServiceForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Service Name <span class="required">*</span></label>
                        <input type="text" name="service_name" class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-textarea" rows="3"></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Category <span class="required">*</span></label>
                        <select name="category" class="form-select" required>
                            <option value="">Select Category</option>
                            <option value="Oil & Fluids">Oil & Fluids</option>
                            <option value="Brake Service">Brake Service</option>
                            <option value="Engine Service">Engine Service</option>
                            <option value="Electrical">Electrical</option>
                            <option value="Suspension">Suspension</option>
                            <option value="General">General</option>
                        </select>
                    </div>

                    <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label">Price (Ksh) <span class="required">*</span></label>
                            <input type="number" name="price" class="form-input" min="0" step="100" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Duration (minutes)</label>
                            <input type="number" name="duration" class="form-input" value="60" min="15" step="15">
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-outline" onclick="closeModal('addServiceModal')">Cancel</button>
                    <button type="submit" name="add_service" class="btn-primary">Add Service</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Service Modal -->
    <div class="modal" id="editServiceModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Edit Service</h3>
                <button class="modal-close" onclick="closeModal('editServiceModal')">&times;</button>
            </div>
            <form method="POST" id="editServiceForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="service_id" id="editServiceId">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Service Name <span class="required">*</span></label>
                        <input type="text" name="service_name" id="editServiceName" class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="editServiceDescription" class="form-textarea" rows="3"></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Category <span class="required">*</span></label>
                        <select name="category" id="editServiceCategory" class="form-select" required>
                            <option value="Oil & Fluids">Oil & Fluids</option>
                            <option value="Brake Service">Brake Service</option>
                            <option value="Engine Service">Engine Service</option>
                            <option value="Electrical">Electrical</option>
                            <option value="Suspension">Suspension</option>
                            <option value="General">General</option>
                        </select>
                    </div>

                    <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label">Price (Ksh) <span class="required">*</span></label>
                            <input type="number" name="price" id="editServicePrice" class="form-input" min="0" step="100" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Duration (minutes)</label>
                            <input type="number" name="duration" id="editServiceDuration" class="form-input" min="15" step="15">
                        </div>
                    </div>

                    <div class="form-group checkbox-group">
                        <input type="checkbox" name="is_active" id="editServiceActive" value="1">
                        <label for="editServiceActive">Service is active</label>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-outline" onclick="closeModal('editServiceModal')">Cancel</button>
                    <button type="submit" name="update_service" class="btn-primary">Update Service</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Delete Service</h3>
                <button class="modal-close" onclick="closeModal('deleteModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete <strong id="deleteServiceName"></strong>?</p>
                <p style="color: #b91c1c; font-size: 0.875rem; margin-top: 0.5rem;">
                    <i class="fas fa-exclamation-triangle"></i>
                    If this service has bookings, it will be deactivated instead of deleted.
                </p>
            </div>
            <div class="modal-footer">
                <button class="btn-outline" onclick="closeModal('deleteModal')">Cancel</button>
                <form method="POST" id="deleteForm" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="service_id" id="deleteServiceId">
                    <button type="submit" name="delete_service" class="btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div class="toast" id="toast">
        <span class="toast-icon" id="toastIcon">✅</span>
        <span class="toast-message" id="toastMessage"></span>
        <div class="toast-progress"></div>
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
            fetch('get_admin_notifications.php')
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
                    }
                });
        }

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

        // Dropdown
        function toggleDropdown() {
            document.getElementById('dropdownMenu').classList.toggle('show');
            document.getElementById('userProfile').classList.toggle('active');
        }

        window.addEventListener('click', (e) => {
            const container = document.querySelector('.user-profile-container:last-child');
            if (container && !container.contains(e.target)) {
                document.getElementById('dropdownMenu').classList.remove('show');
                document.getElementById('userProfile').classList.remove('active');
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

        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
            document.body.style.overflow = 'auto';
        }

        window.addEventListener('click', (e) => {
            if (e.target.classList.contains('modal')) {
                closeModal(e.target.id);
            }
        });

        // Add service modal
        function openAddServiceModal() {
            document.getElementById('addServiceForm').reset();
            openModal('addServiceModal');
        }

        // Edit service
        function editService(serviceId) {
            // In a real application, you would fetch service details via AJAX
            // For demo purposes, we'll use sample data
            // You should implement an AJAX call to get_service_details.php
            
            // Simulated data - replace with actual AJAX call
            document.getElementById('editServiceId').value = serviceId;
            document.getElementById('editServiceName').value = 'Sample Service';
            document.getElementById('editServiceDescription').value = 'Sample description';
            document.getElementById('editServiceCategory').value = 'General';
            document.getElementById('editServicePrice').value = '5000';
            document.getElementById('editServiceDuration').value = '60';
            document.getElementById('editServiceActive').checked = true;
            
            openModal('editServiceModal');
        }

        // Delete service
        function deleteService(serviceId, serviceName) {
            document.getElementById('deleteServiceId').value = serviceId;
            document.getElementById('deleteServiceName').textContent = serviceName;
            openModal('deleteModal');
        }

        // Toast
        function showToast(icon, message, type = 'success') {
            const toast = document.getElementById('toast');
            document.getElementById('toastIcon').textContent = icon;
            document.getElementById('toastMessage').textContent = message;
            toast.classList.remove('error');
            if(type === 'error') toast.classList.add('error');
            toast.classList.add('show');
            
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }

        // Auto-hide alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

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