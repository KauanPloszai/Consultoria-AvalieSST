<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/reporting.php';

require_admin();

function dashboard_count_recent_sessions(array $payload, int $days = 7): int
{
    $threshold = strtotime('-' . max(1, $days) . ' days');

    return count(array_filter($payload['sessionRows'] ?? [], static function (array $row) use ($threshold): bool {
        $startedAt = strtotime((string) ($row['started_at'] ?? ''));
        return $startedAt !== false && $startedAt >= $threshold;
    }));
}

function dashboard_build_metric_hints(array $payload, int $activeCompanies, int $pendingActions): array
{
    $totalCompanies = count($payload['options']['companies'] ?? []);
    $completedSessions = (int) ($payload['summary']['completedSessions'] ?? 0);
    $totalSessions = (int) ($payload['summary']['totalSessions'] ?? 0);
    $recentSessions = dashboard_count_recent_sessions($payload, 7);
    $companyName = (string) ($payload['company']['name'] ?? 'a empresa');

    return [
        'activeCompanies' => $totalCompanies > 0
            ? sprintf('%d de %d empresas ativas no sistema.', $activeCompanies, $totalCompanies)
            : 'Nenhuma empresa cadastrada no sistema.',
        'evaluationsInProgress' => $recentSessions > 0
            ? sprintf('+ %d novas sessoes nos ultimos 7 dias.', $recentSessions)
            : 'Nenhuma nova sessao iniciada nos ultimos 7 dias.',
        'complianceRate' => $totalSessions > 0
            ? sprintf('%d concluidas de %d sessoes em %s.', $completedSessions, $totalSessions, $companyName)
            : sprintf('Sem sessoes respondidas em %s para o periodo selecionado.', $companyName),
        'pendingActions' => $pendingActions > 0
            ? sprintf('%d item(ns) com risco moderado ou alto requerem atencao.', $pendingActions)
            : 'Nenhuma acao critica pendente no momento.',
    ];
}

if (request_method() !== 'GET') {
    send_json(405, [
        'success' => false,
        'message' => 'Metodo nao permitido.',
    ]);
}

$pdo = db();
$payload = reporting_build_payload($pdo, $_GET);

$activeCompanies = 0;

foreach ($payload['options']['companies'] as $company) {
    if (($company['status'] ?? '') === 'active') {
        $activeCompanies++;
    }
}

$pendingActions = 0;

foreach ($payload['dashboardRows'] as $row) {
    if (in_array($row['riskSlug'] ?? '', ['medium', 'high'], true)) {
        $pendingActions++;
    }
}

$metricHints = dashboard_build_metric_hints($payload, $activeCompanies, $pendingActions);

send_json(200, [
    'success' => true,
    'data' => [
        'filters' => $payload['filters'],
        'company' => $payload['company'],
        'options' => $payload['options'],
        'metrics' => [
            'activeCompanies' => $activeCompanies,
            'evaluationsInProgress' => (int) $payload['summary']['pendingSessions'],
            'complianceRate' => (int) $payload['summary']['complianceRate'],
            'pendingActions' => $pendingActions,
            'hints' => $metricHints,
        ],
        'statusBreakdown' => $payload['statusBreakdown'],
        'completionSeries' => $payload['completionSeries'],
        'planRows' => $payload['dashboardRows'],
    ],
]);
