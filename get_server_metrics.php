<?php
session_start();
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => '로그인이 필요합니다.']);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
$ip = trim($data['ip'] ?? '');
$selected_pem = trim($data['pem'] ?? 'works_mgmt.pem');

if (empty($ip)) {
    echo json_encode(['error' => 'IP 주소가 입력되지 않았습니다.']);
    exit;
}

// 1. 자기 자신(test-mgmt-01) 로컬 수집
if ($ip === '172.16.118.63' || $ip === '127.0.0.1') {
    $load = sys_getloadavg();
    $cpu = round(($load[0] ?? 0) * 10, 1);
    if ($cpu > 100) $cpu = 100;
    
    $meminfo = is_file('/proc/meminfo') ? file_get_contents('/proc/meminfo') : '';
    preg_match('/MemTotal:\s+(\d+) kB/', $meminfo, $tm);
    preg_match('/MemAvailable:\s+(\d+) kB/', $meminfo, $am);
    $tot = round(($tm[1] ?? 0)/1024/1024, 1);
    $used = round((($tm[1] ?? 0) - ($am[1] ?? 0))/1024/1024, 1);
    $memPct = $tot > 0 ? round(($used/$tot)*100, 1) : 0;

    $dt = round(disk_total_space("/") / 1024 / 1024 / 1024, 1);
    $du = round(($dt - disk_free_space("/") / 1024 / 1024 / 1024), 1);
    $dp = $dt > 0 ? round(($du/$dt)*100, 1) : 0;

    echo json_encode([
        'success' => true,
        'ip' => $ip,
        'cpu' => $cpu,
        'mem' => ['used' => $used, 'total' => $tot, 'percent' => $memPct],
        'disk' => ['used' => $du . 'G', 'total' => $dt . 'G', 'percent' => $dp]
    ]);
    exit;
}

// 2. 원격 수집용 스크립트를 Base64로 인코딩하여 따옴표/특수문자 꼬임 원천 차단
$raw_sh = "top -bn1 | grep Cpu | awk '{print $2+$4}'; free -m | awk 'NR==2{print $3, $2}'; df -h / | awk 'NR==2{print $3, $2, $5}'";
$b64_cmd = base64_encode($raw_sh);
$remote_exec = "echo '{$b64_cmd}' | base64 -d | bash";

// 시도할 PEM 키 및 사용자 (선택된 키/계정 우선)
$pems = [$selected_pem];
if ($selected_pem !== 'rocky.pem') $pems[] = 'rocky.pem';
if ($selected_pem !== 'works_mgmt.pem') $pems[] = 'works_mgmt.pem';

$users = ['rocky', 'ubuntu'];

$out = '';
$last_raw_err = '';

$env_prefix = "export HOME=/tmp; export ANSIBLE_LOCAL_TEMP=/tmp; export ANSIBLE_REMOTE_TEMP=/tmp; export ANSIBLE_HOST_KEY_CHECKING=False; export PATH=\$PATH:/usr/local/bin:/usr/bin;";

foreach ($pems as $pem) {
    $pem_path = "/opt/scp/" . preg_replace('/[^a-zA-Z0-9_\.-]/', '', $pem);
    if (!file_exists($pem_path)) continue;

    foreach ($users as $user) {
        $cmd = "{$env_prefix} ansible all -i '{$ip},' -u {$user} --private-key={$pem_path} -m shell -a \"{$remote_exec}\" --ssh-common-args=\"-o StrictHostKeyChecking=no -o ConnectTimeout=5\" 2>&1";
        $result = shell_exec($cmd);

        if (!empty($result) && (strpos($result, 'rc=0') !== false || strpos($result, 'CHANGED') !== false)) {
            $out = $result;
            break 2;
        } else {
            $last_raw_err = $result;
        }
    }
}

if (empty($out)) {
    echo json_encode(['error' => "Ansible 수집 실패 ({$ip})\n로그:\n" . trim($last_raw_err)]);
    exit;
}

// 응답 데이터 파싱
$raw_lines = explode("\n", trim($out));
$parsed_rows = [];

foreach ($raw_lines as $line) {
    $line = trim($line);
    if ($line !== '' && !preg_match('/(CHANGED|SUCCESS|rc=0|172\.|\[USER)/i', $line)) {
        $cols = preg_split('/\s+/', $line);
        if (count($cols) >= 1) {
            $parsed_rows[] = $cols;
        }
    }
}

if (count($parsed_rows) < 3) {
    echo json_encode(['error' => "자원 데이터 파싱 실패. Raw Output:\n" . $out]);
    exit;
}

// CPU
$cpu = round((float)($parsed_rows[0][0] ?? 0), 1);

// Memory (MB -> GB 변환)
$mUsed = round(((float)($parsed_rows[1][0] ?? 0)) / 1024, 1);
$mTotal = round(((float)($parsed_rows[1][1] ?? 0)) / 1024, 1);
$mPct = $mTotal > 0 ? round(($mUsed / $mTotal) * 100, 1) : 0;

// Disk
$dUsed = $parsed_rows[2][0] ?? '-';
$dTotal = $parsed_rows[2][1] ?? '-';
$dPct = (float)str_replace('%', '', $parsed_rows[2][2] ?? 0);

echo json_encode([
    'success' => true,
    'ip' => $ip,
    'cpu' => $cpu,
    'mem' => ['used' => $mUsed, 'total' => $mTotal, 'percent' => $mPct],
    'disk' => ['used' => $dUsed, 'total' => $dTotal, 'percent' => $dPct]
]);
