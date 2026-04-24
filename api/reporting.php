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

function reporting_score_to_percent(?float $score): int
{
    $normalizedScore = (float) ($score ?? 0);

    if ($normalizedScore <= 0) {
        return 0;
    }

    return (int) max(0, min(100, round(($normalizedScore / 25) * 100)));
}

function reporting_find_company(array $companies, int $companyId): array
{
    foreach ($companies as $company) {
        if ((int) ($company['id'] ?? 0) === $companyId) {
            return $company;
        }
    }

    return [
        'id' => 0,
        'name' => 'Empresa',
        'status' => 'active',
        'cnpj' => '',
        'employees' => 0,
        'activeFormId' => null,
        'activeFormName' => '',
        'activeFormCode' => '',
    ];
}

function reporting_fetch_company_forms(PDO $pdo, int $companyId): array
{
    if ($companyId <= 0) {
        return [];
    }

    $statement = $pdo->prepare(
        'SELECT
             f.id,
             f.public_code,
             f.name,
             f.status,
             CASE WHEN c.active_form_id = f.id THEN 1 ELSE 0 END AS is_primary
         FROM company_form_links cfl
         INNER JOIN forms f ON f.id = cfl.form_id
         INNER JOIN companies c ON c.id = cfl.company_id
         WHERE cfl.company_id = :company_id
         ORDER BY is_primary DESC, f.name ASC, f.id ASC'
    );
    $statement->execute(['company_id' => $companyId]);

    $forms = [];

    foreach ($statement->fetchAll() as $row) {
        $forms[] = [
            'id' => (int) $row['id'],
            'publicCode' => (string) ($row['public_code'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'status' => (string) ($row['status'] ?? 'active'),
            'isPrimary' => (int) ($row['is_primary'] ?? 0) === 1,
        ];
    }

    return $forms;
}

function reporting_resolve_form_filter(array $companyForms, array $company, array $input): array
{
    $requestedFormId = (int) ($input['formId'] ?? 0);
    $activeFormId = (int) ($company['activeFormId'] ?? 0);
    $fallback = null;

    foreach ($companyForms as $form) {
        if ($fallback === null) {
            $fallback = $form;
        }

        if ($requestedFormId > 0 && (int) ($form['id'] ?? 0) === $requestedFormId) {
            return $form;
        }
    }

    if ($activeFormId > 0) {
        foreach ($companyForms as $form) {
            if ((int) ($form['id'] ?? 0) === $activeFormId) {
                return $form;
            }
        }
    }

    return $fallback ?? [
        'id' => 0,
        'publicCode' => '',
        'name' => '',
        'status' => 'inactive',
        'isPrimary' => false,
    ];
}

function reporting_normalize_text(string $value): string
{
    $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

    if (!is_string($normalized) || $normalized === '') {
        $normalized = $value;
    }

    return mb_strtolower($normalized, 'UTF-8');
}

function reporting_factor_catalog(): array
{
    // Fixed effects are defined by psychosocial factor and reused by report, preview and exports.
    return [
        'demands' => [
            'factorKey' => 'demands',
            'factorName' => 'Demandas Psicossociais',
            'effect' => 5,
            'effectDescription' => 'Impacto alto por envolver ritmo, sobrecarga, prazos e pressao operacional.',
            'keywords' => ['carga', 'prazo', 'pressao', 'demanda', 'sobrecarga', 'ritmo', 'muitas informacoes', 'trabalho'],
        ],
        'recovery' => [
            'factorKey' => 'recovery',
            'factorName' => 'Recuperacao e Pausas',
            'effect' => 4,
            'effectDescription' => 'Impacto relevante sobre recuperacao fisica e mental durante a jornada.',
            'keywords' => ['pausa', 'descanso', 'recuperacao', 'jornada'],
        ],
        'support' => [
            'factorKey' => 'support',
            'factorName' => 'Apoio Social e Lideranca',
            'effect' => 4,
            'effectDescription' => 'Impacto relevante na seguranca psicossocial por depender de apoio, escuta e feedback.',
            'keywords' => ['apoio', 'lider', 'lideranca', 'feedback'],
        ],
        'climate' => [
            'factorKey' => 'climate',
            'factorName' => 'Clima Organizacional',
            'effect' => 4,
            'effectDescription' => 'Impacto relevante na cooperacao, convivio e estabilidade emocional da equipe.',
            'keywords' => ['clima', 'dialogo', 'equipe', 'conflito', 'desentendimento'],
        ],
        'clarity' => [
            'factorKey' => 'clarity',
            'factorName' => 'Clareza de Papel e Prioridades',
            'effect' => 3,
            'effectDescription' => 'Impacto moderado sobre organizacao do trabalho, autonomia e direcionamento.',
            'keywords' => ['clareza', 'prioridade', 'papel', 'autonomia', 'decis'],
        ],
        'engagement' => [
            'factorKey' => 'engagement',
            'factorName' => 'Engajamento e Sentido do Trabalho',
            'effect' => 3,
            'effectDescription' => 'Impacto moderado relacionado a motivacao, reconhecimento e proposito percebido.',
            'keywords' => ['engaj', 'sentido', 'motiv', 'reconhecimento'],
        ],
        'general' => [
            'factorKey' => 'general',
            'factorName' => 'Fator Psicossocial Geral',
            'effect' => 3,
            'effectDescription' => 'Impacto moderado aplicado quando a pergunta nao se encaixa em uma categoria especifica.',
            'keywords' => [],
        ],
    ];
}

function reporting_question_profile(string $questionText): array
{
    $normalized = reporting_normalize_text($questionText);
    $catalog = reporting_factor_catalog();

    foreach ($catalog as $key => $profile) {
        foreach ($profile['keywords'] as $keyword) {
            if ($keyword !== '' && str_contains($normalized, $keyword)) {
                return $profile;
            }
        }
    }

    return $catalog['general'];
}

function reporting_risk_from_score(?float $score, int $sampleCount = 0): array
{
    if ($sampleCount <= 0 || $score === null || $score <= 0) {
        return array_merge(reporting_empty_risk(), [
            'includePgr' => false,
            'pgrLabel' => 'Sem dados',
            'band' => '',
            'actionGuidance' => 'Aguardando respostas suficientes para classificacao.',
        ]);
    }

    if ($score >= 15) {
        return [
            'label' => 'Risco Alto',
            'slug' => 'high',
            'color' => '#ef5656',
            'includePgr' => true,
            'pgrLabel' => 'Incluir no PGR',
            'band' => '15 a 25',
            'actionGuidance' => 'Incluir no PGR com plano imediato e acompanhamento frequente.',
        ];
    }

    if ($score >= 7) {
        return [
            'label' => 'Risco Moderado',
            'slug' => 'medium',
            'color' => '#f4a31d',
            'includePgr' => true,
            'pgrLabel' => 'Incluir no PGR',
            'band' => '7 a 14',
            'actionGuidance' => 'Incluir no PGR com medidas preventivas formais e monitoramento.',
        ];
    }

    return [
        'label' => 'Risco Baixo',
        'slug' => 'low',
        'color' => '#1eb980',
        'includePgr' => false,
        'pgrLabel' => 'Monitoramento Interno',
        'band' => '1 a 6',
        'actionGuidance' => 'Manter monitoramento interno e rotina preventiva.',
    ];
}

function reporting_scope_label_from_row(array $row): string
{
    $sectorName = trim((string) ($row['sector_name'] ?? ''));
    $functionName = trim((string) ($row['function_name'] ?? ''));

    if ($functionName !== '') {
        return $sectorName !== '' ? $sectorName . ' / ' . $functionName : $functionName;
    }

    if ($sectorName !== '') {
        return $sectorName;
    }

    return 'Empresa';
}

function reporting_dominant_label(array $counts, string $fallback = ''): string
{
    if ($counts === []) {
        return $fallback;
    }

    arsort($counts);
    return (string) (array_key_first($counts) ?? $fallback);
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
        'formId' => null,
        'formLabel' => '',
        'formCode' => '',
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

    if (($filters['formId'] ?? null) !== null) {
        $conditions[] = 'ac.form_id = :form_id';
        $params['form_id'] = (int) $filters['formId'];
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
             f.public_code AS form_code,
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
             f.name AS form_name,
             f.public_code AS form_code,
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
         INNER JOIN forms f ON f.id = ac.form_id
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

function reporting_build_summary(
    array $sessionRows,
    array $answerRows,
    array $company,
    array $questionRankings,
    array $factorResults
): array
{
    $totalSessions = count($sessionRows);
    $completedSessions = 0;
    $pendingSessions = 0;
    $answersCount = 0;
    $weightedProbability = 0.0;
    $weightedEffect = 0.0;

    foreach ($sessionRows as $row) {
        if (($row['status'] ?? '') === 'done') {
            $completedSessions++;
        } else {
            $pendingSessions++;
        }
    }

    foreach ($questionRankings as $item) {
        $questionAnswerCount = (int) ($item['answerCount'] ?? 0);

        if ($questionAnswerCount <= 0) {
            continue;
        }

        $answersCount += $questionAnswerCount;
        $weightedProbability += ((float) ($item['probability'] ?? 0)) * $questionAnswerCount;
        $weightedEffect += ((float) ($item['effect'] ?? 0)) * $questionAnswerCount;
    }

    $overallProbability = $answersCount > 0 ? round($weightedProbability / $answersCount, 2) : 0.0;
    $overallEffect = $answersCount > 0 ? round($weightedEffect / $answersCount, 2) : 0.0;
    $overallRiskScore = $answersCount > 0 ? round($overallProbability * $overallEffect, 2) : 0.0;
    $overallRisk = reporting_risk_from_score($overallRiskScore, $answersCount);
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
        'average' => $overallProbability,
        'effectAverage' => $overallEffect,
        'riskScore' => $overallRiskScore,
        'riskIndex' => reporting_score_to_percent($overallRiskScore),
        'riskLabel' => $overallRisk['label'],
        'riskSlug' => $overallRisk['slug'],
        'pgrIncluded' => (bool) ($overallRisk['includePgr'] ?? false),
        'pgrLabel' => (string) ($overallRisk['pgrLabel'] ?? 'Sem dados'),
        'participationRate' => $participationRate,
        'complianceRate' => $complianceRate,
        'totalQuestions' => count($questionRankings),
        'criticalFactorsCount' => count(array_filter($factorResults, static function (array $factor): bool {
            return in_array((string) ($factor['riskSlug'] ?? 'neutral'), ['medium', 'high'], true);
        })),
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

        $answerValue = (int) ($row['answer_value'] ?? 0);
        $effect = (float) ($row['effect'] ?? reporting_question_profile((string) ($row['question_text'] ?? ''))['effect']);

        $buckets[$key]['sum'] += round($answerValue * $effect, 2);
        $buckets[$key]['count']++;
    }

    foreach ($buckets as &$bucket) {
        $averageScore = reporting_average((float) $bucket['sum'], (int) $bucket['count']);
        $bucket['value'] = reporting_score_to_percent($averageScore);
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
            $profile = reporting_question_profile((string) ($row['question_text'] ?? ''));
            $buckets[$questionId] = [
                'questionId' => $questionId,
                'position' => (int) ($row['position'] ?? 0),
                'text' => (string) ($row['question_text'] ?? ''),
                'factorKey' => (string) ($profile['factorKey'] ?? 'general'),
                'factorName' => (string) ($profile['factorName'] ?? 'Fator Psicossocial Geral'),
                'effect' => (int) ($profile['effect'] ?? 3),
                'effectDescription' => (string) ($profile['effectDescription'] ?? ''),
                'sum' => 0,
                'count' => 0,
                'sectorCounts' => [],
                'scopeCounts' => [],
            ];
        }

        $buckets[$questionId]['sum'] += (int) ($row['answer_value'] ?? 0);
        $buckets[$questionId]['count']++;

        $sectorName = trim((string) ($row['sector_name'] ?? ''));
        $scopeName = reporting_scope_label_from_row($row);

        if ($sectorName !== '') {
            $buckets[$questionId]['sectorCounts'][$sectorName] = ($buckets[$questionId]['sectorCounts'][$sectorName] ?? 0) + 1;
        }

        if ($scopeName !== '') {
            $buckets[$questionId]['scopeCounts'][$scopeName] = ($buckets[$questionId]['scopeCounts'][$scopeName] ?? 0) + 1;
        }
    }

    $items = [];

    foreach ($buckets as $bucket) {
        $probability = reporting_average((int) $bucket['sum'], (int) $bucket['count']);
        $effect = (int) ($bucket['effect'] ?? 3);
        $riskScore = round($probability * $effect, 2);
        $risk = reporting_risk_from_score($riskScore, (int) $bucket['count']);
        $sectorName = reporting_dominant_label($bucket['sectorCounts']);
        $scopeName = reporting_dominant_label($bucket['scopeCounts'], $sectorName !== '' ? $sectorName : 'Empresa');

        $items[] = [
            'questionId' => (int) $bucket['questionId'],
            'position' => (int) $bucket['position'],
            'text' => (string) $bucket['text'],
            'factorKey' => (string) $bucket['factorKey'],
            'factorName' => (string) $bucket['factorName'],
            'effect' => $effect,
            'effectDescription' => (string) $bucket['effectDescription'],
            'average' => $probability,
            'probability' => $probability,
            'riskScore' => $riskScore,
            'score' => reporting_score_to_percent($riskScore),
            'answerCount' => (int) $bucket['count'],
            'sectorName' => $sectorName !== '' ? $sectorName : 'Empresa',
            'scopeName' => $scopeName !== '' ? $scopeName : 'Empresa',
            'riskLabel' => $risk['label'],
            'riskSlug' => $risk['slug'],
            'pgrIncluded' => (bool) ($risk['includePgr'] ?? false),
            'pgrLabel' => (string) ($risk['pgrLabel'] ?? 'Sem dados'),
            'riskBand' => (string) ($risk['band'] ?? ''),
            'actionGuidance' => (string) ($risk['actionGuidance'] ?? ''),
        ];
    }

    usort($items, static function (array $left, array $right): int {
        if (($left['riskScore'] ?? 0) === ($right['riskScore'] ?? 0)) {
            return $left['position'] <=> $right['position'];
        }

        return ($right['riskScore'] ?? 0) <=> ($left['riskScore'] ?? 0);
    });

    return $items;
}

function reporting_build_applied_questions(array $questionRankings): array
{
    $items = $questionRankings;

    usort($items, static function (array $left, array $right): int {
        return ($left['position'] ?? 0) <=> ($right['position'] ?? 0);
    });

    return array_values($items);
}

function reporting_build_factor_results(array $questionRankings): array
{
    $buckets = [];

    foreach ($questionRankings as $item) {
        $factorKey = (string) ($item['factorKey'] ?? 'general');

        if (!isset($buckets[$factorKey])) {
            $buckets[$factorKey] = [
                'factorKey' => $factorKey,
                'factorName' => (string) ($item['factorName'] ?? 'Fator Psicossocial Geral'),
                'effect' => (int) ($item['effect'] ?? 3),
                'effectDescription' => (string) ($item['effectDescription'] ?? ''),
                'weightedProbability' => 0.0,
                'answerCount' => 0,
                'questionCount' => 0,
                'questions' => [],
                'scopeCounts' => [],
                'sectorCounts' => [],
                'topQuestionText' => (string) ($item['text'] ?? ''),
                'topRiskScore' => (float) ($item['riskScore'] ?? 0),
            ];
        }

        $answerCount = (int) ($item['answerCount'] ?? 0);
        $buckets[$factorKey]['weightedProbability'] += ((float) ($item['probability'] ?? 0)) * max($answerCount, 1);
        $buckets[$factorKey]['answerCount'] += $answerCount;
        $buckets[$factorKey]['questionCount']++;
        $buckets[$factorKey]['questions'][] = [
            'questionId' => (int) ($item['questionId'] ?? 0),
            'position' => (int) ($item['position'] ?? 0),
            'text' => (string) ($item['text'] ?? ''),
        ];
        $scopeName = (string) ($item['scopeName'] ?? 'Empresa');
        $sectorName = (string) ($item['sectorName'] ?? 'Empresa');
        $buckets[$factorKey]['scopeCounts'][$scopeName] = ($buckets[$factorKey]['scopeCounts'][$scopeName] ?? 0) + max($answerCount, 1);
        $buckets[$factorKey]['sectorCounts'][$sectorName] = ($buckets[$factorKey]['sectorCounts'][$sectorName] ?? 0) + max($answerCount, 1);

        if ((float) ($item['riskScore'] ?? 0) >= (float) $buckets[$factorKey]['topRiskScore']) {
            $buckets[$factorKey]['topRiskScore'] = (float) ($item['riskScore'] ?? 0);
            $buckets[$factorKey]['topQuestionText'] = (string) ($item['text'] ?? '');
        }
    }

    $results = [];

    foreach ($buckets as $bucket) {
        $answerCount = (int) ($bucket['answerCount'] ?? 0);
        $probability = $answerCount > 0
            ? round(((float) $bucket['weightedProbability']) / $answerCount, 2)
            : 0.0;
        $effect = (int) ($bucket['effect'] ?? 3);
        $riskScore = round($probability * $effect, 2);
        $risk = reporting_risk_from_score($riskScore, $answerCount);

        $results[] = [
            'factorKey' => (string) ($bucket['factorKey'] ?? 'general'),
            'factorName' => (string) ($bucket['factorName'] ?? 'Fator Psicossocial Geral'),
            'effect' => $effect,
            'effectDescription' => (string) ($bucket['effectDescription'] ?? ''),
            'probability' => $probability,
            'riskScore' => $riskScore,
            'riskIndex' => reporting_score_to_percent($riskScore),
            'answerCount' => $answerCount,
            'questionCount' => (int) ($bucket['questionCount'] ?? 0),
            'questions' => $bucket['questions'],
            'scopeName' => reporting_dominant_label($bucket['scopeCounts'], 'Empresa'),
            'sectorName' => reporting_dominant_label($bucket['sectorCounts'], 'Empresa'),
            'topQuestionText' => (string) ($bucket['topQuestionText'] ?? ''),
            'riskLabel' => (string) ($risk['label'] ?? 'Sem dados'),
            'riskSlug' => (string) ($risk['slug'] ?? 'neutral'),
            'pgrIncluded' => (bool) ($risk['includePgr'] ?? false),
            'pgrLabel' => (string) ($risk['pgrLabel'] ?? 'Sem dados'),
            'riskBand' => (string) ($risk['band'] ?? ''),
            'actionGuidance' => (string) ($risk['actionGuidance'] ?? ''),
        ];
    }

    usort($results, static function (array $left, array $right): int {
        return ($right['riskScore'] ?? 0) <=> ($left['riskScore'] ?? 0);
    });

    return $results;
}

function reporting_build_methodology(array $questionRankings): array
{
    $factorCatalog = [];

    foreach ($questionRankings as $item) {
        $factorKey = (string) ($item['factorKey'] ?? 'general');

        if (isset($factorCatalog[$factorKey])) {
            continue;
        }

        $factorCatalog[$factorKey] = [
            'factorKey' => $factorKey,
            'factorName' => (string) ($item['factorName'] ?? 'Fator Psicossocial Geral'),
            'effect' => (int) ($item['effect'] ?? 3),
            'effectDescription' => (string) ($item['effectDescription'] ?? ''),
        ];
    }

    usort($factorCatalog, static function (array $left, array $right): int {
        if (($right['effect'] ?? 0) === ($left['effect'] ?? 0)) {
            return strcmp((string) ($left['factorName'] ?? ''), (string) ($right['factorName'] ?? ''));
        }

        return ($right['effect'] ?? 0) <=> ($left['effect'] ?? 0);
    });

    return [
        'scale' => [
            ['value' => 1, 'label' => 'Nunca'],
            ['value' => 2, 'label' => 'Raramente'],
            ['value' => 3, 'label' => 'As vezes'],
            ['value' => 4, 'label' => 'Frequentemente'],
            ['value' => 5, 'label' => 'Sempre'],
        ],
        'formula' => [
            'A probabilidade corresponde a media aritmetica das respostas validas na escala de 1 a 5.',
            'O efeito e um valor fixo por fator psicossocial mapeado para cada pergunta.',
            'O resultado do risco e calculado por: probabilidade media x efeito.',
            'A classificacao final segue a matriz de risco com faixas baixo (1 a 6), moderado (7 a 14) e alto (15 a 25).',
        ],
        'pgrCriterion' => 'Itens classificados como risco moderado ou alto (resultado igual ou superior a 7) devem ser considerados para inclusao no PGR.',
        'matrix' => [
            [
                'range' => '1 a 6',
                'label' => 'Risco Baixo',
                'slug' => 'low',
                'pgrLabel' => 'Monitoramento interno',
            ],
            [
                'range' => '7 a 14',
                'label' => 'Risco Moderado',
                'slug' => 'medium',
                'pgrLabel' => 'Incluir no PGR',
            ],
            [
                'range' => '15 a 25',
                'label' => 'Risco Alto',
                'slug' => 'high',
                'pgrLabel' => 'Incluir no PGR',
            ],
        ],
        'factorCatalog' => array_values($factorCatalog),
    ];
}

function reporting_filter_catalog_by_scope(array $catalog, array $filters): array
{
    $selectedSectorIds = array_map('intval', $filters['sectorIds'] ?? []);
    $selectedSectorId = (int) ($filters['sectorId'] ?? 0);
    $selectedFunctionId = (int) ($filters['functionId'] ?? 0);

    if ($selectedFunctionId > 0) {
        foreach ($catalog['functions'] ?? [] as $companyFunction) {
            if ((int) ($companyFunction['id'] ?? 0) !== $selectedFunctionId) {
                continue;
            }

            $sectorId = (int) ($companyFunction['sectorId'] ?? 0);
            $filteredSectors = [];

            foreach ($catalog['sectors'] ?? [] as $sector) {
                if ((int) ($sector['id'] ?? 0) !== $sectorId) {
                    continue;
                }

                $sector['functions'] = array_values(array_filter(
                    $sector['functions'] ?? [],
                    static fn (array $item): bool => (int) ($item['id'] ?? 0) === $selectedFunctionId
                ));
                $filteredSectors[] = $sector;
                break;
            }

            return [
                'sectors' => $filteredSectors,
                'functions' => [$companyFunction],
            ];
        }
    }

    if ($selectedSectorId > 0) {
        $selectedSectorIds = [$selectedSectorId];
    }

    if ($selectedSectorIds === []) {
        return $catalog;
    }

    $filteredSectors = array_values(array_filter(
        $catalog['sectors'] ?? [],
        static fn (array $sector): bool => in_array((int) ($sector['id'] ?? 0), $selectedSectorIds, true)
    ));

    $filteredFunctions = array_values(array_filter(
        $catalog['functions'] ?? [],
        static fn (array $companyFunction): bool => in_array((int) ($companyFunction['sectorId'] ?? 0), $selectedSectorIds, true)
    ));

    return [
        'sectors' => $filteredSectors,
        'functions' => $filteredFunctions,
    ];
}

function reporting_build_sector_breakdown(array $catalog, array $answerRows): array
{
    $sectorBuckets = [];
    $functionBuckets = [];

    foreach ($answerRows as $row) {
        $sectorId = (int) ($row['resolved_sector_id'] ?? 0);
        $functionId = (int) ($row['function_id'] ?? 0);
        $answerValue = (int) ($row['answer_value'] ?? 0);
        $effect = (float) ($row['effect'] ?? reporting_question_profile((string) ($row['question_text'] ?? ''))['effect']);

        if ($sectorId > 0) {
            if (!isset($sectorBuckets[$sectorId])) {
                $sectorBuckets[$sectorId] = ['probabilitySum' => 0.0, 'effectSum' => 0.0, 'count' => 0];
            }

            $sectorBuckets[$sectorId]['probabilitySum'] += $answerValue;
            $sectorBuckets[$sectorId]['effectSum'] += $effect;
            $sectorBuckets[$sectorId]['count']++;
        }

        if ($functionId > 0) {
            if (!isset($functionBuckets[$functionId])) {
                $functionBuckets[$functionId] = ['probabilitySum' => 0.0, 'effectSum' => 0.0, 'count' => 0];
            }

            $functionBuckets[$functionId]['probabilitySum'] += $answerValue;
            $functionBuckets[$functionId]['effectSum'] += $effect;
            $functionBuckets[$functionId]['count']++;
        }
    }

    $sectors = [];

    foreach ($catalog['sectors'] as $sector) {
        $bucket = $sectorBuckets[(int) $sector['id']] ?? ['probabilitySum' => 0.0, 'effectSum' => 0.0, 'count' => 0];
        $average = $bucket['count'] > 0
            ? reporting_average((float) $bucket['probabilitySum'], (int) $bucket['count'])
            : 0.0;
        $effectAverage = $bucket['count'] > 0
            ? reporting_average((float) $bucket['effectSum'], (int) $bucket['count'])
            : 0.0;
        $riskScore = $bucket['count'] > 0 ? round($average * $effectAverage, 2) : 0.0;
        $risk = reporting_risk_from_score($riskScore, (int) ($bucket['count'] ?? 0));

        $functions = [];

        foreach ($sector['functions'] as $companyFunction) {
            $functionBucket = $functionBuckets[(int) $companyFunction['id']] ?? ['probabilitySum' => 0.0, 'effectSum' => 0.0, 'count' => 0];
            $functionAverage = $functionBucket['count'] > 0
                ? reporting_average((float) $functionBucket['probabilitySum'], (int) $functionBucket['count'])
                : 0.0;
            $functionEffectAverage = $functionBucket['count'] > 0
                ? reporting_average((float) $functionBucket['effectSum'], (int) $functionBucket['count'])
                : 0.0;
            $functionRiskScore = $functionBucket['count'] > 0
                ? round($functionAverage * $functionEffectAverage, 2)
                : 0.0;
            $functionRisk = reporting_risk_from_score($functionRiskScore, (int) ($functionBucket['count'] ?? 0));

            $functions[] = [
                'id' => (int) $companyFunction['id'],
                'name' => (string) $companyFunction['name'],
                'employees' => (int) $companyFunction['employees'],
                'average' => $functionAverage,
                'effectAverage' => $functionEffectAverage,
                'riskScore' => $functionRiskScore,
                'answerCount' => (int) ($functionBucket['count'] ?? 0),
                'riskLabel' => $functionRisk['label'],
                'riskSlug' => $functionRisk['slug'],
            ];
        }

        usort($functions, static function (array $left, array $right): int {
            return ($right['riskScore'] ?? 0) <=> ($left['riskScore'] ?? 0);
        });

        $sectors[] = [
            'id' => (int) $sector['id'],
            'name' => (string) $sector['name'],
            'employees' => (int) $sector['employees'],
            'average' => $average,
            'effectAverage' => $effectAverage,
            'riskScore' => $riskScore,
            'answerCount' => (int) ($bucket['count'] ?? 0),
            'riskLabel' => $risk['label'],
            'riskSlug' => $risk['slug'],
            'functions' => $functions,
        ];
    }

    usort($sectors, static function (array $left, array $right): int {
        return ($right['riskScore'] ?? 0) <=> ($left['riskScore'] ?? 0);
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

function reporting_build_heatmap_items(array $factorResults): array
{
    $items = [];

    foreach (array_slice($factorResults, 0, 5) as $index => $item) {
        $probability = max(1, min(5, (int) round((float) ($item['probability'] ?? 0))));
        $impact = max(1, min(5, (int) ($item['effect'] ?? 1)));
        $score = round((float) ($item['riskScore'] ?? 0), 2);

        $items[] = [
            'rank' => $index + 1,
            'factorKey' => (string) ($item['factorKey'] ?? 'general'),
            'text' => (string) ($item['factorName'] ?? 'Fator Psicossocial Geral'),
            'sectorName' => (string) (($item['scopeName'] ?? $item['sectorName'] ?? '') ?: 'Empresa'),
            'average' => (float) ($item['probability'] ?? 0),
            'probability' => $probability,
            'impact' => $impact,
            'score' => $score,
            'riskLabel' => (string) ($item['riskLabel'] ?? 'Sem dados'),
            'riskSlug' => (string) ($item['riskSlug'] ?? 'neutral'),
            'pgrLabel' => (string) ($item['pgrLabel'] ?? 'Sem dados'),
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

function reporting_recommendation_from_factor(string $factorKey, string $questionText = ''): string
{
    $normalizedKey = trim(strtolower($factorKey));

    return match ($normalizedKey) {
        'demands' => 'Revisar distribuicao de tarefas, prazos, volume operacional e pausas para reduzir sobrecarga percebida.',
        'recovery' => 'Fortalecer pausas, recuperacao e organizacao da jornada para evitar desgaste continuo.',
        'support' => 'Estruturar rotina de apoio, escuta e feedback com lideranca e equipe imediata.',
        'climate' => 'Promover alinhamento do clima, dialogo e mediacao de conflitos no ambiente de trabalho.',
        'clarity' => 'Reforcar prioridades, papeis e criterios de decisao para reduzir ambiguidades na execucao.',
        'engagement' => 'Criar acoes de engajamento, reconhecimento e reforco do sentido do trabalho no dia a dia.',
        default => reporting_recommendation_from_question($questionText),
    };
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

function reporting_build_action_plan(array $factorResults, string $selectedSectorLabel = ''): array
{
    $plan = [];
    $selectedSectorLabel = trim($selectedSectorLabel);

    foreach (array_slice($factorResults, 0, 4) as $item) {
        $riskSlug = (string) ($item['riskSlug'] ?? 'low');
        $priority = 'Monitorar';
        $prioritySlug = 'monitor';
        $deadline = '90 dias';

        if ($riskSlug === 'high') {
            $priority = 'Prioridade Alta';
            $prioritySlug = 'high';
            $deadline = '30 dias';
        } elseif ($riskSlug === 'medium') {
            $priority = 'Prioridade Média';
            $prioritySlug = 'medium';
            $deadline = '60 dias';
        }

        $plan[] = [
            'factor' => (string) ($item['factorName'] ?? 'Fator Psicossocial Geral'),
            'sectorName' => $selectedSectorLabel !== '' ? $selectedSectorLabel : (string) (($item['scopeName'] ?? $item['sectorName'] ?? '') ?: 'Empresa'),
            'recommendedAction' => reporting_recommendation_from_factor(
                (string) ($item['factorKey'] ?? 'general'),
                (string) ($item['topQuestionText'] ?? '')
            ),
            'deadline' => $deadline,
            'priorityLabel' => $priority,
            'prioritySlug' => $prioritySlug,
            'riskScore' => (float) ($item['riskScore'] ?? 0),
            'pgrLabel' => (string) ($item['pgrLabel'] ?? 'Sem dados'),
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
    $company = reporting_find_company($companies, (int) $filters['companyId']);
    $companyForms = reporting_fetch_company_forms($pdo, (int) $filters['companyId']);
    $selectedForm = reporting_resolve_form_filter($companyForms, $company, $input);
    $filters['formId'] = (int) ($selectedForm['id'] ?? 0) > 0 ? (int) $selectedForm['id'] : null;
    $filters['formLabel'] = (string) ($selectedForm['name'] ?? '');
    $filters['formCode'] = (string) ($selectedForm['publicCode'] ?? '');

    $catalog = reporting_fetch_company_catalog($pdo, (int) $filters['companyId']);
    $reportCatalog = reporting_filter_catalog_by_scope($catalog, $filters);
    $sessionRows = reporting_fetch_session_rows($pdo, $filters);
    $answerRows = reporting_fetch_answer_rows($pdo, $filters);
    $questionRankings = reporting_build_question_rankings($answerRows);
    $appliedQuestions = reporting_build_applied_questions($questionRankings);
    $factorResults = reporting_build_factor_results($questionRankings);
    $methodology = reporting_build_methodology($questionRankings);
    $summary = reporting_build_summary($sessionRows, $answerRows, $company, $questionRankings, $factorResults);
    $sectorBreakdown = reporting_build_sector_breakdown($reportCatalog, $answerRows);
    $heatmapItems = reporting_build_heatmap_items($factorResults);
    $savedActionPlan = reporting_fetch_saved_action_plan($pdo, $filters);
    $selectedSectorLabel = reporting_selected_sector_label($catalog, $filters);
    $actionPlan = $savedActionPlan !== [] ? $savedActionPlan : reporting_build_action_plan($factorResults, $selectedSectorLabel);
    $distribution = reporting_build_distribution($sectorBreakdown);
    $criticalSectors = array_values(array_filter($sectorBreakdown, static function (array $sector): bool {
        return in_array($sector['riskSlug'] ?? '', ['medium', 'high'], true);
    }));
    $pgrIncludedFactors = array_values(array_filter($factorResults, static function (array $factor): bool {
        return (bool) ($factor['pgrIncluded'] ?? false);
    }));

    $company['selectedFormId'] = (int) ($selectedForm['id'] ?? 0) > 0 ? (int) $selectedForm['id'] : null;
    $company['selectedFormName'] = (string) ($selectedForm['name'] ?? '') !== ''
        ? (string) $selectedForm['name']
        : (string) ($company['activeFormName'] ?? '');
    $company['selectedFormCode'] = (string) ($selectedForm['publicCode'] ?? '') !== ''
        ? (string) $selectedForm['publicCode']
        : (string) ($company['activeFormCode'] ?? '');

    return [
        'filters' => $filters,
        'company' => $company,
        'options' => [
            'companies' => $companies,
            'forms' => $companyForms,
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
            'answersCount' => (int) $summary['answersCount'],
            'riskIndex' => (int) $summary['riskIndex'],
            'riskLabel' => (string) $summary['riskLabel'],
            'riskSlug' => (string) $summary['riskSlug'],
            'averageProbability' => (float) $summary['average'],
            'averageEffect' => (float) $summary['effectAverage'],
            'riskScore' => (float) $summary['riskScore'],
            'pgrIncluded' => (bool) $summary['pgrIncluded'],
            'pgrLabel' => (string) $summary['pgrLabel'],
            'totalQuestions' => (int) $summary['totalQuestions'],
            'criticalSectorsCount' => count($criticalSectors),
            'pgrFactorsCount' => count($pgrIncludedFactors),
        ],
        'methodology' => $methodology,
        'appliedQuestions' => $appliedQuestions,
        'factorResults' => $factorResults,
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
        'sessionRows' => $sessionRows,
        'answerRows' => $answerRows,
    ];
}
