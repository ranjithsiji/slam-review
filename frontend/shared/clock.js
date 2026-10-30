// Server-time based clock. Clients never trust their own clock for deadlines:
// we keep an offset from the server's `server_now` and run countdowns locally.
import { ref } from 'vue';

const TZ = 'Asia/Kolkata';
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
const timeF = fmt({ hour: '2-digit', minute: '2-digit', hour12: false });
const dateF = fmt({ day: 'numeric', month: 'short', year: 'numeric' });
const dateTimeF = fmt({ day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', hour12: false });
const longDateF = fmt({ weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

export const time = (iso) => (iso ? timeF.format(new Date(iso)) : '');
export const date = (iso) => (iso ? dateF.format(new Date(iso)) : '');
export const dateTime = (iso) => (iso ? dateTimeF.format(new Date(iso)) : '');
export const longDate = (iso) => (iso ? longDateF.format(new Date(iso)) : '');

/** IST date (YYYY-MM-DD) and time (HH:MM) → ISO UTC. IST has no DST. */
export const istToIso = (d, t) => new Date(`${d}T${t}:00+05:30`).toISOString().replace(/\.\d{3}Z$/, 'Z');
/** ISO UTC → IST parts for <input type="date"/"time">. */
export function isoToIst(iso) {
  if (!iso) return { date: '', time: '' };
  const d = new Date(Date.parse(iso) + 5.5 * 3600 * 1000);
  const p = d.toISOString();
  return { date: p.slice(0, 10), time: p.slice(11, 16) };
}
export const addMinutes = (iso, m) => new Date(Date.parse(iso) + m * 60000).toISOString().replace(/\.\d{3}Z$/, 'Z');
export const minutesBetween = (a, b) => Math.round((Date.parse(b) - Date.parse(a)) / 60000);
