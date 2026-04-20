<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (request_method() !== 'POST') {
    send_json(405, [
        'success' => false,
        'message' => 'Metodo nao permitido.',
    ]);
}

$input = read_json_input();
$code = strtoupper(trim((string) ($input['code'] ?? '')));

if ($code === '') {
    send_json(422, [
        'success' => false,
        'message' => 'Digite o codigo de acesso para continuar.',
    ]);
}

$pdo = db();

$statement = $pdo->prepare(
    'SELECT
         ac.id,
         ac.code,
         ac.expires_at,
         ac.is_active,
         ac.scope_label,
         c.name AS company_name,
         f.name AS form_name
     FROM employee_access_codes ac
     INNER JOIN companies c ON c.id = ac.company_id
     INNER JOIN forms f ON f.id = ac.form_id
     WHERE ac.code = :code
     LIMIT 1'
);
$statement->execute(['code' => $code]);
$access = $statement->fetch();

if (!$access) {
    send_json(404, [
        'success' => false,
        'message' => 'Codigo de acesso invalido.',
    ]);
}

if ((int) $access['is_active'] !== 1) {
    send_json(403, [
        'success' => false,
        'message' => 'Este codigo esta inativo.',
    ]);
}

if (!empty($access['expires_at']) && strtotime((string) $access['expires_at']) < time()) {
    send_json(410, [
        'success' => false,
        'message' => 'Este codigo expirou.',
    ]);
}

$sessionPublicId = generate_access_session_public_id($pdo);
$ipMasked = mask_ip_address(client_ip_address());

$sessionStatement = $pdo->prepare(
    'INSERT INTO access_code_sessions (access_code_id, session_public_id, ip_masked, status)
     VALUES (:access_code_id, :session_public_id, :ip_masked, :status)'
);
$sessionStatement->execute([
    'access_code_id' => (int) $access['id'],
    'session_public_id' => $sessionPublicId,
    'ip_masked' => $ipMasked,
    'status' => 'pending',
]);

send_json(200, [
    'success' => true,
    'message' => 'Codigo validado com sucesso.',
    'data' => [
        'code' => (string) $access['code'],
        'companyName' => (string) $access['company_name'],
        'formName' => (string) $access['form_name'],
        'scopeLabel' => (string) $access['scope_label'],
        'expiresAt' => (string) $access['expires_at'],
        'sessionId' => $sessionPublicId,
    ],
]);
