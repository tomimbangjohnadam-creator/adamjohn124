<?php
session_start();
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['employee_id'])) {
    header('Location: login.php');
    exit();
}

// Handle Add Holiday
if (isset($_POST['add_holiday'])) {
    $holiday_date = $_POST['holiday_date'] ?? '';
    $holiday_name = $_POST['holiday_name'] ?? '';
    $holiday_type = $_POST['holiday_type'] ?? 'regular';
    
    if ($holiday_date && $holiday_name) {
        try {
            $stmt = $pdo->prepare("INSERT INTO holidays (holiday_date, holiday_name, holiday_type) 
                                   VALUES (?, ?, ?)");
            $stmt->execute([$holiday_date, $holiday_name, $holiday_type]);
            $_SESSION['success'] = "Holiday added successfully!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error adding holiday: " . $e->getMessage();
        }
    }
    
    // Redirect back to the schedule page
    $redirect_url = 'employee_schedule.php';
    if (isset($_GET['month'])) {
        $redirect_url .= '?month=' . $_GET['month'];
    }
    if (isset($_GET['view_employee'])) {
        $redirect_url .= (strpos($redirect_url, '?') === false ? '?' : '&') . 'view_employee=' . $_GET['view_employee'];
    }
    header('Location: ' . $redirect_url);
    exit();
}

// Handle Delete Holiday
if (isset($_POST['delete_holiday'])) {
    $holiday_date = $_POST['delete_holiday'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM holidays WHERE holiday_date = ?");
        $stmt->execute([$holiday_date]);
        $_SESSION['success'] = "Holiday deleted successfully!";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error deleting holiday: " . $e->getMessage();
    }
    
    // Redirect back to the schedule page
    $redirect_url = 'employee_schedule.php';
    if (isset($_GET['month'])) {
        $redirect_url .= '?month=' . $_GET['month'];
    }
    if (isset($_GET['view_employee'])) {
        $redirect_url .= (strpos($redirect_url, '?') === false ? '?' : '&') . 'view_employee=' . $_GET['view_employee'];
    }
    header('Location: ' . $redirect_url);
    exit();
}

// If no action, redirect to schedule
header('Location: employee_schedule.php');
exit();
?>