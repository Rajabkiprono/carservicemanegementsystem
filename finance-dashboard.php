<?php
session_start();
require_once 'database.php';

// Check if logged in
if (!isset($_SESSION['finance_user_id'])) {
    header('Location: finance-login.php');
    exit();
}

$role = $_SESSION['finance_role'];
$full_name = $_SESSION['finance_full_name'];

// Initialize stats array with default values
$stats = [
    'transactions' => ['total_amount' => 0, 'total_count' => 0],
    'pending' => ['pending_amount' => 0, 'pending_invoices' => 0],
    'today' => ['today_amount' => 0, 'today_count' => 0]
];

// Check if tables exist before querying
$tables_exist = true;

// Check financial_transactions table
$check = mysqli_query($conn, "SHOW TABLES LIKE 'financial_transactions'");
if (mysqli_num_rows($check) == 0) {
    $tables_exist = false;
}

if ($tables_exist) {
    // Total transactions amount
    $query = "SELECT COALESCE(SUM(amount), 0) as total_amount, COUNT(*) as total_count FROM financial_transactions";
    $result = mysqli_query($conn, $query);
    if ($result) {
        $stats['transactions'] = mysqli_fetch_assoc($result);
    }

    // Check if invoices table exists
    $check_invoices = mysqli_query($conn, "SHOW TABLES LIKE 'invoices'");
    if (mysqli_num_rows($check_invoices) > 0) {
        // Pending invoices
        $query = "SELECT COUNT(*) as pending_invoices, COALESCE(SUM(amount), 0) as pending_amount FROM invoices WHERE status = 'unpaid'";
        $result = mysqli_query($conn, $query);
        if ($result) {
            $stats['pending'] = mysqli_fetch_assoc($result);
        }
    }

    // Today's transactions
    $query = "SELECT COUNT(*) as today_count, COALESCE(SUM(amount), 0) as today_amount FROM financial_transactions WHERE DATE(transaction_date) = CURDATE()";
    $result = mysqli_query($conn, $query);
    if ($result) {
        $stats['today'] = mysqli_fetch_assoc($result);
    }

    // Recent transactions
    $recent_transactions = mysqli_query($conn, "SELECT ft.*, u.name as user_name, u.email as user_email 
          FROM financial_transactions ft 
          LEFT JOIN users u ON ft.user_id = u.id 
          ORDER BY ft.transaction_date DESC LIMIT 10");

    // Get all users with financial activities
    $users_query = "SELECT DISTINCT u.id, u.name, u.email, u.phone, 
                    (SELECT COUNT(*) FROM financial_transactions ft WHERE ft.user_id = u.id) as transaction_count,
                    (SELECT COALESCE(SUM(amount), 0) FROM financial_transactions ft WHERE ft.user_id = u.id) as total_spent
                    FROM users u 
                    INNER JOIN financial_transactions ft ON u.id = ft.user_id 
                    ORDER BY total_spent DESC";
    $users = mysqli_query($conn, $users_query);
} else {
    $recent_transactions = false;
    $users = false;
}

// Get all users for dropdown (even those without transactions)
$all_users = mysqli_query($conn, "SELECT id, name, email FROM users ORDER BY name");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finance Dashboard - CASMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .sidebar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            color: white;
            position: fixed;
            width: 16.666%;
        }
        .sidebar h4 {
            margin-bottom: 30px;
            font-weight: 600;
        }
        .sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 12px 20px;
            margin-bottom: 5px;
            border-radius: 10px;
            transition: all 0.3s;
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        .sidebar .nav-link i {
            margin-right: 10px;
        }
        .main-content {
            margin-left: 16.666%;
            padding: 30px;
        }
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            transition: transform 0.3s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .stat-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
        }
        .stat-icon i {
            font-size: 30px;
            color: white;
        }
        .stat-value {
            font-size: 28px;
            font-weight: 600;
            color: #333;
        }
        .stat-label {
            color: #666;
            font-size: 14px;
        }
        .table-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        .badge-status {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        .badge-completed { background: #d4edda; color: #155724; }
        .badge-pending { background: #fff3cd; color: #856404; }
        .badge-failed { background: #f8d7da; color: #721c24; }
        .btn-export {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 500;
            transition: all 0.3s;
        }
        .btn-export:hover {
            color: white;
            opacity: 0.9;
            transform: translateY(-2px);
        }
        .user-info {
            border-left: 3px solid #fff;
            padding-left: 15px;
            margin-bottom: 20px;
        }
        .role-badge {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
            margin-top: 5px;
        }
        .setup-alert {
            background: #fff3cd;
            border: 1px solid #ffeeba;
            color: #856404;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 30px;
        }
        .quick-action-btn {
            background: #f8f9fa;
            border: 1px dashed #667eea;
            color: #667eea;
            padding: 15px;
            border-radius: 12px;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
        }
        .quick-action-btn:hover {
            background: #667eea;
            color: white;
            border: 1px dashed #667eea;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-2 p-0">
                <div class="sidebar">
                    <h4>CASMS Finance</h4>
                    <div class="user-info">
                        <p class="mb-1">Welcome,</p>
                        <p class="mb-0 fw-bold"><?php echo htmlspecialchars($full_name); ?></p>
                        <span class="role-badge"><?php echo ucfirst($role); ?></span>
                    </div>
                    <nav class="nav flex-column">
                        <a class="nav-link active" href="finance-dashboard.php">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                        <a class="nav-link" href="finance-transactions.php">
                            <i class="bi bi-cash-stack"></i> All Transactions
                        </a>
                        <a class="nav-link" href="finance-users.php">
                            <i class="bi bi-people"></i> Users
                        </a>
                        <a class="nav-link" href="finance-invoices.php">
                            <i class="bi bi-file-text"></i> Invoices
                        </a>
                        <a class="nav-link" href="finance-reports.php">
                            <i class="bi bi-graph-up"></i> Reports
                        </a>
                        <hr class="bg-light">
                        <a class="nav-link" href="finance-logout.php">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </a>
                    </nav>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-10 main-content">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>Financial Dashboard</h2>
                    <div>
                        <button class="btn btn-export me-2" onclick="exportData('excel')">
                            <i class="bi bi-file-excel"></i> Export to Excel
                        </button>
                        <button class="btn btn-export" onclick="exportData('pdf')">
                            <i class="bi bi-file-pdf"></i> Export to PDF
                        </button>
                    </div>
                </div>
                
                <?php if (!$tables_exist): ?>
                <div class="setup-alert">
                    <h5><i class="bi bi-exclamation-triangle"></i> Database Setup Required</h5>
                    <p>The financial tables are not set up yet. Please run the SQL setup script first.</p>
                    <button class="btn btn-warning" onclick="runSetup()">Run Setup</button>
                </div>
                <?php endif; ?>
                
                <!-- Statistics Cards -->
                <div class="row">
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-cash"></i>
                            </div>
                            <div class="stat-value">KSh <?php echo number_format($stats['transactions']['total_amount'] ?? 0, 2); ?></div>
                            <div class="stat-label">Total Revenue</div>
                            <small class="text-muted"><?php echo $stats['transactions']['total_count'] ?? 0; ?> transactions</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-clock-history"></i>
                            </div>
                            <div class="stat-value">KSh <?php echo number_format($stats['pending']['pending_amount'] ?? 0, 2); ?></div>
                            <div class="stat-label">Pending Payments</div>
                            <small class="text-muted"><?php echo $stats['pending']['pending_invoices'] ?? 0; ?> invoices</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-calendar-day"></i>
                            </div>
                            <div class="stat-value">KSh <?php echo number_format($stats['today']['today_amount'] ?? 0, 2); ?></div>
                            <div class="stat-label">Today's Transactions</div>
                            <small class="text-muted"><?php echo $stats['today']['today_count'] ?? 0; ?> transactions</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-people"></i>
                            </div>
                            <div class="stat-value"><?php echo $users ? mysqli_num_rows($users) : 0; ?></div>
                            <div class="stat-label">Active Users</div>
                            <small class="text-muted">With transactions</small>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="quick-action-btn" onclick="addFinanceRecord()">
                            <i class="bi bi-plus-circle fs-4"></i>
                            <p class="mb-0 mt-2">Add Transaction</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="quick-action-btn" onclick="generateInvoice()">
                            <i class="bi bi-file-text fs-4"></i>
                            <p class="mb-0 mt-2">Generate Invoice</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="quick-action-btn" onclick="viewReports()">
                            <i class="bi bi-graph-up fs-4"></i>
                            <p class="mb-0 mt-2">View Reports</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="quick-action-btn" onclick="exportData('excel')">
                            <i class="bi bi-download fs-4"></i>
                            <p class="mb-0 mt-2">Download Data</p>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Transactions -->
                <div class="table-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Recent Transactions</h5>
                        <a href="finance-transactions.php" class="text-decoration-none">View All →</a>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>User</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($recent_transactions && mysqli_num_rows($recent_transactions) > 0): ?>
                                    <?php while($transaction = mysqli_fetch_assoc($recent_transactions)): ?>
                                    <tr>
                                        <td>#<?php echo $transaction['id']; ?></td>
                                        <td>
                                            <?php echo htmlspecialchars($transaction['user_name'] ?? 'N/A'); ?><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($transaction['user_email'] ?? ''); ?></small>
                                        </td>
                                        <td><?php echo ucfirst(str_replace('_', ' ', $transaction['transaction_type'])); ?></td>
                                        <td><strong>KSh <?php echo number_format($transaction['amount'], 2); ?></strong></td>
                                        <td><?php echo $transaction['payment_method'] ?? 'N/A'; ?></td>
                                        <td><?php echo date('d M Y H:i', strtotime($transaction['transaction_date'])); ?></td>
                                        <td>
                                            <span class="badge-status badge-<?php echo $transaction['status']; ?>">
                                                <?php echo ucfirst($transaction['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary" onclick="viewTransaction(<?php echo $transaction['id']; ?>)">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <?php if($role == 'admin'): ?>
                                            <button class="btn btn-sm btn-outline-success" onclick="editTransaction(<?php echo $transaction['id']; ?>)">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4">
                                            <i class="bi bi-inbox fs-1 d-block mb-3 text-muted"></i>
                                            <p class="text-muted">No transactions found</p>
                                            <button class="btn btn-primary btn-sm" onclick="addFinanceRecord()">Add First Transaction</button>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Users with Financial Activity -->
                <div class="table-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Users Financial Summary</h5>
                        <button class="btn btn-sm btn-outline-primary" onclick="addFinanceRecord()">
                            <i class="bi bi-plus-circle"></i> Add Finance Record
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Transactions</th>
                                    <th>Total Spent</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($users && mysqli_num_rows($users) > 0): ?>
                                    <?php while($user = mysqli_fetch_assoc($users)): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($user['name']); ?></td>
                                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td><?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?></td>
                                        <td><?php echo $user['transaction_count']; ?></td>
                                        <td><strong>KSh <?php echo number_format($user['total_spent'] ?? 0, 2); ?></strong></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-info" onclick="viewUserTransactions(<?php echo $user['id']; ?>)">
                                                <i class="bi bi-list-ul"></i> View
                                            </button>
                                            <button class="btn btn-sm btn-outline-success" onclick="addUserTransaction(<?php echo $user['id']; ?>)">
                                                <i class="bi bi-plus"></i> Add
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4">
                                            <i class="bi bi-people fs-1 d-block mb-3 text-muted"></i>
                                            <p class="text-muted">No user financial activities yet</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Transaction Modal -->
    <div class="modal fade" id="addTransactionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Financial Record</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="process-finance.php" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_transaction">
                        <div class="mb-3">
                            <label class="form-label">User</label>
                            <select class="form-control" name="user_id" required>
                                <option value="">Select User</option>
                                <?php 
                                if ($all_users) {
                                    mysqli_data_seek($all_users, 0);
                                    while($u = mysqli_fetch_assoc($all_users)): 
                                ?>
                                <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['name']); ?> (<?php echo htmlspecialchars($u['email']); ?>)</option>
                                <?php 
                                    endwhile;
                                }
                                ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Transaction Type</label>
                            <select class="form-control" name="transaction_type" required>
                                <option value="service_payment">Service Payment</option>
                                <option value="spare_part">Spare Part</option>
                                <option value="deposit">Deposit</option>
                                <option value="refund">Refund</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Amount (KSh)</label>
                            <input type="number" class="form-control" name="amount" step="0.01" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Payment Method</label>
                            <select class="form-control" name="payment_method">
                                <option value="cash">Cash</option>
                                <option value="mpesa">M-Pesa</option>
                                <option value="card">Card</option>
                                <option value="bank">Bank Transfer</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-control" name="status">
                                <option value="completed">Completed</option>
                                <option value="pending">Pending</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Record</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function exportData(type) {
            if(type === 'excel') {
                window.location.href = 'export-finance.php?type=excel';
            } else {
                alert('PDF export will be available soon');
            }
        }
        
        function viewTransaction(id) {
            window.location.href = 'view-transaction.php?id=' + id;
        }
        
        function editTransaction(id) {
            window.location.href = 'edit-transaction.php?id=' + id;
        }
        
        function viewUserTransactions(userId) {
            window.location.href = 'user-transactions.php?user_id=' + userId;
        }
        
        function addFinanceRecord() {
            const modal = new bootstrap.Modal(document.getElementById('addTransactionModal'));
            modal.show();
        }
        
        function addUserTransaction(userId) {
            const modal = new bootstrap.Modal(document.getElementById('addTransactionModal'));
            const select = document.querySelector('select[name="user_id"]');
            if (select) {
                select.value = userId;
            }
            modal.show();
        }
        
        function generateInvoice() {
            alert('Invoice generation feature coming soon');
        }
        
        function viewReports() {
            window.location.href = 'finance-reports.php';
        }
        
        function runSetup() {
            window.location.href = 'setup-finance.php';
        }
    </script>
</body>
</html>