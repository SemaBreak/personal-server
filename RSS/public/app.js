import { addFeed, listFeeds, listItems, refreshFeeds, removeFeed } from "./api.js";

const entries = document.querySelector("#entries");
const emptyState = document.querySelector("#empty-state");
const moreButton = document.querySelector("#more");
const refreshButton = document.querySelector("#refresh");
const updated = document.querySelector("#updated");
const message = document.querySelector("#message");
const feedForm = document.querySelector("#feed-form");
const feedUrl = document.querySelector("#feed-url");
const feedList = document.querySelector("#feed-list");

let nextCursor = null;

const showMessage = (text = "") => { message.textContent = text; };
const dateFormat = new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" });
const formatDate = (iso) => dateFormat.format(new Date(iso));

// Runs an action while a button is disabled, reporting failures in the message area.
async function withButton(button, action) {
  button.disabled = true;
  button.classList.add("busy");
  showMessage();
  try {
    await action();
  } catch (error) {
    showMessage(error.message);
  } finally {
    button.disabled = false;
    button.classList.remove("busy");
  }
}

function renderItem(item) {
  const li = document.createElement("li");
  li.className = "entry-item";

  const link = document.createElement("a");
  link.href = item.link;
  link.textContent = item.title;
  link.target = "_blank";
  link.rel = "noopener noreferrer";

  const meta = document.createElement("div");
  meta.className = "entry-meta";
  meta.textContent = `${item.feed_title} · ${formatDate(item.published_at)}`;

  li.append(link, meta);
  return li;
}

async function loadItems(append) {
  const page = await listItems(append ? nextCursor : null);
  if (!append) entries.replaceChildren();
  entries.append(...page.items.map(renderItem));
  nextCursor = page.next;
  moreButton.hidden = nextCursor === null;
  emptyState.hidden = entries.children.length > 0;
}

function renderFeed(feed) {
  const li = document.createElement("li");

  const name = document.createElement("div");
  name.className = "feed-name";
  name.textContent = feed.title || feed.url;
  if (feed.last_error) {
    const error = document.createElement("div");
    error.className = "feed-error";
    error.textContent = feed.last_error;
    name.append(error);
  }

  const remove = document.createElement("button");
  remove.type = "button";
  remove.textContent = "Remove";
  remove.addEventListener("click", () => withButton(remove, async () => {
    await removeFeed(feed.id);
    await Promise.all([loadFeeds(), loadItems(false)]);
  }));

  li.append(name, remove);
  return li;
}

async function loadFeeds() {
  const feeds = await listFeeds();
  feedList.replaceChildren(...feeds.map(renderFeed));

  const fetched = feeds.map((feed) => feed.last_fetched).filter(Boolean).sort();
  updated.textContent = fetched.length ? `Last refreshed ${formatDate(fetched[fetched.length - 1])}` : "Never refreshed";
}

refreshButton.addEventListener("click", () => withButton(refreshButton, async () => {
  const result = await refreshFeeds();
  await Promise.all([loadFeeds(), loadItems(false)]);
  if (result.errors > 0) showMessage(`${result.errors} of ${result.feeds} feeds failed. See the list below.`);
}));

moreButton.addEventListener("click", () => withButton(moreButton, () => loadItems(true)));

feedForm.addEventListener("submit", (event) => {
  event.preventDefault();
  withButton(feedForm.querySelector("button"), async () => {
    await addFeed(feedUrl.value.trim());
    feedForm.reset();
    await Promise.all([loadFeeds(), loadItems(false)]);
  });
});

Promise.all([loadFeeds(), loadItems(false)]).catch((error) => showMessage(error.message));
