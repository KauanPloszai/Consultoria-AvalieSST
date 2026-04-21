<?php

declare(strict_types=1);

function send_json(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function read_json_input(): array
{
    $rawBody = file_get_contents('php://input');

    if ($rawBody === false || trim($rawBody) === '') {
        return [];
    }

    $decoded = json_decode($rawBody, true);

    if (!is_array($decoded)) {
        send_json(400, [
            'success' => false,
            'message' => 'Corpo JSON invalido.',
        ]);
    }

    return $decoded;
}

function current_user(): ?array
{
    $user = $_SESSION['user'] ?? null;

    if (!is_array($user)) {
        return null;
    }

    return [
        'id' => (int) ($user['id'] ?? 0),
        'name' => (string) ($user['name'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
        'role' => normalize_user_role((string) ($user['role'] ?? 'admin')),
        'companyId' => isset($user['companyId']) ? (int) $user['companyId'] : null,
        'companyName' => isset($user['companyName']) ? (string) $user['companyName'] : '',
    ];
}

function require_auth(): array
{
    $user = current_user();

    if ($user === null || $user['id'] <= 0) {
        send_json(401, [
            'success' => false,
            'message' => 'Sessao expirada. Faca login novamente.',
        ]);
    }

    return $user;
}

function normalize_user_role(?string $role): string
{
    return $role === 'company' ? 'company' : 'admin';
}

function is_admin_user(?array $user): bool
{
    return is_array($user) && normalize_user_role((string) ($user['role'] ?? 'admin')) === 'admin';
}

function is_company_user(?array $user): bool
{
    return is_array($user) && normalize_user_role((string) ($user['role'] ?? 'admin')) === 'company';
}

function require_role(array $allowedRoles): array
{
    $user = require_auth();
    $normalizedRoles = array_map('normalize_user_role', $allowedRoles);

    if (!in_array($user['role'], $normalizedRoles, true)) {
        send_json(403, [
            'success' => false,
            'message' => 'Voce nao tem permissao para acessar este recurso.',
        ]);
    }

    return $user;
}

function require_admin(): array
{
    return require_role(['admin']);
}

function require_company_scope(array $user, int $companyId, bool $allowEmpty = false): void
{
    if ($companyId <= 0) {
        if ($allowEmpty) {
            return;
        }

        send_json(422, [
            'success' => false,
            'message' => 'Empresa invalida.',
        ]);
    }

    if (is_admin_user($user)) {
        return;
    }

    $userCompanyId = (int) ($user['companyId'] ?? 0);

    if ($userCompanyId <= 0 || $userCompanyId !== $companyId) {
        send_json(403, [
            'success' => false,
            'message' => 'Voce so pode acessar os dados da empresa vinculada ao seu usuario.',
        ]);
    }
}

function resolve_company_scope_filter(array $user, int $companyId = 0): ?int
{
    if (is_admin_user($user)) {
        return $companyId > 0 ? $companyId : null;
    }

    $userCompanyId = (int) ($user['companyId'] ?? 0);

    if ($companyId > 0) {
        require_company_scope($user, $companyId);
    }

    return $userCompanyId > 0 ? $userCompanyId : null;
}

function user_role_label(string $role): string
{
    return normalize_user_role($role) === 'company' ? 'Empresa' : 'Admin';
}

function pbkdf2_hash(string $password, string $salt, int $iterations): string
{
    return hash_pbkdf2('sha256', $password, $salt, $iterations, 64);
}

function normalize_status(?string $status): string
{
    return $status === 'inactive' ? 'inactive' : 'active';
}

function normalize_string_list(array $items): array
{
    $normalized = [];

    foreach ($items as $item) {
        $value = trim((string) $item);

        if ($value === '' || in_array($value, $normalized, true)) {
            continue;
        }

        $normalized[] = $value;
    }

    return $normalized;
}

function code_segment(string $value, int $length = 4, string $fallback = 'AVL'): string
{
    $normalized = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

    if (!is_string($normalized) || $normalized === '') {
        $normalized = $value;
    }

    $normalized = strtoupper(preg_replace('/[^A-Z0-9]+/', '', strtoupper($normalized)) ?? '');

    if ($normalized === '') {
        $normalized = strtoupper($fallback);
    }

    return substr($normalized, 0, max(1, $length));
}

function random_alphanumeric(int $length = 4): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $maxIndex = strlen($alphabet) - 1;
    $result = '';

    for ($index = 0; $index < $length; $index++) {
        $result .= $alphabet[random_int(0, $maxIndex)];
    }

    return $result;
}

function generate_access_code(PDO $pdo, string $companyName, string $scopeLabel): string
{
    $companyPrefix = code_segment($companyName, 4, 'COMP');
    $scopePrefix = code_segment($scopeLabel, 3, 'GLB');
    $year = date('Y');

    do {
        $code = sprintf('%s-%s-%s-%s', $companyPrefix, $scopePrefix, random_alphanumeric(4), $year);
        $statement = $pdo->prepare('SELECT id FROM employee_access_codes WHERE code = :code LIMIT 1');
        $statement->execute(['code' => $code]);
        $exists = $statement->fetch();
    } while ($exists);

    return $code;
}

function generate_access_session_public_id(PDO $pdo): string
{
    $lastId = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM access_code_sessions')->fetchColumn();
    return sprintf('SES-%04d', $lastId + 1001);
}

function generate_access_link_token(PDO $pdo): string
{
    do {
        $token = strtolower(bin2hex(random_bytes(12)));
        $statement = $pdo->prepare('SELECT id FROM employee_access_codes WHERE access_link_token = :token LIMIT 1');
        $statement->execute(['token' => $token]);
        $exists = $statement->fetch();
    } while ($exists);

    return $token;
}

function client_ip_address(): string
{
    $candidates = [
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || trim($candidate) === '') {
            continue;
        }

        $firstCandidate = trim(explode(',', $candidate)[0]);

        if ($firstCandidate !== '') {
            return $firstCandidate;
        }
    }

    return '0.0.0.0';
}

function mask_ip_address(string $ipAddress): string
{
    if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $segments = explode(':', $ipAddress);
        $visible = array_slice($segments, 0, 2);
        return implode(':', $visible) . ':****';
    }

    if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $segments = explode('.', $ipAddress);

        if (count($segments) === 4) {
            return sprintf('%s.%s.**.***', $segments[0], $segments[1]);
        }
    }

    return 'IP oculto';
}

function access_status_label(string $status): string
{
    return $status === 'done' ? 'Concluida' : 'Em Andamento';
}

function current_scheme(): string
{
    $https = $_SERVER['HTTPS'] ?? '';
    return ($https === 'on' || $https === '1') ? 'https' : 'http';
}

function app_base_path(): string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

    if ($scriptName === '') {
        return '';
    }

    $basePath = dirname(dirname($scriptName));

    if ($basePath === '.' || $basePath === '/' || $basePath === '\\') {
        return '';
    }

    return rtrim(str_replace('\\', '/', $basePath), '/');
}

function app_base_url(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return current_scheme() . '://' . $host . app_base_path();
}

function build_employee_access_url(string $accessLinkToken): string
{
    return app_base_url() . '/acesso-funcionario.html?token=' . rawurlencode($accessLinkToken);
}

function answer_average_to_percent(float $average): int
{
    return (int) max(0, min(100, round(($average / 5) * 100)));
}

function risk_level_from_average(float $average): array
{
    if ($average >= 4.2) {
        return [
            'label' => 'Risco Alto',
            'slug' => 'high',
            'color' => '#ef5656',
        ];
    }

    if ($average >= 3.2) {
        return [
            'label' => 'Risco Moderado',
            'slug' => 'medium',
            'color' => '#f4a31d',
        ];
    }

    return [
        'label' => 'Risco Baixo',
        'slug' => 'low',
        'color' => '#18a35d',
    ];
}
