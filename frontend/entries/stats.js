// Statistics screen: Chart.js charts + the per-question DataTable.
import '../theme/index.js';
import '@tabler/core/dist/js/tabler.esm.js';
import { Chart, BarController, BarElement, CategoryScale, LinearScale, Legend, Tooltip } from 'chart.js';
import { get } from '../shared/api.js';
import { initAllTables } from '../shared/datatable.js';
import { initThemeToggle } from '../shared/theme-toggle.js';
import { initLiveBar } from '../shared/live-bar.js';
import { esc } from '../shared/ui.js';

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Legend, Tooltip);
initThemeToggle();
initLiveBar();
initAllTables();

const css = (v) => getComputedStyle(document.documentElement).getPropertyValue(v).trim();
const root = document.querySelector('[data-stats]');
if (root) {
  get(root.dataset.stats).then((d) => {
    const blue = css('--tblr-primary') || '#066fd1';
    const purple = css('--tblr-purple') || '#ae3ec9';
    const green = css('--tblr-green') || '#2fb344';
    const orange = css('--tblr-orange') || '#f76707';
    const text = css('--tblr-body-color');
    Chart.defaults.color = text || '#666';
    const names = d.results.map((r) => r.presenter_name);
    const opts = { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } };
    new Chart(root.querySelector('[data-chart="groups"]'), {
      type: 'bar',
      data: { labels: names, datasets: [
        { label: 'General', data: d.results.map((r) => r.avg_general), backgroundColor: blue },
        { label: 'Representatives', data: d.results.map((r) => r.avg_representative), backgroundColor: orange },
        { label: 'Judges', data: d.results.map((r) => r.avg_judge), backgroundColor: purple },
        { label: 'Final', data: d.results.map((r) => r.final_mark), backgroundColor: green },
      ] },
      options: { ...opts, scales: { y: { min: 0, max: 10 } } },
    });
    new Chart(root.querySelector('[data-chart="dist"]'), {
      type: 'bar',
      data: { labels: Object.keys(d.distribution), datasets: [
        { label: 'General', data: Object.values(d.distribution).map((x) => x.general), backgroundColor: blue },
        { label: 'Representatives', data: Object.values(d.distribution).map((x) => x.representative), backgroundColor: orange },
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
    const audience = Number(d.counts.general_reviewing) + Number(d.counts.representatives_reviewing);
    root.querySelector('[data-summary]').innerHTML = `
      <dl class="row mb-0">
        <dt class="col-7">Reviews</dt><dd class="col-5">${esc(d.counts.reviews)}</dd>
        <dt class="col-7">Users who checked in</dt><dd class="col-5">${esc(inUsers)}</dd>
        <dt class="col-7">Audience users who reviewed</dt><dd class="col-5">${esc(audience)}${inUsers ? ` (${Math.round((100 * audience) / inUsers)}%)` : ''}</dd>
        <dt class="col-7">Judges who checked in / reviewed</dt><dd class="col-5">${esc(inJudges)} / ${esc(d.counts.judges_reviewing)}</dd>
        <dt class="col-7">Reviewed every performance</dt><dd class="col-5">${esc(d.counts.complete_reviewers)}</dd>
        <dt class="col-7">Audience representatives</dt><dd class="col-5">${esc(d.counts.representatives)}</dd>
        <dt class="col-7">Performances opened</dt><dd class="col-5">${esc(d.counts.opened_performances)}</dd>
      </dl>`;
  }).catch((e) => { root.insertAdjacentHTML('afterbegin', `<div class="col-12"><div class="alert alert-danger">${esc(e.message)}</div></div>`); });
}
