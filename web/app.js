// Gol POS — register front end (Day 1 skeleton: full ring-out path + Gol chat).
'use strict';
const API = '../server/api.php';
const BRAIN = '../server/brain.php';
const $ = (id) => document.getElementById(id);
const money = (n) => '$' + Number(n).toFixed(2);

// --- themes (presentation only — Midnight default, owner picks on device) -----
const THEMES = ['midnight', 'clean', 'bold'];
const THEME_LABELS = { midnight: 'Midnight Register', clean: 'Clean Counter', bold: 'Bold Blue' };
function applyTheme(name, save) {
  if (!THEMES.includes(name)) { name = 'midnight'; }
  document.documentElement.dataset.theme = name;
  if (save) { try { localStorage.setItem('golpos_theme', name); } catch (e) { /* private mode */ } }
  document.querySelectorAll('[data-set-theme]').forEach(b =>
    b.classList.toggle('on', b.dataset.setTheme === name));
  const tn = $('themeName');
  if (tn) { tn.textContent = THEME_LABELS[name]; }
}
document.querySelectorAll('[data-set-theme]').forEach(b =>
  b.addEventListener('click', () => applyTheme(b.dataset.setTheme, true)));
applyTheme(document.documentElement.dataset.theme || 'midnight', false);

// --- header clock -------------------------------------------------------------
function tickClock() {
  const el = $('clock');
  if (el) { el.textContent = new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }); }
}
tickClock(); setInterval(tickClock, 20000);

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
function artClass(p) {
  return 'art-' + String(p.category || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
}
function renderGrid() {
  const q = $('searchInput').value.trim().toLowerCase();
  const items = catalog.products.filter(p =>
    (activeCat === 'All' || p.category === activeCat) &&
    (!q || p.name.toLowerCase().includes(q) || p.barcode.includes(q)));
  $('grid').innerHTML = items.map(p =>
    `<button type="button" class="tile" data-sku="${p.sku}">
       <span class="tile-art ${artClass(p)}">${(p.name || 'G').trim().charAt(0).toUpperCase()}</span>
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
    `<div class="line"><div class="line-info"><div class="line-name">${p.name}</div><div class="line-sub">${money(p.price)} each</div></div>
       <div class="stepper"><button type="button" data-dec="${p.sku}">−</button><b>${qty}</b><button type="button" data-inc="${p.sku}">+</button></div>
       <div class="line-total">${money(total)}</div></div>`).join('')
    : '<p class="empty">Cart is empty — tap a product or scan.</p>';
  const count = lines.reduce((s, l) => s + l.qty, 0);
  $('cartCount').textContent = count ? count + (count === 1 ? ' item' : ' items') : '';
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
  let body;
  if (card.type === 'price_change') {
    body = `<div class="sc-what">Price change — ${card.name}</div>` +
      `<div class="sc-prices"><span class="sc-old">${money(card.old_price)}</span><span class="sc-arrow">→</span><span class="sc-new">${money(card.new_price)}</span></div>`;
  } else if (card.type === 'poster') {
    body = `<div class="sc-what">Poster — ${card.name} · ${money(card.price)}</div><img src="${card.image}" alt="Offer poster preview">`;
  } else if (card.type === 'photo_read') {
    const found = card.regions === null || card.regions === undefined
      ? 'SAM gave no read on this photo'
      : `SAM found ${card.regions} region(s) for "${card.prompt}"`;
    const matchLine = card.match_sku ? `<div class="sc-line">Matches: ${card.match_name} (${money(card.match_price)}) → cart on "gol"</div>` : '';
    body = `<img src="${card.image}" alt="Your photo"><div class="sc-line">${found}</div>${matchLine}`;
  } else {
    body = `<div class="sc-what">New item — ${card.name} · ${money(card.price)}</div>`;
  }
  d.innerHTML = `<span class="sc-badge">STAGED</span>${body}<button type="button">✓ Confirm — Gol</button>`;
  d.querySelector('button').addEventListener('click', async () => {
    const res = await fetch(BRAIN, { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ confirm: true }) });
    const r = await res.json();
    chatMsg(r.reply, 'gol');
    if (r.action && r.action.type === 'add') { addToCart(r.action.sku); }
    loadCatalog();
  });
  $('chatBody').appendChild(d); $('chatBody').scrollTop = 1e6;
}
// --- photo lane: upload -> SAM reads it -> staged card ------------------------
async function sendPhoto(file, promptText) {
  chatMsg('📷 Photo sent' + (promptText ? ' — "' + promptText + '"' : ''), 'me');
  const fd = new FormData();
  fd.append('photo', file);
  if (promptText) { fd.append('prompt', promptText); }
  const res = await fetch('../server/photo.php', { method: 'POST', body: fd });
  const r = await res.json();
  if (r.reply) { chatMsg(r.reply, 'gol'); }
  if (r.card) { chatCard(r.card); }
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
  else { chatMsg('Barcode ' + code + ' not found — tell Gol the name and I\'ll add it.', 'gol'); $('chatDrawer').classList.remove('hidden'); }
});
$('cashBtn').addEventListener('click', () => tender('cash'));
$('cardBtn').addEventListener('click', () => tender('card'));
$('clearBtn').addEventListener('click', () => { cart.clear(); renderCart(); });
$('receiptClose').addEventListener('click', () => $('receiptModal').classList.add('hidden'));
$('golBtn').addEventListener('click', () => { $('chatDrawer').classList.remove('hidden'); $('chatInput').focus(); });
$('chatClose').addEventListener('click', () => $('chatDrawer').classList.add('hidden'));
// --- quick-ask chips: one tap sends the everyday asks — no typing at the till.
document.querySelectorAll('[data-chip]').forEach(b =>
  b.addEventListener('click', () => sendChat(b.dataset.chip)));
$('chatForm').addEventListener('submit', (e) => {
  e.preventDefault();
  const v = $('chatInput').value.trim();
  if (v) { $('chatInput').value = ''; sendChat(v); }
});
$('photoInput').addEventListener('change', (e) => {
  const f = e.target.files[0];
  if (!f) { return; }
  // Whatever the owner typed with the photo is the concept SAM looks for.
  const hint = $('chatInput').value.trim();
  $('chatInput').value = '';
  $('chatDrawer').classList.remove('hidden');
  sendPhoto(f, hint);
  e.target.value = '';
});
$('ownerBtn').addEventListener('click', async () => {
  $('ownerPanel').classList.remove('hidden');
  const res = await fetch(API + '?summary'); const s = await res.json();
  const pres = await fetch(API + '?pending'); const p = await pres.json();
  const fres = await fetch(API + '?feed'); const feed = await fres.json();
  const feedHtml = Array.isArray(feed) && feed.length
    ? `<p><b>Demo feed (${feed.length})</b></p>` + feed.slice().reverse().map(f =>
        `<p>🖼️ ${f.caption || (f.name + ' — ' + money(f.price))}</p>`).join('')
    : '<p>Demo feed is empty — ask Gol to "make an offer poster".</p>';
  $('ownerBody').innerHTML =
    `<p>Gross ${money(s.gross)} · Cash ${money(s.cash)} · Card ${money(s.card)} · Sales ${s.txns}</p>` +
    (p && p.type ? `<p>Staged card waiting: ${p.type} — ${p.name || ''}</p>` : '<p>No staged cards waiting.</p>') +
    feedHtml;
});
$('ownerClose').addEventListener('click', () => $('ownerPanel').classList.add('hidden'));

loadCatalog();
