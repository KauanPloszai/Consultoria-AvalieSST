const employeeCodeForm = document.querySelector(".employee-code-form");
const employeeCodeInput = document.querySelector("#employeeAccessCode");
const employeeCodeFeedback = document.querySelector("[data-employee-code-feedback]");
const employeeCodeButton = employeeCodeForm?.querySelector('button[type="submit"]');
const employeeCodeButtonLabel = employeeCodeButton?.querySelector("span");

const EMPLOYEE_SESSION_STORAGE_KEY = "employeeQuestionnaireSessionId";

let validatedSessionId = "";

function setEmployeeCodeButtonState(label, isDisabled = false) {
  if (employeeCodeButton) {
    employeeCodeButton.disabled = isDisabled;
  }

  if (employeeCodeButtonLabel) {
    employeeCodeButtonLabel.textContent = label;
  }
}

function getValidatedSessionId(payload) {
  return String(
    payload?.sessionId ||
      payload?.session_id ||
      payload?.sessionPublicId ||
      payload?.session_public_id ||
      "",
  ).trim();
}

function redirectToEmployeeQuestionnaire(sessionId) {
  const normalizedSessionId = String(sessionId || "").trim();

  if (!normalizedSessionId) {
    return;
  }

  validatedSessionId = normalizedSessionId;

  try {
    window.sessionStorage.setItem(EMPLOYEE_SESSION_STORAGE_KEY, normalizedSessionId);
  } catch (error) {
    // Se o navegador bloquear sessionStorage, seguimos apenas com a query string.
  }

  const destinationUrl = new URL(
    `questionario-funcionario.html?session=${encodeURIComponent(normalizedSessionId)}`,
    window.location.href,
  ).toString();

  window.location.assign(destinationUrl);

  window.setTimeout(() => {
    if (window.location.href !== destinationUrl) {
      window.location.replace(destinationUrl);
    }
  }, 700);
}

async function validateEmployeeAccess(payload) {
  const response = await window.apiClient.request("api/access-code.php", {
    method: "POST",
    body: payload,
  });
  const data = response.data || {};
  const sessionId = getValidatedSessionId(data);

  employeeCodeFeedback.textContent = `Código validado para ${data.companyName || "a empresa"} - ${data.formName || "formulário"}.`;
  employeeCodeFeedback.classList.add("is-success");

  if (!sessionId) {
    setEmployeeCodeButtonState("Iniciar Questionário", false);
    employeeCodeFeedback.textContent =
      "O código foi validado, mas a sessão do questionário não foi criada. Tente novamente.";
    employeeCodeFeedback.classList.remove("is-success");
    return;
  }

  setEmployeeCodeButtonState("Abrindo Questionário...", true);
  redirectToEmployeeQuestionnaire(sessionId);
}

async function autoStartEmployeeAccessFromLink() {
  const params = new URLSearchParams(window.location.search);
  const token = (params.get("token") || "").trim();
  const codeFromUrl = (params.get("code") || "").trim().toUpperCase();

  if (!token && !codeFromUrl) {
    return;
  }

  if (employeeCodeInput && codeFromUrl) {
    employeeCodeInput.value = codeFromUrl;
  }

  try {
    setEmployeeCodeButtonState("Validando...", true);
    employeeCodeFeedback.textContent = "Validando acesso da empresa...";
    employeeCodeFeedback.classList.remove("is-success");

    await validateEmployeeAccess(token ? { token } : { code: codeFromUrl });
  } catch (error) {
    validatedSessionId = "";
    employeeCodeFeedback.textContent = error.message || "Não foi possível validar o link de acesso.";
    employeeCodeFeedback.classList.remove("is-success");
    setEmployeeCodeButtonState("Iniciar Questionário", false);
  }
}

if (employeeCodeForm && employeeCodeInput && employeeCodeFeedback) {
  employeeCodeInput.addEventListener("input", () => {
    validatedSessionId = "";
    employeeCodeFeedback.textContent = "";
    employeeCodeFeedback.classList.remove("is-success");
    setEmployeeCodeButtonState("Iniciar Questionário", false);
  });

  employeeCodeForm.addEventListener("submit", async (event) => {
    event.preventDefault();

    const accessCode = employeeCodeInput.value.trim().toUpperCase();

    if (!accessCode) {
      employeeCodeFeedback.textContent = "Digite o código de acesso para continuar.";
      employeeCodeFeedback.classList.remove("is-success");
      setEmployeeCodeButtonState("Iniciar Questionário", false);
      return;
    }

    if (validatedSessionId) {
      setEmployeeCodeButtonState("Abrindo Questionário...", true);
      redirectToEmployeeQuestionnaire(validatedSessionId);
      return;
    }

    try {
      setEmployeeCodeButtonState("Validando...", true);
      await validateEmployeeAccess({ code: accessCode });
    } catch (error) {
      validatedSessionId = "";
      employeeCodeFeedback.textContent = error.message || "Não foi possível validar o código.";
      employeeCodeFeedback.classList.remove("is-success");
      setEmployeeCodeButtonState("Iniciar Questionário", false);
    }
  });

  autoStartEmployeeAccessFromLink();
}
