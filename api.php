    <?php
    require_once 'db.php';
    require_once __DIR__ . '/vendor/autoload.php';
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, DELETE');
    header('Access-Control-Allow-Headers: Content-Type');

    define('USERINFO_PATH', __DIR__ . '/USERINFO.csv');

    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    switch ($action) {

    

        case 'get_companies':
            $db   = getDB();
            $rows = $db->query("SELECT * FROM companies ORDER BY name ASC")->fetchAll();
            echo json_encode(['success' => true, 'data' => $rows]);
            break;

        case 'save_company':
            $db   = getDB();
            $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $id   = (int)($data['id'] ?? 0);
            $name = trim($data['name']      ?? '');
            $addr = trim($data['address']   ?? '');
            $logo = trim($data['logo_file'] ?? '');
            if (!$id || !$name) { echo json_encode(['success'=>false,'msg'=>'Missing fields']); break; }
            $db->prepare("UPDATE companies SET name=?, address=?, logo_file=? WHERE id=?")
            ->execute([$name, $addr, $logo, $id]);
            echo json_encode(['success' => true, 'msg' => 'Company saved.']);
            break;

    

        case 'get_employees':
            syncCSVtoDB();
            $db   = getDB();
            $rows = $db->query("SELECT * FROM employees ORDER BY full_name ASC")->fetchAll();
            echo json_encode(['success' => true, 'data' => $rows]);
            break;

        case 'add_employee':
            $db    = getDB();
            $data  = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $devId = trim($data['device_id']   ?? '');
            $empId = trim($data['employee_id'] ?? '');
            $name  = trim($data['full_name']   ?? '');
            $dept  = trim($data['department']  ?? '');
            $pos   = trim($data['position']    ?? '');
            $co    = trim($data['company']     ?? '');
            $email = trim($data['email']       ?? '');

            if (!$devId || !$empId || !$name) {
                echo json_encode(['success' => false, 'msg' => 'Device ID, Employee ID and Name are required.']);
                break;
            }
            try {
                $db->prepare("
                    INSERT INTO employees (device_id, employee_id, full_name, department, position, company, email)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        employee_id = VALUES(employee_id),
                        full_name   = VALUES(full_name),
                        department  = VALUES(department),
                        position    = VALUES(position),
                        company     = VALUES(company),
                        email       = VALUES(email)
                ")->execute([$devId, $empId, $name, $dept, $pos, $co, $email]);

                writeAllToCSV();
                echo json_encode(['success' => true, 'msg' => 'Employee saved.']);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'msg' => $e->getMessage()]);
            }
            break;

        case 'delete_employee':
            $db    = getDB();
            $data  = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $devId = trim($data['device_id'] ?? '');
            if (!$devId) { echo json_encode(['success' => false, 'msg' => 'No device_id.']); break; }
            $db->prepare("DELETE FROM employees WHERE device_id = ?")->execute([$devId]);
            writeAllToCSV();
            echo json_encode(['success' => true, 'msg' => 'Employee deleted.']);
            break;

        case 'import_userinfo':
            if (!isset($_FILES['file'])) {
                echo json_encode(['success' => false, 'msg' => 'No file uploaded.']); break;
            }
            $tmp   = $_FILES['file']['tmp_name'];
            $lines = file($tmp, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $db    = getDB();
            $added = 0; $updated = 0; $skipped = 0;
            foreach ($lines as $line) {
                $cols = preg_split('/\t/', $line);
                if (count($cols) < 2) $cols = str_getcsv($line);
                $cols = array_map('trim', $cols);

                $devId = $cols[1] ?? '';
                $empId = $cols[2] ?? '';
                $name  = $cols[3] ?? '';
                $pos   = $cols[5] ?? '';
                if (!$devId || !$name) { $skipped++; continue; }

                try {
                    $chk = $db->prepare("SELECT id FROM employees WHERE device_id = ?");
                    $chk->execute([$devId]);
                    $exists = $chk->fetch();
                    $db->prepare("
                        INSERT INTO employees (device_id, employee_id, full_name, position)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            employee_id = VALUES(employee_id),
                            full_name   = VALUES(full_name),
                            position    = VALUES(position)
                    ")->execute([$devId, $empId, $name, $pos]);
                    $exists ? $updated++ : $added++;
                } catch (PDOException $e) { $skipped++; }
            }
            writeAllToCSV();
            echo json_encode(['success' => true, 'msg' => "Import complete: {$added} added, {$updated} updated, {$skipped} skipped."]);
            break;

        case 'export_userinfo':
            if (file_exists(USERINFO_PATH)) {
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="USERINFO.csv"');
                readfile(USERINFO_PATH);
            } else {
                $db   = getDB();
                $rows = $db->query("SELECT * FROM employees ORDER BY full_name ASC")->fetchAll();
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="USERINFO.csv"');
                $out = fopen('php://output', 'w');
                foreach ($rows as $i => $r) {
                    fputcsv($out, [$i+1, $r['device_id'], $r['employee_id'], $r['full_name'], '', $r['position'], $r['department']]);
                }
                fclose($out);
            }
            exit;

        // ── ATTENDANCE ───────────────────────────────────────────

        case 'upload_attlog':
            if (!isset($_FILES['file'])) {
                echo json_encode(['success' => false, 'msg' => 'No file uploaded.']); break;
            }
            $tmp      = $_FILES['file']['tmp_name'];
            $filename = basename($_FILES['file']['name']);
            $lines    = file($tmp, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $db       = getDB();
            $punches  = [];

            foreach ($lines as $line) {
                $cols = preg_split('/\s+/', trim($line));
                if (count($cols) < 5) continue;
                $deviceId  = trim($cols[0]);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cols[1])) continue;
                if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $cols[2])) continue;
                $dateRaw   = $cols[1];
                $timeRaw   = $cols[2];
                $punchType = isset($cols[4]) ? (int)$cols[4] : null;
                if (!in_array($punchType, [0, 1])) continue;
                if (!isset($punches[$deviceId][$dateRaw])) {
                    $punches[$deviceId][$dateRaw] = ['ins' => [], 'outs' => []];
                }
                $punchType === 0
                    ? $punches[$deviceId][$dateRaw]['ins'][]  = $timeRaw
                    : $punches[$deviceId][$dateRaw]['outs'][] = $timeRaw;
            }

            $inserted = 0; $updated = 0;
            foreach ($punches as $deviceId => $dates) {
                foreach ($dates as $date => $p) {
                    sort($p['ins']); sort($p['outs']);
                    $timeIn  = !empty($p['ins'])  ? $p['ins'][0]    : null;
                    $timeOut = !empty($p['outs']) ? end($p['outs']) : null;
                    try {
                        $chk = $db->prepare("SELECT id, time_in, time_out FROM attendance WHERE device_id = ? AND work_date = ?");
                        $chk->execute([$deviceId, $date]);
                        $existing = $chk->fetch();
                        if ($existing) {
                            $newIn  = $timeIn;
                            $newOut = $timeOut;
                            if ($existing['time_in']  && $timeIn  && $timeIn  > $existing['time_in'])  $newIn  = $existing['time_in'];
                            if ($existing['time_out'] && $timeOut && $timeOut < $existing['time_out']) $newOut = $existing['time_out'];
                            if (!$existing['time_in']  && $timeIn)  $newIn  = $timeIn;
                            if (!$existing['time_out'] && $timeOut) $newOut = $timeOut;
                            $db->prepare("UPDATE attendance SET time_in=?, time_out=?, source_file=? WHERE device_id=? AND work_date=?")
                            ->execute([$newIn, $newOut, $filename, $deviceId, $date]);
                            $updated++;
                        } else {
                            $db->prepare("INSERT INTO attendance (device_id, work_date, time_in, time_out, source_file) VALUES (?,?,?,?,?)")
                            ->execute([$deviceId, $date, $timeIn, $timeOut, $filename]);
                            $inserted++;
                        }
                    } catch (PDOException $e) {}
                }
            }
            echo json_encode(['success' => true, 'msg' => "attlog.dat processed: {$inserted} new records, {$updated} updated."]);
            break;

        case 'get_attendance':
            $db     = getDB();
            $from   = $_GET['from']       ?? '';
            $to     = $_GET['to']         ?? '';
            $devId  = $_GET['device_id']  ?? '';
            $co     = $_GET['company']    ?? '';

            $where  = ['1=1'];
            $params = [];
            if ($from)  { $where[] = 'a.work_date >= ?'; $params[] = $from; }
            if ($to)    { $where[] = 'a.work_date <= ?'; $params[] = $to;   }
            if ($devId) { $where[] = 'a.device_id = ?';  $params[] = $devId; }
            if ($co)    { $where[] = 'e.company = ?';    $params[] = $co;   }

            $sql = "
                SELECT a.device_id,
                    COALESCE(e.employee_id, a.device_id)                    AS employee_id,
                    COALESCE(e.full_name, CONCAT('Device #',a.device_id))   AS full_name,
                    COALESCE(e.department,'') AS department,
                    COALESCE(e.position,'')   AS position,
                    COALESCE(e.company,'')    AS company,
                    COALESCE(e.email,'')      AS email,
                    a.work_date, a.time_in, a.time_out
                FROM attendance a
                LEFT JOIN employees e ON e.device_id = a.device_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY a.work_date ASC, full_name ASC
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            foreach ($rows as &$r) $r['total_hours'] = calcTotalHours($r['time_in'], $r['time_out']);
            echo json_encode(['success' => true, 'data' => $rows]);
            break;

        case 'get_date_range':
            $db  = getDB();
            $row = $db->query("SELECT MIN(work_date) AS min_date, MAX(work_date) AS max_date FROM attendance")->fetch();
            echo json_encode(['success' => true, 'data' => $row]);
            break;

        case 'clear_attendance':
            getDB()->exec("TRUNCATE TABLE attendance");
            echo json_encode(['success' => true, 'msg' => 'All attendance records cleared.']);
            break;

        // ── SETTINGS ─────────────────────────────────────────────

        case 'get_settings':
            $db   = getDB();
            $rows = $db->query("SELECT setting_key, setting_value FROM settings")->fetchAll();
            $out  = [];
            foreach ($rows as $r) $out[$r['setting_key']] = $r['setting_value'];
            echo json_encode(['success' => true, 'data' => $out]);
            break;

        case 'save_settings':
            $db   = getDB();
            $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $stmt = $db->prepare("INSERT INTO settings (setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
            foreach ($data as $k => $v) $stmt->execute([$k, $v]);
            echo json_encode(['success' => true, 'msg' => 'Settings saved.']);
            break;

        // ── EMAIL ────────────────────────────────────────────────

        case 'send_dtr_email':
            $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

            // Check if PHPMailer is installed
            if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
                echo json_encode(['success' => false, 'msg' => 'PHPMailer not installed. Run: composer require phpmailer/phpmailer in your dtr folder.']);
                break;
            }

            require_once __DIR__ . '/send_dtr.php';
            $result = sendDTREmail($data);
            echo json_encode($result);
            break;

        case 'send_dtr_bulk':
            $data    = json_decode(file_get_contents('php://input'), true) ?? [];
            $devices = $data['device_ids'] ?? [];
            $df      = $data['date_from']  ?? '';
            $dt      = $data['date_to']    ?? '';
            $subject = $data['subject']    ?? '';
            $body    = $data['body']       ?? '';

            if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
                echo json_encode(['success' => false, 'msg' => 'PHPMailer not installed.']);
                break;
            }

            require_once __DIR__ . '/send_dtr.php';
            $sent = 0; $failed = []; $noEmail = [];

            foreach ($devices as $devId) {
                $db   = getDB();
                $stmt = $db->prepare("SELECT email, full_name FROM employees WHERE device_id = ?");
                $stmt->execute([$devId]);
                $emp  = $stmt->fetch();
                if (!$emp || !$emp['email']) { $noEmail[] = $emp['full_name'] ?? $devId; continue; }

                $r = sendDTREmail([
                    'device_id' => $devId,
                    'date_from' => $df,
                    'date_to'   => $dt,
                    'recipient' => $emp['email'],
                    'subject'   => $subject,
                    'body'      => $body,
                ]);
                $r['success'] ? $sent++ : $failed[] = ($emp['full_name'] ?? $devId) . ': ' . $r['msg'];
            }

            $msg = "Sent: {$sent}.";
            if ($noEmail) $msg .= ' No email: ' . implode(', ', $noEmail) . '.';
            if ($failed)  $msg .= ' Failed: ' . implode('; ', $failed) . '.';
            echo json_encode(['success' => true, 'msg' => $msg, 'sent' => $sent]);
            break;
    case 'sync_device':
    try {
        $zk = new Rats\Zkteco\Lib\ZKTeco('139.135.152.122', 4370);

        if (!$zk->connect()) {
            echo json_encode(['success' => false, 'msg' => 'Could not connect to device.']);
            exit;
        }

        $logs = $zk->getAttendance();
        $zk->disconnect();
        if (empty($logs)) {
            echo json_encode(['success' => false, 'msg' => 'Connected but no attendance records returned.']);
            exit;
        }

        $db      = getDB();
        $added   = 0;
        $skipped = 0;

        foreach ($logs as $log) {
            $devId = (string)$log['id'];
            $date  = $log['timestamp'] ? date('Y-m-d', strtotime($log['timestamp'])) : '';
            $time  = $log['timestamp'] ? date('H:i:s',  strtotime($log['timestamp'])) : '';
            $type  = (int)$log['type']; // 0=IN, 1=OUT

            if (!$date || !$time) { $skipped++; continue; }

            $empStmt = $db->prepare("SELECT device_id FROM employees WHERE device_id = ?");
            $empStmt->execute([$devId]);
            $emp = $empStmt->fetch();
            if (!$emp) { $skipped++; continue; }

            $chk = $db->prepare("SELECT id, time_in, time_out FROM attendance WHERE device_id = ? AND work_date = ?");
            $chk->execute([$devId, $date]);
            $existing = $chk->fetch();

            if (!$existing) {
                $timeIn  = $type == 0 ? $time : null;
                $timeOut = $type == 1 ? $time : null;
                $db->prepare("INSERT INTO attendance (device_id, work_date, time_in, time_out, source_file) VALUES (?, ?, ?, ?, 'device_sync')")
                   ->execute([$devId, $date, $timeIn, $timeOut]);
                $added++;
            } else {
                if ($type == 0 && empty($existing['time_in'])) {
                    $db->prepare("UPDATE attendance SET time_in = ? WHERE id = ?")
                       ->execute([$time, $existing['id']]);
                    $added++;
                } elseif ($type == 1 && empty($existing['time_out'])) {
                    $db->prepare("UPDATE attendance SET time_out = ? WHERE id = ?")
                       ->execute([$time, $existing['id']]);
                    $added++;
                } else {
                    $skipped++;
                }
            }
        }

        echo json_encode([
            'success' => true,
            'msg'     => "Sync complete. {$added} records added/updated, {$skipped} skipped.",
            'total'   => count($logs),
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'msg' => 'Error: ' . $e->getMessage()]);
    }
    break;

    default:
        echo json_encode(['success' => false, 'msg' => 'Unknown action: ' . $action]);
}
        

    // ── CSV SYNC HELPERS ─────────────────────────────────────────
    function syncCSVtoDB(): void {
        if (!file_exists(USERINFO_PATH)) return;
        $db    = getDB();
        $lines = file(USERINFO_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $stmt  = $db->prepare("INSERT INTO employees (device_id,employee_id,full_name,position) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE employee_id=VALUES(employee_id),full_name=VALUES(full_name),position=VALUES(position)");
        foreach ($lines as $line) {
            $cols = preg_split('/\t/', $line);
            if (count($cols) < 2) $cols = str_getcsv($line);
            $cols  = array_map('trim', $cols);
            $devId = $cols[1] ?? ''; $name = $cols[3] ?? '';
            if (!$devId || !$name) continue;
            try { $stmt->execute([$devId, $cols[2]??'', $name, $cols[5]??'']); } catch(PDOException $e){}
        }
    }

    function writeAllToCSV(): bool {
        $db   = getDB();
        $rows = $db->query("SELECT * FROM employees ORDER BY CAST(device_id AS UNSIGNED) ASC")->fetchAll();
        if (!is_writable(dirname(USERINFO_PATH))) return false;
        $h = fopen(USERINFO_PATH, 'w');
        if (!$h) return false;
        foreach ($rows as $i => $r) {
            fputcsv($h, [$i+1, $r['device_id'], $r['employee_id'], $r['full_name'], '', $r['position'], $r['department']]);
        }
        fclose($h);
        return true;
    }

    function calcTotalHours(?string $in, ?string $out): string {
        if (!$in || !$out) return '';
        try {
            [$ih,$im] = array_map('intval', explode(':', $in));
            [$oh,$om] = array_map('intval', explode(':', $out));
            $inM  = $ih*60+$im; $outM = $oh*60+$om;
            if ($outM < $inM) $outM += 1440;
            $diff = $outM - $inM;
            return $diff > 0 ? floor($diff/60).'h '.($diff%60).'m' : '';
        } catch(Throwable $e) { return ''; }
    }