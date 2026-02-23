<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['finance_user_id'])) {
    header('Location: finance-login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'add_transaction') {
        $user_id = mysqli_real_escape_string($conn, $_POST['user_id']);
        $transaction_type = mysqli_real_escape_string($conn, $_POST['transaction_type']);
        $amount = mysqli_real_escape_string($conn, $_POST['amount']);
        $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method']);
        $description = mysqli_real_escape_string($conn, $_POST['description']);
        $status = mysqli_real_escape_string($conn, $_POST['status']);
        
        $query = "INSERT INTO financial_transactions (user_id, transaction_type, amount, payment_method, description, status) 
                  VALUES ('$user_id', '$transaction_type', '$amount', '$payment_method', '$description', '$status')";
        
        if (mysqli_query($conn, $query)) {
            $transaction_id = mysqli_insert_id($conn);
            
            // Create invoice for completed transactions
            if ($status === 'completed') {
                $invoice_number = 'INV-' . date('Y') . '-' . str_pad($transaction_id, 6, '0', STR_PAD_LEFT);
                $issue_date = date('Y-m-d');
                
                $invoice_query = "INSERT INTO invoices (user_id, transaction_id, invoice_number, amount, issue_date, status) 
                                 VALUES ('$user_id', '$transaction_id', '$invoice_number', '$amount', '$issue_date', 'paid')";
                mysqli_query($conn, $invoice_query);
            }
            
            $_SESSION['success'] = 'Transaction added successfully';
        } else {
            $_SESSION['error'] = 'Error adding transaction: ' . mysqli_error($conn);
        }
    }
}

header('Location: finance-dashboard.php');
exit();
?>