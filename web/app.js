// Gol POS — register front end (Day 1 skeleton: full ring-out path + Gol chat).
'use strict';
const API = '../server/api.php';
const BRAIN = '../server/brain.php';
const $ = (id) => document.getElementById(id);
const money = (n) => '$' + Number(n).toFixed(2);

let catalog = { store: {}, products: [] };
let cart = new Map(); // sku -> qty
let activeCat = 'All';

async function loadCatalog() {
  const res = await fetch(API + '?products');
  catalog = await res.json();
  $('storeName').textContent = catalog.store.name || 'Register';
  renderCats(); renderGrid(); refreshToday();
}
function renderCats() {
  const cats = ['All', ...new Set(catalog.products.map(p => p.category))];
  $('catRow').innerHTML = cats.map(c =>
    `<button type="button" class="${c === activeCat ? 'on' : ''}" data-cat="${c}">${c}</button>`).join('');
  $('catRow').querySelectorAll('button').forEach(b =>
    b.addEventListener('click', () => { activeCat = b.dataset.cat; renderCats(); renderGrid(); }));
}
function renderGrid() {
  const q = $('searchInput').value.trim().toLowerCase();
  const items = catalog.products.filter(p =>
    (activeCat === 'All' || p.category === activeCat) &&
    (!q || p.name.toLowerCase().includes(q) || p.barcode.includes(q)));
  $('grid').innerHTML = items.map(p =>
    `<button type="button" class="tile" data-sku="${p.sku}">
       <span class="tile-name">${p.name}</span><span class="tile-price">${money(p.price)}</span>
     </button>`).join('') || '<p class="empty">No products match.</p>';
  $('grid').querySelectorAll('.tile').forEach(t =>
    t.addEventListener('click', () => addToCart(t.dataset.sku)));
}
function addToCart(sku) {
  cart.set(sku, (cart.get(sku) || 0) + 1);
  renderCart();
}
function renderCart() {
  const lines = [...cart.entries()].map(([sku, qty]) => {
    const p = catalog.products.find(x => x.sku === sku);
    return { p, qty, total: p.price * qty };
  });
  $('cartLines').innerHTML = lines.length ? lines.map(({ p, qty, total }) =>
    `<div class="line"><span>${p.name} × ${qty}</span><b>${money(total)}</b>
       <button type="button" data-dec="${p.sku}">−</button>
       <button type="button" data-inc="${p.sku}">+</button></div>`).join('')
    : '<p class="empty">Cart is empty — tap a product or scan.</p>';
  $('cartLines').querySelectorAll('[data-inc]').forEach(b =>
    b.addEventListener('click', () => { cart.set(b.dataset.inc, cart.get(b.dataset.inc) + 1); renderCart(); }));
  $('cartLines').querySelectorAll('[data-dec]').forEach(b =>
    b.addEventListener('click', () => {
      const q = cart.get(b.dataset.dec) - 1;
      q <= 0 ? cart.delete(b.dataset.dec) : cart.set(b.dataset.dec, q); renderCart();
    }));
  const sub = lines.reduce((s, l) => s + l.total, 0);
  const tax = sub * (catalog.store.tax_rate || 0);
  $('subTotal').textContent = money(sub);
  $('taxTotal').textContent = money(tax);
  $('grandTotal').textContent = money(sub + tax);
}
async function tender(kind) {
  if (!cart.size) { return; }
  const lines = [...cart.entries()].map(([sku, qty]) => ({ sku, qty }));
  const res = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ sale: { lines, tender: kind } }) });
  const sale = await res.json();
  $('receiptBody').textContent =
    sale.lines.map(l => `${l.name} x${l.qty}  ${money(l.line_total)}`).join('\n') +
    `\n--------------------------\nSubtotal  ${money(sale.subtotal)}\nTax       ${money(sale.tax)}\nTOTAL     ${money(sale.total)}\nPaid by ${sale.tender}`;
  $('receiptModal').classList.remove('hidden');
  cart.clear(); renderCart(); refreshToday();
}
async function refreshToday() {
  const res = await fetch(API + '?summary');
  const s = await res.json();
  $('todayTotal').textContent = 'Today: ' + money(s.gross) + ' (' + s.txns + ')';
}

// --- Gol chat ---------------------------------------------------------------
function chatMsg(text, who) {
  const d = document.createElement('div');
  d.className = 'msg ' + who; d.textContent = text;
  $('chatBody').appendChild(d); $('chatBody').scrollTop = 1e6;
  return d;
}
function chatCard(card) {
  const d = document.createElement('div');
  d.className = 'msg card';
  const label = card.type === 'price_change'
    ? `${card.name}: ${money(card.old_price)} → ${money(card.new_price)}`
    : `New item: ${card.name} — ${money(card.price)}`;
  d.innerHTML = `<b>Staged</b> ${label}<br><button type="button">Confirm — "gol"</button>`;
  d.querySelector('button').addEventListener('click', async () => {
    const res = await fetch(BRAIN, { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ confirm: true }) });
    const r = await res.json();
    chatMsg(r.reply, 'gol'); loadCatalog();
  });
  $('chatBody').appendChild(d); $('chatBody').scrollTop = 1e6;
}
async function sendChat(text) {
  chatMsg(text, 'me');
  const res = await fetch(BRAIN, { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ message: text }) });
  const r = await res.json();
  if (r.reply) { chatMsg(r.reply, 'gol'); }
  if (r.card) { chatCard(r.card); }
  if (r.action && r.action.type === 'add') { addToCart(r.action.sku); }
  if (r.done) { loadCatalog(); }
}

// --- wiring -------------------------------------------------------------------
$('searchInput').addEventListener('input', renderGrid);
$('scanInput').addEventListener('keydown', (e) => {
  if (e.key !== 'Enter') { return; }
  const code = $('scanInput').value.trim();
  const p = catalog.products.find(x => x.barcode === code || x.sku === code);
  if (p) { addToCart(p.sku); $('scanInput').value = ''; }
  else { chatMsg('Barcode ' + code + ' bhettiyena — Gol lai naam bhannus, ma thapchhu.', 'gol'); $('chatDrawer').classList.remove('hidden'); }
});
$('cashBtn').addEventListener('click', () => tender('cash'));
$('cardBtn').addEventListener('click', () => tender('card'));
$('clearBtn').addEventListener('click', () => { cart.clear(); renderCart(); });
$('receiptClose').addEventListener('click', () => $('receiptModal').classList.add('hidden'));
$('golBtn').addEventListener('click', () => { $('chatDrawer').classList.remove('hidden'); $('chatInput').focus(); });
$('chatClose').addEventListener('click', () => $('chatDrawer').classList.add('hidden'));
$('chatForm').addEventListener('submit', (e) => {
  e.preventDefault();
  const v = $('chatInput').value.trim();
  if (v) { $('chatInput').value = ''; sendChat(v); }
});
$('ownerBtn').addEventListener('click', async () => {
  $('ownerPanel').classList.remove('hidden');
  const res = await fetch(API + '?summary'); const s = await res.json();
  const pres = await fetch(API + '?pending'); const p = await pres.json();
  $('ownerBody').innerHTML =
    `<p>Gross ${money(s.gross)} · Cash ${money(s.cash)} · Card ${money(s.card)} · Sales ${s.txns}</p>` +
    (p && p.type ? `<p>Staged card waiting: ${p.type} — ${p.name || ''}</p>` : '<p>No staged cards waiting.</p>');
});
$('ownerClose').addEventListener('click', () => $('ownerPanel').classList.add('hidden'));

loadCatalog();
