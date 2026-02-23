<?php
session_start();
if(!isset($_SESSION['user_id'])){
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once "config/database.php";
$db = (new Database())->connect();

try {
    // Check if any services were updated in the last 5 minutes
    $stmt = $db->prepare("
        SELECT COUNT(*) as update_count
        FROM book_service
        WHERE user_id = :uid
        AND updated_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    ");
    $stmt->execute([":uid" => $_SESSION['user_id']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode(['updated' => $result['update_count'] > 0]);
} catch (PDOException $e) {
    echo json_encode(['updated' => false]);
}