<?php
require_once 'database.php';

// Function to hash password
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

// Clear existing users
mysqli_query($conn, "DELETE FROM finance_users");

// Insert admin with proper hash
$admin_password = hashPassword('Admin@123');
$query1 = "INSERT INTO finance_users (username, password, full_name, role) VALUES 
          ('admin', '$admin_password', 'System Administrator', 'admin')";
mysqli_query($conn, $query1);

// Insert finance officer with proper hash
$finance_password = hashPassword('Finance@123');
$query2 = "INSERT INTO finance_users (username, password, full_name, role) VALUES 
          ('finance', '$finance_password', 'Finance Officer', 'finance_officer')";
mysqli_query($conn, $query2);

echo "Users created successfully!\n";
echo "Admin password: Admin@123\n";
echo "Finance password: Finance@123\n";

// Verify the passwords
$result = mysqli_query($conn, "SELECT * FROM finance_users");
while($row = mysqli_fetch_assoc($result)) {
    echo "\nUser: " . $row['username'] . "\n";
    echo "Hash: " . $row['password'] . "\n";
    echo "Admin@123 verification: " . (password_verify('Admin@123', $row['password']) ? 'OK' : 'FAILED') . "\n";
    echo "Finance@123 verification: " . (password_verify('Finance@123', $row['password']) ? 'OK' : 'FAILED') . "\n";
}
?>