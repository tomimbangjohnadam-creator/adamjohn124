<?php
require_once 'admin_auth.php'; 
require_once 'configa.php';

header('Content-Type: application/json');

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$search = $_GET['q'] ?? '';

if (strlen($search) < 2) {
    echo json_encode(['success' => false, 'message' => 'Search term too short', 'employees' => []]);
    exit;
}

try {
    $searchTerm = "%$search%";
    
    $stmt = $pdo->prepare("SELECT employee_id, first_name, last_name, position, department, email 
                           FROM employees 
                           WHERE employee_id LIKE ? 
                           OR first_name LIKE ? 
                           OR last_name LIKE ? 
                           OR position LIKE ? 
                           OR department LIKE ? 
                           ORDER BY first_name LIMIT 20");
    
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Add initials for each employee
    foreach ($employees as &$emp) {
        $emp['initials'] = strtoupper(substr($emp['first_name'] ?? '', 0, 1) . substr($emp['last_name'] ?? '', 0, 1));
    }
    
    echo json_encode(['success' => true, 'employees' => $employees]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>