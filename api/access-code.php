<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

function create_access_session(PDO $pdo, array $access): array
{
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

    return [
        'sessionId' => $sessionPublicId,
        'ipMasked' => $ipMasked,
    ];
}

function find_access_by_code_or_token(PDO $pdo, ?string $code, ?string $token): ?array
{
    if ($code === null && $token === null) {
        return null;
    }

    if ($token !== null && $token !== '') {
        $statement = $pdo->prepare(
            'SELECT
                 ac.id,
                 ac.code,
                 ac.expires_at,
                 ac.is_active,
                 ac.scope_label,
                 ac.scope_type,
                 ac.access_link_token,
                 c.id AS company_id,
                 c.name AS company_name,
                 f.id AS form_id,
                 f.name AS form_name,
                 f.status AS form_status
             FROM employee_access_codes ac
             INNER JOIN companies c ON c.id = ac.company_id
             INNER JOIN forms f ON f.id = ac.form_id
             WHERE ac.access_link_token = :token
               AND ac.is_active = 1
             ORDER BY ac.updated_at DESC, ac.id DESC
             LIMIT 1'
        );
        $statement->execute(['token' => $token]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    $statement = $pdo->prepare(
        'SELECT
             ac.id,
             ac.code,
             ac.expires_at,
             ac.is_active,
             ac.scope_label,
             ac.scope_type,
             ac.access_link_token,
             c.id AS company_id,
             c.name AS company_name,
             f.id AS form_id,
             f.name AS form_name,
             f.status AS form_status
         FROM employee_access_codes ac
         INNER JOIN companies c ON c.id = ac.company_id
         INNER JOIN forms f ON f.id = ac.form_id
         WHERE ac.code = :code
         LIMIT 1'
    );
    $statement->execute(['code' => $code]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

$method = request_method();

if (!in_array($method, ['GET', 'POST'], true)) {
    send_json(405, [
        'success' => false,
        'message' => 'Metodo nao permitido.',
    ]);
}

$input = $method === 'POST' ? read_json_input() : $_GET;
$code = isset($input['code']) ? strtoupper(trim((string) $input['code'])) : null;
$token = isset($input['token']) ? trim((string) $input['token']) : null;

if (($code === null || $code === '') && ($token === null || $token === '')) {
    send_json(422, [
        'success' => false,
        'message' => 'Digite o codigo de acesso para continuar.',
    ]);
}

$pdo = db();
$access = find_access_by_code_or_token($pdo, $code !== '' ? $code : null, $token !== '' ? $token : null);

if (!$access) {
    send_json(404, [
        'success' => false,
        'message' => 'Codigo ou link de acesso invalido.',
    ]);
}

if ((int) $access['is_active'] !== 1) {
    send_json(403, [
        'success' => false,
        'message' => 'Este acesso esta inativo.',
    ]);
}

if (normalize_status((string) ($access['form_status'] ?? 'inactive')) === 'inactive') {
    send_json(403, [
        'success' => false,
        'message' => 'Este formulario esta inativo no momento. Solicite um novo acesso ao RH ou gestor.',
    ]);
}

if (!empty($access['expires_at']) && strtotime((string) $access['expires_at']) < time()) {
    send_json(410, [
        'success' => false,
        'message' => 'Este acesso expirou.',
    ]);
}

$session = create_access_session($pdo, $access);

send_json(200, [
    'success' => true,
    'message' => 'Acesso validado com sucesso.',
    'data' => [
        'code' => (string) $access['code'],
        'companyId' => (int) $access['company_id'],
        'companyName' => (string) $access['company_name'],
        'formId' => (int) $access['form_id'],
        'formName' => (string) $access['form_name'],
        'scopeType' => (string) ($access['scope_type'] ?? 'global'),
        'scopeLabel' => (string) $access['scope_label'],
        'expiresAt' => (string) $access['expires_at'],
        'accessLinkToken' => (string) ($access['access_link_token'] ?? ''),
        'sessionId' => $session['sessionId'],
    ],
]);
