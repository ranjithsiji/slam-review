// Stage timer: a huge countdown the presenter can read from the stage. Public data only, no login.
import '../theme/index.js';
import './stage.css';
import { time } from '../shared/clock.js';

const root = document.querySelector('[data-stage]');
const $ = (k) => root.querySelector(`[data-stage-${k}]`);
const [who, label, big, sub, clock, full] = ['who', 'label', 'time', 'sub', 'clock', 'full'].map($);
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
    set(who, ''); set(label, ''); set(big, 'Thank you!'); set(sub, `${root.dataset.name} has finished.`); state = 'done';
  } else if (talk && talk.on_stage && talk.started_at) {
    const end = Date.parse(talk.started_at) + (st.presentation_minutes || 10) * 60000;
    const left = (end - at) / 1000;
    set(who, talk.presenter || '');
    set(label, pausedAt ? 'Paused' : left >= 0 ? 'Time left' : 'Over time');
    set(big, (left < 0 ? '+' : '') + fmt(left));
    set(sub, talk.title || '');
    state = pausedAt ? 'paused' : left < 0 ? 'over' : left <= 60 ? 'last' : left <= 120 ? 'soon' : 'ok';
  } else if (doc.now && doc.now.kind === 'break' && st.break_ends_at) {
    const left = (Date.parse(st.break_ends_at) - at) / 1000;
    set(who, ''); set(label, doc.now.label || 'Break'); set(big, fmt(Math.max(0, left)));
    set(sub, doc.next && doc.next.kind !== 'break' ? `Next: ${doc.next.presenter_name}` : '');
    state = pausedAt ? 'paused' : 'break';
  } else {
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
