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

const HEALTH_POLL_MS = 5000;

function formatBytes(bytes) {
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let value = bytes;
  let unit = 0;
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit += 1;
  }
  return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
}

function formatUptime(seconds) {
  const days = Math.floor(seconds / 86400);
  const hours = Math.floor((seconds % 86400) / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  return days > 0 ? `${days}d ${hours}h` : `${hours}h ${minutes}m`;
}

const percent = (used, total) => (total > 0 ? (100 * used) / total : 0);

// Each metric is [label, text, optional percentage for a usage bar].
function healthMetrics(h) {
  const cpu = Math.min(100, h.cpu_percent);
  const memory = percent(h.memory.used, h.memory.total);
  const disk = percent(h.disk.used, h.disk.total);
  const metrics = [
    ['CPU', `${cpu.toFixed(0)}% · ${h.cores} cores`, cpu],
    ['Memory', `${formatBytes(h.memory.used)} / ${formatBytes(h.memory.total)}`, memory],
    ['Disk', `${formatBytes(h.disk.used)} / ${formatBytes(h.disk.total)}`, disk],
    ['Disk I/O', `R ${formatBytes(h.disk_io.in)}/s · W ${formatBytes(h.disk_io.out)}/s`],
    ['Network', `↓ ${formatBytes(h.network.in)}/s · ↑ ${formatBytes(h.network.out)}/s`],
    ['Load', h.load.map((value) => value.toFixed(2)).join(' · ')],
    ['Uptime', formatUptime(h.uptime_seconds)],
  ];
  if (h.memory.swap_total > 0) {
    metrics.splice(2, 0, ['Swap', `${formatBytes(h.memory.swap_used)} / ${formatBytes(h.memory.swap_total)}`,
      percent(h.memory.swap_used, h.memory.swap_total)]);
  }
  return metrics;
}

function renderMetric([label, text, usage]) {
  const row = document.createElement('div');
  row.className = 'metric';
  const name = document.createElement('span');
  name.className = 'metric-label';
  name.textContent = label;
  const value = document.createElement('span');
  value.className = 'metric-value';
  value.textContent = text;
  row.append(name, value);
  if (usage !== undefined) {
    const track = document.createElement('div');
    track.className = 'metric-bar';
    const fill = document.createElement('div');
    fill.style.width = `${Math.max(0, Math.min(100, usage))}%`;
    if (usage >= 90) fill.className = 'high';
    track.append(fill);
    row.append(track);
  }
  return row;
}

// Unlike loadPanel, the health panel stays visible and shows a problem state,
// so a dead collector is noticeable instead of silently disappearing.
async function refreshHealth() {
  const status = document.getElementById('health-status');
  const rows = document.getElementById('health-rows');
  try {
    const response = await fetch('/health.php', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (!response.ok) throw new Error('unavailable');
    const health = await response.json();
    rows.replaceChildren(...healthMetrics(health).map(renderMetric));
    status.className = health.stale ? 'bad' : '';
    status.textContent = health.stale ? `Stale: last sample ${health.age}s ago` : 'Live';
  } catch (error) {
    rows.replaceChildren();
    status.className = 'bad';
    status.textContent = 'Health collector unavailable';
  }
}

refreshHealth();
setInterval(() => {
  if (!document.hidden) refreshHealth();
}, HEALTH_POLL_MS);
