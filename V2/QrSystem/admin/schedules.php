<?php
require_once 'admin_auth.php'; 
require_once 'configa.php';

// Get filter parameters
$filter_employee = $_GET['employee_id'] ?? '';
$filter_day = $_GET['day'] ?? '';

// Build query
$sql = "SELECT s.*, e.first_name, e.last_name, e.position 
        FROM schedules s 
        JOIN employees e ON s.employee_id = e.employee_id 
        WHERE 1=1";
$params = [];

if (!empty($filter_employee)) {
    $sql .= " AND s.employee_id = ?";
    $params[] = $filter_employee;
}

if (!empty($filter_day)) {
    $sql .= " AND s.day = ?";
    $params[] = $filter_day;
}

$sql .= " ORDER BY e.last_name, FIELD(s.day, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'), s.time_start";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$schedules = $stmt->fetchAll();

// Get all employees for filter dropdown
$employees = $pdo->query("SELECT employee_id, first_name, last_name FROM employees ORDER BY first_name")->fetchAll();

// Get unique days for filter
$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

// Handle delete request
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM schedules WHERE id = ?");
    if ($stmt->execute([$id])) {
        $success = "Schedule deleted successfully!";
        // Refresh page to show updated list
        header("Location: schedules.php?deleted=1");
        exit;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Schedule Management - Attendance System</title>
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
            max-width: 1300px;
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

        .btn-add {
            background: #28a745;
            color: white;
        }

        .btn-add:hover {
            background: #218838;
            transform: translateY(-2px);
        }

        .btn-edit {
            background: #ffc107;
            color: #333;
            padding: 6px 12px;
            font-size: 14px;
        }

        .btn-delete {
            background: #dc3545;
            color: white;
            padding: 6px 12px;
            font-size: 14px;
        }

        .btn-edit:hover, .btn-delete:hover {
            transform: translateY(-2px);
        }

        .filter-section {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 30px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }

        .filter-input {
            flex: 1;
            min-width: 200px;
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

        .table-container {
            background: white;
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        th {
            background: #667eea;
            color: white;
            padding: 15px;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.9em;
        }

        td {
            padding: 12px 15px;
            border-bottom: 1px solid #e0e0e0;
            text-align: center;
        }

        tr:hover {
            background: #f8f9fa;
        }

        .employee-info {
            text-align: left;
        }

        .employee-name {
            font-weight: 600;
            color: #333;
        }

        .employee-id {
            font-size: 0.85em;
            color: #667eea;
        }

        .time-slot {
            font-weight: 600;
            color: #28a745;
        }

        .day-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
        }

        .day-Monday { background: #f8d7da; color: #721c24; }
        .day-Tuesday { background: #fff3cd; color: #856404; }
        .day-Wednesday { background: #d4edda; color: #155724; }
        .day-Thursday { background: #cce5ff; color: #004085; }
        .day-Friday { background: #d1ecf1; color: #0c5460; }
        .day-Saturday { background: #e0d4fc; color: #5a3e8a; }

        .action-buttons {
            display: flex;
            gap: 5px;
            justify-content: center;
        }

        .no-data {
            text-align: center;
            padding: 50px;
            color: #999;
            font-size: 1.2em;
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

        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .summary-card {
            background: white;
            padding: 20px;
            border-radius: 15px;
            text-align: center;
        }

        .summary-card h3 {
            font-size: 2em;
            color: #667eea;
            margin-bottom: 5px;
        }

        .summary-card p {
            color: #666;
            font-weight: 500;
        }

        @media (max-width: 768px) {
            h1 {
                font-size: 2em;
            }
            
            .filter-section {
                flex-direction: column;
            }
            
            .filter-input {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Schedule Management</h1>

        <?php if (isset($_GET['deleted'])): ?>
            <div class="alert alert-success">Schedule deleted successfully!</div>
        <?php endif; ?>

        <?php if (isset($_GET['updated'])): ?>
            <div class="alert alert-success">Schedule updated successfully!</div>
        <?php endif; ?>

        <?php if (isset($_GET['added'])): ?>
            <div class="alert alert-success">Schedule added successfully!</div>
        <?php endif; ?>

        <div class="header-buttons">
            <a href="admin_dashboard.php" class="btn btn-back">← Back to Dashboard</a>
            <a href="schedule_edit.php" class="btn btn-add">+ Add New Schedule</a>
        </div>

        <!-- Summary Cards -->
        <?php
        $totalSchedules = count($schedules);
        $uniqueEmployees = $pdo->query("SELECT COUNT(DISTINCT employee_id) FROM schedules")->fetchColumn();
        ?>
        <div class="summary-cards">
            <div class="summary-card">
                <h3><?php echo $totalSchedules; ?></h3>
                <p>Total Schedules</p>
            </div>
            <div class="summary-card">
                <h3><?php echo $uniqueEmployees; ?></h3>
                <p>Employees with Schedules</p>
            </div>
            <div class="summary-card">
                <h3><?php echo count(array_filter($schedules, function($s) { return $s['day'] == date('l'); })); ?></h3>
                <p>Today's Classes</p>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <select id="employeeFilter" class="filter-input">
                <option value="">All Employees</option>
                <?php foreach ($employees as $emp): ?>
                    <option value="<?php echo $emp['employee_id']; ?>" 
                        <?php echo ($filter_employee == $emp['employee_id']) ? 'selected' : ''; ?>>
                        <?php echo $emp['first_name'] . ' ' . $emp['last_name'] . ' (' . $emp['employee_id'] . ')'; ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select id="dayFilter" class="filter-input">
                <option value="">All Days</option>
                <?php foreach ($days as $day): ?>
                    <option value="<?php echo $day; ?>" 
                        <?php echo ($filter_day == $day) ? 'selected' : ''; ?>>
                        <?php echo $day; ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button class="filter-btn" onclick="applyFilters()">Apply Filters</button>
            <button class="filter-btn" style="background: #6c757d;" onclick="clearFilters()">Clear</button>
        </div>

        <!-- Schedules Table -->
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Subject Code</th>
                        <th>Subject Name</th>
                        <th>Units</th>
                        <th>Day</th>
                        <th>Time</th>
                        <th>Room</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($schedules)): ?>
                        <tr>
                            <td colspan="8" class="no-data">No schedules found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($schedules as $schedule): ?>
                            <tr>
                                <td class="employee-info">
                                    <div class="employee-name">
                                        <?php echo htmlspecialchars($schedule['first_name'] . ' ' . $schedule['last_name']); ?>
                                    </div>
                                    <div class="employee-id">
                                        <?php echo htmlspecialchars($schedule['employee_id']); ?>
                                    </div>
                                </td>
                                <td><strong><?php echo htmlspecialchars($schedule['subject_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($schedule['subject_name']); ?></td>
                                <td><?php echo $schedule['units']; ?></td>
                                <td>
                                    <span class="day-badge day-<?php echo $schedule['day']; ?>">
                                        <?php echo $schedule['day']; ?>
                                    </span>
                                </td>
                                <td class="time-slot">
                                    <?php 
                                    echo date('h:i A', strtotime($schedule['time_start'])) . ' - ' . 
                                         date('h:i A', strtotime($schedule['time_end'])); 
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($schedule['room']); ?></td>
                                <td class="action-buttons">
                                    <a href="schedule_edit.php?id=<?php echo $schedule['id']; ?>" 
                                       class="btn btn-edit">✏️ Edit</a>
                                    <a href="javascript:void(0)" 
                                       onclick="confirmDelete(<?php echo $schedule['id']; ?>)" 
                                       class="btn btn-delete">🗑️ Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        function applyFilters() {
            const employee = document.getElementById('employeeFilter').value;
            const day = document.getElementById('dayFilter').value;
            
            let url = 'schedules.php?';
            if (employee) url += 'employee_id=' + encodeURIComponent(employee) + '&';
            if (day) url += 'day=' + encodeURIComponent(day);
            
            window.location.href = url;
        }

        function clearFilters() {
            window.location.href = 'schedules.php';
        }

        function confirmDelete(id) {
            if (confirm('Are you sure you want to delete this schedule?')) {
                window.location.href = 'schedules.php?delete=' + id;
            }
        }
    </script>
</body>
</html>