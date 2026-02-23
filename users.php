<?php
session_start();
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin'){
    header("Location: ../auth/login.php");
    exit();
}
require_once "../config/database.php";
$db = (new Database())->connect();
$stmt = $db->query("SELECT c.id, c.brand, c.model, c.year, c.price, u.name AS owner FROM cars c JOIN users u ON c.user_id = u.id");
$cars = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin - Cars</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.1/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
    <h2>All Cars</h2>
    <table class="table table-striped">
        <thead class="table-dark">
            <tr><th>ID</th><th>Brand</th><th>Model</th><th>Year</th><th>Price</th><th>Owner</th></tr>
        </thead>
        <tbody>
            <?php foreach($cars as $c): ?>
                <tr>
                    <td><?php echo $c['id'];?></td>
                    <td><?php echo $c['brand'];?></td>
                    <td><?php echo $c['model'];?></td>
                    <td><?php echo $c['year'];?></td>
                    <td><?php echo number_format($c['price'],2);?></td>
                    <td><?php echo $c['owner'];?></td>
                </tr>
            <?php endforeach;?>
        </tbody>
    </table>
    <a href="../dashboard.php" class="btn btn-secondary mt-3">Back to Dashboard</a>
</div>
</body>
</html>