<?php
session_start();
require_once 'config.php';

// Define position-based rates
$position_rates = [
    'Developer' => ['hourly_rate' => 250, 'work_hours_per_day' => 8, 'work_days_per_month' => 22, 
                    'philhealth_rate' => 2.5, 'sss_rate' => 4.5, 'pagibig_rate' => 2, 'overtime_rate' => 1.25],
    'Senior Developer' => ['hourly_rate' => 350, 'work_hours_per_day' => 8, 'work_days_per_month' => 22,
                          'philhealth_rate' => 2.5, 'sss_rate' => 4.5, 'pagibig_rate' => 2, 'overtime_rate' => 1.25],
    'Manager' => ['hourly_rate' => 300, 'work_hours_per_day' => 8, 'work_days_per_month' => 22,
                 'philhealth_rate' => 2.5, 'sss_rate' => 4.5, 'pagibig_rate' => 2, 'overtime_rate' => 1.25],
    'Analyst' => ['hourly_rate' => 200, 'work_hours_per_day' => 8, 'work_days_per_month' => 22,
                 'philhealth_rate' => 2.5, 'sss_rate' => 4.5, 'pagibig_rate' => 2, 'overtime_rate' => 1.25],
    'Designer' => ['hourly_rate' => 180, 'work_hours_per_day' => 8, 'work_days_per_month' => 22,
                  'philhealth_rate' => 2.5, 'sss_rate' => 4.5, 'pagibig_rate' => 2, 'overtime_rate' => 1.25],
    'Tester' => ['hourly_rate' => 180, 'work_hours_per_day' => 8, 'work_days_per_month' => 22,
                'philhealth_rate' => 2.5, 'sss_rate' => 4.5, 'pagibig_rate' => 2, 'overtime_rate' => 1.25],
    'HR Staff' => ['hourly_rate' => 160, 'work_hours_per_day' => 8, 'work_days_per_month' => 22,
                  'philhealth_rate' => 2.5, 'sss_rate' => 4.5, 'pagibig_rate' => 2, 'overtime_rate' => 1.25],
    'Admin' => ['hourly_rate' => 200, 'work_hours_per_day' => 8, 'work_days_per_month' => 22,
               'philhealth_rate' => 2.5, 'sss_rate' => 4.5, 'pagibig_rate' => 2, 'overtime_rate' => 1.25],
    'Cashier' => ['hourly_rate' => 150, 'work_hours_per_day' => 8, 'work_days_per_month' => 22,
                 'philhealth_rate' => 2.5, 'sss_rate' => 4.5, 'pagibig_rate' => 2, 'overtime_rate' => 1.25],
    'Security' => ['hourly_rate' => 140, 'work_hours_per_day' => 8, 'work_days_per_month' => 22,
                  'philhealth_rate' => 2.5, 'sss_rate' => 4.5, 'pagibig_rate' => 2, 'overtime_rate' => 1.25],
    'Janitor' => ['hourly_rate' => 130, 'work_hours_per_day' => 8, 'work_days_per_month' => 22,
                 'philhealth_rate' => 2.5, 'sss_rate' => 4.5, 'pagibig_rate' => 2, 'overtime_rate' => 1.25],
    'Driver' => ['hourly_rate' => 135, 'work_hours_per_day' => 8, 'work_days_per_month' => 22,
                'philhealth_rate' => 2.5, 'sss_rate' => 4.5, 'pagibig_rate' => 2, 'overtime_rate' => 1.25]
];

// Default rate for unknown positions
$default_rate = ['hourly_rate' => 150, 'work_hours_per_day' => 8, 'work_days_per_month' => 22,
                'philhealth_rate' => 2.5, 'sss_rate' => 4.5, 'pagibig_rate' => 2, 'overtime_rate' => 1.25];

// Simple QR generator function using Google Charts API
function generateSimpleQR($data, $filename, $size = 200) {
    // Google Charts API URL for QR code generation
    $qrData = urlencode($data);
    $qrUrl = "https://chart.googleapis.com/chart?chs={$size}x{$size}&cht=qr&chl={$qrData}&choe=UTF-8";
    
    // Download QR code image
    $qrImage = @file_get_contents($qrUrl);
    if ($qrImage) {
        file_put_contents($filename, $qrImage);
        return true;
    } else {
        // Log error for debugging
        error_log("Failed to generate QR from URL: " . $qrUrl);
        return false;
    }
}

// Alternative function using a different API if Google fails
function generateQRWithQRServer($data, $filename, $size = 200) {
    $qrData = urlencode($data);
    $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$size}&data={$qrData}";
    
    $qrImage = @file_get_contents($qrUrl);
    if ($qrImage) {
        file_put_contents($filename, $qrImage);
        return true;
    }
    return false;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $first_name = $_POST['first_name'] ?? '';
    $last_name = $_POST['last_name'] ?? '';
    $position = $_POST['position'] ?? '';
    $birth_date = $_POST['birth_date'] ?? '';
    $age = $_POST['age'] ?? '';
    $address = $_POST['address'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    
    // Validate required fields
    if (empty($first_name) || empty($last_name) || empty($position) || empty($email) || empty($phone)) {
        $error = "Please fill in all required fields.";
    } else {
        // Generate unique employee ID
        $employee_id = 'EMP' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Start transaction
        $pdo->beginTransaction();
        
        try {
            // Insert into employees table
            $sql = "INSERT INTO employees (employee_id, first_name, last_name, position, birth_date, age, address, email, phone) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$employee_id, $first_name, $last_name, $position, $birth_date, $age, $address, $email, $phone]);
            
            // Get rate based on position
            $rate = isset($position_rates[$position]) ? $position_rates[$position] : $default_rate;
            
            // Insert into employee_payroll_settings with auto-rate
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
            
            // Create directory if not exists with proper permissions
            $qrDir = 'qrcodes/';
            if (!file_exists($qrDir)) {
                mkdir($qrDir, 0777, true);
            }
            
            // Make sure directory is writable
            if (!is_writable($qrDir)) {
                chmod($qrDir, 0777);
            }
            
            // QR Code data - what will be stored in the QR code
            $qrData = json_encode([
                'employee_id' => $employee_id,
                'name' => $first_name . ' ' . $last_name,
                'email' => $email
            ]);
            
            // Generate QR code filename
            $qrFile = $qrDir . $employee_id . '.png';
            
            // Try Google Charts API first, then fallback to QRServer
            $qrGenerated = generateSimpleQR($qrData, $qrFile, 200);
            
            if (!$qrGenerated) {
                // Try alternative API
                $qrGenerated = generateQRWithQRServer($qrData, $qrFile, 200);
            }
            
            if ($qrGenerated) {
                // Update employee with QR code path
                $updateSql = "UPDATE employees SET qr_code_path = ? WHERE employee_id = ?";
                $updateStmt = $pdo->prepare($updateSql);
                $updateStmt->execute([$qrFile, $employee_id]);
                
                // Commit transaction
                $pdo->commit();
                
                $success = "Employee registered successfully! Employee ID: " . $employee_id;
                $showQR = true;
            } else {
                // Rollback transaction if QR generation fails
                $pdo->rollBack();
                $error = "Failed to generate QR code. Please check your internet connection and try again.";
            }
            
        } catch (PDOException $e) {
            // Rollback transaction on error
            $pdo->rollBack();
            $error = "Database Error: " . $e->getMessage();
        }
    }
}

// Get positions from database or define them
$positions = array_keys($position_rates);
sort($positions); // Sort alphabetically
?>

<!DOCTYPE html>
<html>
<head>
    <title>Create QR Code - Attendance System</title>
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

        .back-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 2px solid white;
            padding: 10px 25px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }

        .back-btn:hover {
            background: white;
            color: #667eea;
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

        .rate-note {
            background: #e7f5ff;
            color: #004085;
            padding: 10px;
            border-radius: 5px;
            font-size: 0.9em;
            margin-top: 10px;
            border-left: 4px solid #667eea;
        }

        .button-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 30px;
        }

        .btn {
            padding: 15px;
            border: none;
            border-radius: 10px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-generate {
            background: #28a745;
            color: white;
        }

        .btn-generate:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
        }

        .btn-reset {
            background: #ffc107;
            color: #333;
        }

        .btn-reset:hover {
            background: #e0a800;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 193, 7, 0.3);
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

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        .qr-result {
            text-align: center;
            margin-top: 30px;
            padding: 30px;
            background: #f8f9fa;
            border-radius: 15px;
            border: 2px dashed #667eea;
        }

        .qr-result img {
            max-width: 250px;
            height: auto;
            margin: 20px 0;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .employee-id {
            font-size: 24px;
            font-weight: bold;
            color: #667eea;
            margin: 10px 0;
        }

        .download-btn {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 12px 30px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            margin-top: 15px;
            transition: all 0.3s;
        }

        .download-btn:hover {
            background: #5a67d8;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }

        .note {
            margin-top: 20px;
            padding: 15px;
            background: #e7f3ff;
            border-radius: 10px;
            color: #004085;
            font-size: 0.9em;
            border-left: 4px solid #667eea;
        }

        .note strong {
            color: #667eea;
        }

        .rate-badge {
            background: #28a745;
            color: white;
            padding: 3px 8px;
            border-radius: 15px;
            font-size: 0.8em;
            margin-left: 10px;
        }

        .payroll-summary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            border-radius: 10px;
            margin-top: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
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
            <a href="dashboard.php" class="back-btn">← Back to Dashboard</a>
        </div>

        <h1>Create QR Code for Employee</h1>

        <div class="form-card">
            <?php if (isset($success)): ?>
                <div class="alert alert-success">
                    <strong>✅ Success!</strong> <?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    <strong>❌ Error!</strong> <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <div class="alert alert-info">
                <strong>ℹ️ Auto-Rate System:</strong> Payroll rates are automatically set based on position. 
                You can modify these later in Payroll Settings.
            </div>

            <form method="POST" action="" id="qrForm">
                <div class="form-title">Employee Information</div>

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

                    <!-- Position with rate preview -->
                    <div class="form-group full-width">
                        <label>Position *
                        <select name="position" id="positionSelect" required onchange="updateRatePreview()">
                            <option value="">Select Position</option>
                            <?php foreach ($positions as $pos): ?>
                                <option value="<?php echo $pos; ?>" 
                                    data-rate="<?php echo isset($position_rates[$pos]) ? $position_rates[$pos]['hourly_rate'] : $default_rate['hourly_rate']; ?>"
                                    <?php echo (isset($_POST['position']) && $_POST['position'] == $pos) ? 'selected' : ''; ?>>
                                    <?php echo $pos; ?> - ₱<?php echo isset($position_rates[$pos]) ? number_format($position_rates[$pos]['hourly_rate'], 2) : number_format($default_rate['hourly_rate'], 2); ?>/hr
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Rate Preview -->
                    <div class="full-width" id="ratePreviewContainer" style="<?php echo isset($_POST['position']) ? 'display: block;' : 'display: none;'; ?>">
                        <div class="rate-preview">
                            <div class="rate-item">
                                <div class="rate-label">Hourly Rate</div>
                                <div class="rate-value" id="previewHourlyRate">₱0.00</div>
                            </div>
                            <div class="rate-item">
                                <div class="rate-label">Daily Rate (8hrs)</div>
                                <div class="rate-value" id="previewDailyRate">₱0.00</div>
                            </div>
                            <div class="rate-item">
                                <div class="rate-label">Monthly (22 days)</div>
                                <div class="rate-value" id="previewMonthlyRate">₱0.00</div>
                            </div>
                            <div class="rate-item">
                                <div class="rate-label">Overtime Rate</div>
                                <div class="rate-value" id="previewOTRate">1.25x</div>
                            </div>
                        </div>
                        <div class="rate-note">
                            <strong>📊 Payroll Settings Auto-configured:</strong> PhilHealth (2.5%), SSS (4.5%), Pag-IBIG (2%)
                        </div>
                    </div>

                    <!-- Birth Date and Age -->
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
                </div>

                <div class="button-group">
                    <button type="submit" class="btn btn-generate">
                        <span style="font-size: 1.2em;"></span> Generate QR Code
                    </button>
                    <button type="reset" class="btn btn-reset" onclick="return confirm('Reset all fields?')">
                        <span style="font-size: 1.2em;"></span> Reset Form
                    </button>
                </div>
            </form>

            <?php if (isset($showQR) && $showQR): ?>
                <div class="qr-result">
                    <h3>✅ QR Code Generated Successfully!</h3>
                    <div class="employee-id">📋 <?php echo $employee_id; ?></div>
                    <img src="<?php echo $qrFile . '?t=' . time(); ?>" alt="Employee QR Code">
                    <p style="color: #666; margin: 10px 0;">
                        <strong>Employee:</strong> <?php echo htmlspecialchars($first_name . ' ' . $last_name); ?><br>
                        <strong>Position:</strong> <?php echo htmlspecialchars($position); ?><br>
                        <strong>Email:</strong> <?php echo htmlspecialchars($email); ?>
                    </p>
                    
                    <!-- Payroll Summary -->
                    <?php 
                    $rate = isset($position_rates[$position]) ? $position_rates[$position] : $default_rate;
                    $daily_rate = $rate['hourly_rate'] * 8;
                    $monthly_rate = $daily_rate * 22;
                    ?>
                    <div class="payroll-summary">
                        <span><strong>💰 Payroll Rate:</strong> ₱<?php echo number_format($rate['hourly_rate'], 2); ?>/hr</span>
                        <span><strong>📅 Daily:</strong> ₱<?php echo number_format($daily_rate, 2); ?></span>
                        <span><strong>📊 Monthly:</strong> ₱<?php echo number_format($monthly_rate, 2); ?></span>
                    </div>
                    
                    <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; margin-top: 20px;">
                        <a href="<?php echo $qrFile; ?>" download class="download-btn">
                            ⬇️ Save QR Code
                        </a>
                        <a href="add_employee.php" class="download-btn" style="background: #28a745;">
                            ➕ Add Another Employee
                        </a>
                        <a href="employee_payroll_settings.php?employee_id=<?php echo $employee_id; ?>" class="download-btn" style="background: #17a2b8;">
                            ⚙️ Adjust Payroll
                        </a>
                    </div>
                    <p style="margin-top: 15px; color: #999; font-size: 14px; background: #f0f0f0; padding: 10px; border-radius: 5px;">
                        <strong>📋 Instructions:</strong><br>
                        1. Save this QR code<br>
                        2. Print or send to employee<br>
                        3. Employee will use this for attendance scanning<br>
                        4. Payroll rates are auto-configured based on position
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Position rate data
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
            const positionSelect = document.getElementById('positionSelect');
            const selectedPosition = positionSelect.value;
            const previewContainer = document.getElementById('ratePreviewContainer');
            
            if (selectedPosition && positionRates[selectedPosition]) {
                const rate = positionRates[selectedPosition];
                const hourlyRate = rate.hourly_rate;
                const dailyRate = hourlyRate * 8;
                const monthlyRate = dailyRate * 22;
                
                document.getElementById('previewHourlyRate').textContent = '₱' + hourlyRate.toFixed(2);
                document.getElementById('previewDailyRate').textContent = '₱' + dailyRate.toFixed(2);
                document.getElementById('previewMonthlyRate').textContent = '₱' + monthlyRate.toFixed(2);
                document.getElementById('previewOTRate').textContent = rate.overtime_rate + 'x';
                
                previewContainer.style.display = 'block';
            } else {
                previewContainer.style.display = 'none';
            }
        }

        // Real-time phone number validation
        document.querySelector('input[name="phone"]').addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
            if (this.value.length > 11) {
                this.value = this.value.slice(0, 11);
            }
        });

        // Form validation
        document.getElementById('qrForm').addEventListener('submit', function(e) {
            const phone = document.querySelector('input[name="phone"]').value;
            if (phone && phone.length !== 11) {
                e.preventDefault();
                alert('Please enter a valid 11-digit phone number');
            }
            
            const email = document.querySelector('input[name="email"]').value;
            if (email && !email.includes('@')) {
                e.preventDefault();
                alert('Please enter a valid email address');
            }
        });

        // Auto-capitalize first letters of name
        document.querySelector('input[name="first_name"]').addEventListener('input', function(e) {
            this.value = this.value.charAt(0).toUpperCase() + this.value.slice(1).toLowerCase();
        });
        
        document.querySelector('input[name="last_name"]').addEventListener('input', function(e) {
            this.value = this.value.charAt(0).toUpperCase() + this.value.slice(1).toLowerCase();
        });

        // Initialize rate preview if position is selected
        window.onload = function() {
            <?php if (isset($_POST['position'])): ?>
            updateRatePreview();
            <?php endif; ?>
        };
    </script>
</body>
</html>