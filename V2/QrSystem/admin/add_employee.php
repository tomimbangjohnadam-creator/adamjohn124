<?php
require_once 'admin_auth.php'; 
require_once 'configa.php';

// Fetch positions from database
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
$default_rate = ['hourly_rate' => 150, 'work_hours_per_day' => 8, 'work_days_per_month' => 22,
                'philhealth_rate' => 2.5, 'sss_rate' => 4.5, 'pagibig_rate' => 2, 'overtime_rate' => 1.25];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Get form data
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
    
    $error = '';
    if (empty($first_name) || empty($last_name) || empty($position) || empty($email) || empty($phone)) {
        $error = "Please fill in all required fields.";
    } else {
        // Generate employee ID
        $employee_id = 'EMP' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        $pdo->beginTransaction();
        try {
            // Insert employee (including emergency contact)
            $sql = "INSERT INTO employees (employee_id, first_name, last_name, position, birth_date, age, address, email, phone,
                                           emergency_contact_name, emergency_contact_phone, emergency_contact_relationship) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $employee_id, $first_name, $last_name, $position, $birth_date, $age,
                $address, $email, $phone,
                $emergency_name, $emergency_phone, $emergency_relationship
            ]);
            
            // Get rate from position_rates
            $rate = isset($position_rates[$position]) ? $position_rates[$position] : $default_rate;
            
            // Insert payroll settings
            $settings_sql = "INSERT INTO employee_payroll_settings 
                            (employee_id, hourly_rate, work_hours_per_day, work_days_per_month, 
                             philhealth_rate, sss_rate, pagibig_rate, overtime_rate) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
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

            // ==========================================
            // AUTO-GENERATE QR CODE
            // ==========================================
            $qrDir = '../qrcodes/';
            // Create directory if it doesn't exist
            if (!is_dir($qrDir)) {
                mkdir($qrDir, 0777, true);
            }
            
            $qrFile = $qrDir . 'qr_' . $employee_id . '.png';
            $qrData = $employee_id; // The data inside the QR code is just the Employee ID

            // Method 1: Use local phpqrcode library if you have it installed
            if (file_exists('phpqrcode/qrlib.php')) {
                require_once 'phpqrcode/qrlib.php';
                QRcode::png($qrData, $qrFile, QR_ECLEVEL_L, 10);
            } 
            // Method 2: Fallback to a free QR API (Requires Internet)
            else {
                $apiUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($qrData);
                $qrImage = @file_get_contents($apiUrl);
                if ($qrImage !== false) {
                    file_put_contents($qrFile, $qrImage);
                }
            }
            // ==========================================
            
            // Redirect to employee list after success
            header("Location: employees.php?success=Employee added and QR code generated successfully!");
            exit;
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Get positions for dropdown
$positions = $pdo->query("SELECT * FROM positions ORDER BY position_name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Add Employee</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        /* YOUR EXISTING CSS HERE (No changes needed) */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; min-height: 100vh; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; }
        h1 { text-align: center; color: white; margin-bottom: 30px; font-size: 2.5em; text-shadow: 2px 2px 4px rgba(0,0,0,0.2); }
        .header-buttons { margin-bottom: 20px; }
        .btn { padding: 12px 25px; border: none; border-radius: 10px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.3s; text-decoration: none; display: inline-block; }
        .btn-back { background: rgba(255,255,255,0.2); color: white; border: 2px solid white; }
        .btn-back:hover { background: white; color: #667eea; }
        .btn-warning { background: #ffc107; color: #333; }
        .btn-warning:hover { background: #e0a800; transform: translateY(-2px); }
        .btn-primary { background: #28a745; color: white; }
        .btn-primary:hover { background: #218838; transform: translateY(-2px); }
        .btn-reset { background: #6c757d; color: white; }
        .btn-reset:hover { background: #5a6268; transform: translateY(-2px); }
        .form-card { background: white; border-radius: 20px; padding: 40px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
        .form-title { text-align: center; color: #333; font-size: 2em; margin-bottom: 30px; border-bottom: 3px solid #667eea; padding-bottom: 15px; }
        .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; }
        .full-width { grid-column: span 2; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; color: #555; font-weight: 500; font-size: 16px; }
        input, select, textarea { width: 100%; padding: 12px 15px; border: 2px solid #e0e0e0; border-radius: 10px; font-size: 16px; transition: all 0.3s; font-family: inherit; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1); }
        textarea { resize: vertical; min-height: 80px; }
        .rate-preview { background: #f8f9fa; border: 2px solid #667eea; border-radius: 10px; padding: 20px; margin: 20px 0; display: flex; flex-wrap: wrap; gap: 15px; justify-content: space-around; }
        .rate-item { text-align: center; min-width: 120px; }
        .rate-label { color: #666; font-size: 0.9em; margin-bottom: 5px; }
        .rate-value { color: #28a745; font-weight: 700; font-size: 1.2em; }
        .button-group { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 30px; }
        .alert { padding: 15px; border-radius: 10px; margin-bottom: 20px; font-weight: 500; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        @media (max-width: 768px) { .form-grid { grid-template-columns: 1fr; } .full-width { grid-column: span 1; } .form-card { padding: 20px; } h1 { font-size: 2em; } .rate-preview { flex-direction: column; align-items: center; } .button-group { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-buttons">
            <a href="employees.php" class="btn btn-back">← Back to Employees</a>
            <a href="manage_positions.php" class="btn btn-warning">⚙️ Manage Positions</a>
        </div>

        <h1>➕ Add New Employee</h1>

        <div class="form-card">
            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    <strong>❌ Error!</strong> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="employeeForm">
                <div class="form-grid">
                    <!-- Name Fields -->
                    <div class="form-group">
                        <label>First Name *</label>
                        <input type="text" name="first_name" placeholder="Enter first name" required 
                               value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label>Last Name *</label>
                        <input type="text" name="last_name" placeholder="Enter last name" required
                               value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>">
                    </div>

                    <!-- Position dropdown from database -->
                    <div class="form-group full-width">
                        <label>Position *</label>
                        <select name="position" id="positionSelect" required>
                            <option value="">Select Position</option>
                            <?php foreach ($positions as $pos): ?>
                                <option value="<?php echo htmlspecialchars($pos['position_name']); ?>"
                                    data-rate="<?php echo $pos['hourly_rate']; ?>"
                                    <?php echo (isset($_POST['position']) && $_POST['position'] == $pos['position_name']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($pos['position_name']); ?>
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
                    <div class="full-width" id="ratePreviewContainer" style="display:none;">
                        <div class="rate-preview">
                            <div class="rate-item">
                                <div class="rate-label">Hourly Rate</div>
                                <div class="rate-value" id="previewHourlyRate">₱0.00</div>
                            </div>
                            <div class="rate-item">
                                <div class="rate-label">Daily (8hrs)</div>
                                <div class="rate-value" id="previewDailyRate">₱0.00</div>
                            </div>
                            <div class="rate-item">
                                <div class="rate-label">Monthly (22d)</div>
                                <div class="rate-value" id="previewMonthlyRate">₱0.00</div>
                            </div>
                            <div class="rate-item">
                                <div class="rate-label">Overtime Rate</div>
                                <div class="rate-value" id="previewOTRate">1.25x</div>
                            </div>
                        </div>
                    </div>

                    <!-- Birth Date & Age -->
                    <div class="form-group">
                        <label>Birth Date</label>
                        <input type="date" name="birth_date" id="birthDate" onchange="calculateAge()"
                               value="<?php echo isset($_POST['birth_date']) ? htmlspecialchars($_POST['birth_date']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label>Age</label>
                        <input type="number" name="age" id="age" readonly placeholder="Auto-calculated"
                               value="<?php echo isset($_POST['age']) ? htmlspecialchars($_POST['age']) : ''; ?>">
                    </div>

                    <!-- Address -->
                    <div class="form-group full-width">
                        <label>Complete Address</label>
                        <textarea name="address" placeholder="Street/House No./Village/Barangay"><?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?></textarea>
                    </div>

                    <!-- Contact Information -->
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" name="email" placeholder="example@email.com" required
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label>Phone Number *</label>
                        <input type="tel" name="phone" placeholder="09*********" pattern="[0-9]{11}" required
                               value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
                        <small style="color: #999;">11 digits starting with 09</small>
                    </div>

                    <!-- Emergency Contact -->
                    <div class="form-group">
                        <label>Emergency Contact Name</label>
                        <input type="text" name="emergency_name" placeholder="Full name" 
                               value="<?php echo isset($_POST['emergency_name']) ? htmlspecialchars($_POST['emergency_name']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label>Emergency Contact Phone</label>
                        <input type="tel" name="emergency_phone" placeholder="09*********" pattern="[0-9]{11}"
                               value="<?php echo isset($_POST['emergency_phone']) ? htmlspecialchars($_POST['emergency_phone']) : ''; ?>">
                        <small style="color: #999;">11 digits</small>
                    </div>
                    <div class="form-group full-width">
                        <label>Relationship</label>
                        <input type="text" name="emergency_relationship" placeholder="e.g. Spouse, Parent, Sibling"
                               value="<?php echo isset($_POST['emergency_relationship']) ? htmlspecialchars($_POST['emergency_relationship']) : ''; ?>">
                    </div>
                </div>

                <div class="button-group">
                    <button type="submit" class="btn btn-primary">➕ Add Employee</button>
                    <button type="reset" class="btn btn-reset" onclick="return confirm('Reset all fields?')">🔄 Reset</button>
                </div>
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
                document.getElementById('previewDailyRate').textContent = '₱' + (rate.hourly_rate * 8).toFixed(2);
                document.getElementById('previewMonthlyRate').textContent = '₱' + (rate.hourly_rate * 8 * 22).toFixed(2);
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

        // Initialize preview if position is already selected (after form validation error)
        window.onload = function() {
            <?php if (isset($_POST['position'])): ?>
                updateRatePreview();
            <?php endif; ?>
        };
    </script>
</body>
</html>