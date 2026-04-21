<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

function fetch_questionnaire_session(PDO $pdo, string $sessionPublicId): ?array
{
    $statement = $pdo->prepare(
        'SELECT
             s.id AS session_id,
             s.session_public_id,
             s.status AS session_status,
             s.started_at,
             s.updated_at,
             s.completed_at,
             ac.id AS access_code_id,
             ac.code AS access_code,
             ac.scope_label,
             ac.scope_type,
             ac.sector_id,
             ac.function_id,
             ac.expires_at,
             ac.is_active,
             c.id AS company_id,
             c.name AS company_name,
             f.id AS form_id,
             f.public_code AS form_code,
             f.name AS form_name,
             f.status AS form_status,
             sct.sector_name,
             fn.function_name
         FROM access_code_sessions s
         INNER JOIN employee_access_codes ac ON ac.id = s.access_code_id
         INNER JOIN companies c ON c.id = ac.company_id
         INNER JOIN forms f ON f.id = ac.form_id
         LEFT JOIN company_sectors sct ON sct.id = ac.sector_id
         LEFT JOIN company_functions fn ON fn.id = ac.function_id
         WHERE s.session_public_id = :session_id
         LIMIT 1'
    );
    $statement->execute(['session_id' => $sessionPublicId]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

function fetch_questionnaire_questions(PDO $pdo, int $formId): array
{
    $statement = $pdo->prepare(
        'SELECT id, question_text, position
         FROM form_questions
         WHERE form_id = :form_id
         ORDER BY position ASC, id ASC'
    );
    $statement->execute(['form_id' => $formId]);

    $questions = [];

    foreach ($statement->fetchAll() as $row) {
        $questions[] = [
            'id' => (int) $row['id'],
            'text' => (string) $row['question_text'],
            'position' => (int) $row['position'],
        ];
    }

    return $questions;
}

function fetch_questionnaire_answers(PDO $pdo, int $sessionId): array
{
    $statement = $pdo->prepare(
        'SELECT form_question_id, answer_value
         FROM access_code_answers
         WHERE session_id = :session_id'
    );
    $statement->execute(['session_id' => $sessionId]);

    $answers = [];

    foreach ($statement->fetchAll() as $row) {
        $answers[(string) ((int) $row['form_question_id'])] = (int) $row['answer_value'];
    }

    return $answers;
}

function build_questionnaire_payload(PDO $pdo, array $sessionRow): array
{
    $sessionId = (int) $sessionRow['session_id'];
    $formId = (int) $sessionRow['form_id'];
    $questions = fetch_questionnaire_questions($pdo, $formId);
    $answers = fetch_questionnaire_answers($pdo, $sessionId);

    return [
        'sessionId' => (string) $sessionRow['session_public_id'],
        'sessionStatus' => (string) $sessionRow['session_status'],
        'startedAt' => (string) $sessionRow['started_at'],
        'submittedAt' => (string) ($sessionRow['completed_at'] ?? $sessionRow['updated_at']),
        'accessCode' => (string) $sessionRow['access_code'],
        'scopeType' => (string) ($sessionRow['scope_type'] ?? 'global'),
        'scopeLabel' => (string) $sessionRow['scope_label'],
        'sectorId' => $sessionRow['sector_id'] !== null ? (int) $sessionRow['sector_id'] : null,
        'sectorName' => (string) ($sessionRow['sector_name'] ?? ''),
        'functionId' => $sessionRow['function_id'] !== null ? (int) $sessionRow['function_id'] : null,
        'functionName' => (string) ($sessionRow['function_name'] ?? ''),
        'companyId' => (int) $sessionRow['company_id'],
        'companyName' => (string) $sessionRow['company_name'],
        'formId' => $formId,
        'formCode' => (string) $sessionRow['form_code'],
        'formName' => (string) $sessionRow['form_name'],
        'questionCount' => count($questions),
        'questions' => $questions,
        'answers' => $answers,
        'scale' => [
            ['value' => 1, 'label' => 'Nunca'],
            ['value' => 2, 'label' => 'Raramente'],
            ['value' => 3, 'label' => 'As vezes'],
            ['value' => 4, 'label' => 'Frequentemente'],
            ['value' => 5, 'label' => 'Sempre'],
        ],
    ];
}

function ensure_questionnaire_form_is_active(array $sessionRow): void
{
    if (normalize_status((string) ($sessionRow['form_status'] ?? 'inactive')) === 'inactive') {
        send_json(403, [
            'success' => false,
            'message' => 'Este formulario foi desativado e nao pode mais ser respondido.',
        ]);
    }
}

function parse_questionnaire_submission(array $input): array
{
    $sessionPublicId = trim((string) ($input['sessionId'] ?? ''));
    $answersInput = $input['answers'] ?? null;

    if ($sessionPublicId === '') {
        send_json(422, [
            'success' => false,
            'message' => 'Sessao do questionario invalida.',
        ]);
    }

    if (!is_array($answersInput) || $answersInput === []) {
        send_json(422, [
            'success' => false,
            'message' => 'Responda o questionario antes de enviar.',
        ]);
    }

    $answers = [];

    foreach ($answersInput as $questionId => $answerValue) {
        $normalizedQuestionId = (int) $questionId;
        $normalizedAnswerValue = (int) $answerValue;

        if ($normalizedQuestionId <= 0 || $normalizedAnswerValue < 1 || $normalizedAnswerValue > 5) {
            send_json(422, [
                'success' => false,
                'message' => 'Foi encontrada uma resposta invalida no questionario.',
            ]);
        }

        $answers[$normalizedQuestionId] = $normalizedAnswerValue;
    }

    return [
        'sessionId' => $sessionPublicId,
        'answers' => $answers,
    ];
}

function persist_questionnaire_answers(PDO $pdo, int $sessionId, array $answers): void
{
    $statement = $pdo->prepare(
        'INSERT INTO access_code_answers (session_id, form_question_id, answer_value)
         VALUES (:session_id, :form_question_id, :answer_value)
         ON DUPLICATE KEY UPDATE
           answer_value = VALUES(answer_value),
           updated_at = CURRENT_TIMESTAMP'
    );

    foreach ($answers as $questionId => $answerValue) {
        $statement->execute([
            'session_id' => $sessionId,
            'form_question_id' => $questionId,
            'answer_value' => $answerValue,
        ]);
    }
}

$method = request_method();
$pdo = db();

if ($method === 'GET') {
    $sessionPublicId = trim((string) ($_GET['session'] ?? ''));

    if ($sessionPublicId === '') {
        send_json(422, [
            'success' => false,
            'message' => 'Sessao do questionario nao informada.',
        ]);
    }

    $sessionRow = fetch_questionnaire_session($pdo, $sessionPublicId);

    if ($sessionRow === null) {
        send_json(404, [
            'success' => false,
            'message' => 'Sessao do questionario nao encontrada.',
        ]);
    }

    ensure_questionnaire_form_is_active($sessionRow);

    send_json(200, [
        'success' => true,
        'data' => build_questionnaire_payload($pdo, $sessionRow),
    ]);
}

if ($method === 'POST') {
    $payload = parse_questionnaire_submission(read_json_input());
    $sessionRow = fetch_questionnaire_session($pdo, $payload['sessionId']);

    if ($sessionRow === null) {
        send_json(404, [
            'success' => false,
            'message' => 'Sessao do questionario nao encontrada.',
        ]);
    }

    ensure_questionnaire_form_is_active($sessionRow);

    if ((string) $sessionRow['session_status'] === 'done') {
        send_json(409, [
            'success' => false,
            'message' => 'Este questionario ja foi concluido.',
        ]);
    }

    $questions = fetch_questionnaire_questions($pdo, (int) $sessionRow['form_id']);

    if ($questions === []) {
        send_json(422, [
            'success' => false,
            'message' => 'Este formulario ainda nao possui perguntas cadastradas.',
        ]);
    }

    $expectedQuestionIds = [];

    foreach ($questions as $question) {
        $expectedQuestionIds[(int) $question['id']] = true;
    }

    foreach (array_keys($payload['answers']) as $questionId) {
        if (!isset($expectedQuestionIds[(int) $questionId])) {
            send_json(422, [
                'success' => false,
                'message' => 'O envio contem perguntas que nao pertencem a este formulario.',
            ]);
        }
    }

    if (count($payload['answers']) !== count($expectedQuestionIds)) {
        send_json(422, [
            'success' => false,
            'message' => 'Responda todas as perguntas antes de enviar.',
        ]);
    }

    $pdo->beginTransaction();

    try {
        persist_questionnaire_answers($pdo, (int) $sessionRow['session_id'], $payload['answers']);

        $updateStatement = $pdo->prepare(
            'UPDATE access_code_sessions
             SET status = :status,
                 updated_at = CURRENT_TIMESTAMP,
                 completed_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $updateStatement->execute([
            'status' => 'done',
            'id' => (int) $sessionRow['session_id'],
        ]);

        $pdo->commit();
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        send_json(500, [
            'success' => false,
            'message' => 'Nao foi possivel salvar as respostas agora.',
        ]);
    }

    $updatedSession = fetch_questionnaire_session($pdo, $payload['sessionId']);

    send_json(200, [
        'success' => true,
        'message' => 'Respostas enviadas com sucesso.',
        'data' => build_questionnaire_payload($pdo, $updatedSession ?: $sessionRow),
    ]);
}

send_json(405, [
    'success' => false,
    'message' => 'Metodo nao permitido.',
]);
