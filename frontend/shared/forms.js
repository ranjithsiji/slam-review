// Progressive enhancements for server-rendered forms.
import { dialog, toast, esc } from './ui.js';
import { post, get } from './api.js';

export function initForms(root = document) {
  // <form data-confirm="Message" [data-reason="field_name"] [data-danger]>: confirm (and ask a reason) before submit.
  root.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', async (e) => {
      if (form.dataset.confirmed === '1') return;
      e.preventDefault();
      const reasonField = form.dataset.reason;
      const r = await dialog({
        title: form.dataset.title || 'Please confirm', message: form.dataset.confirm, danger: 'danger' in form.dataset,
        reason: !!reasonField, confirmText: form.dataset.ok || 'Confirm',
      });
      if (r === null) return;
      if (reasonField) {
        let input = form.querySelector(`input[name="${reasonField}"]`);
        if (!input) { input = document.createElement('input'); input.type = 'hidden'; input.name = reasonField; form.appendChild(input); }
        input.value = r;
      }
      form.dataset.confirmed = '1';
      form.requestSubmit ? form.requestSubmit() : form.submit();
    });
  });

  // <button data-api-post="/api/..." data-confirm="..." data-reason data-reload data-form="#id">: JSON POST action
  // (data-form adds that form's fields to the body).
  root.querySelectorAll('[data-api-post]').forEach((btn) => {
    const ownForm = btn.dataset.form ? document.querySelector(btn.dataset.form) : null;
    // Enter in the form runs the button instead of a page submit.
    ownForm?.addEventListener('submit', (ev) => { ev.preventDefault(); btn.click(); });
    btn.addEventListener('click', async () => {
      if (ownForm && !ownForm.reportValidity()) return;
      let reason = '';
      if (btn.dataset.confirm !== undefined || btn.dataset.reason !== undefined) {
        const r = await dialog({ title: btn.dataset.title || btn.textContent.trim(), message: btn.dataset.confirm || '', danger: 'danger' in btn.dataset, reason: btn.dataset.reason !== undefined });
        if (r === null) return;
        reason = r;
      }
      try {
        const extra = btn.dataset.body ? JSON.parse(btn.dataset.body) : {};
        const fields = ownForm ? Object.fromEntries(new FormData(ownForm).entries()) : {};
        const res = await post(btn.dataset.apiPost, { reason, ...fields, ...extra });
        toast(btn.dataset.success || res?.message || 'Done.');
        if ('reload' in btn.dataset) setTimeout(() => window.location.reload(), 600);
      } catch (e) {
        toast(e.message, 'error');
      }
    });
  });

  // <select data-nav-select>: choosing an option opens its URL (slam switcher).
  root.querySelectorAll('select[data-nav-select]').forEach((sel) => {
    sel.addEventListener('change', () => { if (sel.value) window.location.href = sel.value; });
  });

  // <button data-copy="text">: copy to clipboard.
  root.querySelectorAll('[data-copy]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      try { await navigator.clipboard.writeText(btn.dataset.copy); toast('Copied.'); } catch { toast('Copy failed — select and copy manually.', 'error'); }
    });
  });

  // Character counters: <textarea data-maxlength="2000">.
  root.querySelectorAll('[data-maxlength]').forEach((el) => {
    const max = Number(el.dataset.maxlength);
    const hint = document.createElement('div');
    hint.className = 'form-hint text-end';
    el.after(hint);
    const upd = () => { const n = [...el.value].length; hint.textContent = `${n} / ${max}`; hint.classList.toggle('text-danger', n > max); };
    el.addEventListener('input', upd);
    upd();
  });

  // <select data-autosubmit>: submit its form on change (no inline handlers under the CSP).
  root.querySelectorAll('[data-autosubmit]').forEach((el) => el.addEventListener('change', () => el.form?.submit()));

  // QR screen: single-attendee search, preview, downloads and hand-picking for the badge PDF.
  const qrInput = root.querySelector('[data-qr-search]');
  if (qrInput) {
    const out = root.querySelector('[data-qr-results]');
    const pickedInput = root.querySelector('[data-qr-picked]');
    const pickedLabel = root.querySelector('[data-qr-picked-label]');
    const picked = new Map();
    const showPicked = () => {
      pickedInput.value = [...picked.keys()].join(',');
      pickedLabel.textContent = picked.size ? `Hand-picked: ${[...picked.values()].join(', ')}` : '';
    };
    let t = null;
    qrInput.addEventListener('input', () => {
      clearTimeout(t);
      t = setTimeout(async () => {
        const q = qrInput.value.trim();
        if (q.length < 2) { out.innerHTML = ''; return; }
        try {
          const res = await get(`${qrInput.dataset.qrSearch}&q=${encodeURIComponent(q)}`);
          out.innerHTML = res.data.length ? '' : '<div class="text-secondary p-2">No match.</div>';
          res.data.forEach((a) => {
            const row = document.createElement('div');
            row.className = 'list-group-item';
            row.innerHTML = `<div class="d-flex gap-3 align-items-center">
                <div class="qr-box" style="width:84px"><img src="/admin/qr/${a.id}.svg?inline=1" alt=""></div>
                <div class="flex-fill"><div class="fw-bold ml-text">${esc(a.name)}</div><div><code>${esc(a.badge_id)}</code> · ${esc(a.user_type)} · printed ${esc(a.badge_print_count)}×</div>
                <div class="btn-list mt-1"><a class="btn btn-sm" href="/admin/qr/${a.id}.svg">SVG</a><a class="btn btn-sm" href="/admin/qr/${a.id}.png">PNG</a>
                <button type="button" class="btn btn-sm" data-c>Copy link</button><button type="button" class="btn btn-sm" data-p>Pick</button></div></div></div>`;
            row.querySelector('[data-c]').onclick = () => navigator.clipboard?.writeText(a.url).then(() => toast('Copied.'));
            row.querySelector('[data-p]').onclick = () => { picked.set(a.id, a.name); showPicked(); const f = root.querySelector('#filter'); if (f) f.value = 'selected'; };
            out.appendChild(row);
          });
        } catch (e) { toast(e.message, 'error'); }
      }, 350);
    });
  }

  root.querySelectorAll('[data-print]').forEach((b) => b.addEventListener('click', () => window.print()));

  // Uppercase badge-ID inputs.
  root.querySelectorAll('[data-uppercase]').forEach((el) => el.addEventListener('input', () => {
    const p = el.selectionStart;
    el.value = el.value.toUpperCase().replace(/\s+/g, '');
    try { el.setSelectionRange(p, p); } catch {}
  }));
}
