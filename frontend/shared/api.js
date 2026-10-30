// Fetch wrapper: JSON in/out, CSRF header from <meta>, same-origin cookies,
// domain errors surfaced as ApiError with the server's message.
export class ApiError extends Error {
  constructor(message, status, body) {
    super(message);
    this.status = status;
    this.body = body || {};
    this.reason = this.body.reason || null;
    this.details = this.body.details || null;
  }
}

const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.getAttribute('content') || '';

export function csrfHeaders() {
  const h = meta('csrf-header');
  return h ? { [h]: meta('csrf-token') } : {};
}

export async function api(url, { method = 'GET', body, headers = {}, signal, raw = false } = {}) {
  const isForm = body instanceof FormData;
  const res = await fetch(url, {
    method,
    credentials: 'same-origin',
    signal,
    headers: {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...(body !== undefined && !isForm ? { 'Content-Type': 'application/json' } : {}),
      ...(method !== 'GET' ? csrfHeaders() : {}),
      ...headers,
    },
    body: body === undefined ? undefined : isForm ? body : JSON.stringify(body),
  });
  if (raw) return res;
  let data = null;
  const text = await res.text();
  try { data = text ? JSON.parse(text) : null; } catch { data = null; }
  if (!res.ok) {
    throw new ApiError(data?.error || `Request failed (${res.status})`, res.status, data);
  }
  return data;
}

export const get = (url, opts) => api(url, { ...opts, method: 'GET' });
export const post = (url, body, opts) => api(url, { ...opts, method: 'POST', body: body ?? {} });
export const put = (url, body, opts) => api(url, { ...opts, method: 'PUT', body: body ?? {} });
export const del = (url, opts) => api(url, { ...opts, method: 'DELETE' });
