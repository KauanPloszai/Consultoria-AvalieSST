const formTableBody = document.querySelector("[data-forms-table-body]");
const formsResultsLabel = document.querySelector("[data-forms-results]");
const formsPagination = document.querySelector("[data-forms-pagination]");
const openFormModalButton = document.querySelector("[data-open-form-modal]");
const openLinkModalButton = document.querySelector("[data-open-link-modal]");
const formModal = document.querySelector("[data-form-modal]");
const formModalTitle = document.querySelector("[data-form-modal-title]");
const closeFormModalButtons = document.querySelectorAll("[data-close-form-modal]");
const formBuilder = document.querySelector("[data-form-builder]");
const formNameInput = document.querySelector("[data-form-name-input]");
const formStatusInput = document.querySelector("[data-form-status-input]");
const questionList = document.querySelector("[data-question-list]");
const addQuestionButton = document.querySelector("[data-add-question]");
const formFeedback = document.querySelector("[data-form-feedback]");

const linkModal = document.querySelector("[data-link-modal]");
const closeLinkModalButtons = document.querySelectorAll("[data-close-link-modal]");
const linkBuilder = document.querySelector("[data-company-link-builder]");
const linkCompanySelect = document.querySelector("[data-link-company]");
const linkFormSelect = document.querySelector("[data-link-form]");
const linkCurrentCompany = document.querySelector("[data-link-current-company]");
const linkCurrentForm = document.querySelector("[data-link-current-form]");
const linkSelectedList = document.querySelector("[data-link-selected-list]");
const linkFeedback = document.querySelector("[data-company-link-feedback]");
const linkList = document.querySelector("[data-link-list]");
const linkListCount = document.querySelector("[data-link-list-count]");
const removeLinkButton = document.querySelector("[data-remove-link]");

const fixedScaleOptions = [
  "1 = Nunca",
  "2 = Raramente",
  "3 = Às vezes",
  "4 = Frequentemente",
  "5 = Sempre",
];

const FORMS_PAGE_SIZE = 10;

let formsDb = [];
let editingFormId = null;
let currentFormsPage = 1;
let linkDashboard = {
  companies: [],
  forms: [],
  links: [],
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

function formatDate(dateString) {
  const date = new Date(`${dateString}T00:00:00`);

  if (Number.isNaN(date.getTime())) {
    return dateString;
  }

  return new Intl.DateTimeFormat("pt-BR").format(date);
}

function buildFormLabel(form) {
  if (!form) {
    return "";
  }

  return `${form.name}${form.publicCode ? ` (${form.publicCode})` : ""}`;
}

function createQuestionItem(questionText = "") {
  const questionWrapper = document.createElement("article");
  questionWrapper.className = "question-item";

  const scaleMarkup = fixedScaleOptions
    .map((option) => `<span class="question-item__scale-pill">${option}</span>`)
    .join("");

  questionWrapper.innerHTML = `
    <div class="question-item__header">
      <strong class="question-item__title">Pergunta</strong>
      <button class="question-item__remove" type="button" aria-label="Remover pergunta">
        <svg viewBox="0 0 24 24" role="presentation">
          <path d="m7 7 10 10M17 7 7 17" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="2" />
        </svg>
      </button>
    </div>
    <label class="question-item__field">
      <span>Texto da pergunta</span>
      <textarea rows="3" placeholder="Digite a pergunta que será respondida no questionário.">${escapeHtml(questionText)}</textarea>
    </label>
    <div class="question-item__scale">
      ${scaleMarkup}
    </div>
  `;

  return questionWrapper;
}

function updateQuestionTitles() {
  const questionItems = questionList.querySelectorAll(".question-item");

  questionItems.forEach((item, index) => {
    const title = item.querySelector(".question-item__title");
    const removeButton = item.querySelector(".question-item__remove");

    if (title) {
      title.textContent = `Pergunta ${index + 1}`;
    }

    if (removeButton) {
      removeButton.disabled = questionItems.length === 1;
    }
  });
}

function renderQuestionList(questions = [""]) {
  if (!questionList) {
    return;
  }

  questionList.innerHTML = "";

  questions.forEach((question) => {
    questionList.appendChild(createQuestionItem(question));
  });

  updateQuestionTitles();
}

function getFormById(formId) {
  return formsDb.find((form) => String(form.id) === String(formId)) || null;
}

function buildPaginationTokens(totalPages, currentPage) {
  if (totalPages <= 5) {
    return Array.from({ length: totalPages }, (_, index) => index + 1);
  }

  if (currentPage <= 3) {
    return [1, 2, 3, "...", totalPages];
  }

  if (currentPage >= totalPages - 2) {
    return [1, "...", totalPages - 2, totalPages - 1, totalPages];
  }

  return [1, "...", currentPage - 1, currentPage, currentPage + 1, "...", totalPages];
}

function renderFormsPagination(totalItems) {
  if (!formsPagination) {
    return;
  }

  const totalPages = Math.max(1, Math.ceil(totalItems / FORMS_PAGE_SIZE));
  const tokens = buildPaginationTokens(totalPages, currentFormsPage);

  formsPagination.innerHTML = [
    `
      <button
        type="button"
        data-forms-nav="prev"
        ${currentFormsPage === 1 || totalItems === 0 ? "disabled" : ""}
      >
        Anterior
      </button>
    `,
    ...tokens.map((token) => {
      if (token === "...") {
        return '<button type="button" class="pagination__ellipsis" disabled>...</button>';
      }

      return `
        <button
          type="button"
          data-forms-page="${token}"
          class="${token === currentFormsPage ? "is-active" : ""}"
        >
          ${token}
        </button>
      `;
    }),
    `
      <button
        type="button"
        data-forms-nav="next"
        ${currentFormsPage === totalPages || totalItems === 0 ? "disabled" : ""}
      >
        Próxima
      </button>
    `,
  ].join("");
}

function renderFormsTable() {
  if (!formTableBody) {
    return;
  }

  const total = formsDb.length;
  const totalPages = Math.max(1, Math.ceil(total / FORMS_PAGE_SIZE));
  currentFormsPage = Math.min(currentFormsPage, totalPages);

  const startIndex = (currentFormsPage - 1) * FORMS_PAGE_SIZE;
  const pageItems = formsDb.slice(startIndex, startIndex + FORMS_PAGE_SIZE);

  const markup = pageItems
    .map((form) => {
      const statusClass =
        form.status === "inactive" ? "status-pill status-pill--inactive" : "status-pill status-pill--active";
      const statusLabel = form.status === "inactive" ? "Inativo" : "Ativo";

      return `
        <article class="forms-table__row">
          <div class="forms-table__name">
            <strong>${escapeHtml(form.name)}</strong>
            <span>ID: ${escapeHtml(form.id)}</span>
            <span>${form.linkedCompaniesCount || 0} empresa(s) vinculada(s)</span>
          </div>
          <span class="forms-table__value">${form.questions.length}</span>
          <span class="${statusClass}">${statusLabel}</span>
          <span class="forms-table__date">${formatDate(form.createdAt)}</span>
          <button class="table-action" type="button" aria-label="Editar formulário" data-edit-form="${escapeHtml(form.id)}">
            <svg viewBox="0 0 24 24" role="presentation">
              <path d="m4 16.5 9.8-9.8a2.1 2.1 0 1 1 3 3L7 19.5 4 20l.5-3.5Z" fill="none" stroke="currentColor" stroke-linejoin="round" stroke-width="1.7" />
            </svg>
          </button>
        </article>
      `;
    })
    .join("");

  formTableBody.innerHTML = markup;

  if (formsResultsLabel) {
    const start = total ? startIndex + 1 : 0;
    const end = total ? Math.min(startIndex + FORMS_PAGE_SIZE, total) : 0;
    formsResultsLabel.innerHTML = `Mostrando <strong>${start}</strong> a <strong>${end}</strong> de <strong>${total}</strong> resultados`;
  }

  renderFormsPagination(total);
}

async function loadForms() {
  if (!formTableBody) {
    return;
  }

  try {
    const response = await window.apiClient.get("api/forms.php");
    formsDb = Array.isArray(response.data) ? response.data : [];
    renderFormsTable();
  } catch (error) {
    formTableBody.innerHTML = "";

    if (formsResultsLabel) {
      formsResultsLabel.innerHTML = `<strong>${escapeHtml(error.message || "Não foi possível carregar os formulários.")}</strong>`;
    }
  }
}

function getLinkCompanyById(companyId) {
  return linkDashboard.companies.find((company) => Number(company.id) === Number(companyId)) || null;
}

function getAvailableLinkForms() {
  const activeForms = linkDashboard.forms.filter((form) => form.status !== "inactive");
  return activeForms.length ? activeForms : linkDashboard.forms;
}

function isFormLinkedToCompany(company, formId) {
  if (!company || !formId) {
    return false;
  }

  return (company.linkedForms || []).some((form) => Number(form.id) === Number(formId));
}

function populateLinkCompanyOptions() {
  if (!linkCompanySelect) {
    return;
  }

  if (!linkDashboard.companies.length) {
    linkCompanySelect.innerHTML = '<option value="">Nenhuma empresa cadastrada</option>';
    return;
  }

  linkCompanySelect.innerHTML = linkDashboard.companies
    .map((company) => `<option value="${company.id}">${escapeHtml(company.name)}</option>`)
    .join("");
}

function populateLinkFormOptions() {
  if (!linkFormSelect) {
    return;
  }

  const sourceForms = getAvailableLinkForms();

  if (!sourceForms.length) {
    linkFormSelect.innerHTML = '<option value="">Nenhum formulário disponível</option>';
    return;
  }

  linkFormSelect.innerHTML = sourceForms
    .map((form) => `<option value="${form.id}">${escapeHtml(buildFormLabel(form))}</option>`)
    .join("");
}

function getRecommendedFormId(company, preferredFormId = null) {
  const availableForms = getAvailableLinkForms();

  if (!availableForms.length) {
    return "";
  }

  if (preferredFormId && availableForms.some((form) => Number(form.id) === Number(preferredFormId))) {
    return String(preferredFormId);
  }

  const currentValue = Number.parseInt(linkFormSelect?.value || "0", 10) || 0;

  if (currentValue && availableForms.some((form) => Number(form.id) === currentValue)) {
    return String(currentValue);
  }

  if (company) {
    const firstUnlinkedForm = availableForms.find((form) => !isFormLinkedToCompany(company, form.id));

    if (firstUnlinkedForm) {
      return String(firstUnlinkedForm.id);
    }

    if (company.linkedForms?.length) {
      return String(company.linkedForms[0].id);
    }
  }

  return String(availableForms[0].id);
}

function syncLinkFormSelection(preferredFormId = null) {
  if (!linkFormSelect) {
    return;
  }

  const company = getLinkCompanyById(linkCompanySelect?.value);
  const nextFormId = getRecommendedFormId(company, preferredFormId);

  if (nextFormId) {
    linkFormSelect.value = nextFormId;
  }
}

function renderSelectedCompanyLinks() {
  if (!linkSelectedList) {
    return;
  }

  const selectedCompany = getLinkCompanyById(linkCompanySelect?.value);
  const selectedFormId = Number.parseInt(linkFormSelect?.value || "0", 10) || 0;

  if (!selectedCompany) {
    linkSelectedList.innerHTML = '<div class="company-link-selected-empty">Selecione uma empresa para visualizar e gerenciar os formulários vinculados.</div>';
    return;
  }

  if (!selectedCompany.linkedFormsCount) {
    linkSelectedList.innerHTML = '<div class="company-link-selected-empty">Nenhum formulário foi vinculado a esta empresa ainda.</div>';
    return;
  }

  linkSelectedList.innerHTML = selectedCompany.linkedForms
    .map((form) => {
      const isSelected = Number(form.id) === selectedFormId;
      const isPrimary = Number(selectedCompany.activeFormId || 0) === Number(form.id);

      return `
        <article class="company-link-selected-row${isSelected ? " is-selected" : ""}">
          <div class="company-link-selected-row__info">
            <strong>${escapeHtml(form.name)}</strong>
            <span>${escapeHtml(form.publicCode || "Sem código público")}</span>
          </div>

          <div class="company-link-selected-row__badges">
            ${isPrimary ? '<span class="sector-tag sector-tag--primary">Principal</span>' : ""}
            ${isSelected ? '<span class="sector-tag">Selecionado</span>' : ""}
          </div>

          <div class="company-link-selected-row__actions">
            <button
              class="table-action"
              type="button"
              aria-label="Selecionar formulário"
              data-select-company-link="${selectedCompany.id}"
              data-select-form-link="${form.id}"
            >
              <svg viewBox="0 0 24 24" role="presentation">
                <path d="m4 12 5 5 11-11" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" />
              </svg>
            </button>

            <button
              class="table-action"
              type="button"
              aria-label="Remover formulário"
              data-remove-company-form="${selectedCompany.id}:${form.id}"
            >
              <svg viewBox="0 0 24 24" role="presentation">
                <path d="M7 7h10M9 7V5.8A.8.8 0 0 1 9.8 5h4.4a.8.8 0 0 1 .8.8V7m-6 3v6m4-6v6M7.8 19h8.4a1 1 0 0 0 1-.9l.7-9.1H6.1l.7 9.1a1 1 0 0 0 1 .9Z" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" />
              </svg>
            </button>
          </div>
        </article>
      `;
    })
    .join("");
}

function renderLinkList() {
  if (!linkList) {
    return;
  }

  const linkedCompanies = linkDashboard.companies
    .filter((company) => (company.linkedFormsCount || 0) > 0)
    .sort((left, right) => String(left.name).localeCompare(String(right.name), "pt-BR"));

  if (!linkedCompanies.length) {
    linkList.innerHTML = '<p class="company-link-list__empty">Nenhuma empresa possui formulários vinculados no momento.</p>';
  } else {
    linkList.innerHTML = linkedCompanies
      .map((company) => {
        const previewForms = (company.linkedForms || []).slice(0, 3);
        const remainingForms = Math.max(0, (company.linkedForms || []).length - previewForms.length);

        return `
          <article class="company-link-row">
            <div class="company-link-row__meta">
              <strong>${escapeHtml(company.name)}</strong>
              <span>${company.linkedFormsCount || 0} formulário(s) vinculado(s)</span>
              <div class="tag-list">
                ${previewForms
                  .map((form) => {
                    const isPrimary = Number(company.activeFormId || 0) === Number(form.id);
                    return `<span class="sector-tag${isPrimary ? " sector-tag--primary" : ""}">${escapeHtml(buildFormLabel(form))}</span>`;
                  })
                  .join("")}
                ${remainingForms > 0 ? `<span class="sector-tag sector-tag--more">+${remainingForms}</span>` : ""}
              </div>
            </div>

            <button class="table-action" type="button" aria-label="Editar vínculos da empresa" data-edit-link-company="${company.id}">
              <svg viewBox="0 0 24 24" role="presentation">
                <path d="m4 16.5 9.8-9.8a2.1 2.1 0 1 1 3 3L7 19.5 4 20l.5-3.5Z" fill="none" stroke="currentColor" stroke-linejoin="round" stroke-width="1.7" />
              </svg>
            </button>
          </article>
        `;
      })
      .join("");
  }

  if (linkListCount) {
    linkListCount.textContent = `${linkedCompanies.length} empresa(s)`;
  }
}

function updateLinkPreview() {
  if (!linkCurrentCompany || !linkCurrentForm || !removeLinkButton) {
    return;
  }

  const selectedCompany = getLinkCompanyById(linkCompanySelect?.value);

  if (!selectedCompany) {
    linkCurrentCompany.textContent = "Selecione uma empresa";
    linkCurrentForm.textContent = "Nenhum formulário vinculado no momento.";
    removeLinkButton.disabled = true;
    renderSelectedCompanyLinks();
    return;
  }

  syncLinkFormSelection();

  const selectedFormId = Number.parseInt(linkFormSelect?.value || "0", 10) || 0;
  const linkedFormsCount = selectedCompany.linkedFormsCount || 0;

  linkCurrentCompany.textContent = selectedCompany.name;
  linkCurrentForm.textContent = linkedFormsCount
    ? `${linkedFormsCount} formulário(s) vinculado(s). Gere os códigos depois na tela de geração de acesso.`
    : "Nenhum formulário vinculado ainda. Escolha um formulário acima e clique em salvar vínculo.";

  removeLinkButton.disabled = !isFormLinkedToCompany(selectedCompany, selectedFormId);
  renderSelectedCompanyLinks();
}

async function loadCompanyFormLinks(options = {}) {
  if (!linkBuilder) {
    return;
  }

  const {
    preferredCompanyId = null,
    preferredFormId = null,
  } = options;

  const currentCompanyId = Number.parseInt(linkCompanySelect?.value || "0", 10) || 0;
  const currentFormId = Number.parseInt(linkFormSelect?.value || "0", 10) || 0;

  try {
    const response = await window.apiClient.get("api/company-form-links.php");
    linkDashboard = response.data || { companies: [], forms: [], links: [] };

    populateLinkCompanyOptions();
    populateLinkFormOptions();

    const targetCompanyId = preferredCompanyId || currentCompanyId || linkDashboard.companies[0]?.id || "";

    if (linkCompanySelect && targetCompanyId) {
      linkCompanySelect.value = String(targetCompanyId);
    }

    const selectedCompany = getLinkCompanyById(linkCompanySelect?.value);
    syncLinkFormSelection(preferredFormId || currentFormId || selectedCompany?.activeFormId || null);
    renderLinkList();
    updateLinkPreview();
  } catch (error) {
    if (linkFeedback) {
      linkFeedback.textContent = error.message || "Não foi possível carregar os vínculos.";
      linkFeedback.classList.remove("is-success");
    }
  }
}

function openFormModal(mode = "create", form = null) {
  if (!formModal || !formNameInput || !formStatusInput) {
    return;
  }

  editingFormId = form?.id || null;
  formNameInput.value = form?.name || "";
  formStatusInput.value = form?.status === "inactive" ? "inactive" : "active";
  renderQuestionList(form?.questions?.length ? form.questions : [""]);

  if (formFeedback) {
    formFeedback.textContent = "";
    formFeedback.classList.remove("is-success");
  }

  if (formModalTitle) {
    formModalTitle.textContent = mode === "edit" ? "Editar Formulário" : "Novo Formulário";
  }

  formModal.hidden = false;
  document.body.classList.add("modal-open");

  window.setTimeout(() => {
    formModal.classList.add("is-open");
    formNameInput.focus();
  }, 0);
}

function closeFormModal() {
  if (!formModal) {
    return;
  }

  formModal.classList.remove("is-open");
  document.body.classList.remove("modal-open");

  window.setTimeout(() => {
    formModal.hidden = true;
  }, 160);
}

async function openLinkModal(companyId = null) {
  if (!linkModal) {
    return;
  }

  if (linkFeedback) {
    linkFeedback.textContent = "";
    linkFeedback.classList.remove("is-success");
  }

  await loadCompanyFormLinks({
    preferredCompanyId: companyId ? Number(companyId) : null,
  });

  linkModal.hidden = false;
  document.body.classList.add("modal-open");

  window.setTimeout(() => {
    linkModal.classList.add("is-open");
    linkCompanySelect?.focus();
  }, 0);
}

function closeLinkModal() {
  if (!linkModal) {
    return;
  }

  linkModal.classList.remove("is-open");
  document.body.classList.remove("modal-open");

  window.setTimeout(() => {
    linkModal.hidden = true;
  }, 160);
}

function handleQuestionListClick(event) {
  const removeButton = event.target.closest(".question-item__remove");

  if (!removeButton) {
    return;
  }

  const questionItem = removeButton.closest(".question-item");

  if (!questionItem) {
    return;
  }

  questionItem.remove();

  if (!questionList.querySelector(".question-item")) {
    questionList.appendChild(createQuestionItem(""));
  }

  updateQuestionTitles();
}

function handleFormsTableClick(event) {
  const editButton = event.target.closest("[data-edit-form]");

  if (!editButton) {
    return;
  }

  const formId = editButton.getAttribute("data-edit-form");
  const form = getFormById(formId);

  if (form) {
    openFormModal("edit", form);
  }
}

function handleFormsPaginationClick(event) {
  const pageButton = event.target.closest("[data-forms-page]");

  if (pageButton) {
    currentFormsPage = Number.parseInt(pageButton.getAttribute("data-forms-page"), 10) || 1;
    renderFormsTable();
    return;
  }

  const navButton = event.target.closest("[data-forms-nav]");

  if (!navButton) {
    return;
  }

  const totalPages = Math.max(1, Math.ceil(formsDb.length / FORMS_PAGE_SIZE));
  const action = navButton.getAttribute("data-forms-nav");

  if (action === "prev" && currentFormsPage > 1) {
    currentFormsPage -= 1;
  }

  if (action === "next" && currentFormsPage < totalPages) {
    currentFormsPage += 1;
  }

  renderFormsTable();
}

async function handleLinkListClick(event) {
  const editButton = event.target.closest("[data-edit-link-company]");

  if (editButton) {
    event.preventDefault();
    await openLinkModal(editButton.getAttribute("data-edit-link-company"));
    return;
  }

  const selectButton = event.target.closest("[data-select-company-link][data-select-form-link]");

  if (selectButton) {
    const companyId = selectButton.getAttribute("data-select-company-link");
    const formId = selectButton.getAttribute("data-select-form-link");

    if (linkCompanySelect) {
      linkCompanySelect.value = String(companyId);
    }

    if (linkFormSelect) {
      linkFormSelect.value = String(formId);
    }

    updateLinkPreview();
    return;
  }

  const removeButton = event.target.closest("[data-remove-company-form]");

  if (removeButton) {
    const [companyId, formId] = String(removeButton.getAttribute("data-remove-company-form") || "").split(":");
    await handleRemoveLink(companyId, formId);
  }
}

async function handleFormSubmit(event) {
  event.preventDefault();

  const formName = formNameInput?.value.trim() || "";
  const formStatus = formStatusInput?.value === "inactive" ? "inactive" : "active";
  const questions = Array.from(questionList?.querySelectorAll("textarea") || [])
    .map((field) => field.value.trim())
    .filter(Boolean);

  if (!formName) {
    formFeedback.textContent = "Informe o nome do formulário.";
    formFeedback.classList.remove("is-success");
    formNameInput?.focus();
    return;
  }

  if (!questions.length) {
    formFeedback.textContent = "Adicione pelo menos uma pergunta.";
    formFeedback.classList.remove("is-success");
    questionList?.querySelector("textarea")?.focus();
    return;
  }

  try {
    formFeedback.textContent = editingFormId ? "Atualizando formulário..." : "Salvando formulário...";
    formFeedback.classList.remove("is-success");

    const payload = {
      id: editingFormId,
      name: formName,
      questions,
      status: formStatus,
    };

    if (editingFormId) {
      await window.apiClient.put("api/forms.php", payload);
    } else {
      await window.apiClient.post("api/forms.php", payload);
      currentFormsPage = 1;
    }

    await loadForms();
    closeFormModal();
  } catch (error) {
    formFeedback.textContent = error.message || "Não foi possível salvar o formulário.";
    formFeedback.classList.remove("is-success");
  }
}

async function handleLinkSubmit(event) {
  event.preventDefault();

  const companyId = Number.parseInt(linkCompanySelect?.value || "0", 10) || 0;
  const formId = Number.parseInt(linkFormSelect?.value || "0", 10) || 0;
  const company = getLinkCompanyById(companyId);

  if (!companyId) {
    linkFeedback.textContent = "Selecione a empresa para salvar o vínculo.";
    linkFeedback.classList.remove("is-success");
    return;
  }

  if (!formId) {
    linkFeedback.textContent = "Selecione o formulário que será liberado.";
    linkFeedback.classList.remove("is-success");
    return;
  }

  if (isFormLinkedToCompany(company, formId)) {
    linkFeedback.textContent = "Este formulário já está vinculado à empresa selecionada.";
    linkFeedback.classList.remove("is-success");
    return;
  }

  try {
    linkFeedback.textContent = "Salvando vínculo...";
    linkFeedback.classList.remove("is-success");

    await window.apiClient.post("api/company-form-links.php", {
      companyId,
      formId,
    });

    await Promise.all([
      loadForms(),
      loadCompanyFormLinks({
        preferredCompanyId: companyId,
        preferredFormId: formId,
      }),
    ]);

    linkFeedback.textContent = "Vínculo salvo com sucesso.";
    linkFeedback.classList.add("is-success");
  } catch (error) {
    linkFeedback.textContent = error.message || "Não foi possível salvar o vínculo.";
    linkFeedback.classList.remove("is-success");
  }
}

async function handleRemoveLink(companyIdOverride = null, formIdOverride = null) {
  const companyId = Number.parseInt(String(companyIdOverride || linkCompanySelect?.value || "0"), 10) || 0;
  const formId = Number.parseInt(String(formIdOverride || linkFormSelect?.value || "0"), 10) || 0;
  const company = getLinkCompanyById(companyId);

  if (!companyId) {
    return;
  }

  if (!formId) {
    linkFeedback.textContent = "Selecione o formulário que deseja remover da empresa.";
    linkFeedback.classList.remove("is-success");
    return;
  }

  if (!isFormLinkedToCompany(company, formId)) {
    linkFeedback.textContent = "Selecione um formulário já vinculado para remover.";
    linkFeedback.classList.remove("is-success");
    return;
  }

  try {
    linkFeedback.textContent = "Removendo vínculo...";
    linkFeedback.classList.remove("is-success");

    await window.apiClient.delete(`api/company-form-links.php?companyId=${companyId}&formId=${formId}`);

    await Promise.all([
      loadForms(),
      loadCompanyFormLinks({
        preferredCompanyId: companyId,
      }),
    ]);

    linkFeedback.textContent = "Vínculo removido com sucesso.";
    linkFeedback.classList.add("is-success");
  } catch (error) {
    linkFeedback.textContent = error.message || "Não foi possível remover o vínculo.";
    linkFeedback.classList.remove("is-success");
  }
}

function initializeFormPage() {
  if (!formTableBody) {
    return;
  }

  void loadForms();
  formTableBody.addEventListener("click", handleFormsTableClick);
  formsPagination?.addEventListener("click", handleFormsPaginationClick);
}

function initializeFormBuilderModal() {
  if (!formBuilder || !formModal) {
    return;
  }

  openFormModalButton?.addEventListener("click", () => {
    openFormModal("create");
  });

  closeFormModalButtons.forEach((button) => {
    button.addEventListener("click", closeFormModal);
  });

  addQuestionButton?.addEventListener("click", () => {
    questionList.appendChild(createQuestionItem(""));
    updateQuestionTitles();
    questionList.querySelector(".question-item:last-child textarea")?.focus();
  });

  formBuilder.addEventListener("submit", handleFormSubmit);
  questionList?.addEventListener("click", handleQuestionListClick);
}

function initializeCompanyLinkModal() {
  if (!linkModal || !linkBuilder) {
    return;
  }

  void loadCompanyFormLinks();

  openLinkModalButton?.addEventListener("click", async () => {
    await openLinkModal();
  });

  closeLinkModalButtons.forEach((button) => {
    button.addEventListener("click", closeLinkModal);
  });

  linkBuilder.addEventListener("submit", handleLinkSubmit);
  linkCompanySelect?.addEventListener("change", updateLinkPreview);
  linkFormSelect?.addEventListener("change", updateLinkPreview);
  removeLinkButton?.addEventListener("click", () => void handleRemoveLink());
  linkList?.addEventListener("click", (event) => {
    void handleLinkListClick(event);
  });
  linkSelectedList?.addEventListener("click", (event) => {
    void handleLinkListClick(event);
  });
}

initializeFormPage();
initializeFormBuilderModal();
initializeCompanyLinkModal();

window.openCompanyLinkModal = openLinkModal;
window.showCompanyLinkModal = (companyId = null) => {
  void openLinkModal(companyId);
  return false;
};
window.hideCompanyLinkModal = () => {
  closeLinkModal();
  return false;
};

document.addEventListener("keydown", (event) => {
  if (event.key === "Escape" && formModal && !formModal.hidden) {
    closeFormModal();
  }

  if (event.key === "Escape" && linkModal && !linkModal.hidden) {
    closeLinkModal();
  }
});
