// Checkout: switch delivery/pickup, live delivery fee and totals, coupon, phone check.
import { api, naira } from './lib.js';

export function initCheckout() {
  const form = document.querySelector('[data-checkout]');
  if (!form) return;
  const $ = (s) => form.querySelector(s);
  const q = (k) => form.querySelector(`[data-q="${k}"]`);

  const ful = () => form.querySelector('[data-fulfilment]:checked')?.value || 'delivery';
  const quote = async () => {
    try {
      const r = await api('/checkout/quote', { method: 'POST', body: { fulfilment: ful(), zone_id: $('[data-zone]').value, coupon: $('[data-coupon]').value } });
      q('subtotal').textContent = naira(r.subtotal);
      q('delivery').innerHTML = r.fee_pending ? '<span class="muted">Choose area</span>' : r.delivery_fee ? naira(r.delivery_fee) : 'Free';
      form.querySelector('[data-q-row=discount]').hidden = !r.discount;
      q('discount').textContent = '−' + naira(r.discount);
      q('total').textContent = naira(r.total);
      $('[data-coupon-err]').hidden = true;
      setDates(r.earliest_date);
      setPod(r.pay_on_delivery !== false);
    } catch (e) {
      if (e.data?.fields?.coupon) { const el = $('[data-coupon-err]'); el.textContent = e.data.fields.coupon; el.hidden = false; }
    }
  };

  // Courier areas (Lagos) can't pay on delivery: disable it and move the choice to paying online.
  const setPod = (ok) => {
    const opt = form.querySelector('[data-pod-option]');
    if (!opt) return;
    const radio = opt.querySelector('input');
    radio.disabled = !ok;
    opt.classList.toggle('is-off', !ok);
    form.querySelector('[data-pod-note]').hidden = ok;
    if (!ok && radio.checked) {
      radio.checked = false;
      const next = form.querySelector('input[name=payment_method][value=paystack]') || form.querySelector('input[name=payment_method]:not(:disabled)');
      if (next) next.checked = true;
    }
  };

  const setDates = (earliest) => {
    const sel = $('[data-date]');
    const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Lagos' });
    [...sel.options].forEach((o) => (o.disabled = o.value < earliest));
    const first = [...sel.options].find((o) => !o.disabled)?.value;
    if (sel.value < earliest || sel.dataset.auto !== 'no') sel.value = first; // follow the earliest date until the shopper picks one

    const hint = form.querySelector('[data-cutoff-hint]');
    if (hint) hint.textContent = earliest === today ? 'Same-day delivery available for this area' : (ful() === 'pickup' ? 'Pickup from tomorrow' : 'Earliest delivery for this area is ' + new Date(earliest).toDateString().slice(0, 10));
  };

  form.addEventListener('change', (e) => {
    if (e.target.matches('[data-fulfilment]')) {
      form.querySelectorAll('[data-when]').forEach((el) => (el.hidden = el.dataset.when !== ful()));
      quote();
    }
    if (e.target.matches('[data-zone]')) quote();
    if (e.target.matches('[data-date]')) e.target.dataset.auto = 'no';
  });
  $('[data-apply-coupon]').addEventListener('click', quote);

  const phone = $('[data-phone]');
  phone.addEventListener('blur', () => {
    const d = phone.value.replace(/\D/g, '').replace(/^234/, '0');
    const ok = /^0[789][01]\d{8}$/.test(d);
    phone.setAttribute('aria-invalid', phone.value && !ok ? 'true' : 'false');
    if (ok) phone.value = d.replace(/(\d{4})(\d{3})(\d{4})/, '$1 $2 $3');
  });

  form.addEventListener('submit', (e) => {
    const missing = [];
    if (!form.first_name.value.trim()) missing.push(form.first_name);
    if (!/^0[789][01]\d{8}$/.test(phone.value.replace(/\D/g, '').replace(/^234/, '0'))) missing.push(phone);
    if (ful() === 'delivery') {
      if (!form.zone_id.value) missing.push(form.zone_id);
      if (!form.street.value.trim()) missing.push(form.street);
    }
    form.querySelectorAll('[aria-invalid=true]').forEach((el) => el.setAttribute('aria-invalid', 'false'));
    if (missing.length) {
      e.preventDefault();
      missing.forEach((el) => el.setAttribute('aria-invalid', 'true'));
      missing[0].focus();
      missing[0].scrollIntoView({ block: 'center', behavior: 'smooth' });
      return;
    }
    form.querySelector('[data-place]').disabled = true;
    form.querySelector('[data-place]').textContent = 'Placing order…';
  });
}
