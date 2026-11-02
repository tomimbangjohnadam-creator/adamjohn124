<?php
require_once 'admin_auth.php';
require_once 'configa.php';

// ============================================================
// CONFIG
// ============================================================
define('FORGOT_TIMEOUT_HOURS', 12); // flag if time_in older than this and no time_out

// Auto-create overtime table if missing
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

// ============================================================
// AJAX HANDLERS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    $admin_name = $_SESSION['admin_username']
        ?? $_SESSION['username']
        ?? $_SESSION['admin_name']
        ?? 'Admin';

    try {
        // ---------------------------------------------------
        // APPROVE / UPDATE OVERTIME
        // ---------------------------------------------------
        if ($_POST['action'] === 'approve_overtime') {
            $employee_id = trim($_POST['employee_id'] ?? '');
            $date        = $_POST['date'] ?? date('Y-m-d');
            $hours       = (float)($_POST['overtime_hours'] ?? 0);
            $notes       = trim($_POST['notes'] ?? '');

            if ($employee_id === '' || $hours <= 0) {
                echo json_encode(['success' => false, 'message' => 'Employee ID and positive OT hours are required.']);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO overtime_approvals (employee_id, date, overtime_hours, approved_by, notes, status)
                VALUES (?, ?, ?, ?, ?, 'approved')
                ON DUPLICATE KEY UPDATE
                    overtime_hours = VALUES(overtime_hours),
                    approved_by    = VALUES(approved_by),
                    approved_at    = CURRENT_TIMESTAMP,
                    notes          = VALUES(notes),
                    status         = 'approved'
            ");
            $stmt->execute([$employee_id, $date, $hours, $admin_name, $notes]);

            echo json_encode([
                'success' => true,
                'message' => "Overtime of {$hours} hr(s) approved for {$employee_id} on {$date}."
            ]);
            exit;
        }

        // ---------------------------------------------------
        // CANCEL OVERTIME
        // ---------------------------------------------------
        if ($_POST['action'] === 'cancel_overtime') {
            $employee_id = trim($_POST['employee_id'] ?? '');
            $date        = $_POST['date'] ?? date('Y-m-d');

            $stmt = $pdo->prepare("
                UPDATE overtime_approvals
                SET status = 'cancelled', approved_by = ?, approved_at = CURRENT_TIMESTAMP
                WHERE employee_id = ? AND date = ?
            ");
            $stmt->execute([$admin_name, $employee_id, $date]);

            echo json_encode(['success' => true, 'message' => "Overtime cancelled for {$employee_id}."]);
            exit;
        }

        // ---------------------------------------------------
        // FORCE TIME OUT (admin closes an open attendance)
        // ---------------------------------------------------
        if ($_POST['action'] === 'force_timeout') {
            $attendance_id = (int)($_POST['attendance_id'] ?? 0);
            $forced_time   = $_POST['forced_time'] ?? date('H:i:s'); // HH:MM or HH:MM:SS
            $reason        = trim($_POST['reason'] ?? 'Admin forced timeout');

            if ($attendance_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid attendance ID.']);
                exit;
            }

            // Normalize time to HH:MM:SS
            if (preg_match('/^\d{2}:\d{2}$/', $forced_time)) {
                $forced_time .= ':00';
            }

            // Load attendance
            $stmt = $pdo->prepare("SELECT * FROM attendance WHERE id = ?");
            $stmt->execute([$attendance_id]);
            $att = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$att) {
                echo json_encode(['success' => false, 'message' => 'Attendance record not found.']);
                exit;
            }
            if ($att['time_out']) {
                echo json_encode(['success' => false, 'message' => 'This employee has already timed out.']);
                exit;
            }

            // Make sure forced_time is after time_in
            if (strtotime($forced_time) <= strtotime($att['time_in'])) {
                echo json_encode(['success' => false, 'message' => 'Forced time out must be after the Time In.']);
                exit;
            }

            // Update attendance
            $upd = $pdo->prepare("UPDATE attendance SET time_out = ? WHERE id = ?");
            $upd->execute([$forced_time, $attendance_id]);

            // Recalculate hours worked (subtract completed breaks)
            $breakSum = $pdo->prepare("
                SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, break_in, break_out)), 0) AS mins
                FROM employee_breaks
                WHERE employee_id = ? AND date = ? AND break_out IS NOT NULL
            ");
            $breakSum->execute([$att['employee_id'], $att['date']]);
            $breakMins = (int)$breakSum->fetchColumn();

            $totalSeconds = strtotime($forced_time) - strtotime($att['time_in']);
            $workSeconds  = max(0, $totalSeconds - ($breakMins * 60));
            $hoursWorked  = round($workSeconds / 3600, 2);

            // Update or insert work schedule
            $ws = $pdo->prepare("SELECT id FROM employee_work_schedules WHERE employee_id = ? AND work_date = ?");
            $ws->execute([$att['employee_id'], $att['date']]);
            $wsRow = $ws->fetch(PDO::FETCH_ASSOC);

            if ($wsRow) {
                $wu = $pdo->prepare("
                    UPDATE employee_work_schedules
                    SET time_out = ?, hours_worked = ?, break_minutes = ?,
                        notes = CONCAT(COALESCE(notes,''), ' | FORCED TIMEOUT by {$admin_name}: {$reason}')
                    WHERE id = ?
                ");
                $wu->execute([$forced_time, $hoursWorked, $breakMins, $wsRow['id']]);
            } else {
                $wi = $pdo->prepare("
                    INSERT INTO employee_work_schedules
                        (employee_id, work_date, status, hours_worked, time_in, time_out, break_minutes, notes)
                    VALUES (?, ?, 'present', ?, ?, ?, ?, ?)
                ");
                $wi->execute([
                    $att['employee_id'], $att['date'], $hoursWorked,
                    $att['time_in'], $forced_time, $breakMins,
                    "FORCED TIMEOUT by {$admin_name}: {$reason}"
                ]);
            }

            echo json_encode([
                'success'       => true,
                'message'       => "Forced Time Out set to {$forced_time}. Hours worked: {$hoursWorked} hrs.",
                'hours_worked'  => $hoursWorked,
                'break_minutes' => $breakMins
            ]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    } catch (PDOException $e) {
        error_log("admin_attendance AJAX error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================================
// PAGE DATA
// ============================================================
$filter_date     = $_GET['date']     ?? date('Y-m-d');
$filter_employee = $_GET['employee'] ?? '';

$sql = "SELECT 
            a.*, 
            e.first_name, 
            e.last_name, 
            e.position,
            e.employee_id as emp_id,
            (SELECT COUNT(*) FROM employee_breaks WHERE employee_id = a.employee_id AND date = a.date) as break_count
        FROM attendance a 
        JOIN employees e ON a.employee_id = e.employee_id 
        WHERE a.id = (
            SELECT MIN(a2.id) 
            FROM attendance a2 
            WHERE a2.employee_id = a.employee_id AND a2.date = a.date
        )";

$params = [];

if ($filter_date) {
    $sql .= " AND a.date = ?";
    $params[] = $filter_date;
}

if ($filter_employee) {
    $sql .= " AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ?)";
    $params[] = "%$filter_employee%";
    $params[] = "%$filter_employee%";
    $params[] = "%$filter_employee%";
}

$sql .= " ORDER BY a.date DESC, a.time_in ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$attendanceRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Break details + OT approvals + forgot-timeout flag
foreach ($attendanceRecords as &$record) {
    $breakDetailSql = "SELECT break_in, break_out, 
                       TIMESTAMPDIFF(MINUTE, break_in, IFNULL(break_out, NOW())) as duration,
                       CASE WHEN break_out IS NULL THEN 'on_break' ELSE 'completed' END as break_status
                       FROM employee_breaks 
                       WHERE employee_id = ? AND date = ? 
                       ORDER BY break_in";
    $breakDetailStmt = $pdo->prepare($breakDetailSql);
    $breakDetailStmt->execute([$record['employee_id'], $record['date']]);
    $record['breaks'] = $breakDetailStmt->fetchAll(PDO::FETCH_ASSOC);

    // OT approval lookup
    $otStmt = $pdo->prepare("SELECT * FROM overtime_approvals WHERE employee_id = ? AND date = ? AND status = 'approved'");
    $otStmt->execute([$record['employee_id'], $record['date']]);
    $record['overtime'] = $otStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // Forgot timeout flag
    $record['forgot_timeout'] = false;
    $record['hours_since_in'] = 0;
    if (!empty($record['time_in']) && empty($record['time_out'])) {
        $since = (time() - strtotime($record['date'] . ' ' . $record['time_in'])) / 3600;
        $record['hours_since_in'] = round($since, 1);
        if ($since >= FORGOT_TIMEOUT_HOURS) {
            $record['forgot_timeout'] = true;
        }
    }
}
unset($record);

// Count forgot timeouts for the alert banner
$forgotTimeoutList = array_values(array_filter($attendanceRecords, fn($r) => $r['forgot_timeout']));

// Statistics
$statsSql = "SELECT 
                COUNT(DISTINCT a.employee_id) as total_employees,
                SUM(CASE WHEN a.date = CURDATE() AND a.time_in IS NOT NULL THEN 1 ELSE 0 END) as present_today,
                SUM(CASE WHEN a.date = CURDATE() AND a.time_in IS NOT NULL AND a.time_out IS NULL THEN 1 ELSE 0 END) as still_working,
                (SELECT COUNT(*) FROM employee_breaks WHERE date = CURDATE()) as total_breaks_today,
                (SELECT COUNT(*) FROM employee_breaks WHERE date = CURDATE() AND break_out IS NULL) as on_break_today,
                (SELECT COUNT(*) FROM overtime_approvals WHERE date = CURDATE() AND status = 'approved') as overtime_today
             FROM attendance a";
$stats = $pdo->query($statsSql)->fetch(PDO::FETCH_ASSOC);

$countSql = "SELECT COUNT(*) as total_records FROM attendance";
$totalRecords = $pdo->query($countSql)->fetch(PDO::FETCH_ASSOC)['total_records'] ?? 0;

$employeeSql = "SELECT employee_id, first_name, last_name FROM employees ORDER BY first_name";
$employees = $pdo->query($employeeSql)->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Attendance Record - QR Attendance System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            padding: 20px;
        }

        .container { max-width: 1500px; margin: 0 auto; }

        h1 {
            text-align: center; color: white; margin-bottom: 25px;
            font-size: 2.5em; text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }

        .header-buttons { display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; }

        .btn {
            padding: 12px 22px; border: none; border-radius: 10px;
            font-size: 15px; font-weight: 600; cursor: pointer;
            transition: all 0.3s; text-decoration: none; display: inline-block;
        }
        .btn-back { background: rgba(255,255,255,0.2); color: white; border: 2px solid white; }
        .btn-back:hover { background: white; color: #667eea; }
        .btn-scan { background: #28a745; color: white; }
        .btn-scan:hover { background: #218838; transform: translateY(-2px); }
        .btn-refresh { background: #17a2b8; color: white; margin-left: auto; }
        .btn-refresh:hover { background: #138496; transform: translateY(-2px); }
        .btn-break-report { background: #fd7e14; color: white; }
        .btn-break-report:hover { background: #e06b0f; transform: translateY(-2px); }

        /* Alert banner */
        .alert-banner {
            background: #fff3cd;
            border-left: 6px solid #ffc107;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            color: #856404;
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        }
        .alert-banner h3 { margin-bottom: 8px; color: #b45309; font-size: 1.15em; }
        .alert-banner ul { margin-left: 20px; }
        .alert-banner li { padding: 3px 0; font-weight: 500; }
        .alert-banner .time-since { color: #dc3545; font-weight: 700; }

        .stats-container {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 15px; margin-bottom: 20px;
        }

        .stat-card {
            background: white; padding: 18px 15px; border-radius: 15px;
            text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .stat-card h3 { font-size: 2.2em; color: #667eea; margin-bottom: 3px; }
        .stat-card p { color: #666; font-size: 0.9em; font-weight: 500; }
        .stat-card.break-stat h3 { color: #fd7e14; }
        .stat-card.ot-stat h3 { color: #28a745; }

        .filter-section {
            background: white; padding: 20px; border-radius: 15px;
            margin-bottom: 20px; display: flex; gap: 15px; flex-wrap: wrap; align-items: center;
        }
        .filter-input {
            flex: 1; min-width: 180px; padding: 12px 15px;
            border: 2px solid #e0e0e0; border-radius: 10px; font-size: 15px;
        }
        .filter-input:focus { outline: none; border-color: #667eea; }
        .filter-btn {
            background: #667eea; color: white; padding: 12px 22px;
            border: none; border-radius: 10px; cursor: pointer; font-weight: 600;
        }
        .filter-btn:hover { background: #5a67d8; }
        .filter-btn.clear { background: #6c757d; }
        .filter-btn.clear:hover { background: #5a6268; }

        .table-container {
            background: white; border-radius: 15px; overflow-x: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        table { width: 100%; border-collapse: collapse; min-width: 1250px; }

        th {
            background: #667eea; color: white; padding: 12px 10px;
            font-weight: 600; text-transform: uppercase; font-size: 0.78em;
            position: sticky; top: 0; z-index: 10; white-space: nowrap;
        }
        td {
            padding: 10px 10px; border-bottom: 1px solid #e0e0e0;
            text-align: center; vertical-align: middle; font-size: 0.88em;
        }
        tr:hover { background: #f8f9fa; }
        tr.row-forgot-timeout { background: #fff4f4; }
        tr.row-forgot-timeout:hover { background: #ffe8e8; }
        tr.row-ot { background: #f0fff4; }
        tr.row-ot:hover { background: #e1f7e8; }

        .status-badge {
            padding: 4px 10px; border-radius: 20px; font-size: 0.75em;
            font-weight: 600; display: inline-block; white-space: nowrap;
        }
        .status-present { background: #d4edda; color: #155724; }
        .status-working { background: #fff3cd; color: #856404; }
        .status-completed { background: #cce5ff; color: #004085; }
        .status-on-break { background: #f8d7da; color: #721c24; }
        .status-absent { background: #e2e3e5; color: #383d41; }
        .status-forgot { background: #f5c6cb; color: #721c24; animation: pulseRed 1.6s infinite; }
        .status-ot { background: #d4edda; color: #155724; }

        @keyframes pulseRed {
            0%, 100% { box-shadow: 0 0 0 0 rgba(220,53,69,0.4); }
            50%      { box-shadow: 0 0 0 6px rgba(220,53,69,0); }
        }

        .no-data { text-align: center; padding: 50px; color: #999; font-size: 1.2em; }
        .time-display { font-weight: 600; color: #333; }

        .break-details { font-size: 0.8em; color: #6f42c1; text-align: left; }
        .break-item { display: block; padding: 2px 0; border-bottom: 1px dashed #e0e0e0; }
        .break-item:last-child { border-bottom: none; }
        .break-on { color: #fd7e14; font-weight: 600; }
        .break-completed { color: #6f42c1; }
        .break-duration { font-size: 0.85em; color: #666; }
        .hours-worked { color: #28a745; font-weight: 600; }
        .break-minutes { color: #fd7e14; font-weight: 600; }
        .no-breaks { color: #999; font-size: 0.8em; }

        .ot-badge {
            display: inline-block; background: #28a745; color: white;
            padding: 3px 10px; border-radius: 20px; font-size: 0.75em;
            font-weight: 700; margin-bottom: 4px;
        }
        .ot-hours { color: #28a745; font-weight: 700; font-size: 0.9em; }
        .ot-approved-by { font-size: 0.72em; color: #666; display: block; }

        .action-btn {
            padding: 6px 10px; border: none; border-radius: 6px;
            font-size: 0.78em; font-weight: 600; cursor: pointer;
            margin: 2px; transition: all 0.2s; color: white;
        }
        .action-btn:hover { transform: translateY(-1px); box-shadow: 0 3px 8px rgba(0,0,0,0.2); }
        .action-btn.ot { background: #28a745; }
        .action-btn.ot-cancel { background: #6c757d; }
        .action-btn.force-out { background: #dc3545; }
        .action-btn:disabled { opacity: 0.4; cursor: not-allowed; transform: none; }

        /* Modal */
        .modal {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.55);
            z-index: 2000; justify-content: center; align-items: center; padding: 20px;
        }
        .modal.show { display: flex; }
        .modal-content {
            background: white; border-radius: 16px; padding: 28px;
            max-width: 440px; width: 100%; animation: modalIn 0.25s ease;
        }
        @keyframes modalIn { from { transform: translateY(-40px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .modal-title { font-size: 1.25em; font-weight: 700; color: #333; margin-bottom: 6px; }
        .modal-sub   { font-size: 0.9em; color: #666; margin-bottom: 18px; }
        .modal label { display: block; font-size: 0.85em; font-weight: 600; color: #444; margin-bottom: 5px; margin-top: 12px; }
        .modal input, .modal textarea {
            width: 100%; padding: 11px 13px; border: 2px solid #e0e0e0;
            border-radius: 9px; font-size: 15px; font-family: inherit;
        }
        .modal input:focus, .modal textarea:focus { outline: none; border-color: #667eea; }
        .modal-buttons { display: flex; gap: 10px; margin-top: 20px; }
        .modal-btn {
            flex: 1; padding: 11px; border: none; border-radius: 9px;
            font-weight: 600; cursor: pointer; font-size: 15px;
        }
        .btn-confirm { background: #667eea; color: white; }
        .btn-confirm:hover { background: #5a67d8; }
        .btn-danger  { background: #dc3545; color: white; }
        .btn-danger:hover { background: #c82333; }
        .btn-cancel  { background: #6c757d; color: white; }
        .btn-cancel:hover { background: #5a6268; }

        /* Toast */
        .toast {
            position: fixed; top: 20px; right: 20px; padding: 14px 22px;
            border-radius: 10px; color: white; font-weight: 600; z-index: 3000;
            display: none; box-shadow: 0 8px 25px rgba(0,0,0,0.3);
            animation: toastIn 0.25s ease; max-width: 380px;
        }
        .toast.success { background: #28a745; }
        .toast.error   { background: #dc3545; }
        .toast.info    { background: #17a2b8; }
        @keyframes toastIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

        @media (max-width: 768px) {
            h1 { font-size: 2em; }
            .btn-refresh { margin-left: 0; }
            .stats-container { grid-template-columns: repeat(3, 1fr); }
            .stat-card h3 { font-size: 1.8em; }
            table { min-width: 900px; }
            th, td { padding: 8px; font-size: 0.8em; }
        }
        @media (max-width: 480px) {
            .stats-container { grid-template-columns: repeat(2, 1fr); }
            .filter-section { flex-direction: column; }
            .filter-input, .filter-btn { width: 100%; min-width: unset; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📋 Attendance & Break Records</h1>

        <div class="header-buttons">
            <a href="admin_dashboard.php" class="btn btn-back">← Back to Dashboard</a>
            <a href="admin_attendance.php" class="btn btn-refresh">🔄 Refresh</a>
            <a href="admin_scanner.php" class="btn btn-scan">📷 Scan QR</a>
            <button class="btn btn-break-report" onclick="exportBreakReport()">📊 Export Break Report</button>
        </div>

        <!-- ================= FORGOT TIMEOUT ALERT ================= -->
        <?php if (!empty($forgotTimeoutList)): ?>
        <div class="alert-banner">
            <h3>⚠️ <?php echo count($forgotTimeoutList); ?> employee(s) may have forgotten to Time Out</h3>
            <ul>
                <?php foreach ($forgotTimeoutList as $f): ?>
                    <li>
                        <strong><?php echo htmlspecialchars($f['first_name'] . ' ' . $f['last_name']); ?></strong>
                        (<?php echo htmlspecialchars($f['emp_id'] ?? $f['employee_id']); ?>)
                        — Time In: <?php echo date('h:i A', strtotime($f['time_in'])); ?>
                        on <?php echo date('M d', strtotime($f['date'])); ?>
                        <span class="time-since">(<?php echo $f['hours_since_in']; ?> hrs ago, still open)</span>
                        <button class="action-btn force-out" style="margin-left:8px;"
                                onclick="openForceTimeout(
                                    <?php echo (int)$f['id']; ?>,
                                    '<?php echo htmlspecialchars($f['first_name'] . ' ' . $f['last_name'], ENT_QUOTES); ?>',
                                    '<?php echo htmlspecialchars($f['time_in'], ENT_QUOTES); ?>'
                                )">⏰ Force Time Out</button>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- ================= STAT CARDS ================= -->
        <div class="stats-container">
            <div class="stat-card"><h3><?php echo $stats['total_employees'] ?? 0; ?></h3><p>Total Employees</p></div>
            <div class="stat-card"><h3><?php echo $stats['present_today'] ?? 0; ?></h3><p>Present Today</p></div>
            <div class="stat-card"><h3><?php echo $stats['still_working'] ?? 0; ?></h3><p>Still Working</p></div>
            <div class="stat-card"><h3><?php echo $totalRecords; ?></h3><p>Total Records</p></div>
            <div class="stat-card break-stat"><h3><?php echo $stats['total_breaks_today'] ?? 0; ?></h3><p>☕ Breaks Today</p></div>
            <div class="stat-card break-stat"><h3><?php echo $stats['on_break_today'] ?? 0; ?></h3><p>🚶 On Break</p></div>
            <div class="stat-card ot-stat"><h3><?php echo $stats['overtime_today'] ?? 0; ?></h3><p>⏱️ OT Approved Today</p></div>
        </div>

        <!-- ================= FILTERS ================= -->
        <div class="filter-section">
            <input type="date" class="filter-input" id="dateFilter" value="<?php echo htmlspecialchars($filter_date); ?>">
            <input type="text" class="filter-input" id="employeeFilter" placeholder="Search by ID or Name..." value="<?php echo htmlspecialchars($filter_employee); ?>">
            <button class="filter-btn" onclick="applyFilters()">🔍 Apply Filters</button>
            <button class="filter-btn clear" onclick="clearFilters()">✖ Clear</button>
        </div>

        <!-- ================= TABLE ================= -->
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Employee ID</th>
                        <th>Name</th>
                        <th>Position</th>
                        <th>Date</th>
                        <th>Time In</th>
                        <th>Time Out</th>
                        <th>Work Hours</th>
                        <th>Break Time</th>
                        <th>Break Details</th>
                        <th>Overtime</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($attendanceRecords)): ?>
                        <tr><td colspan="12" class="no-data">No attendance records found</td></tr>
                    <?php else: ?>
                        <?php foreach ($attendanceRecords as $record): ?>
                            <?php
                            // Work hours
                            $hoursWorked = '-';
                            $totalBreakMinutes = 0;

                            if ($record['time_in']) {
                                $timeIn  = strtotime($record['time_in']);
                                $timeOut = $record['time_out'] ? strtotime($record['time_out']) : time();

                                if (!empty($record['breaks'])) {
                                    foreach ($record['breaks'] as $break) {
                                        if ($break['break_out']) {
                                            $totalBreakMinutes += (strtotime($break['break_out']) - strtotime($break['break_in'])) / 60;
                                        }
                                    }
                                }

                                $totalMinutes = ($timeOut - $timeIn) / 60;
                                $workMinutes  = max($totalMinutes - $totalBreakMinutes, 0);
                                $hours        = round($workMinutes / 60, 2);
                                $hoursWorked  = number_format($hours, 1) . ' hrs' . ($record['time_out'] ? '' : ' (ongoing)');
                            }

                            $breakDisplay = $totalBreakMinutes > 0 ? round($totalBreakMinutes) . ' min' : '-';

                            // Status
                            $status = 'Present';
                            $statusClass = 'status-present';

                            if (!$record['time_in']) {
                                $status = 'Absent';
                                $statusClass = 'status-absent';
                            } elseif ($record['time_in'] && !$record['time_out']) {
                                $onBreak = false;
                                if (!empty($record['breaks'])) {
                                    foreach ($record['breaks'] as $break) {
                                        if ($break['break_status'] === 'on_break') { $onBreak = true; break; }
                                    }
                                }
                                if ($onBreak) {
                                    $status = 'On Break';
                                    $statusClass = 'status-on-break';
                                } elseif ($record['forgot_timeout']) {
                                    $status = 'Forgot to Time Out?';
                                    $statusClass = 'status-forgot';
                                } else {
                                    $status = 'Working';
                                    $statusClass = 'status-working';
                                }
                            } elseif ($record['time_in'] && $record['time_out']) {
                                $status = 'Completed';
                                $statusClass = 'status-completed';
                            }

                            // Break details
                            $breakDetailsHtml = '';
                            if (!empty($record['breaks'])) {
                                foreach ($record['breaks'] as $index => $break) {
                                    $breakNum  = $index + 1;
                                    $breakIn   = date('h:i A', strtotime($break['break_in']));
                                    $breakOut  = $break['break_out'] ? date('h:i A', strtotime($break['break_out'])) : 'On Break';
                                    $duration  = $break['duration'] ?? 0;
                                    $statusText = $break['break_status'] === 'on_break' ? '🔄' : '✅';
                                    $breakItemClass = $break['break_status'] === 'on_break' ? 'break-on' : 'break-completed';

                                    $breakDetailsHtml .= '<span class="break-item ' . $breakItemClass . '">';
                                    $breakDetailsHtml .= 'Break ' . $breakNum . ': ' . $breakIn . ' → ' . $breakOut;
                                    if ($duration > 0) {
                                        $breakDetailsHtml .= ' <span class="break-duration">(' . $duration . ' min)</span>';
                                    }
                                    $breakDetailsHtml .= ' ' . $statusText . '</span>';
                                }
                            } else {
                                $breakDetailsHtml = '<span class="no-breaks">No breaks taken</span>';
                            }

                            // Row class
                            $rowClass = '';
                            if ($record['forgot_timeout']) $rowClass = 'row-forgot-timeout';
                            elseif (!empty($record['overtime'])) $rowClass = 'row-ot';
                            ?>

                            <tr class="<?php echo $rowClass; ?>">
                                <td><strong><?php echo htmlspecialchars($record['emp_id'] ?? $record['employee_id']); ?></strong></td>
                                <td><?php echo htmlspecialchars($record['first_name'] . ' ' . $record['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($record['position'] ?? 'N/A'); ?></td>
                                <td><?php echo date('M d, Y', strtotime($record['date'])); ?></td>
                                <td class="time-display"><?php echo $record['time_in'] ? date('h:i A', strtotime($record['time_in'])) : '-'; ?></td>
                                <td class="time-display">
                                    <?php echo $record['time_out']
                                        ? date('h:i A', strtotime($record['time_out']))
                                        : ($record['forgot_timeout']
                                            ? '<span style="color:#dc3545;font-weight:700;">MISSING</span>'
                                            : '-'); ?>
                                </td>
                                <td class="hours-worked"><?php echo $hoursWorked; ?></td>
                                <td class="break-minutes"><?php echo $breakDisplay; ?></td>
                                <td class="break-details"><?php echo $breakDetailsHtml; ?></td>

                                <!-- OVERTIME CELL -->
                                <td>
                                    <?php if (!empty($record['overtime'])): ?>
                                        <span class="ot-badge">⏱️ OT APPROVED</span>
                                        <div class="ot-hours"><?php echo number_format($record['overtime']['overtime_hours'], 2); ?> hrs</div>
                                        <span class="ot-approved-by">by <?php echo htmlspecialchars($record['overtime']['approved_by']); ?></span>
                                    <?php else: ?>
                                        <span style="color:#999;">—</span>
                                    <?php endif; ?>
                                </td>

                                <td><span class="status-badge <?php echo $statusClass; ?>"><?php echo $status; ?></span></td>

                                <!-- ACTIONS CELL -->
                                <td>
                                    <!-- Force Time Out -->
                                    <?php if (!empty($record['time_in']) && empty($record['time_out'])): ?>
                                        <button class="action-btn force-out"
                                                onclick="openForceTimeout(
                                                    <?php echo (int)$record['id']; ?>,
                                                    '<?php echo htmlspecialchars($record['first_name'] . ' ' . $record['last_name'], ENT_QUOTES); ?>',
                                                    '<?php echo htmlspecialchars($record['time_in'], ENT_QUOTES); ?>'
                                                )">⏰ Force Out</button>
                                    <?php endif; ?>

                                    <!-- Overtime approve / cancel -->
                                    <?php if (empty($record['overtime'])): ?>
                                        <button class="action-btn ot"
                                                onclick="openOvertimeModal(
                                                    '<?php echo htmlspecialchars($record['employee_id'], ENT_QUOTES); ?>',
                                                    '<?php echo htmlspecialchars($record['first_name'] . ' ' . $record['last_name'], ENT_QUOTES); ?>',
                                                    '<?php echo htmlspecialchars($record['date'], ENT_QUOTES); ?>'
                                                )">✅ Approve OT</button>
                                    <?php else: ?>
                                        <button class="action-btn ot"
                                                onclick="openOvertimeModal(
                                                    '<?php echo htmlspecialchars($record['employee_id'], ENT_QUOTES); ?>',
                                                    '<?php echo htmlspecialchars($record['first_name'] . ' ' . $record['last_name'], ENT_QUOTES); ?>',
                                                    '<?php echo htmlspecialchars($record['date'], ENT_QUOTES); ?>',
                                                    <?php echo (float)$record['overtime']['overtime_hours']; ?>
                                                )">✏️ Edit OT</button>
                                        <button class="action-btn ot-cancel"
                                                onclick="cancelOvertime(
                                                    '<?php echo htmlspecialchars($record['employee_id'], ENT_QUOTES); ?>',
                                                    '<?php echo htmlspecialchars($record['date'], ENT_QUOTES); ?>'
                                                )">❌ Cancel OT</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ================= OVERTIME MODAL ================= -->
    <div class="modal" id="otModal">
        <div class="modal-content">
            <div class="modal-title">⏱️ Approve Overtime</div>
            <div class="modal-sub" id="otModalSub">—</div>

            <input type="hidden" id="ot_employee_id">
            <input type="hidden" id="ot_date">

            <label for="ot_hours">Overtime Hours</label>
            <input type="number" id="ot_hours" min="0.25" step="0.25" placeholder="e.g., 2.5">

            <label for="ot_notes">Notes (optional)</label>
            <textarea id="ot_notes" rows="2" placeholder="Reason / project / remarks"></textarea>

            <div class="modal-buttons">
                <button class="modal-btn btn-confirm" onclick="submitOvertime()">Approve Overtime</button>
                <button class="modal-btn btn-cancel" onclick="closeModal('otModal')">Cancel</button>
            </div>
        </div>
    </div>

    <!-- ================= FORCE TIMEOUT MODAL ================= -->
    <div class="modal" id="forceModal">
        <div class="modal-content">
            <div class="modal-title">⏰ Force Time Out</div>
            <div class="modal-sub" id="forceModalSub">—</div>

            <input type="hidden" id="force_attendance_id">

            <label for="force_time">Time Out (HH:MM, 24h)</label>
            <input type="time" id="force_time">

            <label for="force_reason">Reason</label>
            <textarea id="force_reason" rows="2" placeholder="e.g., Employee forgot to scan out">Employee forgot to scan out</textarea>

            <div class="modal-buttons">
                <button class="modal-btn btn-danger" onclick="submitForceTimeout()">Confirm Force Time Out</button>
                <button class="modal-btn btn-cancel" onclick="closeModal('forceModal')">Cancel</button>
            </div>
        </div>
    </div>

    <div id="toast" class="toast"></div>

    <script>
        // ---------- Helpers ----------
        function showToast(msg, type = 'info') {
            const t = document.getElementById('toast');
            t.textContent = msg;
            t.className = 'toast ' + type;
            t.style.display = 'block';
            setTimeout(() => t.style.display = 'none', 4000);
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('show');
        }

        function applyFilters() {
            const date = document.getElementById('dateFilter').value;
            const employee = document.getElementById('employeeFilter').value;
            let url = 'admin_attendance.php?';
            if (date) url += 'date=' + encodeURIComponent(date) + '&';
            if (employee) url += 'employee=' + encodeURIComponent(employee);
            window.location.href = url;
        }

        function clearFilters() {
            window.location.href = 'admin_attendance.php';
        }

        function exportBreakReport() {
            const date = document.getElementById('dateFilter').value || '<?php echo date('Y-m-d'); ?>';
            window.location.href = 'export_break_report.php?date=' + encodeURIComponent(date);
        }

        // ---------- Overtime modal ----------
        function openOvertimeModal(employeeId, employeeName, date, existingHours) {
            document.getElementById('ot_employee_id').value = employeeId;
            document.getElementById('ot_date').value = date;
            document.getElementById('ot_hours').value = existingHours || '';
            document.getElementById('ot_notes').value = '';
            document.getElementById('otModalSub').textContent =
                employeeName + ' — ' + date + (existingHours ? ' (editing existing OT)' : '');
            document.getElementById('otModal').classList.add('show');
            setTimeout(() => document.getElementById('ot_hours').focus(), 100);
        }

        function submitOvertime() {
            const employeeId = document.getElementById('ot_employee_id').value;
            const date       = document.getElementById('ot_date').value;
            const hours      = parseFloat(document.getElementById('ot_hours').value);
            const notes      = document.getElementById('ot_notes').value;

            if (!hours || hours <= 0) {
                showToast('Please enter positive overtime hours.', 'error');
                return;
            }

            const fd = new URLSearchParams();
            fd.append('action', 'approve_overtime');
            fd.append('employee_id', employeeId);
            fd.append('date', date);
            fd.append('overtime_hours', hours);
            fd.append('notes', notes);

            fetch('admin_attendance.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: fd.toString()
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    closeModal('otModal');
                    setTimeout(() => location.reload(), 900);
                } else {
                    showToast(data.message || 'Failed to approve OT.', 'error');
                }
            })
            .catch(err => showToast('Network error: ' + err.message, 'error'));
        }

        function cancelOvertime(employeeId, date) {
            if (!confirm('Cancel overtime approval for this employee on ' + date + '?')) return;

            const fd = new URLSearchParams();
            fd.append('action', 'cancel_overtime');
            fd.append('employee_id', employeeId);
            fd.append('date', date);

            fetch('admin_attendance.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: fd.toString()
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    setTimeout(() => location.reload(), 900);
                } else {
                    showToast(data.message || 'Failed to cancel OT.', 'error');
                }
            })
            .catch(err => showToast('Network error: ' + err.message, 'error'));
        }

        // ---------- Force timeout modal ----------
        function openForceTimeout(attendanceId, employeeName, timeIn) {
            document.getElementById('force_attendance_id').value = attendanceId;
            document.getElementById('forceModalSub').textContent =
                employeeName + ' (Time In: ' + formatTime12h(timeIn) + ')';

            // Suggest a time = now
            const now = new Date();
            const hh = String(now.getHours()).padStart(2, '0');
            const mm = String(now.getMinutes()).padStart(2, '0');
            document.getElementById('force_time').value = hh + ':' + mm;
            document.getElementById('force_reason').value = 'Employee forgot to scan out';

            document.getElementById('forceModal').classList.add('show');
        }

        function formatTime12h(t) {
            if (!t) return '';
            const parts = t.split(':');
            let h = parseInt(parts[0], 10);
            const m = parts[1];
            const ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12 || 12;
            return h + ':' + m + ' ' + ampm;
        }

        function submitForceTimeout() {
            const attendanceId = document.getElementById('force_attendance_id').value;
            const forcedTime   = document.getElementById('force_time').value;
            const reason       = document.getElementById('force_reason').value;

            if (!forcedTime) {
                showToast('Please choose a time out.', 'error');
                return;
            }

            if (!confirm('This will close the open attendance row. Continue?')) return;

            const fd = new URLSearchParams();
            fd.append('action', 'force_timeout');
            fd.append('attendance_id', attendanceId);
            fd.append('forced_time', forcedTime);
            fd.append('reason', reason);

            fetch('admin_attendance.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: fd.toString()
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    closeModal('forceModal');
                    setTimeout(() => location.reload(), 900);
                } else {
                    showToast(data.message || 'Failed to force time out.', 'error');
                }
            })
            .catch(err => showToast('Network error: ' + err.message, 'error'));
        }

        // Auto refresh every 60s (pause if a modal is open)
        setInterval(function () {
            const anyOpen = document.querySelector('.modal.show');
            if (!anyOpen) location.reload();
        }, 60000);

        // Ctrl+F focuses employee search
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                document.getElementById('employeeFilter').focus();
            }
        });

        document.getElementById('employeeFilter').addEventListener('keypress', function (e) {
            if (e.key === 'Enter') applyFilters();
        });
        document.getElementById('dateFilter').addEventListener('keypress', function (e) {
            if (e.key === 'Enter') applyFilters();
        });

        // Close modal on backdrop click
        document.querySelectorAll('.modal').forEach(m => {
            m.addEventListener('click', function (e) {
                if (e.target === this) this.classList.remove('show');
            });
        });
    </script>
</body>
</html>