<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($config['session']['name'] ?? 'AVALIESSTSESSID');

    // Mantem compatibilidade com instalacoes PHP mais antigas.
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        ini_set('session.cookie_httponly', '1');
        @ini_set('session.cookie_samesite', 'Lax');
        session_set_cookie_params(0, '/');
    }

    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/database.php';
