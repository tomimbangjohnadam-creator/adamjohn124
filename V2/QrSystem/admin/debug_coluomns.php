<?php
require_once 'configa.php';

echo "<!DOCTYPE html>
<html>
<head>
    <title>Fix Admin Login</title>
    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        h1 { color: #333; margin-bottom: 20px; }
        h2 { color: #667eea; margin: 20px 0 10px; }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 10px; margin: 10px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 10px; margin: 10px 0; }
        .info { background: #e7f3ff; color: #004085; padding: 15px; border-radius: 10px; margin: 10px 0; }
        table { border-collapse: collapse; width: 100%; margin: 10px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f2f2f2; }
        .btn { 
            background: #28a745; 
            color: white; 
            padding: 12px 30px; 
            border: none; 
            border-radius: 10px; 
            font-size: 16px; 
            font-weight: 600;
            cursor: pointer;
            margin: 10px 5px;
        }
        .btn:hover { background: #218838; }
        .btn-danger { background: #dc3545; }
        .btn-danger:hover { background: #c82333; }
    </style>
</head>
<body>
    <div class='container'>";

echo "<h1>🔧 Admin Login Fix Tool</h1>";

// Step 1: Check database connection
echo "<h2>Step 1: Database Connection</h2>";
try {
    echo "<div class='success'>✅ Connected to database successfully</div>";
} catch (Exception $e) {
    echo "<div class='error'>❌ Connection failed: " . $e->getMessage() . "</div>";
    exit;
}

// Step 2: Check if admins table exists
echo "<h2>Step 2: Check Admins Table</h2>";
try {
    $tables = $pdo->query("SHOW TABLES LIKE 'admins'")->rowCount();
    if ($tables > 0) {
        echo "<div class='success'>✅ Admins table exists</div>";
    } else {
        echo "<div class='error'>❌ Admins table does not exist! Creating...</div>";
        
        // Create admins table
        $sql = "CREATE TABLE IF NOT EXISTS admins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            email VARCHAR(100) UNIQUE,
            full_name VARCHAR(100),
            role ENUM('super_admin', 'admin') DEFAULT 'admin',
            last_login TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $pdo->exec($sql);
        echo "<div class='success'>✅ Admins table created</div>";
    }
} catch (Exception $e) {
    echo "<div class='error'>❌ Error: " . $e->getMessage() . "</div>";
}

// Step 3: Show table structure
echo "<h2>Step 3: Table Structure</h2>";
try {
    $columns = $pdo->query("SHOW COLUMNS FROM admins")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    foreach ($columns as $column) {
        echo "<tr>";
        echo "<td><strong>" . $column['Field'] . "</strong></td>";
        echo "<td>" . $column['Type'] . "</td>";
        echo "<td>" . $column['Null'] . "</td>";
        echo "<td>" . $column['Key'] . "</td>";
        echo "<td>" . ($column['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<div class='error'>❌ Error: " . $e->getMessage() . "</div>";
}

// Step 4: Check existing admins
echo "<h2>Step 4: Current Admin Records</h2>";
try {
    $admins = $pdo->query("SELECT * FROM admins")->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($admins) > 0) {
        echo "<table>";
        echo "<tr><th>ID</th><th>Username</th><th>Email</th><th>Full Name</th><th>Role</th><th>Last Login</th></tr>";
        foreach ($admins as $admin) {
            // Try to get username from any possible column
            $username = $admin['username'] ?? $admin['user_name'] ?? $admin['user'] ?? 'N/A';
            
            echo "<tr>";
            echo "<td>" . $admin['id'] . "</td>";
            echo "<td>" . htmlspecialchars($username) . "</td>";
            echo "<td>" . ($admin['email'] ?? 'N/A') . "</td>";
            echo "<td>" . ($admin['full_name'] ?? 'N/A') . "</td>";
            echo "<td>" . ($admin['role'] ?? 'admin') . "</td>";
            echo "<td>" . ($admin['last_login'] ?? 'Never') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<div class='info'>No admin records found</div>";
    }
} catch (Exception $e) {
    echo "<div class='error'>❌ Error: " . $e->getMessage() . "</div>";
}

// Step 5: Fix admin password
echo "<h2>Step 5: Fix Admin Password</h2>";

// Handle form submission
if (isset($_POST['create_admin'])) {
    $username = $_POST['new_username'] ?? 'admin';
    $password = $_POST['new_password'] ?? 'admin123';
    $email = $_POST['new_email'] ?? 'admin@attendance.com';
    $full_name = $_POST['new_fullname'] ?? 'System Administrator';
    
    // Generate password hash
    $hash = password_hash($password, PASSWORD_DEFAULT);
    
    // Detect username column
    $username_column = null;
    foreach ($columns as $col) {
        if (in_array($col['Field'], ['username', 'user_name', 'user'])) {
            $username_column = $col['Field'];
            break;
        }
    }
    
    if (!$username_column) {
        $username_column = 'username'; // Default
    }
    
    try {
        // Delete existing admin with same username
        $deleteStmt = $pdo->prepare("DELETE FROM admins WHERE $username_column = ?");
        $deleteStmt->execute([$username]);
        
        // Insert new admin
        $sql = "INSERT INTO admins ($username_column, password, email, full_name, role) VALUES (?, ?, ?, ?, 'super_admin')";
        $insertStmt = $pdo->prepare($sql);
        $insertStmt->execute([$username, $hash, $email, $full_name]);
        
        echo "<div class='success'>✅ Admin user created successfully!</div>";
        echo "<div class='info'>";
        echo "<strong>Login Credentials:</strong><br>";
        echo "Username: " . $username . "<br>";
        echo "Password: " . $password . "<br>";
        echo "</div>";
        
    } catch (Exception $e) {
        echo "<div class='error'>❌ Error creating admin: " . $e->getMessage() . "</div>";
    }
}

if (isset($_POST['reset_password'])) {
    $admin_id = $_POST['admin_id'] ?? 0;
    $new_password = $_POST['reset_password_value'] ?? 'admin123';
    
    $hash = password_hash($new_password, PASSWORD_DEFAULT);
    
    try {
        $updateStmt = $pdo->prepare("UPDATE admins SET password = ? WHERE id = ?");
        $updateStmt->execute([$hash, $admin_id]);
        
        echo "<div class='success'>✅ Password reset successfully for admin ID: $admin_id</div>";
        echo "<div class='info'>New password: " . $new_password . "</div>";
        
    } catch (Exception $e) {
        echo "<div class='error'>❌ Error resetting password: " . $e->getMessage() . "</div>";
    }
}

if (isset($_POST['test_login'])) {
    $test_username = $_POST['test_username'] ?? 'admin';
    $test_password = $_POST['test_password'] ?? 'admin123';
    
    // Detect username column
    $username_column = null;
    foreach ($columns as $col) {
        if (in_array($col['Field'], ['username', 'user_name', 'user'])) {
            $username_column = $col['Field'];
            break;
        }
    }
    
    if (!$username_column) {
        $username_column = 'username';
    }
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE $username_column = ?");
        $stmt->execute([$test_username]);
        $admin = $stmt->fetch();
        
        if ($admin) {
            echo "<div class='success'>✅ Username '$test_username' found in database</div>";
            
            if (password_verify($test_password, $admin['password'])) {
                echo "<div class='success'>✅ Password verification successful! You should be able to login.</div>";
            } else {
                echo "<div class='error'>❌ Password verification failed. The password hash doesn't match '$test_password'</div>";
                echo "Stored hash: " . $admin['password'] . "<br>";
                
                // Generate correct hash for testing
                $correct_hash = password_hash($test_password, PASSWORD_DEFAULT);
                echo "Expected hash for '$test_password': " . $correct_hash . "<br>";
            }
        } else {
            echo "<div class='error'>❌ Username '$test_username' not found in database</div>";
        }
    } catch (Exception $e) {
        echo "<div class='error'>❌ Error: " . $e->getMessage() . "</div>";
    }
}

// Show forms
echo "<h2>Step 6: Actions</h2>";

// Form to create new admin
echo "<div style='background: #f8f9fa; padding: 20px; border-radius: 10px; margin: 20px 0;'>";
echo "<h3>Create New Admin</h3>";
echo "<form method='POST'>";
echo "<input type='text' name='new_username' placeholder='Username' value='admin' style='padding: 8px; margin: 5px; width: 200px;'><br>";
echo "<input type='password' name='new_password' placeholder='Password' value='admin123' style='padding: 8px; margin: 5px; width: 200px;'><br>";
echo "<input type='email' name='new_email' placeholder='Email' value='admin@attendance.com' style='padding: 8px; margin: 5px; width: 200px;'><br>";
echo "<input type='text' name='new_fullname' placeholder='Full Name' value='System Administrator' style='padding: 8px; margin: 5px; width: 200px;'><br>";
echo "<button type='submit' name='create_admin' class='btn'>Create Admin</button>";
echo "</form>";
echo "</div>";

// Form to test login
echo "<div style='background: #f8f9fa; padding: 20px; border-radius: 10px; margin: 20px 0;'>";
echo "<h3>Test Login Credentials</h3>";
echo "<form method='POST'>";
echo "<input type='text' name='test_username' placeholder='Username' value='admin' style='padding: 8px; margin: 5px; width: 200px;'><br>";
echo "<input type='password' name='test_password' placeholder='Password' value='admin123' style='padding: 8px; margin: 5px; width: 200px;'><br>";
echo "<button type='submit' name='test_login' class='btn'>Test Login</button>";
echo "</form>";
echo "</div>";

// If there are admins, show reset form for each
if (!empty($admins)) {
    echo "<div style='background: #f8f9fa; padding: 20px; border-radius: 10px; margin: 20px 0;'>";
    echo "<h3>Reset Password for Existing Admin</h3>";
    foreach ($admins as $admin) {
        $username = $admin['username'] ?? $admin['user_name'] ?? $admin['user'] ?? 'Unknown';
        echo "<form method='POST' style='margin: 10px 0; padding: 10px; background: white; border-radius: 5px;'>";
        echo "<input type='hidden' name='admin_id' value='" . $admin['id'] . "'>";
        echo "<strong>ID: " . $admin['id'] . " - Username: " . htmlspecialchars($username) . "</strong><br>";
        echo "<input type='password' name='reset_password_value' placeholder='New Password' value='admin123' style='padding: 5px; width: 150px;'>";
        echo "<button type='submit' name='reset_password' class='btn' style='padding: 5px 15px; font-size: 14px;'>Reset Password</button>";
        echo "</form>";
    }
    echo "</div>";
}

echo "<div style='margin-top: 30px;'>";
echo "<a href='admin_login.php' style='background: #667eea; color: white; padding: 12px 30px; text-decoration: none; border-radius: 10px; display: inline-block;'>Go to Login Page</a>";
echo "</div>";

echo "</div></body></html>";
?>