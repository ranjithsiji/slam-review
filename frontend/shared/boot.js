export function bootData(id = 'boot') {
  const el = document.getElementById(id);
  if (!el) return {};
  try { return JSON.parse(el.textContent || '{}'); } catch { return {}; }
}
