<?php
require_once 'admin_auth.php';
require_once 'configa.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

$month = $_GET['month'] ?? date('Y-m');

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="payroll_history_' . $month . '.csv"');

$output = fopen('php://output', 'w');

// Add headers
fputcsv($output, [
    'Employee ID', 'Employee Name', 'Position', 'Pay Period',
    'Work Days', 'Work Hours', 'Overtime Hours',
    'Gross Pay', 'PhilHealth', 'SSS', 'Pag-IBIG', 'Total Deductions',
    'Net Pay', 'Payment Date', 'Payment Method', 'Reference', 'Notes'
]);

// Get history records
$stmt = $pdo->prepare("
    SELECT ph.*, e.first_name, e.last_name, e.position
    FROM payroll_history ph
    JOIN employees e ON ph.employee_id = e.employee_id
    WHERE DATE_FORMAT(ph.paid_at, '%Y-%m') = ?
    ORDER BY ph.paid_at DESC
");
$stmt->execute([$month]);
$records = $stmt->fetchAll();

foreach ($records as $record) {
    fputcsv($output, [
        $record['employee_id'],
        $record['first_name'] . ' ' . $record['last_name'],
        $record['position'],
        date('F Y', strtotime($record['payroll_month'])),
        $record['work_days'],
        $record['work_hours'],
        $record['overtime_hours'],
        $record['gross_pay'],
        $record['philhealth_deduction'],
        $record['sss_deduction'],
        $record['pagibig_deduction'],
        $record['total_deductions'],
        $record['net_pay'],
        date('Y-m-d H:i:s', strtotime($record['paid_at'])),
        $record['payment_method'],
        $record['payment_reference'],
        $record['payment_notes']
    ]);
}

fclose($output);
exit;
?>