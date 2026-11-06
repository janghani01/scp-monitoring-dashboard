<?php

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=servers.csv');

$fp = fopen('php://output', 'w');

fputcsv($fp, [
    'Server Name',
    'IP',
    'State',
    'vCPU',
    'RAM'
]);

$json = file_get_contents('/opt/scp/servers.json');

$data = json_decode($json, true);

foreach ($data['servers'] as $server) {

    $ip = '';

    if (isset($server['addresses'][0]['ip_addresses'][0]['ip_address'])) {
        $ip = $server['addresses'][0]['ip_addresses'][0]['ip_address'];
    }

    fputcsv($fp, [
        $server['name'],
        $ip,
        $server['state'],
        $server['server_type']['vcpus'],
        $server['server_type']['ram']
    ]);
}

fclose($fp);
?>
