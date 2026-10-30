// Settings page clock card: server time ticking in the display timezone and in
// UTC, a live preview of the timezone being chosen, and this device's drift.
import { HOUR12, TZ } from './clock.js';

export function initServerClock(box) {
  if (!box) return;
  const offset = Date.parse(box.dataset.serverClock) - Date.now();
  const select = document.querySelector('[data-tz-select]');
  const fmt = (zone) => new Intl.DateTimeFormat('en-IN', { timeZone: zone, hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: HOUR12 });
  const local = fmt(TZ);
  const utc = fmt('UTC');
  let preview = fmt(select?.value || TZ);
  select?.addEventListener('change', () => { preview = fmt(select.value); tick(); });
  const set = (k, v) => { const el = box.querySelector(`[data-clock="${k}"]`); if (el) el.textContent = v; };
  const abs = Math.abs(offset);
  set('drift', abs < 2000 ? 'in step with the server' : `${(abs / 1000).toFixed(0)} s ${offset > 0 ? 'behind' : 'ahead of'} the server (countdowns correct for this)`);
  function tick() {
    const t = new Date(Date.now() + offset);
    set('local', local.format(t));
    set('utc', utc.format(t));
    set('preview', preview.format(t));
  }
  tick();
  setInterval(tick, 1000);
}
