<?php
require_once 'admin_auth.php'; 
require_once 'configa.php';

$message = '';
$message_type = '';

/* ------------------------------------------------------------------
   Get all employees (position column is optional)
   ------------------------------------------------------------------ */
try {
    $employees = $pdo->query("SELECT employee_id, first_name, last_name, position FROM employees ORDER BY first_name")->fetchAll();
} catch (PDOException $e) {
    $employees = $pdo->query("SELECT employee_id, first_name, last_name FROM employees ORDER BY first_name")->fetchAll();
}

/* ------------------------------------------------------------------
   Selected date / employee / calendar month
   ------------------------------------------------------------------ */
$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

$view_employee_id = $_GET['employee'] ?? '';

$calendar_month = $_GET['month'] ?? date('Y-m', strtotime($date));
if (!preg_match('/^\d{4}-\d{2}$/', $calendar_month)) {
    $calendar_month = date('Y-m');
}

/* ------------------------------------------------------------------
   Handle saving
   ------------------------------------------------------------------
   NOTE: Holidays are now ALLOWED. Only rest days are blocked.
   ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_schedules'])) {
    $schedules = $_POST['schedule'] ?? [];
    $date      = $_POST['date'] ?? date('Y-m-d');
    $saved     = 0;
    $blocked   = 0;

    $dow = date('l', strtotime($date));

    foreach ($schedules as $emp_id => $times) {

        // Server-side guard: block REST DAYS only (holidays are allowed)
        $r_chk = $pdo->prepare("SELECT 1 FROM employee_rest_days 
                                WHERE employee_id = ? 
                                  AND (day_of_week = ? OR custom_date = ?) 
                                LIMIT 1");
        $r_chk->execute([$emp_id, $dow, $date]);
        if ($r_chk->fetchColumn()) {
            $blocked++;
            continue;   // never write a schedule on a rest day
        }

        $time_in  = !empty($times['time_in'])  ? $times['time_in']  . ':00' : null;
        $time_out = !empty($times['time_out']) ? $times['time_out'] . ':00' : null;

        // Check if schedule already exists
        $check = $pdo->prepare("SELECT id FROM employee_work_schedules WHERE employee_id = ? AND work_date = ?");
        $check->execute([$emp_id, $date]);
        $existing = $check->fetch();

        if ($existing) {
            $update = $pdo->prepare("UPDATE employee_work_schedules 
                                     SET scheduled_time_in = ?, scheduled_time_out = ? 
                                     WHERE employee_id = ? AND work_date = ?");
            $update->execute([$time_in, $time_out, $emp_id, $date]);
            $saved++;
        } else {
            if ($time_in || $time_out) {
                $insert = $pdo->prepare("INSERT INTO employee_work_schedules 
                                         (employee_id, work_date, scheduled_time_in, scheduled_time_out, status, hours_worked, overtime_hours, notes) 
                                         VALUES (?, ?, ?, ?, 'present', 0, 0, 'Manual schedule')");
                $insert->execute([$emp_id, $date, $time_in, $time_out]);
                $saved++;
            }
        }
    }

    $message = "Schedules saved successfully! ($saved saved";
    if ($blocked) $message .= ", $blocked skipped (rest day)";
    $message .= ")";
    $message_type = "success";
}

/* ------------------------------------------------------------------
   Which employees are we viewing?
   ------------------------------------------------------------------ */
$schedules    = [];
$rest_days    = [];

if ($view_employee_id) {
    $emp_ids = [$view_employee_id];
} else {
    $emp_ids = array_column($employees, 'employee_id');
}

/* ------------------------------------------------------------------
   Existing schedules for the selected date
   ------------------------------------------------------------------ */
if (!empty($emp_ids)) {
    $stmt = $pdo->prepare("SELECT employee_id, scheduled_time_in, scheduled_time_out, status 
                           FROM employee_work_schedules 
                           WHERE work_date = ? AND employee_id IN (" . implode(',', array_fill(0, count($emp_ids), '?')) . ")");
    $params = array_merge([$date], $emp_ids);
    $stmt->execute($params);
    while ($row = $stmt->fetch()) {
        $schedules[$row['employee_id']] = $row;
    }

    /* Rest days for these employees (recurring + custom) */
    $rest_query = $pdo->prepare("SELECT employee_id, day_of_week, custom_date 
                                 FROM employee_rest_days 
                                 WHERE employee_id IN (" . implode(',', array_fill(0, count($emp_ids), '?')) . ")");
    $rest_query->execute($emp_ids);
    $day_of_week = date('l', strtotime($date));
    while ($row = $rest_query->fetch()) {
        if ($row['day_of_week'] == $day_of_week || $row['custom_date'] == $date) {
            $rest_days[$row['employee_id']] = true;
        }
    }
}

/* Holiday flag for the selected date (informational only — does NOT block) */
$holiday_stmt = $pdo->prepare("SELECT holiday_date, holiday_name, holiday_type 
                               FROM holidays WHERE holiday_date = ? LIMIT 1");
try {
    $holiday_stmt->execute([$date]);
    $holiday_row = $holiday_stmt->fetch();
} catch (PDOException $e) {
    // Fallback if holiday_name / holiday_type columns don't exist
    $holiday_stmt = $pdo->prepare("SELECT holiday_date FROM holidays WHERE holiday_date = ?");
    $holiday_stmt->execute([$date]);
    $holiday_row = $holiday_stmt->fetch();
}
$is_holiday = (bool) $holiday_row;

/* ==================================================================
   CALENDAR DATA
   ================================================================== */
$cal_start         = $calendar_month . '-01';
$cal_end           = date('Y-m-t', strtotime($cal_start));
$days_in_cal_month = (int) date('t', strtotime($cal_start));
$first_dow         = (int) date('N', strtotime($cal_start));   // 1=Mon ... 7=Sun
$prev_month        = date('Y-m', strtotime($cal_start . ' -1 month'));
$next_month        = date('Y-m', strtotime($cal_start . ' +1 month'));

/* Holidays in the displayed month (shown but NOT blocked) */
$cal_holidays = [];   // 'YYYY-MM-DD' => name/type
$h_stmt = $pdo->prepare("SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN ? AND ?");
$h_stmt->execute([$cal_start, $cal_end]);
foreach ($h_stmt->fetchAll(PDO::FETCH_COLUMN) as $hd) {
    $cal_holidays[$hd] = true;
}

/* Rest days in the displayed month for the selected employees */
$cal_rest = [];   // 'YYYY-MM-DD' => [emp_id => true]
if (!empty($emp_ids)) {
    $emp_rest_map = [];
    foreach ($emp_ids as $eid) {
        $emp_rest_map[$eid] = ['days' => [], 'dates' => []];
    }

    $rest_stmt = $pdo->prepare("SELECT employee_id, day_of_week, custom_date 
                                FROM employee_rest_days 
                                WHERE employee_id IN (" . implode(',', array_fill(0, count($emp_ids), '?')) . ")");
    $rest_stmt->execute($emp_ids);

    while ($r = $rest_stmt->fetch()) {
        if ($r['day_of_week']) {
            $emp_rest_map[$r['employee_id']]['days'][] = $r['day_of_week'];
        }
        if ($r['custom_date']) {
            $emp_rest_map[$r['employee_id']]['dates'][] = $r['custom_date'];
        }
    }

    for ($d = 1; $d <= $days_in_cal_month; $d++) {
        $ds  = sprintf('%s-%02d', $calendar_month, $d);
        $dow = date('l', strtotime($ds));
        foreach ($emp_ids as $eid) {
            if (in_array($dow, $emp_rest_map[$eid]['days']) || in_array($ds, $emp_rest_map[$eid]['dates'])) {
                $cal_rest[$ds][$eid] = true;
            }
        }
    }
}

/**
 * Returns 'rest' if the date is blocked (rest day), otherwise false.
 * Holidays are intentionally NOT blocked — they are shown for information only.
 */
if (!function_exists('calendar_day_block_state')) {
    function calendar_day_block_state($ds, $cal_rest, $view_employee_id, $emp_ids) {
        if ($view_employee_id && isset($cal_rest[$ds][$view_employee_id])) {
            return 'rest';
        }

        if (!$view_employee_id && !empty($emp_ids)) {
            $all_rest = true;
            foreach ($emp_ids as $eid) {
                if (!isset($cal_rest[$ds][$eid])) { $all_rest = false; break; }
            }
            if ($all_rest) return 'rest';
        }

        return false;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manual Schedule Maker</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Segoe UI', sans-serif;
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
        .card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
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
        .btn-back:hover { background: white; color: #667eea; }
        .btn-primary { background: #28a745; color: white; }
        .btn-primary:hover { background: #218838; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-danger:hover { background: #c82333; }

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
        .alert { padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-error   { background: #f8d7da; color: #721c24; }

        /* ============ CALENDAR ============ */
        .calendar { margin-top: 5px; }
        .cal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
        }
        .cal-title {
            font-size: 1.35em;
            font-weight: 700;
            color: #333;
            letter-spacing: .5px;
        }
        .cal-nav {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: #f1f3f5;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            color: #333;
            font-size: 1.5em;
            font-weight: 700;
            line-height: 1;
            transition: all .2s;
        }
        .cal-nav:hover { background: #667eea; color: #fff; }

        .cal-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 6px;
        }
        .cal-dow {
            text-align: center;
            font-weight: 700;
            color: #888;
            font-size: 0.78em;
            padding: 8px 0;
            text-transform: uppercase;
            letter-spacing: .5px;
        }
        .cal-day {
            min-height: 58px;
            border-radius: 10px;
            padding: 6px 4px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            color: #333;
            background: #f8f9fa;
            font-weight: 600;
            font-size: 0.95em;
            position: relative;
            transition: all .15s;
            line-height: 1.2;
        }
        .cal-day:hover { background: #e9ecef; transform: translateY(-2px); }
        .cal-day.empty { background: transparent; pointer-events: none; }

        /* selected */
        .cal-day.selected {
            background: #667eea;
            color: #fff;
            box-shadow: 0 6px 18px rgba(102,126,234,.4);
        }
        .cal-day.selected:hover { background: #5568d3; }

        /* today */
        .cal-day.today {
            border: 2px solid #667eea;
            padding: 4px 2px;
        }

        /* HOLIDAY — clickable, only tinted red to inform */
        .cal-day.holiday {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .cal-day.holiday:hover {
            background: #f1b0b7;
            transform: translateY(-2px);
        }
        /* Keep the red tint even when selected */
        .cal-day.holiday.selected {
            background: #dc3545;
            color: #fff;
            border-color: #c82333;
            box-shadow: 0 6px 18px rgba(220,53,69,.4);
        }
        .cal-day.holiday.selected:hover { background: #c82333; }

        /* REST DAY — blocked */
        .cal-day.rest {
            background: #fff3cd;
            color: #856404;
            cursor: not-allowed;
            opacity: .85;
        }
        .cal-day.rest:hover {
            transform: none;
            background: #fff3cd;
        }

        .cal-badge {
            font-size: 0.75em;
            margin-top: 2px;
        }
        .cal-legend {
            display: flex;
            gap: 18px;
            margin-top: 18px;
            font-size: 0.85em;
            color: #666;
            flex-wrap: wrap;
        }
        .cal-legend span { display: flex; align-items: center; gap: 6px; }
        .dot {
            width: 13px;
            height: 13px;
            border-radius: 4px;
            display: inline-block;
        }
        .dot-selected { background: #667eea; }
        .dot-today    { background: #fff; border: 2px solid #667eea; }
        .dot-holiday  { background: #f8d7da; border: 1px solid #f5c6cb; }
        .dot-rest     { background: #fff3cd; border: 1px solid #ffeeba; }

        /* ============ SCHEDULE TABLE ============ */
        .schedule-table { width: 100%; border-collapse: collapse; }
        .schedule-table th, .schedule-table td {
            padding: 12px 15px;
            border: 1px solid #e0e0e0;
            text-align: left;
        }
        .schedule-table th { background: #f8f9fa; font-weight: 600; color: #333; }
        .schedule-table tr:hover { background: #f1f3f5; }
        .schedule-table input[type="time"] {
            padding: 8px 10px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            width: 100%;
        }
        .schedule-table input[type="time"]:disabled {
            background: #e9ecef;
            cursor: not-allowed;
        }
        .rest-day-badge {
            background: #ffc107; color: #856404;
            padding: 5px 10px; border-radius: 20px;
            font-size: 0.9em; display: inline-block;
        }
        .holiday-badge {
            background: #dc3545; color: white;
            padding: 5px 10px; border-radius: 20px;
            font-size: 0.9em; display: inline-block;
        }
        .holiday-note {
            background: #fff3cd;
            border-left: 4px solid #dc3545;
            padding: 10px 14px;
            border-radius: 8px;
            color: #721c24;
            font-size: 0.9em;
            margin-bottom: 15px;
        }
        .header-buttons {
            display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap;
        }
        .info-text { font-size: 0.9em; color: #666; margin-top: 5px; }
        .date-label {
            font-weight: 600; color: #333; margin-bottom: 15px;
            font-size: 1.1em;
        }
        .save-row { margin-top: 20px; text-align: center; }

        @media (max-width: 768px) {
            .filter-grid { grid-template-columns: 1fr; }
            .schedule-table th, .schedule-table td { padding: 8px 10px; font-size: 0.9em; }
        }
        @media (max-width: 600px) {
            .card { padding: 18px; }
            h1 { font-size: 1.8em; }
            .cal-day { min-height: 44px; font-size: 0.82em; border-radius: 8px; }
            .cal-dow { font-size: 0.65em; }
            .cal-nav { width: 36px; height: 36px; font-size: 1.2em; }
            .cal-title { font-size: 1.05em; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📋 Manual Schedule Maker</h1>

        <div class="header-buttons">
            <a href="employee_work_schedule.php" class="btn btn-back">← Back to Schedule View</a>
            <a href="admin_dashboard.php" class="btn btn-back">Dashboard</a>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <!-- ===================== FILTER ===================== -->
        <div class="card">
            <form method="GET" action="schedule_maker.php" class="filter-grid">
                <div>
                    <label style="font-weight:500; display:block; margin-bottom:5px;">Date</label>
                    <input type="date" name="date" value="<?php echo $date; ?>" required>
                </div>
                <div>
                    <label style="font-weight:500; display:block; margin-bottom:5px;">Employee</label>
                    <select name="employee">
                        <option value="">All Employees</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?php echo $emp['employee_id']; ?>" <?php echo $view_employee_id == $emp['employee_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display: flex; gap: 10px; align-items: end;">
                    <button type="submit" class="btn btn-primary">Load</button>
                    <a href="schedule_maker.php?date=<?php echo date('Y-m-d'); ?>" class="btn btn-back">Today</a>
                </div>
            </form>
        </div>

        <!-- ===================== CALENDAR ===================== -->
        <div class="card">
            <h2 style="margin-bottom: 15px; color:#333; font-size: 1.2em;">📅 Pick a Date</h2>
            <div class="calendar">
                <div class="cal-header">
                    <a class="cal-nav"
                       href="?date=<?php echo urlencode($date); ?>&employee=<?php echo urlencode($view_employee_id); ?>&month=<?php echo $prev_month; ?>"
                       title="Previous month">‹</a>
                    <div class="cal-title"><?php echo date('F Y', strtotime($cal_start)); ?></div>
                    <a class="cal-nav"
                       href="?date=<?php echo urlencode($date); ?>&employee=<?php echo urlencode($view_employee_id); ?>&month=<?php echo $next_month; ?>"
                       title="Next month">›</a>
                </div>

                <div class="cal-grid">
                    <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $dn): ?>
                        <div class="cal-dow"><?php echo $dn; ?></div>
                    <?php endforeach; ?>

                    <?php
                    // Empty cells before the 1st
                    for ($i = 1; $i < $first_dow; $i++) {
                        echo '<div class="cal-day empty"></div>';
                    }

                    // Day cells
                    for ($d = 1; $d <= $days_in_cal_month; $d++):
                        $ds = sprintf('%s-%02d', $calendar_month, $d);

                        $is_holiday_day  = isset($cal_holidays[$ds]);
                        $block_state     = calendar_day_block_state($ds, $cal_rest, $view_employee_id, $emp_ids);
                        $is_blocked      = ($block_state === 'rest');

                        $is_selected = ($ds === $date);
                        $is_today    = ($ds === date('Y-m-d'));

                        $classes = ['cal-day'];
                        if ($is_selected)     $classes[] = 'selected';
                        if ($is_today)        $classes[] = 'today';
                        if ($is_holiday_day)  $classes[] = 'holiday';
                        if ($is_blocked)      $classes[] = 'rest';
                        $class_str = implode(' ', $classes);

                        $tooltip = date('l, F d, Y', strtotime($ds));
                        if ($is_holiday_day) $tooltip .= ' — 🎉 Holiday (double pay / +30% if worked)';
                        if ($is_blocked)     $tooltip .= ' — 🛌 Rest Day (blocked)';

                        $link = '?date=' . $ds
                              . '&employee=' . urlencode($view_employee_id)
                              . '&month=' . urlencode($calendar_month);
                    ?>

                        <?php if ($is_blocked): ?>
                            <div class="<?php echo $class_str; ?>" title="<?php echo htmlspecialchars($tooltip); ?>">
                                <span><?php echo $d; ?></span>
                                <span class="cal-badge">🛌</span>
                            </div>
                        <?php else: ?>
                            <a class="<?php echo $class_str; ?>"
                               href="<?php echo $link; ?>"
                               title="<?php echo htmlspecialchars($tooltip); ?>">
                                <span><?php echo $d; ?></span>
                                <?php if ($is_holiday_day): ?>
                                    <span class="cal-badge">🎉</span>
                                <?php endif; ?>
                            </a>
                        <?php endif; ?>

                    <?php endfor; ?>
                </div>

                <div class="cal-legend">
                    <span><span class="dot dot-selected"></span> Selected</span>
                    <span><span class="dot dot-today"></span> Today</span>
                    <span><span class="dot dot-holiday"></span> Holiday (double pay / +30%)</span>
                    <span><span class="dot dot-rest"></span> Rest Day (blocked)</span>
                </div>
                <div class="info-text" style="margin-top: 10px;">
                    💡 Click any available day to load its schedule form. Holidays are allowed — schedule them to record double pay or +30% rates. Rest days (🛌) are locked.
                </div>
            </div>
        </div>

        <!-- ===================== SCHEDULE FORM ===================== -->
        <div class="card">
            <div class="date-label">
                📅 <?php echo date('l, F d, Y', strtotime($date)); ?>
                <?php if ($is_holiday): ?>
                    <span class="holiday-badge">🎉 Holiday</span>
                <?php endif; ?>
            </div>

            <?php if ($is_holiday): ?>
                <div class="holiday-note">
                    🎉 <strong>This is a holiday.</strong>
                    Any employee scheduled today is entitled to <strong>double pay</strong>
                    or a <strong>+30% premium</strong>, depending on how this holiday is
                    configured in your system.
                </div>
            <?php endif; ?>

            <?php if (empty($emp_ids)): ?>
                <p style="color: #666;">No employees found.</p>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="date" value="<?php echo $date; ?>">
                    <table class="schedule-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Position</th>
                                <th>Day Type</th>
                                <th style="width: 150px;">Scheduled Time‑In</th>
                                <th style="width: 150px;">Scheduled Time‑Out</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($employees as $emp):
                                if ($view_employee_id && $view_employee_id != $emp['employee_id']) continue;
                                $emp_id = $emp['employee_id'];
                                $is_rest = isset($rest_days[$emp_id]);   // <-- only rest days block now
                                $schedule = $schedules[$emp_id] ?? null;

                                $time_in  = ($schedule && !empty($schedule['scheduled_time_in']))  ? date('H:i', strtotime($schedule['scheduled_time_in']))  : '';
                                $time_out = ($schedule && !empty($schedule['scheduled_time_out'])) ? date('H:i', strtotime($schedule['scheduled_time_out'])) : '';
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($emp['position'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php if ($is_rest): ?>
                                        <span class="rest-day-badge">🛌 Rest Day</span>
                                    <?php elseif ($is_holiday): ?>
                                        <span class="holiday-badge">🎉 Holiday</span>
                                    <?php else: ?>
                                        <span style="color: #28a745;">✅ Work Day</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <input type="time" name="schedule[<?php echo $emp_id; ?>][time_in]"
                                           value="<?php echo $time_in; ?>"
                                           <?php echo $is_rest ? 'disabled' : ''; ?>>
                                </td>
                                <td>
                                    <input type="time" name="schedule[<?php echo $emp_id; ?>][time_out]"
                                           value="<?php echo $time_out; ?>"
                                           <?php echo $is_rest ? 'disabled' : ''; ?>>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="save-row">
                        <button type="submit" name="save_schedules" class="btn btn-primary" style="padding: 15px 50px; font-size: 18px;">
                            💾 Save All Schedules
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>