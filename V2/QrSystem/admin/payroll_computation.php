<?php
require_once 'admin_auth.php'; 
require_once 'configa.php';

// Auto-create overtime table if missing (safety net)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS overtime_approvals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id VARCHAR(50) NOT NULL,
        date DATE NOT NULL,
        overtime_hours DECIMAL(5,2) NOT NULL DEFAULT 0,
        approved_by VARCHAR(100) NOT NULL,
        approved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        notes TEXT NULL,
        status ENUM('approved','cancelled') DEFAULT 'approved',
        UNIQUE KEY uniq_ot (employee_id, date)
    )");
} catch (PDOException $e) {
    error_log("Could not create overtime_approvals: " . $e->getMessage());
}

$selected_month = $_GET['month'] ?? date('Y-m');
$selected_employee = $_GET['employee'] ?? '';
$selected_salary_type = $_GET['salary_type'] ?? '';
$view_history = isset($_GET['history']) ? true : false;
$history_month = $_GET['history_month'] ?? date('Y-m');

// Get all employees with their settings including salary_type
$employees_sql = "SELECT e.*, eps.hourly_rate, eps.work_hours_per_day, eps.work_days_per_month,
                  eps.philhealth_rate, eps.sss_rate, eps.pagibig_rate, eps.overtime_rate,
                  eps.daily_rate, eps.monthly_salary,
                  e.salary_type
                  FROM employees e
                  LEFT JOIN employee_payroll_settings eps ON e.employee_id = eps.employee_id";

if (!empty($selected_salary_type)) {
    $employees_sql .= " WHERE e.salary_type = :salary_type";
}

$employees_sql .= " ORDER BY e.first_name";

$employees_stmt = $pdo->prepare($employees_sql);
if (!empty($selected_salary_type)) {
    $employees_stmt->execute(['salary_type' => $selected_salary_type]);
} else {
    $employees_stmt->execute();
}
$employees = $employees_stmt->fetchAll();

// Handle payroll computation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'compute_payroll') {
        $employee_id = $_POST['employee_id'] ?? '';
        $payroll_month = $_POST['payroll_month'] ?? '';
        
        // Get employee details including salary_type
        $empStmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ?");
        $empStmt->execute([$employee_id]);
        $employee_data = $empStmt->fetch();
        $salary_type = $employee_data['salary_type'] ?? 'Monthly';
        
        // Get employee settings
        $settings = $pdo->prepare("SELECT * FROM employee_payroll_settings WHERE employee_id = ?");
        $settings->execute([$employee_id]);
        $setting = $settings->fetch();
        
        if (!$setting) {
            $error = "Please set up payroll settings for this employee first.";
        } else {
            // Get attendance records for the month
            $start_date = $payroll_month . '-01';
            $end_date = date('Y-m-t', strtotime($payroll_month));
            
            // Get holidays for the month
            $holiday_stmt = $pdo->prepare("SELECT * FROM holidays WHERE holiday_date BETWEEN ? AND ?");
            $holiday_stmt->execute([$start_date, $end_date]);
            $month_holidays = [];
            while ($row = $holiday_stmt->fetch()) {
                $month_holidays[$row['holiday_date']] = $row;
            }
            
            // GET EMPLOYEE REST DAYS for this employee
            $rest_days_query = $pdo->prepare("
                SELECT day_of_week, custom_date FROM employee_rest_days 
                WHERE employee_id = ? 
                AND (is_recurring = 1 OR (custom_date BETWEEN ? AND ?))
            ");
            $rest_days_query->execute([$employee_id, $start_date, $end_date]);
            $employee_rest_days = [];
            while ($row = $rest_days_query->fetch()) {
                if ($row['day_of_week']) {
                    $employee_rest_days[$row['day_of_week']] = true;
                }
                if ($row['custom_date']) {
                    $employee_rest_days[$row['custom_date']] = true;
                }
            }
            
            // === GET APPROVED OVERTIME FOR THE MONTH ===
            $ot_stmt = $pdo->prepare("
                SELECT date, overtime_hours 
                FROM overtime_approvals 
                WHERE employee_id = ? 
                AND date BETWEEN ? AND ? 
                AND status = 'approved'
            ");
            $ot_stmt->execute([$employee_id, $start_date, $end_date]);
            $approved_overtime_records = [];
            while ($row = $ot_stmt->fetch()) {
                $approved_overtime_records[$row['date']] = (float)$row['overtime_hours'];
            }
            
            // Get ALL attendance records for the month (FIXED: pulls break_minutes)
            $attendanceStmt = $pdo->prepare("
                SELECT a.*, 
                       IFNULL((SELECT SUM(TIMESTAMPDIFF(MINUTE, break_in, IFNULL(break_out, NOW()))) 
                               FROM employee_breaks 
                               WHERE employee_id = a.employee_id AND date = a.date), 0) AS break_minutes
                FROM attendance a 
                WHERE a.employee_id = ? 
                AND a.date BETWEEN ? AND ?
                ORDER BY a.date, a.time_in
            ");
            $attendanceStmt->execute([$employee_id, $start_date, $end_date]);
            $attendance_records = $attendanceStmt->fetchAll();
            
            // Group attendance by date
            $daily_attendance = [];
            foreach ($attendance_records as $record) {
                $date = $record['date'];
                $day_of_week = date('l', strtotime($date));
                $is_rest_day = isset($employee_rest_days[$day_of_week]) || isset($employee_rest_days[$date]);
                
                if (!isset($daily_attendance[$date])) {
                    $daily_attendance[$date] = [
                        'time_in' => null,
                        'time_out' => null,
                        'breaks' => [],
                        'total_break_minutes' => $record['break_minutes'] ?? 0, // FIXED
                        'is_holiday' => isset($month_holidays[$date]),
                        'holiday_type' => isset($month_holidays[$date]) ? $month_holidays[$date]['holiday_type'] : null,
                        'holiday_name' => isset($month_holidays[$date]) ? $month_holidays[$date]['holiday_name'] : null,
                        'is_rest_day' => $is_rest_day
                    ];
                }
                
                // Track breaks
                if ($record['time_out'] && !$record['time_in']) {
                    if (!isset($daily_attendance[$date]['breaks'][$record['time_out']])) {
                        $daily_attendance[$date]['breaks'][$record['time_out']] = [
                            'break_out' => $record['time_out'],
                            'break_in' => null
                        ];
                    }
                }
                
                // Track time in
                if ($record['time_in']) {
                    $is_break_in = false;
                    foreach ($daily_attendance[$date]['breaks'] as &$break) {
                        if ($break['break_in'] === null && $break['break_out'] < $record['time_in']) {
                            $break['break_in'] = $record['time_in'];
                            $is_break_in = true;
                            break;
                        }
                    }
                    
                    if (!$is_break_in) {
                        if ($daily_attendance[$date]['time_in'] === null) {
                            $daily_attendance[$date]['time_in'] = $record['time_in'];
                        }
                    }
                }
                
                // Track time out
                if ($record['time_out']) {
                    $is_break_out = false;
                    foreach ($daily_attendance[$date]['breaks'] as $break) {
                        if ($break['break_out'] == $record['time_out']) {
                            $is_break_out = true;
                            break;
                        }
                    }
                    
                    if (!$is_break_out) {
                        $daily_attendance[$date]['time_out'] = $record['time_out'];
                    }
                }
            }
            
            // Calculate total work hours per day
            $total_work_days = 0;
            $total_hours = 0;
            $total_overtime = 0;
            $total_regular_pay = 0;
            $total_overtime_pay = 0;
            $total_holiday_pay = 0;
            $total_holiday_hours = 0;
            $total_holiday_days = 0;
            $daily_breakdown = [];
            
            // Default work hours per day from settings
            $default_hours_per_day = $setting['work_hours_per_day'] ?? 8;
            $hourly_rate = $setting['hourly_rate'] ?? 0;
            $overtime_rate = $setting['overtime_rate'] ?? 1.25;
            
            // Hourly rate is constant regardless of payout frequency
            $adjusted_hourly_rate = $hourly_rate;
            
            foreach ($daily_attendance as $date => $day_data) {
                $time_in = $day_data['time_in'];
                $time_out = $day_data['time_out'];
                
                // Skip if no time_in
                if (!$time_in) {
                    continue;
                }
                
                // Calculate break minutes (FIXED: from table)
                $total_break_minutes = $day_data['total_break_minutes'] ?? 0;
                
                // Calculate total hours worked for the day
                $time_in_ts = strtotime($time_in);
                
                if (!$time_out) {
                    $time_out_ts = strtotime(date('Y-m-d 17:00:00'));
                } else {
                    $time_out_ts = strtotime($time_out);
                }
                
                $total_minutes = ($time_out_ts - $time_in_ts) / 60;
                $work_minutes = max($total_minutes - $total_break_minutes, 0);
                $work_hours = round($work_minutes / 60, 2);
                
                // === DETERMINE OVERTIME BASED ON APPROVAL ===
                $raw_overtime_hours = 0;
                if ($work_hours > $default_hours_per_day) {
                    $raw_overtime_hours = $work_hours - $default_hours_per_day;
                }
                
                // Check if there is approved OT for this date
                $approved_ot_hours = $approved_overtime_records[$date] ?? 0;
                
                // Only pay the minimum of raw OT and approved OT
                $overtime_hours = min($raw_overtime_hours, $approved_ot_hours);
                
                // Regular hours = total work hours minus paid overtime hours
                $regular_hours = max(0, $work_hours - $overtime_hours);
                // ===================================================
                
                // Check if this is a holiday
                $is_holiday = $day_data['is_holiday'] ?? false;
                $holiday_type = $day_data['holiday_type'] ?? null;
                $holiday_name = $day_data['holiday_name'] ?? '';
                $is_rest_day = $day_data['is_rest_day'] ?? false;
                
                // Calculate holiday pay - ONLY IF NOT A REST DAY
                $holiday_pay = 0;
                $holiday_pay_rate = 1;
                $holiday_regular_hours = 0;
                $is_holiday_worked = false;
                
                // IMPORTANT: Holiday pay only applies if NOT a rest day
                if ($is_holiday && !$is_rest_day && $work_hours > 0) {
                    $is_holiday_worked = true;
                    if ($holiday_type == 'regular') {
                        $holiday_pay_rate = 2; // Double pay
                    } elseif ($holiday_type == 'special') {
                        $holiday_pay_rate = 1.3; // 30% additional
                    }
                    
                    // Holiday pay applies to regular hours only (excluding overtime)
                    $holiday_regular_hours = $regular_hours;
                    $holiday_pay = $holiday_regular_hours * $adjusted_hourly_rate * $holiday_pay_rate;
                    
                    $total_holiday_days++;
                    // FIX: Only add regular hours to prevent double subtraction
                    $total_holiday_hours += $regular_hours; 
                    $total_holiday_pay += $holiday_pay;
                }
                
                // Calculate regular pay (excluding holiday pay)
                $overtime_pay = $overtime_hours * $adjusted_hourly_rate * $overtime_rate;
                
                // If holiday worked (and not rest day), regular pay is replaced by holiday pay
                if ($is_holiday_worked) {
                    $regular_pay = 0; // Holiday pay replaces regular pay
                    $day_total_pay = $holiday_pay + $overtime_pay;
                } else {
                    $regular_pay = $regular_hours * $adjusted_hourly_rate;
                    $day_total_pay = $regular_pay + $overtime_pay;
                }
                
                // Store daily breakdown
                $daily_breakdown[] = [
                    'date' => $date,
                    'time_in' => $time_in,
                    'time_out' => $time_out,
                    'break_minutes' => $total_break_minutes,
                    'work_hours' => $work_hours,
                    'regular_hours' => $regular_hours,
                    'overtime_hours' => $overtime_hours,
                    'regular_pay' => $regular_pay,
                    'overtime_pay' => $overtime_pay,
                    'holiday_pay' => $holiday_pay,
                    'holiday_name' => $holiday_name,
                    'holiday_type' => $holiday_type,
                    'is_holiday' => $is_holiday,
                    'is_rest_day' => $is_rest_day,
                    'holiday_pay_rate' => $holiday_pay_rate,
                    'total_pay' => $day_total_pay
                ];
                
                $total_work_days++;
                $total_hours += $work_hours;
                $total_overtime += $overtime_hours;
                $total_regular_pay += $regular_pay;
                $total_overtime_pay += $overtime_pay;
            }
            
            $gross_pay = $total_regular_pay + $total_overtime_pay + $total_holiday_pay;
            
            // Compute mandatory deductions
            $philhealth = $gross_pay * (($setting['philhealth_rate'] ?? 0) / 100);
            $sss = $gross_pay * (($setting['sss_rate'] ?? 0) / 100);
            $pagibig = $gross_pay * (($setting['pagibig_rate'] ?? 0) / 100);
            
            // ----- Fetch custom deductions for this employee -----
            $custom_deductions_list = [];
            $custom_deductions_total = 0;
            $ded_stmt = $pdo->prepare("SELECT * FROM employee_custom_deductions WHERE employee_id = ?");
            $ded_stmt->execute([$employee_id]);
            $custom_deductions = $ded_stmt->fetchAll();

            foreach ($custom_deductions as $cd) {
                $amount = 0;
                if ($cd['deduction_type'] == 'fixed') {
                    $amount = floatval($cd['deduction_value']);
                } elseif ($cd['deduction_type'] == 'percent') {
                    $amount = $gross_pay * (floatval($cd['deduction_value']) / 100);
                }
                $custom_deductions_total += $amount;
                $custom_deductions_list[] = [
                    'name'    => $cd['deduction_name'],
                    'type'    => $cd['deduction_type'],
                    'value'   => floatval($cd['deduction_value']),
                    'amount'  => round($amount, 2),
                    'remarks' => $cd['remarks']
                ];
            }

            // Add custom deductions to total deductions
            $total_deductions = $philhealth + $sss + $pagibig + $custom_deductions_total;
            $custom_deductions_json = json_encode($custom_deductions_list);
            $net_pay = $gross_pay - $total_deductions;
            
            // Check if payroll record already exists
            $check = $pdo->prepare("SELECT id, status FROM payroll_records WHERE employee_id = ? AND payroll_month = ?");
            $check->execute([$employee_id, $payroll_month . '-01']);
            $existing = $check->fetch();
            
            if ($existing) {
                if ($existing['status'] != 'paid') {
                    $update = $pdo->prepare("UPDATE payroll_records 
                                             SET work_days = ?, work_hours = ?, overtime_hours = ?,
                                                 gross_pay = ?, philhealth_deduction = ?, sss_deduction = ?,
                                                 pagibig_deduction = ?, total_deductions = ?, net_pay = ?,
                                                 holiday_pay = ?, holiday_hours = ?, holiday_days = ?,
                                                 status = 'computed', computed_at = NOW(),
                                                 daily_breakdown = ?, salary_type = ?,
                                                 custom_deductions_breakdown = ?
                                             WHERE id = ?");
                    $update->execute([
                        $total_work_days, $total_hours, $total_overtime,
                        $gross_pay, $philhealth, $sss, $pagibig, $total_deductions, $net_pay,
                        $total_holiday_pay, $total_holiday_hours, $total_holiday_days,
                        json_encode($daily_breakdown), $salary_type,
                        $custom_deductions_json,
                        $existing['id']
                    ]);
                    $success = "Payroll computed successfully! Total: ₱" . number_format($net_pay, 2);
                } else {
                    $error = "This payroll has already been paid. Cannot recompute paid payroll.";
                }
            } else {
                $insert = $pdo->prepare("INSERT INTO payroll_records 
                                         (employee_id, payroll_month, work_days, work_hours, overtime_hours,
                                          gross_pay, philhealth_deduction, sss_deduction, pagibig_deduction,
                                          total_deductions, net_pay, holiday_pay, holiday_hours, holiday_days,
                                          status, computed_at, daily_breakdown, salary_type, custom_deductions_breakdown)
                                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'computed', NOW(), ?, ?, ?)");
                $insert->execute([
                    $employee_id, $payroll_month . '-01', $total_work_days, $total_hours, $total_overtime,
                    $gross_pay, $philhealth, $sss, $pagibig, $total_deductions, $net_pay,
                    $total_holiday_pay, $total_holiday_hours, $total_holiday_days,
                    json_encode($daily_breakdown), $salary_type, $custom_deductions_json
                ]);
                $success = "Payroll computed successfully! Total: ₱" . number_format($net_pay, 2);
            }
        }
    }
    
    // ============ 13TH MONTH PAY COMPUTATION ============
    if ($_POST['action'] == 'compute_13th_month') {
        $employee_id = $_POST['employee_id'] ?? '';
        $year = $_POST['year'] ?? date('Y');
        
        // Get employee details and settings
        $empStmt = $pdo->prepare("
            SELECT e.*, eps.hourly_rate 
            FROM employees e 
            LEFT JOIN employee_payroll_settings eps ON e.employee_id = eps.employee_id 
            WHERE e.employee_id = ?
        ");
        $empStmt->execute([$employee_id]);
        $employee_data = $empStmt->fetch();
        
        if (!$employee_data) {
            $error = "Employee not found.";
        } else {
            // Get all payroll records for the year
            $payroll_stmt = $pdo->prepare("
                SELECT * FROM payroll_records 
                WHERE employee_id = ? 
                AND payroll_month LIKE ?
                AND status = 'paid'
                AND (thirteenth_month_pay IS NULL OR thirteenth_month_pay = 0)
                ORDER BY payroll_month
            ");
            $payroll_stmt->execute([$employee_id, $year . '-%']);
            $payroll_records = $payroll_stmt->fetchAll();
            
            $history_stmt = $pdo->prepare("
                SELECT * FROM payroll_history 
                WHERE employee_id = ? 
                AND payroll_month LIKE ? 
                AND (thirteenth_month_pay IS NULL OR thirteenth_month_pay = 0)
                ORDER BY payroll_month
            ");
            $history_stmt->execute([$employee_id, $year . '-%']);
            $history_records = $history_stmt->fetchAll();
            
            $all_records = array_merge($payroll_records, $history_records);
            
            if (empty($all_records)) {
                $error = "No paid payroll records found for this employee in $year. Please compute and pay at least one month first.";
            } else {
                $total_basic_salary = 0;
                $months_worked = 0;
                $monthly_basic = [];
                
                foreach ($all_records as $record) {
                    $gross = $record['gross_pay'] ?? 0;
                    $holiday_pay = $record['holiday_pay'] ?? 0;
                    $overtime_hours = $record['overtime_hours'] ?? 0;
                    $hourly_rate = $employee_data['hourly_rate'] ?? 0;
                    
                    $overtime_pay = $overtime_hours * ($hourly_rate * 1.25);
                    $basic_salary = $gross - $holiday_pay - $overtime_pay;
                    
                    if ($basic_salary <= 0) {
                        $basic_salary = $gross;
                    }
                    
                    $total_basic_salary += $basic_salary;
                    $months_worked++;
                    $monthly_basic[] = $basic_salary;
                }
                
                $thirteenth_month_pay = 0;
                if ($months_worked > 0) {
                    $thirteenth_month_pay = ($total_basic_salary / 12);
                    if ($months_worked < 12) {
                        $thirteenth_month_pay = ($total_basic_salary / 12) * ($months_worked / 12);
                    }
                }
                
                $thirteenth_month_pay = round($thirteenth_month_pay, 2);
                
                $check_13th = $pdo->prepare("
                    SELECT id FROM payroll_records 
                    WHERE employee_id = ? 
                    AND payroll_month = ? 
                    AND thirteenth_month_pay IS NOT NULL
                ");
                $check_13th->execute([$employee_id, $year . '-12-01']);
                $existing_13th = $check_13th->fetch();
                
                if ($existing_13th) {
                    $update_13th = $pdo->prepare("
                        UPDATE payroll_records 
                        SET thirteenth_month_pay = ?,
                            thirteenth_month_computed_at = NOW(),
                            net_pay = net_pay + (? - thirteenth_month_pay)
                        WHERE id = ?
                    ");
                    $update_13th->execute([$thirteenth_month_pay, $thirteenth_month_pay, $existing_13th['id']]);
                    $success = "13th month pay updated for " . $employee_data['first_name'] . ' ' . $employee_data['last_name'] . 
                               "! Amount: ₱" . number_format($thirteenth_month_pay, 2) . 
                               " (based on $months_worked months)";
                } else {
                    $check_dec = $pdo->prepare("
                        SELECT id, net_pay FROM payroll_records 
                        WHERE employee_id = ? 
                        AND payroll_month = ?
                    ");
                    $check_dec->execute([$employee_id, $year . '-12-01']);
                    $dec_record = $check_dec->fetch();
                    
                    if ($dec_record) {
                        $update_dec = $pdo->prepare("
                            UPDATE payroll_records 
                            SET thirteenth_month_pay = ?,
                                thirteenth_month_computed_at = NOW(),
                                net_pay = net_pay + ?
                            WHERE id = ?
                        ");
                        $update_dec->execute([$thirteenth_month_pay, $thirteenth_month_pay, $dec_record['id']]);
                    } else {
                        $insert_13th = $pdo->prepare("
                            INSERT INTO payroll_records 
                            (employee_id, payroll_month, work_days, work_hours, overtime_hours,
                             gross_pay, philhealth_deduction, sss_deduction, pagibig_deduction,
                             total_deductions, net_pay, holiday_pay, holiday_hours, holiday_days,
                             status, computed_at, salary_type, thirteenth_month_pay, thirteenth_month_computed_at)
                            VALUES (?, ?, 0, 0, 0, 0, 0, 0, 0, 0, ?, 0, 0, 0, 'computed', NOW(), ?, ?, NOW())
                        ");
                        $insert_13th->execute([
                            $employee_id, 
                            $year . '-12-01',
                            $thirteenth_month_pay,
                            $employee_data['salary_type'] ?? 'Monthly',
                            $thirteenth_month_pay
                        ]);
                    }
                    
                    $success = "13th month pay computed for " . $employee_data['first_name'] . ' ' . $employee_data['last_name'] . 
                               "! Amount: ₱" . number_format($thirteenth_month_pay, 2) . 
                               " (based on $months_worked months)";
                }
            }
        }
    }
    
    if ($_POST['action'] == 'mark_paid') {
        $payroll_id = $_POST['payroll_id'] ?? 0;
        $payment_reference = $_POST['payment_reference'] ?? '';
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $payment_notes = $_POST['payment_notes'] ?? '';
        
        $get_payroll = $pdo->prepare("SELECT * FROM payroll_records WHERE id = ?");
        $get_payroll->execute([$payroll_id]);
        $payroll_data = $get_payroll->fetch();
        
        if ($payroll_data && $payroll_data['status'] != 'paid') {
            $pdo->beginTransaction();
            
            try {
                $insert_history = $pdo->prepare("
                    INSERT INTO payroll_history 
                    (payroll_id, employee_id, payroll_month, work_days, work_hours, overtime_hours,
                     gross_pay, philhealth_deduction, sss_deduction, pagibig_deduction,
                     total_deductions, net_pay, holiday_pay, holiday_hours, holiday_days,
                     paid_at, payment_reference, payment_method, payment_notes,
                     daily_breakdown, salary_type, thirteenth_month_pay, custom_deductions_breakdown)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)
                ");
                $insert_history->execute([
                    $payroll_data['id'],
                    $payroll_data['employee_id'],
                    $payroll_data['payroll_month'],
                    $payroll_data['work_days'],
                    $payroll_data['work_hours'],
                    $payroll_data['overtime_hours'],
                    $payroll_data['gross_pay'],
                    $payroll_data['philhealth_deduction'],
                    $payroll_data['sss_deduction'],
                    $payroll_data['pagibig_deduction'],
                    $payroll_data['total_deductions'],
                    $payroll_data['net_pay'],
                    $payroll_data['holiday_pay'] ?? 0,
                    $payroll_data['holiday_hours'] ?? 0,
                    $payroll_data['holiday_days'] ?? 0,
                    $payment_reference,
                    $payment_method,
                    $payment_notes,
                    $payroll_data['daily_breakdown'],
                    $payroll_data['salary_type'] ?? 'Monthly',
                    $payroll_data['thirteenth_month_pay'] ?? 0,
                    $payroll_data['custom_deductions_breakdown']
                ]);
                
                $update = $pdo->prepare("UPDATE payroll_records SET status = 'paid', paid_at = NOW() WHERE id = ?");
                $update->execute([$payroll_id]);
                
                $next_month = date('Y-m-01', strtotime($payroll_data['payroll_month'] . ' +1 month'));
                
                $check_next = $pdo->prepare("SELECT id, status FROM payroll_records WHERE employee_id = ? AND payroll_month = ?");
                $check_next->execute([$payroll_data['employee_id'], $next_month]);
                $next_exists = $check_next->fetch();
                
                if ($next_exists) {
                    if ($next_exists['status'] != 'paid') {
                        $reset_next = $pdo->prepare("
                            UPDATE payroll_records 
                            SET work_days = 0, work_hours = 0, overtime_hours = 0,
                                gross_pay = 0, philhealth_deduction = 0, sss_deduction = 0,
                                pagibig_deduction = 0, total_deductions = 0, net_pay = 0,
                                holiday_pay = 0, holiday_hours = 0, holiday_days = 0,
                                status = 'pending', computed_at = NULL, daily_breakdown = NULL,
                                thirteenth_month_pay = 0, thirteenth_month_computed_at = NULL,
                                custom_deductions_breakdown = NULL
                            WHERE id = ?
                        ");
                        $reset_next->execute([$next_exists['id']]);
                    }
                } else {
                    $create_next = $pdo->prepare("
                        INSERT INTO payroll_records 
                        (employee_id, payroll_month, work_days, work_hours, overtime_hours,
                         gross_pay, philhealth_deduction, sss_deduction, pagibig_deduction,
                         total_deductions, net_pay, holiday_pay, holiday_hours, holiday_days,
                         status, salary_type)
                        VALUES (?, ?, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 'pending', ?)
                    ");
                    $create_next->execute([$payroll_data['employee_id'], $next_month, $payroll_data['salary_type'] ?? 'Monthly']);
                }
                
                $pdo->commit();
                $success = "Payroll marked as paid! Record saved to history. Next month's payroll has been automatically reset to zero.";
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Error processing payment: " . $e->getMessage();
            }
        } else {
            $error = "Payroll record not found or already paid.";
        }
    }
    
    if ($_POST['action'] == 'bulk_compute') {
        $payroll_month = $_POST['payroll_month'] ?? '';
        $computed_count = 0;
        $errors = [];
        
        $all_employees = $pdo->query("
            SELECT e.employee_id, e.first_name, e.last_name, e.salary_type
            FROM employees e
            INNER JOIN employee_payroll_settings eps ON e.employee_id = eps.employee_id
        ")->fetchAll();
        
        $start_date = $payroll_month . '-01';
        $end_date = date('Y-m-t', strtotime($payroll_month));
        $holiday_stmt = $pdo->prepare("SELECT * FROM holidays WHERE holiday_date BETWEEN ? AND ?");
        $holiday_stmt->execute([$start_date, $end_date]);
        $month_holidays = [];
        while ($row = $holiday_stmt->fetch()) {
            $month_holidays[$row['holiday_date']] = $row;
        }
        
        foreach ($all_employees as $emp) {
            $employee_id = $emp['employee_id'];
            $salary_type = $emp['salary_type'] ?? 'Monthly';
            
            $check_paid = $pdo->prepare("SELECT status FROM payroll_records WHERE employee_id = ? AND payroll_month = ?");
            $check_paid->execute([$employee_id, $payroll_month . '-01']);
            $paid_check = $check_paid->fetch();
            
            if ($paid_check && $paid_check['status'] == 'paid') {
                continue;
            }
            
            $settings = $pdo->prepare("SELECT * FROM employee_payroll_settings WHERE employee_id = ?");
            $settings->execute([$employee_id]);
            $setting = $settings->fetch();
            
            if ($setting) {
                $rest_days_query = $pdo->prepare("
                    SELECT day_of_week, custom_date FROM employee_rest_days 
                    WHERE employee_id = ? 
                    AND (is_recurring = 1 OR (custom_date BETWEEN ? AND ?))
                ");
                $rest_days_query->execute([$employee_id, $start_date, $end_date]);
                $employee_rest_days = [];
                while ($row = $rest_days_query->fetch()) {
                    if ($row['day_of_week']) {
                        $employee_rest_days[$row['day_of_week']] = true;
                    }
                    if ($row['custom_date']) {
                        $employee_rest_days[$row['custom_date']] = true;
                    }
                }
                
                $ot_stmt = $pdo->prepare("
                    SELECT date, overtime_hours 
                    FROM overtime_approvals 
                    WHERE employee_id = ? 
                    AND date BETWEEN ? AND ? 
                    AND status = 'approved'
                ");
                $ot_stmt->execute([$employee_id, $start_date, $end_date]);
                $approved_overtime_records = [];
                while ($row = $ot_stmt->fetch()) {
                    $approved_overtime_records[$row['date']] = (float)$row['overtime_hours'];
                }
                
                // FIXED SQL
                $attendanceStmt = $pdo->prepare("
                    SELECT a.*, 
                           IFNULL((SELECT SUM(TIMESTAMPDIFF(MINUTE, break_in, IFNULL(break_out, NOW()))) 
                                   FROM employee_breaks 
                                   WHERE employee_id = a.employee_id AND date = a.date), 0) AS break_minutes
                    FROM attendance a 
                    WHERE a.employee_id = ? 
                    AND a.date BETWEEN ? AND ?
                    ORDER BY a.date, a.time_in
                ");
                $attendanceStmt->execute([$employee_id, $start_date, $end_date]);
                $attendance_records = $attendanceStmt->fetchAll();
                
                $daily_attendance = [];
                foreach ($attendance_records as $record) {
                    $date = $record['date'];
                    $day_of_week = date('l', strtotime($date));
                    $is_rest_day = isset($employee_rest_days[$day_of_week]) || isset($employee_rest_days[$date]);
                    
                    if (!isset($daily_attendance[$date])) {
                        $daily_attendance[$date] = [
                            'time_in' => null,
                            'time_out' => null,
                            'breaks' => [],
                            'total_break_minutes' => $record['break_minutes'] ?? 0, // FIXED
                            'is_holiday' => isset($month_holidays[$date]),
                            'holiday_type' => isset($month_holidays[$date]) ? $month_holidays[$date]['holiday_type'] : null,
                            'holiday_name' => isset($month_holidays[$date]) ? $month_holidays[$date]['holiday_name'] : null,
                            'is_rest_day' => $is_rest_day
                        ];
                    }
                    
                    if ($record['time_out'] && !$record['time_in']) {
                        if (!isset($daily_attendance[$date]['breaks'][$record['time_out']])) {
                            $daily_attendance[$date]['breaks'][$record['time_out']] = [
                                'break_out' => $record['time_out'],
                                'break_in' => null
                            ];
                        }
                    }
                    
                    if ($record['time_in']) {
                        $is_break_in = false;
                        foreach ($daily_attendance[$date]['breaks'] as &$break) {
                            if ($break['break_in'] === null && $break['break_out'] < $record['time_in']) {
                                $break['break_in'] = $record['time_in'];
                                $is_break_in = true;
                                break;
                            }
                        }
                        
                        if (!$is_break_in) {
                            if ($daily_attendance[$date]['time_in'] === null) {
                                $daily_attendance[$date]['time_in'] = $record['time_in'];
                            }
                        }
                    }
                    
                    if ($record['time_out']) {
                        $is_break_out = false;
                        foreach ($daily_attendance[$date]['breaks'] as $break) {
                            if ($break['break_out'] == $record['time_out']) {
                                $is_break_out = true;
                                break;
                            }
                        }
                        
                        if (!$is_break_out) {
                            $daily_attendance[$date]['time_out'] = $record['time_out'];
                        }
                    }
                }
                
                $total_work_days = 0;
                $total_hours = 0;
                $total_overtime = 0;
                $total_holiday_pay = 0;
                $total_holiday_hours = 0;
                $total_holiday_days = 0;
                $hourly_rate = $setting['hourly_rate'] ?? 0;
                $overtime_rate = $setting['overtime_rate'] ?? 1.25;
                $default_hours_per_day = $setting['work_hours_per_day'] ?? 8;
                $daily_breakdown = [];
                
                $adjusted_hourly_rate = $hourly_rate;
                
                foreach ($daily_attendance as $date => $day_data) {
                    $time_in = $day_data['time_in'];
                    
                    if (!$time_in) {
                        continue;
                    }
                    
                    $total_break_minutes = $day_data['total_break_minutes'] ?? 0; // FIXED
                    
                    $time_in_ts = strtotime($time_in);
                    
                    if (!$day_data['time_out']) {
                        $time_out_ts = strtotime(date('Y-m-d 17:00:00'));
                    } else {
                        $time_out_ts = strtotime($day_data['time_out']);
                    }
                    
                    $total_minutes = ($time_out_ts - $time_in_ts) / 60;
                    $work_minutes = max($total_minutes - $total_break_minutes, 0);
                    $work_hours = round($work_minutes / 60, 2);
                    
                    $raw_overtime_hours = 0;
                    if ($work_hours > $default_hours_per_day) {
                        $raw_overtime_hours = $work_hours - $default_hours_per_day;
                    }
                    $approved_ot_hours = $approved_overtime_records[$date] ?? 0;
                    $overtime_hours = min($raw_overtime_hours, $approved_ot_hours);
                    $regular_hours = max(0, $work_hours - $overtime_hours);
                    
                    $is_holiday = $day_data['is_holiday'] ?? false;
                    $holiday_type = $day_data['holiday_type'] ?? null;
                    $holiday_name = $day_data['holiday_name'] ?? '';
                    $is_rest_day = $day_data['is_rest_day'] ?? false;
                    
                    $holiday_pay = 0;
                    $holiday_pay_rate = 1;
                    
                    if ($is_holiday && !$is_rest_day && $work_hours > 0) {
                        if ($holiday_type == 'regular') {
                            $holiday_pay_rate = 2;
                        } elseif ($holiday_type == 'special') {
                            $holiday_pay_rate = 1.3;
                        }
                        
                        $holiday_pay = $regular_hours * $adjusted_hourly_rate * $holiday_pay_rate;
                        $total_holiday_days++;
                        $total_holiday_hours += $regular_hours; 
                        $total_holiday_pay += $holiday_pay;
                    }
                    
                    $total_work_days++;
                    $total_hours += $work_hours;
                    $total_overtime += $overtime_hours;
                }
                
                $total_regular_hours_only = max(0, ($total_hours - $total_overtime - $total_holiday_hours));
                $regular_pay = $total_regular_hours_only * $adjusted_hourly_rate;
                $overtime_pay = $total_overtime * $adjusted_hourly_rate * $overtime_rate;
                $gross_pay = $regular_pay + $overtime_pay + $total_holiday_pay;
                
                $philhealth = $gross_pay * (($setting['philhealth_rate'] ?? 0) / 100);
                $sss = $gross_pay * (($setting['sss_rate'] ?? 0) / 100);
                $pagibig = $gross_pay * (($setting['pagibig_rate'] ?? 0) / 100);
                
                $custom_deductions_list = [];
                $custom_deductions_total = 0;
                $ded_stmt = $pdo->prepare("SELECT * FROM employee_custom_deductions WHERE employee_id = ?");
                $ded_stmt->execute([$employee_id]);
                $custom_deductions = $ded_stmt->fetchAll();

                foreach ($custom_deductions as $cd) {
                    $amount = 0;
                    if ($cd['deduction_type'] == 'fixed') {
                        $amount = floatval($cd['deduction_value']);
                    } elseif ($cd['deduction_type'] == 'percent') {
                        $amount = $gross_pay * (floatval($cd['deduction_value']) / 100);
                    }
                    $custom_deductions_total += $amount;
                    $custom_deductions_list[] = [
                        'name'    => $cd['deduction_name'],
                        'type'    => $cd['deduction_type'],
                        'value'   => floatval($cd['deduction_value']),
                        'amount'  => round($amount, 2),
                        'remarks' => $cd['remarks']
                    ];
                }

                $total_deductions = $philhealth + $sss + $pagibig + $custom_deductions_total;
                $custom_deductions_json = json_encode($custom_deductions_list);
                $net_pay = $gross_pay - $total_deductions;
                
                $check = $pdo->prepare("SELECT id FROM payroll_records WHERE employee_id = ? AND payroll_month = ?");
                $check->execute([$employee_id, $payroll_month . '-01']);
                
                if ($check->fetch()) {
                    $update = $pdo->prepare("UPDATE payroll_records 
                                             SET work_days = ?, work_hours = ?, overtime_hours = ?,
                                                 gross_pay = ?, philhealth_deduction = ?, sss_deduction = ?,
                                                 pagibig_deduction = ?, total_deductions = ?, net_pay = ?,
                                                 holiday_pay = ?, holiday_hours = ?, holiday_days = ?,
                                                 status = 'computed', computed_at = NOW(), salary_type = ?,
                                                 custom_deductions_breakdown = ?
                                             WHERE employee_id = ? AND payroll_month = ?");
                    $update->execute([
                        $total_work_days, $total_hours, $total_overtime,
                        $gross_pay, $philhealth, $sss, $pagibig, $total_deductions, $net_pay,
                        $total_holiday_pay, $total_holiday_hours, $total_holiday_days,
                        $salary_type,
                        $custom_deductions_json,
                        $employee_id, $payroll_month . '-01'
                    ]);
                } else {
                    $insert = $pdo->prepare("INSERT INTO payroll_records 
                                             (employee_id, payroll_month, work_days, work_hours, overtime_hours,
                                              gross_pay, philhealth_deduction, sss_deduction, pagibig_deduction,
                                              total_deductions, net_pay, holiday_pay, holiday_hours, holiday_days,
                                              status, computed_at, salary_type, custom_deductions_breakdown)
                                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'computed', NOW(), ?, ?)");
                    $insert->execute([
                        $employee_id, $payroll_month . '-01', $total_work_days, $total_hours, $total_overtime,
                        $gross_pay, $philhealth, $sss, $pagibig, $total_deductions, $net_pay,
                        $total_holiday_pay, $total_holiday_hours, $total_holiday_days,
                        $salary_type, $custom_deductions_json
                    ]);
                }
                
                $computed_count++;
            }
        }
        
        $success = "Bulk payroll computed successfully for $computed_count employees!";
    }

    if ($_POST['action'] == 'bulk_compute_by_type') {
        $payroll_month = $_POST['payroll_month'] ?? '';
        $salary_type_filter = $_POST['salary_type'] ?? '';
        $computed_count = 0;
        $errors = [];
        
        $employees_sql = "
            SELECT e.employee_id, e.first_name, e.last_name, e.salary_type
            FROM employees e
            INNER JOIN employee_payroll_settings eps ON e.employee_id = eps.employee_id
        ";
        if (!empty($salary_type_filter)) {
            $employees_sql .= " WHERE e.salary_type = :salary_type";
        }
        
        $all_employees_stmt = $pdo->prepare($employees_sql);
        if (!empty($salary_type_filter)) {
            $all_employees_stmt->execute(['salary_type' => $salary_type_filter]);
        } else {
            $all_employees_stmt->execute();
        }
        $all_employees = $all_employees_stmt->fetchAll();
        
        $start_date = $payroll_month . '-01';
        $end_date = date('Y-m-t', strtotime($payroll_month));
        $holiday_stmt = $pdo->prepare("SELECT * FROM holidays WHERE holiday_date BETWEEN ? AND ?");
        $holiday_stmt->execute([$start_date, $end_date]);
        $month_holidays = [];
        while ($row = $holiday_stmt->fetch()) {
            $month_holidays[$row['holiday_date']] = $row;
        }
        
        foreach ($all_employees as $emp) {
            $employee_id = $emp['employee_id'];
            $salary_type = $emp['salary_type'] ?? 'Monthly';
            
            $check_paid = $pdo->prepare("SELECT status FROM payroll_records WHERE employee_id = ? AND payroll_month = ?");
            $check_paid->execute([$employee_id, $payroll_month . '-01']);
            $paid_check = $check_paid->fetch();
            
            if ($paid_check && $paid_check['status'] == 'paid') {
                continue;
            }
            
            $settings = $pdo->prepare("SELECT * FROM employee_payroll_settings WHERE employee_id = ?");
            $settings->execute([$employee_id]);
            $setting = $settings->fetch();
            
            if ($setting) {
                $rest_days_query = $pdo->prepare("
                    SELECT day_of_week, custom_date FROM employee_rest_days 
                    WHERE employee_id = ? 
                    AND (is_recurring = 1 OR (custom_date BETWEEN ? AND ?))
                ");
                $rest_days_query->execute([$employee_id, $start_date, $end_date]);
                $employee_rest_days = [];
                while ($row = $rest_days_query->fetch()) {
                    if ($row['day_of_week']) {
                        $employee_rest_days[$row['day_of_week']] = true;
                    }
                    if ($row['custom_date']) {
                        $employee_rest_days[$row['custom_date']] = true;
                    }
                }
                
                $ot_stmt = $pdo->prepare("
                    SELECT date, overtime_hours 
                    FROM overtime_approvals 
                    WHERE employee_id = ? 
                    AND date BETWEEN ? AND ? 
                    AND status = 'approved'
                ");
                $ot_stmt->execute([$employee_id, $start_date, $end_date]);
                $approved_overtime_records = [];
                while ($row = $ot_stmt->fetch()) {
                    $approved_overtime_records[$row['date']] = (float)$row['overtime_hours'];
                }
                
                // FIXED SQL
                $attendanceStmt = $pdo->prepare("
                    SELECT a.*, 
                           IFNULL((SELECT SUM(TIMESTAMPDIFF(MINUTE, break_in, IFNULL(break_out, NOW()))) 
                                   FROM employee_breaks 
                                   WHERE employee_id = a.employee_id AND date = a.date), 0) AS break_minutes
                    FROM attendance a 
                    WHERE a.employee_id = ? 
                    AND a.date BETWEEN ? AND ?
                    ORDER BY a.date, a.time_in
                ");
                $attendanceStmt->execute([$employee_id, $start_date, $end_date]);
                $attendance_records = $attendanceStmt->fetchAll();
                
                $daily_attendance = [];
                foreach ($attendance_records as $record) {
                    $date = $record['date'];
                    $day_of_week = date('l', strtotime($date));
                    $is_rest_day = isset($employee_rest_days[$day_of_week]) || isset($employee_rest_days[$date]);
                    
                    if (!isset($daily_attendance[$date])) {
                        $daily_attendance[$date] = [
                            'time_in' => null,
                            'time_out' => null,
                            'breaks' => [],
                            'total_break_minutes' => $record['break_minutes'] ?? 0, // FIXED
                            'is_holiday' => isset($month_holidays[$date]),
                            'holiday_type' => isset($month_holidays[$date]) ? $month_holidays[$date]['holiday_type'] : null,
                            'holiday_name' => isset($month_holidays[$date]) ? $month_holidays[$date]['holiday_name'] : null,
                            'is_rest_day' => $is_rest_day
                        ];
                    }
                    
                    if ($record['time_out'] && !$record['time_in']) {
                        if (!isset($daily_attendance[$date]['breaks'][$record['time_out']])) {
                            $daily_attendance[$date]['breaks'][$record['time_out']] = [
                                'break_out' => $record['time_out'],
                                'break_in' => null
                            ];
                        }
                    }
                    
                    if ($record['time_in']) {
                        $is_break_in = false;
                        foreach ($daily_attendance[$date]['breaks'] as &$break) {
                            if ($break['break_in'] === null && $break['break_out'] < $record['time_in']) {
                                $break['break_in'] = $record['time_in'];
                                $is_break_in = true;
                                break;
                            }
                        }
                        
                        if (!$is_break_in) {
                            if ($daily_attendance[$date]['time_in'] === null) {
                                $daily_attendance[$date]['time_in'] = $record['time_in'];
                            }
                        }
                    }
                    
                    if ($record['time_out']) {
                        $is_break_out = false;
                        foreach ($daily_attendance[$date]['breaks'] as $break) {
                            if ($break['break_out'] == $record['time_out']) {
                                $is_break_out = true;
                                break;
                            }
                        }
                        
                        if (!$is_break_out) {
                            $daily_attendance[$date]['time_out'] = $record['time_out'];
                        }
                    }
                }
                
                $total_work_days = 0;
                $total_hours = 0;
                $total_overtime = 0;
                $total_holiday_pay = 0;
                $total_holiday_hours = 0;
                $total_holiday_days = 0;
                $hourly_rate = $setting['hourly_rate'] ?? 0;
                $overtime_rate = $setting['overtime_rate'] ?? 1.25;
                $default_hours_per_day = $setting['work_hours_per_day'] ?? 8;
                $daily_breakdown = [];
                
                $adjusted_hourly_rate = $hourly_rate;
                
                foreach ($daily_attendance as $date => $day_data) {
                    $time_in = $day_data['time_in'];
                    
                    if (!$time_in) {
                        continue;
                    }
                    
                    $total_break_minutes = $day_data['total_break_minutes'] ?? 0; // FIXED
                    
                    $time_in_ts = strtotime($time_in);
                    
                    if (!$day_data['time_out']) {
                        $time_out_ts = strtotime(date('Y-m-d 17:00:00'));
                    } else {
                        $time_out_ts = strtotime($day_data['time_out']);
                    }
                    
                    $total_minutes = ($time_out_ts - $time_in_ts) / 60;
                    $work_minutes = max($total_minutes - $total_break_minutes, 0);
                    $work_hours = round($work_minutes / 60, 2);
                    
                    $raw_overtime_hours = 0;
                    if ($work_hours > $default_hours_per_day) {
                        $raw_overtime_hours = $work_hours - $default_hours_per_day;
                    }
                    $approved_ot_hours = $approved_overtime_records[$date] ?? 0;
                    $overtime_hours = min($raw_overtime_hours, $approved_ot_hours);
                    $regular_hours = max(0, $work_hours - $overtime_hours);
                    
                    $is_holiday = $day_data['is_holiday'] ?? false;
                    $holiday_type = $day_data['holiday_type'] ?? null;
                    $holiday_name = $day_data['holiday_name'] ?? '';
                    $is_rest_day = $day_data['is_rest_day'] ?? false;
                    
                    $holiday_pay = 0;
                    $holiday_pay_rate = 1;
                    
                    if ($is_holiday && !$is_rest_day && $work_hours > 0) {
                        if ($holiday_type == 'regular') {
                            $holiday_pay_rate = 2;
                        } elseif ($holiday_type == 'special') {
                            $holiday_pay_rate = 1.3;
                        }
                        
                        $holiday_pay = $regular_hours * $adjusted_hourly_rate * $holiday_pay_rate;
                        $total_holiday_days++;
                        $total_holiday_hours += $regular_hours; 
                        $total_holiday_pay += $holiday_pay;
                    }
                    
                    $total_work_days++;
                    $total_hours += $work_hours;
                    $total_overtime += $overtime_hours;
                }
                
                $total_regular_hours_only = max(0, ($total_hours - $total_overtime - $total_holiday_hours));
                $regular_pay = $total_regular_hours_only * $adjusted_hourly_rate;
                $overtime_pay = $total_overtime * $adjusted_hourly_rate * $overtime_rate;
                $gross_pay = $regular_pay + $overtime_pay + $total_holiday_pay;
                
                $philhealth = $gross_pay * (($setting['philhealth_rate'] ?? 0) / 100);
                $sss = $gross_pay * (($setting['sss_rate'] ?? 0) / 100);
                $pagibig = $gross_pay * (($setting['pagibig_rate'] ?? 0) / 100);
                
                $custom_deductions_list = [];
                $custom_deductions_total = 0;
                $ded_stmt = $pdo->prepare("SELECT * FROM employee_custom_deductions WHERE employee_id = ?");
                $ded_stmt->execute([$employee_id]);
                $custom_deductions = $ded_stmt->fetchAll();

                foreach ($custom_deductions as $cd) {
                    $amount = 0;
                    if ($cd['deduction_type'] == 'fixed') {
                        $amount = floatval($cd['deduction_value']);
                    } elseif ($cd['deduction_type'] == 'percent') {
                        $amount = $gross_pay * (floatval($cd['deduction_value']) / 100);
                    }
                    $custom_deductions_total += $amount;
                    $custom_deductions_list[] = [
                        'name'    => $cd['deduction_name'],
                        'type'    => $cd['deduction_type'],
                        'value'   => floatval($cd['deduction_value']),
                        'amount'  => round($amount, 2),
                        'remarks' => $cd['remarks']
                    ];
                }

                $total_deductions = $philhealth + $sss + $pagibig + $custom_deductions_total;
                $custom_deductions_json = json_encode($custom_deductions_list);
                $net_pay = $gross_pay - $total_deductions;
                
                $check = $pdo->prepare("SELECT id FROM payroll_records WHERE employee_id = ? AND payroll_month = ?");
                $check->execute([$employee_id, $payroll_month . '-01']);
                
                if ($check->fetch()) {
                    $update = $pdo->prepare("UPDATE payroll_records 
                                             SET work_days = ?, work_hours = ?, overtime_hours = ?,
                                                 gross_pay = ?, philhealth_deduction = ?, sss_deduction = ?,
                                                 pagibig_deduction = ?, total_deductions = ?, net_pay = ?,
                                                 holiday_pay = ?, holiday_hours = ?, holiday_days = ?,
                                                 status = 'computed', computed_at = NOW(), salary_type = ?,
                                                 custom_deductions_breakdown = ?
                                             WHERE employee_id = ? AND payroll_month = ?");
                    $update->execute([
                        $total_work_days, $total_hours, $total_overtime,
                        $gross_pay, $philhealth, $sss, $pagibig, $total_deductions, $net_pay,
                        $total_holiday_pay, $total_holiday_hours, $total_holiday_days,
                        $salary_type,
                        $custom_deductions_json,
                        $employee_id, $payroll_month . '-01'
                    ]);
                } else {
                    $insert = $pdo->prepare("INSERT INTO payroll_records 
                                             (employee_id, payroll_month, work_days, work_hours, overtime_hours,
                                              gross_pay, philhealth_deduction, sss_deduction, pagibig_deduction,
                                              total_deductions, net_pay, holiday_pay, holiday_hours, holiday_days,
                                              status, computed_at, salary_type, custom_deductions_breakdown)
                                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'computed', NOW(), ?, ?)");
                    $insert->execute([
                        $employee_id, $payroll_month . '-01', $total_work_days, $total_hours, $total_overtime,
                        $gross_pay, $philhealth, $sss, $pagibig, $total_deductions, $net_pay,
                        $total_holiday_pay, $total_holiday_hours, $total_holiday_days,
                        $salary_type, $custom_deductions_json
                    ]);
                }
                
                $computed_count++;
            }
        }
        
        $type_label = !empty($salary_type_filter) ? $salary_type_filter : 'All';
        $success = "Bulk payroll computed successfully for $computed_count employees with salary type: $type_label!";
    }
}

// Get payroll records based on view mode
if ($view_history) {
    $payroll_records_sql = "
        SELECT ph.*, e.first_name, e.last_name, e.position, 
               eps.hourly_rate, eps.overtime_rate, eps.work_hours_per_day,
               eps.daily_rate, eps.monthly_salary, eps.work_days_per_month,
               'paid' as status, ph.paid_at as computed_at
        FROM payroll_history ph
        JOIN employees e ON ph.employee_id = e.employee_id
        LEFT JOIN employee_payroll_settings eps ON e.employee_id = eps.employee_id
        WHERE DATE_FORMAT(ph.paid_at, '%Y-%m') = :month
    ";
    if (!empty($selected_salary_type)) {
        $payroll_records_sql .= " AND e.salary_type = :salary_type";
    }
    $payroll_records_sql .= " ORDER BY ph.paid_at DESC";
    
    $payroll_records = $pdo->prepare($payroll_records_sql);
    $params = ['month' => $history_month];
    if (!empty($selected_salary_type)) {
        $params['salary_type'] = $selected_salary_type;
    }
    $payroll_records->execute($params);
} else {
    $payroll_records_sql = "
        SELECT pr.*, e.first_name, e.last_name, e.position, 
               eps.hourly_rate, eps.overtime_rate, eps.work_hours_per_day,
               eps.daily_rate, eps.monthly_salary, eps.work_days_per_month
        FROM payroll_records pr
        JOIN employees e ON pr.employee_id = e.employee_id
        LEFT JOIN employee_payroll_settings eps ON e.employee_id = eps.employee_id
        WHERE DATE_FORMAT(pr.payroll_month, '%Y-%m') = :month
    ";
    if (!empty($selected_salary_type)) {
        $payroll_records_sql .= " AND e.salary_type = :salary_type";
    }
    $payroll_records_sql .= " ORDER BY pr.created_at DESC";
    
    $payroll_records = $pdo->prepare($payroll_records_sql);
    $params = ['month' => $selected_month];
    if (!empty($selected_salary_type)) {
        $params['salary_type'] = $selected_salary_type;
    }
    $payroll_records->execute($params);
}
$payrolls = $payroll_records->fetchAll();

// Get paid history summary
$history_summary = $pdo->prepare("
    SELECT 
        COUNT(*) as total_payments,
        SUM(net_pay) as total_amount,
        MONTH(paid_at) as month,
        YEAR(paid_at) as year
    FROM payroll_history
    GROUP BY YEAR(paid_at), MONTH(paid_at)
    ORDER BY paid_at DESC
    LIMIT 12
");
$history_summary->execute();
$payment_history = $history_summary->fetchAll();

?>
<!DOCTYPE html>
<html>
<head>
    <title>Payroll Computation - QR Attendance Based</title>
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
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        .btn-info:hover {
            background: #138496;
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
        .btn-success {
            background: #28a745;
            color: white;
        }
        .btn-success:hover {
            background: #218838;
        }
        .btn-13th {
            background: #6f42c1;
            color: white;
        }
        .btn-13th:hover {
            background: #5a32a3;
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
            grid-template-columns: 1fr 1fr 1fr auto auto;
            gap: 15px;
            align-items: end;
        }
        .filter-grid .filter-label {
            font-size: 0.85em;
            color: #666;
            margin-bottom: 5px;
            display: block;
        }
        select, input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
        }
        select:focus, input:focus {
            border-color: #667eea;
            outline: none;
        }
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            background: white;
            border-radius: 15px;
            padding: 5px;
            flex-wrap: wrap;
        }
        .tab {
            flex: 1;
            padding: 15px;
            text-align: center;
            background: none;
            border: none;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            border-radius: 10px;
            transition: all 0.3s;
            min-width: 120px;
        }
        .tab.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .payroll-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(480px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .payroll-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            transition: transform 0.3s;
        }
        .payroll-card:hover {
            transform: translateY(-5px);
        }
        .payroll-header {
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .employee-name {
            font-size: 1.3em;
            font-weight: 600;
            color: #333;
        }
        .employee-position {
            color: #666;
            font-size: 0.9em;
        }
        .payroll-detail {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            border-bottom: 1px dashed #e0e0e0;
        }
        .payroll-section {
            margin: 12px 0;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        .section-title {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            font-size: 1em;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .gross-pay {
            color: #28a745;
            font-weight: 600;
            font-size: 1.1em;
        }
        .deduction {
            color: #dc3545;
        }
        .net-pay {
            color: #007bff;
            font-weight: 700;
            font-size: 1.3em;
        }
        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
        }
        .status-pending { background: #e9ecef; color: #495057; }
        .status-computed { background: #cce5ff; color: #004085; }
        .status-paid { background: #d4edda; color: #155724; }
        .qr-based-badge {
            background: #e7f5ff;
            color: #004085;
            padding: 3px 8px;
            border-radius: 15px;
            font-size: 0.75em;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }
        .salary-type-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 5px;
        }
        .badge-monthly { background: #d4edda; color: #155724; }
        .badge-bi-monthly { background: #fff3cd; color: #856404; }
        .badge-weekly { background: #cce5ff; color: #004085; }
        .holiday-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            margin-left: 5px;
        }
        .badge-regular-holiday { background: #dc3545; color: white; }
        .badge-special-holiday { background: #ffc107; color: #333; }
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
        .compute-section {
            background: white;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .summary-stats {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 15px;
            margin-top: 15px;
        }
        .stat-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
        }
        .stat-box.holiday-stat {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        }
        .stat-box.thirteenth-stat {
            background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%);
        }
        .stat-value {
            font-size: 1.8em;
            font-weight: 700;
        }
        .stat-label {
            font-size: 0.9em;
            opacity: 0.9;
        }
        .total-breakdown {
            margin-top: 15px;
            border-top: 2px solid #667eea;
            padding-top: 15px;
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
        .modal.show {
            display: flex;
        }
        .modal-content {
            background: white;
            border-radius: 20px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #667eea;
        }
        .modal-title {
            font-size: 1.5em;
            font-weight: 600;
            color: #333;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5em;
            cursor: pointer;
            color: #666;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            color: #333;
            font-weight: 500;
        }
        input, select, textarea {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
        }
        .modal-footer {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        .payment-method {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 5px;
            font-size: 0.8em;
            font-weight: 600;
        }
        .method-cash { background: #28a745; color: white; }
        .method-bank { background: #17a2b8; color: white; }
        .method-check { background: #ffc107; color: #333; }
        .method-gcash { background: #007bff; color: white; }
        .btn-paid {
            background: #007bff;
            color: white;
            width: 100%;
            margin-top: 10px;
            padding: 12px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-paid:hover {
            background: #0056b3;
        }
        .daily-breakdown-toggle {
            background: none;
            border: none;
            color: #667eea;
            cursor: pointer;
            font-weight: 600;
            margin-top: 10px;
            padding: 5px;
        }
        .daily-breakdown-toggle:hover {
            text-decoration: underline;
        }
        .daily-breakdown-list {
            display: none;
            margin-top: 10px;
            background: #f8f9fa;
            border-radius: 10px;
            padding: 10px;
            max-height: 300px;
            overflow-y: auto;
        }
        .daily-breakdown-list.show {
            display: block;
        }
        .daily-item {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr;
            gap: 5px;
            padding: 5px 0;
            border-bottom: 1px solid #e0e0e0;
            font-size: 0.85em;
        }
        .daily-item.header {
            font-weight: 600;
            color: #667eea;
            border-bottom: 2px solid #667eea;
        }
        .daily-item .amount {
            color: #28a745;
            font-weight: 600;
        }
        .daily-item .holiday-amount {
            color: #dc3545;
            font-weight: 600;
        }
        .auto-reset-notice {
            background: #d4edda;
            border-left: 4px solid #28a745;
            padding: 12px;
            margin-top: 15px;
            border-radius: 8px;
            font-size: 0.85em;
            color: #155724;
        }
        .work-info {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr;
            gap: 10px;
            margin-top: 10px;
        }
        .work-stat {
            text-align: center;
            padding: 8px;
            background: white;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }
        .work-stat .number {
            font-size: 1.2em;
            font-weight: 700;
            color: #333;
        }
        .work-stat .label {
            font-size: 0.8em;
            color: #666;
        }
        .work-stat.holiday-stat {
            border-color: #dc3545;
            background: #f8d7da;
        }
        .work-stat.holiday-stat .number {
            color: #dc3545;
        }
        .work-stat.thirteenth-stat {
            border-color: #6f42c1;
            background: #e7d4f5;
        }
        .work-stat.thirteenth-stat .number {
            color: #6f42c1;
        }
        .filter-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
        }
        .filter-badge-active {
            background: #007bff;
            color: white;
        }
        .compute-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .holiday-info-text {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            padding: 10px 15px;
            border-radius: 8px;
            margin-top: 10px;
            font-size: 0.9em;
        }
        .thirteenth-section {
            background: #f8f0ff;
            border: 2px solid #6f42c1;
            border-radius: 12px;
            padding: 15px;
            margin-top: 15px;
        }
        .thirteenth-section .section-title {
            color: #6f42c1;
        }
        .thirteenth-amount {
            color: #6f42c1;
            font-weight: 700;
            font-size: 1.2em;
        }
        .rate-highlight {
            background: #e7f5ff;
            padding: 8px 10px;
            border-radius: 8px;
            margin-bottom: 8px;
            border-left: 4px solid #007bff;
        }
        .rate-highlight .payroll-detail {
            border-bottom: none;
            padding: 3px 0;
            font-size: 0.9em;
        }
        @media (max-width: 768px) {
            .filter-grid {
                grid-template-columns: 1fr 1fr;
            }
            .payroll-grid {
                grid-template-columns: 1fr;
            }
            .summary-stats {
                grid-template-columns: 1fr 1fr;
            }
            .daily-item {
                grid-template-columns: 1fr 1fr;
                font-size: 0.75em;
            }
            .daily-item.header {
                display: none;
            }
            .work-info {
                grid-template-columns: 1fr 1fr;
            }
        }
        @media (max-width: 480px) {
            .filter-grid {
                grid-template-columns: 1fr;
            }
            .summary-stats {
                grid-template-columns: 1fr;
            }
            .work-info {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🧮 Payroll Computation System</h1>

       <div class="header-buttons">
            <a href="admin_dashboard.php" class="btn btn-back">← Dashboard</a>
            <a href="admin_scanner.php" class="btn btn-back">📱 QR Scanner</a>
            <a href="employee_work_schedule.php" class="btn btn-back">📅 Schedule & Holidays</a>
            <a href="employee_payroll_settings.php" class="btn btn-back">⚙️ Payroll Settings</a>
        </div>     

        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab <?php echo !$view_history ? 'active' : ''; ?>" onclick="switchTab('current')">📊 Current Payroll</button>
            <button class="tab <?php echo $view_history ? 'active' : ''; ?>" onclick="switchTab('history')">📜 Payment History</button>
        </div>
 <!-- 13th Month Pay Section -->
            <div class="compute-section" style="border: 2px solid #6f42c1; background: #f8f0ff;">
                <h2 style="margin-bottom: 15px; color: #6f42c1;">🎄 13th Month Pay Computation</h2>
                <div class="filter-grid">
                    <div>
                        <label class="filter-label">👤 Employee</label>
                        <select id="thirteenthEmployee">
                            <option value="">Select Employee</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo $emp['employee_id']; ?>">
                                    <?php echo $emp['first_name'] . ' ' . $emp['last_name']; ?>
                                    (<?php echo $emp['salary_type'] ?? 'Monthly'; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="filter-label">📅 Year</label>
                        <select id="thirteenthYear">
                            <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo $y == date('Y') ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="filter-label">&nbsp;</label>
                        <button class="btn btn-13th" onclick="computeThirteenthMonth()">🎄 Compute 13th Month</button>
                    </div>
                </div>
                <div style="margin-top: 10px; font-size: 0.9em; color: #6f42c1;">
                    💡 Computes 13th month pay based on the total basic salary (excluding holiday pay and overtime) for all paid months in the selected year. 
                    The amount is automatically added to the December payroll.
                </div>
            </div>
        <!-- Current Payroll Tab -->
        <div id="currentTab" class="tab-content <?php echo !$view_history ? 'active' : ''; ?>">
            <div class="filter-card">
                <h2 style="margin-bottom: 15px;">Quick Payroll Compute <?php echo !empty($selected_salary_type) ? '- ' . htmlspecialchars($selected_salary_type) : ''; ?></h2>
                <div class="filter-grid">
                    <div>
                        <label class="filter-label">📅 Month</label>
                        <input type="month" id="monthSelect" value="<?php echo $selected_month; ?>" 
                               onchange="window.location.href='payroll_computation.php?month=' + this.value + '&salary_type=<?php echo $selected_salary_type; ?>'">
                    </div>
                    
                    <div>
                        <label class="filter-label">👤 Employee</label>
                        <select id="employeeSelect" onchange="if(this.value) window.location.href='payroll_computation.php?month=<?php echo $selected_month; ?>&employee=' + this.value + '&salary_type=<?php echo $selected_salary_type; ?>'">
                            <option value="">All Employees</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo $emp['employee_id']; ?>"
                                    <?php echo $selected_employee == $emp['employee_id'] ? 'selected' : ''; ?>>
                                    <?php echo $emp['first_name'] . ' ' . $emp['last_name']; ?>
                                    <?php if (!empty($emp['salary_type'])): ?> 
                                        (<?php echo $emp['salary_type']; ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="filter-label">💰 Salary Type</label>
                        <select id="salaryTypeFilter" onchange="window.location.href='payroll_computation.php?month=<?php echo $selected_month; ?>&employee=<?php echo $selected_employee; ?>&salary_type=' + this.value">
                            <option value="">All Types</option>
                            <option value="Monthly" <?php echo $selected_salary_type == 'Monthly' ? 'selected' : ''; ?>>Monthly</option>
                            <option value="Bi-monthly" <?php echo $selected_salary_type == 'Bi-monthly' ? 'selected' : ''; ?>>Bi-monthly</option>
                            <option value="Weekly" <?php echo $selected_salary_type == 'Weekly' ? 'selected' : ''; ?>>Weekly</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="filter-label">&nbsp;</label>
                        <button class="btn btn-back" onclick="window.location.href='payroll_computation.php'">🔄 Reset</button>
                    </div>
                    
                    <div>
                        <label class="filter-label">&nbsp;</label>
                        <div class="compute-buttons">
                            <button class="btn btn-primary" onclick="bulkCompute()">📊 Compute All</button>
                            <?php if (!empty($selected_salary_type)): ?>
                                <button class="btn btn-warning" onclick="bulkComputeByType()">📊 Compute <?php echo $selected_salary_type; ?></button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Compute Section -->
            <div class="compute-section">
                <h2 style="margin-bottom: 15px;">Manual Payroll Compute</h2>
                <div class="filter-grid">
                    <div>
                        <label class="filter-label">👤 Employee</label>
                        <select id="computeEmployee">
                            <option value="">Select Employee</option>
                            <?php foreach ($employees as $emp): ?>
                                <?php if (!empty($emp['hourly_rate']) && $emp['hourly_rate'] > 0): ?>
                                    <option value="<?php echo $emp['employee_id']; ?>">
                                        <?php echo $emp['first_name'] . ' ' . $emp['last_name']; ?>
                                        (<?php echo $emp['salary_type'] ?? 'Monthly'; ?>)
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="filter-label">📅 Month</label>
                        <input type="month" id="computeMonth" value="<?php echo $selected_month; ?>">
                    </div>
                    
                    <div>
                        <label class="filter-label">&nbsp;</label>
                        <button class="btn btn-primary" onclick="computePayroll()">💰 Compute Payroll</button>
                    </div>
                </div>
            </div>

            <!-- Payroll Records -->
            <div class="payroll-grid">
                <?php if (empty($payrolls)): ?>
                    <div style="grid-column: 1/-1; text-align: center; padding: 50px; background: white; border-radius: 20px;">
                        <div style="font-size: 3em; margin-bottom: 20px;">📊</div>
                        <h3>No payroll records for this month</h3>
                        <p style="color: #666; margin-top: 10px;">Select an employee and click "Compute Payroll" to generate payroll from QR attendance</p>
                        <button class="btn btn-primary" style="margin-top: 20px;" onclick="bulkCompute()">Compute All Employees</button>
                    </div>
                <?php else: ?>
                    <?php foreach ($payrolls as $payroll): 
                        $is_paid = ($payroll['status'] ?? '') == 'paid';
                        $daily_breakdown = !empty($payroll['daily_breakdown']) ? json_decode($payroll['daily_breakdown'], true) : [];
                        $salary_type = $payroll['salary_type'] ?? 'Monthly';
                        $badge_class = 'badge-monthly';
                        if ($salary_type == 'Bi-monthly') $badge_class = 'badge-bi-monthly';
                        if ($salary_type == 'Weekly') $badge_class = 'badge-weekly';
                        $has_holiday = ($payroll['holiday_days'] ?? 0) > 0;
                        $has_thirteenth = ($payroll['thirteenth_month_pay'] ?? 0) > 0;
                        $display_net_pay = $payroll['net_pay'] ?? 0;
                        // If this is December, show the net pay that includes 13th month
                        $is_december = (date('m', strtotime($payroll['payroll_month'])) == '12');

                        // Get custom deductions for display
                        $custom_deductions_display = [];
                        if (!empty($payroll['custom_deductions_breakdown'])) {
                            $custom_deductions_display = json_decode($payroll['custom_deductions_breakdown'], true);
                            if (!is_array($custom_deductions_display)) $custom_deductions_display = [];
                        }

                        // ---- RATE COMPUTATIONS (CORRECTED) ----
                        $emp_base_rate = (float)($payroll['hourly_rate'] ?? 0);
                        $emp_effective_rate = $emp_base_rate; // Hourly rate is constant

                        $emp_ot_multiplier = (float)($payroll['overtime_rate'] ?? 1.25);
                        $emp_ot_rate = $emp_effective_rate * $emp_ot_multiplier;

                        $emp_work_hrs_per_day = (float)($payroll['work_hours_per_day'] ?? 8);
                        $emp_work_days_per_month = (float)($payroll['work_days_per_month'] ?? 22);

                        // Fetch exact values from DB, or calculate if missing
                        $emp_daily_rate = (float)($payroll['daily_rate'] ?? 0);
                        if ($emp_daily_rate == 0) {
                            $emp_daily_rate = $emp_effective_rate * $emp_work_hrs_per_day;
                        }

                        $emp_monthly_salary = (float)($payroll['monthly_salary'] ?? 0);
                        if ($emp_monthly_salary == 0) {
                            $emp_monthly_salary = $emp_daily_rate * $emp_work_days_per_month;
                        }
                    ?>
                        <div class="payroll-card">
                            <div class="payroll-header">
                                <div>
                                    <div class="employee-name">
                                        <?php echo htmlspecialchars($payroll['first_name'] . ' ' . $payroll['last_name']); ?>
                                        <span class="salary-type-badge <?php echo $badge_class; ?>"><?php echo $salary_type; ?></span>
                                        <?php if ($has_thirteenth): ?>
                                            <span style="background: #6f42c1; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; margin-left: 5px;">🎄 13th</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="employee-position"><?php echo htmlspecialchars($payroll['position'] ?? 'N/A'); ?></div>
                                    <span class="qr-based-badge" style="margin-top: 3px;">📱 QR Attendance Based</span>
                                </div>
                                <div>
                                    <span class="status-badge status-<?php echo $payroll['status']; ?>">
                                        <?php echo ucfirst($payroll['status'] ?? 'pending'); ?>
                                    </span>
                                </div>
                            </div>

                            <!-- Work Summary from QR -->
                            <div class="payroll-section">
                                <div class="section-title">📊 QR Attendance Summary</div>
                                <div class="work-info">
                                    <div class="work-stat">
                                        <div class="number"><?php echo $payroll['work_days'] ?? 0; ?></div>
                                        <div class="label">Days Present</div>
                                    </div>
                                    <div class="work-stat">
                                        <div class="number"><?php echo number_format($payroll['work_hours'] ?? 0, 1); ?>h</div>
                                        <div class="label">Total Hours</div>
                                    </div>
                                    <div class="work-stat">
                                        <div class="number"><?php echo number_format($payroll['overtime_hours'] ?? 0, 1); ?>h</div>
                                        <div class="label">Overtime</div>
                                    </div>
                                    <?php if ($has_holiday): ?>
                                        <div class="work-stat holiday-stat">
                                            <div class="number"><?php echo $payroll['holiday_days']; ?>d</div>
                                            <div class="label">Holiday Days Worked</div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Rate Information (UPDATED) -->
                            <div class="payroll-section">
                                <div class="section-title">💵 Rate Information</div>
                                <div class="rate-highlight">
                                    <div class="payroll-detail">
                                        <span>⏱️ Hourly Rate:</span>
                                        <span><strong>₱<?php echo number_format($emp_effective_rate, 2); ?>/hr</strong></span>
                                    </div>
                                    <div class="payroll-detail">
                                        <span>📅 Daily Rate:</span>
                                        <span><strong>₱<?php echo number_format($emp_daily_rate, 2); ?></strong></span>
                                    </div>
                                    <div class="payroll-detail">
                                        <span>💵 Monthly Salary:</span>
                                        <span><strong>₱<?php echo number_format($emp_monthly_salary, 2); ?></strong></span>
                                    </div>
                                    <div class="payroll-detail">
                                        <span>⏰ Overtime Rate (<?php echo $emp_ot_multiplier; ?>x):</span>
                                        <span><strong>₱<?php echo number_format($emp_ot_rate, 2); ?>/hr</strong></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Earnings Breakdown -->
                            <div class="payroll-section">
                                <div class="section-title">💰 Earnings Breakdown</div>
                                <div class="payroll-detail">
                                    <span>Regular Pay:</span>
                                    <span>₱<?php echo number_format(($payroll['gross_pay'] ?? 0) - (($payroll['overtime_hours'] ?? 0) * $emp_ot_rate) - ($payroll['holiday_pay'] ?? 0), 2); ?></span>
                                </div>
                                <div class="payroll-detail">
                                    <span>Overtime Pay:</span>
                                    <span>₱<?php echo number_format(($payroll['overtime_hours'] ?? 0) * $emp_ot_rate, 2); ?></span>
                                </div>
                                <?php if ($has_holiday): ?>
                                    <div class="payroll-detail" style="color: #dc3545; font-weight: 600;">
                                        <span>🎉 Holiday Pay:</span>
                                        <span>₱<?php echo number_format($payroll['holiday_pay'] ?? 0, 2); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($has_thirteenth): ?>
                                    <div class="payroll-detail" style="color: #6f42c1; font-weight: 600;">
                                        <span>🎄 13th Month Pay:</span>
                                        <span>₱<?php echo number_format($payroll['thirteenth_month_pay'] ?? 0, 2); ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="payroll-detail gross-pay">
                                    <span>TOTAL GROSS PAY:</span>
                                    <span>₱<?php echo number_format($payroll['gross_pay'] ?? 0, 2); ?></span>
                                </div>
                            </div>

                            <!-- Deductions -->
                            <div class="payroll-section">
                                <div class="section-title">📉 Deductions</div>
                                <div class="payroll-detail deduction">
                                    <span>PhilHealth:</span>
                                    <span>-₱<?php echo number_format($payroll['philhealth_deduction'] ?? 0, 2); ?></span>
                                </div>
                                <div class="payroll-detail deduction">
                                    <span>SSS:</span>
                                    <span>-₱<?php echo number_format($payroll['sss_deduction'] ?? 0, 2); ?></span>
                                </div>
                                <div class="payroll-detail deduction">
                                    <span>Pag-IBIG:</span>
                                    <span>-₱<?php echo number_format($payroll['pagibig_deduction'] ?? 0, 2); ?></span>
                                </div>
                                <!-- Custom deductions -->
                                <?php if (!empty($custom_deductions_display)): ?>
                                    <?php foreach ($custom_deductions_display as $cd): ?>
                                        <div class="payroll-detail deduction">
                                            <span><?php echo htmlspecialchars($cd['name']); ?>:</span>
                                            <span>-₱<?php echo number_format($cd['amount'], 2); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <div class="payroll-detail deduction">
                                    <span><strong>TOTAL DEDUCTIONS:</strong></span>
                                    <span><strong>-₱<?php echo number_format($payroll['total_deductions'] ?? 0, 2); ?></strong></span>
                                </div>
                            </div>

                            <!-- Net Pay -->
                            <div class="total-breakdown">
                                <div class="payroll-detail">
                                    <span style="font-size: 1.1em;">GROSS PAY:</span>
                                    <span class="gross-pay">₱<?php echo number_format($payroll['gross_pay'] ?? 0, 2); ?></span>
                                </div>
                                <?php if ($has_thirteenth): ?>
                                    <div class="payroll-detail" style="color: #6f42c1;">
                                        <span style="font-size: 1.1em;">+ 13th Month Pay:</span>
                                        <span class="thirteenth-amount">₱<?php echo number_format($payroll['thirteenth_month_pay'] ?? 0, 2); ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="payroll-detail">
                                    <span style="font-size: 1.1em;">LESS DEDUCTIONS:</span>
                                    <span class="deduction">-₱<?php echo number_format($payroll['total_deductions'] ?? 0, 2); ?></span>
                                </div>
                                <div class="payroll-detail" style="border-bottom: none;">
                                    <span style="font-size: 1.3em; font-weight: 700;">NET PAY:</span>
                                    <span class="net-pay">₱<?php echo number_format($display_net_pay, 2); ?></span>
                                </div>
                                <?php if ($has_thirteenth && $is_december): ?>
                                    <div style="margin-top: 8px; padding: 8px; background: #f8f0ff; border-radius: 8px; text-align: center; font-size: 0.9em; color: #6f42c1;">
                                        🎄 Includes 13th month pay of ₱<?php echo number_format($payroll['thirteenth_month_pay'] ?? 0, 2); ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Daily Breakdown Toggle -->
                            <?php if (!empty($daily_breakdown)): ?>
                                <button class="daily-breakdown-toggle" onclick="toggleDailyBreakdown(this)">
                                    📋 Show Daily Breakdown
                                </button>
                                <div class="daily-breakdown-list">
                                    <div class="daily-item header">
                                        <span>Date</span>
                                        <span>Hours</span>
                                        <span>Break</span>
                                        <span>OT</span>
                                        <span>Pay</span>
                                        <span>Holiday</span>
                                    </div>
                                    <?php foreach ($daily_breakdown as $day): ?>
                                        <div class="daily-item">
                                            <span><?php echo date('M d', strtotime($day['date'])); ?></span>
                                            <span><?php echo number_format($day['work_hours'] ?? 0, 1); ?>h</span>
                                            <span><?php echo round($day['break_minutes'] ?? 0); ?>min</span>
                                            <span><?php echo number_format($day['overtime_hours'] ?? 0, 1); ?>h</span>
                                            <span class="amount">₱<?php echo number_format($day['total_pay'] ?? 0, 2); ?></span>
                                            <span class="holiday-amount">
                                                <?php if (($day['is_holiday'] ?? false) && !($day['is_rest_day'] ?? false)): ?>
                                                    ✅ <?php echo ($day['holiday_pay_rate'] ?? 1) == 2 ? '2x' : '1.3x'; ?>
                                                <?php elseif (($day['is_holiday'] ?? false) && ($day['is_rest_day'] ?? false)): ?>
                                                    ⛔ Rest
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!$is_paid): ?>
                                <button onclick="showPaymentModal(<?php echo $payroll['id']; ?>, '<?php echo addslashes($payroll['first_name'] . ' ' . $payroll['last_name']); ?>', <?php echo $display_net_pay; ?>)" 
                                        class="btn-paid">✅ Mark as Paid</button>
                                <div class="auto-reset-notice">
                                    💡 When marked as paid, this payroll will be archived and next month's payroll will be automatically reset to zero.
                                </div>
                            <?php else: ?>
                                <div class="auto-reset-notice" style="background: #e9ecef; border-left-color: #6c757d; color: #495057;">
                                    ✓ This payroll has been paid and archived. Next month's payroll has been reset and is ready for new calculations.
                                </div>
                            <?php endif; ?>

                            <div style="margin-top: 10px; font-size: 0.85em; color: #999; text-align: center;">
                                Computed: <?php echo !empty($payroll['computed_at']) ? date('M d, Y h:i A', strtotime($payroll['computed_at'])) : 'N/A'; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Summary Statistics -->
            <?php if (!empty($payrolls)): ?>
                <div class="compute-section">
                    <h2 style="margin-bottom: 15px;">
                        📈 Month Summary 
                        <?php if (!empty($selected_salary_type)): ?>
                            <span class="filter-badge filter-badge-active">
                                <?php echo htmlspecialchars($selected_salary_type); ?>
                            </span>
                        <?php endif; ?>
                    </h2>
                    <?php
                    $total_gross = array_sum(array_column($payrolls, 'gross_pay') ?: [0]);
                    $total_deductions = array_sum(array_column($payrolls, 'total_deductions') ?: [0]);
                    $total_net = array_sum(array_column($payrolls, 'net_pay') ?: [0]);
                    $total_days = array_sum(array_column($payrolls, 'work_days') ?: [0]);
                    $total_holiday_pay = array_sum(array_column($payrolls, 'holiday_pay') ?: [0]);
                    $total_thirteenth = array_sum(array_column($payrolls, 'thirteenth_month_pay') ?: [0]);
                    ?>
                    <div class="summary-stats">
                        <div class="stat-box">
                            <div class="stat-value"><?php echo $total_days; ?></div>
                            <div class="stat-label">Total Days Worked</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-value">₱<?php echo number_format($total_gross, 2); ?></div>
                            <div class="stat-label">Total Gross Pay</div>
                        </div>
                        <div class="stat-box" style="background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);">
                            <div class="stat-value">₱<?php echo number_format($total_deductions, 2); ?></div>
                            <div class="stat-label">Total Deductions</div>
                        </div>
                        <div class="stat-box" style="background: linear-gradient(135deg, #28a745 0%, #218838 100%);">
                            <div class="stat-value">₱<?php echo number_format($total_net, 2); ?></div>
                            <div class="stat-label">Total Net Pay</div>
                        </div>
                        <?php if ($total_holiday_pay > 0 || $total_thirteenth > 0): ?>
                            <div class="stat-box <?php echo $total_thirteenth > 0 ? 'thirteenth-stat' : 'holiday-stat'; ?>">
                                <div class="stat-value">
                                    <?php 
                                    if ($total_thirteenth > 0) {
                                        echo '₱' . number_format($total_thirteenth, 2);
                                    } else {
                                        echo '₱' . number_format($total_holiday_pay, 2);
                                    }
                                    ?>
                                </div>
                                <div class="stat-label">
                                    <?php echo $total_thirteenth > 0 ? 'Total 13th Month Pay' : 'Total Holiday Pay'; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- History Tab -->
        <div id="historyTab" class="tab-content <?php echo $view_history ? 'active' : ''; ?>">
            <div class="filter-card">
                <div class="filter-grid">
                    <div>
                        <label class="filter-label">📅 Month</label>
                        <input type="month" id="historyMonthSelect" value="<?php echo $history_month; ?>" 
                               onchange="window.location.href='payroll_computation.php?history=1&history_month=' + this.value + '&salary_type=<?php echo $selected_salary_type; ?>'">
                    </div>
                    
                    <div>
                        <label class="filter-label">👤 Employee</label>
                        <select id="historyEmployeeSelect" onchange="if(this.value) window.location.href='payroll_computation.php?history=1&history_month=<?php echo $history_month; ?>&employee=' + this.value + '&salary_type=<?php echo $selected_salary_type; ?>'">
                            <option value="">All Employees</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo $emp['employee_id']; ?>"
                                    <?php echo $selected_employee == $emp['employee_id'] ? 'selected' : ''; ?>>
                                    <?php echo $emp['first_name'] . ' ' . $emp['last_name']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="filter-label">💰 Salary Type</label>
                        <select id="historySalaryTypeFilter" onchange="window.location.href='payroll_computation.php?history=1&history_month=<?php echo $history_month; ?>&employee=<?php echo $selected_employee; ?>&salary_type=' + this.value">
                            <option value="">All Types</option>
                            <option value="Monthly" <?php echo $selected_salary_type == 'Monthly' ? 'selected' : ''; ?>>Monthly</option>
                            <option value="Bi-monthly" <?php echo $selected_salary_type == 'Bi-monthly' ? 'selected' : ''; ?>>Bi-monthly</option>
                            <option value="Weekly" <?php echo $selected_salary_type == 'Weekly' ? 'selected' : ''; ?>>Weekly</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="filter-label">&nbsp;</label>
                        <button class="btn btn-back" onclick="window.location.href='payroll_computation.php?history=1'">🔄 Reset</button>
                    </div>
                </div>
            </div>

            <!-- Payment History Summary -->
            <?php if (!empty($payment_history)): ?>
                <div class="compute-section">
                    <h2 style="margin-bottom: 15px;">📊 Payment History Summary</h2>
                    <div class="summary-stats">
                        <?php $display_count = 0; ?>
                        <?php foreach ($payment_history as $history): ?>
                            <?php if ($display_count++ < 4): ?>
                                <div class="stat-box">
                                    <div class="stat-value">₱<?php echo number_format($history['total_amount'], 2); ?></div>
                                    <div class="stat-label"><?php echo date('F Y', mktime(0, 0, 0, $history['month'], 1, $history['year'])); ?></div>
                                    <div class="stat-label">(<?php echo $history['total_payments']; ?> payments)</div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Paid History Records -->
            <div class="payroll-grid">
                <?php if (empty($payrolls)): ?>
                    <div style="grid-column: 1/-1; text-align: center; padding: 50px; background: white; border-radius: 20px;">
                        <div style="font-size: 3em; margin-bottom: 20px;">📜</div>
                        <h3>No payment history for this month</h3>
                        <p style="color: #666; margin-top: 10px;">Mark payroll as paid to see history here</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($payrolls as $payroll): 
                        $salary_type = $payroll['salary_type'] ?? 'Monthly';
                        $badge_class = 'badge-monthly';
                        if ($salary_type == 'Bi-monthly') $badge_class = 'badge-bi-monthly';
                        if ($salary_type == 'Weekly') $badge_class = 'badge-weekly';
                        $has_holiday = ($payroll['holiday_days'] ?? 0) > 0;
                        $has_thirteenth = ($payroll['thirteenth_month_pay'] ?? 0) > 0;
                        $display_net_pay = $payroll['net_pay'] ?? 0;

                        // Custom deductions for history
                        $custom_deductions_display = [];
                        if (!empty($payroll['custom_deductions_breakdown'])) {
                            $custom_deductions_display = json_decode($payroll['custom_deductions_breakdown'], true);
                            if (!is_array($custom_deductions_display)) $custom_deductions_display = [];
                        }

                        // ---- RATE COMPUTATIONS (CORRECTED - History) ----
                        $emp_base_rate = (float)($payroll['hourly_rate'] ?? 0);
                        $emp_effective_rate = $emp_base_rate; // Hourly rate is constant

                        $emp_ot_multiplier = (float)($payroll['overtime_rate'] ?? 1.25);
                        $emp_ot_rate = $emp_effective_rate * $emp_ot_multiplier;

                        $emp_work_hrs_per_day = (float)($payroll['work_hours_per_day'] ?? 8);
                        $emp_work_days_per_month = (float)($payroll['work_days_per_month'] ?? 22);

                        // Fetch exact values from DB, or calculate if missing
                        $emp_daily_rate = (float)($payroll['daily_rate'] ?? 0);
                        if ($emp_daily_rate == 0) {
                            $emp_daily_rate = $emp_effective_rate * $emp_work_hrs_per_day;
                        }

                        $emp_monthly_salary = (float)($payroll['monthly_salary'] ?? 0);
                        if ($emp_monthly_salary == 0) {
                            $emp_monthly_salary = $emp_daily_rate * $emp_work_days_per_month;
                        }
                    ?>
                        <div class="payroll-card">
                            <div class="payroll-header">
                                <div>
                                    <div class="employee-name">
                                        <?php echo htmlspecialchars($payroll['first_name'] . ' ' . $payroll['last_name']); ?>
                                        <span class="salary-type-badge <?php echo $badge_class; ?>"><?php echo $salary_type; ?></span>
                                        <?php if ($has_thirteenth): ?>
                                            <span style="background: #6f42c1; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; margin-left: 5px;">🎄 13th</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="employee-position"><?php echo htmlspecialchars($payroll['position'] ?? 'N/A'); ?></div>
                                </div>
                                <div>
                                    <span class="status-badge status-paid">PAID</span>
                                </div>
                            </div>

                            <!-- Payment Info -->
                            <div class="payroll-section" style="background: #d4edda;">
                                <div class="section-title">💵 Payment Details</div>
                                <div class="payroll-detail">
                                    <span>Payment Date:</span>
                                    <span><strong><?php echo date('F d, Y h:i A', strtotime($payroll['paid_at'])); ?></strong></span>
                                </div>
                                <div class="payroll-detail">
                                    <span>Payment Method:</span>
                                    <span>
                                        <span class="payment-method method-<?php echo $payroll['payment_method']; ?>">
                                            <?php echo strtoupper($payroll['payment_method'] ?? 'CASH'); ?>
                                        </span>
                                    </span>
                                </div>
                                <?php if (!empty($payroll['payment_reference'])): ?>
                                    <div class="payroll-detail">
                                        <span>Reference:</span>
                                        <span><?php echo htmlspecialchars($payroll['payment_reference']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($payroll['payment_notes'])): ?>
                                    <div class="payroll-detail">
                                        <span>Notes:</span>
                                        <span><?php echo htmlspecialchars($payroll['payment_notes']); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Rate Information (UPDATED - History) -->
                            <div class="payroll-section">
                                <div class="section-title">💵 Rate Information</div>
                                <div class="rate-highlight">
                                    <div class="payroll-detail">
                                        <span>⏱️ Hourly Rate:</span>
                                        <span><strong>₱<?php echo number_format($emp_effective_rate, 2); ?>/hr</strong></span>
                                    </div>
                                    <div class="payroll-detail">
                                        <span>📅 Daily Rate:</span>
                                        <span><strong>₱<?php echo number_format($emp_daily_rate, 2); ?></strong></span>
                                    </div>
                                    <div class="payroll-detail">
                                        <span>💵 Monthly Salary:</span>
                                        <span><strong>₱<?php echo number_format($emp_monthly_salary, 2); ?></strong></span>
                                    </div>
                                    <div class="payroll-detail">
                                        <span>📆 Work Days/Month:</span>
                                        <span><strong><?php echo $emp_work_days_per_month; ?> days</strong></span>
                                    </div>
                                    <div class="payroll-detail">
                                        <span>⏰ Overtime Rate (<?php echo $emp_ot_multiplier; ?>x):</span>
                                        <span><strong>₱<?php echo number_format($emp_ot_rate, 2); ?>/hr</strong></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Work Summary -->
                            <div class="payroll-section">
                                <div class="section-title">📊 Work Summary</div>
                                <div class="payroll-detail">
                                    <span>Days Present:</span>
                                    <span><strong><?php echo $payroll['work_days'] ?? 0; ?></strong> days</span>
                                </div>
                                <div class="payroll-detail">
                                    <span>Total Hours:</span>
                                    <span><strong><?php echo number_format($payroll['work_hours'] ?? 0, 1); ?></strong> hrs</span>
                                </div>
                                <div class="payroll-detail">
                                    <span>Overtime Hours:</span>
                                    <span><strong><?php echo number_format($payroll['overtime_hours'] ?? 0, 1); ?></strong> hrs</span>
                                </div>
                                <?php if ($has_holiday): ?>
                                    <div class="payroll-detail" style="color: #dc3545;">
                                        <span>🎉 Holiday Pay:</span>
                                        <span><strong>₱<?php echo number_format($payroll['holiday_pay'] ?? 0, 2); ?></strong></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($has_thirteenth): ?>
                                    <div class="payroll-detail" style="color: #6f42c1;">
                                        <span>🎄 13th Month Pay:</span>
                                        <span><strong>₱<?php echo number_format($payroll['thirteenth_month_pay'] ?? 0, 2); ?></strong></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Financial Summary -->
                            <div class="total-breakdown">
                                <div class="payroll-detail">
                                    <span>Gross Pay:</span>
                                    <span class="gross-pay">₱<?php echo number_format($payroll['gross_pay'] ?? 0, 2); ?></span>
                                </div>
                                <?php if ($has_thirteenth): ?>
                                    <div class="payroll-detail" style="color: #6f42c1;">
                                        <span>+ 13th Month Pay:</span>
                                        <span class="thirteenth-amount">₱<?php echo number_format($payroll['thirteenth_month_pay'] ?? 0, 2); ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="payroll-detail">
                                    <span>Total Deductions:</span>
                                    <span class="deduction">-₱<?php echo number_format($payroll['total_deductions'] ?? 0, 2); ?></span>
                                </div>
                                <!-- Custom deductions in history -->
                                <?php if (!empty($custom_deductions_display)): ?>
                                    <?php foreach ($custom_deductions_display as $cd): ?>
                                        <div class="payroll-detail deduction">
                                            <span><?php echo htmlspecialchars($cd['name']); ?>:</span>
                                            <span>-₱<?php echo number_format($cd['amount'], 2); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <div class="payroll-detail" style="border-bottom: none;">
                                    <span style="font-size: 1.2em; font-weight: 700;">NET PAID:</span>
                                    <span class="net-pay">₱<?php echo number_format($display_net_pay, 2); ?></span>
                                </div>
                                <?php if ($has_thirteenth): ?>
                                    <div style="margin-top: 8px; padding: 8px; background: #f8f0ff; border-radius: 8px; text-align: center; font-size: 0.9em; color: #6f42c1;">
                                        🎄 Includes 13th month pay of ₱<?php echo number_format($payroll['thirteenth_month_pay'] ?? 0, 2); ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Print Payslip Button -->
                            <?php 
                            // Ensure custom_deductions_breakdown is included in the JSON
                            $payroll['custom_deductions_breakdown'] = $payroll['custom_deductions_breakdown'] ?? null;
                            $payroll_json = htmlspecialchars(json_encode($payroll), ENT_QUOTES, 'UTF-8');
                            ?>
                            <button onclick='printPayslip(<?php echo $payroll_json; ?>)' 
                                    class="btn btn-info" style="width: 100%; margin-top: 15px; background: #17a2b8;">
                                🖨️ Print Payslip
                            </button>

                            <div style="margin-top: 10px; font-size: 0.85em; color: #999; text-align: center;">
                                Period: <?php echo date('F Y', strtotime($payroll['payroll_month'])); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Payment Modal -->
    <div id="paymentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">💰 Mark as Paid</h2>
                <button class="modal-close" onclick="closePaymentModal()">×</button>
            </div>
            <form method="POST" id="paymentForm">
                <input type="hidden" name="action" value="mark_paid">
                <input type="hidden" name="payroll_id" id="paymentPayrollId">
                
                <div class="form-group">
                    <label>Employee:</label>
                    <input type="text" id="paymentEmployeeName" readonly style="background: #f8f9fa;">
                </div>
                
                <div class="form-group">
                    <label>Net Pay Amount:</label>
                    <input type="text" id="paymentAmount" readonly style="background: #f8f9fa; color: #28a745; font-weight: bold;">
                </div>
                
                <div class="form-group">
                    <label>Payment Method *</label>
                    <select name="payment_method" required>
                        <option value="cash">💵 Cash</option>
                        <option value="bank">🏦 Bank Transfer</option>
                        <option value="check">📝 Check</option>
                        <option value="gcash">📱 GCash</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Reference Number (Optional)</label>
                    <input type="text" name="payment_reference" placeholder="Check #, Transaction ID, etc.">
                </div>
                
                <div class="form-group">
                    <label>Notes (Optional)</label>
                    <textarea name="payment_notes" rows="3" placeholder="Additional notes about this payment..."></textarea>
                </div>
                
                <div class="auto-reset-notice">
                    🔄 Automatic Reset: After marking as paid, this payroll will be archived to history and next month's payroll will be automatically reset to zero for a fresh start.
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-back" onclick="closePaymentModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">✅ Confirm Payment</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Hidden forms -->
    <form id="bulkComputeForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="bulk_compute">
        <input type="hidden" name="payroll_month" id="bulkMonth" value="<?php echo $selected_month; ?>">
    </form>

    <form id="bulkComputeByTypeForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="bulk_compute_by_type">
        <input type="hidden" name="payroll_month" id="bulkByTypeMonth" value="<?php echo $selected_month; ?>">
        <input type="hidden" name="salary_type" id="bulkByTypeSalaryType" value="<?php echo $selected_salary_type; ?>">
    </form>

    <!-- 13th Month Form -->
    <form id="thirteenthForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="compute_13th_month">
        <input type="hidden" name="employee_id" id="thirteenthEmployeeId">
        <input type="hidden" name="year" id="thirteenthYearValue">
    </form>

    <script>
        function switchTab(tab) {
            if (tab === 'current') {
                window.location.href = 'payroll_computation.php?month=<?php echo $selected_month; ?>&salary_type=<?php echo $selected_salary_type; ?>';
            } else {
                window.location.href = 'payroll_computation.php?history=1&history_month=<?php echo $history_month; ?>&salary_type=<?php echo $selected_salary_type; ?>';
            }
        }
        
        function computePayroll() {
            const employee = document.getElementById('computeEmployee').value;
            const month = document.getElementById('computeMonth').value;
            
            if (!employee) {
                alert('Please select an employee');
                return;
            }
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="compute_payroll">
                <input type="hidden" name="employee_id" value="${employee}">
                <input type="hidden" name="payroll_month" value="${month}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
        
        function computeThirteenthMonth() {
            const employee = document.getElementById('thirteenthEmployee').value;
            const year = document.getElementById('thirteenthYear').value;
            
            if (!employee) {
                alert('Please select an employee');
                return;
            }
            
            if (!year) {
                alert('Please select a year');
                return;
            }
            
            if (confirm(`Compute 13th month pay for this employee for the year ${year}?\n\nThis will:\n1. Calculate total basic salary from all paid months\n2. Compute 13th month pay (total basic salary / 12)\n3. Add the amount to the December payroll\n\nProceed?`)) {
                document.getElementById('thirteenthEmployeeId').value = employee;
                document.getElementById('thirteenthYearValue').value = year;
                document.getElementById('thirteenthForm').submit();
            }
        }
        
        function bulkCompute() {
            if (confirm('Compute payroll for ALL employees with settings? This will use QR attendance data.')) {
                const month = document.getElementById('monthSelect').value;
                document.getElementById('bulkMonth').value = month;
                document.getElementById('bulkComputeForm').submit();
            }
        }
        
        function bulkComputeByType() {
            const salaryType = document.getElementById('salaryTypeFilter')?.value || '';
            const typeLabel = salaryType || 'All';
            const confirmMsg = salaryType 
                ? `Compute payroll for ALL ${salaryType} employees? This will use QR attendance data.`
                : 'Compute payroll for ALL employees with settings? This will use QR attendance data.';
            
            if (confirm(confirmMsg)) {
                const month = document.getElementById('monthSelect').value;
                document.getElementById('bulkByTypeMonth').value = month;
                document.getElementById('bulkByTypeSalaryType').value = salaryType;
                document.getElementById('bulkComputeByTypeForm').submit();
            }
        }
        
        function showPaymentModal(payrollId, employeeName, amount) {
            document.getElementById('paymentPayrollId').value = payrollId;
            document.getElementById('paymentEmployeeName').value = employeeName;
            document.getElementById('paymentAmount').value = '₱' + parseFloat(amount).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('paymentModal').classList.add('show');
        }
        
        function closePaymentModal() {
            document.getElementById('paymentModal').classList.remove('show');
        }
        
        function toggleDailyBreakdown(btn) {
            const list = btn.nextElementSibling;
            list.classList.toggle('show');
            btn.textContent = list.classList.contains('show') ? '📋 Hide Daily Breakdown' : '📋 Show Daily Breakdown';
        }
        
        function printPayslip(payrollData) {
            if (!payrollData || typeof payrollData !== 'object') {
                alert('Invalid payroll data. Cannot generate payslip.');
                return;
            }
            
            const formatMoney = (amount) => {
                let num = parseFloat(amount || 0);
                if (isNaN(num)) num = 0;
                return '₱' + num.toLocaleString('en-PH', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            };
            
            const formatDate = (dateString) => {
                if (!dateString || dateString === '0000-00-00') return 'N/A';
                try {
                    return new Date(dateString).toLocaleDateString('en-PH', {
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric'
                    });
                } catch(e) {
                    return 'N/A';
                }
            };
            
            const formatDateTime = (dateString) => {
                if (!dateString || dateString === '0000-00-00 00:00:00') return 'N/A';
                try {
                    return new Date(dateString).toLocaleString('en-PH', {
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                } catch(e) {
                    return 'N/A';
                }
            };
            
            const numberToWords = (num) => {
                let amount = parseFloat(num || 0);
                if (isNaN(amount)) amount = 0;
                if (amount === 0) return 'Zero';
                
                const ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
                              'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen',
                              'Eighteen', 'Nineteen'];
                const tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
                
                const convert = (n) => {
                    if (n < 20) return ones[n];
                    if (n < 100) return tens[Math.floor(n / 10)] + (n % 10 ? ' ' + ones[n % 10] : '');
                    if (n < 1000) return ones[Math.floor(n / 100)] + ' Hundred' + (n % 100 ? ' ' + convert(n % 100) : '');
                    if (n < 1000000) return convert(Math.floor(n / 1000)) + ' Thousand' + (n % 1000 ? ' ' + convert(n % 1000) : '');
                    return convert(Math.floor(n / 1000000)) + ' Million' + (n % 1000000 ? ' ' + convert(n % 1000000) : '');
                };
                
                const pesos = Math.floor(amount);
                const centavos = Math.round((amount - pesos) * 100);
                let result = convert(pesos) + ' Pesos';
                if (centavos > 0) {
                    result += ' and ' + convert(centavos) + ' Centavos';
                }
                return result;
            };
            
            const escapeHtml = (text) => {
                if (!text) return '';
                return String(text).replace(/[&<>]/g, function(m) {
                    if (m === '&') return '&amp;';
                    if (m === '<') return '&lt;';
                    if (m === '>') return '&gt;';
                    return m;
                });
            };
            
            const employeeName = escapeHtml((payrollData.first_name || '') + ' ' + (payrollData.last_name || ''));
            const position = escapeHtml(payrollData.position || 'N/A');
            const employeeId = escapeHtml(payrollData.employee_id || 'N/A');
            const workDays = payrollData.work_days || 0;
            const workHours = parseFloat(payrollData.work_hours || 0).toFixed(1);
            const overtimeHours = parseFloat(payrollData.overtime_hours || 0).toFixed(1);
            const grossPay = payrollData.gross_pay || 0;
            const totalDeductions = payrollData.total_deductions || 0;
            const netPay = payrollData.net_pay || 0;
            const philhealth = payrollData.philhealth_deduction || 0;
            const sss = payrollData.sss_deduction || 0;
            const pagibig = payrollData.pagibig_deduction || 0;
            const holidayPay = payrollData.holiday_pay || 0;
            const thirteenthMonthPay = payrollData.thirteenth_month_pay || 0;
            const paymentMethod = (payrollData.payment_method || 'CASH').toUpperCase();
            const paymentReference = escapeHtml(payrollData.payment_reference || '');
            const paymentNotes = escapeHtml(payrollData.payment_notes || '');
            const paidAt = payrollData.paid_at || '';
            const payrollMonth = payrollData.payroll_month || '';
            const salaryType = payrollData.salary_type || 'Monthly';

            // ---- RATE COMPUTATIONS (CORRECTED) ----
            const baseHourlyRate = parseFloat(payrollData.hourly_rate || 0);
            const effectiveHourlyRate = baseHourlyRate; // Hourly rate is constant
            const overtimeMultiplier = parseFloat(payrollData.overtime_rate || 1.25);
            const overtimeHourlyRate = effectiveHourlyRate * overtimeMultiplier;
            const workHoursPerDay = parseFloat(payrollData.work_hours_per_day || 8);

            // Fetch exact values from DB, or calculate if missing
            let exactDailyRate = parseFloat(payrollData.daily_rate || 0);
            if (exactDailyRate === 0) exactDailyRate = effectiveHourlyRate * workHoursPerDay;

            const exactWorkDaysPerMonth = parseFloat(payrollData.work_days_per_month || 22);
            let exactMonthlySalary = parseFloat(payrollData.monthly_salary || 0);
            if (exactMonthlySalary === 0) exactMonthlySalary = exactDailyRate * exactWorkDaysPerMonth;
            // ------------------------------------

            // ----- Custom deductions for payslip -----
            let customDeductionsHtml = '';
            try {
                const customDeds = JSON.parse(payrollData.custom_deductions_breakdown || '[]');
                if (Array.isArray(customDeds) && customDeds.length > 0) {
                    customDeductionsHtml = `
                        <div class="section">
                            <div class="section-title">📋 Other Deductions</div>
                            ${customDeds.map(ded => `
                                <div class="row deduction"><span><strong>${escapeHtml(ded.name)}:</strong></span><span>-${formatMoney(ded.amount)}</span></div>
                            `).join('')}
                        </div>
                    `;
                }
            } catch(e) {}
            
            const printWindow = window.open('', '_blank');
            if (!printWindow) {
                alert('Please allow pop-ups to print payslips');
                return;
            }
            
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Payslip - ${employeeName}</title>
                    <meta charset="UTF-8">
                    <style>
                        * { margin: 0; padding: 0; box-sizing: border-box; }
                        body {
                            font-family: 'Segoe UI', Arial, sans-serif;
                            margin: 0;
                            padding: 20px;
                            background: #f0f0f0;
                        }
                        .payslip {
                            max-width: 800px;
                            margin: 0 auto;
                            background: white;
                            border-radius: 15px;
                            overflow: hidden;
                            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
                        }
                        .header {
                            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                            color: white;
                            padding: 30px;
                            text-align: center;
                        }
                        .header h2 { margin-bottom: 10px; font-size: 28px; }
                        .header p { margin: 5px 0; opacity: 0.9; }
                        .body { padding: 30px; }
                        .section {
                            margin-bottom: 25px;
                            border-bottom: 1px solid #e0e0e0;
                            padding-bottom: 15px;
                        }
                        .section-title {
                            font-size: 18px;
                            font-weight: 600;
                            color: #667eea;
                            margin-bottom: 15px;
                            border-bottom: 2px solid #667eea;
                            padding-bottom: 5px;
                        }
                        .row {
                            display: flex;
                            justify-content: space-between;
                            padding: 8px 0;
                            border-bottom: 1px dashed #f0f0f0;
                        }
                        .row.total {
                            font-size: 18px;
                            font-weight: 700;
                            margin-top: 10px;
                            padding-top: 10px;
                            border-top: 2px solid #667eea;
                            border-bottom: none;
                        }
                        .gross { color: #28a745; font-weight: 600; }
                        .deduction { color: #dc3545; }
                        .net { color: #007bff; font-weight: 700; font-size: 1.3em; }
                        .holiday-pay { color: #dc3545; font-weight: 600; }
                        .thirteenth-pay { color: #6f42c1; font-weight: 600; }
                        .rate-section { background: #e7f5ff; padding: 15px; border-radius: 10px; border-left: 4px solid #007bff; }
                        .rate-section .section-title { color: #004085; border-bottom-color: #007bff; }
                        .rate-value { color: #007bff; font-weight: 700; }
                        .footer {
                            background: #f8f9fa;
                            padding: 20px;
                            text-align: center;
                            font-size: 12px;
                            color: #666;
                            border-top: 1px solid #e0e0e0;
                        }
                        .signatures {
                            margin-top: 30px;
                            padding-top: 20px;
                            border-top: 1px dashed #ccc;
                            display: flex;
                            justify-content: space-between;
                        }
                        .signature {
                            text-align: center;
                            width: 200px;
                        }
                        .signature-line {
                            margin-top: 40px;
                            border-top: 1px solid #000;
                            width: 200px;
                        }
                        .payment-method {
                            display: inline-block;
                            padding: 2px 8px;
                            border-radius: 5px;
                            background: #28a745;
                            color: white;
                            font-size: 12px;
                        }
                        .amount-words {
                            font-size: 12px;
                            color: #666;
                            margin-top: 5px;
                            font-style: italic;
                        }
                        .qr-badge {
                            background: #e7f5ff;
                            color: #004085;
                            padding: 3px 8px;
                            border-radius: 15px;
                            font-size: 11px;
                            display: inline-block;
                        }
                        .salary-badge {
                            background: #fff3cd;
                            color: #856404;
                            padding: 2px 8px;
                            border-radius: 12px;
                            font-size: 11px;
                            display: inline-block;
                            margin-left: 5px;
                        }
                        .thirteenth-badge {
                            background: #6f42c1;
                            color: white;
                            padding: 2px 8px;
                            border-radius: 12px;
                            font-size: 11px;
                            display: inline-block;
                            margin-left: 5px;
                        }
                        @media print {
                            body { background: white; padding: 0; }
                            .no-print { display: none; }
                            .payslip { box-shadow: none; }
                        }
                        .button-group {
                            text-align: center;
                            padding: 15px;
                            background: white;
                            border-top: 1px solid #e0e0e0;
                        }
                        .btn-print {
                            background: #28a745;
                            color: white;
                            border: none;
                            padding: 10px 20px;
                            border-radius: 5px;
                            cursor: pointer;
                            margin-right: 10px;
                            font-size: 14px;
                        }
                        .btn-close {
                            background: #dc3545;
                            color: white;
                            border: none;
                            padding: 10px 20px;
                            border-radius: 5px;
                            cursor: pointer;
                            font-size: 14px;
                        }
                        .btn-print:hover, .btn-close:hover { opacity: 0.9; }
                    </style>
                </head>
                <body>
                    <div class="payslip">
                        <div class="header">
                            <h2>EMPLOYEE PAYSLIP</h2>
                            <p>Pay Period: ${formatDate(payrollMonth)}</p>
                            <p>Payment Date: ${formatDateTime(paidAt)}</p>
                            <p>
                                <span class="qr-badge">📱 Based on QR Attendance</span>
                                <span class="salary-badge">${salaryType}</span>
                                ${thirteenthMonthPay > 0 ? `<span class="thirteenth-badge">🎄 13th Month</span>` : ''}
                            </p>
                        </div>
                        
                        <div class="body">
                            <div class="section">
                                <div class="section-title">👤 EMPLOYEE INFORMATION</div>
                                <div class="row"><span><strong>Employee Name:</strong></span><span>${employeeName}</span></div>
                                <div class="row"><span><strong>Position:</strong></span><span>${position}</span></div>
                                <div class="row"><span><strong>Employee ID:</strong></span><span>${employeeId}</span></div>
                                <div class="row"><span><strong>Salary Type:</strong></span><span>${salaryType}</span></div>
                            </div>

                            <div class="section rate-section">
                                <div class="section-title">💵 RATE INFORMATION</div>
                                <div class="row"><span><strong>Hourly Rate:</strong></span><span class="rate-value">${formatMoney(effectiveHourlyRate)}/hr</span></div>
                                <div class="row"><span><strong>Daily Rate:</strong></span><span class="rate-value">${formatMoney(exactDailyRate)}</span></div>
                                <div class="row"><span><strong>Monthly Salary:</strong></span><span class="rate-value">${formatMoney(exactMonthlySalary)}</span></div>
                                <div class="row"><span><strong>Work Days/Month:</strong></span><span class="rate-value">${exactWorkDaysPerMonth} days</span></div>
                                <div class="row"><span><strong>Overtime Rate (${overtimeMultiplier}x):</strong></span><span class="rate-value">${formatMoney(overtimeHourlyRate)}/hr</span></div>
                            </div>
                            
                            <div class="section">
                                <div class="section-title">📊 QR ATTENDANCE SUMMARY</div>
                                <div class="row"><span><strong>Days Present:</strong></span><span>${workDays} days</span></div>
                                <div class="row"><span><strong>Regular Hours Worked:</strong></span><span>${workHours} hours</span></div>
                                <div class="row"><span><strong>Overtime Hours:</strong></span><span>${overtimeHours} hours</span></div>
                                ${holidayPay > 0 ? `<div class="row" style="color: #dc3545;"><span><strong>Holiday Days Worked:</strong></span><span>${payrollData.holiday_days || 0} days</span></div>` : ''}
                            </div>
                            
                            <div class="section">
                                <div class="section-title">💰 EARNINGS BREAKDOWN</div>
                                <div class="row"><span><strong>Gross Pay:</strong></span><span class="gross">${formatMoney(grossPay)}</span></div>
                                ${holidayPay > 0 ? `<div class="row holiday-pay"><span><strong>🎉 Holiday Pay:</strong></span><span>${formatMoney(holidayPay)}</span></div>` : ''}
                                ${thirteenthMonthPay > 0 ? `<div class="row thirteenth-pay"><span><strong>🎄 13th Month Pay:</strong></span><span>${formatMoney(thirteenthMonthPay)}</span></div>` : ''}
                                <div class="row deduction"><span><strong>Less: Deductions</strong></span><span>-${formatMoney(totalDeductions)}</span></div>
                                <div class="row total"><span><strong>NET PAY:</strong></span><span class="net">${formatMoney(netPay)}</span></div>
                                <div class="amount-words"><strong>Amount in Words:</strong> ${numberToWords(netPay)}</div>
                            </div>
                            
                            <div class="section">
                                <div class="section-title">📉 DEDUCTIONS DETAILS</div>
                                <div class="row"><span><strong>PhilHealth:</strong></span><span>${formatMoney(philhealth)}</span></div>
                                <div class="row"><span><strong>SSS:</strong></span><span>${formatMoney(sss)}</span></div>
                                <div class="row"><span><strong>Pag-IBIG:</strong></span><span>${formatMoney(pagibig)}</span></div>
                            </div>
                            
                            ${customDeductionsHtml}
                            
                            <div class="section">
                                <div class="section-title">💵 PAYMENT INFORMATION</div>
                                <div class="row"><span><strong>Payment Method:</strong></span><span><span class="payment-method">${paymentMethod}</span></span></div>
                                ${paymentReference ? `<div class="row"><span><strong>Reference Number:</strong></span><span>${paymentReference}</span></div>` : ''}
                                ${paymentNotes ? `<div class="row"><span><strong>Notes:</strong></span><span>${paymentNotes}</span></div>` : ''}
                            </div>
                            
                            <div class="signatures">
                                <div class="signature">
                                    <div class="signature-line"></div>
                                    <p style="margin-top: 5px; font-size: 12px;">Employee Signature</p>
                                </div>
                                <div class="signature">
                                    <div class="signature-line"></div>
                                    <p style="margin-top: 5px; font-size: 12px;">Authorized Signature</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="footer">
                            <p>This is a computer-generated payslip based on QR attendance logs.</p>
                            <p>Generated on: ${new Date().toLocaleString()}</p>
                        </div>
                        
                        <div class="button-group no-print">
                            <button class="btn-print" onclick="window.print()">🖨️ Print Payslip</button>
                            <button class="btn-close" onclick="window.close()">❌ Close</button>
                        </div>
                    </div>
                </body>
                </html>
            `);
            printWindow.document.close();
        }
        
        window.onclick = function(event) {
            const modal = document.getElementById('paymentModal');
            if (event.target == modal) {
                closePaymentModal();
            }
        }
    </script>
</body>
</html>