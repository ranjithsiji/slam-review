// Public schedule page: server-rendered; JS only refreshes live status (~10 s, paused when hidden).
import '../theme/index.js';
import '@tabler/core/dist/js/tabler.esm.js';
import { initThemeToggle } from '../shared/theme-toggle.js';
import { initSiteMenu } from '../shared/site-menu.js';
import { esc, iconHtml } from '../shared/ui.js';
import { sem } from '../shared/semantic.js';
import { time } from '../shared/clock.js';

initThemeToggle();
initSiteMenu();
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
      if (doc.paused && !doc.finished) html = `<span class="badge bg-orange-lt me-2">Paused</span>The programme is paused for a short while and will continue shortly. ${html}`;
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

// Home page "Happening now": polls the live list (~10 s, paused while the tab is hidden). The wording matches
// live_headline() in ui_helper.php. When the set of live slams or the current/next talk changes, reload so the schedule
// list below shows the new statuses; otherwise update the text in place (paused / running late).
const home = document.querySelector('[data-home-live]');
if (home) {
  let etag = null;
  let timer = null;
  const sigOf = (list) => list.map((d) => `${d.slug}:${d.now?.id ?? ''}:${d.next?.id ?? ''}:${d.phase}:${d.paused ? 1 : 0}`).join('|');
  const headline = (d) => {
    let t;
    if (d.now && d.now.kind === 'break') t = `Now: <strong>${esc(d.now.label)}</strong>`;
    else if (d.now) t = `Presenting now: <strong>${esc(d.now.presenter_name)}</strong> — ${esc(d.now.topic)}`;
    else if (d.phase === 'checkin_open') t = 'Gates are open. Check in at the entrance.';
    else if (d.next) t = `Up next: <strong>${esc(d.next.kind === 'break' ? d.next.label : d.next.presenter_name)}</strong> at ${esc(time(d.next.planned_start_at))}`;
    else if (['reviews_complete', 'revision', 'revision_closed'].includes(d.phase)) t = 'All performances are done. Scores are being finalised.';
    else t = 'Gates are closed. The first performance starts shortly.';
    if (d.paused) t = `<span class="badge bg-orange-lt me-2">Paused</span>The programme is paused for a short while. ${t}`;
    return t;
  };
  const poll = async () => {
    clearTimeout(timer);
    if (!document.hidden) {
      try {
        const res = await fetch(home.dataset.homeLive, { headers: etag ? { 'If-None-Match': etag } : {}, credentials: 'same-origin' });
        if (res.status === 200) {
          etag = res.headers.get('ETag');
          const { slams } = await res.json();
          if (sigOf(slams) !== home.dataset.sig) { window.location.reload(); return; }
          slams.forEach((d) => {
            const text = home.querySelector(`[data-live-text="${CSS.escape(d.slug)}"]`);
            if (text) text.innerHTML = headline(d);
            const late = home.querySelector(`[data-live-late="${CSS.escape(d.slug)}"]`);
            if (late) late.innerHTML = d.late_minutes > 0 ? `<span class="badge bg-orange-lt">Running about ${esc(d.late_minutes)} min behind schedule</span>` : '';
          });
        }
      } catch { /* keep the last view */ }
    }
    timer = setTimeout(poll, 8000 + Math.random() * 4000);
  };
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
  timer = setTimeout(poll, 4000);
}
