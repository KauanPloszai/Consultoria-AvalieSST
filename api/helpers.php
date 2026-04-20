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
