<?php

declare(strict_types=1);

function db(): PDO
{
    global $config;

    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $database = $config['db'] ?? [];
    $host = $database['host'] ?? '127.0.0.1';
    $port = $database['port'] ?? '3306';
    $name = $database['name'] ?? 'avaliiesst';
    $user = $database['user'] ?? 'root';
    $pass = $database['pass'] ?? '';

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $exception) {
        send_json(500, [
            'success' => false,
            'message' => 'Nao foi possivel conectar ao MySQL. Revise o arquivo config.php.',
        ]);
    }

    return $pdo;
}
