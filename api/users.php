<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

function users_fetch_companies(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT id, name
         FROM companies
         ORDER BY name ASC'
    );

    $companies = [];

    foreach ($statement->fetchAll() as $row) {
        $companies[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
        ];
    }

    return $companies;
}

function users_fetch_all(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT u.id,
                u.name,
                u.email,
                u.role,
                u.company_id,
                u.is_active,
                u.created_at,
                c.name AS company_name
         FROM users u
         LEFT JOIN companies c ON c.id = u.company_id
         ORDER BY u.created_at DESC, u.id DESC'
    );

    $users = [];

    foreach ($statement->fetchAll() as $row) {
        $users[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
            'role' => normalize_user_role((string) ($row['role'] ?? 'admin')),
            'roleLabel' => user_role_label((string) ($row['role'] ?? 'admin')),
            'companyId' => $row['company_id'] !== null ? (int) $row['company_id'] : null,
            'companyName' => (string) ($row['company_name'] ?? ''),
            'isActive' => (int) ($row['is_active'] ?? 0) === 1,
            'createdAt' => substr((string) ($row['created_at'] ?? ''), 0, 10),
        ];
    }

    return $users;
}

function users_dashboard(PDO $pdo): array
{
    return [
        'users' => users_fetch_all($pdo),
        'companies' => users_fetch_companies($pdo),
    ];
}

function users_guess_name_from_email(string $email): string
{
    $localPart = trim((string) strstr($email, '@', true));
    $normalized = preg_replace('/[^a-z0-9]+/i', ' ', $localPart) ?? $localPart;
    $normalized = trim($normalized);

    if ($normalized === '') {
        return 'Usuario';
    }

    return ucwords(strtolower($normalized));
}

function users_hash_password(string $password): array
{
    $iterations = 120000;
    $salt = bin2hex(random_bytes(16));

    return [
        'passwordHash' => pbkdf2_hash($password, $salt, $iterations),
        'passwordSalt' => $salt,
        'passwordIterations' => $iterations,
    ];
}

function users_validate_payload(array $input, bool $isUpdate = false): array
{
    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $password = trim((string) ($input['password'] ?? ''));
    $role = normalize_user_role((string) ($input['role'] ?? 'admin'));
    $companyId = (int) ($input['companyId'] ?? 0);
    $isActive = array_key_exists('isActive', $input) ? (bool) $input['isActive'] : true;

    if ($email === '') {
        send_json(422, [
            'success' => false,
            'message' => 'Informe o e-mail do usuario.',
        ]);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        send_json(422, [
            'success' => false,
            'message' => 'Informe um e-mail valido.',
        ]);
    }

    if (!$isUpdate && $password === '') {
        send_json(422, [
            'success' => false,
            'message' => 'Informe a senha do usuario.',
        ]);
    }

    if ($role === 'company' && $companyId <= 0) {
        send_json(422, [
            'success' => false,
            'message' => 'Selecione a empresa vinculada para o usuario do tipo empresa.',
        ]);
    }

    return [
        'email' => $email,
        'password' => $password,
        'role' => $role,
        'companyId' => $role === 'company' && $companyId > 0 ? $companyId : null,
        'isActive' => $isActive,
    ];
}

function users_ensure_company_exists(PDO $pdo, ?int $companyId): void
{
    if ($companyId === null || $companyId <= 0) {
        return;
    }

    $statement = $pdo->prepare(
        'SELECT id
         FROM companies
         WHERE id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $companyId]);

    if (!$statement->fetch()) {
        send_json(404, [
            'success' => false,
            'message' => 'Empresa vinculada nao encontrada.',
        ]);
    }
}

function users_ensure_unique_email(PDO $pdo, string $email, ?int $ignoreUserId = null): void
{
    if ($ignoreUserId === null) {
        $statement = $pdo->prepare(
            'SELECT id
             FROM users
             WHERE email = :email
             LIMIT 1'
        );
        $statement->execute(['email' => $email]);
    } else {
        $statement = $pdo->prepare(
            'SELECT id
             FROM users
             WHERE email = :email
               AND id <> :ignore_id
             LIMIT 1'
        );
        $statement->execute([
            'email' => $email,
            'ignore_id' => $ignoreUserId,
        ]);
    }

    if ($statement->fetch()) {
        send_json(422, [
            'success' => false,
            'message' => 'Ja existe um usuario cadastrado com este e-mail.',
        ]);
    }
}

function users_fetch_existing(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare(
        'SELECT id, email, role, company_id
         FROM users
         WHERE id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $userId]);
    $row = $statement->fetch();

    if (!$row) {
        send_json(404, [
            'success' => false,
            'message' => 'Usuario nao encontrado.',
        ]);
    }

    return $row;
}

$method = request_method();
$pdo = db();
$currentUser = require_admin();

if ($method === 'GET') {
    send_json(200, [
        'success' => true,
        'data' => users_dashboard($pdo),
    ]);
}

$input = read_json_input();

if ($method === 'POST') {
    $payload = users_validate_payload($input, false);
    users_ensure_company_exists($pdo, $payload['companyId']);
    users_ensure_unique_email($pdo, $payload['email']);
    $passwordData = users_hash_password($payload['password']);
    $name = users_guess_name_from_email($payload['email']);

    $statement = $pdo->prepare(
        'INSERT INTO users (
             name,
             email,
             role,
             company_id,
             password_hash,
             password_salt,
             password_iterations,
             is_active
         ) VALUES (
             :name,
             :email,
             :role,
             :company_id,
             :password_hash,
             :password_salt,
             :password_iterations,
             :is_active
         )'
    );
    $statement->bindValue('name', $name, PDO::PARAM_STR);
    $statement->bindValue('email', $payload['email'], PDO::PARAM_STR);
    $statement->bindValue('role', $payload['role'], PDO::PARAM_STR);
    $statement->bindValue('password_hash', $passwordData['passwordHash'], PDO::PARAM_STR);
    $statement->bindValue('password_salt', $passwordData['passwordSalt'], PDO::PARAM_STR);
    $statement->bindValue('password_iterations', $passwordData['passwordIterations'], PDO::PARAM_INT);
    $statement->bindValue('is_active', $payload['isActive'] ? 1 : 0, PDO::PARAM_INT);

    if ($payload['companyId'] === null) {
        $statement->bindValue('company_id', null, PDO::PARAM_NULL);
    } else {
        $statement->bindValue('company_id', $payload['companyId'], PDO::PARAM_INT);
    }

    $statement->execute();

    send_json(201, [
        'success' => true,
        'message' => 'Usuario criado com sucesso.',
        'data' => users_dashboard($pdo),
    ]);
}

if ($method === 'PUT') {
    $userId = (int) ($input['id'] ?? 0);

    if ($userId <= 0) {
        send_json(422, [
            'success' => false,
            'message' => 'Usuario invalido.',
        ]);
    }

    users_fetch_existing($pdo, $userId);
    $payload = users_validate_payload($input, true);
    users_ensure_company_exists($pdo, $payload['companyId']);
    users_ensure_unique_email($pdo, $payload['email'], $userId);

    $sql = 'UPDATE users
            SET name = :name,
                email = :email,
                role = :role,
                company_id = :company_id,
                is_active = :is_active,
                updated_at = CURRENT_TIMESTAMP';
    $params = [
        'id' => $userId,
        'name' => users_guess_name_from_email($payload['email']),
        'email' => $payload['email'],
        'role' => $payload['role'],
        'is_active' => $payload['isActive'] ? 1 : 0,
    ];

    if ($payload['password'] !== '') {
        $passwordData = users_hash_password($payload['password']);
        $sql .= ',
                password_hash = :password_hash,
                password_salt = :password_salt,
                password_iterations = :password_iterations';
        $params['password_hash'] = $passwordData['passwordHash'];
        $params['password_salt'] = $passwordData['passwordSalt'];
        $params['password_iterations'] = $passwordData['passwordIterations'];
    }

    $sql .= ' WHERE id = :id';

    $statement = $pdo->prepare($sql);
    $statement->bindValue('id', $params['id'], PDO::PARAM_INT);
    $statement->bindValue('name', $params['name'], PDO::PARAM_STR);
    $statement->bindValue('email', $params['email'], PDO::PARAM_STR);
    $statement->bindValue('role', $params['role'], PDO::PARAM_STR);
    $statement->bindValue('is_active', $params['is_active'], PDO::PARAM_INT);

    if ($payload['companyId'] === null) {
        $statement->bindValue('company_id', null, PDO::PARAM_NULL);
    } else {
        $statement->bindValue('company_id', $payload['companyId'], PDO::PARAM_INT);
    }

    if (isset($params['password_hash'])) {
        $statement->bindValue('password_hash', $params['password_hash'], PDO::PARAM_STR);
        $statement->bindValue('password_salt', $params['password_salt'], PDO::PARAM_STR);
        $statement->bindValue('password_iterations', $params['password_iterations'], PDO::PARAM_INT);
    }

    $statement->execute();

    send_json(200, [
        'success' => true,
        'message' => 'Usuario atualizado com sucesso.',
        'data' => users_dashboard($pdo),
    ]);
}

if ($method === 'DELETE') {
    $userId = (int) ($_GET['id'] ?? 0);

    if ($userId <= 0) {
        send_json(422, [
            'success' => false,
            'message' => 'Usuario invalido.',
        ]);
    }

    if ($userId === (int) ($currentUser['id'] ?? 0)) {
        send_json(422, [
            'success' => false,
            'message' => 'Voce nao pode excluir o usuario atualmente logado.',
        ]);
    }

    users_fetch_existing($pdo, $userId);

    $statement = $pdo->prepare(
        'DELETE FROM users
         WHERE id = :id'
    );
    $statement->execute(['id' => $userId]);

    send_json(200, [
        'success' => true,
        'message' => 'Usuario removido com sucesso.',
        'data' => users_dashboard($pdo),
    ]);
}

send_json(405, [
    'success' => false,
    'message' => 'Metodo nao permitido.',
]);
