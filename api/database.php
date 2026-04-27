<?php

declare(strict_types=1);

function schema_table_exists(PDO $pdo, string $tableName): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = :table_name'
    );
    $statement->execute(['table_name' => $tableName]);

    return (int) $statement->fetchColumn() > 0;
}

function schema_column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND column_name = :column_name'
    );
    $statement->execute([
        'table_name' => $tableName,
        'column_name' => $columnName,
    ]);

    return (int) $statement->fetchColumn() > 0;
}

function schema_index_exists(PDO $pdo, string $tableName, string $indexName): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND index_name = :index_name'
    );
    $statement->execute([
        'table_name' => $tableName,
        'index_name' => $indexName,
    ]);

    return (int) $statement->fetchColumn() > 0;
}

function schema_drop_index(PDO $pdo, string $tableName, string $indexName): void
{
    if (!schema_index_exists($pdo, $tableName, $indexName)) {
        return;
    }

    $pdo->exec(sprintf('ALTER TABLE `%s` DROP INDEX `%s`', $tableName, $indexName));
}

function schema_ensure_column(PDO $pdo, string $tableName, string $columnName, string $definition): void
{
    if (schema_column_exists($pdo, $tableName, $columnName)) {
        return;
    }

    try {
        $pdo->exec(sprintf('ALTER TABLE `%s` ADD COLUMN %s', $tableName, $definition));
    } catch (PDOException $exception) {
        $errorCode = (int) ($exception->errorInfo[1] ?? 0);

        if ($errorCode === 1060 || schema_column_exists($pdo, $tableName, $columnName)) {
            return;
        }

        throw $exception;
    }
}

function schema_ensure_index(PDO $pdo, string $tableName, string $indexName, string $columns, bool $unique = false): void
{
    if (schema_index_exists($pdo, $tableName, $indexName)) {
        return;
    }

    $uniqueSql = $unique ? 'UNIQUE ' : '';
    try {
        $pdo->exec(sprintf(
            'ALTER TABLE `%s` ADD %sINDEX `%s` (%s)',
            $tableName,
            $uniqueSql,
            $indexName,
            $columns
        ));
    } catch (PDOException $exception) {
        $errorCode = (int) ($exception->errorInfo[1] ?? 0);

        if ($errorCode === 1061 || schema_index_exists($pdo, $tableName, $indexName)) {
            return;
        }

        throw $exception;
    }
}

function migrate_schema(PDO $pdo): void
{
    static $alreadyMigrated = false;

    if ($alreadyMigrated) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS company_functions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id INT UNSIGNED NOT NULL,
            sector_id INT UNSIGNED NOT NULL,
            function_name VARCHAR(160) NOT NULL,
            employees_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_company_functions_company
              FOREIGN KEY (company_id) REFERENCES companies(id)
              ON DELETE CASCADE,
            CONSTRAINT fk_company_functions_sector
              FOREIGN KEY (sector_id) REFERENCES company_sectors(id)
              ON DELETE CASCADE,
            UNIQUE KEY uniq_company_sector_function (sector_id, function_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS company_form_links (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id INT UNSIGNED NOT NULL,
            form_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_company_form_links_company
              FOREIGN KEY (company_id) REFERENCES companies(id)
              ON DELETE CASCADE,
            CONSTRAINT fk_company_form_links_form
              FOREIGN KEY (form_id) REFERENCES forms(id)
              ON DELETE CASCADE,
            UNIQUE KEY uniq_company_form_link (company_id, form_id),
            KEY idx_company_form_links_company (company_id),
            KEY idx_company_form_links_form (form_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS action_plan_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id INT UNSIGNED NOT NULL,
            sector_id INT UNSIGNED NULL,
            function_id INT UNSIGNED NULL,
            factor VARCHAR(255) NOT NULL,
            action_text TEXT NOT NULL,
            deadline VARCHAR(120) NOT NULL,
            status_slug VARCHAR(32) NOT NULL DEFAULT "todo",
            responsible VARCHAR(160) NOT NULL DEFAULT "",
            notes TEXT NULL,
            position INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_action_plan_items_company
              FOREIGN KEY (company_id) REFERENCES companies(id)
              ON DELETE CASCADE,
            CONSTRAINT fk_action_plan_items_sector
              FOREIGN KEY (sector_id) REFERENCES company_sectors(id)
              ON DELETE CASCADE,
            CONSTRAINT fk_action_plan_items_function
              FOREIGN KEY (function_id) REFERENCES company_functions(id)
              ON DELETE CASCADE,
            KEY idx_action_plan_company_scope (company_id, sector_id, function_id),
            KEY idx_action_plan_position (position)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    schema_ensure_column(
        $pdo,
        'users',
        'role',
        "`role` ENUM('admin', 'company') NOT NULL DEFAULT 'admin' AFTER `email`"
    );
    schema_ensure_column(
        $pdo,
        'users',
        'company_id',
        '`company_id` INT UNSIGNED NULL AFTER `role`'
    );
    schema_ensure_index($pdo, 'users', 'idx_users_role', '`role`');
    schema_ensure_index($pdo, 'users', 'idx_users_company', '`company_id`');

    $pdo->exec(
        "UPDATE users
         SET role = 'admin'
         WHERE role IS NULL
            OR role NOT IN ('admin', 'company')"
    );

    schema_ensure_column(
        $pdo,
        'companies',
        'active_form_id',
        '`active_form_id` INT UNSIGNED NULL AFTER `employees_count`'
    );
    schema_ensure_index($pdo, 'companies', 'idx_companies_active_form', '`active_form_id`');
    schema_ensure_column(
        $pdo,
        'companies',
        'cep',
        '`cep` VARCHAR(20) NOT NULL DEFAULT "" AFTER `cnpj`'
    );
    schema_ensure_column(
        $pdo,
        'companies',
        'street',
        '`street` VARCHAR(180) NOT NULL DEFAULT "" AFTER `cep`'
    );
    schema_ensure_column(
        $pdo,
        'companies',
        'street_number',
        '`street_number` VARCHAR(30) NOT NULL DEFAULT "" AFTER `street`'
    );

    schema_ensure_column(
        $pdo,
        'company_sectors',
        'employees_count',
        '`employees_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `sector_name`'
    );
    schema_ensure_column(
        $pdo,
        'company_sectors',
        'updated_at',
        '`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`'
    );

    schema_ensure_column(
        $pdo,
        'employee_access_codes',
        'scope_type',
        "`scope_type` ENUM('global', 'sector', 'function') NOT NULL DEFAULT 'global' AFTER `form_id`"
    );
    schema_ensure_column(
        $pdo,
        'employee_access_codes',
        'sector_id',
        '`sector_id` INT UNSIGNED NULL AFTER `scope_type`'
    );
    schema_ensure_column(
        $pdo,
        'employee_access_codes',
        'function_id',
        '`function_id` INT UNSIGNED NULL AFTER `sector_id`'
    );
    schema_ensure_column(
        $pdo,
        'employee_access_codes',
        'access_link_token',
        '`access_link_token` VARCHAR(64) NULL AFTER `updated_at`'
    );
    schema_ensure_index($pdo, 'employee_access_codes', 'idx_access_codes_company_active', '`company_id`, `is_active`');
    schema_ensure_index($pdo, 'employee_access_codes', 'idx_access_codes_scope_sector', '`sector_id`');
    schema_ensure_index($pdo, 'employee_access_codes', 'idx_access_codes_scope_function', '`function_id`');

    if (schema_index_exists($pdo, 'employee_access_codes', 'uniq_access_link_token')) {
        schema_drop_index($pdo, 'employee_access_codes', 'uniq_access_link_token');
    }

    schema_ensure_index($pdo, 'employee_access_codes', 'idx_access_link_token', '`access_link_token`');

    schema_ensure_column(
        $pdo,
        'access_code_sessions',
        'completed_at',
        '`completed_at` DATETIME NULL AFTER `updated_at`'
    );

    $pdo->exec(
        "UPDATE access_code_sessions
         SET completed_at = updated_at
         WHERE status = 'done'
           AND completed_at IS NULL"
    );

    $missingTokenStatement = $pdo->query(
        "SELECT id
         FROM employee_access_codes
         WHERE access_link_token IS NULL OR access_link_token = ''"
    );

    if ($missingTokenStatement !== false) {
        $updateTokenStatement = $pdo->prepare(
            'UPDATE employee_access_codes
             SET access_link_token = :token
             WHERE id = :id'
        );

        foreach ($missingTokenStatement->fetchAll(PDO::FETCH_COLUMN) as $accessCodeId) {
            $updateTokenStatement->execute([
                'token' => generate_access_link_token($pdo),
                'id' => (int) $accessCodeId,
            ]);
        }
    }

    $companiesWithoutForm = $pdo->query(
        'SELECT c.id
         FROM companies c
         WHERE c.active_form_id IS NULL'
    );

    if ($companiesWithoutForm !== false) {
        $lastAccessCodeStatement = $pdo->prepare(
            'SELECT form_id
             FROM employee_access_codes
             WHERE company_id = :company_id
             ORDER BY is_active DESC, updated_at DESC, id DESC
             LIMIT 1'
        );
        $updateCompanyFormStatement = $pdo->prepare(
            'UPDATE companies
             SET active_form_id = :form_id,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :company_id'
        );

        foreach ($companiesWithoutForm->fetchAll(PDO::FETCH_COLUMN) as $companyId) {
            $lastAccessCodeStatement->execute(['company_id' => (int) $companyId]);
            $formId = (int) $lastAccessCodeStatement->fetchColumn();

            if ($formId <= 0) {
                continue;
            }

            $updateCompanyFormStatement->execute([
                'form_id' => $formId,
                'company_id' => (int) $companyId,
            ]);
        }
    }

    $activeCompanyFormStatement = $pdo->query(
        'SELECT id AS company_id, active_form_id AS form_id
         FROM companies
         WHERE active_form_id IS NOT NULL'
    );

    if ($activeCompanyFormStatement !== false) {
        $insertCompanyFormLink = $pdo->prepare(
            'INSERT INTO company_form_links (company_id, form_id)
             VALUES (:company_id, :form_id)
             ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP'
        );

        foreach ($activeCompanyFormStatement->fetchAll() as $row) {
            $insertCompanyFormLink->execute([
                'company_id' => (int) $row['company_id'],
                'form_id' => (int) $row['form_id'],
            ]);
        }
    }

    $historicalCompanyFormStatement = $pdo->query(
        'SELECT DISTINCT company_id, form_id
         FROM employee_access_codes
         WHERE company_id IS NOT NULL
           AND form_id IS NOT NULL'
    );

    if ($historicalCompanyFormStatement !== false) {
        $insertHistoricalCompanyFormLink = $pdo->prepare(
            'INSERT INTO company_form_links (company_id, form_id)
             VALUES (:company_id, :form_id)
             ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP'
        );

        foreach ($historicalCompanyFormStatement->fetchAll() as $row) {
            $insertHistoricalCompanyFormLink->execute([
                'company_id' => (int) $row['company_id'],
                'form_id' => (int) $row['form_id'],
            ]);
        }
    }

    $alreadyMigrated = true;
}

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

    migrate_schema($pdo);

    return $pdo;
}
