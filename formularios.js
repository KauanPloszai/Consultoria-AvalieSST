const formTableBody = document.querySelector("[data-forms-table-body]");
const formsResultsLabel = document.querySelector("[data-forms-results]");
const openFormModalButton = document.querySelector("[data-open-form-modal]");
const formModal = document.querySelector("[data-form-modal]");
const formModalTitle = document.querySelector("[data-form-modal-title]");
const closeFormModalButtons = document.querySelectorAll("[data-close-form-modal]");
const formBuilder = document.querySelector("[data-form-builder]");
const formNameInput = document.querySelector("[data-form-name-input]");
const questionList = document.querySelector("[data-question-list]");
const addQuestionButton = document.querySelector("[data-add-question]");
const formFeedback = document.querySelector("[data-form-feedback]");

const fixedScaleOptions = [
  "1 = Nunca",
  "2 = Raramente",
  "3 = As vezes",
  "4 = Frequentemente",
  "5 = Sempre",
];

let formsDb = [];
let editingFormId = null;

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
      <textarea rows="3" placeholder="Digite a pergunta que sera respondida no questionario.">${escapeHtml(questionText)}</textarea>
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
  questionList.innerHTML = "";

  questions.forEach((question) => {
    questionList.appendChild(createQuestionItem(question));
  });

  updateQuestionTitles();
}

function getFormById(formId) {
  return formsDb.find((form) => form.id === formId) || null;
}

function renderFormsTable() {
  if (!formTableBody) {
    return;
  }

  const markup = formsDb
    .map((form) => {
      const statusClass =
        form.status === "inactive" ? "status-pill status-pill--inactive" : "status-pill status-pill--active";
      const statusLabel = form.status === "inactive" ? "Inativo" : "Ativo";

      return `
        <article class="forms-table__row">
          <div class="forms-table__name">
            <strong>${escapeHtml(form.name)}</strong>
            <span>ID: ${escapeHtml(form.id)}</span>
          </div>
          <span class="forms-table__value">${form.questions.length}</span>
          <span class="${statusClass}">${statusLabel}</span>
          <span class="forms-table__date">${formatDate(form.createdAt)}</span>
          <button class="table-action" type="button" aria-label="Editar formulario" data-edit-form="${escapeHtml(form.id)}">
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
    const total = formsDb.length;
    formsResultsLabel.innerHTML = `Mostrando <strong>${total ? 1 : 0}</strong> a <strong>${total}</strong> de <strong>${total}</strong> resultados`;
  }
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
      formsResultsLabel.innerHTML = `<strong>${escapeHtml(error.message || "Nao foi possivel carregar os formularios.")}</strong>`;
    }
  }
}

function openFormModal(mode = "create", form = null) {
  if (!formModal || !formNameInput) {
    return;
  }

  editingFormId = form?.id || null;
  formNameInput.value = form?.name || "";
  renderQuestionList(form?.questions?.length ? form.questions : [""]);
  formFeedback.textContent = "";
  formFeedback.classList.remove("is-success");

  if (formModalTitle) {
    formModalTitle.textContent = mode === "edit" ? "Editar Formulario" : "Novo Formulario";
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

async function handleFormSubmit(event) {
  event.preventDefault();

  const formName = formNameInput.value.trim();
  const questions = Array.from(questionList.querySelectorAll("textarea"))
    .map((field) => field.value.trim())
    .filter(Boolean);

  if (!formName) {
    formFeedback.textContent = "Informe o nome do formulario.";
    formFeedback.classList.remove("is-success");
    formNameInput.focus();
    return;
  }

  if (!questions.length) {
    formFeedback.textContent = "Adicione pelo menos uma pergunta.";
    formFeedback.classList.remove("is-success");
    const firstTextarea = questionList.querySelector("textarea");

    if (firstTextarea) {
      firstTextarea.focus();
    }

    return;
  }

  try {
    formFeedback.textContent = editingFormId ? "Atualizando formulario..." : "Salvando formulario...";
    formFeedback.classList.remove("is-success");

    const payload = {
      id: editingFormId,
      name: formName,
      questions,
      status: "active",
    };

    if (editingFormId) {
      await window.apiClient.put("api/forms.php", payload);
    } else {
      await window.apiClient.post("api/forms.php", payload);
    }

    await loadForms();
    closeFormModal();
  } catch (error) {
    formFeedback.textContent = error.message || "Nao foi possivel salvar o formulario.";
    formFeedback.classList.remove("is-success");
  }
}

if (formTableBody && formBuilder && formModal) {
  loadForms();

  if (openFormModalButton) {
    openFormModalButton.addEventListener("click", () => {
      openFormModal("create");
    });
  }

  closeFormModalButtons.forEach((button) => {
    button.addEventListener("click", () => {
      closeFormModal();
    });
  });

  if (addQuestionButton) {
    addQuestionButton.addEventListener("click", () => {
      questionList.appendChild(createQuestionItem(""));
      updateQuestionTitles();
      const lastTextarea = questionList.querySelector(".question-item:last-child textarea");

      if (lastTextarea) {
        lastTextarea.focus();
      }
    });
  }

  formBuilder.addEventListener("submit", handleFormSubmit);
  questionList.addEventListener("click", handleQuestionListClick);
  formTableBody.addEventListener("click", handleFormsTableClick);

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !formModal.hidden) {
      closeFormModal();
    }
  });
}
