<?php
require_once 'admin_auth.php'; 
require_once 'configa.php';

// Get employee ID from URL or POST
$employee_id = isset($_GET['id']) ? $_GET['id'] : (isset($_POST['employee_id']) ? $_POST['employee_id'] : '');
$action = isset($_POST['action']) ? $_POST['action'] : '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($action == 'update_settings') {
        $employee_id = $_POST['employee_id'];
        $salary_type = $_POST['salary_type'] ?? 'Monthly';
        $philhealth_rate = $_POST['philhealth_rate'] ?? 0;
        $sss_rate = $_POST['sss_rate'] ?? 0;
        $pagibig_rate = $_POST['pagibig_rate'] ?? 0;

        // Check if settings exist
        $check = $pdo->prepare("SELECT * FROM employee_payroll_settings WHERE employee_id = ?");
        $check->execute([$employee_id]);
        $existing = $check->fetch();

        if ($existing) {
            $update = $pdo->prepare("
                UPDATE employee_payroll_settings 
                SET philhealth_rate = ?, sss_rate = ?, pagibig_rate = ?,
                    updated_at = NOW()
                WHERE employee_id = ?
            ");
            $update->execute([$philhealth_rate, $sss_rate, $pagibig_rate, $employee_id]);
        } else {
            $insert = $pdo->prepare("
                INSERT INTO employee_payroll_settings 
                (employee_id, philhealth_rate, sss_rate, pagibig_rate, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $insert->execute([$employee_id, $philhealth_rate, $sss_rate, $pagibig_rate]);
        }

        // Update salary_type in employees table
        $update_salary = $pdo->prepare("UPDATE employees SET salary_type = ? WHERE employee_id = ?");
        $update_salary->execute([$salary_type, $employee_id]);

        // ---- Handle custom deductions ----
        // Delete existing custom deductions for this employee
        $delete = $pdo->prepare("DELETE FROM employee_custom_deductions WHERE employee_id = ?");
        $delete->execute([$employee_id]);

        // Get posted deduction data
        $deduction_names = $_POST['deduction_name'] ?? [];
        $deduction_types = $_POST['deduction_type'] ?? [];
        $deduction_values = $_POST['deduction_value'] ?? [];
        $deduction_remarks = $_POST['deduction_remarks'] ?? [];

        for ($i = 0; $i < count($deduction_names); $i++) {
            $name = trim($deduction_names[$i]);
            if (empty($name)) continue;
            $type = $deduction_types[$i] ?? 'fixed';
            $value = floatval($deduction_values[$i] ?? 0);
            $remarks = trim($deduction_remarks[$i] ?? '');
            if ($value <= 0) continue;

            $insert_ded = $pdo->prepare("
                INSERT INTO employee_custom_deductions 
                (employee_id, deduction_name, deduction_type, deduction_value, remarks)
                VALUES (?, ?, ?, ?, ?)
            ");
            $insert_ded->execute([$employee_id, $name, $type, $value, $remarks]);
        }

        $success = "Payroll settings and deductions updated successfully!";
    }
}

// Get all employees for the list
$employees_list = $pdo->query("
    SELECT employee_id, first_name, last_name, position, salary_type 
    FROM employees 
    ORDER BY first_name
")->fetchAll();

// Get selected employee details
$selected_employee = null;
$settings = null;
$custom_deductions = [];

if ($employee_id) {
    $empStmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ?");
    $empStmt->execute([$employee_id]);
    $selected_employee = $empStmt->fetch();

    if ($selected_employee) {
        $settingsStmt = $pdo->prepare("SELECT * FROM employee_payroll_settings WHERE employee_id = ?");
        $settingsStmt->execute([$employee_id]);
        $settings = $settingsStmt->fetch();

        // Get custom deductions
        $dedStmt = $pdo->prepare("SELECT * FROM employee_custom_deductions WHERE employee_id = ?");
        $dedStmt->execute([$employee_id]);
        $custom_deductions = $dedStmt->fetchAll();
    }
}

// Get summary statistics
$stats = $pdo->query("
    SELECT 
        COUNT(*) as total_employees,
        COUNT(DISTINCT employee_id) as with_settings,
        (SELECT COUNT(*) FROM employee_custom_deductions) as total_deductions
    FROM employee_payroll_settings
")->fetch();

$salary_types = ['Monthly', 'Bi-monthly', 'Weekly'];

?>
<!DOCTYPE html>
<html>
<head>
    <title>Employee Payroll Settings</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Segoe UI', sans-serif;
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
        .header-buttons {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
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
        .btn-back {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 2px solid white;
        }
        .btn-back:hover {
            background: white;
            color: #667eea;
        }
        .btn-primary {
            background: #28a745;
            color: white;
        }
        .btn-primary:hover {
            background: #218838;
        }
        .btn-warning {
            background: #ffc107;
            color: #333;
        }
        .btn-warning:hover {
            background: #e0a800;
        }
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        .btn-danger:hover {
            background: #c82333;
        }
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        .btn-info:hover {
            background: #138496;
        }
        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
        }
        .grid-container {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 20px;
        }
        .card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .employee-list {
            max-height: 600px;
            overflow-y: auto;
        }
        .employee-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            border-bottom: 1px solid #e0e0e0;
            cursor: pointer;
            transition: background 0.2s;
            border-radius: 8px;
        }
        .employee-item:hover {
            background: #f0f0ff;
        }
        .employee-item.active {
            background: #667eea;
            color: white;
        }
        .employee-item.active .badge {
            background: white;
            color: #667eea;
        }
        .employee-item .name {
            font-weight: 500;
        }
        .employee-item .position {
            font-size: 0.85em;
            color: #666;
        }
        .employee-item.active .position {
            color: rgba(255,255,255,0.8);
        }
        .employee-item .badge {
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 0.75em;
            background: #e9ecef;
            color: #333;
        }
        .badge-monthly { background: #d4edda; color: #155724; }
        .badge-bi-monthly { background: #fff3cd; color: #856404; }
        .badge-weekly { background: #cce5ff; color: #004085; }
        .badge-has-settings { background: #28a745; color: white; }
        .badge-no-settings { background: #dc3545; color: white; }
        .form-section {
            margin-bottom: 25px;
            border-bottom: 1px solid #e0e0e0;
            padding-bottom: 20px;
        }
        .form-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        .form-section-title {
            font-size: 1.1em;
            font-weight: 600;
            color: #333;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 12px;
        }
        .form-row.three-col {
            grid-template-columns: 1fr 1fr 1fr;
        }
        .form-group {
            margin-bottom: 12px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            color: #333;
            font-weight: 500;
            font-size: 0.9em;
        }
        input, select, textarea {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        input:focus, select:focus, textarea:focus {
            border-color: #667eea;
            outline: none;
        }
        input[readonly] {
            background: #f8f9fa;
            cursor: not-allowed;
        }
        .input-hint {
            font-size: 0.8em;
            color: #666;
            margin-top: 4px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
        }
        .stat-box .value {
            font-size: 2em;
            font-weight: 700;
        }
        .stat-box .label {
            font-size: 0.9em;
            opacity: 0.9;
        }
        .stat-box.green {
            background: linear-gradient(135deg, #28a745 0%, #218838 100%);
        }
        .stat-box.orange {
            background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);
        }
        .stat-box.purple {
            background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%);
        }
        .no-employee-selected {
            text-align: center;
            padding: 50px 20px;
            color: #666;
        }
        .no-employee-selected .icon {
            font-size: 4em;
            margin-bottom: 20px;
        }
        .btn-group {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        .btn-group .btn {
            flex: 1;
            text-align: center;
            min-width: 120px;
        }
        .search-box {
            margin-bottom: 15px;
        }
        .search-box input {
            padding: 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            width: 100%;
            font-size: 14px;
        }
        .employee-info-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #667eea;
        }
        .employee-info-header .name {
            font-size: 1.5em;
            font-weight: 600;
            color: #333;
        }
        .employee-info-header .position {
            color: #666;
        }
        /* deduction row styles */
        .deduction-row {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            border: 1px solid #e0e0e0;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: end;
        }
        .deduction-row .form-group {
            flex: 1;
            min-width: 120px;
            margin-bottom: 0;
        }
        .deduction-row .form-group:last-child {
            flex: 0 0 40px;
        }
        .deduction-row .form-group .btn-remove-ded {
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 10px;
            cursor: pointer;
            width: 100%;
            font-size: 18px;
            line-height: 1;
        }
        .deduction-row .form-group .btn-remove-ded:hover {
            background: #c82333;
        }
        .empty-deductions-msg {
            color: #999;
            font-style: italic;
            padding: 10px 0;
            text-align: center;
            border: 1px dashed #ddd;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        @media (max-width: 1024px) {
            .grid-container {
                grid-template-columns: 1fr;
            }
            .employee-list {
                max-height: 300px;
            }
        }
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            .form-row.three-col {
                grid-template-columns: 1fr;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .header-buttons {
                flex-direction: column;
            }
            .header-buttons .btn {
                width: 100%;
                text-align: center;
            }
            .deduction-row {
                flex-direction: column;
                align-items: stretch;
            }
            .deduction-row .form-group:last-child {
                flex: 1;
            }
        }
        @media print {
            .header-buttons, .btn, .no-print { display: none; }
            body { background: white; }
            .card { box-shadow: none; border: 1px solid #ddd; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>⚙️ Employee Payroll Settings</h1>

        <div class="header-buttons">
            <a href="admin_dashboard.php" class="btn btn-back">← Dashboard</a>
            <a href="payroll_computation.php" class="btn btn-back">📊 Payroll</a>
            <a href="employee_work_schedule.php" class="btn btn-back">📅 Schedule</a>
            <a href="admin_scanner.php" class="btn btn-back">📱 QR Scanner</a>
            <a href="manage_positions.php" class="btn btn-back" style="background: #6f42c1; border-color: #6f42c1;">⚙️ Positions</a>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert alert-success">✅ <?php echo $success; ?></div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-error">❌ <?php echo $error; ?></div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-box">
                <div class="value"><?php echo $stats['total_employees'] ?? 0; ?></div>
                <div class="label">Total Employees</div>
            </div>
            <div class="stat-box green">
                <div class="value"><?php echo $stats['with_settings'] ?? 0; ?></div>
                <div class="label">With Payroll Settings</div>
            </div>
            <div class="stat-box purple">
                <div class="value"><?php echo $stats['total_deductions'] ?? 0; ?></div>
                <div class="label">Custom Deductions</div>
            </div>
        </div>

        <div class="grid-container">
            <!-- Left: Employee List -->
            <div class="card">
                <div class="search-box">
                    <input type="text" id="searchEmployee" placeholder="🔍 Search employees..." onkeyup="filterEmployees()">
                </div>
                <div class="employee-list" id="employeeList">
                    <?php foreach ($employees_list as $emp): 
                        $has_settings = $pdo->prepare("SELECT COUNT(*) FROM employee_payroll_settings WHERE employee_id = ?");
                        $has_settings->execute([$emp['employee_id']]);
                        $has_settings_count = $has_settings->fetchColumn();
                        $is_active = ($employee_id == $emp['employee_id']);
                        $badge_class = 'badge-' . strtolower(str_replace('-', '', $emp['salary_type'] ?? 'monthly'));
                    ?>
                        <div class="employee-item <?php echo $is_active ? 'active' : ''; ?>" 
                             onclick="window.location.href='employee_payroll_settings.php?id=<?php echo $emp['employee_id']; ?>'"
                             data-name="<?php echo strtolower($emp['first_name'] . ' ' . $emp['last_name']); ?>">
                            <div>
                                <div class="name"><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?></div>
                                <div class="position"><?php echo htmlspecialchars($emp['position'] ?? 'N/A'); ?></div>
                            </div>
                            <div>
                                <span class="badge <?php echo $badge_class; ?>"><?php echo $emp['salary_type'] ?? 'Monthly'; ?></span>
                                <span class="badge <?php echo $has_settings_count > 0 ? 'badge-has-settings' : 'badge-no-settings'; ?>" style="margin-left: 5px;">
                                    <?php echo $has_settings_count > 0 ? '✓' : '✗'; ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Right: Settings Form -->
            <div class="card">
                <?php if ($selected_employee): ?>
                    <div class="employee-info-header">
                        <div>
                            <div class="name"><?php echo htmlspecialchars($selected_employee['first_name'] . ' ' . $selected_employee['last_name']); ?></div>
                            <div class="position"><?php echo htmlspecialchars($selected_employee['position'] ?? 'N/A'); ?> • ID: <?php echo $selected_employee['employee_id']; ?></div>
                        </div>
                        <div>
                            <span class="badge badge-<?php echo strtolower(str_replace('-', '', $selected_employee['salary_type'] ?? 'monthly')); ?>" style="font-size: 14px; padding: 5px 15px;">
                                <?php echo $selected_employee['salary_type'] ?? 'Monthly'; ?>
                            </span>
                        </div>
                    </div>

                    <form method="POST" id="settingsForm">
                        <input type="hidden" name="action" value="update_settings">
                        <input type="hidden" name="employee_id" value="<?php echo $employee_id; ?>">

                        <!-- Salary Type -->
                        <div class="form-section">
                            <div class="form-section-title">📋 Basic Info</div>
                            <div class="form-group">
                                <label for="salary_type">Salary Type *</label>
                                <select name="salary_type" id="salary_type" required>
                                    <?php foreach ($salary_types as $type): ?>
                                        <option value="<?php echo $type; ?>" <?php echo ($selected_employee['salary_type'] ?? 'Monthly') == $type ? 'selected' : ''; ?>>
                                            <?php echo $type; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="input-hint">Determines pay period for payroll calculations</div>
                            </div>
                        </div>

                        <!-- Mandatory Deduction Rates -->
                        <div class="form-section">
                            <div class="form-section-title">📉 Mandatory Deduction Rates</div>
                            <div class="form-row three-col">
                                <div class="form-group">
                                    <label for="philhealth_rate">PhilHealth (%)</label>
                                    <input type="number" step="0.01" name="philhealth_rate" id="philhealth_rate" 
                                           value="<?php echo $settings['philhealth_rate'] ?? 0; ?>" min="0" max="100">
                                    <div class="input-hint">Percentage of gross pay</div>
                                </div>
                                <div class="form-group">
                                    <label for="sss_rate">SSS (%)</label>
                                    <input type="number" step="0.01" name="sss_rate" id="sss_rate" 
                                           value="<?php echo $settings['sss_rate'] ?? 0; ?>" min="0" max="100">
                                    <div class="input-hint">Percentage of gross pay</div>
                                </div>
                                <div class="form-group">
                                    <label for="pagibig_rate">Pag-IBIG (%)</label>
                                    <input type="number" step="0.01" name="pagibig_rate" id="pagibig_rate" 
                                           value="<?php echo $settings['pagibig_rate'] ?? 0; ?>" min="0" max="100">
                                    <div class="input-hint">Percentage of gross pay</div>
                                </div>
                            </div>
                        </div>

                        <!-- Other Deductions (Customizable) -->
                        <div class="form-section">
                            <div class="form-section-title">➕ Other Deductions (Customizable)</div>
                            <p style="color: #666; font-size: 0.9em; margin-bottom: 10px;">
                                Add employee‑specific deductions such as HMO, uniform, cash advance, loan payments, etc.
                            </p>

                            <div id="deductionsContainer">
                                <?php if (empty($custom_deductions)): ?>
                                    <div class="empty-deductions-msg">No custom deductions added yet.</div>
                                <?php else: ?>
                                    <?php foreach ($custom_deductions as $idx => $ded): ?>
                                        <div class="deduction-row">
                                            <div class="form-group">
                                                <label>Deduction Name *</label>
                                                <input type="text" name="deduction_name[]" value="<?php echo htmlspecialchars($ded['deduction_name']); ?>" placeholder="e.g. HMO, Uniform, Loan" required>
                                            </div>
                                            <div class="form-group">
                                                <label>Type</label>
                                                <select name="deduction_type[]">
                                                    <option value="fixed" <?php echo $ded['deduction_type'] == 'fixed' ? 'selected' : ''; ?>>Fixed Amount</option>
                                                    <option value="percent" <?php echo $ded['deduction_type'] == 'percent' ? 'selected' : ''; ?>>Percentage</option>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label>Amount / Percentage</label>
                                                <input type="number" step="0.01" name="deduction_value[]" value="<?php echo $ded['deduction_value']; ?>" placeholder="e.g. 500" required min="0">
                                            </div>
                                            <div class="form-group">
                                                <label>Remarks (Optional)</label>
                                                <input type="text" name="deduction_remarks[]" value="<?php echo htmlspecialchars($ded['remarks']); ?>" placeholder="e.g. monthly loan">
                                            </div>
                                            <div class="form-group">
                                                <label>&nbsp;</label>
                                                <button type="button" class="btn-remove-ded" onclick="removeDeductionRow(this)">✕</button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <button type="button" class="btn btn-primary" onclick="addDeductionRow()" style="margin-top: 10px;">
                                + Add Another Deduction
                            </button>
                        </div>

                        <div class="btn-group">
                            <button type="submit" class="btn btn-primary">💾 Save Settings</button>
                            <button type="reset" class="btn btn-warning">↺ Reset Form</button>
                            <a href="payroll_computation.php?employee=<?php echo $employee_id; ?>" class="btn btn-info">📊 View Payroll</a>
                        </div>
                    </form>

                <?php else: ?>
                    <div class="no-employee-selected">
                        <div class="icon">👈</div>
                        <h3>Select an Employee</h3>
                        <p style="color: #666; margin-top: 10px;">Choose an employee from the list on the left to view and edit their payroll settings.</p>
                        <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
                            <a href="add_employee.php" class="btn btn-primary">➕ Add New Employee</a>
                            <a href="payroll_computation.php" class="btn btn-info">📊 Go to Payroll</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Filter employee list
        function filterEmployees() {
            const search = document.getElementById('searchEmployee').value.toLowerCase();
            const items = document.querySelectorAll('.employee-item');
            items.forEach(item => {
                const name = item.getAttribute('data-name') || '';
                item.style.display = name.includes(search) ? 'flex' : 'none';
            });
        }

        // Deduction rows - fully optional
        function addDeductionRow() {
            const container = document.getElementById('deductionsContainer');
            // Remove the "empty" message if present
            const emptyMsg = container.querySelector('.empty-deductions-msg');
            if (emptyMsg) emptyMsg.remove();

            const row = document.createElement('div');
            row.className = 'deduction-row';
            row.innerHTML = `
                <div class="form-group">
                    <label>Deduction Name *</label>
                    <input type="text" name="deduction_name[]" placeholder="e.g. HMO, Uniform, Loan" required>
                </div>
                <div class="form-group">
                    <label>Type</label>
                    <select name="deduction_type[]">
                        <option value="fixed" selected>Fixed Amount</option>
                        <option value="percent">Percentage</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Amount / Percentage</label>
                    <input type="number" step="0.01" name="deduction_value[]" placeholder="e.g. 500" required min="0">
                </div>
                <div class="form-group">
                    <label>Remarks (Optional)</label>
                    <input type="text" name="deduction_remarks[]" placeholder="e.g. monthly loan">
                </div>
                <div class="form-group">
                    <label>&nbsp;</label>
                    <button type="button" class="btn-remove-ded" onclick="removeDeductionRow(this)">✕</button>
                </div>
            `;
            container.appendChild(row);
        }

        function removeDeductionRow(btn) {
            const row = btn.closest('.deduction-row');
            if (row) {
                row.remove();
                // If no rows left, show the empty message
                const container = document.getElementById('deductionsContainer');
                if (container.querySelectorAll('.deduction-row').length === 0) {
                    // Check if message already exists
                    if (!container.querySelector('.empty-deductions-msg')) {
                        const msg = document.createElement('div');
                        msg.className = 'empty-deductions-msg';
                        msg.textContent = 'No custom deductions added yet.';
                        container.appendChild(msg);
                    }
                }
            }
        }

        // No default row added on load - user must click "Add Another Deduction"
    </script>
</body>
</html>