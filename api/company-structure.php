<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

function fetch_company_options(PDO $pdo, ?int $companyId = null): array
{
    if ($companyId !== null && $companyId > 0) {
        $statement = $pdo->prepare(
            'SELECT id, name
             FROM companies
             WHERE id = :company_id
             ORDER BY name ASC'
        );
        $statement->execute(['company_id' => $companyId]);
    } else {
        $statement = $pdo->query(
            'SELECT id, name
             FROM companies
             ORDER BY name ASC'
        );
    }

    $companies = [];

    foreach ($statement->fetchAll() as $row) {
        $companies[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
        ];
    }

    return $companies;
}

function fetch_company_structure(PDO $pdo, int $companyId): array
{
    $companyStatement = $pdo->prepare(
        'SELECT c.id, c.name, c.employees_count,
                c.active_form_id,
                f.name AS active_form_name
         FROM companies c
         LEFT JOIN forms f ON f.id = c.active_form_id
         WHERE c.id = :id
         LIMIT 1'
    );
    $companyStatement->execute(['id' => $companyId]);
    $company = $companyStatement->fetch();

    if (!$company) {
        send_json(404, [
            'success' => false,
            'message' => 'Empresa nao encontrada.',
        ]);
    }

    $sectorStatement = $pdo->prepare(
        'SELECT id, sector_name, employees_count
         FROM company_sectors
         WHERE company_id = :company_id
         ORDER BY sector_name ASC, id ASC'
    );
    $sectorStatement->execute(['company_id' => $companyId]);

    $sectors = [];
    $sectorIds = [];

    foreach ($sectorStatement->fetchAll() as $row) {
        $sectorId = (int) $row['id'];
        $sectorIds[] = $sectorId;
        $sectors[$sectorId] = [
            'id' => $sectorId,
            'name' => (string) $row['sector_name'],
            'employees' => (int) $row['employees_count'],
            'riskLabel' => 'Sem dados',
            'riskSlug' => 'neutral',
            'functions' => [],
        ];
    }

    if ($sectorIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($sectorIds), '?'));

        $functionStatement = $pdo->prepare(
            "SELECT id, sector_id, function_name, employees_count
             FROM company_functions
             WHERE sector_id IN ($placeholders)
             ORDER BY function_name ASC, id ASC"
        );
        $functionStatement->execute($sectorIds);

        foreach ($functionStatement->fetchAll() as $row) {
            $sectorId = (int) $row['sector_id'];

            if (!isset($sectors[$sectorId])) {
                continue;
            }

            $sectors[$sectorId]['functions'][] = [
                'id' => (int) $row['id'],
                'sectorId' => $sectorId,
                'name' => (string) $row['function_name'],
                'employees' => (int) $row['employees_count'],
                'riskLabel' => 'Sem dados',
                'riskSlug' => 'neutral',
            ];
        }

        $riskStatement = $pdo->prepare(
            "SELECT ac.scope_type, ac.sector_id, ac.function_id, AVG(ans.answer_value) AS avg_answer
             FROM access_code_answers ans
             INNER JOIN access_code_sessions s ON s.id = ans.session_id
             INNER JOIN employee_access_codes ac ON ac.id = s.access_code_id
             WHERE ac.company_id = ?
               AND (
                    ac.sector_id IN ($placeholders)
                    OR ac.function_id IN (
                        SELECT id FROM company_functions WHERE sector_id IN ($placeholders)
                    )
               )
             GROUP BY ac.scope_type, ac.sector_id, ac.function_id"
        );
        $riskParams = array_merge([$companyId], $sectorIds, $sectorIds);
        $riskStatement->execute($riskParams);

        $functionRiskById = [];

        foreach ($riskStatement->fetchAll() as $row) {
            $average = (float) ($row['avg_answer'] ?? 0);
            $risk = risk_level_from_average($average);
            $scopeType = (string) $row['scope_type'];

            if ($scopeType === 'function' && (int) ($row['function_id'] ?? 0) > 0) {
                $functionRiskById[(int) $row['function_id']] = $risk;
                continue;
            }

            if ($scopeType === 'sector' && (int) ($row['sector_id'] ?? 0) > 0) {
                $sectorId = (int) $row['sector_id'];

                if (isset($sectors[$sectorId])) {
                    $sectors[$sectorId]['riskLabel'] = $risk['label'];
                    $sectors[$sectorId]['riskSlug'] = $risk['slug'];
                }
            }
        }

        foreach ($sectors as $sectorId => $sector) {
            foreach ($sector['functions'] as $functionIndex => $function) {
                $functionRisk = $functionRiskById[$function['id']] ?? null;

                if ($functionRisk !== null) {
                    $sectors[$sectorId]['functions'][$functionIndex]['riskLabel'] = $functionRisk['label'];
                    $sectors[$sectorId]['functions'][$functionIndex]['riskSlug'] = $functionRisk['slug'];
                }
            }
        }
    }

    $functionCount = 0;
    $mappedEmployees = 0;

    foreach ($sectors as $sector) {
        $functionCount += count($sector['functions']);
        $mappedEmployees += (int) $sector['employees'];

        foreach ($sector['functions'] as $function) {
            $mappedEmployees += (int) $function['employees'];
        }
    }

    return [
        'company' => [
            'id' => (int) $company['id'],
            'name' => (string) $company['name'],
            'employees' => (int) $company['employees_count'],
            'activeFormId' => $company['active_form_id'] !== null ? (int) $company['active_form_id'] : null,
            'activeFormName' => (string) ($company['active_form_name'] ?? ''),
        ],
        'summary' => [
            'sectorCount' => count($sectors),
            'functionCount' => $functionCount,
            'mappedEmployees' => $mappedEmployees,
        ],
        'sectors' => array_values($sectors),
    ];
}

function parse_structure_payload(array $input): array
{
    $type = trim((string) ($input['type'] ?? ''));
    $entityId = (int) ($input['id'] ?? 0);
    $companyId = (int) ($input['companyId'] ?? 0);
    $sectorId = (int) ($input['sectorId'] ?? 0);
    $name = trim((string) ($input['name'] ?? ''));
    $employees = max(0, (int) ($input['employees'] ?? 0));

    if (!in_array($type, ['sector', 'function'], true)) {
        send_json(422, [
            'success' => false,
            'message' => 'Tipo de item invalido.',
        ]);
    }

    if ($companyId <= 0) {
        send_json(422, [
            'success' => false,
            'message' => 'Empresa invalida para a estrutura.',
        ]);
    }

    if ($name === '') {
        send_json(422, [
            'success' => false,
            'message' => 'Informe o nome do item.',
        ]);
    }

    if ($type === 'function' && $sectorId <= 0) {
        send_json(422, [
            'success' => false,
            'message' => 'Selecione o setor da funcao.',
        ]);
    }

    return [
        'type' => $type,
        'id' => $entityId > 0 ? $entityId : null,
        'companyId' => $companyId,
        'sectorId' => $sectorId > 0 ? $sectorId : null,
        'name' => $name,
        'employees' => $employees,
    ];
}

function ensure_structure_targets(PDO $pdo, array $payload): void
{
    $companyStatement = $pdo->prepare('SELECT id FROM companies WHERE id = :id LIMIT 1');
    $companyStatement->execute(['id' => $payload['companyId']]);

    if (!$companyStatement->fetch()) {
        send_json(404, [
            'success' => false,
            'message' => 'Empresa nao encontrada.',
        ]);
    }

    if ($payload['type'] === 'function' && $payload['sectorId'] !== null) {
        $sectorStatement = $pdo->prepare(
            'SELECT id
             FROM company_sectors
             WHERE id = :id
               AND company_id = :company_id
             LIMIT 1'
        );
        $sectorStatement->execute([
            'id' => $payload['sectorId'],
            'company_id' => $payload['companyId'],
        ]);

        if (!$sectorStatement->fetch()) {
            send_json(404, [
                'success' => false,
                'message' => 'Setor nao encontrado para esta empresa.',
            ]);
        }
    }
}

$method = request_method();
$pdo = db();
$user = require_role(['admin', 'company']);

if ($method === 'GET') {
    $requestedCompanyId = (int) ($_GET['companyId'] ?? 0);
    $scopedCompanyId = resolve_company_scope_filter($user, $requestedCompanyId);
    $companies = fetch_company_options($pdo, $scopedCompanyId);
    $companyId = $scopedCompanyId ?? 0;

    if ($companyId <= 0 && $companies !== []) {
        $companyId = (int) $companies[0]['id'];
    }

    send_json(200, [
        'success' => true,
        'data' => [
            'companies' => $companies,
            'selectedCompanyId' => $companyId > 0 ? $companyId : null,
            'structure' => $companyId > 0 ? fetch_company_structure($pdo, $companyId) : null,
        ],
    ]);
}

if ($method === 'POST' || $method === 'PUT') {
    $payload = parse_structure_payload(read_json_input());
    require_company_scope($user, (int) $payload['companyId']);
    ensure_structure_targets($pdo, $payload);

    if ($payload['type'] === 'sector') {
        if ($payload['id'] === null) {
            $statement = $pdo->prepare(
                'INSERT INTO company_sectors (company_id, sector_name, employees_count)
                 VALUES (:company_id, :sector_name, :employees_count)'
            );
            $statement->execute([
                'company_id' => $payload['companyId'],
                'sector_name' => $payload['name'],
                'employees_count' => $payload['employees'],
            ]);
        } else {
            $statement = $pdo->prepare(
                'UPDATE company_sectors
                 SET sector_name = :sector_name,
                     employees_count = :employees_count,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id
                   AND company_id = :company_id'
            );
            $statement->execute([
                'id' => $payload['id'],
                'company_id' => $payload['companyId'],
                'sector_name' => $payload['name'],
                'employees_count' => $payload['employees'],
            ]);
        }
    } else {
        if ($payload['id'] === null) {
            $statement = $pdo->prepare(
                'INSERT INTO company_functions (company_id, sector_id, function_name, employees_count)
                 VALUES (:company_id, :sector_id, :function_name, :employees_count)'
            );
            $statement->execute([
                'company_id' => $payload['companyId'],
                'sector_id' => $payload['sectorId'],
                'function_name' => $payload['name'],
                'employees_count' => $payload['employees'],
            ]);
        } else {
            $statement = $pdo->prepare(
                'UPDATE company_functions
                 SET sector_id = :sector_id,
                     function_name = :function_name,
                     employees_count = :employees_count,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id
                   AND company_id = :company_id'
            );
            $statement->execute([
                'id' => $payload['id'],
                'company_id' => $payload['companyId'],
                'sector_id' => $payload['sectorId'],
                'function_name' => $payload['name'],
                'employees_count' => $payload['employees'],
            ]);
        }
    }

    send_json(200, [
        'success' => true,
        'message' => $payload['id'] === null ? 'Item criado com sucesso.' : 'Item atualizado com sucesso.',
        'data' => [
            'companies' => fetch_company_options($pdo, resolve_company_scope_filter($user, (int) $payload['companyId'])),
            'selectedCompanyId' => $payload['companyId'],
            'structure' => fetch_company_structure($pdo, $payload['companyId']),
        ],
    ]);
}

if ($method === 'DELETE') {
    $entityType = trim((string) ($_GET['type'] ?? ''));
    $entityId = (int) ($_GET['id'] ?? 0);
    $companyId = (int) ($_GET['companyId'] ?? 0);

    if (!in_array($entityType, ['sector', 'function'], true) || $entityId <= 0 || $companyId <= 0) {
        send_json(422, [
            'success' => false,
            'message' => 'Item invalido para exclusao.',
        ]);
    }

    require_company_scope($user, $companyId);

    if ($entityType === 'sector') {
        $statement = $pdo->prepare(
            'DELETE FROM company_sectors
             WHERE id = :id
               AND company_id = :company_id'
        );
    } else {
        $statement = $pdo->prepare(
            'DELETE FROM company_functions
             WHERE id = :id
               AND company_id = :company_id'
        );
    }

    $statement->execute([
        'id' => $entityId,
        'company_id' => $companyId,
    ]);

    send_json(200, [
        'success' => true,
        'message' => 'Item removido com sucesso.',
        'data' => [
            'companies' => fetch_company_options($pdo, resolve_company_scope_filter($user, $companyId)),
            'selectedCompanyId' => $companyId,
            'structure' => fetch_company_structure($pdo, $companyId),
        ],
    ]);
}

send_json(405, [
    'success' => false,
    'message' => 'Metodo nao permitido.',
]);
