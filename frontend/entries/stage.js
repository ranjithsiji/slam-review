// Stage timer: a huge countdown the presenter can read from the stage. Public data only, no login.
import '../theme/index.js';
import './stage.css';
import { time } from '../shared/clock.js';

const root = document.querySelector('[data-stage]');
const $ = (k) => root.querySelector(`[data-stage-${k}]`);
const [who, label, big, sub, clock, full, bar] = ['who', 'label', 'time', 'sub', 'clock', 'full', 'bar'].map($);
const seg = (k) => root.querySelector(`[data-seg-${k}]`);
// Progress bar along the bottom: green, orange for the last 2 minutes, red for the last one; the marker shows time used.
function setBar(total, used) {
  if (!total) { bar.hidden = true; return; }
  bar.hidden = false;
  const soon = Math.min(120, total / 2), last = Math.min(60, soon / 2);
  // Each part darkens as the time in it is used, so the bar shows what is left in each colour.
  const parts = [['ok', 0, total - soon, '#5fc16b', '#2c302d'], ['soon', total - soon, total - last, '#e8a33e', '#352d26'], ['last', total - last, total, '#df5048', '#362a2a']];
  for (const [k, from, to, bright, dim] of parts) {
    const el = seg(k);
    el.style.width = `${((to - from) / total) * 100}%`;
    const p = Math.max(0, Math.min(100, ((used - from) / (to - from)) * 100));
    el.style.background = `linear-gradient(to right, ${dim} ${p}%, ${bright} ${p}%)`;
  }
  seg('mark').style.left = `${Math.max(0, Math.min(100, (used / total) * 100))}%`;
}
let doc = null;
let etag = null;
let offset = 0; // server clock minus this device's clock, from the Date header

const now = () => Date.now() + offset;
const fmt = (s) => {
  const a = Math.abs(Math.trunc(s));
  return `${Math.floor(a / 60)}:${String(a % 60).padStart(2, '0')}`;
};
const set = (el, v) => { if (el.textContent !== v) el.textContent = v; };

function view() {
  let state = 'idle';
  if (!doc) { set(label, ''); set(big, ''); set(sub, 'Connecting…'); root.dataset.state = state; return; }
  const st = doc.stage || {};
  const pausedAt = st.paused_since ? Date.parse(st.paused_since) : null;
  const at = pausedAt ?? now();                    // while paused the clock stands still
  const talk = doc.talk;
  if (doc.finished) {
    bar.hidden = true;
    set(who, ''); set(label, ''); set(big, 'Thank you!'); set(sub, `${root.dataset.name} has finished.`); state = 'done';
  } else if (talk && talk.on_stage && talk.started_at) {
    const end = Date.parse(talk.started_at) + (st.presentation_minutes || 10) * 60000;
    const left = (end - at) / 1000;
    set(who, talk.presenter || '');
    set(label, pausedAt ? 'Paused' : '');
    setBar((st.presentation_minutes || 10) * 60, (st.presentation_minutes || 10) * 60 - left);
    set(big, (left < 0 ? '+' : '') + fmt(left));
    set(sub, talk.title || '');
    state = pausedAt ? 'paused' : left < 0 ? 'over' : left <= 60 ? 'last' : left <= 120 ? 'soon' : 'ok';
  } else if (doc.now && doc.now.kind === 'break' && st.break_ends_at) {
    const left = (Date.parse(st.break_ends_at) - at) / 1000;
    bar.hidden = true;
    set(who, doc.now.label || 'Break'); set(label, ''); set(big, fmt(Math.max(0, left)));
    set(sub, doc.next && doc.next.kind !== 'break' ? `Next: ${doc.next.presenter_name}` : '');
    state = pausedAt ? 'paused' : 'break';
  } else {
    bar.hidden = true;
    const n = doc.next;
    set(who, ''); set(label, n ? (n.kind === 'break' ? 'Next' : 'Next on stage') : '');
    set(big, n ? (n.kind === 'break' ? n.label : n.presenter_name) || '' : root.dataset.name);
    set(sub, n && n.kind !== 'break' ? n.topic || '' : '');
    state = 'idle';
  }
  root.dataset.state = state;
  root.classList.toggle('stage-text', state === 'idle' || state === 'done');
}

async function poll() {
  try {
    const res = await fetch(root.dataset.stage, { headers: etag ? { 'If-None-Match': etag } : {}, credentials: 'omit', cache: 'no-store' });
    const d = res.headers.get('Date');
    if (d) offset = Date.parse(d) + 500 - Date.now();
    if (res.status === 200) { etag = res.headers.get('ETag'); doc = await res.json(); }
    root.classList.remove('stage-offline');
  } catch { root.classList.add('stage-offline'); }
  setTimeout(poll, 3000 + Math.random() * 1000);
}

setInterval(() => { view(); set(clock, time(new Date(now()).toISOString())); }, 250);
const wake = async () => { try { await navigator.wakeLock?.request('screen'); } catch { /* not allowed */ } };
wake();
document.addEventListener('visibilitychange', () => { if (!document.hidden) { wake(); poll(); } });
let hide = null;
const showBtn = () => { full.hidden = !!document.fullscreenElement; clearTimeout(hide); hide = setTimeout(() => { full.hidden = true; }, 6000); };
document.addEventListener('mousemove', showBtn);
document.addEventListener('touchstart', showBtn);
full.addEventListener('click', () => document.documentElement.requestFullscreen?.());
showBtn();
poll();
