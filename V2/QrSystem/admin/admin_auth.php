<?php
// admin_auth.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_id']) || empty($_SESSION['admin_id'])) {
    // Prevent caching of protected pages
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");

    // Redirect to login
    header("Location: admin_login.php");
    exit;
}