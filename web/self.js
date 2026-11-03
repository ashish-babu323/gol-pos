// Sunrise Mart — Self Checkout (customer face of the demo Gol POS).
// PROTOTYPE: reads the demo store's LIVE catalog + day summary from the
// same server API the register uses (../server/api.php?products /
// ?summary), so what the customer sees is the store's real demo data.
// Everything here is in-memory and visual only: no sale is written,
// no payment is taken, no store data changes.
const API = '../server/api.php';
// Pre-filled demo basket: the protected hero + a 21+ item (drives the
// age-check card). SKUs only — names/prices always come from the catalog.
const START_CART = [['1001', 1], ['1062', 1]]; // Coca-Cola 20oz · Corona 6 Pack
// Fallback = the same rows as verified live on 2026-11-03, used only if
// the catalog fetch fails, so the prototype never renders blank.
const FALLBACK = {
  store: { name: 'Sunrise Mart (demo)', tax_rate: 0.08 },
  products: [
    { sku: '1001', name: 'Coca-Cola 20oz', price: 3.00, category: 'Drinks', img: 'assets/products/1001.webp', stock: 3 },
    { sku: '1062', name: 'Corona 6 Pack', price: 11.49, category: 'Beer and Wine', img: 'assets/products/1062.webp', stock: 23 },
    { sku: '1002', name: 'Pepsi 20oz', price: 2.19, category: 'Drinks', img: 'assets/products/1002.webp', stock: 23 },
    { sku: '1004', name: 'Gatorade Cool Blue 20oz', price: 2.49, category: 'Drinks', img: 'assets/products/1004.webp', stock: 19 },
    { sku: '1005', name: 'Bottled Water 16.9oz', price: 1.29, category: 'Drinks', img: 'assets/products/1005.webp', stock: 21 },
    { sku: '1007', name: 'Doritos Nacho 1oz', price: 1.19, category: 'Snacks', img: 'assets/products/1007.webp', stock: 11 },
    { sku: '1008', name: 'Snickers Bar', price: 1.49, category: 'Snacks', img: 'assets/products/1008.webp', stock: 11 }
  ]
};

const $ = (id) => document.getElementById(id);
const round2 = (n) => Math.round(n * 100) / 100;
const money = (n) => '$' + round2(n).toFixed(2);
const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

let catalog = null;
let cart = new Map();
let summary = null;

function prod(sku) { return catalog.products.find((p) => p.sku === sku); }
function storeLabel() { return (catalog.store.name || 'Sunrise Mart').replace(/\s*\(demo\)\s*/i, ''); }

async function loadSummary() {
  try {
    const r = await fetch(API + '?summary');
    summary = await r.json();
    if (summary && typeof summary.gross === 'number') {
      $('dayChip').textContent = 'Today: ' + money(summary.gross) + ' (' + summary.txns + ')';
      $('dayLine').textContent = storeLabel() + ' today: ' + money(summary.gross) + ' · ' + summary.txns + ' sales';
    }
  } catch (e) { /* visual only — the strip simply keeps its static text */ }
}

function totals() {
  let sub = 0, taxable = 0;
  cart.forEach((qty, sku) => {
    const p = prod(sku); if (!p) return;
    const lt = p.price * qty; sub += lt;
    if (p.taxable !== false) taxable += lt;
  });
  const tax = round2(taxable * (catalog.store.tax_rate || 0)); // same rule as the register
  sub = round2(sub);
  return { sub, tax, total: round2(sub + tax) };
}

function renderCart() {
  const wrap = $('cartLines');
  let html = '', count = 0, has21 = false;
  cart.forEach((qty, sku) => {
    const p = prod(sku); if (!p) return;
    count += qty;
    if (p.category === 'Beer and Wine') has21 = true;
    html += '<div class="cart-line">'
      + (p.img ? '<img src="' + esc(p.img) + '" alt="">' : '')
      + '<div class="cl-info"><div class="cl-name">' + esc(p.name) + '</div>'
      + '<div class="cl-unit">' + money(p.price) + ' each</div></div>'
      + '<div class="cl-step"><button type="button" data-dec="' + esc(sku) + '" aria-label="One less">−</button>'
      + '<span>' + qty + '</span>'
      + '<button type="button" data-inc="' + esc(sku) + '" aria-label="One more">+</button></div>'
      + '<div class="cl-total">' + money(p.price * qty) + '</div></div>';
  });
  wrap.innerHTML = html || '<p class="cart-empty">Your basket is empty — scan an item or tap a favourite to start.</p>';
  $('cartCount').textContent = count ? '· ' + count + ' item' + (count > 1 ? 's' : '') : '';
  $('ageCard').classList.toggle('hidden', !has21);
  const t = totals();
  $('subTotal').textContent = money(t.sub);
  $('taxTotal').textContent = money(t.tax);
  $('grandTotal').textContent = money(t.total);
  $('taxLabel').textContent = 'Tax (' + Math.round((catalog.store.tax_rate || 0) * 100) + '%)';
  $('payCard').disabled = $('payCash').disabled = !cart.size;
  // keep the Gol panel's basket line in step with the till
  const names = [];
  cart.forEach((qty, sku) => { const p = prod(sku); if (p) names.push(p.name + ' ×' + qty); });
  $('panelBasket').textContent = names.length ? names.join(' · ') + ' — total ' + money(t.total) + '.' : 'Your basket is empty.';
}

function renderGrid(filter) {
  const q = (filter || '').trim().toLowerCase();
  let items = catalog.products.filter((p) => (p.stock == null || p.stock > 0));
  if (q) items = items.filter((p) => p.name.toLowerCase().includes(q));
  else items = items.filter((p) => ['Drinks', 'Snacks', 'Grocery'].includes(p.category));
  items = items.slice(0, 6);
  $('quickGrid').innerHTML = items.map((p) =>
    '<button type="button" class="quick-tile" data-add="' + esc(p.sku) + '">'
    + (p.img ? '<img src="' + esc(p.img) + '" alt="">' : '')
    + '<span class="qt-name">' + esc(p.name) + '</span>'
    + '<span class="qt-price">' + money(p.price) + '</span></button>').join('')
    || '<p class="cart-empty">No matches in the ' + esc(storeLabel()) + ' catalog.</p>';
}

// --- payment (visual only) -------------------------------------------------
function pay(kind) {
  if (!cart.size) return;
  $('payState').classList.remove('hidden');
  $('doneState').classList.add('hidden');
  $('payWaitText').textContent = kind === 'card' ? 'Contacting the card reader…' : 'Please wait — the till is counting…';
  $('payOverlay').classList.remove('hidden');
  setTimeout(async () => {
    await loadSummary(); // the day line on the receipt state is read live
    $('doneDay').textContent = (summary && typeof summary.gross === 'number')
      ? storeLabel() + ' today: ' + money(summary.gross) + ' · ' + summary.txns + ' sales — live from MR.STORE'
      : '';
    $('payState').classList.add('hidden');
    $('doneState').classList.remove('hidden');
  }, 1900);
}

// --- Gol help panel (faithful clone of the register's bot-face panel) ------
function openPanel() {
  const d = $('chatDrawer');
  const w = Math.min(400, window.innerWidth - 24);
  const h = Math.min(620, Math.round(window.innerHeight * 0.78));
  d.style.width = w + 'px'; d.style.height = h + 'px';
  d.style.left = (window.innerWidth - w - 16) + 'px';
  d.style.top = (window.innerHeight - h - 16) + 'px';
  d.classList.remove('hidden');
  $('golScrim').classList.remove('hidden');
}
function closePanel() {
  $('chatDrawer').classList.add('hidden');
  $('golScrim').classList.add('hidden');
}
function golSay(html) {
  const b = $('chatBody');
  const div = document.createElement('div');
  div.className = 'msg gol'; div.innerHTML = html;
  b.appendChild(div); b.scrollTop = b.scrollHeight;
}
function meSay(text) {
  const b = $('chatBody');
  const div = document.createElement('div');
  div.className = 'msg me'; div.textContent = text;
  b.appendChild(div); b.scrollTop = b.scrollHeight;
}
const REPLIES = {
  price: () => { const c = prod('1001'); return 'Tell me the item and I read its shelf price from the live catalog — ' + (c ? esc(c.name) + ' is ' + money(c.price) + ' today.' : 'every price here comes straight from the shelf.'); },
  beer: 'Beer and wine are in the wall cooler to your left. Please keep your ID handy — we check 21+ before payment.',
  pay: 'Tap <b>Pay by Card</b> and follow the reader, or choose <b>Cash</b> and a team member will come to this till.',
  human: 'Done — I have let the team know. Someone will be with you in a moment; I will stay right here.'
};

// --- wiring ------------------------------------------------------------------
function tickClock() {
  const el = $('clock'); if (!el) return;
  el.textContent = new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
}

(async function init() {
  try {
    const r = await fetch(API + '?products');
    catalog = await r.json();
  } catch (e) { catalog = null; }
  if (!catalog || !Array.isArray(catalog.products) || !catalog.products.length) catalog = FALLBACK;
  $('storeName').textContent = storeLabel();
  $('stripStore').textContent = storeLabel();
  $('doneSaved').textContent = 'Sale saved · ' + storeLabel() + ' · MR.STORE';
  document.title = storeLabel() + ' — Self Checkout';
  cart = new Map(START_CART.filter(([sku]) => prod(sku)));
  renderGrid('');
  renderCart();
  loadSummary();
  tickClock(); setInterval(tickClock, 10000);

  $('quickGrid').addEventListener('click', (e) => {
    const b = e.target.closest('[data-add]'); if (!b) return;
    const sku = b.getAttribute('data-add');
    cart.set(sku, (cart.get(sku) || 0) + 1);
    renderCart();
  });
  $('cartLines').addEventListener('click', (e) => {
    const inc = e.target.closest('[data-inc]'), dec = e.target.closest('[data-dec]');
    if (inc) { const s = inc.getAttribute('data-inc'); cart.set(s, (cart.get(s) || 0) + 1); }
    else if (dec) {
      const s = dec.getAttribute('data-dec'); const q = (cart.get(s) || 0) - 1;
      if (q <= 0) cart.delete(s); else cart.set(s, q);
    } else return;
    renderCart();
  });
  $('searchInput').addEventListener('input', (e) => renderGrid(e.target.value));
  $('payCard').addEventListener('click', () => pay('card'));
  $('payCash').addEventListener('click', () => pay('cash'));
  $('againBtn').addEventListener('click', () => {
    $('payOverlay').classList.add('hidden');
    cart = new Map(START_CART.filter(([sku]) => prod(sku)));
    renderCart();
  });
  $('talkBtn').addEventListener('click', openPanel);
  $('talkBtn2').addEventListener('click', openPanel);
  $('chatClose').addEventListener('click', closePanel);
  $('golScrim').addEventListener('click', closePanel);
  $('chatChips').addEventListener('click', (e) => {
    const b = e.target.closest('[data-ask]'); if (!b) return;
    meSay(b.textContent.trim());
    const key = b.getAttribute('data-ask');
    const rep = REPLIES[key] || REPLIES.human;
    setTimeout(() => golSay(typeof rep === 'function' ? rep() : rep), 350);
  });
  $('chatForm').addEventListener('submit', (e) => {
    e.preventDefault();
    const inp = $('chatInput'); const v = inp.value.trim(); if (!v) return;
    inp.value = '';
    meSay(v);
    setTimeout(() => golSay('Got it — a team member has been notified, and I am right here if you need a price or a product.'), 350);
  });
  // drag the panel by its head, like the register's floating panel
  const head = $('chatHead'), drawer = $('chatDrawer');
  let drag = null;
  head.addEventListener('pointerdown', (e) => {
    if (e.target.closest('button')) return;
    drag = { dx: e.clientX - drawer.offsetLeft, dy: e.clientY - drawer.offsetTop };
    head.setPointerCapture(e.pointerId);
  });
  head.addEventListener('pointermove', (e) => {
    if (!drag) return;
    drawer.style.left = Math.max(0, Math.min(window.innerWidth - 120, e.clientX - drag.dx)) + 'px';
    drawer.style.top = Math.max(0, Math.min(window.innerHeight - 80, e.clientY - drag.dy)) + 'px';
  });
  head.addEventListener('pointerup', () => { drag = null; });
})();
