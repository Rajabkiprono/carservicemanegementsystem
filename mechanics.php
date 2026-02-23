<?php
session_start();
if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit();
}

require_once "database.php";

// Check if user is mechanic or admin
$db = (new Database())->connect();
$roleStmt = $db->prepare("SELECT role FROM users WHERE id = ?");
$roleStmt->execute([$_SESSION['user_id']]);
$userRole = $roleStmt->fetchColumn();

if(!in_array($userRole, ['mechanic', 'admin'])) {
    header("Location: dashboard.php");
    exit();
}

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

// Handle start job
if(isset($_POST['start_job'])) {
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid security token");
        }

        $assignmentId = intval($_POST['assignment_id']);
        
        $stmt = $db->prepare("
            UPDATE assignments 
            SET status = 'in_progress', started_at = NOW() 
            WHERE id = ? AND mechanic_id = ? AND status = 'assigned'
        ");
        $stmt->execute([$assignmentId, $_SESSION['user_id']]);

        if($stmt->rowCount() > 0) {
            // Get booking details for notification
            $bookingStmt = $db->prepare("
                SELECT a.booking_id, b.user_id 
                FROM assignments a
                JOIN book_service b ON a.booking_id = b.id
                WHERE a.id = ?
            ");
            $bookingStmt->execute([$assignmentId]);
            $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);

            if($booking) {
                // Notify user
                $notifStmt = $db->prepare("
                    INSERT INTO notifications (user_id, type, title, message, data, created_at)
                    VALUES (?, 'info', 'Service Started', 'A mechanic has started working on your vehicle', ?, NOW())
                ");
                $data = json_encode(['assignment_id' => $assignmentId]);
                $notifStmt->execute([$booking['user_id'], $data]);
            }

            $_SESSION['success'] = "Job started successfully";
        } else {
            throw new Exception("Unable to start job");
        }
        
        header("Location: mechanics.php");
        exit();

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle complete job
if(isset($_POST['complete_job'])) {
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid security token");
        }

        $assignmentId = intval($_POST['assignment_id']);
        $completionNotes = $_POST['completion_notes'] ?? '';
        
        $db->beginTransaction();

        $stmt = $db->prepare("
            UPDATE assignments 
            SET status = 'completed', completed_at = NOW(), notes = CONCAT(IFNULL(notes, ''), '\n[', NOW(), '] Completion: ', ?)
            WHERE id = ? AND mechanic_id = ? AND status = 'in_progress'
        ");
        $stmt->execute([$completionNotes, $assignmentId, $_SESSION['user_id']]);

        if($stmt->rowCount() > 0) {
            // Get booking details
            $bookingStmt = $db->prepare("
                SELECT a.booking_id, b.user_id, b.id as booking_id
                FROM assignments a
                JOIN book_service b ON a.booking_id = b.id
                WHERE a.id = ?
            ");
            $bookingStmt->execute([$assignmentId]);
            $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);

            if($booking) {
                // Update booking status
                $updateBooking = $db->prepare("
                    UPDATE book_service SET status = 'completed' WHERE id = ?
                ");
                $updateBooking->execute([$booking['booking_id']]);

                // Notify user
                $notifStmt = $db->prepare("
                    INSERT INTO notifications (user_id, type, title, message, data, created_at)
                    VALUES (?, 'success', 'Service Completed', 'Your vehicle service has been completed successfully', ?, NOW())
                ");
                $data = json_encode(['assignment_id' => $assignmentId]);
                $notifStmt->execute([$booking['user_id'], $data]);

                // Notify admin
                $adminNotif = $db->prepare("
                    INSERT INTO notifications (user_id, type, title, message, data, created_at)
                    SELECT id, 'info', 'Job Completed', 
                           CONCAT('Mechanic completed job #', ?, ' for booking #', ?),
                           ?, NOW()
                    FROM users WHERE role = 'admin'
                ");
                $adminData = json_encode(['assignment_id' => $assignmentId, 'booking_id' => $booking['booking_id']]);
                $adminNotif->execute([$assignmentId, $booking['booking_id'], $adminData]);
            }

            $db->commit();
            $_SESSION['success'] = "Job marked as completed";
        } else {
            throw new Exception("Unable to complete job");
        }
        
        header("Location: mechanics.php");
        exit();

    } catch (Exception $e) {
        $db->rollBack();
        $error = $e->getMessage();
    }
}

// Handle add note
if(isset($_POST['add_note'])) {
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid security token");
        }

        $assignmentId = intval($_POST['assignment_id']);
        $note = $_POST['note'];
        
        $stmt = $db->prepare("
            UPDATE assignments 
            SET notes = CONCAT(IFNULL(notes, ''), '\n[', NOW(), '] ', ?)
            WHERE id = ? AND mechanic_id = ?
        ");
        $stmt->execute([$note, $assignmentId, $_SESSION['user_id']]);

        $_SESSION['success'] = "Note added successfully";
        header("Location: mechanics.php");
        exit();

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get filter parameters
$status = isset($_GET['status']) ? $_GET['status'] : 'all';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

// Build query for assignments
$query = "
    SELECT 
        a.*,
        b.id as booking_id,
        b.service_type,
        b.appointment_date,
        b.notes as booking_notes,
        u.name as customer_name,
        u.phone as customer_phone,
        u.email as customer_email,
        v.brand,
        v.model,
        v.year,
        v.license_plate,
        v.color,
        p.amount as payment_amount,
        p.status as payment_status,
        CONCAT(assigner.name) as assigned_by_name
    FROM assignments a
    JOIN book_service b ON a.booking_id = b.id
    JOIN users u ON b.user_id = u.id
    JOIN vehicles v ON b.vehicle_id = v.id
    LEFT JOIN payments p ON b.payment_id = p.id
    LEFT JOIN users assigner ON a.assigned_by = assigner.id
    WHERE a.mechanic_id = ?
";

$params = [$_SESSION['user_id']];

if($status !== 'all') {
    $query .= " AND a.status = ?";
    $params[] = $status;
}

$query .= " AND DATE(a.assigned_at) BETWEEN ? AND ?";
$params[] = $dateFrom;
$params[] = $dateTo;

$query .= " ORDER BY a.assigned_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$statsStmt = $db->prepare("
    SELECT 
        COUNT(*) as total_jobs,
        SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END) as assigned,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
        AVG(TIMESTAMPDIFF(MINUTE, started_at, completed_at)) as avg_completion_time
    FROM assignments
    WHERE mechanic_id = ?
");
$statsStmt->execute([$_SESSION['user_id']]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Get user details
$userStmt = $db->prepare("SELECT name, email FROM users WHERE id = ?");
$userStmt->execute([$_SESSION['user_id']]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

// Get unread notifications count
$notifCountStmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
$notifCountStmt->execute([$_SESSION['user_id']]);
$unreadNotifications = $notifCountStmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get recent notifications
$recentNotifStmt = $db->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 5
");
$recentNotifStmt->execute([$_SESSION['user_id']]);
$recentNotifications = $recentNotifStmt->fetchAll(PDO::FETCH_ASSOC);

// Get greeting
$currentHour = (int)date('H');
$greeting = $currentHour < 12 ? 'Good Morning' : ($currentHour < 17 ? 'Good Afternoon' : 'Good Evening');

// Status colors
$statusColors = [
    'assigned' => ['bg' => '#fef3c7', 'color' => '#92400e', 'icon' => '📋', 'label' => 'Assigned'],
    'in_progress' => ['bg' => '#dbeafe', 'color' => '#1e40af', 'icon' => '🔧', 'label' => 'In Progress'],
    'completed' => ['bg' => '#d1fae5', 'color' => '#065f46', 'icon' => '✅', 'label' => 'Completed'],
    'cancelled' => ['bg' => '#fee2e2', 'color' => '#b91c1c', 'icon' => '❌', 'label' => 'Cancelled']
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CASMS | Mechanic Dashboard</title>
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
            border-left-color: #f59e0b !important;
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
            border-color: #f59e0b !important;
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

        body.dark .jobs-grid {
            background: #1e293b;
            border-color: #334155;
        }

        body.dark .job-card {
            background: #1e293b;
            border-color: #334155;
        }

        body.dark .job-card:hover {
            border-color: #f59e0b;
        }

        body.dark .job-header {
            border-color: #334155;
        }

        body.dark .job-details {
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

        body.dark .modal-content {
            background: #1e293b;
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
            background-color: #f59e0b;
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
            color: #f59e0b;
            background: rgba(245, 158, 11, 0.1);
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
            border-left: 3px solid #f59e0b;
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
            border-color: #f59e0b;
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
            border-color: #f59e0b;
        }

        .avatar {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, #f59e0b, #d97706);
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
            box-shadow: 0 10px 25px -5px rgba(245, 158, 11, 0.2);
            border-color: #f59e0b;
        }

        .stats-icon {
            width: 48px;
            height: 48px;
            background: #fef3c7;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: #f59e0b;
            margin-bottom: 1rem;
        }

        .stats-value {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .stats-label {
            color: #64748b;
            font-size: 0.875rem;
        }

        .stats-trend {
            font-size: 0.75rem;
            color: #f59e0b;
            margin-top: 0.5rem;
        }

        /* Filter Section */
        .filter-section {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .filter-row {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: flex-end;
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
            border-color: #f59e0b;
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
            align-items: flex-end;
        }

        .btn-filter {
            padding: 0.75rem 1.5rem;
            background: #f59e0b;
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-filter:hover {
            background: #d97706;
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
            border-color: #f59e0b;
            color: #f59e0b;
        }

        /* Jobs Grid */
        .jobs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 1.5rem;
        }

        .job-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            overflow: hidden;
            transition: all 0.3s;
        }

        .job-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
            border-color: #f59e0b;
        }

        .job-header {
            padding: 1.25rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .job-status {
            padding: 0.35rem 1rem;
            border-radius: 40px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .job-date {
            font-size: 0.875rem;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .job-body {
            padding: 1.5rem;
        }

        .customer-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .customer-avatar {
            width: 48px;
            height: 48px;
            background: #f59e0b;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.25rem;
        }

        .customer-details h4 {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .customer-details p {
            font-size: 0.875rem;
            color: #64748b;
        }

        .vehicle-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .vehicle-icon {
            width: 48px;
            height: 48px;
            background: #f1f5f9;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .vehicle-details h4 {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .vehicle-details p {
            font-size: 0.875rem;
            color: #64748b;
        }

        .service-details {
            background: #f8fafc;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }

        .service-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .service-row:last-child {
            border-bottom: none;
        }

        .service-label {
            color: #64748b;
            font-size: 0.875rem;
        }

        .service-value {
            font-weight: 600;
        }

        .payment-status {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.75rem;
            border-radius: 40px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .payment-status.pending {
            background: #fef3c7;
            color: #92400e;
        }

        .payment-status.verified {
            background: #dbeafe;
            color: #1e40af;
        }

        .payment-status.completed {
            background: #d1fae5;
            color: #065f46;
        }

        .job-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1.5rem;
        }

        .btn-job {
            flex: 1;
            padding: 0.75rem;
            border: none;
            border-radius: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-job.start {
            background: #dbeafe;
            color: #1e40af;
        }

        .btn-job.start:hover {
            background: #2563eb;
            color: white;
        }

        .btn-job.complete {
            background: #d1fae5;
            color: #065f46;
        }

        .btn-job.complete:hover {
            background: #10b981;
            color: white;
        }

        .btn-job.note {
            background: #f1f5f9;
            color: #475569;
        }

        .btn-job.note:hover {
            background: #64748b;
            color: white;
        }

        .btn-job.view {
            background: #f1f5f9;
            color: #475569;
        }

        .btn-job.view:hover {
            background: #f59e0b;
            color: white;
        }

        .job-notes {
            margin-top: 1rem;
            padding: 1rem;
            background: #f8fafc;
            border-radius: 12px;
            font-size: 0.875rem;
            color: #64748b;
            max-height: 100px;
            overflow-y: auto;
        }

        .job-notes strong {
            color: #1e293b;
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
            max-width: 450px;
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

        .modal-body {
            margin-bottom: 1.5rem;
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
        @media (max-width: 1024px) {
            .filter-row {
                flex-direction: column;
            }
            
            .jobs-grid {
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
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
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
            <h2>CASMS Mechanic</h2>
            <nav>
                <a href="dashboard.php">Dashboard</a>
                <a href="vehicles.php">Vehicles</a>
                <a href="services.php">Services</a>
                <a href="mechanics.php" class="active">🔧 My Jobs</a>
                <a href="service_history.php">Service History</a>
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
                                <span class="greeting-badge">MECHANIC DASHBOARD</span>
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
                                    <span class="chip-text"><?php echo $stats['in_progress']; ?> Active</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="status-message">
                            <div class="status-icon">
                                <span class="pulse-dot"></span>
                                <span>Jobs Summary</span>
                            </div>
                            <div class="message-text">
                                <span class="highlight"><?php echo $stats['assigned']; ?> assigned</span> · 
                                <span class="highlight"><?php echo $stats['in_progress']; ?> in progress</span> · 
                                <span class="highlight"><?php echo $stats['completed']; ?> completed</span>
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
                                    <a href="notifications.php" style="font-size: 0.75rem; color: #f59e0b;">View All</a>
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
                                    <span><?php echo strtoupper(substr(htmlspecialchars($user['name']), 0, 1)); ?></span>
                                </div>
                                <div class="user-info">
                                    <span class="user-name"><?php echo htmlspecialchars($user['name']); ?></span>
                                    <span class="user-role">Mechanic</span>
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

            <?php if(isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stats-card">
                    <div class="stats-icon">📋</div>
                    <div class="stats-value"><?php echo $stats['total_jobs']; ?></div>
                    <div class="stats-label">Total Jobs</div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">📋</div>
                    <div class="stats-value"><?php echo $stats['assigned']; ?></div>
                    <div class="stats-label">Assigned</div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">🔧</div>
                    <div class="stats-value"><?php echo $stats['in_progress']; ?></div>
                    <div class="stats-label">In Progress</div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">✅</div>
                    <div class="stats-value"><?php echo $stats['completed']; ?></div>
                    <div class="stats-label">Completed</div>
                </div>
                <?php if($stats['avg_completion_time']): ?>
                <div class="stats-card">
                    <div class="stats-icon">⏱️</div>
                    <div class="stats-value"><?php echo round($stats['avg_completion_time']); ?> min</div>
                    <div class="stats-label">Avg. Completion Time</div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" id="filterForm">
                    <div class="filter-row">
                        <div class="filter-group">
                            <span class="filter-label">Status</span>
                            <select name="status" class="filter-select" onchange="this.form.submit()">
                                <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Jobs</option>
                                <option value="assigned" <?php echo $status === 'assigned' ? 'selected' : ''; ?>>Assigned</option>
                                <option value="in_progress" <?php echo $status === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <span class="filter-label">Date From</span>
                            <input type="date" name="date_from" class="filter-input" value="<?php echo $dateFrom; ?>" onchange="this.form.submit()">
                        </div>

                        <div class="filter-group">
                            <span class="filter-label">Date To</span>
                            <input type="date" name="date_to" class="filter-input" value="<?php echo $dateTo; ?>" onchange="this.form.submit()">
                        </div>

                        <div class="filter-actions">
                            <button type="submit" class="btn-filter">
                                <i class="fas fa-filter"></i> Apply
                            </button>
                            <a href="mechanics.php" class="btn-reset">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Jobs Grid -->
            <?php if(empty($assignments)): ?>
                <div style="text-align: center; padding: 4rem 2rem;">
                    <div style="font-size: 4rem; margin-bottom: 1rem; opacity: 0.5;">🔧</div>
                    <h3 style="margin-bottom: 0.5rem;">No Jobs Found</h3>
                    <p style="color: #64748b;">No job assignments match your filters.</p>
                </div>
            <?php else: ?>
                <div class="jobs-grid">
                    <?php foreach($assignments as $job): 
                        $statusStyle = $statusColors[$job['status']] ?? ['bg' => '#e2e8f0', 'color' => '#475569', 'icon' => '📌', 'label' => ucfirst($job['status'])];
                        $paymentStatusClass = $job['payment_status'] ?? 'pending';
                    ?>
                        <div class="job-card">
                            <div class="job-header">
                                <div class="job-status" style="background: <?php echo $statusStyle['bg']; ?>; color: <?php echo $statusStyle['color']; ?>;">
                                    <?php echo $statusStyle['icon']; ?> <?php echo $statusStyle['label']; ?>
                                </div>
                                <div class="job-date">
                                    <i class="far fa-calendar"></i>
                                    <?php echo date('M d, Y', strtotime($job['assigned_at'])); ?>
                                </div>
                            </div>

                            <div class="job-body">
                                <div class="customer-info">
                                    <div class="customer-avatar">
                                        <?php echo strtoupper(substr($job['customer_name'], 0, 1)); ?>
                                    </div>
                                    <div class="customer-details">
                                        <h4><?php echo htmlspecialchars($job['customer_name']); ?></h4>
                                        <p>
                                            <i class="fas fa-phone"></i> <?php echo htmlspecialchars($job['customer_phone'] ?? 'N/A'); ?>
                                        </p>
                                    </div>
                                </div>

                                <div class="vehicle-info">
                                    <div class="vehicle-icon">
                                        🚗
                                    </div>
                                    <div class="vehicle-details">
                                        <h4><?php echo htmlspecialchars($job['brand'] . ' ' . $job['model']); ?></h4>
                                        <p>
                                            <?php echo $job['year']; ?> • <?php echo htmlspecialchars($job['license_plate']); ?>
                                            <?php if($job['color']): ?> • <?php echo $job['color']; ?><?php endif; ?>
                                        </p>
                                    </div>
                                </div>

                                <div class="service-details">
                                    <div class="service-row">
                                        <span class="service-label">Service Type:</span>
                                        <span class="service-value"><?php echo htmlspecialchars($job['service_type']); ?></span>
                                    </div>
                                    <div class="service-row">
                                        <span class="service-label">Appointment:</span>
                                        <span class="service-value"><?php echo date('M d, Y h:i A', strtotime($job['appointment_date'])); ?></span>
                                    </div>
                                    <?php if($job['payment_amount']): ?>
                                    <div class="service-row">
                                        <span class="service-label">Payment:</span>
                                        <span class="service-value">
                                            <span class="payment-status <?php echo $paymentStatusClass; ?>">
                                                Ksh <?php echo number_format($job['payment_amount'], 2); ?> • <?php echo ucfirst($job['payment_status']); ?>
                                            </span>
                                        </span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if($job['started_at']): ?>
                                    <div class="service-row">
                                        <span class="service-label">Started:</span>
                                        <span class="service-value"><?php echo date('M d, h:i A', strtotime($job['started_at'])); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if($job['completed_at']): ?>
                                    <div class="service-row">
                                        <span class="service-label">Completed:</span>
                                        <span class="service-value"><?php echo date('M d, h:i A', strtotime($job['completed_at'])); ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <?php if(!empty($job['booking_notes'])): ?>
                                <div class="job-notes">
                                    <strong>Customer Notes:</strong>
                                    <p><?php echo nl2br(htmlspecialchars($job['booking_notes'])); ?></p>
                                </div>
                                <?php endif; ?>

                                <?php if(!empty($job['notes'])): ?>
                                <div class="job-notes">
                                    <strong>Mechanic Notes:</strong>
                                    <p><?php echo nl2br(htmlspecialchars($job['notes'])); ?></p>
                                </div>
                                <?php endif; ?>

                                <div class="job-actions">
                                    <?php if($job['status'] === 'assigned'): ?>
                                        <form method="POST" style="flex: 1;">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="assignment_id" value="<?php echo $job['id']; ?>">
                                            <button type="submit" name="start_job" class="btn-job start">
                                                <i class="fas fa-play"></i> Start Job
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if($job['status'] === 'in_progress'): ?>
                                        <button class="btn-job complete" onclick="completeJob(<?php echo $job['id']; ?>, '<?php echo htmlspecialchars($job['customer_name']); ?>', '<?php echo htmlspecialchars($job['service_type']); ?>')">
                                            <i class="fas fa-check"></i> Complete
                                        </button>
                                    <?php endif; ?>

                                    <?php if(in_array($job['status'], ['assigned', 'in_progress'])): ?>
                                        <button class="btn-job note" onclick="addNote(<?php echo $job['id']; ?>)">
                                            <i class="fas fa-sticky-note"></i> Add Note
                                        </button>
                                    <?php endif; ?>

                                    <button class="btn-job view" onclick="viewJobDetails(<?php echo $job['id']; ?>)">
                                        <i class="fas fa-eye"></i> Details
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Complete Job Modal -->
    <div class="modal" id="completeModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Complete Job</h3>
                <button class="modal-close" onclick="closeModal('completeModal')">&times;</button>
            </div>
            <form method="POST" id="completeForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="assignment_id" id="completeAssignmentId">
                
                <div class="modal-body">
                    <p>Are you sure you want to mark this job as completed?</p>
                    <div style="background: #f8fafc; padding: 1rem; border-radius: 12px; margin: 1rem 0;">
                        <div style="margin-bottom: 0.5rem;">
                            <strong>Customer:</strong> <span id="completeCustomerName"></span>
                        </div>
                        <div>
                            <strong>Service:</strong> <span id="completeServiceType"></span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Completion Notes (Optional)</label>
                        <textarea name="completion_notes" class="form-input" rows="3" placeholder="Enter any notes about the completed work..."></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-reset" onclick="closeModal('completeModal')">Cancel</button>
                    <button type="submit" name="complete_job" class="btn-filter">Complete Job</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Note Modal -->
    <div class="modal" id="noteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Add Note</h3>
                <button class="modal-close" onclick="closeModal('noteModal')">&times;</button>
            </div>
            <form method="POST" id="noteForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="assignment_id" id="noteAssignmentId">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Your Note</label>
                        <textarea name="note" class="form-input" rows="4" required placeholder="Enter your notes about this job..."></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-reset" onclick="closeModal('noteModal')">Cancel</button>
                    <button type="submit" name="add_note" class="btn-filter">Add Note</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Job Details Modal -->
    <div class="modal" id="detailsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Job Details</h3>
                <button class="modal-close" onclick="closeModal('detailsModal')">&times;</button>
            </div>
            <div class="modal-body" id="detailsModalBody">
                <!-- Content will be loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button class="btn-filter" onclick="closeModal('detailsModal')">Close</button>
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
            fetch('get_notifications.php')
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

        // Complete job
        function completeJob(assignmentId, customerName, serviceType) {
            document.getElementById('completeAssignmentId').value = assignmentId;
            document.getElementById('completeCustomerName').textContent = customerName;
            document.getElementById('completeServiceType').textContent = serviceType;
            openModal('completeModal');
        }

        // Add note
        function addNote(assignmentId) {
            document.getElementById('noteAssignmentId').value = assignmentId;
            openModal('noteModal');
        }

        // View job details
        function viewJobDetails(assignmentId) {
            // In a real application, you would fetch details via AJAX
            document.getElementById('detailsModalBody').innerHTML = `
                <p>Detailed job information would be displayed here.</p>
                <p>This includes customer contact, vehicle specifications, service history, etc.</p>
            `;
            openModal('detailsModal');
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