<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/send_dtr.php';

$params = [
    'device_id' => '119',
    'date_from' => '2026-06-01',
    'date_to'   => '2026-06-07',
    'recipient' => 'dalmanflynn@gmail.com',
    'subject'   => 'DTR Test {{name}} ({{period}})',
    'body'      => 'Hello {{name}}, attached is your DTR for {{period}}'
];

$result = sendDTREmail($params);

echo "<pre>";
print_r($result);
echo "</pre>";