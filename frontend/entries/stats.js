// Statistics screen: Chart.js charts + the per-question DataTable.
import '../theme/index.js';
import '@tabler/core/dist/js/tabler.esm.js';
import { Chart, BarController, BarElement, CategoryScale, LinearScale, Legend, Tooltip } from 'chart.js';
import { get } from '../shared/api.js';
import { initAllTables } from '../shared/datatable.js';
import { initThemeToggle } from '../shared/theme-toggle.js';
import { esc } from '../shared/ui.js';

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Legend, Tooltip);
initThemeToggle();
initAllTables();

const css = (v) => getComputedStyle(document.documentElement).getPropertyValue(v).trim();
const root = document.querySelector('[data-stats]');
if (root) {
  get(root.dataset.stats).then((d) => {
    const blue = css('--tblr-primary') || '#066fd1';
    const purple = css('--tblr-purple') || '#ae3ec9';
    const green = css('--tblr-green') || '#2fb344';
    const text = css('--tblr-body-color');
    Chart.defaults.color = text || '#666';
    const names = d.results.map((r) => r.presenter_name);
    const opts = { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } };
    new Chart(root.querySelector('[data-chart="groups"]'), {
      type: 'bar',
      data: { labels: names, datasets: [
        { label: 'Users', data: d.results.map((r) => r.avg_user), backgroundColor: blue },
        { label: 'Judges', data: d.results.map((r) => r.avg_judge), backgroundColor: purple },
        { label: 'Final', data: d.results.map((r) => r.final_mark), backgroundColor: green },
      ] },
      options: { ...opts, scales: { y: { min: 0, max: 10 } } },
    });
    new Chart(root.querySelector('[data-chart="dist"]'), {
      type: 'bar',
      data: { labels: Object.keys(d.distribution), datasets: [
        { label: 'Users', data: Object.values(d.distribution).map((x) => x.user), backgroundColor: blue },
        { label: 'Judges', data: Object.values(d.distribution).map((x) => x.judge), backgroundColor: purple },
      ] },
      options: opts,
    });
    new Chart(root.querySelector('[data-chart="participation"]'), {
      type: 'bar',
      data: { labels: d.participation.map((p) => p.name), datasets: [{ label: 'Reviews', data: d.participation.map((p) => Number(p.reviews)), backgroundColor: blue }] },
      options: { ...opts, indexAxis: 'y', plugins: { legend: { display: false } } },
    });
    const inUsers = Number(d.checked_in.user || 0);
    const inJudges = Number(d.checked_in.judge || 0);
    root.querySelector('[data-summary]').innerHTML = `
      <dl class="row mb-0">
        <dt class="col-7">Reviews</dt><dd class="col-5">${esc(d.counts.reviews)}</dd>
        <dt class="col-7">Users who checked in</dt><dd class="col-5">${esc(inUsers)}</dd>
        <dt class="col-7">Users who reviewed</dt><dd class="col-5">${esc(d.counts.users_reviewing)}${inUsers ? ` (${Math.round((100 * d.counts.users_reviewing) / inUsers)}%)` : ''}</dd>
        <dt class="col-7">Judges who checked in / reviewed</dt><dd class="col-5">${esc(inJudges)} / ${esc(d.counts.judges_reviewing)}</dd>
        <dt class="col-7">Complete reviewers</dt><dd class="col-5">${esc(d.counts.complete_reviewers)}</dd>
        <dt class="col-7">Performances opened</dt><dd class="col-5">${esc(d.counts.opened_performances)}</dd>
      </dl>`;
  }).catch((e) => { root.insertAdjacentHTML('afterbegin', `<div class="col-12"><div class="alert alert-danger">${esc(e.message)}</div></div>`); });
}
