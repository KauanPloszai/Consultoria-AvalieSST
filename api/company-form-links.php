<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

require_admin();

function fetch_link_modal_companies(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT c.id, c.name, c.active_form_id,
                f.public_code AS active_form_code,
                f.name AS active_form_name
         FROM companies c
         LEFT JOIN forms f ON f.id = c.active_form_id
         ORDER BY c.created_at DESC, c.id DESC'
    );

    $companies = [];
    $companyIds = [];

    foreach ($statement->fetchAll() as $row) {
        $companyId = (int) $row['id'];
        $companyIds[] = $companyId;
        $companies[$companyId] = [
            'id' => $companyId,
            'name' => (string) $row['name'],
            'activeFormId' => $row['active_form_id'] !== null ? (int) $row['active_form_id'] : null,
            'activeFormCode' => (string) ($row['active_form_code'] ?? ''),
            'activeFormName' => (string) ($row['active_form_name'] ?? ''),
            'linkedForms' => [],
        ];
    }

    if ($companyIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($companyIds), '?'));
        $linksStatement = $pdo->prepare(
            "SELECT l.company_id, f.id AS form_id, f.public_code, f.name, f.status
             FROM company_form_links l
             INNER JOIN forms f ON f.id = l.form_id
             WHERE l.company_id IN ($placeholders)
             ORDER BY f.name ASC, f.id ASC"
        );
        $linksStatement->execute($companyIds);

        foreach ($linksStatement->fetchAll() as $row) {
            $companyId = (int) $row['company_id'];

            if (!isset($companies[$companyId])) {
                continue;
            }

            $companies[$companyId]['linkedForms'][] = [
                'id' => (int) $row['form_id'],
                'publicCode' => (string) ($row['public_code'] ?? ''),
                'name' => (string) $row['name'],
                'status' => normalize_status((string) ($row['status'] ?? 'inactive')),
            ];
        }
    }

    foreach ($companies as $companyId => $company) {
        $companies[$companyId]['linkedFormsCount'] = count($company['linkedForms']);
    }

    return array_values($companies);
}

function fetch_link_modal_forms(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT id, public_code, name, status
         FROM forms
         ORDER BY created_at DESC, id DESC'
    );

    $forms = [];

    foreach ($statement->fetchAll() as $row) {
        $forms[] = [
            'id' => (int) $row['id'],
            'publicCode' => (string) ($row['public_code'] ?? ''),
            'name' => (string) $row['name'],
            'status' => normalize_status((string) ($row['status'] ?? 'inactive')),
        ];
    }

    return $forms;
}

function fetch_link_modal_rows(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT l.id,
                c.id AS company_id,
                c.name AS company_name,
                c.active_form_id,
                f.id AS form_id,
                f.public_code,
                f.name AS form_name
         FROM company_form_links l
         INNER JOIN companies c ON c.id = l.company_id
         INNER JOIN forms f ON f.id = l.form_id
         ORDER BY c.name ASC, f.name ASC, l.id ASC'
    );

    $links = [];

    foreach ($statement->fetchAll() as $row) {
        $links[] = [
            'id' => (int) $row['id'],
            'companyId' => (int) $row['company_id'],
            'companyName' => (string) $row['company_name'],
            'formId' => (int) $row['form_id'],
            'formCode' => (string) ($row['public_code'] ?? ''),
            'formName' => (string) $row['form_name'],
            'isPrimary' => (int) ($row['active_form_id'] ?? 0) === (int) $row['form_id'],
        ];
    }

    return $links;
}

function fetch_company_form_link_dashboard(PDO $pdo): array
{
    return [
        'companies' => fetch_link_modal_companies($pdo),
        'forms' => fetch_link_modal_forms($pdo),
        'links' => fetch_link_modal_rows($pdo),
    ];
}

function parse_company_form_link_payload(array $input): array
{
    $companyId = (int) ($input['companyId'] ?? 0);
    $formId = (int) ($input['formId'] ?? 0);

    if ($companyId <= 0) {
        send_json(422, [
            'success' => false,
            'message' => 'Selecione a empresa para salvar o vinculo.',
        ]);
    }

    if ($formId <= 0) {
        send_json(422, [
            'success' => false,
            'message' => 'Selecione o formulario que sera liberado.',
        ]);
    }

    return [
        'companyId' => $companyId,
        'formId' => $formId,
    ];
}

function ensure_link_company_exists(PDO $pdo, int $companyId): array
{
    $statement = $pdo->prepare(
        'SELECT id, name, active_form_id
         FROM companies
         WHERE id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $companyId]);
    $row = $statement->fetch();

    if (!$row) {
        send_json(404, [
            'success' => false,
            'message' => 'Empresa nao encontrada.',
        ]);
    }

    return $row;
}

function ensure_link_form_exists(PDO $pdo, int $formId): array
{
    $statement = $pdo->prepare(
        'SELECT id, public_code, name, status
         FROM forms
         WHERE id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $formId]);
    $row = $statement->fetch();

    if (!$row) {
        send_json(404, [
            'success' => false,
            'message' => 'Formulario nao encontrado.',
        ]);
    }

    if (normalize_status((string) ($row['status'] ?? 'inactive')) === 'inactive') {
        send_json(422, [
            'success' => false,
            'message' => 'Este formulario esta inativo. Reative-o antes de vincular a empresa.',
        ]);
    }

    return $row;
}

function sync_company_active_form(PDO $pdo, int $companyId, ?int $preferredFormId = null): void
{
    $nextFormId = null;

    if ($preferredFormId !== null && $preferredFormId > 0) {
        $checkStatement = $pdo->prepare(
            "SELECT l.form_id
             FROM company_form_links l
             INNER JOIN forms f ON f.id = l.form_id
             WHERE l.company_id = :company_id
               AND l.form_id = :form_id
               AND f.status = 'active'
             LIMIT 1"
        );
        $checkStatement->execute([
            'company_id' => $companyId,
            'form_id' => $preferredFormId,
        ]);

        if ($checkStatement->fetchColumn() !== false) {
            $nextFormId = $preferredFormId;
        }
    }

    if ($nextFormId === null) {
        $currentStatement = $pdo->prepare(
            "SELECT c.active_form_id
             FROM companies c
             INNER JOIN company_form_links l ON l.company_id = c.id AND l.form_id = c.active_form_id
             INNER JOIN forms f ON f.id = l.form_id
             WHERE c.id = :company_id
               AND f.status = 'active'
             LIMIT 1"
        );
        $currentStatement->execute(['company_id' => $companyId]);
        $currentFormId = (int) $currentStatement->fetchColumn();

        if ($currentFormId > 0) {
            $nextFormId = $currentFormId;
        }
    }

    if ($nextFormId === null) {
        $statement = $pdo->prepare(
            "SELECT l.form_id
             FROM company_form_links l
             INNER JOIN forms f ON f.id = l.form_id
             WHERE l.company_id = :company_id
               AND f.status = 'active'
             ORDER BY l.updated_at DESC, l.id DESC
             LIMIT 1"
        );
        $statement->execute(['company_id' => $companyId]);
        $fetchedFormId = (int) $statement->fetchColumn();
        $nextFormId = $fetchedFormId > 0 ? $fetchedFormId : null;
    }

    $updateStatement = $pdo->prepare(
        'UPDATE companies
         SET active_form_id = :active_form_id,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :company_id'
    );
    $updateStatement->bindValue('company_id', $companyId, PDO::PARAM_INT);

    if ($nextFormId === null) {
        $updateStatement->bindValue('active_form_id', null, PDO::PARAM_NULL);
    } else {
        $updateStatement->bindValue('active_form_id', $nextFormId, PDO::PARAM_INT);
    }

    $updateStatement->execute();
}

$method = request_method();
$pdo = db();

if ($method === 'GET') {
    send_json(200, [
        'success' => true,
        'data' => fetch_company_form_link_dashboard($pdo),
    ]);
}

if ($method === 'POST') {
    $payload = parse_company_form_link_payload(read_json_input());
    ensure_link_company_exists($pdo, $payload['companyId']);
    ensure_link_form_exists($pdo, $payload['formId']);

    $pdo->beginTransaction();

    try {
        $insertStatement = $pdo->prepare(
            'INSERT INTO company_form_links (company_id, form_id)
             VALUES (:company_id, :form_id)
             ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP'
        );
        $insertStatement->execute([
            'company_id' => $payload['companyId'],
            'form_id' => $payload['formId'],
        ]);

        sync_company_active_form($pdo, $payload['companyId'], $payload['formId']);
        $pdo->commit();
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        send_json(500, [
            'success' => false,
            'message' => 'Nao foi possivel salvar o vinculo.',
        ]);
    }

    send_json(200, [
        'success' => true,
        'message' => 'Vinculo salvo com sucesso.',
        'data' => fetch_company_form_link_dashboard($pdo),
    ]);
}

if ($method === 'DELETE') {
    $companyId = (int) ($_GET['companyId'] ?? 0);
    $formId = (int) ($_GET['formId'] ?? 0);

    if ($companyId <= 0) {
        send_json(422, [
            'success' => false,
            'message' => 'Selecione a empresa para remover o vinculo.',
        ]);
    }

    ensure_link_company_exists($pdo, $companyId);

    $pdo->beginTransaction();

    try {
        if ($formId > 0) {
            $deleteStatement = $pdo->prepare(
                'DELETE FROM company_form_links
                 WHERE company_id = :company_id
                   AND form_id = :form_id'
            );
            $deleteStatement->execute([
                'company_id' => $companyId,
                'form_id' => $formId,
            ]);
        } else {
            $deleteStatement = $pdo->prepare(
                'DELETE FROM company_form_links
                 WHERE company_id = :company_id'
            );
            $deleteStatement->execute(['company_id' => $companyId]);
        }

        sync_company_active_form($pdo, $companyId);
        $pdo->commit();
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        send_json(500, [
            'success' => false,
            'message' => 'Nao foi possivel remover o vinculo.',
        ]);
    }

    send_json(200, [
        'success' => true,
        'message' => 'Vinculo removido com sucesso.',
        'data' => fetch_company_form_link_dashboard($pdo),
    ]);
}

send_json(405, [
    'success' => false,
    'message' => 'Metodo nao permitido.',
]);
