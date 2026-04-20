const ACCESS_HISTORY_PAGE_SIZE = 5;

const accessBuilder = document.querySelector("[data-access-builder]");
const accessCompanySelect = document.querySelector("[data-access-company]");
const accessFormSelect = document.querySelector("[data-access-form]");
const accessScopeSelect = document.querySelector("[data-access-scope]");
const accessExpiresAtInput = document.querySelector("[data-access-expires-at]");
const accessFeedback = document.querySelector("[data-access-feedback]");

const activeCodeCard = document.querySelector("[data-active-code-card]");
const activeCodeTitle = document.querySelector("[data-active-code-title]");
const activeCodeValidity = document.querySelector("[data-active-code-validity]");
const activeCodeMeta = document.querySelector("[data-active-code-meta]");
const activeCodeValue = document.querySelector("[data-active-code-value]");
const copyAccessCodeButton = document.querySelector("[data-copy-access-code]");
const regenerateAccessCodeButton = document.querySelector("[data-regenerate-access-code]");
const revokeAccessCodeButton = document.querySelector("[data-revoke-access-code]");

const accessTotalLabel = document.querySelector("[data-access-total]");
const accessCompletedLabel = document.querySelector("[data-access-completed]");
const accessPendingLabel = document.querySelector("[data-access-pending]");

const accessHistorySearch = document.querySelector("[data-access-history-search]");
const exportAccessHistoryButton = document.querySelector("[data-export-access-history]");
const accessHistoryBody = document.querySelector("[data-access-history-body]");
const accessHistoryResults = document.querySelector("[data-access-history-results]");
const accessHistoryPagination = document.querySelector("[data-access-history-pagination]");

let companiesDb = [];
let formsDb = [];
let accessCodesDb = [];
let accessHistoryDb = [];
let filteredAccessHistory = [];
let currentAccessHistoryPage = 1;
let isSubmittingAccessCode = false;

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

function parseDateValue(dateValue) {
  if (!dateValue) {
    return null;
  }

  const normalized = String(dateValue).trim().replace(" ", "T");
  const parsedDate = new Date(normalized);

  return Number.isNaN(parsedDate.getTime()) ? null : parsedDate;
}

function formatDateTime(dateValue) {
  const parsedDate = parseDateValue(dateValue);

  if (!parsedDate) {
    return dateValue || "--";
  }

  return new Intl.DateTimeFormat("pt-BR", {
    dateStyle: "short",
    timeStyle: "short",
  }).format(parsedDate);
}

function toDateTimeLocalValue(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  const hours = String(date.getHours()).padStart(2, "0");
  const minutes = String(date.getMinutes()).padStart(2, "0");

  return `${year}-${month}-${day}T${hours}:${minutes}`;
}

function resolveFormDatabaseId(form) {
  return Number.parseInt(String(form?.databaseId || 0), 10) || 0;
}

function setAccessFeedback(message, isSuccess = false) {
  if (!accessFeedback) {
    return;
  }

  accessFeedback.textContent = message;
  accessFeedback.classList.toggle("is-success", isSuccess);
}

function setGenerationControlsDisabled(isDisabled) {
  if (accessCompanySelect) {
    accessCompanySelect.disabled = isDisabled || !companiesDb.length;
  }

  if (accessFormSelect) {
    accessFormSelect.disabled = isDisabled || !formsDb.length;
  }

  if (accessScopeSelect) {
    accessScopeSelect.disabled = isDisabled;
  }

  if (accessExpiresAtInput) {
    accessExpiresAtInput.disabled = isDisabled;
  }

  if (accessBuilder) {
    const submitButton = accessBuilder.querySelector('button[type="submit"]');

    if (submitButton) {
      submitButton.disabled = isDisabled || !companiesDb.length || !formsDb.length;
    }
  }
}

function ensureDefaultExpiry() {
  if (!accessExpiresAtInput || accessExpiresAtInput.value) {
    return;
  }

  const tomorrow = new Date();
  tomorrow.setDate(tomorrow.getDate() + 1);
  tomorrow.setHours(23, 59, 0, 0);
  accessExpiresAtInput.value = toDateTimeLocalValue(tomorrow);
}

function getSelectedCompanyId() {
  return Number.parseInt(accessCompanySelect?.value || "0", 10) || 0;
}

function getSelectedFormId() {
  return Number.parseInt(accessFormSelect?.value || "0", 10) || 0;
}

function buildSelectPlaceholder(message) {
  return `<option value="">${escapeHtml(message)}</option>`;
}

function populateCompanyOptions(preservedCompanyId = 0) {
  if (!accessCompanySelect) {
    return;
  }

  if (!companiesDb.length) {
    accessCompanySelect.innerHTML = buildSelectPlaceholder("Nenhuma empresa cadastrada");
    accessCompanySelect.disabled = true;
    return;
  }

  const options = companiesDb
    .map((company) => {
      const suffix = company.status === "inactive" ? " (Inativa)" : "";
      return `<option value="${company.id}">${escapeHtml(company.name + suffix)}</option>`;
    })
    .join("");

  accessCompanySelect.innerHTML = options;
  accessCompanySelect.disabled = false;

  const nextCompanyId =
    preservedCompanyId && companiesDb.some((company) => Number(company.id) === preservedCompanyId)
      ? preservedCompanyId
      : Number(companiesDb[0]?.id || 0);

  accessCompanySelect.value = nextCompanyId ? String(nextCompanyId) : "";
}

function populateFormOptions(preservedFormId = 0) {
  if (!accessFormSelect) {
    return;
  }

  const activeForms = formsDb.filter((form) => form.status !== "inactive");
  const sourceForms = activeForms.length ? activeForms : formsDb;

  if (!sourceForms.length) {
    accessFormSelect.innerHTML = buildSelectPlaceholder("Nenhum formulario cadastrado");
    accessFormSelect.disabled = true;
    return;
  }

  const options = sourceForms
    .map((form) => {
      const databaseId = resolveFormDatabaseId(form);
      const publicCode = form.publicCode || form.id || "";
      return `<option value="${databaseId}">${escapeHtml(form.name)}${publicCode ? ` (${escapeHtml(publicCode)})` : ""}</option>`;
    })
    .join("");

  accessFormSelect.innerHTML = options;
  accessFormSelect.disabled = false;

  const nextFormId =
    preservedFormId && sourceForms.some((form) => resolveFormDatabaseId(form) === preservedFormId)
      ? preservedFormId
      : resolveFormDatabaseId(sourceForms[0]);

  accessFormSelect.value = nextFormId ? String(nextFormId) : "";
}

function ensureScopeOptionExists(scopeLabel) {
  if (!accessScopeSelect || !scopeLabel) {
    return;
  }

  const hasOption = Array.from(accessScopeSelect.options).some((option) => option.value === scopeLabel);

  if (!hasOption) {
    const option = document.createElement("option");
    option.value = scopeLabel;
    option.textContent = scopeLabel;
    accessScopeSelect.appendChild(option);
  }
}

function syncBuilderWithActiveCode() {
  const activeCode = getActiveCodeForSelectedCompany();

  if (!activeCode) {
    ensureDefaultExpiry();
    return;
  }

  if (accessFormSelect && activeCode.formId) {
    accessFormSelect.value = String(activeCode.formId);
  }

  if (accessScopeSelect && activeCode.scopeLabel) {
    ensureScopeOptionExists(activeCode.scopeLabel);
    accessScopeSelect.value = activeCode.scopeLabel;
  }

  if (accessExpiresAtInput) {
    const expiresAt = parseDateValue(activeCode.expiresAt);

    if (expiresAt) {
      accessExpiresAtInput.value = toDateTimeLocalValue(expiresAt);
    }
  }
}

async function loadAccessData(options = {}) {
  const preservedCompanyId = options.preservedCompanyId ?? getSelectedCompanyId();
  const preservedFormId = options.preservedFormId ?? getSelectedFormId();

  try {
    setGenerationControlsDisabled(true);

    const [companiesResponse, formsResponse, accessCodesResponse] = await Promise.all([
      window.apiClient.get("api/companies.php"),
      window.apiClient.get("api/forms.php"),
      window.apiClient.get("api/access-codes.php"),
    ]);

    companiesDb = Array.isArray(companiesResponse.data) ? companiesResponse.data : [];
    formsDb = Array.isArray(formsResponse.data) ? formsResponse.data : [];
    accessCodesDb = Array.isArray(accessCodesResponse.data?.codes) ? accessCodesResponse.data.codes : [];
    accessHistoryDb = Array.isArray(accessCodesResponse.data?.history) ? accessCodesResponse.data.history : [];

    populateCompanyOptions(preservedCompanyId);
    populateFormOptions(preservedFormId);
    ensureDefaultExpiry();
    syncBuilderWithActiveCode();
    applyAccessHistoryFilters();
  } catch (error) {
    setAccessFeedback(error.message || "Nao foi possivel carregar a geracao de acesso.", false);

    if (accessHistoryBody) {
      accessHistoryBody.innerHTML = "";
    }

    if (accessHistoryResults) {
      accessHistoryResults.textContent = error.message || "Nao foi possivel carregar os acessos.";
    }
  } finally {
    setGenerationControlsDisabled(isSubmittingAccessCode);
  }
}

function getActiveCodeForSelectedCompany() {
  const selectedCompanyId = getSelectedCompanyId();

  return (
    accessCodesDb.find(
      (code) => Number(code.companyId) === selectedCompanyId && code.isActive && !code.isExpired,
    ) || null
  );
}

function renderActiveCodeCard() {
  const activeCode = getActiveCodeForSelectedCompany();
  const selectedCompany = companiesDb.find((company) => Number(company.id) === getSelectedCompanyId()) || null;
  const hasActiveCode = Boolean(activeCode);

  if (activeCodeTitle) {
    activeCodeTitle.textContent = hasActiveCode ? "Codigo da Empresa Ativo" : "Nenhum codigo ativo";
  }

  if (activeCodeValidity) {
    activeCodeValidity.textContent = hasActiveCode
      ? `Valido ate ${formatDateTime(activeCode.expiresAt)}`
      : "Gere um novo codigo para liberar o acesso.";
  }

  if (activeCodeMeta) {
    activeCodeMeta.textContent = hasActiveCode
      ? `${activeCode.companyName} | ${activeCode.formName} | ${activeCode.scopeLabel}`
      : `${selectedCompany?.name || "Selecione uma empresa"} | sem codigo ativo`;
  }

  if (activeCodeValue) {
    activeCodeValue.textContent = hasActiveCode ? activeCode.code : "--";
  }

  if (activeCodeCard) {
    activeCodeCard.classList.toggle("code-card--inactive", !hasActiveCode);
  }

  if (copyAccessCodeButton) {
    copyAccessCodeButton.disabled = !hasActiveCode;
  }

  if (regenerateAccessCodeButton) {
    regenerateAccessCodeButton.disabled = !hasActiveCode;
  }

  if (revokeAccessCodeButton) {
    revokeAccessCodeButton.disabled = !hasActiveCode;
  }
}

function renderAccessStats(baseHistory) {
  const total = baseHistory.length;
  const completed = baseHistory.filter((item) => item.status === "done").length;
  const pending = baseHistory.filter((item) => item.status === "pending").length;

  if (accessTotalLabel) {
    accessTotalLabel.textContent = String(total);
  }

  if (accessCompletedLabel) {
    accessCompletedLabel.textContent = String(completed);
  }

  if (accessPendingLabel) {
    accessPendingLabel.textContent = String(pending);
  }
}

function getAccessHistoryTotalPages() {
  return Math.max(1, Math.ceil(filteredAccessHistory.length / ACCESS_HISTORY_PAGE_SIZE));
}

function getCurrentAccessHistoryPageItems() {
  const totalPages = getAccessHistoryTotalPages();
  currentAccessHistoryPage = Math.min(currentAccessHistoryPage, totalPages);

  const startIndex = (currentAccessHistoryPage - 1) * ACCESS_HISTORY_PAGE_SIZE;
  return filteredAccessHistory.slice(startIndex, startIndex + ACCESS_HISTORY_PAGE_SIZE);
}

function buildAccessHistoryPaginationTokens(totalPages, currentPage) {
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

function renderAccessHistoryPagination() {
  if (!accessHistoryPagination) {
    return;
  }

  const totalItems = filteredAccessHistory.length;
  const totalPages = getAccessHistoryTotalPages();
  const tokens = buildAccessHistoryPaginationTokens(totalPages, currentAccessHistoryPage);

  accessHistoryPagination.innerHTML = [
    `
      <button
        type="button"
        data-access-history-nav="prev"
        ${currentAccessHistoryPage === 1 || totalItems === 0 ? "disabled" : ""}
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
          data-access-history-page="${token}"
          class="${token === currentAccessHistoryPage ? "is-active" : ""}"
        >
          ${token}
        </button>
      `;
    }),
    `
      <button
        type="button"
        data-access-history-nav="next"
        ${currentAccessHistoryPage === totalPages || totalItems === 0 ? "disabled" : ""}
      >
        Proxima
      </button>
    `,
  ].join("");
}

function renderAccessHistoryTable() {
  if (!accessHistoryBody) {
    return;
  }

  const pageItems = getCurrentAccessHistoryPageItems();

  if (!filteredAccessHistory.length) {
    accessHistoryBody.innerHTML = "";
  } else {
    accessHistoryBody.innerHTML = pageItems
      .map((item) => {
        const statusClass =
          item.status === "done"
            ? "access-status access-status--done"
            : "access-status access-status--pending";

        return `
          <article class="access-table__row">
            <span class="session-id">#${escapeHtml(item.sessionId)}</span>
            <span class="table-muted">${escapeHtml(formatDateTime(item.accessedAt))}</span>
            <span class="table-muted">${escapeHtml(item.ipMasked)}</span>
            <span class="${statusClass}">${escapeHtml(item.statusLabel)}</span>
            <button class="table-link-button" type="button" data-access-session-view="${item.id}">Ver</button>
          </article>
        `;
      })
      .join("");
  }

  if (accessHistoryResults) {
    const total = filteredAccessHistory.length;
    const start = total ? (currentAccessHistoryPage - 1) * ACCESS_HISTORY_PAGE_SIZE + 1 : 0;
    const end = total ? Math.min(currentAccessHistoryPage * ACCESS_HISTORY_PAGE_SIZE, total) : 0;
    accessHistoryResults.textContent = `Mostrando ${start} a ${end} de ${total} acessos`;
  }

  renderAccessHistoryPagination();
}

function applyAccessHistoryFilters(options = {}) {
  const { resetPage = true } = options;
  const selectedCompanyId = getSelectedCompanyId();
  const searchTerm = accessHistorySearch ? accessHistorySearch.value.trim().toLowerCase() : "";

  if (resetPage) {
    currentAccessHistoryPage = 1;
  }

  const companyHistory = accessHistoryDb.filter((item) => {
    return !selectedCompanyId || Number(item.companyId) === selectedCompanyId;
  });

  renderAccessStats(companyHistory);

  filteredAccessHistory = companyHistory.filter((item) => {
    if (!searchTerm) {
      return true;
    }

    return [
      item.sessionId,
      item.code,
      item.companyName,
      item.formName,
      item.statusLabel,
      item.ipMasked,
    ]
      .join(" ")
      .toLowerCase()
      .includes(searchTerm);
  });

  renderActiveCodeCard();
  renderAccessHistoryTable();
}

function exportCurrentHistory() {
  if (!filteredAccessHistory.length) {
    setAccessFeedback("Nao ha historico para exportar.", false);
    return;
  }

  const header = ["Sessao", "DataHora", "IP", "Status", "Codigo", "Empresa", "Formulario"];
  const rows = filteredAccessHistory.map((item) => [
    item.sessionId,
    formatDateTime(item.accessedAt),
    item.ipMasked,
    item.statusLabel,
    item.code,
    item.companyName,
    item.formName,
  ]);

  const csvContent = [header]
    .concat(rows)
    .map((row) => row.map((value) => `"${String(value).replace(/"/g, '""')}"`).join(";"))
    .join("\n");

  const blob = new Blob([csvContent], { type: "text/csv;charset=utf-8;" });
  const url = URL.createObjectURL(blob);
  const downloadLink = document.createElement("a");

  downloadLink.href = url;
  downloadLink.download = "historico-acessos.csv";
  downloadLink.click();

  URL.revokeObjectURL(url);
  setAccessFeedback("Historico exportado com sucesso.", true);
}

async function handleAccessBuilderSubmit(event) {
  event.preventDefault();

  const companyId = getSelectedCompanyId();
  const formId = getSelectedFormId();
  const scopeLabel = accessScopeSelect?.value || "Todos os setores (Codigo Global)";
  const expiresAt = accessExpiresAtInput?.value || "";

  if (!companyId) {
    setAccessFeedback("Selecione a empresa.", false);
    return;
  }

  if (!formId) {
    setAccessFeedback("Selecione o formulario.", false);
    return;
  }

  if (!expiresAt) {
    setAccessFeedback("Informe a validade do codigo.", false);
    return;
  }

  try {
    isSubmittingAccessCode = true;
    setGenerationControlsDisabled(true);
    setAccessFeedback("Gerando codigo...", false);

    await window.apiClient.post("api/access-codes.php", {
      companyId,
      formId,
      scopeLabel,
      expiresAt,
    });

    await loadAccessData({
      preservedCompanyId: companyId,
      preservedFormId: formId,
    });

    setAccessFeedback("Codigo gerado com sucesso e salvo no MySQL.", true);
  } catch (error) {
    setAccessFeedback(error.message || "Nao foi possivel gerar o codigo.", false);
  } finally {
    isSubmittingAccessCode = false;
    setGenerationControlsDisabled(false);
  }
}

async function handleRegenerateAccessCode() {
  const activeCode = getActiveCodeForSelectedCompany();

  if (!activeCode) {
    setAccessFeedback("Nao ha codigo ativo para regenerar.", false);
    return;
  }

  try {
    isSubmittingAccessCode = true;
    setGenerationControlsDisabled(true);
    setAccessFeedback("Regenerando codigo...", false);

    await window.apiClient.put("api/access-codes.php", {
      id: activeCode.id,
      action: "regenerate",
      expiresAt: accessExpiresAtInput?.value || activeCode.expiresAt,
      scopeLabel: accessScopeSelect?.value || activeCode.scopeLabel,
    });

    await loadAccessData({
      preservedCompanyId: Number(activeCode.companyId),
      preservedFormId: Number(activeCode.formId),
    });

    setAccessFeedback("Codigo regenerado com sucesso.", true);
  } catch (error) {
    setAccessFeedback(error.message || "Nao foi possivel regenerar o codigo.", false);
  } finally {
    isSubmittingAccessCode = false;
    setGenerationControlsDisabled(false);
  }
}

async function handleRevokeAccessCode() {
  const activeCode = getActiveCodeForSelectedCompany();

  if (!activeCode) {
    setAccessFeedback("Nao ha codigo ativo para revogar.", false);
    return;
  }

  try {
    isSubmittingAccessCode = true;
    setGenerationControlsDisabled(true);
    setAccessFeedback("Revogando codigo...", false);

    await window.apiClient.put("api/access-codes.php", {
      id: activeCode.id,
      action: "revoke",
    });

    await loadAccessData({
      preservedCompanyId: Number(activeCode.companyId),
      preservedFormId: Number(activeCode.formId),
    });

    setAccessFeedback("Codigo revogado com sucesso.", true);
  } catch (error) {
    setAccessFeedback(error.message || "Nao foi possivel revogar o codigo.", false);
  } finally {
    isSubmittingAccessCode = false;
    setGenerationControlsDisabled(false);
  }
}

async function handleCopyAccessCode() {
  const activeCode = getActiveCodeForSelectedCompany();

  if (!activeCode) {
    setAccessFeedback("Nao ha codigo ativo para copiar.", false);
    return;
  }

  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(activeCode.code);
      setAccessFeedback("Codigo copiado para a area de transferencia.", true);
      return;
    }

    const fallbackInput = document.createElement("textarea");
    fallbackInput.value = activeCode.code;
    document.body.appendChild(fallbackInput);
    fallbackInput.select();
    document.execCommand("copy");
    fallbackInput.remove();
    setAccessFeedback("Codigo copiado para a area de transferencia.", true);
  } catch (error) {
    setAccessFeedback("Nao foi possivel copiar o codigo.", false);
  }
}

function handleAccessHistoryBodyClick(event) {
  const viewButton = event.target.closest("[data-access-session-view]");

  if (!viewButton) {
    return;
  }

  const sessionId = Number.parseInt(viewButton.getAttribute("data-access-session-view"), 10) || 0;
  const session = accessHistoryDb.find((item) => Number(item.id) === sessionId);

  if (!session) {
    return;
  }

  window.alert(
    [
      `Sessao: ${session.sessionId}`,
      `Codigo: ${session.code}`,
      `Empresa: ${session.companyName}`,
      `Formulario: ${session.formName}`,
      `Status: ${session.statusLabel}`,
      `IP: ${session.ipMasked}`,
      `Acesso: ${formatDateTime(session.accessedAt)}`,
    ].join("\n"),
  );
}

function handleAccessHistoryPaginationClick(event) {
  const pageButton = event.target.closest("[data-access-history-page]");

  if (pageButton) {
    currentAccessHistoryPage =
      Number.parseInt(pageButton.getAttribute("data-access-history-page"), 10) || 1;
    renderAccessHistoryTable();
    return;
  }

  const navButton = event.target.closest("[data-access-history-nav]");

  if (!navButton) {
    return;
  }

  const action = navButton.getAttribute("data-access-history-nav");
  const totalPages = getAccessHistoryTotalPages();

  if (action === "prev" && currentAccessHistoryPage > 1) {
    currentAccessHistoryPage -= 1;
  }

  if (action === "next" && currentAccessHistoryPage < totalPages) {
    currentAccessHistoryPage += 1;
  }

  renderAccessHistoryTable();
}

if (accessBuilder) {
  ensureDefaultExpiry();
  loadAccessData();

  accessBuilder.addEventListener("submit", handleAccessBuilderSubmit);

  if (accessCompanySelect) {
    accessCompanySelect.addEventListener("change", () => {
      syncBuilderWithActiveCode();
      applyAccessHistoryFilters();
    });
  }

  if (accessHistorySearch) {
    accessHistorySearch.addEventListener("input", () => {
      applyAccessHistoryFilters();
    });
  }

  if (copyAccessCodeButton) {
    copyAccessCodeButton.addEventListener("click", handleCopyAccessCode);
  }

  if (regenerateAccessCodeButton) {
    regenerateAccessCodeButton.addEventListener("click", handleRegenerateAccessCode);
  }

  if (revokeAccessCodeButton) {
    revokeAccessCodeButton.addEventListener("click", handleRevokeAccessCode);
  }

  if (exportAccessHistoryButton) {
    exportAccessHistoryButton.addEventListener("click", exportCurrentHistory);
  }

  if (accessHistoryBody) {
    accessHistoryBody.addEventListener("click", handleAccessHistoryBodyClick);
  }

  if (accessHistoryPagination) {
    accessHistoryPagination.addEventListener("click", handleAccessHistoryPaginationClick);
  }
}
