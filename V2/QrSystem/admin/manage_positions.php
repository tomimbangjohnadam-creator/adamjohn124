<?php
require_once 'admin_auth.php'; 
require_once 'configa.php';

// Check if user is admin (you may add a role check)

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    // Add new position
    if ($action == 'add') {
        $position_name = trim($_POST['position_name']);
        $hourly_rate = floatval($_POST['hourly_rate']);
        $work_hours_per_day = floatval($_POST['work_hours_per_day'] ?: 8);
        $work_days_per_month = floatval($_POST['work_days_per_month'] ?: 22);
        $overtime_rate = floatval($_POST['overtime_rate'] ?: 1.25);

        if (empty($position_name)) {
            $error = "Position name is required.";
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO positions (position_name, hourly_rate, work_hours_per_day, work_days_per_month, overtime_rate) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$position_name, $hourly_rate, $work_hours_per_day, $work_days_per_month, $overtime_rate]);
                $success = "Position '$position_name' added successfully.";
            } catch (PDOException $e) {
                if ($e->errorInfo[1] == 1062) { // Duplicate entry
                    $error = "Position '$position_name' already exists.";
                } else {
                    $error = "Database error: " . $e->getMessage();
                }
            }
        }
    }

    // Edit position
    if ($action == 'edit') {
        $id = intval($_POST['id']);
        $position_name = trim($_POST['position_name']);
        $hourly_rate = floatval($_POST['hourly_rate']);
        $work_hours_per_day = floatval($_POST['work_hours_per_day'] ?: 8);
        $work_days_per_month = floatval($_POST['work_days_per_month'] ?: 22);
        $overtime_rate = floatval($_POST['overtime_rate'] ?: 1.25);

        if (empty($position_name)) {
            $error = "Position name is required.";
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE positions SET position_name = ?, hourly_rate = ?, work_hours_per_day = ?, work_days_per_month = ?, overtime_rate = ? WHERE id = ?");
                $stmt->execute([$position_name, $hourly_rate, $work_hours_per_day, $work_days_per_month, $overtime_rate, $id]);
                $success = "Position updated successfully.";
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }

    // Delete position
    if ($action == 'delete') {
        $id = intval($_POST['id']);
        // Check if any employees use this position
        $check = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE position = (SELECT position_name FROM positions WHERE id = ?)");
        $check->execute([$id]);
        $count = $check->fetchColumn();
        if ($count > 0) {
            $error = "Cannot delete position: $count employee(s) are currently using it. Update their positions first.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM positions WHERE id = ?");
            $stmt->execute([$id]);
            $success = "Position deleted successfully.";
        }
    }
}

// Fetch all positions
$positions = $pdo->query("SELECT * FROM positions ORDER BY position_name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Positions</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 {
            text-align: center;
            color: white;
            margin-bottom: 30px;
            font-size: 2.5em;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }
        .header-buttons {
            margin-bottom: 20px;
        }
        .btn {
            padding: 10px 20px;
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
            transform: translateY(-2px);
        }
        .btn-warning {
            background: #ffc107;
            color: #333;
        }
        .btn-warning:hover {
            background: #e0a800;
            transform: translateY(-2px);
        }
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-2px);
        }
        .btn-sm {
            padding: 5px 12px;
            font-size: 14px;
        }
        .card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            margin-bottom: 30px;
        }
        .card h2 {
            margin-bottom: 20px;
            color: #333;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #555;
        }
        input[type="text"], input[type="number"] {
            width: 100%;
            padding: 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
        }
        input[type="text"]:focus, input[type="number"]:focus {
            border-color: #667eea;
            outline: none;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
        }
        .form-row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        .table-container {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th {
            background: #667eea;
            color: white;
            padding: 12px;
            text-align: left;
        }
        td {
            padding: 12px;
            border-bottom: 1px solid #e0e0e0;
        }
        tr:hover {
            background: #f8f9fa;
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
        .actions {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        .edit-form {
            display: none;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin-top: 10px;
        }
        .edit-form.show {
            display: block;
        }
        @media (max-width: 768px) {
            .form-row, .form-row-2 {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-buttons">
            <a href="admin_dashboard.php" class="btn btn-back">← Back to Dashboard</a>
            <a href="employees.php" class="btn btn-back" style="margin-left: 10px;">👥 Employees</a>
            <a href="generate_qrcodes.php" class="btn btn-back" style="margin-left: 10px;">➕ Create Employee</a>
            <a href="employee_payroll_settings.php" class="btn btn-back" style="margin-left: 10px;">⚙️ Employee Settings</a>
        </div>

        <h1>⚙️ Position Management</h1>

        <?php if (isset($success)): ?>
            <div class="alert alert-success">✅ <?php echo $success; ?></div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="alert alert-error">❌ <?php echo $error; ?></div>
        <?php endif; ?>

        <!-- Add Position Form -->
        <div class="card">
            <h2>➕ Add New Position</h2>
            <form method="POST" id="addForm">
                <input type="hidden" name="action" value="add">
                <div class="form-row">
                    <div class="form-group">
                        <label>Position Name *</label>
                        <input type="text" name="position_name" placeholder="e.g. Software Engineer" required>
                    </div>
                    <div class="form-group">
                        <label>Hourly Rate (₱) *</label>
                        <input type="number" step="0.01" name="hourly_rate" placeholder="250" required>
                    </div>
                    <div class="form-group">
                        <label>Work Hours per Day</label>
                        <input type="number" step="0.5" name="work_hours_per_day" placeholder="8">
                    </div>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label>Work Days per Month</label>
                        <input type="number" step="0.5" name="work_days_per_month" placeholder="22">
                    </div>
                    <div class="form-group">
                        <label>Overtime Rate (multiplier)</label>
                        <input type="number" step="0.01" name="overtime_rate" placeholder="1.25">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Add Position</button>
            </form>
        </div>

        <!-- List of Positions -->
        <div class="card">
            <h2>📋 Existing Positions</h2>
            <?php if (empty($positions)): ?>
                <p style="color: #999;">No positions defined yet. Add one above.</p>
            <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Position</th>
                                <th>Hourly Rate</th>
                                <th>Daily (8h)</th>
                                <th>Monthly (22d)</th>
                                <th>OT Rate</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($positions as $pos): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($pos['position_name']); ?></strong></td>
                                    <td>₱<?php echo number_format($pos['hourly_rate'], 2); ?></td>
                                    <td>₱<?php echo number_format($pos['hourly_rate'] * ($pos['work_hours_per_day'] ?? 8), 2); ?></td>
                                    <td>₱<?php echo number_format($pos['hourly_rate'] * ($pos['work_hours_per_day'] ?? 8) * ($pos['work_days_per_month'] ?? 22), 2); ?></td>
                                    <td><?php echo $pos['overtime_rate']; ?>x</td>
                                    <td>
                                        <div class="actions">
                                            <button class="btn btn-warning btn-sm" onclick="toggleEdit(<?php echo $pos['id']; ?>)">✏️ Edit</button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this position? This will also remove it from employee selection.')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $pos['id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">🗑️ Delete</button>
                                            </form>
                                        </div>
                                        <!-- Inline Edit Form -->
                                        <div id="editForm_<?php echo $pos['id']; ?>" class="edit-form">
                                            <form method="POST">
                                                <input type="hidden" name="action" value="edit">
                                                <input type="hidden" name="id" value="<?php echo $pos['id']; ?>">
                                                <div class="form-row">
                                                    <div class="form-group">
                                                        <label>Position Name</label>
                                                        <input type="text" name="position_name" value="<?php echo htmlspecialchars($pos['position_name']); ?>" required>
                                                    </div>
                                                    <div class="form-group">
                                                        <label>Hourly Rate (₱)</label>
                                                        <input type="number" step="0.01" name="hourly_rate" value="<?php echo $pos['hourly_rate']; ?>" required>
                                                    </div>
                                                    <div class="form-group">
                                                        <label>Work Hours/Day</label>
                                                        <input type="number" step="0.5" name="work_hours_per_day" value="<?php echo $pos['work_hours_per_day']; ?>">
                                                    </div>
                                                </div>
                                                <div class="form-row-2">
                                                    <div class="form-group">
                                                        <label>Work Days/Month</label>
                                                        <input type="number" step="0.5" name="work_days_per_month" value="<?php echo $pos['work_days_per_month']; ?>">
                                                    </div>
                                                    <div class="form-group">
                                                        <label>Overtime Rate</label>
                                                        <input type="number" step="0.01" name="overtime_rate" value="<?php echo $pos['overtime_rate']; ?>">
                                                    </div>
                                                </div>
                                                <div style="margin-top: 10px;">
                                                    <button type="submit" class="btn btn-primary btn-sm">💾 Save Changes</button>
                                                    <button type="button" class="btn btn-secondary btn-sm" onclick="toggleEdit(<?php echo $pos['id']; ?>)">Cancel</button>
                                                </div>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function toggleEdit(id) {
            const form = document.getElementById('editForm_' + id);
            form.classList.toggle('show');
        }
    </script>
</body>
</html>