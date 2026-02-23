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

// Handle cancel appointment
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_booking'])){
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid security token");
        }

        $bookingId = intval($_POST['booking_id']);
        
        // Begin transaction
        $db->beginTransaction();
        
        // Verify booking belongs to user
        $checkStmt = $db->prepare("SELECT bs.*, v.brand, v.model, v.license_plate FROM book_service bs JOIN vehicles v ON bs.vehicle_id = v.id WHERE bs.id = ? AND bs.user_id = ? AND bs.status = 'Pending'");
        $checkStmt->execute([$bookingId, $_SESSION['user_id']]);
        $booking = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if(!$booking) {
            throw new Exception("Booking not found or cannot be cancelled");
        }

        $stmt = $db->prepare("UPDATE book_service SET status = 'Cancelled' WHERE id = ?");
        $result = $stmt->execute([$bookingId]);

        if($result) {
            // Create notification for cancellation
            $notifStmt = $db->prepare("
                INSERT INTO notifications (user_id, type, title, message, data, created_at)
                VALUES (?, 'warning', 'Service Cancelled', ?, ?, NOW())
            ");
            
            $vehicleName = $booking['brand'] . ' ' . $booking['model'] . ' (' . $booking['license_plate'] . ')';
            $message = "Your " . $booking['service_type'] . " service for " . $vehicleName . " scheduled for " . date('F j, Y', strtotime($booking['appointment_date'])) . " has been cancelled.";
            $data = json_encode([
                'booking_id' => $bookingId,
                'vehicle_id' => $booking['vehicle_id'],
                'service_type' => $booking['service_type'],
                'status' => 'Cancelled'
            ]);
            
            $notifStmt->execute([$_SESSION['user_id'], $message, $data]);
            
            $db->commit();
            $_SESSION['success'] = "Appointment cancelled successfully";
        } else {
            throw new Exception("Failed to cancel appointment");
        }
        header("Location: service_history.php");
        exit();
    } catch (Exception $e) {
        $db->rollBack();
        $error = $e->getMessage();
        error_log("Cancel booking error: " . $e->getMessage());
    }
}

// Handle rebook
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rebook'])){
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid security token");
        }

        $oldBookingId = intval($_POST['booking_id']);
        
        // Begin transaction
        $db->beginTransaction();
        
        // Get old booking details
        $stmt = $db->prepare("SELECT bs.*, v.brand, v.model, v.license_plate FROM book_service bs JOIN vehicles v ON bs.vehicle_id = v.id WHERE bs.id = ? AND bs.user_id = ?");
        $stmt->execute([$oldBookingId, $_SESSION['user_id']]);
        $oldBooking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if(!$oldBooking) {
            throw new Exception("Booking not found");
        }

        // Create new booking with same details
        $newDate = date('Y-m-d', strtotime('+1 day'));
        $insertStmt = $db->prepare("INSERT INTO book_service (user_id, vehicle_id, service_type, appointment_date, notes, status, created_at) 
                                    VALUES (:user_id, :vehicle_id, :service_type, :appointment_date, :notes, 'Pending', NOW())");
        
        $result = $insertStmt->execute([
            ":user_id" => $_SESSION['user_id'],
            ":vehicle_id" => $oldBooking['vehicle_id'],
            ":service_type" => $oldBooking['service_type'],
            ":appointment_date" => $newDate,
            ":notes" => $oldBooking['notes']
        ]);

        $newBookingId = $db->lastInsertId();

        if($result) {
            // Create notification for rebooking
            $notifStmt = $db->prepare("
                INSERT INTO notifications (user_id, type, title, message, data, created_at)
                VALUES (?, 'info', 'Service Rebooked', ?, ?, NOW())
            ");
            
            $vehicleName = $oldBooking['brand'] . ' ' . $oldBooking['model'] . ' (' . $oldBooking['license_plate'] . ')';
            $message = "Your " . $oldBooking['service_type'] . " service for " . $vehicleName . " has been rebooked for " . date('F j, Y', strtotime($newDate)) . ". Please confirm the date.";
            $data = json_encode([
                'booking_id' => $newBookingId,
                'old_booking_id' => $oldBookingId,
                'vehicle_id' => $oldBooking['vehicle_id'],
                'service_type' => $oldBooking['service_type'],
                'appointment_date' => $newDate
            ]);
            
            $notifStmt->execute([$_SESSION['user_id'], $message, $data]);
            
            $db->commit();
            $_SESSION['success'] = "Service rebooked successfully! Please select a new date.";
            header("Location: book_service.php");
            exit();
        } else {
            throw new Exception("Failed to rebook service");
        }
    } catch (Exception $e) {
        $db->rollBack();
        $error = $e->getMessage();
        error_log("Rebook error: " . $e->getMessage());
    }
}

// Get filter parameters
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'all';
$dateRange = isset($_GET['date_range']) ? $_GET['date_range'] : 'all';
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query based on filters
$query = "
    SELECT 
        bs.*,
        v.brand,
        v.model,
        v.license_plate,
        v.color,
        v.year
    FROM book_service bs
    JOIN vehicles v ON bs.vehicle_id = v.id
    WHERE bs.user_id = :user_id
";

$params = [":user_id" => $_SESSION['user_id']];

// Apply status filter
if($statusFilter !== 'all') {
    $query .= " AND bs.status = :status";
    $params[":status"] = $statusFilter;
}

// Apply date range filter
if($dateRange !== 'all') {
    switch($dateRange) {
        case 'upcoming':
            $query .= " AND bs.appointment_date >= CURDATE()";
            break;
        case 'past':
            $query .= " AND bs.appointment_date < CURDATE()";
            break;
        case 'this_month':
            $query .= " AND MONTH(bs.appointment_date) = MONTH(CURDATE()) AND YEAR(bs.appointment_date) = YEAR(CURDATE())";
            break;
        case 'last_month':
            $query .= " AND MONTH(bs.appointment_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) 
                       AND YEAR(bs.appointment_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
            break;
    }
}

// Apply search filter
if(!empty($searchTerm)) {
    $query .= " AND (v.brand LIKE :search OR v.model LIKE :search OR v.license_plate LIKE :search OR bs.service_type LIKE :search)";
    $params[":search"] = "%$searchTerm%";
}

// Add ordering
$query .= " ORDER BY bs.appointment_date DESC, bs.created_at DESC";

// Execute query
try {
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get statistics
    $statsStmt = $db->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) as cancelled,
            SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as in_progress,
            COUNT(DISTINCT vehicle_id) as vehicles_serviced
        FROM book_service 
        WHERE user_id = ?
    ");
    $statsStmt->execute([$_SESSION['user_id']]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    // Get upcoming appointments count
    $upcomingStmt = $db->prepare("
        SELECT COUNT(*) as upcoming 
        FROM book_service 
        WHERE user_id = ? AND appointment_date >= CURDATE() AND status = 'Pending'
    ");
    $upcomingStmt->execute([$_SESSION['user_id']]);
    $upcoming = $upcomingStmt->fetch(PDO::FETCH_ASSOC);
    
    // Get unread notifications count
    $notifCountStmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
    $notifCountStmt->execute([$_SESSION['user_id']]);
    $unreadNotifications = $notifCountStmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Get recent notifications for dropdown
    $recentNotifStmt = $db->prepare("
        SELECT * FROM notifications 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $recentNotifStmt->execute([$_SESSION['user_id']]);
    $recentNotifications = $recentNotifStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $bookings = [];
    $stats = ['total' => 0, 'pending' => 0, 'completed' => 0, 'cancelled' => 0, 'in_progress' => 0, 'vehicles_serviced' => 0];
    $upcoming = ['upcoming' => 0];
    $unreadNotifications = 0;
    $recentNotifications = [];
    error_log("Error fetching bookings: " . $e->getMessage());
}

// Get user details
try {
    $userStmt = $db->prepare("SELECT name, email FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = ['name' => $_SESSION['user_name'] ?? 'User', 'email' => ''];
}

// Get greeting based on time
$currentHour = (int)date('H');
$greeting = $currentHour < 12 ? 'Good Morning' : ($currentHour < 17 ? 'Good Afternoon' : 'Good Evening');

// Status colors and icons
$statusConfig = [
    'Pending' => ['bg' => '#fef3c7', 'color' => '#92400e', 'icon' => '⏳'],
    'In Progress' => ['bg' => '#dbeafe', 'color' => '#1e40af', 'icon' => '🔧'],
    'Completed' => ['bg' => '#d1fae5', 'color' => '#065f46', 'icon' => '✅'],
    'Cancelled' => ['bg' => '#fee2e2', 'color' => '#991b1b', 'icon' => '❌']
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CASMS | Service History</title>
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

        body.dark .history-card {
            background: #1e293b;
            border-color: #334155;
        }

        body.dark .history-header {
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

        body.dark .empty-state {
            background: #1e293b;
            border-color: #334155;
        }

        body.dark .modal-content {
            background: #1e293b;
        }

        body.dark .modal-title {
            color: #f1f5f9;
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

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stats-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            padding: 1.5rem;
            transition: all 0.3s;
            text-align: center;
        }

        .stats-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px -5px rgba(37, 99, 235, 0.2);
            border-color: #2563eb;
        }

        .stats-icon {
            font-size: 2rem;
            margin-bottom: 0.75rem;
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
            letter-spacing: 0.5px;
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

        /* History Cards */
        .history-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .history-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            overflow: hidden;
            transition: all 0.3s;
        }

        .history-card:hover {
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
            border-color: #2563eb;
        }

        .history-header {
            padding: 1.25rem 1.5rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .vehicle-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .vehicle-icon {
            width: 48px;
            height: 48px;
            background: white;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .vehicle-details {
            display: flex;
            flex-direction: column;
        }

        .vehicle-name {
            font-weight: 600;
            font-size: 1.125rem;
        }

        .vehicle-plate {
            font-size: 0.875rem;
            color: #64748b;
        }

        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 40px;
            font-size: 0.875rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .history-body {
            padding: 1.5rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
        }

        .service-detail {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .detail-label {
            font-size: 0.75rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-value {
            font-weight: 600;
        }

        .detail-value.large {
            font-size: 1.125rem;
        }

        .service-type {
            background: #dbeafe;
            color: #1e40af;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            display: inline-block;
        }

        .history-footer {
            padding: 1rem 1.5rem;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }

        .btn-action {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 30px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: white;
            border: 1px solid #e2e8f0;
            text-decoration: none;
            color: #1e293b;
        }

        .btn-action:hover {
            background: #2563eb;
            color: white;
            border-color: #2563eb;
        }

        .btn-action.cancel:hover {
            background: #ef4444;
            border-color: #ef4444;
        }

        .btn-action.rebook:hover {
            background: #10b981;
            border-color: #10b981;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: white;
            border-radius: 24px;
            border: 2px dashed #e2e8f0;
        }

        .empty-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .empty-text {
            color: #64748b;
            margin-bottom: 2rem;
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
            max-width: 400px;
            width: 90%;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
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

        .modal-body {
            margin-bottom: 1.5rem;
        }

        .modal-footer {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
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
            
            .history-body {
                grid-template-columns: 1fr;
            }
            
            .history-footer {
                flex-direction: column;
            }
            
            .btn-action {
                width: 100%;
                justify-content: center;
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
            
            .filter-section {
                flex-direction: column;
            }
            
            .filter-actions {
                width: 100%;
            }
            
            .btn-filter,
            .btn-reset {
                flex: 1;
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
            <h2>CASMS</h2>
            <nav>
                <a href="dashboard.php">Dashboard</a>
                <a href="vehicles.php">Vehicles</a>
                <a href="services.php">Services</a>
                <a href="book_service.php">Book Service</a>
                <a href="service_history.php" class="active">Service History</a>
                <a href="spareparts.php">Spare Parts</a>
                <a href="profile.php">Profile</a>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Navbar -->
            <div class="navbar">
                <div class="navbar-left">
                    <div class="welcome-section">
                        <div class="welcome-header">
                            <div class="title-wrapper">
                                <span class="greeting-badge">SERVICE HISTORY</span>
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
                                    <span class="chip-icon">🔧</span>
                                    <span class="chip-text"><?php echo $stats['total'] ?? 0; ?> Services</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="status-message">
                            <div class="status-icon">
                                <span class="pulse-dot"></span>
                                <span>Service Status</span>
                            </div>
                            <div class="message-text">
                                <?php if($upcoming['upcoming'] > 0): ?>
                                    <span class="highlight"><?php echo $upcoming['upcoming']; ?> upcoming</span> appointments
                                <?php else: ?>
                                    <span class="highlight">No upcoming</span> appointments
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
                            <div class="user-profile" onclick="toggleDropdown()" id="userProfile">
                                <div class="avatar">
                                    <span><?php echo strtoupper(substr(htmlspecialchars($_SESSION['user_name'] ?? 'U'), 0, 1)); ?></span>
                                </div>
                                <div class="user-info">
                                    <span class="user-name"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?></span>
                                    <span class="user-role">Garage Manager</span>
                                </div>
                                <svg class="dropdown-icon" viewBox="0 0 24 24">
                                    <path d="M7 10L12 15L17 10H7Z"/>
                                </svg>
                            </div>
                            
                            <div class="dropdown-menu" id="dropdownMenu" style="width: 240px;">
                                <div class="dropdown-header">
                                    <div class="user-name"><?php echo htmlspecialchars($user['name'] ?? 'User'); ?></div>
                                    <div class="user-email"><?php echo htmlspecialchars($user['email'] ?? 'user@example.com'); ?></div>
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

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stats-card">
                    <div class="stats-icon">📋</div>
                    <div class="stats-value"><?php echo $stats['total'] ?? 0; ?></div>
                    <div class="stats-label">Total Services</div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">⏳</div>
                    <div class="stats-value"><?php echo $stats['pending'] ?? 0; ?></div>
                    <div class="stats-label">Pending</div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">🔧</div>
                    <div class="stats-value"><?php echo $stats['in_progress'] ?? 0; ?></div>
                    <div class="stats-label">In Progress</div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">✅</div>
                    <div class="stats-value"><?php echo $stats['completed'] ?? 0; ?></div>
                    <div class="stats-label">Completed</div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">❌</div>
                    <div class="stats-value"><?php echo $stats['cancelled'] ?? 0; ?></div>
                    <div class="stats-label">Cancelled</div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">🚗</div>
                    <div class="stats-value"><?php echo $stats['vehicles_serviced'] ?? 0; ?></div>
                    <div class="stats-label">Vehicles Serviced</div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" style="display: contents;">
                    <div class="filter-group">
                        <span class="filter-label">Status</span>
                        <select name="status" class="filter-select" onchange="this.form.submit()">
                            <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="Pending" <?php echo $statusFilter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="In Progress" <?php echo $statusFilter === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="Completed" <?php echo $statusFilter === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="Cancelled" <?php echo $statusFilter === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <span class="filter-label">Date Range</span>
                        <select name="date_range" class="filter-select" onchange="this.form.submit()">
                            <option value="all" <?php echo $dateRange === 'all' ? 'selected' : ''; ?>>All Time</option>
                            <option value="upcoming" <?php echo $dateRange === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                            <option value="past" <?php echo $dateRange === 'past' ? 'selected' : ''; ?>>Past</option>
                            <option value="this_month" <?php echo $dateRange === 'this_month' ? 'selected' : ''; ?>>This Month</option>
                            <option value="last_month" <?php echo $dateRange === 'last_month' ? 'selected' : ''; ?>>Last Month</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <span class="filter-label">Search</span>
                        <input type="text" name="search" class="filter-input" placeholder="Vehicle or service..." value="<?php echo htmlspecialchars($searchTerm); ?>">
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn-filter">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="service_history.php" class="btn-reset">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- History Grid -->
            <?php if(empty($bookings)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📅</div>
                    <div class="empty-title">No Service History Found</div>
                    <div class="empty-text">
                        <?php if(!empty($searchTerm) || $statusFilter !== 'all' || $dateRange !== 'all'): ?>
                            No services match your filters. Try adjusting your search criteria.
                        <?php else: ?>
                            You haven't booked any services yet. Book your first service to get started.
                        <?php endif; ?>
                    </div>
                    <a href="book_service.php" class="btn btn-primary" style="text-decoration: none; display: inline-block; padding: 0.75rem 2rem;">
                        <i class="fas fa-plus"></i> Book a Service
                    </a>
                </div>
            <?php else: ?>
                <div class="history-grid">
                    <?php foreach($bookings as $booking): 
                        $status = $booking['status'];
                        $statusStyle = $statusConfig[$status] ?? ['bg' => '#e2e8f0', 'color' => '#475569', 'icon' => '📌'];
                        $today = new DateTime();
                        $appt = new DateTime($booking['appointment_date']);
                        $diff = $today->diff($appt)->days;
                    ?>
                        <div class="history-card">
                            <div class="history-header">
                                <div class="vehicle-info">
                                    <div class="vehicle-icon">
                                        <?php
                                        $icons = [
                                            'Toyota' => '🇯🇵', 'Honda' => '🇯🇵', 'Hyundai' => '🇰🇷',
                                            'Maruti' => '🇮🇳', 'Tata' => '🇮🇳', 'Mahindra' => '🇮🇳',
                                            'BMW' => '🇩🇪', 'Mercedes' => '🇩🇪', 'Audi' => '🇩🇪',
                                            'default' => '🚗'
                                        ];
                                        echo $icons[$booking['brand']] ?? $icons['default'];
                                        ?>
                                    </div>
                                    <div class="vehicle-details">
                                        <span class="vehicle-name"><?php echo htmlspecialchars($booking['brand'] . ' ' . $booking['model']); ?></span>
                                        <span class="vehicle-plate"><?php echo htmlspecialchars($booking['license_plate']); ?></span>
                                    </div>
                                </div>
                                <span class="status-badge" style="background: <?php echo $statusStyle['bg']; ?>; color: <?php echo $statusStyle['color']; ?>;">
                                    <span><?php echo $statusStyle['icon']; ?></span>
                                    <?php echo htmlspecialchars($status); ?>
                                </span>
                            </div>

                            <div class="history-body">
                                <div class="service-detail">
                                    <span class="detail-label">Service Type</span>
                                    <span class="detail-value large"><?php echo htmlspecialchars($booking['service_type']); ?></span>
                                </div>
                                
                                <div class="service-detail">
                                    <span class="detail-label">Appointment Date</span>
                                    <span class="detail-value">
                                        <i class="fas fa-calendar"></i> 
                                        <?php echo date('F j, Y', strtotime($booking['appointment_date'])); ?>
                                    </span>
                                    <?php if($status === 'Pending' && $appt > $today): ?>
                                        <span style="font-size: 0.75rem; color: #2563eb;">
                                            in <?php echo $diff; ?> day<?php echo $diff > 1 ? 's' : ''; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="service-detail">
                                    <span class="detail-label">Vehicle Details</span>
                                    <span class="detail-value">
                                        <?php echo $booking['year']; ?> • <?php echo $booking['color'] ?? 'N/A'; ?>
                                    </span>
                                </div>
                                
                                <div class="service-detail">
                                    <span class="detail-label">Booked On</span>
                                    <span class="detail-value">
                                        <?php echo date('M d, Y', strtotime($booking['created_at'])); ?>
                                    </span>
                                </div>
                                
                                <?php if(!empty($booking['notes'])): ?>
                                <div class="service-detail" style="grid-column: span 2;">
                                    <span class="detail-label">Notes</span>
                                    <span class="detail-value" style="font-style: italic;">
                                        "<?php echo htmlspecialchars($booking['notes']); ?>"
                                    </span>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="history-footer">
                                <?php if($status === 'Pending'): ?>
                                    <?php if($appt >= $today): ?>
                                        <button class="btn-action cancel" onclick="cancelBooking(<?php echo $booking['id']; ?>)">
                                            <i class="fas fa-times"></i> Cancel
                                        </button>
                                    <?php endif; ?>
                                    <a href="book_service.php?rebook=<?php echo $booking['id']; ?>" class="btn-action">
                                        <i class="fas fa-calendar-plus"></i> Reschedule
                                    </a>
                                <?php elseif($status === 'Completed'): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                        <button type="submit" name="rebook" class="btn-action rebook">
                                            <i class="fas fa-redo"></i> Rebook
                                        </button>
                                    </form>
                                <?php elseif($status === 'Cancelled'): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                        <button type="submit" name="rebook" class="btn-action">
                                            <i class="fas fa-redo"></i> Book Again
                                        </button>
                                    </form>
                                <?php endif; ?>
                                
                                <a href="invoice.php?booking_id=<?php echo $booking['id']; ?>" class="btn-action">
                                    <i class="fas fa-file-invoice"></i> Invoice
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Cancel Confirmation Modal -->
    <div class="modal" id="cancelModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Cancel Appointment</h3>
                <button class="modal-close" onclick="closeModal('cancelModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to cancel this appointment? This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('cancelModal')">No, Keep It</button>
                <form method="POST" id="cancelForm" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="booking_id" id="cancelBookingId">
                    <button type="submit" name="cancel_booking" class="btn btn-danger">Yes, Cancel</button>
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

        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', initializeNotifications);

        // Clean up interval when page unloads
        window.addEventListener('beforeunload', function() {
            if(notificationCheckInterval) {
                clearInterval(notificationCheckInterval);
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

        // Modal
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('show');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }

        window.addEventListener('click', (e) => {
            if (e.target.classList.contains('modal')) {
                closeModal(e.target.id);
            }
        });

        // Cancel booking
        function cancelBooking(bookingId) {
            document.getElementById('cancelBookingId').value = bookingId;
            openModal('cancelModal');
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

        // Show session messages
        <?php if(isset($_SESSION['success'])): ?>
            showToast('✅', '<?php echo htmlspecialchars($_SESSION['success']); ?>');
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if(!empty($error)): ?>
            showToast('❌', '<?php echo htmlspecialchars($error); ?>', 'error');
        <?php endif; ?>
    </script>
</body>
</html>