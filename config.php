<?php

declare(strict_types=1);

return [
    'db' => [
        'host' => getenv('AVALIESST_DB_HOST') ?: '127.0.0.1',
        'port' => getenv('AVALIESST_DB_PORT') ?: '3306',
        'name' => getenv('AVALIESST_DB_NAME') ?: 'avaliiesst',
        'user' => getenv('AVALIESST_DB_USER') ?: 'root',
        'pass' => getenv('AVALIESST_DB_PASS') ?: '',
    ],
    'session' => [
        'name' => getenv('AVALIESST_SESSION_NAME') ?: 'AVALIESSTSESSID',
    ],
];
