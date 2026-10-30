// Public header: the phone menu button (no framework needed on the tiny check-in pages).
export function initSiteMenu() {
  const btn = document.querySelector('[data-site-menu-toggle]');
  const menu = document.getElementById('site-menu');
  if (!btn || !menu) return;
  btn.addEventListener('click', () => {
    const open = menu.classList.toggle('show');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
}
