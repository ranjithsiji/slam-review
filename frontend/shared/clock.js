// Server-time based clock. Clients never trust their own clock for deadlines:
// we keep an offset from the server's `server_now` and run countdowns locally.
import { ref } from 'vue';

// Display timezone and clock style come from Admin → Settings via <meta> tags.
const meta = (name, fallback) => (typeof document !== 'undefined' && document.querySelector(`meta[name="${name}"]`)?.content) || fallback;
export const TZ = meta('app-tz', 'Asia/Kolkata');
export const HOUR12 = meta('app-time-format', '24h') === '12h';
/** Short timezone label for headings, e.g. IST. */
export const tzLabel = meta('app-tz-label', TZ);
let offsetMs = 0;
export const now = ref(Date.now());

export function syncServerTime(serverIso) {
  if (!serverIso) return;
  const s = Date.parse(serverIso);
  if (!Number.isNaN(s)) offsetMs = s - Date.now();
  now.value = serverNow();
}

export const serverNow = () => Date.now() + offsetMs;

let ticker = null;
export function startTicker() {
  if (ticker) return;
  ticker = setInterval(() => { now.value = serverNow(); }, 250);
}

/** Seconds until an ISO time (negative when passed). */
export const secondsUntil = (iso, at = now.value) => (iso ? Math.round((Date.parse(iso) - at) / 1000) : null);

export function fmtCountdown(sec) {
  if (sec === null || sec === undefined) return '';
  const s = Math.max(0, sec);
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const r = s % 60;
  return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${String(r).padStart(2, '0')}` : `${m}:${String(r).padStart(2, '0')}`;
}

const fmt = (opts) => new Intl.DateTimeFormat('en-IN', { timeZone: TZ, ...opts });
const clock = { hour: HOUR12 ? 'numeric' : '2-digit', minute: '2-digit', hour12: HOUR12 };
const timeF = fmt(clock);
const dateF = fmt({ day: 'numeric', month: 'short', year: 'numeric' });
const dateTimeF = fmt({ day: 'numeric', month: 'short', ...clock });
const longDateF = fmt({ weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
const partsF = new Intl.DateTimeFormat('en-US', { timeZone: TZ, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' });

export const time = (iso) => (iso ? timeF.format(new Date(iso)) : '');
export const date = (iso) => (iso ? dateF.format(new Date(iso)) : '');
export const dateTime = (iso) => (iso ? dateTimeF.format(new Date(iso)) : '');
export const longDate = (iso) => (iso ? longDateF.format(new Date(iso)) : '');

const toIso = (ms) => new Date(ms).toISOString().replace(/\.\d{3}Z$/, 'Z');
function wallClock(ms) {
  const p = Object.fromEntries(partsF.formatToParts(new Date(ms)).map((x) => [x.type, x.value]));
  return { y: +p.year, mo: +p.month, d: +p.day, h: +p.hour, mi: +p.minute, s: +p.second };
}
/** Offset of the display timezone from UTC at a moment (ms). */
function offsetAt(ms) {
  const w = wallClock(ms);
  return Date.UTC(w.y, w.mo - 1, w.d, w.h, w.mi, w.s) - Math.floor(ms / 1000) * 1000;
}
/** Date (YYYY-MM-DD) and time (HH:MM) in the display timezone → ISO UTC (DST-safe). */
export function localToIso(d, t) {
  const wall = Date.parse(`${d}T${t}:00Z`);
  let ms = wall - offsetAt(wall);
  ms = wall - offsetAt(ms);
  return toIso(ms);
}
/** ISO UTC → display-timezone parts for <input type="date"/"time">. */
export function isoToLocal(iso) {
  if (!iso) return { date: '', time: '' };
  const w = wallClock(Date.parse(iso));
  const two = (n) => String(n).padStart(2, '0');
  return { date: `${w.y}-${two(w.mo)}-${two(w.d)}`, time: `${two(w.h)}:${two(w.mi)}` };
}
export const addMinutes = (iso, m) => toIso(Date.parse(iso) + m * 60000);
export const minutesBetween = (a, b) => Math.round((Date.parse(b) - Date.parse(a)) / 60000);
