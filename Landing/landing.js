const text = (id, value) => { document.getElementById(id).textContent = value; };
const plural = (count, singular, pluralForm = `${singular}s`) => `${count} ${count === 1 ? singular : pluralForm}`;

async function getJson(url) {
  const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
  if (!response.ok) throw new Error(`${url}: ${response.status}`);
  return response.json();
}

// Fills one service card's badge and detail line. A failure leaves the card as a
// plain link, so an app being down never hides it.
async function loadService(id, load) {
  try {
    const { badge, detail } = await load();
    text(`${id}-badge`, badge);
    text(`${id}-detail`, detail);
  } catch (error) {
    text(`${id}-badge`, '');
  }
}

function addedText(createdAt) {
  const days = Math.floor((Date.now() - Date.parse(createdAt)) / 86400000);
  if (days < 1) return 'added today';
  return `${plural(days, 'day')} old`;
}

loadService('daylight', async () => {
  const open = (await getJson('/daylight/api.php')).filter((task) => !task.completed);
  if (open.length === 0) return { badge: 'All done', detail: 'Nothing open' };
  const oldest = open.reduce((a, b) => (Date.parse(b.created_at) < Date.parse(a.created_at) ? b : a));
  return { badge: `${open.length} open`, detail: `Oldest: ${oldest.title} · ${addedText(oldest.created_at)}` };
});

loadService('np', async () => {
  const items = await getJson('/np/api.php');
  if (items.length === 0) return { badge: 'Empty', detail: 'Nothing planned' };
  const total = items.reduce((sum, item) => sum + item.price, 0);
  return {
    badge: plural(items.length, 'item'),
    detail: `Next: ${items[0].name} · ${items[0].price.toFixed(2)} (total ${total.toFixed(2)})`,
  };
});

// RSS keeps no read state, so the badge counts subscribed feeds, not unread items.
loadService('rss', async () => {
  const [page, feeds] = await Promise.all([getJson('/rss/api.php?limit=1'), getJson('/rss/api.php?feeds')]);
  const latest = page.items[0];
  return {
    badge: plural(feeds.length, 'feed'),
    detail: latest ? `Latest: ${latest.title} · ${latest.feed_title}` : 'No items yet',
  };
});

// ---- Server health ----

const HEALTH_POLL_MS = 5000;
const WARN_AT = 70;
const HIGH_AT = 90;

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

// A metric is { label, text, usage?, hint? }; usage (0-100) adds a coloured bar and percentage.
function primaryMetrics(h) {
  const cpu = Math.min(100, h.cpu_percent);
  return [
    { label: 'CPU', text: plural(h.cores, 'core'), usage: cpu },
    { label: 'Memory', text: `${formatBytes(h.memory.used)} / ${formatBytes(h.memory.total)}`, usage: percent(h.memory.used, h.memory.total) },
    { label: 'Disk', text: `${formatBytes(h.disk.used)} / ${formatBytes(h.disk.total)}`, usage: percent(h.disk.used, h.disk.total) },
  ];
}

function moreMetrics(h) {
  const metrics = [
    { label: 'Disk I/O', text: `R ${formatBytes(h.disk_io.in)}/s · W ${formatBytes(h.disk_io.out)}/s` },
    { label: 'Network', text: `↓ ${formatBytes(h.network.in)}/s · ↑ ${formatBytes(h.network.out)}/s` },
    {
      label: 'Load',
      text: h.load.map((value) => value.toFixed(2)).join(' · '),
      hint: `Average work waiting over 1, 5 and 15 minutes. On ${plural(h.cores, 'core')}, a load of ${h.cores}.00 means fully busy.`,
    },
  ];
  if (h.memory.swap_total > 0 && h.memory.swap_used > 0) {
    metrics.unshift({
      label: 'Swap',
      text: `${formatBytes(h.memory.swap_used)} / ${formatBytes(h.memory.swap_total)}`,
      usage: percent(h.memory.swap_used, h.memory.swap_total),
    });
  }
  return metrics;
}

function renderMetric({ label, text: value, usage, hint }) {
  const row = document.createElement('div');
  row.className = usage === undefined ? 'metric metric-plain' : 'metric';

  const name = document.createElement('span');
  name.className = 'metric-label';
  name.textContent = label;
  if (hint) {
    name.title = hint;
    name.tabIndex = 0;
  }

  const detail = document.createElement('span');
  detail.className = 'metric-text';
  detail.textContent = value;

  if (usage === undefined) {
    row.append(name, detail);
    return row;
  }

  const track = document.createElement('div');
  track.className = 'metric-bar';
  const fill = document.createElement('div');
  fill.style.width = `${Math.max(0, Math.min(100, usage))}%`;
  fill.className = usage > HIGH_AT ? 'high' : usage >= WARN_AT ? 'warn' : '';
  track.append(fill);

  const pct = document.createElement('span');
  pct.className = 'metric-pct';
  pct.textContent = `${Math.round(usage)}%`;

  row.append(name, track, pct, detail);
  return row;
}

const dots = [document.getElementById('server-dot'), document.getElementById('health-dot')];
const setDots = (state, pulse = false) => {
  dots.forEach((dot) => { dot.className = `dot ${state}${pulse ? ' pulse' : ''}`; });
};

let lastHealth = null; // { sampleAge, receivedAt, stale }

// Re-rendered every second so "Updated Ns ago" keeps counting between polls.
function renderFreshness() {
  if (!lastHealth) return;
  const age = lastHealth.sampleAge + Math.round((Date.now() - lastHealth.receivedAt) / 1000);
  const stale = lastHealth.stale || age > 20;
  text('health-status', stale ? `Stale · updated ${age}s ago` : `Updated ${age}s ago`);
  setDots(stale ? 'bad' : 'ok', !stale);
}

function showUnavailable() {
  lastHealth = null;
  setDots('bad');
  text('health-status', 'Health collector unavailable');
  text('server-status', 'Status unknown');
  document.getElementById('health-rows').replaceChildren();
  document.getElementById('health-more').replaceChildren();
  document.getElementById('health-more-toggle').hidden = true;
}

async function refreshHealth() {
  try {
    const health = await getJson('/health.php');
    lastHealth = { sampleAge: health.age, receivedAt: Date.now(), stale: health.stale };
    text('server-name', health.hostname || location.hostname);
    document.title = health.hostname || location.hostname;
    text('server-status', health.stale ? 'Collector stale' : `Online · up ${formatUptime(health.uptime_seconds)}`);
    document.getElementById('health-rows').replaceChildren(...primaryMetrics(health).map(renderMetric));
    document.getElementById('health-more').replaceChildren(...moreMetrics(health).map(renderMetric));
    document.getElementById('health-more-toggle').hidden = false;
    renderFreshness();
  } catch (error) {
    showUnavailable();
  }
}

const moreToggle = document.getElementById('health-more-toggle');
moreToggle.addEventListener('click', () => {
  const panel = document.getElementById('health-more');
  panel.hidden = !panel.hidden;
  moreToggle.setAttribute('aria-expanded', String(!panel.hidden));
  moreToggle.textContent = panel.hidden ? 'More' : 'Less';
});

text('server-name', location.hostname);
refreshHealth();
setInterval(() => {
  if (!document.hidden) refreshHealth();
}, HEALTH_POLL_MS);
setInterval(renderFreshness, 1000);
