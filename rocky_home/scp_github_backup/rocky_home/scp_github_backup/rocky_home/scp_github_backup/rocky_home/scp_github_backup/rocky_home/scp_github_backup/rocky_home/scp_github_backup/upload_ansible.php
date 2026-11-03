<?php
if (!isset($_FILES['file']) || !isset($_POST['ips']) || !isset($_POST['destPath'])) {
    echo "오류: 파일, 서버 IP, 또는 목적지 경로가 누락되었습니다.";
    exit;
}

$ips = explode(',', $_POST['ips']); 
$destPath = trim($_POST['destPath']); 
$pemFile = $_POST['pem'] ?? 'works_mgmt.pem';

$allowedPems = ['works_mgmt.pem', 'rocky.pem'];
if (!in_array($pemFile, $allowedPems)) {
    echo "오류: 허용되지 않은 키 파일입니다.";
    exit;
}
$pemPath = '/opt/scp/' . $pemFile;
$inventory = implode(',', $ips) . ',';

$uploadDir = '/tmp/ansible_uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true); 
}

$tmpName = $_FILES['file']['tmp_name'];
$fileName = $_FILES['file']['name'];
$targetTempFile = $uploadDir . $fileName;

if (!move_uploaded_file($tmpName, $targetTempFile)) {
    echo "오류: 중앙 서버 임시 폴더에 파일을 저장하지 못했습니다.";
    exit;
}

$destDir = dirname($destPath);
$escapedDestDir = escapeshellarg($destDir);
$escapedDestPath = escapeshellarg($destPath);
$escapedTargetFile = escapeshellarg($targetTempFile);

$cmdMkdir = "ANSIBLE_HOME=/tmp ANSIBLE_LOCAL_TEMP=/tmp ansible all -i '{$inventory}' -u rocky --private-key {$pemPath} --ssh-common-args='-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null' -m shell -a \"mkdir -p {$escapedDestDir}\" 2>&1";
$cmdCopy = "ANSIBLE_HOME=/tmp ANSIBLE_LOCAL_TEMP=/tmp ansible all -i '{$inventory}' -u rocky --private-key {$pemPath} --ssh-common-args='-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null' -m copy -a \"src={$escapedTargetFile} dest={$escapedDestPath}\" 2>&1";

echo "📁 [1/2] 대상 서버에 경로가 있는지 확인 및 생성 중...\n";
echo shell_exec($cmdMkdir) . "\n";
echo "🚀 [2/2] 파일 복사 중...\n";
echo shell_exec($cmdCopy);

@unlink($targetTempFile);

// --- [신규 추가] 로그 기록 로직 시작 ---
$logFile = '/opt/scp/ansible_audit.log';
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
$timestamp = date('Y-m-d H:i:s');
$targetCount = count($ips);

$logMessage = sprintf(
    "[%s] [IP: %s] [파일 배포] 대상: %d대 (%s) | 파일명: %s -> 저장경로: %s\n",
    $timestamp, $clientIp, $targetCount, implode(',', $ips), $fileName, $destPath
);
file_put_contents($logFile, $logMessage, FILE_APPEND);
// --- [신규 추가] 로그 기록 로직 끝 ---
?>
