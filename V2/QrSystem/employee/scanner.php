<?php
define('MIN_BREAK_MINUTES', 45);
date_default_timezone_set('Asia/Manila');

$date = date('Y-m-d');
$time = date('H:i:s');

error_log("Current time: " . $time);

session_start();

require_once 'config.php';

// Handle AJAX requests for attendance recording
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] == 'record_attendance') {
        $employee_id = $_POST['employee_id'] ?? '';
        $scan_type = $_POST['scan_type'] ?? ''; // 'in', 'out', 'break_in', or 'break_out'
        
        // Validate employee ID
        if (empty($employee_id)) {
            echo json_encode(['success' => false, 'message' => 'Employee ID is required']);
            exit;
        }
        
        // Validate scan type
        if (empty($scan_type) || !in_array($scan_type, ['in', 'out', 'break_in', 'break_out'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid scan type']);
            exit;
        }
        
        $date = date('Y-m-d');
        $time = date('H:i:s');
        
        try {
            // Check if employee exists
            $stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ?");
            $stmt->execute([$employee_id]);
            $employee = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$employee) {
                echo json_encode(['success' => false, 'message' => 'Employee not found with ID: ' . $employee_id]);
                exit;
            }
            
            // Check if it's a rest day
            $day_of_week = date('l', strtotime($date));
            $rest_check = $pdo->prepare("SELECT id FROM employee_rest_days WHERE employee_id = ? AND (day_of_week = ? OR custom_date = ?)");
            $rest_check->execute([$employee_id, $day_of_week, $date]);
            
            if ($rest_check->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Today is a rest day. Cannot record attendance.']);
                exit;
            }
            
            // Check today's attendance
            $stmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND date = ?");
            $stmt->execute([$employee_id, $date]);
            $attendance = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Check today's break records
            $break_stmt = $pdo->prepare("SELECT * FROM employee_breaks WHERE employee_id = ? AND date = ? ORDER BY id DESC");
            $break_stmt->execute([$employee_id, $date]);
            $break_records = $break_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Check if currently on break (break_in without break_out)
            $on_break = false;
            $last_break = null;
            if (!empty($break_records)) {
                $last_break = $break_records[0];
                if ($last_break['break_in'] && !$last_break['break_out']) {
                    $on_break = true;
                }
            }
            
            // Also check work schedule
            $work_check = $pdo->prepare("SELECT * FROM employee_work_schedules WHERE employee_id = ? AND work_date = ?");
            $work_check->execute([$employee_id, $date]);
            $work_schedule = $work_check->fetch(PDO::FETCH_ASSOC);
            
            $type = '';
            $message = '';
            
            // --- BREAK OUT LOGIC (Start Break) ---
            if ($scan_type == 'break_out') {
                // Check if employee has timed in first
                if (!$attendance || !$attendance['time_in']) {
                    echo json_encode(['success' => false, 'message' => 'You must Time In first before taking a break.']);
                    exit;
                }
    
                if ($attendance['time_out']) {
                    echo json_encode(['success' => false, 'message' => 'You have already timed out for today. Cannot take a break.']);
                    exit;
                }
    
                // Check if already on break
                if ($on_break) {
                    echo json_encode(['success' => false, 'message' => 'You are already on break. Please use Break In first.']);
                    exit;
                }
    
                // NEW: Check if employee already had a break today (even completed)
                $existing_break_stmt = $pdo->prepare("SELECT COUNT(*) FROM employee_breaks WHERE employee_id = ? AND date = ?");
                $existing_break_stmt->execute([$employee_id, $date]);
                $existing_break_count = $existing_break_stmt->fetchColumn();
                if ($existing_break_count > 0) {
                    echo json_encode(['success' => false, 'message' => 'You have already taken a break today. Only one break is allowed per day.']);
                    exit;
                }
    
                // Insert break record (this is BREAK OUT = starting break)
                $break_insert = $pdo->prepare("INSERT INTO employee_breaks (employee_id, date, break_in, status) VALUES (?, ?, ?, 'on_break')");
                $break_insert->execute([$employee_id, $date, $time]);
    
                $type = 'break_out';
                $message = 'Break started successfully. Enjoy your break!';
    
                echo json_encode([
                    'success' => true,
                    'message' => $message,
                    'type' => $type,
                    'employee' => $employee['first_name'] . ' ' . $employee['last_name'],
                    'time' => date('h:i A', strtotime($time)),
                    'employee_id' => $employee['employee_id'],
                    'position' => $employee['position'] ?? 'N/A',
                    'department' => $employee['department'] ?? 'N/A'
                ]);
                exit;
            }
                            // --- BREAK IN LOGIC (End Break) ---
                if ($scan_type == 'break_in') {
                    // Check if employee is on break
                    if (!$on_break || !$last_break) {
                        echo json_encode(['success' => false, 'message' => 'You are not currently on break. Please use Break Out first.']);
                        exit;
                    }
                    
                    // Calculate break duration so far
                    $break_start_time = strtotime($last_break['break_in']);
                    $break_end_time = strtotime($time);
                    $break_duration_minutes = round(($break_end_time - $break_start_time) / 60, 0);
                    
                    // NEW: Enforce minimum break duration
                    if ($break_duration_minutes < MIN_BREAK_MINUTES) {
                        echo json_encode([
                            'success' => false,
                            'message' => "Break too short. You must take at least " . MIN_BREAK_MINUTES . " minutes of break. Current duration: $break_duration_minutes minutes."
                        ]);
                        exit;
                    }
                    
                    // Update break record with break_out (this is BREAK IN = ending break)
                    $break_update = $pdo->prepare("UPDATE employee_breaks SET break_out = ?, status = 'completed' WHERE id = ?");
                    $break_update->execute([$time, $last_break['id']]);
                    
                    $type = 'break_in';
                    $message = 'Break ended successfully. Break duration: ' . $break_duration_minutes . ' minutes.';
                    
                    echo json_encode([
                        'success' => true,
                        'message' => $message,
                        'type' => $type,
                        'employee' => $employee['first_name'] . ' ' . $employee['last_name'],
                        'time' => date('h:i A', strtotime($time)),
                        'employee_id' => $employee['employee_id'],
                        'position' => $employee['position'] ?? 'N/A',
                        'department' => $employee['department'] ?? 'N/A',
                        'break_duration' => $break_duration_minutes
                    ]);
                    exit;
                }
            
            // --- REGULAR TIME IN LOGIC ---
            if ($scan_type == 'in') {
                // Check if already on break (can't time in if on break)
                if ($on_break) {
                    echo json_encode(['success' => false, 'message' => 'You are currently on break. Please use Break In first.']);
                    exit;
                }
                
                // Time In logic
                if ($attendance) {
                    if ($attendance['time_in'] && !$attendance['time_out']) {
                        echo json_encode(['success' => false, 'message' => 'Already timed in. Please use Time Out button.']);
                        exit;
                    } elseif ($attendance['time_in'] && $attendance['time_out']) {
                        echo json_encode(['success' => false, 'message' => 'Already completed attendance for today. Come back tomorrow!']);
                        exit;
                    }
                }
                
                // Proceed with Time In
                $stmt = $pdo->prepare("INSERT INTO attendance (employee_id, date, time_in) VALUES (?, ?, ?)");
                $result = $stmt->execute([$employee_id, $date, $time]);
                
                if ($result) {
                    // Get employee settings for default hours
                    $settings = $pdo->prepare("SELECT work_hours_per_day FROM employee_payroll_settings WHERE employee_id = ?");
                    $settings->execute([$employee_id]);
                    $setting = $settings->fetch();
                    $default_hours = $setting ? $setting['work_hours_per_day'] : 8;
                    
                    // Insert into work schedule as present
                    if (!$work_schedule) {
                        $work_insert = $pdo->prepare("INSERT INTO employee_work_schedules 
                                                     (employee_id, work_date, status, hours_worked, time_in, notes) 
                                                     VALUES (?, ?, 'present', ?, ?, 'QR Scan Time In')");
                        $work_insert->execute([$employee_id, $date, $default_hours, $time]);
                    } else {
                        // Update existing work schedule
                        $work_update = $pdo->prepare("UPDATE employee_work_schedules 
                                                     SET status = 'present', time_in = ?, notes = CONCAT(notes, ' | QR Scan Time In') 
                                                     WHERE id = ?");
                        $work_update->execute([$time, $work_schedule['id']]);
                    }
                    
                    $type = 'in';
                    $message = 'Time In recorded successfully';
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to insert attendance record']);
                    exit;
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => $message,
                    'type' => $type,
                    'employee' => $employee['first_name'] . ' ' . $employee['last_name'],
                    'time' => date('h:i A', strtotime($time)),
                    'employee_id' => $employee['employee_id'],
                    'position' => $employee['position'] ?? 'N/A',
                    'department' => $employee['department'] ?? 'N/A'
                ]);
                exit;
            }
            
            // --- REGULAR TIME OUT LOGIC ---
            if ($scan_type == 'out') {
                // Check if on break (can't time out if on break)
                if ($on_break) {
                    echo json_encode(['success' => false, 'message' => 'You are currently on break. Please use Break In first.']);
                    exit;
                }
                
                // Time Out logic
                if (!$attendance) {
                    echo json_encode(['success' => false, 'message' => 'No time in record found. Please use Time In button first.']);
                    exit;
                }
                
                if ($attendance['time_out']) {
                    echo json_encode(['success' => false, 'message' => 'Already timed out for today.']);
                    exit;
                }
                
                // Proceed with Time Out
                $stmt = $pdo->prepare("UPDATE attendance SET time_out = ? WHERE id = ?");
                $result = $stmt->execute([$time, $attendance['id']]);
                
                if ($result) {
                    // Calculate hours worked (excluding break time)
                    $time_in = strtotime($attendance['time_in']);
                    $time_out = strtotime($time);
                    $total_seconds = $time_out - $time_in;
                    
                    // Subtract break time (sum of all breaks for the day)
                    $break_sum = $pdo->prepare("SELECT SUM(TIMESTAMPDIFF(MINUTE, break_in, break_out)) as total_break_minutes 
                                               FROM employee_breaks 
                                               WHERE employee_id = ? AND date = ? AND break_out IS NOT NULL");
                    $break_sum->execute([$employee_id, $date]);
                    $break_total = $break_sum->fetch(PDO::FETCH_ASSOC);
                    $break_minutes = $break_total['total_break_minutes'] ?? 0;
                    
                    $work_seconds = $total_seconds - ($break_minutes * 60);
                    $hours_worked = round(max(0, $work_seconds / 3600), 1); // Convert to hours, ensure not negative
                    
                    // Update work schedule
                    if ($work_schedule) {
                        $work_update = $pdo->prepare("UPDATE employee_work_schedules 
                                                     SET time_out = ?, hours_worked = ?, 
                                                     break_minutes = ?, notes = CONCAT(notes, ' | QR Scan Time Out') 
                                                     WHERE id = ?");
                        $work_update->execute([$time, $hours_worked, $break_minutes, $work_schedule['id']]);
                    } else {
                        // Create work schedule if it doesn't exist
                        $work_insert = $pdo->prepare("INSERT INTO employee_work_schedules 
                                                     (employee_id, work_date, status, hours_worked, time_in, time_out, break_minutes, notes) 
                                                     VALUES (?, ?, 'present', ?, ?, ?, ?, 'QR Scan Complete')");
                        $work_insert->execute([$employee_id, $date, $hours_worked, $attendance['time_in'], $time, $break_minutes]);
                    }
                    
                    $type = 'out';
                    $message = 'Time Out recorded successfully. Total breaks: ' . $break_minutes . ' minutes. Hours worked: ' . $hours_worked . ' hrs.';
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to update attendance record']);
                    exit;
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => $message,
                    'type' => $type,
                    'employee' => $employee['first_name'] . ' ' . $employee['last_name'],
                    'time' => date('h:i A', strtotime($time)),
                    'employee_id' => $employee['employee_id'],
                    'position' => $employee['position'] ?? 'N/A',
                    'department' => $employee['department'] ?? 'N/A',
                    'hours_worked' => $hours_worked,
                    'break_minutes' => $break_minutes
                ]);
                exit;
            }
            
        } catch (PDOException $e) {
            error_log("Database error in admin_scanner.php: " . $e->getMessage());
            echo json_encode([
                'success' => false, 
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        } catch (Exception $e) {
            error_log("General error in admin_scanner.php: " . $e->getMessage());
            echo json_encode([
                'success' => false, 
                'message' => 'Error: ' . $e->getMessage()
            ]);
        }
        exit;
    }
    
    if ($_POST['action'] == 'get_employee') {
        $employee_id = $_POST['employee_id'] ?? '';
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ?");
            $stmt->execute([$employee_id]);
            $employee = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($employee) {
                echo json_encode(['success' => true, 'employee' => $employee]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Employee not found']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // New: Get break status for employee
    if ($_POST['action'] == 'get_break_status') {
        $employee_id = $_POST['employee_id'] ?? '';
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM employee_breaks WHERE employee_id = ? AND date = ? AND break_out IS NULL ORDER BY id DESC LIMIT 1");
            $stmt->execute([$employee_id, date('Y-m-d')]);
            $break = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'on_break' => !empty($break),
                'break_in_time' => $break ? $break['break_in'] : null
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
}

// Get today's attendance summary
$today = date('Y-m-d');
try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_today,
            SUM(CASE WHEN time_out IS NULL THEN 1 ELSE 0 END) as still_working
        FROM attendance 
        WHERE date = ?
    ");
    $stmt->execute([$today]);
    $today_summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Also get work schedule summary for today
    $work_stmt = $pdo->prepare("
        SELECT COUNT(*) as present_today 
        FROM employee_work_schedules 
        WHERE work_date = ? AND status = 'present'
    ");
    $work_stmt->execute([$today]);
    $work_summary = $work_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get break summary for today
    $break_stmt = $pdo->prepare("
        SELECT COUNT(*) as total_breaks, 
               SUM(CASE WHEN break_out IS NULL THEN 1 ELSE 0 END) as on_break_count
        FROM employee_breaks 
        WHERE date = ?
    ");
    $break_stmt->execute([$today]);
    $break_summary = $break_stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $today_summary = ['total_today' => 0, 'still_working' => 0];
    $work_summary = ['present_today' => 0];
    $break_summary = ['total_breaks' => 0, 'on_break_count' => 0];
}

// Get recent attendance with work schedule data and break data
try {
    $stmt = $pdo->prepare("
        SELECT a.*, e.first_name, e.last_name, e.employee_id, e.position,
               ws.time_in as work_time_in, ws.time_out as work_time_out, ws.hours_worked, ws.break_minutes,
               (SELECT COUNT(*) FROM employee_breaks WHERE employee_id = a.employee_id AND date = a.date AND break_out IS NOT NULL) as break_count,
               (SELECT SUM(TIMESTAMPDIFF(MINUTE, break_in, break_out)) FROM employee_breaks WHERE employee_id = a.employee_id AND date = a.date AND break_out IS NOT NULL) as total_break_minutes
        FROM attendance a 
        JOIN employees e ON a.employee_id = e.employee_id 
        LEFT JOIN employee_work_schedules ws ON a.employee_id = ws.employee_id AND a.date = ws.work_date
        WHERE a.date = ? 
        ORDER BY a.time_in DESC 
        LIMIT 10
    ");
    $stmt->execute([$today]);
    $recent_attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_attendance = [];
}

// Get current scan mode from session or default to 'in'
if (!isset($_SESSION['scan_mode'])) {
    $_SESSION['scan_mode'] = 'in';
}

// Handle mode toggle
if (isset($_GET['mode'])) {
    $_SESSION['scan_mode'] = $_GET['mode'];
    header('Location: scanner.php');
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>QR Attendance Scanner</title>
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
            max-width: 1400px;
            margin: 0 auto;
        }

        h1 {
            text-align: center;
            color: white;
            margin-bottom: 20px;
            font-size: 2.5em;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }

        .header-buttons {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            justify-content: center;
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

        .btn-manual {
            background: #ffc107;
            color: #333;
        }

        .btn-manual:hover {
            background: #e0a800;
            transform: translateY(-2px);
        }

        .btn-schedule {
            background: #17a2b8;
            color: white;
            text-decoration: none;
        }

        .btn-schedule:hover {
            background: #138496;
            transform: translateY(-2px);
        }

        /* Mode Selection Buttons - Order: Time In, Break Out, Break In, Time Out */
        .mode-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .mode-btn {
            padding: 15px 30px;
            border: none;
            border-radius: 50px;
            font-size: 1.1em;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            min-width: 140px;
            justify-content: center;
        }

        /* Time In - Green */
        .mode-btn.in {
            background: #28a745;
            color: white;
        }
        .mode-btn.in:hover:not(.active) { 
            background: #218838; 
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(40, 167, 69, 0.4);
        }
        .mode-btn.in.active { 
            background: #28a745; 
            box-shadow: 0 0 0 4px white, 0 0 0 8px #28a745;
            cursor: default;
            transform: scale(1.05);
        }

        /* Break Out - Orange (Start Break) */
        .mode-btn.break-out {
            background: #fd7e14;
            color: white;
        }
        .mode-btn.break-out:hover:not(.active) { 
            background: #e06b0f; 
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(253, 126, 20, 0.4);
        }
        .mode-btn.break-out.active { 
            background: #fd7e14; 
            box-shadow: 0 0 0 4px white, 0 0 0 8px #fd7e14;
            cursor: default;
            transform: scale(1.05);
        }

        /* Break In - Purple (End Break) */
        .mode-btn.break-in {
            background: #6f42c1;
            color: white;
        }
        .mode-btn.break-in:hover:not(.active) { 
            background: #5a32a3; 
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(111, 66, 193, 0.4);
        }
        .mode-btn.break-in.active { 
            background: #6f42c1; 
            box-shadow: 0 0 0 4px white, 0 0 0 8px #6f42c1;
            cursor: default;
            transform: scale(1.05);
        }

        /* Time Out - Red */
        .mode-btn.out {
            background: #dc3545;
            color: white;
        }
        .mode-btn.out:hover:not(.active) { 
            background: #c82333; 
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(220, 53, 69, 0.4);
        }
        .mode-btn.out.active { 
            background: #dc3545; 
            box-shadow: 0 0 0 4px white, 0 0 0 8px #dc3545;
            cursor: default;
            transform: scale(1.05);
        }

        .mode-badge {
            background: rgba(255,255,255,0.25);
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 0.7em;
            letter-spacing: 0.5px;
        }

        .mode-shortcut {
            font-size: 0.7em;
            opacity: 0.8;
            background: rgba(0,0,0,0.1);
            padding: 2px 8px;
            border-radius: 4px;
        }

        .scanner-grid {
            display: grid;
            grid-template-columns: 1.2fr 0.8fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        /* Scanner Section */
        .scanner-container {
            background: white;
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }

        .scanner-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            background: #f8f9fa;
            padding: 10px 15px;
            border-radius: 10px;
            flex-wrap: wrap;
            gap: 5px;
        }

        .scanner-title {
            font-size: 1.3em;
            font-weight: 600;
            color: #333;
        }

        .current-mode {
            padding: 5px 15px;
            background: <?php 
                $mode = $_SESSION['scan_mode'];
                if ($mode == 'in') echo '#28a745';
                elseif ($mode == 'out') echo '#dc3545';
                elseif ($mode == 'break_in') echo '#6f42c1';
                else echo '#fd7e14';
            ?>;
            color: white;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
        }

        .camera-status {
            padding: 5px 15px;
            background: #28a745;
            color: white;
            border-radius: 20px;
            font-size: 0.85em;
            display: none;
        }
        .camera-status.active { display: block; }

        #scanner-container {
            width: 100%;
            height: 350px;
            background: #000;
            border-radius: 15px;
            overflow: hidden;
            position: relative;
            margin-bottom: 15px;
            border: 3px solid <?php 
                $mode = $_SESSION['scan_mode'];
                if ($mode == 'in') echo '#28a745';
                elseif ($mode == 'out') echo '#dc3545';
                elseif ($mode == 'break_in') echo '#6f42c1';
                else echo '#fd7e14';
            ?>;
        }

        #video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            /* MIRROR EFFECT - Flip the video horizontally */
            transform: scaleX(-1);
            -webkit-transform: scaleX(-1);
            -moz-transform: scaleX(-1);
            -ms-transform: scaleX(-1);
            -o-transform: scaleX(-1);
        }

        .scanner-overlay {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 250px;
            height: 250px;
            border: 3px solid <?php 
                $mode = $_SESSION['scan_mode'];
                if ($mode == 'in') echo '#28a745';
                elseif ($mode == 'out') echo '#dc3545';
                elseif ($mode == 'break_in') echo '#6f42c1';
                else echo '#fd7e14';
            ?>;
            border-radius: 10px;
            box-shadow: 0 0 0 9999px rgba(0,0,0,0.5);
            pointer-events: none;
            animation: scan 2s infinite;
        }

        .scanner-overlay::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: <?php 
                $mode = $_SESSION['scan_mode'];
                if ($mode == 'in') echo '#28a745';
                elseif ($mode == 'out') echo '#dc3545';
                elseif ($mode == 'break_in') echo '#6f42c1';
                else echo '#fd7e14';
            ?>;
            animation: scan-line 2s infinite;
        }

        @keyframes scan {
            0%, 100% { border-color: rgba(0, 255, 0, 0.5); }
            50% { border-color: rgba(255, 0, 0, 0.5); }
        }

        @keyframes scan-line {
            0% { top: 0; }
            50% { top: 100%; }
            100% { top: 0; }
        }

        .camera-controls {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 15px;
            flex-wrap: wrap;
        }

        .btn-camera {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 14px;
        }

        .btn-camera.start { background: #28a745; color: white; }
        .btn-camera.start:hover:not(:disabled) { background: #218838; transform: translateY(-2px); }
        .btn-camera.stop { background: #dc3545; color: white; }
        .btn-camera.stop:hover:not(:disabled) { background: #c82333; transform: translateY(-2px); }
        .btn-camera:disabled { opacity: 0.5; cursor: not-allowed; }

        /* Payroll Info Banner */
        .payroll-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 10px 15px;
            border-radius: 10px;
            margin-top: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.9em;
            flex-wrap: wrap;
            gap: 5px;
        }

        .payroll-info span {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* Debug Panel */
        .debug-panel {
            margin-top: 10px;
            background: #1e1e1e;
            color: #0f0;
            padding: 10px;
            border-radius: 5px;
            font-family: monospace;
            font-size: 12px;
            display: none;
            word-break: break-all;
            border-left: 3px solid #0f0;
        }
        .debug-panel.active { display: block; }
        .debug-title { color: #fff; margin-bottom: 5px; font-weight: bold; }
        .debug-content { color: #0f0; }

        /* Result Panel */
        .result-container {
            background: white;
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            display: flex;
            flex-direction: column;
        }

        .result-header {
            font-size: 1.3em;
            font-weight: 600;
            color: #333;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #667eea;
            background: #f8f9fa;
            padding: 10px 15px;
            border-radius: 10px;
        }

        #scanMessage {
            text-align: center;
            padding: 40px 20px;
            color: #666;
            background: #f8f9fa;
            border-radius: 10px;
            margin-bottom: 15px;
            border: 2px dashed #667eea;
            transition: all 0.3s;
        }
        #scanMessage.scanning { background: #e8f0fe; border-color: #28a745; }
        #scanMessage div:first-child { font-size: 3em; margin-bottom: 15px; }
        #scanMessage div:nth-child(2) { font-size: 1.2em; font-weight: 600; color: #333; margin-bottom: 10px; }
        #scanMessage div:last-child { font-size: 0.95em; color: #667eea; background: #e8f0fe; padding: 8px 15px; border-radius: 20px; display: inline-block; }

        #employeeCard { display: none; }

        .employee-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            animation: slideIn 0.3s;
            margin-bottom: 15px;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .employee-name {
            font-size: 1.8em;
            font-weight: 600;
            margin-bottom: 15px;
            text-align: center;
            padding-bottom: 10px;
            border-bottom: 2px solid rgba(255,255,255,0.3);
        }

        .employee-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin: 20px 0;
        }

        .detail-item {
            background: rgba(255,255,255,0.2);
            padding: 12px;
            border-radius: 10px;
        }

        .detail-label { font-size: 0.85em; opacity: 0.9; margin-bottom: 5px; }
        .detail-value { font-size: 1.2em; font-weight: 600; }

        .attendance-result {
            margin-top: 20px;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            font-weight: 600;
            animation: pulse 1s;
        }

        .attendance-result.in { background: #d4edda; color: #155724; border-left: 5px solid #28a745; }
        .attendance-result.out { background: #fff3cd; color: #856404; border-left: 5px solid #ffc107; }
        .attendance-result.break_in { background: #e2d9f3; color: #4b0082; border-left: 5px solid #6f42c1; }
        .attendance-result.break_out { background: #cce5ff; color: #004085; border-left: 5px solid #fd7e14; }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .attendance-time { font-size: 1.5em; margin-top: 10px; font-weight: bold; }

        .payroll-badge {
            margin-top: 10px;
            padding: 8px;
            background: rgba(255,255,255,0.2);
            border-radius: 20px;
            text-align: center;
            font-size: 0.9em;
        }

        /* Recent Activity */
        .recent-container {
            background: white;
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }

        .recent-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            flex-wrap: wrap;
            gap: 5px;
        }

        .recent-title { font-size: 1.3em; font-weight: 600; color: #333; }

        .summary-badge {
            background: #667eea;
            color: white;
            padding: 8px 20px;
            border-radius: 25px;
            font-size: 0.95em;
            font-weight: 600;
        }

        .recent-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
            max-height: 350px;
            overflow-y: auto;
            padding: 5px;
        }

        .recent-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 12px;
            border-left: 5px solid;
            transition: transform 0.2s;
            animation: fadeIn 0.3s;
        }
        .recent-item:hover { transform: translateX(-5px); }
        .recent-item.in { border-left-color: #28a745; }
        .recent-item.out { border-left-color: #dc3545; }
        .recent-item.break_in { border-left-color: #6f42c1; }
        .recent-item.break_out { border-left-color: #fd7e14; }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateX(-10px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .recent-icon {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.5em;
            flex-shrink: 0;
        }
        .icon-in { background: #d4edda; color: #28a745; }
        .icon-out { background: #f8d7da; color: #dc3545; }
        .icon-break-in { background: #e2d9f3; color: #6f42c1; }
        .icon-break-out { background: #cce5ff; color: #fd7e14; }

        .recent-info { flex: 1; }
        .recent-name { font-weight: 600; color: #333; font-size: 1.1em; margin-bottom: 5px; }
        .recent-time { font-size: 0.9em; color: #666; display: flex; gap: 10px; flex-wrap: wrap; }

        .time-badge {
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.85em;
            font-weight: 600;
        }
        .time-in { background: #d4edda; color: #28a745; }
        .time-out { background: #f8d7da; color: #dc3545; }
        .time-break-in { background: #e2d9f3; color: #6f42c1; }
        .time-break-out { background: #cce5ff; color: #fd7e14; }

        .hours-worked { font-size: 0.85em; color: #667eea; font-weight: 600; }

        /* Status Message */
        .status-message {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0,0,0,0.8);
            color: white;
            padding: 12px 25px;
            border-radius: 50px;
            font-size: 1em;
            font-weight: 500;
            display: none;
            z-index: 1000;
            animation: slideUp 0.3s;
            text-align: center;
            white-space: nowrap;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translate(-50%, 20px); }
            to { opacity: 1; transform: translate(-50%, 0); }
        }

        .status-message.show { display: block; }
        .status-message.success { background: #28a745; }
        .status-message.error { background: #dc3545; }
        .status-message.info { background: #17a2b8; }
        .status-message.warning { background: #ffc107; color: #333; }

        /* Quick actions */
        .quick-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .quick-btn {
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            background: #f0f0f0;
            color: #333;
            font-size: 0.9em;
            cursor: pointer;
            transition: all 0.3s;
        }
        .quick-btn:hover { background: #667eea; color: white; }

        /* Message */
        .message {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 25px;
            border-radius: 10px;
            color: white;
            font-weight: 500;
            z-index: 1000;
            animation: slideInRight 0.3s;
            display: none;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }

        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .message.success { background: #28a745; }
        .message.error { background: #dc3545; }
        .message.info { background: #17a2b8; }

        /* Modal */
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
            animation: modalSlideIn 0.3s;
        }

        @keyframes modalSlideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-title { font-size: 1.3em; font-weight: 600; color: #333; margin-bottom: 20px; text-align: center; }
        .modal-input { width: 100%; padding: 15px; border: 2px solid #e0e0e0; border-radius: 10px; font-size: 16px; margin-bottom: 20px; transition: border-color 0.3s; }
        .modal-input:focus { outline: none; border-color: #667eea; }

        .modal-buttons { display: flex; gap: 10px; }
        .modal-btn { flex: 1; padding: 12px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.3s; font-size: 16px; }
        .btn-confirm { background: #667eea; color: white; }
        .btn-confirm:hover { background: #5a67d8; }
        .btn-cancel { background: #6c757d; color: white; }
        .btn-cancel:hover { background: #5a6268; }

        /* Workflow indicator */
        .workflow-steps {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 5px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 10px;
            margin-bottom: 15px;
            font-size: 0.8em;
            flex-wrap: wrap;
        }
        .workflow-step {
            padding: 4px 12px;
            border-radius: 15px;
            font-weight: 600;
        }
        .workflow-step.active {
            background: #667eea;
            color: white;
        }
        .workflow-step.done {
            background: #d4edda;
            color: #155724;
        }
        .workflow-arrow {
            color: #999;
        }

        /* Mirror toggle button */
        .mirror-toggle {
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            background: #6c757d;
            color: white;
            font-size: 0.9em;
            cursor: pointer;
            transition: all 0.3s;
        }
        .mirror-toggle:hover {
            background: #5a6268;
        }
        .mirror-toggle.active {
            background: #28a745;
        }

        @media (max-width: 768px) {
            .scanner-grid { grid-template-columns: 1fr; }
            h1 { font-size: 2em; }
            .header-buttons { justify-content: center; }
            .mode-buttons { flex-direction: column; align-items: center; }
            .mode-btn { width: 100%; justify-content: center; }
            .workflow-steps { flex-direction: column; gap: 5px; }
            .workflow-arrow { transform: rotate(90deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>QR Attendance Scanner</h1>

        <div class="header-buttons">
            <a href="dashboard.php" class="btn btn-back">← Dashboard</a>
            <a href="employee_schedule.php" class="btn btn-schedule">📅 View Work Schedule</a>
        </div>
        <!-- Mode Selection Buttons - Order: Time In, Break Out, Break In, Time Out -->
        <div class="mode-buttons">
            <a href="scanner.php?mode=in" class="mode-btn in <?php echo $_SESSION['scan_mode'] == 'in' ? 'active' : ''; ?>">
                <span>⏰</span> Time In
                <?php if ($_SESSION['scan_mode'] == 'in'): ?><span class="mode-badge">ACTIVE</span><?php endif; ?>
            </a>
            <a href="scanner.php?mode=break_out" class="mode-btn break-out <?php echo $_SESSION['scan_mode'] == 'break_out' ? 'active' : ''; ?>">
                <span>🚶</span> Break Out
                <?php if ($_SESSION['scan_mode'] == 'break_out'): ?><span class="mode-badge">ACTIVE</span><?php endif; ?>
            </a>
            <a href="scanner.php?mode=break_in" class="mode-btn break-in <?php echo $_SESSION['scan_mode'] == 'break_in' ? 'active' : ''; ?>">
                <span>🔄</span> Break In
                <?php if ($_SESSION['scan_mode'] == 'break_in'): ?><span class="mode-badge">ACTIVE</span><?php endif; ?>
            </a>
            <a href="scanner.php?mode=out" class="mode-btn out <?php echo $_SESSION['scan_mode'] == 'out' ? 'active' : ''; ?>">
                <span>🏁</span> Time Out
                <?php if ($_SESSION['scan_mode'] == 'out'): ?><span class="mode-badge">ACTIVE</span><?php endif; ?>
            </a>
        </div>

        <div class="scanner-grid">
            <!-- Scanner Section -->
            <div class="scanner-container">
                <div class="scanner-header">
                    <span class="scanner-title">📷 QR Scanner</span>
                    <span class="current-mode" id="currentMode">
                        <?php 
                            $mode = $_SESSION['scan_mode'];
                            if ($mode == 'in') echo '⏰ TIME IN';
                            elseif ($mode == 'out') echo '🏁 TIME OUT';
                            elseif ($mode == 'break_in') echo '🔄 BREAK IN';
                            else echo '🚶 BREAK OUT';
                        ?>
                    </span>
                    <span class="camera-status" id="cameraStatus">Camera Active</span>
                </div>
                
                <div id="scanner-container">
                    <video id="video" playsinline></video>
                    <div class="scanner-overlay"></div>
                </div>

                <div class="camera-controls">
                    <button class="btn-camera start" onclick="startCamera()">▶ Start Camera</button>
                    <button class="btn-camera stop" onclick="stopCamera()" disabled>⏹ Stop Camera</button>
                </div>

                <div class="quick-actions">
                    <button class="quick-btn" onclick="resetScanState()">🔄 Reset Scanner</button>
                </div>

                <!-- Debug Panel -->
                <div class="debug-panel" id="debugPanel">
                    <div class="debug-title">🔍 Debug Info:</div>
                    <div class="debug-content" id="debugContent">No scans yet</div>
                </div>
            </div>

            <!-- Result Section -->
            <div class="result-container">
                <div class="result-header">📋 Scan Result</div>
                
                <div id="scanMessage">
                    <div>👆</div>
                    <div>Point camera at QR code</div>
                    <div id="scanStatus">Current mode: <?php 
                        $mode = $_SESSION['scan_mode'];
                        if ($mode == 'in') echo '⏰ TIME IN';
                        elseif ($mode == 'out') echo '🏁 TIME OUT';
                        elseif ($mode == 'break_in') echo '🔄 BREAK IN';
                        else echo '🚶 BREAK OUT';
                    ?></div>
                </div>

                <div id="employeeCard"></div>
                <div id="recentScan" style="margin-top: 10px; font-size: 0.9em; color: #666; text-align: center;"></div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="recent-container">
            <div class="recent-header">
                <span class="recent-title">📊 Today's Activity</span>
                <span class="summary-badge">
                    <?php echo $today_summary['total_today'] ?? 0; ?> Scans | 
                    <?php echo $work_summary['present_today'] ?? 0; ?> Present |
                    <?php echo $break_summary['total_breaks'] ?? 0; ?> Breaks
                </span>
            </div>
            <div class="recent-grid" id="recentGrid">
                <?php if (!empty($recent_attendance)): ?>
                    <?php foreach ($recent_attendance as $record): ?>
                    <div class="recent-item <?php 
                        if ($record['time_out']) echo 'out'; 
                        else echo 'in'; 
                    ?>">
                        <div class="recent-icon <?php 
                            if ($record['time_out']) echo 'icon-out'; 
                            else echo 'icon-in'; 
                        ?>">
                            <?php echo $record['time_out'] ? '🏁' : '⏰'; ?>
                        </div>
                        <div class="recent-info">
                            <div class="recent-name"><?php echo htmlspecialchars($record['first_name'] . ' ' . $record['last_name']); ?></div>
                            <div class="recent-time">
                                <span class="time-badge time-in">IN: <?php echo date('h:i A', strtotime($record['time_in'])); ?></span>
                                <?php if ($record['time_out']): ?>
                                <span class="time-badge time-out">OUT: <?php echo date('h:i A', strtotime($record['time_out'])); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($record['hours_worked'] > 0): ?>
                                <div class="hours-worked">⏱️ Work: <?php echo $record['hours_worked']; ?> hrs</div>
                            <?php endif; ?>
                            <?php if (($record['break_count'] ?? 0) > 0): ?>
                                <div class="hours-worked" style="color: #6f42c1;">☕ Breaks: <?php echo $record['break_count']; ?> (<?php echo $record['total_break_minutes'] ?? 0; ?> min)</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="grid-column: 1/-1; text-align: center; padding: 30px; color: #999; background: #f8f9fa; border-radius: 10px;">
                        No attendance records for today
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Status Message (Bottom Center) -->
    <div id="statusMessage" class="status-message"></div>

    <!-- Manual Entry Modal -->
    <div id="manualModal" class="modal">
        <div class="modal-content">
            <div class="modal-title">📝 Manual Attendance Entry</div>
            <div style="text-align: center; margin-bottom: 15px; color: #666;">
                Current Mode: <strong style="color: <?php 
                    $mode = $_SESSION['scan_mode'];
                    if ($mode == 'in') echo '#28a745';
                    elseif ($mode == 'out') echo '#dc3545';
                    elseif ($mode == 'break_in') echo '#6f42c1';
                    else echo '#fd7e14';
                ?>">
                    <?php 
                        $mode = $_SESSION['scan_mode'];
                        if ($mode == 'in') echo '⏰ TIME IN';
                        elseif ($mode == 'out') echo '🏁 TIME OUT';
                        elseif ($mode == 'break_in') echo '🔄 BREAK IN';
                        else echo '🚶 BREAK OUT';
                    ?>
                </strong>
            </div>
            <input type="text" id="manualEmployeeId" class="modal-input" placeholder="Enter Employee ID (e.g., EMP001)" autocomplete="off">
            <div class="modal-buttons">
                <button class="modal-btn btn-confirm" onclick="submitManualEntry()">Record <?php 
                    $mode = $_SESSION['scan_mode'];
                    if ($mode == 'in') echo 'Time In';
                    elseif ($mode == 'out') echo 'Time Out';
                    elseif ($mode == 'break_in') echo 'Break In';
                    else echo 'Break Out';
                ?></button>
                <button class="modal-btn btn-cancel" onclick="hideManualEntry()">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Notification Message (Top Right) -->
    <div id="message" class="message"></div>

    <!-- Include jsQR library -->
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>

    <script>
        let video = document.getElementById('video');
        let scanning = false;
        let currentStream = null;
        let lastScan = '';
        let canScan = true;
        let scanCount = 0;
        let processingScan = false;
        let currentMode = '<?php echo $_SESSION['scan_mode']; ?>';
        let isMirrored = true; // Default to mirrored

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            checkCameraSupport();
            setTimeout(startCamera, 500);
        });

        function checkCameraSupport() {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                showMessage('Your browser does not support camera access', 'error');
                document.querySelector('.btn-camera.start').disabled = true;
            }
        }

        async function startCamera() {
            try {
                const constraints = {
                    video: {
                        facingMode: 'environment',
                        width: { ideal: 1280 },
                        height: { ideal: 720 }
                    }
                };

                showStatus('Requesting camera access...', 'info');
                
                currentStream = await navigator.mediaDevices.getUserMedia(constraints);
                video.srcObject = currentStream;
                video.setAttribute('playsinline', true);
                
                await new Promise((resolve) => {
                    video.onloadedmetadata = () => {
                        video.play();
                        resolve();
                    };
                });

                document.querySelector('.btn-camera.start').disabled = true;
                document.querySelector('.btn-camera.stop').disabled = false;
                document.getElementById('cameraStatus').classList.add('active');
                document.getElementById('scanMessage').classList.add('scanning');
                
                scanning = true;
                canScan = true;
                lastScan = '';
                scanQRCode();
                
                showStatus('Camera started successfully', 'success');
                showMessage('Camera ready - scan QR codes', 'success');

            } catch (err) {
                console.error('Camera error:', err);
                showStatus('Error accessing camera: ' + err.message, 'error');
                showMessage('Camera error: ' + err.message, 'error');
            }
        }

        function stopCamera() {
            if (currentStream) {
                currentStream.getTracks().forEach(track => track.stop());
                currentStream = null;
                scanning = false;
                canScan = false;

                document.querySelector('.btn-camera.start').disabled = false;
                document.querySelector('.btn-camera.stop').disabled = true;
                document.getElementById('cameraStatus').classList.remove('active');
                document.getElementById('scanMessage').classList.remove('scanning');
                
                showStatus('Camera stopped', 'info');
                showMessage('Camera stopped', 'info');
            }
        }

        // Toggle mirror effect
        function toggleMirror() {
            isMirrored = !isMirrored;
            const toggleBtn = document.getElementById('mirrorToggle');
            
            if (isMirrored) {
                video.style.transform = 'scaleX(-1)';
                video.style.webkitTransform = 'scaleX(-1)';
                video.style.mozTransform = 'scaleX(-1)';
                video.style.msTransform = 'scaleX(-1)';
                video.style.oTransform = 'scaleX(-1)';
                toggleBtn.textContent = '🔄 Mirror';
                toggleBtn.classList.add('active');
                showStatus('Mirror mode ON', 'info');
            } else {
                video.style.transform = 'scaleX(1)';
                video.style.webkitTransform = 'scaleX(1)';
                video.style.mozTransform = 'scaleX(1)';
                video.style.msTransform = 'scaleX(1)';
                video.style.oTransform = 'scaleX(1)';
                toggleBtn.textContent = '🔄 Mirror';
                toggleBtn.classList.remove('active');
                showStatus('Mirror mode OFF', 'info');
            }
        }

        function resetScanState() {
            lastScan = '';
            canScan = true;
            processingScan = false;
            showStatus('Scanner reset - ready for new scans', 'success');
            showMessage('Scanner reset', 'success');
            const modeLabels = {
                'in': '⏰ TIME IN',
                'out': '🏁 TIME OUT',
                'break_in': '🔄 BREAK IN',
                'break_out': '🚶 BREAK OUT'
            };
            document.getElementById('scanStatus').textContent = 'Current mode: ' + (modeLabels[currentMode] || 'UNKNOWN');
        }

        function scanQRCode() {
            if (!scanning || !canScan || processingScan) {
                requestAnimationFrame(scanQRCode);
                return;
            }

            if (video.readyState === video.HAVE_ENOUGH_DATA) {
                const width = 640;
                const height = 480;
                
                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;
                
                const context = canvas.getContext('2d');
                
                // Draw the video frame (mirrored for display, but QR scanning works on the original data)
                // jsQR can handle mirrored images, so we draw it normally
                context.drawImage(video, 0, 0, width, height);
                
                const imageData = context.getImageData(0, 0, width, height);
                
                const code = jsQR(imageData.data, width, height, {
                    inversionAttempts: "dontInvert",
                });

                if (code && !processingScan) {
                    showDebug('QR Detected: ' + code.data.substring(0, 100) + (code.data.length > 100 ? '...' : ''));
                    
                    if (code.data !== lastScan) {
                        lastScan = code.data;
                        processScan(code.data);
                        
                        const colors = {
                            'in': '#28a745',
                            'out': '#dc3545',
                            'break_in': '#6f42c1',
                            'break_out': '#fd7e14'
                        };
                        document.querySelector('.scanner-overlay').style.borderColor = colors[currentMode] || '#667eea';
                        setTimeout(() => {
                            document.querySelector('.scanner-overlay').style.borderColor = colors[currentMode] || '#667eea';
                        }, 200);
                        
                        playBeep();
                    }
                }
            }

            requestAnimationFrame(scanQRCode);
        }

        function processScan(content) {
            processingScan = true;
            
            try {
                console.log("Raw QR Content:", content);
                showDebug('Raw: ' + content);
                
                let employeeId = null;
                
                if (content.includes('EMPLOYEE_ID:')) {
                    const match = content.match(/EMPLOYEE_ID:([^|]+)/);
                    if (match && match[1]) {
                        employeeId = match[1].trim();
                        showDebug('Extracted ID: ' + employeeId + ' (from pipe format)');
                    }
                }
                else {
                    try {
                        const data = JSON.parse(content);
                        employeeId = data.employee_id || data.id || data.emp_id;
                        if (employeeId) {
                            showDebug('Extracted ID: ' + employeeId + ' (from JSON)');
                        }
                    } catch (e) {
                        if (content.match(/^[A-Z0-9]+$/)) {
                            employeeId = content;
                            showDebug('Using raw content as ID: ' + employeeId);
                        }
                    }
                }

                if (employeeId) {
                    const modeLabels = {
                        'in': '⏰ TIME IN',
                        'out': '🏁 TIME OUT',
                        'break_in': '🔄 BREAK IN',
                        'break_out': '🚶 BREAK OUT'
                    };
                    document.getElementById('scanStatus').textContent = 'Processing: ' + employeeId + ' for ' + (modeLabels[currentMode] || 'UNKNOWN');
                    showStatus('Processing scan for ID: ' + employeeId, 'info');
                    recordAttendance(employeeId);
                } else {
                    showDebug('❌ Could not extract employee ID');
                    showStatus('Invalid QR code format', 'error');
                    showMessage('Invalid QR format - no employee ID found', 'error');
                    processingScan = false;
                }
            } catch (e) {
                console.error('Scan error:', e);
                showDebug('Error: ' + e.message);
                showStatus('Error processing QR code', 'error');
                processingScan = false;
            }
        }

        function showDebug(message) {
            const debugPanel = document.getElementById('debugPanel');
            const debugContent = document.getElementById('debugContent');
            
            debugPanel.classList.add('active');
            debugContent.textContent = message + ' [' + new Date().toLocaleTimeString() + ']';
            
            setTimeout(() => {
                debugPanel.classList.remove('active');
            }, 10000);
        }

        function showStatus(text, type) {
            const statusEl = document.getElementById('statusMessage');
            statusEl.textContent = text;
            statusEl.className = 'status-message show ' + type;
            
            setTimeout(() => {
                statusEl.classList.remove('show');
            }, 3000);
        }

        function recordAttendance(employeeId) {
            const formData = new URLSearchParams();
            formData.append('action', 'record_attendance');
            formData.append('employee_id', employeeId);
            formData.append('scan_type', currentMode);

            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData.toString()
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    showStatus(data.message, 'success');
                    showMessage(data.message, 'success');
                    displayAttendanceResult(data);
                    addToRecent(data);
                    
                    setTimeout(() => {
                        processingScan = false;
                        lastScan = '';
                        const modeLabels = {
                            'in': '⏰ TIME IN',
                            'out': '🏁 TIME OUT',
                            'break_in': '🔄 BREAK IN',
                            'break_out': '🚶 BREAK OUT'
                        };
                        document.getElementById('scanStatus').textContent = 'Ready for next ' + (modeLabels[currentMode] || 'UNKNOWN');
                        showStatus('Ready for next scan', 'info');
                    }, 2000);
                } else {
                    showStatus(data.message, 'error');
                    showMessage(data.message, 'error');
                    processingScan = false;
                    lastScan = '';
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                showStatus('Error recording attendance', 'error');
                showMessage('Error: ' + error.message, 'error');
                processingScan = false;
                lastScan = '';
            });
        }

        function displayAttendanceResult(data) {
            const employeeCard = document.getElementById('employeeCard');
            const scanMessage = document.getElementById('scanMessage');
            const recentScan = document.getElementById('recentScan');
            
            scanCount++;
            
            const typeDisplay = {
                'in': '⏰ TIME IN',
                'out': '🏁 TIME OUT',
                'break_in': '🔄 BREAK IN (End Break)',
                'break_out': '🚶 BREAK OUT (Start Break)'
            };
            
            const breakInfo = data.break_duration ? `<div style="margin-top:10px;font-size:0.9em;">⏱️ Break duration: ${data.break_duration} minutes</div>` : '';
            const hoursInfo = data.hours_worked ? `<div style="margin-top:10px;font-size:0.9em;">⏱️ Hours worked: ${data.hours_worked} hrs</div>` : '';
            const breakMinutesInfo = data.break_minutes ? `<div style="margin-top:10px;font-size:0.9em;">☕ Total break time: ${data.break_minutes} minutes</div>` : '';
            
            const resultHtml = `
                <div class="employee-card">
                    <div class="employee-name">${data.employee}</div>
                    
                    <div class="employee-details">
                        <div class="detail-item">
                            <div class="detail-label">Employee ID</div>
                            <div class="detail-value">${data.employee_id}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Position</div>
                            <div class="detail-value">${data.position}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Department</div>
                            <div class="detail-value">${data.department}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">${data.type === 'in' ? 'Time In' : data.type === 'out' ? 'Time Out' : data.type === 'break_in' ? 'Break In (End Break)' : 'Break Out (Start Break)'}</div>
                            <div class="detail-value">${data.time}</div>
                        </div>
                    </div>

                    <div class="attendance-result ${data.type}">
                        <div style="font-size: 2em; margin-bottom: 5px;">
                            ${typeDisplay[data.type] || data.type}
                        </div>
                        ${breakInfo}
                        ${hoursInfo}
                        ${breakMinutesInfo}
                    </div>
                    
                    <div class="payroll-badge">
                        ${data.type === 'break_in' || data.type === 'break_out' ? 
                            '☕ Break time is NOT counted towards salary/payroll' : 
                            '✅ Synced with payroll system'}
                    </div>
                </div>
            `;
            
            employeeCard.innerHTML = resultHtml;
            employeeCard.style.display = 'block';
            scanMessage.style.display = 'none';
            
            recentScan.innerHTML = `Last scan: ${data.employee} (${data.type}) at ${new Date().toLocaleTimeString()}`;
            
            setTimeout(() => {
                employeeCard.style.display = 'none';
                scanMessage.style.display = 'block';
            }, 6000);
        }

        function addToRecent(data) {
            const recentGrid = document.getElementById('recentGrid');
            
            if (recentGrid.children.length === 1 && recentGrid.children[0].innerHTML.includes('No attendance records')) {
                recentGrid.innerHTML = '';
            }
            
            const item = document.createElement('div');
            item.className = `recent-item ${data.type}`;
            
            const now = new Date();
            const timeStr = now.toLocaleTimeString();
            
            const icons = {
                'in': '⏰',
                'out': '🏁',
                'break_in': '🔄',
                'break_out': '🚶'
            };
            
            const iconClasses = {
                'in': 'icon-in',
                'out': 'icon-out',
                'break_in': 'icon-break-in',
                'break_out': 'icon-break-out'
            };
            
            const timeClasses = {
                'in': 'time-in',
                'out': 'time-out',
                'break_in': 'time-break-in',
                'break_out': 'time-break-out'
            };
            
            const labels = {
                'in': 'TIME IN',
                'out': 'TIME OUT',
                'break_in': 'BREAK IN',
                'break_out': 'BREAK OUT'
            };
            
            item.innerHTML = `
                <div class="recent-icon ${iconClasses[data.type] || 'icon-in'}">
                    ${icons[data.type] || '⏰'}
                </div>
                <div class="recent-info">
                    <div class="recent-name">${data.employee}</div>
                    <div class="recent-time">
                        <span class="time-badge ${timeClasses[data.type] || 'time-in'}">
                            ${labels[data.type] || data.type}: ${data.time}
                        </span>
                    </div>
                    ${data.break_duration ? `<div class="hours-worked" style="color:#fd7e14;">⏱️ Break: ${data.break_duration} min</div>` : ''}
                    ${data.hours_worked ? `<div class="hours-worked">⏱️ Hours: ${data.hours_worked} hrs</div>` : ''}
                </div>
            `;
            
            recentGrid.insertBefore(item, recentGrid.firstChild);
            
            while (recentGrid.children.length > 20) {
                recentGrid.removeChild(recentGrid.lastChild);
            }
        }

        function playBeep() {
            try {
                const audioContext = new (window.AudioContext || window.webkitAudioContext)();
                const oscillator = audioContext.createOscillator();
                const gainNode = audioContext.createGain();
                
                oscillator.connect(gainNode);
                gainNode.connect(audioContext.destination);
                
                oscillator.frequency.setValueAtTime(1000, audioContext.currentTime);
                gainNode.gain.setValueAtTime(0.1, audioContext.currentTime);
                
                oscillator.start();
                oscillator.stop(audioContext.currentTime + 0.1);
            } catch (e) {
                console.log('Beep failed:', e);
            }
        }

        function showMessage(text, type) {
            const message = document.getElementById('message');
            message.textContent = text;
            message.className = `message ${type}`;
            message.style.display = 'block';
            
            setTimeout(() => {
                message.style.display = 'none';
            }, 3000);
        }

        function showManualEntry() {
            document.getElementById('manualModal').classList.add('show');
            document.getElementById('manualEmployeeId').focus();
        }

        function hideManualEntry() {
            document.getElementById('manualModal').classList.remove('show');
            document.getElementById('manualEmployeeId').value = '';
        }

        function submitManualEntry() {
            const employeeId = document.getElementById('manualEmployeeId').value.trim();
            
            if (!employeeId) {
                showMessage('Please enter Employee ID', 'error');
                return;
            }
            
            hideManualEntry();
            recordAttendance(employeeId);
        }

        document.getElementById('manualEmployeeId').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                submitManualEntry();
            }
        });

        window.addEventListener('beforeunload', function() {
            stopCamera();
        });
    </script>
</body>
</html>