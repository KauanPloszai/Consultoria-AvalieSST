const actionPlanScopeTitle = document.querySelector("[data-action-plan-scope-title]");
const actionPlanCompanyName = document.querySelector("[data-action-plan-company-name]");
const actionPlanScopeName = document.querySelector("[data-action-plan-scope-name]");
const actionPlanRiskLabel = document.querySelector("[data-action-plan-risk-label]");
const actionPlanRiskIndex = document.querySelector("[data-action-plan-risk-index]");
const actionPlanPeriodLabel = document.querySelector("[data-action-plan-period-label]");
const actionPlanTotalItems = document.querySelector("[data-action-plan-total-items]");
const actionPlanReportLink = document.querySelector("[data-action-plan-report-link]");
const actionPlanFormTitle = document.querySelector("[data-action-plan-form-title]");
const actionPlanForm = document.querySelector("[data-action-plan-form]");
const actionPlanItemIdInput = document.querySelector("[data-action-plan-item-id]");
const actionPlanFactorInput = document.querySelector("[data-action-plan-factor]");
const actionPlanTextInput = document.querySelector("[data-action-plan-text]");
const actionPlanDeadlineInput = document.querySelector("[data-action-plan-deadline]");
const actionPlanStatusSelect = document.querySelector("[data-action-plan-status]");
const actionPlanResponsibleInput = document.querySelector("[data-action-plan-responsible]");
const actionPlanNotesInput = document.querySelector("[data-action-plan-notes]");
const actionPlanFeedback = document.querySelector("[data-action-plan-feedback]");
const actionPlanNewButton = document.querySelector("[data-action-plan-new]");
const actionPlanCancelButton = document.querySelector("[data-action-plan-cancel]");
const actionPlanList = document.querySelector("[data-action-plan-list]");

const actionPlanState = {
  scope: {
    companyId: 0,
    sectorId: 0,
    functionId: 0,
    period: "180",
  },
  context: null,
  items: [],
  suggestion: null,
  statusOptions: [],
  editingId: 0,
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

function parseScopeFromUrl() {
  const params = new URLSearchParams(window.location.search);

  return {
    companyId: Number.parseInt(params.get("companyId") || "0", 10) || 0,
    sectorId: Number.parseInt(params.get("sectorId") || "0", 10) || 0,
    functionId: Number.parseInt(params.get("functionId") || "0", 10) || 0,
    period: params.get("period") || "180",
  };
}

function buildScopeQuery() {
  const params = new URLSearchParams();

  if (actionPlanState.scope.companyId > 0) {
    params.set("companyId", String(actionPlanState.scope.companyId));
  }

  if (actionPlanState.scope.sectorId > 0) {
    params.set("sectorId", String(actionPlanState.scope.sectorId));
  }

  if (actionPlanState.scope.functionId > 0) {
    params.set("functionId", String(actionPlanState.scope.functionId));
  }

  params.set("period", actionPlanState.scope.period || "180");
  return params.toString();
}

function setActionPlanFeedback(message = "", isSuccess = false) {
  if (!actionPlanFeedback) {
    return;
  }

  actionPlanFeedback.textContent = message;
  actionPlanFeedback.classList.toggle("is-success", Boolean(isSuccess));
}

function renderStatusOptions() {
  if (!actionPlanStatusSelect) {
    return;
  }

  const options = actionPlanState.statusOptions || [];

  actionPlanStatusSelect.innerHTML = options
    .map(
      (option) =>
        `<option value="${escapeHtml(option.slug)}">${escapeHtml(option.label)}</option>`,
    )
    .join("");
}

function buildReportLink() {
  if (!actionPlanReportLink) {
    return;
  }

  const params = new URLSearchParams();

  if (actionPlanState.scope.companyId > 0) {
    params.set("companyId", String(actionPlanState.scope.companyId));
  }

  params.set("period", actionPlanState.scope.period || "180");

  if (actionPlanState.scope.sectorId > 0) {
    params.set("sectorIds", String(actionPlanState.scope.sectorId));
  }

  actionPlanReportLink.href = `relatorios.html?${params.toString()}`;
}

function renderSummary() {
  const context = actionPlanState.context;
  const suggestion = actionPlanState.suggestion;

  if (!context) {
    return;
  }

  if (actionPlanScopeTitle) {
    actionPlanScopeTitle.textContent =
      context.scopeType === "function"
        ? `Plano de Ação da Função ${context.functionName}`
        : context.scopeType === "sector"
          ? `Plano de Ação do Setor ${context.sectorName}`
          : `Plano de Ação da Empresa ${context.companyName}`;
  }

  if (actionPlanCompanyName) {
    actionPlanCompanyName.textContent = context.companyName || "--";
  }

  if (actionPlanScopeName) {
    actionPlanScopeName.textContent = context.scopeName || "--";
  }

  if (actionPlanRiskLabel) {
    actionPlanRiskLabel.textContent = suggestion?.riskLabel || "Sem dados";
  }

  if (actionPlanRiskIndex) {
    actionPlanRiskIndex.textContent = `Índice consolidado: ${suggestion?.riskIndex || 0}/100`;
  }

  if (actionPlanPeriodLabel) {
    actionPlanPeriodLabel.textContent = context.periodLabel || "Período atual";
  }

  if (actionPlanTotalItems) {
    actionPlanTotalItems.textContent = String(actionPlanState.items.length);
  }

  buildReportLink();
}

function clearActionPlanForm(useSuggestion = true) {
  const suggestion = useSuggestion ? actionPlanState.suggestion : null;
  actionPlanState.editingId = 0;

  if (actionPlanItemIdInput) {
    actionPlanItemIdInput.value = "";
  }

  if (actionPlanFactorInput) {
    actionPlanFactorInput.value = suggestion?.factor || "";
  }

  if (actionPlanTextInput) {
    actionPlanTextInput.value = suggestion?.actionText || "";
  }

  if (actionPlanDeadlineInput) {
    actionPlanDeadlineInput.value = suggestion?.deadline || "";
  }

  if (actionPlanStatusSelect) {
    actionPlanStatusSelect.value = suggestion?.statusSlug || "monitor";
  }

  if (actionPlanResponsibleInput) {
    actionPlanResponsibleInput.value = "";
  }

  if (actionPlanNotesInput) {
    actionPlanNotesInput.value = "";
  }

  if (actionPlanFormTitle) {
    actionPlanFormTitle.textContent = "Nova Ação";
  }

  setActionPlanFeedback("");
}

function populateFormFromItem(item) {
  if (!item) {
    return;
  }

  actionPlanState.editingId = Number(item.id || 0);

  if (actionPlanItemIdInput) {
    actionPlanItemIdInput.value = String(item.id || "");
  }

  if (actionPlanFactorInput) {
    actionPlanFactorInput.value = item.factor || "";
  }

  if (actionPlanTextInput) {
    actionPlanTextInput.value = item.actionText || "";
  }

  if (actionPlanDeadlineInput) {
    actionPlanDeadlineInput.value = item.deadline || "";
  }

  if (actionPlanStatusSelect) {
    actionPlanStatusSelect.value = item.statusSlug || "monitor";
  }

  if (actionPlanResponsibleInput) {
    actionPlanResponsibleInput.value = item.responsible || "";
  }

  if (actionPlanNotesInput) {
    actionPlanNotesInput.value = item.notes || "";
  }

  if (actionPlanFormTitle) {
    actionPlanFormTitle.textContent = "Editar Ação";
  }

  window.scrollTo({ top: 0, behavior: "smooth" });
}

function renderActionPlanList() {
  if (!actionPlanList) {
    return;
  }

  if (!actionPlanState.items.length) {
    actionPlanList.innerHTML = `
      <div class="org-empty-state">
        Nenhuma ação cadastrada ainda para este escopo. Use a sugestão acima e salve a primeira ação para ela aparecer no relatório.
      </div>
    `;
    return;
  }

  actionPlanList.innerHTML = actionPlanState.items
    .map(
      (item) => `
        <article class="action-plan-item">
          <div class="action-plan-item__head">
            <div>
              <strong>${escapeHtml(item.factor)}</strong>
              <span>${escapeHtml(item.sectorName || actionPlanState.context?.scopeName || "Empresa")}</span>
            </div>

            <span class="plan-status plan-status--${escapeHtml(item.statusSlug || "monitor")}">${escapeHtml(item.statusLabel || "Monitorar")}</span>
          </div>

          <div class="action-plan-item__body">
            <div class="action-plan-item__meta">
              <span>Ação</span>
              <p>${escapeHtml(item.actionText || "--")}</p>
            </div>

            <div class="action-plan-item__meta-grid">
              <div class="action-plan-item__meta">
                <span>Prazo</span>
                <p>${escapeHtml(item.deadline || "--")}</p>
              </div>

              <div class="action-plan-item__meta">
                <span>Responsável</span>
                <p>${escapeHtml(item.responsible || "--")}</p>
              </div>
            </div>

            ${
              item.notes
                ? `
                  <div class="action-plan-item__meta">
                    <span>Observações</span>
                    <p>${escapeHtml(item.notes)}</p>
                  </div>
                `
                : ""
            }
          </div>

          <div class="action-plan-item__actions">
            <button class="form-builder__ghost" type="button" data-action-plan-edit="${item.id}">Editar</button>
            <button class="form-builder__ghost action-plan-item__delete" type="button" data-action-plan-delete="${item.id}">Excluir</button>
          </div>
        </article>
      `,
    )
    .join("");
}

function renderPage() {
  renderStatusOptions();
  renderSummary();
  renderActionPlanList();
}

async function loadActionPlanPage() {
  if (!actionPlanForm) {
    return;
  }

  try {
    setActionPlanFeedback("Carregando plano de ação...");
    const response = await window.apiClient.get(`api/action-plans.php?${buildScopeQuery()}`);
    const data = response.data || {};

    actionPlanState.context = data.context || null;
    actionPlanState.items = Array.isArray(data.items) ? data.items : [];
    actionPlanState.suggestion = data.suggestion || null;
    actionPlanState.statusOptions = Array.isArray(data.statusOptions) ? data.statusOptions : [];

    renderPage();
    clearActionPlanForm(true);
    setActionPlanFeedback("Plano carregado com sucesso.", true);
  } catch (error) {
    renderActionPlanList();
    setActionPlanFeedback(error.message || "Não foi possível carregar o plano de ação.");
  }
}

function readActionPlanPayload() {
  return {
    id: Number.parseInt(actionPlanItemIdInput?.value || "0", 10) || 0,
    companyId: actionPlanState.scope.companyId,
    sectorId: actionPlanState.scope.sectorId,
    functionId: actionPlanState.scope.functionId,
    period: actionPlanState.scope.period,
    factor: actionPlanFactorInput?.value.trim() || "",
    actionText: actionPlanTextInput?.value.trim() || "",
    deadline: actionPlanDeadlineInput?.value.trim() || "",
    statusSlug: actionPlanStatusSelect?.value || "monitor",
    responsible: actionPlanResponsibleInput?.value.trim() || "",
    notes: actionPlanNotesInput?.value.trim() || "",
  };
}

async function handleActionPlanSubmit(event) {
  event.preventDefault();

  const payload = readActionPlanPayload();

  if (!payload.companyId) {
    setActionPlanFeedback("Empresa invalida para salvar o plano.");
    return;
  }

  try {
    setActionPlanFeedback(payload.id ? "Atualizando ação..." : "Salvando ação...");

    const response = payload.id
      ? await window.apiClient.put("api/action-plans.php", payload)
      : await window.apiClient.post("api/action-plans.php", payload);

    const data = response.data || {};
    actionPlanState.context = data.context || actionPlanState.context;
    actionPlanState.items = Array.isArray(data.items) ? data.items : actionPlanState.items;
    actionPlanState.suggestion = data.suggestion || actionPlanState.suggestion;

    renderPage();
    clearActionPlanForm(true);
    setActionPlanFeedback(response.message || "Ação salva com sucesso.", true);
  } catch (error) {
    setActionPlanFeedback(error.message || "Não foi possível salvar a ação.");
  }
}

async function deleteActionPlanItem(itemId) {
  if (!itemId) {
    return;
  }

  if (!window.confirm("Deseja realmente excluir esta ação do plano?")) {
    return;
  }

  try {
    setActionPlanFeedback("Removendo ação...");
    const response = await window.apiClient.delete(`api/action-plans.php?id=${itemId}&period=${encodeURIComponent(actionPlanState.scope.period)}`);
    const data = response.data || {};

    actionPlanState.context = data.context || actionPlanState.context;
    actionPlanState.items = Array.isArray(data.items) ? data.items : [];
    actionPlanState.suggestion = data.suggestion || actionPlanState.suggestion;

    renderPage();
    clearActionPlanForm(true);
    setActionPlanFeedback(response.message || "Ação removida com sucesso.", true);
  } catch (error) {
    setActionPlanFeedback(error.message || "Não foi possível remover a ação.");
  }
}

function handleActionPlanListClick(event) {
  const editButton = event.target.closest("[data-action-plan-edit]");

  if (editButton) {
    const itemId = Number.parseInt(editButton.getAttribute("data-action-plan-edit") || "0", 10) || 0;
    const item = actionPlanState.items.find((entry) => Number(entry.id) === itemId) || null;

    if (item) {
      populateFormFromItem(item);
    }

    return;
  }

  const deleteButton = event.target.closest("[data-action-plan-delete]");

  if (deleteButton) {
    const itemId = Number.parseInt(deleteButton.getAttribute("data-action-plan-delete") || "0", 10) || 0;
    void deleteActionPlanItem(itemId);
  }
}

if (actionPlanForm) {
  actionPlanState.scope = parseScopeFromUrl();

  actionPlanForm.addEventListener("submit", handleActionPlanSubmit);
  actionPlanNewButton?.addEventListener("click", () => clearActionPlanForm(true));
  actionPlanCancelButton?.addEventListener("click", () => clearActionPlanForm(true));
  actionPlanList?.addEventListener("click", handleActionPlanListClick);

  void loadActionPlanPage();
}
