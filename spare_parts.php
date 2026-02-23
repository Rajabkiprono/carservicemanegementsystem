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

// Handle add to cart
if(isset($_POST['add_to_cart'])) {
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid security token");
        }

        $partId = intval($_POST['part_id']);
        $quantity = intval($_POST['quantity'] ?? 1);

        // Get part details
        $stmt = $db->prepare("SELECT * FROM spare_parts WHERE id = ? AND stock >= ?");
        $stmt->execute([$partId, $quantity]);
        $part = $stmt->fetch(PDO::FETCH_ASSOC);

        if(!$part) {
            throw new Exception("Selected part is out of stock or insufficient quantity");
        }

        // Initialize cart if not exists
        if(!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }

        // Add to cart or update quantity
        if(isset($_SESSION['cart'][$partId])) {
            $newQuantity = $_SESSION['cart'][$partId]['quantity'] + $quantity;
            if($newQuantity > $part['stock']) {
                throw new Exception("Cannot add more than available stock");
            }
            $_SESSION['cart'][$partId]['quantity'] = $newQuantity;
        } else {
            $_SESSION['cart'][$partId] = [
                'id' => $part['id'],
                'name' => $part['name'],
                'price' => $part['price'],
                'quantity' => $quantity,
                'image' => $part['image'] ?? null
            ];
        }

        // Create notification
        $notifStmt = $db->prepare("
            INSERT INTO notifications (user_id, type, title, message, data, created_at)
            VALUES (?, 'success', 'Item Added to Cart', ?, ?, NOW())
        ");
        
        $message = $part['name'] . " (x" . $quantity . ") added to your cart";
        $data = json_encode([
            'part_id' => $partId,
            'quantity' => $quantity,
            'cart_total' => count($_SESSION['cart'])
        ]);
        
        $notifStmt->execute([$_SESSION['user_id'], $message, $data]);

        $_SESSION['success'] = "Item added to cart successfully!";
        header("Location: spare_parts.php");
        exit();

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle remove from cart
if(isset($_POST['remove_from_cart'])) {
    $partId = intval($_POST['part_id']);
    if(isset($_SESSION['cart'][$partId])) {
        unset($_SESSION['cart'][$partId]);
        $_SESSION['success'] = "Item removed from cart";
    }
    header("Location: spare_parts.php");
    exit();
}

// Handle update cart quantity
if(isset($_POST['update_cart'])) {
    try {
        $partId = intval($_POST['part_id']);
        $quantity = intval($_POST['quantity']);

        // Check stock
        $stmt = $db->prepare("SELECT stock FROM spare_parts WHERE id = ?");
        $stmt->execute([$partId]);
        $part = $stmt->fetch(PDO::FETCH_ASSOC);

        if($quantity > $part['stock']) {
            throw new Exception("Requested quantity exceeds available stock");
        }

        if($quantity > 0) {
            $_SESSION['cart'][$partId]['quantity'] = $quantity;
            $_SESSION['success'] = "Cart updated successfully";
        } else {
            unset($_SESSION['cart'][$partId]);
            $_SESSION['success'] = "Item removed from cart";
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
    header("Location: spare_parts.php");
    exit();
}

// Handle checkout
if(isset($_POST['checkout'])) {
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid security token");
        }

        if(empty($_SESSION['cart'])) {
            throw new Exception("Your cart is empty");
        }

        // Begin transaction
        $db->beginTransaction();

        $orderTotal = 0;
        $orderItems = [];

        // Process each item in cart
        foreach($_SESSION['cart'] as $item) {
            // Check stock again
            $stmt = $db->prepare("SELECT * FROM spare_parts WHERE id = ? FOR UPDATE");
            $stmt->execute([$item['id']]);
            $part = $stmt->fetch(PDO::FETCH_ASSOC);

            if($item['quantity'] > $part['stock']) {
                throw new Exception("Insufficient stock for " . $part['name']);
            }

            // Update stock
            $newStock = $part['stock'] - $item['quantity'];
            $updateStmt = $db->prepare("UPDATE spare_parts SET stock = ? WHERE id = ?");
            $updateStmt->execute([$newStock, $item['id']]);

            $subtotal = $part['price'] * $item['quantity'];
            $orderTotal += $subtotal;

            $orderItems[] = [
                'part_id' => $item['id'],
                'name' => $part['name'],
                'quantity' => $item['quantity'],
                'price' => $part['price'],
                'subtotal' => $subtotal
            ];
        }

        // Calculate tax and total
        $tax = $orderTotal * 0.16; // 16% VAT
        $grandTotal = $orderTotal + $tax;

        // Generate order number
        $orderNumber = 'ORD-' . strtoupper(uniqid());

        // Create order
        $orderStmt = $db->prepare("
            INSERT INTO orders (
                order_number, user_id, items, subtotal, tax, total, 
                status, payment_method, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, NOW())
        ");

        $itemsJson = json_encode($orderItems);
        $paymentMethod = $_POST['payment_method'] ?? 'mpesa';

        $orderStmt->execute([
            $orderNumber,
            $_SESSION['user_id'],
            $itemsJson,
            $orderTotal,
            $tax,
            $grandTotal,
            $paymentMethod
        ]);

        $orderId = $db->lastInsertId();

        // Create notification
        $notifStmt = $db->prepare("
            INSERT INTO notifications (user_id, type, title, message, data, created_at)
            VALUES (?, 'success', 'Order Placed Successfully', ?, ?, NOW())
        ");

        $message = "Your order #" . $orderNumber . " has been placed. Total: Ksh " . number_format($grandTotal, 2);
        $data = json_encode([
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'total' => $grandTotal,
            'items_count' => count($orderItems)
        ]);

        $notifStmt->execute([$_SESSION['user_id'], $message, $data]);

        // Clear cart
        $_SESSION['cart'] = [];

        $db->commit();

        $_SESSION['success'] = "Order placed successfully! Order #: " . $orderNumber;
        header("Location: spare_parts.php?order_success=1&order=" . $orderNumber);
        exit();

    } catch (Exception $e) {
        $db->rollBack();
        $error = $e->getMessage();
        error_log("Checkout error: " . $e->getMessage());
    }
}

// Handle M-Pesa payment simulation
if(isset($_POST['process_mpesa'])) {
    try {
        $phone = $_POST['phone'];
        $amount = $_POST['amount'];
        $orderNumber = $_POST['order_number'];

        // Validate phone number (simple Kenyan format)
        if(!preg_match('/^(?:254|\+254|0)?(7[0-9]{8})$/', $phone)) {
            throw new Exception("Please enter a valid Kenyan phone number");
        }

        // Simulate STK push
        $_SESSION['success'] = "M-Pesa STK push sent to " . $phone . ". Please check your phone and enter PIN to complete payment.";
        
        // In production, you would integrate with Safaricom M-Pesa API here
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get filter parameters
$category = isset($_GET['category']) ? $_GET['category'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'name_asc';
$minPrice = isset($_GET['min_price']) ? floatval($_GET['min_price']) : 0;
$maxPrice = isset($_GET['max_price']) ? floatval($_GET['max_price']) : 100000;

// Build query for spare parts
$query = "SELECT * FROM spare_parts WHERE 1=1";
$params = [];

if($category !== 'all') {
    $query .= " AND category = ?";
    $params[] = $category;
}

if(!empty($search)) {
    $query .= " AND (name LIKE ? OR description LIKE ? OR category LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if($minPrice > 0) {
    $query .= " AND price >= ?";
    $params[] = $minPrice;
}

if($maxPrice < 100000) {
    $query .= " AND price <= ?";
    $params[] = $maxPrice;
}

switch($sort) {
    case 'name_asc':
        $query .= " ORDER BY name ASC";
        break;
    case 'name_desc':
        $query .= " ORDER BY name DESC";
        break;
    case 'price_asc':
        $query .= " ORDER BY price ASC";
        break;
    case 'price_desc':
        $query .= " ORDER BY price DESC";
        break;
    case 'stock_asc':
        $query .= " ORDER BY stock ASC";
        break;
    case 'stock_desc':
        $query .= " ORDER BY stock DESC";
        break;
    default:
        $query .= " ORDER BY name ASC";
}

$stmt = $db->prepare($query);
$stmt->execute($params);
$parts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories for filter
$catStmt = $db->query("SELECT DISTINCT category FROM spare_parts ORDER BY category");
$categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

// Calculate cart total
$cartTotal = 0;
$cartCount = 0;
if(isset($_SESSION['cart'])) {
    foreach($_SESSION['cart'] as $item) {
        $cartTotal += $item['price'] * $item['quantity'];
        $cartCount += $item['quantity'];
    }
}

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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CASMS | Spare Parts Store</title>
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

        body.dark .parts-grid {
            background: #1e293b;
            border-color: #334155;
        }

        body.dark .part-card {
            background: #1e293b;
            border-color: #334155;
        }

        body.dark .part-card:hover {
            border-color: #2563eb;
        }

        body.dark .cart-sidebar {
            background: #1e293b;
            border-color: #334155;
        }

        body.dark .cart-header {
            border-color: #334155;
        }

        body.dark .cart-item {
            border-color: #334155;
        }

        body.dark .cart-item:hover {
            background: #0f172a;
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

        /* Store Layout */
        .store-container {
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: 2rem;
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

        /* Parts Grid */
        .parts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .part-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            overflow: hidden;
            transition: all 0.3s;
            position: relative;
        }

        .part-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
            border-color: #2563eb;
        }

        .part-image {
            height: 180px;
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .part-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            padding: 0.25rem 0.75rem;
            border-radius: 40px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .part-badge.in-stock {
            background: #d1fae5;
            color: #065f46;
        }

        .part-badge.low-stock {
            background: #fef3c7;
            color: #92400e;
        }

        .part-badge.out-stock {
            background: #fee2e2;
            color: #b91c1c;
        }

        .part-info {
            padding: 1.5rem;
        }

        .part-category {
            font-size: 0.75rem;
            color: #2563eb;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .part-name {
            font-weight: 600;
            font-size: 1.125rem;
            margin-bottom: 0.5rem;
        }

        .part-description {
            font-size: 0.875rem;
            color: #64748b;
            margin-bottom: 1rem;
            line-height: 1.5;
        }

        .part-price {
            font-size: 1.5rem;
            font-weight: 700;
            color: #2563eb;
            margin-bottom: 0.5rem;
        }

        .part-stock {
            font-size: 0.875rem;
            color: #64748b;
            margin-bottom: 1rem;
        }

        .part-actions {
            display: flex;
            gap: 0.5rem;
        }

        .quantity-input {
            width: 80px;
            padding: 0.5rem;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            text-align: center;
        }

        .btn-add-cart {
            flex: 1;
            padding: 0.75rem;
            background: #2563eb;
            color: white;
            border: none;
            border-radius: 10px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-add-cart:hover:not(:disabled) {
            background: #1d4ed8;
            transform: translateY(-2px);
        }

        .btn-add-cart:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-view {
            width: 40px;
            height: 40px;
            background: #f1f5f9;
            border: none;
            border-radius: 10px;
            color: #475569;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-view:hover {
            background: #2563eb;
            color: white;
        }

        /* Cart Sidebar */
        .cart-sidebar {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            overflow: hidden;
            position: sticky;
            top: 2rem;
            height: fit-content;
        }

        .cart-header {
            padding: 1.5rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .cart-title {
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .cart-count {
            background: #2563eb;
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 40px;
            font-size: 0.75rem;
        }

        .cart-items {
            max-height: 400px;
            overflow-y: auto;
            padding: 1rem;
        }

        .cart-item {
            display: flex;
            gap: 1rem;
            padding: 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            margin-bottom: 0.75rem;
            transition: all 0.2s;
        }

        .cart-item:hover {
            background: #f8fafc;
        }

        .cart-item-image {
            width: 60px;
            height: 60px;
            background: #f1f5f9;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .cart-item-details {
            flex: 1;
        }

        .cart-item-name {
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .cart-item-price {
            font-weight: 700;
            color: #2563eb;
            font-size: 1rem;
        }

        .cart-item-quantity {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .cart-quantity-input {
            width: 60px;
            padding: 0.25rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            text-align: center;
        }

        .cart-item-remove {
            color: #ef4444;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
        }

        .cart-footer {
            padding: 1.5rem;
            border-top: 1px solid #e2e8f0;
        }

        .cart-total {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
            font-size: 1.125rem;
            margin-bottom: 1.5rem;
        }

        .cart-total-amount {
            color: #2563eb;
            font-size: 1.25rem;
        }

        .btn-checkout {
            width: 100%;
            padding: 1rem;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-checkout:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.3);
        }

        .btn-clear-cart {
            width: 100%;
            padding: 0.75rem;
            background: transparent;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            color: #64748b;
            margin-top: 0.75rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-clear-cart:hover {
            border-color: #ef4444;
            color: #ef4444;
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
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
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

        /* Payment Methods */
        .payment-methods {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 1rem;
            margin: 1.5rem 0;
        }

        .payment-method {
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 1rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }

        .payment-method:hover {
            border-color: #2563eb;
        }

        .payment-method.selected {
            border-color: #2563eb;
            background: #eff6ff;
        }

        .payment-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .payment-name {
            font-weight: 500;
            font-size: 0.9rem;
        }

        /* Order Summary */
        .order-summary {
            background: #f8fafc;
            border-radius: 16px;
            padding: 1.5rem;
            margin: 1.5rem 0;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
        }

        .summary-row.total {
            border-top: 2px solid #e2e8f0;
            margin-top: 0.5rem;
            padding-top: 1rem;
            font-weight: 700;
            font-size: 1.125rem;
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
        @media (max-width: 1200px) {
            .store-container {
                grid-template-columns: 1fr;
            }
            
            .cart-sidebar {
                position: static;
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
            
            .parts-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-section {
                flex-direction: column;
            }
            
            .filter-actions {
                width: 100%;
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
                <a href="book_service.php">Book Service</a>
                <a href="service_history.php">Service History</a>
                <a href="spare_parts.php" class="active">Spare Parts</a>
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
                                <span class="greeting-badge">SPARE PARTS STORE</span>
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
                                    <span class="chip-text"><?php echo count($parts); ?> Parts</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="status-message">
                            <div class="status-icon">
                                <span class="pulse-dot"></span>
                                <span>Store Status</span>
                            </div>
                            <div class="message-text">
                                <span class="highlight"><?php echo $cartCount; ?> items</span> in cart · 
                                <span class="highlight">Ksh <?php echo number_format($cartTotal, 2); ?></span> total
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

            <?php if(isset($_GET['order_success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    Order placed successfully! Order #: <?php echo htmlspecialchars($_GET['order']); ?>
                </div>
            <?php endif; ?>

            <!-- Filter Section -->
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
                        <span class="filter-label">Sort By</span>
                        <select name="sort" class="filter-select">
                            <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>Name (A-Z)</option>
                            <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>Name (Z-A)</option>
                            <option value="price_asc" <?php echo $sort === 'price_asc' ? 'selected' : ''; ?>>Price (Low to High)</option>
                            <option value="price_desc" <?php echo $sort === 'price_desc' ? 'selected' : ''; ?>>Price (High to Low)</option>
                            <option value="stock_asc" <?php echo $sort === 'stock_asc' ? 'selected' : ''; ?>>Stock (Low to High)</option>
                            <option value="stock_desc" <?php echo $sort === 'stock_desc' ? 'selected' : ''; ?>>Stock (High to Low)</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <span class="filter-label">Search</span>
                        <input type="text" name="search" class="filter-input" placeholder="Search parts..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn-filter">
                            <i class="fas fa-filter"></i> Apply
                        </button>
                        <a href="spare_parts.php" class="btn-reset">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Store Container -->
            <div class="store-container">
                <!-- Parts Grid -->
                <div class="parts-grid">
                    <?php if(empty($parts)): ?>
                        <div class="empty-state" style="grid-column: 1/-1;">
                            <div class="empty-icon">🔧</div>
                            <div class="empty-title">No Parts Found</div>
                            <div class="empty-text">Try adjusting your filters or search criteria.</div>
                        </div>
                    <?php else: ?>
                        <?php foreach($parts as $part): 
                            $stockStatus = $part['stock'] > 10 ? 'in-stock' : ($part['stock'] > 0 ? 'low-stock' : 'out-stock');
                            $stockText = $part['stock'] > 10 ? 'In Stock' : ($part['stock'] > 0 ? 'Low Stock' : 'Out of Stock');
                            $stockColor = $part['stock'] > 10 ? '#10b981' : ($part['stock'] > 0 ? '#f59e0b' : '#ef4444');
                        ?>
                            <div class="part-card">
                                <div class="part-image">
                                    <?php 
                                    $icons = [
                                        'Engine' => '⚙️',
                                        'Brake' => '🛑',
                                        'Suspension' => '🔧',
                                        'Electrical' => '⚡',
                                        'Transmission' => '🔄',
                                        'Cooling' => '❄️',
                                        'Exhaust' => '💨',
                                        'Fuel' => '⛽',
                                        'Body' => '🚗',
                                        'Interior' => '🪑',
                                        'Wheel' => '🛞',
                                        'Battery' => '🔋',
                                        'Filter' => '🧹',
                                        'Light' => '💡',
                                        'Sensor' => '📊'
                                    ];
                                    $categoryIcon = $icons[$part['category']] ?? '🔧';
                                    echo $categoryIcon;
                                    ?>
                                </div>
                                <span class="part-badge <?php echo $stockStatus; ?>">
                                    <?php echo $stockText; ?>
                                </span>
                                <div class="part-info">
                                    <div class="part-category"><?php echo htmlspecialchars($part['category']); ?></div>
                                    <div class="part-name"><?php echo htmlspecialchars($part['name']); ?></div>
                                    <div class="part-description"><?php echo htmlspecialchars($part['description']); ?></div>
                                    <div class="part-price">Ksh <?php echo number_format($part['price'], 2); ?></div>
                                    <div class="part-stock">
                                        <i class="fas fa-box"></i> Stock: <?php echo $part['stock']; ?> units
                                    </div>
                                    <form method="POST" class="part-actions">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="part_id" value="<?php echo $part['id']; ?>">
                                        <input type="number" name="quantity" class="quantity-input" value="1" min="1" max="<?php echo $part['stock']; ?>" <?php echo $part['stock'] == 0 ? 'disabled' : ''; ?>>
                                        <button type="submit" name="add_to_cart" class="btn-add-cart" <?php echo $part['stock'] == 0 ? 'disabled' : ''; ?>>
                                            <i class="fas fa-cart-plus"></i> Add to Cart
                                        </button>
                                        <button type="button" class="btn-view" onclick="viewPartDetails(<?php echo $part['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Cart Sidebar -->
                <div class="cart-sidebar">
                    <div class="cart-header">
                        <div class="cart-title">
                            <i class="fas fa-shopping-cart"></i>
                            Your Cart
                        </div>
                        <span class="cart-count"><?php echo $cartCount; ?> items</span>
                    </div>

                    <?php if(empty($_SESSION['cart'])): ?>
                        <div class="empty-state" style="padding: 2rem;">
                            <div class="empty-icon">🛒</div>
                            <div class="empty-text">Your cart is empty</div>
                        </div>
                    <?php else: ?>
                        <div class="cart-items">
                            <?php foreach($_SESSION['cart'] as $item): ?>
                                <div class="cart-item">
                                    <div class="cart-item-image">
                                        <?php echo $icons[$part['category']] ?? '🔧'; ?>
                                    </div>
                                    <div class="cart-item-details">
                                        <div class="cart-item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                        <div class="cart-item-price">Ksh <?php echo number_format($item['price'], 2); ?></div>
                                        <form method="POST" class="cart-item-quantity">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="part_id" value="<?php echo $item['id']; ?>">
                                            <input type="number" name="quantity" class="cart-quantity-input" value="<?php echo $item['quantity']; ?>" min="0" max="100" onchange="this.form.submit()">
                                            <button type="submit" name="update_cart" style="display: none;"></button>
                                            <button type="submit" name="remove_from_cart" class="cart-item-remove">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="cart-footer">
                            <div class="cart-total">
                                <span>Total:</span>
                                <span class="cart-total-amount">Ksh <?php echo number_format($cartTotal, 2); ?></span>
                            </div>
                            <!-- Add this in the dropdown-items section -->
<a href="orders.php" class="dropdown-item">
    <span class="item-icon">📦</span>
    My Orders
</a>
                            <button class="btn-checkout" onclick="openCheckoutModal()">
                                <i class="fas fa-lock"></i> Proceed to Checkout
                            </button>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <button type="submit" name="clear_cart" class="btn-clear-cart">
                                    <i class="fas fa-times"></i> Clear Cart
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Checkout Modal -->
    <div class="modal" id="checkoutModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Checkout</h3>
                <button class="modal-close" onclick="closeModal('checkoutModal')">&times;</button>
            </div>
            <form method="POST" id="checkoutForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                
                <div class="modal-body">
                    <!-- Order Summary -->
                    <div class="order-summary">
                        <div class="summary-row">
                            <span>Subtotal (<?php echo $cartCount; ?> items)</span>
                            <span>Ksh <?php echo number_format($cartTotal, 2); ?></span>
                        </div>
                        <div class="summary-row">
                            <span>VAT (16%)</span>
                            <span>Ksh <?php echo number_format($cartTotal * 0.16, 2); ?></span>
                        </div>
                        <div class="summary-row total">
                            <span>Total</span>
                            <span>Ksh <?php echo number_format($cartTotal * 1.16, 2); ?></span>
                        </div>
                    </div>

                    <!-- Payment Methods -->
                    <div class="form-group">
                        <label class="form-label">Payment Method</label>
                        <div class="payment-methods">
                            <div class="payment-method selected" onclick="selectPaymentMethod('mpesa')">
                                <div class="payment-icon">
                                    <i class="fas fa-mobile-alt"></i>
                                </div>
                                <div class="payment-name">M-Pesa</div>
                            </div>
                            <div class="payment-method" onclick="selectPaymentMethod('card')">
                                <div class="payment-icon">
                                    <i class="fas fa-credit-card"></i>
                                </div>
                                <div class="payment-name">Card</div>
                            </div>
                            <div class="payment-method" onclick="selectPaymentMethod('bank')">
                                <div class="payment-icon">
                                    <i class="fas fa-university"></i>
                                </div>
                                <div class="payment-name">Bank Transfer</div>
                            </div>
                        </div>
                        <input type="hidden" name="payment_method" id="paymentMethod" value="mpesa">
                    </div>

                    <!-- M-Pesa Details -->
                    <div id="mpesaFields">
                        <div class="form-group">
                            <label class="form-label">M-Pesa Phone Number</label>
                            <input type="tel" name="phone" class="form-input" placeholder="e.g., 0712345678" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                            <small style="color: #64748b;">Enter the M-Pesa registered phone number</small>
                        </div>
                    </div>

                    <!-- Card Details (hidden by default) -->
                    <div id="cardFields" style="display: none;">
                        <div class="form-group">
                            <label class="form-label">Card Number</label>
                            <input type="text" class="form-input" placeholder="1234 5678 9012 3456" disabled>
                        </div>
                        <div class="form-row">
                            <div class="form-group" style="flex: 1;">
                                <label class="form-label">Expiry</label>
                                <input type="text" class="form-input" placeholder="MM/YY" disabled>
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <label class="form-label">CVV</label>
                                <input type="text" class="form-input" placeholder="123" disabled>
                            </div>
                        </div>
                        <p style="color: #64748b; font-size: 0.875rem;">Card payments coming soon</p>
                    </div>

                    <!-- Bank Transfer Details (hidden by default) -->
                    <div id="bankFields" style="display: none;">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            Please transfer to the following account:
                        </div>
                        <div style="background: #f8fafc; padding: 1rem; border-radius: 12px;">
                            <p><strong>Bank:</strong> Kenya Commercial Bank</p>
                            <p><strong>Account Name:</strong> CASMS Auto Parts</p>
                            <p><strong>Account Number:</strong> 1234567890</p>
                            <p><strong>Branch:</strong> Nairobi</p>
                            <p><strong>SWIFT:</strong> KCBLKENX</p>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-outline" onclick="closeModal('checkoutModal')">Cancel</button>
                    <button type="submit" name="checkout" class="btn-primary">Place Order</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Part Details Modal -->
    <div class="modal" id="partModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="partModalTitle">Part Details</h3>
                <button class="modal-close" onclick="closeModal('partModal')">&times;</button>
            </div>
            <div class="modal-body" id="partModalBody">
                <!-- Content loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button class="btn-outline" onclick="closeModal('partModal')">Close</button>
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

        // Checkout modal
        function openCheckoutModal() {
            openModal('checkoutModal');
        }

        // Payment method selection
        function selectPaymentMethod(method) {
            document.querySelectorAll('.payment-method').forEach(el => {
                el.classList.remove('selected');
            });
            event.currentTarget.classList.add('selected');
            
            document.getElementById('paymentMethod').value = method;
            
            document.getElementById('mpesaFields').style.display = method === 'mpesa' ? 'block' : 'none';
            document.getElementById('cardFields').style.display = method === 'card' ? 'block' : 'none';
            document.getElementById('bankFields').style.display = method === 'bank' ? 'block' : 'none';
        }

        // View part details
        function viewPartDetails(partId) {
            // In a real application, you would fetch part details via AJAX
            // For now, we'll just show a message
            document.getElementById('partModalTitle').textContent = 'Part Details';
            document.getElementById('partModalBody').innerHTML = `
                <p>Detailed information about this part would be displayed here.</p>
                <p>This includes specifications, compatibility, warranty information, etc.</p>
            `;
            openModal('partModal');
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