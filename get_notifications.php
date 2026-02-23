<?php
session_start();
if(!isset($_SESSION['user_id'])){
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

require_once "database.php";
$db = (new Database())->connect();

try {
    // Get unread count
    $countStmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
    $countStmt->execute([$_SESSION['user_id']]);
    $unreadCount = $countStmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Get recent notifications
    $notifStmt = $db->prepare("
        SELECT id, type, title, message, data, is_read, created_at 
        FROM notifications 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $notifStmt->execute([$_SESSION['user_id']]);
    $notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'unread_count' => (int)$unreadCount,
        'notifications' => $notifications
    ]);

} catch (PDOException $e) {
    error_log("Error fetching notifications: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>