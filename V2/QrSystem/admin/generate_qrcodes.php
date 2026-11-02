<?php
require_once 'admin_auth.php'; 
require_once 'configa.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

$message = '';
$message_type = '';

// Handle QR code generation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generate_qr'])) {
    $employee_id = isset($_POST['employee_id']) ? $_POST['employee_id'] : '';
    $custom_data = isset($_POST['custom_data']) ? $_POST['custom_data'] : '';
    $file_name = isset($_POST['file_name']) ? $_POST['file_name'] : 'qrcode';
    $size = isset($_POST['size']) ? $_POST['size'] : 300;
    
    if (!empty($employee_id)) {
        $stmt = $pdo->prepare("SELECT employee_id, first_name, last_name, position FROM employees WHERE employee_id = ?");
        $stmt->execute([$employee_id]);
        $emp = $stmt->fetch();
        
        if ($emp) {
            $qr_data = "EMPLOYEE_ID:" . $emp['employee_id'] . "|NAME:" . $emp['first_name'] . " " . $emp['last_name'] . "|TYPE:attendance";
            $default_filename = 'qr_' . $emp['employee_id'];
        } else {
            $message = "Employee not found";
            $message_type = "error";
        }
    } else if (!empty($custom_data)) {
        $qr_data = $custom_data;
        $default_filename = preg_replace('/[^a-zA-Z0-9]/', '_', substr($custom_data, 0, 20));
    } else {
        $message = "Please select an employee or enter custom data";
        $message_type = "error";
    }
    
    if (empty($message) && isset($qr_data)) {
        $filename = !empty($file_name) ? $file_name : $default_filename;
        $filename = preg_replace('/[^a-zA-Z0-9_-]/', '', $filename);
        if (empty($filename)) $filename = 'qrcode';
        
        $qr_path = '../qrcodes/' . $filename . '.png';
        
        if (!is_dir('../qrcodes')) {
            mkdir('../qrcodes', 0777, true);
        }
        
        $api_url = "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$size}&data=" . urlencode($qr_data);
        
        $context = stream_context_create([
            'http' => ['timeout' => 15, 'user_agent' => 'Mozilla/5.0']
        ]);
        
        $qr_content = @file_get_contents($api_url, false, $context);
        
        if ($qr_content !== false && strlen($qr_content) > 100) {
            file_put_contents($qr_path, $qr_content);
            $message = "QR Code saved as: " . $filename . ".png";
            $message_type = "success";
        } else {
            $message = "Error generating QR code";
            $message_type = "error";
        }
    }
}

// Get employees with position
$employees = $pdo->query("SELECT employee_id, first_name, last_name, position FROM employees ORDER BY first_name")->fetchAll();

// Get existing QR codes
$qr_files = glob('../qrcodes/*.png');
$qr_list = array();
foreach ($qr_files as $file) {
    $qr_list[] = basename($file);
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>QR Code Generator</title>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            margin: 0;
            min-height: 100vh;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .header {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .header h1 {
            color: #333;
            margin: 0;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            text-decoration: none;
            color: white;
            cursor: pointer;
            display: inline-block;
            margin: 5px;
        }
        .btn-back { background: #6c757d; }
        .btn-primary { background: #28a745; }
        .btn-secondary { background: #17a2b8; }
        .btn-danger { background: #dc3545; }
        .btn-warning { background: #ffc107; color: #333; }
        
        .main-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .folder-info {
            background: #e3f2fd;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input, select, textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        textarea {
            min-height: 100px;
        }
        .filename-input {
            display: flex;
            gap: 10px;
        }
        .filename-input input {
            flex: 1;
        }
        .filename-input span {
            background: #f0f0f0;
            padding: 8px;
            border-radius: 4px;
        }
        .preview-box {
            background: #f8f9fa;
            border: 2px dashed #ddd;
            border-radius: 5px;
            padding: 20px;
            text-align: center;
            min-height: 300px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .alert {
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 15px;
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
        .qr-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 10px;
            max-height: 500px;
            overflow-y: auto;
        }
        .qr-item {
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 10px;
            text-align: center;
        }
        .qr-item img {
            width: 80px;
            height: 80px;
        }
        .qr-item p {
            font-size: 12px;
            word-break: break-all;
            margin: 5px 0;
        }
        .qr-actions {
            display: flex;
            gap: 5px;
            justify-content: center;
        }
        .qr-actions a {
            padding: 3px 8px;
            border-radius: 3px;
            text-decoration: none;
            color: white;
            font-size: 12px;
        }
        .view-btn { background: #28a745; }
        .download-btn { background: #17a2b8; }
        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        .button-group .btn {
            flex: 1;
            padding: 12px;
        }
        .position-badge {
            background: #e7f3ff;
            color: #004085;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            margin-left: 5px;
        }
        @media (max-width: 768px) {
            .main-content {
                grid-template-columns: 1fr;
            }
            .header {
                flex-direction: column;
                align-items: stretch;
            }
            .header .btn {
                width: 100%;
                text-align: center;
                margin: 2px 0;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📱 QR Code Generator</h1>
            <div>
                <a href="admin_dashboard.php" class="btn btn-back">Back</a>
                <a href="generate_qrcodes.php" class="btn btn-secondary">Refresh</a>
            </div>
        </div>

        <div class="main-content">
            <div class="card">
                <div class="folder-info">
                    <strong>Save to:</strong> /qrcodes/ folder
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <div class="form-group">
                        <label>Select Employee</label>
                        <select name="employee_id" id="employee_id">
                            <option value="">-- Custom QR --</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo $emp['employee_id']; ?>">
                                    <?php echo $emp['employee_id'] . ' - ' . $emp['first_name'] . ' ' . $emp['last_name']; ?>
                                    <span class="position-badge"><?php echo htmlspecialchars($emp['position'] ?? ''); ?></span>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Custom Data</label>
                        <textarea name="custom_data" id="custom_data">https://example.com</textarea>
                    </div>

                    <div class="form-group">
                        <label>Size</label>
                        <select name="size" id="size">
                            <option value="150">Small</option>
                            <option value="200">Medium</option>
                            <option value="300" selected>Large</option>
                        </select>
                    </div>

                    <div class="filename-input">
                        <input type="text" name="file_name" id="file_name" value="qrcode">
                        <span>.png</span>
                    </div>

                    <div class="button-group">
                        <button type="submit" name="generate_qr" class="btn btn-primary">Generate & Save</button>
                        <button type="button" class="btn btn-secondary" onclick="previewCode()">Preview</button>
                        <button type="button" class="btn btn-danger" onclick="clearForm()">Clear</button>
                    </div>
                </form>

                <div class="preview-box" id="preview_box">
                    <div>Preview will appear here</div>
                </div>
            </div>

            <div class="card">
                <h2>Saved QR Codes</h2>
                <div class="qr-grid">
                    <?php if (empty($qr_list)): ?>
                        <p>No QR codes found</p>
                    <?php else: ?>
                        <?php foreach ($qr_list as $file): ?>
                            <div class="qr-item">
                                <img src="../qrcodes/<?php echo $file; ?>?t=<?php echo time(); ?>" alt="">
                                <p><?php echo $file; ?></p>
                                <div class="qr-actions">
                                    <a href="../qrcodes/<?php echo $file; ?>" target="_blank" class="view-btn">View</a>
                                    <a href="../qrcodes/<?php echo $file; ?>" download class="download-btn">Download</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
    let qrPreview = null;
    
    function updateFileName() {
        var select = document.getElementById('employee_id');
        var input = document.getElementById('file_name');
        if (select.value) {
            input.value = 'qr_' + select.value;
        }
    }
    
    function previewCode() {
        var select = document.getElementById('employee_id');
        var custom = document.getElementById('custom_data');
        var size = document.getElementById('size').value;
        var data = '';
        
        if (select.value) {
            data = 'EMPLOYEE_ID:' + select.value + '|TYPE:attendance';
        } else if (custom.value) {
            data = custom.value;
        } else {
            alert('Please enter data');
            return;
        }
        
        var box = document.getElementById('preview_box');
        box.innerHTML = '';
        
        var div = document.createElement('div');
        div.id = 'qrcode';
        box.appendChild(div);
        
        new QRCode(div, {
            text: data,
            width: parseInt(size),
            height: parseInt(size)
        });
    }
    
    function clearForm() {
        document.getElementById('employee_id').value = '';
        document.getElementById('custom_data').value = 'https://example.com';
        document.getElementById('file_name').value = 'qrcode';
        document.getElementById('size').value = '300';
        document.getElementById('preview_box').innerHTML = '<div>Preview will appear here</div>';
    }
    
    document.getElementById('employee_id').addEventListener('change', updateFileName);
    </script>
</body>
</html>