<?php
require_once 'admin_auth.php'; 
require_once 'configa.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

// Get employee ID from URL
$employee_id = $_GET['employee_id'] ?? '';

if (empty($employee_id)) {
    header("Location: admin_dashboard.php");
    exit;
}

// Get employee details
$stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ?");
$stmt->execute([$employee_id]);
$employee = $stmt->fetch();

if (!$employee) {
    header("Location: admin_dashboard.php?error=notfound");
    exit;
}

// Get employee statistics
// Total attendance
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM attendance WHERE employee_id = ?");
$stmt->execute([$employee_id]);
$total_attendance = $stmt->fetch()['total'];

// Present this month
$first_day = date('Y-m-01');
$last_day = date('Y-m-t');
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM attendance WHERE employee_id = ? AND date BETWEEN ? AND ? AND time_in IS NOT NULL");
$stmt->execute([$employee_id, $first_day, $last_day]);
$present_this_month = $stmt->fetch()['total'];

// Total hours worked this month
$stmt = $pdo->prepare("SELECT SUM(hours_worked) as total FROM employee_work_schedules WHERE employee_id = ? AND work_date BETWEEN ? AND ? AND status = 'present'");
$stmt->execute([$employee_id, $first_day, $last_day]);
$hours_this_month = $stmt->fetch()['total'] ?? 0;

// Get recent attendance
$stmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? ORDER BY date DESC, time_in DESC LIMIT 10");
$stmt->execute([$employee_id]);
$recent_attendance = $stmt->fetchAll();

// Get work schedule for this month
$stmt = $pdo->prepare("SELECT * FROM employee_work_schedules WHERE employee_id = ? AND work_date BETWEEN ? AND ? ORDER BY work_date");
$stmt->execute([$employee_id, $first_day, $last_day]);
$work_schedule = $stmt->fetchAll();

// Get payroll settings
$stmt = $pdo->prepare("SELECT * FROM employee_payroll_settings WHERE employee_id = ?");
$stmt->execute([$employee_id]);
$payroll = $stmt->fetch();

// Get admin info
$admin_name = $_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? 'Admin';
?>

<!DOCTYPE html>
<html>
<head>
    <title>Employee Profile - <?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></title>
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
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Header */
        .header {
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

        .header h1 {
            color: #333;
            font-size: 1.8em;
        }

        .header-buttons {
            display: flex;
            gap: 10px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }

        .btn-back {
            background: #6c757d;
            color: white;
        }

        .btn-back:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }

        .btn-edit {
            background: #17a2b8;
            color: white;
        }

        .btn-edit:hover {
            background: #138496;
            transform: translateY(-2px);
        }

        .btn-primary {
            background: #28a745;
            color: white;
        }

        /* Profile Card */
        .profile-card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }

        .profile-header {
            display: flex;
            align-items: center;
            gap: 30px;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
            flex-wrap: wrap;
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 3em;
            font-weight: bold;
            text-transform: uppercase;
            overflow: hidden;
            border: 4px solid #667eea;
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-title {
            flex: 1;
        }

        .profile-title h2 {
            color: #333;
            font-size: 2.5em;
            margin-bottom: 10px;
        }

        .profile-title p {
            color: #666;
            font-size: 1.2em;
            margin-bottom: 10px;
        }

        .employee-id-badge {
            background: #667eea;
            color: white;
            padding: 8px 20px;
            border-radius: 30px;
            font-weight: 600;
            display: inline-block;
            font-size: 1.1em;
        }

        .profile-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .info-section {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 20px;
        }

        .section-title {
            font-size: 1.3em;
            font-weight: 600;
            color: #333;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #667eea;
        }

        .info-item {
            display: flex;
            margin-bottom: 12px;
            padding: 8px 0;
            border-bottom: 1px dashed #e0e0e0;
        }

        .info-label {
            width: 120px;
            color: #666;
            font-weight: 500;
        }

        .info-value {
            flex: 1;
            color: #333;
            font-weight: 600;
        }

        .info-value i {
            color: #28a745;
            margin-right: 5px;
        }

        .status-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9em;
        }

        .status-regular {
            background: #d4edda;
            color: #28a745;
        }

        .status-probationary {
            background: #fff3cd;
            color: #ffc107;
        }

        .status-contractual {
            background: #cce5ff;
            color: #17a2b8;
        }

        .status-part-time {
            background: #e0d4fc;
            color: #6f42c1;
        }

        /* Stats Cards */
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
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .stat-value {
            font-size: 2.5em;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #666;
            font-size: 1em;
        }

        /* Tables */
        .table-container {
            background: white;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        th {
            background: #667eea;
            color: white;
            padding: 12px;
            font-weight: 600;
            text-align: left;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid #e0e0e0;
        }

        tr:hover {
            background: #f8f9fa;
        }

        .status-working {
            background: #fff3cd;
            color: #856404;
            padding: 3px 8px;
            border-radius: 5px;
            font-size: 0.85em;
            font-weight: 600;
            display: inline-block;
        }

        .status-completed {
            background: #d4edda;
            color: #28a745;
            padding: 3px 8px;
            border-radius: 5px;
            font-size: 0.85em;
            font-weight: 600;
            display: inline-block;
        }

        .no-data {
            text-align: center;
            padding: 30px;
            color: #999;
            font-style: italic;
        }

        /* Payroll Card */
        .payroll-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
        }

        .payroll-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }

        .payroll-row:last-child {
            border-bottom: none;
        }

        .payroll-label {
            opacity: 0.9;
        }

        .payroll-value {
            font-weight: 600;
            font-size: 1.1em;
        }

        @media (max-width: 768px) {
            .profile-header {
                flex-direction: column;
                text-align: center;
            }
            
            .info-item {
                flex-direction: column;
            }
            
            .info-label {
                width: 100%;
                margin-bottom: 5px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>👤 Employee Profile</h1>
            <div class="header-buttons">
                <a href="admin_dashboard.php" class="btn btn-back">← Back to Dashboard</a>
            </div>
        </div>

        <!-- Profile Card -->
        <div class="profile-card">
            <div class="profile-header">
                <div class="profile-avatar">
                    <?php if ($employee['profile_image'] && file_exists('uploads/profiles/' . $employee['profile_image'])): ?>
                        <img src="uploads/profiles/<?php echo $employee['profile_image']; ?>" alt="Profile">
                    <?php else: ?>
                        <?php echo strtoupper(substr($employee['first_name'] ?? '', 0, 1) . substr($employee['last_name'] ?? '', 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <div class="profile-title">
                    <h2><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></h2>
                    <p><?php echo htmlspecialchars($employee['position'] ?? 'No Position'); ?> • <?php echo htmlspecialchars($employee['department'] ?? 'No Department'); ?></p>
                    <span class="employee-id-badge">ID: <?php echo htmlspecialchars($employee['employee_id']); ?></span>
                </div>
            </div>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $total_attendance; ?></div>
                    <div class="stat-label">Total Attendance</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $present_this_month; ?></div>
                    <div class="stat-label">Present This Month</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($hours_this_month, 1); ?></div>
                    <div class="stat-label">Hours This Month</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo date('M Y', strtotime($employee['hire_date'] ?? 'now')); ?></div>
                    <div class="stat-label">Hire Date</div>
                </div>
            </div>

            <!-- Information Grid -->
            <div class="profile-grid">
                <!-- Personal Information -->
                <div class="info-section">
                    <div class="section-title">📋 Personal Information</div>
                    <div class="info-item">
                        <span class="info-label">Full Name:</span>
                        <span class="info-value"><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Email:</span>
                        <span class="info-value"><i>✉️</i> <?php echo htmlspecialchars($employee['email'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Phone:</span>
                        <span class="info-value"><i>📞</i> <?php echo htmlspecialchars($employee['phone'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Address:</span>
                        <span class="info-value"><i>🏠</i> <?php echo htmlspecialchars($employee['address'] ?? 'N/A'); ?></span>
                    </div>
                </div>

                <!-- Employment Information -->
                <div class="info-section">
                    <div class="section-title">💼 Employment Information</div>
                    <div class="info-item">
                        <span class="info-label">Employee ID:</span>
                        <span class="info-value"><i>🆔</i> <?php echo htmlspecialchars($employee['employee_id']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Position:</span>
                        <span class="info-value"><i>👔</i> <?php echo htmlspecialchars($employee['position'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Department:</span>
                        <span class="info-value"><i>🏢</i> <?php echo htmlspecialchars($employee['department'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Hire Date:</span>
                        <span class="info-value"><i>📅</i> <?php echo $employee['hire_date'] ? date('F d, Y', strtotime($employee['hire_date'])) : 'N/A'; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Status:</span>
                        <span class="info-value">
                            <?php
                            $status_class = '';
                            $status_text = $employee['employment_status'] ?? 'regular';
                            if ($status_text == 'regular') $status_class = 'status-regular';
                            elseif ($status_text == 'probationary') $status_class = 'status-probationary';
                            elseif ($status_text == 'contractual') $status_class = 'status-contractual';
                            elseif ($status_text == 'part-time') $status_class = 'status-part-time';
                            ?>
                            <span class="status-badge <?php echo $status_class; ?>">
                                <?php echo ucfirst(str_replace('-', ' ', $status_text)); ?>
                            </span>
                        </span>
                    </div>
                </div>

                <!-- Emergency Contact -->
                <div class="info-section">
                    <div class="section-title">🚨 Emergency Contact</div>
                    <div class="info-item">
                        <span class="info-label">Contact Name:</span>
                        <span class="info-value"><i>👤</i> <?php echo htmlspecialchars($employee['emergency_contact'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Contact Phone:</span>
                        <span class="info-value"><i>📱</i> <?php echo htmlspecialchars($employee['emergency_phone'] ?? 'N/A'); ?></span>
                    </div>
                </div>

                <!-- Payroll Information (if available) -->
                <?php if ($payroll): ?>
                <div class="info-section">
                    <div class="section-title">💰 Payroll Information</div>
                    <div class="payroll-card">
                        <div class="payroll-row">
                            <span class="payroll-label">Hourly Rate:</span>
                            <span class="payroll-value">₱<?php echo number_format($payroll['hourly_rate'] ?? 0, 2); ?></span>
                        </div>
                        <div class="payroll-row">
                            <span class="payroll-label">Daily Rate:</span>
                            <span class="payroll-value">₱<?php echo number_format($payroll['daily_rate'] ?? 0, 2); ?></span>
                        </div>
                        <div class="payroll-row">
                            <span class="payroll-label">Monthly Salary:</span>
                            <span class="payroll-value">₱<?php echo number_format($payroll['monthly_salary'] ?? 0, 2); ?></span>
                        </div>
                        <div class="payroll-row">
                            <span class="payroll-label">Work Days/Month:</span>
                            <span class="payroll-value"><?php echo $payroll['work_days_per_month'] ?? 22; ?> days</span>
                        </div>
                        <div class="payroll-row">
                            <span class="payroll-label">Work Hours/Day:</span>
                            <span class="payroll-value"><?php echo $payroll['work_hours_per_day'] ?? 8; ?> hours</span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Attendance -->
        <div class="table-container">
            <div class="section-title" style="margin-bottom: 20px;">📊 Recent Attendance</div>
            
            <?php if (empty($recent_attendance)): ?>
                <div class="no-data">No attendance records found</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>Status</th>
                            <th>Hours</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_attendance as $att): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($att['date'])); ?></td>
                                <td><?php echo $att['time_in'] ? date('h:i A', strtotime($att['time_in'])) : '-'; ?></td>
                                <td><?php echo $att['time_out'] ? date('h:i A', strtotime($att['time_out'])) : '-'; ?></td>
                                <td>
                                    <?php if ($att['time_in'] && !$att['time_out']): ?>
                                        <span class="status-working">Working</span>
                                    <?php elseif ($att['time_in'] && $att['time_out']): ?>
                                        <span class="status-completed">Completed</span>
                                    <?php else: ?>
                                        <span class="status-badge" style="background: #e9ecef;">Absent</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    if ($att['time_in'] && $att['time_out']) {
                                        $start = strtotime($att['time_in']);
                                        $end = strtotime($att['time_out']);
                                        $hours = round(($end - $start) / 3600, 1);
                                        echo $hours . ' hrs';
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Work Schedule This Month -->
        <div class="table-container">
            <div class="section-title" style="margin-bottom: 20px;">📅 Work Schedule - <?php echo date('F Y'); ?></div>
            
            <?php if (empty($work_schedule)): ?>
                <div class="no-data">No work schedule for this month</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Day</th>
                            <th>Status</th>
                            <th>Hours Worked</th>
                            <th>Overtime</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($work_schedule as $work): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($work['work_date'])); ?></td>
                                <td><?php echo date('l', strtotime($work['work_date'])); ?></td>
                                <td>
                                    <?php if ($work['status'] == 'present'): ?>
                                        <span class="status-completed">Present</span>
                                    <?php elseif ($work['status'] == 'absent'): ?>
                                        <span class="status-badge" style="background: #f8d7da; color: #721c24;">Absent</span>
                                    <?php elseif ($work['status'] == 'late'): ?>
                                        <span class="status-working">Late</span>
                                    <?php elseif ($work['status'] == 'rest_day'): ?>
                                        <span class="status-badge" style="background: #cce5ff; color: #004085;">Rest Day</span>
                                    <?php else: ?>
                                        <span class="status-badge" style="background: #e9ecef;">No Record</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $work['hours_worked'] ? $work['hours_worked'] . ' hrs' : '-'; ?></td>
                                <td><?php echo $work['overtime_hours'] ? '+' . $work['overtime_hours'] . ' hrs' : '-'; ?></td>
                                <td><?php echo htmlspecialchars($work['notes'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Edit employee function
        function editEmployee(employeeId) {
            // Redirect to admin dashboard with edit modal
            window.location.href = 'admin_dashboard.php?edit=' + employeeId;
        }

        // Print function
        function printProfile() {
            window.print();
        }
    </script>

    <style media="print">
        body {
            background: white;
            padding: 0;
        }
        .btn, .header-buttons {
            display: none !important;
        }
        .container {
            max-width: 100%;
            padding: 20px;
        }
        .profile-card, .table-container {
            box-shadow: none;
            border: 1px solid #ddd;
        }
    </style>
</body>
</html>