// Light/dark toggle for any [data-theme-toggle] button; remembered per device.
export function initThemeToggle(root = document) {
  root.querySelectorAll('[data-theme-toggle]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-bs-theme', next);
      try { localStorage.setItem('slam-theme', next); } catch {}
    });
  });
}
