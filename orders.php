<?php
session_start();
if(!isset($_SESSION['user_id'])){
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

// Handle cancel order
if(isset($_POST['cancel_order'])) {
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid security token");
        }

        $orderId = intval($_POST['order_id']);
        
        // Check if order belongs to user and is pending
        $checkStmt = $db->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ? AND status = 'pending'");
        $checkStmt->execute([$orderId, $_SESSION['user_id']]);
        $order = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if(!$order) {
            throw new Exception("Order not found or cannot be cancelled");
        }

        // Begin transaction
        $db->beginTransaction();

        // Restore stock for each item
        $items = json_decode($order['items'], true);
        foreach($items as $item) {
            $updateStmt = $db->prepare("UPDATE spare_parts SET stock = stock + ? WHERE id = ?");
            $updateStmt->execute([$item['quantity'], $item['part_id']]);
        }

        // Update order status
        $stmt = $db->prepare("UPDATE orders SET status = 'cancelled', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$orderId]);

        // Create notification
        $notifStmt = $db->prepare("
            INSERT INTO notifications (user_id, type, title, message, data, created_at)
            VALUES (?, 'warning', 'Order Cancelled', ?, ?, NOW())
        ");

        $message = "Your order #" . $order['order_number'] . " has been cancelled";
        $data = json_encode([
            'order_id' => $orderId,
            'order_number' => $order['order_number']
        ]);

        $notifStmt->execute([$_SESSION['user_id'], $message, $data]);

        $db->commit();

        $_SESSION['success'] = "Order cancelled successfully";
        header("Location: orders.php");
        exit();

    } catch (Exception $e) {
        $db->rollBack();
        $error = $e->getMessage();
    }
}

// Handle reorder
if(isset($_POST['reorder'])) {
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid security token");
        }

        $oldOrderId = intval($_POST['order_id']);
        
        // Get old order details
        $orderStmt = $db->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
        $orderStmt->execute([$oldOrderId, $_SESSION['user_id']]);
        $oldOrder = $orderStmt->fetch(PDO::FETCH_ASSOC);

        if(!$oldOrder) {
            throw new Exception("Order not found");
        }

        $items = json_decode($oldOrder['items'], true);
        $newItems = [];
        $subtotal = 0;

        // Begin transaction
        $db->beginTransaction();

        // Check stock for each item
        foreach($items as $item) {
            $stockStmt = $db->prepare("SELECT stock, price FROM spare_parts WHERE id = ?");
            $stockStmt->execute([$item['part_id']]);
            $part = $stockStmt->fetch(PDO::FETCH_ASSOC);

            if($part['stock'] < $item['quantity']) {
                throw new Exception("Insufficient stock for " . $item['name']);
            }

            // Update stock
            $updateStmt = $db->prepare("UPDATE spare_parts SET stock = stock - ? WHERE id = ?");
            $updateStmt->execute([$item['quantity'], $item['part_id']]);

            $item['price'] = $part['price'];
            $subtotal += $part['price'] * $item['quantity'];
            $newItems[] = $item;
        }

        // Calculate tax and total
        $tax = $subtotal * 0.16; // 16% VAT
        $total = $subtotal + $tax;

        // Generate new order number
        $orderNumber = 'ORD-' . strtoupper(uniqid());

        // Create new order
        $insertStmt = $db->prepare("
            INSERT INTO orders (order_number, user_id, items, subtotal, tax, total, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");

        $insertStmt->execute([
            $orderNumber,
            $_SESSION['user_id'],
            json_encode($newItems),
            $subtotal,
            $tax,
            $total
        ]);

        $newOrderId = $db->lastInsertId();

        // Create notification
        $notifStmt = $db->prepare("
            INSERT INTO notifications (user_id, type, title, message, data, created_at)
            VALUES (?, 'success', 'Order Placed', ?, ?, NOW())
        ");

        $message = "Your reorder #" . $orderNumber . " has been placed successfully";
        $data = json_encode([
            'order_id' => $newOrderId,
            'order_number' => $orderNumber,
            'total' => $total
        ]);

        $notifStmt->execute([$_SESSION['user_id'], $message, $data]);

        $db->commit();

        $_SESSION['success'] = "Reorder placed successfully!";
        header("Location: orders.php");
        exit();

    } catch (Exception $e) {
        $db->rollBack();
        $error = $e->getMessage();
    }
}

// Get filter parameters
$status = isset($_GET['status']) ? $_GET['status'] : 'all';
$dateRange = isset($_GET['date_range']) ? $_GET['date_range'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query
$query = "SELECT * FROM orders WHERE user_id = ?";
$params = [$_SESSION['user_id']];

// Apply status filter
if($status !== 'all') {
    $query .= " AND status = ?";
    $params[] = $status;
}

// Apply date range filter
if($dateRange !== 'all') {
    switch($dateRange) {
        case 'today':
            $query .= " AND DATE(created_at) = CURDATE()";
            break;
        case 'week':
            $query .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            break;
        case 'month':
            $query .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            break;
        case 'year':
            $query .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)";
            break;
    }
}

// Apply search filter
if(!empty($search)) {
    $query .= " AND (order_number LIKE ? OR items LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$query .= " ORDER BY created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get order statistics
$statsStmt = $db->prepare("
    SELECT 
        COUNT(*) as total_orders,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
        COALESCE(SUM(total), 0) as total_spent,
        COALESCE(AVG(total), 0) as avg_order_value
    FROM orders 
    WHERE user_id = ?
");
$statsStmt->execute([$_SESSION['user_id']]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Get user details
try {
    $userStmt = $db->prepare("SELECT name, email FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = ['name' => $_SESSION['user_name'] ?? 'User', 'email' => ''];
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

// Status colors and icons
$statusConfig = [
    'pending' => ['bg' => '#fef3c7', 'color' => '#92400e', 'icon' => '⏳', 'label' => 'Pending'],
    'processing' => ['bg' => '#dbeafe', 'color' => '#1e40af', 'icon' => '⚙️', 'label' => 'Processing'],
    'completed' => ['bg' => '#d1fae5', 'color' => '#065f46', 'icon' => '✅', 'label' => 'Completed'],
    'cancelled' => ['bg' => '#fee2e2', 'color' => '#b91c1c', 'icon' => '❌', 'label' => 'Cancelled']
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CASMS | My Orders</title>
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

        body.dark .orders-grid {
            background: #1e293b;
            border-color: #334155;
        }

        body.dark .order-card {
            background: #1e293b;
            border-color: #334155;
        }

        body.dark .order-card:hover {
            border-color: #2563eb;
        }

        body.dark .order-header {
            border-color: #334155;
        }

        body.dark .order-items {
            border-color: #334155;
        }

        body.dark .order-item {
            border-color: #334155;
        }

        body.dark .order-item:hover {
            background: #0f172a;
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

        /* Orders Grid */
        .orders-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .order-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            overflow: hidden;
            transition: all 0.3s;
        }

        .order-card:hover {
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
            border-color: #2563eb;
        }

        .order-header {
            padding: 1.5rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .order-info {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .order-number {
            font-size: 1.125rem;
            font-weight: 600;
        }

        .order-date {
            font-size: 0.875rem;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .order-status {
            padding: 0.5rem 1rem;
            border-radius: 40px;
            font-size: 0.875rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .order-body {
            padding: 1.5rem;
        }

        .order-items {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 1.5rem;
        }

        .order-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .order-item:last-child {
            border-bottom: none;
        }

        .order-item:hover {
            background: #f8fafc;
        }

        .item-details {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .item-icon {
            width: 40px;
            height: 40px;
            background: #dbeafe;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            color: #2563eb;
        }

        .item-name {
            font-weight: 500;
        }

        .item-quantity {
            font-size: 0.875rem;
            color: #64748b;
        }

        .item-price {
            font-weight: 600;
            color: #2563eb;
        }

        .order-summary {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 0.5rem;
            padding: 1rem;
            background: #f8fafc;
            border-radius: 12px;
        }

        .summary-row {
            display: flex;
            gap: 2rem;
            font-size: 0.95rem;
        }

        .summary-row.total {
            font-weight: 700;
            font-size: 1.125rem;
            color: #2563eb;
            border-top: 1px solid #e2e8f0;
            padding-top: 0.5rem;
        }

        .order-footer {
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

        .btn-action.reorder:hover {
            background: #10b981;
            border-color: #10b981;
        }

        /* Empty State */
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
            
            .filter-section {
                flex-direction: column;
            }
            
            .order-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .order-footer {
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
                <a href="services.php">Services & Pricing</a>
                <a href="book_service.php">Book Appointment</a>
                <a href="service_history.php">Service History</a>
                <a href="spareparts.php">Spare Parts</a>
                <a href="orders.php" class="active">My Orders</a>
                <a href="emergency_services.php">🚨 Emergency</a>
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
                                <span class="greeting-badge">MY ORDERS</span>
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
                                    <span class="chip-icon">📦</span>
                                    <span class="chip-text"><?php echo $stats['total_orders']; ?> Orders</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="status-message">
                            <div class="status-icon">
                                <span class="pulse-dot"></span>
                                <span>Order Summary</span>
                            </div>
                            <div class="message-text">
                                <span class="highlight"><?php echo $stats['pending']; ?> pending</span> · 
                                <span class="highlight"><?php echo $stats['processing']; ?> processing</span> · 
                                <span class="highlight">Ksh <?php echo number_format($stats['total_spent'], 2); ?></span> total spent
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
                                    <a href="orders.php" class="dropdown-item">
                                        <span class="item-icon">📦</span>
                                        My Orders
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
                    <div class="stats-icon">📦</div>
                    <div class="stats-value"><?php echo $stats['total_orders']; ?></div>
                    <div class="stats-label">Total Orders</div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">⏳</div>
                    <div class="stats-value"><?php echo $stats['pending']; ?></div>
                    <div class="stats-label">Pending</div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">⚙️</div>
                    <div class="stats-value"><?php echo $stats['processing']; ?></div>
                    <div class="stats-label">Processing</div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">✅</div>
                    <div class="stats-value"><?php echo $stats['completed']; ?></div>
                    <div class="stats-label">Completed</div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">💰</div>
                    <div class="stats-value">Ksh <?php echo number_format($stats['total_spent'], 2); ?></div>
                    <div class="stats-label">Total Spent</div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">📊</div>
                    <div class="stats-value">Ksh <?php echo number_format($stats['avg_order_value'], 2); ?></div>
                    <div class="stats-label">Average Order</div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" style="display: contents;">
                    <div class="filter-group">
                        <span class="filter-label">Status</span>
                        <select name="status" class="filter-select" onchange="this.form.submit()">
                            <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Orders</option>
                            <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="processing" <?php echo $status === 'processing' ? 'selected' : ''; ?>>Processing</option>
                            <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <span class="filter-label">Date Range</span>
                        <select name="date_range" class="filter-select" onchange="this.form.submit()">
                            <option value="all" <?php echo $dateRange === 'all' ? 'selected' : ''; ?>>All Time</option>
                            <option value="today" <?php echo $dateRange === 'today' ? 'selected' : ''; ?>>Today</option>
                            <option value="week" <?php echo $dateRange === 'week' ? 'selected' : ''; ?>>This Week</option>
                            <option value="month" <?php echo $dateRange === 'month' ? 'selected' : ''; ?>>This Month</option>
                            <option value="year" <?php echo $dateRange === 'year' ? 'selected' : ''; ?>>This Year</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <span class="filter-label">Search</span>
                        <input type="text" name="search" class="filter-input" placeholder="Order number..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn-filter">
                            <i class="fas fa-filter"></i> Apply
                        </button>
                        <a href="orders.php" class="btn-reset">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Orders Grid -->
            <?php if(empty($orders)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📦</div>
                    <div class="empty-title">No Orders Found</div>
                    <div class="empty-text">
                        <?php if($status !== 'all' || $dateRange !== 'all' || !empty($search)): ?>
                            No orders match your filters. Try adjusting your search criteria.
                        <?php else: ?>
                            You haven't placed any orders yet. Visit our <a href="spareparts.php">Spare Parts Store</a> to start shopping.
                        <?php endif; ?>
                    </div>
                    <?php if($status === 'all' && $dateRange === 'all' && empty($search)): ?>
                        <a href="spareparts.php" class="btn-primary" style="text-decoration: none; display: inline-block; padding: 0.75rem 2rem;">
                            <i class="fas fa-shopping-cart"></i> Shop Now
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="orders-grid">
                    <?php foreach($orders as $order): 
                        $items = json_decode($order['items'], true);
                        $statusStyle = $statusConfig[$order['status']] ?? ['bg' => '#e2e8f0', 'color' => '#475569', 'icon' => '📌', 'label' => ucfirst($order['status'])];
                    ?>
                        <div class="order-card">
                            <div class="order-header">
                                <div class="order-info">
                                    <div class="order-number">Order #<?php echo htmlspecialchars($order['order_number']); ?></div>
                                    <div class="order-date">
                                        <i class="far fa-calendar"></i>
                                        <?php echo date('F j, Y', strtotime($order['created_at'])); ?> at <?php echo date('g:i A', strtotime($order['created_at'])); ?>
                                    </div>
                                </div>
                                <span class="order-status" style="background: <?php echo $statusStyle['bg']; ?>; color: <?php echo $statusStyle['color']; ?>;">
                                    <span><?php echo $statusStyle['icon']; ?></span>
                                    <?php echo $statusStyle['label']; ?>
                                </span>
                            </div>

                            <div class="order-body">
                                <div class="order-items">
                                    <?php foreach($items as $item): ?>
                                        <div class="order-item">
                                            <div class="item-details">
                                                <div class="item-icon">
                                                    <i class="fas fa-car"></i>
                                                </div>
                                                <div>
                                                    <div class="item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                                    <div class="item-quantity">Quantity: <?php echo $item['quantity']; ?></div>
                                                </div>
                                            </div>
                                            <div class="item-price">
                                                Ksh <?php echo number_format($item['price'] * $item['quantity'], 2); ?>
                                                <div style="font-size: 0.75rem; color: #64748b;">
                                                    Ksh <?php echo number_format($item['price'], 2); ?> each
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="order-summary">
                                    <div class="summary-row">
                                        <span>Subtotal:</span>
                                        <span>Ksh <?php echo number_format($order['subtotal'], 2); ?></span>
                                    </div>
                                    <div class="summary-row">
                                        <span>VAT (16%):</span>
                                        <span>Ksh <?php echo number_format($order['tax'], 2); ?></span>
                                    </div>
                                    <div class="summary-row total">
                                        <span>Total:</span>
                                        <span>Ksh <?php echo number_format($order['total'], 2); ?></span>
                                    </div>
                                </div>
                            </div>

                            <div class="order-footer">
                                <?php if($order['status'] === 'pending'): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                        <button type="submit" name="cancel_order" class="btn-action cancel" onclick="return confirm('Are you sure you want to cancel this order?')">
                                            <i class="fas fa-times"></i> Cancel Order
                                        </button>
                                    </form>
                                <?php endif; ?>
                                
                                <?php if(in_array($order['status'], ['completed', 'cancelled'])): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                        <button type="submit" name="reorder" class="btn-action reorder">
                                            <i class="fas fa-redo"></i> Reorder
                                        </button>
                                    </form>
                                <?php endif; ?>
                                
                                <button class="btn-action" onclick="viewOrderDetails(<?php echo $order['id']; ?>)">
                                    <i class="fas fa-eye"></i> View Details
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Order Details Modal -->
    <div class="modal" id="detailsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Order Details</h3>
                <button class="modal-close" onclick="closeModal('detailsModal')">&times;</button>
            </div>
            <div class="modal-body" id="detailsModalBody">
                <!-- Content will be loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button class="btn-primary" onclick="closeModal('detailsModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- Cancel Confirmation Modal -->
    <div class="modal" id="cancelModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Cancel Order</h3>
                <button class="modal-close" onclick="closeModal('cancelModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to cancel this order? This action cannot be undone.</p>
                <p style="color: #b91c1c; font-size: 0.875rem; margin-top: 0.5rem;">
                    <i class="fas fa-exclamation-triangle"></i>
                    Cancelling will restore the items to your cart.
                </p>
            </div>
            <div class="modal-footer">
                <button class="btn-outline" onclick="closeModal('cancelModal')">No, Keep It</button>
                <form method="POST" id="cancelForm" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="order_id" id="cancelOrderId">
                    <button type="submit" name="cancel_order" class="btn-danger">Yes, Cancel</button>
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

        // View order details
        function viewOrderDetails(orderId) {
            // In a real application, you would fetch details via AJAX
            // For demo purposes, we'll show a message
            document.getElementById('detailsModalBody').innerHTML = `
                <p>Detailed order information would be displayed here.</p>
                <p>This includes shipping details, payment information, tracking number, etc.</p>
            `;
            openModal('detailsModal');
        }

        // Cancel order
        function cancelOrder(orderId) {
            document.getElementById('cancelOrderId').value = orderId;
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