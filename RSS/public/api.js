const JSON_HEADERS = { "Content-Type": "application/json" };

// Sends a request to api.php and returns the parsed JSON body.
// A 401 means the session expired, so the browser is sent to the shared login page.
async function request(query = "", options = {}) {
  const headers = { Accept: "application/json", "X-Requested-With": "rss", ...options.headers };
  const response = await fetch(`api.php${query}`, { ...options, headers, credentials: "same-origin" });

  if (response.status === 401) {
    const next = encodeURIComponent(location.pathname + location.search);
    location.assign(`/login.php?next=${next}`);
    throw new Error("Redirecting to sign in.");
  }

  let body;
  try {
    body = await response.json();
  } catch (error) {
    throw new Error("The server returned an invalid response.");
  }
  if (!response.ok) throw new Error(body.error || "The server couldn't complete that request.");
  return body;
}

// One page of items, newest first. `before` is the `next` cursor from the previous page.
export const listItems = (before) => {
  const params = new URLSearchParams({ limit: "50" });
  if (before) params.set("before", before);
  return request(`?${params}`);
};

export const listFeeds = () => request("?feeds");

export const addFeed = (url) =>
  request("", { method: "POST", headers: JSON_HEADERS, body: JSON.stringify({ url }) });

export const removeFeed = (id) =>
  request("", { method: "DELETE", headers: JSON_HEADERS, body: JSON.stringify({ id }) });

// Fetches every feed. Rejects with the server's message if refreshed too recently.
export const refreshFeeds = () => request("?refresh", { method: "POST" });
