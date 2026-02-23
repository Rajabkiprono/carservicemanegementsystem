<?php
session_start();
if(!isset($_SESSION['user_id'])){
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once "database.php";
$db = (new Database())->connect();

try {
    $stmt = $db->prepare("
        SELECT s.*, v.brand, v.model, v.year, v.license_plate
        FROM book_service s
        JOIN vehicles v ON s.car_id = v.id
        WHERE s.id = :id AND s.user_id = :uid
    ");
    $stmt->execute([
        ":id" => $_GET['id'],
        ":uid" => $_SESSION['user_id']
    ]);
    
    $service = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if($service) {
        echo json_encode($service);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Service not found']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}