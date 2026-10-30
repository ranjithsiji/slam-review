// Applies the saved/OS colour scheme before first paint (no flash). Non-module, tiny.
(function () {
  var t = null;
  try { t = localStorage.getItem('slam-theme'); } catch (e) {}
  if (t !== 'light' && t !== 'dark') {
    t = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  document.documentElement.setAttribute('data-bs-theme', t);
})();
