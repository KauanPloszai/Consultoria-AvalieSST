<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/reporting.php';

require_admin();

function action_plan_status_map(): array
{
    return [
        'high' => 'Prioridade Alta',
        'medium' => 'Prioridade Media',
        'monitor' => 'Monitorar',
        'done' => 'Concluida',
    ];
}

function action_plan_normalize_status_slug(string $statusSlug): string
{
    $normalized = trim(strtolower($statusSlug));
    $allowed = array_keys(action_plan_status_map());

    if (in_array($normalized, $allowed, true)) {
        return $normalized;
    }

    return 'monitor';
}

function action_plan_status_label(string $statusSlug): string
{
    $map = action_plan_status_map();
    $normalized = action_plan_normalize_status_slug($statusSlug);
    return $map[$normalized] ?? $map['monitor'];
}

function action_plan_scope_from_input(array $input): array
{
    return [
        'companyId' => (int) ($input['companyId'] ?? $_GET['companyId'] ?? 0),
        'sectorId' => (int) ($input['sectorId'] ?? $_GET['sectorId'] ?? 0),
        'functionId' => (int) ($input['functionId'] ?? $_GET['functionId'] ?? 0),
        'period' => reporting_normalize_period((string) ($input['period'] ?? $_GET['period'] ?? '180')),
    ];
}

function action_plan_fetch_context(PDO $pdo, array $scope): array
{
    if ($scope['companyId'] <= 0) {
        send_json(422, [
            'success' => false,
            'message' => 'Selecione a empresa para editar o plano de acao.',
        ]);
    }

    $companyStatement = $pdo->prepare(
        'SELECT id, name, status, employees_count
         FROM companies
         WHERE id = :id
         LIMIT 1'
    );
    $companyStatement->execute(['id' => $scope['companyId']]);
    $company = $companyStatement->fetch();

    if (!$company) {
        send_json(404, [
            'success' => false,
            'message' => 'Empresa nao encontrada.',
        ]);
    }

    $context = [
        'companyId' => (int) $company['id'],
        'companyName' => (string) $company['name'],
        'companyStatus' => (string) $company['status'],
        'companyEmployees' => (int) ($company['employees_count'] ?? 0),
        'sectorId' => null,
        'sectorName' => '',
        'functionId' => null,
        'functionName' => '',
        'scopeType' => 'company',
        'scopeName' => (string) $company['name'],
        'employees' => (int) ($company['employees_count'] ?? 0),
        'period' => (string) $scope['period'],
        'periodLabel' => reporting_period_label((string) $scope['period']),
    ];

    if ($scope['functionId'] > 0) {
        $functionStatement = $pdo->prepare(
            'SELECT fn.id, fn.function_name, fn.employees_count, fn.sector_id, sct.sector_name
             FROM company_functions fn
             INNER JOIN company_sectors sct ON sct.id = fn.sector_id
             WHERE fn.id = :function_id
               AND fn.company_id = :company_id
             LIMIT 1'
        );
        $functionStatement->execute([
            'function_id' => $scope['functionId'],
            'company_id' => $scope['companyId'],
        ]);
        $functionRow = $functionStatement->fetch();

        if (!$functionRow) {
            send_json(404, [
                'success' => false,
                'message' => 'Funcao nao encontrada para a empresa selecionada.',
            ]);
        }

        $context['sectorId'] = (int) $functionRow['sector_id'];
        $context['sectorName'] = (string) $functionRow['sector_name'];
        $context['functionId'] = (int) $functionRow['id'];
        $context['functionName'] = (string) $functionRow['function_name'];
        $context['scopeType'] = 'function';
        $context['scopeName'] = (string) $functionRow['function_name'];
        $context['employees'] = (int) ($functionRow['employees_count'] ?? 0);

        return $context;
    }

    if ($scope['sectorId'] > 0) {
        $sectorStatement = $pdo->prepare(
            'SELECT id, sector_name, employees_count
             FROM company_sectors
             WHERE id = :sector_id
               AND company_id = :company_id
             LIMIT 1'
        );
        $sectorStatement->execute([
            'sector_id' => $scope['sectorId'],
            'company_id' => $scope['companyId'],
        ]);
        $sectorRow = $sectorStatement->fetch();

        if (!$sectorRow) {
            send_json(404, [
                'success' => false,
                'message' => 'Setor nao encontrado para a empresa selecionada.',
            ]);
        }

        $context['sectorId'] = (int) $sectorRow['id'];
        $context['sectorName'] = (string) $sectorRow['sector_name'];
        $context['scopeType'] = 'sector';
        $context['scopeName'] = (string) $sectorRow['sector_name'];
        $context['employees'] = (int) ($sectorRow['employees_count'] ?? 0);
    }

    return $context;
}

function action_plan_fetch_items(PDO $pdo, array $context): array
{
    $conditions = ['api.company_id = :company_id'];
    $params = ['company_id' => (int) $context['companyId']];

    if (($context['functionId'] ?? null) !== null) {
        $conditions[] = 'api.function_id = :function_id';
        $params['function_id'] = (int) $context['functionId'];
    } elseif (($context['sectorId'] ?? null) !== null) {
        $conditions[] = 'api.sector_id = :sector_id';
        $conditions[] = 'api.function_id IS NULL';
        $params['sector_id'] = (int) $context['sectorId'];
    } else {
        $conditions[] = 'api.sector_id IS NULL';
        $conditions[] = 'api.function_id IS NULL';
    }

    $statement = $pdo->prepare(
        'SELECT api.id,
                api.company_id,
                api.sector_id,
                api.function_id,
                api.factor,
                api.action_text,
                api.deadline,
                api.status_slug,
                api.responsible,
                api.notes,
                api.position,
                api.updated_at,
                sct.sector_name,
                fn.function_name
         FROM action_plan_items api
         LEFT JOIN company_sectors sct ON sct.id = api.sector_id
         LEFT JOIN company_functions fn ON fn.id = api.function_id
         WHERE ' . implode(' AND ', $conditions) . '
         ORDER BY api.position ASC, api.updated_at DESC, api.id DESC'
    );
    $statement->execute($params);

    $items = [];

    foreach ($statement->fetchAll() as $row) {
        $statusSlug = action_plan_normalize_status_slug((string) ($row['status_slug'] ?? 'monitor'));
        $items[] = [
            'id' => (int) $row['id'],
            'companyId' => (int) $row['company_id'],
            'sectorId' => $row['sector_id'] !== null ? (int) $row['sector_id'] : null,
            'functionId' => $row['function_id'] !== null ? (int) $row['function_id'] : null,
            'sectorName' => (string) ($row['sector_name'] ?? ''),
            'functionName' => (string) ($row['function_name'] ?? ''),
            'factor' => (string) $row['factor'],
            'actionText' => (string) $row['action_text'],
            'deadline' => (string) $row['deadline'],
            'statusSlug' => $statusSlug,
            'statusLabel' => action_plan_status_label($statusSlug),
            'responsible' => (string) ($row['responsible'] ?? ''),
            'notes' => (string) ($row['notes'] ?? ''),
            'position' => (int) ($row['position'] ?? 0),
            'updatedAt' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    return $items;
}

function action_plan_build_suggestion(PDO $pdo, array $context): array
{
    $payload = reporting_build_payload($pdo, [
        'companyId' => $context['companyId'],
        'sectorId' => $context['sectorId'],
        'functionId' => $context['functionId'],
        'period' => $context['period'],
    ]);

    $summaryRiskSlug = (string) ($payload['summary']['riskSlug'] ?? 'neutral');
    $summaryRiskLabel = (string) ($payload['summary']['riskLabel'] ?? 'Sem dados');
    $topQuestion = $payload['questionRankings'][0] ?? null;

    $deadline = '90 dias';
    $statusSlug = 'monitor';

    if ($summaryRiskSlug === 'high') {
        $deadline = '30 dias';
        $statusSlug = 'high';
    } elseif ($summaryRiskSlug === 'medium') {
        $deadline = '60 dias';
        $statusSlug = 'medium';
    }

    return [
        'riskSlug' => $summaryRiskSlug,
        'riskLabel' => $summaryRiskLabel,
        'riskIndex' => (int) ($payload['summary']['riskIndex'] ?? 0),
        'factor' => (string) ($topQuestion['text'] ?? 'Fator de risco priorizado para este escopo'),
        'actionText' => $topQuestion
            ? reporting_recommendation_from_question((string) $topQuestion['text'])
            : 'Definir acao de melhoria, responsavel e acompanhamento para este escopo.',
        'deadline' => $deadline,
        'statusSlug' => $statusSlug,
        'statusLabel' => action_plan_status_label($statusSlug),
    ];
}

function action_plan_validate_payload(PDO $pdo, array $input): array
{
    $scope = action_plan_scope_from_input($input);
    $context = action_plan_fetch_context($pdo, $scope);
    $statusSlug = action_plan_normalize_status_slug((string) ($input['statusSlug'] ?? 'monitor'));
    $factor = trim((string) ($input['factor'] ?? ''));
    $actionText = trim((string) ($input['actionText'] ?? ''));
    $deadline = trim((string) ($input['deadline'] ?? ''));
    $responsible = trim((string) ($input['responsible'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? ''));
    $position = (int) ($input['position'] ?? 0);

    if ($factor === '') {
        send_json(422, [
            'success' => false,
            'message' => 'Informe o fator de risco da acao.',
        ]);
    }

    if ($actionText === '') {
        send_json(422, [
            'success' => false,
            'message' => 'Informe a acao recomendada.',
        ]);
    }

    if ($deadline === '') {
        send_json(422, [
            'success' => false,
            'message' => 'Informe o prazo da acao.',
        ]);
    }

    return [
        'context' => $context,
        'factor' => $factor,
        'actionText' => $actionText,
        'deadline' => $deadline,
        'statusSlug' => $statusSlug,
        'responsible' => $responsible,
        'notes' => $notes,
        'position' => max(0, $position),
    ];
}

function action_plan_next_position(PDO $pdo, array $context): int
{
    $conditions = ['company_id = :company_id'];
    $params = ['company_id' => (int) $context['companyId']];

    if (($context['functionId'] ?? null) !== null) {
        $conditions[] = 'function_id = :function_id';
        $params['function_id'] = (int) $context['functionId'];
    } elseif (($context['sectorId'] ?? null) !== null) {
        $conditions[] = 'sector_id = :sector_id';
        $conditions[] = 'function_id IS NULL';
        $params['sector_id'] = (int) $context['sectorId'];
    } else {
        $conditions[] = 'sector_id IS NULL';
        $conditions[] = 'function_id IS NULL';
    }

    $statement = $pdo->prepare(
        'SELECT COALESCE(MAX(position), 0)
         FROM action_plan_items
         WHERE ' . implode(' AND ', $conditions)
    );
    $statement->execute($params);

    return ((int) $statement->fetchColumn()) + 1;
}

function action_plan_fetch_single(PDO $pdo, int $itemId): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, company_id, sector_id, function_id
         FROM action_plan_items
         WHERE id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $itemId]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

$method = request_method();
$pdo = db();

if ($method === 'GET') {
    $scope = action_plan_scope_from_input($_GET);
    $context = action_plan_fetch_context($pdo, $scope);
    $items = action_plan_fetch_items($pdo, $context);
    $suggestion = action_plan_build_suggestion($pdo, $context);

    send_json(200, [
        'success' => true,
        'data' => [
            'context' => $context,
            'items' => $items,
            'suggestion' => $suggestion,
            'statusOptions' => array_map(
                static fn(string $slug): array => [
                    'slug' => $slug,
                    'label' => action_plan_status_label($slug),
                ],
                array_keys(action_plan_status_map())
            ),
        ],
    ]);
}

if ($method === 'POST') {
    $payload = action_plan_validate_payload($pdo, read_json_input());
    $context = $payload['context'];
    $position = $payload['position'] > 0 ? $payload['position'] : action_plan_next_position($pdo, $context);

    $statement = $pdo->prepare(
        'INSERT INTO action_plan_items (
             company_id,
             sector_id,
             function_id,
             factor,
             action_text,
             deadline,
             status_slug,
             responsible,
             notes,
             position
         ) VALUES (
             :company_id,
             :sector_id,
             :function_id,
             :factor,
             :action_text,
             :deadline,
             :status_slug,
             :responsible,
             :notes,
             :position
         )'
    );
    $statement->execute([
        'company_id' => (int) $context['companyId'],
        'sector_id' => $context['sectorId'],
        'function_id' => $context['functionId'],
        'factor' => $payload['factor'],
        'action_text' => $payload['actionText'],
        'deadline' => $payload['deadline'],
        'status_slug' => $payload['statusSlug'],
        'responsible' => $payload['responsible'],
        'notes' => $payload['notes'],
        'position' => $position,
    ]);

    send_json(201, [
        'success' => true,
        'message' => 'Acao salva com sucesso.',
        'data' => [
            'context' => $context,
            'items' => action_plan_fetch_items($pdo, $context),
            'suggestion' => action_plan_build_suggestion($pdo, $context),
        ],
    ]);
}

if ($method === 'PUT') {
    $input = read_json_input();
    $itemId = (int) ($input['id'] ?? 0);

    if ($itemId <= 0) {
        send_json(422, [
            'success' => false,
            'message' => 'Acao invalida.',
        ]);
    }

    if (!action_plan_fetch_single($pdo, $itemId)) {
        send_json(404, [
            'success' => false,
            'message' => 'Acao nao encontrada.',
        ]);
    }

    $payload = action_plan_validate_payload($pdo, $input);
    $context = $payload['context'];
    $position = $payload['position'] > 0 ? $payload['position'] : action_plan_next_position($pdo, $context);

    $statement = $pdo->prepare(
        'UPDATE action_plan_items
         SET company_id = :company_id,
             sector_id = :sector_id,
             function_id = :function_id,
             factor = :factor,
             action_text = :action_text,
             deadline = :deadline,
             status_slug = :status_slug,
             responsible = :responsible,
             notes = :notes,
             position = :position,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $statement->execute([
        'id' => $itemId,
        'company_id' => (int) $context['companyId'],
        'sector_id' => $context['sectorId'],
        'function_id' => $context['functionId'],
        'factor' => $payload['factor'],
        'action_text' => $payload['actionText'],
        'deadline' => $payload['deadline'],
        'status_slug' => $payload['statusSlug'],
        'responsible' => $payload['responsible'],
        'notes' => $payload['notes'],
        'position' => $position,
    ]);

    send_json(200, [
        'success' => true,
        'message' => 'Acao atualizada com sucesso.',
        'data' => [
            'context' => $context,
            'items' => action_plan_fetch_items($pdo, $context),
            'suggestion' => action_plan_build_suggestion($pdo, $context),
        ],
    ]);
}

if ($method === 'DELETE') {
    $itemId = (int) ($_GET['id'] ?? 0);

    if ($itemId <= 0) {
        send_json(422, [
            'success' => false,
            'message' => 'Acao invalida.',
        ]);
    }

    $item = action_plan_fetch_single($pdo, $itemId);

    if (!$item) {
        send_json(404, [
            'success' => false,
            'message' => 'Acao nao encontrada.',
        ]);
    }

    $context = action_plan_fetch_context($pdo, [
        'companyId' => (int) $item['company_id'],
        'sectorId' => (int) ($item['sector_id'] ?? 0),
        'functionId' => (int) ($item['function_id'] ?? 0),
        'period' => reporting_normalize_period((string) ($_GET['period'] ?? '180')),
    ]);

    $statement = $pdo->prepare('DELETE FROM action_plan_items WHERE id = :id');
    $statement->execute(['id' => $itemId]);

    send_json(200, [
        'success' => true,
        'message' => 'Acao removida com sucesso.',
        'data' => [
            'context' => $context,
            'items' => action_plan_fetch_items($pdo, $context),
            'suggestion' => action_plan_build_suggestion($pdo, $context),
        ],
    ]);
}

send_json(405, [
    'success' => false,
    'message' => 'Metodo nao permitido.',
]);
