const loginForm = document.querySelector(".login-form");
const emailInput = document.querySelector("#email");
const passwordInput = document.querySelector("#password");
const loginFeedback = document.querySelector("[data-login-feedback]");

const DEFAULT_DASHBOARD_URL = "dashboard.html";
const COMPANY_DASHBOARD_URL = "geracao-acesso.html";

function resolveHomeUrl(user) {
  return user?.role === "company" ? COMPANY_DASHBOARD_URL : DEFAULT_DASHBOARD_URL;
}

if (loginFeedback && window.location.protocol === "file:") {
  loginFeedback.textContent =
    "Esta tela precisa ser aberta por um servidor PHP local, por exemplo http://localhost/... e não direto pelo arquivo index.html.";
  loginFeedback.classList.remove("is-success");
}

async function redirectIfAuthenticated() {
  try {
    const response = await window.apiClient.get("api/session.php");
    window.location.href = resolveHomeUrl(response.data || {});
  } catch (error) {
    return null;
  }
}

redirectIfAuthenticated();

if (loginForm && emailInput && passwordInput && loginFeedback) {
  loginForm.addEventListener("submit", async (event) => {
    event.preventDefault();

    const email = emailInput.value.trim().toLowerCase();
    const password = passwordInput.value.trim();

    if (!email || !password) {
      loginFeedback.textContent = "Preencha e-mail e senha para continuar.";
      loginFeedback.classList.remove("is-success");
      return;
    }

    try {
      loginFeedback.textContent = "Validando acesso...";
      loginFeedback.classList.remove("is-success");

      const response = await window.apiClient.post("api/login.php", {
        email,
        password,
      });

      loginFeedback.textContent = "Login realizado com sucesso. Redirecionando...";
      loginFeedback.classList.add("is-success");

      window.setTimeout(() => {
        window.location.href = resolveHomeUrl(response.data || {});
      }, 250);
    } catch (error) {
      loginFeedback.textContent = error.message || "Não foi possível entrar.";
      loginFeedback.classList.remove("is-success");
    }
  });
}
