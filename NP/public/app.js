import { addItem, deleteItem, listItems, saveOrder } from "./api.js";

const form = document.querySelector("#item-form");
const nameInput = document.querySelector("#item-name");
const priceInput = document.querySelector("#item-price");
const linkInput = document.querySelector("#item-link");
const list = document.querySelector("#item-list");
const emptyState = document.querySelector("#empty-state");
const total = document.querySelector("#total");
const message = document.querySelector("#message");

let items = [];

function showError(error) {
  message.textContent = error.message;
}

function isHttpUrl(value) {
  try {
    const url = new URL(value);
    return url.protocol === "http:" || url.protocol === "https:";
  } catch (error) {
    return false;
  }
}

function actionButton(label, text, className) {
  const button = document.createElement("button");
  button.type = "button";
  button.className = className;
  button.setAttribute("aria-label", label);
  button.textContent = text;
  return button;
}

function makeItem(item, index) {
  const row = document.createElement("li");
  row.className = "item";
  row.dataset.id = item.id;

  const name = document.createElement("span");
  name.className = "item-name";
  if (item.link && isHttpUrl(item.link)) {
    const anchor = document.createElement("a");
    anchor.href = item.link;
    anchor.target = "_blank";
    anchor.rel = "noopener noreferrer";
    anchor.textContent = item.name;
    name.append(anchor);
  } else {
    name.textContent = item.name;
  }

  const price = document.createElement("span");
  price.className = "item-price";
  price.textContent = item.price.toFixed(2);

  const actions = document.createElement("span");
  actions.className = "item-actions";
  const up = actionButton(`Move ${item.name} earlier`, "↑", "move-up");
  const down = actionButton(`Move ${item.name} later`, "↓", "move-down");
  const remove = actionButton(`Delete ${item.name}`, "✕", "remove");
  up.disabled = index === 0;
  down.disabled = index === items.length - 1;
  actions.append(up, down, remove);

  row.append(name, price, actions);
  return row;
}

function render() {
  list.replaceChildren(...items.map(makeItem));
  emptyState.hidden = items.length > 0;
  const sum = items.reduce((acc, item) => acc + Math.round(item.price * 100), 0) / 100;
  total.textContent = items.length ? `Total: ${sum.toFixed(2)}` : "";
}

form.addEventListener("submit", async (event) => {
  event.preventDefault();
  const submit = form.querySelector('button[type="submit"]');
  submit.disabled = true;
  try {
    const item = await addItem(nameInput.value.trim(), parseFloat(priceInput.value), linkInput.value.trim());
    items.push(item);
    message.textContent = "";
    form.reset();
    nameInput.focus();
    render();
  } catch (error) {
    showError(error);
  } finally {
    submit.disabled = false;
  }
});

list.addEventListener("click", async (event) => {
  const button = event.target.closest("button");
  const row = event.target.closest(".item");
  if (!button || !row) return;
  const id = Number(row.dataset.id);
  const index = items.findIndex((item) => item.id === id);
  if (index === -1) return;

  button.disabled = true;
  try {
    if (button.classList.contains("remove")) {
      await deleteItem(id);
      items.splice(index, 1);
    } else {
      const target = button.classList.contains("move-up") ? index - 1 : index + 1;
      if (target < 0 || target >= items.length) return;
      const reordered = items.slice();
      [reordered[index], reordered[target]] = [reordered[target], reordered[index]];
      items = await saveOrder(reordered.map((item) => item.id));
    }
    message.textContent = "";
  } catch (error) {
    showError(error);
  }
  render();
});

async function initialize() {
  try {
    items = await listItems();
  } catch (error) {
    showError(error);
  }
  render();
}

initialize();
