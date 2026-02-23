<?php
session_start();
if(!isset($_SESSION['user_id'])){
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once "database.php";
$database = new Database();
$db = $database->connect();

try {
    $stmt = $db->prepare("SELECT id, part_name, price, stock, description FROM spare_parts WHERE id = :id");
    $stmt->execute([":id" => $_GET['id']]);
    $part = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if($part) {
        echo json_encode($part);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Part not found']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}