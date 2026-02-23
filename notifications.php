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

// Handle mark as read
if(isset($_POST['mark_read'])) {
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid security token");
        }

        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND id = ?");
        $stmt->execute([$_SESSION['user_id'], $_POST['notification_id']]);
        
        echo json_encode(['success' => true]);
        exit();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit();
    }
}

// Handle mark all as read
if(isset($_POST['mark_all_read'])) {
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid security token");
        }

        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$_SESSION['user_id']]);
        
        $_SESSION['success'] = "All notifications marked as read";
        header("Location: notifications.php");
        exit();
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle delete notification
if(isset($_POST['delete'])) {
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid security token");
        }

        $stmt = $db->prepare("DELETE FROM notifications WHERE user_id = ? AND id = ?");
        $stmt->execute([$_SESSION['user_id'], $_POST['notification_id']]);
        
        $_SESSION['success'] = "Notification deleted";
        header("Location: notifications.php");
        exit();
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle delete all
if(isset($_POST['delete_all'])) {
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid security token");
        }

        $stmt = $db->prepare("DELETE FROM notifications WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        
        $_SESSION['success'] = "All notifications deleted";
        header("Location: notifications.php");
        exit();
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get filter parameters
$type = isset($_GET['type']) ? $_GET['type'] : 'all';
$read = isset($_GET['read']) ? $_GET['read'] : 'all';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Build query
$query = "SELECT * FROM notifications WHERE user_id = :user_id";
$countQuery = "SELECT COUNT(*) as total FROM notifications WHERE user_id = :user_id";
$params = [":user_id" => $_SESSION['user_id']];

// Apply type filter
if($type !== 'all') {
    $query .= " AND type = :type";
    $countQuery .= " AND type = :type";
    $params[":type"] = $type;
}

// Apply read filter
if($read !== 'all') {
    $isRead = ($read === 'read') ? 1 : 0;
    $query .= " AND is_read = :is_read";
    $countQuery .= " AND is_read = :is_read";
    $params[":is_read"] = $isRead;
}

// Add ordering and pagination
$query .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";

// Fetch notifications
try {
    // Get total count for pagination
    $countStmt = $db->prepare($countQuery);
    foreach($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalNotifications = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalNotifications / $limit);

    // Get notifications
    $stmt = $db->prepare($query);
    foreach($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
    $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get statistics
    $statsStmt = $db->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN type = 'emergency' THEN 1 ELSE 0 END) as emergency,
            SUM(CASE WHEN type = 'info' THEN 1 ELSE 0 END) as info,
            SUM(CASE WHEN type = 'success' THEN 1 ELSE 0 END) as success,
            SUM(CASE WHEN type = 'warning' THEN 1 ELSE 0 END) as warning,
            SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread
        FROM notifications 
        WHERE user_id = ?
    ");
    $statsStmt->execute([$_SESSION['user_id']]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $notifications = [];
    $stats = ['total' => 0, 'emergency' => 0, 'info' => 0, 'success' => 0, 'warning' => 0, 'unread' => 0];
    $totalPages = 1;
    error_log("Error fetching notifications: " . $e->getMessage());
}

// Get user details
try {
    $userStmt = $db->prepare("SELECT name, email FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = ['name' => $_SESSION['user_name'] ?? 'User', 'email' => ''];
}

// Get greeting
$currentHour = (int)date('H');
$greeting = $currentHour < 12 ? 'Good Morning' : ($currentHour < 17 ? 'Good Afternoon' : 'Good Evening');

// Notification type configuration
$typeConfig = [
    'emergency' => [
        'icon' => '🚨',
        'bg' => '#fee2e2',
        'color' => '#b91c1c',
        'fa' => 'fa-exclamation-triangle'
    ],
    'info' => [
        'icon' => 'ℹ️',
        'bg' => '#dbeafe',
        'color' => '#1e40af',
        'fa' => 'fa-info-circle'
    ],
    'success' => [
        'icon' => '✅',
        'bg' => '#d1fae5',
        'color' => '#065f46',
        'fa' => 'fa-check-circle'
    ],
    'warning' => [
        'icon' => '⚠️',
        'bg' => '#fef3c7',
        'color' => '#92400e',
        'fa' => 'fa-exclamation-circle'
    ]
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CASMS | Notifications</title>
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

        body.dark .notifications-card {
            background: #1e293b;
            border-color: #334155;
        }

        body.dark .filter-section {
            background: #1e293b;
            border-color: #334155;
        }

        body.dark .filter-select {
            background: #0f172a;
            border-color: #334155;
            color: #e2e8f0;
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

        body.dark .pagination-item {
            background: #0f172a;
            border-color: #334155;
            color: #94a3b8;
        }

        body.dark .pagination-item.active {
            background: #2563eb;
            color: white;
            border-color: #2563eb;
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
            width: 240px;
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
        }

        .dropdown-header .user-email {
            font-size: 0.8rem;
            color: #64748b;
            margin-top: 0.25rem;
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
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stats-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 1.25rem;
            transition: all 0.3s;
            cursor: pointer;
        }

        .stats-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border-color: #2563eb;
        }

        .stats-card.active {
            border-color: #2563eb;
            background: #eff6ff;
        }

        .stats-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }

        .stats-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .stats-title {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .stats-number {
            font-size: 1.75rem;
            font-weight: 700;
        }

        /* Action Bar */
        .action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .action-bar h2 {
            font-size: 1.5rem;
            font-weight: 600;
        }

        .action-buttons {
            display: flex;
            gap: 0.75rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 40px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            font-family: 'Inter', sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: #2563eb;
            color: white;
        }

        .btn-primary:hover {
            background: #1d4ed8;
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.3);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid #e2e8f0;
            color: #475569;
        }

        .btn-outline:hover {
            background: #f8fafc;
            border-color: #2563eb;
            color: #2563eb;
        }

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        /* Filter Section */
        .filter-section {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
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
            font-size: 0.7rem;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 0.25rem;
            text-transform: uppercase;
        }

        .filter-select {
            width: 100%;
            padding: 0.6rem;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-family: 'Inter', sans-serif;
            font-size: 0.9rem;
            transition: all 0.2s;
        }

        .filter-select:focus {
            outline: none;
            border-color: #2563eb;
        }

        /* Notifications Card */
        .notifications-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 24px;
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .notifications-header {
            padding: 1.25rem 1.5rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .notifications-title {
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .notifications-count {
            background: #e2e8f0;
            padding: 0.25rem 0.75rem;
            border-radius: 40px;
            font-size: 0.8rem;
        }

        .notifications-list {
            min-height: 400px;
        }

        .notification-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            transition: all 0.2s;
            position: relative;
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
            width: 48px;
            height: 48px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .notification-content {
            flex: 1;
        }

        .notification-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.5rem;
        }

        .notification-title {
            font-weight: 600;
            font-size: 1rem;
        }

        .notification-time {
            font-size: 0.8rem;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .notification-message {
            color: #475569;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .notification-meta {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .notification-type {
            font-size: 0.75rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .notification-actions {
            display: flex;
            gap: 0.5rem;
            opacity: 0;
            transition: opacity 0.2s;
        }

        .notification-item:hover .notification-actions {
            opacity: 1;
        }

        .action-btn {
            width: 32px;
            height: 32px;
            border: none;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            background: white;
            border: 1px solid #e2e8f0;
        }

        .action-btn:hover {
            background: #f1f5f9;
            border-color: #2563eb;
        }

        .action-btn.mark-read:hover {
            color: #2563eb;
        }

        .action-btn.delete:hover {
            color: #ef4444;
            border-color: #ef4444;
        }

        /* Unread indicator */
        .unread-dot {
            width: 8px;
            height: 8px;
            background: #2563eb;
            border-radius: 50%;
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
        }

        .empty-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .empty-text {
            color: #64748b;
            margin-bottom: 2rem;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }

        .pagination-item {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s;
            background: white;
        }

        .pagination-item:hover {
            border-color: #2563eb;
            color: #2563eb;
        }

        .pagination-item.active {
            background: #2563eb;
            color: white;
            border-color: #2563eb;
        }

        .pagination-item.disabled {
            opacity: 0.5;
            cursor: not-allowed;
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

        /* Animations */
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .notification-item {
            animation: slideIn 0.3s ease;
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
            
            .notification-header {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .notification-actions {
                opacity: 1;
                margin-top: 0.5rem;
            }
            
            .action-bar {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .action-buttons {
                width: 100%;
            }
            
            .btn {
                flex: 1;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-section {
                flex-direction: column;
            }
            
            .notification-item {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .notification-icon {
                align-self: flex-start;
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
                <a href="emergency_services.php">🚨 Emergency</a>
                <a href="notifications.php" class="active">🔔 Notifications</a>
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
                                <span class="greeting-badge">NOTIFICATIONS</span>
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
                                    <span class="chip-icon">🔔</span>
                                    <span class="chip-text"><?php echo $stats['unread']; ?> Unread</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="status-message">
                            <div class="status-icon">
                                <span class="pulse-dot"></span>
                                <span>Notification Center</span>
                            </div>
                            <div class="message-text">
                                <span class="highlight"><?php echo $stats['unread']; ?> unread</span> notifications
                                <?php if($stats['emergency'] > 0): ?>
                                    • <span style="color: #dc2626;"><?php echo $stats['emergency']; ?> emergency</span>
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
                            
                            <div class="dropdown-menu" id="dropdownMenu">
                                <div class="dropdown-header">
                                    <div class="user-name"><?php echo htmlspecialchars($user['name']); ?></div>
                                    <div class="user-email"><?php echo htmlspecialchars($user['email']); ?></div>
                                </div>
                                <div class="dropdown-items">
                                    <a href="profile.php" class="dropdown-item">
                                        <span class="item-icon">👤</span>
                                        My Profile
                                    </a>
                                    <a href="notifications.php" class="dropdown-item">
                                        <span class="item-icon">🔔</span>
                                        Notifications
                                        <?php if($stats['unread'] > 0): ?>
                                            <span style="background: #ef4444; color: white; padding: 0.1rem 0.4rem; border-radius: 10px; font-size: 0.7rem; margin-left: auto;">
                                                <?php echo $stats['unread']; ?>
                                            </span>
                                        <?php endif; ?>
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

            <!-- Stats Cards -->
            <div class="stats-grid">
                <a href="?type=all&read=all" class="stats-card <?php echo $type === 'all' && $read === 'all' ? 'active' : ''; ?>">
                    <div class="stats-header">
                        <div class="stats-icon" style="background: #e2e8f0;">
                            📋
                        </div>
                        <div class="stats-title">All</div>
                    </div>
                    <div class="stats-number"><?php echo $stats['total']; ?></div>
                </a>

                <a href="?read=unread" class="stats-card <?php echo $read === 'unread' ? 'active' : ''; ?>">
                    <div class="stats-header">
                        <div class="stats-icon" style="background: #dbeafe;">
                            🔔
                        </div>
                        <div class="stats-title">Unread</div>
                    </div>
                    <div class="stats-number"><?php echo $stats['unread']; ?></div>
                </a>

                <a href="?type=emergency" class="stats-card <?php echo $type === 'emergency' ? 'active' : ''; ?>">
                    <div class="stats-header">
                        <div class="stats-icon" style="background: #fee2e2;">
                            🚨
                        </div>
                        <div class="stats-title">Emergency</div>
                    </div>
                    <div class="stats-number"><?php echo $stats['emergency']; ?></div>
                </a>

                <a href="?type=success" class="stats-card <?php echo $type === 'success' ? 'active' : ''; ?>">
                    <div class="stats-header">
                        <div class="stats-icon" style="background: #d1fae5;">
                            ✅
                        </div>
                        <div class="stats-title">Success</div>
                    </div>
                    <div class="stats-number"><?php echo $stats['success']; ?></div>
                </a>

                <a href="?type=warning" class="stats-card <?php echo $type === 'warning' ? 'active' : ''; ?>">
                    <div class="stats-header">
                        <div class="stats-icon" style="background: #fef3c7;">
                            ⚠️
                        </div>
                        <div class="stats-title">Warning</div>
                    </div>
                    <div class="stats-number"><?php echo $stats['warning']; ?></div>
                </a>

                <a href="?type=info" class="stats-card <?php echo $type === 'info' ? 'active' : ''; ?>">
                    <div class="stats-header">
                        <div class="stats-icon" style="background: #dbeafe;">
                            ℹ️
                        </div>
                        <div class="stats-title">Info</div>
                    </div>
                    <div class="stats-number"><?php echo $stats['info']; ?></div>
                </a>
            </div>

            <!-- Action Bar -->
            <div class="action-bar">
                <h2>All Notifications</h2>
                <div class="action-buttons">
                    <?php if($stats['unread'] > 0): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <button type="submit" name="mark_all_read" class="btn-outline">
                                <i class="fas fa-check-double"></i> Mark All Read
                            </button>
                        </form>
                    <?php endif; ?>
                    
                    <?php if(!empty($notifications)): ?>
                        <button class="btn-outline" onclick="openDeleteAllModal()">
                            <i class="fas fa-trash"></i> Delete All
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <div class="filter-group">
                    <span class="filter-label">Type</span>
                    <select class="filter-select" id="typeFilter" onchange="applyFilters()">
                        <option value="all" <?php echo $type === 'all' ? 'selected' : ''; ?>>All Types</option>
                        <option value="emergency" <?php echo $type === 'emergency' ? 'selected' : ''; ?>>Emergency</option>
                        <option value="info" <?php echo $type === 'info' ? 'selected' : ''; ?>>Info</option>
                        <option value="success" <?php echo $type === 'success' ? 'selected' : ''; ?>>Success</option>
                        <option value="warning" <?php echo $type === 'warning' ? 'selected' : ''; ?>>Warning</option>
                    </select>
                </div>

                <div class="filter-group">
                    <span class="filter-label">Status</span>
                    <select class="filter-select" id="readFilter" onchange="applyFilters()">
                        <option value="all" <?php echo $read === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="unread" <?php echo $read === 'unread' ? 'selected' : ''; ?>>Unread</option>
                        <option value="read" <?php echo $read === 'read' ? 'selected' : ''; ?>>Read</option>
                    </select>
                </div>

                <div class="filter-group" style="flex: 0 0 auto;">
                    <button class="btn-outline" onclick="resetFilters()" style="height: 100%;">
                        <i class="fas fa-times"></i> Clear
                    </button>
                </div>
            </div>

            <!-- Notifications List -->
            <div class="notifications-card">
                <div class="notifications-header">
                    <div class="notifications-title">
                        <i class="fas fa-bell"></i>
                        Notification Center
                    </div>
                    <span class="notifications-count">
                        <?php echo count($notifications); ?> of <?php echo $totalNotifications; ?>
                    </span>
                </div>

                <div class="notifications-list">
                    <?php if(empty($notifications)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">🔔</div>
                            <div class="empty-title">No Notifications</div>
                            <div class="empty-text">
                                <?php if($type !== 'all' || $read !== 'all'): ?>
                                    No notifications match your filters. Try adjusting your filters.
                                <?php else: ?>
                                    You don't have any notifications yet. They'll appear here when you receive them.
                                <?php endif; ?>
                            </div>
                            <?php if($type !== 'all' || $read !== 'all'): ?>
                                <a href="notifications.php" class="btn-primary" style="text-decoration: none;">View All</a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <?php foreach($notifications as $notification): 
                            $config = $typeConfig[$notification['type']] ?? $typeConfig['info'];
                        ?>
                            <div class="notification-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>" id="notification-<?php echo $notification['id']; ?>">
                                <?php if(!$notification['is_read']): ?>
                                    <div class="unread-dot"></div>
                                <?php endif; ?>
                                
                                <div class="notification-icon" style="background: <?php echo $config['bg']; ?>; color: <?php echo $config['color']; ?>;">
                                    <i class="fas <?php echo $config['fa']; ?>"></i>
                                </div>
                                
                                <div class="notification-content">
                                    <div class="notification-header">
                                        <div class="notification-title">
                                            <?php echo htmlspecialchars($notification['title']); ?>
                                        </div>
                                        <div class="notification-time">
                                            <i class="far fa-clock"></i>
                                            <?php 
                                            $time = strtotime($notification['created_at']);
                                            $now = time();
                                            $diff = $now - $time;
                                            
                                            if($diff < 60) {
                                                echo 'Just now';
                                            } elseif($diff < 3600) {
                                                echo floor($diff / 60) . ' minutes ago';
                                            } elseif($diff < 86400) {
                                                echo floor($diff / 3600) . ' hours ago';
                                            } else {
                                                echo date('M j, g:i A', $time);
                                            }
                                            ?>
                                        </div>
                                    </div>
                                    
                                    <div class="notification-message">
                                        <?php echo htmlspecialchars($notification['message']); ?>
                                    </div>
                                    
                                    <div class="notification-meta">
                                        <span class="notification-type" style="background: <?php echo $config['bg']; ?>; color: <?php echo $config['color']; ?>;">
                                            <i class="fas <?php echo $config['fa']; ?>"></i>
                                            <?php echo ucfirst($notification['type']); ?>
                                        </span>
                                        
                                        <?php if(!empty($notification['data'])): 
                                            $data = json_decode($notification['data'], true);
                                            if(isset($data['request_id'])): ?>
                                                <a href="emergency_services.php?request=<?php echo $data['request_id']; ?>" style="font-size: 0.8rem; color: #2563eb;">
                                                    View Details →
                                                </a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="notification-actions">
                                    <?php if(!$notification['is_read']): ?>
                                        <button class="action-btn mark-read" onclick="markAsRead(<?php echo $notification['id']; ?>)" title="Mark as read">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    <?php endif; ?>
                                    <button class="action-btn delete" onclick="deleteNotification(<?php echo $notification['id']; ?>)" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Pagination -->
            <?php if($totalPages > 1): ?>
                <div class="pagination">
                    <a href="?page=<?php echo max(1, $page-1); ?>&type=<?php echo $type; ?>&read=<?php echo $read; ?>" 
                       class="pagination-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                    
                    <?php for($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="?page=<?php echo $i; ?>&type=<?php echo $type; ?>&read=<?php echo $read; ?>" 
                           class="pagination-item <?php echo $i == $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <a href="?page=<?php echo min($totalPages, $page+1); ?>&type=<?php echo $type; ?>&read=<?php echo $read; ?>" 
                       class="pagination-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Delete Notification</h3>
                <button class="modal-close" onclick="closeModal('deleteModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this notification? This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button class="btn-outline" onclick="closeModal('deleteModal')">Cancel</button>
                <form method="POST" id="deleteForm" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="notification_id" id="deleteNotificationId">
                    <button type="submit" name="delete" class="btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete All Modal -->
    <div class="modal" id="deleteAllModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Delete All Notifications</h3>
                <button class="modal-close" onclick="closeModal('deleteAllModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete all notifications? This action cannot be undone.</p>
                <p style="color: #b91c1c; font-size: 0.875rem; margin-top: 0.5rem;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo $totalNotifications; ?> notifications will be permanently deleted.
                </p>
            </div>
            <div class="modal-footer">
                <button class="btn-outline" onclick="closeModal('deleteAllModal')">Cancel</button>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <button type="submit" name="delete_all" class="btn-danger">Delete All</button>
                </form>
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

        // Dropdown
        function toggleDropdown() {
            document.getElementById('dropdownMenu').classList.toggle('show');
            document.getElementById('userProfile').classList.toggle('active');
        }

        window.addEventListener('click', function(e) {
            const container = document.querySelector('.user-profile-container');
            const dropdown = document.getElementById('dropdownMenu');
            const profile = document.getElementById('userProfile');
            
            if (container && !container.contains(e.target)) {
                dropdown.classList.remove('show');
                profile.classList.remove('active');
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
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }

        window.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                closeModal(e.target.id);
            }
        });

        // Delete notification
        function deleteNotification(id) {
            document.getElementById('deleteNotificationId').value = id;
            openModal('deleteModal');
        }

        // Delete all
        function openDeleteAllModal() {
            openModal('deleteAllModal');
        }

        // Mark as read
        function markAsRead(id) {
            fetch('notifications.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'mark_read=1&notification_id=' + id + '&csrf_token=<?php echo $_SESSION['csrf_token']; ?>'
            })
            .then(response => response.json())
            .then(data => {
                if(data.success) {
                    const notification = document.getElementById('notification-' + id);
                    notification.classList.remove('unread');
                    notification.querySelector('.unread-dot')?.remove();
                    
                    // Update stats
                    location.reload();
                }
            });
        }

        // Apply filters
        function applyFilters() {
            const type = document.getElementById('typeFilter').value;
            const read = document.getElementById('readFilter').value;
            window.location.href = `?type=${type}&read=${read}`;
        }

        // Reset filters
        function resetFilters() {
            window.location.href = 'notifications.php';
        }

        // Auto refresh notifications (every 60 seconds)
        setInterval(function() {
            // Reload page to get latest notifications
            // You can implement AJAX refresh here if needed
        }, 60000);

        // Mark as read on click (optional)
        document.querySelectorAll('.notification-item').forEach(item => {
            item.addEventListener('click', function(e) {
                if(!e.target.closest('.notification-actions')) {
                    const markBtn = this.querySelector('.mark-read');
                    if(markBtn) {
                        const id = this.id.replace('notification-', '');
                        markAsRead(id);
                    }
                }
            });
        });
    </script>
</body>
</html>