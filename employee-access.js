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

if (employeeCodeForm && employeeCodeInput && employeeCodeFeedback) {
  employeeCodeInput.addEventListener("input", () => {
    validatedSessionId = "";
    employeeCodeFeedback.textContent = "";
    employeeCodeFeedback.classList.remove("is-success");
    setEmployeeCodeButtonState("Iniciar Questionario", false);
  });

  employeeCodeForm.addEventListener("submit", async (event) => {
    event.preventDefault();

    const accessCode = employeeCodeInput.value.trim().toUpperCase();

    if (!accessCode) {
      employeeCodeFeedback.textContent = "Digite o codigo de acesso para continuar.";
      employeeCodeFeedback.classList.remove("is-success");
      setEmployeeCodeButtonState("Iniciar Questionario", false);
      return;
    }

    if (validatedSessionId) {
      setEmployeeCodeButtonState("Abrindo Questionario...", true);
      redirectToEmployeeQuestionnaire(validatedSessionId);
      return;
    }

    try {
      setEmployeeCodeButtonState("Validando...", true);

      const response = await window.apiClient.post("api/access-code.php", {
        code: accessCode,
      });
      const data = response.data || {};
      const sessionId = getValidatedSessionId(data);

      employeeCodeFeedback.textContent = `Codigo validado para ${data.companyName || "a empresa"} - ${data.formName || "formulario"}.`;
      employeeCodeFeedback.classList.add("is-success");

      if (!sessionId) {
        setEmployeeCodeButtonState("Iniciar Questionario", false);
        employeeCodeFeedback.textContent =
          "O codigo foi validado, mas a sessao do questionario nao foi criada. Tente novamente.";
        employeeCodeFeedback.classList.remove("is-success");
        return;
      }

      setEmployeeCodeButtonState("Abrindo Questionario...", true);
      redirectToEmployeeQuestionnaire(sessionId);
    } catch (error) {
      validatedSessionId = "";
      employeeCodeFeedback.textContent = error.message || "Nao foi possivel validar o codigo.";
      employeeCodeFeedback.classList.remove("is-success");
      setEmployeeCodeButtonState("Iniciar Questionario", false);
    }
  });
}
