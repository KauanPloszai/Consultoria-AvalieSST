<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

require_auth();

function access_code_exists(PDO $pdo, int $codeId): ?array
{
    $statement = $pdo->prepare(
        'SELECT ac.id, ac.code, ac.company_id, ac.form_id, ac.scope_label, ac.expires_at, ac.is_active,
                c.name AS company_name, f.name AS form_name
         FROM employee_access_codes ac
         INNER JOIN companies c ON c.id = ac.company_id
         INNER JOIN forms f ON f.id = ac.form_id
         WHERE ac.id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $codeId]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

function fetch_access_codes(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT ac.id, ac.code, ac.company_id, ac.form_id, ac.scope_label, ac.expires_at, ac.is_active, ac.created_at,
                c.name AS company_name, f.name AS form_name
         FROM employee_access_codes ac
         INNER JOIN companies c ON c.id = ac.company_id
         INNER JOIN forms f ON f.id = ac.form_id
         ORDER BY ac.created_at DESC, ac.id DESC'
    );

    $codes = [];

    foreach ($statement->fetchAll() as $row) {
        $expiresAt = (string) ($row['expires_at'] ?? '');
        $isExpired = $expiresAt !== '' && strtotime($expiresAt) < time();

        $codes[] = [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'companyId' => (int) $row['company_id'],
            'companyName' => (string) $row['company_name'],
            'formId' => (int) $row['form_id'],
            'formName' => (string) $row['form_name'],
            'scopeLabel' => (string) $row['scope_label'],
            'expiresAt' => $expiresAt,
            'isActive' => (int) $row['is_active'] === 1,
            'isExpired' => $isExpired,
            'createdAt' => (string) $row['created_at'],
        ];
    }

    return $codes;
}

function fetch_access_history(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT s.id, s.session_public_id, s.started_at, s.ip_masked, s.status,
                ac.id AS access_code_id, ac.code, ac.company_id, ac.form_id,
                c.name AS company_name, f.name AS form_name
         FROM access_code_sessions s
         INNER JOIN employee_access_codes ac ON ac.id = s.access_code_id
         INNER JOIN companies c ON c.id = ac.company_id
         INNER JOIN forms f ON f.id = ac.form_id
         ORDER BY s.started_at DESC, s.id DESC
         LIMIT 250'
    );

    $history = [];

    foreach ($statement->fetchAll() as $row) {
        $history[] = [
            'id' => (int) $row['id'],
            'sessionId' => (string) $row['session_public_id'],
            'accessedAt' => (string) $row['started_at'],
            'ipMasked' => (string) $row['ip_masked'],
            'status' => (string) $row['status'],
            'statusLabel' => access_status_label((string) $row['status']),
            'codeId' => (int) $row['access_code_id'],
            'code' => (string) $row['code'],
            'companyId' => (int) $row['company_id'],
            'companyName' => (string) $row['company_name'],
            'formId' => (int) $row['form_id'],
            'formName' => (string) $row['form_name'],
        ];
    }

    return $history;
}

function fetch_access_stats(PDO $pdo): array
{
    $statement = $pdo->query(
        "SELECT
            COUNT(*) AS total_accesses,
            SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) AS completed_accesses,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_accesses
         FROM access_code_sessions"
    );
    $row = $statement->fetch() ?: [];

    return [
        'totalAccesses' => (int) ($row['total_accesses'] ?? 0),
        'completedAccesses' => (int) ($row['completed_accesses'] ?? 0),
        'pendingAccesses' => (int) ($row['pending_accesses'] ?? 0),
    ];
}

function fetch_access_dashboard(PDO $pdo): array
{
    return [
        'codes' => fetch_access_codes($pdo),
        'history' => fetch_access_history($pdo),
        'stats' => fetch_access_stats($pdo),
    ];
}

function parse_access_code_payload(array $input): array
{
    $companyId = (int) ($input['companyId'] ?? 0);
    $formId = (int) ($input['formId'] ?? 0);
    $scopeLabel = trim((string) ($input['scopeLabel'] ?? 'Todos os setores (Codigo Global)'));
    $expiresAt = trim((string) ($input['expiresAt'] ?? ''));

    if ($companyId <= 0) {
        send_json(422, [
            'success' => false,
            'message' => 'Selecione a empresa.',
        ]);
    }

    if ($formId <= 0) {
        send_json(422, [
            'success' => false,
            'message' => 'Selecione o formulario.',
        ]);
    }

    if ($scopeLabel === '') {
        $scopeLabel = 'Todos os setores (Codigo Global)';
    }

    if ($expiresAt === '') {
        send_json(422, [
            'success' => false,
            'message' => 'Informe a validade do codigo.',
        ]);
    }

    $timestamp = strtotime($expiresAt);

    if ($timestamp === false) {
        send_json(422, [
            'success' => false,
            'message' => 'Validade do codigo invalida.',
        ]);
    }

    if ($timestamp <= time()) {
        send_json(422, [
            'success' => false,
            'message' => 'A validade precisa ser uma data futura.',
        ]);
    }

    return [
        'companyId' => $companyId,
        'formId' => $formId,
        'scopeLabel' => $scopeLabel,
        'expiresAt' => date('Y-m-d H:i:s', $timestamp),
    ];
}

function ensure_company_and_form(PDO $pdo, int $companyId, int $formId): array
{
    $companyStatement = $pdo->prepare('SELECT id, name FROM companies WHERE id = :id LIMIT 1');
    $companyStatement->execute(['id' => $companyId]);
    $company = $companyStatement->fetch();

    if (!$company) {
        send_json(404, [
            'success' => false,
            'message' => 'Empresa nao encontrada.',
        ]);
    }

    $formStatement = $pdo->prepare('SELECT id, name FROM forms WHERE id = :id LIMIT 1');
    $formStatement->execute(['id' => $formId]);
    $form = $formStatement->fetch();

    if (!$form) {
        send_json(404, [
            'success' => false,
            'message' => 'Formulario nao encontrado.',
        ]);
    }

    return [
        'company' => $company,
        'form' => $form,
    ];
}

function deactivate_company_codes(PDO $pdo, int $companyId): void
{
    $statement = $pdo->prepare(
        'UPDATE employee_access_codes
         SET is_active = 0, updated_at = NOW()
         WHERE company_id = :company_id AND is_active = 1'
    );
    $statement->execute(['company_id' => $companyId]);
}

function create_access_code_record(PDO $pdo, array $company, array $payload): int
{
    $code = generate_access_code($pdo, (string) $company['name'], (string) $payload['scopeLabel']);

    $statement = $pdo->prepare(
        'INSERT INTO employee_access_codes (code, company_id, form_id, scope_label, expires_at, is_active)
         VALUES (:code, :company_id, :form_id, :scope_label, :expires_at, 1)'
    );
    $statement->execute([
        'code' => $code,
        'company_id' => (int) $payload['companyId'],
        'form_id' => (int) $payload['formId'],
        'scope_label' => (string) $payload['scopeLabel'],
        'expires_at' => (string) $payload['expiresAt'],
    ]);

    return (int) $pdo->lastInsertId();
}

$method = request_method();
$pdo = db();

if ($method === 'GET') {
    send_json(200, [
        'success' => true,
        'data' => fetch_access_dashboard($pdo),
    ]);
}

$input = read_json_input();

if ($method === 'POST') {
    $payload = parse_access_code_payload($input);
    $entities = ensure_company_and_form($pdo, $payload['companyId'], $payload['formId']);

    $pdo->beginTransaction();

    try {
        deactivate_company_codes($pdo, (int) $payload['companyId']);
        create_access_code_record($pdo, $entities['company'], $payload);
        $pdo->commit();
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        send_json(500, [
            'success' => false,
            'message' => 'Nao foi possivel gerar o codigo.',
        ]);
    }

    send_json(201, [
        'success' => true,
        'message' => 'Codigo gerado com sucesso.',
        'data' => fetch_access_dashboard($pdo),
    ]);
}

if ($method === 'PUT') {
    $codeId = (int) ($input['id'] ?? 0);
    $action = trim((string) ($input['action'] ?? ''));

    if ($codeId <= 0) {
        send_json(422, [
            'success' => false,
            'message' => 'Codigo invalido.',
        ]);
    }

    $existingCode = access_code_exists($pdo, $codeId);

    if ($existingCode === null) {
        send_json(404, [
            'success' => false,
            'message' => 'Codigo nao encontrado.',
        ]);
    }

    if ($action === 'revoke') {
        $statement = $pdo->prepare(
            'UPDATE employee_access_codes
             SET is_active = 0, updated_at = NOW()
             WHERE id = :id'
        );
        $statement->execute(['id' => $codeId]);

        send_json(200, [
            'success' => true,
            'message' => 'Codigo revogado com sucesso.',
            'data' => fetch_access_dashboard($pdo),
        ]);
    }

    if ($action === 'regenerate') {
        $expiresAtInput = trim((string) ($input['expiresAt'] ?? ''));
        $scopeLabel = trim((string) ($input['scopeLabel'] ?? $existingCode['scope_label']));
        $expiresAt = $expiresAtInput !== '' ? $expiresAtInput : (string) $existingCode['expires_at'];
        $payload = parse_access_code_payload([
            'companyId' => (int) $existingCode['company_id'],
            'formId' => (int) $existingCode['form_id'],
            'scopeLabel' => $scopeLabel,
            'expiresAt' => $expiresAt,
        ]);

        $entities = ensure_company_and_form($pdo, $payload['companyId'], $payload['formId']);

        $pdo->beginTransaction();

        try {
            deactivate_company_codes($pdo, (int) $existingCode['company_id']);
            create_access_code_record($pdo, $entities['company'], $payload);
            $pdo->commit();
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            send_json(500, [
                'success' => false,
                'message' => 'Nao foi possivel regenerar o codigo.',
            ]);
        }

        send_json(200, [
            'success' => true,
            'message' => 'Codigo regenerado com sucesso.',
            'data' => fetch_access_dashboard($pdo),
        ]);
    }
}

send_json(405, [
    'success' => false,
    'message' => 'Metodo nao permitido.',
]);
