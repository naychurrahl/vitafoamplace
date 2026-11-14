// Shop filters: still a plain GET form; with JS they apply on change and swap in the results without a full reload.
export function initFilters() {
  const form = document.querySelector('[data-filter-form]');
  if (!form) return;
  const panel = document.querySelector('[data-filters]');
  document.querySelector('[data-filters-open]')?.addEventListener('click', () => panel.classList.add('open'));
  document.querySelector('[data-filters-close]')?.addEventListener('click', () => panel.classList.remove('open'));

  const mobile = () => matchMedia('(max-width: 900px)').matches;
  let ctrl;
  const load = async (href, push = true) => {
    ctrl?.abort();
    ctrl = new AbortController();
    const results = document.querySelector('[data-results]');
    results.classList.add('loading');
    try {
      const html = await (await fetch(href, { signal: ctrl.signal })).text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      results.replaceWith(doc.querySelector('[data-results]'));
      document.querySelector('[data-filters]').querySelector('.filters-head').replaceWith(doc.querySelector('.filters-head'));
      document.querySelector('[data-filters-close]')?.addEventListener('click', () => panel.classList.remove('open'));
      document.querySelector('[data-filters-open]')?.addEventListener('click', () => panel.classList.add('open'));
      document.querySelectorAll('[data-autosubmit]').forEach((s) => s.addEventListener('change', () => s.form.requestSubmit()));
      if (push) history.pushState(null, '', href);
      document.dispatchEvent(new CustomEvent('cards:updated'));
    } catch (e) {
      if (e.name !== 'AbortError') location.href = href;
    }
  };
  const hrefFor = (f) => {
    const params = new URLSearchParams();
    for (const [k, v] of new FormData(f)) if (v !== '') params.append(k, v);
    return f.action + (params.toString() ? '?' + params : '');
  };

  let t;
  form.addEventListener('change', () => {
    if (mobile()) return; // on phones, apply with the button so the panel doesn't jump
    clearTimeout(t);
    t = setTimeout(() => load(hrefFor(form)), 250);
  });
  form.addEventListener('submit', (e) => { e.preventDefault(); panel.classList.remove('open'); load(hrefFor(form)); });
  document.addEventListener('submit', (e) => {
    if (!e.target.matches('[data-sort-form]')) return;
    e.preventDefault();
    load(hrefFor(e.target));
  });
  addEventListener('popstate', () => load(location.href, false));
}
