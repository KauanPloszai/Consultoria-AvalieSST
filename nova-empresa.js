const COMPANY_PAGE_SIZE = 10;

const companyTableBody = document.querySelector("[data-company-table-body]");
const companyResultsLabel = document.querySelector("[data-company-results]");
const companySearchInput = document.querySelector("[data-company-search]");
const companyStatusFilter = document.querySelector("[data-company-status-filter]");
const companySectorFilter = document.querySelector("[data-company-sector-filter]");
const companyRefreshButton = document.querySelector("[data-company-refresh]");
const companySelectAllButton = document.querySelector("[data-company-select-all]");
const companyPagination = document.querySelector("[data-company-pagination]");

const companyStatTotal = document.querySelector("[data-company-stat-total]");
const companyStatActive = document.querySelector("[data-company-stat-active]");
const companyStatInactive = document.querySelector("[data-company-stat-inactive]");
const companyStatEmployees = document.querySelector("[data-company-stat-employees]");

const openCompanyModalButton = document.querySelector("[data-open-company-modal]");
const companyModal = document.querySelector("[data-company-modal]");
const companyModalTitle = document.querySelector("[data-company-modal-title]");
const closeCompanyModalButtons = document.querySelectorAll("[data-close-company-modal]");
const companyBuilder = document.querySelector("[data-company-builder]");
const companyFeedback = document.querySelector("[data-company-feedback]");

const companyNameInput = document.querySelector("[data-company-name]");
const companyCnpjInput = document.querySelector("[data-company-cnpj]");
const companyStatusInput = document.querySelector("[data-company-status]");
const companySectorsInput = document.querySelector("[data-company-sectors]");
const companyEmployeesInput = document.querySelector("[data-company-employees]");

let companiesDb = [];
let filteredCompanies = [];
let editingCompanyId = null;
let currentCompanyPage = 1;

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

function formatStatusPill(status) {
  if (status === "inactive") {
    return '<span class="status-pill status-pill--inactive">Inativo</span>';
  }

  return '<span class="status-pill status-pill--active">Ativo</span>';
}

function renderSectorTags(sectors) {
  return sectors
    .map((sector) => `<span class="sector-tag">${escapeHtml(sector)}</span>`)
    .join("");
}

function syncCompanySelectAllState() {
  if (!companySelectAllButton || !companyTableBody) {
    return;
  }

  const rowChecks = Array.from(companyTableBody.querySelectorAll("[data-company-select-row]"));
  const hasRows = rowChecks.length > 0;
  const everySelected = hasRows && rowChecks.every((button) => button.classList.contains("table-check--selected"));

  companySelectAllButton.classList.toggle("table-check--selected", everySelected);
}

function getCompanyTotalPages() {
  return Math.max(1, Math.ceil(filteredCompanies.length / COMPANY_PAGE_SIZE));
}

function getCurrentPageCompanies() {
  const totalPages = getCompanyTotalPages();
  currentCompanyPage = Math.min(currentCompanyPage, totalPages);

  const startIndex = (currentCompanyPage - 1) * COMPANY_PAGE_SIZE;
  return filteredCompanies.slice(startIndex, startIndex + COMPANY_PAGE_SIZE);
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
  if (!companyPagination) {
    return;
  }

  const totalResults = filteredCompanies.length;
  const totalPages = getCompanyTotalPages();
  const paginationTokens = buildPaginationTokens(totalPages, currentCompanyPage);

  const buttons = [
    `
      <button
        type="button"
        data-company-page-nav="prev"
        ${currentCompanyPage === 1 || totalResults === 0 ? "disabled" : ""}
      >
        Anterior
      </button>
    `,
    ...paginationTokens.map((token) => {
      if (token === "...") {
        return '<button type="button" class="pagination__ellipsis" disabled>...</button>';
      }

      const isActive = token === currentCompanyPage;
      return `
        <button
          type="button"
          data-company-page="${token}"
          class="${isActive ? "is-active" : ""}"
        >
          ${token}
        </button>
      `;
    }),
    `
      <button
        type="button"
        data-company-page-nav="next"
        ${currentCompanyPage === totalPages || totalResults === 0 ? "disabled" : ""}
      >
        Proxima
      </button>
    `,
  ];

  companyPagination.innerHTML = buttons.join("");
}

function renderCompaniesTable() {
  if (!companyTableBody) {
    return;
  }

  const paginatedCompanies = getCurrentPageCompanies();

  if (!filteredCompanies.length) {
    companyTableBody.innerHTML = "";
  } else {
    const rows = paginatedCompanies
      .map((company) => {
        return `
          <article class="company-table__row">
            <button class="table-check" type="button" aria-label="Selecionar linha" data-company-select-row="${company.id}"></button>
            <div class="company-cell">
              <span class="company-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" role="presentation">
                  <path d="M5 20V8.5A1.5 1.5 0 0 1 6.5 7H14v13H5Zm9 0V4.5A1.5 1.5 0 0 1 15.5 3H19v17h-5ZM8 10h2m-2 3h2m-2 3h2m7-6h1m-1 3h1" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.5" />
                </svg>
              </span>
              <div class="company-meta">
                <strong>${escapeHtml(company.name)}</strong>
                <span>ID: ${escapeHtml(company.id)}</span>
              </div>
            </div>
            <span class="table-text">${escapeHtml(company.cnpj)}</span>
            ${formatStatusPill(company.status)}
            <div class="tag-list">${renderSectorTags(company.sectors)}</div>
            <span class="employee-count">${company.employees}</span>
            <button class="table-action" type="button" aria-label="Editar empresa" data-edit-company="${company.id}">
              <svg viewBox="0 0 24 24" role="presentation">
                <path d="m4 16.5 9.8-9.8a2.1 2.1 0 1 1 3 3L7 19.5 4 20l.5-3.5Z" fill="none" stroke="currentColor" stroke-linejoin="round" stroke-width="1.7" />
              </svg>
            </button>
          </article>
        `;
      })
      .join("");

    companyTableBody.innerHTML = rows;
  }

  if (companyResultsLabel) {
    const total = filteredCompanies.length;
    const start = total ? (currentCompanyPage - 1) * COMPANY_PAGE_SIZE + 1 : 0;
    const end = total ? Math.min(currentCompanyPage * COMPANY_PAGE_SIZE, total) : 0;

    companyResultsLabel.innerHTML = `Mostrando ${start} a ${end} de <strong>${total}</strong> resultados`;
  }

  renderCompanyPagination();
  syncCompanySelectAllState();
}

function renderCompanyStats() {
  const total = companiesDb.length;
  const active = companiesDb.filter((company) => company.status === "active").length;
  const inactive = companiesDb.filter((company) => company.status === "inactive").length;
  const employees = companiesDb.reduce((sum, company) => sum + Number(company.employees || 0), 0);

  if (companyStatTotal) {
    companyStatTotal.textContent = String(total);
  }

  if (companyStatActive) {
    companyStatActive.textContent = String(active);
  }

  if (companyStatInactive) {
    companyStatInactive.textContent = String(inactive);
  }

  if (companyStatEmployees) {
    companyStatEmployees.textContent = String(employees);
  }
}

function renderSectorOptions() {
  if (!companySectorFilter) {
    return;
  }

  const currentValue = companySectorFilter.value;
  const sectorSet = new Set();

  companiesDb.forEach((company) => {
    company.sectors.forEach((sector) => {
      sectorSet.add(sector);
    });
  });

  const options = ['<option value="all">Setor: Todos</option>']
    .concat(
      Array.from(sectorSet)
        .sort((left, right) => left.localeCompare(right))
        .map((sector) => `<option value="${escapeHtml(sector)}">Setor: ${escapeHtml(sector)}</option>`),
    )
    .join("");

  companySectorFilter.innerHTML = options;

  if (currentValue && Array.from(companySectorFilter.options).some((option) => option.value === currentValue)) {
    companySectorFilter.value = currentValue;
  }
}

function applyCompanyFilters(options = {}) {
  const { resetPage = true } = options;
  const searchTerm = companySearchInput ? companySearchInput.value.trim().toLowerCase() : "";
  const statusFilter = companyStatusFilter ? companyStatusFilter.value : "all";
  const sectorFilter = companySectorFilter ? companySectorFilter.value : "all";

  if (resetPage) {
    currentCompanyPage = 1;
  }

  filteredCompanies = companiesDb.filter((company) => {
    const matchesSearch =
      !searchTerm ||
      company.name.toLowerCase().includes(searchTerm) ||
      company.cnpj.toLowerCase().includes(searchTerm) ||
      company.id.toLowerCase().includes(searchTerm);

    const matchesStatus = statusFilter === "all" || company.status === statusFilter;
    const matchesSector =
      sectorFilter === "all" || company.sectors.some((sector) => sector === sectorFilter);

    return matchesSearch && matchesStatus && matchesSector;
  });

  renderCompaniesTable();
}

async function loadCompanies() {
  if (!companyTableBody) {
    return;
  }

  try {
    const response = await window.apiClient.get("api/companies.php");
    companiesDb = Array.isArray(response.data) ? response.data : [];
    renderSectorOptions();
    renderCompanyStats();
    applyCompanyFilters();
  } catch (error) {
    companyTableBody.innerHTML = "";

    if (companyResultsLabel) {
      companyResultsLabel.innerHTML = `<strong>${escapeHtml(error.message || "Nao foi possivel carregar as empresas.")}</strong>`;
    }
  }
}

function openCompanyModal(mode = "create", company = null) {
  if (!companyModal || !companyBuilder) {
    return;
  }

  editingCompanyId = company?.id || null;

  if (companyNameInput) {
    companyNameInput.value = company?.name || "";
  }

  if (companyCnpjInput) {
    companyCnpjInput.value = company?.cnpj || "";
  }

  if (companyStatusInput) {
    companyStatusInput.value = company?.status || "active";
  }

  if (companySectorsInput) {
    companySectorsInput.value = company?.sectors?.join(", ") || "";
  }

  if (companyEmployeesInput) {
    companyEmployeesInput.value = company?.employees ? String(company.employees) : "1";
  }

  if (companyFeedback) {
    companyFeedback.textContent = "";
    companyFeedback.classList.remove("is-success");
  }

  if (companyModalTitle) {
    companyModalTitle.textContent = mode === "edit" ? "Editar Empresa" : "Nova Empresa";
  }

  companyModal.hidden = false;
  document.body.classList.add("modal-open");

  window.setTimeout(() => {
    companyModal.classList.add("is-open");
    if (companyNameInput) {
      companyNameInput.focus();
    }
  }, 0);
}

function closeCompanyModal() {
  if (!companyModal) {
    return;
  }

  companyModal.classList.remove("is-open");
  document.body.classList.remove("modal-open");

  window.setTimeout(() => {
    companyModal.hidden = true;
  }, 160);
}

function sanitizeSectors(rawSectors) {
  return rawSectors
    .split(",")
    .map((sector) => sector.trim())
    .filter(Boolean);
}

async function handleCompanySubmit(event) {
  event.preventDefault();

  const companyName = companyNameInput ? companyNameInput.value.trim() : "";
  const companyCnpj = companyCnpjInput ? companyCnpjInput.value.trim() : "";
  const companyStatus = companyStatusInput ? companyStatusInput.value : "active";
  const companySectors = companySectorsInput ? sanitizeSectors(companySectorsInput.value) : [];
  const companyEmployees = companyEmployeesInput
    ? Math.max(1, Number.parseInt(companyEmployeesInput.value, 10) || 1)
    : 1;

  if (!companyName) {
    companyFeedback.textContent = "Informe o nome da empresa.";
    companyFeedback.classList.remove("is-success");
    companyNameInput.focus();
    return;
  }

  if (!companyCnpj) {
    companyFeedback.textContent = "Informe o CNPJ da empresa.";
    companyFeedback.classList.remove("is-success");
    companyCnpjInput.focus();
    return;
  }

  if (!companySectors.length) {
    companyFeedback.textContent = "Informe pelo menos um setor.";
    companyFeedback.classList.remove("is-success");
    companySectorsInput.focus();
    return;
  }

  try {
    companyFeedback.textContent = editingCompanyId ? "Atualizando empresa..." : "Salvando empresa...";
    companyFeedback.classList.remove("is-success");

    const payload = {
      id: editingCompanyId,
      name: companyName,
      cnpj: companyCnpj,
      status: companyStatus,
      sectors: companySectors,
      employees: companyEmployees,
    };

    if (editingCompanyId) {
      await window.apiClient.put("api/companies.php", payload);
    } else {
      await window.apiClient.post("api/companies.php", payload);
    }

    await loadCompanies();
    closeCompanyModal();
  } catch (error) {
    companyFeedback.textContent = error.message || "Nao foi possivel salvar a empresa.";
    companyFeedback.classList.remove("is-success");
  }
}

function handleCompanyTableClick(event) {
  const editButton = event.target.closest("[data-edit-company]");

  if (editButton) {
    const companyId = editButton.getAttribute("data-edit-company");
    const company = companiesDb.find((item) => item.id === companyId);

    if (company) {
      openCompanyModal("edit", company);
    }

    return;
  }

  const rowCheck = event.target.closest("[data-company-select-row]");

  if (rowCheck) {
    rowCheck.classList.toggle("table-check--selected");
    syncCompanySelectAllState();
  }
}

function handleCompanyPaginationClick(event) {
  const pageButton = event.target.closest("[data-company-page]");

  if (pageButton) {
    currentCompanyPage = Number.parseInt(pageButton.getAttribute("data-company-page"), 10) || 1;
    renderCompaniesTable();
    return;
  }

  const navButton = event.target.closest("[data-company-page-nav]");

  if (!navButton) {
    return;
  }

  const action = navButton.getAttribute("data-company-page-nav");
  const totalPages = getCompanyTotalPages();

  if (action === "prev" && currentCompanyPage > 1) {
    currentCompanyPage -= 1;
  }

  if (action === "next" && currentCompanyPage < totalPages) {
    currentCompanyPage += 1;
  }

  renderCompaniesTable();
}

function resetCompanyFilters() {
  if (companySearchInput) {
    companySearchInput.value = "";
  }

  if (companyStatusFilter) {
    companyStatusFilter.value = "all";
  }

  if (companySectorFilter) {
    companySectorFilter.value = "all";
  }

  applyCompanyFilters();
}

if (companyTableBody && companyBuilder) {
  loadCompanies();

  if (openCompanyModalButton) {
    openCompanyModalButton.addEventListener("click", () => {
      openCompanyModal("create");
    });
  }

  closeCompanyModalButtons.forEach((button) => {
    button.addEventListener("click", () => {
      closeCompanyModal();
    });
  });

  companyBuilder.addEventListener("submit", handleCompanySubmit);
  companyTableBody.addEventListener("click", handleCompanyTableClick);

  if (companyPagination) {
    companyPagination.addEventListener("click", handleCompanyPaginationClick);
  }

  if (companySearchInput) {
    companySearchInput.addEventListener("input", () => {
      applyCompanyFilters();
    });
  }

  if (companyStatusFilter) {
    companyStatusFilter.addEventListener("change", () => {
      applyCompanyFilters();
    });
  }

  if (companySectorFilter) {
    companySectorFilter.addEventListener("change", () => {
      applyCompanyFilters();
    });
  }

  if (companyRefreshButton) {
    companyRefreshButton.addEventListener("click", resetCompanyFilters);
  }

  if (companySelectAllButton) {
    companySelectAllButton.addEventListener("click", () => {
      companySelectAllButton.classList.toggle("table-check--selected");
      const rowChecks = companyTableBody.querySelectorAll("[data-company-select-row]");
      const shouldSelectAll = companySelectAllButton.classList.contains("table-check--selected");

      rowChecks.forEach((button) => {
        button.classList.toggle("table-check--selected", shouldSelectAll);
      });
    });
  }

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && companyModal && !companyModal.hidden) {
      closeCompanyModal();
    }
  });
}
