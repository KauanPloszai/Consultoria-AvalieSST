<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

require_auth();

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

$method = request_method();
$pdo = db();

if ($method === 'GET') {
    send_json(200, [
        'success' => true,
        'data' => fetch_forms($pdo),
    ]);
}

$input = read_json_input();
$payload = parse_form_payload($input);

if ($method === 'POST') {
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

if ($method === 'PUT') {
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
