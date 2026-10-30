// Top bar on every organiser page: what is going on right now in each running
// slam (phase, current performance or break, a countdown) with a link to its
// Status screen. Small and framework-free; polls every ~5 s, pauses when hidden.
import { get } from './api.js';
import { fmtCountdown } from './clock.js';

export function initLiveBar() {
  const bar = document.getElementById('live-bar');
  if (!bar) return;
  let offset = 0;
  let slams = [];
  let timer = null;
  let failures = 0;

  const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  };

  function render() {
    bar.replaceChildren();
    bar.hidden = slams.length === 0;
    for (const s of slams) {
      const a = el('a', 'live-bar-item');
      a.href = s.url;
      a.append(el('span', 'live-dot'));
      a.append(el('strong', 'live-name', s.name));
      a.append(el('span', 'badge bg-orange-lt', s.label));
      if (s.hold) a.append(el('span', 'badge bg-secondary-lt', s.hold === 'manual' ? 'MANUAL' : 'HELD'));
      if (s.paused_since) {
        a.append(el('span', 'badge bg-red text-white', 'PAUSED'));
        const e = el('span', 'live-time');
        e.dataset.since = s.paused_since;
        a.append(e);
      }
      if (s.drift) {
        const d = el('span', 'badge');
        d.dataset.orig = s.drift.original;
        d.dataset.basis = s.drift.basis;
        d.dataset.fixed = s.drift.minutes;
        a.append(d);
      }
      a.append(el('span', 'live-detail', s.detail));
      if (s.until && !s.paused_since) {
        const t = el('span', 'live-time');
        t.dataset.until = s.until;
        a.append(t);
      }
      a.append(el('span', 'live-go ms-auto', 'Status →'));
      bar.append(a);
    }
    tick();
  }
  function tick() {
    const nowMs = Date.now() + offset;
    bar.querySelectorAll('[data-since]').forEach((t) => {
      t.textContent = fmtCountdown(Math.round((nowMs - Date.parse(t.dataset.since)) / 1000));
    });
    bar.querySelectorAll('[data-orig]').forEach((d) => {
      // Waiting for the next performance: the gap to its original start keeps growing (or shrinking) with the clock.
      const m = d.dataset.basis === 'waiting' ? Math.round((nowMs - Date.parse(d.dataset.orig)) / 60000) : Number(d.dataset.fixed);
      d.textContent = m >= 1 ? `${m} min behind` : m <= -1 ? `${-m} min ahead` : 'on schedule';
      d.className = `badge ${m >= 1 ? 'bg-orange-lt' : m <= -1 ? 'bg-green-lt' : 'bg-secondary-lt'}`;
    });
    bar.querySelectorAll('[data-until]').forEach((t) => {
      t.textContent = `${fmtCountdown(Math.round((Date.parse(t.dataset.until) - nowMs) / 1000))} left`;
    });
  }
  async function load() {
    clearTimeout(timer);
    if (document.hidden) { timer = setTimeout(load, 5000); return; }
    try {
      const d = await get('/api/admin/live');
      offset = Date.parse(d.server_now) - Date.now();
      slams = d.slams;
      failures = 0;
      render();
    } catch (e) {
      failures += 1;
      if (e.status === 401 || e.status === 403) return;
    }
    timer = setTimeout(load, Math.min(30000, 5000 * 2 ** Math.min(failures, 3)) * (0.8 + Math.random() * 0.4));
  }
  setInterval(tick, 1000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });
  window.addEventListener('slam:changed', () => setTimeout(load, 300)); // an action was just taken on this page
  load();
}
