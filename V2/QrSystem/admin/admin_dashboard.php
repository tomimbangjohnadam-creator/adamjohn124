<?php
require_once 'admin_auth.php'; 
require_once 'configa.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

// Define position-based rates (same as add_employee.php)
$position_rates = [
    'Developer' => ['hourly_rate' => 250, 'work_hours_per_day' => 8, 'work_days_per_month' => 22, 
                    'philhealth_rate' => 2.5, 'sss_rate' => 4.5, 'pagibig_rate' => 2, 'overtime_rate' => 1.25],
    'Senior Developer' => ['hourly_rate' => 350, 'work_hours_per_day' => 8, 'work_days_per_month' => 22,
                          'philhealth_rate' => 2.5, 'sss_rate' => 4.5, 'pagibig_rate' => 2, 'overtime_rate' => 1.25],
    'Manager' => ['hourly_rate' => 300, 'work_hours_per_day' => 8, 'work_days_per_month' => 22,
                 'philhealth_rate' => 2.5, 'sss_rate' => 4.5, 'pagibig_rate' => 2, 'overtime_rate' => 1.25],
    'Analyst' => ['hourly_rate' => 200, 'work_hours_per_day' => 8, 'work_days_per_month' => 22,
                 'philhealth_rate' => 2.5, 'sss_rate' => 4.5, 'pagibig_rate' => 2, 'overtime_rate' => 1.25],
    'Designer' => ['hourly_rate' => 180, 'work_hours_per_day' => 8, 'work_days_per_month' => 22,
                  'philhealth_rate' => 2.5, 'sss_rate' => 4.5, 'pagibig_rate' => 2, 'overtime_rate' => 1.25],
    'Tester' => ['hourly_rate' => 180, 'work_hours_per_day' => 8, 'work_days_per_month' => 22,
                'philhealth_rate' => 2.5, 'sss_rate' => 4.5, 'pagibig_rate' => 2, 'overtime_rate' => 1.25],
    'HR Staff' => ['hourly_rate' => 160, 'work_hours_per_day' => 8, 'work_days_per_month' => 22,
                  'philhealth_rate' => 2.5, 'sss_rate' => 4.5, 'pagibig_rate' => 2, 'overtime_rate' => 1.25],
    'Admin' => ['hourly_rate' => 200, 'work_hours_per_day' => 8, 'work_days_per_month' => 22,
               'philhealth_rate' => 2.5, 'sss_rate' => 4.5, 'pagibig_rate' => 2, 'overtime_rate' => 1.25],
    'Cashier' => ['hourly_rate' => 150, 'work_hours_per_day' => 8, 'work_days_per_month' => 22,
                 'philhealth_rate' => 2.5, 'sss_rate' => 4.5, 'pagibig_rate' => 2, 'overtime_rate' => 1.25],
    'Security' => ['hourly_rate' => 140, 'work_hours_per_day' => 8, 'work_days_per_month' => 22,
                  'philhealth_rate' => 2.5, 'sss_rate' => 4.5, 'pagibig_rate' => 2, 'overtime_rate' => 1.25],
    'Janitor' => ['hourly_rate' => 130, 'work_hours_per_day' => 8, 'work_days_per_month' => 22,
                 'philhealth_rate' => 2.5, 'sss_rate' => 4.5, 'pagibig_rate' => 2, 'overtime_rate' => 1.25],
    'Driver' => ['hourly_rate' => 135, 'work_hours_per_day' => 8, 'work_days_per_month' => 22,
                'philhealth_rate' => 2.5, 'sss_rate' => 4.5, 'pagibig_rate' => 2, 'overtime_rate' => 1.25]
];

// Default rate for unknown positions
$default_rate = ['hourly_rate' => 150, 'work_hours_per_day' => 8, 'work_days_per_month' => 22,
                'philhealth_rate' => 2.5, 'sss_rate' => 4.5, 'pagibig_rate' => 2, 'overtime_rate' => 1.25];

// Handle profile update actions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        // Update employee profile - REMOVED EMERGENCY CONTACT FIELDS
        if ($_POST['action'] == 'update_employee') {
            $employee_id = $_POST['employee_id'] ?? '';
            $first_name = $_POST['first_name'] ?? '';
            $last_name = $_POST['last_name'] ?? '';
            $email = $_POST['email'] ?? '';
            $phone = $_POST['phone'] ?? '';
            $position = $_POST['position'] ?? '';
            $address = $_POST['address'] ?? '';
            $hire_date = $_POST['hire_date'] ?? '';
            $employment_status = $_POST['employment_status'] ?? 'regular';
            $update_payroll = isset($_POST['update_payroll']) ? true : false;
            
            // Handle profile image upload
            $profile_image = '';
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == 0) {
                $allowed = ['jpg', 'jpeg', 'png', 'gif'];
                $filename = $_FILES['profile_image']['name'];
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                
                if (in_array($ext, $allowed)) {
                    $new_filename = 'employee_' . $employee_id . '_' . time() . '.' . $ext;
                    $upload_path = 'uploads/profiles/' . $new_filename;
                    
                    // Create directory if not exists
                    if (!is_dir('uploads/profiles')) {
                        mkdir('uploads/profiles', 0777, true);
                    }
                    
                    if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_path)) {
                        $profile_image = $new_filename;
                    }
                }
            }
            
            // Start transaction
            $pdo->beginTransaction();
            
            try {
                // Build update query - REMOVED EMERGENCY CONTACT FIELDS
                $sql = "UPDATE employees SET 
                        first_name = ?, last_name = ?, email = ?, phone = ?, 
                        position = ?, address = ?, 
                        hire_date = ?, employment_status = ?";
                
                $params = [$first_name, $last_name, $email, $phone, $position, 
                          $address, $hire_date, $employment_status];
                
                if ($profile_image) {
                    $sql .= ", profile_image = ?";
                    $params[] = $profile_image;
                }
                
                $sql .= " WHERE employee_id = ?";
                $params[] = $employee_id;
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                
                // Update payroll settings if checkbox is checked
                if ($update_payroll) {
                    // Get rate based on new position
                    $rate = isset($position_rates[$position]) ? $position_rates[$position] : $default_rate;
                    
                    // Check if payroll settings exist
                    $check_payroll = $pdo->prepare("SELECT id FROM employee_payroll_settings WHERE employee_id = ?");
                    $check_payroll->execute([$employee_id]);
                    
                    if ($check_payroll->fetch()) {
                        // Update existing payroll settings
                        $update_payroll_sql = "UPDATE employee_payroll_settings SET 
                                              hourly_rate = ?, work_hours_per_day = ?, work_days_per_month = ?,
                                              philhealth_rate = ?, sss_rate = ?, pagibig_rate = ?, overtime_rate = ?
                                              WHERE employee_id = ?";
                        $update_payroll_stmt = $pdo->prepare($update_payroll_sql);
                        $update_payroll_stmt->execute([
                            $rate['hourly_rate'], $rate['work_hours_per_day'], $rate['work_days_per_month'],
                            $rate['philhealth_rate'], $rate['sss_rate'], $rate['pagibig_rate'], $rate['overtime_rate'],
                            $employee_id
                        ]);
                    } else {
                        // Insert new payroll settings
                        $insert_payroll_sql = "INSERT INTO employee_payroll_settings 
                                              (employee_id, hourly_rate, work_hours_per_day, work_days_per_month,
                                               philhealth_rate, sss_rate, pagibig_rate, overtime_rate)
                                              VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                        $insert_payroll_stmt = $pdo->prepare($insert_payroll_sql);
                        $insert_payroll_stmt->execute([
                            $employee_id,
                            $rate['hourly_rate'], $rate['work_hours_per_day'], $rate['work_days_per_month'],
                            $rate['philhealth_rate'], $rate['sss_rate'], $rate['pagibig_rate'], $rate['overtime_rate']
                        ]);
                    }
                }
                
                // Commit transaction
                $pdo->commit();
                
                $message = "Employee profile updated successfully!";
                $message_type = "success";
                
            } catch (Exception $e) {
                // Rollback transaction on error
                $pdo->rollBack();
                $message = "Error updating employee profile: " . $e->getMessage();
                $message_type = "error";
            }
        }
        
        // ADD NEW EMPLOYEE - REMOVED EMERGENCY CONTACT FIELDS
        if ($_POST['action'] == 'add_employee') {
            $employee_id = $_POST['emp_id'] ?? '';
            $first_name = $_POST['first_name'] ?? '';
            $last_name = $_POST['last_name'] ?? '';
            $email = $_POST['email'] ?? '';
            $phone = $_POST['phone'] ?? '';
            $position = $_POST['position'] ?? '';
            $address = $_POST['address'] ?? '';
            $hire_date = $_POST['hire_date'] ?? date('Y-m-d');
            $employment_status = $_POST['employment_status'] ?? 'regular';
            
            // Handle profile image upload
            $profile_image = '';
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == 0) {
                $allowed = ['jpg', 'jpeg', 'png', 'gif'];
                $filename = $_FILES['profile_image']['name'];
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                
                if (in_array($ext, $allowed)) {
                    $new_filename = 'employee_' . $employee_id . '_' . time() . '.' . $ext;
                    $upload_path = 'uploads/profiles/' . $new_filename;
                    
                    if (!is_dir('uploads/profiles')) {
                        mkdir('uploads/profiles', 0777, true);
                    }
                    
                    if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_path)) {
                        $profile_image = $new_filename;
                    }
                }
            }
            
            // Check if employee ID exists
            $checkId = $pdo->prepare("SELECT employee_id FROM employees WHERE employee_id = ?");
            $checkId->execute([$employee_id]);
            if ($checkId->fetch()) {
                $message = "Employee ID already exists! Please use a different ID.";
                $message_type = "error";
            } else {
                // Insert new employee - REMOVED EMERGENCY CONTACT FIELDS
                $sql = "INSERT INTO employees (employee_id, first_name, last_name, email, phone, 
                        position, address, hire_date, employment_status, profile_image, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                
                $stmt = $pdo->prepare($sql);
                if ($stmt->execute([$employee_id, $first_name, $last_name, $email, $phone, 
                                   $position, $address, $hire_date, $employment_status, $profile_image])) {
                    
                    // Auto-create payroll settings
                    $rate = isset($position_rates[$position]) ? $position_rates[$position] : $default_rate;
                    
                    $payroll_sql = "INSERT INTO employee_payroll_settings 
                                   (employee_id, hourly_rate, work_hours_per_day, work_days_per_month,
                                    philhealth_rate, sss_rate, pagibig_rate, overtime_rate)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    $payroll_stmt = $pdo->prepare($payroll_sql);
                    $payroll_stmt->execute([
                        $employee_id,
                        $rate['hourly_rate'], $rate['work_hours_per_day'], $rate['work_days_per_month'],
                        $rate['philhealth_rate'], $rate['sss_rate'], $rate['pagibig_rate'], $rate['overtime_rate']
                    ]);
                    
                    $message = "Employee added successfully with auto-rate!";
                    $message_type = "success";
                } else {
                    $message = "Error adding employee.";
                    $message_type = "error";
                }
            }
        }
    }
}

// Get statistics
$stats = [];

// Total employees
$result = $pdo->query("SELECT COUNT(*) as total FROM employees");
$stats['total_employees'] = $result->fetch()['total'];

// Total schedules
//$result = $pdo->query("SELECT COUNT(*) as total FROM schedules");
//$stats['total_schedules'] = $result->fetch()['total'];

// Today's attendance
$today = date('Y-m-d');
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM attendance WHERE date = ? AND time_in IS NOT NULL");
$stmt->execute([$today]);
$stats['today_present'] = $stmt->fetch()['total'];

// Currently working
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM attendance WHERE date = ? AND time_in IS NOT NULL AND time_out IS NULL");
$stmt->execute([$today]);
$stats['currently_working'] = $stmt->fetch()['total'];

// Total admins
$result = $pdo->query("SELECT COUNT(*) as total FROM admins");
$stats['total_admins'] = $result->fetch()['total'];

// Recent activities - with safety check for profile_image column
try {
    // First check if profile_image column exists
    $checkColumn = $pdo->query("SHOW COLUMNS FROM employees LIKE 'profile_image'");
    $hasProfileImage = $checkColumn->rowCount() > 0;
    
    if ($hasProfileImage) {
        $recent_attendance = $pdo->query("
            SELECT a.*, e.first_name, e.last_name, e.profile_image 
            FROM attendance a 
            JOIN employees e ON a.employee_id = e.employee_id 
            ORDER BY a.date DESC, a.time_in DESC 
            LIMIT 5
        ")->fetchAll();
    } else {
        $recent_attendance = $pdo->query("
            SELECT a.*, e.first_name, e.last_name 
            FROM attendance a 
            JOIN employees e ON a.employee_id = e.employee_id 
            ORDER BY a.date DESC, a.time_in DESC 
            LIMIT 5
        ")->fetchAll();
    }
} catch (Exception $e) {
    // Fallback if there's an error
    $recent_attendance = $pdo->query("
        SELECT a.*, e.first_name, e.last_name 
        FROM attendance a 
        JOIN employees e ON a.employee_id = e.employee_id 
        ORDER BY a.date DESC, a.time_in DESC 
        LIMIT 5
    ")->fetchAll();
}

// Get all employees for management
$employees = $pdo->query("SELECT * FROM employees ORDER BY first_name LIMIT 10")->fetchAll();

// Get admin info
$admin_name = $_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? 'Admin';
$admin_role = $_SESSION['admin_role'] ?? 'admin';

// Get all positions for dropdown
$positions = array_keys($position_rates);
sort($positions);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard - Attendance System</title>
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

        /* Header */
        .admin-header {
            background: white;
            border-radius: 20px;
            padding: 20px 30px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .admin-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .admin-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5em;
        }

        .admin-details h2 {
            color: #333;
            font-size: 1.2em;
        }

        .admin-details p {
            color: #666;
            font-size: 0.9em;
        }

        .role-badge {
            background: #28a745;
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8em;
            margin-left: 10px;
        }

        .header-buttons {
            display: flex;
            gap: 10px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }

        .btn-logout {
            background: #dc3545;
            color: white;
        }

        .btn-logout:hover {
            background: #c82333;
            transform: translateY(-2px);
        }

        .btn-profile {
            background: #17a2b8;
            color: white;
        }

        .btn-profile:hover {
            background: #138496;
            transform: translateY(-2px);
        }

        .btn-primary {
            background: #28a745;
            color: white;
        }

        .btn-primary:hover {
            background: #218838;
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

        /* Welcome Section */
        .welcome-section {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .welcome-section h1 {
            color: #333;
            font-size: 2em;
            margin-bottom: 10px;
        }

        .welcome-section p {
            color: #666;
            font-size: 1.1em;
        }

        .date-time {
            color: #667eea;
            font-weight: 600;
            margin-top: 10px;
        }

        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-close {
            background: none;
            border: none;
            font-size: 1.2em;
            cursor: pointer;
            color: inherit;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            transition: transform 0.3s;
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8em;
            color: white;
        }

        .stat-info h3 {
            font-size: 2em;
            color: #333;
            margin-bottom: 5px;
        }

        .stat-info p {
            color: #666;
            font-weight: 500;
        }

        /* Employee Management Section */
        .employee-management {
            background: white;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }

        .section-header h2 {
            color: #333;
            font-size: 1.3em;
        }

        .view-link {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e0e0e0;
            padding-bottom: 10px;
        }

        .tab {
            padding: 10px 20px;
            background: none;
            border: none;
            font-weight: 600;
            color: #666;
            cursor: pointer;
            position: relative;
        }

        .tab.active {
            color: #667eea;
        }

        .tab.active::after {
            content: '';
            position: absolute;
            bottom: -12px;
            left: 0;
            right: 0;
            height: 3px;
            background: #667eea;
            border-radius: 3px 3px 0 0;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Employee Avatar */
        .employee-avatar-sm {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2em;
            font-weight: bold;
            text-transform: uppercase;
        }

        .employee-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .employee-card {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 15px;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: transform 0.3s;
            border: 1px solid #e0e0e0;
        }

        .employee-card:hover {
            transform: translateX(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .employee-info-sm {
            flex: 1;
        }

        .employee-info-sm h4 {
            color: #333;
            margin-bottom: 3px;
            font-size: 1em;
        }

        .employee-info-sm p {
            color: #666;
            font-size: 0.85em;
            margin-bottom: 2px;
        }

        .employee-info-sm small {
            color: #999;
            font-size: 0.75em;
        }

        .employee-actions {
            display: flex;
            gap: 5px;
        }

        .action-btn {
            padding: 5px 10px;
            border: none;
            border-radius: 5px;
            font-size: 0.8em;
            cursor: pointer;
            text-decoration: none;
            color: white;
        }

        .action-btn.edit {
            background: #17a2b8;
        }

        .action-btn.view {
            background: #28a745;
        }

        /* Management Cards */
        .management-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .management-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            text-decoration: none;
            color: inherit;
            transition: transform 0.3s;
            display: block;
        }

        .management-card:hover {
            transform: translateY(-5px);
        }

        .card-icon {
            font-size: 2.5em;
            margin-bottom: 15px;
        }

        .card-title {
            font-size: 1.3em;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
        }

        .card-description {
            color: #666;
            margin-bottom: 15px;
            font-size: 0.95em;
        }

        .card-stats {
            color: #667eea;
            font-weight: 600;
            font-size: 0.9em;
        }

        /* Recent Activity */
        .recent-section {
            background: white;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .recent-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .recent-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 10px;
        }

        .recent-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            text-transform: uppercase;
        }

        .recent-time {
            background: #667eea;
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.8em;
        }

        .recent-name {
            flex: 1;
            font-weight: 600;
            color: #333;
        }

        .recent-status {
            padding: 3px 8px;
            border-radius: 5px;
            font-size: 0.8em;
            font-weight: 600;
        }

        .status-present {
            background: #d4edda;
            color: #155724;
        }

        .status-working {
            background: #fff3cd;
            color: #856404;
        }

        /* Quick Actions */
        .quick-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 20px;
        }

        .quick-btn {
            padding: 10px 20px;
            background: #f8f9fa;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            text-decoration: none;
            color: #333;
            font-weight: 500;
            transition: all 0.3s;
        }

        .quick-btn:hover {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

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
            overflow-y: auto;
            padding: 20px;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            padding: 30px;
            max-width: 600px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalSlideIn 0.3s;
        }

        @keyframes modalSlideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
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

        .modal-close:hover {
            color: #333;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group.full-width {
            grid-column: span 2;
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
            transition: border-color 0.3s;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #667eea;
        }

        .image-preview {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            overflow: hidden;
            margin: 10px auto;
            border: 3px solid #667eea;
        }

        .image-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* Rate Preview */
        .rate-preview {
            background: #f8f9fa;
            border: 2px solid #667eea;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            justify-content: space-around;
        }

        .rate-item {
            text-align: center;
            min-width: 120px;
        }

        .rate-label {
            color: #666;
            font-size: 0.9em;
            margin-bottom: 5px;
        }

        .rate-value {
            color: #28a745;
            font-weight: 700;
            font-size: 1.2em;
        }

        .rate-note {
            background: #e7f5ff;
            color: #004085;
            padding: 10px;
            border-radius: 5px;
            font-size: 0.9em;
            margin-top: 10px;
            border-left: 4px solid #667eea;
        }

        .rate-badge {
            background: #28a745;
            color: white;
            padding: 3px 8px;
            border-radius: 15px;
            font-size: 0.8em;
            margin-left: 10px;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 15px 0;
            padding: 10px;
            background: #e7f5ff;
            border-radius: 8px;
        }

        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }

        .checkbox-group label {
            margin-bottom: 0;
            cursor: pointer;
            color: #004085;
            font-weight: 600;
        }

        .modal-footer {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }

        @media (max-width: 768px) {
            .admin-header {
                flex-direction: column;
                text-align: center;
            }
            
            .admin-info {
                flex-direction: column;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .form-group.full-width {
                grid-column: span 1;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Admin Header -->
        <div class="admin-header">
            <div class="admin-info">
                <div class="admin-avatar">
                    <?php echo strtoupper(substr($admin_name, 0, 1)); ?>
                </div>
                <div class="admin-details">
                    <h2>Welcome, <?php echo htmlspecialchars($admin_name ?? 'Admin'); ?> 
                        <span class="role-badge"><?php echo htmlspecialchars($admin_role ?? 'admin'); ?></span>
                    </h2>
                    <p>Last login: <?php echo date('F j, Y h:i A'); ?></p>
                </div>
            </div>
            <div class="header-buttons">
                <a href="admin_logout.php" class="btn btn-logout">🚪 Logout</a>
            </div>
        </div>

        <!-- Welcome Section -->
        <div class="welcome-section">
            <h1>Admin Dashboard</h1>
            <div class="date-time">
                📅 <?php echo date('l, F j, Y'); ?> | ⏰ <?php echo date('h:i A'); ?>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <?php echo htmlspecialchars($message); ?>
                <button class="alert-close" onclick="this.parentElement.style.display='none'">×</button>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">👥</div>
                <div class="stat-info">
                    <h3><?php echo $stats['total_employees'] ?? 0; ?></h3>
                    <p>Total Employees</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📅</div>
                <div class="stat-info">
                    <h3><?php echo $stats['total_schedules'] ?? 0; ?></h3>
                    <p>Total Schedules</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">✅</div>
                <div class="stat-info">
                    <h3><?php echo $stats['today_present'] ?? 0; ?></h3>
                    <p>Present Today</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">⏰</div>
                <div class="stat-info">
                    <h3><?php echo $stats['currently_working'] ?? 0; ?></h3>
                    <p>Currently Working</p>
                </div>
            </div>
        </div>
            <!-- Search Tab -->
            <div id="search-employees" class="tab-content">
                <div style="margin-bottom: 20px;">
                    <input type="text" id="searchInput" placeholder="Search by name, ID, position..." style="width: 100%;">
                </div>
                <div class="employee-list" id="searchResults">
                    <p style="text-align: center; padding: 20px;">Type to search employees...</p>
                </div>
            </div>
        </div>

        <!-- Management Cards -->
        <div class="management-grid">
            <a href="employees.php" class="management-card">
                <div class="card-icon">👥</div>
                <div class="card-title">Manage Employees</div>
                <div class="card-description">Add, edit, or remove employees and generate QR codes</div>
                <div class="card-stats"><?php echo $stats['total_employees'] ?? 0; ?> registered</div>
            </a>

            <a href="admin_attendance.php" class="management-card">
                <div class="card-icon">📊</div>
                <div class="card-title">Attendance Records</div>
                <div class="card-description">View and export attendance records</div>
                <div class="card-stats"><?php echo $stats['today_present'] ?? 0; ?> present today</div>
            </a>

            <a href="admin_scanner.php" class="management-card">
                <div class="card-icon">📷</div>
                <div class="card-title">QR Scanner</div>
                <div class="card-description">Scan employee QR codes for attendance</div>
                <div class="card-stats">Live scanner</div>
            </a>
            
            <a href="employee_work_schedule.php" class="management-card">
                <div class="card-icon">📅</div>
                <div class="card-title">Work Schedule</div>
                <div class="card-description">Manage daily attendance, overtime, and rest days</div>
                <div class="card-stats">Monthly calendar</div>
            </a>
    
            <a href="payroll_computation.php" class="management-card">
                <div class="card-icon">🧮</div>
                <div class="card-title">Payroll Computation</div>
                <div class="card-description">Calculate salaries with deductions</div>
                <div class="card-stats">Auto-compute</div>
            </a>
        </div>

        <!-- Recent Activity -->
        <div class="recent-section">
            <div class="section-header">
                <h2>Recent Attendance Activity</h2>
                <a href="admin_attendance.php" class="view-link">View All →</a>
            </div>
            <div class="recent-list">
                <?php if (empty($recent_attendance)): ?>
                    <p style="text-align: center; color: #999;">No recent activity</p>
                <?php else: ?>
                    <?php foreach ($recent_attendance as $record): ?>
                        <div class="recent-item">
                            <div class="recent-avatar">
                                <?php 
                                $firstInitial = substr($record['first_name'] ?? '', 0, 1);
                                $lastInitial = substr($record['last_name'] ?? '', 0, 1);
                                echo strtoupper($firstInitial . $lastInitial) ?: '👤'; 
                                ?>
                            </div>
                            <span class="recent-time">
                                <?php echo isset($record['time_in']) ? date('h:i A', strtotime($record['time_in'])) : '-'; ?>
                            </span>
                            <span class="recent-name">
                                <?php echo htmlspecialchars(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')); ?>
                            </span>
                            <span class="recent-status <?php echo isset($record['time_out']) && $record['time_out'] ? 'status-present' : 'status-working'; ?>">
                                <?php echo isset($record['time_out']) && $record['time_out'] ? 'Completed' : 'Working'; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
          
        <p style="text-align: left; color: #383838;"> | Shortcut Feature</p>
             
        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="add_employee.php" class="quick-btn">➕ Add Employee</a>
            <a href="generate_qrcodes.php" class="quick-btn">📱 Generate QR Codes</a>
            <a href="employee_work_schedule.php" class="quick-btn">📅 Employee Work Schedule</a>
            <a href="payroll_computation.php" class="quick-btn">💰 Compute Payroll</a>
        </div>
    </div>

    <!-- Add/Edit Employee Modal -->
    <div id="employeeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="modalTitle">Add New Employee</h2>
                <button class="modal-close" onclick="hideModal()">×</button>
            </div>
            
            <form method="POST" enctype="multipart/form-data" id="employeeForm">
                <input type="hidden" name="action" id="formAction" value="add_employee">
                <input type="hidden" name="employee_id" id="employeeId">
                
                <div style="text-align: center; margin-bottom: 20px;">
                    <div class="image-preview" id="imagePreview">
                        <img src="default-avatar.png" alt="Profile Preview" id="previewImg">
                    </div>
                    <label for="profile_image" class="btn btn-primary" style="cursor: pointer;">📷 Upload Photo</label>
                    <input type="file" name="profile_image" id="profile_image" accept="image/*" style="display: none;" onchange="previewImage(this)">
                </div>

                <!-- Rate Preview Section -->
                <div id="ratePreviewContainer" style="display: none;">
                    <div class="rate-preview">
                        <div class="rate-item">
                            <div class="rate-label">Hourly Rate</div>
                            <div class="rate-value" id="previewHourlyRate">₱0.00</div>
                        </div>
                        <div class="rate-item">
                            <div class="rate-label">Daily Rate (8hrs)</div>
                            <div class="rate-value" id="previewDailyRate">₱0.00</div>
                        </div>
                        <div class="rate-item">
                            <div class="rate-label">Monthly (22 days)</div>
                            <div class="rate-value" id="previewMonthlyRate">₱0.00</div>
                        </div>
                        <div class="rate-item">
                            <div class="rate-label">Overtime Rate</div>
                            <div class="rate-value" id="previewOTRate">1.25x</div>
                        </div>
                    </div>
                    <div class="rate-note">
                        <strong>📊 Payroll Settings:</strong> PhilHealth (2.5%), SSS (4.5%), Pag-IBIG (2%)
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Employee ID *</label>
                        <input type="text" name="emp_id" id="emp_id" required placeholder="e.g., EMP001" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label>First Name *</label>
                        <input type="text" name="first_name" id="first_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Last Name *</label>
                        <input type="text" name="last_name" id="last_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" name="email" id="email" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="tel" name="phone" id="phone">
                    </div>
                    
                    <div class="form-group">
                        <label>Position *</label>
                        <select name="position" id="position" required onchange="updateRatePreview()">
                            <option value="">Select Position</option>
                            <?php foreach ($positions as $pos): ?>
                                <option value="<?php echo $pos; ?>" 
                                    data-rate="<?php echo isset($position_rates[$pos]) ? $position_rates[$pos]['hourly_rate'] : $default_rate['hourly_rate']; ?>">
                                    <?php echo $pos; ?> - ₱<?php echo isset($position_rates[$pos]) ? number_format($position_rates[$pos]['hourly_rate'], 2) : number_format($default_rate['hourly_rate'], 2); ?>/hr
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Hire Date</label>
                        <input type="date" name="hire_date" id="hire_date" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Employment Status</label>
                        <select name="employment_status" id="employment_status">
                            <option value="regular">Regular</option>
                            <option value="probationary">Probationary</option>
                            <option value="contractual">Contractual</option>
                            <option value="part-time">Part Time</option>
                        </select>
                    </div>
                    
                    <div class="form-group full-width">
                        <label>Address</label>
                        <textarea name="address" id="address" rows="3"></textarea>
                    </div>
                </div>

                <!-- Update Payroll Settings Checkbox (only for edit mode) -->
                <div id="updatePayrollContainer" class="checkbox-group full-width" style="display: none;">
                     
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" onclick="hideModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">Save Employee</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Position rate data
        const positionRates = <?php echo json_encode($position_rates); ?>;
        const defaultRate = <?php echo json_encode($default_rate); ?>;

        // Modal functions
        function showAddEmployeeModal() {
            document.getElementById('modalTitle').textContent = 'Add New Employee';
            document.getElementById('formAction').value = 'add_employee';
            document.getElementById('employeeForm').reset();
            document.getElementById('employeeId').value = '';
            document.getElementById('emp_id').readOnly = false;
            document.getElementById('emp_id').value = '';
            document.getElementById('previewImg').src = 'default-avatar.png';
            document.getElementById('ratePreviewContainer').style.display = 'none';
            document.getElementById('updatePayrollContainer').style.display = 'none';
            document.getElementById('employeeModal').classList.add('show');
        }

        function showEditEmployeeModal() {
            document.getElementById('modalTitle').textContent = 'Edit Employee';
            document.getElementById('formAction').value = 'update_employee';
            document.getElementById('emp_id').readOnly = true;
            document.getElementById('updatePayrollContainer').style.display = 'block';
            document.getElementById('employeeModal').classList.add('show');
        }

        function hideModal() {
            document.getElementById('employeeModal').classList.remove('show');
        }

        // Preview image before upload
        function previewImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('previewImg').src = e.target.result;
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Update rate preview based on selected position
        function updateRatePreview() {
            const positionSelect = document.getElementById('position');
            const selectedPosition = positionSelect.value;
            const previewContainer = document.getElementById('ratePreviewContainer');
            
            if (selectedPosition && positionRates[selectedPosition]) {
                const rate = positionRates[selectedPosition];
                const hourlyRate = rate.hourly_rate;
                const dailyRate = hourlyRate * 8;
                const monthlyRate = dailyRate * 22;
                
                document.getElementById('previewHourlyRate').textContent = '₱' + hourlyRate.toFixed(2);
                document.getElementById('previewDailyRate').textContent = '₱' + dailyRate.toFixed(2);
                document.getElementById('previewMonthlyRate').textContent = '₱' + monthlyRate.toFixed(2);
                document.getElementById('previewOTRate').textContent = rate.overtime_rate + 'x';
                
                previewContainer.style.display = 'block';
            } else {
                previewContainer.style.display = 'none';
            }
        }

        // Edit employee function
        function editEmployee(employeeId) {
            // Fetch employee data via AJAX
            fetch(`get_employee.php?employee_id=${employeeId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('modalTitle').textContent = 'Edit Employee';
                        document.getElementById('formAction').value = 'update_employee';
                        document.getElementById('employeeId').value = data.employee.employee_id;
                        document.getElementById('emp_id').value = data.employee.employee_id;
                        document.getElementById('first_name').value = data.employee.first_name || '';
                        document.getElementById('last_name').value = data.employee.last_name || '';
                        document.getElementById('email').value = data.employee.email || '';
                        document.getElementById('phone').value = data.employee.phone || '';
                        document.getElementById('position').value = data.employee.position || '';
                        document.getElementById('hire_date').value = data.employee.hire_date || '<?php echo date('Y-m-d'); ?>';
                        document.getElementById('employment_status').value = data.employee.employment_status || 'regular';
                        document.getElementById('address').value = data.employee.address || '';
                        
                        if (data.employee.profile_image) {
                            document.getElementById('previewImg').src = 'uploads/profiles/' + data.employee.profile_image;
                        } else {
                            document.getElementById('previewImg').src = 'default-avatar.png';
                        }
                        
                        // Update rate preview based on current position
                        updateRatePreview();
                        
                        showEditEmployeeModal();
                    } else {
                        alert('Employee not found!');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading employee data');
                });
        }

        // Tab functionality
        function openTab(event, tabName) {
            const tabs = document.getElementsByClassName('tab');
            const contents = document.getElementsByClassName('tab-content');
            
            for (let i = 0; i < tabs.length; i++) {
                tabs[i].classList.remove('active');
            }
            
            for (let i = 0; i < contents.length; i++) {
                contents[i].classList.remove('active');
            }
            
            event.currentTarget.classList.add('active');
            document.getElementById(tabName).classList.add('active');
            
            // Load all employees if switching to that tab
            if (tabName === 'all-employees') {
                loadAllEmployees();
            }
        }

        // Load all employees via AJAX
        function loadAllEmployees() {
            const container = document.getElementById('allEmployeesList');
            container.innerHTML = '<p style="text-align: center; padding: 20px;">Loading employees...</p>';
            
            fetch('get_all_employees.php')
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success && data.employees && data.employees.length > 0) {
                        let html = '';
                        data.employees.forEach(emp => {
                            html += `
                                <div class="employee-card">
                                    <div class="employee-avatar-sm">
                                        ${emp.initials || '👤'}
                                    </div>
                                    <div class="employee-info-sm">
                                        <h4>${escapeHtml(emp.first_name || '')} ${escapeHtml(emp.last_name || '')}</h4>
                                        <p>${escapeHtml(emp.position || 'No Position')}</p>
                                        <small>ID: ${escapeHtml(emp.employee_id || '')}</small>
                                    </div>
                                    <div class="employee-actions">
                                        <button class="action-btn edit" onclick="editEmployee('${emp.employee_id}')">✏️ Edit</button>
                                        <a href="employee_profile.php?employee_id=${emp.employee_id}" class="action-btn view">👁️ View</a>
                                    </div>
                                </div>
                            `;
                        });
                        container.innerHTML = html;
                    } else {
                        container.innerHTML = '<p style="text-align: center; padding: 20px;">No employees found</p>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    container.innerHTML = '<p style="text-align: center; padding: 20px; color: red;">Error loading employees. Please check console.</p>';
                });
        }

        // Helper function to escape HTML
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Search functionality
        document.getElementById('searchInput')?.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const resultsContainer = document.getElementById('searchResults');
            
            if (searchTerm.length < 2) {
                resultsContainer.innerHTML = '<p style="text-align: center; padding: 20px;">Type at least 2 characters to search...</p>';
                return;
            }
            
            resultsContainer.innerHTML = '<p style="text-align: center; padding: 20px;">Searching...</p>';
            
            fetch(`search_employees.php?q=${encodeURIComponent(searchTerm)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.employees && data.employees.length > 0) {
                        let html = '';
                        data.employees.forEach(emp => {
                            html += `
                                <div class="employee-card">
                                    <div class="employee-avatar-sm">
                                        ${emp.initials || '👤'}
                                    </div>
                                    <div class="employee-info-sm">
                                        <h4>${escapeHtml(emp.first_name || '')} ${escapeHtml(emp.last_name || '')}</h4>
                                        <p>${escapeHtml(emp.position || '')}</p>
                                        <small>ID: ${escapeHtml(emp.employee_id || '')}</small>
                                    </div>
                                    <div class="employee-actions">
                                        <button class="action-btn edit" onclick="editEmployee('${emp.employee_id}')">✏️ Edit</button>
                                        <a href="employee_profile.php?employee_id=${emp.employee_id}" class="action-btn view">👁️ View</a>
                                    </div>
                                </div>
                            `;
                        });
                        resultsContainer.innerHTML = html;
                    } else {
                        resultsContainer.innerHTML = '<p style="text-align: center; padding: 20px;">No employees found</p>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    resultsContainer.innerHTML = '<p style="text-align: center; padding: 20px; color: red;">Error searching employees</p>';
                });
        });

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('employeeModal');
            if (event.target == modal) {
                hideModal();
            }
        }

        // Phone number validation
        document.querySelectorAll('input[type="tel"]').forEach(input => {
            input.addEventListener('input', function(e) {
                this.value = this.value.replace(/[^0-9]/g, '');
                if (this.value.length > 11) {
                    this.value = this.value.slice(0, 11);
                }
            });
        });

        // Auto-capitalize names
        document.getElementById('first_name').addEventListener('input', function(e) {
            this.value = this.value.charAt(0).toUpperCase() + this.value.slice(1).toLowerCase();
        });
        
        document.getElementById('last_name').addEventListener('input', function(e) {
            this.value = this.value.charAt(0).toUpperCase() + this.value.slice(1).toLowerCase();
        });
    </script>
</body>
</html>