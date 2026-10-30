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
      a.append(el('span', 'live-detail', s.detail));
      if (s.until) {
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
