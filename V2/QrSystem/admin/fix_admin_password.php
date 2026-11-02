<?php
require_once 'configa.php';

echo "<h2>🔧 Admin Password Fix Tool</h2>";

try {
    // First, check if admin exists
    $check = $pdo->query("SELECT * FROM admins WHERE username = 'admin'")->fetch();
    
    if ($check) {
        echo "<p>✅ Admin found in database</p>";
        echo "<p>Current password hash: " . ($check['password'] ? $check['password'] : 'EMPTY/NULL') . "</p>";
        
        // Generate new hash for 'admin123'
        $new_hash = password_hash('admin123', PASSWORD_DEFAULT);
        
        // Update the password
        $update = $pdo->prepare("UPDATE admins SET password = ? WHERE username = 'admin'");
        $update->execute([$new_hash]);
        
        echo "<p style='color: green; font-weight: bold;'>✅ Password updated successfully!</p>";
        echo "<p>New hash: " . $new_hash . "</p>";
        
    } else {
        echo "<p>❌ Admin not found. Creating new admin...</p>";
        
        // Create new admin
        $new_hash = password_hash('admin123', PASSWORD_DEFAULT);
        $insert = $pdo->prepare("INSERT INTO admins (username, password, email, full_name, role) VALUES (?, ?, ?, ?, ?)");
        $insert->execute(['admin', $new_hash, 'admin@attendance.com', 'System Administrator', 'super_admin']);
        
        echo "<p style='color: green; font-weight: bold;'>✅ Admin created successfully!</p>";
    }
    
    // Show credentials
    echo "<div style='background: #f0f8ff; padding: 20px; border-radius: 10px; margin: 20px 0; border: 2px solid #667eea;'>";
    echo "<h3>📋 Your Login Credentials</h3>";
    echo "<p><strong>Username:</strong> <code>admin</code></p>";
    echo "<p><strong>Password:</strong> <code>admin123</code></p>";
    echo "</div>";
    
    echo "<a href='admin_login.php' style='background: #667eea; color: white; padding: 12px 30px; text-decoration: none; border-radius: 10px; display: inline-block;'>🔑 Go to Login</a>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
}
?>