// Progressive enhancement only: every page works without this file.
import { initCart } from './cart.js';
import { initSizePicker } from './size-picker.js';
import { initFilters } from './filters.js';
import { initWishlist } from './wishlist.js';
import { initCheckout } from './checkout.js';
import { api, naira, url } from './lib.js';

document.documentElement.classList.add('js');

function initNav() {
  const btn = document.querySelector('[data-nav-toggle]');
  const nav = document.querySelector('[data-nav]');
  btn?.addEventListener('click', () => {
    const open = nav.classList.toggle('open');
    btn.setAttribute('aria-expanded', open);
  });
}

function initSearch() {
  const form = document.querySelector('[data-search]');
  if (!form) return;
  const input = form.querySelector('input');
  const box = form.querySelector('.suggest');
  let timer, last = '';
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  input.addEventListener('input', () => {
    clearTimeout(timer);
    const q = input.value.trim();
    if (q.length < 2) { box.hidden = true; return; }
    timer = setTimeout(async () => {
      if (q === last) return;
      last = q;
      try {
        const r = await api('/search/suggest', { query: { q } });
        let html = '';
        if (r.products.length) html += '<div class="grp">Products</div>' + r.products.map((p) => `<a href="${url('/product/' + p.slug)}"><span>${esc(p.name)} <span class="muted small">${esc(p.brand)}</span></span><span class="muted">${naira(p.price_from)}</span></a>`).join('');
        if (r.categories.length) html += '<div class="grp">Categories</div>' + r.categories.map((c) => `<a href="${url('/' + c.slug)}">${esc(c.name)}</a>`).join('');
        if (r.brands.length) html += '<div class="grp">Brands</div>' + r.brands.map((b) => `<a href="${url('/brands/' + b.slug)}">${esc(b.name)}</a>`).join('');
        html += `<a href="${url('/search?q=' + encodeURIComponent(q))}"><strong>See all results for “${esc(q)}”</strong></a>`;
        box.innerHTML = html;
        box.hidden = false;
      } catch { box.hidden = true; }
    }, 180);
  });
  document.addEventListener('click', (e) => { if (!form.contains(e.target)) box.hidden = true; });
  input.addEventListener('keydown', (e) => { if (e.key === 'Escape') box.hidden = true; });
}

function initTabs() {
  document.querySelectorAll('[data-tabs]').forEach((wrap) => {
    const tabs = [...wrap.querySelectorAll('[role=tab]')];
    const show = (tab) => {
      tabs.forEach((t) => {
        const on = t === tab;
        t.setAttribute('aria-selected', on);
        document.getElementById(t.getAttribute('aria-controls')).classList.toggle('active', on);
      });
    };
    tabs.forEach((t) => t.addEventListener('click', () => show(t)));
    show(tabs.find((t) => location.hash && '#' + t.getAttribute('aria-controls') === location.hash) || tabs[0]);
    document.querySelectorAll('a[href="#reviews"]').forEach((a) => a.addEventListener('click', () => show(tabs.find((t) => t.getAttribute('aria-controls') === 'reviews'))));
  });
}

function initQtySteppers() {
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-qty] [data-step]');
    if (!b) return;
    const input = b.parentElement.querySelector('input');
    const max = input.max ? +input.max : Infinity;
    const v = Math.max(+input.min || 0, Math.min(max, (+input.value || 0) + +b.dataset.step));
    if (v === +input.value) return;
    input.value = v;
    input.dispatchEvent(new Event('change', { bubbles: true }));
  });
}

function initCountdown() {
  const el = document.querySelector('[data-countdown]');
  if (!el) return;
  const cutoff = el.closest('[data-cutoff]').dataset.cutoff; // HH:MM, Lagos time
  const tick = () => {
    const now = new Date(new Date().toLocaleString('en-US', { timeZone: 'Africa/Lagos' }));
    const [h, m] = cutoff.split(':').map(Number);
    const end = new Date(now); end.setHours(h, m, 0, 0);
    const left = end - now;
    if (left <= 0) { el.innerHTML = 'Order now for delivery <strong>tomorrow</strong>'; return; }
    const hh = Math.floor(left / 3.6e6), mm = Math.floor((left % 3.6e6) / 6e4);
    el.innerHTML = `Order in the next <strong>${hh ? hh + 'h ' : ''}${mm}m</strong> for same-day delivery`;
  };
  tick();
  setInterval(tick, 30000);
}

// Loading feedback: a bar along the top while the next page loads, a spinner on the button
// that submitted, and a second submit of the same form is ignored until the page changes.
const bar = Object.assign(document.createElement('div'), { className: 'loadbar', hidden: true });
let safety;
function startLoading() {
  bar.hidden = false;
  bar.classList.remove('run');
  void bar.offsetWidth; // restart the animation
  bar.classList.add('run');
  document.body.setAttribute('aria-busy', 'true');
  clearTimeout(safety);
  safety = setTimeout(stopLoading, 20000); // never spin forever
}
function stopLoading() {
  bar.hidden = true;
  bar.classList.remove('run');
  document.body.removeAttribute('aria-busy');
  document.querySelectorAll('.is-busy').forEach((b) => b.classList.remove('is-busy'));
  document.querySelectorAll('form[data-busy]').forEach((f) => delete f.dataset.busy);
}

function initLoading() {
  bar.setAttribute('role', 'progressbar');
  bar.setAttribute('aria-label', 'Loading');
  document.body.append(bar);
  window.addEventListener('pageshow', stopLoading); // back/forward cache restores the old page as it was

  document.addEventListener('click', (e) => {
    const a = e.target.closest('a[href]');
    if (!a || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || a.target || a.hasAttribute('download')) return;
    const u = new URL(a.href, location.href);
    if (u.origin !== location.origin || /\.(csv|pdf|xml|txt)$/i.test(u.pathname)) return;
    if (u.hash && u.pathname === location.pathname && u.search === location.search) return;
    setTimeout(() => { if (!e.defaultPrevented) startLoading(); });
  });

  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (form.dataset.busy) { e.preventDefault(); return; }
    setTimeout(() => { // after page handlers (validation, add-to-cart via fetch) have had their say
      if (e.defaultPrevented || form.target === '_blank') return;
      form.dataset.busy = '1';
      (e.submitter || form.querySelector('button:not([type=button])'))?.classList.add('is-busy');
      startLoading();
    });
  });
}

// Sign in without reloading, so a wrong password doesn't wipe the field.
// (The server never echoes a password back into the page.)
function initAjaxLogin() {
  const form = document.querySelector('form[data-ajax-login]');
  form?.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (form.dataset.busy) return;
    form.dataset.busy = '1';
    const btn = form.querySelector('button:not([type=button])');
    const label = btn.textContent;
    btn.classList.add('is-busy');
    btn.textContent = 'Signing in…';
    const errBox = document.querySelector('[data-login-error]');
    let msg;
    try {
      const res = await fetch(form.getAttribute('action') || location.href, { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
      if (res.redirected && new URL(res.url).pathname !== new URL(form.getAttribute('action') || location.href, location.href).pathname) {
        startLoading();
        location.assign(res.url);
        return;
      }
      const html = await res.text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      msg = doc.querySelector('[data-login-error]')?.textContent.trim() || (res.ok ? 'Something went wrong. Try again.' : doc.body.textContent.trim());
    } catch {
      msg = "Couldn't reach the server. Check your connection and try again.";
    }
    errBox.textContent = msg;
    errBox.hidden = false;
    btn.classList.remove('is-busy');
    btn.textContent = label;
    delete form.dataset.busy;
    form.querySelector('[name=password]').focus();
  });
}

function initPasswordToggles() {
  document.querySelectorAll('input[type=password]').forEach((input) => {
    const wrap = document.createElement('span');
    wrap.className = 'pw';
    input.replaceWith(wrap);
    const btn = Object.assign(document.createElement('button'), { type: 'button', className: 'pw-toggle', textContent: 'Show' });
    btn.setAttribute('aria-label', 'Show password');
    btn.setAttribute('aria-pressed', 'false');
    btn.addEventListener('click', () => {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.textContent = show ? 'Hide' : 'Show';
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      btn.setAttribute('aria-pressed', String(show));
      input.focus();
    });
    wrap.append(input, btn);
  });
}

function initMisc() {
  document.querySelectorAll('[data-autosubmit]').forEach((s) => s.addEventListener('change', () => s.form.requestSubmit()));
  const wa = document.querySelector('[data-autoopen]');
  if (wa) window.open(wa.href, '_blank', 'noopener');
  const send = document.querySelector('[data-send-wa]');
  send?.addEventListener('click', () => {
    const f = send.form;
    const text = `Hello, I'm ${f.name.value}${f.phone.value ? ' (' + f.phone.value + ')' : ''}.\n${f.subject.value ? f.subject.value + ': ' : ''}${f.message.value}`;
    window.open(`https://wa.me/${send.dataset.sendWa}?text=${encodeURIComponent(text)}`, '_blank', 'noopener');
  });
}

initNav();
initSearch();
initTabs();
initQtySteppers();
initCountdown();
initMisc();
initPasswordToggles();
initLoading();
initAjaxLogin();
initCart();
initSizePicker();
initFilters();
initWishlist();
initCheckout();
