<?php
require_once 'admin_auth.php'; 
require_once 'configa.php';

$employee_id = $_GET['id'] ?? '';

if (empty($employee_id)) {
    header("Location: employees.php");
    exit;
}

// Get employee details
$stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ?");
$stmt->execute([$employee_id]);
$employee = $stmt->fetch();

if (!$employee) {
    header("Location: employees.php");
    exit;
}

// Fetch employee's current payroll settings (if any)
$payrollStmt = $pdo->prepare("SELECT * FROM employee_payroll_settings WHERE employee_id = ?");
$payrollStmt->execute([$employee_id]);
$payrollSettings = $payrollStmt->fetch();

// Fetch all positions from database
$positions = $pdo->query("SELECT * FROM positions ORDER BY position_name")->fetchAll(PDO::FETCH_ASSOC);

// Build position_rates array for JavaScript preview
$position_rates = [];
foreach ($positions as $pos) {
    $position_rates[$pos['position_name']] = [
        'hourly_rate' => $pos['hourly_rate'],
        'work_hours_per_day' => $pos['work_hours_per_day'],
        'work_days_per_month' => $pos['work_days_per_month'],
        'philhealth_rate' => $pos['philhealth_rate'],
        'sss_rate' => $pos['sss_rate'],
        'pagibig_rate' => $pos['pagibig_rate'],
        'overtime_rate' => $pos['overtime_rate']
    ];
}
$default_rate = [
    'hourly_rate' => 150,
    'work_hours_per_day' => 8,
    'work_days_per_month' => 22,
    'philhealth_rate' => 2.5,
    'sss_rate' => 4.5,
    'pagibig_rate' => 2,
    'overtime_rate' => 1.25
];

$success = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $birth_date = $_POST['birth_date'] ?? '';
    $age = $_POST['age'] ?? '';
    $address = trim($_POST['address'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $emergency_name = trim($_POST['emergency_name'] ?? '');
    $emergency_phone = trim($_POST['emergency_phone'] ?? '');
    $emergency_relationship = trim($_POST['emergency_relationship'] ?? '');

    if (empty($first_name) || empty($last_name) || empty($position) || empty($email) || empty($phone)) {
        $error = "Please fill in all required fields.";
    } else {
        $pdo->beginTransaction();
        try {
            // Update employee basic info (including emergency contact)
            $updateStmt = $pdo->prepare("
                UPDATE employees 
                SET first_name = ?, last_name = ?, position = ?, birth_date = ?, age = ?, 
                    address = ?, email = ?, phone = ?,
                    emergency_contact_name = ?, emergency_contact_phone = ?, emergency_contact_relationship = ?
                WHERE employee_id = ?
            ");
            $updateStmt->execute([
                $first_name, $last_name, $position, $birth_date, $age,
                $address, $email, $phone,
                $emergency_name, $emergency_phone, $emergency_relationship,
                $employee_id
            ]);

            // Get rate for the selected position
            $rate = isset($position_rates[$position]) ? $position_rates[$position] : $default_rate;

            // Update or insert payroll settings
            $settings_sql = "
                INSERT INTO employee_payroll_settings 
                (employee_id, hourly_rate, work_hours_per_day, work_days_per_month, philhealth_rate, sss_rate, pagibig_rate, overtime_rate)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    hourly_rate = VALUES(hourly_rate),
                    work_hours_per_day = VALUES(work_hours_per_day),
                    work_days_per_month = VALUES(work_days_per_month),
                    philhealth_rate = VALUES(philhealth_rate),
                    sss_rate = VALUES(sss_rate),
                    pagibig_rate = VALUES(pagibig_rate),
                    overtime_rate = VALUES(overtime_rate)
            ";
            $settings_stmt = $pdo->prepare($settings_sql);
            $settings_stmt->execute([
                $employee_id,
                $rate['hourly_rate'],
                $rate['work_hours_per_day'],
                $rate['work_days_per_month'],
                $rate['philhealth_rate'],
                $rate['sss_rate'],
                $rate['pagibig_rate'],
                $rate['overtime_rate']
            ]);

            $pdo->commit();
            $success = "Employee updated successfully!";

            // Refresh employee data
            $stmt->execute([$employee_id]);
            $employee = $stmt->fetch();
            // Refresh payroll settings
            $payrollStmt->execute([$employee_id]);
            $payrollSettings = $payrollStmt->fetch();

        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Helper function
function safeHtml($value) {
    return htmlspecialchars((string)$value ?? '', ENT_QUOTES, 'UTF-8');
}

// Determine which position is currently selected for preview
$currentPosition = $employee['position'] ?? '';
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Employee - Attendance System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
        }

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
            width: 100%;
            padding: 14px;
            font-size: 18px;
        }
        .btn-primary:hover {
            background: #218838;
            transform: translateY(-2px);
        }

        .form-card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }

        .form-title {
            text-align: center;
            color: #333;
            font-size: 2em;
            margin-bottom: 30px;
            border-bottom: 3px solid #667eea;
            padding-bottom: 15px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .full-width {
            grid-column: span 2;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
            font-size: 16px;
        }

        input, select, textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
            font-family: inherit;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        textarea {
            resize: vertical;
            min-height: 80px;
        }

        .rate-preview {
            background: #f8f9fa;
            border: 2px solid #667eea;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            justify-content: space-around;
        }

        .rate-item {
            text-align: center;
            min-width: 120px;
        }

        .rate-label {
            color: #666;
            font-size: 0.9em;
            margin-bottom: 5px;
        }

        .rate-value {
            color: #28a745;
            font-weight: 700;
            font-size: 1.2em;
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

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            .full-width {
                grid-column: span 1;
            }
            .form-card {
                padding: 20px;
            }
            h1 {
                font-size: 2em;
            }
            .rate-preview {
                flex-direction: column;
                align-items: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-buttons">
            <a href="employees.php" class="btn btn-back">← Back to Employees</a>
            <a href="manage_positions.php" class="btn btn-back" style="margin-left: 10px;">⚙️ Manage Positions</a>
        </div>

        <h1>✏️ Edit Employee</h1>

        <div class="form-card">
            <?php if ($success): ?>
                <div class="alert alert-success">✅ <?php echo safeHtml($success); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error">❌ <?php echo safeHtml($error); ?></div>
            <?php endif; ?>

            <form method="POST" id="employeeForm">
                <div class="form-grid">
                    <!-- Name Fields -->
                    <div class="form-group">
                        <label>First Name *</label>
                        <input type="text" name="first_name" value="<?php echo safeHtml($employee['first_name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Last Name *</label>
                        <input type="text" name="last_name" value="<?php echo safeHtml($employee['last_name']); ?>" required>
                    </div>

                    <!-- Position dropdown from database -->
                    <div class="form-group full-width">
                        <label>Position *</label>
                        <select name="position" id="positionSelect" required>
                            <option value="">Select Position</option>
                            <?php foreach ($positions as $pos): ?>
                                <option value="<?php echo safeHtml($pos['position_name']); ?>"
                                    data-rate="<?php echo $pos['hourly_rate']; ?>"
                                    <?php echo ($employee['position'] == $pos['position_name']) ? 'selected' : ''; ?>>
                                    <?php echo safeHtml($pos['position_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($positions)): ?>
                            <small style="color: #dc3545; display: block; margin-top: 5px;">
                                ⚠️ No positions defined. Please <a href="manage_positions.php">add a position</a> first.
                            </small>
                        <?php endif; ?>
                    </div>

                    <!-- Rate Preview -->
                    <div class="full-width" id="ratePreviewContainer" style="display:<?php echo $currentPosition ? 'block' : 'none'; ?>;">
                        <div class="rate-preview">
                            <div class="rate-item">
                                <div class="rate-label">Hourly Rate</div>
                                <div class="rate-value" id="previewHourlyRate">
                                    <?php
                                    $rate = isset($position_rates[$currentPosition]) ? $position_rates[$currentPosition] : $default_rate;
                                    echo '₱' . number_format($rate['hourly_rate'], 2);
                                    ?>
                                </div>
                            </div>
                            <div class="rate-item">
                                <div class="rate-label">Daily (<?php echo $rate['work_hours_per_day']; ?>h)</div>
                                <div class="rate-value" id="previewDailyRate">
                                    ₱<?php echo number_format($rate['hourly_rate'] * $rate['work_hours_per_day'], 2); ?>
                                </div>
                            </div>
                            <div class="rate-item">
                                <div class="rate-label">Monthly (<?php echo $rate['work_days_per_month']; ?>d)</div>
                                <div class="rate-value" id="previewMonthlyRate">
                                    ₱<?php echo number_format($rate['hourly_rate'] * $rate['work_hours_per_day'] * $rate['work_days_per_month'], 2); ?>
                                </div>
                            </div>
                            <div class="rate-item">
                                <div class="rate-label">Overtime Rate</div>
                                <div class="rate-value" id="previewOTRate"><?php echo $rate['overtime_rate']; ?>x</div>
                            </div>
                        </div>
                    </div>

                    <!-- Birth Date & Age -->
                    <div class="form-group">
                        <label>Birth Date</label>
                        <input type="date" name="birth_date" id="birthDate" onchange="calculateAge()"
                               value="<?php echo safeHtml($employee['birth_date']); ?>">
                    </div>
                    <div class="form-group">
                        <label>Age</label>
                        <input type="number" name="age" id="age" readonly
                               value="<?php echo safeHtml($employee['age']); ?>">
                    </div>

                    <!-- Address -->
                    <div class="form-group full-width">
                        <label>Complete Address</label>
                        <textarea name="address" rows="3"><?php echo safeHtml($employee['address']); ?></textarea>
                    </div>

                    <!-- Contact Information -->
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" name="email" value="<?php echo safeHtml($employee['email']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Phone Number *</label>
                        <input type="tel" name="phone" value="<?php echo safeHtml($employee['phone']); ?>" pattern="[0-9]{11}" required>
                        <small style="color: #999;">11 digits starting with 09</small>
                    </div>

                    <!-- Emergency Contact -->
                    <div class="form-group">
                        <label>Emergency Contact Name</label>
                        <input type="text" name="emergency_name" value="<?php echo safeHtml($employee['emergency_contact_name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Emergency Contact Phone</label>
                        <input type="tel" name="emergency_phone" value="<?php echo safeHtml($employee['emergency_contact_phone'] ?? ''); ?>" pattern="[0-9]{11}">
                        <small style="color: #999;">11 digits</small>
                    </div>
                    <div class="form-group full-width">
                        <label>Relationship</label>
                        <input type="text" name="emergency_relationship" value="<?php echo safeHtml($employee['emergency_contact_relationship'] ?? ''); ?>" placeholder="e.g. Spouse, Parent, Sibling">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">💾 Update Employee</button>
            </form>
        </div>
    </div>

    <script>
        // Position rates from database (converted to JavaScript)
        const positionRates = <?php echo json_encode($position_rates); ?>;
        const defaultRate = <?php echo json_encode($default_rate); ?>;

        function calculateAge() {
            const birthDate = document.getElementById('birthDate').value;
            if (birthDate) {
                const today = new Date();
                const birth = new Date(birthDate);
                let age = today.getFullYear() - birth.getFullYear();
                const monthDiff = today.getMonth() - birth.getMonth();
                if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birth.getDate())) {
                    age--;
                }
                document.getElementById('age').value = age;
            }
        }

        function updateRatePreview() {
            const select = document.getElementById('positionSelect');
            const selected = select.value;
            const container = document.getElementById('ratePreviewContainer');
            if (selected && positionRates[selected]) {
                const rate = positionRates[selected];
                document.getElementById('previewHourlyRate').textContent = '₱' + rate.hourly_rate.toFixed(2);
                document.getElementById('previewDailyRate').textContent = '₱' + (rate.hourly_rate * rate.work_hours_per_day).toFixed(2);
                document.getElementById('previewMonthlyRate').textContent = '₱' + (rate.hourly_rate * rate.work_hours_per_day * rate.work_days_per_month).toFixed(2);
                document.getElementById('previewOTRate').textContent = rate.overtime_rate + 'x';
                container.style.display = 'block';
            } else {
                container.style.display = 'none';
            }
        }

        document.getElementById('positionSelect').addEventListener('change', updateRatePreview);

        // Phone number validation
        document.querySelector('input[name="phone"]').addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
            if (this.value.length > 11) {
                this.value = this.value.slice(0, 11);
            }
        });
        document.querySelector('input[name="emergency_phone"]').addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
            if (this.value.length > 11) {
                this.value = this.value.slice(0, 11);
            }
        });

        // Auto-capitalize first letters
        document.querySelector('input[name="first_name"]').addEventListener('input', function(e) {
            this.value = this.value.charAt(0).toUpperCase() + this.value.slice(1).toLowerCase();
        });
        document.querySelector('input[name="last_name"]').addEventListener('input', function(e) {
            this.value = this.value.charAt(0).toUpperCase() + this.value.slice(1).toLowerCase();
        });

        // Initialize preview on load
        window.onload = function() {
            updateRatePreview();
            if (document.getElementById('birthDate').value) {
                calculateAge();
            }
        };
    </script>
</body>
</html>