// Fetches a JSON endpoint and, on success, fills and reveals a panel.
// Any failure (not signed in, empty list, app down) leaves the panel hidden.
async function loadPanel(url, panelId, fill) {
  try {
    const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (!response.ok) return;
    fill(await response.json());
    document.getElementById(panelId).hidden = false;
  } catch (error) {
    // Panels are optional.
  }
}

function isHttpUrl(value) {
  try {
    const url = new URL(value);
    return url.protocol === 'http:' || url.protocol === 'https:';
  } catch (error) {
    return false;
  }
}

loadPanel('/daylight/api.php?oldest=1', 'oldest', (task) => {
  const days = Math.floor((Date.now() - Date.parse(task.created_at)) / 86400000);
  document.getElementById('oldest-title').textContent = task.title;
  document.getElementById('oldest-age').textContent =
    days < 1 ? 'Added today' : `Open for ${days} day${days === 1 ? '' : 's'}`;
});

loadPanel('/np/api.php?position=1', 'next-buy', (item) => {
  const title = document.getElementById('next-buy-title');
  if (item.link && isHttpUrl(item.link)) {
    const anchor = document.createElement('a');
    anchor.href = item.link;
    anchor.target = '_blank';
    anchor.rel = 'noopener noreferrer';
    anchor.textContent = item.name;
    title.replaceChildren(anchor);
  } else {
    title.textContent = item.name;
  }
  document.getElementById('next-buy-price').textContent = item.price.toFixed(2);
});

loadPanel('/rss/api.php?limit=1', 'latest', (page) => {
  const item = page.items[0];
  if (!item || !isHttpUrl(item.link)) throw new Error('No headline.');
  const anchor = document.createElement('a');
  anchor.href = item.link;
  anchor.target = '_blank';
  anchor.rel = 'noopener noreferrer';
  anchor.textContent = item.title;
  document.getElementById('latest-title').replaceChildren(anchor);
  document.getElementById('latest-meta').textContent = item.feed_title;
});
