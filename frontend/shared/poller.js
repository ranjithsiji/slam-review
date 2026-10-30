// Live polling (see CLAUDE.md "Live polling design"):
// chained setTimeout, one request in flight, ±20 % jitter, ETag/304,
// pause while hidden (poll at once when visible), exponential back-off on errors,
// and an immediate (jittered 0–1.5 s) poll when a known deadline is reached.
import { ref } from 'vue';
import { csrfHeaders } from './api.js';
import { syncServerTime, serverNow } from './clock.js';

export function createPoller(url, { interval = 4000, onData, onAuthError, deadlines = () => [] } = {}) {
  const status = ref('idle'); // idle | ok | retrying | offline
  const lastOk = ref(null);
  let etag = null;
  let timer = null;
  let inFlight = false;
  let failures = 0;
  let stopped = false;
  let deadlineTimer = null;

  const jitter = (ms) => Math.round(ms * (0.8 + Math.random() * 0.4));

  function schedule(ms) {
    clearTimeout(timer);
    if (stopped || document.hidden) return;
    timer = setTimeout(tick, ms);
  }

  function armDeadline() {
    clearTimeout(deadlineTimer);
    const upcoming = (deadlines() || []).filter(Boolean).map((iso) => Date.parse(iso) - serverNow()).filter((ms) => ms > 0 && ms < 6 * 3600 * 1000);
    if (!upcoming.length) return;
    const ms = Math.min(...upcoming) + Math.round(Math.random() * 1500) + 300;
    deadlineTimer = setTimeout(() => { if (!document.hidden) tick(); }, ms);
  }

  async function tick() {
    if (inFlight || stopped) return;
    inFlight = true;
    try {
      const res = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', ...(etag ? { 'If-None-Match': etag } : {}), ...csrfHeaders() },
        cache: 'no-store',
      });
      if (res.status === 401 || res.status === 403) {
        stopped = true;
        let body = null;
        try { body = await res.json(); } catch {}
        onAuthError?.(body, res.status);
        return;
      }
      if (res.status === 304) {
        syncServerTime(res.headers.get('X-Server-Now'));
      } else if (res.ok) {
        etag = res.headers.get('ETag');
        const data = await res.json();
        syncServerTime(data.server_now);
        onData?.(data);
      } else {
        throw new Error(`HTTP ${res.status}`);
      }
      failures = 0;
      status.value = 'ok';
      lastOk.value = Date.now();
      armDeadline();
      schedule(jitter(interval));
    } catch (e) {
      failures++;
      status.value = failures >= 3 ? 'offline' : 'retrying';
      schedule(jitter(Math.min(30000, interval * 2 ** Math.min(failures, 3))));
    } finally {
      inFlight = false;
    }
  }

  function onVisibility() {
    if (document.hidden) { clearTimeout(timer); clearTimeout(deadlineTimer); } else { tick(); }
  }

  return {
    status,
    lastOk,
    start() { stopped = false; document.addEventListener('visibilitychange', onVisibility); tick(); },
    stop() { stopped = true; clearTimeout(timer); clearTimeout(deadlineTimer); document.removeEventListener('visibilitychange', onVisibility); },
    now() { clearTimeout(timer); tick(); },
    setInterval(ms) { interval = ms; },
  };
}
