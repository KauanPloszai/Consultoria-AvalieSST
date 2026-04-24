const reportCompanySelect = document.querySelector("[data-report-company]");
const reportFormSelect = document.querySelector("[data-report-form]");
const reportPeriodSelect = document.querySelector("[data-report-period]");
const reportSectorList = document.querySelector("[data-report-sector-list]");
const reportRefreshButton = document.querySelector("[data-report-refresh]");
const reportPreviewArea = document.querySelector("[data-report-preview-area]");
const reportFeedback = document.querySelector("[data-report-feedback]");
const reportExcelButton = document.querySelector("[data-report-export-excel]");
const reportPdfButton = document.querySelector("[data-report-export-pdf]");
const reportSectionInputs = document.querySelectorAll("[data-report-section]");

const REPORT_SECTION_META = {
  cover: { label: "Capa e Índice" },
  summary: { label: "Resumo Executivo e Métricas Principais" },
  sectorAnalysis: { label: "Análise por Setor" },
  heatmap: { label: "Matriz de Risco Geral" },
  actionPlan: { label: "Plano de Ação Recomendado" },
};

const reportState = {
  data: null,
};

function safeArray(value) {
  return Array.isArray(value) ? value : [];
}

function escapeHtml(value) {
  return String(value ?? "").replace(/[&<>"']/g, (character) => {
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

function formatAverage(value) {
  return new Intl.NumberFormat("pt-BR", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(Number(value || 0));
}

function formatPercent(value) {
  return `${Number(value || 0)}%`;
}

function formatInteger(value) {
  return new Intl.NumberFormat("pt-BR").format(Number(value || 0));
}

function getSelectedReportSections() {
  return Array.from(reportSectionInputs)
    .filter((input) => input.checked)
    .map((input) => input.getAttribute("data-report-section"))
    .filter(Boolean);
}

function getSelectedSectorIds() {
  return Array.from(reportSectorList?.querySelectorAll("input[type='checkbox']:checked") || [])
    .map((input) => Number.parseInt(input.value, 10) || 0)
    .filter((id) => id > 0);
}

function buildReportQuery() {
  const params = new URLSearchParams();
  const companyId = Number.parseInt(reportCompanySelect?.value || "0", 10) || 0;
  const formId = Number.parseInt(reportFormSelect?.value || "0", 10) || 0;
  const period = reportPeriodSelect?.value || "180";
  const sectorIds = getSelectedSectorIds();
  const sections = getSelectedReportSections();

  if (companyId > 0) {
    params.set("companyId", String(companyId));
  }

  if (formId > 0) {
    params.set("formId", String(formId));
  }

  params.set("period", period);

  if (sectorIds.length) {
    params.set("sectorIds", sectorIds.join(","));
  }

  if (sections.length) {
    params.set("sections", sections.join(","));
  }

  return params.toString();
}

function renderReportSectorList(options = [], selectedIds = []) {
  if (!reportSectorList) {
    return;
  }

  const normalizedOptions = safeArray(options);
  const normalizedSelectedIds = selectedIds.length
    ? selectedIds.map((id) => Number(id))
    : normalizedOptions.map((sector) => Number(sector.id));

  if (!normalizedOptions.length) {
    reportSectorList.innerHTML = '<span class="org-empty-state">Nenhum setor cadastrado para esta empresa.</span>';
    return;
  }

  reportSectorList.innerHTML = normalizedOptions
    .map(
      (sector) => `
        <label class="report-checkrow">
          <input type="checkbox" value="${sector.id}" ${normalizedSelectedIds.includes(Number(sector.id)) ? "checked" : ""} />
          <span>${escapeHtml(sector.name)}</span>
        </label>
      `,
    )
    .join("");
}

function renderReportCompanyOptions(companies, selectedCompanyId) {
  if (!reportCompanySelect) {
    return;
  }

  reportCompanySelect.innerHTML = safeArray(companies)
    .map((company) => `<option value="${company.id}">${escapeHtml(company.name)}</option>`)
    .join("");

  if (selectedCompanyId) {
    reportCompanySelect.value = String(selectedCompanyId);
  }
}

function renderReportFormOptions(forms, selectedFormId) {
  if (!reportFormSelect) {
    return;
  }

  const normalizedForms = safeArray(forms);

  if (!normalizedForms.length) {
    reportFormSelect.innerHTML = '<option value="0">Nenhum formulario vinculado</option>';
    reportFormSelect.value = "0";
    return;
  }

  reportFormSelect.innerHTML = normalizedForms
    .map((form) => {
      const code = String(form.publicCode || "");
      const statusLabel = String(form.status || "active") === "inactive" ? "Inativo" : "Ativo";
      const suffix = code ? ` (${code})` : "";

      return `<option value="${form.id}">${escapeHtml(`${form.name || "Formulario"}${suffix} - ${statusLabel}`)}</option>`;
    })
    .join("");

  if (selectedFormId) {
    reportFormSelect.value = String(selectedFormId);
  }
}

function getReportPageSequence() {
  const sections = getSelectedReportSections();
  const pages = [];
  let currentPage = 1;

  if (sections.includes("cover")) {
    pages.push({ key: "cover", pageNumber: currentPage++ });
    pages.push({ key: "index", pageNumber: currentPage++ });
  }

  sections
    .filter((section) => section !== "cover")
    .forEach((section) => {
      pages.push({ key: section, pageNumber: currentPage++ });
    });

  return pages;
}

function resolveSelectedSectorNames(data) {
  const availableSectors = safeArray(data?.options?.sectors);
  const selectedSectorIds = safeArray(data?.filters?.sectorIds).map((id) => Number(id));

  if (!availableSectors.length) {
    return [];
  }

  if (!selectedSectorIds.length) {
    return availableSectors.map((sector) => String(sector.name || "")).filter(Boolean);
  }

  return availableSectors
    .filter((sector) => selectedSectorIds.includes(Number(sector.id)))
    .map((sector) => String(sector.name || ""))
    .filter(Boolean);
}

function buildIndexRows(pageSequence) {
  return pageSequence
    .filter((entry) => !["cover", "index"].includes(entry.key))
    .map((entry, index) => {
      const label = REPORT_SECTION_META[entry.key]?.label || entry.key;

      return `
        <div class="report-index__row">
          <span>${index + 1}. ${escapeHtml(label)}</span>
          <strong>${String(entry.pageNumber).padStart(2, "0")}</strong>
        </div>
      `;
    })
    .join("");
}

function buildInlineBadges(items) {
  const normalizedItems = safeArray(items).filter(Boolean);

  if (!normalizedItems.length) {
    return '<span class="report-empty-state report-empty-state--inline">Nenhum item selecionado.</span>';
  }

  return `
    <div class="report-inline-badges">
      ${normalizedItems
        .map((item) => `<span class="report-inline-badge">${escapeHtml(item)}</span>`)
        .join("")}
    </div>
  `;
}

function buildRiskPill(label, slug) {
  return `<span class="report-pill report-pill--${escapeHtml(slug || "neutral")}">${escapeHtml(label || "Sem dados")}</span>`;
}

function buildMethodologySteps(items) {
  const normalizedItems = safeArray(items);

  if (!normalizedItems.length) {
    return '<div class="report-empty-state">Metodologia indisponivel para o filtro atual.</div>';
  }

  return `
    <div class="report-method-list">
      ${normalizedItems
        .map(
          (item, index) => `
            <div class="report-method-list__row">
              <i>${index + 1}</i>
              <span>${escapeHtml(item)}</span>
            </div>
          `,
        )
        .join("")}
    </div>
  `;
}

function buildScaleLegend(scale) {
  const normalizedScale = safeArray(scale);

  if (!normalizedScale.length) {
    return '<div class="report-empty-state report-empty-state--inline">Escala indisponivel.</div>';
  }

  return `
    <div class="report-inline-badges">
      ${normalizedScale
        .map(
          (item) => `
            <span class="report-inline-badge">
              ${escapeHtml(`${item.value} = ${item.label}`)}
            </span>
          `,
        )
        .join("")}
    </div>
  `;
}

function buildAppliedQuestionsTable(items) {
  const normalizedItems = safeArray(items);

  if (!normalizedItems.length) {
    return '<div class="report-empty-state">Nenhuma pergunta aplicada foi encontrada para este formulario.</div>';
  }

  return `
    <div class="report-table-wrap">
      <table class="report-data-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Pergunta aplicada</th>
            <th>Fator</th>
            <th>Efeito</th>
            <th>Probabilidade</th>
            <th>Resultado</th>
            <th>Classificacao</th>
          </tr>
        </thead>
        <tbody>
          ${normalizedItems
            .map(
              (item) => `
                <tr>
                  <td>${formatInteger(item.position || 0)}</td>
                  <td>
                    <strong>${escapeHtml(item.text || "Pergunta")}</strong>
                    <div class="report-muted-row">${escapeHtml(item.scopeName || item.sectorName || "Empresa")}</div>
                  </td>
                  <td>${escapeHtml(item.factorName || "Fator")}</td>
                  <td>
                    <strong>${formatInteger(item.effect || 0)}</strong>
                    <div class="report-muted-row">${escapeHtml(item.effectDescription || "")}</div>
                  </td>
                  <td>${formatAverage(item.probability || item.average || 0)}</td>
                  <td>${formatAverage(item.riskScore || 0)}</td>
                  <td>${buildRiskPill(item.riskLabel, item.riskSlug)}</td>
                </tr>
              `,
            )
            .join("")}
        </tbody>
      </table>
    </div>
  `;
}

function buildFactorResultsTable(items) {
  const normalizedItems = safeArray(items);

  if (!normalizedItems.length) {
    return '<div class="report-empty-state">Nao ha fatores consolidados suficientes para exibir o calculo do risco.</div>';
  }

  return `
    <div class="report-table-wrap">
      <table class="report-data-table">
        <thead>
          <tr>
            <th>Fator</th>
            <th>Probabilidade media</th>
            <th>Efeito fixo</th>
            <th>Resultado do risco</th>
            <th>Classificacao</th>
            <th>PGR</th>
          </tr>
        </thead>
        <tbody>
          ${normalizedItems
            .map(
              (item) => `
                <tr>
                  <td>
                    <strong>${escapeHtml(item.factorName || "Fator")}</strong>
                    <div class="report-muted-row">${escapeHtml(item.scopeName || item.sectorName || "Empresa")}</div>
                  </td>
                  <td>${formatAverage(item.probability || 0)}</td>
                  <td>
                    <strong>${formatInteger(item.effect || 0)}</strong>
                    <div class="report-muted-row">${escapeHtml(item.effectDescription || "")}</div>
                  </td>
                  <td>${formatAverage(item.riskScore || 0)}</td>
                  <td>${buildRiskPill(item.riskLabel, item.riskSlug)}</td>
                  <td>${escapeHtml(item.pgrLabel || "Sem dados")}</td>
                </tr>
              `,
            )
            .join("")}
        </tbody>
      </table>
    </div>
  `;
}

function buildFinalConsiderations(data) {
  const summary = data.summary || {};
  const factors = safeArray(data.factorResults);
  const highestFactor = factors[0] || null;
  const selectedFormName =
    data.company?.selectedFormName || data.company?.activeFormName || "Formulario nao identificado";
  const selectedSectors = resolveSelectedSectorNames(data);
  const sectorsLabel = selectedSectors.length ? selectedSectors.join(", ") : "Todos os setores selecionados";

  if (!Number(summary.answersCount || 0)) {
    return `
      <div class="report-note-card">
        <strong>Consideracoes finais</strong>
        <p>O formulario ${escapeHtml(selectedFormName)} ainda nao possui respostas concluidas suficientes para gerar uma interpretacao tecnica consolidada.</p>
      </div>
    `;
  }

  return `
    <div class="report-note-card">
      <strong>Consideracoes finais</strong>
      <p>
        O relatorio foi consolidado com base nas respostas reais do formulario
        <strong>${escapeHtml(selectedFormName)}</strong>, considerando o recorte de
        <strong>${escapeHtml(sectorsLabel)}</strong>. O risco global atual foi classificado como
        <strong>${escapeHtml(summary.riskLabel || "Sem dados")}</strong>.
      </p>
      <p>
        ${highestFactor
          ? `O fator de maior impacto foi <strong>${escapeHtml(highestFactor.factorName || "Fator")}</strong>,
             com resultado ${escapeHtml(formatAverage(highestFactor.riskScore || 0))} e indicacao
             <strong>${escapeHtml(highestFactor.pgrLabel || "Sem dados")}</strong>.`
          : "Nenhum fator consolidado foi encontrado para os filtros atuais."}
      </p>
    </div>
  `;
}

function buildLineChart(series, options = {}) {
  const normalizedSeries = safeArray(series);
  const width = 320;
  const height = 150;
  const left = 28;
  const right = 18;
  const top = 18;
  const bottom = 34;
  const chartWidth = width - left - right;
  const chartHeight = height - top - bottom;
  const maxValue = Math.max(
    ...normalizedSeries.map((item) => Number(item?.value || 0)),
    Number(options.minimumMax || 1),
  );
  const divider = Math.max(normalizedSeries.length - 1, 1);

  if (!normalizedSeries.length) {
    return '<div class="report-empty-state">Não há dados suficientes para exibir o gráfico.</div>';
  }

  const points = normalizedSeries.map((item, index) => {
    const x = left + (chartWidth / divider) * index;
    const y = top + chartHeight - (chartHeight * Number(item?.value || 0)) / maxValue;

    return {
      x,
      y,
      label: String(item?.label || ""),
      value: Number(item?.value || 0),
    };
  });

  const linePath = points
    .map((point, index) => `${index === 0 ? "M" : "L"}${point.x.toFixed(2)} ${point.y.toFixed(2)}`)
    .join(" ");
  const areaPath = `${linePath} L${(left + chartWidth).toFixed(2)} ${(top + chartHeight).toFixed(2)} L${left.toFixed(2)} ${(top + chartHeight).toFixed(2)} Z`;

  const guideLevels = [0, 0.25, 0.5, 0.75, 1];

  return `
    <svg viewBox="0 0 ${width} ${height}" role="presentation" aria-hidden="true">
      ${guideLevels
        .map((level) => {
          const y = top + chartHeight - chartHeight * level;
          const value = Math.round(maxValue * level);

          return `
            <line x1="${left}" y1="${y}" x2="${left + chartWidth}" y2="${y}" class="chart-grid"></line>
            <text x="${left - 8}" y="${y + 4}" text-anchor="end" class="chart-scale">${value}</text>
          `;
        })
        .join("")}
      <line x1="${left}" y1="${top}" x2="${left}" y2="${top + chartHeight}" class="chart-axis"></line>
      <line x1="${left}" y1="${top + chartHeight}" x2="${left + chartWidth}" y2="${top + chartHeight}" class="chart-axis"></line>
      <path d="${areaPath}" class="chart-area"></path>
      <path d="${linePath}" class="chart-line"></path>
      ${points
        .map(
          (point) => `
            <circle cx="${point.x}" cy="${point.y}" r="3.5" class="chart-point"></circle>
            <text x="${point.x}" y="${height - 12}" text-anchor="middle" class="chart-label">${escapeHtml(point.label)}</text>
          `,
        )
        .join("")}
    </svg>
  `;
}

function buildDonutChart(items) {
  const normalizedItems = safeArray(items);
  const total = normalizedItems.reduce((sum, item) => sum + Number(item?.value || 0), 0);
  let cursor = 0;

  const segments = normalizedItems
    .map((item) => {
      const value = Number(item?.value || 0);

      if (!total || value <= 0) {
        return null;
      }

      const start = cursor;
      const end = cursor + (value / total) * 360;
      cursor = end;

      return `${item.color} ${start}deg ${end}deg`;
    })
    .filter(Boolean);

  const background = segments.length ? `conic-gradient(${segments.join(", ")})` : "#e8eef6";

  return `
    <div class="report-donut-card">
      <div class="report-donut" style="background:${background};">
        <div class="report-donut__hole">
          <strong>${formatInteger(total)}</strong>
          <span>coletas</span>
        </div>
      </div>

      <div class="report-donut-legend">
        ${normalizedItems
          .map(
            (item) => `
              <div class="report-donut-legend__item">
                <i class="report-donut-legend__swatch" style="background:${escapeHtml(item.color || "#d6dde8")};"></i>
                <span>${escapeHtml(item.label || "Sem dados")}</span>
                <strong>${formatInteger(item.value || 0)}</strong>
              </div>
            `,
          )
          .join("")}
      </div>
    </div>
  `;
}

function buildTopQuestionList(items, emptyMessage = "Nenhuma pergunta consolidada ate o momento.") {
  const normalizedItems = safeArray(items);

  if (!normalizedItems.length) {
    return `<div class="report-empty-state">${escapeHtml(emptyMessage)}</div>`;
  }

  return `
    <div class="report-list-card">
      ${normalizedItems
        .map(
          (item, index) => `
            <div class="report-list-row">
              <div class="report-list-row__rank">${index + 1}</div>
              <div class="report-list-row__copy">
                <strong>${escapeHtml(item.text || item.factor || "Pergunta")}</strong>
                <span>${escapeHtml(item.sectorName || "Empresa")}</span>
              </div>
              <div class="report-list-row__meta">
                <strong>${formatAverage(item.average || 0)}</strong>
                ${buildRiskPill(item.riskLabel, item.riskSlug)}
              </div>
            </div>
          `,
        )
        .join("")}
    </div>
  `;
}

function buildCoverPage(data, pageNumber) {
  const selectedFormName = data.company?.selectedFormName || data.company?.activeFormName || "Não vinculado";

  return `
    <article class="report-page report-page--cover">
      <div class="report-page__topmeta">USO INTERNO / CONFIDENCIAL</div>

      <div class="report-cover-brand">
        <img class="report-cover-brand__logo" src="img/logo.png" alt="AvalieSST" />
      </div>

      <span class="report-cover-kicker">RELATÓRIO DE DIAGNÓSTICOS</span>
      <h2 class="report-cover-title">Avaliação de Riscos Psicossociais no Trabalho</h2>
      <span class="report-cover-line" aria-hidden="true"></span>

      <div class="report-cover-meta">
        <div>
          <span>ORGANIZAÇÃO AVALIADA</span>
          <strong>${escapeHtml(data.company?.name || "Empresa")}</strong>
        </div>
        <div>
          <span>PERÍODO DE REFERÊNCIA</span>
          <strong>${escapeHtml(data.summary?.periodLabel || "Todo o histórico")}</strong>
        </div>
        <div>
          <span>FORMULÁRIO ATIVO</span>
          <strong>${escapeHtml(selectedFormName)}</strong>
        </div>
        <div>
          <span>DATA DE EMISSÃO</span>
          <strong>${escapeHtml(data.summary?.emittedAt || "")}</strong>
        </div>
      </div>

      <div class="report-cover-footer">
        <div>
          <strong>Sistema AvalieSST</strong>
          <span>Relatório consolidado com base nas respostas salvas no banco de dados.</span>
        </div>
        <span>Página ${pageNumber}</span>
      </div>
    </article>
  `;
}

function buildIndexPage(data, pageSequence, pageNumber) {
  const sectorNames = resolveSelectedSectorNames(data);
  const selectedFormName = data.company?.selectedFormName || data.company?.activeFormName || "Consolidado";

  return `
    <article class="report-page">
      <section class="report-section">
        <h3 class="report-section-title">Índice</h3>
        <div class="report-index">${buildIndexRows(pageSequence)}</div>

        <div class="report-note-card">
          <strong>Escopo do relatório</strong>
          <p>
            Empresa: <strong>${escapeHtml(data.company?.name || "Empresa")}</strong> |
            Período: <strong>${escapeHtml(data.summary?.periodLabel || "Todo o histórico")}</strong> |
            Formulário analisado: <strong>${escapeHtml(selectedFormName)}</strong>
          </p>
          ${buildInlineBadges(sectorNames)}
        </div>
      </section>
      <span class="report-page__number">Página ${pageNumber}</span>
    </article>
  `;
}

function buildSummaryPage(data, pageNumber) {
  const summary = data.summary || {};
  const completionSeries = buildLineChart(data.completionSeries, { minimumMax: 5 });
  const riskSeries = buildLineChart(data.riskSeries, { minimumMax: 20 });
  const methodology = data.methodology || {};
  const appliedQuestions = safeArray(data.appliedQuestions);
  const selectedFormName = data.company?.selectedFormName || data.company?.activeFormName || "Formulario nao vinculado";

  return `
    <article class="report-page">
      <section class="report-section report-section--spaced">
        <h3 class="report-section-title">Resumo Executivo</h3>
        <p class="report-paragraph">
          Esta visão considera as respostas reais registradas para a empresa e mostra a
          situação geral da coleta, o nível médio de risco e os principais pontos que
          precisam de acompanhamento.
        </p>

        <div class="report-metrics">
          <article class="report-mini-card">
            <span>ÍNDICE GLOBAL DE RISCO</span>
            <strong>${formatInteger(summary.riskIndex || 0)} <small>/100</small></strong>
            <em>${escapeHtml(summary.riskLabel || "Sem dados")}</em>
          </article>
          <article class="report-mini-card">
            <span>PARTICIPAÇÃO</span>
            <strong>${formatPercent(summary.participationRate || 0)}</strong>
            <em class="is-green">${formatInteger(summary.completedSessions || 0)} sessões concluídas</em>
          </article>
          <article class="report-mini-card">
            <span>CONFORMIDADE</span>
            <strong>${formatPercent(summary.complianceRate || 0)}</strong>
            <em class="is-green">${formatInteger(summary.totalSessions || 0)} sessões no período</em>
          </article>
          <article class="report-mini-card">
            <span>FATORES NO PGR</span>
            <strong class="is-red">${formatInteger(summary.pgrFactorsCount || 0)}</strong>
            <em class="is-red">${escapeHtml(summary.pgrLabel || "Sem dados")}</em>
          </article>
        </div>

        <div class="report-chart-grid">
          <article class="report-chart-card">
            <span class="report-chart-card__title">Metodologia aplicada</span>
            ${buildMethodologySteps(methodology.formula)}
          </article>

          <article class="report-chart-card">
            <span class="report-chart-card__title">Escala de respostas e criterio do PGR</span>
            ${buildScaleLegend(methodology.scale)}
            <div class="report-note-card report-note-card--compact">
              <strong>Formulario considerado</strong>
              <p>${escapeHtml(selectedFormName)}</p>
              <p>${escapeHtml(methodology.pgrCriterion || "Itens moderados ou altos devem ser avaliados para o PGR.")}</p>
            </div>
          </article>
        </div>

        <div class="report-chart-grid">
          <article class="report-chart-card">
            <span class="report-chart-card__title">Evolução das coletas concluídas</span>
            ${completionSeries}
          </article>

          <article class="report-chart-card">
            <span class="report-chart-card__title">Evolução do índice de risco</span>
            ${riskSeries}
          </article>
        </div>

        <div class="report-chart-grid">
          <article class="report-chart-card">
            <span class="report-chart-card__title">Status das coletas</span>
            ${buildDonutChart(data.statusBreakdown)}
          </article>

          <article class="report-chart-card">
            <span class="report-chart-card__title">Perguntas com maior media de risco</span>
            ${buildTopQuestionList(
              safeArray(data.questionRankings).slice(0, 5),
              "Ainda não existem perguntas suficientes respondidas para montar o ranking.",
            )}
          </article>
        </div>

        <div class="report-note-card">
          <strong>Perguntas aplicadas e calculo da probabilidade</strong>
          <p>
            Cada pergunta abaixo mostra o fator psicossocial associado, o efeito fixo utilizado
            na metodologia e o resultado calculado com base na media real das respostas salvas.
          </p>
        </div>
        ${buildAppliedQuestionsTable(appliedQuestions)}
      </section>
      <span class="report-page__number">Página ${pageNumber}</span>
    </article>
  `;
}

function buildSectorAnalysisPage(data, pageNumber) {
  const sectors = safeArray(data.sectorBreakdown);
  const selectedSectorNames = resolveSelectedSectorNames(data);

  return `
    <article class="report-page">
      <section class="report-section">
        <h3 class="report-section-title">Análise por Setor</h3>
        <p class="report-paragraph">
          Os setores abaixo foram calculados a partir das respostas vinculadas à empresa
          selecionada. A média representa a intensidade percebida de risco nas respostas.
        </p>

        <div class="report-note-card">
          <strong>Setores considerados</strong>
          ${buildInlineBadges(selectedSectorNames)}
        </div>

        ${
          sectors.length
            ? `
              <div class="report-table-wrap">
                <table class="report-data-table">
                  <thead>
                    <tr>
                      <th>Setor</th>
                      <th>Colaboradores</th>
                      <th>Média</th>
                      <th>Respostas</th>
                      <th>Funcoes</th>
                      <th>Risco</th>
                    </tr>
                  </thead>
                  <tbody>
                    ${sectors
                      .map(
                        (sector) => `
                          <tr>
                            <td>
                              <strong>${escapeHtml(sector.name)}</strong>
                            </td>
                            <td>${formatInteger(sector.employees || 0)}</td>
                            <td>${formatAverage(sector.average || 0)}</td>
                            <td>${formatInteger(sector.answerCount || 0)}</td>
                            <td>${formatInteger(safeArray(sector.functions).length)}</td>
                            <td>${buildRiskPill(sector.riskLabel, sector.riskSlug)}</td>
                          </tr>
                        `,
                      )
                      .join("")}
                  </tbody>
                </table>
              </div>
            `
            : '<div class="report-empty-state">Nenhum setor possui respostas consolidadas no período escolhido.</div>'
        }

        <div class="report-chart-grid">
          <article class="report-chart-card">
            <span class="report-chart-card__title">Distribuicao geral de risco</span>
            ${buildDonutChart([
              {
                label: "Risco Baixo",
                value: Number(data.distribution?.low || 0),
                color: "#1eb980",
              },
              {
                label: "Risco Moderado",
                value: Number(data.distribution?.medium || 0),
                color: "#f4a31d",
              },
              {
                label: "Risco Alto",
                value: Number(data.distribution?.high || 0),
                color: "#ef5656",
              },
            ])}
          </article>

          <article class="report-chart-card">
            <span class="report-chart-card__title">Perguntas críticas por consolidação</span>
            ${buildTopQuestionList(
              safeArray(data.questionRankings).slice(0, 5),
              "Não há perguntas suficientes para gerar a lista crítica.",
            )}
          </article>
        </div>
      </section>
      <span class="report-page__number">Página ${pageNumber}</span>
    </article>
  `;
}

function buildHeatmapMarkup(items) {
  const normalizedItems = safeArray(items);
  const markerMarkup = normalizedItems
    .map((item) => {
      const probability = Number(item.probability || 1);
      const impact = Number(item.impact || 1);
      const top = (5 - impact) * 56 + 10;
      const left = (probability - 1) * 56 + 10;

      return `<span class="heatmap-marker" style="top:${top}px; left:${left}px;">${item.rank}</span>`;
    })
    .join("");

  return `
    <div class="heatmap-wrap">
      <span class="heatmap-axis heatmap-axis--left">IMPACTO</span>
      <div class="heatmap-grid">
        <span class="heatmap-cell heatmap-cell--y1">5</span>
        <span class="heatmap-cell heatmap-cell--y2">10</span>
        <span class="heatmap-cell heatmap-cell--o1">15</span>
        <span class="heatmap-cell heatmap-cell--r1">20</span>
        <span class="heatmap-cell heatmap-cell--r2">25</span>

        <span class="heatmap-cell heatmap-cell--g1">4</span>
        <span class="heatmap-cell heatmap-cell--y1">8</span>
        <span class="heatmap-cell heatmap-cell--y2">12</span>
        <span class="heatmap-cell heatmap-cell--o1">16</span>
        <span class="heatmap-cell heatmap-cell--r1">20</span>

        <span class="heatmap-cell heatmap-cell--g2">3</span>
        <span class="heatmap-cell heatmap-cell--g1">6</span>
        <span class="heatmap-cell heatmap-cell--y1">9</span>
        <span class="heatmap-cell heatmap-cell--y2">12</span>
        <span class="heatmap-cell heatmap-cell--o1">15</span>

        <span class="heatmap-cell heatmap-cell--g3">2</span>
        <span class="heatmap-cell heatmap-cell--g2">4</span>
        <span class="heatmap-cell heatmap-cell--g1">6</span>
        <span class="heatmap-cell heatmap-cell--y1">8</span>
        <span class="heatmap-cell heatmap-cell--y2">10</span>

        <span class="heatmap-cell heatmap-cell--g4">1</span>
        <span class="heatmap-cell heatmap-cell--g3">2</span>
        <span class="heatmap-cell heatmap-cell--g2">3</span>
        <span class="heatmap-cell heatmap-cell--g1">4</span>
        <span class="heatmap-cell heatmap-cell--y1">5</span>
        ${markerMarkup}
      </div>
      <span class="heatmap-axis heatmap-axis--bottom">PROBABILIDADE</span>
    </div>
  `;
}

function buildHeatmapPage(data, pageNumber) {
  const heatmapItems = safeArray(data.heatmapItems);
  const methodology = data.methodology || {};
  const factorResults = safeArray(data.factorResults);

  return `
    <article class="report-page">
      <section class="report-section">
        <h3 class="report-section-title">Matriz de Risco (Heatmap)</h3>
        <p class="report-paragraph">
          A matriz cruza a probabilidade media das respostas (1 a 5) com o efeito fixo
          do fator psicossocial. O resultado do risco e calculado por
          <strong>probabilidade x efeito</strong>, sendo enquadrado na classificacao final
          da matriz.
        </p>

        <div class="report-chart-grid">
          <article class="report-chart-card">
            <span class="report-chart-card__title">Definicao do efeito por fator</span>
            ${buildFactorResultsTable(
              safeArray(methodology.factorCatalog).map((factor) => ({
                factorName: factor.factorName,
                scopeName: "Referencia metodologica",
                probability: 0,
                effect: factor.effect,
                effectDescription: factor.effectDescription,
                riskScore: 0,
                riskLabel: "-",
                riskSlug: "neutral",
                pgrLabel: "-",
              })),
            )}
          </article>

          <article class="report-chart-card">
            <span class="report-chart-card__title">Classificacao da matriz e criterio do PGR</span>
            <div class="report-method-list">
              ${safeArray(methodology.matrix)
                .map(
                  (item, index) => `
                    <div class="report-method-list__row">
                      <i>${index + 1}</i>
                      <span>
                        <strong>${escapeHtml(item.range || "")}</strong> -
                        ${escapeHtml(item.label || "Sem dados")} |
                        ${escapeHtml(item.pgrLabel || "")}
                      </span>
                    </div>
                  `,
                )
                .join("")}
            </div>
          </article>
        </div>

        ${
          heatmapItems.length
            ? buildHeatmapMarkup(heatmapItems)
            : '<div class="report-empty-state">Não há respostas suficientes para montar a matriz de risco.</div>'
        }

        <div class="mapped-factors-card">
          <strong>Fatores mapeados (Top 5)</strong>
          ${
            heatmapItems.length
              ? heatmapItems
                  .map(
                    (item) => `
                      <div class="mapped-factor-row"><i>${item.rank}</i><span>${escapeHtml(item.text)}</span></div>
                      <small>
                        Setor: ${escapeHtml(item.sectorName || "Empresa")} |
                        Probabilidade: ${item.probability} |
                        Impacto: ${item.impact} |
                        Score: ${item.score} |
                        ${escapeHtml(item.riskLabel)} |
                        ${escapeHtml(item.pgrLabel || "Sem dados")}
                      </small>
                    `,
                  )
                  .join("")
              : '<small>Nenhum fator mapeado com base nas respostas atuais.</small>'
          }
        </div>

        <div class="report-note-card">
          <strong>Resultado consolidado do risco</strong>
          <p>
            A tabela abaixo resume a probabilidade media por fator, o efeito fixo aplicado,
            o resultado do risco, a classificacao final e a indicacao de inclusao no PGR.
          </p>
        </div>
        ${buildFactorResultsTable(factorResults)}
      </section>
      <span class="report-page__number">Página ${pageNumber}</span>
    </article>
  `;
}

function buildActionPlanPage(data, pageNumber) {
  const actionPlan = safeArray(data.actionPlan);
  const planSource = data.actionPlanMeta?.source === "manual" ? "manual" : "automatico";

  return `
    <article class="report-page">
      <section class="report-section">
        <h3 class="report-section-title">Plano de Ação Recomendado</h3>
        <p class="report-paragraph">
          Esta seção utiliza ${
            planSource === "manual" ? "o plano salvo manualmente" : "um plano sugerido automaticamente"
          } a partir das respostas consolidadas. O objetivo é priorizar os fatores de maior risco para acompanhamento.
        </p>

        ${
          actionPlan.length
            ? `
              <div class="report-action-table">
                <div class="report-action-table__head">
                  <span>FATOR DE RISCO</span>
                  <span>AÇÃO RECOMENDADA</span>
                  <span>PRAZO</span>
                  <span>STATUS</span>
                </div>

                ${actionPlan
                  .map(
                    (item) => `
                      <div class="report-action-table__row">
                        <div>
                          <strong>${escapeHtml(item.factor)}</strong>
                          <small>${escapeHtml(item.sectorName)}</small>
                        </div>
                        <div>${escapeHtml(item.recommendedAction)}</div>
                        <div>${escapeHtml(item.deadline)}</div>
                        <div>
                          <span class="plan-status plan-status--${escapeHtml(item.prioritySlug || "monitor")}">
                            ${escapeHtml(item.priorityLabel)}
                          </span>
                        </div>
                      </div>
                    `,
                  )
                  .join("")}
              </div>
            `
            : '<div class="report-empty-state">Nenhuma ação foi gerada para os filtros selecionados.</div>'
        }

        ${buildFinalConsiderations(data)}

        <div class="report-signature-card">
          <div class="report-signature-card__head">
            <span class="report-signature-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" role="presentation">
                <path d="M7 15.5c2.5-2.8 5-4.8 7.4-5.9M9.5 18.2c3.7-1.5 6.6-4.4 8.2-8.2" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.8" />
                <circle cx="8" cy="8" r="2.2" fill="none" stroke="currentColor" stroke-width="1.8" />
              </svg>
            </span>
            <div>
              <strong>Assinaturas e Validação</strong>
              <span>Documento gerado a partir dos dados consolidados do sistema AvalieSST.</span>
            </div>
          </div>

          <div class="report-signature-lines">
            <div>
              <span></span>
              <strong>Responsável SST</strong>
              <small>${escapeHtml(data.company?.name || "Empresa")}</small>
            </div>
            <div>
              <span></span>
              <strong>Diretoria</strong>
              <small>${escapeHtml(data.company?.name || "Empresa")}</small>
            </div>
          </div>
        </div>
      </section>
      <span class="report-page__number">Página ${pageNumber}</span>
    </article>
  `;
}

function renderReportPreview(data) {
  if (!reportPreviewArea) {
    return;
  }

  const pageSequence = getReportPageSequence();

  if (!pageSequence.length) {
    reportPreviewArea.innerHTML = '<div class="org-empty-state">Selecione ao menos uma secao para montar o preview.</div>';
    return;
  }

  const markup = pageSequence
    .map((entry) => {
      switch (entry.key) {
        case "cover":
          return buildCoverPage(data, entry.pageNumber);
        case "index":
          return buildIndexPage(data, pageSequence, entry.pageNumber);
        case "summary":
          return buildSummaryPage(data, entry.pageNumber);
        case "sectorAnalysis":
          return buildSectorAnalysisPage(data, entry.pageNumber);
        case "heatmap":
          return buildHeatmapPage(data, entry.pageNumber);
        case "actionPlan":
          return buildActionPlanPage(data, entry.pageNumber);
        default:
          return "";
      }
    })
    .filter(Boolean)
    .join("");

    reportPreviewArea.innerHTML = markup || '<div class="org-empty-state">Não foi possível montar o preview.</div>';
}

async function loadReportData() {
  if (!reportPreviewArea) {
    return;
  }

  try {
    if (reportFeedback) {
      reportFeedback.textContent = "Atualizando preview...";
      reportFeedback.classList.remove("is-success");
    }

    const response = await window.apiClient.get(`api/report-data.php?${buildReportQuery()}`);
    const data = response.data || null;

    reportState.data = data;
    renderReportCompanyOptions(data?.options?.companies || [], data?.filters?.companyId || 0);
    renderReportFormOptions(data?.options?.forms || [], data?.filters?.formId || data?.company?.selectedFormId || 0);
    renderReportSectorList(data?.options?.sectors || [], data?.filters?.sectorIds || []);
    renderReportPreview(data);

    if (reportFeedback) {
      reportFeedback.textContent = "Preview atualizado com dados reais do sistema.";
      reportFeedback.classList.add("is-success");
    }
  } catch (error) {
    if (reportPreviewArea) {
      reportPreviewArea.innerHTML = `<div class="org-empty-state">${escapeHtml(error.message || "Não foi possível carregar o relatório.")}</div>`;
    }

    if (reportFeedback) {
      reportFeedback.textContent = error.message || "Não foi possível carregar o relatório.";
      reportFeedback.classList.remove("is-success");
    }
  }
}

function exportReport(format) {
  const query = buildReportQuery();
  const url = `api/report-export.php?format=${format}&${query}`;

  if (format === "pdf") {
    // A versao PDF abre em uma pagina dedicada, pronta para impressao.
    // Isso da mais controle ao usuario para salvar em PDF sem depender do
    // disparo automatico da janela de impressao do navegador.
    window.open(url, "_blank");
    return;
  }

  window.location.href = url;
}

if (reportPreviewArea && reportCompanySelect) {
  reportRefreshButton?.addEventListener("click", loadReportData);
  reportCompanySelect.addEventListener("change", () => {
    if (reportFormSelect) {
      reportFormSelect.innerHTML = "";
    }

    if (reportSectorList) {
      reportSectorList.innerHTML = "";
    }

    loadReportData();
  });
  reportFormSelect?.addEventListener("change", loadReportData);
  reportPeriodSelect?.addEventListener("change", loadReportData);
  reportSectorList?.addEventListener("change", loadReportData);
  reportSectionInputs.forEach((input) => {
    input.addEventListener("change", () => {
      if (reportState.data) {
        renderReportPreview(reportState.data);
      }
    });
  });
  reportExcelButton?.addEventListener("click", () => exportReport("excel"));
  reportPdfButton?.addEventListener("click", () => exportReport("pdf"));

  loadReportData();
}
