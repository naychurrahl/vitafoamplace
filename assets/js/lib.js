// Shared helpers for the page scripts. No side effects: importing this never runs anything.
// (Keep it that way. app.js is loaded as app.js?v=…, so any module importing './app.js' would
// load a second copy of it and attach every handler twice.)

const base = document.querySelector('meta[name=base]')?.content ?? '';
const csrf = document.querySelector('meta[name=csrf]')?.content ?? '';

export const naira = (n) => '₦' + Math.round(n).toLocaleString('en-NG');
export const url = (p) => base + p;

export async function api(path, { method = 'GET', body, query } = {}) {
  const qs = query ? '?' + new URLSearchParams(query) : '';
  const res = await fetch(url('/_api' + path + qs), {
    method, headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf }, body: body ? JSON.stringify(body) : undefined, credentials: 'same-origin',
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw Object.assign(new Error(data.error || 'Something went wrong'), { status: res.status, data });
  return data;
}

let toastTimer;
export function toast(html, type = '') {
  const t = document.querySelector('.toast');
  t.innerHTML = html;
  t.className = 'toast ' + type;
  t.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => (t.hidden = true), 4200);
}

export function setCartCount(n) {
  document.querySelectorAll('[data-cart-count]').forEach((b) => { b.textContent = n; b.hidden = !n; });
}
