<?php
session_start();

// 💡 9시간 오차 해결: 시스템 시간을 한국 시간(KST)으로 강제 고정
date_default_timezone_set('Asia/Seoul');

// ================= [🔑 API 백엔드 통합] =================
$rawInput = file_get_contents('php://input');
$req = json_decode($rawInput, true);
$action = $_GET['ajax_action'] ?? $_POST['ajax_action'] ?? ($req['ajax_action'] ?? '');

$WEBHOOK_URL = 'https://hooks.slack.com/services/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';

function sendWebhookAlert($url, $serverName, $ip, $type) {
    if (empty($url)) return;
    $icon = ($type === '생성') ? "🟢" : "🔴";
    $message = "{$icon} *[서버 {$type} 자동 감지]*\n- 서버명: {$serverName}\n- IP 주소: {$ip}\n👉 대시보드에 접속하여 [Jira 티켓]을 수동으로 등록해 주세요!";
    $payload = json_encode(['text' => $message]);
    $ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $payload); curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']); curl_exec($ch); curl_close($ch);
}

function sendTaskWebhookAlert($url, $date, $name) {
    if (empty($url)) return;
    $message = "📅 *[오늘의 인프라 작업 일정]*\n- 예정 일자: {$date}\n- 작업 내용: {$name}\n👉 오늘 계획된 작업이 있습니다. 대시보드 캘린더를 확인해 주세요!";
    $payload = json_encode(['text' => $message]);
    $ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $payload); curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']); curl_exec($ch); curl_close($ch);
}

function sendRoutineWebhookAlert($url, $time, $name) {
    if (empty($url)) return;
    $message = "⏰ *[맞춤형 일일 알림]*\n- 설정 시간: {$time}\n- 점검 항목: {$name}\n👉 대시보드 우측 상단 '알림 설정 및 이력 관리' 팝업에서 점검 후 [✓ 해결]을 눌러주세요!";
    $payload = json_encode(['text' => $message]);
    $ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $payload); curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']); curl_exec($ch); curl_close($ch);
}

$db_file = '/opt/scp/dashboard_users.db';
try {
    $db = new PDO("sqlite:" . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec("CREATE TABLE IF NOT EXISTS jira_tickets (
        id INTEGER PRIMARY KEY AUTOINCREMENT, server_name TEXT, event_type TEXT, event_date TEXT, jira_number TEXT, UNIQUE(server_name, event_type, event_date)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS scheduled_tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT, task_date TEXT, task_name TEXT, is_sent INTEGER DEFAULT 0, created_at TEXT
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS notes (
        id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, content TEXT, created_at TEXT, updated_at TEXT
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS daily_routines (
        id INTEGER PRIMARY KEY AUTOINCREMENT, routine_name TEXT, alarm_time TEXT, is_active INTEGER DEFAULT 1
    )");
    try { $db->exec("ALTER TABLE daily_routines ADD COLUMN alarm_time TEXT DEFAULT '09:00'"); } catch(Exception $e) {}

    $db->exec("CREATE TABLE IF NOT EXISTS routine_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT, routine_id INTEGER, log_date TEXT, status INTEGER DEFAULT 0, resolved_at TEXT, slack_sent INTEGER DEFAULT 0,
        UNIQUE(routine_id, log_date)
    )");
    try { $db->exec("ALTER TABLE routine_logs ADD COLUMN slack_sent INTEGER DEFAULT 0"); } catch(Exception $e) {}

    $db->exec("DELETE FROM routine_logs WHERE status = 1 AND log_date <= '2026-09-29'");

    $today = date('Y-m-d');
    $stmt = $db->query("SELECT id, task_name FROM scheduled_tasks WHERE task_date = '$today' AND is_sent = 0");
    $pendingTasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pendingTasks as $t) {
        sendTaskWebhookAlert($WEBHOOK_URL, $today, $t['task_name']);
        $db->exec("UPDATE scheduled_tasks SET is_sent = 1 WHERE id = " . $t['id']);
    }

    if (!empty($action)) {
        header('Content-Type: application/json; charset=utf-8');
        error_reporting(0);
        ini_set('display_errors', 0);

        if (!isset($_SESSION['user_id'])) { echo json_encode(['error' => '세션이 만료되었습니다.']); exit; }

        if ($action === 'get_notes') {
            $stmt = $db->query("SELECT id, title, substr(REPLACE(REPLACE(REPLACE(content, '<', ''), '>', ''), '&nbsp;', ' '), 1, 50) as preview, updated_at FROM notes ORDER BY updated_at DESC");
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]); exit;
        }
        if ($action === 'get_note_detail') {
            $stmt = $db->prepare("SELECT * FROM notes WHERE id = ?");
            $stmt->execute([$req['id']]);
            echo json_encode(['success' => true, 'data' => $stmt->fetch(PDO::FETCH_ASSOC)]); exit;
        }
        if ($action === 'save_note') {
            $now = date('Y-m-d H:i:s');
            if(empty($req['id'])) {
                $stmt = $db->prepare("INSERT INTO notes (title, content, created_at, updated_at) VALUES (?, ?, ?, ?)");
                $stmt->execute([$req['title'], $req['content'], $now, $now]);
                echo json_encode(['success' => true, 'id' => $db->lastInsertId()]); exit;
            } else {
                $stmt = $db->prepare("UPDATE notes SET title = ?, content = ?, updated_at = ? WHERE id = ?");
                $stmt->execute([$req['title'], $req['content'], $now, $req['id']]);
                echo json_encode(['success' => true, 'id' => $req['id']]); exit;
            }
        }
        if ($action === 'delete_note') {
            $stmt = $db->prepare("DELETE FROM notes WHERE id = ?");
            $stmt->execute([$req['id']]);
            echo json_encode(['success' => true]); exit;
        }

        if ($action === 'check_routines') {
            $today = date('Y-m-d');
            $currentTime = date('H:i');
            $routines = $db->query("SELECT id FROM daily_routines WHERE is_active = 1")->fetchAll();
            foreach($routines as $r) {
                $db->exec("INSERT OR IGNORE INTO routine_logs (routine_id, log_date, status, slack_sent) VALUES ({$r['id']}, '$today', 0, 0)");
            }
            $stmt = $db->query("SELECT rl.id as log_id, dr.id as routine_id, dr.routine_name, dr.alarm_time, rl.slack_sent FROM routine_logs rl JOIN daily_routines dr ON rl.routine_id = dr.id WHERE rl.log_date = '$today' AND rl.status = 0 AND dr.alarm_time <= '$currentTime' ORDER BY dr.alarm_time ASC");
            $pendingRoutines = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach($pendingRoutines as $p) {
                if ($p['slack_sent'] == 0) {
                    sendRoutineWebhookAlert($WEBHOOK_URL, $p['alarm_time'], $p['routine_name']);
                    $db->exec("UPDATE routine_logs SET slack_sent = 1 WHERE id = " . $p['log_id']);
                }
            }
            echo json_encode(['success' => true, 'pending' => $pendingRoutines]); exit;
        }

        if ($action === 'get_routine_settings') {
            $stmt = $db->query("SELECT id, routine_name, alarm_time FROM daily_routines WHERE is_active = 1 ORDER BY alarm_time ASC");
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]); exit;
        }

        if ($action === 'resolve_routine') {
            $stmt = $db->prepare("UPDATE routine_logs SET status = 1, resolved_at = ? WHERE id = ?");
            $stmt->execute([date('Y-m-d H:i:s'), $req['log_id']]);

            $db->exec("UPDATE daily_routines SET is_active = 0 WHERE id = (SELECT routine_id FROM routine_logs WHERE id = " . intval($req['log_id']) . ")");
            echo json_encode(['success' => true]); exit;
        }

        if ($action === 'defer_routine') {
            $stmt = $db->prepare("UPDATE routine_logs SET status = 2 WHERE id = ?");
            $stmt->execute([$req['log_id']]);
            echo json_encode(['success' => true]); exit;
        }

        if ($action === 'add_routine') {
            $stmt = $db->prepare("INSERT INTO daily_routines (routine_name, alarm_time) VALUES (?, ?)");
            $stmt->execute([trim($req['routine_name']), trim($req['alarm_time'])]);
            echo json_encode(['success' => true]); exit;
        }
        if ($action === 'delete_routine') {
            $stmt = $db->prepare("UPDATE daily_routines SET is_active = 0 WHERE id = ?");
            $stmt->execute([$req['routine_id']]);
            $stmt = $db->prepare("DELETE FROM routine_logs WHERE routine_id = ? AND status = 0");
            $stmt->execute([$req['routine_id']]);
            echo json_encode(['success' => true]); exit;
        }
        if ($action === 'get_routine_history') {
            $month = $req['month'] ?? date('Y-m');
            $keyword = isset($req['keyword']) ? trim($req['keyword']) : '';
            if ($keyword !== '') {
                $stmt = $db->prepare("SELECT rl.log_date, dr.routine_name, rl.resolved_at FROM routine_logs rl JOIN daily_routines dr ON rl.routine_id = dr.id WHERE rl.status = 1 AND rl.log_date LIKE ? AND dr.routine_name LIKE ? ORDER BY rl.resolved_at DESC");
                $stmt->execute(["$month%", "%$keyword%"]);
            } else {
                $stmt = $db->prepare("SELECT rl.log_date, dr.routine_name, rl.resolved_at FROM routine_logs rl JOIN daily_routines dr ON rl.routine_id = dr.id WHERE rl.status = 1 AND rl.log_date LIKE ? ORDER BY rl.resolved_at DESC");
                $stmt->execute(["$month%"]);
            }
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]); exit;
        }

        if ($action === 'download_monthly_report') {
            $report = [];

            $vmCsv = "서버명,유형,IP 주소,상태,vCPU,메모리(GB),운영체제\n";
            $serverData = json_decode(@file_get_contents('/opt/scp/servers.json'), true);
            $osInfo = json_decode(@file_get_contents('/opt/scp/os_info.json'), true);
            if(isset($serverData['servers'])) {
                foreach($serverData['servers'] as $srv) {
                    $ip = $srv['addresses'][0]['ip_addresses'][0]['ip_address'] ?? '';
                    $type = (strpos(strtolower($srv['server_type']['name'] ?? ''),'gpu')!==false) ? 'GPU' : 'VM';
                    $os = $osInfo[$ip]['os'] ?? '-';
                    if($os=='UNREACHABLE' || $os=='접속불가') $os = '접속불가';
                    $status = $srv['state'] ?? '-';
                    $vcpu = $srv['server_type']['vcpus'] ?? '';
                    $ram = $srv['server_type']['ram'] ?? '';
                    $name = $srv['name'] ?? '-';
                    $vmCsv .= "\"{$name}\",\"{$type}\",\"{$ip}\",\"{$status}\",\"{$vcpu}\",\"{$ram}\",\"{$os}\"\n";
                }
            }
            $report['vm'] = $vmCsv;

            $iamCsv = "NAME,ID,POLICIES,ACCESS KEYS,CREATED AT,DESCRIPTION,EMAIL,LAST LOGIN AT\n";
            $rawIam = json_decode(@file_get_contents('/opt/scp/iam_current.json'), true);
            $items = $rawIam['contents'] ?? $rawIam['users'] ?? $rawIam['items'] ?? (is_array($rawIam) ? $rawIam : []);
            foreach($items as $item) {
                $policies = [];
                if(isset($item['policies']) && is_array($item['policies'])) {
                    foreach($item['policies'] as $p) { $policies[] = $p['name'] ?? $p['policyName'] ?? ''; }
                }
                $policyStr = empty($policies) ? '-' : implode(', ', $policies);
                $hasKey = ((isset($item['access_keys']) && !empty($item['access_keys'])) || (isset($item['accessKeyCount']) && $item['accessKeyCount'] > 0)) ? '발급됨' : '미발급';
                $name = $item['userName'] ?? $item['user_name'] ?? $item['name'] ?? '-';
                $id = $item['userId'] ?? $item['user_id'] ?? $item['id'] ?? '-';
                $created = $item['createdAt'] ?? $item['created_at'] ?? '-';
                $desc = $item['description'] ?? '-';
                $email = $item['email'] ?? '-';
                $lastLogin = $item['lastLoginAt'] ?? $item['last_login_at'] ?? '-';
                $iamCsv .= "\"{$name}\",\"{$id}\",\"{$policyStr}\",\"{$hasKey}\",\"{$created}\",\"{$desc}\",\"{$email}\",\"{$lastLogin}\"\n";
            }
            $report['iam'] = $iamCsv;

            $lbCsv = @file_get_contents('/opt/scp/scp_lb_member_mapping.csv');
            if(!$lbCsv) $lbCsv = "LB_NAME,LB_TYPE,SG_NAME,MEMBER_NAME,MEMBER_IP,PORT\n데이터 없음\n";
            $report['lb'] = $lbCsv;

            echo json_encode(['success' => true, 'data' => $report]);
            exit;
        }

        if ($action === 'get_sg_rules') {
            $sgId = escapeshellarg($req['sg_id'] ?? '');

            $cmd1 = "sudo /usr/local/bin/scp-cli security-group security-group-user-rule list --security_group_id {$sgId} --format json 2>&1";
            $output = shell_exec($cmd1);
            $decoded = json_decode($output, true);

            if ($decoded === null || (isset($decoded['contents']) && empty($decoded['contents']))) {
                $cmd2 = "sudo /usr/local/bin/scp-cli security-group security-group-rule list --security_group_id {$sgId} --format json 2>&1";
                $output2 = shell_exec($cmd2);
                if (trim($output2) !== '') {
                    $output = $output2;
                }
            }

            echo $output ?: json_encode([]);
            exit;
        }

        if ($action === 'get_cloud_resource') {
            $type = $req['type'] ?? '';

            if ($type === 'iam') {
                $file = '/opt/scp/iam_current.json';
                if (file_exists($file)) {
                    $raw = json_decode(file_get_contents($file), true);
                    $items = [];
                    if (isset($raw['contents'])) $items = $raw['contents'];
                    elseif (isset($raw['users'])) $items = $raw['users'];
                    elseif (isset($raw['items'])) $items = $raw['items'];
                    elseif (is_array($raw)) $items = $raw;

                    $data = [];
                    foreach ($items as $item) {
                        $policies = [];
                        if (isset($item['policies']) && is_array($item['policies'])) {
                            foreach ($item['policies'] as $p) {
                                if (isset($p['name'])) $policies[] = $p['name'];
                                elseif (isset($p['policyName'])) $policies[] = $p['policyName'];
                            }
                        }
                        $policyStr = empty($policies) ? '-' : implode(', ', $policies);

                        $hasKey = '미발급';
                        if ((isset($item['access_keys']) && !empty($item['access_keys'])) ||
                            (isset($item['accessKeys']) && !empty($item['accessKeys'])) ||
                            (isset($item['accessKeyCount']) && $item['accessKeyCount'] > 0)) {
                            $hasKey = '발급됨 🔑';
                        }

                        $data[] = [
                            'NAME' => $item['userName'] ?? $item['user_name'] ?? $item['name'] ?? '-',
                            'ID' => $item['userId'] ?? $item['user_id'] ?? $item['id'] ?? '-',
                            'POLICIES' => $policyStr,
                            'ACCESS KEYS' => $hasKey,
                            'CREATED AT' => $item['createdAt'] ?? $item['created_at'] ?? $item['createdDt'] ?? '-',
                            'DESCRIPTION' => $item['description'] ?? $item['userDescription'] ?? '-',
                            'EMAIL' => $item['email'] ?? $item['userEmail'] ?? '-',
                            'LAST LOGIN AT' => $item['lastLoginAt'] ?? $item['last_login_at'] ?? $item['lastLoginDt'] ?? '-'
                        ];
                    }
                    echo json_encode($data, JSON_UNESCAPED_UNICODE);
                } else {
                    echo json_encode([]);
                }
                exit;
            }

            if ($type === 'lb') {
                $csvFile = '/opt/scp/scp_lb_member_mapping.csv';
                if (file_exists($csvFile)) {
                    $rows = file($csvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    $data = [];
                    foreach ($rows as $idx => $row) {
                        if ($idx === 0) continue;
                        $cols = str_getcsv($row);
                        if (count($cols) >= 6) {
                            $data[] = [
                                'LB_NAME' => $cols[0] ?? '-',
                                'LB_TYPE' => $cols[1] ?? '-',
                                'SG_NAME' => $cols[2] ?? '-',
                                'MEMBER_NAME' => $cols[3] ?? '-',
                                'MEMBER_IP' => $cols[4] ?? '-',
                                'PORT' => $cols[5] ?? '-'
                            ];
                        }
                    }
                    echo json_encode($data, JSON_UNESCAPED_UNICODE);
                } else {
                    echo json_encode([]);
                }
                exit;
            }

            $file = '/opt/scp/' . strtolower($type) . '_current.json';
            if (file_exists($file)) {
                echo file_get_contents($file);
            } else {
                echo json_encode([]);
            }
            exit;
        }

        if ($action === 'task_list') {
            $stmt = $db->query("SELECT * FROM scheduled_tasks ORDER BY task_date ASC");
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]); exit;
        }
        if ($action === 'task_add') {
            $stmt = $db->prepare("INSERT INTO scheduled_tasks (task_date, task_name, created_at) VALUES (?, ?, ?)");
            $stmt->execute([ trim($req['task_date']), trim($req['task_name']), date('Y-m-d H:i') ]);
            echo json_encode(['success' => true]); exit;
        }
        if ($action === 'task_delete') {
            $stmt = $db->prepare("DELETE FROM scheduled_tasks WHERE id = ?");
            $stmt->execute([ $req['id'] ]);
            echo json_encode(['success' => true]); exit;
        }
        if ($action === 'jira_save') {
            $stmt = $db->prepare("INSERT OR REPLACE INTO jira_tickets (server_name, event_type, event_date, jira_number) VALUES (?, ?, ?, ?)");
            $stmt->execute([trim($req['server_name']), trim($req['event_type']), trim($req['event_date']), trim($req['jira_number'])]);
            echo json_encode(['success' => true]); exit;
        }
        exit;
    }
} catch(Exception $e) {}
// ====================================================================

$timeout_duration = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') ? 3600 : 600;

if (isset($_SESSION['last_activity'])) {
    if ((time() - $_SESSION['last_activity']) > $timeout_duration) {
        session_unset(); session_destroy();
        echo "<script>alert('⏰ 세션 유지 시간이 초과되어 자동 로그아웃되었습니다.'); location.href = 'login.php';</script>"; exit;
    }
}
$_SESSION['last_activity'] = time();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$cpuUsage = 0;
if (function_exists('sys_getloadavg')) {
    $load = sys_getloadavg(); $cores = 1;
    if (is_file('/proc/cpuinfo')) { $cpuinfo = file_get_contents('/proc/cpuinfo'); preg_match_all('/^processor/m', $cpuinfo, $matches); $cores = count($matches[0]) ?: 1; }
    $cpuUsage = round(($load[0] / $cores) * 100, 1); if ($cpuUsage > 100) $cpuUsage = 100;
}

$memTotal = 0; $memUsed = 0; $memPercent = 0;
if (is_file('/proc/meminfo')) {
    $meminfo = file_get_contents('/proc/meminfo');
    preg_match('/MemTotal:\s+(\d+) kB/', $meminfo, $totalMatches); preg_match('/MemAvailable:\s+(\d+) kB/', $meminfo, $availMatches);
    if (!empty($totalMatches[1]) && !empty($availMatches[1])) {
        $memTotalKB = $totalMatches[1]; $memAvailKB = $availMatches[1]; $memUsedKB = $memTotalKB - $memAvailKB;
        $memTotal = round($memTotalKB / 1024 / 1024, 1); $memUsed = round($memUsedKB / 1024 / 1024, 1); $memPercent = round(($memUsedKB / $memTotalKB) * 100, 1);
    }
}

$diskTotal = round(disk_total_space("/") / 1024 / 1024 / 1024, 1); $diskFree = round(disk_free_space("/") / 1024 / 1024 / 1024, 1);
$diskUsed = round($diskTotal - $diskFree, 1); $diskPercent = ($diskTotal > 0) ? round(($diskUsed / $diskTotal) * 100, 1) : 0;

$serverData = []; $osInfo = []; $storageData = []; $userCount = 0;
if(file_exists('/opt/scp/servers.json')) $serverData = json_decode(file_get_contents('/opt/scp/servers.json'), true);
if(file_exists('/opt/scp/os_info.json')) $osInfo = json_decode(file_get_contents('/opt/scp/os_info.json'), true);
if(file_exists('/opt/scp/storage.json')) $storageData = json_decode(file_get_contents('/opt/scp/storage.json'), true);

$snapshot_file = '/opt/scp/servers_snapshot.json'; $deleted_file = '/opt/scp/deleted_history.json';
if (isset($serverData['servers']) && is_array($serverData['servers'])) {
    $currentMap = []; foreach ($serverData['servers'] as $srv) { $id = $srv['id'] ?? $srv['name'] ?? ''; if ($id !== '') $currentMap[$id] = $srv; }
    if (file_exists($snapshot_file)) {
        $snapshotData = json_decode(file_get_contents($snapshot_file), true); $deletedHistoryArr = [];
        if (file_exists($deleted_file)) { $rawDel = json_decode(file_get_contents($deleted_file), true); if (is_array($rawDel)) { foreach ($rawDel as $k => $v) { $deletedHistoryArr[] = $v; } } }
        $changed = false;
        if (is_array($snapshotData)) {
            foreach ($snapshotData as $snapId => $snapSrv) {
                if (!isset($currentMap[$snapId])) {
                    $alreadyExists = false;
                    foreach ($deletedHistoryArr as $d) { if (($d['name'] ?? '') === ($snapSrv['name'] ?? '') && ($snapSrv['name'] ?? '') !== '') { $alreadyExists = true; break; } }
                    if (!$alreadyExists) {
                        $snapSrv['deleted_at'] = date('Y-m-d H:i:s'); $deletedHistoryArr[] = $snapSrv; $changed = true;
                        sendWebhookAlert($WEBHOOK_URL, $snapSrv['name'] ?? '-', $snapSrv['addresses'][0]['ip_addresses'][0]['ip_address'] ?? '-', '삭제');
                    }
                }
            }
        }
        foreach ($currentMap as $currId => $currSrv) {
            if (!isset($snapshotData[$currId])) {
                $changed = true; sendWebhookAlert($WEBHOOK_URL, $currSrv['name'] ?? '-', $currSrv['addresses'][0]['ip_addresses'][0]['ip_address'] ?? '-', '생성');
            }
        }
        if ($changed) @file_put_contents($deleted_file, json_encode($deletedHistoryArr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    @file_put_contents($snapshot_file, json_encode($currentMap, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

$vmCount=0; $gpuCount=0; $totalCount=0; $totalVcpu=0; $totalRam=0;
$osCounts = ['Rocky' => 0, 'Ubuntu' => 0, 'Windows' => 0, 'UNREACHABLE' => 0, 'OTHER' => 0];
$monthlyCreated = []; $monthlyDeleted = []; $allYm = []; $jiraEvents = [];

if(isset($serverData['servers'])){
    foreach($serverData['servers'] as $server){
        $ip = $server['addresses'][0]['ip_addresses'][0]['ip_address'] ?? ''; $name = $server['name'] ?? '-';
        $type = 'VM'; $flavor = strtolower($server['server_type']['name'] ?? '');
        if(strpos($flavor,'gpu') !== false || strpos($flavor,'a100') !== false || strpos($flavor,'v100') !== false){ $type = 'GPU'; $gpuCount++; } else { $vmCount++; }
        $totalCount++; $totalVcpu += ($server['server_type']['vcpus'] ?? 0); $totalRam += ($server['server_type']['ram'] ?? 0);
        $os = $osInfo[$ip]['os'] ?? '-';
        if(stripos($os,'Rocky')!==false) $osCounts['Rocky']++; elseif(stripos($os,'Ubuntu')!==false) $osCounts['Ubuntu']++; elseif(stripos($os,'Windows')!==false) $osCounts['Windows']++; elseif($os=='UNREACHABLE' || $os=='접속불가') $osCounts['UNREACHABLE']++; else $osCounts['OTHER']++;

        $createdStr = $server['created_at'] ?? $server['created_dt'] ?? $server['createdAt'] ?? $server['createdDt'] ?? '';
        if(!empty($createdStr)) {
            $date = substr($createdStr, 0, 10);
            $ym = substr($createdStr, 0, 7);
            if(preg_match('/^\d{4}-\d{2}$/', $ym)) {
                $monthlyCreated[$ym] = ($monthlyCreated[$ym] ?? 0) + 1;
                $allYm[$ym] = true;
                $jiraEvents[] = ['name' => $name, 'ip' => $ip, 'type' => '생성', 'date' => $date, 'ym' => $ym];
            }
        }
    }
}
$deletedHistory = [];
if(file_exists('/opt/scp/deleted_history.json')){
    $deletedHistory = json_decode(file_get_contents('/opt/scp/deleted_history.json'), true);
    if (is_array($deletedHistory)) {
        foreach($deletedHistory as $del_srv){
            $name = $del_srv['name'] ?? '-'; $ip = $del_srv['addresses'][0]['ip_addresses'][0]['ip_address'] ?? '-';
            $deletedStr = $del_srv['deleted_at'] ?? $del_srv['deletedDt'] ?? '';
            if(!empty($deletedStr)) {
                $date = substr($deletedStr, 0, 10);
                $ym = substr($deletedStr, 0, 7);
                if(preg_match('/^\d{4}-\d{2}$/', $ym)) {
                    $monthlyDeleted[$ym] = ($monthlyDeleted[$ym] ?? 0) + 1;
                    $allYm[$ym] = true;
                    $jiraEvents[] = ['name' => $name, 'ip' => $ip, 'type' => '삭제', 'date' => $date, 'ym' => $ym];
                }
            }
        }
    }
}

try {
    $db = new PDO("sqlite:/opt/scp/dashboard_users.db");
    $stmt = $db->query("SELECT * FROM jira_tickets"); $savedTickets = $stmt->fetchAll(PDO::FETCH_ASSOC); $ticketMap = [];
    foreach($savedTickets as $t) { $key = $t['server_name'] . '_' . $t['event_type'] . '_' . $t['event_date']; $ticketMap[$key] = $t['jira_number']; }
    foreach($jiraEvents as &$ev) { $key = $ev['name'] . '_' . $ev['type'] . '_' . $ev['date']; $ev['ticket'] = $ticketMap[$key] ?? ''; }
} catch (Exception $e) { foreach($jiraEvents as &$ev) { $ev['ticket'] = ''; } }

ksort($allYm); $ymLabels = array_keys($allYm); $ymCountsCreated = []; $ymCountsDeleted = [];
foreach($ymLabels as $ym) { $ymCountsCreated[] = $monthlyCreated[$ym] ?? 0; $ymCountsDeleted[] = $monthlyDeleted[$ym] ?? 0; }
$storageTotalBuckets = $storageData['totalBuckets'] ?? 0; $storageTotalSize = $storageData['totalSizeGB'] ?? '-';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>SCP 통합 관제 대시보드</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js'></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<script id="jira-data" type="application/json">
<?php echo empty($jiraEvents) ? '[]' : json_encode($jiraEvents, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>
</script>

<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Malgun Gothic', sans-serif; background: #f1f5f9; color: #1e293b; display: flex; height: 100vh; overflow: hidden; transition: background 0.3s, color 0.3s; }

.sidebar { width: 240px; background: #0f172a; color: #f8fafc; display: flex; flex-direction: column; justify-content: space-between; flex-shrink: 0; }
.sidebar-header { padding: 20px; font-size: 18px; font-weight: bold; border-bottom: 1px solid #334155; display: flex; align-items: center; gap: 10px; }
.sidebar-menu { list-style: none; padding: 15px 0; flex: 1; overflow-y: auto; }
.sidebar-menu li a { display: flex; align-items: center; gap: 12px; padding: 12px 20px; color: #94a3b8; text-decoration: none; font-size: 14px; font-weight: bold; transition: all 0.2s; }
.sidebar-menu li a:hover, .sidebar-menu li.active a { background: #1e293b; color: #38bdf8; border-left: 4px solid #38bdf8; }
.sidebar-footer { padding: 15px 20px; border-top: 1px solid #334155; font-size: 13px; color: #94a3b8; }
.session-timer { background: #1e293b; padding: 8px; border-radius: 6px; color: #38bdf8; font-weight: bold; text-align: center; margin-top: 8px; font-size: 12px; }

.btn-theme, .btn-logout { display: block; margin-top: 10px; width: 100%; text-align: center; padding: 8px; border-radius: 6px; font-weight: bold; cursor: pointer; transition: 0.2s; border: none; text-decoration: none;}
.btn-theme { background: #334155; color: white; } .btn-theme:hover { background: #475569; }
.btn-logout { background: #ef4444; color: white; margin-top: 8px;} .btn-logout:hover { background: #dc2626; }

.main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; position: relative; }
.top-header { background: white; padding: 15px 30px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; transition: background 0.3s; }
.top-title { font-size: 20px; font-weight: bold; color: #0f172a; }
.content-body { padding: 25px 30px; }
.tab-page { display: none; } .tab-page.active { display: block; }

#routineAlarmBanner {
    display: none; position: absolute; top: 0; left: 0; width: 100%; background: #ef4444; color: white;
    z-index: 1000; padding: 10px 15px; font-weight: bold; font-size: 15px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    animation: slideDown 0.5s ease-out;
}
@keyframes slideDown { from { top: -60px; } to { top: 0; } }

.btn-alarm-action { background: white; border: none; padding: 0 15px; border-radius: 20px; font-weight: bold; cursor: pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.1); transition: 0.2s; white-space: nowrap; height: 34px; display: inline-flex; align-items: center; justify-content: center; }
.btn-alarm-action:hover { filter: brightness(0.9); }

.notes-layout { display: flex; gap: 20px; height: calc(100vh - 120px); }
.note-sidebar { width: 300px; display: flex; flex-direction: column; flex-shrink: 0; }
.note-list-card { background: white; border-radius: 12px; border: 1px solid #e2e8f0; display: flex; flex-direction: column; flex: 1; overflow: hidden; }
.note-list-header { padding: 15px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; font-weight: bold; background: #f8fafc; font-size: 14px; }
.note-item { padding: 15px; border-bottom: 1px solid #f1f5f9; cursor: pointer; transition: background 0.2s; }
.note-item:hover, .note-item.active { background: #f0f9ff; border-left: 4px solid #3b82f6; padding-left: 11px; }
.note-item-title { font-weight: bold; color: #0f172a; margin-bottom: 4px; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.note-item-date { font-size: 11px; color: #94a3b8; }
.note-editor-card { background: white; border-radius: 12px; border: 1px solid #e2e8f0; flex: 1; display: flex; flex-direction: column; padding: 30px 40px; position: relative; }
.note-title-input { font-size: 26px; font-weight: bold; color: #0f172a; border: none; outline: none; width: 100%; margin-bottom: 10px; background: transparent; }
.note-title-input::placeholder { color: #cbd5e1; }
.editor-toolbar { display: flex; gap: 5px; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #e2e8f0; align-items: center; }
.editor-btn { background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 4px; padding: 5px 10px; font-size: 13px; cursor: pointer; font-weight: bold; color: #334155; transition: 0.2s; }
.editor-btn:hover { background: #e2e8f0; }
.note-body-editor { flex: 1; outline: none; width: 100%; overflow-y: auto; font-size: 15px; line-height: 1.6; color: #334155; font-family: 'Malgun Gothic', sans-serif; background: transparent; }
.note-body-editor[contenteditable]:empty:before { content: "여기에 내용을 작성하세요... (서식 지정 가능)"; color: #cbd5e1; cursor: text; }

.routine-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 15px; border-bottom: 1px solid #f1f5f9; }
.routine-title { font-size: 13px; font-weight: bold; color: #1e293b; }
.btn-resolve { background: #10b981; color: white; border: none; padding: 5px 10px; border-radius: 6px; font-size: 12px; font-weight: bold; cursor: pointer; }
.history-item { font-size: 13px; color: #475569; padding: 10px 15px; border-bottom: 1px dashed #e2e8f0; display:flex; gap:10px; align-items:center; }

.cards-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 25px; }
.card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.04); border: 1px solid #e2e8f0; transition: background 0.3s, border 0.3s; }
.card-title { color: #64748b; font-size: 13px; font-weight: bold; margin-bottom: 8px; }
.card-value { font-size: 28px; font-weight: bold; color: #0f172a; }
.card-sub { font-size: 12px; color: #94a3b8; margin-top: 4px; }

.charts-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 25px; }
.chart-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.04); border: 1px solid #e2e8f0; text-align: center; transition: background 0.3s, border 0.3s; }
.chart-title { font-size: 15px; font-weight: bold; color: #334155; margin-bottom: 15px; text-align: left; }
.chart-box { height: 220px; position: relative; }

.mgmt-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.04); border: 1px solid #e2e8f0; margin-bottom: 25px; transition: background 0.3s, border 0.3s; }
.mgmt-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #f1f5f9; padding-bottom: 12px; transition: border 0.3s; }
.mgmt-title { font-size: 16px; font-weight: bold; color: #0f172a; display: flex; align-items: center; gap: 8px; }
.mgmt-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; }
.metric-box { background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0; transition: background 0.3s, border 0.3s; }
.metric-label { font-size: 13px; font-weight: bold; color: #64748b; margin-bottom: 8px; display: flex; justify-content: space-between; }
.metric-val { font-size: 20px; font-weight: bold; color: #0f172a; margin-bottom: 8px; }
.progress-bg { background: #e2e8f0; height: 10px; border-radius: 5px; overflow: hidden; }
.progress-bar-fill { height: 100%; border-radius: 5px; transition: width 0.3s ease; }

.toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px; }
.search, .filter-select { padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; }
.search { width: 250px; } .filter-select { background: white; color: #475569; font-weight: bold; margin-right: 5px; }

.btn-excel { background: #16a34a; color: white; border: none; padding: 8px 15px; border-radius: 6px; font-weight: bold; cursor: pointer; }
.btn-blue { background: #2563eb; color: white; border: none; padding: 8px 15px; border-radius: 6px; font-weight: bold; cursor: pointer; } .btn-blue:hover { background: #1d4ed8; }
.btn-sm-action { background: #3b82f6; color: white; border: none; padding: 5px 10px; border-radius: 4px; font-weight: bold; font-size: 12px; cursor: pointer; margin-right: 3px; }
.btn-sm-danger { background: #ef4444; color: white; border: none; padding: 5px 10px; border-radius: 4px; font-weight: bold; font-size: 12px; cursor: pointer; }

.table-container { width: 100%; overflow-x: auto; background: white; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.04); margin-bottom: 20px; max-height: calc(100vh - 280px); }
table { width: 100%; border-collapse: collapse; white-space: nowrap; }
th { background: #1e293b; color: white; padding: 12px; text-align: left; font-size: 13px; font-weight:bold; position: sticky; top: 0; z-index: 10; }
td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #334155; }
tr:hover { background: #f8fafc; }

.server-link { color: #2563eb; font-weight: bold; cursor: pointer; text-decoration: underline; }
.status-active { color: #16a34a; font-weight: bold; } .status-error { color: #dc2626; font-weight: bold; }
.os-rocky { color: #2563eb; font-weight: bold; } .os-ubuntu { color: #7c3aed; font-weight: bold; }
.os-win { color: #475569; font-weight: bold; } .os-unreachable { color: #dc2626; font-weight: bold; }

.ansible-box { background: white; padding: 15px; border-radius: 8px; border: 1px solid #cbd5e1; margin-bottom: 10px; display: flex; gap: 10px; align-items: center; }
.ansible-box input[type="text"] { flex: 1; padding: 9px; border: 1px solid #ccc; border-radius: 6px; font-family: monospace; }
.ansible-box input[type="file"] { border: 1px solid #ccc; padding: 6px; border-radius: 6px; font-size: 13px; }
.btn-exec { background: #10b981; color: white; border: none; padding: 9px 15px; border-radius: 6px; font-weight: bold; cursor: pointer; }
.btn-upload { background: #3b82f6; color: white; border: none; padding: 9px 15px; border-radius: 6px; font-weight: bold; cursor: pointer; }
.terminal-output { background: #1e1e1e; color: #a3be8c; padding: 15px; border-radius: 8px; font-family: monospace; font-size: 13px; white-space: pre-wrap; max-height: 300px; overflow-y: auto; display: none; margin-bottom: 20px; }

.modal-overlay { position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.6); display:none; justify-content:center; align-items:center; z-index: 9999; }
.modal-card { background: white; width: 900px; max-width: 95%; border-radius: 12px; padding: 25px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); position: relative; max-height:90vh; display:flex; flex-direction:column; }
.modal-header { display:flex; justify-content:space-between; align-items:center; border-bottom: 1px solid #e2e8f0; padding-bottom: 15px; margin-bottom: 20px; flex-shrink:0; }
.modal-title { font-size: 18px; font-weight: bold; color: #0f172a; }
.btn-close-modal { background: none; border: none; font-size: 20px; cursor: pointer; color: #64748b; }
.modal-content { flex:1; overflow-y:auto; }

.badge-good { background: #dcfce7; color: #15803d; padding: 4px 10px; border-radius: 12px; font-weight: bold; font-size: 12px; display: inline-block; min-width: 60px; text-align: center; }
.badge-warn { background: #fef08a; color: #b45309; padding: 4px 10px; border-radius: 12px; font-weight: bold; font-size: 12px; display: inline-block; min-width: 60px; text-align: center; }
.badge-bad  { background: #fee2e2; color: #b91c1c; padding: 4px 10px; border-radius: 12px; font-weight: bold; font-size: 12px; display: inline-block; min-width: 60px; text-align: center; }

.kisa-group-box { background: white; border: 1px solid #cbd5e1; border-radius: 8px; padding: 15px; margin-bottom: 12px; }
.kisa-group-title { font-weight: bold; font-size: 14px; color: #1e293b; margin-bottom: 10px; border-bottom: 1px solid #f1f5f9; padding-bottom: 6px; }

#calendar { font-family: 'Malgun Gothic', sans-serif; background: white; padding: 20px; border-radius: 8px; }
.fc-event { cursor: pointer; padding: 2px 4px; font-size: 12px; border: none; }
.fc-toolbar-title { font-size: 18px !important; font-weight: bold; color: #0f172a; }
.fc-button-primary { background-color: #3b82f6 !important; border-color: #2563eb !important; }

/* 다크 모드 (Dark Mode) 지원 */
body.dark-mode { background: #0f172a; color: #f8fafc; }
body.dark-mode .top-header { background: #1e293b; border-bottom: 1px solid #0f172a; } body.dark-mode .top-title { color: #f8fafc; }
body.dark-mode .card, body.dark-mode .chart-card, body.dark-mode .mgmt-card, body.dark-mode .ansible-box, body.dark-mode .table-container { background: #1e293b; border-color: #0f172a; box-shadow: none; }
body.dark-mode .card-value { color: #38bdf8 !important; } body.dark-mode .card-title, body.dark-mode .chart-title, body.dark-mode .mgmt-title { color: #e2e8f0; }
body.dark-mode .mgmt-header { border-color: #334155; } body.dark-mode .metric-box { background: #0f172a; border-color: #0f172a; }
body.dark-mode .metric-label { color: #cbd5e1; } body.dark-mode .metric-val { color: #f8fafc; }
body.dark-mode table, body.dark-mode th, body.dark-mode td { background: #1e293b; border-color: #0f172a; color: #cbd5e1; }
body.dark-mode th { background: #0f172a; border-color: #334155; color: #f8fafc; } body.dark-mode tr:hover { background: #334155 !important; }
body.dark-mode .search, body.dark-mode .filter-select, body.dark-mode input[type="text"], body.dark-mode input[type="file"], body.dark-mode input[type="date"] { background: #0f172a; color: #f8fafc; border: 1px solid #334155; }
body.dark-mode .modal-card { background: #1e293b; } body.dark-mode .modal-title { color: #f8fafc; } body.dark-mode .modal-header { border-color: #334155; }
body.dark-mode .terminal-output { background: #000000; border: 1px solid #334155; }
body.dark-mode .status-active { color: #4ade80; } body.dark-mode .status-error { color: #f87171; }
body.dark-mode .os-rocky { color: #60a5fa; } body.dark-mode .os-ubuntu { color: #a78bfa; } body.dark-mode .os-win { color: #cbd5e1; }
body.dark-mode .server-link { color: #60a5fa; } body.dark-mode .net-box { background: #1e293b; border-color: #334155; }
body.dark-mode .kisa-group-box { background: #1e293b; border-color: #334155; } body.dark-mode .kisa-group-title { color: #f8fafc; border-color: #334155; }
body.dark-mode #calendar { background: #1e293b; color: #f8fafc; } body.dark-mode .fc-theme-standard td, body.dark-mode .fc-theme-standard th { border-color: #334155; } body.dark-mode .fc-daygrid-day { background-color: #1e293b; } body.dark-mode .fc-col-header-cell { background-color: #0f172a; } body.dark-mode .fc-daygrid-day-number { color: #f8fafc; } body.dark-mode .fc .fc-toolbar-title { color: #f8fafc; }
body.dark-mode .note-list-card, body.dark-mode .note-editor-card { background: #1e293b; border-color: #0f172a; }
body.dark-mode .note-list-header { background: #0f172a; border-color: #334155; color: #f8fafc; }
body.dark-mode .note-item, body.dark-mode .routine-item { border-color: #334155; }
body.dark-mode .note-item:hover, body.dark-mode .note-item.active { background: #334155; border-color: #38bdf8; }
body.dark-mode .note-item-title, body.dark-mode .note-title-input { color: #f8fafc; }
body.dark-mode .note-body-editor { color: #cbd5e1; }
body.dark-mode .editor-btn { background: #0f172a; border-color: #334155; color: #cbd5e1; }
body.dark-mode .editor-btn:hover { background: #334155; }
body.dark-mode .routine-title { color: #f8fafc; }
body.dark-mode .history-item { border-color: #334155; color: #cbd5e1; }
body.dark-mode #newRoutineTimeModal, body.dark-mode #newRoutineInputModal, body.dark-mode #historyMonthModal, body.dark-mode #historySearchInputModal { background: #0f172a; color: #f8fafc; border: 1px solid #334155; }
</style>
</head>

<body>

<div class="sidebar">
    <div>
        <div class="sidebar-header">☁️ SCP 관제 플랫폼</div>
        <ul class="sidebar-menu">
            <li class="active"><a href="#" onclick="showTab('page-dashboard', this)">📊 대시보드</a></li>
            <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
            <li><a href="#" onclick="showTab('page-notes', this)">📝 내 작업 노트</a></li>
            <?php endif; ?>
            <li><a href="#" onclick="showTab('page-server', this)">🖥️ VM 서버 관리</a></li>
            <li><a href="#" onclick="showTab('page-storage', this)">📦 Object Storage</a></li>
            <li><a href="#" onclick="showTab('page-dbaas', this)">🗄️ DBaaS 관리 (MySQL)</a></li>
            <li><a href="#" onclick="showTab('page-iam', this)">🔑 IAM 권한 관리</a></li>
            <li><a href="#" onclick="showTab('page-sg', this)">🛡 Security Group 규칙</a></li>
            <li><a href="#" onclick="showTab('page-lb', this)">⚖️ Load Balancer 관리</a></li>
            <li><a href="#" onclick="showTab('page-jira', this)">🎫 Jira 요청 관리</a></li>
            <li><a href="#" onclick="showTab('page-network', this)">🌐 통신 확인</a></li>
            <li><a href="#" onclick="showTab('page-security', this)">🛡️ KISA 취약점 진단</a></li>
            <li><a href="#" onclick="showTab('page-docker', this)">🐳 Docker 관리</a></li>
            <li><a href="#" onclick="showTab('page-calendar', this)">📅 작업 일정 캘린더</a></li>
            <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
                <li><a href="#" onclick="showTab('page-alerts', this)">🚨 알림 현황</a></li>
                <li><a href="admin_users.php">⚙️ 회원 관리</a></li>
            <?php endif; ?>
        </ul>
    </div>
    <div class="sidebar-footer">
        👤 <strong><?php echo htmlspecialchars($_SESSION['user_name'] ?? $_SESSION['user_id']); ?></strong>님
        <div class="session-timer">🔒 세션 만료: <span id="timer-display"><?php echo (($_SESSION['user_role'] ?? '') === 'admin') ? '60:00' : '10:00'; ?></span></div>
        <button class="btn-theme" onclick="toggleTheme()" id="theme-icon">🌙 다크 모드</button>
        <a href="logout.php" class="btn-logout">로그아웃</a>
    </div>
</div>

<div class="main-content">

    <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
    <div id="routineAlarmBanner"></div>
    <?php endif; ?>

    <div class="top-header">
        <div style="display: flex; align-items: center; gap: 15px;">
            <div class="top-title" id="page-title-text">📊 메인 통합 대시보드</div>
            <a href="https://123.41.32.12:9000/" target="_blank" class="btn-blue" style="text-decoration: none; font-size: 13px; padding: 6px 12px; display: inline-flex; align-items: center;">🛡️ 서버접근제어</a>
            <button class="btn-blue" onclick="downloadMonthlyReport()" style="background: #10b981; text-decoration: none; font-size: 13px; padding: 6px 12px; display: inline-flex; align-items: center;">📊 월간 통합 보고서 다운로드</button>
            <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
            <button class="btn-blue" onclick="openRoutineModal()" style="background: #f59e0b; text-decoration: none; font-size: 13px; padding: 6px 12px; display: inline-flex; align-items: center;">⏰ 알림 설정 및 이력 관리</button>
            <?php endif; ?>
        </div>
        <div style="font-size: 13px; color: #64748b;">📅 시스템 시각: <span id="systemTimeDisplay"><?php echo date('Y-m-d H:i'); ?></span></div>
    </div>

    <div class="content-body">

        <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
        <div id="page-notes" class="tab-page">
            <div class="notes-layout">
                <div class="note-sidebar">
                    <div class="note-list-card">
                        <div class="note-list-header">
                            <span>📝 내 작업 노트</span>
                            <button onclick="createNewNote()" style="background:none; border:none; font-size:18px; cursor:pointer; color:#3b82f6;" title="새 노트 작성">➕</button>
                        </div>
                        <div id="noteListArea" style="flex:1; overflow-y:auto;"></div>
                    </div>
                </div>

                <div class="note-editor-card">
                    <div style="position: absolute; top: 20px; right: 25px; display: flex; gap: 8px;">
                        <button onclick="exportNoteToPDF()" style="background:#8b5cf6; color:white; border:none; padding:8px 15px; border-radius:6px; font-weight:bold; cursor:pointer; font-size:12px; display:none;" id="btnExportPdf">📄 PDF 내보내기</button>
                        <button onclick="deleteCurrentNote()" style="background:#ef4444; color:white; border:none; padding:8px 15px; border-radius:6px; font-weight:bold; cursor:pointer; font-size:12px; display:none;" id="btnDeleteNote">🗑 삭제</button>
                        <button onclick="saveCurrentNote()" style="background:#10b981; color:white; border:none; padding:8px 15px; border-radius:6px; font-weight:bold; cursor:pointer; font-size:12px;">💾 저장 (Ctrl+S)</button>
                    </div>
                    <input type="hidden" id="currentNoteId" value="">
                    <input type="text" id="currentNoteTitle" class="note-title-input" placeholder="제목 없는 노트">

                    <div class="editor-toolbar">
                        <button class="editor-btn" onclick="formatDoc('bold')" title="굵게 (Ctrl+B)"><b>B</b></button>
                        <button class="editor-btn" onclick="formatDoc('italic')" title="기울임 (Ctrl+I)"><i>I</i></button>
                        <button class="editor-btn" onclick="formatDoc('underline')" title="밑줄 (Ctrl+U)"><u>U</u></button>
                        <button class="editor-btn" onclick="formatDoc('strikethrough')" title="취소선"><s>S</s></button>
                        <span style="border-left:1px solid #cbd5e1; height:20px; margin:0 5px;"></span>
                        <input type="color" id="fontColor" class="editor-btn" style="padding:0; width:30px; height:30px; border-radius:4px;" onchange="formatDoc('foreColor', this.value)" title="글자 색상">
                        <input type="color" id="hiliteColor" class="editor-btn" style="padding:0; width:30px; height:30px; border-radius:4px;" onchange="formatDoc('hiliteColor', this.value)" title="배경 색상" value="#ffff00">
                        <span style="border-left:1px solid #cbd5e1; height:20px; margin:0 5px;"></span>
                        <select class="editor-btn" onchange="formatDoc('fontSize', this.value)" style="font-weight:normal;">
                            <option value="">크기</option><option value="1">가장 작게</option><option value="2">작게</option>
                            <option value="3">보통</option><option value="4">크게</option><option value="5">더 크게</option><option value="6">가장 크게</option>
                        </select>
                        <button class="editor-btn" onclick="formatDoc('insertUnorderedList')" title="글머리 기호">• 목록</button>
                        <button class="editor-btn" onclick="formatDoc('insertOrderedList')" title="번호 매기기">1. 번호</button>
                    </div>
                    <div id="currentNoteContent" class="note-body-editor" contenteditable="true"></div>
                    <div id="noteSaveStatus" style="position:absolute; bottom:20px; right:25px; font-size:12px; color:#94a3b8; font-weight:bold;"></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div id="page-dashboard" class="tab-page active">
            <div class="cards-grid">
                <div class="card"><div class="card-title">전체 서버</div><div class="card-value" style="color: #2563eb;"><?php echo $totalCount; ?> <span style="font-size: 16px;">대</span></div><div class="card-sub">VM: <?php echo $vmCount; ?> / GPU: <?php echo $gpuCount; ?></div></div>
                <div class="card"><div class="card-title">총 vCPU 할당량</div><div class="card-value" style="color: #0284c7;"><?php echo $totalVcpu; ?> <span style="font-size: 16px;">Core</span></div><div class="card-sub">인프라 코어 총합</div></div>
                <div class="card"><div class="card-title">총 메모리 할당량</div><div class="card-value" style="color: #7c3aed;"><?php echo $totalRam; ?> <span style="font-size: 16px;">GB</span></div><div class="card-sub">인프라 RAM 총합</div></div>
                <div class="card"><div class="card-title">Object Storage</div><div class="card-value" style="color: #059669;"><?php echo $storageTotalSize; ?> <span style="font-size: 16px;">GB</span></div><div class="card-sub">총 <?php echo $storageTotalBuckets; ?>개 버킷 사용 중</div></div>
                <div class="card"><div class="card-title">대시보드 사용자</div><div class="card-value" style="color: #d97706;"><?php echo $userCount; ?> <span style="font-size: 16px;">명</span></div><div class="card-sub">등록된 회원 수</div></div>
            </div>
            <div class="charts-grid">
                <div class="chart-card"><div class="chart-title">🖥️ 운영체제(OS) 분포 현황</div><div class="chart-box"><canvas id="osChart"></canvas></div></div>
                <div class="chart-card"><div class="chart-title">⚙️ 서버 유형(Type) 분포 현황</div><div class="chart-box"><canvas id="typeChart"></canvas></div></div>
            </div>
            <div class="mgmt-card">
                <div class="mgmt-header"><div class="mgmt-title">🖥 중앙 관리서버 (test-mgmt-01) 실시간 성능 모니터링</div><div style="font-size: 12px; color: #16a34a; font-weight: bold;">● 정상 작동 중</div></div>
                <div class="mgmt-grid">
                    <div class="metric-box"><div class="metric-label"><span>⚡ CPU 사용률</span><span><?php echo $cpuUsage; ?>%</span></div><div class="metric-val"><?php echo $cpuUsage; ?> %</div><div class="progress-bg"><div class="progress-bar-fill" style="width: <?php echo $cpuUsage; ?>%; background: <?php echo ($cpuUsage > 80) ? '#ef4444' : '#2563eb'; ?>;"></div></div></div>
                    <div class="metric-box"><div class="metric-label"><span>💾 메모리 (RAM)</span><span><?php echo $memPercent; ?>%</span></div><div class="metric-val"><?php echo $memUsed; ?> GB / <?php echo $memTotal; ?> GB</div><div class="progress-bg"><div class="progress-bar-fill" style="width: <?php echo $memPercent; ?>%; background: <?php echo ($memPercent > 80) ? '#f59e0b' : '#7c3aed'; ?>;"></div></div></div>
                    <div class="metric-box"><div class="metric-label"><span>💽 디스크 사용량 ( / )</span><span><?php echo $diskPercent; ?>%</span></div><div class="metric-val"><?php echo $diskUsed; ?> GB / <?php echo $diskTotal; ?> GB</div><div class="progress-bg"><div class="progress-bar-fill" style="width: <?php echo $diskPercent; ?>%; background: <?php echo ($diskPercent > 85) ? '#ef4444' : '#059669'; ?>;"></div></div></div>
                </div>
            </div>
            <div class="mgmt-card">
                <div class="mgmt-header"><div class="mgmt-title">📅 년월별 VM 라이프사이클 (생성/삭제 추이)</div><div style="font-size: 13px; color: #64748b; font-weight: bold;">총 <?php echo array_sum($ymCountsCreated); ?>대 생성 / <?php echo array_sum($ymCountsDeleted); ?>대 삭제 이력 수집됨</div></div>
                <div style="height: 280px; position: relative;"><canvas id="monthlyChart"></canvas></div>
            </div>
        </div>

        <div id="page-server" class="tab-page">
            <div style="margin-bottom: 5px;"><strong>🔑 접속 키 선택:</strong> <select id="pemSelect" class="filter-select"><option value="works_mgmt.pem">works_mgmt.pem</option><option value="rocky.pem">rocky.pem</option></select></div>
            <div class="ansible-box"><strong>⚙️ 쉘 명령:</strong> <input type="text" id="ansibleCommand" placeholder="리눅스 명령어 입력 (예: uptime, df -h)"> <button class="btn-exec" onclick="executeAnsible()">🚀 실행</button></div>
            <div class="ansible-box" style="margin-bottom: 20px;"><strong>📂 파일 배포:</strong> <input type="file" id="uploadFile"> <input type="text" id="destPath" placeholder="원격지 저장 전체 경로 (예: /home/rocky/deploy/test.txt)"> <button class="btn-upload" onclick="deployFile()">📤 대상 서버에 업로드</button></div>
            <div class="ansible-box" style="margin-bottom: 20px; background: #f0f9ff; border-color: #bae6fd;"><strong>🔍 통합 로그 검색:</strong> <input type="text" id="logPath" placeholder="대상 파일" style="width: 230px;" value="/var/log/messages"> <input type="text" id="logKeyword" placeholder="검색어 입력 (예: error)" style="flex: 1;"> <button class="btn-blue" onclick="searchGlobalLogs()" style="background: #0284c7;">전체 서버 검색</button></div>
            <div id="terminalResult" class="terminal-output"></div>

            <div class="toolbar">
                <div>
                    <select class="filter-select" onchange="filterType(this.value)"><option value="ALL">전체 유형</option><option value="VM">VM</option><option value="GPU">GPU</option></select>
                    <select class="filter-select" onchange="filterOS(this.value)"><option value="ALL">전체 OS</option><option value="Rocky">Rocky</option><option value="Ubuntu">Ubuntu</option><option value="Windows">Windows</option><option value="UNREACHABLE">접속불가</option></select>
                    <button class="btn-excel" onclick="exportServerExcel()">📊 Excel 다운로드</button>
                </div>
                <input type="text" id="search" class="search" placeholder="서버명 검색" onkeyup="applyFilter()">
            </div>
            <div class="table-container">
                <table id="serverTable">
                    <thead>
                        <tr>
                            <th style="width: 40px;"><input type="checkbox" onclick="toggleAll(this)"></th>
                            <th>서버명 (클릭시 자원 조회)</th>
                            <th>유형</th>
                            <th>IP 주소</th>
                            <th>🛡️ 방화벽(SG)</th>
                            <th>상태</th>
                            <th>vCPU</th>
                            <th>메모리(GB)</th>
                            <th>운영체제</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if(isset($serverData['servers'])){
                            foreach($serverData['servers'] as $server){
                                $ip = $server['addresses'][0]['ip_addresses'][0]['ip_address'] ?? ''; 
                                $type='VM';
                                if(strpos(strtolower($server['server_type']['name'] ?? ''),'gpu')!==false) $type='GPU';
                                
                                $os = $osInfo[$ip]['os'] ?? '-'; 
                                $osClass=''; 
                                $osFilter='OTHER';
                                if(stripos($os,'Rocky')!==false){ $osClass='os-rocky'; $osFilter='Rocky'; } 
                                elseif(stripos($os,'Ubuntu')!==false){ $osClass='os-ubuntu'; $osFilter='Ubuntu'; } 
                                elseif(stripos($os,'Windows')!==false){ $osClass='os-win'; $osFilter='Windows'; } 
                                elseif($os=='UNREACHABLE' || $os=='접속불가'){ $os='접속불가'; $osClass='os-unreachable'; $osFilter='UNREACHABLE'; }
                                
                                $status = $server['state'] ?? '-'; 
                                $statusClass = ($status=='ACTIVE') ? 'status-active' : 'status-error'; 
                                $disabled = ($os === '접속불가' || $ip === '') ? 'disabled' : '';
                                $serverName = htmlspecialchars($server['name'] ?? '-');

                                // 💡 방화벽(SG) 파싱 로직 추가
                                $sgList = [];
                                if (isset($server['security_groups']) && is_array($server['security_groups'])) {
                                    foreach ($server['security_groups'] as $sg) {
                                        $sgName = $sg['security_group_name'] ?? $sg['name'] ?? $sg['id'] ?? '';
                                        if ($sgName) $sgList[] = htmlspecialchars($sgName);
                                    }
                                } elseif (isset($server['securityGroupIds']) && is_array($server['securityGroupIds'])) {
                                    foreach ($server['securityGroupIds'] as $sgId) {
                                        $sgList[] = htmlspecialchars($sgId);
                                    }
                                }
                                $sgDisplay = empty($sgList) ? '<span style="color:#94a3b8; font-size:11px;">미할당</span>' : implode('<br>', $sgList);

                                echo "<tr data-type='{$type}' data-os='{$osFilter}'>
                                        <td><input type='checkbox' class='ip-checkbox' value='{$ip}' {$disabled}></td>
                                        <td><span class='server-link' onclick=\"openServerMetrics('{$serverName}', '{$ip}', '{$os}')\">{$serverName}</span></td>
                                        <td>{$type}</td>
                                        <td>{$ip}</td>
                                        <td style='font-size: 12px; color: #475569; font-weight: bold;'>{$sgDisplay}</td>
                                        <td class='{$statusClass}'>" . ($status=='ACTIVE'?'운영중':$status) . "</td>
                                        <td>{$server['server_type']['vcpus']} Core</td>
                                        <td>{$server['server_type']['ram']} GB</td>
                                        <td class='{$osClass}'>{$os}</td>
                                      </tr>";
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="page-storage" class="tab-page">
            <div class="toolbar" style="justify-content: space-between;">
                <div style="color: #64748b; font-size: 14px; font-weight: bold;">🔗 Endpoint: https://object-store.kr-west1.e.samsungsdscloud.com</div>
                <div><button class="btn-excel" onclick="exportStorageExcel()">📊 Excel 다운로드</button> <input type="text" id="searchStorage" class="search" placeholder="버킷명 검색" onkeyup="applyStorageFilter()" style="margin-left: 5px;"></div>
            </div>
            <div class="table-container">
                <table id="storageTable">
                    <thead><tr><th>버킷명 (Bucket)</th><th>생성일시</th><th>사용 용량 (GB)</th><th>파일 개수</th><th>상태</th></tr></thead>
                    <tbody>
                        <?php
                        if(isset($storageData['buckets'])){
                            foreach($storageData['buckets'] as $bucket){
                                echo "<tr><td><strong>" . htmlspecialchars($bucket['bucketName'] ?? '-') . "</strong></td><td>" . htmlspecialchars($bucket['creationDate'] ?? '-') . "</td><td>" . htmlspecialchars($bucket['sizeGB'] ?? '-') . " GB</td><td>" . htmlspecialchars($bucket['fileCount'] ?? '-') . " 개</td><td><span class='status-active'>" . htmlspecialchars($bucket['status'] ?? 'ACTIVE') . "</span></td></tr>";
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 🗄️ DBaaS (MySQL) 관리 탭 시작 -->
        <div id="page-dbaas" class="tab-page">
            <div class="mgmt-card">
                <div class="mgmt-header">
                    <div class="mgmt-title">🗄️ DBaaS 인스턴스 (MySQL) 통합 관리</div>
                </div>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px; background:#f8fafc; padding:15px; border-radius:8px; border:1px solid #e2e8f0;">
                    <div style="font-size:13px; color:#2563eb; font-weight:bold;">※ 서버 내 Crontab 스케줄러(sync_mysql_info.py)에 의해 자동으로 동기화되는 JSON 데이터를 읽어옵니다.</div>
                    <div style="display:flex; gap:8px;">
                        <input type="text" id="searchDbaas" class="search" placeholder="DB명, IP 등 검색..." onkeyup="filterDbaas()">
                        <button class="btn-blue" onclick="loadDbaasData()" style="padding: 8px 15px; font-size: 13px;">🔄 화면 새로고침</button>
                    </div>
                </div>
                <div class="table-container">
                    <table id="dbaasTable">
                        <thead>
                            <tr>
                                <th style="width: 15%; text-align: center;">엔진 (Engine)</th>
                                <th style="width: 20%;">클러스터명 (Name)</th>
                                <th style="width: 15%; text-align: center;">상태 (Status)</th>
                                <th style="width: 20%; text-align: center;">엔드포인트 (VIP:Port)</th>
                                <th style="width: 15%; text-align: center;">할당 스펙</th>
                                <th style="width: 15%;">서버 노드 (Active/Standby)</th>
                            </tr>
                        </thead>
                        <tbody><tr><td colspan="6" style="text-align:center; padding:30px; color:#94a3b8;">데이터를 불러오는 중입니다...</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
        <!-- 🗄️ DBaaS (MySQL) 관리 탭 끝 -->

        <div id="page-iam" class="tab-page">
            <div class="mgmt-card">
                <div class="mgmt-header"><div class="mgmt-title">🔑 IAM 사용자 목록 조회 (CLI 연동)</div></div>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px; background:#f8fafc; padding:15px; border-radius:8px; border:1px solid #e2e8f0;">
                    <div style="font-size:13px; color:#2563eb; font-weight:bold;">※ Crontab 스케줄러에 의해 10분 주기로 자동 동기화 중입니다.</div>
                    <div style="display:flex; gap:8px;">
                        <select id="filterFieldIam" class="filter-select" onchange="applyCloudFilter('iam', '#iamTable', 'searchIam', 'filterFieldIam')">
                            <option value="ALL">전체 항목 검색</option>
                            <option value="NAME">NAME</option>
                            <option value="ID">ID</option>
                            <option value="POLICIES">POLICIES</option>
                            <option value="ACCESS KEYS">ACCESS KEYS</option>
                            <option value="CREATED AT">CREATED AT</option>
                            <option value="DESCRIPTION">DESCRIPTION</option>
                            <option value="EMAIL">EMAIL</option>
                            <option value="LAST LOGIN AT">LAST LOGIN AT</option>
                        </select>
                        <input type="text" id="searchIam" class="search" placeholder="검색어 입력..." onkeyup="applyCloudFilter('iam', '#iamTable', 'searchIam', 'filterFieldIam')">
                        <button class="btn-excel" onclick="exportDynamicTableToCsv('iamTable', 'SCP_IAM_List')" style="padding: 8px 15px; font-size: 13px;">📊 Excel 다운로드</button>
                        <button class="btn-blue" onclick="loadCloudResource('iam', '#iamTable')" style="padding: 8px 15px; font-size: 13px;">🔄 화면 새로고침</button>
                    </div>
                </div>
                <div class="table-container"><table id="iamTable"><thead><tr><th>데이터 로딩 중...</th></tr></thead><tbody></tbody></table></div>
            </div>
        </div>

        <div id="page-sg" class="tab-page">
            <div class="mgmt-card">
                <div class="mgmt-header"><div class="mgmt-title">🛡️ Security Group (방화벽) 규칙 조회</div></div>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px; background:#f8fafc; padding:15px; border-radius:8px; border:1px solid #e2e8f0;">
                    <div style="font-size:13px; color:#2563eb; font-weight:bold;">※ Crontab 스케줄러에 의해 10분 주기로 자동 동기화 중입니다.</div>
                    <div style="display:flex; gap:8px;">
                        <input type="text" id="searchSg" class="search" placeholder="SG 이름, 설명 검색..." onkeyup="applyCloudFilter('sg', '#sgTable', 'searchSg')">
                        <button class="btn-excel" onclick="exportDynamicTableToCsv('sgTable', 'SCP_SecurityGroup_List')" style="padding: 8px 15px; font-size: 13px;">📊 Excel 다운로드</button>
                        <button class="btn-blue" onclick="loadCloudResource('sg', '#sgTable')" style="padding: 8px 15px; font-size: 13px;">🔄 화면 새로고침</button>
                    </div>
                </div>
                <div class="table-container"><table id="sgTable"><thead><tr><th>데이터 로딩 중...</th></tr></thead><tbody></tbody></table></div>
            </div>
        </div>

        <div id="page-lb" class="tab-page">
            <div class="mgmt-card">
                <div class="mgmt-header"><div class="mgmt-title">⚖️ Load Balancer 대상 및 리스너 그룹 맵핑 현황</div></div>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px; background:#f8fafc; padding:15px; border-radius:8px; border:1px solid #e2e8f0;">
                    <div style="font-size:13px; color:#2563eb; font-weight:bold;">※ 백그라운드에서 추출된 리스너 CSV 데이터를 엑셀 피벗 형태로 파싱합니다.</div>
                    <div style="display:flex; gap:8px;">
                        <input type="text" id="searchLb" class="search" placeholder="LB, 멤버, IP 검색..." onkeyup="applyCloudFilter('lb', '#lbTable', 'searchLb')">
                        <button class="btn-excel" onclick="exportDynamicTableToCsv('lbTable', 'SCP_LoadBalancer_Listeners')" style="padding: 8px 15px; font-size: 13px;">📊 Excel 다운로드</button>
                        <button class="btn-blue" onclick="loadCloudResource('lb', '#lbTable')" style="padding: 8px 15px; font-size: 13px;">🔄 화면 새로고침</button>
                    </div>
                </div>
                <div class="table-container"><table id="lbTable"><thead><tr><th>데이터 로딩 중...</th></tr></thead><tbody></tbody></table></div>
            </div>
        </div>

        <div id="page-jira" class="tab-page">
            <div class="mgmt-card" style="margin-bottom: 20px;">
                <div class="toolbar" style="justify-content: space-between; margin-bottom: 0;">
                    <div><strong style="font-size: 15px; color: #0f172a;">📅 조회할 월 선택: </strong><select id="jiraMonthSelect" class="filter-select" onchange="renderJiraTable()" style="width: 150px;"></select></div>
                    <div style="font-size: 13px; color: #64748b;">※ 서버 변동 내역은 자동으로 감지되어 목록에 나타납니다.</div>
                </div>
            </div>
            <div class="table-container"><table id="jiraTable"><thead><tr><th style="width: 10%; text-align: center;">구분</th><th style="width: 25%;">서버명</th><th style="width: 20%;">서버 IP</th><th style="width: 15%; text-align: center;">발생 날짜</th><th style="width: 15%; text-align: center;">Jira 티켓 번호</th><th style="width: 15%; text-align: center;">관리</th></tr></thead><tbody></tbody></table></div>
        </div>

        <div id="page-network" class="tab-page">
            <div class="mgmt-card">
                <div class="mgmt-header"><div class="mgmt-title">🌐 서버 간 통신 라우팅 및 방화벽 점검</div></div>
                <div style="display:flex; gap:15px; align-items:flex-end; margin-bottom: 25px; flex-wrap:wrap;">
                    <div><strong style="font-size: 13px; color: #64748b; display:block; margin-bottom:5px;">🔑 접속 키</strong><select id="pemSelectNetwork" class="filter-select"><option value="works_mgmt.pem">works_mgmt.pem</option><option value="rocky.pem">rocky.pem</option></select></div>
                    <div><strong style="font-size: 13px; color: #64748b; display:block; margin-bottom:5px;">🖥️ 출발지 서버 (Source)</strong><select id="netSourceIp" class="filter-select" style="width:250px;"><option value="">-- 출발지 서버 선택 --</option>
                    <?php if(isset($serverData['servers'])){ foreach($serverData['servers'] as $server){ $ip = $server['addresses'][0]['ip_addresses'][0]['ip_address'] ?? ''; $name = htmlspecialchars($server['name'] ?? '-'); if($ip !== '') echo "<option value='{$ip}'>[{$name}] {$ip}</option>"; } } ?>
                    </select></div>
                    <div><strong style="font-size: 13px; color: #64748b; display:block; margin-bottom:5px;">🎯 도착지 IP/도메인</strong><input type="text" id="netDest" class="search" placeholder="예: 172.16.118.98" style="width:200px;"></div>
                    <div><strong style="font-size: 13px; color: #64748b; display:block; margin-bottom:5px;">🔀 프로토콜</strong><select id="netProtocol" class="filter-select" style="width:90px;"><option value="TCP">TCP</option><option value="UDP">UDP</option></select></div>
                    <div><strong style="font-size: 13px; color: #64748b; display:block; margin-bottom:5px;">🚪 포트</strong><input type="text" id="netPort" class="search" placeholder="예: 80" style="width:80px;"></div>
                    <button class="btn-blue" onclick="checkNetworkTelnet()" style="padding: 9px 20px;">🚀 통신 테스트</button>
                </div>
                <div id="netVizArea" style="display:none; padding: 40px 20px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; text-align:center; margin-bottom:15px;"></div>
                <div id="netTerminal" class="terminal-output"></div>
            </div>
        </div>

        <div id="page-security" class="tab-page">
            <div class="mgmt-card">
                <div class="mgmt-header"><div class="mgmt-title">🛡️ 주요정보통신기반시설 취약점 자동 진단 & 조치 (CSPM)</div></div>
                <div style="display:flex; flex-direction:column; gap:15px; margin-bottom: 20px; background:#f8fafc; padding:20px; border-radius:8px; border:1px solid #e2e8f0;">
                    <div style="display:flex; gap:15px; align-items:center;">
                        <strong style="font-size:14px; color:#0f172a; width: 110px;">🖥️ 대상 서버 :</strong>
                        <select id="kisaTargetIp" class="filter-select" style="width: 320px;"><option value="">-- 진단할 서버를 선택하세요 --</option>
                        <?php if(isset($serverData['servers'])){ foreach($serverData['servers'] as $server){ $ip = $server['addresses'][0]['ip_addresses'][0]['ip_address'] ?? ''; $name = htmlspecialchars($server['name'] ?? '-'); $os = $osInfo[$ip]['os'] ?? '-'; if($ip !== '' && stripos($os, 'Windows') === false && stripos($os, '접속불가') === false) echo "<option value='{$ip}'>[{$name}] {$ip}</option>"; } } ?>
                        </select>
                        <select id="pemSelectSecurity" class="filter-select"><option value="works_mgmt.pem">works_mgmt.pem</option><option value="rocky.pem">rocky.pem</option></select>
                    </div>
                    <hr style="border:0; border-top:1px solid #cbd5e1; margin: 5px 0;">
                    <div>
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 12px;"><strong style="font-size:14px; color:#0f172a;">📋 진단 항목 선택</strong><label style="font-size:13px; font-weight:bold; cursor:pointer;"><input type="checkbox" onclick="toggleKisaAll(this)" checked> 전체 선택/해제</label></div>
                        <div class="kisa-group-box"><div class="kisa-group-title">1. 계정 관리</div><div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px; font-size:13px; color:#334155;"><label><input type="checkbox" class="kisa-cb" value="U-01" checked> <strong>[U-01]</strong> root 계정 원격 접속 제한</label><label><input type="checkbox" class="kisa-cb" value="U-02" checked> <strong>[U-02]</strong> 패스워드 복잡성 설정</label><label><input type="checkbox" class="kisa-cb" value="U-03" checked> <strong>[U-03]</strong> 계정 잠금 임계값 설정</label><label><input type="checkbox" class="kisa-cb" value="U-04" checked> <strong>[U-04]</strong> 패스워드 파일 보호 (/etc/shadow)</label><label><input type="checkbox" class="kisa-cb" value="U-05" checked> <strong>[U-05]</strong> root 홈, 패스 디렉터리 권한</label><label><input type="checkbox" class="kisa-cb" value="U-06" checked> <strong>[U-06]</strong> root 이외의 UID 0 계정 금지</label><label><input type="checkbox" class="kisa-cb" value="U-07" checked> <strong>[U-07]</strong> /etc/passwd 파일 권한 점검</label></div></div>
                        <div class="kisa-group-box"><div class="kisa-group-title">2. 서비스 관리</div><div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px; font-size:13px; color:#334155;"><label><input type="checkbox" class="kisa-cb" value="U-19" checked> <strong>[U-19]</strong> Finger 서비스 비활성화</label><label><input type="checkbox" class="kisa-cb" value="U-20" checked> <strong>[U-20]</strong> Anonymous FTP 비활성화</label><label><input type="checkbox" class="kisa-cb" value="U-60" checked> <strong>[U-60]</strong> SSH 서비스 포트 변경 권고</label></div></div>
                    </div>
                    <div style="text-align: right; margin-top: 5px;"><button class="btn-blue" onclick="startKisaScan()" style="padding: 10px 25px; font-size: 14px;">🚀 선택 항목 진단 시작</button></div>
                </div>
                <div class="table-container"><table id="securityTable"><thead><tr><th style="width: 25%;">KISA 점검 항목</th><th style="width: 40%;">상세 설명 (판단 기준)</th><th style="width: 15%; text-align:center;">진단 결과</th><th style="width: 20%; text-align:center;">자동 조치</th></tr></thead><tbody><tr><td colspan="4" style="text-align:center; padding:30px; color:#94a3b8; font-weight:bold;">서버와 점검 항목을 선택하고 [진단 시작] 버튼을 눌러주세요.</td></tr></tbody></table></div>
            </div>
        </div>

        <div id="page-docker" class="tab-page">
            <div class="mgmt-card">
                <div class="mgmt-header"><div class="mgmt-title">🐳 Docker 컨테이너 통합 모니터링 & 제어</div></div>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px; background:#f8fafc; padding:15px; border-radius:8px; border:1px solid #e2e8f0;">
                    <div style="display:flex; gap:15px; align-items:center;"><strong style="font-size:14px; color:#0f172a;">🖥️ 대상 서버 :</strong>
                        <select id="dockerTargetIp" class="filter-select" style="width: 300px;"><option value="">-- Docker를 확인할 서버 선택 --</option>
                        <?php if(isset($serverData['servers'])){ foreach($serverData['servers'] as $server){ $ip = $server['addresses'][0]['ip_addresses'][0]['ip_address'] ?? ''; $name = htmlspecialchars($server['name'] ?? '-'); $os = $osInfo[$ip]['os'] ?? '-'; if($ip !== '' && stripos($os, 'Windows') === false && stripos($os, '접속불가') === false) echo "<option value='{$ip}'>[{$name}] {$ip}</option>"; } } ?>
                        </select>
                        <select id="pemSelectDocker" class="filter-select"><option value="works_mgmt.pem">works_mgmt.pem</option><option value="rocky.pem">rocky.pem</option></select>
                    </div>
                    <button class="btn-blue" onclick="fetchDockerContainers()" style="padding: 10px 20px; font-size: 14px;">🔍 컨테이너 목록 조회</button>
                </div>
                <div class="table-container"><table id="dockerTable"><thead><tr><th style="width: 20%;">컨테이너 이름 (Name)</th><th style="width: 25%;">이미지 (Image)</th><th style="width: 15%; text-align:center;">상태 (Status)</th><th style="width: 25%;">포트 매핑 (Ports)</th><th style="width: 15%; text-align:center;">관리 작업</th></tr></thead><tbody><tr><td colspan="5" style="text-align:center; padding:30px; color:#94a3b8; font-weight:bold;">서버를 선택하고 [조회] 버튼을 눌러주세요.</td></tr></tbody></table></div>
            </div>
        </div>

        <div id="page-calendar" class="tab-page">
            <div class="mgmt-card">
                <div class="mgmt-header">
                    <div class="mgmt-title">📅 인프라 유지보수 작업 캘린더</div>
                    <div style="font-size: 12px; color: #16a34a; font-weight: bold;">※ 당일이 되면 슬랙(Slack)으로 자동 푸시 알림이 발송됩니다. (날짜를 클릭해 새 일정을 등록하세요)</div>
                </div>
                <div id='calendar'></div>
            </div>
        </div>

        <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
        <div id="page-alerts" class="tab-page">
            <div class="card shadow mb-4" style="border-left: 4px solid #ef4444; border-radius: 12px; padding: 0; overflow: hidden;">
                <div class="card-header py-3" style="background:#f8fafc; border-bottom:1px solid #e2e8f0; padding:15px 20px; display:flex; justify-content:space-between; align-items:center;">
                    <h6 class="m-0 font-weight-bold" style="color: #dc2626; font-size:16px;">🚨 최근 장애 및 리소스 변동 감지 내역</h6>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <select id="typeFilter" onchange="filterAlerts()" style="padding: 6px; border-radius: 4px; border: 1px solid #ccc; font-size:13px; font-weight:bold;">
                            <option value="전체">전체 알림 유형</option><option value="서버 다운">서버 다운</option><option value="CPU 과부하">CPU 과부하</option><option value="MEM 과부하">MEM 과부하</option>
                            <option value="SG 변경">🛡️ SG 변경</option><option value="LB 변경">⚖️ LB 변경</option><option value="IAM 변경">🔑 IAM 변경</option>
                        </select>
                        <input type="text" id="alertSearchInput" onkeyup="filterAlerts()" placeholder="대상 서버(IP) 검색..." style="padding: 6px; width: 220px; border-radius: 4px; border: 1px solid #ccc; font-size:13px;">
                    </div>
                </div>
                <div class="card-body" style="padding: 0;">
                    <table id="alertTable" class="table" style="margin-bottom:0; box-shadow:none; border-radius:0;">
                        <thead style="background: #1e293b; color: white;"><tr><th style="width: 20%; text-align:center;">발생 일시</th><th style="width: 20%; text-align:center;">대상 (서버/클라우드)</th><th style="width: 15%; text-align:center;">알림 유형</th><th style="width: 45%;">상세 내용</th></tr></thead>
                        <tbody>
                            <?php
                            $history_file = '/opt/scp/alert_history.json';
                            if (file_exists($history_file)) {
                                $history_data = json_decode(file_get_contents($history_file), true);
                                if (!empty($history_data)) {
                                    foreach ($history_data as $alert) {
                                        $badge_bg = '#64748b'; $badge_color = 'white';
                                        if ($alert['type'] == '재부팅') { $badge_bg = '#f59e0b'; $badge_color = '#1e293b'; }
                                        if (strpos($alert['type'], '과부하') !== false || strpos($alert['type'], '다운') !== false) { $badge_bg = '#ef4444'; $badge_color = 'white'; }
                                        if ($alert['type'] == 'SG 변경') { $badge_bg = '#8b5cf6'; $badge_color = 'white'; }
                                        if ($alert['type'] == 'LB 변경') { $badge_bg = '#0ea5e9'; $badge_color = 'white'; }
                                        if ($alert['type'] == 'IAM 변경') { $badge_bg = '#10b981'; $badge_color = 'white'; }
                                        echo "<tr><td style='text-align:center;'>{$alert['time']}</td><td style='text-align:center;'><strong>{$alert['server']}</strong></td><td style='text-align:center;'><span style='background:{$badge_bg}; color:{$badge_color}; padding:5px 10px; border-radius:6px; font-size:12px; font-weight:bold;'>{$alert['type']}</span></td><td style='color:#334155; font-weight:bold;'>{$alert['message']}</td></tr>";
                                    }
                                } else echo "<tr><td colspan='4' style='text-align:center; padding:30px; color:#64748b; font-weight:bold;'>최근 발생한 알림 내역이 없습니다. 평화롭네요! 🕊️</td></tr>";
                            } else echo "<tr><td colspan='4' style='text-align:center; padding:30px; color:#64748b; font-weight:bold;'>알림 내역 데이터 파일이 아직 생성되지 않았습니다.</td></tr>";
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<div id="metricsModal" class="modal-overlay"><div class="modal-card"><div class="modal-header"><div class="modal-title" id="modalServerTitle">🖥️ 서버 자원 현황</div><button class="btn-close-modal" onclick="closeModal()">✖</button></div><div id="modalContent"></div></div></div>

<?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
<div id="routineManageModal" class="modal-overlay">
    <div class="modal-card">
        <div class="modal-header">
            <div class="modal-title">⏰ 맞춤형 알림 설정 및 해결 이력</div>
            <button class="btn-close-modal" onclick="document.getElementById('routineManageModal').style.display='none'">✖</button>
        </div>
        <div class="modal-content" style="display:flex; flex-direction:column; gap:20px;">
            <div class="mgmt-card" style="margin-bottom:0; box-shadow:none;">
                <div class="mgmt-header" style="margin-bottom:10px;"><div class="mgmt-title">🔔 새로운 알림(To-Do) 추가</div></div>
                <div style="display:flex; gap:10px; align-items:center; background:#f8fafc; padding:15px; border-radius:8px; border:1px solid #e2e8f0;">
                    <input type="time" id="newRoutineTimeModal" value="09:00" style="padding:8px; border:1px solid #ccc; font-size:14px; border-radius:6px; width:120px;">
                    <input type="text" id="newRoutineInputModal" placeholder="매일 지정된 시간에 띄울 점검 항목의 이름을 적어주세요." style="flex:1; padding:8px; border:1px solid #ccc; font-size:14px; border-radius:6px;">
                    <button onclick="addRoutineModal()" class="btn-blue" style="padding:8px 20px;">추가</button>
                </div>
                <div style="margin-top:15px; max-height:150px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:8px;">
                    <table style="width:100%; border-collapse:collapse; font-size:13px;">
                        <thead style="background:#f1f5f9; position:sticky; top:0;"><tr><th style="padding:8px; text-align:center; width:100px;">알림 시간</th><th style="padding:8px; text-align:left;">점검 항목</th><th style="padding:8px; text-align:center; width:80px;">삭제</th></tr></thead>
                        <tbody id="activeRoutineListModal"></tbody>
                    </table>
                </div>
            </div>

            <div class="mgmt-card" style="margin-bottom:0; box-shadow:none; flex:1; display:flex; flex-direction:column;">
                <div class="mgmt-header" style="margin-bottom:10px; border:none; padding:0;">
                    <div class="mgmt-title">📅 월간 해결 이력 조회</div>
                    <div style="display:flex; gap:10px; align-items:center;">
                        <input type="text" id="historySearchInputModal" placeholder="작업 내용 검색..." onkeyup="if(event.keyCode===13) loadRoutineHistoryModal()" style="padding:6px; border:1px solid #ccc; border-radius:4px; font-size:13px; width:180px;">
                        <input type="month" id="historyMonthModal" value="<?php echo date('Y-m'); ?>" onchange="loadRoutineHistoryModal()" style="padding:6px; border:1px solid #ccc; border-radius:4px; font-size:13px;">
                        <button onclick="loadRoutineHistoryModal()" class="btn-blue" style="padding:6px 12px; background:#475569;">🔍 검색</button>
                    </div>
                </div>
                <div id="routineHistoryListModal" style="flex:1; border:1px solid #e2e8f0; border-radius:8px; overflow-y:auto; background:#f8fafc; padding:10px;"></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
let fullCalendarInstance;
window.cloudRawData = {};

document.addEventListener("DOMContentLoaded", function() {
    let jiraRawData = '[]';
    try {
        let el = document.getElementById('jira-data');
        if(el) jiraRawData = el.textContent || '[]';
    } catch(e) {}

    window.jiraEventData = [];
    try {
        window.jiraEventData = JSON.parse(jiraRawData);
    } catch(e) {
        window.jiraEventData = [];
    }

    let isDark = document.body.classList.contains('dark-mode');
    Chart.defaults.color = isDark ? '#cbd5e1' : '#64748b';

    const ctxOs = document.getElementById('osChart').getContext('2d');
    new Chart(ctxOs, {
        type: 'doughnut',
        data: {
            labels: ['Rocky Linux', 'Ubuntu', 'Windows', '접속불가'],
            datasets: [{ data: [<?php echo $osCounts['Rocky']; ?>, <?php echo $osCounts['Ubuntu']; ?>, <?php echo $osCounts['Windows']; ?>, <?php echo $osCounts['UNREACHABLE']; ?>], backgroundColor: ['#2563eb', '#7c3aed', '#475569', '#ef4444'] }]
        },
        options: { responsive: true, maintainAspectRatio: false }
    });

    const ctxType = document.getElementById('typeChart').getContext('2d');
    new Chart(ctxType, {
        type: 'doughnut',
        data: {
            labels: ['일반 VM', 'GPU 서버'],
            datasets: [{ data: [<?php echo $vmCount; ?>, <?php echo $gpuCount; ?>], backgroundColor: ['#0284c7', '#f59e0b'] }]
        },
        options: { responsive: true, maintainAspectRatio: false }
    });

    const ctxMonthly = document.getElementById('monthlyChart').getContext('2d');
    new Chart(ctxMonthly, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($ymLabels); ?>,
            datasets: [
                { label: '생성된 VM 수 (대)', data: <?php echo json_encode($ymCountsCreated); ?>, backgroundColor: '#3b82f6', borderRadius: 4 },
                { label: '삭제된 VM 수 (대)', data: <?php echo json_encode($ymCountsDeleted); ?>, backgroundColor: '#ef4444', borderRadius: 4 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: true, position: 'top' } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } },
            onClick: (e, activeEls) => {
                if (activeEls.length > 0) {
                    const index = activeEls[0].index;
                    const selectedYm = <?php echo json_encode($ymLabels); ?>[index];
                    const jiraTabBtn = document.querySelector('a[onclick*="page-jira"]');
                    if(jiraTabBtn) showTab('page-jira', jiraTabBtn);
                    const selectEl = document.getElementById('jiraMonthSelect');
                    if(selectEl) { selectEl.value = selectedYm; renderJiraTable(); }
                }
            }
        }
    });

    var calendarEl = document.getElementById('calendar');
    fullCalendarInstance = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth', locale: 'ko',
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek' }, height: 600,
        events: function(fetchInfo, successCallback, failureCallback) {
            fetch('index.php', { method: 'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ajax_action:'task_list'}) })
            .then(res => res.json())
            .then(json => {
                let evts = json.data.map(t => ({
                    id: t.id,
                    title: (t.is_sent == 1 ? '✅ ' : '⏳ ') + t.task_name,
                    start: t.task_date,
                    color: t.is_sent == 1 ? '#16a34a' : '#3b82f6',
                    textColor: '#ffffff'
                }));
                successCallback(evts);
            });
        },
        dateClick: function(info) {
            let taskName = prompt(info.dateStr + " 일자에 등록할 작업 내용을 입력하세요:");
            if(taskName) {
                fetch('index.php', { method: 'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ajax_action:'task_add', task_date: info.dateStr, task_name: taskName}) })
                .then(() => fullCalendarInstance.refetchEvents());
            }
        },
        eventClick: function(info) {
            if(confirm(`[${info.event.startStr}] 일정을 삭제하시겠습니까?\n내용: ${info.event.title}`)) {
                fetch('index.php', { method: 'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ajax_action:'task_delete', id: info.event.id}) })
                .then(() => fullCalendarInstance.refetchEvents());
            }
        }
    });

    if(document.getElementById('routineAlarmBanner')) {
        checkRoutineAlarm();
        setInterval(checkRoutineAlarm, 60000);
    }
});

let currentType='ALL', currentOS='ALL';
let timeLeft = <?php echo (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') ? 3600 : 600; ?>;

function downloadCsv(csvContent, fileName) {
    const blob = new Blob(["\uFEFF" + csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function formatDoc(cmd, value=null) {
    let editor = document.getElementById('currentNoteContent');
    if(!editor) return;
    editor.focus();
    document.execCommand(cmd, false, value);
}

function openRoutineModal() {
    let modal = document.getElementById('routineManageModal');
    if(!modal) return;
    modal.style.display = 'flex';
    loadRoutineSettingsModal();
    loadRoutineHistoryModal();
}

function loadRoutineSettingsModal() {
    fetch('index.php', { method: 'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ajax_action:'get_routine_settings'})})
    .then(res => res.json())
    .then(json => {
        let html = '';
        if(json.data.length === 0) {
            html = '<tr><td colspan="3" style="text-align:center; padding:15px; color:#94a3b8;">등록된 알림이 없습니다.</td></tr>';
        } else {
            json.data.forEach(r => {
                html += `<tr><td style="text-align:center; color:#ef4444; font-weight:bold;">${r.alarm_time}</td><td style="color:#1e293b; font-weight:bold;">${r.routine_name}</td><td style="text-align:center;"><button onclick="deleteRoutineModal(${r.id})" style="background:#ef4444; color:white; border:none; padding:4px 8px; border-radius:4px; font-size:11px; cursor:pointer;">삭제</button></td></tr>`;
            });
        }
        let tbody = document.getElementById('activeRoutineListModal');
        if(tbody) tbody.innerHTML = html;
    });
}

function loadRoutineHistoryModal() {
    let monthInput = document.getElementById('historyMonthModal');
    let searchInput = document.getElementById('historySearchInputModal');
    if(!monthInput || !searchInput) return;

    let month = monthInput.value;
    let keyword = searchInput.value;
    fetch('index.php', { method: 'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ajax_action:'get_routine_history', month: month, keyword: keyword})})
    .then(res => res.json())
    .then(json => {
        let html = '';
        if(json.data.length === 0) {
            html = '<div style="padding:30px; color:#94a3b8; font-size:13px; text-align:center;">검색된 해결 이력이 없습니다.</div>';
        } else {
            json.data.forEach(item => {
                html += `<div class="history-item">
                            <span style="background:#10b981; color:white; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:bold;">해결 완료</span>
                            <span style="color:#64748b; font-weight:bold;">[${item.resolved_at.slice(5,16)}]</span>
                            <span style="color:#0f172a; font-weight:bold; flex:1;">${item.routine_name}</span>
                         </div>`;
            });
        }
        document.getElementById('routineHistoryListModal').innerHTML = html;
    });
}

async function addRoutineModal() {
    let nameInput = document.getElementById('newRoutineInputModal');
    let timeInput = document.getElementById('newRoutineTimeModal');
    if(!nameInput || !timeInput) return;

    let name = nameInput.value.trim();
    let time = timeInput.value;
    if(!name) return alert("추가할 점검 항목의 내용을 입력하세요.");
    if(!time) return alert("알림 시간을 지정해주세요.");
    try {
        await fetch('index.php', { method: 'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ajax_action:'add_routine', routine_name: name, alarm_time: time})});
        nameInput.value = '';
        loadRoutineSettingsModal();
        checkRoutineAlarm();
    } catch(e) { alert('오류 발생: ' + e); }
}

async function deleteRoutineModal(routineId) {
    if(!confirm("이 알림 항목을 완전히 삭제하시겠습니까?")) return;
    try {
        await fetch('index.php', { method: 'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ajax_action:'delete_routine', routine_id: routineId})});
        loadRoutineSettingsModal();
        checkRoutineAlarm();
    } catch(e) { alert('오류 발생: ' + e); }
}

function checkRoutineAlarm() {
    let banner = document.getElementById('routineAlarmBanner');
    if(!banner) return;

    fetch('index.php', { method: 'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ajax_action:'check_routines'})})
    .then(res => res.json())
    .then(json => {
        if(json.success && json.pending && json.pending.length > 0) {
            let bannerHtml = `<div style="display:flex; justify-content:center; align-items:center; gap:15px; width:100%; flex-wrap:wrap;">
                                <span style="font-size:15px; white-space:nowrap;">⏰ <b>[일일 알림]</b> 설정하신 맞춤형 점검 작업(${json.pending.length}건) 대기 중!</span>`;

            let firstTask = json.pending[0];
            bannerHtml += `<span style="background:rgba(255,255,255,0.2); padding:5px 12px; border-radius:4px; font-size:14px; white-space:nowrap; max-width:350px; overflow:hidden; text-overflow:ellipsis;" title="${firstTask.routine_name}">👉 ${firstTask.routine_name}</span>`;

            bannerHtml += `<div style="display:flex; gap:8px; align-items:center;">
                            <button class="btn-alarm-action" style="color:#10b981; margin:0;" onclick="resolveRoutineFromBanner(${firstTask.log_id})">✓ 해결</button>
                            <button class="btn-alarm-action" style="color:#f59e0b; margin:0;" onclick="deferRoutineFromBanner(${firstTask.log_id})" title="오늘은 넘어가고 내일 동일한 시간에 다시 알람">⏳ 미해결</button>
                            <button class="btn-alarm-action" style="background:#475569; color:white; margin:0;" onclick="openRoutineModal()">⚙️ 전체 관리</button>
                           </div></div>`;

            banner.innerHTML = bannerHtml;
            banner.style.display = 'block';
        } else {
            banner.style.display = 'none';
        }
    });
}

async function resolveRoutineFromBanner(logId) {
    if(!confirm("해결 처리하시겠습니까?\n(완료 내역에 저장되며 점검 항목에서 완전히 사라집니다.)")) return;
    try {
        await fetch('index.php', { method: 'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ajax_action:'resolve_routine', log_id: logId})});
        checkRoutineAlarm();
        if(document.getElementById('routineManageModal') && document.getElementById('routineManageModal').style.display !== 'none') {
            loadRoutineHistoryModal();
            loadRoutineSettingsModal();
        }
    } catch(e) { alert('오류 발생: ' + e); }
}

async function deferRoutineFromBanner(logId) {
    if(!confirm("오늘 알림을 끄시겠습니까?\n(미해결 처리되어 내일 동일한 시간에 다시 알림이 울립니다.)")) return;
    try {
        await fetch('index.php', { method: 'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ajax_action:'defer_routine', log_id: logId})});
        checkRoutineAlarm();
    } catch(e) { alert('오류 발생: ' + e); }
}

function loadNotesList() {
    fetch('index.php', { method: 'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ajax_action:'get_notes'})})
    .then(res => res.json())
    .then(json => {
        let listArea = document.getElementById('noteListArea');
        if(!listArea) return;

        let html = '';
        if(json.data.length === 0) {
            html = '<div style="padding:20px; color:#94a3b8; font-size:13px; text-align:center;">작성된 노트가 없습니다.</div>';
        } else {
            json.data.forEach(note => {
                let preview = note.preview ? note.preview.substring(0, 25) + '...' : '내용 없음';
                html += `<div class="note-item" onclick="openNote(${note.id}, this)">
                            <div class="note-item-title">${note.title || '제목 없음'}</div>
                            <div class="note-item-date">${note.updated_at.slice(0,10)} | ${preview}</div>
                         </div>`;
            });
        }
        listArea.innerHTML = html;
    });
}

function createNewNote() {
    let noteIdField = document.getElementById('currentNoteId');
    if(!noteIdField) return;

    noteIdField.value = '';
    document.getElementById('currentNoteTitle').value = '';
    document.getElementById('currentNoteContent').innerHTML = '';
    document.getElementById('btnDeleteNote').style.display = 'none';
    let btnExport = document.getElementById('btnExportPdf');
    if(btnExport) btnExport.style.display = 'none';

    document.getElementById('noteSaveStatus').innerText = '새 노트 작성 중...';
    document.querySelectorAll('.note-item').forEach(el => el.classList.remove('active'));
    document.getElementById('currentNoteTitle').focus();
}

function openNote(id, el) {
    document.querySelectorAll('.note-item').forEach(e => e.classList.remove('active'));
    if(el) el.classList.add('active');

    fetch('index.php', { method: 'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ajax_action:'get_note_detail', id: id})})
    .then(res => res.json())
    .then(json => {
        if(json.data) {
            document.getElementById('currentNoteId').value = json.data.id;
            document.getElementById('currentNoteTitle').value = json.data.title;
            document.getElementById('currentNoteContent').innerHTML = json.data.content;
            document.getElementById('btnDeleteNote').style.display = 'block';
            let btnExport = document.getElementById('btnExportPdf');
            if(btnExport) btnExport.style.display = 'block';

            document.getElementById('noteSaveStatus').innerText = '최종 수정: ' + json.data.updated_at;
        }
    });
}

function saveCurrentNote() {
    let titleInput = document.getElementById('currentNoteTitle');
    let contentEditor = document.getElementById('currentNoteContent');
    if(!titleInput || !contentEditor) return;

    let id = document.getElementById('currentNoteId').value;
    let title = titleInput.value;
    let content = contentEditor.innerHTML;

    if(!title.trim() && !content.trim() && content === '<br>') {
        alert("내용이나 제목을 입력해주세요!"); return;
    }

    document.getElementById('noteSaveStatus').innerText = '저장 중...';
    fetch('index.php', { method: 'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ajax_action:'save_note', id: id, title: title, content: content})})
    .then(res => res.json())
    .then(json => {
        if(json.success) {
            document.getElementById('currentNoteId').value = json.id;
            let now = new Date();
            let mm = now.getMinutes() < 10 ? '0'+now.getMinutes() : now.getMinutes();
            document.getElementById('noteSaveStatus').innerText = `저장 완료 (${now.getHours()}:${mm})`;
            document.getElementById('btnDeleteNote').style.display = 'block';
            let btnExport = document.getElementById('btnExportPdf');
            if(btnExport) btnExport.style.display = 'block';
            loadNotesList();
        }
    });
}

function deleteCurrentNote() {
    let id = document.getElementById('currentNoteId').value;
    if(!id) return;
    if(!confirm("이 노트를 정말 삭제하시겠습니까?")) return;

    fetch('index.php', { method: 'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ajax_action:'delete_note', id: id})})
    .then(res => res.json())
    .then(json => {
        if(json.success) {
            createNewNote();
            loadNotesList();
        }
    });
}

function exportNoteToPDF() {
    let title = document.getElementById('currentNoteTitle').value || '제목 없는 노트';
    let contentHtml = document.getElementById('currentNoteContent').innerHTML;

    if(!contentHtml.trim() || contentHtml === '<br>') {
        alert("내보낼 내용이 없습니다.");
        return;
    }

    let printDiv = document.createElement('div');
    printDiv.style.width = '800px';
    printDiv.style.padding = '30px';
    printDiv.style.fontFamily = "'Malgun Gothic', '맑은 고딕', sans-serif";
    printDiv.style.color = "#000000";
    printDiv.style.background = "#ffffff";
    printDiv.innerHTML = `
        <style>
            img { max-width: 100% !important; height: auto !important; }
            table { width: 100% !important; table-layout: fixed; }
            td, th { word-wrap: break-word; }
        </style>
        <h2 style="border-bottom: 2px solid #ccc; padding-bottom: 10px; margin-bottom: 20px; color:#000;">${title}</h2>
        <div style="font-size: 14px; line-height: 1.6; color:#000;">${contentHtml}</div>
    `;

    let opt = {
      margin:       15,
      filename:     title + '.pdf',
      image:        { type: 'jpeg', quality: 0.98 },
      html2canvas:  { scale: 2, useCORS: true },
      jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };

    let btn = document.getElementById('btnExportPdf');
    let originalText = btn.innerText;
    btn.innerText = '⏳ 생성 중...';
    btn.disabled = true;

    html2pdf().set(opt).from(printDiv).save().then(() => {
        btn.innerText = originalText;
        btn.disabled = false;
    }).catch(err => {
        alert("PDF 생성 중 오류가 발생했습니다.");
        btn.innerText = originalText;
        btn.disabled = false;
    });
}

document.addEventListener('keydown', function(e) {
    if ((window.navigator.platform.match("Mac") ? e.metaKey : e.ctrlKey) && e.key === 's') {
        let notesPage = document.getElementById('page-notes');
        if (notesPage && notesPage.classList.contains('active')) {
            e.preventDefault();
            saveCurrentNote();
        }
    }
});

function showTab(tabId, el) {
    document.querySelectorAll('.tab-page').forEach(page => page.classList.remove('active'));
    document.querySelectorAll('.sidebar-menu li').forEach(li => li.classList.remove('active'));
    document.getElementById(tabId).classList.add('active');
    el.parentElement.classList.add('active');

    const titles = {
        'page-dashboard': '📊 메인 통합 대시보드',
        'page-notes': '📝 내 작업 노트',
        'page-server': '🖥️ VM 서버 관리',
        'page-storage': '📦 Object Storage 현황',
        'page-dbaas': '🗄️ DBaaS (MySQL) 관리',
        'page-iam': '🔑 IAM 권한 관리 (CLI 연동)',
        'page-sg': '🛡️ Security Group 규칙 조회 (CLI 연동)',
        'page-lb': '⚖️ Load Balancer 대상 및 리스너 현황',
        'page-jira': '🎫 Jira 요청 관리',
        'page-network': '🌐 통신 방화벽 확인',
        'page-security': '🛡️ 주요기반 취약점 진단 (KISA)',
        'page-docker': '🐳 Docker 컨테이너 관제',
        'page-calendar': '📅 인프라 작업 일정 캘린더',
        'page-alerts': '🚨 시스템 알림 현황 (관리자)'
    };
    document.getElementById('page-title-text').innerText = titles[tabId] || '대시보드';

    if(tabId === 'page-notes') loadNotesList();
    if(tabId === 'page-jira') initJiraTab();
    if(tabId === 'page-dbaas') loadDbaasData();
    if(tabId === 'page-calendar' && fullCalendarInstance) setTimeout(() => { fullCalendarInstance.render(); }, 100);

    if(tabId === 'page-iam' && (!window.cloudRawData || !window.cloudRawData['iam'])) loadCloudResource('iam', '#iamTable');
    if(tabId === 'page-sg' && (!window.cloudRawData || !window.cloudRawData['sg'])) loadCloudResource('sg', '#sgTable');
    if(tabId === 'page-lb' && (!window.cloudRawData || !window.cloudRawData['lb'])) loadCloudResource('lb', '#lbTable');
}

async function loadCloudResource(type, tableId) {
    const tbody = document.querySelector(tableId + ' tbody');
    const thead = document.querySelector(tableId + ' thead');
    if (!tbody || !thead) return;

    tbody.innerHTML = `<tr><td style="text-align:center; padding:30px; color:#2563eb;"><strong>⏳ 데이터를 불러오는 중입니다...</strong></td></tr>`;
    thead.innerHTML = '';

    try {
        const res = await fetch('index.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ ajax_action: 'get_cloud_resource', type: type }) });
        const textRaw = await res.text();
        let data;

        try {
            data = JSON.parse(textRaw);
        } catch(e) {
            tbody.innerHTML = `<tr><td style="text-align:center; padding:30px; color:#dc2626;">❌ JSON 데이터를 파싱할 수 없습니다.</td></tr>`;
            return;
        }

        if (!data || data.length === 0) {
            tbody.innerHTML = `<tr><td style="text-align:center; padding:30px; color:#94a3b8;">데이터가 없거나 아직 동기화되지 않았습니다.</td></tr>`; return;
        }

        window.cloudRawData[type] = data;

        const hiddenCols = ['account_id', 'created_by', 'modified_at', 'modified_by', 'loggable', 'public_nat_enabled'];
        const keys = Object.keys(data[0]).filter(key => !hiddenCols.includes(key.toLowerCase()));

        let trHead = document.createElement('tr');
        keys.forEach(key => {
            let th = document.createElement('th');
            th.innerText = key.replace(/_/g, ' ').toUpperCase();
            trHead.appendChild(th);
        });
        thead.appendChild(trHead);

        tbody.innerHTML = '';
        data.forEach(item => {
            let tr = document.createElement('tr');
            keys.forEach(key => {
                let td = document.createElement('td');
                let val = item[key];

                if (type === 'sg' && key.toLowerCase() === 'rule_count') {
                    td.innerHTML = `<span style="cursor:pointer; color:#2563eb; font-weight:bold; text-decoration:underline;" onclick="openSgRules('${item.id}', '${item.name}')">🔍 규칙 보기 (${val}개)</span>`;
                } else {
                    if (val === null || val === undefined) val = '-';
                    else if (typeof val === 'object') val = JSON.stringify(val);
                    td.innerText = val;
                }
                tr.appendChild(td);
            });
            tbody.appendChild(tr);
        });

        if (type === 'lb') {
            applyRowSpan(tableId);
        } else {
            document.querySelectorAll(tableId + ' td, ' + tableId + ' th').forEach(cell => {
                cell.style.textAlign = 'center';
                cell.style.verticalAlign = 'middle';
            });
        }

    } catch(e) {
        tbody.innerHTML = `<tr><td style="text-align:center; padding:30px; color:#dc2626;">❌ 데이터를 불러오지 못했습니다.</td></tr>`;
    }
}

async function openSgRules(sgId, sgName) {
    const modal = document.getElementById('metricsModal');
    const modalTitle = document.getElementById('modalServerTitle');
    const modalContent = document.getElementById('modalContent');
    if (!modal || !modalTitle || !modalContent) return;

    modalTitle.innerText = `🛡️ Security Group 규칙 상세 - ${sgName}`;
    modalContent.innerHTML = `<div style="text-align:center; padding:40px; font-weight:bold; color:#2563eb;">⏳ 보안 그룹 규칙을 서버에서 실시간 조회 중입니다...</div>`;
    modal.style.display = 'flex';

    try {
        const res = await fetch('index.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ajax_action: 'get_sg_rules', sg_id: sgId })
        });
        const text = await res.text();
        let data;

        try {
            data = JSON.parse(text);
        } catch(e) {
            modalContent.innerHTML = `<div style="text-align:left; padding:20px; color:#dc2626; background:#fee2e2; border-radius:8px; font-family:monospace; font-size:13px; white-space:pre-wrap; border:1px solid #f87171;"><b>❌ CLI 명령어 실행 에러:</b><br><br>${text}</div>`;
            return;
        }

        if (data && !Array.isArray(data)) {
            if (data.contents) data = data.contents;
            else if (data.securityGroupRules) data = data.securityGroupRules;
            else if (data.rules) data = data.rules;
            else data = [data];
        }

        if (!data || data.length === 0) {
            modalContent.innerHTML = `<div style="text-align:center; padding:30px; color:#94a3b8; font-weight:bold;">등록된 보안 규칙이 없거나 서버에서 빈 값을 반환했습니다.</div>`;
            return;
        }

        let html = `<div class="table-container" style="max-height:450px; overflow-y:auto; border-radius:8px; border:1px solid #e2e8f0; margin-bottom:0;">`;
        html += `<table style="width:100%; border-collapse:collapse; font-size:12px; white-space:nowrap;"><thead><tr>`;

        const hiddenCols = ['id', 'security_group_id', 'created_by', 'modified_by', 'modified_at', 'account_id'];
        const keys = Object.keys(data[0]).filter(k => !hiddenCols.includes(k.toLowerCase()));

        keys.forEach(k => {
            html += `<th style="background:#f8fafc; color:#334155; padding:12px; border-bottom:2px solid #cbd5e1; text-align:center; position:sticky; top:0; z-index:1;">${k.replace(/_/g, ' ').toUpperCase()}</th>`;
        });
        html += `</tr></thead><tbody>`;

        data.forEach(rule => {
            html += `<tr style="border-bottom:1px solid #f1f5f9; transition:background 0.2s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">`;
            keys.forEach(k => {
                let v = rule[k];
                if (typeof v === 'object') v = JSON.stringify(v);

                if (k.toLowerCase() === 'action' && String(v).toLowerCase() === 'allow') {
                    v = `<span class="badge-good">허용 (ALLOW)</span>`;
                }
                if (k.toLowerCase() === 'direction') {
                    if (String(v).toLowerCase() === 'in') v = `<span style="color:#2563eb; font-weight:bold;">📥 INBOUND</span>`;
                    if (String(v).toLowerCase() === 'out') v = `<span style="color:#d97706; font-weight:bold;">📤 OUTBOUND</span>`;
                }

                html += `<td style="padding:10px 12px; text-align:center; color:#475569;">${v || '-'}</td>`;
            });
            html += `</tr>`;
        });
        html += `</tbody></table></div>`;
        modalContent.innerHTML = html;

    } catch (e) {
        modalContent.innerHTML = `<div style="text-align:center; padding:30px; color:#dc2626;">❌ 서버 통신 중 오류가 발생했습니다.<br>${e}</div>`;
    }
}

async function downloadMonthlyReport() {
    if(!confirm("📊 현재 시점을 기준으로 월간 통합 보고서(VM, IAM, LB)를 생성하여 다운로드하시겠습니까?\n\n※ 브라우저 설정에 따라 다중 파일 다운로드 허용 알림이 뜰 수 있습니다.")) return;

    try {
        const res = await fetch('index.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ajax_action: 'download_monthly_report'})
        });
        const json = await res.json();

        if(json.success) {
            const dateStr = new Date().toISOString().slice(0, 10);
            const prefix = `SCP_MonthlyReport_${dateStr}`;

            downloadCsv(json.data.vm, `${prefix}_1_VM_List.csv`);
            setTimeout(() => downloadCsv(json.data.iam, `${prefix}_2_IAM_List.csv`), 600);
            setTimeout(() => downloadCsv(json.data.lb, `${prefix}_3_LB_List.csv`), 1200);

        } else {
            alert("❌ 보고서 생성 중 오류가 발생했습니다.");
        }
    } catch (e) {
        alert("통신 오류: " + e);
    }
}

function applyCloudFilter(type, tableId, inputId, selectId = null) {
    let keyword = document.getElementById(inputId).value.toLowerCase();
    let field = selectId ? document.getElementById(selectId).value : 'ALL';
    let data = window.cloudRawData ? window.cloudRawData[type] : null;
    if (!data || data.length === 0) return;

    const hiddenCols = ['account_id', 'created_by', 'modified_at', 'modified_by', 'loggable', 'public_nat_enabled'];
    const keys = Object.keys(data[0]).filter(key => !hiddenCols.includes(key.toLowerCase()));

    let filtered = data.filter(item => {
        if (field === 'ALL') {
            return keys.some(key => String(item[key]).toLowerCase().includes(keyword));
        } else {
            let val = String(item[field] || '');
            if (typeof item[field] === 'object') val = JSON.stringify(item[field]);
            return val.toLowerCase().includes(keyword);
        }
    });

    const tbody = document.querySelector(tableId + ' tbody');
    if (!tbody) return;
    tbody.innerHTML = '';

    if (filtered.length === 0) {
        tbody.innerHTML = `<tr><td colspan="${keys.length}" style="text-align:center; padding:30px; color:#94a3b8;">검색 결과가 없습니다.</td></tr>`;
        return;
    }

    filtered.forEach(item => {
        let tr = document.createElement('tr');
        keys.forEach(key => {
            let td = document.createElement('td');
            let val = item[key];

            if (type === 'sg' && key.toLowerCase() === 'rule_count') {
                td.innerHTML = `<span style="cursor:pointer; color:#2563eb; font-weight:bold; text-decoration:underline;" onclick="openSgRules('${item.id}', '${item.name}')">🔍 규칙 보기 (${val}개)</span>`;
            } else {
                if (val === null || val === undefined) val = '-';
                else if (typeof val === 'object') val = JSON.stringify(val);
                td.innerText = val;
            }
            tr.appendChild(td);
        });
        tbody.appendChild(tr);
    });

    if (type === 'lb') {
        applyRowSpan(tableId);
    } else {
        document.querySelectorAll(tableId + ' td').forEach(cell => {
            cell.style.textAlign = 'center';
            cell.style.verticalAlign = 'middle';
        });
    }
}

function applyRowSpan(tableId) {
    const tbody = document.querySelector(tableId + ' tbody');
    if (!tbody) return;

    let prevRowsData = [];
    let prevCells = [];
    let rowSpans = [];

    for (let i = 0; i < tbody.rows.length; i++) {
        let row = tbody.rows[i];
        let rowData = [];
        for (let c = 0; c < row.cells.length; c++) {
            rowData[c] = row.cells[c].innerText;
        }

        for (let c = 0; c <= 2; c++) {
            let cell = row.cells[c];
            if (!cell) continue;

            let isMatch = false;
            if (i > 0 && rowData[c] === prevRowsData[c] && rowData[c] !== '-' && rowData[c] !== 'UNKNOWN' && rowData[c] !== '') {
                isMatch = true;
                for (let p = 0; p < c; p++) {
                    if (rowData[p] !== prevRowsData[p]) {
                        isMatch = false; break;
                    }
                }
            }

            if (isMatch) {
                rowSpans[c]++;
                prevCells[c].setAttribute('rowspan', rowSpans[c]);
                cell.style.display = 'none';
            } else {
                prevCells[c] = cell;
                rowSpans[c] = 1;
            }
        }
        prevRowsData = rowData;
    }

    document.querySelectorAll(tableId + ' th, ' + tableId + ' td').forEach(cell => {
        cell.style.border = '1px solid #cbd5e1';
        cell.style.verticalAlign = 'middle';
        cell.style.textAlign = 'center';
    });
}

function exportDynamicTableToCsv(tableId, fileNamePrefix) {
    const table = document.getElementById(tableId);
    if (!table) return alert("테이블을 찾을 수 없습니다.");

    const rows = table.querySelectorAll('tr');
    if (rows.length <= 1 || (rows[1] && rows[1].innerText.includes('데이터가 없거나')) || (rows[1] && rows[1].innerText.includes('데이터를 불러오는 중'))) {
        return alert("다운로드할 데이터가 없습니다.");
    }

    let csv = "";
    const BOM = "\uFEFF";

    rows.forEach(row => {
        const cols = row.querySelectorAll('th, td');
        let rowData = [];
        cols.forEach(col => {
            let text = col.innerText.replace(/"/g, '""');
            rowData.push(`"${text}"`);
        });
        csv += rowData.join(",") + "\n";
    });

    const dateStr = new Date().toISOString().slice(0, 10);
    const blob = new Blob([BOM + csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = `${fileNamePrefix}_${dateStr}.csv`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function toggleTheme() {
    const body = document.body;
    body.classList.toggle('dark-mode');
    const isDark = body.classList.contains('dark-mode');
    localStorage.setItem('theme', isDark ? 'dark' : 'light');
    document.getElementById('theme-icon').innerText = isDark ? '☀️ 라이트 모드' : '🌙 다크 모드';
    Chart.defaults.color = isDark ? '#cbd5e1' : '#64748b';
    for (let id in Chart.instances) {
        let chart = Chart.instances[id];
        if (chart.options.scales && chart.options.scales.x) {
            chart.options.scales.x.ticks.color = isDark ? '#cbd5e1' : '#64748b';
            chart.options.scales.y.ticks.color = isDark ? '#cbd5e1' : '#64748b';
        }
        if (chart.options.plugins && chart.options.plugins.legend) {
            chart.options.plugins.legend.labels.color = isDark ? '#cbd5e1' : '#64748b';
        }
        chart.update();
    }
}

function updateTimer() {
    let minutes = Math.floor(timeLeft / 60);
    let seconds = timeLeft % 60;
    if (seconds < 10) seconds = '0' + seconds;
    let display = document.getElementById('timer-display');
    if(display) display.innerText = `${minutes}:${seconds}`;
    if (timeLeft <= 0) {
        alert('⏰ 세션 유지 시간이 초과되어 자동 로그아웃되었습니다.');
        window.location.href = 'logout.php';
    } else {
        timeLeft--;
    }
}
setInterval(updateTimer, 1000);

function filterType(type){ currentType=type; applyFilter(); }
function filterOS(os){ currentOS=os; applyFilter(); }

function applyFilter(){
    let keyword = document.getElementById('search').value.toLowerCase();
    document.querySelectorAll('#serverTable tbody tr').forEach(function(row){
        let typeMatch = currentType == 'ALL' || row.dataset.type == currentType;
        let osMatch = currentOS == 'ALL' || row.dataset.os == currentOS;
        let name = row.cells[1].innerText.toLowerCase();
        row.style.display = (typeMatch && osMatch && name.includes(keyword)) ? '' : 'none';
    });
}

function filterAlerts() {
    let keyword = document.getElementById("alertSearchInput").value.toLowerCase();
    let typeFilter = document.getElementById("typeFilter").value;
    document.querySelectorAll("#alertTable tbody tr").forEach(row => {
        if(row.cells.length <= 1) return;
        let rowText = row.innerText.toLowerCase();
        let typeCell = row.cells[2] ? row.cells[2].innerText : "";
        row.style.display = ((keyword === "" || rowText.includes(keyword)) && (typeFilter === "전체" || typeCell.includes(typeFilter))) ? "" : "none";
    });
}

function applyStorageFilter() {
    let keyword = document.getElementById('searchStorage').value.toLowerCase();
    document.querySelectorAll('#storageTable tbody tr').forEach(function(row){
        row.style.display = row.cells[0].innerText.toLowerCase().includes(keyword) ? '' : 'none';
    });
}

function toggleAll(source) {
    document.querySelectorAll('.ip-checkbox:not(:disabled)').forEach(cb => {
        if(cb.closest('tr').style.display !== 'none') cb.checked = source.checked;
    });
}

function exportServerExcel() {
    const checkedBoxes = document.querySelectorAll('#serverTable tbody .ip-checkbox:checked');
    let rowsToExport = [];
    if (checkedBoxes.length > 0) {
        checkedBoxes.forEach(cb => rowsToExport.push(cb.closest('tr')));
    } else {
        document.querySelectorAll('#serverTable tbody tr').forEach(row => {
            if (row.style.display !== 'none') rowsToExport.push(row);
        });
    }
    if (rowsToExport.length === 0) return alert("다운로드할 데이터가 없습니다.");
    let csv = "서버명,유형,IP 주소,방화벽(SG),상태,vCPU,메모리(GB),운영체제\n";
    rowsToExport.forEach(row => {
        const c = row.querySelectorAll('td');
        if(c.length >= 9) { // 💡 컬럼 추가(SG)로 인한 배열 인덱스 1칸씩 이동
            let col1 = c[1].innerText.replace(/"/g, '""'); // 서버명
            let col2 = c[2].innerText.replace(/"/g, '""'); // 유형
            let col3 = c[3].innerText.replace(/"/g, '""'); // IP 주소
            let col4 = c[4].innerText.replace(/"/g, '""').replace(/\n/g, ' / '); // 🛡️ 방화벽(SG) 줄바꿈을 엑셀용 슬래시로 변경
            let col5 = c[5].innerText.replace(/"/g, '""'); // 상태
            let col6 = c[6].innerText.replace(/"/g, '""'); // vCPU
            let col7 = c[7].innerText.replace(/"/g, '""'); // RAM
            let col8 = c[8].innerText.replace(/"/g, '""'); // OS
            csv += `"${col1}","${col2}","${col3}","${col4}","${col5}","${col6}","${col7}","${col8}"\n`;
        }
    });
    downloadCsv(csv, `SCP_Server_List_${new Date().toISOString().slice(0,10)}.csv`);
}

function exportStorageExcel() {
    const rowsToExport = [];
    document.querySelectorAll('#storageTable tbody tr').forEach(row => {
        if (row.style.display !== 'none') rowsToExport.push(row);
    });
    if (rowsToExport.length === 0) return alert("다운로드할 데이터가 없습니다.");
    let csv = "버킷명,생성일시,사용 용량(GB),파일 개수,상태\n";
    rowsToExport.forEach(row => {
        const c = row.querySelectorAll('td');
        if(c.length >= 5) {
            let col0 = c[0].innerText.replace(/"/g, '""');
            let col1 = c[1].innerText.replace(/"/g, '""');
            let col2 = c[2].innerText.replace(/"/g, '""');
            let col3 = c[3].innerText.replace(/"/g, '""');
            let col4 = c[4].innerText.replace(/"/g, '""');
            csv += `"${col0}","${col1}","${col2}","${col3}","${col4}"\n`;
        }
    });
    downloadCsv(csv, `SCP_Storage_List_${new Date().toISOString().slice(0,10)}.csv`);
}

async function executeAnsible() {
    const cmd = document.getElementById('ansibleCommand').value;
    const pem = document.getElementById('pemSelect').value;
    const selectedIps = Array.from(document.querySelectorAll('.ip-checkbox:checked')).map(cb => cb.value);
    const resultBox = document.getElementById('terminalResult');

    if(selectedIps.length === 0) return alert("대상 서버를 선택해 주세요.");
    if(cmd.trim() === "") return alert("명령어를 입력해 주세요.");

    resultBox.style.display = 'block';
    resultBox.innerText = `[명령어 실행 중...] 대상: ${selectedIps.length}대 ⏳`;
    try {
        const res = await fetch('run_ansible.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ips: selectedIps, cmd: cmd, pem: pem }) });
        resultBox.innerText = `[실행 완료]\n` + await res.text();
    } catch (e) {
        resultBox.innerText = `[오류 발생] ` + e;
    }
}

async function deployFile() {
    const fileInput = document.getElementById('uploadFile');
    const destPath = document.getElementById('destPath').value;
    const pem = document.getElementById('pemSelect').value;
    const selectedIps = Array.from(document.querySelectorAll('.ip-checkbox:checked')).map(cb => cb.value);
    const resultBox = document.getElementById('terminalResult');

    if(selectedIps.length === 0) return alert("배포할 대상 서버를 선택해 주세요.");
    if(fileInput.files.length === 0) return alert("업로드할 파일을 선택해 주세요.");
    if(destPath.trim() === "") return alert("원격 경로를 입력해 주세요.");

    let formData = new FormData();
    formData.append('file', fileInput.files[0]);
    formData.append('ips', selectedIps.join(','));
    formData.append('destPath', destPath);
    formData.append('pem', pem);

    resultBox.style.display = 'block';
    resultBox.innerText = `[파일 배포 진행 중...] ⏳`;
    try {
        const response = await fetch('upload_ansible.php', { method: 'POST', body: formData });
        resultBox.innerText = `[배포 완료]\n` + await response.text();
    } catch (error) {
        resultBox.innerText = `[통신 오류] ` + error;
    }
}

async function searchGlobalLogs() {
    const logPath = document.getElementById('logPath').value;
    const logKeyword = document.getElementById('logKeyword').value;
    const pem = document.getElementById('pemSelect').value;
    const selectedIps = Array.from(document.querySelectorAll('.ip-checkbox:checked')).map(cb => cb.value);
    const resultBox = document.getElementById('terminalResult');

    if(selectedIps.length === 0) return alert("대상 서버를 선택해 주세요.");
    if(logPath.trim() === "" || logKeyword.trim() === "") return alert("경로와 키워드를 입력해 주세요.");

    const cmd = `if [ -f ${logPath.replace(/'/g,"")} ]; then sudo grep -in "${logKeyword.replace(/'/g,"")}" ${logPath.replace(/'/g,"")} | tail -n 30 || echo "✅ 해당 키워드가 포함된 로그가 없습니다."; else echo "❌ 파일을 찾을 수 없습니다: ${logPath.replace(/'/g,"")}"; fi`;

    resultBox.style.display = 'block';
    resultBox.innerText = `[🔍 통합 로그 검색 중...] ⏳`;
    try {
        const res = await fetch('run_ansible.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ips: selectedIps, cmd: cmd, pem: pem }) });
        resultBox.innerText = `[✨ 검색 완료]\n\n` + await res.text();
    } catch (error) {
        resultBox.innerText = `[오류 발생] ` + error;
    }
}

async function openServerMetrics(serverName, ip, os) {
    if (os.includes('Windows') || os.includes('접속불가') || !ip) return alert("접속 불가 또는 Windows 서버입니다.");

    const modal = document.getElementById('metricsModal');
    const modalTitle = document.getElementById('modalServerTitle');
    const modalContent = document.getElementById('modalContent');
    const pem = document.getElementById('pemSelect').value;
    if(!modal || !modalTitle || !modalContent) return;

    modalTitle.innerText = `🖥️ ${serverName} (${ip}) 자원 현황`;
    modalContent.innerHTML = `<div style="text-align:center; padding:30px; font-weight:bold; color:#2563eb;">⏳ 자원 측정 중...</div>`;
    modal.style.display = 'flex';

    try {
        const response = await fetch('get_server_metrics.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ip: ip, pem: pem }) });
        const textData = await response.text();
        let data;
        try {
            data = JSON.parse(textData);
        } catch (e) {
            modalContent.innerHTML = `오류:\n${textData}`; return;
        }
        if (data.error) {
            modalContent.innerHTML = `⚠️ ${data.error}`;
        } else {
            modalContent.innerHTML = `<div style="display:flex; flex-direction:column; gap:15px;"><div class="metric-box"><div class="metric-label"><span>⚡ CPU</span><span>${data.cpu}%</span></div><div class="progress-bg"><div class="progress-bar-fill" style="width: ${data.cpu}%; background: ${data.cpu > 80 ? '#ef4444' : '#2563eb'};"></div></div></div><div class="metric-box"><div class="metric-label"><span>💾 RAM</span><span>${data.mem.percent}%</span></div><div class="progress-bg"><div class="progress-bar-fill" style="width: ${data.mem.percent}%; background: ${data.mem.percent > 80 ? '#f59e0b' : '#7c3aed'};"></div></div></div><div class="metric-box"><div class="metric-label"><span>💽 Disk</span><span>${data.disk.percent}%</span></div><div class="progress-bg"><div class="progress-bar-fill" style="width: ${data.disk.percent}%; background: ${data.disk.percent > 85 ? '#ef4444' : '#059669'};"></div></div></div></div>`;
        }
    } catch (err) {
        alert(err);
    }
}

function closeModal() {
    let m = document.getElementById('metricsModal');
    if(m) m.style.display = 'none';
}

async function checkNetworkTelnet() {
    const sourceIp = document.getElementById('netSourceIp').value;
    const dest = document.getElementById('netDest').value.trim();
    const port = document.getElementById('netPort').value.trim();
    const protocol = document.getElementById('netProtocol').value;
    const pem = document.getElementById('pemSelectNetwork').value;
    if(!sourceIp || !dest || !port) return alert("출발지, 도착지, 포트를 모두 입력해주세요.");

    const vizArea = document.getElementById('netVizArea');
    const termArea = document.getElementById('netTerminal');
    vizArea.style.display = 'block';
    termArea.style.display = 'block';
    vizArea.innerHTML = `<h3 style="color:#2563eb;">⏳ 통신 라우팅 및 방화벽 점검 중...</h3>`;

    let cmd = (protocol === 'TCP') ? `timeout 5 curl -v telnet://${dest}:${port} < /dev/null 2>&1` : `timeout 5 nc -uzvw 3 ${dest} ${port} 2>&1`;
    try {
        const res = await fetch('run_ansible.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ips: [sourceIp], cmd: cmd, pem: pem }) });
        const text = await res.text();
        termArea.innerText = `[명령어]: ${cmd}\n\n[실행 결과]\n${text}`;

        let isSuccess = (protocol === 'TCP') ? text.includes("Connected to") : (text.toLowerCase().includes("succeeded") || text.toLowerCase().includes("open"));
        if (isSuccess) {
            vizArea.innerHTML = `<h3 style="color:#16a34a; margin-top:25px;">✅ 통신 원활 (Port Open)</h3><p style="color:#64748b; font-size:13px; margin-top:5px;">방화벽이 열려있으며, 목적지 서버 프로세스가 정상 응답합니다.</p>`;
        } else {
            vizArea.innerHTML = `<h3 style="color:#dc2626; margin-top:25px;">❌ 방화벽 차단 또는 서비스 다운</h3><p style="color:#64748b; font-size:13px; margin-top:5px;">보안 장비에서 차단되었거나, 도착지 포트가 닫혀있습니다.</p>`;
        }
    } catch (e) {
        termArea.innerText = `통신 오류: ${e}`;
    }
}

async function fetchDockerContainers() {
    const targetIp = document.getElementById('dockerTargetIp').value;
    const pem = document.getElementById('pemSelectDocker').value;
    if(!targetIp) return alert("대상 서버를 선택해주세요.");

    const tbody = document.querySelector('#dockerTable tbody');
    tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; padding:30px; color:#2563eb;"><strong>⏳ Docker 컨테이너 정보를 가져오는 중입니다...</strong></td></tr>`;

    const cmd = "if [ -x \"/usr/bin/docker\" ]; then sudo /usr/bin/docker ps -a --format '{{.Names}}|{{.Image}}|{{.State}}|{{.Status}}|{{.Ports}}'; else echo 'DOCKER_NOT_FOUND'; fi";
    try {
        const res = await fetch('run_ansible.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ips: [targetIp], cmd: cmd, pem: pem }) });
        const text = await res.text();
        tbody.innerHTML = '';
        if(text.includes('DOCKER_NOT_FOUND')) {
            tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; padding:30px; color:#94a3b8; font-weight:bold;">⚠️ 이 서버에는 Docker가 설치되어 있지 않습니다.</td></tr>`;
            return;
        }
        const lines = text.split('\n').filter(line => line.includes('|'));
        if(lines.length === 0) {
            tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; padding:30px; color:#94a3b8;">생성된 Docker 컨테이너가 없습니다.</td></tr>`;
            return;
        }
        lines.forEach(line => {
            const parts = line.split('|');
            if(parts.length < 4) return;
            const [name, image, state, status, ports] = parts;

            let stateHtml = state.toLowerCase() === 'running' ? '<span class="badge-good">🟢 Running</span>' : (state.toLowerCase() === 'exited' ? '<span class="badge-bad">🔴 Exited</span>' : `<span class="badge-warn">🟡 ${state}</span>`);

            let actions = '';
            if(state.toLowerCase() !== 'running') actions += `<button class="btn-sm-action" onclick="controlDocker('${targetIp}', '${pem}', '${name}', 'start')">▶ 시작</button>`;
            if(state.toLowerCase() === 'running') actions += `<button class="btn-sm-danger" onclick="controlDocker('${targetIp}', '${pem}', '${name}', 'stop')" style="margin-right:3px;">⏹ 정지</button>`;
            actions += `<button class="btn-sm-action" onclick="controlDocker('${targetIp}', '${pem}', '${name}', 'restart')">🔄 재시작</button>`;

            tbody.innerHTML += `<tr><td><strong>${name}</strong></td><td style="color:#64748b; font-size:13px;">${image}</td><td style="text-align:center;">${stateHtml}<br><span style="font-size:11px; color:#94a3b8;">${status}</span></td><td style="font-size:12px;">${ports || '-'}</td><td style="text-align:center;">${actions}</td></tr>`;
        });
    } catch(e) {
        tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; color:#dc2626;">❌ 서버 통신 오류가 발생했습니다.</td></tr>`;
    }
}

async function controlDocker(ip, pem, containerName, action) {
    let actionName = action === 'start' ? '시작' : (action === 'stop' ? '정지' : '재시작');
    if(!confirm(`[${containerName}] 컨테이너를 ${actionName}하시겠습니까?`)) return;

    const cmd = `sudo /usr/bin/docker ${action} ${containerName} && echo 'SUCCESS'`;
    try {
        const res = await fetch('run_ansible.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ips: [ip], cmd: cmd, pem: pem }) });
        const text = await res.text();
        if(text.includes('SUCCESS')) {
            alert(`✅ 컨테이너가 성공적으로 ${actionName}되었습니다.`);
            fetchDockerContainers();
        } else {
            alert(`❌ 제어 실패: \n${text}`);
        }
    } catch(e) {
        alert("통신 오류: " + e);
    }
}

function toggleKisaAll(source) {
    document.querySelectorAll('.kisa-cb').forEach(cb => cb.checked = source.checked);
}

async function fixKisaItem(targetIp, pem, code) {
    let cmd = "";
    let manualMsg = "";
    switch(code) {
        case "U-01": cmd = "sudo sed -i '/^#*PermitRootLogin/c\\PermitRootLogin no' /etc/ssh/sshd_config && sudo systemctl restart sshd && echo 'SUCCESS'"; break;
        case "U-02": cmd = "sudo bash -c 'grep -q \"^minlen\" /etc/security/pwquality.conf || echo \"minlen = 8\" >> /etc/security/pwquality.conf'; sudo sed -i '/^#*minlen/c\\minlen = 8' /etc/security/pwquality.conf && echo 'SUCCESS'"; break;
        case "U-04": cmd = "sudo chmod 400 /etc/shadow && sudo chown root:root /etc/shadow && echo 'SUCCESS'"; break;
        case "U-07": cmd = "sudo chmod 644 /etc/passwd && sudo chown root:root /etc/passwd && echo 'SUCCESS'"; break;
        case "U-19": cmd = "sudo systemctl disable --now finger 2>/dev/null; echo 'SUCCESS'"; break;
        case "U-20": cmd = "sudo systemctl disable --now vsftpd 2>/dev/null; echo 'SUCCESS'"; break;
        case "U-03": manualMsg = "PAM 모듈 설정은 수동 조치를 권장합니다."; break;
        case "U-05": manualMsg = "PATH 환경변수 변경은 수동 조치를 권장합니다."; break;
        case "U-06": manualMsg = "root 외 UID 0 계정 삭제는 관리자 검토가 필요합니다."; break;
        case "U-60": manualMsg = "SSH 포트 변경 시 연결이 끊어질 수 있습니다."; break;
    }
    if (manualMsg) { alert("✋ " + manualMsg); return; }
    if(!confirm(`[${code}] 취약점을 자동으로 조치하시겠습니까?\n대상 서버: ${targetIp}`)) return;
    try {
        const res = await fetch('run_ansible.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ips: [targetIp], cmd: cmd, pem: pem }) });
        const text = await res.text();
        if(text.includes('SUCCESS')) {
            alert(`✅ [${code}] 자동 조치가 완료되었습니다!`);
            const btn = document.getElementById(`fix-btn-${code}`);
            if(btn) { btn.style.background = "#16a34a"; btn.innerText = "✅ 조치 완료"; btn.disabled = true; }
        } else { alert("❌ 조치 실패: " + text); }
    } catch(e) { alert("❌ 서버 통신 오류"); }
}

async function startKisaScan() {
    const targetSelect = document.getElementById('kisaTargetIp');
    const targetIp = targetSelect.value;
    const targetName = targetSelect.options[targetSelect.selectedIndex].text;
    const pem = document.getElementById('pemSelectSecurity').value;
    if(!targetIp) return alert("대상 서버를 선택해주세요.");
    const checkedItems = Array.from(document.querySelectorAll('.kisa-cb:checked')).map(cb => cb.value);
    if(checkedItems.length === 0) return alert("최소 1개 이상의 진단 항목을 선택해주세요.");

    const tbody = document.querySelector('#securityTable tbody');
    tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; padding:30px; color:#2563eb;"><strong>⏳ [${targetName}] 취약점을 점검 중입니다...</strong></td></tr>`;

    let cmd = "echo '---KISA_RESULT_START---'; ";
    if(checkedItems.includes("U-01")) cmd += "echo \"U-01|$(sudo sshd -T 2>/dev/null | grep -i '^permitrootlogin' | cut -d' ' -f2)\"; ";
    if(checkedItems.includes("U-02")) cmd += "echo \"U-02|$(sudo grep -E '^minlen' /etc/security/pwquality.conf 2>/dev/null | cut -d'=' -f2 | tr -d ' ' || echo '설정없음')\"; ";
    if(checkedItems.includes("U-03")) cmd += "echo \"U-03|$(sudo grep -i 'deny=' /etc/pam.d/system-auth /etc/pam.d/password-auth 2>/dev/null | wc -l)\"; ";
    if(checkedItems.includes("U-04")) cmd += "echo \"U-04|$(sudo stat -c '%a:%U' /etc/shadow 2>/dev/null || echo '확인불가')\"; ";
    if(checkedItems.includes("U-05")) cmd += "echo \"U-05|$(echo $PATH | egrep '(^|:)(\\.|:|$)' >/dev/null && echo '취약' || echo '양호')\"; ";
    if(checkedItems.includes("U-06")) cmd += "echo \"U-06|$(sudo cut -d: -f1,3 /etc/passwd 2>/dev/null | grep ':0$' | grep -v '^root:' | wc -l)\"; ";
    if(checkedItems.includes("U-07")) cmd += "echo \"U-07|$(sudo stat -c '%a:%U' /etc/passwd 2>/dev/null || echo '확인불가')\"; ";
    if(checkedItems.includes("U-19")) cmd += "echo \"U-19|$(sudo ss -tuln 2>/dev/null | grep ':79\\b' | wc -l)\"; ";
    if(checkedItems.includes("U-20")) cmd += "echo \"U-20|$(sudo ss -tuln 2>/dev/null | grep ':21\\b' | wc -l)\"; ";
    if(checkedItems.includes("U-60")) cmd += "echo \"U-60|$(sudo sshd -T 2>/dev/null | grep '^port ' | cut -d' ' -f2)\"; ";

    try {
        const res = await fetch('run_ansible.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ips: [targetIp], cmd: cmd, pem: pem }) });
        const text = await res.text();
        const lines = text.split('\n');
        let parseStarted = false;
        let results = {};

        for(let line of lines) {
            if(line.includes('---KISA_RESULT_START---')) { parseStarted = true; continue; }
            if(parseStarted && line.includes('|')) {
                const parts = line.split('|');
                if(parts.length >= 2) results[parts[0].trim()] = parts[1].trim();
            }
        }
        tbody.innerHTML = '';

        const kisaInfo = {
            "U-01": { title: "[U-01] root 계정 원격 접속 제한", desc: "root 직접 접속 차단 여부" },
            "U-02": { title: "[U-02] 패스워 복잡성 설정", desc: "pwquality.conf 내 최소 길이 등" },
            "U-03": { title: "[U-03] 계정 잠금 임계값 설정", desc: "실패 시 잠금 설정 횟수" },
            "U-04": { title: "[U-04] /etc/shadow 파일 소유자 및 권한", desc: "권한 400 이하 및 root 소유 여부" },
            "U-05": { title: "[U-05] root 홈, 패스 디렉터리 권한", desc: "PATH 환경변 내 '.' 포함 여부" },
            "U-06": { title: "[U-06] root 이외의 UID 0 금지", desc: "root 외 UID 0 계정 존재 여부" },
            "U-07": { title: "[U-07] /etc/passwd 파일 소유자 및 권한", desc: "권한 644 이하 및 root 소유 여부" },
            "U-19": { title: "[U-19] Finger 서비스 비활성화", desc: "79번 포트 오픈 여부" },
            "U-20": { title: "[U-20] Anonymous FTP 비활성화", desc: "21번 포트 오픈 여부" },
            "U-60": { title: "[U-60] SSH 서비스 포트 변경 권고", desc: "기본 22번 포트 변경 사용 여부" }
        };

        for (let code of checkedItems) {
            let val = results[code] || "응답실패/확인불가";
            let statusHtml = '';
            let actionHtml = '';

            if(code === "U-01") statusHtml = (val.toLowerCase() === 'no') ? '<span class="badge-good">양호</span>' : '<span class="badge-bad">취약</span>';
            else if(code === "U-02") statusHtml = (val !== '설정없음' && val !== '응답실패/확인불가' && val !== '') ? '<span class="badge-good">양호</span>' : '<span class="badge-warn">수동확인</span>';
            else if(code === "U-03") statusHtml = (!isNaN(parseInt(val)) && parseInt(val) > 0) ? '<span class="badge-good">양호</span>' : '<span class="badge-bad">취약</span>';
            else if(code === "U-04") statusHtml = (val.includes('000:root') || val.includes('400:root')) ? '<span class="badge-good">양호</span>' : '<span class="badge-bad">취약</span>';
            else if(code === "U-05") statusHtml = (val === '양호') ? '<span class="badge-good">양호</span>' : '<span class="badge-bad">취약</span>';
            else if(code === "U-06") statusHtml = (val === '0') ? '<span class="badge-good">양호</span>' : '<span class="badge-bad">취약</span>';
            else if(code === "U-07") statusHtml = (val.includes('644:root') || val.includes('600:root')) ? '<span class="badge-good">양호</span>' : '<span class="badge-warn">수동확인</span>';
            else if(code === "U-19") statusHtml = (val === '0') ? '<span class="badge-good">양호</span>' : '<span class="badge-bad">취약</span>';
            else if(code === "U-20") statusHtml = (val === '0') ? '<span class="badge-good">양호</span>' : '<span class="badge-bad">취약</span>';
            else if(code === "U-60") statusHtml = (val !== '22' && !val.includes('응답실패')) ? '<span class="badge-good">양호</span>' : '<span class="badge-bad">취약</span>';

            if (statusHtml.includes('취약')) {
                actionHtml = `<button id="fix-btn-${code}" onclick="fixKisaItem('${targetIp}', '${pem}', '${code}')" style="background:#2563eb; color:white; border:none; padding:6px 12px; border-radius:6px; font-weight:bold; cursor:pointer; font-size:12px;">🔧 자동 조치</button>`;
            } else if (statusHtml.includes('수동확인')) {
                actionHtml = `<button id="fix-btn-${code}" onclick="fixKisaItem('${targetIp}', '${pem}', '${code}')" style="background:#475569; color:white; border:none; padding:6px 12px; border-radius:6px; font-weight:bold; cursor:pointer; font-size:12px;">✋ 수동확인 필요</button>`;
            } else {
                actionHtml = '<span style="color:#16a34a; font-weight:bold; font-size:13px;">✅ 해당없음 (안전)</span>';
            }

            tbody.innerHTML += `<tr><td><strong>${kisaInfo[code].title}</strong></td><td style="color:#64748b; font-size:13px;">${kisaInfo[code].desc}</td><td style="text-align:center;">${statusHtml}</td><td style="text-align:center;">${actionHtml}</td></tr>`;
        }
    } catch(e) {
        tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; color:#dc2626;">❌ 서버 통신 오류가 발생했습니다.</td></tr>`;
    }
}

async function registerJiraTicket(serverName, eventType, eventDate, currentTicket) {
    const inputStr = prompt(`[${serverName}] ${eventType} 작업에 대한 지라 티켓 번호를 입력하세요.\n(예: GIWP-3193)`, currentTicket);
    if (inputStr === null) return;
    try {
        const res = await fetch('index.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ ajax_action: 'jira_save', server_name: serverName, event_type: eventType, event_date: eventDate, jira_number: inputStr.trim() }) });
        const json = await res.json();
        if(json.success) {
            const item = window.jiraEventData.find(i => i.name === serverName && i.type === eventType && i.date === eventDate);
            if(item) item.ticket = inputStr.trim();
            renderJiraTable();
        } else alert("오류 발생");
    } catch(e) {
        alert("통신 오류: " + e);
    }
}

function initJiraTab() {
    const select = document.getElementById('jiraMonthSelect');
    if (!select) return;

    let months = new Set();
    window.jiraEventData.forEach(ev => {
        if (ev.ym) months.add(ev.ym);
    });

    let monthsArr = Array.from(months).sort().reverse();

    select.innerHTML = '';
    if (monthsArr.length === 0) {
        select.innerHTML = '<option value="">데이터 없음</option>';
    } else {
        monthsArr.forEach(m => {
            let opt = document.createElement('option');
            opt.value = m;
            opt.innerText = m;
            select.appendChild(opt);
        });
        
        let currentYM = new Date().toISOString().slice(0, 7);
        if (monthsArr.includes(currentYM)) {
            select.value = currentYM;
        } else {
            select.value = monthsArr[0];
        }
    }
    
    renderJiraTable();
}

function renderJiraTable() {
    const select = document.getElementById('jiraMonthSelect');
    const tbody = document.querySelector('#jiraTable tbody');
    if (!select || !tbody) return;

    const selectedMonth = select.value;
    tbody.innerHTML = '';

    const filtered = window.jiraEventData.filter(ev => ev.ym === selectedMonth);

    if (filtered.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:30px; color:#94a3b8; font-weight:bold;">해당 월에 감지된 서버 변동 내역이 없습니다.</td></tr>`;
        return;
    }

    filtered.sort((a, b) => b.date.localeCompare(a.date));

    filtered.forEach(ev => {
        let typeBadge = ev.type === '생성'
            ? '<span style="background:#10b981; color:white; padding:4px 10px; border-radius:6px; font-size:12px; font-weight:bold;">생성</span>'
            : '<span style="background:#ef4444; color:white; padding:4px 10px; border-radius:6px; font-size:12px; font-weight:bold;">삭제</span>';

        let ticketDisplay = ev.ticket
            ? `<span style="color:#2563eb; font-weight:bold;">${ev.ticket}</span>`
            : `<span style="color:#94a3b8; font-style:italic;">미등록</span>`;

        let btnText = ev.ticket ? '수정' : '등록';
        let btnColor = ev.ticket ? '#64748b' : '#3b82f6';

        let tr = document.createElement('tr');
        tr.innerHTML = `
            <td style="text-align:center; vertical-align:middle;">${typeBadge}</td>
            <td style="vertical-align:middle;"><strong>${ev.name}</strong></td>
            <td style="vertical-align:middle;">${ev.ip}</td>
            <td style="text-align:center; vertical-align:middle; color:#475569;">${ev.date}</td>
            <td style="text-align:center; vertical-align:middle;">${ticketDisplay}</td>
            <td style="text-align:center; vertical-align:middle;">
                <button onclick="registerJiraTicket('${ev.name}', '${ev.type}', '${ev.date}', '${ev.ticket || ''}')" 
                        style="background:${btnColor}; color:white; border:none; padding:6px 12px; border-radius:6px; font-size:12px; font-weight:bold; cursor:pointer;">
                    ${btnText}
                </button>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

/* ================= 🗄️ DBaaS (MySQL) 프론트엔드 연동 로직 ================= */
async function loadDbaasData() {
    const tbody = document.querySelector('#dbaasTable tbody');
    if(!tbody) return;
    tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:30px; color:#2563eb;"><strong>⏳ 데이터를 불러오는 중입니다...</strong></td></tr>`;
    try {
        // 백그라운드 파이썬 스크립트가 생성한 json을 직접 읽어옵니다 (캐시 방지용 쿼리 파라미터 추가)
        const res = await fetch('mysql_dashboard_data.json?t=' + new Date().getTime());
        if(!res.ok) throw new Error('파일을 찾을 수 없습니다.');
        const data = await res.json();
        renderDbaasTable(data);
    } catch(e) {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:30px; color:#dc2626;">❌ DBaaS 데이터를 불러오지 못했습니다. <br>(백그라운드 동기화 파이썬 스크립트가 정상 실행되었는지 확인해주세요)</td></tr>`;
    }
}

function renderDbaasTable(data) {
    const tbody = document.querySelector('#dbaasTable tbody');
    tbody.innerHTML = '';
    if(!data || data.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:30px; color:#94a3b8;">생성된 DB 인스턴스가 없습니다.</td></tr>`;
        return;
    }

    data.forEach(db => {
        let statusHtml = db.cluster_state === 'RUNNING' ? '<span class="badge-good">🟢 RUNNING</span>' : `<span class="badge-warn">🟡 ${db.cluster_state}</span>`;
        let nodesHtml = '';
        if(db.nodes && db.nodes.length > 0) {
            nodesHtml = '<div style="display:flex; flex-direction:column; gap:4px;">';
            db.nodes.forEach(n => {
                let roleColor = n.role === 'ACTIVE' ? '#2563eb' : '#d97706';
                let ipDisplay = n.ip ? n.ip : 'IP 미할당';
                nodesHtml += `<div style="font-size:12px;"><strong style="color:${roleColor};">[${n.role}]</strong> ${n.server_name} <span style="color:#94a3b8;">(${ipDisplay})</span></div>`;
            });
            nodesHtml += '</div>';
        } else {
            nodesHtml = '-';
        }

        let vipDisplay = db.vip ? db.vip : '미할당';
        let portDisplay = db.port ? db.port : '-';

        let tr = document.createElement('tr');
        tr.innerHTML = `
            <td style="text-align:center; font-weight:bold;">${db.engine || 'MySQL'}</td>
            <td style="font-weight:bold;">${db.cluster_name}</td>
            <td style="text-align:center; vertical-align:middle;">${statusHtml}</td>
            <td style="text-align:center; font-family:monospace; font-size:13px; color:#3b82f6; font-weight:bold;">${vipDisplay}:${portDisplay}</td>
            <td style="text-align:center; font-size:13px; color:#475569;">${db.spec || '-'}</td>
            <td>${nodesHtml}</td>
        `;
        tbody.appendChild(tr);
    });
}

function filterDbaas() {
    let keyword = document.getElementById('searchDbaas').value.toLowerCase();
    document.querySelectorAll('#dbaasTable tbody tr').forEach(row => {
        let text = row.innerText.toLowerCase();
        row.style.display = text.includes(keyword) ? '' : 'none';
    });
}
/* ========================================================================= */

</script>

</body>
</html>
