<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

function access_code_exists(PDO $pdo, int $codeId): ?array
{
    $statement = $pdo->prepare(
        'SELECT ac.id, ac.code, ac.company_id, ac.form_id, ac.scope_label, ac.scope_type,
                ac.sector_id, ac.function_id, ac.expires_at, ac.is_active, ac.access_link_token,
                c.name AS company_name, c.active_form_id,
                f.name AS form_name, f.status AS form_status
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

function fetch_access_codes(PDO $pdo, ?int $companyFilterId = null): array
{
    if ($companyFilterId !== null && $companyFilterId > 0) {
        $statement = $pdo->prepare(
            'SELECT ac.id, ac.code, ac.company_id, ac.form_id, ac.scope_label, ac.scope_type,
                    ac.sector_id, ac.function_id, ac.expires_at, ac.is_active, ac.created_at,
                    ac.access_link_token,
                    c.name AS company_name, c.active_form_id,
                    f.name AS form_name, f.status AS form_status,
                    sct.sector_name,
                    fn.function_name
             FROM employee_access_codes ac
             INNER JOIN companies c ON c.id = ac.company_id
             INNER JOIN forms f ON f.id = ac.form_id
             LEFT JOIN company_sectors sct ON sct.id = ac.sector_id
             LEFT JOIN company_functions fn ON fn.id = ac.function_id
             WHERE ac.company_id = :company_id
             ORDER BY ac.created_at DESC, ac.id DESC'
        );
        $statement->execute(['company_id' => $companyFilterId]);
    } else {
        $statement = $pdo->query(
            'SELECT ac.id, ac.code, ac.company_id, ac.form_id, ac.scope_label, ac.scope_type,
                    ac.sector_id, ac.function_id, ac.expires_at, ac.is_active, ac.created_at,
                    ac.access_link_token,
                    c.name AS company_name, c.active_form_id,
                    f.name AS form_name, f.status AS form_status,
                    sct.sector_name,
                    fn.function_name
             FROM employee_access_codes ac
             INNER JOIN companies c ON c.id = ac.company_id
             INNER JOIN forms f ON f.id = ac.form_id
             LEFT JOIN company_sectors sct ON sct.id = ac.sector_id
             LEFT JOIN company_functions fn ON fn.id = ac.function_id
             ORDER BY ac.created_at DESC, ac.id DESC'
        );
    }

    $codes = [];

    foreach ($statement->fetchAll() as $row) {
        $expiresAt = (string) ($row['expires_at'] ?? '');
        $isExpired = $expiresAt !== '' && strtotime($expiresAt) < time();
        $accessLinkToken = (string) ($row['access_link_token'] ?? '');
        $accessUrl = $accessLinkToken !== '' ? build_employee_access_url($accessLinkToken) : '';

        $codes[] = [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'companyId' => (int) $row['company_id'],
            'companyName' => (string) $row['company_name'],
            'companyActiveFormId' => $row['active_form_id'] !== null ? (int) $row['active_form_id'] : null,
            'formId' => (int) $row['form_id'],
            'formName' => (string) $row['form_name'],
            'formStatus' => normalize_status((string) ($row['form_status'] ?? 'inactive')),
            'scopeType' => (string) ($row['scope_type'] ?? 'global'),
            'scopeLabel' => (string) $row['scope_label'],
            'sectorId' => $row['sector_id'] !== null ? (int) $row['sector_id'] : null,
            'sectorName' => (string) ($row['sector_name'] ?? ''),
            'functionId' => $row['function_id'] !== null ? (int) $row['function_id'] : null,
            'functionName' => (string) ($row['function_name'] ?? ''),
            'expiresAt' => $expiresAt,
            'isActive' => (int) $row['is_active'] === 1,
            'isExpired' => $isExpired,
            'createdAt' => (string) $row['created_at'],
            'accessLinkToken' => $accessLinkToken,
            'accessUrl' => $accessUrl,
            'qrImageUrl' => $accessUrl !== ''
                ? 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . rawurlencode($accessUrl)
                : '',
        ];
    }

    return $codes;
}

function fetch_access_history(PDO $pdo, ?int $companyFilterId = null): array
{
    if ($companyFilterId !== null && $companyFilterId > 0) {
        $statement = $pdo->prepare(
            'SELECT s.id, s.session_public_id, s.started_at, s.completed_at, s.ip_masked, s.status,
                    ac.id AS access_code_id, ac.code, ac.company_id, ac.form_id, ac.scope_label,
                    c.name AS company_name, f.name AS form_name
             FROM access_code_sessions s
             INNER JOIN employee_access_codes ac ON ac.id = s.access_code_id
             INNER JOIN companies c ON c.id = ac.company_id
             INNER JOIN forms f ON f.id = ac.form_id
             WHERE ac.company_id = :company_id
             ORDER BY s.started_at DESC, s.id DESC
             LIMIT 250'
        );
        $statement->execute(['company_id' => $companyFilterId]);
    } else {
        $statement = $pdo->query(
            'SELECT s.id, s.session_public_id, s.started_at, s.completed_at, s.ip_masked, s.status,
                    ac.id AS access_code_id, ac.code, ac.company_id, ac.form_id, ac.scope_label,
                    c.name AS company_name, f.name AS form_name
             FROM access_code_sessions s
             INNER JOIN employee_access_codes ac ON ac.id = s.access_code_id
             INNER JOIN companies c ON c.id = ac.company_id
             INNER JOIN forms f ON f.id = ac.form_id
             ORDER BY s.started_at DESC, s.id DESC
             LIMIT 250'
        );
    }

    $history = [];

    foreach ($statement->fetchAll() as $row) {
        $history[] = [
            'id' => (int) $row['id'],
            'sessionId' => (string) $row['session_public_id'],
            'accessedAt' => (string) $row['started_at'],
            'completedAt' => (string) ($row['completed_at'] ?? ''),
            'ipMasked' => (string) $row['ip_masked'],
            'status' => (string) $row['status'],
            'statusLabel' => access_status_label((string) $row['status']),
            'codeId' => (int) $row['access_code_id'],
            'code' => (string) $row['code'],
            'scopeLabel' => (string) $row['scope_label'],
            'companyId' => (int) $row['company_id'],
            'companyName' => (string) $row['company_name'],
            'formId' => (int) $row['form_id'],
            'formName' => (string) $row['form_name'],
        ];
    }

    return $history;
}

function fetch_access_stats(PDO $pdo, ?int $companyFilterId = null): array
{
    if ($companyFilterId !== null && $companyFilterId > 0) {
        $statement = $pdo->prepare(
            "SELECT
                COUNT(*) AS total_accesses,
                SUM(CASE WHEN s.status = 'done' THEN 1 ELSE 0 END) AS completed_accesses,
                SUM(CASE WHEN s.status = 'pending' THEN 1 ELSE 0 END) AS pending_accesses
             FROM access_code_sessions s
             INNER JOIN employee_access_codes ac ON ac.id = s.access_code_id
             WHERE ac.company_id = :company_id"
        );
        $statement->execute(['company_id' => $companyFilterId]);
    } else {
        $statement = $pdo->query(
            "SELECT
                COUNT(*) AS total_accesses,
                SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) AS completed_accesses,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_accesses
             FROM access_code_sessions"
        );
    }
    $row = $statement->fetch() ?: [];

    return [
        'totalAccesses' => (int) ($row['total_accesses'] ?? 0),
        'completedAccesses' => (int) ($row['completed_accesses'] ?? 0),
        'pendingAccesses' => (int) ($row['pending_accesses'] ?? 0),
    ];
}

function fetch_access_dashboard(PDO $pdo, ?int $companyFilterId = null): array
{
    return [
        'codes' => fetch_access_codes($pdo, $companyFilterId),
        'history' => fetch_access_history($pdo, $companyFilterId),
        'stats' => fetch_access_stats($pdo, $companyFilterId),
    ];
}

function parse_access_code_payload(array $input): array
{
    $companyId = (int) ($input['companyId'] ?? 0);
    $formId = (int) ($input['formId'] ?? 0);
    $scopeType = trim((string) ($input['scopeType'] ?? 'global'));
    $scopeLabel = trim((string) ($input['scopeLabel'] ?? ''));
    $sectorId = (int) ($input['sectorId'] ?? 0);
    $functionId = (int) ($input['functionId'] ?? 0);
    $expiresAt = trim((string) ($input['expiresAt'] ?? ''));

    if ($companyId <= 0) {
        send_json(422, [
            'success' => false,
            'message' => 'Selecione a empresa.',
        ]);
    }

    if (!in_array($scopeType, ['global', 'sector', 'function'], true)) {
        $scopeType = 'global';
    }

    if ($scopeType === 'sector' && $sectorId <= 0) {
        send_json(422, [
            'success' => false,
            'message' => 'Selecione um setor valido para o acesso.',
        ]);
    }

    if ($scopeType === 'function' && $functionId <= 0) {
        send_json(422, [
            'success' => false,
            'message' => 'Selecione uma funcao valida para o acesso.',
        ]);
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
        'formId' => $formId > 0 ? $formId : null,
        'scopeType' => $scopeType,
        'scopeLabel' => $scopeLabel,
        'sectorId' => $sectorId > 0 ? $sectorId : null,
        'functionId' => $functionId > 0 ? $functionId : null,
        'expiresAt' => date('Y-m-d H:i:s', $timestamp),
    ];
}

function ensure_company_and_form(PDO $pdo, array $payload): array
{
    $companyStatement = $pdo->prepare(
        'SELECT id, name, active_form_id
         FROM companies
         WHERE id = :id
         LIMIT 1'
    );
    $companyStatement->execute(['id' => $payload['companyId']]);
    $company = $companyStatement->fetch();

    if (!$company) {
        send_json(404, [
            'success' => false,
            'message' => 'Empresa nao encontrada.',
        ]);
    }

    $resolvedFormId = $payload['formId'] !== null
        ? (int) $payload['formId']
        : (int) ($company['active_form_id'] ?? 0);

    if ($resolvedFormId <= 0) {
        $linkedFormStatement = $pdo->prepare(
            'SELECT form_id
             FROM company_form_links
             WHERE company_id = :company_id
             ORDER BY updated_at DESC, id DESC
             LIMIT 1'
        );
        $linkedFormStatement->execute([
            'company_id' => (int) $payload['companyId'],
        ]);
        $resolvedFormId = (int) $linkedFormStatement->fetchColumn();
    }

    if ($resolvedFormId <= 0) {
        send_json(422, [
            'success' => false,
            'message' => 'Vincule um formulario a empresa antes de gerar o acesso.',
        ]);
    }

    $formStatement = $pdo->prepare(
        'SELECT f.id, f.name, f.status,
                EXISTS(
                    SELECT 1
                    FROM company_form_links l
                    WHERE l.company_id = :company_id
                      AND l.form_id = f.id
                ) AS is_linked
         FROM forms f
         WHERE f.id = :id
         LIMIT 1'
    );
    $formStatement->execute([
        'company_id' => (int) $payload['companyId'],
        'id' => $resolvedFormId,
    ]);
    $form = $formStatement->fetch();

    if (!$form) {
        send_json(404, [
            'success' => false,
            'message' => 'Formulario nao encontrado.',
        ]);
    }

    if ((int) ($form['is_linked'] ?? 0) !== 1) {
        send_json(422, [
            'success' => false,
            'message' => 'Este formulario nao esta vinculado a empresa selecionada.',
        ]);
    }

    if (normalize_status((string) ($form['status'] ?? 'inactive')) === 'inactive') {
        send_json(422, [
            'success' => false,
            'message' => 'Este formulario esta inativo. Reative-o na tela de Formularios para gerar novos acessos.',
        ]);
    }

    $sectorName = '';
    $functionName = '';

    if ($payload['scopeType'] === 'sector' && $payload['sectorId'] !== null) {
        $sectorStatement = $pdo->prepare(
            'SELECT sector_name
             FROM company_sectors
             WHERE id = :id
               AND company_id = :company_id
             LIMIT 1'
        );
        $sectorStatement->execute([
            'id' => $payload['sectorId'],
            'company_id' => $payload['companyId'],
        ]);
        $sectorName = (string) $sectorStatement->fetchColumn();

        if ($sectorName === '') {
            send_json(404, [
                'success' => false,
                'message' => 'Setor nao encontrado para a empresa.',
            ]);
        }
    }

    if ($payload['scopeType'] === 'function' && $payload['functionId'] !== null) {
        $functionStatement = $pdo->prepare(
            'SELECT fn.function_name, fn.sector_id, sct.sector_name
             FROM company_functions fn
             INNER JOIN company_sectors sct ON sct.id = fn.sector_id
             WHERE fn.id = :id
               AND fn.company_id = :company_id
             LIMIT 1'
        );
        $functionStatement->execute([
            'id' => $payload['functionId'],
            'company_id' => $payload['companyId'],
        ]);
        $functionRow = $functionStatement->fetch();

        if (!$functionRow) {
            send_json(404, [
                'success' => false,
                'message' => 'Funcao nao encontrada para a empresa.',
            ]);
        }

        $functionName = (string) $functionRow['function_name'];
        $sectorName = (string) $functionRow['sector_name'];

        if ($payload['sectorId'] === null) {
            $payload['sectorId'] = (int) $functionRow['sector_id'];
        }
    }

    $scopeLabel = $payload['scopeLabel'];

    if ($scopeLabel === '') {
        if ($payload['scopeType'] === 'function') {
            $scopeLabel = $sectorName !== '' ? $sectorName . ' / ' . $functionName : $functionName;
        } elseif ($payload['scopeType'] === 'sector') {
            $scopeLabel = $sectorName;
        } else {
            $scopeLabel = 'Todos os setores (Codigo Global)';
        }
    }

    return [
        'company' => $company,
        'form' => $form,
        'resolvedFormId' => $resolvedFormId,
        'scopeType' => $payload['scopeType'],
        'scopeLabel' => $scopeLabel,
        'sectorId' => $payload['sectorId'],
        'functionId' => $payload['functionId'],
        'expiresAt' => $payload['expiresAt'],
    ];
}

function deactivate_company_form_codes(PDO $pdo, int $companyId, int $formId): void
{
    $statement = $pdo->prepare(
        'UPDATE employee_access_codes
         SET is_active = 0, updated_at = NOW()
         WHERE company_id = :company_id
           AND form_id = :form_id
           AND is_active = 1'
    );
    $statement->execute([
        'company_id' => $companyId,
        'form_id' => $formId,
    ]);
}

function create_access_code_record(PDO $pdo, array $company, array $resolved, string $accessLinkToken): int
{
    $code = generate_access_code($pdo, (string) $company['name'], (string) $resolved['scopeLabel']);

    $statement = $pdo->prepare(
        'INSERT INTO employee_access_codes (
             code,
             company_id,
             form_id,
             scope_type,
             sector_id,
             function_id,
             scope_label,
             expires_at,
             is_active,
             access_link_token
         ) VALUES (
             :code,
             :company_id,
             :form_id,
             :scope_type,
             :sector_id,
             :function_id,
             :scope_label,
             :expires_at,
             1,
             :access_link_token
         )'
    );
    $statement->execute([
        'code' => $code,
        'company_id' => (int) $company['id'],
        'form_id' => (int) $resolved['resolvedFormId'],
        'scope_type' => (string) $resolved['scopeType'],
        'sector_id' => $resolved['sectorId'],
        'function_id' => $resolved['functionId'],
        'scope_label' => (string) $resolved['scopeLabel'],
        'expires_at' => (string) $resolved['expiresAt'],
        'access_link_token' => $accessLinkToken,
    ]);

    return (int) $pdo->lastInsertId();
}

$method = request_method();
$pdo = db();
$user = require_role(['admin', 'company']);

if ($method === 'GET') {
    $companyFilterId = resolve_company_scope_filter($user);

    send_json(200, [
        'success' => true,
        'data' => fetch_access_dashboard($pdo, $companyFilterId),
    ]);
}

$input = read_json_input();

if ($method === 'POST') {
    $payload = parse_access_code_payload($input);
    require_company_scope($user, (int) $payload['companyId']);
    $resolved = ensure_company_and_form($pdo, $payload);
    $accessLinkToken = generate_access_link_token($pdo);

    $pdo->beginTransaction();

    try {
        deactivate_company_form_codes(
            $pdo,
            (int) $payload['companyId'],
            (int) $resolved['resolvedFormId']
        );
        create_access_code_record($pdo, $resolved['company'], $resolved, $accessLinkToken);
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
        'message' => 'Codigo e link de acesso gerados com sucesso.',
        'data' => fetch_access_dashboard($pdo, resolve_company_scope_filter($user, (int) $payload['companyId'])),
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

    require_company_scope($user, (int) $existingCode['company_id']);

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
            'data' => fetch_access_dashboard($pdo, resolve_company_scope_filter($user, (int) $existingCode['company_id'])),
        ]);
    }

    if ($action === 'regenerate') {
        $payload = parse_access_code_payload([
            'companyId' => (int) $existingCode['company_id'],
            'formId' => (int) $existingCode['form_id'],
            'scopeType' => trim((string) ($input['scopeType'] ?? (string) ($existingCode['scope_type'] ?? 'global'))),
            'scopeLabel' => (string) ($input['scopeLabel'] ?? $existingCode['scope_label']),
            'sectorId' => (int) ($input['sectorId'] ?? $existingCode['sector_id'] ?? 0),
            'functionId' => (int) ($input['functionId'] ?? $existingCode['function_id'] ?? 0),
            'expiresAt' => trim((string) ($input['expiresAt'] ?? $existingCode['expires_at'])),
        ]);

        $resolved = ensure_company_and_form($pdo, $payload);
        $accessLinkToken = generate_access_link_token($pdo);

        $pdo->beginTransaction();

        try {
            deactivate_company_form_codes(
                $pdo,
                (int) $existingCode['company_id'],
                (int) $resolved['resolvedFormId']
            );
            create_access_code_record($pdo, $resolved['company'], $resolved, $accessLinkToken);
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
            'data' => fetch_access_dashboard($pdo, resolve_company_scope_filter($user, (int) $existingCode['company_id'])),
        ]);
    }
}

send_json(405, [
    'success' => false,
    'message' => 'Metodo nao permitido.',
]);
