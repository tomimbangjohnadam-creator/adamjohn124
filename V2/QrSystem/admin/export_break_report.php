
<?php
require_once 'admin_auth.php'; 
require_once 'configa.php';

// Get filter parameters
$filter_date = $_GET['date'] ?? date('Y-m-d');
$filter_employee = $_GET['employee'] ?? '';
$format = $_GET['format'] ?? 'html'; // html or csv

// Build query for attendance with break information
$sql = "SELECT 
            a.*, 
            e.first_name, 
            e.last_name, 
            e.position,
            e.employee_id as emp_id,
            ws.hours_worked,
            ws.break_minutes
        FROM attendance a 
        JOIN employees e ON a.employee_id = e.employee_id 
        LEFT JOIN employee_work_schedules ws ON a.employee_id = ws.employee_id AND a.date = ws.work_date
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

$sql .= " ORDER BY a.date DESC, a.time_in DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$attendanceRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get break details separately for each record
$breakData = [];
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

// Get statistics including break stats
$statsSql = "SELECT 
                COUNT(DISTINCT a.employee_id) as total_employees,
                SUM(CASE WHEN a.date = CURDATE() AND a.time_in IS NOT NULL THEN 1 ELSE 0 END) as present_today,
                SUM(CASE WHEN a.date = CURDATE() AND a.time_in IS NOT NULL AND a.time_out IS NULL THEN 1 ELSE 0 END) as still_working,
                (SELECT COUNT(*) FROM employee_breaks WHERE date = CURDATE()) as total_breaks_today,
                (SELECT COUNT(*) FROM employee_breaks WHERE date = CURDATE() AND break_out IS NULL) as on_break_today
             FROM attendance a";
$stats = $pdo->query($statsSql)->fetch(PDO::FETCH_ASSOC);

// Calculate summary statistics for the report
$totalEmployees = count($attendanceRecords);
$totalBreaks = 0;
$totalBreakMinutes = 0;
$employeesOnBreak = 0;
$employeesWithBreaks = 0;

foreach ($attendanceRecords as $record) {
    if (!empty($record['breaks'])) {
        $employeesWithBreaks++;
        foreach ($record['breaks'] as $break) {
            $totalBreaks++;
            if ($break['break_status'] == 'on_break') {
                $employeesOnBreak++;
            }
            $totalBreakMinutes += $break['duration'] ?? 0;
        }
    }
}

// If CSV format requested
if ($format == 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="break_report_' . $filter_date . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Employee ID', 'Name', 'Position', 'Date', 'Time In', 'Time Out', 'Break #', 'Break In', 'Break Out', 'Duration (min)', 'Status']);
    
    foreach ($attendanceRecords as $record) {
        $name = $record['first_name'] . ' ' . $record['last_name'];
        $date = date('Y-m-d', strtotime($record['date']));
        $timeIn = $record['time_in'] ? date('H:i:s', strtotime($record['time_in'])) : '';
        $timeOut = $record['time_out'] ? date('H:i:s', strtotime($record['time_out'])) : '';
        
        if (!empty($record['breaks'])) {
            foreach ($record['breaks'] as $index => $break) {
                fputcsv($output, [
                    $record['emp_id'] ?? $record['employee_id'],
                    $name,
                    $record['position'] ?? 'N/A',
                    $date,
                    $timeIn,
                    $timeOut,
                    $index + 1,
                    date('H:i:s', strtotime($break['break_in'])),
                    $break['break_out'] ? date('H:i:s', strtotime($break['break_out'])) : 'On Break',
                    $break['duration'] ?? 0,
                    $break['break_status']
                ]);
            }
        } else {
            fputcsv($output, [
                $record['emp_id'] ?? $record['employee_id'],
                $name,
                $record['position'] ?? 'N/A',
                $date,
                $timeIn,
                $timeOut,
                '0',
                'No Breaks',
                'No Breaks',
                '0',
                'No Breaks'
            ]);
        }
    }
    
    fclose($output);
    exit;
}

// HTML Report
?>
<!DOCTYPE html>
<html>
<head>
    <title>Break Report - <?php echo date('F d, Y', strtotime($filter_date)); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }

        .report-container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .report-header {
            text-align: center;
            padding-bottom: 25px;
            border-bottom: 3px solid #667eea;
            margin-bottom: 25px;
        }

        .report-header h1 {
            color: #333;
            font-size: 2.2em;
            margin-bottom: 5px;
        }

        .report-header h2 {
            color: #667eea;
            font-size: 1.2em;
            font-weight: 400;
        }

        .report-header .report-date {
            color: #666;
            font-size: 1em;
            margin-top: 5px;
        }

        .header-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }

        .btn-back {
            background: #667eea;
            color: white;
        }

        .btn-back:hover {
            background: #5a67d8;
            transform: translateY(-2px);
        }

        .btn-csv {
            background: #28a745;
            color: white;
        }

        .btn-csv:hover {
            background: #218838;
            transform: translateY(-2px);
        }

        .btn-print {
            background: #17a2b8;
            color: white;
        }

        .btn-print:hover {
            background: #138496;
            transform: translateY(-2px);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            border-left: 4px solid #667eea;
        }

        .stat-box.break-stat {
            border-left-color: #fd7e14;
        }

        .stat-box .number {
            font-size: 2em;
            font-weight: 700;
            color: #333;
        }

        .stat-box .label {
            font-size: 0.85em;
            color: #666;
            margin-top: 2px;
        }

        .stat-box .number.break-number {
            color: #fd7e14;
        }

        .filter-info {
            background: #e8ecf1;
            padding: 12px 18px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            font-size: 0.95em;
        }

        .filter-info span {
            color: #555;
        }

        .filter-info strong {
            color: #333;
        }

        .table-container {
            overflow-x: auto;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }

        th {
            background: #667eea;
            color: white;
            padding: 12px 10px;
            font-weight: 600;
            font-size: 0.8em;
            text-transform: uppercase;
            position: sticky;
            top: 0;
            z-index: 10;
            text-align: center;
        }

        td {
            padding: 10px 10px;
            border-bottom: 1px solid #e0e0e0;
            text-align: center;
            font-size: 0.9em;
            vertical-align: middle;
        }

        tr:nth-child(even) {
            background: #f8f9fa;
        }

        tr:hover {
            background: #e8ecf1;
        }

        .break-details {
            text-align: left;
            font-size: 0.8em;
        }

        .break-item {
            display: block;
            padding: 3px 0;
            border-bottom: 1px dashed #e0e0e0;
        }

        .break-item:last-child {
            border-bottom: none;
        }

        .break-on {
            color: #dc3545;
            font-weight: 600;
        }

        .break-completed {
            color: #28a745;
        }

        .break-duration {
            font-size: 0.85em;
            color: #666;
            font-weight: 500;
        }

        .no-breaks {
            color: #999;
            font-size: 0.85em;
        }

        .status-badge {
            padding: 3px 10px;
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

        .time-display {
            font-weight: 500;
            color: #333;
        }

        .hours-worked {
            color: #28a745;
            font-weight: 600;
        }

        .break-minutes {
            color: #fd7e14;
            font-weight: 600;
        }

        .report-footer {
            margin-top: 25px;
            padding-top: 20px;
            border-top: 2px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            font-size: 0.9em;
            color: #666;
        }

        .report-footer .summary {
            font-weight: 500;
        }

        .report-footer .summary strong {
            color: #333;
        }

        .no-data {
            text-align: center;
            padding: 50px;
            color: #999;
            font-size: 1.2em;
        }

        @media print {
            body {
                background: white;
                padding: 10px;
            }
            
            .report-container {
                box-shadow: none;
                padding: 15px;
            }
            
            .header-buttons {
                display: none;
            }
            
            .filter-info {
                background: #f0f0f0;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .stat-box {
                background: #f0f0f0;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            th {
                background: #667eea !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .status-badge {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }

        @media (max-width: 768px) {
            .report-container {
                padding: 15px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
            
            .stat-box .number {
                font-size: 1.5em;
            }
            
            table {
                min-width: 700px;
            }
            
            th, td {
                padding: 6px 8px;
                font-size: 0.8em;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .report-header h1 {
                font-size: 1.5em;
            }
        }
    </style>
</head>
<body>
    <div class="report-container">
        <!-- Header -->
        <div class="report-header">
            <h1>📊 Break Report</h1>
            <h2>Attendance & Break Analysis</h2>
            <div class="report-date">
                Report Date: <?php echo date('F d, Y'); ?> | 
                Period: <?php echo date('F d, Y', strtotime($filter_date)); ?>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="header-buttons">
            <a href="admin_attendance.php" class="btn btn-back">← Back to Attendance</a>
            <button class="btn btn-csv" onclick="exportCSV()">📥 Download CSV</button>
            <button class="btn btn-print" onclick="window.print()">🖨️ Print Report</button>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-box">
                <div class="number"><?php echo $totalEmployees; ?></div>
                <div class="label">Total Records</div>
            </div>
            <div class="stat-box">
                <div class="number"><?php echo $employeesWithBreaks; ?></div>
                <div class="label">Employees with Breaks</div>
            </div>
            <div class="stat-box break-stat">
                <div class="number break-number"><?php echo $totalBreaks; ?></div>
                <div class="label">Total Breaks Taken</div>
            </div>
            <div class="stat-box break-stat">
                <div class="number break-number"><?php echo $totalBreakMinutes; ?></div>
                <div class="label">Total Break Minutes</div>
            </div>
            <div class="stat-box break-stat">
                <div class="number break-number"><?php echo $employeesOnBreak; ?></div>
                <div class="label">Currently On Break</div>
            </div>
            <div class="stat-box">
                <div class="number"><?php echo $stats['present_today'] ?? 0; ?></div>
                <div class="label">Present Today</div>
            </div>
        </div>

        <!-- Filter Info -->
        <div class="filter-info">
            <span><strong>Date:</strong> <?php echo date('F d, Y', strtotime($filter_date)); ?></span>
            <?php if ($filter_employee): ?>
                <span><strong>Employee Search:</strong> <?php echo htmlspecialchars($filter_employee); ?></span>
            <?php endif; ?>
            <span><strong>Generated:</strong> <?php echo date('h:i A'); ?></span>
        </div>

        <!-- Table -->
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
                        <th>Total Break</th>
                        <th>Break Details</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($attendanceRecords)): ?>
                        <tr>
                            <td colspan="10" class="no-data">No records found for the selected criteria</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($attendanceRecords as $record): ?>
                            <?php
                            // Calculate hours worked
                            $hoursWorked = '-';
                            if ($record['time_in'] && $record['time_out']) {
                                $timeIn = strtotime($record['time_in']);
                                $timeOut = strtotime($record['time_out']);
                                $hours = round(($timeOut - $timeIn) / 3600, 2);
                                $hoursWorked = number_format($hours, 1) . ' hrs';
                            } elseif ($record['time_in']) {
                                $hoursWorked = 'In progress';
                            }
                            
                            if (!empty($record['hours_worked']) && $record['hours_worked'] > 0) {
                                $hoursWorked = number_format($record['hours_worked'], 1) . ' hrs';
                            }
                            
                            // Break minutes
                            $breakMinutes = $record['break_minutes'] ?? 0;
                            $breakDisplay = $breakMinutes > 0 ? $breakMinutes . ' min' : '-';
                            
                            // Determine status
                            $status = 'Present';
                            $statusClass = 'status-present';
                            
                            if (!$record['time_in']) {
                                $status = 'Absent';
                                $statusClass = 'status-absent';
                            } elseif ($record['time_in'] && !$record['time_out']) {
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
                            
                            // Build break details
                            $breakDetailsHtml = '';
                            if (!empty($record['breaks'])) {
                                foreach ($record['breaks'] as $index => $break) {
                                    $breakNum = $index + 1;
                                    $breakIn = date('h:i A', strtotime($break['break_in']));
                                    $breakOut = $break['break_out'] ? date('h:i A', strtotime($break['break_out'])) : 'On Break';
                                    $duration = $break['duration'] ?? 0;
                                    $statusText = $break['break_status'] == 'on_break' ? '🔄' : '✅';
                                    $statusClassBreak = $break['break_status'] == 'on_break' ? 'break-on' : 'break-completed';
                                    
                                    $breakDetailsHtml .= '<span class="break-item ' . $statusClassBreak . '">';
                                    $breakDetailsHtml .= '#' . $breakNum . ': ' . $breakIn . ' → ' . $breakOut;
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
                                <td class="break-details"><?php echo $breakDetailsHtml; ?></td>
                                <td><span class="status-badge <?php echo $statusClass; ?>"><?php echo $status; ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Footer -->
        <div class="report-footer">
            <div class="summary">
                <strong>Report Summary:</strong> 
                <?php echo $totalEmployees; ?> records, 
                <?php echo $employeesWithBreaks; ?> employees took breaks, 
                <?php echo $totalBreaks; ?> total breaks, 
                <?php echo $totalBreakMinutes; ?> minutes total break time
            </div>
            <div>
                Generated by QR Attendance System v1.0
            </div>
        </div>
    </div>

    <script>
        function exportCSV() {
            const date = '<?php echo $filter_date; ?>';
            const employee = '<?php echo htmlspecialchars($filter_employee); ?>';
            let url = 'export_break_report.php?format=csv&date=' + encodeURIComponent(date);
            if (employee) {
                url += '&employee=' + encodeURIComponent(employee);
            }
            window.location.href = url;
        }
    </script>
</body>
</html>