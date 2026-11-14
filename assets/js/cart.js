// Add to cart without leaving the page, and live quantity changes on /cart.
import { api, toast, setCartCount, naira, url } from './lib.js';

export function initCart() {
  const add = document.querySelector('[data-add-form]');
  add?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(add);
    const btn = add.querySelector('[data-add]');
    if (!fd.get('variant_id')) { toast('Choose a size first', 'err'); return; }
    btn.disabled = true;
    btn.classList.add('is-busy');
    try {
      const cart = await api('/cart/items', { method: 'POST', body: { variant_id: +fd.get('variant_id'), qty: +fd.get('qty') || 1 } });
      setCartCount(cart.count);
      toast(`Added to cart · <a href="${url('/cart')}">View cart (${cart.count})</a> · <a href="${url('/checkout')}">Checkout</a>`);
    } catch (err) {
      toast(err.message, 'err');
    } finally {
      btn.disabled = false;
      btn.classList.remove('is-busy');
    }
  });

  const lines = document.querySelector('[data-cart]');
  if (!lines) return;
  const refresh = (cart) => {
    setCartCount(cart.count);
    if (!cart.items.length) { location.reload(); return; }
    document.querySelector('[data-subtotal]').textContent = naira(cart.subtotal);
    document.querySelector('[data-cart-items]').textContent = cart.count;
    lines.querySelectorAll('[data-line]').forEach((row) => {
      const item = cart.items.find((i) => i.id === +row.dataset.line);
      if (!item) { row.remove(); return; }
      row.querySelector('[data-line-total]').textContent = naira(item.line_total);
    });
  };
  lines.addEventListener('change', async (e) => {
    const form = e.target.closest('[data-qty-form]');
    if (!form) return;
    const id = form.querySelector('[name=item]').value;
    try {
      refresh(await api('/cart/items/' + id, { method: 'PUT', body: { qty: +e.target.value } }));
    } catch (err) {
      toast(err.message, 'err');
    }
  });
  lines.addEventListener('submit', async (e) => {
    const form = e.target.closest('[data-remove-form]');
    if (!form) return;
    e.preventDefault();
    try {
      refresh(await api('/cart/items/' + form.querySelector('[name=item]').value, { method: 'DELETE' }));
    } catch (err) {
      toast(err.message, 'err');
    }
  });
}
