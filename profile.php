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

// Handle profile update
if($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid security token");
        }

        if(isset($_POST['update_profile'])) {
            // Validate inputs
            if(empty($_POST['name']) || empty($_POST['email'])) {
                throw new Exception("Name and email are required");
            }

            if(!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email format");
            }

            // Check if email already exists for another user
            $checkStmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $checkStmt->execute([$_POST['email'], $_SESSION['user_id']]);
            if($checkStmt->fetch()) {
                throw new Exception("Email already in use by another account");
            }

            // Handle profile picture upload
            $profilePicture = null;
            if(isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = 'uploads/profiles/';
                
                // Create directory if it doesn't exist
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                
                $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif'];
                $maxSize = 2 * 1024 * 1024; // 2MB
                
                $fileType = $_FILES['profile_picture']['type'];
                $fileSize = $_FILES['profile_picture']['size'];
                
                if(in_array($fileType, $allowedTypes) && $fileSize <= $maxSize) {
                    $extension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
                    $filename = 'profile_' . $_SESSION['user_id'] . '_' . time() . '.' . $extension;
                    $filepath = $uploadDir . $filename;
                    
                    if(move_uploaded_file($_FILES['profile_picture']['tmp_name'], $filepath)) {
                        $profilePicture = $filepath;
                        
                        // Delete old profile picture if exists
                        if(!empty($user['profile_picture']) && file_exists($user['profile_picture'])) {
                            unlink($user['profile_picture']);
                        }
                    }
                } else {
                    throw new Exception("Invalid image format or size too large (max 2MB)");
                }
            }

            // Update profile
            if($profilePicture) {
                $stmt = $db->prepare("UPDATE users SET name = ?, email = ?, phone = ?, address = ?, location = ?, profile_picture = ?, updated_at = NOW() WHERE id = ?");
                $result = $stmt->execute([
                    $_POST['name'],
                    $_POST['email'],
                    $_POST['phone'] ?? null,
                    $_POST['address'] ?? null,
                    $_POST['location'] ?? null,
                    $profilePicture,
                    $_SESSION['user_id']
                ]);
            } else {
                $stmt = $db->prepare("UPDATE users SET name = ?, email = ?, phone = ?, address = ?, location = ?, updated_at = NOW() WHERE id = ?");
                $result = $stmt->execute([
                    $_POST['name'],
                    $_POST['email'],
                    $_POST['phone'] ?? null,
                    $_POST['address'] ?? null,
                    $_POST['location'] ?? null,
                    $_SESSION['user_id']
                ]);
            }

            if($result) {
                $_SESSION['user_name'] = $_POST['name'];
                
                // Create notification for profile update
                $notifStmt = $db->prepare("
                    INSERT INTO notifications (user_id, type, title, message, data, created_at)
                    VALUES (?, 'info', 'Profile Updated', 'Your profile information has been updated successfully', ?, NOW())
                ");
                $data = json_encode(['action' => 'profile_update']);
                $notifStmt->execute([$_SESSION['user_id'], $data]);
                
                $success = "Profile updated successfully";
            } else {
                throw new Exception("Failed to update profile");
            }
        }

        if(isset($_POST['change_password'])) {
            // Validate password
            if(empty($_POST['current_password']) || empty($_POST['new_password']) || empty($_POST['confirm_password'])) {
                throw new Exception("All password fields are required");
            }

            if(strlen($_POST['new_password']) < 8) {
                throw new Exception("Password must be at least 8 characters long");
            }

            if($_POST['new_password'] !== $_POST['confirm_password']) {
                throw new Exception("New passwords do not match");
            }

            // Verify current password
            $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if(!password_verify($_POST['current_password'], $user['password'])) {
                throw new Exception("Current password is incorrect");
            }

            // Update password
            $hashedPassword = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
            $result = $stmt->execute([$hashedPassword, $_SESSION['user_id']]);

            if($result) {
                // Create notification for password change
                $notifStmt = $db->prepare("
                    INSERT INTO notifications (user_id, type, title, message, data, created_at)
                    VALUES (?, 'success', 'Password Changed', 'Your password has been changed successfully', ?, NOW())
                ");
                $data = json_encode(['action' => 'password_change']);
                $notifStmt->execute([$_SESSION['user_id'], $data]);
                
                $success = "Password changed successfully";
            } else {
                throw new Exception("Failed to change password");
            }
        }

        if(isset($_POST['delete_account'])) {
            // Check if user has any active bookings
            $checkStmt = $db->prepare("SELECT COUNT(*) as count FROM book_service WHERE user_id = ? AND status = 'Pending'");
            $checkStmt->execute([$_SESSION['user_id']]);
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if($result['count'] > 0) {
                throw new Exception("Cannot delete account with pending service appointments");
            }

            // Start transaction
            $db->beginTransaction();

            // Delete profile picture if exists
            $userStmt = $db->prepare("SELECT profile_picture FROM users WHERE id = ?");
            $userStmt->execute([$_SESSION['user_id']]);
            $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if(!empty($userData['profile_picture']) && file_exists($userData['profile_picture'])) {
                unlink($userData['profile_picture']);
            }

            // Delete user's data
            $stmt1 = $db->prepare("DELETE FROM notifications WHERE user_id = ?");
            $stmt1->execute([$_SESSION['user_id']]);

            $stmt2 = $db->prepare("DELETE FROM book_service WHERE user_id = ?");
            $stmt2->execute([$_SESSION['user_id']]);

            $stmt3 = $db->prepare("DELETE FROM vehicles WHERE user_id = ?");
            $stmt3->execute([$_SESSION['user_id']]);

            $stmt4 = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmt4->execute([$_SESSION['user_id']]);

            $db->commit();

            // Logout user
            session_destroy();
            header("Location: index.php?message=account_deleted");
            exit();
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
        error_log("Profile error: " . $e->getMessage());
    }
}

// Get user details
try {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if(!$user) {
        throw new Exception("User not found");
    }
} catch (PDOException $e) {
    error_log("Error fetching user: " . $e->getMessage());
    header("Location: logout.php");
    exit();
}

// Get user statistics
try {
    // Vehicle count
    $vehicleStmt = $db->prepare("SELECT COUNT(*) as count FROM vehicles WHERE user_id = ?");
    $vehicleStmt->execute([$_SESSION['user_id']]);
    $vehicleCount = $vehicleStmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Service statistics
    $serviceStmt = $db->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) as cancelled
        FROM book_service 
        WHERE user_id = ?
    ");
    $serviceStmt->execute([$_SESSION['user_id']]);
    $serviceStats = $serviceStmt->fetch(PDO::FETCH_ASSOC);

    // Emergency statistics
    $emergencyStmt = $db->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as active
        FROM emergency_services 
        WHERE user_id = ?
    ");
    $emergencyStmt->execute([$_SESSION['user_id']]);
    $emergencyStats = $emergencyStmt->fetch(PDO::FETCH_ASSOC);

    // Member since
    $memberSince = new DateTime($user['created_at']);
    $now = new DateTime();
    $membershipDays = $memberSince->diff($now)->days;

    // Last login (you might want to track this separately)
    $lastLogin = $user['updated_at'] ?? $user['created_at'];

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

} catch (PDOException $e) {
    $vehicleCount = 0;
    $serviceStats = ['total' => 0, 'completed' => 0, 'pending' => 0, 'in_progress' => 0, 'cancelled' => 0];
    $emergencyStats = ['total' => 0, 'active' => 0];
    $membershipDays = 0;
    $lastLogin = date('Y-m-d H:i:s');
    $unreadNotifications = 0;
    $recentNotifications = [];
    error_log("Error fetching stats: " . $e->getMessage());
}

// Get greeting based on time
$currentHour = (int)date('H');
$greeting = $currentHour < 12 ? 'Good Morning' : ($currentHour < 17 ? 'Good Afternoon' : 'Good Evening');

// Default profile picture if none exists
$defaultAvatar = 'https://ui-avatars.com/api/?name=' . urlencode($user['name']) . '&size=200&background=2563eb&color=fff';
$profilePicture = !empty($user['profile_picture']) && file_exists($user['profile_picture']) ? $user['profile_picture'] : $defaultAvatar;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CASMS | My Profile</title>
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

        body.dark .profile-sidebar {
            background: #1e293b;
            border-color: #334155;
        }

        body.dark .profile-card {
            background: #1e293b;
            border-color: #334155;
        }

        body.dark .profile-header {
            border-color: #334155;
        }

        body.dark .stat-box {
            background: #0f172a;
            border-color: #334155;
        }

        body.dark .form-input,
        body.dark .form-textarea {
            background: #0f172a;
            border-color: #334155;
            color: #e2e8f0;
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

        body.dark .activity-item {
            border-color: #334155;
        }

        body.dark .activity-item:hover {
            background: #0f172a;
        }

        body.dark .danger-zone {
            background: #451a1a;
            border-color: #7f1d1d;
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

        /* Profile Layout */
        .profile-grid {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 2rem;
        }

        /* Profile Sidebar */
        .profile-sidebar {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            height: fit-content;
        }

        .profile-cover {
            height: 120px;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            position: relative;
        }

        .profile-avatar-wrapper {
            position: absolute;
            bottom: -50px;
            left: 50%;
            transform: translateX(-50%);
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: white;
            padding: 4px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            cursor: pointer;
            transition: all 0.3s;
        }

        .profile-avatar-wrapper:hover {
            transform: translateX(-50%) scale(1.05);
        }

        .profile-avatar {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid white;
        }

        .profile-avatar-upload {
            position: absolute;
            bottom: 0;
            right: 0;
            width: 36px;
            height: 36px;
            background: #2563eb;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1rem;
            cursor: pointer;
            border: 2px solid white;
            transition: all 0.3s;
        }

        .profile-avatar-upload:hover {
            background: #1d4ed8;
            transform: scale(1.1);
        }

        .profile-info {
            padding: 70px 2rem 2rem;
            text-align: center;
        }

        .profile-name {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .profile-email {
            color: #64748b;
            margin-bottom: 1.5rem;
            font-size: 0.95rem;
        }

        .profile-badges {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
            margin-bottom: 1.5rem;
        }

        .profile-badge {
            padding: 0.25rem 1rem;
            background: #dbeafe;
            color: #1e40af;
            border-radius: 40px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .profile-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 1rem;
            transition: all 0.3s;
        }

        .stat-box:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #2563eb;
        }

        .stat-label {
            font-size: 0.75rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .profile-meta {
            text-align: left;
            border-top: 1px solid #e2e8f0;
            padding-top: 1.5rem;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .meta-item:last-child {
            border-bottom: none;
        }

        .meta-icon {
            width: 36px;
            height: 36px;
            background: #dbeafe;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #2563eb;
        }

        .meta-content {
            flex: 1;
        }

        .meta-label {
            font-size: 0.75rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .meta-value {
            font-weight: 600;
        }

        /* Profile Content */
        .profile-content {
            display: flex;
            flex-direction: column;
            gap: 2rem;
        }

        .profile-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            transition: all 0.3s;
        }

        .profile-card:hover {
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }

        .profile-header {
            padding: 1.5rem 2rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .header-icon {
            width: 48px;
            height: 48px;
            background: #dbeafe;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: #2563eb;
        }

        .header-title {
            font-size: 1.25rem;
            font-weight: 600;
        }

        .header-subtitle {
            color: #64748b;
            font-size: 0.875rem;
        }

        .profile-body {
            padding: 2rem;
        }

        /* Form Styles */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group.full-width {
            grid-column: span 2;
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
        .form-textarea:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .form-input.error {
            border-color: #ef4444;
        }

        .form-textarea {
            min-height: 100px;
            resize: vertical;
        }

        .password-input-wrapper {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #64748b;
        }

        .password-strength {
            margin-top: 0.5rem;
            height: 4px;
            background: #e2e8f0;
            border-radius: 2px;
            overflow: hidden;
        }

        .strength-bar {
            height: 100%;
            width: 0;
            transition: all 0.3s;
        }

        .strength-bar.weak {
            width: 33.33%;
            background: #ef4444;
        }

        .strength-bar.medium {
            width: 66.66%;
            background: #f59e0b;
        }

        .strength-bar.strong {
            width: 100%;
            background: #10b981;
        }

        .strength-text {
            font-size: 0.75rem;
            margin-top: 0.25rem;
            color: #64748b;
        }

        /* Activity List */
        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .activity-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            transition: all 0.2s;
        }

        .activity-item:hover {
            background: #f8fafc;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            background: #dbeafe;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #2563eb;
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .activity-time {
            font-size: 0.75rem;
            color: #64748b;
        }

        .activity-status {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-completed {
            background: #d1fae5;
            color: #065f46;
        }

        .status-in-progress {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-cancelled {
            background: #fee2e2;
            color: #b91c1c;
        }

        /* Danger Zone */
        .danger-zone {
            background: #fef2f2;
            border: 1px solid #fee2e2;
            border-radius: 16px;
            padding: 1.5rem;
        }

        .danger-title {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #b91c1c;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .danger-description {
            color: #7f1d1d;
            font-size: 0.875rem;
            margin-bottom: 1.5rem;
        }

        .btn-danger {
            background: #ef4444;
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 40px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-danger:hover {
            background: #dc2626;
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(239, 68, 68, 0.3);
        }

        /* Buttons */
        .btn-primary {
            background: #2563eb;
            color: white;
            border: none;
            padding: 0.75rem 2rem;
            border-radius: 40px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            font-family: 'Inter', sans-serif;
        }

        .btn-primary:hover {
            background: #1d4ed8;
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.3);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid #e2e8f0;
            padding: 0.75rem 2rem;
            border-radius: 40px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            color: #475569;
        }

        .btn-outline:hover {
            border-color: #2563eb;
            color: #2563eb;
        }

        .btn-outline-danger {
            background: transparent;
            border: 2px solid #fee2e2;
            padding: 0.75rem 2rem;
            border-radius: 40px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            color: #b91c1c;
        }

        .btn-outline-danger:hover {
            background: #fef2f2;
            border-color: #b91c1c;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
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

        /* Responsive */
        @media (max-width: 1024px) {
            .profile-grid {
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
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .form-group.full-width {
                grid-column: span 1;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn-primary,
            .btn-outline {
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
            
            .profile-avatar-wrapper {
                width: 100px;
                height: 100px;
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
                <a href="profile.php" class="active">Profile</a>
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
                                <span class="greeting-badge">MY PROFILE</span>
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
                                    <span class="chip-icon">👤</span>
                                    <span class="chip-text">Profile</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="status-message">
                            <div class="status-icon">
                                <span class="pulse-dot"></span>
                                <span>Account Status</span>
                            </div>
                            <div class="message-text">
                                <span class="highlight">Manage your personal information</span> and account settings
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

                        <!-- User Profile Dropdown -->
                        <div class="user-profile-container">
                            <div class="user-profile" onclick="toggleDropdown()" id="userProfile">
                                <div class="avatar">
                                    <?php if(!empty($user['profile_picture']) && file_exists($user['profile_picture'])): ?>
                                        <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="Profile" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                                    <?php else: ?>
                                        <span><?php echo strtoupper(substr(htmlspecialchars($_SESSION['user_name'] ?? 'U'), 0, 1)); ?></span>
                                    <?php endif; ?>
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

            <!-- Messages -->
            <?php if(!empty($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if(!empty($success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <!-- Profile Grid -->
            <div class="profile-grid">
                <!-- Profile Sidebar -->
                <div class="profile-sidebar">
                    <div class="profile-cover">
                        <div class="profile-avatar-wrapper" onclick="document.getElementById('profilePictureInput').click()">
                            <img src="<?php echo htmlspecialchars($profilePicture); ?>" alt="Profile" class="profile-avatar" id="profileAvatar">
                            <div class="profile-avatar-upload">
                                <i class="fas fa-camera"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="profile-info">
                        <h2 class="profile-name"><?php echo htmlspecialchars($user['name']); ?></h2>
                        <div class="profile-email"><?php echo htmlspecialchars($user['email']); ?></div>
                        
                        <div class="profile-badges">
                            <span class="profile-badge"><?php echo ucfirst($user['role'] ?? 'User'); ?></span>
                            <?php if($membershipDays > 365): ?>
                                <span class="profile-badge">⭐ Veteran</span>
                            <?php elseif($membershipDays > 30): ?>
                                <span class="profile-badge">🌟 Regular</span>
                            <?php else: ?>
                                <span class="profile-badge">🆕 New</span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="profile-stats">
                            <div class="stat-box">
                                <div class="stat-value"><?php echo $vehicleCount; ?></div>
                                <div class="stat-label">Vehicles</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-value"><?php echo $serviceStats['total']; ?></div>
                                <div class="stat-label">Services</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-value"><?php echo $serviceStats['completed']; ?></div>
                                <div class="stat-label">Completed</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-value"><?php echo $serviceStats['pending']; ?></div>
                                <div class="stat-label">Pending</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-value"><?php echo $emergencyStats['total']; ?></div>
                                <div class="stat-label">Emergencies</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-value"><?php echo $emergencyStats['active']; ?></div>
                                <div class="stat-label">Active</div>
                            </div>
                        </div>

                        <div class="profile-meta">
                            <div class="meta-item">
                                <div class="meta-icon">
                                    <i class="fas fa-calendar-check"></i>
                                </div>
                                <div class="meta-content">
                                    <div class="meta-label">Member Since</div>
                                    <div class="meta-value"><?php echo date('F j, Y', strtotime($user['created_at'])); ?></div>
                                </div>
                            </div>
                            <div class="meta-item">
                                <div class="meta-icon">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <div class="meta-content">
                                    <div class="meta-label">Membership Days</div>
                                    <div class="meta-value"><?php echo $membershipDays; ?> days</div>
                                </div>
                            </div>
                            <div class="meta-item">
                                <div class="meta-icon">
                                    <i class="fas fa-history"></i>
                                </div>
                                <div class="meta-content">
                                    <div class="meta-label">Last Activity</div>
                                    <div class="meta-value"><?php echo date('M j, Y', strtotime($lastLogin)); ?></div>
                                </div>
                            </div>
                            <div class="meta-item">
                                <div class="meta-icon">
                                    <i class="fas fa-shield-alt"></i>
                                </div>
                                <div class="meta-content">
                                    <div class="meta-label">Account Status</div>
                                    <div class="meta-value">
                                        <span style="color: #10b981;">● Active</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Profile Content -->
                <div class="profile-content">
                    <!-- Edit Profile Form -->
                    <div class="profile-card">
                        <div class="profile-header">
                            <div class="header-icon">
                                <i class="fas fa-user-edit"></i>
                            </div>
                            <div>
                                <h3 class="header-title">Edit Profile</h3>
                                <p class="header-subtitle">Update your personal information</p>
                            </div>
                        </div>
                        
                        <div class="profile-body">
                            <form method="POST" id="profileForm" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="file" name="profile_picture" id="profilePictureInput" accept="image/*" style="display: none;" onchange="previewProfilePicture(this)">
                                
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Full Name <span class="required">*</span></label>
                                        <input type="text" name="name" class="form-input" value="<?php echo htmlspecialchars($user['name']); ?>" required>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Email Address <span class="required">*</span></label>
                                        <input type="email" name="email" class="form-input" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Phone Number</label>
                                        <input type="tel" name="phone" class="form-input" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="+1 234 567 8900">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Location</label>
                                        <input type="text" name="location" class="form-input" value="<?php echo htmlspecialchars($user['location'] ?? ''); ?>" placeholder="City, Country">
                                    </div>
                                    
                                    <div class="form-group full-width">
                                        <label class="form-label">Address</label>
                                        <textarea name="address" class="form-textarea" placeholder="Your full address"><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="reset" class="btn-outline">Reset</button>
                                    <button type="submit" name="update_profile" class="btn-primary">Save Changes</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Change Password -->
                    <div class="profile-card">
                        <div class="profile-header">
                            <div class="header-icon">
                                <i class="fas fa-lock"></i>
                            </div>
                            <div>
                                <h3 class="header-title">Change Password</h3>
                                <p class="header-subtitle">Update your password regularly for security</p>
                            </div>
                        </div>
                        
                        <div class="profile-body">
                            <form method="POST" id="passwordForm">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                
                                <div class="form-grid">
                                    <div class="form-group full-width">
                                        <label class="form-label">Current Password <span class="required">*</span></label>
                                        <div class="password-input-wrapper">
                                            <input type="password" name="current_password" class="form-input" id="currentPassword" required>
                                            <span class="password-toggle" onclick="togglePassword('currentPassword')">
                                                <i class="far fa-eye"></i>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">New Password <span class="required">*</span></label>
                                        <div class="password-input-wrapper">
                                            <input type="password" name="new_password" class="form-input" id="newPassword" onkeyup="checkPasswordStrength()" required>
                                            <span class="password-toggle" onclick="togglePassword('newPassword')">
                                                <i class="far fa-eye"></i>
                                            </span>
                                        </div>
                                        <div class="password-strength">
                                            <div class="strength-bar" id="strengthBar"></div>
                                        </div>
                                        <div class="strength-text" id="strengthText"></div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Confirm Password <span class="required">*</span></label>
                                        <div class="password-input-wrapper">
                                            <input type="password" name="confirm_password" class="form-input" id="confirmPassword" required>
                                            <span class="password-toggle" onclick="togglePassword('confirmPassword')">
                                                <i class="far fa-eye"></i>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="reset" class="btn-outline">Clear</button>
                                    <button type="submit" name="change_password" class="btn-primary">Update Password</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <div class="profile-card">
                        <div class="profile-header">
                            <div class="header-icon">
                                <i class="fas fa-history"></i>
                            </div>
                            <div>
                                <h3 class="header-title">Recent Activity</h3>
                                <p class="header-subtitle">Your latest service appointments</p>
                            </div>
                        </div>
                        
                        <div class="profile-body">
                            <?php
                            // Fetch recent bookings
                            $activityStmt = $db->prepare("
                                SELECT bs.*, v.brand, v.model, v.license_plate
                                FROM book_service bs
                                JOIN vehicles v ON bs.vehicle_id = v.id
                                WHERE bs.user_id = ?
                                ORDER BY bs.created_at DESC
                                LIMIT 5
                            ");
                            $activityStmt->execute([$_SESSION['user_id']]);
                            $activities = $activityStmt->fetchAll(PDO::FETCH_ASSOC);
                            ?>
                            
                            <?php if(empty($activities)): ?>
                                <div style="text-align: center; padding: 2rem; color: #64748b;">
                                    <i class="fas fa-calendar-times" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                    <p>No recent activity found</p>
                                    <a href="book_service.php" class="btn-primary" style="display: inline-block; margin-top: 1rem; text-decoration: none;">Book a Service</a>
                                </div>
                            <?php else: ?>
                                <div class="activity-list">
                                    <?php foreach($activities as $activity): 
                                        $statusClass = 'status-' . strtolower(str_replace(' ', '-', $activity['status']));
                                    ?>
                                        <div class="activity-item">
                                            <div class="activity-icon">
                                                <i class="fas fa-tools"></i>
                                            </div>
                                            <div class="activity-content">
                                                <div class="activity-title">
                                                    <?php echo htmlspecialchars($activity['brand'] . ' ' . $activity['model']); ?> - <?php echo htmlspecialchars($activity['service_type']); ?>
                                                </div>
                                                <div class="activity-time">
                                                    <i class="far fa-clock"></i> 
                                                    <?php echo date('M j, Y', strtotime($activity['created_at'])); ?> at 
                                                    <?php echo date('g:i A', strtotime($activity['created_at'])); ?>
                                                </div>
                                            </div>
                                            <span class="activity-status <?php echo $statusClass; ?>">
                                                <?php echo $activity['status']; ?>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <div style="text-align: center; margin-top: 1.5rem;">
                                    <a href="service_history.php" class="btn-outline">View All Activity</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Danger Zone -->
                    <div class="profile-card">
                        <div class="profile-header" style="background: #fef2f2;">
                            <div class="header-icon" style="background: #fee2e2; color: #b91c1c;">
                                <i class="fas fa-exclamation-triangle"></i>
                            </div>
                            <div>
                                <h3 class="header-title" style="color: #b91c1c;">Danger Zone</h3>
                                <p class="header-subtitle">Irreversible account actions</p>
                            </div>
                        </div>
                        
                        <div class="profile-body">
                            <div class="danger-zone">
                                <div class="danger-title">
                                    <i class="fas fa-trash-alt"></i>
                                    Delete Account
                                </div>
                                <p class="danger-description">
                                    Once you delete your account, there is no going back. Please be certain.
                                    This will permanently delete all your vehicles, service history, and emergency requests.
                                </p>
                                <button class="btn-danger" onclick="openDeleteModal()">
                                    <i class="fas fa-trash"></i> Delete My Account
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Account Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Delete Account</h3>
                <button class="modal-close" onclick="closeModal('deleteModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p style="margin-bottom: 1rem;">Are you absolutely sure you want to delete your account?</p>
                <p style="color: #b91c1c; font-size: 0.875rem;">
                    <i class="fas fa-exclamation-circle"></i>
                    This action cannot be undone. All your vehicles, service history, and emergency requests will be permanently deleted.
                </p>
            </div>
            <div class="modal-footer">
                <button class="btn-outline" onclick="closeModal('deleteModal')">Cancel</button>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <button type="submit" name="delete_account" class="btn-danger">Yes, Delete My Account</button>
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

        // Profile picture preview
        function previewProfilePicture(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('profileAvatar').src = e.target.result;
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

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

        // Modal
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

        function openDeleteModal() {
            openModal('deleteModal');
        }

        // Password visibility toggle
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const icon = event.currentTarget.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Password strength checker
        function checkPasswordStrength() {
            const password = document.getElementById('newPassword').value;
            const strengthBar = document.getElementById('strengthBar');
            const strengthText = document.getElementById('strengthText');
            
            let strength = 0;
            
            if (password.length >= 8) strength++;
            if (password.match(/[a-z]+/)) strength++;
            if (password.match(/[A-Z]+/)) strength++;
            if (password.match(/[0-9]+/)) strength++;
            if (password.match(/[$@#&!]+/)) strength++;
            
            strengthBar.className = 'strength-bar';
            
            if (password.length === 0) {
                strengthBar.style.width = '0';
                strengthText.textContent = '';
            } else if (strength <= 2) {
                strengthBar.classList.add('weak');
                strengthText.textContent = 'Weak password';
                strengthText.style.color = '#ef4444';
            } else if (strength <= 4) {
                strengthBar.classList.add('medium');
                strengthText.textContent = 'Medium password';
                strengthText.style.color = '#f59e0b';
            } else {
                strengthBar.classList.add('strong');
                strengthText.textContent = 'Strong password';
                strengthText.style.color = '#10b981';
            }
        }

        // Form validation
        document.getElementById('passwordForm')?.addEventListener('submit', function(e) {
            const newPass = document.getElementById('newPassword').value;
            const confirmPass = document.getElementById('confirmPassword').value;
            
            if (newPass !== confirmPass) {
                e.preventDefault();
                showToast('❌', 'New passwords do not match', 'error');
            }
            
            if (newPass.length < 8) {
                e.preventDefault();
                showToast('❌', 'Password must be at least 8 characters', 'error');
            }
        });

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