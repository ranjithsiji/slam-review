// Public schedule page: server-rendered; JS only refreshes live status (~10 s, paused when hidden).
import '../theme/index.js';
import '@tabler/core/dist/js/tabler.esm.js';
import { initThemeToggle } from '../shared/theme-toggle.js';
import { esc, iconHtml } from '../shared/ui.js';
import { sem } from '../shared/semantic.js';
import { time } from '../shared/clock.js';

initThemeToggle();
const root = document.querySelector('[data-public-schedule]');
if (root) {
  const url = root.dataset.publicSchedule;
  let etag = null;
  let timer = null;
  const badgeFor = (status) => {
    const s = sem('slot', status === 'presenting' ? 'open' : status === 'done' ? 'closed' : status === 'break_now' ? 'open' : 'pending');
    const label = { presenting: 'Presenting now', break_now: 'On now', done: 'Done', upcoming: 'Upcoming' }[status] || status;
    return `<span class="badge bg-${s.color}-lt">${iconHtml(s.icon, 'icon-inline me-1')}${esc(label)}</span>`;
  };
  const render = (doc) => {
    const banner = root.querySelector('[data-banner]');
    if (banner) {
      let html = '';
      if (doc.finished) html = 'Slam finished — thank you!';
      else if (doc.now) html = doc.now.kind === 'break' ? `Now: ${esc(doc.now.label)}` : `Now: <strong>${esc(doc.now.presenter_name)}</strong> — ${esc(doc.now.topic)}`;
      else if (doc.next) html = `Up next: <strong>${esc(doc.next.kind === 'break' ? doc.next.label : doc.next.presenter_name)}</strong> at ${esc(time(doc.next.planned_start_at))}`;
      if (doc.late_minutes > 0 && !doc.finished) html += ` <span class="badge bg-orange-lt ms-2">Running about ${esc(doc.late_minutes)} min behind schedule</span>`;
      banner.innerHTML = html;
      banner.hidden = html === '';
    }
    const list = root.querySelector('[data-slots]');
    const ids = doc.slots.map((s) => String(s.id));
    const current = [...list.querySelectorAll('[data-slot]')].map((el) => el.dataset.slot);
    if (ids.join() !== current.join()) { window.location.reload(); return; }
    doc.slots.forEach((s) => {
      const el = list.querySelector(`[data-slot="${s.id}"]`);
      el.querySelector('[data-status]').innerHTML = badgeFor(s.status);
      el.querySelector('[data-time]').textContent = time(s.planned_start_at);
      el.classList.toggle('border-danger', s.status === 'presenting');
    });
  };
  const poll = async () => {
    clearTimeout(timer);
    if (document.hidden) return;
    try {
      const res = await fetch(url, { headers: etag ? { 'If-None-Match': etag } : {}, credentials: 'same-origin' });
      if (res.status === 200) { etag = res.headers.get('ETag'); render(await res.json()); }
    } catch { /* keep the last view */ }
    timer = setTimeout(poll, 8000 + Math.random() * 4000);
  };
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
  timer = setTimeout(poll, 3000);
}
