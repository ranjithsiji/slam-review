// Shared DataTables wrapper (DataTables 3 + Bootstrap 5 + Responsive, no jQuery).
// Every table is server-side: POST /api/tables/{endpoint} with JSON + CSRF header.
// Output is rendered escaped; action buttons are built client-side from row ids.
import DataTable from 'datatables.net-responsive-bs5';
import 'datatables.net-bs5/css/dataTables.bootstrap5.min.css';
import 'datatables.net-responsive-bs5/css/responsive.bootstrap5.min.css';
import { post } from './api.js';
import { esc, iconHtml, toast, dialog } from './ui.js';
import { sem } from './semantic.js';
import { dateTime, time, date } from './clock.js';

const badge = (group, value, text) => {
  const s = sem(group, value);
  return `<span class="badge bg-${esc(s.color)}-lt">${iconHtml(s.icon, 'icon-inline me-1')}${esc(text || s.label)}</span>`;
};

export const renderers = {
  text: (v) => esc(v),
  ml: (v) => `<span class="ml-text">${esc(v)}</span>`,
  datetime: (v) => esc(dateTime(v)),
  // Marks are stored out of 10 and shown out of 100, to two decimals.
  number100: (v) => (v === null || v === undefined || v === '' ? '—' : (Math.round(Number(v) * 1000) / 100).toFixed(2)),
  time: (v) => esc(time(v)),
  date: (v) => esc(date(v)),
  number2: (v) => (v === null || v === undefined || v === '' ? '<span class="text-secondary">—</span>' : esc(Number(v).toFixed(2))),
  int: (v) => esc(v ?? 0),
  bool: (v) => (Number(v) ? `${iconHtml('status.ok', 'icon-inline text-success')}<span class="visually-hidden">Yes</span>` : `<span class="text-secondary">—</span>`),
  active: (v) => (Number(v) ? badge('checkin', 'checked_in', 'Active') : badge('checkin', 'checked_out', 'Inactive')),
  checkedin: (v) => (Number(v) ? badge('checkin', 'checked_in') : badge('checkin', 'never')),
  phase: (v) => badge('phase', v),
  slot: (v) => (v ? badge('slot', v) : '<span class="badge bg-orange-lt">In the pool, not scheduled</span>'),
  user_type: (v) => badge('user_type', v),
  outcome: (v) => `<span class="badge bg-${v === 'success' ? 'green' : v === 'denied' ? 'orange' : 'red'}-lt">${esc(v)}</span>`,
  thumb: (v) => (v ? `<img src="/${esc(v)}" alt="" class="avatar avatar-sm avatar-photo" loading="lazy">` : `<span class="avatar avatar-sm">${iconHtml('person.user')}</span>`),
  json: (v) => (v ? `<code class="small">${esc(JSON.stringify(v)).slice(0, 120)}</code>` : ''),
  status: (v) => `<span class="badge bg-secondary-lt">${esc(v)}</span>`,
};

const fill = (tpl, row) => tpl.replace(/\{(\w+)\}/g, (_, k) => encodeURIComponent(row[k] ?? ''));

function actionsHtml(actions, row) {
  const visible = actions.filter((a) => !a.when || (a.when.startsWith('!') ? !Number(row[a.when.slice(1)]) : Number(row[a.when])));
  if (!visible.length) return '';
  const items = visible.map((a, i) => (a.href
    ? `<a class="dropdown-item${a.danger ? ' text-danger' : ''}" href="${esc(fill(a.href, row))}">${a.icon ? iconHtml(a.icon, 'me-2') : ''}${esc(a.label)}</a>`
    : `<button type="button" class="dropdown-item${a.danger ? ' text-danger' : ''}" data-dt-action="${i}">${a.icon ? iconHtml(a.icon, 'me-2') : ''}${esc(a.label)}</button>`)).join('');
  return `<div class="dropdown"><button class="btn btn-icon btn-ghost-secondary" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-label="Actions" aria-expanded="false">${iconHtml('action.more')}</button><div class="dropdown-menu dropdown-menu-end">${items}</div></div>`;
}

/**
 * @param {HTMLTableElement} el
 * @param {object} cfg {endpoint, columns, filters, filterForm, actions, order, refresh, pii, onDraw}
 */
export function initTable(el, cfg) {
  const actions = cfg.actions || [];
  const fixed = cfg.filters || {};
  const form = cfg.filterForm ? document.querySelector(cfg.filterForm) : null;
  let lastJson = null;
  let errorBox = null;

  const formFilters = () => {
    if (!form) return {};
    const out = {};
    for (const [k, v] of new FormData(form).entries()) {
      if (v === '') continue;
      if (out[k] !== undefined) out[k] = [].concat(out[k], v); else out[k] = v;
    }
    form.querySelectorAll('select[multiple]').forEach((s) => {
      const vals = [...s.selectedOptions].map((o) => o.value).filter(Boolean);
      if (vals.length) out[s.name.replace(/\[\]$/, '')] = vals; else delete out[s.name.replace(/\[\]$/, '')];
      delete out[s.name];
    });
    return out;
  };

  const showError = (msg, retry) => {
    if (!errorBox) {
      errorBox = document.createElement('div');
      errorBox.className = 'alert alert-danger d-flex align-items-center gap-2 m-2';
      el.closest('.card, .table-responsive, div')?.prepend(errorBox);
    }
    errorBox.innerHTML = `${iconHtml('status.error')}<div class="flex-fill">${esc(msg)}</div><button type="button" class="btn btn-sm btn-danger">Retry</button>`;
    errorBox.querySelector('button').onclick = retry;
    errorBox.hidden = false;
  };

  const columns = cfg.columns.map((c) => ({
    data: c.data,
    title: c.title,
    orderable: c.orderable !== false,
    className: c.className || '',
    responsivePriority: c.priority ?? 100,
    defaultContent: '',
    render: (v, type, row) => (type === 'display' ? (renderers[c.render || 'text'] || renderers.text)(v, row) : (v ?? '')),
  }));
  if (actions.length) {
    // data: null — the action menu is built from the whole row, not from a field.
    columns.push({ data: null, defaultContent: '', title: '<span class="visually-hidden">Actions</span>', orderable: false, searchable: false, className: 'text-end', responsivePriority: 1, render: (_, type, row) => (type === 'display' ? actionsHtml(actions, row) : '') });
  }

  const narrow = window.matchMedia('(max-width: 575.98px)').matches;
  const dt = new DataTable(el, {
    serverSide: true,
    processing: true,
    responsive: true,
    autoWidth: false,
    pageLength: narrow ? 10 : 25,
    lengthMenu: [10, 25, 50, 100],
    order: cfg.order || [],
    searchDelay: 400,
    stateSave: true,
    stateDuration: 60 * 60 * 24 * 7,
    stateSaveParams: (s, data) => { if (cfg.pii !== false) data.search = { search: '' }; },
    columns,
    language: {
      emptyTable: cfg.empty || 'Nothing here yet.',
      zeroRecords: 'No matching rows.',
      processing: '<div class="spinner-border spinner-border-sm text-primary" role="status"></div> Loading…',
      search: '',
      searchPlaceholder: 'Search…',
    },
    ajax: (data, callback) => {
      if (data.search?.value && data.search.value.length === 1) data.search.value = '';
      const body = { ...data, filters: { ...fixed, ...formFilters() } };
      post(`/api/tables/${cfg.endpoint}`, body)
        .then((json) => {
          lastJson = json;
          if (errorBox) errorBox.hidden = true;
          callback(json);
        })
        .catch((e) => {
          showError(e.message || 'Could not load the table.', () => dt.ajax.reload(null, false));
          callback(lastJson ? { ...lastJson, draw: data.draw } : { draw: data.draw, recordsTotal: 0, recordsFiltered: 0, data: [] });
        });
    },
  });

  // Row actions (POST with optional confirm / reason).
  el.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-dt-action]');
    if (!btn) return;
    const tr = btn.closest('tr');
    let row = dt.row(tr).data();
    if (!row && tr?.classList.contains('child')) row = dt.row(tr.previousElementSibling).data();
    const a = actions[Number(btn.dataset.dtAction)];
    if (!a || !row) return;
    let reason = '';
    if (a.reason || a.confirm) {
      const r = await dialog({ title: a.label, message: a.confirm ? fill(a.confirm, row).replace(/%20/g, ' ') : '', danger: !!a.danger, reason: !!a.reason, input: a.reasonDefault ? { value: a.reasonDefault } : null, confirmText: a.label });
      if (r === null) return;
      reason = r;
    }
    try {
      const extra = Object.fromEntries(Object.entries(a.body || {}).map(([k, v]) => [k, typeof v === 'string' ? decodeURIComponent(fill(v, row)) : v]));
      const res = await post(fill(a.post, row), { ...extra, reason });
      toast(a.success || res?.message || 'Done.');
      if (a.reloadPage) setTimeout(() => window.location.reload(), 600);
      else dt.ajax.reload(null, false);
    } catch (e) {
      toast(e.message, 'error');
    }
  });

  if (form) {
    form.addEventListener('change', () => dt.ajax.reload());
    form.addEventListener('submit', (e) => { e.preventDefault(); dt.ajax.reload(); });
  }

  if (cfg.refresh) {
    let t = null;
    const loop = () => { t = setTimeout(() => { if (!document.hidden) dt.ajax.reload(null, false); loop(); }, cfg.refresh); };
    loop();
    document.addEventListener('visibilitychange', () => { if (!document.hidden) dt.ajax.reload(null, false); });
  }

  if (cfg.exportLink) {
    const link = document.querySelector(cfg.exportLink);
    link?.addEventListener('click', (e) => {
      e.preventDefault();
      const p = new URLSearchParams();
      const f = { ...fixed, ...formFilters() };
      Object.entries(f).forEach(([k, v]) => [].concat(v).forEach((x) => p.append(Array.isArray(v) ? `filters[${k}][]` : `filters[${k}]`, x)));
      p.set('search', dt.search());
      dt.order().forEach(([idx, dir], i) => { p.set(`order[${i}][name]`, columns[idx].data); p.set(`order[${i}][dir]`, dir); });
      window.location.href = `/admin/tables/${cfg.endpoint}/export.csv?${p}`;
    });
  }

  return dt;
}

/** Initialise every <table data-dt='{...}'> on the page. */
export function initAllTables(root = document) {
  const map = {};
  root.querySelectorAll('table[data-dt]').forEach((el) => {
    try {
      map[el.id || el.dataset.dtName || Math.random()] = initTable(el, JSON.parse(el.dataset.dt));
    } catch (e) {
      console.error('DataTable init failed', e);
    }
  });
  return map;
}
