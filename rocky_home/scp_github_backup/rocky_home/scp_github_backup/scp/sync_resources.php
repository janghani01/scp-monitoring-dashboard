<?php
// /opt/scp/sync_resources.php
$baseDir = '/opt/scp/';
$historyFile = $baseDir . 'alert_history.json';

// 💡 알아낸 완벽한 CLI 명령어들로 세팅 완료!
$commands = [
    'IAM' => '/usr/bin/scp-cli iam user list --instance_id 9385572a5f534b8590f6c9c0c9eb408a --format json 2>/dev/null',
    'SG'  => '/usr/bin/scp-cli security-group security-group list --format json 2>/dev/null',
    'LB'  => '/usr/bin/scp-cli loadbalancer loadbalancer list --format json 2>/dev/null'
];

$historyData = file_exists($historyFile) ? json_decode(file_get_contents($historyFile), true) : [];
if (!is_array($historyData)) $historyData = [];

$now = date('Y-m-d H:i:s');
$changesDetected = false;

foreach ($commands as $type => $cmd) {
    $currentFile = $baseDir . strtolower($type) . '_current.json';
    
    $output = shell_exec($cmd);
    $newData = json_decode($output, true);
    if (empty($newData)) continue;

    $newCount = isset($newData['contents']) ? count($newData['contents']) : count($newData);
    
    $oldData = file_exists($currentFile) ? json_decode(file_get_contents($currentFile), true) : null;
    
    if ($oldData !== null) {
        $oldCount = isset($oldData['contents']) ? count($oldData['contents']) : count($oldData);
        if ($newCount !== $oldCount) {
            $diff = $newCount - $oldCount;
            $actionStr = $diff > 0 ? "추가됨 (+{$diff})" : "삭제됨 ({$diff})";
            array_unshift($historyData, [
                'time' => $now, 'server' => 'SCP Cloud (' . $type . ')', 'type' => $type . ' 변경',
                'message' => "{$type} 리소스 개수 변동 감지 (기존: {$oldCount}개 → 현재: {$newCount}개 / {$actionStr})"
            ]);
            $changesDetected = true;
        }
    }
    
    file_put_contents($currentFile, json_encode($newData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    chmod($currentFile, 0666);
}

if ($changesDetected) {
    $historyData = array_slice($historyData, 0, 500);
    file_put_contents($historyFile, json_encode($historyData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    chmod($historyFile, 0666);
}
?>
