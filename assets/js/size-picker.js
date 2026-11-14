// Product page: picking a size updates price, stock, max load, the qty limit and the WhatsApp message.
import { naira } from './lib.js';

export function initSizePicker() {
  const root = document.querySelector('[data-product]');
  if (!root) return;
  const data = JSON.parse(root.dataset.product);
  const $ = (s) => root.querySelector(s);

  const update = (id) => {
    const v = data.variants.find((x) => x.id === +id);
    if (!v) return;
    $('[data-price]').textContent = naira(v.price);
    const cmp = $('[data-compare]'), save = $('[data-save]');
    cmp.hidden = save.hidden = !v.compare_at_price;
    if (v.compare_at_price) { cmp.textContent = naira(v.compare_at_price); save.textContent = 'Save ' + naira(v.compare_at_price - v.price); }
    const stock = $('[data-stock]');
    const load = v.max_load_kg ? `<span>Up to ${v.max_load_kg}kg per sleeper</span>` : '';
    stock.innerHTML = (v.in_stock ? `<span class="ok">✓ In stock${v.stock <= 2 ? ' · only ' + v.stock + ' left' : ''}</span>` : '<span class="bad">Out of stock in this size</span>') + load;
    const qty = $('[name=qty]');
    qty.max = Math.max(1, v.stock);
    if (+qty.value > v.stock) qty.value = Math.max(1, v.stock);
    $('[data-add]').disabled = !v.in_stock;
    const wa = $('[data-wa-order]');
    const text = `Hello ${data.store}, I'd like to order:\n${data.name} (${v.size_label}) — ${naira(v.price)}\n${location.origin}${location.pathname}`;
    wa.href = wa.href.split('?')[0] + '?text=' + encodeURIComponent(text);
    const u = new URL(location.href);
    u.searchParams.set('size', v.slug || v.id);
    history.replaceState(null, '', u);
  };

  root.addEventListener('change', (e) => { if (e.target.name === 'variant_id') update(e.target.value); });
}
