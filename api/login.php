<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (request_method() !== 'POST') {
    send_json(405, [
        'success' => false,
        'message' => 'Metodo nao permitido.',
    ]);
}

$input = read_json_input();
$email = strtolower(trim((string) ($input['email'] ?? '')));
$password = trim((string) ($input['password'] ?? ''));

if ($email === '' || $password === '') {
    send_json(422, [
        'success' => false,
        'message' => 'Preencha e-mail e senha para continuar.',
    ]);
}

$statement = db()->prepare(
    'SELECT u.id,
            u.name,
            u.email,
            u.role,
            u.company_id,
            u.password_hash,
            u.password_salt,
            u.password_iterations,
            u.is_active,
            c.name AS company_name
     FROM users
     u
     LEFT JOIN companies c ON c.id = u.company_id
     WHERE u.email = :email
     LIMIT 1'
);
$statement->execute(['email' => $email]);
$user = $statement->fetch();

if (!$user || (int) $user['is_active'] !== 1) {
    send_json(401, [
        'success' => false,
        'message' => 'Credenciais invalidas.',
    ]);
}

$calculatedHash = pbkdf2_hash(
    $password,
    (string) $user['password_salt'],
    (int) $user['password_iterations']
);

if (!hash_equals((string) $user['password_hash'], $calculatedHash)) {
    send_json(401, [
        'success' => false,
        'message' => 'Credenciais invalidas.',
    ]);
}

session_regenerate_id(true);

$_SESSION['user'] = [
    'id' => (int) $user['id'],
    'name' => (string) $user['name'],
    'email' => (string) $user['email'],
    'role' => normalize_user_role((string) ($user['role'] ?? 'admin')),
    'companyId' => $user['company_id'] !== null ? (int) $user['company_id'] : null,
    'companyName' => (string) ($user['company_name'] ?? ''),
];

send_json(200, [
    'success' => true,
    'message' => 'Login realizado com sucesso.',
    'data' => current_user(),
]);
