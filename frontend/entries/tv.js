// Venue TV: shows what is happening now from the public JSON. No login, and nothing that is not already public.
import '../theme/index.js';
import { esc } from '../shared/ui.js';
import { time } from '../shared/clock.js';

const root = document.querySelector('[data-tv]');
const stage = root.querySelector('[data-tv-stage]');
const nextBar = root.querySelector('[data-tv-next]');
const chips = root.querySelector('[data-tv-chips]');
const clock = root.querySelector('[data-tv-clock]');
const fullBtn = root.querySelector('[data-tv-full]');
const url = root.dataset.tv;
let etag = null;
let timer = null;
let lastGood = Date.now();
let adTimer = null;

const logoView = (eyebrow, title, sub = '') => `<div class="tv-center">
    <img src="${esc(root.dataset.logo)}" alt="" class="tv-center-logo">
    ${eyebrow ? `<div class="tv-eyebrow">${esc(eyebrow)}</div>` : ''}
    <div class="tv-headline ml-text">${esc(title)}</div>
    ${sub ? `<div class="tv-sub ml-text">${esc(sub)}</div>` : ''}
  </div>`;

const talkNumber = (doc, id) => {
  const talks = doc.slots.filter((s) => s.kind !== 'break');
  const i = talks.findIndex((s) => s.id === id);
  return i < 0 ? null : i + 1;
};

const talkView = (doc) => {
  const t = doc.talk;
  const slot = doc.now && doc.now.kind !== 'break' ? doc.now : null;
  const no = slot ? talkNumber(doc, slot.id) : null;
  const img = t.photo || t.thumb;
  return `<div class="tv-talk">
    <div class="tv-talk-photo">${img ? `<img src="${esc(img)}" alt="">` : ''}</div>
    <div class="tv-talk-text">
      <div class="tv-eyebrow ${t.on_stage ? 'tv-eyebrow-live' : ''}">${t.on_stage ? 'On stage now' : 'Talk just finished'}${no ? ` · #${no}` : ''}</div>
      <div class="tv-title ml-text">${esc(t.title)}</div>
      <div class="tv-name ml-text">${esc(t.presenter)}</div>
      ${t.designation ? `<div class="tv-inst ml-text">${esc(t.designation)}</div>` : ''}
    </div>
  </div>`;
};

const breakView = (doc) => {
  const slot = doc.slots.find((s) => s.id === doc.now.id);
  const back = slot ? new Date(Date.parse(slot.planned_start_at) + slot.duration_minutes * 60000).toISOString() : null;
  const ads = doc.ads || [];
  const adHtml = ads.map((a, i) => `<div class="tv-ad" ${i ? 'hidden' : ''}>
      ${a.image ? `<img src="${esc(a.image)}" alt="">` : ''}
      <div>${a.sponsor ? `<div class="tv-eyebrow">${esc(a.sponsor)}</div>` : ''}
      ${a.headline ? `<div class="tv-name ml-text">${esc(a.headline)}</div>` : ''}
      ${a.body ? `<div class="tv-inst ml-text">${esc(a.body)}</div>` : ''}</div>
    </div>`).join('');
  return `<div class="tv-break ${ads.length ? 'tv-break-ads' : ''}">
    <div class="tv-center">
      <div class="tv-eyebrow">Break</div>
      <div class="tv-headline ml-text">${esc(doc.now.label)}</div>
      ${back ? `<div class="tv-sub">Back at ${esc(time(back))}</div>` : ''}
    </div>
    ${ads.length ? `<div class="tv-ads" data-tv-ads>${adHtml}</div>` : ''}
  </div>`;
};

const mainView = (doc) => {
  if (doc.finished) return logoView('', 'Thank you!', `${root.dataset.name} has finished.`);
  if (doc.now && doc.now.kind === 'break') return breakView(doc);
  if (doc.talk) return talkView(doc);
  if (doc.phase === 'checkin_open') return logoView('Check-in is open', 'Welcome!', 'Please check in at the entrance.');
  if (['reviews_complete', 'revision', 'revision_closed', 'marks_calculated'].includes(doc.phase)) return logoView('', 'All talks are done', 'Scores are being finalised.');
  if (doc.next) {
    const who = doc.next.kind === 'break' ? doc.next.label : doc.next.presenter_name;
    return logoView(`Up next at ${time(doc.next.planned_start_at)}`, who, doc.next.kind === 'break' ? '' : doc.next.topic);
  }
  return logoView('', root.dataset.name, root.dataset.venue);
};

const nextView = (doc) => {
  if (doc.finished) return '';
  const up = doc.slots.filter((s) => s.status === 'upcoming').slice(0, 3);
  if (!up.length) return '';
  return `<div class="tv-next-label">Up next</div>${up.map((s) => `<div class="tv-next-item">
      <span class="tv-next-time">${esc(time(s.planned_start_at))}</span>
      ${s.kind === 'break' ? `<span class="tv-next-who ml-text">${esc(s.label)}</span>`
    : `${s.thumb ? `<img src="${esc(s.thumb)}" alt="">` : ''}<span class="tv-next-who ml-text">${esc(s.presenter_name)}</span>`}
    </div>`).join('')}`;
};

const rotateAds = () => {
  clearInterval(adTimer);
  const slides = [...stage.querySelectorAll('.tv-ad')];
  if (slides.length < 2) return;
  let i = 0;
  adTimer = setInterval(() => { slides[i].hidden = true; i = (i + 1) % slides.length; slides[i].hidden = false; }, 8000);
};

const render = (doc) => {
  stage.innerHTML = mainView(doc);
  nextBar.innerHTML = nextView(doc);
  let c = '';
  if (doc.paused && !doc.finished) c += '<span class="tv-chip">Paused for a short while</span>';
  if (doc.late_minutes > 0 && !doc.finished) c += `<span class="tv-chip">Running about ${esc(doc.late_minutes)} min behind</span>`;
  chips.innerHTML = c;
  rotateAds();
};

const poll = async () => {
  clearTimeout(timer);
  try {
    const res = await fetch(url, { headers: etag ? { 'If-None-Match': etag } : {}, credentials: 'omit' });
    if (res.status === 200) { etag = res.headers.get('ETag'); render(await res.json()); lastGood = Date.now(); }
    if (res.status === 304) lastGood = Date.now();
  } catch { /* keep showing the last view */ }
  // A TV is unattended: after a long connection loss it keeps the last view and carries on trying.
  root.classList.toggle('tv-offline', Date.now() - lastGood > 60000);
  timer = setTimeout(poll, 5000 + Math.random() * 3000);
};

const tick = () => { clock.textContent = time(new Date().toISOString()); };
tick();
setInterval(tick, 1000);

// Stay awake and offer full screen. Both are best-effort, and the page works without them.
const wake = async () => { try { await navigator.wakeLock?.request('screen'); } catch { /* not allowed */ } };
wake();
document.addEventListener('visibilitychange', () => { if (!document.hidden) { wake(); poll(); } });
// The button only appears while someone is moving the mouse, so it never sits on the screen during the event.
let hideBtn = null;
const syncFull = () => { fullBtn.hidden = !!document.fullscreenElement || !document.documentElement.requestFullscreen; };
const showBtn = () => { syncFull(); clearTimeout(hideBtn); hideBtn = setTimeout(() => { fullBtn.hidden = true; }, 8000); };
document.addEventListener('mousemove', showBtn);
fullBtn.addEventListener('click', () => document.documentElement.requestFullscreen?.());
document.addEventListener('fullscreenchange', syncFull);
showBtn();
poll();
