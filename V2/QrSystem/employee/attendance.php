<?php
session_start();
require_once 'config.php';

// Get filter parameters
$filter_date = $_GET['date'] ?? date('Y-m-d');
$filter_employee = $_GET['employee'] ?? '';

// Build query for attendance with break information
// FIXED: Replaced LEFT JOIN with subqueries to prevent duplicate rows while still getting schedule data
$sql = "SELECT 
            a.*, 
            e.first_name, 
            e.last_name, 
            e.position,
            e.employee_id as emp_id,
            (SELECT ws.hours_worked FROM employee_work_schedules ws WHERE ws.employee_id = a.employee_id AND ws.work_date = a.date LIMIT 1) as hours_worked,
            (SELECT ws.break_minutes FROM employee_work_schedules ws WHERE ws.employee_id = a.employee_id AND ws.work_date = a.date LIMIT 1) as break_minutes,
            (SELECT COUNT(*) FROM employee_breaks WHERE employee_id = a.employee_id AND date = a.date) as break_count
        FROM attendance a 
        JOIN employees e ON a.employee_id = e.employee_id 
        WHERE 1=1";

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

// FIXED: Changed to ASC so the first person who timed in appears at the top
$sql .= " ORDER BY a.date DESC, a.time_in ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$attendanceRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get break details separately for each record
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
}

// FIXED: Unset the reference to prevent the last row from duplicating in the HTML loop
unset($record);

// Get statistics including break stats
$statsSql = "SELECT 
                COUNT(DISTINCT a.employee_id) as total_employees,
                SUM(CASE WHEN a.date = CURDATE() AND a.time_in IS NOT NULL THEN 1 ELSE 0 END) as present_today,
                SUM(CASE WHEN a.date = CURDATE() AND a.time_in IS NOT NULL AND a.time_out IS NULL THEN 1 ELSE 0 END) as still_working,
                (SELECT COUNT(*) FROM employee_breaks WHERE date = CURDATE()) as total_breaks_today,
                (SELECT COUNT(*) FROM employee_breaks WHERE date = CURDATE() AND break_out IS NULL) as on_break_today
             FROM attendance a";
$stats = $pdo->query($statsSql)->fetch(PDO::FETCH_ASSOC);

// Get total attendance records count
$countSql = "SELECT COUNT(*) as total_records FROM attendance";
$countResult = $pdo->query($countSql)->fetch(PDO::FETCH_ASSOC);
$totalRecords = $countResult['total_records'] ?? 0;
?>

<!DOCTYPE html>
<html>
<head>
    <title>Attendance Records - QR Attendance System</title>
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
            margin-bottom: 30px;
            font-size: 2.5em;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }

        .header-buttons {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
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

        .btn-scan {
            background: #28a745;
            color: white;
        }

        .btn-scan:hover {
            background: #218838;
            transform: translateY(-2px);
        }

        .btn-refresh {
            background: #17a2b8;
            color: white;
            margin-left: auto;
        }

        .btn-refresh:hover {
            background: #138496;
            transform: translateY(-2px);
        }

        .btn-break-report {
            background: #fd7e14;
            color: white;
        }

        .btn-break-report:hover {
            background: #e06b0f;
            transform: translateY(-2px);
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 18px 15px;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .stat-card h3 {
            font-size: 2.2em;
            color: #667eea;
            margin-bottom: 3px;
        }

        .stat-card p {
            color: #666;
            font-size: 0.9em;
            font-weight: 500;
        }

        .stat-card.break-stat h3 {
            color: #fd7e14;
        }

        .filter-section {
            background: white;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 30px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }

        .filter-input {
            flex: 1;
            min-width: 180px;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
        }

        .filter-input:focus {
            outline: none;
            border-color: #667eea;
        }

        .filter-btn {
            background: #667eea;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
        }

        .filter-btn:hover {
            background: #5a67d8;
        }

        .filter-btn.clear {
            background: #6c757d;
        }

        .filter-btn.clear:hover {
            background: #5a6268;
        }

        .table-container {
            background: white;
            border-radius: 15px;
            overflow-x: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        th {
            background: #667eea;
            color: white;
            padding: 12px 12px;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.8em;
            position: sticky;
            top: 0;
            z-index: 10;
            white-space: nowrap;
        }

        td {
            padding: 10px 12px;
            border-bottom: 1px solid #e0e0e0;
            text-align: center;
            vertical-align: middle;
            font-size: 0.9em;
        }

        tr:hover {
            background: #f8f9fa;
        }

        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75em;
            font-weight: 600;
            display: inline-block;
            white-space: nowrap;
        }

        .status-present {
            background: #d4edda;
            color: #155724;
        }

        .status-working {
            background: #fff3cd;
            color: #856404;
        }

        .status-completed {
            background: #cce5ff;
            color: #004085;
        }

        .status-on-break {
            background: #f8d7da;
            color: #721c24;
        }

        .status-absent {
            background: #e2e3e5;
            color: #383d41;
        }

        .no-data {
            text-align: center;
            padding: 50px;
            color: #999;
            font-size: 1.2em;
        }

        .time-display {
            font-weight: 600;
            color: #333;
        }

        .break-details {
            font-size: 0.8em;
            color: #6f42c1;
            text-align: left;
        }

        .break-item {
            display: block;
            padding: 2px 0;
            border-bottom: 1px dashed #e0e0e0;
        }

        .break-item:last-child {
            border-bottom: none;
        }

        .break-on {
            color: #fd7e14;
            font-weight: 600;
        }

        .break-completed {
            color: #6f42c1;
        }

        .break-duration {
            font-size: 0.85em;
            color: #666;
        }

        .hours-worked {
            color: #28a745;
            font-weight: 600;
        }

        .break-minutes {
            color: #fd7e14;
            font-weight: 600;
        }

        .no-breaks {
            color: #999;
            font-size: 0.8em;
        }

        .legend {
            margin-top: 20px;
            background: white;
            padding: 15px 20px;
            border-radius: 10px;
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            align-items: center;
        }

        .legend-label {
            font-weight: 600;
            color: #333;
        }

        @media (max-width: 768px) {
            h1 {
                font-size: 2em;
            }
            
            .btn-refresh {
                margin-left: 0;
            }

            .stats-container {
                grid-template-columns: repeat(3, 1fr);
            }

            .stat-card h3 {
                font-size: 1.8em;
            }

            table {
                min-width: 700px;
            }

            th, td {
                padding: 8px 10px;
                font-size: 0.8em;
            }
        }

        @media (max-width: 480px) {
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }

            .filter-section {
                flex-direction: column;
            }

            .filter-input {
                width: 100%;
                min-width: unset;
            }

            .filter-btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📋 Attendance & Break Records</h1>

        <div class="header-buttons">
            <a href="dashboard.php" class="btn btn-back">← Back to Dashboard</a>
            <a href="attendance.php" class="btn btn-refresh">🔄 Refresh</a>
            <a href="scanner.php" class="btn btn-scan">📷 Scan QR</a>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-container">
            <div class="stat-card">
                <h3><?php echo $stats['total_employees'] ?? 0; ?></h3>
                <p>Total Employees</p>
            </div>
            <div class="stat-card">
                <h3><?php echo $stats['present_today'] ?? 0; ?></h3>
                <p>Present Today</p>
            </div>
            <div class="stat-card">
                <h3><?php echo $stats['still_working'] ?? 0; ?></h3>
                <p>Still Working</p>
            </div>
            <div class="stat-card">
                <h3><?php echo $totalRecords; ?></h3>
                <p>Total Records</p>
            </div>
            <div class="stat-card break-stat">
                <h3><?php echo $stats['total_breaks_today'] ?? 0; ?></h3>
                <p>☕ Total Breaks Today</p>
            </div>
            <div class="stat-card break-stat">
                <h3><?php echo $stats['on_break_today'] ?? 0; ?></h3>
                <p>🚶 Currently On Break</p>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <input type="date" class="filter-input" id="dateFilter" value="<?php echo $filter_date; ?>">
            <input type="text" class="filter-input" id="employeeFilter" placeholder="Search by ID or Name..." value="<?php echo htmlspecialchars($filter_employee); ?>">
            <button class="filter-btn" onclick="applyFilters()">🔍 Apply Filters</button>
            <button class="filter-btn clear" onclick="clearFilters()">✖ Clear</button>
        </div>

        <!-- Attendance Table -->
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
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($attendanceRecords)): ?>
                        <tr>
                            <td colspan="10" class="no-data">No attendance records found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($attendanceRecords as $record): ?>
                            <?php
                            // ---------- FIX: Calculate actual work hours from time_in/time_out and subtract breaks ----------
                            $hoursWorked = '-';
                            $totalBreakMinutes = 0;
                            
                            if ($record['time_in']) {
                                $timeIn = strtotime($record['time_in']);
                                // Use time_out if exists, otherwise current time
                                $timeOut = $record['time_out'] ? strtotime($record['time_out']) : time();
                                
                                // Calculate total break minutes from the breaks array
                                if (!empty($record['breaks'])) {
                                    foreach ($record['breaks'] as $break) {
                                        if ($break['break_out']) {
                                            $breakIn = strtotime($break['break_in']);
                                            $breakOut = strtotime($break['break_out']);
                                            $totalBreakMinutes += ($breakOut - $breakIn) / 60;
                                        }
                                        // Active break (break_out IS NULL) – we do not subtract it yet
                                    }
                                }
                                
                                $totalMinutes = ($timeOut - $timeIn) / 60;
                                $workMinutes = max($totalMinutes - $totalBreakMinutes, 0);
                                $hours = round($workMinutes / 60, 2);
                                
                                if ($record['time_out']) {
                                    $hoursWorked = number_format($hours, 1) . ' hrs';
                                } else {
                                    $hoursWorked = number_format($hours, 1) . ' hrs (ongoing)';
                                }
                            }
                            
                            // Break minutes display
                            $breakDisplay = $totalBreakMinutes > 0 ? round($totalBreakMinutes) . ' min' : '-';
                            
                            // Determine status
                            $status = 'Present';
                            $statusClass = 'status-present';
                            
                            if (!$record['time_in']) {
                                $status = 'Absent';
                                $statusClass = 'status-absent';
                            } elseif ($record['time_in'] && !$record['time_out']) {
                                // Check if there's an active break
                                $onBreak = false;
                                if (!empty($record['breaks'])) {
                                    foreach ($record['breaks'] as $break) {
                                        if ($break['break_status'] == 'on_break') {
                                            $onBreak = true;
                                            break;
                                        }
                                    }
                                }
                                if ($onBreak) {
                                    $status = 'On Break';
                                    $statusClass = 'status-on-break';
                                } else {
                                    $status = 'Working';
                                    $statusClass = 'status-working';
                                }
                            } elseif ($record['time_in'] && $record['time_out']) {
                                $status = 'Completed';
                                $statusClass = 'status-completed';
                            }
                            
                            // Build break details HTML
                            $breakDetailsHtml = '';
                            if (!empty($record['breaks'])) {
                                foreach ($record['breaks'] as $index => $break) {
                                    $breakNum = $index + 1;
                                    $breakIn = date('h:i A', strtotime($break['break_in']));
                                    $breakOut = $break['break_out'] ? date('h:i A', strtotime($break['break_out'])) : 'On Break';
                                    $duration = $break['duration'] ?? 0;
                                    $statusText = $break['break_status'] == 'on_break' ? '🔄' : '✅';
                                    
                                    // FIXED: Renamed to $breakItemClass so it doesn't overwrite the row's $statusClass
                                    $breakItemClass = $break['break_status'] == 'on_break' ? 'break-on' : 'break-completed';
                                    
                                    $breakDetailsHtml .= '<span class="break-item ' . $breakItemClass . '">';
                                    $breakDetailsHtml .= 'Break ' . $breakNum . ': ' . $breakIn . ' → ' . $breakOut;
                                    if ($duration > 0) {
                                        $breakDetailsHtml .= ' <span class="break-duration">(' . $duration . ' min)</span>';
                                    }
                                    $breakDetailsHtml .= ' ' . $statusText;
                                    $breakDetailsHtml .= '</span>';
                                }
                            } else {
                                $breakDetailsHtml = '<span class="no-breaks">No breaks taken</span>';
                            }
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($record['emp_id'] ?? $record['employee_id']); ?></strong></td>
                                <td><?php echo htmlspecialchars($record['first_name'] . ' ' . $record['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($record['position'] ?? 'N/A'); ?></td>
                                <td><?php echo date('M d, Y', strtotime($record['date'])); ?></td>
                                <td class="time-display"><?php echo $record['time_in'] ? date('h:i A', strtotime($record['time_in'])) : '-'; ?></td>
                                <td class="time-display"><?php echo $record['time_out'] ? date('h:i A', strtotime($record['time_out'])) : '-'; ?></td>
                                <td class="hours-worked"><?php echo $hoursWorked; ?></td>
                                <td class="break-minutes"><?php echo $breakDisplay; ?></td>
                                <td class="break-details">
                                    <?php echo $breakDetailsHtml; ?>
                                </td>
                                <td><span class="status-badge <?php echo $statusClass; ?>"><?php echo $status; ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        function applyFilters() {
            const date = document.getElementById('dateFilter').value;
            const employee = document.getElementById('employeeFilter').value;
            
            let url = 'attendance.php?';
            if (date) url += 'date=' + encodeURIComponent(date) + '&';
            if (employee) url += 'employee=' + encodeURIComponent(employee);
            
            window.location.href = url;
        }

        function clearFilters() {
            window.location.href = 'attendance.php';
        }

        function exportBreakReport() {
            const date = document.getElementById('dateFilter').value || '<?php echo date('Y-m-d'); ?>';
            window.location.href = 'employee_break_report.php?date=' + encodeURIComponent(date);
        }

        // Auto-refresh every 60 seconds
        setTimeout(function() {
            location.reload();
        }, 60000);

        // Add keyboard shortcut for search (Ctrl+F)
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                document.getElementById('employeeFilter').focus();
            }
        });

        // Enter key on filter inputs triggers search
        document.getElementById('employeeFilter').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                applyFilters();
            }
        });

        document.getElementById('dateFilter').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                applyFilters();
            }
        });
    </script>
</body>
</html>