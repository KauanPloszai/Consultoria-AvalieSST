const questionnaireApp = document.querySelector("[data-questionnaire-app]");
const questionnaireTopbar = document.querySelector("[data-questionnaire-topbar]");
const questionnairePanel = document.querySelector("[data-questionnaire-panel]");
const questionnaireFooter = document.querySelector("[data-questionnaire-footer]");
const questionnaireContent = document.querySelector("[data-questionnaire-content]");
const questionnaireFeedback = document.querySelector("[data-questionnaire-feedback]");
const questionnaireBackButton = document.querySelector("[data-questionnaire-back]");
const questionnaireNextButton = document.querySelector("[data-questionnaire-next]");
const questionnaireNextLabel = document.querySelector("[data-questionnaire-next-label]");
const questionnaireStepLabel = document.querySelector("[data-questionnaire-step-label]");
const questionnaireProgressLabel = document.querySelector("[data-questionnaire-progress-label]");
const questionnaireProgressFill = document.querySelector("[data-questionnaire-progress-fill]");
const questionnaireExitLink = document.querySelector("[data-questionnaire-exit]");
const STORED_EMPLOYEE_SESSION_KEY = "employeeQuestionnaireSessionId";

const RESPONSE_SCALE = {
  1: "Nunca",
  2: "Raramente",
  3: "Às vezes",
  4: "Frequentemente",
  5: "Sempre",
};

const QUESTIONS_PER_STEP = 3;

const questionnaireState = {
  isLoading: true,
  isSubmitting: false,
  hasCompleted: false,
  currentStepIndex: 0,
  sessionId: "",
  data: null,
  answers: {},
};

function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, (character) => {
    const entityMap = {
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#39;",
    };

    return entityMap[character] || character;
  });
}

function getQuestionnaireSessionId() {
  const params = new URLSearchParams(window.location.search);
  const sessionFromUrl = (params.get("session") || "").trim();

  if (sessionFromUrl) {
    try {
      window.sessionStorage.setItem(STORED_EMPLOYEE_SESSION_KEY, sessionFromUrl);
    } catch (error) {
      // Segue normalmente mesmo sem acesso ao sessionStorage.
    }

    return sessionFromUrl;
  }

  try {
    return (window.sessionStorage.getItem(STORED_EMPLOYEE_SESSION_KEY) || "").trim();
  } catch (error) {
    return "";
  }
}

function formatDateTime(dateValue) {
  const normalized = String(dateValue || "").replace(" ", "T");
  const parsedDate = new Date(normalized);

  if (Number.isNaN(parsedDate.getTime())) {
    return dateValue || "--";
  }

  return new Intl.DateTimeFormat("pt-BR", {
    dateStyle: "short",
    timeStyle: "short",
  }).format(parsedDate);
}

function setQuestionnaireFeedback(message, isSuccess = false) {
  if (!questionnaireFeedback) {
    return;
  }

  questionnaireFeedback.textContent = message;
  questionnaireFeedback.classList.toggle("is-success", isSuccess);
}

function getQuestions() {
  return Array.isArray(questionnaireState.data?.questions) ? questionnaireState.data.questions : [];
}

function getQuestionPages() {
  const questions = getQuestions();
  const pages = [];

  for (let index = 0; index < questions.length; index += QUESTIONS_PER_STEP) {
    pages.push(questions.slice(index, index + QUESTIONS_PER_STEP));
  }

  return pages;
}

function getTotalSteps() {
  return getQuestionPages().length + 1;
}

function isReviewStep() {
  return questionnaireState.currentStepIndex === getQuestionPages().length;
}

function getCurrentQuestions() {
  return getQuestionPages()[questionnaireState.currentStepIndex] || [];
}

function getAnswerLabel(answerValue) {
  const normalizedValue = Number(answerValue);
  return RESPONSE_SCALE[normalizedValue] || "Não respondida";
}

function updateQuestionnaireHeader() {
  if (!questionnaireTopbar || !questionnaireProgressFill || !questionnaireStepLabel || !questionnaireProgressLabel) {
    return;
  }

  if (questionnaireState.hasCompleted) {
    questionnaireTopbar.hidden = true;
    return;
  }

  questionnaireTopbar.hidden = false;

  const totalSteps = Math.max(1, getTotalSteps());
  const currentStep = Math.min(questionnaireState.currentStepIndex + 1, totalSteps);
  const progressPercent = Math.round((currentStep / totalSteps) * 100);

  questionnaireStepLabel.textContent = `Passo ${currentStep} de ${totalSteps}`;
  questionnaireProgressLabel.textContent = `${progressPercent}%`;
  questionnaireProgressFill.style.width = `${progressPercent}%`;
}

function updateQuestionnaireFooter() {
  if (!questionnaireFooter || !questionnaireBackButton || !questionnaireNextButton || !questionnaireNextLabel) {
    return;
  }

  if (questionnaireState.hasCompleted) {
    questionnaireFooter.hidden = true;
    return;
  }

  questionnaireFooter.hidden = false;

  const isFirstStep = questionnaireState.currentStepIndex === 0;

  questionnaireBackButton.disabled = questionnaireState.isSubmitting || isFirstStep;
  questionnaireNextButton.disabled = questionnaireState.isSubmitting;
  questionnaireNextLabel.textContent = isReviewStep() ? "Enviar Respostas" : "Proximo";
}

function buildQuestionStepMarkup() {
  const currentQuestions = getCurrentQuestions();
  const formName = questionnaireState.data?.formName || "Questionário";

  return `
    <div class="employee-questionnaire-intro">
      <h1>${escapeHtml(formName)}</h1>
      <p>
        Por favor, responda às afirmações abaixo considerando sua rotina de trabalho
        nos últimos 30 dias. Não há respostas certas ou erradas.
      </p>
    </div>

    <div class="employee-questionnaire-scale-box">
      <strong>Escala de resposta:</strong>
      <span>1 = Nunca | 2 = Raramente | 3 = Às vezes | 4 = Frequentemente | 5 = Sempre</span>
    </div>

    <div class="employee-questionnaire-questions">
      ${currentQuestions
        .map((question) => {
          const selectedValue = Number(questionnaireState.answers[question.id] || 0);

          return `
            <article class="employee-question-card">
              <h2>${question.position}. ${escapeHtml(question.text)}</h2>

              <div class="employee-question-card__options">
                ${Object.entries(RESPONSE_SCALE)
                  .map(([value, label]) => {
                    const numericValue = Number(value);
                    const isSelected = numericValue === selectedValue;

                    return `
                      <button
                        class="employee-question-option ${isSelected ? "is-selected" : ""}"
                        type="button"
                        data-answer-question="${question.id}"
                        data-answer-value="${numericValue}"
                        aria-label="${escapeHtml(label)}"
                      >
                        ${numericValue}
                      </button>
                    `;
                  })
                  .join("")}
              </div>

              <div class="employee-question-card__legend">
                <span>Nunca</span>
                <span>Sempre</span>
              </div>
            </article>
          `;
        })
        .join("")}
    </div>
  `;
}

function buildReviewMarkup() {
  const questions = getQuestions();

  return `
    <div class="employee-questionnaire-intro employee-questionnaire-intro--review">
      <h1>Revisão das Respostas</h1>
      <p>
        Confira abaixo suas respostas antes do envio final. Caso precise ajustar
        alguma resposta, volte para as etapas anteriores.
      </p>
    </div>

    <div class="employee-questionnaire-scale-box employee-questionnaire-scale-box--review">
      <strong>Suas respostas sao anonimas</strong>
      <span>
        Os dados coletados serão consolidados e analisados em grupo. A empresa
        não terá acesso às respostas individuais.
      </span>
    </div>

    <section class="employee-review-card">
      <header class="employee-review-card__header">
        <h2>Respostas</h2>
        <button class="employee-review-edit" type="button" data-review-edit aria-label="Editar respostas">
          <svg viewBox="0 0 24 24" role="presentation">
            <path d="m4 16.5 9.8-9.8a2.1 2.1 0 1 1 3 3L7 19.5 4 20l.5-3.5Z" fill="none" stroke="currentColor" stroke-linejoin="round" stroke-width="1.7" />
          </svg>
        </button>
      </header>

      <div class="employee-review-list">
        ${questions
          .map((question) => {
            const answerValue = Number(questionnaireState.answers[question.id] || 0);
            const answerLabel = `${getAnswerLabel(answerValue)} (${answerValue})`;

            return `
              <div class="employee-review-row">
                <span>${question.position}. ${escapeHtml(question.text)}</span>
                <strong>${escapeHtml(answerLabel)}</strong>
              </div>
            `;
          })
          .join("")}
      </div>
    </section>
  `;
}

function buildCompletionMarkup() {
  const sessionId = questionnaireState.data?.sessionId || questionnaireState.sessionId;

  return `
    <section class="employee-completion-shell">
      <article class="employee-completion-card">
        <span class="employee-completion-icon">
          <svg viewBox="0 0 24 24" role="presentation">
            <path d="m6.8 12.4 3.2 3.2 7.2-7.6" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" />
          </svg>
        </span>

        <h1>Avaliação Concluída!</h1>
        <p>
          Muito obrigado por sua participação. Suas respostas foram registradas
          com sucesso e ajudarão na construção de um ambiente de trabalho mais
          saudável e seguro para todos.
        </p>

        <div class="employee-completion-note">
          <strong>Garantia de Anonimato</strong>
          <span>
            Seus dados foram registrados de forma anônima. O RH e a liderança
            terão acesso apenas a relatórios consolidados do grupo.
          </span>
        </div>

        <div class="employee-completion-actions">
          <button class="employee-questionnaire-button employee-questionnaire-button--ghost" type="button" data-download-receipt>
            Baixar ID de Envio
          </button>
          <a class="employee-questionnaire-button employee-questionnaire-button--primary" href="acesso-funcionario.html">
            Finalizar Sessão
          </a>
        </div>

        <small>ID da sessão: ${escapeHtml(sessionId)}</small>
      </article>

      <div class="employee-completion-foot">
        <span>Plataforma Segura e Criptografada</span>
      </div>
    </section>
  `;
}

function buildErrorMarkup(message) {
  return `
    <section class="employee-completion-shell employee-completion-shell--error">
      <article class="employee-completion-card employee-completion-card--error">
        <h1>Questionário indisponível</h1>
        <p>${escapeHtml(message)}</p>
        <a class="employee-questionnaire-button employee-questionnaire-button--primary" href="acesso-funcionario.html">
          Voltar para o código de acesso
        </a>
      </article>
    </section>
  `;
}

function renderQuestionnaire() {
  if (!questionnaireContent) {
    return;
  }

  updateQuestionnaireHeader();
  updateQuestionnaireFooter();

  if (questionnaireState.isLoading) {
    questionnaireContent.innerHTML = `
      <section class="employee-completion-shell employee-completion-shell--loading">
        <article class="employee-completion-card employee-completion-card--loading">
          <h1>Carregando questionário...</h1>
          <p>Aguarde enquanto buscamos as perguntas vinculadas ao seu código.</p>
        </article>
      </section>
    `;
    return;
  }

  if (!questionnaireState.data) {
    questionnaireContent.innerHTML = buildErrorMarkup("Não foi possível localizar esta sessão.");
    return;
  }

  if (questionnaireState.hasCompleted) {
    questionnaireContent.innerHTML = buildCompletionMarkup();
    return;
  }

  questionnaireContent.innerHTML = isReviewStep() ? buildReviewMarkup() : buildQuestionStepMarkup();
}

function validateCurrentStepAnswers() {
  const currentQuestions = getCurrentQuestions();

  for (const question of currentQuestions) {
    const answerValue = Number(questionnaireState.answers[question.id] || 0);

    if (answerValue < 1 || answerValue > 5) {
      setQuestionnaireFeedback("Responda todas as perguntas desta etapa antes de continuar.", false);
      return false;
    }
  }

  return true;
}

function validateAllAnswers() {
  const questions = getQuestions();

  for (const question of questions) {
    const answerValue = Number(questionnaireState.answers[question.id] || 0);

    if (answerValue < 1 || answerValue > 5) {
      setQuestionnaireFeedback("Responda todas as perguntas antes de enviar o questionário.", false);
      return false;
    }
  }

  return true;
}

async function loadQuestionnaire() {
  questionnaireState.sessionId = getQuestionnaireSessionId();

  if (!questionnaireState.sessionId) {
    questionnaireState.isLoading = false;
    questionnaireState.data = null;
    setQuestionnaireFeedback("Sessão do questionário não informada.", false);
    renderQuestionnaire();
    return;
  }

  try {
    questionnaireState.isLoading = true;
    renderQuestionnaire();

    const response = await window.apiClient.get(
      `api/employee-questionnaire.php?session=${encodeURIComponent(questionnaireState.sessionId)}`,
    );

    questionnaireState.data = response.data || null;
    questionnaireState.answers = { ...(response.data?.answers || {}) };
    questionnaireState.hasCompleted = response.data?.sessionStatus === "done";
    questionnaireState.currentStepIndex = 0;

    if (response.data?.sessionId) {
      try {
        window.sessionStorage.setItem(STORED_EMPLOYEE_SESSION_KEY, response.data.sessionId);
      } catch (error) {
        // Sem impacto funcional se o armazenamento falhar.
      }
    }

    setQuestionnaireFeedback("", false);
  } catch (error) {
    questionnaireState.data = null;
    setQuestionnaireFeedback(error.message || "Não foi possível carregar o questionário.", false);
  } finally {
    questionnaireState.isLoading = false;
    renderQuestionnaire();
  }
}

async function submitQuestionnaire() {
  if (!questionnaireState.data || questionnaireState.isSubmitting) {
    return;
  }

  if (!validateAllAnswers()) {
    return;
  }

  try {
    questionnaireState.isSubmitting = true;
    updateQuestionnaireFooter();
    setQuestionnaireFeedback("Enviando respostas...", false);

    const response = await window.apiClient.post("api/employee-questionnaire.php", {
      sessionId: questionnaireState.data.sessionId,
      answers: questionnaireState.answers,
    });

    questionnaireState.data = response.data || questionnaireState.data;
    questionnaireState.answers = { ...(response.data?.answers || questionnaireState.answers) };
    questionnaireState.hasCompleted = true;
    setQuestionnaireFeedback("", false);
  } catch (error) {
    setQuestionnaireFeedback(error.message || "Não foi possível enviar suas respostas.", false);
  } finally {
    questionnaireState.isSubmitting = false;
    renderQuestionnaire();
  }
}

function handleQuestionnaireContentClick(event) {
  const answerButton = event.target.closest("[data-answer-question]");

  if (answerButton) {
    const questionId = Number(answerButton.getAttribute("data-answer-question"));
    const answerValue = Number(answerButton.getAttribute("data-answer-value"));

    if (questionId > 0 && answerValue >= 1 && answerValue <= 5) {
      questionnaireState.answers[questionId] = answerValue;
      setQuestionnaireFeedback("", false);
      renderQuestionnaire();
    }

    return;
  }

  const reviewEditButton = event.target.closest("[data-review-edit]");

  if (reviewEditButton) {
    questionnaireState.currentStepIndex = 0;
    setQuestionnaireFeedback("", false);
    renderQuestionnaire();
    return;
  }

  const downloadButton = event.target.closest("[data-download-receipt]");

  if (downloadButton) {
    const sessionId = questionnaireState.data?.sessionId || questionnaireState.sessionId;
    const formName = questionnaireState.data?.formName || "Questionário";
    const content = [
      `ID da sessão: ${sessionId}`,
      `Formulário: ${formName}`,
      `Data de envio: ${formatDateTime(questionnaireState.data?.submittedAt || "")}`,
    ].join("\r\n");

    const blob = new Blob([content], { type: "text/plain;charset=utf-8" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");

    link.href = url;
    link.download = `envio-${sessionId}.txt`;
    link.click();

    URL.revokeObjectURL(url);
  }
}

function handleBackClick() {
  if (questionnaireState.currentStepIndex > 0) {
    questionnaireState.currentStepIndex -= 1;
    setQuestionnaireFeedback("", false);
    renderQuestionnaire();
  }
}

function handleNextClick() {
  if (questionnaireState.isSubmitting || questionnaireState.hasCompleted) {
    return;
  }

  if (isReviewStep()) {
    submitQuestionnaire();
    return;
  }

  if (!validateCurrentStepAnswers()) {
    return;
  }

  questionnaireState.currentStepIndex += 1;
  setQuestionnaireFeedback("", false);
  renderQuestionnaire();
}

function handleExitClick(event) {
  if (questionnaireState.hasCompleted) {
    return;
  }

  const hasSomeAnswer = Object.keys(questionnaireState.answers).length > 0;

  if (!hasSomeAnswer) {
    return;
  }

  const shouldLeave = window.confirm(
    "Deseja sair agora? As respostas ainda não enviadas serão perdidas.",
  );

  if (!shouldLeave) {
    event.preventDefault();
  }
}

if (questionnaireApp && questionnaireContent) {
  loadQuestionnaire();

  questionnaireContent.addEventListener("click", handleQuestionnaireContentClick);

  if (questionnaireBackButton) {
    questionnaireBackButton.addEventListener("click", handleBackClick);
  }

  if (questionnaireNextButton) {
    questionnaireNextButton.addEventListener("click", handleNextClick);
  }

  if (questionnaireExitLink) {
    questionnaireExitLink.addEventListener("click", handleExitClick);
  }
}
