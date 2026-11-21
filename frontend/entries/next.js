// Next presenter screen (/slams/{slug}/next): who goes on stage next, big, with a countdown to their expected start,
// and the two after that. From the public JSON only: no login, nothing that is not already public.
import '../theme/index.js';
import { esc } from '../shared/ui.js';
import { time, syncServerTime, serverNow } from '../shared/clock.js';

const root = document.querySelector('[data-nx]');
const stage = root.querySelector('[data-nx-stage]');
const after = root.querySelector('[data-nx-after]');
const chips = root.querySelector('[data-nx-chips]');
const clock = root.querySelector('[data-nx-clock]');
const fullBtn = root.querySelector('[data-nx-full]');
const url = root.dataset.nx;
let etag = null;
let timer = null;
let lastGood = Date.now();
let lastJson = '';
let doc = null;

const talks = (d) => d.slots.filter((s) => s.kind === 'performance');
const numberOf = (d, id) => talks(d).findIndex((s) => s.id === id) + 1;
const upcomingTalks = (d) => talks(d).filter((s) => s.status === 'upcoming');
// Expected start: the planned time, moved by how late the slam is running.
const expected = (d, s) => Date.parse(s.planned_start_at) + Math.max(0, d.late_minutes || 0) * 60000;

const fmtLeft = (ms) => {
  const t = Math.max(0, Math.round(ms / 1000));
  const h = Math.floor(t / 3600); const m = Math.floor((t % 3600) / 60); const sec = t % 60;
  return h ? `${h}:${String(m).padStart(2, '0')}:${String(sec).padStart(2, '0')}` : `${m}:${String(sec).padStart(2, '0')}`;
};

const centre = (eyebrow, title, sub = '') => `<div class="tv-center">
    <img src="${esc(root.dataset.logo)}" alt="" class="tv-center-logo">
    ${eyebrow ? `<div class="tv-eyebrow">${esc(eyebrow)}</div>` : ''}
    <div class="tv-headline ml-text">${esc(title)}</div>
    ${sub ? `<div class="tv-sub ml-text">${esc(sub)}</div>` : ''}
  </div>`;

const nextView = (d) => {
  if (d.finished) return centre('The slam is closed', 'Thank you!', 'The results will be announced soon.');
  const next = upcomingTalks(d)[0];
  if (!next) return centre('', 'All talks are done', 'Thank you, presenters!');
  const img = next.photo || next.thumb;
  const late = d.late_minutes > 0;
  return `<div class="tv-talk nx-talk">
    <div class="tv-talk-photo">${img ? `<img src="${esc(img)}" alt="">` : ''}</div>
    <div class="tv-talk-text">
      <div class="tv-eyebrow nx-eyebrow">Next presenter · #${numberOf(d, next.id)}</div>
      <div class="tv-name nx-name ml-text">${esc(next.presenter_name)}</div>
      ${next.designation ? `<div class="tv-inst ml-text">${esc(next.designation)}</div>` : ''}
      <div class="tv-title nx-title ml-text">${esc(next.topic || '')}</div>
      <div class="nx-when">
        <div class="nx-time"><span>${late ? 'Expected' : 'Planned'}</span><strong>${esc(time(new Date(expected(d, next)).toISOString()))}</strong>${late ? `<em>planned ${esc(time(next.planned_start_at))}</em>` : ''}</div>
        <div class="nx-count" data-nx-count="${expected(d, next)}"><span>Starts in</span><strong>–</strong></div>
      </div>
    </div>
  </div>`;
};

const afterView = (d) => {
  if (d.finished) return '';
  const later = upcomingTalks(d).slice(1, 3);
  if (!later.length) return '';
  return `<div class="tv-next-label">After that</div>${later.map((s) => `<div class="tv-next-item">
      <span class="tv-next-time">${esc(time(new Date(expected(d, s)).toISOString()))}</span>
      ${s.thumb ? `<img src="${esc(s.thumb)}" alt="">` : ''}<span class="tv-next-who ml-text">#${numberOf(d, s.id)} ${esc(s.presenter_name)}</span>
    </div>`).join('')}`;
};

const render = (d) => {
  const json = JSON.stringify(d);
  if (json === lastJson) return;
  lastJson = json; doc = d;
  stage.innerHTML = nextView(d);
  after.innerHTML = afterView(d);
  let c = '';
  if (d.paused && !d.finished) c += '<span class="tv-chip">Paused for a short while</span>';
  if (d.late_minutes > 0 && !d.finished) c += `<span class="tv-chip">Running about ${esc(d.late_minutes)} min behind</span>`;
  chips.innerHTML = c;
  tick();
};

const tick = () => {
  clock.textContent = time(new Date(serverNow()).toISOString());
  const el = stage.querySelector('[data-nx-count]');
  if (!el) return;
  const left = Number(el.dataset.nxCount) - serverNow();
  const out = el.querySelector('strong');
  if (doc?.paused) { out.textContent = 'Paused'; el.classList.remove('nx-soon'); return; }
  out.textContent = left > 0 ? fmtLeft(left) : 'Any moment now';
  el.classList.toggle('nx-soon', left <= 120000);
};

const poll = async () => {
  clearTimeout(timer);
  try {
    const res = await fetch(url, { signal: AbortSignal.timeout?.(12000), headers: etag ? { 'If-None-Match': etag } : {}, credentials: 'omit' });
    syncServerTime(res.headers.get('Date') ? new Date(res.headers.get('Date')).toISOString() : null);
    if (res.status === 200) { etag = res.headers.get('ETag'); render(await res.json()); lastGood = Date.now(); }
    if (res.status === 304) lastGood = Date.now();
  } catch { /* keep showing the last view */ }
  root.classList.toggle('tv-offline', Date.now() - lastGood > 60000);
  timer = setTimeout(poll, 5000 + Math.random() * 3000);
};

setInterval(tick, 1000);
const wake = async () => { try { await navigator.wakeLock?.request('screen'); } catch { /* not allowed */ } };
wake();
document.addEventListener('visibilitychange', () => { if (!document.hidden) { wake(); poll(); } });
let hideBtn = null;
const syncFull = () => { fullBtn.hidden = !!document.fullscreenElement || !document.documentElement.requestFullscreen; };
const showBtn = () => { syncFull(); clearTimeout(hideBtn); hideBtn = setTimeout(() => { fullBtn.hidden = true; }, 8000); };
document.addEventListener('mousemove', showBtn);
fullBtn.addEventListener('click', () => document.documentElement.requestFullscreen?.());
document.addEventListener('fullscreenchange', syncFull);
showBtn();
tick();
poll();
