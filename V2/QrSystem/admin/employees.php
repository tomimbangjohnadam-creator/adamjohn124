<?php
require_once 'admin_auth.php'; 
require_once 'configa.php';

// --- Flash messages from session ---
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Search
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Build query
$sql = "SELECT * FROM employees WHERE 1=1";
$countSql = "SELECT COUNT(*) FROM employees WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql .= " AND (employee_id LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $countSql .= " AND (employee_id LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_fill(0, 5, $searchTerm);
}

$sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll();

$stmt = $pdo->prepare($countSql);
if (!empty($search)) {
    $stmt->execute(array_fill(0, 5, "%$search%"));
} else {
    $stmt->execute();
}
$totalEmployees = $stmt->fetchColumn();
$totalPages = ceil($totalEmployees / $limit);

// --- Handle delete request ---
if (isset($_GET['delete']) && !empty($_GET['delete'])) {
    $employee_id = $_GET['delete'];
    
    try {
        $pdo->beginTransaction();
        
        // Delete attendance
        $stmt = $pdo->prepare("DELETE FROM attendance WHERE employee_id = ?");
        $stmt->execute([$employee_id]);
        
        // Delete payroll settings
        $stmt = $pdo->prepare("DELETE FROM employee_payroll_settings WHERE employee_id = ?");
        $stmt->execute([$employee_id]);
        
        // Delete custom deductions
        $stmt = $pdo->prepare("DELETE FROM employee_custom_deductions WHERE employee_id = ?");
        $stmt->execute([$employee_id]);
        
        // Delete rest days
        $stmt = $pdo->prepare("DELETE FROM employee_rest_days WHERE employee_id = ?");
        $stmt->execute([$employee_id]);
        
        // Delete payroll records
        $stmt = $pdo->prepare("DELETE FROM payroll_records WHERE employee_id = ?");
        $stmt->execute([$employee_id]);
        
        // Delete payroll history
        $stmt = $pdo->prepare("DELETE FROM payroll_history WHERE employee_id = ?");
        $stmt->execute([$employee_id]);
        
        // Delete employee
        $stmt = $pdo->prepare("DELETE FROM employees WHERE employee_id = ?");
        $stmt->execute([$employee_id]);
        
        $pdo->commit();
        
        // Delete QR code file
        $qrFile = '../qrcodes/qr_' . $employee_id . '.png';
        if (file_exists($qrFile)) {
            unlink($qrFile);
        }
        
        $_SESSION['success'] = "Employee deleted successfully!";
        header("Location: employees.php");
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error deleting employee: " . $e->getMessage();
        header("Location: employees.php");
        exit;
    }
}

// --- Helper functions ---
function qrCodeExists($employee_id) {
    $paths = [
        '../qrcodes/qr_' . $employee_id . '.png',
        'qrcodes/qr_' . $employee_id . '.png',
        '../admin/qrcodes/qr_' . $employee_id . '.png',
        'admin/qrcodes/qr_' . $employee_id . '.png',
        '/QrSystem/qrcodes/qr_' . $employee_id . '.png'
    ];
    foreach ($paths as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }
    return false;
}

function getQRCodeUrl($employee_id) {
    return '../qrcodes/qr_' . $employee_id . '.png';
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Employees - Attendance System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        /* All your CSS here – unchanged */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        h1 {
            text-align: center;
            color: white;
            margin-bottom: 30px;
            font-size: 2.5em;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }
        .header-section {
            background: white;
            border-radius: 20px;
            padding: 20px 30px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .header-title {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .header-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5em;
        }
        .header-text h2 {
            color: #333;
            font-size: 1.5em;
        }
        .header-text p {
            color: #666;
        }
        .header-buttons {
            display: flex;
            gap: 15px;
        }
        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary {
            background: #28a745;
            color: white;
        }
        .btn-primary:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }
        .btn-back {
            background: #667eea;
            color: white;
        }
        .btn-back:hover {
            background: #5a67d8;
        }
        .btn-generate {
            background: #17a2b8;
            color: white;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .stat-card h3 {
            font-size: 2em;
            color: #667eea;
            margin-bottom: 5px;
        }
        .stat-card p {
            color: #666;
            font-weight: 500;
        }
        .search-section {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .search-form {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        .search-input {
            flex: 1;
            min-width: 250px;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
        }
        .search-input:focus {
            outline: none;
            border-color: #667eea;
        }
        .search-btn {
            padding: 12px 30px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
        }
        .search-btn:hover {
            background: #5a67d8;
        }
        .reset-btn {
            padding: 12px 30px;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
        }
        .table-container {
            background: white;
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }
        th {
            background: #667eea;
            color: white;
            padding: 15px;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.9em;
        }
        td {
            padding: 15px;
            border-bottom: 1px solid #e0e0e0;
            text-align: center;
        }
        tr:hover {
            background: #f8f9fa;
        }
        .employee-info {
            text-align: left;
        }
        .employee-name {
            font-weight: 600;
            color: #333;
        }
        .employee-id {
            font-size: 0.85em;
            color: #667eea;
        }
        .position-badge {
            background: #e7f3ff;
            color: #004085;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.85em;
            display: inline-block;
        }
        .qr-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 5px;
        }
        .qr-available { background: #28a745; }
        .qr-unavailable { background: #dc3545; }
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
        }
        .status-active { background: #d4edda; color: #155724; }
        .status-present { background: #cce5ff; color: #004085; }
        .status-inactive { background: #f8d7da; color: #721c24; }
        .action-buttons {
            display: flex;
            gap: 5px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .action-btn {
            padding: 8px 12px;
            border: none;
            border-radius: 5px;
            font-size: 0.9em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        .btn-view { background: #17a2b8; color: white; }
        .btn-edit { background: #ffc107; color: #333; }
        .btn-qr { background: #28a745; color: white; }
        .btn-print-qr { background: #6c757d; color: white; }
        .btn-generate-qr { background: #fd7e14; color: white; }
        .btn-delete { background: #dc3545; color: white; }
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 10px rgba(0,0,0,0.2);
        }
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 30px;
            flex-wrap: wrap;
        }
        .page-btn {
            padding: 10px 15px;
            background: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            color: #333;
        }
        .page-btn:hover {
            background: #667eea;
            color: white;
        }
        .page-btn.active {
            background: #667eea;
            color: white;
        }
        .page-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(5px);
        }
        .modal.show { display: flex; }
        .modal-content {
            background: white;
            border-radius: 20px;
            padding: 30px;
            max-width: 450px;
            width: 90%;
            text-align: center;
            animation: modalSlideIn 0.3s;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        @keyframes modalSlideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        #qrImage {
            display: block;
            margin: 20px auto;
            max-width: 250px;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            border: 3px solid #667eea;
        }
        .qr-placeholder {
            width: 250px;
            height: 250px;
            margin: 20px auto;
            background: #f8f9fa;
            border: 2px dashed #667eea;
            border-radius: 10px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #999;
        }
        .qr-placeholder span { font-size: 4em; margin-bottom: 10px; }
        .modal-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        .modal-btn {
            padding: 12px 25px;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        .modal-btn-primary { background: #28a745; color: white; }
        .modal-btn-primary:hover { background: #218838; }
        .modal-btn-secondary { background: #6c757d; color: white; }
        .modal-btn-secondary:hover { background: #5a6268; }
        .modal-close { background: #667eea; color: white; }
        .modal-close:hover { background: #5a67d8; transform: translateY(-2px); }
        .generate-link {
            display: inline-block;
            margin-top: 15px;
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        .generate-link:hover { text-decoration: underline; }
        .qr-note { font-size: 0.9em; color: #999; margin-top: 10px; }
        @media print {
            body * { visibility: hidden; }
            .qr-print-area, .qr-print-area * { visibility: visible; }
            .qr-print-area {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                padding: 20px;
            }
            .no-print { display: none !important; }
        }
        .qr-print-area {
            text-align: center;
            font-family: 'Segoe UI', Arial, sans-serif;
        }
        .qr-print-area img { max-width: 300px; margin: 20px auto; }
        .qr-print-area .company-name {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .qr-print-area .employee-details {
            margin: 20px 0;
            font-size: 16px;
        }
        @media (max-width: 768px) {
            h1 { font-size: 2em; }
            .header-section { flex-direction: column; text-align: center; }
            .search-form { flex-direction: column; }
            .action-buttons { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>👥 Employee Management</h1>

        <!-- Header -->
        <div class="header-section">
            <div class="header-title">
                <div class="header-icon">📋</div>
                <div class="header-text">
                    <h2>Manage Employees</h2>
                    <p>Total Employees: <?php echo $totalEmployees; ?></p>
                </div>
            </div>
            <div class="header-buttons">
                <a href="admin_dashboard.php" class="btn btn-back">← Back to Dashboard</a>
                <a href="generate_qrcodes.php" class="btn btn-generate">📱 Create QR</a>
                <a href="add_employee.php" class="btn btn-primary">➕ Add New Employee</a>
            </div>
        </div>

        <!-- Statistics -->
        <?php
        $totalActive = $pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn();
        $totalPresent = $pdo->query("SELECT COUNT(DISTINCT employee_id) FROM attendance WHERE date = CURDATE()")->fetchColumn();
        $qrCount = 0;
        foreach ($employees as $emp) {
            if (qrCodeExists($emp['employee_id'])) $qrCount++;
        }
        ?>
        <div class="stats-grid">
            <div class="stat-card">
                <h3><?php echo $totalActive; ?></h3>
                <p>Total Employees</p>
            </div>
            <div class="stat-card">
                <h3><?php echo $totalPresent; ?></h3>
                <p>Present Today</p>
            </div>
            <div class="stat-card">
                <h3><?php echo $qrCount; ?></h3>
                <p>With QR Codes</p>
            </div>
            <div class="stat-card">
                <h3><?php echo $totalPages; ?></h3>
                <p>Total Pages</p>
            </div>
        </div>

        <!-- Search -->
        <div class="search-section">
            <form method="GET" class="search-form">
                <input type="text" name="search" class="search-input" placeholder="Search by ID, Last name, Email, or Phone..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="search-btn">🔍 Search</button>
                <a href="employees.php" class="reset-btn">🔄 Reset</a>
            </form>
        </div>

        <!-- Messages (flash from session) -->
        <?php if ($success): ?>
            <div class="alert alert-success">✅ <?php echo $success; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error">❌ <?php echo $error; ?></div>
        <?php endif; ?>

        <!-- Table -->
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Employee ID</th>
                        <th>Name</th>
                        <th>Position</th>
                        <th>Contact</th>
                        <th>QR Status</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($employees)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 50px; color: #999;">
                                No employees found. Click "Add New Employee" to create one.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($employees as $emp): ?>
                            <?php
                            $todayStmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND date = CURDATE()");
                            $todayStmt->execute([$emp['employee_id']]);
                            $hasAttendanceToday = $todayStmt->fetchColumn() > 0;
                            $hasQR = qrCodeExists($emp['employee_id']);
                            $statusClass = $hasAttendanceToday ? 'status-present' : 'status-active';
                            $statusText = $hasAttendanceToday ? 'Present Today' : 'Active';
                            ?>
                            <tr>
                                <td class="employee-id"><strong><?php echo htmlspecialchars($emp['employee_id']); ?></strong></td>
                                <td class="employee-info">
                                    <div class="employee-name"><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?></div>
                                    <small><?php echo htmlspecialchars($emp['email']); ?></small>
                                </td>
                                <td><span class="position-badge"><?php echo htmlspecialchars($emp['position'] ?? 'N/A'); ?></span></td>
                                <td><small><?php echo htmlspecialchars($emp['phone']); ?></small></td>
                                <td>
                                    <span class="qr-indicator <?php echo $hasQR ? 'qr-available' : 'qr-unavailable'; ?>"></span>
                                    <?php echo $hasQR ? 'Available' : 'Not Found'; ?>
                                </td>
                                <td><span class="status-badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span></td>
                                <td class="action-buttons">
                                    <a href="view_employee.php?id=<?php echo $emp['employee_id']; ?>" class="action-btn btn-view" title="View Details">👁️</a>
                                    <a href="edit_employee.php?id=<?php echo $emp['employee_id']; ?>" class="action-btn btn-edit" title="Edit Employee">✏️</a>
                                    <?php if ($hasQR): ?>
                                        <a href="javascript:void(0)" onclick="showQRCode('<?php echo $emp['employee_id']; ?>', '<?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>')" class="action-btn btn-qr" title="View QR Code">📱</a>
                                        <a href="javascript:void(0)" onclick="printQRCode('<?php echo $emp['employee_id']; ?>', '<?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>', '<?php echo htmlspecialchars($emp['position'] ?? ''); ?>')" class="action-btn btn-print-qr" title="Print QR Code">🖨️</a>
                                    <?php else: ?>
                                        <a href="generate_qrcodes.php?employee_id=<?php echo $emp['employee_id']; ?>" class="action-btn btn-generate-qr" title="Generate QR Code">➕📱</a>
                                    <?php endif; ?>
                                    <a href="javascript:void(0)" onclick="confirmDelete('<?php echo $emp['employee_id']; ?>')" class="action-btn btn-delete" title="Delete Employee">🗑️</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <a href="?page=1<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                   class="page-btn <?php echo $page <= 1 ? 'disabled' : ''; ?>">« First</a>
                <a href="?page=<?php echo max(1, $page-1) . (!empty($search) ? '&search=' . urlencode($search) : ''); ?>" 
                   class="page-btn <?php echo $page <= 1 ? 'disabled' : ''; ?>">‹ Prev</a>
                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                for ($i = $start; $i <= $end; $i++):
                ?>
                    <a href="?page=<?php echo $i . (!empty($search) ? '&search=' . urlencode($search) : ''); ?>" 
                       class="page-btn <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                <a href="?page=<?php echo min($totalPages, $page+1) . (!empty($search) ? '&search=' . urlencode($search) : ''); ?>" 
                   class="page-btn <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">Next ›</a>
                <a href="?page=<?php echo $totalPages . (!empty($search) ? '&search=' . urlencode($search) : ''); ?>" 
                   class="page-btn <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">Last »</a>
            </div>
        <?php endif; ?>
    </div>

    <!-- QR Modal -->
    <div id="qrModal" class="modal">
        <div class="modal-content">
            <h3 style="color: #333; margin-bottom: 10px;">📱 Employee QR Code</h3>
            <div id="qrContainer" style="width:100%; display:flex; flex-direction:column; align-items:center;">
                <img id="qrImage" src="" alt="QR Code" style="display:none; margin:20px auto; max-width:250px; border-radius:10px; box-shadow:0 10px 30px rgba(0,0,0,0.2); border:3px solid #667eea;">
                <div id="qrPlaceholder" class="qr-placeholder">
                    <span>📱</span>
                    <p>Loading QR Code...</p>
                </div>
            </div>
            <p id="qrEmployeeName" style="font-weight:600; color:#333; margin:10px 0;"></p>
            <p id="qrEmployeeId" style="color:#667eea; margin-bottom:15px;"></p>
            <p id="qrStatus" style="color:#999; font-size:0.9em;"></p>
            <div class="modal-buttons">
                <button class="modal-btn modal-btn-primary" onclick="printCurrentQR()">🖨️ Print QR Code</button>
                <button class="modal-btn modal-close" onclick="closeQRModal()">Close</button>
            </div>
            <a id="generateQrLink" href="#" class="generate-link" style="display:none;">Generate QR Code</a>
            <p class="qr-note">If QR code doesn't appear, generate it from the Create QR page.</p>
        </div>
    </div>

    <script>
        let currentQRData = { employeeId: '', employeeName: '', employeePosition: '' };

        function confirmDelete(employeeId) {
            if (confirm('⚠️ Are you sure you want to delete this employee?\n\nThis will also delete all attendance records, payroll records, and settings for this employee. This action cannot be undone!')) {
                window.location.href = 'employees.php?delete=' + employeeId;
            }
        }

        function showQRCode(employeeId, employeeName, employeePosition = '') {
            const modal = document.getElementById('qrModal');
            const qrImage = document.getElementById('qrImage');
            const qrPlaceholder = document.getElementById('qrPlaceholder');
            const qrStatus = document.getElementById('qrStatus');
            const generateLink = document.getElementById('generateQrLink');

            currentQRData = { employeeId, employeeName, employeePosition };

            qrImage.style.display = 'none';
            qrPlaceholder.style.display = 'flex';
            qrPlaceholder.innerHTML = '<span>📱</span><p>Loading QR Code...</p>';
            qrStatus.textContent = 'Loading...';
            generateLink.style.display = 'none';

            document.getElementById('qrEmployeeName').textContent = employeeName;
            document.getElementById('qrEmployeeId').textContent = 'ID: ' + employeeId;
            generateLink.href = 'qr_generator.php?employee_id=' + employeeId;

            const qrPath = '../qrcodes/qr_' + employeeId + '.png';
            const img = new Image();
            img.onload = function() {
                qrImage.src = qrPath + '?t=' + new Date().getTime();
                qrImage.style.display = 'block';
                qrPlaceholder.style.display = 'none';
                qrStatus.textContent = 'QR code loaded - Ready to print';
                generateLink.style.display = 'none';
            };
            img.onerror = function() {
                qrPlaceholder.innerHTML = '<span>❌</span><p>QR Code not found</p><small style="color:#999; margin-top:10px;">Generate QR code from Create QR page</small>';
                qrStatus.textContent = 'QR code not available';
                generateLink.style.display = 'inline-block';
            };
            img.src = qrPath;
            modal.classList.add('show');
        }

        function printCurrentQR() {
            const { employeeId, employeeName, employeePosition } = currentQRData;
            if (!employeeId) { alert('No QR code loaded to print'); return; }
            const qrPath = '../qrcodes/qr_' + employeeId + '.png';
            const currentDate = new Date().toLocaleDateString();
            const printWindow = window.open('', '_blank');
            if (!printWindow) { alert('Please allow pop-ups to print QR codes'); return; }
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head><title>QR Code - ${employeeName}</title><meta charset="UTF-8">
                <style>
                    * { margin:0; padding:0; box-sizing:border-box; }
                    body { font-family:'Segoe UI',Arial,sans-serif; padding:40px; background:white; }
                    .qr-card { max-width:400px; margin:0 auto; text-align:center; border:2px solid #667eea; border-radius:20px; padding:30px; box-shadow:0 10px 30px rgba(0,0,0,0.1); }
                    .company-name { font-size:24px; font-weight:bold; color:#667eea; margin-bottom:10px; }
                    .company-sub { color:#666; margin-bottom:20px; font-size:12px; }
                    .qr-image { margin:20px 0; padding:10px; background:white; }
                    .qr-image img { max-width:250px; height:auto; border:3px solid #667eea; border-radius:10px; }
                    .employee-details { margin:20px 0; padding-top:15px; border-top:1px dashed #ccc; }
                    .employee-name { font-size:18px; font-weight:bold; color:#333; margin-bottom:5px; }
                    .employee-id { font-size:14px; color:#667eea; margin-bottom:5px; }
                    .employee-position { font-size:14px; color:#666; }
                    .issue-date { margin-top:20px; font-size:10px; color:#999; border-top:1px solid #eee; padding-top:15px; }
                    .instruction { margin-top:15px; font-size:11px; color:#666; }
                    @media print { body { padding:0; } .no-print { display:none; } .qr-card { box-shadow:none; border:1px solid #ccc; } }
                    .no-print { text-align:center; margin-top:20px; }
                    .btn-print { background:#28a745; color:white; border:none; padding:10px 20px; border-radius:5px; cursor:pointer; font-size:14px; margin:0 5px; }
                    .btn-close { background:#dc3545; color:white; border:none; padding:10px 20px; border-radius:5px; cursor:pointer; font-size:14px; margin:0 5px; }
                </style>
                </head>
                <body>
                <div class="qr-card">
                    <div class="company-name">ATTENDANCE SYSTEM</div>
                    <div class="company-sub">Employee Identification Card</div>
                    <div class="qr-image"><img src="${qrPath}?t=${new Date().getTime()}" alt="QR Code"></div>
                    <div class="employee-details">
                        <div class="employee-name">${escapeHtml(employeeName)}</div>
                        <div class="employee-id">ID: ${escapeHtml(employeeId)}</div>
                        <div class="employee-position">${escapeHtml(employeePosition) || 'Employee'}</div>
                    </div>
                    <div class="issue-date">Issued on: ${currentDate}</div>
                    <div class="instruction">Present this QR code for attendance scanning</div>
                </div>
                <div class="no-print">
                    <button class="btn-print" onclick="window.print()">🖨️ Print QR Code</button>
                    <button class="btn-close" onclick="window.close()">❌ Close</button>
                </div>
                </body>
                </html>
            `);
            printWindow.document.close();
        }

        function printQRCode(employeeId, employeeName, employeePosition) {
            const qrPath = '../qrcodes/qr_' + employeeId + '.png';
            const currentDate = new Date().toLocaleDateString();
            const img = new Image();
            img.onload = function() {
                const printWindow = window.open('', '_blank');
                if (!printWindow) { alert('Please allow pop-ups to print QR codes'); return; }
                printWindow.document.write(`
                    <!DOCTYPE html>
                    <html>
                    <head><title>QR Code - ${employeeName}</title><meta charset="UTF-8">
                    <style>
                        * { margin:0; padding:0; box-sizing:border-box; }
                        body { font-family:'Segoe UI',Arial,sans-serif; padding:40px; background:white; }
                        .qr-card { max-width:400px; margin:0 auto; text-align:center; border:2px solid #667eea; border-radius:20px; padding:30px; box-shadow:0 10px 30px rgba(0,0,0,0.1); }
                        .company-name { font-size:24px; font-weight:bold; color:#667eea; margin-bottom:10px; }
                        .company-sub { color:#666; margin-bottom:20px; font-size:12px; }
                        .qr-image { margin:20px 0; padding:10px; background:white; }
                        .qr-image img { max-width:250px; height:auto; border:3px solid #667eea; border-radius:10px; }
                        .employee-details { margin:20px 0; padding-top:15px; border-top:1px dashed #ccc; }
                        .employee-name { font-size:18px; font-weight:bold; color:#333; margin-bottom:5px; }
                        .employee-id { font-size:14px; color:#667eea; margin-bottom:5px; }
                        .employee-position { font-size:14px; color:#666; }
                        .issue-date { margin-top:20px; font-size:10px; color:#999; border-top:1px solid #eee; padding-top:15px; }
                        .instruction { margin-top:15px; font-size:11px; color:#666; }
                        @media print { body { padding:0; } .no-print { display:none; } .qr-card { box-shadow:none; border:1px solid #ccc; } }
                        .no-print { text-align:center; margin-top:20px; }
                        .btn-print { background:#28a745; color:white; border:none; padding:10px 20px; border-radius:5px; cursor:pointer; font-size:14px; margin:0 5px; }
                        .btn-close { background:#dc3545; color:white; border:none; padding:10px 20px; border-radius:5px; cursor:pointer; font-size:14px; margin:0 5px; }
                    </style>
                    </head>
                    <body>
                    <div class="qr-card">
                        <div class="company-name">ATTENDANCE SYSTEM</div>
                        <div class="company-sub">Employee Identification Card</div>
                        <div class="qr-image"><img src="${qrPath}?t=${new Date().getTime()}" alt="QR Code"></div>
                        <div class="employee-details">
                            <div class="employee-name">${escapeHtml(employeeName)}</div>
                            <div class="employee-id">ID: ${escapeHtml(employeeId)}</div>
                            <div class="employee-position">${escapeHtml(employeePosition) || 'Employee'}</div>
                        </div>
                        <div class="issue-date">Issued on: ${currentDate}</div>
                        <div class="instruction">Present this QR code for attendance scanning</div>
                    </div>
                    <div class="no-print">
                        <button class="btn-print" onclick="window.print()">🖨️ Print QR Code</button>
                        <button class="btn-close" onclick="window.close()">❌ Close</button>
                    </div>
                    </body>
                    </html>
                `);
                printWindow.document.close();
            };
            img.onerror = function() {
                alert('QR code not found. Please generate the QR code first.');
                window.location.href = 'generate_qrcodes.php?employee_id=' + employeeId;
            };
            img.src = qrPath;
        }

        function escapeHtml(text) {
            if (!text) return '';
            return String(text).replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }

        function closeQRModal() {
            document.getElementById('qrModal').classList.remove('show');
        }

        window.onclick = function(event) {
            const modal = document.getElementById('qrModal');
            if (event.target == modal) modal.classList.remove('show');
        };
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeQRModal();
        });
    </script>
</body>
</html>