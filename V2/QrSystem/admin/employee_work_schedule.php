<?php
require_once 'admin_auth.php'; 
require_once 'configa.php';

$current_month = $_GET['month'] ?? date('Y-m');
$selected_employee = $_GET['employee'] ?? '';
$message = '';
$message_type = '';

// Get all employees
$employees = $pdo->query("SELECT employee_id, first_name, last_name FROM employees ORDER BY first_name")->fetchAll();

// Get all holidays for the current month
$holidays = [];
$holiday_stmt = $pdo->prepare("SELECT * FROM holidays WHERE MONTH(holiday_date) = ? AND YEAR(holiday_date) = ? ORDER BY holiday_date");
$holiday_stmt->execute([date('m', strtotime($current_month)), date('Y', strtotime($current_month))]);
while ($row = $holiday_stmt->fetch()) {
    $holidays[$row['holiday_date']] = $row;
}

// Get employee rest days settings
$rest_days = [];
$custom_rest_dates = [];
if ($selected_employee) {
    $rest_days_query = $pdo->prepare("SELECT * FROM employee_rest_days WHERE employee_id = ? ORDER BY 
                                      CASE 
                                        WHEN is_recurring = 1 THEN day_of_week 
                                        ELSE custom_date 
                                      END");
    $rest_days_query->execute([$selected_employee]);
    while ($row = $rest_days_query->fetch()) {
        if ($row['is_recurring']) {
            $rest_days[$row['day_of_week']] = $row;
        } else {
            $custom_rest_dates[] = $row;
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $employee_id = $_POST['employee_id'] ?? '';
    $action = $_POST['action'] ?? '';
    
    // Holiday Management
    if ($action == 'add_holiday') {
        $holiday_date = $_POST['holiday_date'] ?? '';
        $holiday_name = $_POST['holiday_name'] ?? '';
        $holiday_type = $_POST['holiday_type'] ?? 'regular';
        $is_recurring = isset($_POST['is_recurring']) ? 1 : 0;
        
        if ($holiday_date && $holiday_name) {
            $check = $pdo->prepare("SELECT id, holiday_name FROM holidays WHERE holiday_date = ?");
            $check->execute([$holiday_date]);
            $existing = $check->fetch();
            
            if ($existing) {
                $message = "A holiday already exists on " . date('M d, Y', strtotime($holiday_date)) 
                         . " (" . htmlspecialchars($existing['holiday_name']) . "). Please remove it first or choose a different date.";
                $message_type = "error";
            } else {
                try {
                    $insert = $pdo->prepare("INSERT INTO holidays (holiday_date, holiday_name, holiday_type, is_recurring) VALUES (?, ?, ?, ?)");
                    $insert->execute([$holiday_date, $holiday_name, $holiday_type, $is_recurring]);
                    $message = "Holiday added successfully!";
                    $message_type = "success";
                } catch (PDOException $e) {
                    if ($e->getCode() == 23000) {
                        $message = "This holiday date already exists. Please choose a different date.";
                    } else {
                        $message = "Error adding holiday: " . $e->getMessage();
                    }
                    $message_type = "error";
                }
                
                // Refresh holidays
                $holidays = [];
                $holiday_stmt = $pdo->prepare("SELECT * FROM holidays WHERE MONTH(holiday_date) = ? AND YEAR(holiday_date) = ? ORDER BY holiday_date");
                $holiday_stmt->execute([date('m', strtotime($current_month)), date('Y', strtotime($current_month))]);
                while ($row = $holiday_stmt->fetch()) {
                    $holidays[$row['holiday_date']] = $row;
                }
            }
        } else {
            $message = "Please provide both a date and a holiday name.";
            $message_type = "error";
        }
    }
    
    if ($action == 'remove_holiday') {
        $holiday_id = $_POST['holiday_id'] ?? 0;
        $delete = $pdo->prepare("DELETE FROM holidays WHERE id = ?");
        $delete->execute([$holiday_id]);
        $message = "Holiday removed successfully!";
        $message_type = "success";
        
        // Refresh holidays
        $holidays = [];
        $holiday_stmt = $pdo->prepare("SELECT * FROM holidays WHERE MONTH(holiday_date) = ? AND YEAR(holiday_date) = ? ORDER BY holiday_date");
        $holiday_stmt->execute([date('m', strtotime($current_month)), date('Y', strtotime($current_month))]);
        while ($row = $holiday_stmt->fetch()) {
            $holidays[$row['holiday_date']] = $row;
        }
    }
    
    // Rest days management
    if ($action == 'save_rest_days') {
        $clear = $pdo->prepare("DELETE FROM employee_rest_days WHERE employee_id = ?");
        $clear->execute([$employee_id]);
        
        $days = $_POST['rest_days'] ?? [];
        $custom_dates = $_POST['custom_rest_dates'] ?? '';
        
        foreach ($days as $day) {
            $insert = $pdo->prepare("INSERT INTO employee_rest_days (employee_id, day_of_week, is_recurring) VALUES (?, ?, 1)");
            $insert->execute([$employee_id, $day]);
        }
        
        if (!empty($custom_dates)) {
            $dates = explode(',', $custom_dates);
            foreach ($dates as $date) {
                $date = trim($date);
                if (!empty($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    $insert = $pdo->prepare("INSERT INTO employee_rest_days (employee_id, custom_date, is_recurring) VALUES (?, ?, 0)");
                    $insert->execute([$employee_id, $date]);
                }
            }
        }
        
        $message = "Rest days saved successfully!";
        $message_type = "success";
        
        $rest_days = [];
        $custom_rest_dates = [];
        $rest_days_query = $pdo->prepare("SELECT * FROM employee_rest_days WHERE employee_id = ?");
        $rest_days_query->execute([$employee_id]);
        while ($row = $rest_days_query->fetch()) {
            if ($row['is_recurring']) {
                $rest_days[$row['day_of_week']] = $row;
            } else {
                $custom_rest_dates[] = $row;
            }
        }
    }
    
    if ($action == 'remove_rest_day') {
        $rest_day_id = $_POST['rest_day_id'] ?? 0;
        
        $get_rest = $pdo->prepare("SELECT * FROM employee_rest_days WHERE id = ?");
        $get_rest->execute([$rest_day_id]);
        $rest_day = $get_rest->fetch();
        
        if ($rest_day) {
            $delete = $pdo->prepare("DELETE FROM employee_rest_days WHERE id = ?");
            $delete->execute([$rest_day_id]);
            
            if (!$rest_day['is_recurring'] && $rest_day['custom_date']) {
                $update = $pdo->prepare("UPDATE employee_work_schedules SET status = NULL, notes = NULL 
                                         WHERE employee_id = ? AND work_date = ? AND status = 'rest_day'");
                $update->execute([$employee_id, $rest_day['custom_date']]);
            }
            
            $message = "Rest day removed successfully!";
            $message_type = "success";
        }
        
        $rest_days = [];
        $custom_rest_dates = [];
        $rest_days_query = $pdo->prepare("SELECT * FROM employee_rest_days WHERE employee_id = ?");
        $rest_days_query->execute([$employee_id]);
        while ($row = $rest_days_query->fetch()) {
            if ($row['is_recurring']) {
                $rest_days[$row['day_of_week']] = $row;
            } else {
                $custom_rest_dates[] = $row;
            }
        }
    }
    
    // Overtime Request Processing
    if ($action == 'request_overtime') {
        $work_date = $_POST['work_date'] ?? '';
        $overtime_hours = $_POST['overtime_hours'] ?? 0;
        $reason = $_POST['reason'] ?? '';
        
        if ($work_date && $overtime_hours > 0) {
            $check = $pdo->prepare("SELECT id FROM overtime_requests 
                                   WHERE employee_id = ? AND work_date = ? AND status IN ('pending', 'approved')");
            $check->execute([$employee_id, $work_date]);
            $existing = $check->fetch();
            
            if ($existing) {
                $message = "Overtime request already exists for this date.";
                $message_type = "error";
            } else {
                $insert = $pdo->prepare("INSERT INTO overtime_requests 
                                        (employee_id, work_date, hours, reason, status, requested_at) 
                                        VALUES (?, ?, ?, ?, 'pending', NOW())");
                $insert->execute([$employee_id, $work_date, $overtime_hours, $reason]);
                $message = "Overtime request submitted for approval!";
                $message_type = "success";
            }
        } else {
            $message = "Please enter valid hours and date.";
            $message_type = "error";
        }
    }
    
    if ($action == 'approve_overtime') {
        $request_id = $_POST['request_id'] ?? 0;
        $approved_hours = $_POST['approved_hours'] ?? 0;
        
        $get_request = $pdo->prepare("SELECT * FROM overtime_requests WHERE id = ? AND status = 'pending'");
        $get_request->execute([$request_id]);
        $request = $get_request->fetch();
        
        if ($request) {
            $update = $pdo->prepare("UPDATE overtime_requests 
                                    SET status = 'approved', 
                                        approved_at = NOW(),
                                        approved_hours = ?,
                                        approved_by = ?
                                    WHERE id = ?");
            $update->execute([$approved_hours, $_SESSION['user_id'] ?? 1, $request_id]);
            
            $check_schedule = $pdo->prepare("SELECT id, overtime_hours FROM employee_work_schedules 
                                            WHERE employee_id = ? AND work_date = ?");
            $check_schedule->execute([$request['employee_id'], $request['work_date']]);
            $schedule = $check_schedule->fetch();
            
            if ($schedule) {
                $update_schedule = $pdo->prepare("UPDATE employee_work_schedules 
                                                 SET overtime_hours = overtime_hours + ?,
                                                     notes = CONCAT(notes, ' | Overtime Approved: ', ?)
                                                 WHERE id = ?");
                $update_schedule->execute([$approved_hours, $request['reason'], $schedule['id']]);
            } else {
                $insert_schedule = $pdo->prepare("INSERT INTO employee_work_schedules 
                                                 (employee_id, work_date, overtime_hours, notes, status) 
                                                 VALUES (?, ?, ?, 'Overtime Approved: ' || ?, 'present')");
                $insert_schedule->execute([$request['employee_id'], $request['work_date'], $approved_hours, $request['reason']]);
            }
            
            $message = "Overtime approved successfully! Hours added to payroll.";
            $message_type = "success";
        } else {
            $message = "Overtime request not found or already processed.";
            $message_type = "error";
        }
    }
    
    if ($action == 'reject_overtime') {
        $request_id = $_POST['request_id'] ?? 0;
        $rejection_reason = $_POST['rejection_reason'] ?? 'No reason provided';
        
        $update = $pdo->prepare("UPDATE overtime_requests 
                                SET status = 'rejected', 
                                    rejection_reason = ?,
                                    rejected_at = NOW()
                                WHERE id = ? AND status = 'pending'");
        $update->execute([$rejection_reason, $request_id]);
        
        $message = "Overtime request rejected.";
        $message_type = "success";
    }
    
    // NEW: LEAVE REQUEST MANAGEMENT
    if ($action == 'approve_leave') {
        $request_id = $_POST['request_id'] ?? 0;
        $admin_note = $_POST['admin_note'] ?? 'Approved';
        
        $get_req = $pdo->prepare("SELECT * FROM leave_requests WHERE id = ? AND status = 'pending'");
        $get_req->execute([$request_id]);
        $request = $get_req->fetch();
        
        if ($request) {
            $update = $pdo->prepare("UPDATE leave_requests SET status = 'approved', admin_notes = ?, processed_at = NOW() WHERE id = ?");
            $update->execute([$admin_note, $request_id]);
            
            // Automatically add to employee_rest_days if it's a day off/rest day change
            if ($request['request_type'] == 'day_off' || $request['request_type'] == 'rest_day_change') {
                $check = $pdo->prepare("SELECT id FROM employee_rest_days WHERE employee_id = ? AND custom_date = ?");
                $check->execute([$request['employee_id'], $request['request_date']]);
                if (!$check->fetch()) {
                    $insert_rest = $pdo->prepare("INSERT INTO employee_rest_days (employee_id, custom_date, is_recurring) VALUES (?, ?, 0)");
                    $insert_rest->execute([$request['employee_id'], $request['request_date']]);
                }
            }
            $message = "Leave request approved successfully! It has been added to their rest days.";
            $message_type = "success";
        } else {
            $message = "Leave request not found or already processed.";
            $message_type = "error";
        }
    }
    
    if ($action == 'reject_leave') {
        $request_id = $_POST['request_id'] ?? 0;
        $admin_note = $_POST['admin_note'] ?? 'Rejected';
        
        $update = $pdo->prepare("UPDATE leave_requests SET status = 'rejected', admin_notes = ?, processed_at = NOW() WHERE id = ? AND status = 'pending'");
        $update->execute([$admin_note, $request_id]);
        $message = "Leave request rejected.";
        $message_type = "success";
    }
    
    if ($action == 'add_overtime') {
        $work_date = $_POST['work_date'] ?? '';
        $overtime_hours = $_POST['overtime_hours'] ?? 0;
        $notes = $_POST['notes'] ?? '';
        
        $check = $pdo->prepare("SELECT id, hours_worked FROM employee_work_schedules WHERE employee_id = ? AND work_date = ?");
        $check->execute([$employee_id, $work_date]);
        $existing = $check->fetch();
        
        if ($existing) {
            $update = $pdo->prepare("UPDATE employee_work_schedules SET overtime_hours = overtime_hours + ?, notes = CONCAT(notes, ' | ', ?) WHERE id = ?");
            $update->execute([$overtime_hours, $notes, $existing['id']]);
        } else {
            $insert = $pdo->prepare("INSERT INTO employee_work_schedules (employee_id, work_date, overtime_hours, notes, status) VALUES (?, ?, ?, ?, 'present')");
            $insert->execute([$employee_id, $work_date, $overtime_hours, $notes]);
        }
        
        $message = "Overtime hours added successfully!";
        $message_type = "success";
    }
    
    if ($action == 'mark_absent') {
        $work_date = $_POST['work_date'] ?? '';
        
        $insert = $pdo->prepare("INSERT INTO employee_work_schedules (employee_id, work_date, status, hours_worked) VALUES (?, ?, 'absent', 0) 
                                 ON DUPLICATE KEY UPDATE status = 'absent', hours_worked = 0, overtime_hours = 0");
        $insert->execute([$employee_id, $work_date]);
        
        $message = "Employee marked as absent!";
        $message_type = "success";
    }
    
    if ($action == 'mark_rest_day') {
        $work_date = $_POST['work_date'] ?? '';
        
        $day_of_week = date('l', strtotime($work_date));
        $check_rest = $pdo->prepare("SELECT id FROM employee_rest_days WHERE employee_id = ? AND day_of_week = ?");
        $check_rest->execute([$employee_id, $day_of_week]);
        
        if ($check_rest->fetch()) {
            $insert = $pdo->prepare("INSERT INTO employee_work_schedules (employee_id, work_date, status, hours_worked, notes) 
                                     VALUES (?, ?, 'rest_day', 0, 'Regular rest day') 
                                     ON DUPLICATE KEY UPDATE status = 'rest_day', hours_worked = 0, overtime_hours = 0, notes = 'Regular rest day'");
            $insert->execute([$employee_id, $work_date]);
        } else {
            $insert = $pdo->prepare("INSERT INTO employee_rest_days (employee_id, custom_date, is_recurring) VALUES (?, ?, 0)");
            $insert->execute([$employee_id, $work_date]);
            
            $insert = $pdo->prepare("INSERT INTO employee_work_schedules (employee_id, work_date, status, hours_worked, notes) 
                                     VALUES (?, ?, 'rest_day', 0, 'Custom rest day') 
                                     ON DUPLICATE KEY UPDATE status = 'rest_day', hours_worked = 0, overtime_hours = 0, notes = 'Custom rest day'");
            $insert->execute([$employee_id, $work_date]);
        }
        
        $message = "Day marked as rest day!";
        $message_type = "success";
        
        $rest_days = [];
        $custom_rest_dates = [];
        $rest_days_query = $pdo->prepare("SELECT * FROM employee_rest_days WHERE employee_id = ?");
        $rest_days_query->execute([$employee_id]);
        while ($row = $rest_days_query->fetch()) {
            if ($row['is_recurring']) {
                $rest_days[$row['day_of_week']] = $row;
            } else {
                $custom_rest_dates[] = $row;
            }
        }
    }
}

// Get work schedule for selected employee and month
$schedule = [];
$is_rest_day = [];
if ($selected_employee) {
    $start_date = $current_month . '-01';
    $end_date = date('Y-m-t', strtotime($current_month));
    
    $stmt = $pdo->prepare("SELECT ws.*, a.time_in, a.time_out 
                           FROM employee_work_schedules ws
                           LEFT JOIN attendance a ON ws.employee_id = a.employee_id AND ws.work_date = a.date
                           WHERE ws.employee_id = ? AND ws.work_date BETWEEN ? AND ? 
                           ORDER BY ws.work_date");
    $stmt->execute([$selected_employee, $start_date, $end_date]);
    
    while ($row = $stmt->fetch()) {
        $schedule[$row['work_date']] = $row;
    }
    
    // Get attendance records not in work_schedules
    $attendance_stmt = $pdo->prepare("SELECT a.*, e.first_name, e.last_name 
                                      FROM attendance a
                                      JOIN employees e ON a.employee_id = e.employee_id
                                      WHERE a.employee_id = ? AND a.date BETWEEN ? AND ?
                                      AND NOT EXISTS (SELECT 1 FROM employee_work_schedules ws WHERE ws.employee_id = a.employee_id AND ws.work_date = a.date)");
    $attendance_stmt->execute([$selected_employee, $start_date, $end_date]);
    
    while ($attendance = $attendance_stmt->fetch()) {
        $hours_worked = 0;
        if ($attendance['time_in'] && $attendance['time_out']) {
            $time_in = strtotime($attendance['time_in']);
            $time_out = strtotime($attendance['time_out']);
            $hours_worked = round(($time_out - $time_in) / 3600, 1);
        }
        
        $insert = $pdo->prepare("INSERT INTO employee_work_schedules 
                                 (employee_id, work_date, status, hours_worked, time_in, time_out, notes) 
                                 VALUES (?, ?, 'present', ?, ?, ?, 'Auto-synced from attendance')");
        $insert->execute([$selected_employee, $attendance['date'], $hours_worked, $attendance['time_in'], $attendance['time_out']]);
        
        $schedule[$attendance['date']] = [
            'employee_id' => $selected_employee,
            'work_date' => $attendance['date'],
            'status' => 'present',
            'hours_worked' => $hours_worked,
            'overtime_hours' => 0,
            'time_in' => $attendance['time_in'],
            'time_out' => $attendance['time_out'],
            'notes' => 'Auto-synced from attendance'
        ];
    }
    
    $rest_query = $pdo->prepare("SELECT * FROM employee_rest_days WHERE employee_id = ?");
    $rest_query->execute([$selected_employee]);
    while ($row = $rest_query->fetch()) {
        if ($row['custom_date']) {
            $is_rest_day[$row['custom_date']] = true;
        }
    }
}

// Get overtime requests for the selected employee
$overtime_requests = [];
if ($selected_employee) {
    $ot_stmt = $pdo->prepare("SELECT * FROM overtime_requests 
                              WHERE employee_id = ? 
                              AND work_date BETWEEN ? AND ?
                              ORDER BY work_date DESC");
    $ot_stmt->execute([$selected_employee, $current_month . '-01', date('Y-m-t', strtotime($current_month))]);
    while ($row = $ot_stmt->fetch()) {
        $overtime_requests[$row['work_date']] = $row;
    }
}

// Get leave requests for the selected employee
$leave_requests = [];
if ($selected_employee) {
    $leave_stmt = $pdo->prepare("SELECT * FROM leave_requests 
                                 WHERE employee_id = ? 
                                 ORDER BY 
                                    CASE WHEN status = 'pending' THEN 1 ELSE 2 END,
                                    requested_at DESC");
    $leave_stmt->execute([$selected_employee]);
    while ($row = $leave_stmt->fetch()) {
        $leave_requests[] = $row;
    }
}

// Generate calendar days
$days_in_month = date('t', strtotime($current_month));
$first_day = date('N', strtotime($current_month . '-01'));

$days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

// Calculate totals (UPDATED LOGIC)
$total_work_days = 0;
$total_hours = 0;
$total_overtime = 0;
$total_rest_days = 0;
$total_late = 0;
$total_holiday_days = 0;
$total_holiday_hours = 0;
$holiday_pay = 0;
$total_absent_days = 0; 
$total_pending_days = 0; 

foreach ($schedule as $date => $day) {
    $day_of_week = date('l', strtotime($date));
    $is_holiday = isset($holidays[$date]);
    $is_rest = isset($is_rest_day[$date]) || isset($rest_days[$day_of_week]);
    $holiday_type = $is_holiday ? $holidays[$date]['holiday_type'] : null;
    
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
                $status = 'pending'; 
            }
        }
    } else {
        $status = $day['status'] ?? '';
    }
    
    $schedule[$date]['status'] = $status;

    $is_holiday_worked = $is_holiday && !$is_rest && ($status == 'present' || $status == 'late');
    
    if ($status == 'present' || $status == 'late') {
        $total_work_days++;
        $total_hours += $day['hours_worked'];
        
        if ($is_holiday_worked && $day['hours_worked'] > 0) {
            $total_holiday_days++;
            $total_holiday_hours += $day['hours_worked'];
        }
        
        if ($day['hours_worked'] > 8) {
            $overtime = $day['hours_worked'] - 8;
            $total_overtime += $overtime;
        }
        
        $total_overtime += $day['overtime_hours'];
        
        if ($status == 'late') {
            $total_late++;
        }
        
    } elseif ($status == 'rest_day') {
        $total_rest_days++;
    } elseif ($status == 'absent') {
        $total_absent_days++;
    } elseif ($status == 'pending') {
        $total_pending_days++;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Employee Work Schedule</title>
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
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        .btn-danger:hover {
            background: #c82333;
        }
        .btn-warning {
            background: #ffc107;
            color: #333;
        }
        .btn-warning:hover {
            background: #e0a800;
        }
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        .btn-info:hover {
            background: #138496;
        }
        .btn-scanner {
            background: #6610f2;
            color: white;
        }
        .btn-scanner:hover {
            background: #520dc2;
        }
        .btn-overtime {
            background: #fd7e14;
            color: white;
        }
        .btn-overtime:hover {
            background: #e06b0a;
        }
        .btn-overtime-approve {
            background: #28a745;
            color: white;
            padding: 3px 8px;
            font-size: 0.7em;
            border-radius: 5px;
            border: none;
            cursor: pointer;
        }
        .btn-overtime-approve:hover {
            background: #218838;
        }
        .btn-overtime-reject {
            background: #dc3545;
            color: white;
            padding: 3px 8px;
            font-size: 0.7em;
            border-radius: 5px;
            border: none;
            cursor: pointer;
        }
        .btn-overtime-reject:hover {
            background: #c82333;
        }
        .filter-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            margin-bottom: 20px;
        }
        .filter-grid {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
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
        .section-title {
            font-size: 1.5em;
            font-weight: 600;
            color: #333;
            margin-bottom: 20px;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .holiday-card, .rest-days-card, .overtime-requests-card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            margin-bottom: 30px;
        }
        .holiday-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .holiday-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 15px;
            background: #f8f9fa;
            border-radius: 10px;
            border-left: 4px solid #dc3545;
        }
        .holiday-item.special {
            border-left-color: #ffc107;
        }
        .holiday-info {
            display: flex;
            flex-direction: column;
        }
        .holiday-name {
            font-weight: 600;
            color: #333;
        }
        .holiday-date {
            font-size: 0.85em;
            color: #666;
        }
        .holiday-type-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 0.75em;
            font-weight: 600;
        }
        .badge-regular { background: #dc3545; color: white; }
        .badge-special { background: #ffc107; color: #333; }
        .holiday-form {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr auto auto;
            gap: 15px;
            align-items: end;
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
        .calendar-day.holiday {
            border: 3px solid #dc3545;
            position: relative;
        }
        .calendar-day.holiday.special-holiday {
            border-color: #ffc107;
        }
        .calendar-day.holiday-rest-day {
            border: 3px solid #6c757d;
            position: relative;
            background: #e9ecef;
        }
        .holiday-badge {
            background: #dc3545;
            color: white;
            font-size: 0.6em;
            padding: 1px 6px;
            border-radius: 10px;
            position: absolute;
            top: 2px;
            right: 2px;
        }
        .special-holiday .holiday-badge {
            background: #ffc107;
            color: #333;
        }
        .rest-day-badge {
            background: #ffc107;
            color: #856404;
            font-size: 0.75em;
            padding: 2px 5px;
            border-radius: 10px;
            display: inline-block;
            margin-bottom: 3px;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr); 
            gap: 15px;
            margin-top: 20px;
        }
        .summary-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .summary-value {
            font-size: 2em;
            font-weight: 600;
            color: #667eea;
        }
        .summary-label {
            color: #666;
            font-size: 0.9em;
        }
        .holiday-summary {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
        }
        .holiday-summary .summary-value { color: white; }
        .holiday-summary .summary-label { color: rgba(255,255,255,0.8); }
        .rest-days-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
        }
        .rest-day-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 15px;
            background: #f8f9fa;
            border-radius: 20px;
            border: 2px solid #e0e0e0;
            cursor: pointer;
            transition: all 0.2s;
        }
        .rest-day-checkbox.selected {
            background: #d4edda;
            border-color: #28a745;
        }
        .rest-day-checkbox input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .rest-day-checkbox label {
            margin: 0;
            cursor: pointer;
            font-weight: 400;
        }
        .custom-dates-section {
            margin-top: 20px;
            border-top: 1px solid #e0e0e0;
            padding-top: 20px;
        }
        .custom-dates-input-group {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-bottom: 10px;
        }
        .custom-dates-input-group input[type="date"] {
            flex: 1;
            padding: 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
        }
        .custom-dates-input-group .btn {
            padding: 10px 20px;
        }
        .rest-day-tag {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #e9ecef;
            padding: 5px 12px;
            border-radius: 20px;
            margin: 0 5px 5px 0;
            font-size: 0.9em;
        }
        .rest-day-tag.custom {
            background: #fff3cd;
            color: #856404;
        }
        .rest-day-tag .remove {
            background: none;
            border: none;
            color: #dc3545;
            cursor: pointer;
            font-size: 1.2em;
            padding: 0 5px;
            font-weight: bold;
        }
        .rest-day-tag .remove:hover {
            color: #c82333;
        }
        .calendar {
            background: white;
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
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
            min-height: 140px;
            padding: 10px;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            background: #f8f9fa;
            position: relative;
            transition: all 0.3s;
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
        .calendar-day.ot-pending {
            border: 2px dashed #fd7e14;
        }
        .calendar-day.ot-approved {
            border: 3px solid #28a745;
        }
        .calendar-day.holiday {
            border: 3px solid #dc3545;
        }
        .calendar-day.holiday.special-holiday {
            border-color: #ffc107;
        }
        .calendar-day.holiday-rest-day {
            border: 3px solid #6c757d;
            background: #e9ecef;
            opacity: 0.7;
        }
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
        }
        .day-status {
            font-size: 0.85em;
            padding: 3px 5px;
            border-radius: 5px;
            margin-bottom: 3px;
        }
        .status-present { background: #d4edda; color: #155724; }
        .status-absent { background: #f8d7da; color: #721c24; }
        .status-late { background: #fff3cd; color: #856404; }
        .status-rest_day { background: #cce5ff; color: #004085; }
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
        .overtime-pending {
            color: #856404;
            font-weight: 600;
            font-size: 0.8em;
            background: #fff3cd;
            padding: 2px 5px;
            border-radius: 10px;
            display: inline-block;
            margin-top: 2px;
            border: 1px dashed #fd7e14;
        }
        .overtime-approved {
            color: #155724;
            font-weight: 600;
            font-size: 0.8em;
            background: #d4edda;
            padding: 2px 5px;
            border-radius: 10px;
            display: inline-block;
            margin-top: 2px;
            border: 1px solid #28a745;
        }
        .overtime-rejected {
            color: #721c24;
            font-weight: 600;
            font-size: 0.8em;
            background: #f8d7da;
            padding: 2px 5px;
            border-radius: 10px;
            display: inline-block;
            margin-top: 2px;
            border: 1px solid #dc3545;
        }
        .holiday-pay-badge {
            color: #dc3545;
            font-weight: 700;
            font-size: 0.8em;
            background: #f8d7da;
            padding: 2px 5px;
            border-radius: 10px;
            display: inline-block;
            margin-top: 2px;
        }
        .no-holiday-pay-badge {
            color: #6c757d;
            font-size: 0.7em;
            background: #e9ecef;
            padding: 2px 5px;
            border-radius: 10px;
            display: inline-block;
            margin-top: 2px;
        }
        .day-actions {
            margin-top: 5px;
            display: flex;
            gap: 3px;
            flex-wrap: wrap;
        }
        .day-actions button {
            padding: 2px 5px;
            font-size: 0.7em;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }
        .btn-rest {
            background: #ffc107;
            color: #856404;
        }
        .btn-ot {
            background: #fd7e14;
            color: white;
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
        }
        .modal.show { display: flex; }
        .modal-content {
            background: white;
            border-radius: 20px;
            padding: 30px;
            max-width: 400px;
            width: 90%;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #333;
            font-weight: 500;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
        }
        .btn-save {
            background: #28a745;
            color: white;
            border: none;
            padding: 10px;
            border-radius: 8px;
            cursor: pointer;
        }
        .btn-cancel {
            background: #6c757d;
            color: white;
            border: none;
            padding: 10px;
            border-radius: 8px;
            cursor: pointer;
        }
        .info-text {
            font-size: 0.9em;
            color: #666;
            margin-top: 5px;
        }
        .overtime-request-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 15px;
            background: #f8f9fa;
            border-radius: 10px;
            margin-bottom: 10px;
            border-left: 4px solid #fd7e14;
        }
        .overtime-request-item.approved {
            border-left-color: #28a745;
            background: #d4edda;
        }
        .overtime-request-item.rejected {
            border-left-color: #dc3545;
            background: #f8d7da;
        }
        .overtime-request-item.pending {
            border-left-color: #ffc107;
            background: #fff3cd;
        }
        .overtime-request-info {
            flex: 1;
        }
        .overtime-request-info .date {
            font-weight: 600;
            color: #333;
        }
        .overtime-request-info .hours {
            color: #fd7e14;
            font-weight: 600;
        }
        .overtime-request-info .reason {
            font-size: 0.85em;
            color: #666;
        }
        .overtime-request-info .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7em;
            font-weight: 600;
        }
        .status-pending-badge { background: #ffc107; color: #856404; }
        .status-approved-badge { background: #28a745; color: white; }
        .status-rejected-badge { background: #dc3545; color: white; }
        .overtime-requests-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 15px;
        }
        .overtime-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        .overtime-stat-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
        }
        .overtime-stat-card .number {
            font-size: 2em;
            font-weight: 700;
        }
        .overtime-stat-card .label {
            font-size: 0.85em;
            color: #666;
        }
        .overtime-stat-card.pending-stat .number { color: #ffc107; }
        .overtime-stat-card.approved-stat .number { color: #28a745; }
        .overtime-stat-card.rejected-stat .number { color: #dc3545; }
        @media (max-width: 768px) {
            .filter-grid {
                grid-template-columns: 1fr 1fr;
            }
            .holiday-form {
                grid-template-columns: 1fr 1fr;
            }
            .summary-grid {
                grid-template-columns: 1fr 1fr;
            }
            .overtime-stats {
                grid-template-columns: 1fr;
            }
            .overtime-requests-grid {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 480px) {
            .filter-grid {
                grid-template-columns: 1fr;
            }
            .holiday-form {
                grid-template-columns: 1fr;
            }
            .summary-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📅 Employee Work Schedule</h1>

        <div class="header-buttons">
            <a href="admin_dashboard.php" class="btn btn-back">← Dashboard</a>
            <a href="admin_scanner.php" class="btn btn-scanner">📱 QR Scanner</a>
            <a href="payroll_computation.php" class="btn btn-back">🧮 Compute Payroll</a>
            <a href="schedule_maker.php" class="btn btn-primary">📋 Make Schedules</a>
            
        </div>

        <?php if (isset($message) && $message): ?>
            <div class="alert alert-<?php echo $message_type; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <!-- Holiday Management -->
        <div class="holiday-card">
            <div class="section-title">
                🎉 Holiday Management (<?php echo date('F Y', strtotime($current_month . '-01')); ?>)
                <button class="btn btn-info" onclick="window.location.reload()">Refresh</button>
            </div>
            
            <!-- Add Holiday Form -->
            <form method="POST" style="margin-bottom: 20px;">
                <input type="hidden" name="action" value="add_holiday">
                <div class="holiday-form">
                    <div class="form-group">
                        <label>Date</label>
                        <input type="date" name="holiday_date" required>
                    </div>
                    <div class="form-group">
                        <label>Holiday Name</label>
                        <input type="text" name="holiday_name" placeholder="e.g., Christmas Day" required>
                    </div>
                    <div class="form-group">
                        <label>Type</label>
                        <select name="holiday_type">
                            <option value="regular">Regular Holiday (Double Pay)</option>
                            <option value="special">Special Holiday (30% Additional)</option>
                        </select>
                    </div>
                    <div class="form-group" style="display: flex; align-items: center; gap: 10px; padding-top: 5px;">
                        <label style="margin: 0;">
                            <input type="checkbox" name="is_recurring" value="1"> Recurring Yearly
                        </label>
                    </div>
                    <div>
                        <button type="submit" class="btn btn-primary">Add Holiday</button>
                    </div>
                </div>
            </form>
            
            <!-- Holiday List -->
            <div class="holiday-grid">
                <?php if (empty($holidays)): ?>
                    <div style="grid-column: 1/-1; text-align: center; color: #666; padding: 20px;">
                        No holidays set for this month
                    </div>
                <?php else: ?>
                    <?php foreach ($holidays as $date => $holiday): ?>
                        <div class="holiday-item <?php echo $holiday['holiday_type'] == 'special' ? 'special' : ''; ?>">
                            <div class="holiday-info">
                                <span class="holiday-name"><?php echo htmlspecialchars($holiday['holiday_name']); ?></span>
                                <span class="holiday-date">
                                    <?php echo date('l, M d, Y', strtotime($date)); ?>
                                    <?php if ($holiday['is_recurring']): ?>
                                        🔄 Recurring
                                    <?php endif; ?>
                                </span>
                                <span class="holiday-type-badge badge-<?php echo $holiday['holiday_type']; ?>">
                                    <?php echo $holiday['holiday_type'] == 'regular' ? '🔴 Double Pay' : '🟡 30% Additional'; ?>
                                </span>
                            </div>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Remove this holiday?')">
                                <input type="hidden" name="action" value="remove_holiday">
                                <input type="hidden" name="holiday_id" value="<?php echo $holiday['id']; ?>">
                                <button type="submit" class="btn btn-danger" style="padding: 5px 10px; font-size: 0.8em;">✕ Remove</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="filter-card">
            <div class="section-title">
                🛌 Rest Day Management
                <button class="btn btn-info" onclick="window.location.reload()">Refresh</button>
            </div>
            <div class="filter-grid">
                <select id="employeeSelect" onchange="changeEmployee()">
                    <option value="">Select Employee</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?php echo $emp['employee_id']; ?>" 
                            <?php echo $selected_employee == $emp['employee_id'] ? 'selected' : ''; ?>>
                            <?php echo $emp['first_name'] . ' ' . $emp['last_name']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <input type="month" id="monthSelect" value="<?php echo $current_month; ?>" onchange="changeMonth()">
                
                <button class="btn btn-back" onclick="window.location.href='employee_work_schedule.php'">Reset</button>
            </div>
        </div>

        <?php if ($selected_employee): ?>
            
            <!-- Leave / Rest Day Requests Management -->
            <div class="overtime-requests-card">
                <div class="section-title">
                    📅 Leave / Rest Day Requests
                    <button class="btn btn-info" onclick="window.location.reload()">Refresh</button>
                </div>
                
                <div class="overtime-requests-grid">
                    <?php if (empty($leave_requests)): ?>
                        <div style="grid-column: 1/-1; text-align: center; color: #666; padding: 20px;">
                            No leave or rest day requests for this employee.
                        </div>
                    <?php else: ?>
                        <?php foreach ($leave_requests as $req): ?>
                            <div class="overtime-request-item <?php echo $req['status']; ?>">
                                <div class="overtime-request-info">
                                    <div class="date">
                                        📅 <?php echo date('M d, Y', strtotime($req['request_date'])); ?>
                                        <span class="status-badge status-<?php echo $req['status']; ?>-badge">
                                            <?php echo ucfirst($req['status']); ?>
                                        </span>
                                    </div>
                                    <div class="hours" style="color: #333; margin-top:5px;">
                                        <strong>Type:</strong> <?php echo $req['request_type'] == 'day_off' ? 'Day Off (Leave)' : 'Change Rest Day'; ?>
                                    </div>
                                    <?php if (!empty($req['reason'])): ?>
                                        <div class="reason">📝 <?php echo htmlspecialchars($req['reason']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($req['status'] != 'pending' && !empty($req['admin_notes'])): ?>
                                        <div style="font-size: 0.85em; color: <?php echo $req['status'] == 'approved' ? '#28a745' : '#dc3545'; ?>;">
                                            Admin Note: <?php echo htmlspecialchars($req['admin_notes']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div style="font-size: 0.75em; color: #999; margin-top:5px;">
                                        Requested: <?php echo date('M d, Y h:i A', strtotime($req['requested_at'])); ?>
                                    </div>
                                </div>
                                <?php if ($req['status'] == 'pending'): ?>
                                    <div style="display: flex; gap: 5px; flex-direction: column;">
                                        <button onclick="showApproveLeaveModal(<?php echo $req['id']; ?>, '<?php echo date('M d, Y', strtotime($req['request_date'])); ?>')" 
                                                class="btn btn-overtime-approve">
                                            ✅ Approve
                                        </button>
                                        <button onclick="showRejectLeaveModal(<?php echo $req['id']; ?>)" 
                                                class="btn btn-overtime-reject">
                                            ❌ Reject
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Overtime Requests Management -->
            <div class="overtime-requests-card">
                <div class="section-title">
                    ⏰ Overtime Requests
                    <button class="btn btn-info" onclick="window.location.reload()">Refresh</button>
                </div>
                
                <!-- Overtime Statistics -->
                <?php 
                    $pending_count = 0;
                    $approved_count = 0;
                    $rejected_count = 0;
                    $total_pending_hours = 0;
                    $total_approved_hours = 0;
                    
                    foreach ($overtime_requests as $req) {
                        if ($req['status'] == 'pending') {
                            $pending_count++;
                            $total_pending_hours += $req['hours'];
                        } elseif ($req['status'] == 'approved') {
                            $approved_count++;
                            $total_approved_hours += $req['hours'];
                        } elseif ($req['status'] == 'rejected') {
                            $rejected_count++;
                        }
                    }
                ?>
                
                <div class="overtime-stats">
                    <div class="overtime-stat-card pending-stat">
                        <div class="number"><?php echo $pending_count; ?></div>
                        <div class="label">Pending Requests (<?php echo number_format($total_pending_hours, 1); ?> hrs)</div>
                    </div>
                    <div class="overtime-stat-card approved-stat">
                        <div class="number"><?php echo $approved_count; ?></div>
                        <div class="label">Approved (<?php echo number_format($total_approved_hours, 1); ?> hrs)</div>
                    </div>
                    <div class="overtime-stat-card rejected-stat">
                        <div class="number"><?php echo $rejected_count; ?></div>
                        <div class="label">Rejected</div>
                    </div>
                </div>
                
                <!-- Overtime Requests List -->
                <div class="overtime-requests-grid">
                    <?php if (empty($overtime_requests)): ?>
                        <div style="grid-column: 1/-1; text-align: center; color: #666; padding: 20px;">
                            No overtime requests for this month
                        </div>
                    <?php else: ?>
                        <?php foreach ($overtime_requests as $date => $request): ?>
                            <div class="overtime-request-item <?php echo $request['status']; ?>">
                                <div class="overtime-request-info">
                                    <div class="date">
                                        📅 <?php echo date('M d, Y', strtotime($date)); ?>
                                        <span class="status-badge status-<?php echo $request['status']; ?>-badge">
                                            <?php echo ucfirst($request['status']); ?>
                                        </span>
                                    </div>
                                    <div class="hours">⏱️ <?php echo number_format($request['hours'], 1); ?> hours requested</div>
                                    <?php if (!empty($request['reason'])): ?>
                                        <div class="reason">📝 <?php echo htmlspecialchars($request['reason']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($request['status'] == 'approved' && !empty($request['approved_hours'])): ?>
                                        <div style="font-size: 0.85em; color: #28a745;">✅ Approved: <?php echo number_format($request['approved_hours'], 1); ?> hours</div>
                                    <?php endif; ?>
                                    <?php if ($request['status'] == 'rejected' && !empty($request['rejection_reason'])): ?>
                                        <div style="font-size: 0.85em; color: #dc3545;">❌ Reason: <?php echo htmlspecialchars($request['rejection_reason']); ?></div>
                                    <?php endif; ?>
                                    <div style="font-size: 0.75em; color: #999;">
                                        Requested: <?php echo date('M d, Y h:i A', strtotime($request['requested_at'])); ?>
                                    </div>
                                </div>
                                <?php if ($request['status'] == 'pending'): ?>
                                    <div style="display: flex; gap: 5px; flex-direction: column;">
                                        <button onclick="showApproveOvertimeModal(<?php echo $request['id']; ?>, <?php echo $request['hours']; ?>, '<?php echo date('M d, Y', strtotime($date)); ?>')" 
                                                class="btn btn-overtime-approve">
                                            ✅ Approve
                                        </button>
                                        <button onclick="showRejectOvertimeModal(<?php echo $request['id']; ?>)" 
                                                class="btn btn-overtime-reject">
                                            ❌ Reject
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ==================== UPDATED REST DAYS SECTION ==================== -->
            <div class="rest-days-card">
                <div class="section-title">
                    🗓️ Rest Days
                    <button class="btn btn-info" onclick="window.location.reload()">Refresh</button>
                </div>

                <p style="color: #666; font-size: 0.95em; margin-bottom: 15px;">
                    Select the days that are considered rest days for this employee. 
                    Holiday pay is not applied on rest days.
                </p>

                <form method="POST" id="restDaysForm">
                    <input type="hidden" name="action" value="save_rest_days">
                    <input type="hidden" name="employee_id" value="<?php echo $selected_employee; ?>">
                    <!-- Hidden input to store custom dates as comma-separated -->
                    <input type="hidden" name="custom_rest_dates" id="customRestDatesHidden" 
                           value="<?php echo implode(',', array_column($custom_rest_dates, 'custom_date')); ?>">

                    <!-- Recurring Rest Days Checkboxes -->
                    <div class="rest-days-grid">
                        <?php foreach ($days_of_week as $day): ?>
                            <div class="rest-day-checkbox <?php echo isset($rest_days[$day]) ? 'selected' : ''; ?>"
                                 onclick="toggleRestDayCheckbox(this)">
                                <input type="checkbox" name="rest_days[]" value="<?php echo $day; ?>" 
                                       <?php echo isset($rest_days[$day]) ? 'checked' : ''; ?> 
                                       onchange="updateRestDayUI(this)">
                                <label><?php echo $day; ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Custom Rest Days -->
                    <div class="custom-dates-section">
                        <label style="font-weight: 500; display: block; margin-bottom: 8px;">
                            Custom Rest Days (Specific Dates)
                        </label>
                        <div class="custom-dates-input-group">
                            <input type="date" id="customRestDate">
                            <button type="button" class="btn btn-primary" onclick="addCustomRestDay()">
                                + Add
                            </button>
                        </div>
                        <div id="customRestDaysContainer" style="margin-top: 10px;">
                            <?php foreach ($custom_rest_dates as $rest): ?>
                                <span class="rest-day-tag custom">
                                    📅 <?php echo date('M d, Y', strtotime($rest['custom_date'])); ?>
                                    <span class="remove" onclick="removeCustomRestDay(this)">×</span>
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <div class="info-text" style="margin-top: 5px;">
                            Add specific dates for holidays or special non-working days.
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="margin-top: 20px;">
                        💾 Save Rest Days Configuration
                    </button>
                </form>
            </div>
            <!-- ==================== END UPDATED REST DAYS SECTION ==================== -->

            <!-- Calendar -->
            <div class="calendar">
                <div class="calendar-header">
                    <h2><?php echo date('F Y', strtotime($current_month . '-01')); ?></h2>
                    <div class="month-nav">
                        <button onclick="prevMonth()">←</button>
                        <button onclick="nextMonth()">→</button>
                        <button onclick="refreshData()" class="btn btn-info" style="padding: 8px 15px;">🔄 Refresh</button>
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
                        $time_in = $day_schedule['time_in'] ?? ''; // actual
                        $time_out = $day_schedule['time_out'] ?? ''; // actual
                        
                        // NEW: Fetch scheduled times from schedule maker
                        $scheduled_in = $day_schedule['scheduled_time_in'] ?? '';
                        $scheduled_out = $day_schedule['scheduled_time_out'] ?? '';
                        
                        // Check if holiday
                        $is_holiday = isset($holidays[$date]);
                        $holiday_data = $is_holiday ? $holidays[$date] : null;
                        $holiday_type = $is_holiday ? $holiday_data['holiday_type'] : '';
                        
                        // Check if rest day
                        $is_rest = isset($is_rest_day[$date]) || isset($rest_days[$day_of_week]);
                        
                        // Check overtime request status
                        $ot_request = $overtime_requests[$date] ?? null;
                        $ot_status = $ot_request ? $ot_request['status'] : null;
                        $ot_hours = $ot_request ? $ot_request['hours'] : 0;
                        $ot_approved_hours = $ot_request && $ot_request['status'] == 'approved' ? ($ot_request['approved_hours'] ?? $ot_hours) : 0;
                        
                        // Determine if holiday pay applies (worked on holiday AND not a rest day)
                        $is_holiday_worked = $is_holiday && !$is_rest && ($status == 'present' || $status == 'late') && $hours > 0;
                        
                        // Holiday pay rate
                        $holiday_pay_rate = 1;
                        if ($is_holiday_worked) {
                            if ($holiday_type == 'regular') {
                                $holiday_pay_rate = 2; // Double pay
                            } elseif ($holiday_type == 'special') {
                                $holiday_pay_rate = 1.3; // 30% additional
                            }
                        }
                        
                        // Calculate overtime (only if hours > 8)
                        $overtime_calc = 0;
                        if ($hours > 8) {
                            $overtime_calc = $hours - 8;
                        }
                        
                        // FIXED: Check $is_rest first to ensure rest days get proper background color
                        $day_class = '';
                        if ($is_rest) {
                            $day_class = 'rest-day';
                        } elseif ($status == 'present') {
                            $day_class = 'present';
                        } elseif ($status == 'absent') {
                            $day_class = 'absent';
                        } elseif ($status == 'late') {
                            $day_class = 'late';
                        } elseif ($status == 'rest_day') {
                            $day_class = 'rest-day';
                        } elseif ($status == 'pending') {
                            $day_class = 'pending';
                        }
                        
                        // Add overtime class
                        if ($overtime > 0 || $overtime_calc > 0) {
                            $day_class .= ' ot';
                            if ($ot_status == 'pending') {
                                $day_class .= ' ot-pending';
                            } elseif ($ot_status == 'approved') {
                                $day_class .= ' ot-approved';
                            }
                        }
                        
                        // Add holiday class
                        if ($is_holiday) {
                            if ($is_rest) {
                                $day_class .= ' holiday-rest-day'; // Holiday but rest day - no pay
                            } else {
                                $day_class .= ' holiday';
                                if ($holiday_type == 'special') {
                                    $day_class .= ' special-holiday';
                                }
                            }
                        }
                    ?>
                        <div class="calendar-day <?php echo $day_class; ?>">
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
                            
                            <?php if ($is_holiday): ?>
                                <div style="font-size: 0.7em; color: #dc3545; font-weight: 600; margin-bottom: 3px;">
                                    🎉 <?php echo htmlspecialchars($holiday_data['holiday_name']); ?>
                                    <?php if ($is_rest): ?>
                                        <span style="color: #6c757d;">(Rest Day - No Pay)</span>
                                    <?php elseif ($is_holiday_worked): ?>
                                        <span style="color: #28a745;"><?php echo $holiday_type == 'regular' ? '(Double Pay ✅)' : '(+30% ✅)'; ?></span>
                                    <?php else: ?>
                                        <span style="color: #6c757d;">(No Work - No Pay)</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($is_rest && !$day_schedule): ?>
                                <div class="rest-day-badge">🛌 Rest Day</div>
                            <?php endif; ?>
                            
                            <?php if ($day_schedule): ?>
                                <!-- UPDATED STATUS DISPLAY -->
                                <div class="day-status status-<?php echo $status; ?>">
                                    <?php 
                                    if ($status == 'present') echo '✅ Present';
                                    elseif ($status == 'absent') echo '❌ Absent';
                                    elseif ($status == 'late') echo '⚠️ Late';
                                    elseif ($status == 'rest_day') echo '🛌 Rest Day';
                                    elseif ($status == 'pending') echo '⏳ Not Scanned';
                                    ?>
                                </div>
                                
                                <!-- NEW: Display Scheduled Times clearly -->
                                <?php if ($scheduled_in): ?>
                                    <div class="time-display" style="color: #007bff; font-weight: 600;">🕐 Sched IN: <?php echo date('h:i A', strtotime($scheduled_in)); ?></div>
                                <?php endif; ?>
                                <?php if ($scheduled_out): ?>
                                    <div class="time-display" style="color: #007bff; font-weight: 600;">🕐 Sched OUT: <?php echo date('h:i A', strtotime($scheduled_out)); ?></div>
                                <?php endif; ?>
                                
                                <!-- Display actual scan times if available -->
                                <?php if ($time_in): ?>
                                    <div class="time-display" style="color: #28a745;">⬇️ Actual IN: <?php echo date('h:i A', strtotime($time_in)); ?></div>
                                <?php endif; ?>
                                
                                <?php if ($time_out): ?>
                                    <div class="time-display" style="color: #28a745;">⬆️ Actual OUT: <?php echo date('h:i A', strtotime($time_out)); ?></div>
                                <?php endif; ?>
                                
                                <?php if ($hours > 0): ?>
                                    <div class="hours-worked">⏱️ <?php echo $hours; ?> hrs</div>
                                <?php endif; ?>
                                
                                <!-- Overtime Display -->
                                <?php if ($overtime > 0 || $overtime_calc > 0): ?>
                                    <?php if ($ot_status == 'pending'): ?>
                                        <div class="overtime-pending">⏳ OT Pending: <?php echo number_format($ot_hours, 1); ?> hrs</div>
                                    <?php elseif ($ot_status == 'approved'): ?>
                                        <div class="overtime-approved">✅ OT Approved: <?php echo number_format($ot_approved_hours, 1); ?> hrs</div>
                                    <?php elseif ($ot_status == 'rejected'): ?>
                                        <div class="overtime-rejected">❌ OT Rejected</div>
                                    <?php else: ?>
                                        <div class="overtime">➕ OT: <?php echo number_format($overtime > 0 ? $overtime : $overtime_calc, 1); ?> hrs</div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php if ($is_holiday_worked && $hours > 0): ?>
                                    <div class="holiday-pay-badge">
                                        💰 Holiday Pay: <?php echo $holiday_pay_rate == 2 ? '2x' : '1.3x'; ?>
                                    </div>
                                <?php elseif ($is_holiday && $is_rest): ?>
                                    <div class="no-holiday-pay-badge">
                                        ⛔ Rest Day - No Holiday Pay
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($day_schedule['notes']): ?>
                                    <div style="font-size: 0.7em; color: #666;">📝 <?php echo substr($day_schedule['notes'], 0, 20); ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if (!$is_rest && strtotime($date) <= time()): ?>
                                    <div style="color: #dc3545; font-size: 0.8em; margin-top: 5px;">No QR scan</div>
                                <?php endif; ?>
                            <?php endif; ?>

                            <div class="day-actions">
                                <?php if ($day_schedule && ($status == 'present' || $status == 'late')): ?>
                                    <?php 
                                        $has_ot = ($overtime > 0 || $overtime_calc > 0);
                                        $has_pending = $ot_status == 'pending';
                                        $has_approved = $ot_status == 'approved';
                                    ?>
                                    <?php if ($has_ot && !$has_pending && !$has_approved): ?>
                                        <button onclick="showOvertimeRequestModal('<?php echo $date; ?>', <?php echo $overtime > 0 ? $overtime : $overtime_calc; ?>)" 
                                                class="btn-overtime" style="background: #fd7e14; color: white; padding: 3px 8px; font-size: 0.7em; border: none; border-radius: 3px; cursor: pointer;">
                                            ⏰ Request OT
                                        </button>
                                    <?php elseif ($has_pending): ?>
                                        <span style="font-size: 0.7em; color: #856404; background: #fff3cd; padding: 2px 5px; border-radius: 3px;">
                                            ⏳ Pending Approval
                                        </span>
                                    <?php elseif ($has_approved): ?>
                                        <span style="font-size: 0.7em; color: #155724; background: #d4edda; padding: 2px 5px; border-radius: 3px;">
                                            ✅ Approved
                                        </span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php if (!$is_rest && !$day_schedule): ?>
                                    <button onclick="markAbsent('<?php echo $date; ?>')" style="background: #dc3545; color: white; padding: 2px 5px; font-size: 0.7em; border: none; border-radius: 3px; cursor: pointer;">Absent</button>
                                    <button onclick="markRestDay('<?php echo $date; ?>')" class="btn-rest" style="background: #ffc107; color: #856404; padding: 2px 5px; font-size: 0.7em; border: none; border-radius: 3px; cursor: pointer;">Rest Day</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>

                <!-- Summary (UPDATED CARDS) -->
                <div class="summary-grid">
                    <div class="summary-card">
                        <div class="summary-value"><?php echo $total_work_days; ?></div>
                        <div class="summary-label">Days Present</div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-value"><?php echo number_format($total_hours, 1); ?></div>
                        <div class="summary-label">Total Hours</div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-value"><?php echo number_format($total_overtime, 1); ?></div>
                        <div class="summary-label">Overtime Hours</div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-value"><?php echo $total_rest_days; ?></div>
                        <div class="summary-label">Rest Days</div>
                    </div>
                    <div class="summary-card holiday-summary">
                        <div class="summary-value"><?php echo $total_holiday_days; ?></div>
                        <div class="summary-label">Holiday Days Worked <small>(with pay)</small></div>
                    </div>
                    <div class="summary-card" style="background: #fff3cd; border: 2px solid #ffc107;">
                        <div class="summary-value" style="color: #856404;"><?php echo $total_late; ?></div>
                        <div class="summary-label">Days Late</div>
                    </div>
                    <div class="summary-card" style="background: #f8d7da; border: 2px solid #dc3545;">
                        <div class="summary-value" style="color: #dc3545;"><?php echo $total_absent_days; ?></div>
                        <div class="summary-label">Days Absent</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Overtime Request Modal -->
    <div id="overtimeRequestModal" class="modal">
        <div class="modal-content">
            <div class="modal-title" style="font-size: 1.5em; font-weight: 600; margin-bottom: 20px;">⏰ Request Overtime</div>
            <form method="POST">
                <input type="hidden" name="action" value="request_overtime">
                <input type="hidden" name="employee_id" value="<?php echo $selected_employee; ?>">
                <input type="hidden" name="work_date" id="otRequestDate">
                
                <div class="form-group">
                    <label>Overtime Hours</label>
                    <input type="number" name="overtime_hours" id="otRequestHours" step="0.5" min="0.5" max="24" required>
                    <div class="info-text">Maximum 24 hours per day</div>
                </div>
                
                <div class="form-group">
                    <label>Reason for Overtime</label>
                    <textarea name="reason" rows="3" placeholder="Please provide a reason for the overtime request..." required></textarea>
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" class="btn-save" style="flex: 1; padding: 12px; background: #fd7e14;">Submit Request</button>
                    <button type="button" class="btn-cancel" onclick="hideModal('overtimeRequestModal')" style="flex: 1; padding: 12px;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Approve Overtime Modal -->
    <div id="approveOvertimeModal" class="modal">
        <div class="modal-content">
            <div class="modal-title" style="font-size: 1.5em; font-weight: 600; margin-bottom: 20px;">✅ Approve Overtime</div>
            <form method="POST">
                <input type="hidden" name="action" value="approve_overtime">
                <input type="hidden" name="request_id" id="approveRequestId">
                
                <div class="form-group">
                    <label>Request Date</label>
                    <input type="text" id="approveRequestDate" readonly style="background: #f8f9fa;">
                </div>
                
                <div class="form-group">
                    <label>Requested Hours</label>
                    <input type="text" id="approveRequestedHours" readonly style="background: #f8f9fa;">
                </div>
                
                <div class="form-group">
                    <label>Approved Hours</label>
                    <input type="number" name="approved_hours" id="approveHours" step="0.5" min="0.5" max="24" required>
                    <div class="info-text">You can approve partial hours if needed</div>
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" class="btn-save" style="flex: 1; padding: 12px; background: #28a745;">✅ Approve</button>
                    <button type="button" class="btn-cancel" onclick="hideModal('approveOvertimeModal')" style="flex: 1; padding: 12px;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reject Overtime Modal -->
    <div id="rejectOvertimeModal" class="modal">
        <div class="modal-content">
            <div class="modal-title" style="font-size: 1.5em; font-weight: 600; margin-bottom: 20px;">❌ Reject Overtime</div>
            <form method="POST">
                <input type="hidden" name="action" value="reject_overtime">
                <input type="hidden" name="request_id" id="rejectRequestId">
                
                <div class="form-group">
                    <label>Reason for Rejection</label>
                    <textarea name="rejection_reason" rows="3" placeholder="Please provide a reason for rejecting this overtime request..." required></textarea>
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" class="btn-save" style="flex: 1; padding: 12px; background: #dc3545;">❌ Reject</button>
                    <button type="button" class="btn-cancel" onclick="hideModal('rejectOvertimeModal')" style="flex: 1; padding: 12px;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Approve Leave Modal -->
    <div id="approveLeaveModal" class="modal">
        <div class="modal-content">
            <div class="modal-title" style="font-size: 1.5em; font-weight: 600; margin-bottom: 20px;">✅ Approve Request</div>
            <form method="POST">
                <input type="hidden" name="action" value="approve_leave">
                <input type="hidden" name="request_id" id="approveLeaveId">
                
                <div class="form-group">
                    <label>Date Requested</label>
                    <input type="text" id="approveLeaveDate" readonly style="background: #f8f9fa;">
                </div>
                
                <div class="form-group">
                    <label>Admin Notes (Optional)</label>
                    <input type="text" name="admin_note" placeholder="e.g. Approved" value="Approved">
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" class="btn-save" style="flex: 1; padding: 12px; background: #28a745;">✅ Confirm Approval</button>
                    <button type="button" class="btn-cancel" onclick="hideModal('approveLeaveModal')" style="flex: 1; padding: 12px;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reject Leave Modal -->
    <div id="rejectLeaveModal" class="modal">
        <div class="modal-content">
            <div class="modal-title" style="font-size: 1.5em; font-weight: 600; margin-bottom: 20px;">❌ Reject Request</div>
            <form method="POST">
                <input type="hidden" name="action" value="reject_leave">
                <input type="hidden" name="request_id" id="rejectLeaveId">
                
                <div class="form-group">
                    <label>Reason for Rejection</label>
                    <textarea name="admin_note" rows="3" placeholder="Please provide a reason..." required></textarea>
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" class="btn-save" style="flex: 1; padding: 12px; background: #dc3545;">❌ Confirm Rejection</button>
                    <button type="button" class="btn-cancel" onclick="hideModal('rejectLeaveModal')" style="flex: 1; padding: 12px;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function changeEmployee() {
            const emp = document.getElementById('employeeSelect').value;
            const month = document.getElementById('monthSelect').value;
            if (emp) {
                window.location.href = `employee_work_schedule.php?employee=${emp}&month=${month}`;
            }
        }

        function changeMonth() {
            const emp = document.getElementById('employeeSelect').value;
            const month = document.getElementById('monthSelect').value;
            if (emp) {
                window.location.href = `employee_work_schedule.php?employee=${emp}&month=${month}`;
            } else {
                window.location.href = `employee_work_schedule.php?month=${month}`;
            }
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

        function refreshData() {
            location.reload();
        }

        function showModal(modalId) {
            document.getElementById(modalId).classList.add('show');
        }

        function hideModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }

        // --- OVERTIME MODALS ---
        function showOvertimeRequestModal(date, hours) {
            document.getElementById('otRequestDate').value = date;
            document.getElementById('otRequestHours').value = hours > 0 ? hours : '';
            showModal('overtimeRequestModal');
        }

        function showApproveOvertimeModal(requestId, hours, date) {
            document.getElementById('approveRequestId').value = requestId;
            document.getElementById('approveRequestDate').value = date;
            document.getElementById('approveRequestedHours').value = hours + ' hours requested';
            document.getElementById('approveHours').value = hours;
            showModal('approveOvertimeModal');
        }

        function showRejectOvertimeModal(requestId) {
            document.getElementById('rejectRequestId').value = requestId;
            showModal('rejectOvertimeModal');
        }

        // --- LEAVE REQUEST MODALS ---
        function showApproveLeaveModal(id, date) {
            document.getElementById('approveLeaveId').value = id;
            document.getElementById('approveLeaveDate').value = date;
            showModal('approveLeaveModal');
        }

        function showRejectLeaveModal(id) {
            document.getElementById('rejectLeaveId').value = id;
            showModal('rejectLeaveModal');
        }

        function markAbsent(date) {
            if (confirm('Mark this day as absent?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="mark_absent">
                    <input type="hidden" name="employee_id" value="<?php echo $selected_employee; ?>">
                    <input type="hidden" name="work_date" value="${date}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function markRestDay(date) {
            if (confirm('Mark this day as rest day?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="mark_rest_day">
                    <input type="hidden" name="employee_id" value="<?php echo $selected_employee; ?>">
                    <input type="hidden" name="work_date" value="${date}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // --- REST DAYS UI FUNCTIONS ---
        function toggleRestDayCheckbox(container) {
            const checkbox = container.querySelector('input[type="checkbox"]');
            checkbox.checked = !checkbox.checked;
            container.classList.toggle('selected');
        }

        function updateRestDayUI(checkbox) {
            const parent = checkbox.closest('.rest-day-checkbox');
            if (checkbox.checked) {
                parent.classList.add('selected');
            } else {
                parent.classList.remove('selected');
            }
        }

        function addCustomRestDay() {
            const dateInput = document.getElementById('customRestDate');
            const date = dateInput.value;
            if (!date) {
                alert('Please select a date.');
                return;
            }

            const hidden = document.getElementById('customRestDatesHidden');
            const existing = hidden.value ? hidden.value.split(',') : [];
            if (existing.includes(date)) {
                alert('This date is already added.');
                return;
            }

            existing.push(date);
            hidden.value = existing.join(',');

            const container = document.getElementById('customRestDaysContainer');
            const tag = document.createElement('span');
            tag.className = 'rest-day-tag custom';
            const displayDate = new Date(date + 'T00:00:00');
            tag.innerHTML = `
                📅 ${displayDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
                <span class="remove" onclick="removeCustomRestDay(this)">×</span>
            `;
            container.appendChild(tag);
            dateInput.value = '';
        }

        function removeCustomRestDay(element) {
            if (!confirm('Remove this custom rest day?')) return;

            const tag = element.closest('.rest-day-tag');
            const container = document.getElementById('customRestDaysContainer');
            const hidden = document.getElementById('customRestDatesHidden');
            let dates = hidden.value ? hidden.value.split(',') : [];

            const allTags = container.querySelectorAll('.rest-day-tag');
            const index = Array.from(allTags).indexOf(tag);
            if (index !== -1 && dates[index]) {
                dates.splice(index, 1);
                hidden.value = dates.join(',');
                tag.remove();
            } else {
                tag.remove();
                rebuildCustomRestDays();
            }
        }

        function rebuildCustomRestDays() {
            const hidden = document.getElementById('customRestDatesHidden');
            const container = document.getElementById('customRestDaysContainer');
            container.innerHTML = '';
            const dates = hidden.value ? hidden.value.split(',') : [];
            dates.forEach(date => {
                if (date.trim() === '') return;
                const tag = document.createElement('span');
                tag.className = 'rest-day-tag custom';
                const displayDate = new Date(date + 'T00:00:00');
                tag.innerHTML = `
                    📅 ${displayDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
                    <span class="remove" onclick="removeCustomRestDay(this)">×</span>
                `;
                container.appendChild(tag);
            });
        }

        let refreshInterval;
        document.addEventListener('DOMContentLoaded', function() {
            rebuildCustomRestDays();
            
            <?php if ($selected_employee): ?>
            refreshInterval = setInterval(function() {
                if (!document.hidden) {
                    location.reload();
                }
            }, 30000);
            <?php endif; ?>
            
            document.querySelectorAll('.modal').forEach(modal => {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        this.classList.remove('show');
                    }
                });
            });
        });

        window.addEventListener('beforeunload', function() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
            }
        });
    </script>
</body>
</html>