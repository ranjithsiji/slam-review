// Small UI helpers (no framework): escaped HTML, toasts, confirm / reason dialogs.
import { Modal, Toast } from '@tabler/core/dist/js/tabler.esm.js';
import { iconId, spriteUrl } from './semantic.js';

export const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
export const iconHtml = (name, cls = '') => `<svg class="icon ${esc(cls)}" aria-hidden="true"><use href="${esc(spriteUrl())}#${esc(iconId(name))}"></use></svg>`;

let toastHost = null;
export function toast(message, type = 'success') {
  if (!toastHost) {
    toastHost = document.createElement('div');
    toastHost.className = 'toast-container position-fixed bottom-0 end-0 p-3';
    toastHost.style.zIndex = 1090;
    document.body.appendChild(toastHost);
  }
  const el = document.createElement('div');
  el.className = `toast align-items-center text-bg-${type === 'error' ? 'danger' : type} border-0`;
  el.setAttribute('role', type === 'error' ? 'alert' : 'status');
  el.setAttribute('aria-live', 'polite');
  el.innerHTML = `<div class="d-flex"><div class="toast-body">${esc(message)}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>`;
  toastHost.appendChild(el);
  const t = new Toast(el, { delay: type === 'error' ? 8000 : 4000 });
  el.addEventListener('hidden.bs.toast', () => el.remove());
  t.show();
}

let dialogEl = null;
/**
 * Promise-based dialog. Returns null if cancelled, '' for a confirm,
 * or the typed text when `reason` (or `input`) is requested.
 */
export function dialog({ title = 'Are you sure?', message = '', confirmText = 'Confirm', danger = false, reason = false, input = null, placeholder = '', required = true } = {}) {
  if (!dialogEl) {
    dialogEl = document.createElement('div');
    dialogEl.className = 'modal modal-blur fade';
    dialogEl.tabIndex = -1;
    dialogEl.setAttribute('aria-modal', 'true');
    dialogEl.setAttribute('role', 'dialog');
    document.body.appendChild(dialogEl);
  }
  const wantsText = reason || input;
  dialogEl.innerHTML = `
    <div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content">
      <div class="modal-body">
        <div class="modal-title">${esc(title)}</div>
        ${message ? `<div class="text-secondary mb-2">${esc(message)}</div>` : ''}
        ${wantsText ? `<input type="${input?.type || 'text'}" class="form-control" data-dialog-input placeholder="${esc(placeholder || (reason ? 'Reason (required)' : ''))}" value="${esc(input?.value || '')}" ${input?.min !== undefined ? `min="${esc(input.min)}"` : ''} ${input?.max !== undefined ? `max="${esc(input.max)}"` : ''}>
        <div class="invalid-feedback">This is required.</div>` : ''}
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-link link-secondary me-auto" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn ${danger ? 'btn-danger' : 'btn-primary'}" data-dialog-ok>${esc(confirmText)}</button>
      </div>
    </div></div>`;
  const modal = Modal.getOrCreateInstance(dialogEl);
  return new Promise((resolve) => {
    let result = null;
    const ok = dialogEl.querySelector('[data-dialog-ok]');
    const field = dialogEl.querySelector('[data-dialog-input]');
    const submit = () => {
      if (field) {
        const v = field.value.trim();
        if (required && v === '') { field.classList.add('is-invalid'); field.focus(); return; }
        result = v;
      } else {
        result = '';
      }
      modal.hide();
    };
    ok.addEventListener('click', submit);
    field?.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); submit(); } });
    dialogEl.addEventListener('shown.bs.modal', () => (field || ok).focus(), { once: true });
    dialogEl.addEventListener('hidden.bs.modal', () => resolve(result), { once: true });
    modal.show();
  });
}
