<?php

declare(strict_types=1);

function reporting_parse_id_list($value): array
{
    $items = [];

    if (is_array($value)) {
        $items = $value;
    } elseif (is_string($value) && trim($value) !== '') {
        $items = explode(',', $value);
    }

    $normalized = [];

    foreach ($items as $item) {
        $id = (int) $item;

        if ($id > 0 && !in_array($id, $normalized, true)) {
            $normalized[] = $id;
        }
    }

    return $normalized;
}

function reporting_normalize_period(?string $period): string
{
    $allowed = ['30', '90', '180', '365', 'all'];
    $normalized = trim((string) $period);

    if (in_array($normalized, $allowed, true)) {
        return $normalized;
    }

    return '180';
}

function reporting_period_label(string $period): string
{
    $labels = [
        '30' => 'Últimos 30 dias',
        '90' => 'Últimos 90 dias',
        '180' => 'Últimos 6 meses',
        '365' => 'Últimos 12 meses',
        'all' => 'Todo o histórico',
    ];

    return $labels[$period] ?? 'Últimos 6 meses';
}

function reporting_period_start(string $period): ?string
{
    if ($period === 'all') {
        return null;
    }

    $days = (int) $period;

    if ($days <= 0) {
        return null;
    }

    return date('Y-m-d 00:00:00', strtotime('-' . $days . ' days'));
}

function reporting_month_label(string $yearMonth): string
{
    [$year, $month] = array_pad(explode('-', $yearMonth), 2, '01');
    $monthMap = [
        '01' => 'Jan',
        '02' => 'Fev',
        '03' => 'Mar',
        '04' => 'Abr',
        '05' => 'Mai',
        '06' => 'Jun',
        '07' => 'Jul',
        '08' => 'Ago',
        '09' => 'Set',
        '10' => 'Out',
        '11' => 'Nov',
        '12' => 'Dez',
    ];

    return ($monthMap[$month] ?? $month) . '/' . substr($year, -2);
}

function reporting_format_long_date(string $dateValue): string
{
    $timestamp = strtotime($dateValue);

    if ($timestamp === false) {
        return $dateValue;
    }

    $monthMap = [
        1 => 'janeiro',
        2 => 'fevereiro',
        3 => 'março',
        4 => 'abril',
        5 => 'maio',
        6 => 'junho',
        7 => 'julho',
        8 => 'agosto',
        9 => 'setembro',
        10 => 'outubro',
        11 => 'novembro',
        12 => 'dezembro',
    ];

    $day = (int) date('d', $timestamp);
    $month = $monthMap[(int) date('n', $timestamp)] ?? date('m', $timestamp);
    $year = date('Y', $timestamp);

    return sprintf('%d de %s de %s', $day, $month, $year);
}

function reporting_empty_risk(): array
{
    return [
        'label' => 'Sem dados',
        'slug' => 'neutral',
        'color' => '#94a3b8',
    ];
}

function reporting_risk_from_average(?float $average, int $answerCount = 0): array
{
    if ($answerCount <= 0 || $average === null || $average <= 0) {
        return reporting_empty_risk();
    }

    return risk_level_from_average($average);
}

function reporting_average(int|float $sum, int $count): float
{
    if ($count <= 0) {
        return 0.0;
    }

    return round(((float) $sum) / $count, 2);
}

function reporting_build_in_clause(string $prefix, array $ids, array &$params): string
{
    $placeholders = [];

    foreach (array_values($ids) as $index => $id) {
        $key = ':' . $prefix . '_' . $index;
        $placeholders[] = $key;
        $params[substr($key, 1)] = (int) $id;
    }

    return implode(', ', $placeholders);
}

function reporting_fetch_companies(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT
             c.id,
             c.name,
             c.status,
             c.cnpj,
             c.employees_count,
             c.active_form_id,
             f.name AS active_form_name,
             f.public_code AS active_form_code
         FROM companies c
         LEFT JOIN forms f ON f.id = c.active_form_id
         ORDER BY c.name ASC, c.id ASC'
    );

    $companies = [];

    foreach ($statement->fetchAll() as $row) {
        $companies[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'status' => (string) $row['status'],
            'cnpj' => (string) $row['cnpj'],
            'employees' => (int) $row['employees_count'],
            'activeFormId' => $row['active_form_id'] !== null ? (int) $row['active_form_id'] : null,
            'activeFormName' => (string) ($row['active_form_name'] ?? ''),
            'activeFormCode' => (string) ($row['active_form_code'] ?? ''),
        ];
    }

    return $companies;
}

function reporting_resolve_company_id(array $companies, int $requestedCompanyId): int
{
    if ($requestedCompanyId > 0) {
        foreach ($companies as $company) {
            if ((int) $company['id'] === $requestedCompanyId) {
                return $requestedCompanyId;
            }
        }
    }

    foreach ($companies as $company) {
        if (($company['status'] ?? '') === 'active') {
            return (int) $company['id'];
        }
    }

    return (int) ($companies[0]['id'] ?? 0);
}

function reporting_fetch_company_catalog(PDO $pdo, int $companyId): array
{
    if ($companyId <= 0) {
        return [
            'sectors' => [],
            'functions' => [],
        ];
    }

    $sectorStatement = $pdo->prepare(
        'SELECT id, sector_name, employees_count
         FROM company_sectors
         WHERE company_id = :company_id
         ORDER BY sector_name ASC, id ASC'
    );
    $sectorStatement->execute(['company_id' => $companyId]);

    $sectors = [];

    foreach ($sectorStatement->fetchAll() as $row) {
        $sectors[(int) $row['id']] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['sector_name'],
            'employees' => (int) ($row['employees_count'] ?? 0),
            'functions' => [],
        ];
    }

    $functionStatement = $pdo->prepare(
        'SELECT id, sector_id, function_name, employees_count
         FROM company_functions
         WHERE company_id = :company_id
         ORDER BY function_name ASC, id ASC'
    );
    $functionStatement->execute(['company_id' => $companyId]);

    $functions = [];

    foreach ($functionStatement->fetchAll() as $row) {
        $item = [
            'id' => (int) $row['id'],
            'sectorId' => (int) $row['sector_id'],
            'name' => (string) $row['function_name'],
            'employees' => (int) ($row['employees_count'] ?? 0),
        ];
        $functions[] = $item;

        if (isset($sectors[(int) $row['sector_id']])) {
            $sectors[(int) $row['sector_id']]['functions'][] = $item;
        }
    }

    return [
        'sectors' => array_values($sectors),
        'functions' => $functions,
    ];
}

function reporting_build_filters(array $input, array $companies): array
{
    $requestedCompanyId = (int) ($input['companyId'] ?? 0);
    $selectedCompanyId = reporting_resolve_company_id($companies, $requestedCompanyId);
    $period = reporting_normalize_period((string) ($input['period'] ?? '180'));
    $sectorId = (int) ($input['sectorId'] ?? 0);
    $functionId = (int) ($input['functionId'] ?? 0);
    $sectorIds = reporting_parse_id_list($input['sectorIds'] ?? []);

    return [
        'companyId' => $selectedCompanyId,
        'period' => $period,
        'periodLabel' => reporting_period_label($period),
        'dateFrom' => reporting_period_start($period),
        'sectorId' => $sectorId > 0 ? $sectorId : null,
        'functionId' => $functionId > 0 ? $functionId : null,
        'sectorIds' => $sectorIds,
    ];
}

function reporting_build_where_clause(array $filters, array &$params): string
{
    $conditions = ['1 = 1'];

    if (($filters['companyId'] ?? 0) > 0) {
        $conditions[] = 'ac.company_id = :company_id';
        $params['company_id'] = (int) $filters['companyId'];
    }

    if (($filters['functionId'] ?? null) !== null) {
        $conditions[] = 'ac.function_id = :function_id';
        $params['function_id'] = (int) $filters['functionId'];
    } elseif (($filters['sectorId'] ?? null) !== null) {
        $conditions[] = 'COALESCE(ac.sector_id, fn.sector_id) = :sector_id';
        $params['sector_id'] = (int) $filters['sectorId'];
    } elseif (!empty($filters['sectorIds'])) {
        $inClause = reporting_build_in_clause('sector_id', $filters['sectorIds'], $params);
        $conditions[] = 'COALESCE(ac.sector_id, fn.sector_id) IN (' . $inClause . ')';
    }

    if (!empty($filters['dateFrom'])) {
        $conditions[] = 'COALESCE(s.completed_at, s.updated_at, s.started_at) >= :date_from';
        $params['date_from'] = (string) $filters['dateFrom'];
    }

    return implode(' AND ', $conditions);
}

function reporting_fetch_session_rows(PDO $pdo, array $filters): array
{
    $params = [];
    $where = reporting_build_where_clause($filters, $params);

    $statement = $pdo->prepare(
        'SELECT
             s.id AS session_id,
             s.session_public_id,
             s.status,
             s.started_at,
             s.updated_at,
             s.completed_at,
             COALESCE(s.completed_at, s.updated_at, s.started_at) AS event_at,
             ac.company_id,
             ac.form_id,
             ac.scope_type,
             ac.scope_label,
             ac.sector_id,
             ac.function_id,
             ac.expires_at,
             c.name AS company_name,
             f.name AS form_name,
             COALESCE(ac.sector_id, fn.sector_id) AS resolved_sector_id,
             sct.sector_name,
             fn.function_name
         FROM access_code_sessions s
         INNER JOIN employee_access_codes ac ON ac.id = s.access_code_id
         INNER JOIN companies c ON c.id = ac.company_id
         INNER JOIN forms f ON f.id = ac.form_id
         LEFT JOIN company_functions fn ON fn.id = ac.function_id
         LEFT JOIN company_sectors sct ON sct.id = COALESCE(ac.sector_id, fn.sector_id)
         WHERE ' . $where . '
         ORDER BY event_at DESC, s.id DESC'
    );
    $statement->execute($params);

    return $statement->fetchAll();
}

function reporting_fetch_answer_rows(PDO $pdo, array $filters): array
{
    $params = [];
    $where = reporting_build_where_clause($filters, $params);

    $statement = $pdo->prepare(
        'SELECT
             a.session_id,
             a.answer_value,
             fq.id AS question_id,
             fq.position,
             fq.question_text,
             s.session_public_id,
             COALESCE(s.completed_at, s.updated_at, s.started_at) AS event_at,
             ac.company_id,
             ac.form_id,
             ac.scope_type,
             ac.scope_label,
             ac.sector_id,
             ac.function_id,
             COALESCE(ac.sector_id, fn.sector_id) AS resolved_sector_id,
             sct.sector_name,
             fn.function_name
         FROM access_code_answers a
         INNER JOIN access_code_sessions s ON s.id = a.session_id
         INNER JOIN employee_access_codes ac ON ac.id = s.access_code_id
         INNER JOIN form_questions fq ON fq.id = a.form_question_id
         LEFT JOIN company_functions fn ON fn.id = ac.function_id
         LEFT JOIN company_sectors sct ON sct.id = COALESCE(ac.sector_id, fn.sector_id)
         WHERE s.status = "done"
           AND ' . $where . '
         ORDER BY fq.position ASC, a.id ASC'
    );
    $statement->execute($params);

    return $statement->fetchAll();
}

function reporting_build_summary(array $sessionRows, array $answerRows, array $company): array
{
    $totalSessions = count($sessionRows);
    $completedSessions = 0;
    $pendingSessions = 0;
    $answersSum = 0;
    $answersCount = 0;

    foreach ($sessionRows as $row) {
        if (($row['status'] ?? '') === 'done') {
            $completedSessions++;
        } else {
            $pendingSessions++;
        }
    }

    foreach ($answerRows as $row) {
        $answersSum += (int) ($row['answer_value'] ?? 0);
        $answersCount++;
    }

    $overallAverage = reporting_average($answersSum, $answersCount);
    $overallRisk = reporting_risk_from_average($overallAverage, $answersCount);
    $participationRate = 0;
    $companyEmployees = (int) ($company['employees'] ?? 0);

    if ($companyEmployees > 0) {
        $participationRate = (int) max(0, min(100, round(($completedSessions / $companyEmployees) * 100)));
    }

    $complianceRate = $totalSessions > 0
        ? (int) max(0, min(100, round(($completedSessions / $totalSessions) * 100)))
        : 0;

    return [
        'totalSessions' => $totalSessions,
        'completedSessions' => $completedSessions,
        'pendingSessions' => $pendingSessions,
        'answersCount' => $answersCount,
        'average' => $overallAverage,
        'riskIndex' => answer_average_to_percent($overallAverage),
        'riskLabel' => $overallRisk['label'],
        'riskSlug' => $overallRisk['slug'],
        'participationRate' => $participationRate,
        'complianceRate' => $complianceRate,
    ];
}

function reporting_build_status_breakdown(array $sessionRows): array
{
    $done = 0;
    $pending = 0;
    $delayed = 0;
    $now = time();

    foreach ($sessionRows as $row) {
        if (($row['status'] ?? '') === 'done') {
            $done++;
            continue;
        }

        $expiresAt = trim((string) ($row['expires_at'] ?? ''));
        $startedAt = trim((string) ($row['started_at'] ?? ''));
        $isDelayed = false;

        if ($expiresAt !== '' && strtotime($expiresAt) < $now) {
            $isDelayed = true;
        } elseif ($startedAt !== '' && strtotime($startedAt) < strtotime('-7 days')) {
            $isDelayed = true;
        }

        if ($isDelayed) {
            $delayed++;
        } else {
            $pending++;
        }
    }

    return [
        [
            'label' => 'Concluídas',
            'value' => $done,
            'color' => '#1eb980',
            'slug' => 'green',
        ],
        [
            'label' => 'Em Andamento',
            'value' => $pending,
            'color' => '#18a0fb',
            'slug' => 'blue',
        ],
        [
            'label' => 'Atrasadas',
            'value' => $delayed,
            'color' => '#ef5656',
            'slug' => 'red',
        ],
    ];
}

function reporting_build_monthly_completion_series(array $sessionRows, int $months = 6): array
{
    $months = max(1, $months);
    $buckets = [];
    $start = new DateTimeImmutable('first day of this month');
    $start = $start->modify('-' . ($months - 1) . ' months');

    for ($index = 0; $index < $months; $index++) {
        $key = $start->modify('+' . $index . ' months')->format('Y-m');
        $buckets[$key] = [
            'key' => $key,
            'label' => reporting_month_label($key),
            'value' => 0,
        ];
    }

    foreach ($sessionRows as $row) {
        if (($row['status'] ?? '') !== 'done') {
            continue;
        }

        $eventAt = trim((string) ($row['event_at'] ?? ''));
        $timestamp = strtotime($eventAt);

        if ($timestamp === false) {
            continue;
        }

        $key = date('Y-m', $timestamp);

        if (isset($buckets[$key])) {
            $buckets[$key]['value']++;
        }
    }

    return array_values($buckets);
}

function reporting_build_monthly_risk_series(array $answerRows, int $months = 6): array
{
    $months = max(1, $months);
    $buckets = [];
    $start = new DateTimeImmutable('first day of this month');
    $start = $start->modify('-' . ($months - 1) . ' months');

    for ($index = 0; $index < $months; $index++) {
        $key = $start->modify('+' . $index . ' months')->format('Y-m');
        $buckets[$key] = [
            'key' => $key,
            'label' => reporting_month_label($key),
            'sum' => 0,
            'count' => 0,
            'value' => 0,
        ];
    }

    foreach ($answerRows as $row) {
        $timestamp = strtotime((string) ($row['event_at'] ?? ''));

        if ($timestamp === false) {
            continue;
        }

        $key = date('Y-m', $timestamp);

        if (!isset($buckets[$key])) {
            continue;
        }

        $buckets[$key]['sum'] += (int) ($row['answer_value'] ?? 0);
        $buckets[$key]['count']++;
    }

    foreach ($buckets as &$bucket) {
        $average = reporting_average((int) $bucket['sum'], (int) $bucket['count']);
        $bucket['value'] = answer_average_to_percent($average);
        unset($bucket['sum'], $bucket['count']);
    }
    unset($bucket);

    return array_values($buckets);
}

function reporting_build_question_rankings(array $answerRows): array
{
    $buckets = [];

    foreach ($answerRows as $row) {
        $questionId = (int) ($row['question_id'] ?? 0);

        if ($questionId <= 0) {
            continue;
        }

        if (!isset($buckets[$questionId])) {
            $buckets[$questionId] = [
                'questionId' => $questionId,
                'position' => (int) ($row['position'] ?? 0),
                'text' => (string) ($row['question_text'] ?? ''),
                'sum' => 0,
                'count' => 0,
                'sectorCounts' => [],
            ];
        }

        $buckets[$questionId]['sum'] += (int) ($row['answer_value'] ?? 0);
        $buckets[$questionId]['count']++;

        $sectorName = trim((string) ($row['sector_name'] ?? ''));

        if ($sectorName !== '') {
            $buckets[$questionId]['sectorCounts'][$sectorName] = ($buckets[$questionId]['sectorCounts'][$sectorName] ?? 0) + 1;
        }
    }

    $items = [];

    foreach ($buckets as $bucket) {
        $average = reporting_average((int) $bucket['sum'], (int) $bucket['count']);
        $risk = reporting_risk_from_average($average, (int) $bucket['count']);
        arsort($bucket['sectorCounts']);
        $sectorName = (string) (array_key_first($bucket['sectorCounts']) ?? '');

        $items[] = [
            'questionId' => (int) $bucket['questionId'],
            'position' => (int) $bucket['position'],
            'text' => (string) $bucket['text'],
            'average' => $average,
            'score' => answer_average_to_percent($average),
            'answerCount' => (int) $bucket['count'],
            'sectorName' => $sectorName,
            'riskLabel' => $risk['label'],
            'riskSlug' => $risk['slug'],
        ];
    }

    usort($items, static function (array $left, array $right): int {
        if ($left['average'] === $right['average']) {
            return $left['position'] <=> $right['position'];
        }

        return $right['average'] <=> $left['average'];
    });

    return $items;
}

function reporting_build_sector_breakdown(array $catalog, array $answerRows, float $fallbackAverage): array
{
    $sectorBuckets = [];
    $functionBuckets = [];

    foreach ($answerRows as $row) {
        $sectorId = (int) ($row['resolved_sector_id'] ?? 0);
        $functionId = (int) ($row['function_id'] ?? 0);
        $answerValue = (int) ($row['answer_value'] ?? 0);

        if ($sectorId > 0) {
            if (!isset($sectorBuckets[$sectorId])) {
                $sectorBuckets[$sectorId] = ['sum' => 0, 'count' => 0];
            }

            $sectorBuckets[$sectorId]['sum'] += $answerValue;
            $sectorBuckets[$sectorId]['count']++;
        }

        if ($functionId > 0) {
            if (!isset($functionBuckets[$functionId])) {
                $functionBuckets[$functionId] = ['sum' => 0, 'count' => 0];
            }

            $functionBuckets[$functionId]['sum'] += $answerValue;
            $functionBuckets[$functionId]['count']++;
        }
    }

    $sectors = [];

    foreach ($catalog['sectors'] as $sector) {
        $bucket = $sectorBuckets[(int) $sector['id']] ?? ['sum' => 0, 'count' => 0];
        $average = $bucket['count'] > 0
            ? reporting_average((int) $bucket['sum'], (int) $bucket['count'])
            : ($fallbackAverage > 0 ? $fallbackAverage : 0.0);
        $risk = reporting_risk_from_average($average, max((int) $bucket['count'], $fallbackAverage > 0 ? 1 : 0));

        $functions = [];

        foreach ($sector['functions'] as $companyFunction) {
            $functionBucket = $functionBuckets[(int) $companyFunction['id']] ?? ['sum' => 0, 'count' => 0];
            $functionAverage = $functionBucket['count'] > 0
                ? reporting_average((int) $functionBucket['sum'], (int) $functionBucket['count'])
                : $average;
            $functionRisk = reporting_risk_from_average($functionAverage, max((int) $functionBucket['count'], $average > 0 ? 1 : 0));

            $functions[] = [
                'id' => (int) $companyFunction['id'],
                'name' => (string) $companyFunction['name'],
                'employees' => (int) $companyFunction['employees'],
                'average' => $functionAverage,
                'answerCount' => (int) ($functionBucket['count'] ?? 0),
                'riskLabel' => $functionRisk['label'],
                'riskSlug' => $functionRisk['slug'],
            ];
        }

        usort($functions, static function (array $left, array $right): int {
            return $right['average'] <=> $left['average'];
        });

        $sectors[] = [
            'id' => (int) $sector['id'],
            'name' => (string) $sector['name'],
            'employees' => (int) $sector['employees'],
            'average' => $average,
            'answerCount' => (int) ($bucket['count'] ?? 0),
            'riskLabel' => $risk['label'],
            'riskSlug' => $risk['slug'],
            'functions' => $functions,
        ];
    }

    usort($sectors, static function (array $left, array $right): int {
        return $right['average'] <=> $left['average'];
    });

    return $sectors;
}

function reporting_build_distribution(array $sectorBreakdown): array
{
    $distribution = [
        'low' => 0,
        'medium' => 0,
        'high' => 0,
    ];

    foreach ($sectorBreakdown as $sector) {
        $slug = (string) ($sector['riskSlug'] ?? 'neutral');

        if (isset($distribution[$slug])) {
            $distribution[$slug]++;
        }
    }

    return $distribution;
}

function reporting_build_heatmap_items(array $questionRankings): array
{
    $items = [];

    foreach (array_slice($questionRankings, 0, 5) as $index => $item) {
        $probability = max(1, min(5, (int) round((float) $item['average'])));
        $impact = max(1, min(5, (int) round((float) $item['average'] + ((float) $item['average'] >= 4 ? 0.6 : 0.2))));
        $score = $probability * $impact;

        $items[] = [
            'rank' => $index + 1,
            'questionId' => (int) $item['questionId'],
            'text' => (string) $item['text'],
            'sectorName' => (string) ($item['sectorName'] ?? ''),
            'average' => (float) $item['average'],
            'probability' => $probability,
            'impact' => $impact,
            'score' => $score,
            'riskLabel' => (string) $item['riskLabel'],
        ];
    }

    usort($items, static function (array $left, array $right): int {
        return $right['score'] <=> $left['score'];
    });

    return $items;
}

function reporting_selected_sector_label(array $catalog, array $filters): string
{
    $selectedSectorIds = array_map('intval', $filters['sectorIds'] ?? []);

    if ($selectedSectorIds === []) {
        return '';
    }

    $names = [];

    foreach ($catalog['sectors'] ?? [] as $sector) {
        if (in_array((int) ($sector['id'] ?? 0), $selectedSectorIds, true)) {
            $names[] = trim((string) ($sector['name'] ?? ''));
        }
    }

    $names = array_values(array_filter($names));

    if ($names === []) {
        return '';
    }

    return implode(', ', $names);
}

function reporting_recommendation_from_question(string $questionText): string
{
    $normalized = mb_strtolower($questionText, 'UTF-8');

    if (str_contains($normalized, 'prazo') || str_contains($normalized, 'carga') || str_contains($normalized, 'trabalho')) {
        return 'Revisar a distribuição de tarefas, metas operacionais e pausas ao longo da jornada.';
    }

    if (str_contains($normalized, 'apoio') || str_contains($normalized, 'lider') || str_contains($normalized, 'feedback')) {
        return 'Fortalecer a liderança com rituais de escuta, feedback estruturado e acompanhamento mensal.';
    }

    if (str_contains($normalized, 'clareza') || str_contains($normalized, 'prioridade') || str_contains($normalized, 'papel')) {
        return 'Alinhar papéis, prioridades e fluxo de comunicação entre equipes para reduzir ambiguidades.';
    }

    if (str_contains($normalized, 'pausa') || str_contains($normalized, 'descanso')) {
        return 'Revisar pausas, jornada e momentos de recuperação para reduzir desgaste contínuo.';
    }

    return 'Realizar escuta estruturada com o time, definir plano de melhoria e monitorar o indicador nas próximas coletas.';
}

function reporting_action_plan_status_label(string $statusSlug): string
{
    $map = [
        'high' => 'Prioridade Alta',
        'medium' => 'Prioridade Média',
        'monitor' => 'Monitorar',
        'done' => 'Concluída',
    ];

    $normalized = trim(strtolower($statusSlug));

    return $map[$normalized] ?? $map['monitor'];
}

function reporting_action_plan_status_slug(string $statusSlug): string
{
    $normalized = trim(strtolower($statusSlug));
    $allowed = ['high', 'medium', 'monitor', 'done'];

    if (in_array($normalized, $allowed, true)) {
        return $normalized;
    }

    return 'monitor';
}

function reporting_build_action_plan(array $questionRankings, string $selectedSectorLabel = ''): array
{
    $plan = [];
    $selectedSectorLabel = trim($selectedSectorLabel);

    foreach (array_slice($questionRankings, 0, 4) as $item) {
        $average = (float) ($item['average'] ?? 0);
        $priority = 'Monitorar';
        $prioritySlug = 'monitor';
        $deadline = '90 dias';

        if ($average >= 4.2) {
            $priority = 'Prioridade Alta';
            $prioritySlug = 'high';
            $deadline = '30 dias';
        } elseif ($average >= 3.2) {
            $priority = 'Prioridade Média';
            $prioritySlug = 'medium';
            $deadline = '60 dias';
        }

        $plan[] = [
            'factor' => (string) $item['text'],
            'sectorName' => $selectedSectorLabel !== '' ? $selectedSectorLabel : (string) ($item['sectorName'] ?: 'Empresa'),
            'recommendedAction' => reporting_recommendation_from_question((string) $item['text']),
            'deadline' => $deadline,
            'priorityLabel' => $priority,
            'prioritySlug' => $prioritySlug,
        ];
    }

    return $plan;
}

function reporting_fetch_saved_action_plan(PDO $pdo, array $filters): array
{
    if ((int) ($filters['companyId'] ?? 0) <= 0) {
        return [];
    }

    $params = [
        'company_id' => (int) $filters['companyId'],
    ];
    $conditions = ['api.company_id = :company_id'];

    if (($filters['functionId'] ?? null) !== null) {
        $conditions[] = '(api.function_id = :function_id OR (api.function_id IS NULL AND api.sector_id IS NULL))';
        $params['function_id'] = (int) $filters['functionId'];
    } elseif (($filters['sectorId'] ?? null) !== null) {
        $conditions[] = '(api.sector_id = :sector_id OR api.sector_id IS NULL)';
        $params['sector_id'] = (int) $filters['sectorId'];
    } elseif (!empty($filters['sectorIds'])) {
        $inClause = reporting_build_in_clause('action_sector', $filters['sectorIds'], $params);
        $conditions[] = '(api.sector_id IN (' . $inClause . ') OR api.sector_id IS NULL)';
    }

    $statement = $pdo->prepare(
        'SELECT api.id,
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
        $statusSlug = reporting_action_plan_status_slug((string) ($row['status_slug'] ?? 'monitor'));
        $sectorName = trim((string) ($row['sector_name'] ?? ''));
        $functionName = trim((string) ($row['function_name'] ?? ''));

        if ($functionName !== '') {
            $scopeName = $sectorName !== '' ? $sectorName . ' / ' . $functionName : $functionName;
        } elseif ($sectorName !== '') {
            $scopeName = $sectorName;
        } else {
            $scopeName = 'Empresa';
        }

        $items[] = [
            'id' => (int) $row['id'],
            'factor' => (string) $row['factor'],
            'sectorName' => $scopeName,
            'recommendedAction' => (string) $row['action_text'],
            'deadline' => (string) $row['deadline'],
            'priorityLabel' => reporting_action_plan_status_label($statusSlug),
            'prioritySlug' => $statusSlug,
            'responsible' => (string) ($row['responsible'] ?? ''),
            'notes' => (string) ($row['notes'] ?? ''),
            'source' => 'manual',
        ];
    }

    return $items;
}

function reporting_build_dashboard_plan_rows(array $sectorBreakdown, int $companyId = 0, string $period = '180'): array
{
    $rows = [];

    foreach ($sectorBreakdown as $sector) {
        $rows[] = [
            'type' => 'sector',
            'id' => (int) $sector['id'],
            'name' => (string) $sector['name'],
            'employees' => (int) $sector['employees'],
            'riskLabel' => (string) $sector['riskLabel'],
            'riskSlug' => (string) $sector['riskSlug'],
            'average' => (float) $sector['average'],
            'sectorId' => (int) $sector['id'],
            'functionId' => null,
            'actionUrl' =>
                'action-plan-editor.html?companyId=' .
                $companyId .
                '&sectorId=' .
                (int) $sector['id'] .
                '&period=' .
                rawurlencode($period),
        ];

        foreach (array_slice($sector['functions'], 0, 2) as $companyFunction) {
            $rows[] = [
                'type' => 'function',
                'id' => (int) $companyFunction['id'],
                'name' => (string) $companyFunction['name'],
                'employees' => (int) $companyFunction['employees'],
                'riskLabel' => (string) $companyFunction['riskLabel'],
                'riskSlug' => (string) $companyFunction['riskSlug'],
                'average' => (float) $companyFunction['average'],
                'sectorId' => (int) $sector['id'],
                'functionId' => (int) $companyFunction['id'],
                'actionUrl' =>
                    'action-plan-editor.html?companyId=' .
                    $companyId .
                    '&sectorId=' .
                    (int) $sector['id'] .
                    '&functionId=' .
                    (int) $companyFunction['id'] .
                    '&period=' .
                    rawurlencode($period),
            ];
        }
    }

    usort($rows, static function (array $left, array $right): int {
        return $right['average'] <=> $left['average'];
    });

    return array_slice($rows, 0, 8);
}

function reporting_build_payload(PDO $pdo, array $input): array
{
    $companies = reporting_fetch_companies($pdo);
    $filters = reporting_build_filters($input, $companies);
    $catalog = reporting_fetch_company_catalog($pdo, (int) $filters['companyId']);
    $sessionRows = reporting_fetch_session_rows($pdo, $filters);
    $answerRows = reporting_fetch_answer_rows($pdo, $filters);

    $company = [
        'id' => 0,
        'name' => 'Empresa',
        'employees' => 0,
        'activeFormName' => '',
    ];

    foreach ($companies as $companyRow) {
        if ((int) $companyRow['id'] === (int) $filters['companyId']) {
            $company = $companyRow;
            break;
        }
    }

    $summary = reporting_build_summary($sessionRows, $answerRows, $company);
    $sectorBreakdown = reporting_build_sector_breakdown($catalog, $answerRows, (float) $summary['average']);
    $questionRankings = reporting_build_question_rankings($answerRows);
    $heatmapItems = reporting_build_heatmap_items($questionRankings);
    $savedActionPlan = reporting_fetch_saved_action_plan($pdo, $filters);
    $selectedSectorLabel = reporting_selected_sector_label($catalog, $filters);
    $actionPlan = $savedActionPlan !== [] ? $savedActionPlan : reporting_build_action_plan($questionRankings, $selectedSectorLabel);
    $distribution = reporting_build_distribution($sectorBreakdown);
    $criticalSectors = array_values(array_filter($sectorBreakdown, static function (array $sector): bool {
        return in_array($sector['riskSlug'] ?? '', ['medium', 'high'], true);
    }));

    return [
        'filters' => $filters,
        'company' => $company,
        'options' => [
            'companies' => $companies,
            'sectors' => array_map(static function (array $sector): array {
                return [
                    'id' => (int) $sector['id'],
                    'name' => (string) $sector['name'],
                ];
            }, $catalog['sectors']),
            'functions' => array_map(static function (array $companyFunction): array {
                return [
                    'id' => (int) $companyFunction['id'],
                    'sectorId' => (int) $companyFunction['sectorId'],
                    'name' => (string) $companyFunction['name'],
                ];
            }, $catalog['functions']),
        ],
        'summary' => [
            'emittedAt' => reporting_format_long_date(date('Y-m-d')),
            'periodLabel' => (string) $filters['periodLabel'],
            'totalSessions' => (int) $summary['totalSessions'],
            'completedSessions' => (int) $summary['completedSessions'],
            'pendingSessions' => (int) $summary['pendingSessions'],
            'participationRate' => (int) $summary['participationRate'],
            'complianceRate' => (int) $summary['complianceRate'],
            'riskIndex' => (int) $summary['riskIndex'],
            'riskLabel' => (string) $summary['riskLabel'],
            'riskSlug' => (string) $summary['riskSlug'],
            'criticalSectorsCount' => count($criticalSectors),
        ],
        'statusBreakdown' => reporting_build_status_breakdown($sessionRows),
        'completionSeries' => reporting_build_monthly_completion_series($sessionRows, 6),
        'riskSeries' => reporting_build_monthly_risk_series($answerRows, 6),
        'questionRankings' => $questionRankings,
        'sectorBreakdown' => $sectorBreakdown,
        'distribution' => $distribution,
        'heatmapItems' => $heatmapItems,
        'actionPlan' => $actionPlan,
        'actionPlanMeta' => [
            'source' => $savedActionPlan !== [] ? 'manual' : 'automatic',
        ],
        'dashboardRows' => reporting_build_dashboard_plan_rows(
            $sectorBreakdown,
            (int) $filters['companyId'],
            (string) ($filters['period'] ?? '180')
        ),
    ];
}
