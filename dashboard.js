const userEmailLabel = document.querySelector("[data-user-email]");
const userNameLabel = document.querySelector(".user-chip__meta strong");
const userAvatarLabel = document.querySelector(".user-chip__avatar");
const logoutButton = document.querySelector("[data-logout]");
const headerActions = document.querySelector(".app-header__actions");
const headerBreadcrumb = document.querySelector(".app-header__title span");

const dashboardActiveCompanies = document.querySelector("[data-dashboard-active-companies]");
const dashboardActiveCompaniesHint = document.querySelector("[data-dashboard-active-companies-hint]");
const dashboardInProgress = document.querySelector("[data-dashboard-in-progress]");
const dashboardInProgressHint = document.querySelector("[data-dashboard-in-progress-hint]");
const dashboardCompliance = document.querySelector("[data-dashboard-compliance]");
const dashboardComplianceBar = document.querySelector("[data-dashboard-compliance-bar]");
const dashboardComplianceHint = document.querySelector("[data-dashboard-compliance-hint]");
const dashboardPendingActions = document.querySelector("[data-dashboard-pending-actions]");
const dashboardPendingActionsHint = document.querySelector("[data-dashboard-pending-actions-hint]");
const dashboardChartSubtitle = document.querySelector("[data-dashboard-chart-subtitle]");
const dashboardLineChart = document.querySelector("[data-dashboard-line-chart]");
const dashboardDonutChart = document.querySelector("[data-dashboard-donut-chart]");
const dashboardDonutLegend = document.querySelector("[data-dashboard-donut-legend]");
const dashboardCompanyFilter = document.querySelector("[data-dashboard-company-filter]");
const dashboardSectorFilter = document.querySelector("[data-dashboard-sector-filter]");
const dashboardFunctionFilter = document.querySelector("[data-dashboard-function-filter]");
const dashboardPeriodFilter = document.querySelector("[data-dashboard-period-filter]");
const dashboardApplyButton = document.querySelector("[data-dashboard-apply]");
const dashboardPlanBody = document.querySelector("[data-dashboard-plan-body]");

const dashboardState = {
  filters: {
    companyId: 0,
    sectorId: 0,
    functionId: 0,
    period: "180",
  },
  options: {
    companies: [],
    sectors: [],
    functions: [],
  },
  isLoading: false,
  lastCompletionSeries: [],
};

const COMPANY_HOME_URL = "geracao-acesso.html";
const COMPANY_ALLOWED_PAGES = new Set(["geracao-acesso.html", "setores-funcao.html"]);

window.appSession = null;
window.appSessionPromise = null;

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

function hideInterfaceElement(element) {
  if (!element) {
    return;
  }

  element.hidden = true;
  element.style.display = "none";
  element.setAttribute("aria-hidden", "true");
}

function showInterfaceElement(element, displayValue = "") {
  if (!element) {
    return;
  }

  element.hidden = false;
  element.style.display = displayValue;
  element.removeAttribute("aria-hidden");
}

function removeInterfaceElements(selector) {
  document.querySelectorAll(selector).forEach((element) => {
    element.remove();
  });
}

function closeResponsiveNavigation() {
  document.body.classList.remove("is-mobile-menu-open");
  document.querySelector(".mobile-menu-toggle")?.setAttribute("aria-expanded", "false");
}

function setupResponsiveNavigation() {
  const header = document.querySelector(".app-header");
  const brand = document.querySelector(".app-header__brand");
  const sidebar = document.querySelector(".sidebar");

  if (!header || !brand || !sidebar || document.querySelector(".mobile-menu-toggle")) {
    return;
  }

  const toggleButton = document.createElement("button");
  toggleButton.className = "mobile-menu-toggle";
  toggleButton.type = "button";
  toggleButton.setAttribute("aria-label", "Abrir menu principal");
  toggleButton.setAttribute("aria-expanded", "false");
  toggleButton.innerHTML = `
    <span aria-hidden="true"></span>
    <span aria-hidden="true"></span>
    <span aria-hidden="true"></span>
  `;

  const backdrop = document.createElement("button");
  backdrop.className = "mobile-nav-backdrop";
  backdrop.type = "button";
  backdrop.setAttribute("aria-label", "Fechar menu");

  brand.insertAdjacentElement("afterend", toggleButton);
  document.body.appendChild(backdrop);

  toggleButton.addEventListener("click", () => {
    const isOpen = document.body.classList.toggle("is-mobile-menu-open");
    toggleButton.setAttribute("aria-expanded", String(isOpen));
  });

  backdrop.addEventListener("click", closeResponsiveNavigation);
  sidebar.querySelectorAll("a").forEach((link) => {
    link.addEventListener("click", closeResponsiveNavigation);
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      closeResponsiveNavigation();
    }
  });

  window.addEventListener("resize", () => {
    if (window.innerWidth > 1024) {
      closeResponsiveNavigation();
    }
  });
}

function parseDashboardFiltersFromUrl() {
  const params = new URLSearchParams(window.location.search);

  return {
    companyId: Number.parseInt(params.get("companyId") || "0", 10) || 0,
    sectorId: Number.parseInt(params.get("sectorId") || "0", 10) || 0,
    functionId: Number.parseInt(params.get("functionId") || "0", 10) || 0,
    period: params.get("period") || "180",
  };
}

function replaceDashboardUrl(filters) {
  const query = buildDashboardQuery(filters);
  const nextUrl = `${window.location.pathname}${query ? `?${query}` : ""}`;
  window.history.replaceState({}, "", nextUrl);
}

function renderDashboardEmptyState(target, message) {
  if (!target) {
    return;
  }

  target.innerHTML = `<div class="org-empty-state">${escapeHtml(message)}</div>`;
}

function getNiceAxisMax(maxValue) {
  const normalizedMax = Math.max(1, Number(maxValue || 0));

  if (normalizedMax <= 5) {
    return 5;
  }

  if (normalizedMax <= 10) {
    return 10;
  }

  const magnitude = 10 ** Math.floor(Math.log10(normalizedMax));
  return Math.ceil(normalizedMax / magnitude) * magnitude;
}

async function loadSession() {
  try {
    const response = await window.apiClient.get("api/session.php");
    const user = response.data || {};
    const currentPage = window.location.pathname.split("/").pop() || "dashboard.html";

    window.appSession = user;

    if (userEmailLabel) {
      userEmailLabel.textContent = user.email || "admin@avaliiesst.com";
    }

    if (userNameLabel) {
      userNameLabel.textContent = user.name || (user.role === "company" ? "Usuário da Empresa" : "Admin Principal");
    }

    if (userAvatarLabel) {
      const avatarSource = String(user.name || user.email || "A").trim();
      userAvatarLabel.textContent = avatarSource.charAt(0).toUpperCase() || "A";
    }

    if (headerBreadcrumb && user.role === "company") {
      headerBreadcrumb.textContent = user.companyName
        ? `Empresa > ${user.companyName}`
        : "Empresa >";
    }

    applyRolePermissions(user, currentPage);
    document.body.dataset.userRole = user.role || "admin";
    document.body.dataset.userCompanyId = user.companyId ? String(user.companyId) : "";
    window.dispatchEvent(new CustomEvent("app:session-ready", { detail: user }));
    return user;
  } catch (error) {
    window.location.href = "index.html";
    return null;
  }
}

function setSidebarLinkLabel(anchor, nextLabel) {
  const label = anchor?.querySelector("span:last-child");

  if (label) {
    label.textContent = nextLabel;
  }
}

function applyRolePermissions(user, currentPage) {
  const adminLink = document.querySelector('.sidebar-link[href="dashboard.html"]');
  const sectorsLink = document.querySelector('.sidebar-link[href="setores-funcao.html"]');
  const restrictedMenuSelector = '.sidebar-link[href="formularios.html"], .sidebar-link[href="relatorios.html"]';
  const structureAction = document.querySelector("[data-open-structure-modal]");

  if ((user.role || "admin") !== "company") {
    return;
  }

  if (!COMPANY_ALLOWED_PAGES.has(currentPage)) {
    window.location.href = COMPANY_HOME_URL;
    return;
  }

  if (adminLink) {
    adminLink.setAttribute("href", COMPANY_HOME_URL);
    setSidebarLinkLabel(adminLink, "Geração de Acesso");
    adminLink.classList.toggle("sidebar-link--active", currentPage === COMPANY_HOME_URL);
  }

  if (sectorsLink) {
    sectorsLink.classList.toggle("sidebar-link--active", currentPage === "setores-funcao.html");
  }

  removeInterfaceElements(restrictedMenuSelector);

  headerActions?.querySelectorAll(".header-action").forEach((action) => {
    if (currentPage === "setores-funcao.html" && action === structureAction) {
      showInterfaceElement(action, "inline-flex");
      return;
    }

    hideInterfaceElement(action);
  });
}

function getSelectedDashboardFilters() {
  return {
    companyId: Number.parseInt(dashboardCompanyFilter?.value || "0", 10) || 0,
    sectorId: Number.parseInt(dashboardSectorFilter?.value || "0", 10) || 0,
    functionId: Number.parseInt(dashboardFunctionFilter?.value || "0", 10) || 0,
    period: dashboardPeriodFilter?.value || "180",
  };
}

function buildDashboardQuery(filters) {
  const params = new URLSearchParams();

  if (filters.companyId > 0) {
    params.set("companyId", String(filters.companyId));
  }

  if (filters.sectorId > 0) {
    params.set("sectorId", String(filters.sectorId));
  }

  if (filters.functionId > 0) {
    params.set("functionId", String(filters.functionId));
  }

  params.set("period", filters.period || "180");
  return params.toString();
}

function setDashboardLoadingState(isLoading) {
  dashboardState.isLoading = isLoading;

  if (dashboardApplyButton) {
    dashboardApplyButton.disabled = isLoading;
    dashboardApplyButton.textContent = isLoading ? "Carregando..." : "Filtrar";
  }

  if (dashboardCompanyFilter) {
    dashboardCompanyFilter.disabled = isLoading;
  }

  if (dashboardSectorFilter) {
    dashboardSectorFilter.disabled = isLoading;
  }

  if (dashboardFunctionFilter) {
    dashboardFunctionFilter.disabled = isLoading;
  }

  if (dashboardPeriodFilter) {
    dashboardPeriodFilter.disabled = isLoading;
  }
}

function populateDashboardFilters(options, filters) {
  if (!dashboardCompanyFilter || !dashboardSectorFilter || !dashboardFunctionFilter) {
    return;
  }

  dashboardCompanyFilter.innerHTML = (options.companies || [])
    .map((company) => `<option value="${company.id}">${escapeHtml(company.name)}</option>`)
    .join("");

  dashboardSectorFilter.innerHTML = ['<option value="0">Todos os Setores</option>']
    .concat(
      (options.sectors || []).map((sector) => `<option value="${sector.id}">${escapeHtml(sector.name)}</option>`),
    )
    .join("");

  const filteredFunctions = (options.functions || []).filter((companyFunction) => {
    if (!filters.sectorId) {
      return true;
    }

    return Number(companyFunction.sectorId) === Number(filters.sectorId);
  });

  dashboardFunctionFilter.innerHTML = ['<option value="0">Todas as Funções</option>']
    .concat(
      filteredFunctions.map(
        (companyFunction) =>
          `<option value="${companyFunction.id}">${escapeHtml(companyFunction.name)}</option>`,
      ),
    )
    .join("");

  if (filters.companyId) {
    dashboardCompanyFilter.value = String(filters.companyId);
  }

  dashboardSectorFilter.value = String(filters.sectorId || 0);
  dashboardFunctionFilter.value = String(filters.functionId || 0);

  if (dashboardPeriodFilter) {
    dashboardPeriodFilter.value = filters.period || "180";
  }
}

function renderDashboardMetrics(metrics) {
  if (dashboardActiveCompanies) {
    dashboardActiveCompanies.textContent = String(metrics.activeCompanies || 0);
  }

  if (dashboardActiveCompaniesHint) {
    dashboardActiveCompaniesHint.textContent = metrics.hints?.activeCompanies || "Empresas ativas no ambiente.";
  }

  if (dashboardInProgress) {
    dashboardInProgress.textContent = String(metrics.evaluationsInProgress || 0);
  }

  if (dashboardInProgressHint) {
    dashboardInProgressHint.textContent =
      metrics.hints?.evaluationsInProgress || "Sessões abertas para a empresa filtrada.";
  }

  if (dashboardCompliance) {
    dashboardCompliance.textContent = `${metrics.complianceRate || 0}%`;
  }

  if (dashboardComplianceBar) {
    dashboardComplianceBar.style.width = `${metrics.complianceRate || 0}%`;
  }

  if (dashboardComplianceHint) {
    dashboardComplianceHint.textContent = metrics.hints?.complianceRate || "Taxa de resposta do período atual.";
  }

  if (dashboardPendingActions) {
    dashboardPendingActions.textContent = String(metrics.pendingActions || 0);
  }

  if (dashboardPendingActionsHint) {
    dashboardPendingActionsHint.textContent =
      metrics.hints?.pendingActions || "Setores e funções com risco moderado ou alto.";
  }
}

function renderDashboardLineChart(series) {
  if (!dashboardLineChart) {
    return;
  }

  const normalizedSeries = Array.isArray(series) ? series : [];
  dashboardState.lastCompletionSeries = normalizedSeries;

  if (!normalizedSeries.length || normalizedSeries.every((item) => Number(item.value || 0) === 0)) {
    renderDashboardEmptyState(
      dashboardLineChart,
      "Sem avaliações concluídas no período selecionado.",
    );
    return;
  }

  const width = Math.max(Math.round(dashboardLineChart.clientWidth || 620), 620);
  const height = Math.max(Math.round(dashboardLineChart.clientHeight || 220), 220);
  const left = 54;
  const right = 18;
  const top = 18;
  const bottom = 36;
  const chartWidth = width - left - right;
  const chartHeight = height - top - bottom;
  const maxValue = getNiceAxisMax(
    Math.max(...normalizedSeries.map((item) => Number(item.value || 0)), 1),
  );

  const points = normalizedSeries.map((item, index) => {
    const x = left + (chartWidth / Math.max(normalizedSeries.length - 1, 1)) * index;
    const y = top + chartHeight - (chartHeight * Number(item.value || 0)) / maxValue;
    return { x, y, value: Number(item.value || 0), label: item.label || "" };
  });

  const gridLines = Array.from({ length: 5 }, (_, index) => {
    const y = top + (chartHeight / 4) * index;
    const value = Math.round(maxValue - (maxValue / 4) * index);
    return `
      <line x1="${left}" y1="${y}" x2="${left + chartWidth}" y2="${y}"></line>
      <text x="18" y="${y + 4}">${value}</text>
    `;
  }).join("");

  const labelMarkup = points
    .map((point) => `<text x="${point.x}" y="${height - 10}" text-anchor="middle">${escapeHtml(point.label)}</text>`)
    .join("");

  const path = points
    .map((point, index) => {
      if (index === 0) {
        return `M${point.x.toFixed(2)} ${point.y.toFixed(2)}`;
      }

      const previous = points[index - 1];
      const controlX = ((previous.x + point.x) / 2).toFixed(2);
      return `C${controlX} ${previous.y.toFixed(2)}, ${controlX} ${point.y.toFixed(2)}, ${point.x.toFixed(2)} ${point.y.toFixed(2)}`;
    })
    .join(" ");

  const areaPath = `${path} L${left + chartWidth} ${top + chartHeight} L${left} ${top + chartHeight} Z`;

  const pointMarkup = points
    .map((point) => `<circle cx="${point.x}" cy="${point.y}" r="4"></circle>`)
    .join("");

  dashboardLineChart.innerHTML = `
    <svg viewBox="0 0 ${width} ${height}" role="presentation">
      <g class="line-chart__grid">${gridLines}</g>
      <path class="line-chart__area" d="${areaPath}"></path>
      <path class="line-chart__path" d="${path}"></path>
      <g class="line-chart__points">${pointMarkup}</g>
      <g class="line-chart__labels">${labelMarkup}</g>
    </svg>
  `;
}

function renderDashboardDonut(statusBreakdown) {
  if (!dashboardDonutChart || !dashboardDonutLegend) {
    return;
  }

  const items = Array.isArray(statusBreakdown) ? statusBreakdown : [];
  const total = items.reduce((sum, item) => sum + Number(item.value || 0), 0);

  if (!total) {
    dashboardDonutChart.style.background =
      "radial-gradient(circle at center, #fff 0 42%, transparent 43%), conic-gradient(#e5edf7 0 100%)";
    dashboardDonutLegend.innerHTML = "<span>Nenhuma sessão encontrada para o filtro atual.</span>";
    return;
  }

  let start = 0;
  const parts = items.map((item) => {
    const value = Number(item.value || 0);
    const size = (value / total) * 100;
    const segment = `${item.color} ${start}% ${start + size}%`;
    start += size;
    return segment;
  });

  dashboardDonutChart.style.background =
    `radial-gradient(circle at center, #fff 0 42%, transparent 43%), conic-gradient(${parts.join(", ")})`;
  dashboardDonutLegend.innerHTML = items
    .map(
      (item) =>
        `<span><i class="legend__dot" style="background:${escapeHtml(item.color)};"></i>${escapeHtml(item.label)} (${item.value})</span>`,
    )
    .join("");
}

function buildDashboardScopeSubtitle(row, company) {
  const companyName = company?.name || "Empresa";

  if (row.type === "function") {
    const sectorName = row.sectorName || row.parentSectorName || "";

    return sectorName ? `Função • ${sectorName} • ${companyName}` : `Função • ${companyName}`;
  }

  return `Setor • ${companyName}`;
}

function buildDashboardScopeTitle(row) {
  if (row.displayName) {
    return row.displayName;
  }

  if (row.type === "function") {
    const sectorName = row.sectorName || row.parentSectorName || "";

    return sectorName ? `${sectorName} / ${row.name}` : row.name;
  }

  return row.name;
}

function renderDashboardPlanRows(rows, company) {
  if (!dashboardPlanBody) {
    return;
  }

  const normalizedRows = Array.isArray(rows) ? rows : [];

  if (!normalizedRows.length) {
    renderDashboardEmptyState(
      dashboardPlanBody,
      "Nenhum setor com dados suficientes para montar o plano de ação.",
    );
    return;
  }

  dashboardPlanBody.innerHTML = normalizedRows
    .map((row) => {
      const scopeTitle = buildDashboardScopeTitle(row);
      const scopeSubtitle = buildDashboardScopeSubtitle(row, company);
      const actionUrl = row.actionUrl || "setores-funcao.html";

      return `
        <article class="table-row">
          <div class="table-row__main">
            <span class="building-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" role="presentation">
                <path d="M5 20V8.5A1.5 1.5 0 0 1 6.5 7H14v13H5Zm9 0V4.5A1.5 1.5 0 0 1 15.5 3H19v17h-5ZM8 10h2m-2 3h2m-2 3h2m7-6h1m-1 3h1" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.5" />
              </svg>
            </span>
            <div class="company-meta">
              <strong>${escapeHtml(scopeTitle)}</strong>
              <span>${escapeHtml(scopeSubtitle)}</span>
            </div>
          </div>
          <span class="table-row__count">${Number(row.employees || 0)}</span>
          <span class="risk-badge risk-badge--${escapeHtml(row.riskSlug || "neutral")}">${escapeHtml(row.riskLabel || "Sem dados")}</span>
          <a class="edit-button" href="${escapeHtml(actionUrl)}">
            <svg viewBox="0 0 24 24" role="presentation">
              <path d="m4 16.5 9.8-9.8a2.1 2.1 0 1 1 3 3L7 19.5 4 20l.5-3.5Z" fill="none" stroke="currentColor" stroke-linejoin="round" stroke-width="1.7" />
            </svg>
          </a>
        </article>
      `;
    })
    .join("");
}

async function loadDashboard(options = {}) {
  if (!dashboardCompanyFilter || !dashboardPlanBody) {
    return;
  }

  const requestedFilters = options.useStateFilters ? dashboardState.filters : getSelectedDashboardFilters();

  try {
    setDashboardLoadingState(true);
    const query = buildDashboardQuery(requestedFilters);
    const response = await window.apiClient.get(`api/dashboard-data.php?${query}`);
    const data = response.data || {};

    dashboardState.filters = {
      companyId: Number(data.filters?.companyId || 0),
      sectorId: Number(data.filters?.sectorId || 0),
      functionId: Number(data.filters?.functionId || 0),
      period: data.filters?.period || "180",
    };
    dashboardState.options = data.options || { companies: [], sectors: [], functions: [] };

    populateDashboardFilters(dashboardState.options, dashboardState.filters);
    renderDashboardMetrics(data.metrics || {});
    renderDashboardLineChart(data.completionSeries || []);
    renderDashboardDonut(data.statusBreakdown || []);
    renderDashboardPlanRows(data.planRows || [], data.company || null);
    replaceDashboardUrl(dashboardState.filters);

    if (dashboardChartSubtitle) {
      dashboardChartSubtitle.textContent = `Volume de avaliações concluídas para ${data.company?.name || "a empresa"} no período selecionado`;
    }
  } catch (error) {
    renderDashboardEmptyState(
      dashboardLineChart,
      error.message || "Não foi possível carregar o dashboard.",
    );
    renderDashboardEmptyState(
      dashboardPlanBody,
      error.message || "Não foi possível carregar os dados.",
    );
  } finally {
    setDashboardLoadingState(false);
  }
}

setupResponsiveNavigation();
window.appSessionPromise = loadSession();

window.addEventListener("app:session-ready", (event) => {
  if ((event.detail?.role || "admin") !== "company") {
    return;
  }

  removeInterfaceElements('.sidebar-link[href="formularios.html"], .sidebar-link[href="relatorios.html"]');
});

if (logoutButton) {
  logoutButton.addEventListener("click", async () => {
    try {
      await window.apiClient.delete("api/session.php");
    } catch (error) {
      // Ignore and force redirect.
    }

    window.location.href = "index.html";
  });
}

if (dashboardCompanyFilter && dashboardPlanBody) {
  dashboardState.filters = {
    ...dashboardState.filters,
    ...parseDashboardFiltersFromUrl(),
  };

  window.appSessionPromise?.then((user) => {
    if (!user || user.role === "company") {
      return;
    }

    dashboardApplyButton?.addEventListener("click", () => {
      void loadDashboard();
    });

    dashboardCompanyFilter.addEventListener("change", () => {
      dashboardState.filters.companyId = Number.parseInt(dashboardCompanyFilter.value || "0", 10) || 0;
      dashboardState.filters.sectorId = 0;
      dashboardState.filters.functionId = 0;
      if (dashboardSectorFilter) {
        dashboardSectorFilter.value = "0";
      }
      if (dashboardFunctionFilter) {
        dashboardFunctionFilter.value = "0";
      }
      void loadDashboard({ useStateFilters: true });
    });

    dashboardSectorFilter?.addEventListener("change", () => {
      dashboardState.filters.sectorId = Number.parseInt(dashboardSectorFilter.value || "0", 10) || 0;
      dashboardState.filters.functionId = 0;
      if (dashboardFunctionFilter) {
        dashboardFunctionFilter.value = "0";
      }
      void loadDashboard({ useStateFilters: true });
    });

    dashboardFunctionFilter?.addEventListener("change", () => {
      dashboardState.filters.functionId = Number.parseInt(dashboardFunctionFilter.value || "0", 10) || 0;
    });

    dashboardPeriodFilter?.addEventListener("change", () => {
      dashboardState.filters.period = dashboardPeriodFilter.value || "180";
    });

    void loadDashboard({ useStateFilters: true });
  });
}

let dashboardResizeTimer = null;

window.addEventListener("resize", () => {
  if (!dashboardLineChart || !dashboardState.lastCompletionSeries.length) {
    return;
  }

  window.clearTimeout(dashboardResizeTimer);
  dashboardResizeTimer = window.setTimeout(() => {
    renderDashboardLineChart(dashboardState.lastCompletionSeries);
  }, 120);
});
