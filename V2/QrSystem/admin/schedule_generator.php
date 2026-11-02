<?php
require_once 'admin_auth.php'; 
require_once 'configa.php';

/* =====================================================================
   EMPLOYEE DATA INSERTER
   ---------------------------------------------------------------------
   Run this ONCE to seed your `employees` table.
   It is SAFE to run again - existing employees are skipped, not duplicated.
   ===================================================================== */

/* ---------------------------------------------------------------------
   1. YOUR EMPLOYEE LIST  (edit this block freely)
   ---------------------------------------------------------------------
   NOTE ABOUT THE TIME:
   You wrote "6am to 3am".  A 6:00 AM - 3:00 PM shift (9 hours - 1 hour
   break = 8 working hours) is the normal reading, so that is what I used.
   If you REALLY mean 3:00 AM, change '15:00' to '03:00' below.
   --------------------------------------------------------------------- */
$employees = [
    [
        'first_name' => 'Maria Jasmin Jhey',
        'last_name'  => 'Suelila',
        'position'   => 'Manager',
        'time_in'    => '06:00',
        'time_out'   => '15:00',
    ],
    [
        'first_name' => 'Arianne',
        'last_name'  => 'Hantid',
        'position'   => 'Staff',
        'time_in'    => '06:00',
        'time_out'   => '15:00',
    ],
    [
        'first_name' => 'Carmela',
        'last_name'  => 'Olfindo',
        'position'   => 'Designer',
        'time_in'    => '06:00',
        'time_out'   => '15:00',
    ],
    [
        'first_name' => 'Clareza',
        'last_name'  => 'Terante',
        'position'   => 'Tester',
        'time_in'    => '06:00',
        'time_out'   => '15:00',
    ],
    [
        'first_name' => 'Jellaine',
        'last_name'  => 'Carmen',
        'position'   => 'Manager',
        'time_in'    => '06:00',
        'time_out'   => '15:00',
    ],
];

/* Default rest days given to every new employee */
$default_rest_days = ['Saturday', 'Sunday'];

/* Company e-mail domain (used to auto-build e-mail addresses) */
$email_domain = 'company.com';

/* ---------------------------------------------------------------------
   2. HELPERS
   --------------------------------------------------------------------- */
function get_columns(PDO $pdo, string $table): array
{
    $cols = [];
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM `$table`") as $row) {
            $cols[$row['Field']] = $row;   // Field, Type, Null, Key, Default, Extra
        }
    } catch (PDOException $e) {
        // table does not exist
    }
    return $cols;
}

function make_username(string $first, string $last): string
{
    $clean = function ($s) {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $s));
    };
    return $clean($first) . '.' . $clean($last);
}

/**
 * Build the next employee_id, whether the column is an INT or a VARCHAR
 * like EMP001.  Returns null when the column is AUTO_INCREMENT.
 */
function next_employee_id(PDO $pdo, array $colInfo)
{
    if (stripos($colInfo['Extra'], 'auto_increment') !== false) {
        return null;                    // let MySQL handle it
    }

    if (stripos($colInfo['Type'], 'int') !== false) {
        $max = (int) $pdo->query("SELECT COALESCE(MAX(employee_id), 0) FROM employees")->fetchColumn();
        return $max + 1;
    }

    // VARCHAR / CHAR -> EMP001 style
    $rows = $pdo->query("SELECT employee_id FROM employees WHERE employee_id LIKE 'EMP%'")
                ->fetchAll(PDO::FETCH_COLUMN);
    $max = 0;
    foreach ($rows as $r) {
        if (preg_match('/(\d+)\s*$/', $r, $m)) {
            $max = max($max, (int) $m[1]);
        }
    }
    return 'EMP' . str_pad($max + 1, 3, '0', STR_PAD_LEFT);
}

/* ---------------------------------------------------------------------
   3. INSPECT THE DATABASE
   --------------------------------------------------------------------- */
$empCols  = get_columns($pdo, 'employees');
$restCols = get_columns($pdo, 'employee_rest_days');

$results   = [];
$fatal     = '';
$did_run   = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['insert']));

/* ---------------------------------------------------------------------
   4. DO THE INSERT
   --------------------------------------------------------------------- */
if ($did_run) {

    if (!$empCols) {
        $fatal = "Table <code>employees</code> was not found in the database. Check your <code>configa.php</code> / database name.";
    } else {

        try {
            $pdo->beginTransaction();

            foreach ($employees as $e) {

                $full_name = $e['first_name'] . ' ' . $e['last_name'];

                /* ---- skip if already there ---- */
                $chk = $pdo->prepare(
                    "SELECT employee_id FROM employees
                     WHERE first_name = ? AND last_name = ? LIMIT 1"
                );
                $chk->execute([$e['first_name'], $e['last_name']]);
                $already = $chk->fetchColumn();

                if ($already) {
                    $results[] = [
                        'status' => 'skipped',
                        'name'   => $full_name,
                        'id'     => $already,
                        'msg'    => 'Already exists - skipped',
                    ];
                    continue;
                }

                /* ---- build the row, using only columns that exist ---- */
                $username = make_username($e['first_name'], $e['last_name']);

                $candidate = [
                    'first_name'         => $e['first_name'],
                    'last_name'          => $e['last_name'],
                    'middle_name'        => '',
                    'position'           => $e['position'],
                    'job_title'          => $e['position'],
                    'email'              => $username . '@' . $email_domain,
                    'username'           => $username,
                    'contact_number'     => '',
                    'phone'              => '',
                    'address'            => '',
                    'hire_date'          => date('Y-m-d'),
                    'date_hired'         => date('Y-m-d'),
                    'status'             => 'Active',
                    'employment_status'  => 'Active',
                    'scheduled_time_in'  => $e['time_in']  . ':00',
                    'scheduled_time_out' => $e['time_out'] . ':00',
                    'default_hours'      => 8,
                    'basic_salary'       => 0,
                    'daily_rate'         => 0,
                ];

                $data = [];
                foreach ($candidate as $col => $val) {
                    if (array_key_exists($col, $empCols)) {
                        $data[$col] = $val;
                    }
                }

                /* ---- employee_id (only if not AUTO_INCREMENT) ---- */
                if (array_key_exists('employee_id', $empCols)) {
                    $newId = next_employee_id($pdo, $empCols['employee_id']);
                    if ($newId !== null) {
                        $data['employee_id'] = $newId;
                    }
                }

                /* ---- required columns that are NOT NULL and have no default ---- */
                foreach ($empCols as $col => $info) {
                    if (isset($data[$col])) continue;
                    if ($info['Null'] === 'NO' && $info['Default'] === null
                        && stripos($info['Extra'], 'auto_increment') === false
                        && $info['Key'] !== 'PRI') {
                        $data[$col] = '';      // last-resort filler
                    }
                }

                /* ---- insert ---- */
                $cols = array_keys($data);
                $sql  = "INSERT INTO employees (`" . implode('`,`', $cols) . "`)
                         VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")";
                $ins  = $pdo->prepare($sql);
                $ins->execute(array_values($data));

                $newId = $data['employee_id'] ?? $pdo->lastInsertId();

                /* ---- give them default rest days ---- */
                if ($restCols
                    && isset($restCols['employee_id'])
                    && isset($restCols['day_of_week'])) {

                    foreach ($default_rest_days as $day) {
                        $c = $pdo->prepare(
                            "SELECT 1 FROM employee_rest_days
                             WHERE employee_id = ? AND day_of_week = ? LIMIT 1"
                        );
                        $c->execute([$newId, $day]);
                        if (!$c->fetchColumn()) {
                            $r = $pdo->prepare(
                                "INSERT INTO employee_rest_days (employee_id, day_of_week)
                                 VALUES (?, ?)"
                            );
                            $r->execute([$newId, $day]);
                        }
                    }
                }

                $results[] = [
                    'status' => 'inserted',
                    'name'   => $full_name,
                    'id'     => $newId,
                    'msg'    => $e['position'] . ' &middot; '
                                . date('g:i A', strtotime($e['time_in'])) . ' - '
                                . date('g:i A', strtotime($e['time_out']))
                                . ' &middot; rest: ' . implode(', ', $default_rest_days),
                ];
            }

            $pdo->commit();

        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $fatal = 'Database error: ' . htmlspecialchars($ex->getMessage());
        }
    }
}

/* Summary counters */
$inserted = 0;
$skipped  = 0;
foreach ($results as $r) {
    if ($r['status'] === 'inserted') $inserted++;
    else                             $skipped++;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Insert Employee Data</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            background: linear-gradient(135deg,#667eea 0%,#764ba2 100%);
            font-family:'Segoe UI', sans-serif;
            min-height:100vh; padding:20px;
        }
        .container { max-width:900px; margin:0 auto; }
        h1 {
            text-align:center; color:#fff; margin-bottom:30px;
            font-size:2.3em; text-shadow:2px 2px 4px rgba(0,0,0,.2);
        }
        .card {
            background:#fff; border-radius:20px; padding:30px;
            box-shadow:0 20px 60px rgba(0,0,0,.3); margin-bottom:20px;
        }
        h2 { margin-bottom:15px; color:#333; font-size:1.3em; }
        table { width:100%; border-collapse:collapse; margin-top:10px; }
        th, td { padding:12px; text-align:left; border-bottom:1px solid #eee; font-size:.95em; }
        th { background:#f8f9fa; color:#555; font-weight:600; }
        .btn {
            padding:12px 25px; border:none; border-radius:10px;
            font-size:16px; font-weight:600; cursor:pointer;
            transition:all .3s; text-decoration:none; display:inline-block;
        }
        .btn-back {
            background:rgba(255,255,255,.2); color:#fff; border:2px solid #fff;
        }
        .btn-back:hover { background:#fff; color:#667eea; }
        .btn-primary { background:#28a745; color:#fff; }
        .btn-primary:hover { background:#218838; }
        .header-buttons { display:flex; gap:15px; margin-bottom:20px; flex-wrap:wrap; }
        .alert { padding:15px; border-radius:10px; margin-bottom:20px; }
        .alert-success { background:#d4edda; color:#155724; }
        .alert-error   { background:#f8d7da; color:#721c24; }
        .alert-info    { background:#d1ecf1; color:#0c5460; }
        .badge {
            display:inline-block; padding:4px 10px; border-radius:20px;
            font-size:.8em; font-weight:600;
        }
        .badge-ok  { background:#d4edda; color:#155724; }
        .badge-skip{ background:#fff3cd; color:#856404; }
        .note { background:#fff3cd; border-left:4px solid #ffc107; padding:15px;
                border-radius:8px; color:#856404; font-size:.9em; line-height:1.6; }
        code { background:#f1f1f1; padding:2px 6px; border-radius:4px; font-size:.9em; }
    </style>
</head>
<body>
<div class="container">

    <h1>👥 Insert Employee Data</h1>

    <div class="header-buttons">
        <a href="admin_dashboard.php" class="btn btn-back">← Dashboard</a>
        <a href="generate_schedules.php" class="btn btn-back">📋 Schedule Generator</a>
    </div>

    <?php if ($fatal): ?>
        <div class="alert alert-error"><?php echo $fatal; ?></div>
    <?php endif; ?>

    <?php if ($did_run && !$fatal): ?>
        <div class="alert alert-<?php echo $inserted > 0 ? 'success' : 'info'; ?>">
            <strong>Done!</strong>
            <?php echo $inserted; ?> employee(s) inserted,
            <?php echo $skipped; ?> skipped (already existed).
        </div>

        <div class="card">
            <h2>Results</h2>
            <table>
                <tr>
                    <th>Status</th><th>Employee ID</th><th>Name</th><th>Details</th>
                </tr>
                <?php foreach ($results as $r): ?>
                    <tr>
                        <td>
                            <span class="badge <?php echo $r['status'] === 'inserted' ? 'badge-ok' : 'badge-skip'; ?>">
                                <?php echo $r['status'] === 'inserted' ? '✔ Inserted' : '↷ Skipped'; ?>
                            </span>
                        </td>
                        <td><strong><?php echo htmlspecialchars($r['id']); ?></strong></td>
                        <td><?php echo htmlspecialchars($r['name']); ?></td>
                        <td><?php echo $r['msg']; ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Employees that will be added</h2>
        <table>
            <tr>
                <th>#</th><th>Name</th><th>Position</th>
                <th>Shift</th><th>Hours</th><th>Rest Days</th>
            </tr>
            <?php foreach ($employees as $i => $e): ?>
                <tr>
                    <td><?php echo $i + 1; ?></td>
                    <td><strong><?php echo htmlspecialchars($e['first_name'] . ' ' . $e['last_name']); ?></strong></td>
                    <td><?php echo htmlspecialchars($e['position']); ?></td>
                    <td>
                        <?php echo date('g:i A', strtotime($e['time_in'])); ?> –
                        <?php echo date('g:i A', strtotime($e['time_out'])); ?>
                    </td>
                    <td>8</td>
                    <td><?php echo implode(', ', $default_rest_days); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <div style="margin-top:25px;">
            <form method="POST">
                <button type="submit" name="insert" class="btn btn-primary"
                        style="width:100%; padding:15px; font-size:18px;">
                    💾 Insert These Employees
                </button>
            </form>
        </div>
    </div>

    <div class="card">
        <h2>⚠️ Before you run this</h2>
        <div class="note">
            <strong>1. E-mail addresses</strong> are auto-generated as
            <code>firstname.lastname@<?php echo $email_domain; ?></code>.
            Edit them later in your employee edit page if needed.<br><br>

            <strong>2. Shift time</strong> — you wrote <em>"6am to 3am"</em>.
            I used <strong>6:00 AM – 3:00 PM</strong> because 6AM–3AM is a
            21-hour shift. If it really is 3 AM, change <code>'15:00'</code>
            to <code>'03:00'</code> at the top of this file.<br><br>

            <strong>3. The script is safe to re-run.</strong> It checks
            first name + last name and skips anyone already in the table.<br><br>

            <strong>4. After inserting</strong>, open
            <code>generate_schedules.php</code>, set the month, and enter
            <strong>06:00</strong> / <strong>15:00</strong> as the scheduled
            time-in / time-out, then hit <em>Generate Schedules</em>.<br><br>

            <strong>5. Security:</strong> delete this file once you're done.
        </div>
    </div>

</div>
</body>
</html>