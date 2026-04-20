<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$method = request_method();

if ($method === 'GET') {
    $user = current_user();

    if ($user === null) {
        send_json(401, [
            'success' => false,
            'message' => 'Sessao nao encontrada.',
        ]);
    }

    send_json(200, [
        'success' => true,
        'data' => $user,
    ]);
}

if ($method === 'DELETE') {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', false, true);
    }

    session_destroy();

    send_json(200, [
        'success' => true,
        'message' => 'Logout realizado com sucesso.',
    ]);
}

send_json(405, [
    'success' => false,
    'message' => 'Metodo nao permitido.',
]);
