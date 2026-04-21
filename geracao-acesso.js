const COMPANY_PAGE_SIZE = 5;
const HISTORY_PAGE_SIZE = 5;

const accessTotalCompaniesLabel = document.querySelector("[data-access-total-companies]");
const accessCompaniesWithLinksLabel = document.querySelector("[data-access-companies-with-links]");
const accessActiveCodesLabel = document.querySelector("[data-access-active-codes]");
const accessTotalSessionsLabel = document.querySelector("[data-access-total-sessions]");

const accessCompanySearch = document.querySelector("[data-access-company-search]");
const refreshAccessDirectoryButton = document.querySelector("[data-refresh-access-directory]");
const accessCompanyTableBody = document.querySelector("[data-access-company-table-body]");
const accessCompanyResults = document.querySelector("[data-access-company-results]");
const accessCompanyPagination = document.querySelector("[data-access-company-pagination]");

const accessHistorySearch = document.querySelector("[data-access-history-search]");
const exportAccessHistoryButton = document.querySelector("[data-export-access-history]");
const accessTotalLabel = document.querySelector("[data-access-total]");
const accessCompletedLabel = document.querySelector("[data-access-completed]");
const accessPendingLabel = document.querySelector("[data-access-pending]");
const accessHistoryBody = document.querySelector("[data-access-history-body]");
const accessHistoryResults = document.querySelector("[data-access-history-results]");
const accessHistoryPagination = document.querySelector("[data-access-history-pagination]");

const accessDetailModal = document.querySelector("[data-access-detail-modal]");
const closeAccessDetailButtons = document.querySelectorAll("[data-close-access-detail]");
const accessDetailTitle = document.querySelector("[data-access-detail-title]");
const accessDetailLinkedCount = document.querySelector("[data-access-detail-linked-count]");
const accessDetailActiveCount = document.querySelector("[data-access-detail-active-count]");
const accessDetailSessionsCount = document.querySelector("[data-access-detail-sessions-count]");
const accessDetailStatus = document.querySelector("[data-access-detail-status]");
const accessDetailScopeSelect = document.querySelector("[data-access-detail-scope]");
const accessDetailExpiresAtInput = document.querySelector("[data-access-detail-expires-at]");
const accessDetailFeedback = document.querySelector("[data-access-detail-feedback]");
const accessDetailSubtitle = document.querySelector("[data-access-detail-subtitle]");
const accessDetailFormsGrid = document.querySelector("[data-access-detail-forms-grid]");

let companiesDb = [];
let accessCodesDb = [];
let accessHistoryDb = [];
let filteredCompanies = [];
let filteredHistory = [];
let currentCompanyPage = 1;
let currentHistoryPage = 1;
let selectedDetailCompanyId = 0;
let companyStructuresCache = {};
let qrDataUrlCache = {};
let isLoadingDirectory = false;
let isSubmittingAccess = false;

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

function sanitizeFileName(value) {
  return String(value || "qrcode")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/[^a-zA-Z0-9_-]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .toLowerCase();
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

function ensureDefaultDetailExpiry() {
  if (!accessDetailExpiresAtInput || accessDetailExpiresAtInput.value) {
    return;
  }

  const tomorrow = new Date();
  tomorrow.setDate(tomorrow.getDate() + 1);
  tomorrow.setHours(23, 59, 0, 0);
  accessDetailExpiresAtInput.value = toDateTimeLocalValue(tomorrow);
}

function getCompanyById(companyId) {
  return companiesDb.find((company) => Number(company.id) === Number(companyId)) || null;
}

function getCodesForCompany(companyId) {
  return accessCodesDb
    .filter((code) => Number(code.companyId) === Number(companyId))
    .sort((left, right) => {
      const leftDate = parseDateValue(left.createdAt)?.getTime() || 0;
      const rightDate = parseDateValue(right.createdAt)?.getTime() || 0;
      return rightDate - leftDate;
    });
}

function getCodesForCompanyForm(companyId, formId) {
  return getCodesForCompany(companyId).filter((code) => Number(code.formId) === Number(formId));
}

function getSessionsForCompany(companyId) {
  return accessHistoryDb.filter((item) => Number(item.companyId) === Number(companyId));
}

function getActiveCodeForCompanyForm(companyId, formId) {
  return (
    getCodesForCompanyForm(companyId, formId).find((code) => code.isActive && !code.isExpired) || null
  );
}

function getCurrentCodeForCompanyForm(companyId, formId) {
  const activeCode = getActiveCodeForCompanyForm(companyId, formId);

  if (activeCode) {
    return activeCode;
  }

  return getCodesForCompanyForm(companyId, formId)[0] || null;
}

function getCompanyPrimaryCode(companyId) {
  const company = getCompanyById(companyId);

  if (!company) {
    return null;
  }

  if (company.activeFormId) {
    const activeFormCode = getCurrentCodeForCompanyForm(companyId, company.activeFormId);

    if (activeFormCode) {
      return activeFormCode;
    }
  }

  return getCodesForCompany(companyId)[0] || null;
}

function getAccessCodeStatus(accessCode) {
  if (accessCode?.isActive && !accessCode?.isExpired) {
    return {
      slug: "active",
      label: "Ativo",
    };
  }

  if (accessCode?.isExpired) {
    return {
      slug: "expired",
      label: "Expirado",
    };
  }

  return {
    slug: "inactive",
    label: "Revogado",
  };
}

function buildLocalQrDataUrl(accessUrl) {
  const normalizedAccessUrl = String(accessUrl || "").trim();

  if (!normalizedAccessUrl) {
    return "";
  }

  if (qrDataUrlCache[normalizedAccessUrl]) {
    return qrDataUrlCache[normalizedAccessUrl];
  }

  try {
    if (typeof qrcode === "function") {
      const qrInstance = qrcode(0, "M");
      qrInstance.addData(normalizedAccessUrl, "Byte");
      qrInstance.make();
      const dataUrl = qrInstance.createDataURL(8, 4);
      qrDataUrlCache[normalizedAccessUrl] = dataUrl;
      return dataUrl;
    }
  } catch (error) {
    return "";
  }

  return "";
}

function enrichAccessCode(accessCode) {
  const status = getAccessCodeStatus(accessCode);

  return {
    ...accessCode,
    statusSlug: status.slug,
    codeStatusLabel: status.label,
    qrDataUrl: buildLocalQrDataUrl(accessCode?.accessUrl),
  };
}

function getAccessCodeQrSource(accessCode) {
  return accessCode?.qrDataUrl || accessCode?.qrImageUrl || "";
}

function setAccessDetailFeedback(message, isSuccess = false) {
  if (!accessDetailFeedback) {
    return;
  }

  accessDetailFeedback.textContent = message;
  accessDetailFeedback.classList.toggle("is-success", isSuccess);
}

function companyStatusLabel(status) {
  return status === "inactive" ? "Inativa" : "Ativa";
}

function renderOverviewCards() {
  const companiesWithLinks = companiesDb.filter((company) => (company.linkedFormsCount || 0) > 0).length;
  const activeCodes = accessCodesDb.filter((code) => code.isActive && !code.isExpired).length;

  if (accessTotalCompaniesLabel) {
    accessTotalCompaniesLabel.textContent = String(companiesDb.length);
  }

  if (accessCompaniesWithLinksLabel) {
    accessCompaniesWithLinksLabel.textContent = String(companiesWithLinks);
  }

  if (accessActiveCodesLabel) {
    accessActiveCodesLabel.textContent = String(activeCodes);
  }

  if (accessTotalSessionsLabel) {
    accessTotalSessionsLabel.textContent = String(accessHistoryDb.length);
  }
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

function renderCompanyPagination() {
  if (!accessCompanyPagination) {
    return;
  }

  const totalItems = filteredCompanies.length;
  const totalPages = Math.max(1, Math.ceil(totalItems / COMPANY_PAGE_SIZE));
  currentCompanyPage = Math.min(currentCompanyPage, totalPages);
  const tokens = buildPaginationTokens(totalPages, currentCompanyPage);

  accessCompanyPagination.innerHTML = [
    `
      <button
        type="button"
        data-access-company-nav="prev"
        ${currentCompanyPage === 1 || totalItems === 0 ? "disabled" : ""}
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
          data-access-company-page="${token}"
          class="${token === currentCompanyPage ? "is-active" : ""}"
        >
          ${token}
        </button>
      `;
    }),
    `
      <button
        type="button"
        data-access-company-nav="next"
        ${currentCompanyPage === totalPages || totalItems === 0 ? "disabled" : ""}
      >
        Proxima
      </button>
    `,
  ].join("");
}

function renderCompanyTable() {
  if (!accessCompanyTableBody) {
    return;
  }

  const startIndex = (currentCompanyPage - 1) * COMPANY_PAGE_SIZE;
  const pageItems = filteredCompanies.slice(startIndex, startIndex + COMPANY_PAGE_SIZE);

  if (!filteredCompanies.length) {
    accessCompanyTableBody.innerHTML = "";
  } else {
    accessCompanyTableBody.innerHTML = pageItems
      .map((company) => {
        const activeCodesCount = getCodesForCompany(company.id).filter((code) => code.isActive && !code.isExpired)
          .length;
        const accessCount = getSessionsForCompany(company.id).length;
        const linkedForms = company.linkedForms || [];
        const primaryLinkedForm =
          linkedForms.find((form) => Number(company.activeFormId || 0) === Number(form.id)) || linkedForms[0] || null;
        const linkedFormsSummaryLabel =
          linkedForms.length === 1 ? "1 formulario vinculado" : `${linkedForms.length} formularios vinculados`;

        const formsMarkup =
          linkedForms.length > 0
            ? `
              <details class="linked-forms-dropdown">
                <summary class="linked-forms-dropdown__summary">
                  <div class="linked-forms-dropdown__copy">
                    <strong>${escapeHtml(linkedFormsSummaryLabel)}</strong>
                    <span>${escapeHtml(
                      primaryLinkedForm
                        ? `${primaryLinkedForm.name}${primaryLinkedForm.publicCode ? ` (${primaryLinkedForm.publicCode})` : ""}`
                        : "Clique para visualizar os formularios liberados.",
                    )}</span>
                  </div>
                  <span class="linked-forms-dropdown__chevron" aria-hidden="true">
                    <svg viewBox="0 0 24 24" role="presentation">
                      <path d="m7 10 5 5 5-5" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" />
                    </svg>
                  </span>
                </summary>

                <div class="linked-forms-dropdown__content">
                  <div class="tag-list linked-form-stack linked-form-stack--expanded">
                    ${linkedForms
                      .map((form) => {
                        const isPrimary = Number(company.activeFormId || 0) === Number(form.id);
                        const label = `${form.name}${form.publicCode ? ` (${form.publicCode})` : ""}`;

                        return `<span class="sector-tag${isPrimary ? " sector-tag--primary" : ""}">${escapeHtml(label)}</span>`;
                      })
                      .join("")}
                  </div>
                </div>
              </details>
            `
            : '<span class="table-text">Nenhum formulario vinculado</span>';

        return `
          <article class="company-table__row">
            <span class="table-check" aria-hidden="true"></span>

            <div class="company-cell">
              <span class="company-mark">
                <svg viewBox="0 0 24 24" role="presentation">
                  <path d="M7 20V7.5A1.5 1.5 0 0 1 8.5 6H16v14M4 20h16M10 9.5h1.5M10 12.5h1.5M10 15.5h1.5" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" />
                </svg>
              </span>

              <div class="company-meta">
                <strong>${escapeHtml(company.name)}</strong>
                <span>ID: ${escapeHtml(company.id)}</span>
                <span>${escapeHtml(companyStatusLabel(company.status))}</span>
              </div>
            </div>

            ${formsMarkup}
            <span class="table-text">${activeCodesCount}</span>
            <span class="table-text">${accessCount}</span>

            <button
              class="table-action"
              type="button"
              aria-label="Editar acessos da empresa"
              data-edit-access-company="${company.id}"
            >
              <svg viewBox="0 0 24 24" role="presentation">
                <path d="m4 16.5 9.8-9.8a2.1 2.1 0 1 1 3 3L7 19.5 4 20l.5-3.5Z" fill="none" stroke="currentColor" stroke-linejoin="round" stroke-width="1.7" />
              </svg>
            </button>
          </article>
        `;
      })
      .join("");
  }

  if (accessCompanyResults) {
    const total = filteredCompanies.length;
    const start = total ? startIndex + 1 : 0;
    const end = total ? Math.min(startIndex + COMPANY_PAGE_SIZE, total) : 0;
    accessCompanyResults.textContent = `Mostrando ${start} a ${end} de ${total} resultados`;
  }

  renderCompanyPagination();
}

function applyCompanyFilters(options = {}) {
  const { resetPage = true } = options;
  const searchTerm = accessCompanySearch ? accessCompanySearch.value.trim().toLowerCase() : "";

  if (resetPage) {
    currentCompanyPage = 1;
  }

  filteredCompanies = companiesDb
    .filter((company) => {
      if (!searchTerm) {
        return true;
      }

      return [
        company.name,
        company.cnpj,
        company.status,
        ...(company.linkedForms || []).map((form) => `${form.name} ${form.publicCode || ""}`),
      ]
        .join(" ")
        .toLowerCase()
        .includes(searchTerm);
    })
    .sort((left, right) => left.name.localeCompare(right.name, "pt-BR"));

  renderCompanyTable();
}

function renderHistoryStats() {
  const total = filteredHistory.length;
  const completed = filteredHistory.filter((item) => item.status === "done").length;
  const pending = filteredHistory.filter((item) => item.status === "pending").length;

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

function renderHistoryPagination() {
  if (!accessHistoryPagination) {
    return;
  }

  const totalItems = filteredHistory.length;
  const totalPages = Math.max(1, Math.ceil(totalItems / HISTORY_PAGE_SIZE));
  currentHistoryPage = Math.min(currentHistoryPage, totalPages);
  const tokens = buildPaginationTokens(totalPages, currentHistoryPage);

  accessHistoryPagination.innerHTML = [
    `
      <button
        type="button"
        data-access-history-nav="prev"
        ${currentHistoryPage === 1 || totalItems === 0 ? "disabled" : ""}
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
          class="${token === currentHistoryPage ? "is-active" : ""}"
        >
          ${token}
        </button>
      `;
    }),
    `
      <button
        type="button"
        data-access-history-nav="next"
        ${currentHistoryPage === Math.max(1, Math.ceil(totalItems / HISTORY_PAGE_SIZE)) || totalItems === 0 ? "disabled" : ""}
      >
        Proxima
      </button>
    `,
  ].join("");
}

function renderHistoryTable() {
  if (!accessHistoryBody) {
    return;
  }

  const startIndex = (currentHistoryPage - 1) * HISTORY_PAGE_SIZE;
  const pageItems = filteredHistory.slice(startIndex, startIndex + HISTORY_PAGE_SIZE);

  if (!filteredHistory.length) {
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
            <span class="table-muted">${escapeHtml(item.companyName)}<br />${escapeHtml(item.formName)}</span>
            <span class="table-muted">${escapeHtml(formatDateTime(item.accessedAt))}</span>
            <span class="${statusClass}">${escapeHtml(item.statusLabel)}</span>
            <button class="table-link-button" type="button" data-access-session-view="${item.id}">Ver</button>
          </article>
        `;
      })
      .join("");
  }

  if (accessHistoryResults) {
    const total = filteredHistory.length;
    const start = total ? startIndex + 1 : 0;
    const end = total ? Math.min(startIndex + HISTORY_PAGE_SIZE, total) : 0;
    accessHistoryResults.textContent = `Mostrando ${start} a ${end} de ${total} acessos`;
  }

  renderHistoryStats();
  renderHistoryPagination();
}

function applyHistoryFilters(options = {}) {
  const { resetPage = true } = options;
  const searchTerm = accessHistorySearch ? accessHistorySearch.value.trim().toLowerCase() : "";

  if (resetPage) {
    currentHistoryPage = 1;
  }

  filteredHistory = accessHistoryDb.filter((item) => {
    if (!searchTerm) {
      return true;
    }

    return [
      item.sessionId,
      item.companyName,
      item.formName,
      item.statusLabel,
      item.code,
      item.scopeLabel,
    ]
      .join(" ")
      .toLowerCase()
      .includes(searchTerm);
  });

  renderHistoryTable();
}

function buildSelectPlaceholder(message) {
  return `<option value="">${escapeHtml(message)}</option>`;
}

function buildScopeOptionValue(scopeType, sectorId = "", functionId = "") {
  return [scopeType, sectorId || "", functionId || ""].join("|");
}

function parseScopeOptionValue(rawValue) {
  const [scopeType, sectorId, functionId] = String(rawValue || "global||").split("|");

  return {
    scopeType: ["sector", "function"].includes(scopeType) ? scopeType : "global",
    sectorId: Number.parseInt(sectorId || "0", 10) || 0,
    functionId: Number.parseInt(functionId || "0", 10) || 0,
  };
}

function resolveScopeCodeFromAccessCode(accessCode) {
  if (!accessCode) {
    return buildScopeOptionValue("global");
  }

  if (accessCode.scopeType === "function" && accessCode.functionId) {
    return buildScopeOptionValue("function", accessCode.sectorId || "", accessCode.functionId);
  }

  if (accessCode.scopeType === "sector" && accessCode.sectorId) {
    return buildScopeOptionValue("sector", accessCode.sectorId);
  }

  return buildScopeOptionValue("global");
}

async function loadCompanyStructure(companyId) {
  const normalizedCompanyId = Number.parseInt(String(companyId || 0), 10) || 0;

  if (normalizedCompanyId <= 0) {
    return null;
  }

  if (companyStructuresCache[normalizedCompanyId]) {
    return companyStructuresCache[normalizedCompanyId];
  }

  const response = await window.apiClient.get(`api/company-structure.php?companyId=${normalizedCompanyId}`);
  const structure = response.data?.structure || null;
  companyStructuresCache[normalizedCompanyId] = structure;

  return structure;
}

function populateDetailScopeOptions(structure, preferredCode = null) {
  if (!accessDetailScopeSelect) {
    return;
  }

  const options = [
    {
      value: buildScopeOptionValue("global"),
      label: "Todos os setores (Codigo Global)",
    },
  ];

  (structure?.sectors || []).forEach((sector) => {
    options.push({
      value: buildScopeOptionValue("sector", sector.id),
      label: sector.name,
    });

    (sector.functions || []).forEach((companyFunction) => {
      options.push({
        value: buildScopeOptionValue("function", sector.id, companyFunction.id),
        label: `${sector.name} / ${companyFunction.name}`,
      });
    });
  });

  accessDetailScopeSelect.innerHTML =
    options.length > 0
      ? options.map((option) => `<option value="${option.value}">${escapeHtml(option.label)}</option>`).join("")
      : buildSelectPlaceholder("Nenhum escopo disponivel");

  if (preferredCode && options.some((option) => option.value === preferredCode)) {
    accessDetailScopeSelect.value = preferredCode;
    return;
  }

  accessDetailScopeSelect.value = options[0]?.value || "";
}

function getSelectedDetailScopePayload() {
  const scope = parseScopeOptionValue(accessDetailScopeSelect?.value || "");
  const selectedText = accessDetailScopeSelect?.selectedOptions?.[0]?.textContent?.trim();

  return {
    scopeType: scope.scopeType,
    sectorId: scope.sectorId || null,
    functionId: scope.functionId || null,
    scopeLabel: selectedText || "Todos os setores (Codigo Global)",
  };
}

function renderDetailModal() {
  if (!accessDetailModal || !accessDetailFormsGrid) {
    return;
  }

  const company = getCompanyById(selectedDetailCompanyId);

  if (!company) {
    accessDetailFormsGrid.innerHTML = "";
    return;
  }

  const linkedForms = company.linkedForms || [];
  const companySessions = getSessionsForCompany(company.id);
  const activeCodesCount = getCodesForCompany(company.id).filter((code) => code.isActive && !code.isExpired)
    .length;

  if (accessDetailTitle) {
    accessDetailTitle.textContent = company.name;
  }

  if (accessDetailLinkedCount) {
    accessDetailLinkedCount.textContent = String(linkedForms.length);
  }

  if (accessDetailActiveCount) {
    accessDetailActiveCount.textContent = String(activeCodesCount);
  }

  if (accessDetailSessionsCount) {
    accessDetailSessionsCount.textContent = String(companySessions.length);
  }

  if (accessDetailStatus) {
    accessDetailStatus.textContent = companyStatusLabel(company.status);
  }

  if (accessDetailSubtitle) {
    accessDetailSubtitle.textContent = linkedForms.length
      ? `Cada formulario vinculado abaixo possui seu proprio QR Code e seu proprio codigo manual de acesso.`
      : "Esta empresa ainda nao possui formularios vinculados.";
  }

  if (!linkedForms.length) {
    accessDetailFormsGrid.innerHTML = `
      <div class="access-detail-empty">
        Nenhum formulario esta vinculado a esta empresa no momento. Va em Formularios e libere um formulario para continuar.
      </div>
    `;
    return;
  }

  accessDetailFormsGrid.innerHTML = linkedForms
    .map((form) => {
      const currentCode = getCurrentCodeForCompanyForm(company.id, form.id);
      const activeCode = getActiveCodeForCompanyForm(company.id, form.id);
      const qrSource = getAccessCodeQrSource(currentCode);
      const isPrimary = Number(company.activeFormId || 0) === Number(form.id);
      const isFormInactive = form.status === "inactive";
      const status = currentCode
        ? getAccessCodeStatus(currentCode)
        : { slug: "empty", label: "Sem codigo" };
      const actionLabel = isFormInactive
        ? "Formulario inativo"
        : currentCode
          ? "Gerar novo codigo"
          : "Gerar codigo";

      return `
        <article class="access-form-card">
          <div class="access-form-card__head">
            <div>
              <strong>${escapeHtml(form.name)}</strong>
              <span>${escapeHtml(form.publicCode || "Formulario sem codigo publico")}</span>
            </div>

            <div class="access-form-card__badges">
              ${
                isPrimary
                  ? '<span class="sector-tag sector-tag--primary">Principal</span>'
                  : ""
              }
              ${
                isFormInactive
                  ? '<span class="sector-tag sector-tag--inactive-form">Formulario inativo</span>'
                  : ""
              }
              <span class="company-code-status company-code-status--${escapeHtml(status.slug)}">${escapeHtml(status.label)}</span>
            </div>
          </div>

          <div class="access-form-card__body">
            <div class="access-form-card__qr">
              ${
                qrSource
                  ? `<img src="${qrSource}" alt="QR Code ${escapeHtml(form.name)}" />`
                  : '<span class="access-form-card__qr-empty">Nenhum QR Code gerado ainda</span>'
              }
            </div>

            <div class="access-form-card__details">
              <div class="access-form-card__meta">
                <span>Codigo manual</span>
                <strong>${escapeHtml(currentCode?.code || "--")}</strong>
              </div>

              <div class="access-form-card__meta">
                <span>Escopo</span>
                <strong>${escapeHtml(currentCode?.scopeLabel || "Todos os setores (Codigo Global)")}</strong>
              </div>

              <div class="access-form-card__meta">
                <span>Validade</span>
                <strong>${escapeHtml(currentCode?.expiresAt ? formatDateTime(currentCode.expiresAt) : "--")}</strong>
              </div>

              <div class="access-form-card__meta">
                <span>Gerado em</span>
                <strong>${escapeHtml(currentCode?.createdAt ? formatDateTime(currentCode.createdAt) : "--")}</strong>
              </div>

              <div class="access-form-card__meta access-form-card__meta--wide">
                <span>Link de resposta</span>
                <strong class="access-form-card__link">${escapeHtml(currentCode?.accessUrl || "--")}</strong>
              </div>
            </div>
          </div>

          ${
            isFormInactive
              ? '<div class="access-form-card__notice">Este formulario esta inativo. Os codigos e QR Codes vinculados ficam bloqueados ate a reativacao na tela de Formularios.</div>'
              : ""
          }

          <div class="access-form-card__actions">
            <button
              class="form-builder__primary"
              type="button"
              data-access-form-generate="${form.id}"
              ${isFormInactive ? "disabled" : ""}
            >
              ${actionLabel}
            </button>

            <button
              class="form-builder__ghost"
              type="button"
              data-access-form-copy-code="${currentCode?.id || ""}"
              ${currentCode && !isFormInactive ? "" : "disabled"}
            >
              Copiar codigo
            </button>

            <button
              class="form-builder__ghost"
              type="button"
              data-access-form-copy-link="${currentCode?.id || ""}"
              ${currentCode?.accessUrl && !isFormInactive ? "" : "disabled"}
            >
              Copiar link
            </button>

            <button
              class="form-builder__ghost"
              type="button"
              data-access-form-open="${currentCode?.id || ""}"
              ${currentCode?.accessUrl && !isFormInactive ? "" : "disabled"}
            >
              Abrir
            </button>

            <button
              class="form-builder__ghost"
              type="button"
              data-access-form-download="${currentCode?.id || ""}"
              ${qrSource && !isFormInactive ? "" : "disabled"}
            >
              Baixar QR
            </button>

            <button
              class="form-builder__ghost"
              type="button"
              data-access-form-revoke="${activeCode?.id || ""}"
              ${activeCode ? "" : "disabled"}
            >
              Revogar
            </button>
          </div>
        </article>
      `;
    })
    .join("");
}

async function syncDetailModal() {
  const company = getCompanyById(selectedDetailCompanyId);

  if (!company) {
    return;
  }

  const primaryCode = getCompanyPrimaryCode(company.id);

  try {
    const structure = await loadCompanyStructure(company.id);
    populateDetailScopeOptions(structure, resolveScopeCodeFromAccessCode(primaryCode));
  } catch (error) {
    populateDetailScopeOptions(null);
    setAccessDetailFeedback(error.message || "Nao foi possivel carregar os escopos da empresa.", false);
  }

  if (accessDetailExpiresAtInput) {
    if (primaryCode?.expiresAt) {
      const expiresAt = parseDateValue(primaryCode.expiresAt);
      accessDetailExpiresAtInput.value = expiresAt ? toDateTimeLocalValue(expiresAt) : "";
    } else {
      accessDetailExpiresAtInput.value = "";
      ensureDefaultDetailExpiry();
    }
  }

  renderDetailModal();
}

function openDetailModal(companyId) {
  const normalizedCompanyId = Number.parseInt(String(companyId || 0), 10) || 0;

  if (!normalizedCompanyId || !accessDetailModal) {
    return;
  }

  selectedDetailCompanyId = normalizedCompanyId;
  setAccessDetailFeedback("", false);
  accessDetailModal.hidden = false;
  document.body.classList.add("modal-open");

  window.setTimeout(async () => {
    accessDetailModal.classList.add("is-open");
    await syncDetailModal();
    accessDetailScopeSelect?.focus();
  }, 0);
}

function closeDetailModal() {
  if (!accessDetailModal) {
    return;
  }

  accessDetailModal.classList.remove("is-open");
  document.body.classList.remove("modal-open");

  window.setTimeout(() => {
    accessDetailModal.hidden = true;
  }, 160);
}

async function copyTextToClipboard(value, successMessage) {
  const normalizedValue = String(value || "").trim();

  if (!normalizedValue || normalizedValue === "--") {
    setAccessDetailFeedback("Nao ha valor disponivel para copiar.", false);
    return;
  }

  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(normalizedValue);
      setAccessDetailFeedback(successMessage, true);
      return;
    }

    const fallbackInput = document.createElement("textarea");
    fallbackInput.value = normalizedValue;
    document.body.appendChild(fallbackInput);
    fallbackInput.select();
    document.execCommand("copy");
    fallbackInput.remove();
    setAccessDetailFeedback(successMessage, true);
  } catch (error) {
    setAccessDetailFeedback("Nao foi possivel copiar o valor.", false);
  }
}

function downloadAccessQr(accessCode) {
  const qrSource = getAccessCodeQrSource(accessCode);

  if (!qrSource) {
    setAccessDetailFeedback("Nao ha QR Code disponivel para baixar.", false);
    return;
  }

  const companyName = sanitizeFileName(accessCode?.companyName || "empresa");
  const formName = sanitizeFileName(accessCode?.formName || "formulario");
  const codeValue = sanitizeFileName(accessCode?.code || "acesso");
  const extension = qrSource.startsWith("data:image/svg+xml") ? "svg" : "gif";
  const link = document.createElement("a");

  link.href = qrSource;
  link.download = `${companyName}-${formName}-${codeValue}-qrcode.${extension}`;
  document.body.appendChild(link);
  link.click();
  link.remove();

  setAccessDetailFeedback("QR Code baixado com sucesso.", true);
}

function openAccessLinkForCode(accessCode) {
  if (!accessCode?.accessUrl) {
    setAccessDetailFeedback("Nao ha link ativo para abrir.", false);
    return;
  }

  window.open(accessCode.accessUrl, "_blank", "noopener,noreferrer");
}

async function generateAccessForForm(formId) {
  const company = getCompanyById(selectedDetailCompanyId);
  const normalizedFormId = Number.parseInt(String(formId || 0), 10) || 0;

  if (!company || !normalizedFormId) {
    return;
  }

  const expiresAt = accessDetailExpiresAtInput?.value || "";

  if (!expiresAt) {
    setAccessDetailFeedback("Informe a validade do codigo antes de gerar o acesso.", false);
    accessDetailExpiresAtInput?.focus();
    return;
  }

  try {
    isSubmittingAccess = true;
    setAccessDetailFeedback("Gerando codigo e QR Code do formulario...", false);

    await window.apiClient.post("api/access-codes.php", {
      companyId: Number(company.id),
      formId: normalizedFormId,
      ...getSelectedDetailScopePayload(),
      expiresAt,
    });

    await loadAccessData({ preserveDetailCompanyId: Number(company.id) });
    setAccessDetailFeedback("Acesso gerado com sucesso para o formulario selecionado.", true);
  } catch (error) {
    setAccessDetailFeedback(error.message || "Nao foi possivel gerar o acesso.", false);
  } finally {
    isSubmittingAccess = false;
  }
}

async function revokeAccessCode(codeId) {
  const normalizedCodeId = Number.parseInt(String(codeId || 0), 10) || 0;

  if (!normalizedCodeId) {
    return;
  }

  try {
    isSubmittingAccess = true;
    setAccessDetailFeedback("Revogando codigo...", false);

    await window.apiClient.put("api/access-codes.php", {
      id: normalizedCodeId,
      action: "revoke",
    });

    await loadAccessData({ preserveDetailCompanyId: selectedDetailCompanyId });
    setAccessDetailFeedback("Codigo revogado com sucesso.", true);
  } catch (error) {
    setAccessDetailFeedback(error.message || "Nao foi possivel revogar o codigo.", false);
  } finally {
    isSubmittingAccess = false;
  }
}

async function loadAccessData(options = {}) {
  const preservedDetailCompanyId = Number.parseInt(
    String(options.preserveDetailCompanyId || selectedDetailCompanyId || 0),
    10,
  ) || 0;

  try {
    isLoadingDirectory = true;

    const [companiesResponse, accessCodesResponse] = await Promise.all([
      window.apiClient.get("api/companies.php"),
      window.apiClient.get("api/access-codes.php"),
    ]);

    companiesDb = Array.isArray(companiesResponse.data) ? companiesResponse.data : [];
    accessCodesDb = Array.isArray(accessCodesResponse.data?.codes)
      ? accessCodesResponse.data.codes.map(enrichAccessCode)
      : [];
    accessHistoryDb = Array.isArray(accessCodesResponse.data?.history) ? accessCodesResponse.data.history : [];
    companyStructuresCache = {};

    renderOverviewCards();
    applyCompanyFilters();
    applyHistoryFilters();

    if (preservedDetailCompanyId && getCompanyById(preservedDetailCompanyId)) {
      selectedDetailCompanyId = preservedDetailCompanyId;

      if (accessDetailModal && !accessDetailModal.hidden) {
        await syncDetailModal();
      }
    }
  } catch (error) {
    if (accessCompanyTableBody) {
      accessCompanyTableBody.innerHTML = "";
    }

    if (accessCompanyResults) {
      accessCompanyResults.textContent = error.message || "Nao foi possivel carregar a geracao de acesso.";
    }

    if (accessHistoryBody) {
      accessHistoryBody.innerHTML = "";
    }

    if (accessHistoryResults) {
      accessHistoryResults.textContent = error.message || "Nao foi possivel carregar o historico.";
    }
  } finally {
    isLoadingDirectory = false;
  }
}

function exportCurrentHistory() {
  if (!filteredHistory.length) {
    window.alert("Nao ha historico para exportar.");
    return;
  }

  const header = ["Sessao", "Empresa", "Formulario", "DataHora", "Status", "Codigo", "Escopo"];
  const rows = filteredHistory.map((item) => [
    item.sessionId,
    item.companyName,
    item.formName,
    formatDateTime(item.accessedAt),
    item.statusLabel,
    item.code,
    item.scopeLabel,
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
}

function handleCompanyTableClick(event) {
  const editButton = event.target.closest("[data-edit-access-company]");

  if (!editButton) {
    return;
  }

  openDetailModal(editButton.getAttribute("data-edit-access-company"));
}

function handleCompanyPaginationClick(event) {
  const pageButton = event.target.closest("[data-access-company-page]");

  if (pageButton) {
    currentCompanyPage =
      Number.parseInt(pageButton.getAttribute("data-access-company-page"), 10) || 1;
    renderCompanyTable();
    return;
  }

  const navButton = event.target.closest("[data-access-company-nav]");

  if (!navButton) {
    return;
  }

  const action = navButton.getAttribute("data-access-company-nav");
  const totalPages = Math.max(1, Math.ceil(filteredCompanies.length / COMPANY_PAGE_SIZE));

  if (action === "prev" && currentCompanyPage > 1) {
    currentCompanyPage -= 1;
  }

  if (action === "next" && currentCompanyPage < totalPages) {
    currentCompanyPage += 1;
  }

  renderCompanyTable();
}

function handleHistoryPaginationClick(event) {
  const pageButton = event.target.closest("[data-access-history-page]");

  if (pageButton) {
    currentHistoryPage =
      Number.parseInt(pageButton.getAttribute("data-access-history-page"), 10) || 1;
    renderHistoryTable();
    return;
  }

  const navButton = event.target.closest("[data-access-history-nav]");

  if (!navButton) {
    return;
  }

  const action = navButton.getAttribute("data-access-history-nav");
  const totalPages = Math.max(1, Math.ceil(filteredHistory.length / HISTORY_PAGE_SIZE));

  if (action === "prev" && currentHistoryPage > 1) {
    currentHistoryPage -= 1;
  }

  if (action === "next" && currentHistoryPage < totalPages) {
    currentHistoryPage += 1;
  }

  renderHistoryTable();
}

function handleHistoryBodyClick(event) {
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
      `Escopo: ${session.scopeLabel}`,
      `Status: ${session.statusLabel}`,
      `Acesso: ${formatDateTime(session.accessedAt)}`,
    ].join("\n"),
  );
}

function handleAccessDetailGridClick(event) {
  const generateButton = event.target.closest("[data-access-form-generate]");

  if (generateButton) {
    void generateAccessForForm(generateButton.getAttribute("data-access-form-generate"));
    return;
  }

  const copyCodeButton = event.target.closest("[data-access-form-copy-code]");

  if (copyCodeButton) {
    const codeId = Number.parseInt(copyCodeButton.getAttribute("data-access-form-copy-code"), 10) || 0;
    const accessCode = accessCodesDb.find((item) => Number(item.id) === codeId);
    void copyTextToClipboard(accessCode?.code, "Codigo copiado para a area de transferencia.");
    return;
  }

  const copyLinkButton = event.target.closest("[data-access-form-copy-link]");

  if (copyLinkButton) {
    const codeId = Number.parseInt(copyLinkButton.getAttribute("data-access-form-copy-link"), 10) || 0;
    const accessCode = accessCodesDb.find((item) => Number(item.id) === codeId);
    void copyTextToClipboard(accessCode?.accessUrl, "Link copiado para a area de transferencia.");
    return;
  }

  const openButton = event.target.closest("[data-access-form-open]");

  if (openButton) {
    const codeId = Number.parseInt(openButton.getAttribute("data-access-form-open"), 10) || 0;
    const accessCode = accessCodesDb.find((item) => Number(item.id) === codeId);
    openAccessLinkForCode(accessCode);
    return;
  }

  const downloadButton = event.target.closest("[data-access-form-download]");

  if (downloadButton) {
    const codeId = Number.parseInt(downloadButton.getAttribute("data-access-form-download"), 10) || 0;
    const accessCode = accessCodesDb.find((item) => Number(item.id) === codeId);
    downloadAccessQr(accessCode);
    return;
  }

  const revokeButton = event.target.closest("[data-access-form-revoke]");

  if (revokeButton) {
    void revokeAccessCode(revokeButton.getAttribute("data-access-form-revoke"));
  }
}

function initializeAccessPage() {
  if (!accessCompanyTableBody) {
    return;
  }

  ensureDefaultDetailExpiry();
  void loadAccessData();

  accessCompanySearch?.addEventListener("input", () => {
    applyCompanyFilters();
  });

  refreshAccessDirectoryButton?.addEventListener("click", () => {
    if (!isLoadingDirectory) {
      void loadAccessData({ preserveDetailCompanyId: selectedDetailCompanyId });
    }
  });

  accessHistorySearch?.addEventListener("input", () => {
    applyHistoryFilters();
  });

  exportAccessHistoryButton?.addEventListener("click", exportCurrentHistory);
  accessCompanyTableBody?.addEventListener("click", handleCompanyTableClick);
  accessCompanyPagination?.addEventListener("click", handleCompanyPaginationClick);
  accessHistoryBody?.addEventListener("click", handleHistoryBodyClick);
  accessHistoryPagination?.addEventListener("click", handleHistoryPaginationClick);
  accessDetailFormsGrid?.addEventListener("click", handleAccessDetailGridClick);

  closeAccessDetailButtons.forEach((button) => {
    button.addEventListener("click", () => {
      closeDetailModal();
    });
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && accessDetailModal && !accessDetailModal.hidden) {
      closeDetailModal();
    }
  });
}

initializeAccessPage();
