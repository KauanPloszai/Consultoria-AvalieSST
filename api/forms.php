<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

require_admin();

function fetch_forms(PDO $pdo): array
{
    $formsStatement = $pdo->query(
        'SELECT id, public_code, name, status, created_at
         FROM forms
         ORDER BY created_at DESC, id DESC'
    );

    $forms = [];
    $formIds = [];

    foreach ($formsStatement->fetchAll() as $row) {
        $formId = (int) $row['id'];
        $formIds[] = $formId;
        $forms[$formId] = [
            'id' => (string) $row['public_code'],
            'databaseId' => $formId,
            'publicCode' => (string) $row['public_code'],
            'name' => (string) $row['name'],
            'status' => normalize_status((string) $row['status']),
            'createdAt' => substr((string) $row['created_at'], 0, 10),
            'questions' => [],
        ];
    }

    if ($formIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($formIds), '?'));
        $questionsStatement = $pdo->prepare(
            "SELECT form_id, question_text
             FROM form_questions
             WHERE form_id IN ($placeholders)
             ORDER BY position ASC, id ASC"
        );
        $questionsStatement->execute($formIds);

        foreach ($questionsStatement->fetchAll() as $questionRow) {
            $formId = (int) $questionRow['form_id'];

            if (!isset($forms[$formId])) {
                continue;
            }

            $forms[$formId]['questions'][] = (string) $questionRow['question_text'];
        }

        $linksStatement = $pdo->prepare(
            "SELECT l.form_id, c.id AS company_id, c.name AS company_name
             FROM company_form_links l
             INNER JOIN companies c ON c.id = l.company_id
             WHERE l.form_id IN ($placeholders)
             ORDER BY name ASC"
        );
        $linksStatement->execute($formIds);

        foreach ($linksStatement->fetchAll() as $linkRow) {
            $formId = (int) $linkRow['form_id'];

            if (!isset($forms[$formId])) {
                continue;
            }

            if (!isset($forms[$formId]['linkedCompanies'])) {
                $forms[$formId]['linkedCompanies'] = [];
            }

            $forms[$formId]['linkedCompanies'][] = [
                'id' => (int) $linkRow['company_id'],
                'name' => (string) $linkRow['company_name'],
            ];
        }
    }

    foreach ($forms as $formId => $form) {
        $linkedCompanies = $form['linkedCompanies'] ?? [];
        $forms[$formId]['linkedCompanies'] = $linkedCompanies;
        $forms[$formId]['linkedCompaniesCount'] = count($linkedCompanies);
    }

    return array_values($forms);
}

function generate_form_code(PDO $pdo): string
{
    $year = date('Y');
    $statement = $pdo->prepare(
        'SELECT public_code
         FROM forms
         WHERE public_code LIKE :prefix
         ORDER BY public_code DESC
         LIMIT 1'
    );
    $statement->execute(['prefix' => $year . '-%']);
    $latestCode = $statement->fetchColumn();

    $sequence = 1;

    if (is_string($latestCode) && strpos($latestCode, '-') !== false) {
        $parts = explode('-', $latestCode, 2);
        $sequence = ((int) ($parts[1] ?? 0)) + 1;
    }

    return sprintf('%s-%02d', $year, $sequence);
}

function parse_form_payload(array $input): array
{
    $name = trim((string) ($input['name'] ?? ''));
    $questions = normalize_string_list(is_array($input['questions'] ?? null) ? $input['questions'] : []);
    $status = normalize_status((string) ($input['status'] ?? 'active'));

    if ($name === '') {
        send_json(422, [
            'success' => false,
            'message' => 'Informe o nome do formulario.',
        ]);
    }

    if ($questions === []) {
        send_json(422, [
            'success' => false,
            'message' => 'Adicione pelo menos uma pergunta.',
        ]);
    }

    return [
        'name' => $name,
        'questions' => $questions,
        'status' => $status,
    ];
}

function replace_form_questions(PDO $pdo, int $formId, array $questions): void
{
    $deleteStatement = $pdo->prepare('DELETE FROM form_questions WHERE form_id = :form_id');
    $deleteStatement->execute(['form_id' => $formId]);

    $insertStatement = $pdo->prepare(
        'INSERT INTO form_questions (form_id, question_text, position)
         VALUES (:form_id, :question_text, :position)'
    );

    foreach ($questions as $index => $question) {
        $insertStatement->execute([
            'form_id' => $formId,
            'question_text' => $question,
            'position' => $index + 1,
        ]);
    }
}

function resolve_company_active_form(PDO $pdo, int $companyId): ?int
{
    $companyStatement = $pdo->prepare(
        'SELECT active_form_id
         FROM companies
         WHERE id = :company_id
         LIMIT 1'
    );
    $companyStatement->execute(['company_id' => $companyId]);
    $currentActiveFormId = (int) $companyStatement->fetchColumn();

    if ($currentActiveFormId > 0) {
        $currentStatement = $pdo->prepare(
            "SELECT l.form_id
             FROM company_form_links l
             INNER JOIN forms f ON f.id = l.form_id
             WHERE l.company_id = :company_id
               AND l.form_id = :form_id
               AND f.status = 'active'
             LIMIT 1"
        );
        $currentStatement->execute([
            'company_id' => $companyId,
            'form_id' => $currentActiveFormId,
        ]);

        if ($currentStatement->fetchColumn() !== false) {
            return $currentActiveFormId;
        }
    }

    $fallbackStatement = $pdo->prepare(
        "SELECT l.form_id
         FROM company_form_links l
         INNER JOIN forms f ON f.id = l.form_id
         WHERE l.company_id = :company_id
           AND f.status = 'active'
         ORDER BY l.updated_at DESC, l.id DESC
         LIMIT 1"
    );
    $fallbackStatement->execute(['company_id' => $companyId]);
    $fallbackFormId = (int) $fallbackStatement->fetchColumn();

    return $fallbackFormId > 0 ? $fallbackFormId : null;
}

function sync_company_active_form(PDO $pdo, int $companyId): void
{
    $nextFormId = resolve_company_active_form($pdo, $companyId);
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

function sync_companies_for_form(PDO $pdo, int $formId): void
{
    $statement = $pdo->prepare(
        'SELECT DISTINCT company_id
         FROM company_form_links
         WHERE form_id = :linked_form_id

         UNION

         SELECT id AS company_id
         FROM companies
         WHERE active_form_id = :active_form_id'
    );
    $statement->execute([
        'linked_form_id' => $formId,
        'active_form_id' => $formId,
    ]);

    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $companyId) {
        $normalizedCompanyId = (int) $companyId;

        if ($normalizedCompanyId > 0) {
            sync_company_active_form($pdo, $normalizedCompanyId);
        }
    }
}

$method = request_method();
$pdo = db();

if ($method === 'GET') {
    send_json(200, [
        'success' => true,
        'data' => fetch_forms($pdo),
    ]);
}

if ($method === 'POST') {
    $input = read_json_input();
    $payload = parse_form_payload($input);

    $pdo->beginTransaction();

    try {
        $formCode = generate_form_code($pdo);
        $insertStatement = $pdo->prepare(
            'INSERT INTO forms (public_code, name, status)
             VALUES (:public_code, :name, :status)'
        );
        $insertStatement->execute([
            'public_code' => $formCode,
            'name' => $payload['name'],
            'status' => $payload['status'],
        ]);

        $formId = (int) $pdo->lastInsertId();
        replace_form_questions($pdo, $formId, $payload['questions']);
        $pdo->commit();
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        send_json(500, [
            'success' => false,
            'message' => 'Nao foi possivel salvar o formulario.',
        ]);
    }

    send_json(201, [
        'success' => true,
        'message' => 'Formulario salvo com sucesso.',
        'data' => fetch_forms($pdo),
    ]);
}

if ($method === 'DELETE') {
    $publicCode = trim((string) ($_GET['id'] ?? ''));

    if ($publicCode === '') {
        send_json(422, [
            'success' => false,
            'message' => 'Formulario invalido para exclusao.',
        ]);
    }

    $formStatement = $pdo->prepare('SELECT id, name FROM forms WHERE public_code = :public_code LIMIT 1');
    $formStatement->execute(['public_code' => $publicCode]);
    $formRow = $formStatement->fetch();

    if (!$formRow) {
        send_json(404, [
            'success' => false,
            'message' => 'Formulario nao encontrado.',
        ]);
    }

    $formId = (int) $formRow['id'];

    $pdo->beginTransaction();

    try {
        $clearActiveStatement = $pdo->prepare(
            'UPDATE companies
             SET active_form_id = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE active_form_id = :form_id'
        );
        $clearActiveStatement->execute(['form_id' => $formId]);

        $deleteAnswersStatement = $pdo->prepare(
            'DELETE ans
             FROM access_code_answers ans
             INNER JOIN access_code_sessions s ON s.id = ans.session_id
             INNER JOIN employee_access_codes ac ON ac.id = s.access_code_id
             WHERE ac.form_id = :form_id'
        );
        $deleteAnswersStatement->execute(['form_id' => $formId]);

        $deleteSessionsStatement = $pdo->prepare(
            'DELETE s
             FROM access_code_sessions s
             INNER JOIN employee_access_codes ac ON ac.id = s.access_code_id
             WHERE ac.form_id = :form_id'
        );
        $deleteSessionsStatement->execute(['form_id' => $formId]);

        $deleteCodesStatement = $pdo->prepare('DELETE FROM employee_access_codes WHERE form_id = :form_id');
        $deleteCodesStatement->execute(['form_id' => $formId]);

        $deleteLinksStatement = $pdo->prepare('DELETE FROM company_form_links WHERE form_id = :form_id');
        $deleteLinksStatement->execute(['form_id' => $formId]);

        $deleteQuestionsStatement = $pdo->prepare('DELETE FROM form_questions WHERE form_id = :form_id');
        $deleteQuestionsStatement->execute(['form_id' => $formId]);

        $deleteStatement = $pdo->prepare('DELETE FROM forms WHERE id = :id');
        $deleteStatement->execute(['id' => $formId]);

        $pdo->commit();
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        send_json(500, [
            'success' => false,
            'message' => 'Nao foi possivel excluir o formulario.',
        ]);
    }

    send_json(200, [
        'success' => true,
        'message' => 'Formulario excluido com sucesso.',
        'data' => fetch_forms($pdo),
    ]);
}

if ($method === 'PUT') {
    $input = read_json_input();
    $payload = parse_form_payload($input);
    $publicCode = trim((string) ($input['id'] ?? $_GET['id'] ?? ''));

    if ($publicCode === '') {
        send_json(422, [
            'success' => false,
            'message' => 'Formulario invalido.',
        ]);
    }

    $formStatement = $pdo->prepare('SELECT id FROM forms WHERE public_code = :public_code LIMIT 1');
    $formStatement->execute(['public_code' => $publicCode]);
    $formRow = $formStatement->fetch();

    if (!$formRow) {
        send_json(404, [
            'success' => false,
            'message' => 'Formulario nao encontrado.',
        ]);
    }

    $formId = (int) $formRow['id'];

    $pdo->beginTransaction();

    try {
        $updateStatement = $pdo->prepare(
            'UPDATE forms
             SET name = :name,
                 status = :status,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $updateStatement->execute([
            'id' => $formId,
            'name' => $payload['name'],
            'status' => $payload['status'],
        ]);

        replace_form_questions($pdo, $formId, $payload['questions']);
        sync_companies_for_form($pdo, $formId);
        $pdo->commit();
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        send_json(500, [
            'success' => false,
            'message' => 'Nao foi possivel atualizar o formulario.',
        ]);
    }

    send_json(200, [
        'success' => true,
        'message' => 'Formulario atualizado com sucesso.',
        'data' => fetch_forms($pdo),
    ]);
}

send_json(405, [
    'success' => false,
    'message' => 'Metodo nao permitido.',
]);
