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
    $stmt = $db->prepare("SELECT * FROM vehicles WHERE id = :id AND user_id = :user_id");
    $stmt->execute([
        ":id" => $_GET['id'],
        ":user_id" => $_SESSION['user_id']
    ]);
    $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if($vehicle) {
        echo json_encode($vehicle);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Vehicle not found']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}