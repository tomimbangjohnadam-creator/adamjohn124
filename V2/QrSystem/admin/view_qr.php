<?php
require_once 'admin_auth.php'; 
require_once 'configa.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

$employee_id = $_GET['employee_id'] ?? '';

if (empty($employee_id)) {
    header("Location: generate_qrcodes.php");
    exit;
}

// Get employee details
$stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ?");
$stmt->execute([$employee_id]);
$employee = $stmt->fetch();

if (!$employee) {
    header("Location: generate_qrcodes.php?error=notfound");
    exit;
}

// Define QR code path in qrcodes folder
$qr_filename = 'qr_' . $employee['employee_id'] . '.png';
$qr_path = '../qrcodes/' . $qr_filename;
$qr_url = '../qrcodes/' . $qr_filename;

// Function to generate QR code using multiple APIs
function generateQRCodeWithFallback($data, $filepath) {
    // List of QR code APIs to try (in order of reliability)
    $apis = [
        [
            'url' => "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($data),
            'name' => 'QR Server'
        ],
        [
            'url' => "https://quickchart.io/qr?text=" . urlencode($data) . "&size=300&margin=1",
            'name' => 'QuickChart'
        ],
        [
            'url' => "https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=" . urlencode($data) . "&choe=UTF-8",
            'name' => 'Google Charts'
        ]
    ];
    
    // Create stream context with timeout and SSL options
    $context = stream_context_create([
        'http' => [
            'timeout' => 10, // 10 seconds timeout
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; rv:91.0) Gecko/20100101 Firefox/91.0'
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);
    
    // Try each API
    foreach ($apis as $api) {
        try {
            $qr_content = @file_get_contents($api['url'], false, $context);
            
            // Check if we got a valid image (PNG starts with �PNG)
            if ($qr_content !== false && strpos($qr_content, '�PNG') !== false) {
                file_put_contents($filepath, $qr_content);
                return ['success' => true, 'api' => $api['name']];
            }
        } catch (Exception $e) {
            // Continue to next API
            continue;
        }
    }
    
    // If all APIs fail, create a simple HTML fallback
    return ['success' => false, 'api' => 'none'];
}

// Check if QR exists, if not generate it
$generation_status = null;
if (!file_exists($qr_path)) {
    // Create qrcodes directory if it doesn't exist
    if (!is_dir('../qrcodes')) {
        mkdir('../qrcodes', 0777, true);
    }
    
    // Generate QR code data - simple format for easy scanning
    $qr_data = "EMPLOYEE_ID:" . $employee['employee_id'] . "|NAME:" . $employee['first_name'] . " " . $employee['last_name'] . "|TYPE:attendance";
    
    // Generate QR code with fallback
    $generation_status = generateQRCodeWithFallback($qr_data, $qr_path);
}

// Get admin info
$admin_name = $_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? 'Admin';
?>

<!DOCTYPE html>
<html>
<head>
    <title>QR Code - <?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></title>
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
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .qr-container {
            max-width: 600px;
            width: 100%;
            margin: 0 auto;
        }

        .qr-card {
            background: white;
            border-radius: 30px;
            padding: 40px;
            box-shadow: 0 30px 60px rgba(0,0,0,0.3);
            text-align: center;
        }

        .header {
            margin-bottom: 30px;
        }

        .header h1 {
            color: #333;
            font-size: 2em;
            margin-bottom: 10px;
        }

        .header p {
            color: #666;
        }

        .employee-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 20px;
            margin-bottom: 30px;
        }

        .employee-info h2 {
            font-size: 1.8em;
            margin-bottom: 5px;
        }

        .employee-info .id {
            font-size: 1.2em;
            opacity: 0.9;
            margin-bottom: 10px;
        }

        .employee-info .position {
            background: rgba(255,255,255,0.2);
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.9em;
        }

        .qr-image {
            margin: 30px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 20px;
            display: inline-block;
            min-height: 300px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .qr-image img {
            max-width: 300px;
            width: 100%;
            height: auto;
            image-rendering: pixelated;
        }

        .qr-details {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 15px;
            margin: 20px 0;
            text-align: left;
        }

        .qr-details p {
            margin: 10px 0;
            color: #666;
        }

        .qr-details strong {
            color: #333;
            width: 120px;
            display: inline-block;
        }

        .qr-info {
            background: #e7f3ff;
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
            text-align: left;
        }

        .qr-info p {
            color: #004085;
            margin: 5px 0;
            font-size: 0.9em;
        }

        .generation-status {
            background: <?php echo ($generation_status && $generation_status['success']) ? '#d4edda' : '#fff3cd'; ?>;
            color: <?php echo ($generation_status && $generation_status['success']) ? '#155724' : '#856404'; ?>;
            padding: 10px;
            border-radius: 5px;
            margin: 10px 0;
            font-size: 0.9em;
        }

        .actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            flex-wrap: wrap;
        }

        .btn {
            flex: 1;
            padding: 15px 20px;
            border: none;
            border-radius: 15px;
            font-size: 1.1em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            min-width: 150px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.4);
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-success:hover {
            background: #218838;
            transform: translateY(-3px);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-3px);
        }

        .btn-warning {
            background: #ffc107;
            color: #333;
        }

        .btn-warning:hover {
            background: #e0a800;
            transform: translateY(-3px);
        }

        .btn-info {
            background: #17a2b8;
            color: white;
        }

        .print-hint {
            margin-top: 20px;
            padding: 15px;
            background: #e7f3ff;
            border-radius: 10px;
            color: #004085;
            font-size: 0.9em;
        }

        .file-path {
            font-size: 0.8em;
            color: #999;
            margin-top: 10px;
            word-break: break-all;
        }

        .manual-qr {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
            text-align: left;
            font-family: monospace;
            word-break: break-all;
        }

        @media (max-width: 768px) {
            .qr-card {
                padding: 20px;
            }
            
            .actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }
            
            .qr-details strong {
                width: 100%;
                display: block;
                margin-bottom: 5px;
            }
        }

        @media print {
            body {
                background: white;
                padding: 0;
            }
            
            .btn, .actions, .print-hint, .file-path, .qr-info, .generation-status, .manual-qr {
                display: none !important;
            }
            
            .qr-card {
                box-shadow: none;
                padding: 20px;
            }
            
            .employee-info {
                background: #f0f0f0;
                color: #333;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    <div class="qr-container">
        <div class="qr-card">
            <div class="header">
                <h1>🎫 Employee QR Code</h1>
                <p>Scan this QR code for attendance</p>
            </div>

            <div class="employee-info">
                <h2><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></h2>
                <div class="id">ID: <?php echo htmlspecialchars($employee['employee_id']); ?></div>
                <span class="position"><?php echo htmlspecialchars($employee['position'] ?? 'No Position'); ?></span>
            </div>

            <?php if ($generation_status): ?>
                <div class="generation-status">
                    <?php if ($generation_status['success']): ?>
                        ✅ QR Code generated successfully using <?php echo $generation_status['api']; ?>
                    <?php else: ?>
                        ⚠️ QR Code generation failed. Using fallback display.
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="qr-image">
                <?php if (file_exists($qr_path) && filesize($qr_path) > 100): ?>
                    <img src="<?php echo $qr_url . '?t=' . time(); ?>" alt="QR Code for <?php echo htmlspecialchars($employee['first_name']); ?>" id="qrImage">
                <?php else: ?>
                    <div style="padding: 20px; text-align: center;">
                        <div style="font-size: 3em; margin-bottom: 10px;">📱</div>
                        <p style="color: #666;">QR code could not be generated.</p>
                        <p style="color: #999; font-size: 0.9em;">Please check your internet connection and try again.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="manual-qr">
                <strong>📋 Manual QR Data:</strong><br>
                <small>If QR code doesn't scan, use this data:</small><br>
                <code style="background: #f0f0f0; padding: 5px; display: block; margin-top: 5px;">
                    EMPLOYEE_ID:<?php echo $employee['employee_id']; ?>|NAME:<?php echo $employee['first_name'] . ' ' . $employee['last_name']; ?>|TYPE:attendance
                </code>
            </div>

            <div class="qr-details">
                <p><strong>Department:</strong> <?php echo htmlspecialchars($employee['department'] ?? 'N/A'); ?></p>
                <p><strong>Generated:</strong> <?php echo date('F j, Y h:i A'); ?></p>
                <p><strong>QR Type:</strong> Employee Attendance</p>
                <p><strong>Status:</strong> <span style="color: #28a745;">Active</span></p>
            </div>

            <div class="actions">
                <a href="generate_qrcodes.php" class="btn btn-secondary">← Back</a>
                <?php if (file_exists($qr_path)): ?>
                    <a href="<?php echo $qr_url; ?>" download="qr_<?php echo $employee['employee_id']; ?>.png" class="btn btn-success">📥 Download</a>
                <?php endif; ?>
                <button onclick="window.print()" class="btn btn-primary">🖨️ Print</button>
            </div>

            <div class="actions" style="margin-top: 10px;">
                <a href="generate_qrcodes.php?regenerate=<?php echo $employee['employee_id']; ?>" class="btn btn-warning" onclick="return confirm('Regenerate QR code for <?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?>? This will overwrite the existing QR code.')">
                    🔄 Regenerate QR
                </a>
                <button onclick="copyQRData()" class="btn btn-info">📋 Copy QR Data</button>
                <button onclick="retryQRGeneration()" class="btn btn-primary">🔄 Retry Generation</button>
            </div>

            <?php if (file_exists($qr_path)): ?>
                <div class="file-path">
                    <strong>File path:</strong> <?php echo realpath($qr_path); ?><br>
                    <strong>File size:</strong> <?php echo round(filesize($qr_path) / 1024, 2); ?> KB
                </div>
            <?php endif; ?>

            <div class="print-hint">
                💡 Tip: For best results, print on card stock or laminate for durability.
            </div>
        </div>
    </div>

    <script>
        // Copy QR data to clipboard
        function copyQRData() {
            const qrData = "EMPLOYEE_ID:<?php echo $employee['employee_id']; ?>|NAME:<?php echo $employee['first_name'] . ' ' . $employee['last_name']; ?>|TYPE:attendance";
            
            // Create temporary textarea
            const textarea = document.createElement('textarea');
            textarea.value = qrData;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            
            // Show feedback
            alert('QR data copied to clipboard!');
        }

        // Retry QR generation
        function retryQRGeneration() {
            window.location.href = 'view_qr.php?employee_id=<?php echo $employee['employee_id']; ?>&retry=1';
        }

        // Check if image loaded successfully
        document.addEventListener('DOMContentLoaded', function() {
            const img = document.getElementById('qrImage');
            if (img) {
                img.onerror = function() {
                    this.style.display = 'none';
                    const errorDiv = document.createElement('div');
                    errorDiv.style.padding = '20px';
                    errorDiv.style.background = '#f8d7da';
                    errorDiv.style.color = '#721c24';
                    errorDiv.style.borderRadius = '10px';
                    errorDiv.innerHTML = '❌ Failed to load QR code. <button onclick="retryQRGeneration()" style="margin-left:10px; padding:5px 10px; background:#721c24; color:white; border:none; border-radius:5px; cursor:pointer;">Retry</button>';
                    document.querySelector('.qr-image').appendChild(errorDiv);
                };
            }
        });

        // Check for retry parameter
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('retry') === '1') {
            // Show retry message
            const statusDiv = document.createElement('div');
            statusDiv.className = 'generation-status';
            statusDiv.style.background = '#fff3cd';
            statusDiv.style.color = '#856404';
            statusDiv.innerHTML = '⏳ Retrying QR code generation...';
            document.querySelector('.qr-card').insertBefore(statusDiv, document.querySelector('.qr-image'));
        }
    </script>
</body>
</html>