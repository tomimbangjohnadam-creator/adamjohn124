<?php
session_start();
require_once 'config.php';

// Get statistics
$stats = [];

// Total employees
$result = $pdo->query("SELECT COUNT(*) as total FROM employees");
$stats['total_employees'] = $result->fetch()['total'];

// Present today
$result = $pdo->query("SELECT COUNT(*) as total FROM attendance WHERE date = CURDATE() AND time_in IS NOT NULL");
$stats['present_today'] = $result->fetch()['total'];

// Working now
$result = $pdo->query("SELECT COUNT(*) as total FROM attendance WHERE date = CURDATE() AND time_in IS NOT NULL AND time_out IS NULL");
$stats['working_now'] = $result->fetch()['total'];

// Recent attendance
$recent = $pdo->query("
    SELECT a.*, e.first_name, e.last_name 
    FROM attendance a 
    JOIN employees e ON a.employee_id = e.employee_id 
    ORDER BY a.date DESC, a.time_in DESC 
    LIMIT 5
")->fetchAll();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Dashboard - QR Attendance System</title>
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

        /* Header with Admin Button */
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }

        h1 {
            color: white;
            font-size: 2.5em;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
            margin: 0;
        }

        .admin-login-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 2px solid white;
            padding: 12px 25px;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .admin-login-btn:hover {
            background: white;
            color: #667eea;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255,255,255,0.3);
        }

        .admin-icon {
            font-size: 1.3em;
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 40px;
        }

        .stat-box {
            background: rgba(255,255,255,0.2);
            padding: 25px;
            border-radius: 15px;
            color: white;
            text-align: center;
            backdrop-filter: blur(10px);
            transition: transform 0.3s;
        }

        .stat-box:hover {
            transform: translateY(-5px);
        }

        .stat-box h3 {
            font-size: 2.5em;
            margin-bottom: 5px;
        }

        .stat-box p {
            font-size: 1.1em;
            opacity: 0.9;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-bottom: 40px;
        }

        .dashboard-card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            text-align: center;
            transition: all 0.3s;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            text-decoration: none;
            color: inherit;
            display: block;
            position: relative;
            overflow: hidden;
        }

        .dashboard-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #667eea, #764ba2);
        }

        .dashboard-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
        }

        .card-icon {
            font-size: 3.5em;
            margin-bottom: 15px;
        }

        .card-title {
            font-size: 1.5em;
            color: #333;
            margin-bottom: 10px;
            font-weight: 600;
        }

        .card-description {
            color: #666;
            margin-bottom: 15px;
        }

        .card-stats {
            font-size: 1.8em;
            font-weight: bold;
            color: #667eea;
        }

        .recent-section {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .recent-section h2 {
            color: #333;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .recent-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .recent-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            transition: transform 0.2s;
        }

        .recent-item:hover {
            transform: translateX(5px);
            background: #f0f0f0;
        }

        .recent-item strong {
            color: #333;
        }

        .recent-item small {
            color: #666;
        }

        .recent-time {
            text-align: right;
        }

        .date-badge {
            background: #667eea;
            color: white;
            padding: 3px 8px;
            border-radius: 5px;
            font-size: 0.8em;
        }

        @media (max-width: 768px) {
            h1 {
                font-size: 2em;
            }
            
            .dashboard-header {
                flex-direction: column;
                text-align: center;
            }
            
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .admin-login-btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header with Admin Login Button -->
        <div class="dashboard-header">
            <h1>Employee Dashboard</h1>
            <a href="../admin/admin_login.php" class="admin-login-btn">
                <span class="admin-icon">👨‍💼</span>
                Admin Login
            </a>
        </div>

        <!-- Statistics -->
        <div class="stats-container">
            <div class="stat-box">
                <h3><?php echo $stats['total_employees']; ?></h3>
                <p>Total Employees</p>
            </div>
            <div class="stat-box">
                <h3><?php echo $stats['present_today']; ?></h3>
                <p>Present Today</p>
            </div>
            <div class="stat-box">
                <h3><?php echo $stats['working_now']; ?></h3>
                <p>Working Now</p>
            </div>
        </div>

        <!-- Main Dashboard Cards -->
        <div class="dashboard-grid">

            <a href="attendance.php" class="dashboard-card">
                <div class="card-icon">📊</div>
                <div class="card-title">Attendance Record</div>
                <div class="card-description">View and manage attendance records</div>
                <div class="card-stats"><?php echo $stats['present_today']; ?> Today</div>
            </a>

            <a href="employee_schedule.php" class="dashboard-card">
                <div class="card-icon">📅</div>
                <div class="card-title">Schedule Viewer</div>
                <div class="card-description">View employee class schedules</div>
                <div class="card-stats">View Schedule</div>
            </a>
             <a href="scanner.php" class="dashboard-card">
                <div class="card-icon">📷</div>
                <div class="card-title">QR Scanner</div>
                <div class="card-description">Scan employee QR codes for attendance</div>
                <div class="card-stats">Live scanner</div>
            </a>
        </div>

        <!-- Recent Attendance -->
        <div class="recent-section">
            <h2>
                <span>📋</span> Recent Attendance
            </h2>
            <div class="recent-list">
                <?php if (empty($recent)): ?>
                    <p style="text-align: center; color: #999; padding: 20px;">No recent attendance records</p>
                <?php else: ?>
                    <?php foreach ($recent as $record): ?>
                    <div class="recent-item">
                        <span>
                            <strong><?php echo htmlspecialchars($record['first_name'] . ' ' . $record['last_name']); ?></strong>
                            <br>
                            <small>ID: <?php echo htmlspecialchars($record['employee_id']); ?></small>
                        </span>
                        <span class="recent-time">
                            <span class="date-badge"><?php echo date('h:i A', strtotime($record['time_in'])); ?></span>
                            <br>
                            <small><?php echo date('M d, Y', strtotime($record['date'])); ?></small>
                        </span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>