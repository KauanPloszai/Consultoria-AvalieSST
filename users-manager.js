const usersModal = document.querySelector("[data-users-modal]");
const openUsersModalButton = document.querySelector("[data-open-users-modal]");
const closeUsersModalButtons = document.querySelectorAll("[data-close-users-modal]");
const usersList = document.querySelector("[data-users-list]");
const usersForm = document.querySelector("[data-users-form]");
const usersFormTitle = document.querySelector("[data-users-form-title]");
const usersIdInput = document.querySelector("[data-users-id]");
const usersEmailInput = document.querySelector("[data-users-email]");
const usersPasswordInput = document.querySelector("[data-users-password]");
const usersRoleSelect = document.querySelector("[data-users-role]");
const usersCompanyField = document.querySelector("[data-users-company-field]");
const usersCompanySelect = document.querySelector("[data-users-company]");
const usersActiveInput = document.querySelector("[data-users-active]");
const usersFeedback = document.querySelector("[data-users-feedback]");
const usersDeleteButton = document.querySelector("[data-users-delete]");
const usersCancelButton = document.querySelector("[data-users-cancel]");
const usersNewButton = document.querySelector("[data-users-new]");
const usersSaveButton = document.querySelector("[data-users-save]");

const usersManagerState = {
  users: [],
  companies: [],
  selectedId: null,
  isLoaded: false,
};

function usersEscapeHtml(value) {
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

function usersSetFeedback(message, isSuccess = false) {
  if (!usersFeedback) {
    return;
  }

  usersFeedback.textContent = message;
  usersFeedback.classList.toggle("is-success", isSuccess);
}

function usersGetSelectedUser() {
  return usersManagerState.users.find((user) => Number(user.id) === Number(usersManagerState.selectedId)) || null;
}

function usersPopulateCompanyOptions() {
  if (!usersCompanySelect) {
    return;
  }

  const options = ['<option value="">Selecione uma empresa</option>']
    .concat(
      usersManagerState.companies.map(
        (company) => `<option value="${company.id}">${usersEscapeHtml(company.name)}</option>`,
      ),
    )
    .join("");

  usersCompanySelect.innerHTML = options;
}

function usersToggleCompanyField() {
  if (!usersCompanyField || !usersRoleSelect) {
    return;
  }

  const isCompanyRole = usersRoleSelect.value === "company";
  usersCompanyField.hidden = !isCompanyRole;

  if (!isCompanyRole && usersCompanySelect) {
    usersCompanySelect.value = "";
  }
}

function usersResetForm() {
  usersManagerState.selectedId = null;

  if (usersFormTitle) {
    usersFormTitle.textContent = "Novo usuário";
  }

  if (usersIdInput) {
    usersIdInput.value = "";
  }

  if (usersEmailInput) {
    usersEmailInput.value = "";
  }

  if (usersPasswordInput) {
    usersPasswordInput.value = "";
    usersPasswordInput.placeholder = "Digite a senha";
  }

  if (usersRoleSelect) {
    usersRoleSelect.value = "admin";
  }

  if (usersCompanySelect) {
    usersCompanySelect.value = "";
  }

  if (usersActiveInput) {
    usersActiveInput.checked = true;
  }

  if (usersDeleteButton) {
    usersDeleteButton.hidden = true;
  }

  usersToggleCompanyField();
  usersSetFeedback("");
  usersRenderList();
}

function usersFillForm(user) {
  if (!user) {
    usersResetForm();
    return;
  }

  usersManagerState.selectedId = Number(user.id);

  if (usersFormTitle) {
    usersFormTitle.textContent = "Editar usuário";
  }

  if (usersIdInput) {
    usersIdInput.value = String(user.id);
  }

  if (usersEmailInput) {
    usersEmailInput.value = user.email || "";
  }

  if (usersPasswordInput) {
    usersPasswordInput.value = "";
    usersPasswordInput.placeholder = "Deixe em branco para manter a senha atual";
  }

  if (usersRoleSelect) {
    usersRoleSelect.value = user.role || "admin";
  }

  usersToggleCompanyField();

  if (usersCompanySelect) {
    usersCompanySelect.value = user.companyId ? String(user.companyId) : "";
  }

  if (usersActiveInput) {
    usersActiveInput.checked = Boolean(user.isActive);
  }

  if (usersDeleteButton) {
    usersDeleteButton.hidden = false;
  }

  usersSetFeedback("");
  usersRenderList();
}

function usersRenderList() {
  if (!usersList) {
    return;
  }

  if (!usersManagerState.users.length) {
    usersList.innerHTML = '<div class="org-empty-state">Nenhum usuário cadastrado ainda.</div>';
    return;
  }

  usersList.innerHTML = usersManagerState.users
    .map((user) => {
      const isSelected = Number(user.id) === Number(usersManagerState.selectedId);

      return `
        <article class="user-card${isSelected ? " is-selected" : ""}" data-user-card="${user.id}">
          <div class="user-card__main">
            <div class="user-card__meta">
              <span class="user-role-pill user-role-pill--${usersEscapeHtml(user.role)}">${usersEscapeHtml(user.roleLabel)}</span>
              <span class="user-status-pill user-status-pill--${user.isActive ? "active" : "inactive"}">${user.isActive ? "Ativo" : "Inativo"}</span>
            </div>

            <strong>${usersEscapeHtml(user.name || "Usuário")}</strong>
            <span>${usersEscapeHtml(user.email)}</span>
            <small>${usersEscapeHtml(user.companyName || "Sem empresa vinculada")}</small>
          </div>

          <div class="user-card__actions">
            <button class="user-card__action" type="button" data-user-edit="${user.id}" aria-label="Editar usuário">
              <svg viewBox="0 0 24 24" role="presentation">
                <path d="m4 16.5 9.8-9.8a2.1 2.1 0 1 1 3 3L7 19.5 4 20l.5-3.5Z" fill="none" stroke="currentColor" stroke-linejoin="round" stroke-width="1.7" />
              </svg>
            </button>

            <button class="user-card__action user-card__action--danger" type="button" data-user-delete="${user.id}" aria-label="Excluir usuário">
              <svg viewBox="0 0 24 24" role="presentation">
                <path d="M7 7h10M9 7V5.8A1.8 1.8 0 0 1 10.8 4h2.4A1.8 1.8 0 0 1 15 5.8V7m-6 3.2V17m3-6.8V17m3-6.8V17M6.2 7h11.6l-.8 11.1A1.8 1.8 0 0 1 15.2 20H8.8A1.8 1.8 0 0 1 7 18.1L6.2 7Z" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" />
              </svg>
            </button>
          </div>
        </article>
      `;
    })
    .join("");
}

async function usersLoadDashboard(preferredUserId = null) {
  const response = await window.apiClient.get("api/users.php");
  const payload = response.data || {};

  usersManagerState.users = Array.isArray(payload.users) ? payload.users : [];
  usersManagerState.companies = Array.isArray(payload.companies) ? payload.companies : [];
  usersManagerState.isLoaded = true;

  usersPopulateCompanyOptions();

  const preferredUser =
    usersManagerState.users.find((user) => Number(user.id) === Number(preferredUserId)) ||
    usersGetSelectedUser() ||
    null;

  if (preferredUser) {
    usersFillForm(preferredUser);
    return;
  }

  usersResetForm();
}

async function usersOpenModal() {
  if (!usersModal) {
    return;
  }

  const session = window.appSessionPromise ? await window.appSessionPromise : window.appSession;

  if (!session || session.role !== "admin") {
    openUsersModalButton && (openUsersModalButton.hidden = true);
    return;
  }

  usersModal.hidden = false;
  document.body.classList.add("modal-open");

  window.setTimeout(() => {
    usersModal.classList.add("is-open");
    usersEmailInput?.focus();
  }, 0);

  if (!usersManagerState.isLoaded) {
    usersSetFeedback("Carregando usuários...");
  }

  void usersLoadDashboard().catch((error) => {
    usersSetFeedback(error.message || "Não foi possível carregar os usuários.");
  });
}

function usersCloseModal() {
  if (!usersModal) {
    return;
  }

  usersModal.classList.remove("is-open");
  document.body.classList.remove("modal-open");

  window.setTimeout(() => {
    usersModal.hidden = true;
  }, 160);
}

async function usersDelete(userId) {
  const normalizedUserId = Number.parseInt(String(userId || 0), 10) || 0;

  if (!normalizedUserId) {
    return;
  }

  const targetUser = usersManagerState.users.find((user) => Number(user.id) === normalizedUserId);

  if (!targetUser) {
    return;
  }

  const shouldDelete = window.confirm(`Deseja excluir o usuário ${targetUser.email}?`);

  if (!shouldDelete) {
    return;
  }

  try {
    usersSetFeedback("Excluindo usuário...");
    await window.apiClient.delete(`api/users.php?id=${normalizedUserId}`);
    await usersLoadDashboard();
    usersSetFeedback("Usuário excluído com sucesso.", true);
  } catch (error) {
    usersSetFeedback(error.message || "Não foi possível excluir o usuário.");
  }
}

async function usersHandleSubmit(event) {
  event.preventDefault();

  const payload = {
    email: usersEmailInput?.value.trim().toLowerCase() || "",
    password: usersPasswordInput?.value.trim() || "",
    role: usersRoleSelect?.value || "admin",
    companyId: usersCompanySelect?.value ? Number(usersCompanySelect.value) : null,
    isActive: Boolean(usersActiveInput?.checked),
  };
  const editingId = Number.parseInt(usersIdInput?.value || "0", 10) || 0;

  if (!payload.email) {
    usersSetFeedback("Informe o e-mail do usuário.");
    usersEmailInput?.focus();
    return;
  }

  if (!editingId && !payload.password) {
    usersSetFeedback("Informe a senha do usuário.");
    usersPasswordInput?.focus();
    return;
  }

  if (payload.role === "company" && !payload.companyId) {
    usersSetFeedback("Selecione a empresa vinculada para este usuário.");
    usersCompanySelect?.focus();
    return;
  }

  try {
    usersSaveButton && (usersSaveButton.disabled = true);
    usersSetFeedback(editingId ? "Atualizando usuário..." : "Criando usuário...");

    if (editingId) {
      await window.apiClient.put("api/users.php", {
        id: editingId,
        ...payload,
      });
    } else {
      await window.apiClient.post("api/users.php", payload);
    }

    if (!editingId) {
      usersResetForm();
      await usersLoadDashboard();
    } else {
      await usersLoadDashboard(editingId);
    }

    usersSetFeedback(editingId ? "Usuário atualizado com sucesso." : "Usuário criado com sucesso.", true);
  } catch (error) {
    usersSetFeedback(error.message || "Não foi possível salvar o usuário.");
  } finally {
    usersSaveButton && (usersSaveButton.disabled = false);
  }
}

function usersHandleListClick(event) {
  const editButton = event.target.closest("[data-user-edit]");

  if (editButton) {
    const userId = Number.parseInt(editButton.getAttribute("data-user-edit"), 10) || 0;
    const user = usersManagerState.users.find((item) => Number(item.id) === userId);

    if (user) {
      usersFillForm(user);
    }

    return;
  }

  const deleteButton = event.target.closest("[data-user-delete]");

  if (deleteButton) {
    void usersDelete(deleteButton.getAttribute("data-user-delete"));
    return;
  }

  const card = event.target.closest("[data-user-card]");

  if (!card) {
    return;
  }

  const userId = Number.parseInt(card.getAttribute("data-user-card"), 10) || 0;
  const user = usersManagerState.users.find((item) => Number(item.id) === userId);

  if (user) {
    usersFillForm(user);
  }
}

async function usersInitialize() {
  if (!usersModal || !openUsersModalButton) {
    return;
  }

  openUsersModalButton.addEventListener("click", () => {
    void usersOpenModal();
  });
  closeUsersModalButtons.forEach((button) => {
    button.addEventListener("click", usersCloseModal);
  });

  usersNewButton?.addEventListener("click", usersResetForm);
  usersCancelButton?.addEventListener("click", usersResetForm);
  usersRoleSelect?.addEventListener("change", usersToggleCompanyField);
  usersForm?.addEventListener("submit", usersHandleSubmit);
  usersDeleteButton?.addEventListener("click", () => {
    void usersDelete(usersIdInput?.value || "");
  });
  usersList?.addEventListener("click", usersHandleListClick);

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && usersModal && !usersModal.hidden) {
      usersCloseModal();
    }
  });

  const session = window.appSessionPromise ? await window.appSessionPromise : window.appSession;

  if (!session || session.role !== "admin") {
    openUsersModalButton.hidden = true;
  }
}

void usersInitialize();
