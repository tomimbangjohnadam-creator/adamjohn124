<?php
require_once 'admin_auth.php'; 
require_once 'configa.php';

$employee_id = $_GET['id'] ?? '';

if (empty($employee_id)) {
    header("Location: employees.php");
    exit;
}

// Get employee details
$stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ?");
$stmt->execute([$employee_id]);
$employee = $stmt->fetch();

if (!$employee) {
    header("Location: employees.php");
    exit;
}

// Handle profile picture upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_picture'])) {
    $target_dir = "uploads/profile_pictures/";
    
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    $file_extension = strtolower(pathinfo($_FILES["profile_picture"]["name"], PATHINFO_EXTENSION));
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    
    if (in_array($file_extension, $allowed_extensions)) {
        $new_filename = $employee_id . '.' . $file_extension;
        $target_file = $target_dir . $new_filename;
        
        $old_files = glob($target_dir . $employee_id . '.*');
        foreach ($old_files as $old_file) {
            if (file_exists($old_file)) {
                unlink($old_file);
            }
        }
        
        if (move_uploaded_file($_FILES["profile_picture"]["tmp_name"], $target_file)) {
            try {
                $checkColumn = $pdo->prepare("SHOW COLUMNS FROM employees LIKE 'profile_picture'");
                $checkColumn->execute();
                if ($checkColumn->rowCount() > 0) {
                    $updateStmt = $pdo->prepare("UPDATE employees SET profile_picture = ? WHERE employee_id = ?");
                    $updateStmt->execute([$new_filename, $employee_id]);
                }
            } catch (PDOException $e) {}
            
            header("Location: view_employee.php?id=" . $employee_id . "&success=1");
            exit;
        }
    }
}

// Handle photo removal
if (isset($_GET['remove_photo']) && $_GET['remove_photo'] == 1) {
    try {
        $checkColumn = $pdo->prepare("SHOW COLUMNS FROM employees LIKE 'profile_picture'");
        $checkColumn->execute();
        if ($checkColumn->rowCount() > 0) {
            $updateStmt = $pdo->prepare("UPDATE employees SET profile_picture = NULL WHERE employee_id = ?");
            $updateStmt->execute([$employee_id]);
        }
    } catch (PDOException $e) {}
    
    $files_to_delete = glob('uploads/profile_pictures/' . $employee_id . '.*');
    foreach ($files_to_delete as $file) {
        if (file_exists($file)) {
            unlink($file);
        }
    }
    
    header("Location: view_employee.php?id=" . $employee_id);
    exit;
}

// Get attendance records
$attendanceStmt = $pdo->prepare("
    SELECT * FROM attendance 
    WHERE employee_id = ? 
    ORDER BY date DESC, time_in DESC 
    LIMIT 10
");
$attendanceStmt->execute([$employee_id]);
$attendance = $attendanceStmt->fetchAll();

// Get schedules
$schedules = [];
try {
    $checkColumnStmt = $pdo->prepare("SHOW COLUMNS FROM schedules LIKE 'day'");
    $checkColumnStmt->execute();
    $hasDayColumn = $checkColumnStmt->rowCount() > 0;
    
    if ($hasDayColumn) {
        $scheduleStmt = $pdo->prepare("
            SELECT * FROM schedules 
            WHERE employee_id = ? 
            ORDER BY FIELD(day, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), 
                     IFNULL(start_time, '00:00:00')
        ");
    } else {
        $columnsStmt = $pdo->prepare("SHOW COLUMNS FROM schedules");
        $columnsStmt->execute();
        $columns = $columnsStmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (in_array('schedule_date', $columns)) {
            $scheduleStmt = $pdo->prepare("SELECT * FROM schedules WHERE employee_id = ? ORDER BY schedule_date DESC");
        } elseif (in_array('work_date', $columns)) {
            $scheduleStmt = $pdo->prepare("SELECT * FROM schedules WHERE employee_id = ? ORDER BY work_date DESC");
        } elseif (in_array('date', $columns)) {
            $scheduleStmt = $pdo->prepare("SELECT * FROM schedules WHERE employee_id = ? ORDER BY date DESC");
        } else {
            $scheduleStmt = $pdo->prepare("SELECT * FROM schedules WHERE employee_id = ?");
        }
    }
    
    $scheduleStmt->execute([$employee_id]);
    $schedules = $scheduleStmt->fetchAll();
    
} catch (PDOException $e) {
    $schedules = [];
    error_log("Schedules table error: " . $e->getMessage());
}

// Get statistics
$statsStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_attendance,
        COUNT(DISTINCT date) as total_days,
        MIN(time_in) as earliest_in,
        MAX(time_in) as latest_in
    FROM attendance 
    WHERE employee_id = ?
");
$statsStmt->execute([$employee_id]);
$stats = $statsStmt->fetch();

// Get today's attendance
$todayStmt = $pdo->prepare("
    SELECT * FROM attendance 
    WHERE employee_id = ? AND date = CURDATE()
");
$todayStmt->execute([$employee_id]);
$todayAttendance = $todayStmt->fetch();

// QR code existence check
function qrCodeExists($employee_id) {
    $paths = [
        'qrcodes/qr_' . $employee_id . '.png',
        '../qrcodes/qr_' . $employee_id . '.png',
        '/qrcodes/qr_' . $employee_id . '.png',
        $_SERVER['DOCUMENT_ROOT'] . '/qrcodes/qr_' . $employee_id . '.png',
    ];
    
    foreach ($paths as $path) {
        if (file_exists($path)) {
            if (strpos($path, $_SERVER['DOCUMENT_ROOT']) === 0) {
                return str_replace($_SERVER['DOCUMENT_ROOT'], '', $path);
            }
            return $path;
        }
    }
    return false;
}

// Profile picture function
function getProfilePicture($employee_id, $pdo) {
    try {
        $checkColumn = $pdo->prepare("SHOW COLUMNS FROM employees LIKE 'profile_picture'");
        $checkColumn->execute();
        $columnExists = $checkColumn->rowCount() > 0;
        
        if ($columnExists) {
            $stmt = $pdo->prepare("SELECT profile_picture FROM employees WHERE employee_id = ?");
            $stmt->execute([$employee_id]);
            $result = $stmt->fetch();
            
            if ($result && !empty($result['profile_picture'])) {
                $paths = [
                    'uploads/profile_pictures/' . $result['profile_picture'],
                    '../uploads/profile_pictures/' . $result['profile_picture'],
                ];
                foreach ($paths as $path) {
                    if (file_exists($path)) {
                        return $path;
                    }
                }
            }
        }
    } catch (PDOException $e) {}
    
    $possible_paths = [
        'uploads/profile_pictures/' . $employee_id . '.*',
        '../uploads/profile_pictures/' . $employee_id . '.*',
    ];
    
    foreach ($possible_paths as $path_pattern) {
        $files = glob($path_pattern);
        if (!empty($files)) {
            return $files[0];
        }
    }
    return false;
}

// Format currency
function formatPHP($amount) {
    if ($amount === null || $amount === '') {
        return '₱0.00';
    }
    return '₱' . number_format(floatval($amount), 2);
}

$profilePicPath = getProfilePicture($employee_id, $pdo);
$qrPath = qrCodeExists($employee_id);
?>
<!DOCTYPE html>
<html>
<head>
    <title>View Employee - Attendance System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        /* your existing styles - unchanged */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        .header {
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
        .back-btn {
            padding: 10px 20px;
            background: #6c757d;
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .back-btn:hover { background: #5a6268; transform: translateY(-2px); }
        .qr-btn {
            padding: 10px 20px;
            background: #28a745;
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .qr-btn:hover { background: #218838; transform: translateY(-2px); }
        .employee-card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .employee-header {
            display: flex;
            align-items: center;
            gap: 30px;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
            flex-wrap: wrap;
        }
        .profile-section {
            display: flex;
            align-items: center;
            gap: 30px;
            flex-wrap: wrap;
        }
        .avatar-container {
            position: relative;
            width: 120px;
            height: 120px;
            border-radius: 50%;
            cursor: pointer;
            overflow: hidden;
            flex-shrink: 0;
        }
        .profile-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }
        .employee-avatar {
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3.5em;
            font-weight: bold;
            color: white;
            text-transform: uppercase;
        }
        .avatar-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.6);
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
            color: white;
        }
        .avatar-container:hover .avatar-overlay { opacity: 1; }
        .camera-icon { font-size: 1.8em; margin-bottom: 5px; }
        .avatar-overlay span:last-child { font-size: 0.75em; font-weight: 500; }
        .employee-info h1 { color: #333; font-size: 2.5em; margin-bottom: 10px; }
        .employee-info .position { color: #666; font-size: 1.2em; margin-bottom: 5px; }
        .employee-info .id-badge {
            background: #667eea;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            display: inline-block;
            font-weight: 600;
        }
        .today-status {
            background: #e3f2fd;
            border-left: 4px solid #1976d2;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        .today-status h3 { color: #1976d2; margin-bottom: 10px; }
        .time-display { font-size: 1.3em; color: #333; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 15px;
            text-align: center;
            transition: transform 0.3s;
        }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.1); }
        .stat-card h3 { font-size: 2.5em; color: #667eea; margin-bottom: 5px; }
        .stat-card p { color: #666; font-weight: 500; }
        .stat-card.salary-card h3 { color: #28a745; }
        .section-title {
            font-size: 1.5em;
            color: #333;
            margin: 30px 0 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #667eea;
        }
        .table-container {
            background: white;
            border-radius: 15px;
            padding: 20px;
            overflow-x: auto;
            margin-bottom: 30px;
        }
        table { width: 100%; border-collapse: collapse; min-width: 600px; }
        th { background: #667eea; color: white; padding: 12px; font-weight: 600; }
        td { padding: 12px; border-bottom: 1px solid #e0e0e0; }
        tr:hover { background: #f8f9fa; }
        .status-badge { padding: 5px 10px; border-radius: 5px; font-size: 0.9em; font-weight: 600; }
        .status-present { background: #d4edda; color: #155724; }
        .status-working { background: #fff3cd; color: #856404; }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .info-card {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 20px;
        }
        .info-item {
            display: flex;
            margin-bottom: 10px;
            padding: 8px 0;
            border-bottom: 1px dashed #e0e0e0;
        }
        .info-label { width: 140px; color: #666; font-weight: 500; }
        .info-value { flex: 1; color: #333; font-weight: 600; }
        .info-value.salary-amount { color: #28a745; font-size: 1.1em; font-weight: 700; }
        .no-data { text-align: center; padding: 40px; color: #999; font-style: italic; }
        .edit-btn {
            padding: 10px 20px;
            background: #ffc107;
            color: #333;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .edit-btn:hover { background: #e0a800; transform: translateY(-2px); }
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        .modal-content {
            background: white;
            border-radius: 20px;
            padding: 30px;
            max-width: 450px;
            width: 90%;
            text-align: center;
            animation: modalSlideIn 0.3s ease;
        }
        @keyframes modalSlideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .modal-content h2 { color: #333; margin-bottom: 20px; }
        .modal-content .current-photo {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            margin: 20px auto;
            border: 3px solid #667eea;
            display: block;
        }
        .modal-content .current-photo-placeholder {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 4em;
            font-weight: bold;
            color: white;
            margin: 20px auto;
            border: 3px solid #667eea;
        }
        .upload-form { margin: 20px 0; }
        .upload-form .file-input-wrapper {
            position: relative;
            display: inline-block;
            margin: 15px 0;
        }
        .upload-form input[type="file"] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        .upload-form .file-input-label {
            display: inline-block;
            padding: 12px 25px;
            background: #667eea;
            color: white;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        .upload-form .file-input-label:hover { background: #5a67d8; transform: translateY(-2px); }
        .modal-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 20px;
        }
        .modal-buttons button {
            padding: 12px 30px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        .modal-buttons .btn-upload { background: #28a745; color: white; }
        .modal-buttons .btn-upload:hover { background: #218838; }
        .modal-buttons .btn-close { background: #6c757d; color: white; }
        .modal-buttons .btn-close:hover { background: #5a6268; }
        .remove-photo-btn {
            background: #dc3545;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 10px;
            transition: all 0.3s;
        }
        .remove-photo-btn:hover { background: #c82333; }
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 10px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #28a745;
        }
        #qrModal {
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
        .qr-modal-content {
            background: white;
            border-radius: 20px;
            padding: 30px;
            max-width: 400px;
            width: 90%;
            text-align: center;
            animation: modalSlideIn 0.3s ease;
            position: relative;
        }
        .qr-modal-content h3 { color: #333; margin-bottom: 10px; }
        .qr-modal-content .qr-image { max-width: 250px; margin: 20px auto; display: block; }
        .qr-modal-content .close-btn {
            margin-top: 20px;
            padding: 10px 30px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }
        .qr-modal-content .close-btn:hover { background: #5a67d8; }
        .qr-modal-content .employee-name { font-weight: 600; color: #333; }
        .qr-modal-content .employee-id { color: #667eea; }
        @media (max-width: 768px) {
            .employee-header { flex-direction: column; text-align: center; }
            .profile-section { flex-direction: column; text-align: center; }
            .avatar-container { width: 100px; height: 100px; }
            .employee-avatar { width: 100px; height: 100px; font-size: 2.5em; }
            .info-item { flex-direction: column; }
            .info-label { width: 100%; margin-bottom: 5px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <a href="employees.php" class="back-btn">← Back to Employees</a>
            <div>
                <a href="edit_employee.php?id=<?php echo $employee_id; ?>" class="edit-btn" style="margin-right: 10px;">✏️ Edit Employee</a>
                <?php if ($qrPath): ?>
                    <a href="javascript:void(0)" onclick="showQRCode('<?php echo $employee_id; ?>', '<?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?>')" class="qr-btn">📱 View QR Code</a>
                <?php else: ?>
                    <a href="qr_generator.php?employee_id=<?php echo $employee_id; ?>" class="qr-btn" style="background: #fd7e14;">➕ Generate QR Code</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Success Message -->
        <?php if (isset($_GET['success'])): ?>
            <div class="success-message">✅ Profile picture updated successfully!</div>
        <?php endif; ?>

        <!-- Employee Card -->
        <div class="employee-card">
            <div class="employee-header">
                <div class="profile-section">
                    <div class="avatar-container" onclick="openProfileModal()">
                        <?php if ($profilePicPath && file_exists($profilePicPath)): ?>
                            <img src="<?php echo $profilePicPath; ?>" alt="Profile Picture" class="profile-img">
                        <?php else: ?>
                            <div class="employee-avatar">
                                <?php echo strtoupper(substr($employee['first_name'] ?? '', 0, 1) . substr($employee['last_name'] ?? '', 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                        <div class="avatar-overlay">
                            <span class="camera-icon">📷</span>
                            <span>Change Photo</span>
                        </div>
                    </div>
                    <div class="employee-info">
                        <h1><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></h1>
                        <p class="position"><?php echo htmlspecialchars($employee['position'] ?? 'No Position'); ?> • <?php echo htmlspecialchars($employee['department'] ?? 'No Department'); ?></p>
                        <span class="id-badge">ID: <?php echo htmlspecialchars($employee['employee_id']); ?></span>
                    </div>
                </div>
            </div>

            <!-- Today's Status -->
            <div class="today-status">
                <h3>Today's Status: <?php echo $todayAttendance ? '✅ Present' : '⏳ Not yet checked in'; ?></h3>
                <?php if ($todayAttendance): ?>
                    <div class="time-display">
                        Time In: <?php echo $todayAttendance['time_in'] ? date('h:i A', strtotime($todayAttendance['time_in'])) : '-'; ?><br>
                        Time Out: <?php echo $todayAttendance['time_out'] ? date('h:i A', strtotime($todayAttendance['time_out'])) : 'Still working'; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <h3><?php echo $stats['total_attendance'] ?? 0; ?></h3>
                    <p>Total Attendance</p>
                </div>
                <div class="stat-card">
                    <h3><?php echo $stats['total_days'] ?? 0; ?></h3>
                    <p>Days Present</p>
                </div>
                <div class="stat-card">
                    <h3><?php echo count($schedules); ?></h3>
                    <p>Active Schedules</p>
                </div>
                <div class="stat-card salary-card">
                    <h3><?php echo formatPHP($employee['salary'] ?? 0); ?></h3>
                    <p>Monthly Salary</p>
                </div>
            </div>

            <!-- Employee Information Grid -->
            <h2 class="section-title">📋 Employee Information</h2>
            <div class="info-grid">
                <div class="info-card">
                    <h3 style="margin-bottom: 15px; color: #333;">Personal Details</h3>
                    <div class="info-item">
                        <span class="info-label">Full Name:</span>
                        <span class="info-value"><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Email:</span>
                        <span class="info-value"><?php echo htmlspecialchars($employee['email'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Phone:</span>
                        <span class="info-value"><?php echo htmlspecialchars($employee['phone'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Address:</span>
                        <span class="info-value"><?php echo htmlspecialchars($employee['address'] ?? 'N/A'); ?></span>
                    </div>
                </div>

                <div class="info-card">
                    <h3 style="margin-bottom: 15px; color: #333;">Employment Details</h3>
                    <div class="info-item">
                        <span class="info-label">Employee ID:</span>
                        <span class="info-value"><?php echo htmlspecialchars($employee['employee_id']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Position:</span>
                        <span class="info-value"><?php echo htmlspecialchars($employee['position'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Department:</span>
                        <span class="info-value"><?php echo htmlspecialchars($employee['department'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Hire Date:</span>
                        <span class="info-value"><?php echo $employee['hire_date'] ? date('F d, Y', strtotime($employee['hire_date'])) : 'N/A'; ?></span>
                    </div>
                </div>

                <div class="info-card">
                    <h3 style="margin-bottom: 15px; color: #333;">Salary & Contact</h3>
                    <div class="info-item">
                        <span class="info-label">Monthly Salary:</span>
                        <span class="info-value salary-amount"><?php echo formatPHP($employee['salary'] ?? 0); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Birth Date:</span>
                        <span class="info-value"><?php echo $employee['birth_date'] ? date('F d, Y', strtotime($employee['birth_date'])) : 'N/A'; ?></span>
                    </div>
                    <!-- ====== FIXED EMERGENCY CONTACT SECTION ====== -->
                    <div class="info-item">
                        <span class="info-label">Emergency Contact:</span>
                        <span class="info-value"><?php echo htmlspecialchars($employee['emergency_contact_name'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Emergency Phone:</span>
                        <span class="info-value"><?php echo htmlspecialchars($employee['emergency_contact_phone'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Relationship:</span>
                        <span class="info-value"><?php echo htmlspecialchars($employee['emergency_contact_relationship'] ?? 'N/A'); ?></span>
                    </div>
                    <!-- ========================================== -->
                </div>
            </div>

            <!-- Recent Attendance -->
            <h2 class="section-title">📊 Recent Attendance</h2>
            <div class="table-container">
                <?php if (empty($attendance)): ?>
                    <div class="no-data">No attendance records found</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th>Status</th>
                                <th>Hours</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attendance as $record): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($record['date'])); ?></td>
                                    <td><?php echo $record['time_in'] ? date('h:i A', strtotime($record['time_in'])) : '-'; ?></td>
                                    <td><?php echo $record['time_out'] ? date('h:i A', strtotime($record['time_out'])) : '-'; ?></td>
                                    <td>
                                        <?php if ($record['time_in'] && !$record['time_out']): ?>
                                            <span class="status-badge status-working">Working</span>
                                        <?php elseif ($record['time_in'] && $record['time_out']): ?>
                                            <span class="status-badge status-present">Completed</span>
                                        <?php else: ?>
                                            <span class="status-badge">Absent</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        if ($record['time_in'] && $record['time_out']) {
                                            $start = strtotime($record['time_in']);
                                            $end = strtotime($record['time_out']);
                                            $hours = round(($end - $start) / 3600, 1);
                                            echo $hours . ' hrs';
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Schedules -->
            <h2 class="section-title">📅 Schedules</h2>
            <div class="table-container">
                <?php if (empty($schedules)): ?>
                    <div class="no-data">No schedules found</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Day/Date</th>
                                <th>Start Time</th>
                                <th>End Time</th>
                                <th>Type</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($schedules as $schedule): ?>
                                <tr>
                                    <td>
                                        <?php 
                                        if (isset($schedule['day'])) {
                                            echo htmlspecialchars($schedule['day']);
                                        } elseif (isset($schedule['schedule_date'])) {
                                            echo date('M d, Y', strtotime($schedule['schedule_date']));
                                        } elseif (isset($schedule['work_date'])) {
                                            echo date('M d, Y', strtotime($schedule['work_date']));
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        if (isset($schedule['start_time'])) {
                                            echo date('h:i A', strtotime($schedule['start_time']));
                                        } elseif (isset($schedule['time_start'])) {
                                            echo date('h:i A', strtotime($schedule['time_start']));
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        if (isset($schedule['end_time'])) {
                                            echo date('h:i A', strtotime($schedule['end_time']));
                                        } elseif (isset($schedule['time_end'])) {
                                            echo date('h:i A', strtotime($schedule['time_end']));
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($schedule['schedule_type'] ?? $schedule['type'] ?? 'Regular'); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Profile Picture Modal -->
    <div id="profileModal" class="modal-overlay">
        <div class="modal-content">
            <h2>📸 Change Profile Picture</h2>
            <?php if ($profilePicPath && file_exists($profilePicPath)): ?>
                <img id="profilePreview" src="<?php echo $profilePicPath; ?>" alt="Current Profile" class="current-photo">
                <div id="profilePlaceholder" style="display:none;"></div>
            <?php else: ?>
                <div id="profilePlaceholder" class="current-photo-placeholder">
                    <?php echo strtoupper(substr($employee['first_name'] ?? '', 0, 1) . substr($employee['last_name'] ?? '', 0, 1)); ?>
                </div>
                <img id="profilePreview" src="" alt="Profile Preview" class="current-photo" style="display:none;">
            <?php endif; ?>
            <form class="upload-form" action="view_employee.php?id=<?php echo $employee_id; ?>" method="POST" enctype="multipart/form-data">
                <div class="file-input-wrapper">
                    <span class="file-input-label">📁 Choose Photo</span>
                    <input type="file" id="profileFileInput" name="profile_picture" accept="image/*" onchange="updateProfilePreview()">
                </div>
                <p style="color:#666; font-size:0.9em; margin:5px 0;">Supported formats: JPG, PNG, GIF, WebP (Max 5MB)</p>
                <div class="modal-buttons">
                    <button type="submit" class="btn-upload">📤 Upload Photo</button>
                    <button type="button" class="btn-close" onclick="closeProfileModal()">Close</button>
                </div>
            </form>
            <?php if ($profilePicPath && file_exists($profilePicPath)): ?>
                <button class="remove-photo-btn" onclick="removeProfilePhoto()">🗑️ Remove Photo</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- QR Code Modal -->
    <div id="qrModal">
        <div class="qr-modal-content">
            <h3>📱 Employee QR Code</h3>
            <p style="color:#666; font-size:0.9em;">Scan this QR code to mark attendance</p>
            <img id="modalQRImage" src="" alt="QR Code" class="qr-image">
            <p class="employee-name" id="modalEmployeeName"></p>
            <p class="employee-id" id="modalEmployeeId"></p>
            <button class="close-btn" onclick="closeQRModal()">Close</button>
        </div>
    </div>

    <script>
        // Existing JavaScript functions (unchanged)
        function showQRCode(employeeId, employeeName) {
            const modal = document.getElementById('qrModal');
            const possiblePaths = [
                'qrcodes/qr_' + employeeId + '.png',
                '../qrcodes/qr_' + employeeId + '.png',
                '/qrcodes/qr_' + employeeId + '.png'
            ];
            let imageLoaded = false;
            function tryLoadImage(path) {
                const img = new Image();
                img.onload = function() {
                    if (!imageLoaded) {
                        imageLoaded = true;
                        document.getElementById('modalQRImage').src = path + '?t=' + new Date().getTime();
                        document.getElementById('modalEmployeeName').textContent = employeeName;
                        document.getElementById('modalEmployeeId').textContent = 'ID: ' + employeeId;
                        modal.style.display = 'flex';
                    }
                };
                img.onerror = function() {
                    const nextIndex = possiblePaths.indexOf(path) + 1;
                    if (nextIndex < possiblePaths.length) {
                        tryLoadImage(possiblePaths[nextIndex]);
                    } else {
                        document.getElementById('modalQRImage').style.display = 'none';
                        document.getElementById('modalEmployeeName').textContent = employeeName;
                        document.getElementById('modalEmployeeId').textContent = 'ID: ' + employeeId;
                        document.querySelector('.qr-modal-content p:first-of-type').textContent = '⚠️ QR Code not found. Please generate one first.';
                        modal.style.display = 'flex';
                    }
                };
                img.src = path + '?t=' + new Date().getTime();
            }
            document.getElementById('modalQRImage').style.display = 'block';
            document.querySelector('.qr-modal-content p:first-of-type').textContent = 'Scan this QR code to mark attendance';
            tryLoadImage(possiblePaths[0]);
        }

        function closeQRModal() {
            document.getElementById('qrModal').style.display = 'none';
        }

        function openProfileModal() {
            document.getElementById('profileModal').style.display = 'flex';
        }

        function closeProfileModal() {
            document.getElementById('profileModal').style.display = 'none';
        }

        function updateProfilePreview() {
            const fileInput = document.getElementById('profileFileInput');
            const preview = document.getElementById('profilePreview');
            const placeholder = document.getElementById('profilePlaceholder');
            if (fileInput.files && fileInput.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                    placeholder.style.display = 'none';
                };
                reader.readAsDataURL(fileInput.files[0]);
            }
        }

        function removeProfilePhoto() {
            if (confirm('Are you sure you want to remove your profile picture?')) {
                window.location.href = 'view_employee.php?id=<?php echo $employee_id; ?>&remove_photo=1';
            }
        }

        window.onclick = function(event) {
            const modal = document.getElementById('profileModal');
            if (event.target == modal) modal.style.display = 'none';
            const qrModal = document.getElementById('qrModal');
            if (event.target == qrModal) qrModal.style.display = 'none';
        }

        window.onload = function() {
            const successMsg = document.querySelector('.success-message');
            if (successMsg) {
                setTimeout(() => { successMsg.style.display = 'none'; }, 5000);
            }
        }
    </script>
</body>
</html>