<?php

date_default_timezone_set('Asia/Manila');

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'attendance_system');
define('DB_USER', 'root');
define('DB_PASS', '');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Site configuration
define('SITE_NAME', 'QR Attendance System');
define('QR_CODE_PATH', 'qrcodes/');

// Create QR code directory if not exists
if (!file_exists(QR_CODE_PATH)) {
    mkdir(QR_CODE_PATH, 0777, true);
}

// Function to sanitize input
function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)));
}

// Function to generate employee ID
function generateEmployeeID($pdo) {
    do {
        $id = 'EMP' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("SELECT id FROM employees WHERE employee_id = ?");
        $stmt->execute([$id]);
    } while ($stmt->rowCount() > 0);
    
    return $id;
}
?>