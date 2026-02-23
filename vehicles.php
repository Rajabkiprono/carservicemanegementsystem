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

// Handle POST requests for vehicle CRUD
if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = ['success' => false, 'message' => ''];
    
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $response['message'] = 'Invalid security token';
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
    
    if(isset($_POST['action'])) {
        try {
            switch($_POST['action']) {
                case 'add_vehicle':
                    // Validate required fields
                    if(empty($_POST['brand']) || empty($_POST['model']) || empty($_POST['year']) || empty($_POST['license_plate'])) {
                        throw new Exception("Please fill in all required fields");
                    }
                    
                    // Check if license plate already exists
                    $checkStmt = $db->prepare("SELECT id FROM vehicles WHERE license_plate = ? AND user_id = ?");
                    $checkStmt->execute([strtoupper($_POST['license_plate']), $_SESSION['user_id']]);
                    if($checkStmt->fetch()) {
                        throw new Exception("License plate already registered");
                    }
                    
                    $stmt = $db->prepare("
                        INSERT INTO vehicles (user_id, brand, model, year, license_plate, vin, color, fuel_type, transmission, created_at) 
                        VALUES (:user_id, :brand, :model, :year, :license_plate, :vin, :color, :fuel_type, :transmission, NOW())
                    ");
                    $stmt->execute([
                        ":user_id" => $_SESSION['user_id'],
                        ":brand" => trim($_POST['brand']),
                        ":model" => trim($_POST['model']),
                        ":year" => intval($_POST['year']),
                        ":license_plate" => strtoupper(trim($_POST['license_plate'])),
                        ":vin" => !empty($_POST['vin']) ? strtoupper(trim($_POST['vin'])) : null,
                        ":color" => !empty($_POST['color']) ? trim($_POST['color']) : null,
                        ":fuel_type" => $_POST['fuel_type'] ?? 'Petrol',
                        ":transmission" => $_POST['transmission'] ?? 'Manual'
                    ]);
                    
                    $response['success'] = true;
                    $response['message'] = 'Vehicle added successfully';
                    break;
                    
                case 'update_vehicle':
                    // Validate required fields
                    if(empty($_POST['brand']) || empty($_POST['model']) || empty($_POST['year']) || empty($_POST['license_plate'])) {
                        throw new Exception("Please fill in all required fields");
                    }
                    
                    // Check if license plate already exists for another vehicle
                    $checkStmt = $db->prepare("SELECT id FROM vehicles WHERE license_plate = ? AND user_id = ? AND id != ?");
                    $checkStmt->execute([strtoupper($_POST['license_plate']), $_SESSION['user_id'], $_POST['vehicle_id']]);
                    if($checkStmt->fetch()) {
                        throw new Exception("License plate already registered to another vehicle");
                    }
                    
                    $stmt = $db->prepare("
                        UPDATE vehicles 
                        SET brand = :brand, model = :model, year = :year, 
                            license_plate = :license_plate, vin = :vin, color = :color,
                            fuel_type = :fuel_type, transmission = :transmission,
                            updated_at = NOW()
                        WHERE id = :id AND user_id = :user_id
                    ");
                    $stmt->execute([
                        ":id" => intval($_POST['vehicle_id']),
                        ":user_id" => $_SESSION['user_id'],
                        ":brand" => trim($_POST['brand']),
                        ":model" => trim($_POST['model']),
                        ":year" => intval($_POST['year']),
                        ":license_plate" => strtoupper(trim($_POST['license_plate'])),
                        ":vin" => !empty($_POST['vin']) ? strtoupper(trim($_POST['vin'])) : null,
                        ":color" => !empty($_POST['color']) ? trim($_POST['color']) : null,
                        ":fuel_type" => $_POST['fuel_type'] ?? 'Petrol',
                        ":transmission" => $_POST['transmission'] ?? 'Manual'
                    ]);
                    
                    $response['success'] = true;
                    $response['message'] = 'Vehicle updated successfully';
                    break;
                    
                case 'delete_vehicle':
                    // Check if vehicle has any service appointments
                    $checkStmt = $db->prepare("SELECT COUNT(*) as count FROM book_service WHERE vehicle_id = ?");
                    $checkStmt->execute([$_POST['vehicle_id']]);
                    $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if($result['count'] > 0) {
                        $response['message'] = 'Cannot delete vehicle with existing service records';
                    } else {
                        $stmt = $db->prepare("DELETE FROM vehicles WHERE id = :id AND user_id = :user_id");
                        $stmt->execute([
                            ":id" => intval($_POST['vehicle_id']),
                            ":user_id" => $_SESSION['user_id']
                        ]);
                        $response['success'] = true;
                        $response['message'] = 'Vehicle deleted successfully';
                    }
                    break;
                    
                case 'get_vehicle':
                    $stmt = $db->prepare("SELECT * FROM vehicles WHERE id = ? AND user_id = ?");
                    $stmt->execute([$_POST['vehicle_id'], $_SESSION['user_id']]);
                    $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if($vehicle) {
                        $response['success'] = true;
                        $response['data'] = $vehicle;
                    } else {
                        $response['message'] = 'Vehicle not found';
                    }
                    break;
            }
        } catch (PDOException $e) {
            $response['message'] = 'Database error: ' . $e->getMessage();
            error_log("Vehicle action error: " . $e->getMessage());
        } catch (Exception $e) {
            $response['message'] = $e->getMessage();
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Fetch user's vehicles
try {
    $stmt = $db->prepare("SELECT * FROM vehicles WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$_SESSION['user_id']]);
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get service statistics for each vehicle
    foreach($vehicles as &$vehicle) {
        $serviceStmt = $db->prepare("
            SELECT COUNT(*) as total_services,
                   SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed_services,
                   MAX(appointment_date) as last_service,
                   SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending_services
            FROM book_service 
            WHERE vehicle_id = ?
        ");
        $serviceStmt->execute([$vehicle['id']]);
        $stats = $serviceStmt->fetch(PDO::FETCH_ASSOC);
        $vehicle['total_services'] = $stats['total_services'] ?? 0;
        $vehicle['completed_services'] = $stats['completed_services'] ?? 0;
        $vehicle['pending_services'] = $stats['pending_services'] ?? 0;
        $vehicle['last_service'] = $stats['last_service'] ?? null;
        
        // Calculate next service due (example: 6 months after last service)
        if($vehicle['last_service']) {
            $lastDate = new DateTime($vehicle['last_service']);
            $dueDate = clone $lastDate;
            $dueDate->modify('+6 months');
            $vehicle['service_due'] = $dueDate->format('Y-m-d');
            $vehicle['days_until_due'] = (new DateTime())->diff($dueDate)->days;
        } else {
            $vehicle['service_due'] = null;
            $vehicle['days_until_due'] = null;
        }
    }
    
} catch (PDOException $e) {
    $vehicles = [];
    error_log("Error fetching vehicles: " . $e->getMessage());
}

// Get user details for profile
try {
    $userStmt = $db->prepare("SELECT name, email FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $user = ['name' => $_SESSION['user_name'] ?? 'User', 'email' => ''];
}

// Get current date for greeting
$currentHour = (int)date('H');
$greeting = $currentHour < 12 ? 'Good Morning' : ($currentHour < 17 ? 'Good Afternoon' : 'Good Evening');

// Calculate statistics
$totalVehicles = count($vehicles);
$totalServices = array_sum(array_column($vehicles, 'total_services'));
$pendingServices = array_sum(array_column($vehicles, 'pending_services'));
$vehiclesDueService = array_filter($vehicles, function($v) {
    return $v['days_until_due'] !== null && $v['days_until_due'] <= 30;
});
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CASMS | My Vehicles</title>
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

        body.dark .vehicle-card {
            background: #1e293b;
            border-color: #334155;
        }

        body.dark .vehicle-card:hover {
            border-color: #2563eb;
        }

        body.dark .vehicle-header {
            border-color: #334155;
        }

        body.dark .vehicle-detail-item {
            color: #94a3b8;
        }

        body.dark .vehicle-detail-label {
            color: #64748b;
        }

        body.dark .service-badge {
            background: #0f172a;
            color: #94a3b8;
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

        body.dark .form-input,
        body.dark .form-select,
        body.dark .form-textarea {
            background: #0f172a;
            border-color: #334155;
            color: #f1f5f9;
        }

        body.dark .form-label {
            color: #94a3b8;
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

        /* Layout */
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
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

        /* Main content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
        }

        /* Navbar */
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

        /* User Profile */
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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

        /* Action Bar */
        .action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .action-bar h2 {
            font-size: 1.5rem;
            font-weight: 600;
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
        }

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        /* Vehicle Grid */
        .vehicles-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .vehicle-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 24px;
            overflow: hidden;
            transition: all 0.3s;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .vehicle-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            border-color: #2563eb;
        }

        .vehicle-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .vehicle-icon {
            width: 48px;
            height: 48px;
            background: white;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .vehicle-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1e293b;
        }

        .vehicle-subtitle {
            font-size: 0.875rem;
            color: #64748b;
        }

        .vehicle-status {
            display: flex;
            gap: 0.5rem;
        }

        .status-tag {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .status-tag.active {
            background: #ecfdf5;
            color: #065f46;
        }

        .status-tag.warning {
            background: #fef3c7;
            color: #92400e;
        }

        .vehicle-body {
            padding: 1.5rem;
        }

        .vehicle-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .vehicle-detail-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .vehicle-detail-label {
            font-size: 0.75rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .vehicle-detail-value {
            font-weight: 600;
            color: #1e293b;
        }

        .service-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e2e8f0;
        }

        .service-badge {
            text-align: center;
            padding: 0.5rem;
            background: #f8fafc;
            border-radius: 12px;
            font-size: 0.875rem;
        }

        .service-count {
            font-weight: 700;
            color: #2563eb;
            display: block;
            font-size: 1.25rem;
        }

        .service-due {
            text-align: center;
            padding: 0.75rem;
            background: #fef3c7;
            border-radius: 12px;
            margin-top: 1rem;
            font-size: 0.875rem;
            color: #92400e;
        }

        .vehicle-footer {
            padding: 1rem 1.5rem;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }

        .vehicle-action-btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 30px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            background: white;
            border: 1px solid #e2e8f0;
        }

        .vehicle-action-btn:hover {
            background: #2563eb;
            color: white;
            border-color: #2563eb;
        }

        .vehicle-action-btn.delete:hover {
            background: #ef4444;
            border-color: #ef4444;
            color: white;
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
            max-width: 550px;
            width: 90%;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
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
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            transition: all 0.2s;
        }

        .modal-close:hover {
            background: #f1f5f9;
            color: #1e293b;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #475569;
        }

        .form-label .required {
            color: #ef4444;
            margin-left: 2px;
        }

        .form-input,
        .form-select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-family: 'Inter', sans-serif;
            font-size: 0.95rem;
            transition: all 0.2s;
        }

        .form-input:focus,
        .form-select:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .form-input.error {
            border-color: #ef4444;
        }

        .error-message {
            color: #ef4444;
            font-size: 0.75rem;
            margin-top: 0.25rem;
        }

        .modal-footer {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
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

        .toast-icon {
            font-size: 1.25rem;
        }

        .toast-message {
            flex: 1;
            font-weight: 500;
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
            
            .vehicles-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .vehicle-footer {
                flex-direction: column;
            }
            
            .vehicle-action-btn {
                width: 100%;
                justify-content: center;
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
                <a href="vehicles.php" class="active">Vehicles</a>
                <a href="services.php">Services</a>
                <a href="book_service.php">Book Service</a>
                <a href="service_history.php">Service History</a>
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
                                <span class="greeting-badge">VEHICLE MANAGEMENT</span>
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
                                <span>Vehicle Status</span>
                            </div>
                            <div class="message-text">
                                <span class="highlight"><?php echo $totalVehicles; ?> vehicles</span> registered • 
                                <span class="highlight"><?php echo $pendingServices; ?> pending</span> services
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="navbar-right">
                    <div class="action-group">
                        <button class="btn-icon" onclick="toggleMode()" title="Toggle Dark Mode">
                            <span class="mode-icon" id="modeIcon">🌙</span>
                        </button>
                        
                        <button class="btn-icon" title="Notifications">
                            <?php if($pendingServices > 0 || count($vehiclesDueService) > 0): ?>
                                <span class="notification-badge"><?php echo $pendingServices + count($vehiclesDueService); ?></span>
                            <?php endif; ?>
                            <i class="fas fa-bell"></i>
                        </button>
                        
                        <!-- User Profile with Dropdown -->
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
                            
                            <div class="dropdown-menu" id="dropdownMenu">
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
                    <div class="stats-icon">
                        <i class="fas fa-car"></i>
                    </div>
                    <div class="stats-value"><?php echo $totalVehicles; ?></div>
                    <div class="stats-label">Total Vehicles</div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">
                        <i class="fas fa-tools"></i>
                    </div>
                    <div class="stats-value"><?php echo $totalServices; ?></div>
                    <div class="stats-label">Total Services</div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stats-value"><?php echo $pendingServices; ?></div>
                    <div class="stats-label">Pending Services</div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stats-value"><?php echo count($vehiclesDueService); ?></div>
                    <div class="stats-label">Due for Service</div>
                </div>
            </div>

            <!-- Action Bar -->
            <div class="action-bar">
                <h2>My Vehicles</h2>
                <button class="btn btn-primary" onclick="openAddVehicleModal()">
                    <i class="fas fa-plus"></i> Add New Vehicle
                </button>
            </div>

            <!-- Vehicles Grid -->
            <?php if(empty($vehicles)): ?>
                <div class="empty-state">
                    <div class="empty-icon">🚗</div>
                    <div class="empty-title">No Vehicles Added Yet</div>
                    <div class="empty-text">Add your first vehicle to start booking services and tracking maintenance.</div>
                    <button class="btn btn-primary" onclick="openAddVehicleModal()">
                        <i class="fas fa-plus"></i> Add Your First Vehicle
                    </button>
                </div>
            <?php else: ?>
                <div class="vehicles-grid">
                    <?php foreach($vehicles as $vehicle): ?>
                        <div class="vehicle-card" id="vehicle-<?php echo $vehicle['id']; ?>">
                            <div class="vehicle-header">
                                <div class="vehicle-icon">
                                    <?php
                                    $icons = [
                                        'Toyota' => '🇯🇵',
                                        'Honda' => '🇯🇵',
                                        'Hyundai' => '🇰🇷',
                                        'Kia' => '🇰🇷',
                                        'Mahindra' => '🇮🇳',
                                        'Tata' => '🇮🇳',
                                        'Maruti' => '🇮🇳',
                                        'Ford' => '🇺🇸',
                                        'Chevrolet' => '🇺🇸',
                                        'BMW' => '🇩🇪',
                                        'Mercedes' => '🇩🇪',
                                        'Audi' => '🇩🇪',
                                        'Volkswagen' => '🇩🇪',
                                        'default' => '🚗'
                                    ];
                                    echo $icons[$vehicle['brand']] ?? $icons['default'];
                                    ?>
                                </div>
                                <div>
                                    <div class="vehicle-title"><?php echo htmlspecialchars($vehicle['brand'] . ' ' . $vehicle['model']); ?></div>
                                    <div class="vehicle-subtitle"><?php echo htmlspecialchars($vehicle['license_plate']); ?></div>
                                </div>
                                <div class="vehicle-status">
                                    <?php if($vehicle['pending_services'] > 0): ?>
                                        <span class="status-tag warning">Pending</span>
                                    <?php else: ?>
                                        <span class="status-tag active">Active</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="vehicle-body">
                                <div class="vehicle-details">
                                    <div class="vehicle-detail-item">
                                        <span class="vehicle-detail-label">Year</span>
                                        <span class="vehicle-detail-value"><?php echo htmlspecialchars($vehicle['year']); ?></span>
                                    </div>
                                    <div class="vehicle-detail-item">
                                        <span class="vehicle-detail-label">Color</span>
                                        <span class="vehicle-detail-value"><?php echo htmlspecialchars($vehicle['color'] ?? 'N/A'); ?></span>
                                    </div>
                                    <div class="vehicle-detail-item">
                                        <span class="vehicle-detail-label">Fuel Type</span>
                                        <span class="vehicle-detail-value"><?php echo htmlspecialchars($vehicle['fuel_type'] ?? 'N/A'); ?></span>
                                    </div>
                                    <div class="vehicle-detail-item">
                                        <span class="vehicle-detail-label">Transmission</span>
                                        <span class="vehicle-detail-value"><?php echo htmlspecialchars($vehicle['transmission'] ?? 'N/A'); ?></span>
                                    </div>
                                    <?php if(!empty($vehicle['vin'])): ?>
                                    <div class="vehicle-detail-item" style="grid-column: span 2;">
                                        <span class="vehicle-detail-label">VIN</span>
                                        <span class="vehicle-detail-value"><?php echo htmlspecialchars($vehicle['vin']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <div class="service-stats">
                                    <div class="service-badge">
                                        <span class="service-count"><?php echo $vehicle['total_services']; ?></span>
                                        Total
                                    </div>
                                    <div class="service-badge">
                                        <span class="service-count"><?php echo $vehicle['completed_services']; ?></span>
                                        Completed
                                    </div>
                                    <div class="service-badge">
                                        <span class="service-count"><?php echo $vehicle['pending_services']; ?></span>
                                        Pending
                                    </div>
                                </div>

                                <?php if($vehicle['days_until_due'] !== null && $vehicle['days_until_due'] <= 30): ?>
                                    <div class="service-due">
                                        <i class="fas fa-exclamation-circle"></i>
                                        Service due in <?php echo $vehicle['days_until_due']; ?> days
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="vehicle-footer">
                                <button class="vehicle-action-btn" onclick="editVehicle(<?php echo $vehicle['id']; ?>)">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button class="vehicle-action-btn" onclick="viewVehicleHistory(<?php echo $vehicle['id']; ?>)">
                                    <i class="fas fa-history"></i> History
                                </button>
                                <button class="vehicle-action-btn delete" onclick="deleteVehicle(<?php echo $vehicle['id']; ?>)">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Vehicle Modal -->
    <div class="modal" id="addVehicleModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Add New Vehicle</h3>
                <button class="modal-close" onclick="closeModal('addVehicleModal')">&times;</button>
            </div>
            <form id="addVehicleForm" onsubmit="submitAddVehicle(event)">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Brand <span class="required">*</span></label>
                            <input type="text" class="form-input" name="brand" required placeholder="e.g., Toyota, Honda">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Model <span class="required">*</span></label>
                            <input type="text" class="form-input" name="model" required placeholder="e.g., Camry, Civic">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Year <span class="required">*</span></label>
                            <input type="number" class="form-input" name="year" required min="1900" max="<?php echo date('Y') + 1; ?>" placeholder="2024">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Color</label>
                            <input type="text" class="form-input" name="color" placeholder="e.g., Red, Blue">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">License Plate <span class="required">*</span></label>
                            <input type="text" class="form-input" name="license_plate" required placeholder="e.g., ABC-1234" style="text-transform: uppercase;">
                        </div>
                        <div class="form-group">
                            <label class="form-label">VIN</label>
                            <input type="text" class="form-input" name="vin" placeholder="Vehicle Identification Number">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Fuel Type</label>
                            <select class="form-select" name="fuel_type">
                                <option value="Petrol">Petrol</option>
                                <option value="Diesel">Diesel</option>
                                <option value="Electric">Electric</option>
                                <option value="Hybrid">Hybrid</option>
                                <option value="CNG">CNG</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Transmission</label>
                            <select class="form-select" name="transmission">
                                <option value="Manual">Manual</option>
                                <option value="Automatic">Automatic</option>
                                <option value="CVT">CVT</option>
                                <option value="DCT">DCT</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('addVehicleModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Vehicle</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Vehicle Modal -->
    <div class="modal" id="editVehicleModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Edit Vehicle</h3>
                <button class="modal-close" onclick="closeModal('editVehicleModal')">&times;</button>
            </div>
            <form id="editVehicleForm" onsubmit="submitEditVehicle(event)">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="vehicle_id" id="editVehicleId">
                
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Brand <span class="required">*</span></label>
                            <input type="text" class="form-input" name="brand" id="editBrand" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Model <span class="required">*</span></label>
                            <input type="text" class="form-input" name="model" id="editModel" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Year <span class="required">*</span></label>
                            <input type="number" class="form-input" name="year" id="editYear" required min="1900" max="<?php echo date('Y') + 1; ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Color</label>
                            <input type="text" class="form-input" name="color" id="editColor">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">License Plate <span class="required">*</span></label>
                            <input type="text" class="form-input" name="license_plate" id="editLicensePlate" required style="text-transform: uppercase;">
                        </div>
                        <div class="form-group">
                            <label class="form-label">VIN</label>
                            <input type="text" class="form-input" name="vin" id="editVin">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Fuel Type</label>
                            <select class="form-select" name="fuel_type" id="editFuelType">
                                <option value="Petrol">Petrol</option>
                                <option value="Diesel">Diesel</option>
                                <option value="Electric">Electric</option>
                                <option value="Hybrid">Hybrid</option>
                                <option value="CNG">CNG</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Transmission</label>
                            <select class="form-select" name="transmission" id="editTransmission">
                                <option value="Manual">Manual</option>
                                <option value="Automatic">Automatic</option>
                                <option value="CVT">CVT</option>
                                <option value="DCT">DCT</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('editVehicleModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Vehicle</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div class="modal" id="confirmModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="confirmTitle">Confirm Action</h3>
                <button class="modal-close" onclick="closeModal('confirmModal')">&times;</button>
            </div>
            <div class="modal-body" id="confirmMessage">
                Are you sure you want to proceed?
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('confirmModal')">Cancel</button>
                <button class="btn btn-danger" id="confirmActionBtn">Confirm</button>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div class="toast" id="toast">
        <span class="toast-icon" id="toastIcon">✅</span>
        <span class="toast-message" id="toastMessage"></span>
        <div class="toast-progress"></div>
    </div>

    <script src="https://kit.fontawesome.com/your-kit-id.js" crossorigin="anonymous"></script>
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

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            if (event.target.classList.contains('modal')) {
                closeModal(event.target.id);
            }
        });

        // Add Vehicle
        function openAddVehicleModal() {
            document.getElementById('addVehicleForm').reset();
            openModal('addVehicleModal');
        }

        function submitAddVehicle(event) {
            event.preventDefault();
            const formData = new FormData(event.target);
            formData.append('action', 'add_vehicle');
            
            const submitBtn = event.target.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
            submitBtn.disabled = true;
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if(data.success) {
                    showToast('✅', data.message);
                    closeModal('addVehicleModal');
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showToast('❌', data.message, 'error');
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }
            })
            .catch(error => {
                showToast('❌', 'An error occurred', 'error');
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        }

        // Edit Vehicle
        function editVehicle(vehicleId) {
            const formData = new FormData();
            formData.append('action', 'get_vehicle');
            formData.append('vehicle_id', vehicleId);
            formData.append('csrf_token', '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if(data.success && data.data) {
                    const vehicle = data.data;
                    document.getElementById('editVehicleId').value = vehicle.id;
                    document.getElementById('editBrand').value = vehicle.brand;
                    document.getElementById('editModel').value = vehicle.model;
                    document.getElementById('editYear').value = vehicle.year;
                    document.getElementById('editColor').value = vehicle.color || '';
                    document.getElementById('editLicensePlate').value = vehicle.license_plate;
                    document.getElementById('editVin').value = vehicle.vin || '';
                    document.getElementById('editFuelType').value = vehicle.fuel_type || 'Petrol';
                    document.getElementById('editTransmission').value = vehicle.transmission || 'Manual';
                    
                    openModal('editVehicleModal');
                } else {
                    showToast('❌', data.message || 'Failed to load vehicle details', 'error');
                }
            });
        }

        function submitEditVehicle(event) {
            event.preventDefault();
            const formData = new FormData(event.target);
            formData.append('action', 'update_vehicle');
            
            const submitBtn = event.target.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
            submitBtn.disabled = true;
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if(data.success) {
                    showToast('✅', data.message);
                    closeModal('editVehicleModal');
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showToast('❌', data.message, 'error');
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }
            })
            .catch(error => {
                showToast('❌', 'An error occurred', 'error');
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        }

        // Delete Vehicle
        function deleteVehicle(vehicleId) {
            document.getElementById('confirmTitle').textContent = 'Delete Vehicle';
            document.getElementById('confirmMessage').textContent = 'Are you sure you want to delete this vehicle? This action cannot be undone if there are no service records.';
            document.getElementById('confirmActionBtn').onclick = function() {
                performDelete(vehicleId);
            };
            openModal('confirmModal');
        }

        function performDelete(vehicleId) {
            const formData = new FormData();
            formData.append('action', 'delete_vehicle');
            formData.append('vehicle_id', vehicleId);
            formData.append('csrf_token', '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>');
            
            const confirmBtn = document.getElementById('confirmActionBtn');
            const originalText = confirmBtn.innerHTML;
            confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
            confirmBtn.disabled = true;
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if(data.success) {
                    showToast('✅', data.message);
                    closeModal('confirmModal');
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showToast('❌', data.message, 'error');
                    confirmBtn.innerHTML = originalText;
                    confirmBtn.disabled = false;
                    closeModal('confirmModal');
                }
            })
            .catch(error => {
                showToast('❌', 'An error occurred', 'error');
                confirmBtn.innerHTML = originalText;
                confirmBtn.disabled = false;
                closeModal('confirmModal');
            });
        }

        // View Vehicle History
        function viewVehicleHistory(vehicleId) {
            window.location.href = `service_history.php?vehicle_id=${vehicleId}`;
        }

        // Toast notification
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

        // Form validation
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const inputs = this.querySelectorAll('input[required]');
                let valid = true;
                
                inputs.forEach(input => {
                    if(!input.value.trim()) {
                        input.classList.add('error');
                        valid = false;
                        
                        // Remove error class on input
                        input.addEventListener('input', function() {
                            this.classList.remove('error');
                        }, { once: true });
                    }
                });
                
                if(!valid) {
                    e.preventDefault();
                    showToast('❌', 'Please fill in all required fields', 'error');
                }
            });
        });

        // Auto uppercase for license plate
        document.querySelectorAll('input[name="license_plate"]').forEach(input => {
            input.addEventListener('input', function() {
                this.value = this.value.toUpperCase();
            });
        });
    </script>
</body>
</html>