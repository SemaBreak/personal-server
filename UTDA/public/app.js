import { clearCompleted, createTask, deleteTask, listTasks, setCompleted } from "./api.js";

const taskForm = document.querySelector("#task-form");
const taskInput = document.querySelector("#task-input");
const taskList = document.querySelector("#task-list");
const emptyState = document.querySelector("#empty-state");
const emptyTitle = document.querySelector("#empty-title");
const emptyCopy = document.querySelector("#empty-copy");
const taskCount = document.querySelector("#task-count");
const clearCompletedButton = document.querySelector("#clear-completed");
const storageMessage = document.querySelector("#storage-message");
const filterButtons = document.querySelectorAll(".filter");

let filter = "all";
let tasks = [];
let loadError = false;

const checkIcon = '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 8 3.2 3.2L13 4.5"/></svg>';
const deleteIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2m3 0-1 14H6L5 6m4 4v6m6-6v6"/></svg>';

document.querySelector("#today-label").textContent = new Intl.DateTimeFormat(undefined, {
  weekday: "long",
  month: "long",
  day: "numeric"
}).format(new Date());

function showError(error) {
  storageMessage.textContent = error.message;
}

function makeTask(task) {
  const item = document.createElement("li");
  item.className = `task${task.completed ? " is-complete" : ""}`;
  item.dataset.id = task.id;

  const check = document.createElement("button");
  check.className = "check";
  check.type = "button";
  check.setAttribute("aria-label", task.completed ? `Mark ${task.title} as to do` : `Mark ${task.title} as done`);
  check.setAttribute("aria-pressed", String(task.completed));
  if (task.completed) check.innerHTML = checkIcon;

  const title = document.createElement("span");
  title.className = "task-title";
  title.textContent = task.title;

  const remove = document.createElement("button");
  remove.className = "icon-button";
  remove.type = "button";
  remove.setAttribute("aria-label", `Delete ${task.title}`);
  remove.innerHTML = deleteIcon;

  item.append(check, title, remove);
  return item;
}

function describeEmptyState(visibleCount) {
  if (loadError) return ["Can't reach your list", "Check the server connection and try again."];
  if (tasks.length === 0) return ["A fresh start", "Add a task above and make it yours."];
  if (filter === "active" && visibleCount === 0) return ["All caught up", "Everything on your list is done."];
  if (filter === "completed" && visibleCount === 0) return ["Nothing checked off yet", "Finished tasks will find a home here."];
  return ["Nothing here", "Try another filter to find your tasks."];
}

function render() {
  const visibleTasks = tasks.filter((task) => {
    if (filter === "active") return !task.completed;
    if (filter === "completed") return task.completed;
    return true;
  });
  const remaining = tasks.filter((task) => !task.completed).length;
  taskList.replaceChildren(...visibleTasks.map(makeTask));
  emptyState.hidden = visibleTasks.length > 0;
  taskList.hidden = visibleTasks.length === 0;
  [emptyTitle.textContent, emptyCopy.textContent] = describeEmptyState(visibleTasks.length);

  taskCount.textContent = loadError
    ? "Tasks unavailable"
    : `${remaining} ${remaining === 1 ? "task" : "tasks"} left`;
  clearCompletedButton.disabled = !tasks.some((task) => task.completed);
  filterButtons.forEach((button) => {
    button.setAttribute("aria-pressed", String(button.dataset.filter === filter));
  });
}

taskForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  const title = taskInput.value.trim();
  if (!title) {
    taskInput.focus();
    return;
  }

  const submitButton = taskForm.querySelector('button[type="submit"]');
  submitButton.disabled = true;
  try {
    tasks.unshift(await createTask(title));
    storageMessage.textContent = "";
    taskInput.value = "";
    filter = "all";
    render();
    taskInput.focus();
  } catch (error) {
    showError(error);
  } finally {
    submitButton.disabled = false;
  }
});

taskList.addEventListener("click", async (event) => {
  const button = event.target.closest("button");
  const item = event.target.closest(".task");
  if (!button || !item) return;
  const task = tasks.find((entry) => String(entry.id) === item.dataset.id);
  if (!task) return;

  button.disabled = true;
  try {
    if (button.classList.contains("check")) {
      const updated = await setCompleted(task.id, !task.completed);
      tasks = tasks.map((entry) => (entry.id === updated.id ? updated : entry));
    } else if (button.classList.contains("icon-button")) {
      await deleteTask(task.id);
      tasks = tasks.filter((entry) => entry.id !== task.id);
    } else {
      return;
    }
    storageMessage.textContent = "";
    render();
  } catch (error) {
    showError(error);
  } finally {
    button.disabled = false;
  }
});

filterButtons.forEach((button) => {
  button.addEventListener("click", () => {
    filter = button.dataset.filter;
    render();
  });
});

clearCompletedButton.addEventListener("click", async () => {
  clearCompletedButton.disabled = true;
  try {
    await clearCompleted();
    tasks = tasks.filter((task) => !task.completed);
    storageMessage.textContent = "";
    render();
  } catch (error) {
    showError(error);
    clearCompletedButton.disabled = !tasks.some((task) => task.completed);
  }
});

async function initialize() {
  try {
    tasks = await listTasks();
    loadError = false;
    storageMessage.textContent = "";
  } catch (error) {
    loadError = true;
    showError(error);
  }
  render();
}

initialize();
