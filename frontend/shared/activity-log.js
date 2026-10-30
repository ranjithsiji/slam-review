// Activity log page: row → detail drawer, local date range → UTC filters, live tail.
import { Offcanvas } from '@tabler/core/dist/js/tabler.esm.js';
import { localToIso } from './clock.js';

export function initActivityLog(dt) {
  const table = document.getElementById('activity-log');
  if (!table || !dt) return;
  const drawer = document.getElementById('log-detail');
  const pre = drawer?.querySelector('[data-log-detail]');
  table.addEventListener('click', (e) => {
    if (e.target.closest('a,button')) return;
    const tr = e.target.closest('tbody tr');
    const row = tr && dt.row(tr).data();
    if (!row || !pre) return;
    pre.textContent = JSON.stringify(row, null, 2);
    Offcanvas.getOrCreateInstance(drawer).show();
  });
  table.querySelector('tbody')?.style.setProperty('cursor', 'pointer');

  const form = document.getElementById('al-filters');
  form?.querySelectorAll('[data-local-to]').forEach((input) => input.addEventListener('change', () => {
    const hidden = form.querySelector(`input[name="${input.dataset.localTo}"]`);
    hidden.value = input.value ? localToIso(input.value.slice(0, 10), input.value.slice(11, 16)) : '';
    form.dispatchEvent(new Event('change'));
  }));

  let tail = null;
  form?.querySelector('[data-live-tail]')?.addEventListener('change', (e) => {
    clearInterval(tail);
    if (e.target.checked) {
      dt.order([0, 'desc']);
      tail = setInterval(() => { if (!document.hidden) dt.ajax.reload(null, false); }, 5000);
      dt.ajax.reload(null, false);
    }
  });
}
