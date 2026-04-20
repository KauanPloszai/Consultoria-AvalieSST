<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

require_auth();

function fetch_companies(PDO $pdo): array
{
    $companiesStatement = $pdo->query(
        'SELECT id, name, cnpj, status, employees_count
         FROM companies
         ORDER BY created_at DESC, id DESC'
    );

    $companies = [];
    $companyIds = [];

    foreach ($companiesStatement->fetchAll() as $row) {
        $companyId = (int) $row['id'];
        $companyIds[] = $companyId;
        $companies[$companyId] = [
            'id' => (string) $companyId,
            'name' => (string) $row['name'],
            'cnpj' => (string) $row['cnpj'],
            'status' => normalize_status((string) $row['status']),
            'employees' => (int) $row['employees_count'],
            'sectors' => [],
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
    }

    return array_values($companies);
}

function parse_company_payload(array $input): array
{
    $name = trim((string) ($input['name'] ?? ''));
    $cnpj = trim((string) ($input['cnpj'] ?? ''));
    $status = normalize_status((string) ($input['status'] ?? 'active'));
    $employees = max(1, (int) ($input['employees'] ?? 0));
    $sectors = normalize_string_list(is_array($input['sectors'] ?? null) ? $input['sectors'] : []);

    if ($name === '') {
        send_json(422, [
            'success' => false,
            'message' => 'Informe o nome da empresa.',
        ]);
    }

    if ($cnpj === '') {
        send_json(422, [
            'success' => false,
            'message' => 'Informe o CNPJ da empresa.',
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
        'status' => $status,
        'employees' => $employees,
        'sectors' => $sectors,
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

$method = request_method();
$pdo = db();

if ($method === 'GET') {
    send_json(200, [
        'success' => true,
        'data' => fetch_companies($pdo),
    ]);
}

$input = read_json_input();
$payload = parse_company_payload($input);

if ($method === 'POST') {
    $pdo->beginTransaction();

    try {
        $insertStatement = $pdo->prepare(
            'INSERT INTO companies (name, cnpj, status, employees_count)
             VALUES (:name, :cnpj, :status, :employees_count)'
        );
        $insertStatement->execute([
            'name' => $payload['name'],
            'cnpj' => $payload['cnpj'],
            'status' => $payload['status'],
            'employees_count' => $payload['employees'],
        ]);

        $companyId = (int) $pdo->lastInsertId();
        replace_company_sectors($pdo, $companyId, $payload['sectors']);
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
                 status = :status,
                 employees_count = :employees_count,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $updateStatement->execute([
            'id' => $companyId,
            'name' => $payload['name'],
            'cnpj' => $payload['cnpj'],
            'status' => $payload['status'],
            'employees_count' => $payload['employees'],
        ]);

        if ($updateStatement->rowCount() === 0) {
            $existsStatement = $pdo->prepare('SELECT id FROM companies WHERE id = :id LIMIT 1');
            $existsStatement->execute(['id' => $companyId]);

            if (!$existsStatement->fetch()) {
                throw new RuntimeException('company-not-found');
            }
        }

        replace_company_sectors($pdo, $companyId, $payload['sectors']);
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
