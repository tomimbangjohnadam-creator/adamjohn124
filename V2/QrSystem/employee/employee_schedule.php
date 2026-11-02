<?php
session_start();
require_once 'config.php';

$employee_id = $_SESSION['employee_id'] ?? '';
$current_month = $_GET['month'] ?? date('Y-m');
$view_employee_id = $_GET['view_employee'] ?? $employee_id; // Default to self
$success_msg = '';
$error_msg = '';

// Handle Leave / Rest Day Request
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'submit_leave_request') {
    $submit_employee_id = $_POST['employee_id'] ?? $view_employee_id; // Get ID from form
    
    $req_date = $_POST['request_date'];
    $req_type = $_POST['request_type'];
    $req_reason = trim($_POST['reason']);
    
    if ($req_date && $req_type && !empty($submit_employee_id)) {
        $stmt = $pdo->prepare("INSERT INTO leave_requests (employee_id, request_date, request_type, reason) VALUES (?, ?, ?, ?)");
        if ($stmt->execute([$submit_employee_id, $req_date, $req_type, $req_reason])) {
            $success_msg = "Your request has been submitted for admin approval.";
        } else {
            $error_msg = "Failed to submit request. Please try again.";
        }
    } else {
        $error_msg = "Error: Missing Employee ID or required fields.";
    }
}

// Get current logged-in employee info
$emp_stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ?");
$emp_stmt->execute([$employee_id]);
$current_employee = $emp_stmt->fetch();

// Get all employees for dropdown (removed status column)
$employees = $pdo->query("SELECT employee_id, first_name, last_name, position FROM employees ORDER BY first_name")->fetchAll();

// Get the employee being viewed
$view_stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ?");
$view_stmt->execute([$view_employee_id]);
$view_employee = $view_stmt->fetch();

// If invalid employee ID, default to current user
if (!$view_employee && $current_employee) {
    $view_employee_id = $employee_id;
    $view_employee = $current_employee;
}

// Get employee rest days settings for viewed employee
$rest_days = [];
$custom_rest_dates = [];
if ($view_employee) {
    $rest_days_query = $pdo->prepare("SELECT * FROM employee_rest_days WHERE employee_id = ? ORDER BY 
                                      CASE 
                                        WHEN is_recurring = 1 THEN day_of_week 
                                        ELSE custom_date 
                                      END");
    $rest_days_query->execute([$view_employee_id]);
    while ($row = $rest_days_query->fetch()) {
        if ($row['is_recurring']) {
            $rest_days[$row['day_of_week']] = $row;
        } else {
            $custom_rest_dates[] = $row;
        }
    }
}

// Get work schedule for the viewed employee
$schedule = [];
$is_rest_day = [];

$start_date = $current_month . '-01';
$end_date = date('Y-m-t', strtotime($current_month));

if ($view_employee) {
    $stmt = $pdo->prepare("SELECT ws.*, a.time_in, a.time_out 
                           FROM employee_work_schedules ws
                           LEFT JOIN attendance a ON ws.employee_id = a.employee_id AND ws.work_date = a.date
                           WHERE ws.employee_id = ? AND ws.work_date BETWEEN ? AND ? 
                           ORDER BY ws.work_date");
    $stmt->execute([$view_employee_id, $start_date, $end_date]);

    while ($row = $stmt->fetch()) {
        $schedule[$row['work_date']] = $row;
    }

    // Get all rest days for viewed employee
    $rest_query = $pdo->prepare("SELECT * FROM employee_rest_days WHERE employee_id = ?");
    $rest_query->execute([$view_employee_id]);
    while ($row = $rest_query->fetch()) {
        if ($row['custom_date']) {
            $is_rest_day[$row['custom_date']] = true;
        }
    }
}

// ==========================================
// Get holidays for the current month
// ==========================================
$holidays = [];
$holiday_query = $pdo->prepare("SELECT * FROM holidays WHERE holiday_date BETWEEN ? AND ?");
$holiday_query->execute([$start_date, $end_date]);
while ($row = $holiday_query->fetch()) {
    $holidays[$row['holiday_date']] = $row;
}
// ==========================================

// Generate calendar days
$days_in_month = date('t', strtotime($current_month));
$first_day = date('N', strtotime($current_month . '-01'));

// Days of week
$days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

// Calculate totals for viewed employee
$total_work_days = 0;
$total_hours = 0;
$total_overtime = 0;
$total_rest_days = 0;
$total_late = 0;
$total_pending_days = 0;

foreach ($schedule as $date => $day) {
    $day_of_week = date('l', strtotime($date));
    $is_rest = isset($is_rest_day[$date]) || isset($rest_days[$day_of_week]);
    
    // NEW LOGIC: Determine status based on scheduled time and actual scan time
    $sched_in = $day['scheduled_time_in'] ?? null;
    $sched_out = $day['scheduled_time_out'] ?? null;
    $actual_in = $day['time_in'] ?? null;
    $actual_out = $day['time_out'] ?? null;

    if ($is_rest) {
        $status = 'rest_day';
    } elseif ($sched_in) {
        $sched_in_ts = strtotime($sched_in);
        $actual_in_ts = $actual_in ? strtotime($actual_in) : null;

        if ($actual_in_ts) {
            // Grace period of 15 minutes (900 seconds)
            if ($actual_in_ts > ($sched_in_ts + 900)) {
                $status = 'late';
            } else {
                $status = 'present';
            }
        } else {
            $date_ts = strtotime($date . ' 23:59:59');
            if ($date_ts < time()) {
                $status = 'absent';
            } else {
                $status = 'pending'; // Not scanned yet
            }
        }
    } else {
        $status = $day['status'] ?? '';
    }
    
    // Update the schedule array with the calculated status for the HTML loop
    $schedule[$date]['status'] = $status;
    // END NEW LOGIC

    if ($status == 'present' || $status == 'late') {
        $total_work_days++;
        $total_hours += $day['hours_worked'];
        
        // Calculate overtime (hours beyond 8 hours)
        if ($day['hours_worked'] > 8) {
            $total_overtime += ($day['hours_worked'] - 8);
        }
        
        // Add any manually entered overtime
        $total_overtime += $day['overtime_hours'];
        
        if ($status == 'late') {
            $total_late++;
        }
        
    } elseif ($status == 'rest_day') {
        $total_rest_days++;
    } elseif ($status == 'pending') {
        $total_pending_days++;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>View Schedule</title>
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
        
        /* Filter Section */
        .filter-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        
        .filter-title {
            font-size: 1.2em;
            font-weight: 600;
            color: #333;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #667eea;
            display: flex;
            justify-content: space-between; /* Pushes items to opposite ends */
            align-items: center;
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: 2fr 1fr auto;
            gap: 15px;
            align-items: end;
        }
        
        select, input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
        }
        
        select:focus, input:focus {
            outline: none;
            border-color: #667eea;
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
        
        .btn-dashboard {
            background: #667eea;
            color: white;
        }
        
        .btn-dashboard:hover {
            background: #5a67d8;
            transform: translateY(-2px);
        }
        
        .btn-filter {
            background: #28a745;
            color: white;
            border: none;
        }
        
        .btn-filter:hover {
            background: #218838;
        }
        
        /* Welcome Card */
        .welcome-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .welcome-text h2 {
            color: #333;
            font-size: 1.5em;
            margin-bottom: 10px;
        }
        
        .welcome-text p {
            color: #666;
        }
        
        .date-display {
            background: #f8f9fa;
            padding: 15px 25px;
            border-radius: 10px;
            text-align: center;
        }
        
        .date-display .day {
            font-size: 2em;
            font-weight: 700;
            color: #667eea;
        }
        
        .date-display .month-year {
            color: #666;
            margin-top: 5px;
        }
        
        /* Employee Info Card */
        .employee-info-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        
        .employee-name-large {
            font-size: 2.2em;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .employee-position-large {
            font-size: 1.2em;
            opacity: 0.9;
            margin-bottom: 5px;
        }
        
        .employee-id-large {
            font-size: 1.1em;
            opacity: 0.8;
        }
        
        .viewing-badge {
            background: rgba(255,255,255,0.2);
            padding: 8px 15px;
            border-radius: 30px;
            font-size: 0.9em;
            display: inline-flex;
            align-items: center;
            gap: 5px;
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
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-icon {
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        
        .stat-value {
            font-size: 1.8em;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.9em;
        }
        
        /* Calendar */
        .calendar {
            background: white;
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            margin-bottom: 30px;
        }
        
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .month-nav {
            display: flex;
            gap: 10px;
        }
        
        .month-nav button {
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            background: #667eea;
            color: white;
            cursor: pointer;
        }
        
        .month-nav button:hover {
            background: #5a67d8;
        }
        
        .weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            text-align: center;
            font-weight: 600;
            color: #667eea;
            margin-bottom: 10px;
        }
        
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
        }
        
        .calendar-day {
            min-height: 110px; 
            padding: 10px;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            background: #f8f9fa;
            position: relative;
        }
        
        .calendar-day.present {
            background: #d4edda;
            border-color: #28a745;
        }
        
        .calendar-day.rest-day {
            background: #fff3cd;
            border-color: #ffc107;
        }
        
        .calendar-day.absent {
            background: #f8d7da;
            border-color: #dc3545;
        }
        
        .calendar-day.late {
            background: #fff3cd;
            border-color: #ffc107;
        }
        .calendar-day.pending {
            background: #e2e3e5;
            border-color: #6c757d;
        }
        
        .calendar-day.ot {
            border: 2px solid #fd7e14;
        }

        /* ==========================================
           NEW: Holiday Styles (Matching Admin)
           ========================================== */
        .calendar-day.holiday {
            border: 3px solid #dc3545; /* Regular Holiday */
        }
        .calendar-day.holiday.special-holiday {
            border-color: #ffc107; /* Special Holiday */
        }
        .holiday-badge {
            position: absolute;
            top: 2px;
            right: 2px;
            font-size: 0.8em;
            background: transparent;
        }
        .holiday-label {
            font-size: 0.7em; 
            color: #dc3545; 
            font-weight: 600; 
            margin-bottom: 3px;
        }
        /* ========================================== */
        
        .day-number {
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
            display: flex;
            justify-content: space-between;
        }
        
        .scanned-badge {
            background: #28a745;
            color: white;
            font-size: 0.7em;
            padding: 2px 5px;
            border-radius: 10px;
            display: inline-block;
        }
        
        .rest-day-badge {
            background: #ffc107;
            color: #856404;
            font-size: 0.75em;
            padding: 2px 5px;
            border-radius: 10px;
            display: inline-block;
        }
        
        .day-status {
            font-size: 0.85em;
            padding: 3px 5px;
            border-radius: 5px;
            margin-bottom: 3px;
        }
        
        .status-present { background: #d4edda; color: #155724; }
        .status-absent { background: #f8d7da; color: #721c24; }
        .status-rest_day { background: #cce5ff; color: #004085; }
        .status-late { background: #fff3cd; color: #856404; }
        .status-pending { background: #e2e3e5; color: #383d41; }
        
        .hours-worked {
            font-size: 0.9em;
            color: #333;
            font-weight: 500;
        }
        
        .time-display {
            font-size: 0.8em;
            color: #666;
            margin-top: 2px;
        }
        
        .overtime {
            color: #fd7e14;
            font-weight: 600;
            font-size: 0.85em;
            background: #fff3cd;
            padding: 2px 5px;
            border-radius: 10px;
            display: inline-block;
            margin-top: 2px;
        }
        
        @media (max-width: 768px) {
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .calendar-day {
                min-height: 80px;
                font-size: 0.9em;
            }
            
            .employee-info-card {
                flex-direction: column;
                text-align: center;
            }
        }
        
        /* Modal Styles */
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
        }
        .modal.show { display: flex; }
        .modal-content {
            background: white;
            border-radius: 20px;
            padding: 30px;
            max-width: 450px;
            width: 90%;
        }
    </style>
</head>
<body>
    <div class="container">
        
        <!-- Filter Section -->
        <div class="filter-card">
            <div class="filter-title">
                <span>🔍 View Employee Schedule</span>
                <a href="dashboard.php" class="btn btn-dashboard" style="padding: 8px 15px; font-size: 14px;">← Dashboard</a>
            </div>
            <form method="GET" action="employee_schedule.php" class="filter-grid">
                
                <!-- Plain Text Input for Employee ID (No Dropdown) -->
                <input type="text" name="view_employee" id="employeeSelect" 
                       placeholder="Enter Employee ID (e.g. EMP3346)..." 
                       value="<?php echo htmlspecialchars($view_employee_id); ?>" required autocomplete="off">
                
                <input type="month" name="month" id="monthSelect" value="<?php echo $current_month; ?>">
                
                <button type="submit" class="btn btn-filter">View Schedule</button>
            </form>
        </div>

        <?php if ($view_employee): ?>
            <!-- Employee Info Card -->
            <div class="employee-info-card">
                <div>
                    <div class="employee-name-large">
                        <?php echo htmlspecialchars($view_employee['first_name'] . ' ' . $view_employee['last_name']); ?>
                        <?php if ($view_employee_id != $employee_id): ?>
                            <span class="viewing-badge">👁️ Viewing</span>
                        <?php endif; ?>
                    </div>
                    <div class="employee-position-large"><?php echo htmlspecialchars($view_employee['position'] ?? 'No Position'); ?></div>
                    <div class="employee-id-large">ID: <?php echo htmlspecialchars($view_employee['employee_id']); ?></div>
                </div>
                
                <!-- Request Leave Button -->
                <div>
                    <button onclick="showLeaveRequestModal()" class="btn btn-primary" style="background:white; color:#28a745; font-weight:bold; box-shadow: 0 4px 15px rgba(0,0,0,0.2);">
                        📅 Request Leave / Rest Day
                    </button>
                </div>
                
            </div>

            <!-- Welcome Card -->
            <div class="welcome-card">
                <div class="welcome-text">
                    <h2>📅 Work Schedule</h2>
                    <p>Viewing attendance records for <?php echo htmlspecialchars($view_employee['first_name'] . ' ' . $view_employee['last_name']); ?></p>
                </div>
                <div class="date-display">
                    <div class="day"><?php echo date('d'); ?></div>
                    <div class="month-year"><?php echo date('F Y'); ?></div>
                </div>
            </div>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">✅</div>
                    <div class="stat-value"><?php echo $total_work_days; ?></div>
                    <div class="stat-label">Days Present</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">⏱️</div>
                    <div class="stat-value"><?php echo number_format($total_hours, 1); ?></div>
                    <div class="stat-label">Total Hours</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">➕</div>
                    <div class="stat-value"><?php echo number_format($total_overtime, 1); ?></div>
                    <div class="stat-label">Overtime Hours</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">🛌</div>
                    <div class="stat-value"><?php echo $total_rest_days; ?></div>
                    <div class="stat-label">Rest Days</div>
                </div>
                <!-- Late and Pending Stats -->
                <div class="stat-card">
                    <div class="stat-icon">⚠️</div>
                    <div class="stat-value" style="color: #ffc107;"><?php echo $total_late; ?></div>
                    <div class="stat-label">Days Late</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">⏳</div>
                    <div class="stat-value" style="color: #6c757d;"><?php echo $total_pending_days; ?></div>
                    <div class="stat-label">Pending Days</div>
                </div>
            </div>

            <!-- Calendar -->
            <div class="calendar">
                <div class="calendar-header">
                    <h2><?php echo date('F Y', strtotime($current_month . '-01')); ?></h2>
                    <div class="month-nav">
                        <button onclick="prevMonth()">←</button>
                        <button onclick="nextMonth()">→</button>
                    </div>
                </div>

                <div class="weekdays">
                    <div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div><div>Sun</div>
                </div>

                <div class="calendar-grid">
                    <?php for ($i = 1; $i < $first_day; $i++): ?>
                        <div class="calendar-day" style="background: transparent; border: none;"></div>
                    <?php endfor; ?>

                    <?php for ($day = 1; $day <= $days_in_month; $day++): 
                        $date = sprintf('%s-%02d', $current_month, $day);
                        $day_of_week = date('l', strtotime($date));
                        $day_schedule = $schedule[$date] ?? null;
                        $status = $day_schedule['status'] ?? '';
                        $hours = $day_schedule['hours_worked'] ?? 0;
                        $overtime = $day_schedule['overtime_hours'] ?? 0;
                        $time_in = $day_schedule['time_in'] ?? ''; // Actual
                        $time_out = $day_schedule['time_out'] ?? ''; // Actual
                        
                        // Fetch scheduled times from schedule maker
                        $scheduled_in = $day_schedule['scheduled_time_in'] ?? '';
                        $scheduled_out = $day_schedule['scheduled_time_out'] ?? '';
                        
                        // Calculate overtime from hours worked if > 8
                        if ($hours > 8) {
                            $overtime = $hours - 8;
                        }
                        
                        // Check if it's a rest day
                        $is_rest = isset($is_rest_day[$date]) || isset($rest_days[$day_of_week]);
                        
                        // Check if it's a holiday (Connected to Admin)
                        $is_holiday = isset($holidays[$date]);
                        $holiday_info = $holidays[$date] ?? null;
                        $holiday_type = $is_holiday ? $holiday_info['holiday_type'] : '';
                        
                        // Determine day class
                        $day_class = '';
                        if ($status == 'present') $day_class = 'present';
                        elseif ($status == 'absent') $day_class = 'absent';
                        elseif ($status == 'late') $day_class = 'late';
                        elseif ($status == 'pending') $day_class = 'pending';
                        elseif ($is_rest || $status == 'rest_day') $day_class = 'rest-day';
                        
                        // Add holiday class (Matching Admin)
                        if ($is_holiday) {
                            $day_class .= ' holiday';
                            if ($holiday_type == 'special') {
                                $day_class .= ' special-holiday';
                            }
                        }
                        
                        if ($overtime > 0) $day_class .= ' ot';
                    ?>
                        <div class="calendar-day <?php echo $day_class; ?>">
                            
                            <!-- Holiday Badge Emoji (Matching Admin) -->
                            <?php if ($is_holiday): ?>
                                <span class="holiday-badge">
                                    <?php echo $holiday_type == 'regular' ? '🔴' : '🟡'; ?>
                                </span>
                            <?php endif; ?>
                            
                            <div class="day-number">
                                <?php echo $day; ?>
                                <?php if ($time_in): ?>
                                    <span class="scanned-badge">QR</span>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Holiday Label (Matching Admin) -->
                            <?php if ($is_holiday): ?>
                                <div class="holiday-label">
                                    🎉 <?php echo htmlspecialchars($holiday_info['holiday_name']); ?>
                                    <?php if ($is_rest): ?>
                                        <span style="color: #6c757d;">(Rest Day - No Pay)</span>
                                    <?php elseif ($status == 'present' || $status == 'late'): ?>
                                        <span style="color: #28a745;"><?php echo $holiday_type == 'regular' ? '(Double Pay ✅)' : '(+30% ✅)'; ?></span>
                                    <?php else: ?>
                                        <span style="color: #6c757d;">(No Work - No Pay)</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($is_rest && !$day_schedule && !$is_holiday): ?>
                                <div class="rest-day-badge">🛌 Rest Day</div>
                            <?php endif; ?>
                            
                            <?php if ($day_schedule): ?>
                                <div class="day-status status-<?php echo $status; ?>">
                                    <?php 
                                    if ($status == 'present') echo '✅ Present';
                                    elseif ($status == 'absent') echo '❌ Absent';
                                    elseif ($status == 'late') echo '⚠️ Late';
                                    elseif ($status == 'rest_day') echo '🛌 Rest Day';
                                    elseif ($status == 'pending') echo '⏳ Not Scanned';
                                    ?>
                                </div>
                                
                                <!-- Display Scheduled Times -->
                                <?php if ($scheduled_in): ?>
                                    <div class="time-display" style="color: #007bff; font-weight: 600;">🕐 Sched IN: <?php echo date('h:i A', strtotime($scheduled_in)); ?></div>
                                <?php endif; ?>
                                <?php if ($scheduled_out): ?>
                                    <div class="time-display" style="color: #007bff; font-weight: 600;">🕐 Sched OUT: <?php echo date('h:i A', strtotime($scheduled_out)); ?></div>
                                <?php endif; ?>
                                
                                <!-- Display actual scan times -->
                                <?php if ($time_in): ?>
                                    <div class="time-display" style="color: #28a745;">⬇️ Actual IN: <?php echo date('h:i A', strtotime($time_in)); ?></div>
                                <?php endif; ?>
                                
                                <?php if ($time_out): ?>
                                    <div class="time-display" style="color: #28a745;">⬆️ Actual OUT: <?php echo date('h:i A', strtotime($time_out)); ?></div>
                                <?php endif; ?>
                                
                                <?php if ($hours > 0): ?>
                                    <div class="hours-worked">⏱️ <?php echo $hours; ?> hrs</div>
                                <?php endif; ?>
                                
                                <?php if ($overtime > 0): ?>
                                    <div class="overtime">➕ OT: <?php echo number_format($overtime, 1); ?> hrs</div>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if ($is_holiday): ?>
                                    <!-- Holiday label already shown above -->
                                <?php elseif (!$is_rest && strtotime($date) <= time()): ?>
                                    <div style="color: #dc3545; font-size: 0.8em; margin-top: 5px;">No scan</div>
                                <?php elseif (strtotime($date) > time()): ?>
                                    <div style="color: #999; font-size: 0.8em; margin-top: 5px;">Upcoming</div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
            
            <!-- Leave Request Modal -->
            <div id="leaveRequestModal" class="modal">
                <div class="modal-content">
                    <h3 style="margin-bottom:20px; color:#333; text-align:center;">📅 Request Day Off / Rest Day</h3>
                    
                    <?php if(!empty($success_msg)): ?>
                        <div style="background:#d4edda; color:#155724; padding:10px; border-radius:5px; margin-bottom:15px; text-align:center;"><?php echo $success_msg; ?></div>
                    <?php endif; ?>
                    <?php if(!empty($error_msg)): ?>
                        <div style="background:#f8d7da; color:#721c24; padding:10px; border-radius:5px; margin-bottom:15px; text-align:center;"><?php echo $error_msg; ?></div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="submit_leave_request">
                        <input type="hidden" name="employee_id" value="<?php echo htmlspecialchars($view_employee_id); ?>">
                        
                        <!-- Employee Info Display -->
                        <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 5px solid #667eea; text-align: left;">
                            <div style="font-size: 0.85em; color: #666; margin-bottom: 2px;">Requesting For:</div>
                            <div style="font-weight: bold; color: #333; font-size: 1.1em;">
                                <?php echo htmlspecialchars($view_employee['first_name'] . ' ' . $view_employee['last_name']); ?>
                            </div>
                            <div style="font-size: 0.85em; color: #667eea; font-weight: 600;">
                                Employee ID: <?php echo htmlspecialchars($view_employee['employee_id']); ?>
                            </div>
                        </div>
                        <!-- END EMPLOYEE INFO -->

                        <div class="form-group" style="margin-bottom:15px; text-align: left;">
                            <label style="display:block; margin-bottom:5px; font-weight:600;">Date Requested</label>
                            <input type="date" name="request_date" required style="width:100%; padding:10px; border:2px solid #e0e0e0; border-radius:8px;">
                        </div>
                        <div class="form-group" style="margin-bottom:15px; text-align: left;">
                            <label style="display:block; margin-bottom:5px; font-weight:600;">Request Type</label>
                            <select name="request_type" required style="width:100%; padding:10px; border:2px solid #e0e0e0; border-radius:8px;">
                                <option value="day_off">Day Off (Leave)</option>
                                <option value="rest_day_change">Change Rest Day</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom:20px; text-align: left;">
                            <label style="display:block; margin-bottom:5px; font-weight:600;">Reason</label>
                            <textarea name="reason" rows="3" required placeholder="State your reason here..." style="width:100%; padding:10px; border:2px solid #e0e0e0; border-radius:8px;"></textarea>
                        </div>
                        <div style="display:flex; gap:10px;">
                            <button type="submit" class="btn btn-primary" style="flex:1; background:#28a745; color:white; border:none; padding:12px; border-radius:8px; cursor:pointer;">Submit Request</button>
                            <button type="button" onclick="document.getElementById('leaveRequestModal').classList.remove('show')" class="btn btn-reset" style="flex:1; background:#6c757d; color:white; border:none; padding:12px; border-radius:8px; cursor:pointer;">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
  
        <?php endif; ?>
        
    </div>

    <script>
        // Month navigation functions
        function changeMonth() {
            const month = document.getElementById('monthSelect').value;
            const employee = document.getElementById('employeeSelect').value;
            let url = 'employee_schedule.php?';
            if (month) url += 'month=' + month;
            if (employee) url += '&view_employee=' + encodeURIComponent(employee);
            window.location.href = url;
        }

        function prevMonth() {
            const current = document.getElementById('monthSelect').value;
            const [year, month] = current.split('-');
            let newMonth = parseInt(month) - 1;
            let newYear = parseInt(year);
            if (newMonth < 1) {
                newMonth = 12;
                newYear--;
            }
            const newDate = `${newYear}-${String(newMonth).padStart(2, '0')}`;
            document.getElementById('monthSelect').value = newDate;
            changeMonth();
        }

        function nextMonth() {
            const current = document.getElementById('monthSelect').value;
            const [year, month] = current.split('-');
            let newMonth = parseInt(month) + 1;
            let newYear = parseInt(year);
            if (newMonth > 12) {
                newMonth = 1;
                newYear++;
            }
            const newDate = `${newYear}-${String(newMonth).padStart(2, '0')}`;
            document.getElementById('monthSelect').value = newDate;
            changeMonth();
        }

        // Modal function
        function showLeaveRequestModal() {
            document.getElementById('leaveRequestModal').classList.add('show');
        }
    </script>
</body>
</html>