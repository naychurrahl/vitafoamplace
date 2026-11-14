// Wishlist in localStorage (no login needed). /wishlist renders it from /_api/products?ids=
import { api, toast, url } from './lib.js';

const KEY = 'rw_wishlist';
const read = () => { try { return JSON.parse(localStorage.getItem(KEY)) || []; } catch { return []; } };
const write = (ids) => { try { localStorage.setItem(KEY, JSON.stringify(ids)); } catch {} };

function paint() {
  const ids = read();
  document.querySelectorAll('[data-wish]').forEach((b) => {
    const on = ids.includes(+b.dataset.wish);
    b.setAttribute('aria-pressed', on);
    b.querySelector('use')?.setAttribute('href', on ? '#i-heart-fill' : '#i-heart');
  });
  document.querySelectorAll('[data-wish-count]').forEach((b) => { b.textContent = ids.length; b.hidden = !ids.length; });
}

export function initWishlist() {
  paint();
  document.addEventListener('cards:updated', paint);
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-wish]');
    if (!b) return;
    e.preventDefault();
    const id = +b.dataset.wish;
    const ids = read();
    const had = ids.includes(id);
    write(had ? ids.filter((x) => x !== id) : [...ids, id]);
    paint();
    if (!had) toast(`Saved · <a href="${url('/wishlist')}">View wishlist</a>`);
    if (had && document.querySelector('[data-wishlist-page]')) renderPage();
  });
  if (document.querySelector('[data-wishlist-page]')) renderPage();
}

async function renderPage() {
  const grid = document.querySelector('[data-wishlist-grid]');
  const empty = document.querySelector('[data-wishlist-empty]');
  const ids = read();
  if (!ids.length) { grid.innerHTML = ''; empty.hidden = false; return; }
  // Render the server's own card markup by loading the shop with ids=, so cards match everywhere.
  const html = await (await fetch(url('/shop?ids=' + ids.join(',')))).text().catch(() => '');
  const doc = new DOMParser().parseFromString(html, 'text/html');
  const cards = doc.querySelectorAll('[data-results] .card');
  grid.replaceChildren(...cards);
  empty.hidden = cards.length > 0;
  paint();
}
