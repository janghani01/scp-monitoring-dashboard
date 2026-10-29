<?php
$requestPayload = file_get_contents('php://input');
$data = json_decode($requestPayload, true);

$ips = $data['ips'] ?? [];
$command = $data['cmd'] ?? '';
$pemFile = $data['pem'] ?? 'works_mgmt.pem'; 

if (empty($ips) || empty($command)) {
    echo "오류: 선택된 서버가 없거나 명령어가 비어있습니다.";
    exit;
}

$allowedPems = ['works_mgmt.pem', 'rocky.pem'];
if (!in_array($pemFile, $allowedPems)) {
    echo "오류: 허용되지 않은 키 파일입니다.";
    exit;
}

$inventory = implode(',', $ips) . ',';
$escapedCmd = escapeshellarg($command);
$pemPath = '/opt/scp/' . $pemFile; 

$ansibleCmd = "ANSIBLE_HOME=/tmp ANSIBLE_LOCAL_TEMP=/tmp ansible all -i '{$inventory}' -u rocky --private-key {$pemPath} --ssh-common-args='-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null' -m shell -a {$escapedCmd} 2>&1";

$output = shell_exec($ansibleCmd);

// --- [신규 추가] 로그 기록 로직 시작 ---
$logFile = '/opt/scp/ansible_audit.log';
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'Unknown'; // 접속한 사용자의 PC IP
$timestamp = date('Y-m-d H:i:s');
$targetCount = count($ips);

$logMessage = sprintf(
    "[%s] [IP: %s] [명령어 실행] 대상: %d대 (%s) | 명령어: %s\n",
    $timestamp, $clientIp, $targetCount, implode(',', $ips), $command
);
file_put_contents($logFile, $logMessage, FILE_APPEND); // 파일 끝에 내용 이어붙이기
// --- [신규 추가] 로그 기록 로직 끝 ---

if ($output === null) {
    echo "명령어 실행에 실패했습니다.\n내부 명령어: $ansibleCmd";
} else {
    echo $output;
}
?>
