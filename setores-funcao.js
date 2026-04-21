const structureCompanySelect = document.querySelector("[data-structure-company]");
const structureActiveFormMeta = document.querySelector("[data-structure-active-form]");
const structureTable = document.querySelector("[data-structure-table]");
const openStructureModalButton = document.querySelector("[data-open-structure-modal]");

const structureStatSectors = document.querySelector("[data-structure-stat-sectors]");
const structureStatFunctions = document.querySelector("[data-structure-stat-functions]");
const structureStatEmployees = document.querySelector("[data-structure-stat-employees]");
const structureStatCompany = document.querySelector("[data-structure-stat-company]");

const structureModal = document.querySelector("[data-structure-modal]");
const structureModalTitle = document.querySelector("[data-structure-modal-title]");
const closeStructureModalButtons = document.querySelectorAll("[data-close-structure-modal]");
const structureBuilder = document.querySelector("[data-structure-builder]");
const structureTypeSelect = document.querySelector("[data-structure-type]");
const structureCompanyModalSelect = document.querySelector("[data-structure-company-modal]");
const structureSectorSelect = document.querySelector("[data-structure-sector-select]");
const structureNameInput = document.querySelector("[data-structure-name]");
const structureEmployeesInput = document.querySelector("[data-structure-employees]");
const structureFeedback = document.querySelector("[data-structure-feedback]");
const deleteStructureItemButton = document.querySelector("[data-delete-structure-item]");

let structureDashboard = {
  companies: [],
  selectedCompanyId: null,
  structure: null,
};

let editingStructureItem = null;
let structureSession = null;

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

function getSelectedStructureCompanyId() {
  return Number.parseInt(structureCompanySelect?.value || "0", 10) || 0;
}

function isCompanyStructureUser() {
  return (structureSession?.role || "admin") === "company";
}

function findStructureSectorById(sectorId) {
  return structureDashboard.structure?.sectors?.find((sector) => Number(sector.id) === Number(sectorId)) || null;
}

function populateStructureCompanyOptions() {
  if (!structureCompanySelect || !structureCompanyModalSelect) {
    return;
  }

  const options = structureDashboard.companies
    .map((company) => `<option value="${company.id}">${escapeHtml(company.name)}</option>`)
    .join("");

  structureCompanySelect.innerHTML = options || '<option value="">Nenhuma empresa cadastrada</option>';
  structureCompanyModalSelect.innerHTML = options || '<option value="">Nenhuma empresa cadastrada</option>';

  if (structureDashboard.selectedCompanyId) {
    structureCompanySelect.value = String(structureDashboard.selectedCompanyId);
    structureCompanyModalSelect.value = String(structureDashboard.selectedCompanyId);
  }

  const lockCompanyField = isCompanyStructureUser();
  structureCompanySelect.disabled = lockCompanyField;
  structureCompanyModalSelect.disabled = lockCompanyField;
}

function populateStructureSectorOptions() {
  if (!structureSectorSelect) {
    return;
  }

  const sectors = structureDashboard.structure?.sectors || [];

  if (!sectors.length) {
    structureSectorSelect.innerHTML = '<option value="">Cadastre um setor primeiro</option>';
    structureSectorSelect.disabled = true;
    return;
  }

  structureSectorSelect.innerHTML = sectors
    .map((sector) => `<option value="${sector.id}">${escapeHtml(sector.name)}</option>`)
    .join("");
  structureSectorSelect.disabled = structureTypeSelect?.value !== "function";
}

function renderStructureStats() {
  const structure = structureDashboard.structure;

  if (!structure) {
    return;
  }

  if (structureStatSectors) {
    structureStatSectors.textContent = String(structure.summary?.sectorCount || 0);
  }

  if (structureStatFunctions) {
    structureStatFunctions.textContent = String(structure.summary?.functionCount || 0);
  }

  if (structureStatEmployees) {
    structureStatEmployees.textContent = String(structure.company?.employees || 0);
  }

  if (structureStatCompany) {
    structureStatCompany.textContent = structure.company?.name || "--";
  }

  if (structureActiveFormMeta) {
    structureActiveFormMeta.textContent = structure.company?.activeFormName
      ? `Formulário vinculado: ${structure.company.activeFormName}`
      : "Nenhum formulário vinculado para a empresa selecionada.";
  }
}

function buildRiskTagMarkup(item) {
  const slug = item?.riskSlug || "neutral";
  const label = item?.riskLabel || "Sem dados";

  return `<span class="org-tag org-tag--${escapeHtml(slug)}">${escapeHtml(label)}</span>`;
}

function renderStructureTable() {
  if (!structureTable) {
    return;
  }

  const structure = structureDashboard.structure;
  const headMarkup = `
    <div class="org-table__head">
      <span>Setor / Função</span>
      <span>Colaboradores</span>
      <span>Riscos Identificados</span>
      <span>Ações</span>
    </div>
  `;

  if (!structure || !(structure.sectors || []).length) {
    structureTable.innerHTML = `
      ${headMarkup}
      <div class="org-empty-state">Nenhum setor ou função cadastrado para a empresa selecionada.</div>
    `;
    return;
  }

  const groupsMarkup = structure.sectors
    .map((sector) => {
      const sectorActions = `
        <div class="org-row__actions">
          <button class="org-mini-action" type="button" data-add-function="${sector.id}">+ Função</button>
          <button class="org-edit-button" type="button" data-edit-structure="sector" data-structure-id="${sector.id}" aria-label="Editar setor">
            <svg viewBox="0 0 24 24" role="presentation">
              <path d="m4 16.5 9.8-9.8a2.1 2.1 0 1 1 3 3L7 19.5 4 20l.5-3.5Z" fill="none" stroke="currentColor" stroke-linejoin="round" stroke-width="1.7" />
            </svg>
          </button>
        </div>
      `;

      const childrenMarkup = (sector.functions || [])
        .map(
          (companyFunction) => `
            <article class="org-row org-row--child">
              <div class="org-row__name org-row__name--child">
                <span class="org-tree-line" aria-hidden="true"></span>
                <span class="org-child-label">${escapeHtml(companyFunction.name)}</span>
              </div>
              <span class="org-count">${companyFunction.employees}</span>
              <div class="org-tags">${buildRiskTagMarkup(companyFunction)}</div>
              <button class="org-edit-button" type="button" data-edit-structure="function" data-structure-id="${companyFunction.id}" aria-label="Editar função">
                <svg viewBox="0 0 24 24" role="presentation">
                  <path d="m4 16.5 9.8-9.8a2.1 2.1 0 1 1 3 3L7 19.5 4 20l.5-3.5Z" fill="none" stroke="currentColor" stroke-linejoin="round" stroke-width="1.7" />
                </svg>
              </button>
            </article>
          `,
        )
        .join("");

      return `
        <div class="org-group org-group--single">
          <article class="org-row org-row--parent">
            <div class="org-row__name">
              <span class="org-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" role="presentation">
                  <path d="M5 20V8.5A1.5 1.5 0 0 1 6.5 7H14v13H5Zm9 0V4.5A1.5 1.5 0 0 1 15.5 3H19v17h-5ZM8 10h2m-2 3h2m-2 3h2m7-6h1m-1 3h1" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.5" />
                </svg>
              </span>
              <strong>${escapeHtml(sector.name)}</strong>
            </div>
            <span class="org-count">${sector.employees}</span>
            <div class="org-tags">${buildRiskTagMarkup(sector)}</div>
            ${sectorActions}
          </article>
          ${childrenMarkup}
        </div>
      `;
    })
    .join("");

  structureTable.innerHTML = `${headMarkup}${groupsMarkup}`;
}

async function loadStructure(companyId = 0) {
  try {
    const targetCompanyId = Number.parseInt(String(companyId || getSelectedStructureCompanyId() || 0), 10) || 0;
    const query = targetCompanyId > 0 ? `?companyId=${targetCompanyId}` : "";
    const response = await window.apiClient.get(`api/company-structure.php${query}`);

    structureDashboard = response.data || {
      companies: [],
      selectedCompanyId: null,
      structure: null,
    };

    populateStructureCompanyOptions();
    populateStructureSectorOptions();
    renderStructureStats();
    renderStructureTable();
  } catch (error) {
    if (structureTable) {
      structureTable.innerHTML = `
        <div class="org-table__head">
          <span>Setor / Função</span>
          <span>Colaboradores</span>
          <span>Riscos Identificados</span>
          <span>Ações</span>
        </div>
        <div class="org-empty-state">${escapeHtml(error.message || "Não foi possível carregar a estrutura.")}</div>
      `;
    }
  }
}

function openStructureModal(config = {}) {
  if (!structureModal) {
    return;
  }

  editingStructureItem = config.item || null;

  if (structureFeedback) {
    structureFeedback.textContent = "";
    structureFeedback.classList.remove("is-success");
  }

  if (structureModalTitle) {
    structureModalTitle.textContent = config.title || "Novo Setor";
  }

  if (structureTypeSelect) {
    structureTypeSelect.value = config.type || "sector";
  }

  if (structureCompanyModalSelect) {
    structureCompanyModalSelect.value = String(config.companyId || structureDashboard.selectedCompanyId || "");
  }

  populateStructureSectorOptions();

  if (structureSectorSelect) {
    structureSectorSelect.value = config.sectorId ? String(config.sectorId) : structureSectorSelect.value;
  }

  if (structureNameInput) {
    structureNameInput.value = config.name || "";
  }

  if (structureEmployeesInput) {
    structureEmployeesInput.value = config.employees != null ? String(config.employees) : "0";
  }

  if (deleteStructureItemButton) {
    deleteStructureItemButton.hidden = !editingStructureItem;
  }

  structureSectorSelect.disabled = structureTypeSelect?.value !== "function";

  if (isCompanyStructureUser() && structureCompanyModalSelect && structureDashboard.selectedCompanyId) {
    structureCompanyModalSelect.value = String(structureDashboard.selectedCompanyId);
    structureCompanyModalSelect.disabled = true;
  }

  structureModal.hidden = false;
  document.body.classList.add("modal-open");

  window.setTimeout(() => {
    structureModal.classList.add("is-open");
    structureNameInput?.focus();
  }, 0);
}

function closeStructureModal() {
  if (!structureModal) {
    return;
  }

  structureModal.classList.remove("is-open");
  document.body.classList.remove("modal-open");

  window.setTimeout(() => {
    structureModal.hidden = true;
  }, 160);
}

function handleStructureTableClick(event) {
  const addFunctionButton = event.target.closest("[data-add-function]");

  if (addFunctionButton) {
    const sectorId = Number.parseInt(addFunctionButton.getAttribute("data-add-function"), 10) || 0;
    const sector = findStructureSectorById(sectorId);

    openStructureModal({
      title: "Nova Função",
      type: "function",
      companyId: structureDashboard.selectedCompanyId,
      sectorId,
      name: "",
      employees: 0,
    });

    if (sector && structureFeedback) {
      structureFeedback.textContent = `Nova função será criada em ${sector.name}.`;
      structureFeedback.classList.add("is-success");
    }

    return;
  }

  const editButton = event.target.closest("[data-edit-structure]");

  if (!editButton) {
    return;
  }

  const itemType = editButton.getAttribute("data-edit-structure");
  const itemId = Number.parseInt(editButton.getAttribute("data-structure-id"), 10) || 0;

  if (itemType === "sector") {
    const sector = findStructureSectorById(itemId);

    if (!sector) {
      return;
    }

    openStructureModal({
      title: "Editar Setor",
      type: "sector",
      companyId: structureDashboard.selectedCompanyId,
      item: { type: "sector", id: sector.id },
      name: sector.name,
      employees: sector.employees,
    });

    return;
  }

  for (const sector of structureDashboard.structure?.sectors || []) {
    const companyFunction = (sector.functions || []).find((item) => Number(item.id) === itemId);

    if (!companyFunction) {
      continue;
    }

    openStructureModal({
      title: "Editar Função",
      type: "function",
      companyId: structureDashboard.selectedCompanyId,
      sectorId: sector.id,
      item: { type: "function", id: companyFunction.id },
      name: companyFunction.name,
      employees: companyFunction.employees,
    });
    return;
  }
}

async function handleStructureSubmit(event) {
  event.preventDefault();

  const type = structureTypeSelect?.value || "sector";
  const companyId = Number.parseInt(structureCompanyModalSelect?.value || "0", 10) || 0;
  const sectorId = Number.parseInt(structureSectorSelect?.value || "0", 10) || 0;
  const name = structureNameInput?.value.trim() || "";
  const employees = Math.max(0, Number.parseInt(structureEmployeesInput?.value || "0", 10) || 0);

  if (!companyId) {
    structureFeedback.textContent = "Selecione a empresa.";
    structureFeedback.classList.remove("is-success");
    return;
  }

  if (!name) {
    structureFeedback.textContent = "Informe o nome do item.";
    structureFeedback.classList.remove("is-success");
    return;
  }

  if (type === "function" && !sectorId) {
    structureFeedback.textContent = "Selecione o setor da função.";
    structureFeedback.classList.remove("is-success");
    return;
  }

  try {
    structureFeedback.textContent = editingStructureItem ? "Atualizando item..." : "Salvando item...";
    structureFeedback.classList.remove("is-success");

    await window.apiClient.post("api/company-structure.php", {
      id: editingStructureItem?.id || null,
      type,
      companyId,
      sectorId: type === "function" ? sectorId : null,
      name,
      employees,
    });

    await loadStructure(companyId);
    closeStructureModal();
  } catch (error) {
    structureFeedback.textContent = error.message || "Não foi possível salvar o item.";
    structureFeedback.classList.remove("is-success");
  }
}

async function handleDeleteStructureItem() {
  if (!editingStructureItem) {
    return;
  }

  const companyId = Number.parseInt(structureCompanyModalSelect?.value || "0", 10) || 0;

  if (!companyId) {
    return;
  }

  try {
    structureFeedback.textContent = "Excluindo item...";
    structureFeedback.classList.remove("is-success");

    await window.apiClient.delete(
      `api/company-structure.php?type=${editingStructureItem.type}&id=${editingStructureItem.id}&companyId=${companyId}`,
    );

    await loadStructure(companyId);
    closeStructureModal();
  } catch (error) {
    structureFeedback.textContent = error.message || "Não foi possível excluir o item.";
    structureFeedback.classList.remove("is-success");
  }
}

if (structureTable && structureCompanySelect) {
  window.appSessionPromise?.then((user) => {
    structureSession = user || null;
    return loadStructure();
  });

  structureCompanySelect.addEventListener("change", () => {
    loadStructure(getSelectedStructureCompanyId());
  });

  openStructureModalButton?.addEventListener("click", () => {
    openStructureModal({
      title: "Novo Setor",
      type: "sector",
      companyId: structureDashboard.selectedCompanyId,
      employees: 0,
    });
  });

  closeStructureModalButtons.forEach((button) => {
    button.addEventListener("click", closeStructureModal);
  });

  structureTypeSelect?.addEventListener("change", () => {
    structureSectorSelect.disabled = structureTypeSelect.value !== "function";
  });

  structureBuilder?.addEventListener("submit", handleStructureSubmit);
  deleteStructureItemButton?.addEventListener("click", handleDeleteStructureItem);
  structureTable.addEventListener("click", handleStructureTableClick);

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && structureModal && !structureModal.hidden) {
      closeStructureModal();
    }
  });
}
