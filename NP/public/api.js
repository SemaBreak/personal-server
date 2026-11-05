const JSON_HEADERS = { "Content-Type": "application/json" };

// Sends a request to api.php and returns the parsed JSON body.
// A 401 means the session expired, so the browser is sent to the shared login page.
async function request(options = {}) {
  const headers = { Accept: "application/json", "X-Requested-With": "np", ...options.headers };
  const response = await fetch("api.php", { ...options, headers, credentials: "same-origin" });

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

export const listItems = () => request();

export const addItem = (name, price, link) =>
  request({ method: "POST", headers: JSON_HEADERS, body: JSON.stringify({ name, price, link }) });

export const deleteItem = (id) =>
  request({ method: "DELETE", headers: JSON_HEADERS, body: JSON.stringify({ id }) });

// Saves the full order and returns the list as stored.
export const saveOrder = (order) =>
  request({ method: "PUT", headers: JSON_HEADERS, body: JSON.stringify({ order }) });
