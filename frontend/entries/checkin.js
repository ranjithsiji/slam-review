// Public check-in pages: tiny, no framework.
import '../theme/index.js';
import { initThemeToggle } from '../shared/theme-toggle.js';

initThemeToggle();
const input = document.querySelector('[data-badge-input]');
if (input) {
  // Letters first (text keyboard), then digits: switch the keyboard after two letters.
  const sync = () => {
    input.value = input.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 7);
    input.inputMode = input.value.length >= 2 ? 'numeric' : 'text';
  };
  input.addEventListener('input', sync);
  sync();
}
document.querySelectorAll('form[data-once]').forEach((f) => f.addEventListener('submit', () => {
  f.querySelectorAll('button[type="submit"]').forEach((b) => { b.disabled = true; b.dataset.label = b.textContent; b.textContent = 'Checking in…'; });
}));
