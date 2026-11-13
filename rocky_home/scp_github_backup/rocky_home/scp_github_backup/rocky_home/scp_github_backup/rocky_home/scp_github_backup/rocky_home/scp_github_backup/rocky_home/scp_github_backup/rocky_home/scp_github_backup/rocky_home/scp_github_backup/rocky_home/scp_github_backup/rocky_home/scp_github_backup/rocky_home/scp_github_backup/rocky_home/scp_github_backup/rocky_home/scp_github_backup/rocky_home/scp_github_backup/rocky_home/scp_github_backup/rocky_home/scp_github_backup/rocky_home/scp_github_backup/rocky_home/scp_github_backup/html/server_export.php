<?php

$json = file_get_contents('/opt/scp/servers.json');
$data = json_decode($json, true);

if (!$data || !isset($data['servers'])) {
    die("API ERROR");
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=server_list.csv');

$out = fopen('php://output', 'w');

fputcsv($out, [
    'Server Name',
    'IP Address',
    'State',
    'vCPU',
    'RAM(GB)',
    'Created At'
]);

foreach ($data['servers'] as $server) {

    $ip = '';

    if (isset($server['addresses'][0]['ip_addresses'][0]['ip_address'])) {
        $ip = $server['addresses'][0]['ip_addresses'][0]['ip_address'];
    }

    fputcsv($out, [
        $server['name'] ?? '',
        $ip,
        $server['state'] ?? '',
        $server['server_type']['vcpus'] ?? '',
        $server['server_type']['ram'] ?? '',
        $server['created_at'] ?? ''
    ]);
}

fclose($out);
?>
