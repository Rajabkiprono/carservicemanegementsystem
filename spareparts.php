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

// Handle Add New Spare Part
if(isset($_POST['add'])){
    try {
        // Validate CSRF token
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid security token. Please try again.");
        }

        // Validate inputs
        if(empty($_POST['name']) || empty($_POST['price']) || empty($_POST['stock'])) {
            throw new Exception("Please fill in all required fields");
        }

        // Sanitize and validate
        $name = trim($_POST['name']);
        $description = trim($_POST['description'] ?? '');
        $price = floatval($_POST['price']);
        $stock = intval($_POST['stock']);

        if($price <= 0) {
            throw new Exception("Price must be greater than 0");
        }
        if($stock < 0) {
            throw new Exception("Stock cannot be negative");
        }

        // Begin transaction
        $db->beginTransaction();

        $stmt = $db->prepare("INSERT INTO spare_parts (name, description, price, stock, created_at) VALUES (:name, :desc, :price, :stock, NOW())");
        $result = $stmt->execute([
            ":name" => $name,
            ":desc" => $description,
            ":price" => $price,
            ":stock" => $stock
        ]);

        if($result) {
            $db->commit();
            $_SESSION['success'] = "Spare part added successfully!";
            header("Location: spareparts.php");
            exit();
        } else {
            throw new Exception("Failed to add spare part");
        }
    } catch (Exception $e) {
        $db->rollBack();
        $error = $e->getMessage();
        error_log("Add spare part error: " . $e->getMessage());
    }
}

// Handle Delete Spare Part
if(isset($_POST['delete'])){
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid security token");
        }

        $id = intval($_POST['id']);
        
        $stmt = $db->prepare("DELETE FROM spare_parts WHERE id = ?");
        $result = $stmt->execute([$id]);

        if($result) {
            $_SESSION['success'] = "Spare part deleted successfully!";
        } else {
            throw new Exception("Failed to delete spare part");
        }
        header("Location: spareparts.php");
        exit();
    } catch (Exception $e) {
        $error = $e->getMessage();
        error_log("Delete spare part error: " . $e->getMessage());
    }
}

// Handle Update Stock
if(isset($_POST['update_stock'])){
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid security token");
        }

        $id = intval($_POST['id']);
        $stock = intval($_POST['stock']);

        if($stock < 0) {
            throw new Exception("Stock cannot be negative");
        }

        $stmt = $db->prepare("UPDATE spare_parts SET stock = ? WHERE id = ?");
        $result = $stmt->execute([$stock, $id]);

        if($result) {
            $_SESSION['success'] = "Stock updated successfully!";
        } else {
            throw new Exception("Failed to update stock");
        }
        header("Location: spareparts.php");
        exit();
    } catch (Exception $e) {
        $error = $e->getMessage();
        error_log("Update stock error: " . $e->getMessage());
    }
}

// Fetch spare parts
try {
    $stmt = $db->query("SELECT * FROM spare_parts ORDER BY id DESC");
    $parts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate statistics
    $totalParts = count($parts);
    $totalValue = 0;
    $lowStock = 0;
    $outOfStock = 0;
    
    foreach($parts as $part) {
        $totalValue += $part['price'] * $part['stock'];
        if($part['stock'] == 0) {
            $outOfStock++;
        } elseif($part['stock'] <= 5) {
            $lowStock++;
        }
    }
} catch (PDOException $e) {
    $parts = [];
    $totalParts = 0;
    $totalValue = 0;
    $lowStock = 0;
    $outOfStock = 0;
    error_log("Error fetching spare parts: " . $e->getMessage());
}

// Get user details
try {
    $userStmt = $db->prepare("SELECT name, email FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = ['name' => $_SESSION['user_name'] ?? 'Admin', 'email' => ''];
}

// Get greeting based on time
$currentHour = (int)date('H');
$greeting = $currentHour < 12 ? 'Good Morning' : ($currentHour < 17 ? 'Good Afternoon' : 'Good Evening');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CASMS | Spare Parts Management</title>
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

        body.dark .stat-card {
            background: #1e293b;
            border-color: #334155;
        }

        body.dark .card-header {
            background: #0f172a;
            border-bottom-color: #334155;
        }

        body.dark .form-control, 
        body.dark .input-group-text {
            background: #0f172a;
            border-color: #334155;
            color: #e2e8f0;
        }

        body.dark .table {
            color: #e2e8f0;
        }

        body.dark .table thead th {
            background: #0f172a;
            border-bottom-color: #334155;
            color: #94a3b8;
        }

        body.dark .table tbody td {
            border-color: #334155;
        }

        body.dark .table-striped tbody tr:nth-of-type(odd) {
            background-color: #1e293b;
        }

        body.dark .table-hover tbody tr:hover {
            background-color: #0f172a;
        }

        body.dark .btn-outline-secondary {
            border-color: #334155;
            color: #94a3b8;
        }

        body.dark .btn-outline-secondary:hover {
            background: #1e293b;
            border-color: #2563eb;
            color: #2563eb;
        }

        body.dark .user-profile {
            background: #0f172a !important;
        }

        body.dark .dropdown-menu {
            background: #1e293b;
            border-color: #334155;
        }

        body.dark .dropdown-item {
            color: #e2e8f0;
        }

        body.dark .dropdown-item:hover {
            background: #0f172a;
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

        /* Content Card */
        .content-card {
            background: white;
            border-radius: 32px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            animation: slideUp 0.5s ease;
        }

        .card-header {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            padding: 2rem;
            color: white;
        }

        .card-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .card-header p {
            opacity: 0.9;
            font-size: 1rem;
        }

        .card-body {
            padding: 2rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            padding: 1.5rem;
            transition: all 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px -5px rgba(37, 99, 235, 0.2);
            border-color: #2563eb;
        }

        .stat-icon {
            font-size: 2rem;
            margin-bottom: 1rem;
        }

        .stat-label {
            font-size: 0.875rem;
            color: #64748b;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: #1e293b;
        }

        .stat-desc {
            font-size: 0.875rem;
            color: #64748b;
            margin-top: 0.5rem;
        }

        .low-stock {
            color: #f59e0b;
        }

        .out-of-stock {
            color: #ef4444;
        }

        /* Form Styles */
        .add-form {
            background: #f8fafc;
            border-radius: 20px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .form-title {
            font-size: 1.125rem;
            font-weight: 600;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .input-group-custom {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .input-group-custom .form-control {
            flex: 1;
            min-width: 150px;
            padding: 0.75rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-family: 'Inter', sans-serif;
            font-size: 0.95rem;
            transition: all 0.2s;
        }

        .input-group-custom .form-control:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .btn-add {
            padding: 0.75rem 2rem;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            border: none;
            border-radius: 12px;
            color: white;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            min-width: 120px;
        }

        .btn-add:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(37, 99, 235, 0.5);
        }

        /* Table Styles */
        .table-container {
            overflow-x: auto;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95rem;
        }

        .table thead th {
            background: #f8fafc;
            padding: 1rem 1.5rem;
            text-align: left;
            font-weight: 600;
            color: #475569;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }

        .table tbody td {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }

        .table tbody tr:last-child td {
            border-bottom: none;
        }

        .table tbody tr:hover {
            background-color: #f8fafc;
        }

        .stock-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 1rem;
            border-radius: 40px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .stock-badge.high {
            background: #dbeafe;
            color: #1e40af;
        }

        .stock-badge.medium {
            background: #fef3c7;
            color: #92400e;
        }

        .stock-badge.low {
            background: #fee2e2;
            color: #b91c1c;
        }

        .action-btns {
            display: flex;
            gap: 0.5rem;
        }

        .btn-action {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: transparent;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 1rem;
        }

        .btn-action:hover {
            background: #f1f5f9;
            border-color: #2563eb;
        }

        .btn-action.edit:hover {
            border-color: #f59e0b;
            color: #f59e0b;
        }

        .btn-action.delete:hover {
            border-color: #ef4444;
            color: #ef4444;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1100;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 24px;
            width: 90%;
            max-width: 450px;
            padding: 2rem;
            animation: slideUp 0.3s;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .modal-header h3 {
            font-size: 1.25rem;
            font-weight: 600;
        }

        .modal-close {
            background: transparent;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #64748b;
        }

        .modal-body {
            margin-bottom: 1.5rem;
        }

        .modal-actions {
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
        }

        .btn-modal {
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
        }

        .btn-modal.cancel {
            background: #f1f5f9;
            color: #475569;
        }

        .btn-modal.cancel:hover {
            background: #e2e8f0;
        }

        .btn-modal.confirm {
            background: #2563eb;
            color: white;
        }

        .btn-modal.confirm:hover {
            background: #1d4ed8;
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(37, 99, 235, 0.5);
        }

        .btn-modal.delete {
            background: #ef4444;
            color: white;
        }

        .btn-modal.delete:hover {
            background: #dc2626;
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

        /* Back Button */
        .back-section {
            margin-top: 2rem;
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

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
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
                grid-template-columns: 1fr;
            }

            .input-group-custom {
                flex-direction: column;
            }

            .input-group-custom .form-control,
            .btn-add {
                width: 100%;
            }

            .table thead th,
            .table tbody td {
                padding: 0.75rem 1rem;
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
                <a href="spareparts.php" class="active">Spare Parts</a>
                <a href="users.php">Users</a>
                <a href="reports.php">Reports</a>
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
                                <span class="greeting-badge">ADMIN PANEL</span>
                                <h1 class="welcome-title">
                                    <span class="greeting-word"><?php echo htmlspecialchars($greeting); ?>,</span>
                                    <span class="user-name"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Admin'); ?></span>
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
                                    <span class="chip-text"><?php echo $totalParts; ?> Parts</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="navbar-right">
                    <div class="action-group">
                        <button class="btn-icon" onclick="toggleMode()" title="Toggle Dark Mode" aria-label="Toggle dark mode">
                            <span class="mode-icon" id="modeIcon">🌙</span>
                        </button>
                        
                        <!-- User Profile -->
                        <div class="user-profile-container">
                            <div class="user-profile" onclick="toggleDropdown()" id="userProfile">
                                <div class="avatar">
                                    <span><?php echo strtoupper(substr(htmlspecialchars($_SESSION['user_name'] ?? 'A'), 0, 1)); ?></span>
                                </div>
                                <div class="user-info">
                                    <span class="user-name"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Admin'); ?></span>
                                    <span class="user-role">Administrator</span>
                                </div>
                                <svg class="dropdown-icon" width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M7 10L12 15L17 10H7Z"/>
                                </svg>
                            </div>
                            
                            <div class="dropdown-menu" id="dropdownMenu">
                                <div class="dropdown-header">
                                    <div class="user-name"><?php echo htmlspecialchars($user['name'] ?? 'Admin'); ?></div>
                                    <div class="user-email"><?php echo htmlspecialchars($user['email'] ?? 'admin@example.com'); ?></div>
                                </div>
                                <div class="dropdown-items">
                                    <a href="profile.php" class="dropdown-item">
                                        <span class="item-icon">👤</span>
                                        My Profile
                                    </a>
                                    <a href="settings.php" class="dropdown-item">
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

            <!-- Main Card -->
            <div class="content-card">
                <div class="card-header">
                    <h1>Spare Parts Management</h1>
                    <p>Manage your inventory of spare parts and accessories</p>
                </div>

                <div class="card-body">
                    <!-- Stats Grid -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">📦</div>
                            <div class="stat-label">Total Parts</div>
                            <div class="stat-value"><?php echo $totalParts; ?></div>
                            <div class="stat-desc">Unique spare parts</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">💰</div>
                            <div class="stat-label">Inventory Value</div>
                            <div class="stat-value">₹<?php echo number_format($totalValue, 2); ?></div>
                            <div class="stat-desc">Total stock value</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">⚠️</div>
                            <div class="stat-label">Low Stock</div>
                            <div class="stat-value <?php echo $lowStock > 0 ? 'low-stock' : ''; ?>"><?php echo $lowStock; ?></div>
                            <div class="stat-desc">Items with ≤5 units</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">❌</div>
                            <div class="stat-label">Out of Stock</div>
                            <div class="stat-value <?php echo $outOfStock > 0 ? 'out-of-stock' : ''; ?>"><?php echo $outOfStock; ?></div>
                            <div class="stat-desc">Items with 0 units</div>
                        </div>
                    </div>

                    <!-- Messages -->
                    <?php if(isset($error) && !empty($error)): ?>
                        <div class="alert alert-error" role="alert">
                            <span>⚠️</span>
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <?php if(isset($_SESSION['success'])): ?>
                        <div class="alert alert-success" role="alert">
                            <span>✅</span>
                            <?php 
                            echo htmlspecialchars($_SESSION['success']); 
                            unset($_SESSION['success']);
                            ?>
                        </div>
                    <?php endif; ?>

                    <!-- Add New Part Form -->
                    <div class="add-form">
                        <div class="form-title">
                            <span>➕</span>
                            Add New Spare Part
                        </div>
                        <form method="POST" class="input-group-custom">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="text" name="name" class="form-control" placeholder="Part Name *" required>
                            <input type="text" name="description" class="form-control" placeholder="Description">
                            <input type="number" step="0.01" name="price" class="form-control" placeholder="Price (₹) *" required min="0.01">
                            <input type="number" name="stock" class="form-control" placeholder="Stock *" required min="0">
                            <button type="submit" name="add" class="btn-add">Add Part</button>
                        </form>
                    </div>

                    <!-- Parts Table -->
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Part Name</th>
                                    <th>Description</th>
                                    <th>Price (₹)</th>
                                    <th>Stock</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($parts)): ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center; padding: 3rem; color: #64748b;">
                                            <div style="font-size: 3rem; margin-bottom: 1rem;">📦</div>
                                            <div style="font-size: 1.125rem; font-weight: 500;">No spare parts found</div>
                                            <div style="font-size: 0.875rem; margin-top: 0.5rem;">Add your first spare part using the form above</div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach($parts as $part): ?>
                                        <?php 
                                        $stockStatus = $part['stock'] > 10 ? 'high' : ($part['stock'] > 0 ? 'medium' : 'low');
                                        $statusText = $part['stock'] > 10 ? 'In Stock' : ($part['stock'] > 0 ? 'Low Stock' : 'Out of Stock');
                                        ?>
                                        <tr>
                                            <td><strong>#<?php echo $part['id']; ?></strong></td>
                                            <td><?php echo htmlspecialchars($part['name']); ?></td>
                                            <td><?php echo htmlspecialchars($part['description'] ?: '-'); ?></td>
                                            <td><strong>₹<?php echo number_format($part['price'], 2); ?></strong></td>
                                            <td><?php echo $part['stock']; ?> units</td>
                                            <td>
                                                <span class="stock-badge <?php echo $stockStatus; ?>">
                                                    <?php echo $statusText; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-btns">
                                                    <button class="btn-action" onclick="openStockModal(<?php echo $part['id']; ?>, '<?php echo htmlspecialchars(addslashes($part['name'])); ?>', <?php echo $part['stock']; ?>)" title="Update Stock">
                                                        📦
                                                    </button>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirmDelete('<?php echo htmlspecialchars(addslashes($part['name'])); ?>')">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                        <input type="hidden" name="id" value="<?php echo $part['id']; ?>">
                                                        <button type="submit" name="delete" class="btn-action delete" title="Delete Part">
                                                            🗑️
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Back Button -->
                    <div class="back-section">
                        <a href="dashboard.php" class="btn-back">
                            <span class="back-icon">←</span>
                            Back to Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Stock Update Modal -->
    <div class="modal" id="stockModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Update Stock</h3>
                <button class="modal-close" onclick="closeStockModal()">×</button>
            </div>
            <form method="POST" id="stockForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="id" id="stockPartId">
                <div class="modal-body">
                    <p>Update stock for <strong id="stockPartName"></strong></p>
                    <input type="number" name="stock" id="stockQuantity" class="form-control" min="0" required style="margin-top: 1rem;">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-modal cancel" onclick="closeStockModal()">Cancel</button>
                    <button type="submit" name="update_stock" class="btn-modal confirm">Update Stock</button>
                </div>
            </form>
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
            dropdown.classList.toggle('show');
            profile.classList.toggle('active');
        }

        window.addEventListener('click', function(event) {
            const dropdown = document.getElementById('dropdownMenu');
            const profile = document.getElementById('userProfile');
            const container = document.querySelector('.user-profile-container');
            
            if (!container.contains(event.target)) {
                dropdown.classList.remove('show');
                profile.classList.remove('active');
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

        // Stock Modal
        function openStockModal(id, name, currentStock) {
            document.getElementById('stockPartId').value = id;
            document.getElementById('stockPartName').textContent = name;
            document.getElementById('stockQuantity').value = currentStock;
            document.getElementById('stockModal').classList.add('show');
        }

        function closeStockModal() {
            document.getElementById('stockModal').classList.remove('show');
        }

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('stockModal');
            if (event.target === modal) {
                closeStockModal();
            }
        });

        // Confirm delete
        function confirmDelete(partName) {
            return confirm(`Are you sure you want to delete "${partName}"? This action cannot be undone.`);
        }

        // Form validation
        document.querySelector('form[method="POST"]')?.addEventListener('submit', function(e) {
            if (e.target.querySelector('button[name="add"]')) {
                const price = parseFloat(e.target.querySelector('[name="price"]').value);
                const stock = parseInt(e.target.querySelector('[name="stock"]').value);
                
                if (price <= 0) {
                    e.preventDefault();
                    alert('Price must be greater than 0');
                    return;
                }
                
                if (stock < 0) {
                    e.preventDefault();
                    alert('Stock cannot be negative');
                    return;
                }
            }
        });

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>
</body>
</html>