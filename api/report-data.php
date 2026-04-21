<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/reporting.php';

require_admin();

if (request_method() !== 'GET') {
    send_json(405, [
        'success' => false,
        'message' => 'Metodo nao permitido.',
    ]);
}

$pdo = db();
$payload = reporting_build_payload($pdo, $_GET);

send_json(200, [
    'success' => true,
    'data' => $payload,
]);
