<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

function company_only_digits(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?? '';
}

function company_format_cnpj(string $value): string
{
    $digits = substr(company_only_digits($value), 0, 14);

    if (strlen($digits) !== 14) {
        return $digits;
    }

    return preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $digits) ?: $digits;
}

function company_format_cep(string $value): string
{
    $digits = substr(company_only_digits($value), 0, 8);

    if (strlen($digits) !== 8) {
        return $digits;
    }

    return preg_replace('/^(\d{5})(\d{3})$/', '$1-$2', $digits) ?: $digits;
}

function fetch_companies(PDO $pdo, ?int $companyFilterId = null): array
{
    if ($companyFilterId !== null && $companyFilterId > 0) {
        $companiesStatement = $pdo->prepare(
            'SELECT c.id, c.name, c.cnpj, c.cep, c.street, c.street_number, c.status, c.employees_count,
                    c.active_form_id,
                    f.public_code AS active_form_code,
                    f.name AS active_form_name
             FROM companies
             c
             LEFT JOIN forms f ON f.id = c.active_form_id
             WHERE c.id = :company_id
             ORDER BY c.created_at DESC, c.id DESC'
        );
        $companiesStatement->execute(['company_id' => $companyFilterId]);
    } else {
        $companiesStatement = $pdo->query(
            'SELECT c.id, c.name, c.cnpj, c.cep, c.street, c.street_number, c.status, c.employees_count,
                    c.active_form_id,
                    f.public_code AS active_form_code,
                    f.name AS active_form_name
             FROM companies
             c
             LEFT JOIN forms f ON f.id = c.active_form_id
             ORDER BY c.created_at DESC, c.id DESC'
        );
    }

    $companies = [];
    $companyIds = [];

    foreach ($companiesStatement->fetchAll() as $row) {
        $companyId = (int) $row['id'];
        $companyIds[] = $companyId;
        $companies[$companyId] = [
            'id' => (string) $companyId,
            'name' => (string) $row['name'],
            'cnpj' => (string) $row['cnpj'],
            'cep' => (string) ($row['cep'] ?? ''),
            'street' => (string) ($row['street'] ?? ''),
            'streetNumber' => (string) ($row['street_number'] ?? ''),
            'status' => normalize_status((string) $row['status']),
            'employees' => (int) $row['employees_count'],
            'activeFormId' => $row['active_form_id'] !== null ? (int) $row['active_form_id'] : null,
            'activeFormCode' => (string) ($row['active_form_code'] ?? ''),
            'activeFormName' => (string) ($row['active_form_name'] ?? ''),
            'sectors' => [],
            'linkedForms' => [],
        ];
    }

    if ($companyIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($companyIds), '?'));
        $sectorsStatement = $pdo->prepare(
            "SELECT company_id, sector_name
             FROM company_sectors
             WHERE company_id IN ($placeholders)
             ORDER BY id ASC"
        );
        $sectorsStatement->execute($companyIds);

        foreach ($sectorsStatement->fetchAll() as $sectorRow) {
            $companyId = (int) $sectorRow['company_id'];

            if (!isset($companies[$companyId])) {
                continue;
            }

            $companies[$companyId]['sectors'][] = (string) $sectorRow['sector_name'];
        }

        $formsStatement = $pdo->prepare(
            "SELECT l.company_id, f.id AS form_id, f.public_code, f.name, f.status
             FROM company_form_links l
             INNER JOIN forms f ON f.id = l.form_id
             WHERE l.company_id IN ($placeholders)
             ORDER BY f.name ASC, f.id ASC"
        );
        $formsStatement->execute($companyIds);

        foreach ($formsStatement->fetchAll() as $formRow) {
            $companyId = (int) $formRow['company_id'];

            if (!isset($companies[$companyId])) {
                continue;
            }

            $companies[$companyId]['linkedForms'][] = [
                'id' => (int) $formRow['form_id'],
                'publicCode' => (string) ($formRow['public_code'] ?? ''),
                'name' => (string) $formRow['name'],
                'status' => normalize_status((string) ($formRow['status'] ?? 'inactive')),
            ];
        }
    }

    foreach ($companies as $companyId => $company) {
        $companies[$companyId]['linkedFormsCount'] = count($company['linkedForms']);
    }

    return array_values($companies);
}

function parse_company_payload(array $input): array
{
    $name = trim((string) ($input['name'] ?? ''));
    $rawCnpj = $input['cnpj'] ?? $input['companyCnpj'] ?? '';
    $rawCep = $input['cep'] ?? $input['companyCep'] ?? '';
    $cnpjDigits = company_only_digits(trim((string) $rawCnpj));
    $cepDigits = company_only_digits(trim((string) $rawCep));
    $cnpj = company_format_cnpj($cnpjDigits);
    $cep = company_format_cep($cepDigits);
    $street = trim((string) ($input['street'] ?? ''));
    $streetNumber = trim((string) ($input['streetNumber'] ?? ''));
    $status = normalize_status((string) ($input['status'] ?? 'active'));
    $employees = max(1, (int) ($input['employees'] ?? 0));
    $sectors = normalize_string_list(is_array($input['sectors'] ?? null) ? $input['sectors'] : []);
    $activeFormId = (int) ($input['activeFormId'] ?? 0);

    if ($name === '') {
        send_json(422, [
            'success' => false,
            'message' => 'Informe o nome da empresa.',
        ]);
    }

    if ($cnpjDigits === '' || strlen($cnpjDigits) !== 14) {
        send_json(422, [
            'success' => false,
            'message' => 'Informe um CNPJ valido.',
        ]);
    }

    if ($cepDigits === '' || strlen($cepDigits) !== 8) {
        send_json(422, [
            'success' => false,
            'message' => 'Informe um CEP valido.',
        ]);
    }

    if ($street === '') {
        send_json(422, [
            'success' => false,
            'message' => 'Informe a rua da empresa.',
        ]);
    }

    if ($streetNumber === '') {
        send_json(422, [
            'success' => false,
            'message' => 'Informe o numero da empresa.',
        ]);
    }

    if ($sectors === []) {
        send_json(422, [
            'success' => false,
            'message' => 'Informe pelo menos um setor.',
        ]);
    }

    return [
        'name' => $name,
        'cnpj' => $cnpj,
        'cep' => $cep,
        'street' => $street,
        'streetNumber' => $streetNumber,
        'status' => $status,
        'employees' => $employees,
        'sectors' => $sectors,
        'activeFormId' => $activeFormId > 0 ? $activeFormId : null,
    ];
}

function replace_company_sectors(PDO $pdo, int $companyId, array $sectors): void
{
    $deleteStatement = $pdo->prepare('DELETE FROM company_sectors WHERE company_id = :company_id');
    $deleteStatement->execute(['company_id' => $companyId]);

    $insertStatement = $pdo->prepare(
        'INSERT INTO company_sectors (company_id, sector_name)
         VALUES (:company_id, :sector_name)'
    );

    foreach ($sectors as $sector) {
        $insertStatement->execute([
            'company_id' => $companyId,
            'sector_name' => $sector,
        ]);
    }
}

function sync_company_form_link(PDO $pdo, int $companyId, ?int $activeFormId): void
{
    if ($activeFormId === null || $activeFormId <= 0) {
        return;
    }

    $statement = $pdo->prepare(
        'INSERT INTO company_form_links (company_id, form_id)
         VALUES (:company_id, :form_id)
         ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP'
    );
    $statement->execute([
        'company_id' => $companyId,
        'form_id' => $activeFormId,
    ]);
}

$method = request_method();
$pdo = db();
$user = require_role(['admin', 'company']);

if ($method === 'GET') {
    $companyFilterId = resolve_company_scope_filter($user);

    send_json(200, [
        'success' => true,
        'data' => fetch_companies($pdo, $companyFilterId),
    ]);
}

require_admin();

$input = read_json_input();
$payload = parse_company_payload($input);

if ($method === 'POST') {
    $pdo->beginTransaction();

    try {
        $insertStatement = $pdo->prepare(
            'INSERT INTO companies (name, cnpj, cep, street, street_number, status, employees_count, active_form_id)
             VALUES (:name, :cnpj, :cep, :street, :street_number, :status, :employees_count, :active_form_id)'
        );
        $insertStatement->execute([
            'name' => $payload['name'],
            'cnpj' => $payload['cnpj'],
            'cep' => $payload['cep'],
            'street' => $payload['street'],
            'street_number' => $payload['streetNumber'],
            'status' => $payload['status'],
            'employees_count' => $payload['employees'],
            'active_form_id' => $payload['activeFormId'],
        ]);

        $companyId = (int) $pdo->lastInsertId();
        replace_company_sectors($pdo, $companyId, $payload['sectors']);
        sync_company_form_link($pdo, $companyId, $payload['activeFormId']);
        $pdo->commit();
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        send_json(500, [
            'success' => false,
            'message' => 'Nao foi possivel salvar a empresa.',
        ]);
    }

    send_json(201, [
        'success' => true,
        'message' => 'Empresa salva com sucesso.',
        'data' => fetch_companies($pdo),
    ]);
}

if ($method === 'PUT') {
    $companyId = (int) ($input['id'] ?? $_GET['id'] ?? 0);

    if ($companyId <= 0) {
        send_json(422, [
            'success' => false,
            'message' => 'Empresa invalida.',
        ]);
    }

    $pdo->beginTransaction();

    try {
        $updateStatement = $pdo->prepare(
            'UPDATE companies
             SET name = :name,
                 cnpj = :cnpj,
                 cep = :cep,
                 street = :street,
                 street_number = :street_number,
                 status = :status,
                 employees_count = :employees_count,
                 active_form_id = :active_form_id,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $updateStatement->execute([
            'id' => $companyId,
            'name' => $payload['name'],
            'cnpj' => $payload['cnpj'],
            'cep' => $payload['cep'],
            'street' => $payload['street'],
            'street_number' => $payload['streetNumber'],
            'status' => $payload['status'],
            'employees_count' => $payload['employees'],
            'active_form_id' => $payload['activeFormId'],
        ]);

        if ($updateStatement->rowCount() === 0) {
            $existsStatement = $pdo->prepare('SELECT id FROM companies WHERE id = :id LIMIT 1');
            $existsStatement->execute(['id' => $companyId]);

            if (!$existsStatement->fetch()) {
                throw new RuntimeException('company-not-found');
            }
        }

        replace_company_sectors($pdo, $companyId, $payload['sectors']);
        sync_company_form_link($pdo, $companyId, $payload['activeFormId']);
        $pdo->commit();
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($throwable instanceof RuntimeException && $throwable->getMessage() === 'company-not-found') {
            send_json(404, [
                'success' => false,
                'message' => 'Empresa nao encontrada.',
            ]);
        }

        send_json(500, [
            'success' => false,
            'message' => 'Nao foi possivel atualizar a empresa.',
        ]);
    }

    send_json(200, [
        'success' => true,
        'message' => 'Empresa atualizada com sucesso.',
        'data' => fetch_companies($pdo),
    ]);
}

send_json(405, [
    'success' => false,
    'message' => 'Metodo nao permitido.',
]);
