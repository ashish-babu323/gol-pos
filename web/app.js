// Gol POS — register front end (Day 1 skeleton: full ring-out path + Gol chat).
'use strict';
const API = '../server/api.php';
const BRAIN = '../server/brain.php';

// --- chat identity (access layer, Oct 28) --------------------------------------
// This device holds a session id the server keys admin elevation to, and —
// after the invite flow — an employee identity (email + device token). Both
// ride along with every chat call; the server decides what the session may
// do. An employee identity grants the cashier domain only, never admin.
let SESSION_ID = 'sess_ephemeral';
try {
  SESSION_ID = localStorage.getItem('golpos_session') || '';
  if (!SESSION_ID) {
    SESSION_ID = 'sess_' + Math.random().toString(36).slice(2, 10) + Date.now().toString(36);
    localStorage.setItem('golpos_session', SESSION_ID);
  }
} catch (e) { /* private mode: elevation simply will not persist */ }
function brainPayload(obj) {
  const p = Object.assign({}, obj, { session: SESSION_ID });
  try {
    const emp = JSON.parse(localStorage.getItem('golpos_employee') || 'null');
    if (emp && emp.email && emp.token) { p.employee = emp; }
  } catch (e) { /* no employee on this device */ }
  return p;
}
const LOYALTY = '../server/loyalty.php';
const $ = (id) => document.getElementById(id);
const money = (n) => '$' + Number(n).toFixed(2);
const esc = (s) => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

// --- receipt print settings: ONE store, two doors -----------------------------
// Gol chat stages changes as cards; the owner panel edits a form. Both write
// the same server-side settings (api.php ?settings) and this register renders
// every receipt from them — there is no settings menu anywhere else.
let receiptSettings = null;
async function loadSettings() {
  try { const r = await fetch(API + '?settings'); receiptSettings = await r.json(); }
  catch (e) { /* keep the last known settings; the register never stops */ }
  applyStorePill();
}
// --- store open/closed (Settings dropdown toggle, persisted server-side) -----
// Closed pauses NEW sales: the register refuses the tap, and the sale
// endpoint refuses the POST with the same plain words. Chat, browsing and
// the owner panel are never blocked.
function storeIsOpen() { return !receiptSettings || receiptSettings.register_open !== false; }
function applyStorePill() {
  const open = storeIsOpen();
  const dot = document.querySelector('.status-dot');
  const label = document.querySelector('.store-open');
  if (dot) { dot.classList.toggle('closed', !open); }
  if (label) { label.textContent = open ? ' · Register open' : ' · Register closed'; }
}
function storeClosedNote() { showToast('Store is closed — open it from Settings'); }
let toastTimer = null;
function showToast(text) {
  const el = $('toast'); if (!el) { return; }
  el.textContent = text; el.classList.remove('hidden');
  clearTimeout(toastTimer); toastTimer = setTimeout(() => el.classList.add('hidden'), 4600);
}

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
let manualLines = []; // hand-typed amounts from the Keypad tab: {id, price, taxable}
let manualSeq = 0;
let cartTotals = { subtotal: 0, tax: 0, total: 0 };
let activeCat = 'All';
let taxExempt = false; // Settings menu, current sale only — never persisted

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
  if (!storeIsOpen()) { storeClosedNote(); return; }
  cart.set(sku, (cart.get(sku) || 0) + 1);
  renderCart();
}
function renderCart() {
  const roundMoney = (n) => Math.round((n + Number.EPSILON) * 100) / 100;
  const lines = [...cart.entries()].map(([sku, qty]) => {
    const p = catalog.products.find(x => x.sku === sku);
    return { p, qty, total: p.price * qty };
  });
  const catHtml = lines.map(({ p, qty, total }) =>
    `<div class="line"><div class="line-info"><div class="line-name">${p.name}</div><div class="line-sub">${money(p.price)} each</div></div>
       <div class="stepper"><button type="button" data-dec="${p.sku}">−</button><b>${qty}</b><button type="button" data-inc="${p.sku}">+</button></div>
       <div class="line-total">${money(total)}</div></div>`).join('');
  // Manual lines (Keypad tab): qty is always 1 — to change the amount the
  // cashier removes the line and keys it again, as the line's tooltip says.
  const manHtml = manualLines.map(m =>
    `<div class="line" title="Manual amount — remove and re-add to change the amount"><div class="line-info"><div class="line-name">Manual amount<span class="line-tag ${m.taxable ? 'tax' : 'notax'}">${m.taxable ? 'TAX' : 'NO-TAX'}</span></div><div class="line-sub">${money(m.price)}</div></div>
       <div class="stepper"><button type="button" data-mremove="${m.id}" aria-label="Remove manual amount">×</button></div>
       <div class="line-total">${money(m.price)}</div></div>`).join('');
  $('cartLines').innerHTML = (lines.length || manualLines.length) ? catHtml + manHtml
    : '<p class="empty">Cart is empty — tap a product or scan.</p>';
  const count = lines.reduce((s, l) => s + l.qty, 0) + manualLines.length;
  $('cartCount').textContent = count ? count + (count === 1 ? ' item' : ' items') : '';
  $('cartLines').querySelectorAll('[data-inc]').forEach(b =>
    b.addEventListener('click', () => { cart.set(b.dataset.inc, cart.get(b.dataset.inc) + 1); renderCart(); }));
  $('cartLines').querySelectorAll('[data-dec]').forEach(b =>
    b.addEventListener('click', () => {
      const q = cart.get(b.dataset.dec) - 1;
      q <= 0 ? cart.delete(b.dataset.dec) : cart.set(b.dataset.dec, q); renderCart();
    }));
  $('cartLines').querySelectorAll('[data-mremove]').forEach(b =>
    b.addEventListener('click', () => {
      manualLines = manualLines.filter(m => m.id !== b.dataset.mremove);
      renderCart();
    }));
  const sub = roundMoney(lines.reduce((s, l) => s + l.total, 0)
    + manualLines.reduce((s, m) => s + m.price, 0));
  // Tax is per-line: the rate falls on taxable lines only. Catalog
  // products are taxable unless the catalog flags one taxable:false;
  // a manual line carries the choice the cashier made on the keypad.
  // The whole-sale Tax Exempt still overrides everything to zero.
  const taxableSub = roundMoney(
    lines.reduce((s, l) => s + (l.p.taxable === false ? 0 : l.total), 0)
    + manualLines.reduce((s, m) => s + (m.taxable ? m.price : 0), 0));
  const tax = taxExempt ? 0 : roundMoney(taxableSub * (catalog.store.tax_rate || 0));
  cartTotals = { subtotal: sub, tax, total: roundMoney(sub + tax) };
  $('subTotal').textContent = money(sub);
  $('taxTotal').textContent = money(tax);
  $('taxBadge').classList.toggle('hidden', !taxExempt);
  $('grandTotal').textContent = money(sub + tax);
}
// Build the receipt text from the print settings: header lines (store name,
// address, phone) appear only when their setting shows them, the tax line
// only when show_tax is on, the footer closes it, and the whole block
// repeats for the copies count — printing once yields that many copies.
function receiptText(sale) {
  const st = receiptSettings || {};
  const sep = '--------------------------';
  const rows = [];
  if (st.store_name) { rows.push(st.store_name); }
  if (st.show_address && st.address) { rows.push(st.address); }
  if (st.show_phone && st.phone) { rows.push(st.phone); }
  if (rows.length) { rows.push(sep); }
  rows.push(...sale.lines.map(l => `${l.name} x${l.qty}  ${money(l.line_total)}` + (l.manual ? (l.taxable ? ' (TAX)' : ' (NO-TAX)') : '')));
  rows.push(sep, `Subtotal  ${money(sale.subtotal)}`);
  if (st.show_tax !== false) { rows.push(`Tax       ${money(sale.tax)}`); }
  rows.push(`TOTAL     ${money(sale.total)}`, `Paid by ${sale.tender}`);
  if (sale.tender === 'cash' && sale.tendered !== undefined && sale.tendered !== null) {
    rows.push(`Cash given ${money(sale.tendered)}`, `Change    ${money(sale.change_due || 0)}`);
  }
  rows.push(new Date(sale.at || Date.now()).toLocaleString('en-US'));
  if (st.footer) { rows.push(sep, st.footer); }
  const block = rows.join('\n');
  const n = Math.min(3, Math.max(1, parseInt(st.copies, 10) || 1));
  if (n === 1) { return block; }
  const parts = [];
  for (let i = 1; i <= n; i++) { parts.push(`— copy ${i} of ${n} —\n` + block); }
  return parts.join('\n\n✂ - - - - - - - - - - - -\n\n');
}
// Render a recorded sale into the receipt view (the print settings decide
// logo, paper width and copy count). Used by the tender path and by the
// Settings menu's reprint, so a reprint is byte-identical to the original.
function showReceipt(sale) {
  const st = receiptSettings || {};
  $('receiptLogo').style.display = st.logo ? 'block' : 'none';
  const card = document.querySelector('.receipt-card');
  card.classList.toggle('w58', st.paper_width === '58mm');
  card.classList.toggle('w80', st.paper_width !== '58mm');
  const n = parseInt(st.copies, 10) || 1;
  $('receiptTitle').textContent = 'Receipt' + (n > 1 ? ' ×' + n : '');
  $('receiptBody').textContent = receiptText(sale);
  $('receiptModal').classList.remove('hidden');
}
async function tender(kind, tendered) {
  if (!cart.size && !manualLines.length) { return false; }
  if (!storeIsOpen()) { storeClosedNote(); return false; }
  const lines = [...cart.entries()].map(([sku, qty]) => ({ sku, qty }))
    .concat(manualLines.map(m => ({ manual: true, name: 'Manual amount', price: m.price, qty: 1, taxable: m.taxable })));
  const salePayload = { lines, tender: kind };
  if (kind === 'cash') { salePayload.tendered = Number(Number(tendered || 0).toFixed(2)); }
  if (taxExempt) { salePayload.tax_exempt = true; }
  const res = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ sale: salePayload }) });
  const sale = await res.json();
  if (!res.ok || sale.error) {
    showToast(sale.error || 'The sale could not be recorded — the cart is unchanged.');
    return false;
  }
  await loadSettings();
  const st = receiptSettings || {};
  taxExempt = false; // the exemption dies with the sale it was set for
  setToggle($('smTaxBtn'), false);
  cart.clear(); manualLines = []; renderCart(); refreshToday();
  if (st.auto_open === false) {
    showToast('Sale rung — receipt auto-open is off. Turn Printer on in Settings to bring it back.');
  } else {
    showReceipt(sale);
  }
  return true;
}
// Cash is a register step, not a blind button: the cashier sees the total,
// enters or taps the cash handed over, and sees the change before the sale
// can complete. The server repeats the same calculation and refuses a
// short tender, so the recorded receipt can never invent change.
function updateCashPanel() {
  const total = cartTotals.total;
  const received = parseFloat($('cashReceived').value || '0') || 0;
  const change = received - total;
  $('cashDue').textContent = money(total);
  if (change >= 0) {
    $('cashChangeLabel').textContent = 'Change due';
    $('cashChange').textContent = money(change);
    $('cashComplete').disabled = false;
  } else {
    $('cashChangeLabel').textContent = 'Still needed';
    $('cashChange').textContent = money(Math.abs(change));
    $('cashComplete').disabled = true;
  }
}
function openCashPanel() {
  if (!cart.size && !manualLines.length) { return; }
  if (!storeIsOpen()) { storeClosedNote(); return; }
  $('cashReceived').value = cartTotals.total.toFixed(2);
  updateCashPanel();
  $('cashModal').classList.remove('hidden');
  $('cashReceived').focus(); $('cashReceived').select();
}
async function completeCashSale() {
  const tendered = parseFloat($('cashReceived').value || '0') || 0;
  if (tendered < cartTotals.total) { updateCashPanel(); return; }
  if (await tender('cash', tendered)) { $('cashModal').classList.add('hidden'); }
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
  } else if (card.type === 'setting_change') {
    const fmt = (v) => typeof v === 'boolean' ? (v ? 'on' : 'off') : String(v);
    body = `<div class="sc-what">Print setting — ${esc(card.label || card.setting)}</div>` +
      `<div class="sc-prices"><span class="sc-old">${esc(fmt(card.old))}</span><span class="sc-arrow">→</span><span class="sc-new">${esc(fmt(card.new))}</span></div>`;
  } else if (card.type === 'batch_close') {
    body = `<div class="sc-what">Batch close — ${card.count} card sales</div>` +
      `<div class="sc-prices"><span class="sc-new">${money(card.total)}</span></div>`;
  } else if (card.type === 'employee_remove') {
    body = `<div class="sc-what">Remove employee — ${esc(card.name ? card.name + ' · ' : '')}${esc(card.email)}</div>`;
  } else if (card.type === 'stock_receive') {
    body = `<div class="sc-what">Stock received — ${esc(card.name)} · ${card.qty}</div>` +
      `<div class="sc-line">MR.STORE stock goes up when you confirm</div>`;
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
      body: JSON.stringify(brainPayload({ confirm: true })) });
    const r = await res.json();
    chatMsg(r.reply, 'gol');
    if (r.action && r.action.type === 'add') { addToCart(r.action.sku); }
    loadCatalog(); loadSettings();
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
  const mine = chatMsg(text, 'me');
  const res = await fetch(BRAIN, { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(brainPayload({ message: text })) });
  const r = await res.json();
  // The server consumed that message as a PIN entry — never leave the
  // digits standing in the chat history.
  if (r.pin_mask && mine) { mine.textContent = '••••'; }
  if (r.reply) { chatMsg(r.reply, 'gol'); }
  if (r.card) { chatCard(r.card); }
  if (r.action && r.action.type === 'add') { addToCart(r.action.sku); }
  if (r.done) { loadCatalog(); loadSettings(); }
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
$('cashBtn').addEventListener('click', openCashPanel);
$('cashBack').addEventListener('click', () => $('cashModal').classList.add('hidden'));
$('cashComplete').addEventListener('click', completeCashSale);
$('cashReceived').addEventListener('input', updateCashPanel);
$('cashExact').addEventListener('click', () => { $('cashReceived').value = cartTotals.total.toFixed(2); updateCashPanel(); });
$('cashClear').addEventListener('click', () => { $('cashReceived').value = ''; updateCashPanel(); $('cashReceived').focus(); });
document.querySelectorAll('[data-cash-add]').forEach(b =>
  b.addEventListener('click', () => {
    const cur = parseFloat($('cashReceived').value || '0') || 0;
    $('cashReceived').value = (cur + parseFloat(b.dataset.cashAdd)).toFixed(2);
    updateCashPanel();
  }));
$('cardBtn').addEventListener('click', () => tender('card'));
$('clearBtn').addEventListener('click', () => {
  cart.clear(); manualLines = []; taxExempt = false; setToggle($('smTaxBtn'), false); renderCart();
});
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
  $('ownerStoreName').textContent = $('storeName').textContent;
  const res = await fetch(API + '?summary'); const s = await res.json();
  const pres = await fetch(API + '?pending'); const p = await pres.json();
  const fres = await fetch(API + '?feed'); const feed = await fres.json();
  const feedHtml = Array.isArray(feed) && feed.length
    ? `<h3 class="ocard-title">Demo feed <span class="ocard-count">${feed.length}</span></h3>` + feed.slice().reverse().map(f =>
        `<p class="feed-line">🖼️ ${f.caption || (f.name + ' — ' + money(f.price))}</p>`).join('')
    : '<h3 class="ocard-title">Demo feed</h3><p class="owner-muted">Demo feed is empty — ask Gol to "make an offer poster".</p>';
  $('ownerFeed').innerHTML = feedHtml;
  $('ownerBody').innerHTML =
    `<div class="stat-tiles">` +
      `<div class="stat-tile"><span class="stat-label">Gross</span><b class="stat-num">${money(s.gross)}</b></div>` +
      `<div class="stat-tile"><span class="stat-label">Cash</span><b class="stat-num">${money(s.cash)}</b></div>` +
      `<div class="stat-tile"><span class="stat-label">Card</span><b class="stat-num">${money(s.card)}</b></div>` +
      `<div class="stat-tile"><span class="stat-label">Sales</span><b class="stat-num">${s.txns}</b></div>` +
    `</div>` +
    (p && p.type ? `<p class="owner-note">Staged card waiting: ${p.type} — ${p.name || ''}</p>` : '<p class="owner-muted">No staged cards waiting.</p>');
  // Receipt Loyalty — the live leaderboard, computed by the server from the
  // receipt photos customers sent; it can never drift from what they were told.
  try {
    const lb = await (await fetch(LOYALTY + '?leaderboard')).json();
    const board = Array.isArray(lb.board) ? lb.board : [];
    $('ownerLoyalty').innerHTML =
      `<h3 class="ocard-title">Receipt Loyalty — leaderboard <small>— send customers to web/loyalty.html</small></h3>` +
      (board.length ? board.map(r =>
        `<div class="rep-row"><span>#${r.rank} ${esc(r.name)}</span><b>${money(r.total)}</b>` +
        `<span>${r.receipts} receipt${r.receipts === 1 ? '' : 's'}</span></div>`).join('')
        : '<p class="owner-muted">No loyalty receipts yet.</p>');
  } catch (e) { $('ownerLoyalty').innerHTML = ''; }
  await loadSettings(); renderSettingsForm();
  renderDayClose();
  showReport('today');
});
$('ownerClose').addEventListener('click', () => $('ownerPanel').classList.add('hidden'));

// --- owner panel: print settings form (the manual door, same store) ---------
function renderSettingsForm() {
  const st = receiptSettings || {};
  const chk = (b) => b ? 'checked' : '';
  const opts = (vals, cur) => vals.map(v =>
    `<option value="${v}" ${String(v) === String(cur) ? 'selected' : ''}>${v}</option>`).join('');
  $('ownerSettings').innerHTML =
    `<h3 class="ocard-title">Receipt print settings <small>— the same store Gol chat writes</small></h3>
     <div class="os-grid">
       <label>Store name <input id="os_store_name" type="text" value="${esc(st.store_name || '')}"></label>
       <label>Address <input id="os_address" type="text" value="${esc(st.address || '')}"></label>
       <label>Phone <input id="os_phone" type="text" value="${esc(st.phone || '')}"></label>
       <label>Footer message <input id="os_footer" type="text" value="${esc(st.footer || '')}"></label>
       <label>Copies <select id="os_copies">${opts([1, 2, 3], st.copies)}</select></label>
       <label>Paper width <select id="os_paper_width">${opts(['80mm', '58mm'], st.paper_width)}</select></label>
       <label>Store opens <input id="os_store_open" type="time" value="${esc(st.store_open || '08:00')}"></label>
       <label>Store closes <input id="os_store_close" type="time" value="${esc(st.store_close || '21:00')}"></label>
       <label>Batch close <select id="os_batch_mode">${opts(['manual', 'auto'], st.batch_mode || 'manual')}</select></label>
       <label class="os-chk"><input id="os_show_address" type="checkbox" ${chk(st.show_address)}> Show address line</label>
       <label class="os-chk"><input id="os_show_phone" type="checkbox" ${chk(st.show_phone)}> Show phone line</label>
       <label class="os-chk"><input id="os_logo" type="checkbox" ${chk(st.logo)}> Logo on receipt</label>
       <label class="os-chk"><input id="os_show_tax" type="checkbox" ${chk(st.show_tax)}> Show tax line</label>
       <label class="os-chk"><input id="os_auto_open" type="checkbox" ${chk(st.auto_open)}> Receipt after each sale</label>
     </div>
     <button id="osSave" type="button">Save print settings</button> <span id="osSaved"></span>`;
  $('osSave').addEventListener('click', saveSettingsForm);
}
async function saveSettingsForm() {
  const payload = { settings: {
    store_name: $('os_store_name').value, address: $('os_address').value,
    phone: $('os_phone').value, footer: $('os_footer').value,
    copies: parseInt($('os_copies').value, 10), paper_width: $('os_paper_width').value,
    store_open: $('os_store_open').value, store_close: $('os_store_close').value,
    batch_mode: $('os_batch_mode').value,
    show_address: $('os_show_address').checked, show_phone: $('os_show_phone').checked,
    logo: $('os_logo').checked, show_tax: $('os_show_tax').checked,
    auto_open: $('os_auto_open').checked } };
  const r = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload) });
  receiptSettings = await r.json();
  $('osSaved').textContent = 'Saved ✓ — Gol chat reads these same settings.';
}

// --- owner panel: Close the day — the daily action, top of the panel --------
// The close lives directly under the "Owner — today" summary (owner
// directive, Oct 28: daily actions on top, sit-down settings below). Same
// api.php close_day path the Gol chat and the Settings dropdown run —
// behind the same tap-confirm (first tap arms, second tap runs).
let dayCloseArmed = false;
let dayCloseTimer = null;
async function renderDayClose() {
  const btn = $('dayCloseBtn'); const resEl = $('dayCloseResult');
  if (!btn) { return; }
  dayCloseArmed = false; clearTimeout(dayCloseTimer);
  btn.classList.remove('armed');
  try {
    const sum = await (await fetch(API + '?summary')).json(); // sum.date = today
    const r = await (await fetch(API + '?report&date=' + sum.date)).json();
    if (r.close) {
      btn.textContent = 'Re-close today (refresh the record)';
      const nb = (r.close.batches || []).length;
      const unb = r.close.unbatched_card || { count: 0 };
      resEl.textContent = 'Closed — ' + money(r.close.gross) + ' across ' + r.close.txns + ' sales'
        + (nb ? ' · ' + nb + ' batch' + (nb === 1 ? '' : 'es') + ' sealed' : '')
        + (unb.count ? ' · ' + unb.count + ' card sale' + (unb.count === 1 ? '' : 's') + ' not in a batch' : '');
    } else {
      btn.textContent = 'Close the day';
      resEl.textContent = '';
    }
  } catch (e) { btn.textContent = 'Close the day'; }
}
async function dayCloseAction() {
  const btn = $('dayCloseBtn'); if (!btn) { return; }
  if (!dayCloseArmed) {
    dayCloseArmed = true; btn.textContent = 'Tap again to confirm close'; btn.classList.add('armed');
    dayCloseTimer = setTimeout(() => { if (dayCloseArmed) { renderDayClose(); } }, 4000);
    return;
  }
  dayCloseArmed = false; clearTimeout(dayCloseTimer);
  const r = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ close_day: true }) });
  const rec = await r.json();
  showToast('Day closed — ' + money(rec.gross) + ' across ' + rec.txns + ' sales.');
  refreshToday(); renderDayClose(); showReport('today');
}

// --- owner panel: Reports card (manual twin of the chat's numbers) ----------
// Same day-summary data the chat speaks, for a picked period; a date with a
// saved close record shows the record's frozen figures, marked Closed.
async function showReport(mode, dateVal) {
  document.querySelectorAll('[data-report]').forEach(b =>
    b.classList.toggle('on', b.dataset.report === mode));
  const body = $('reportBody');
  if (mode === 'week') {
    const r = await (await fetch(API + '?report&week=1')).json();
    body.innerHTML = r.days.map(d =>
      `<div class="rep-row"><span>${d.date}</span><b>${money(d.gross)}</b>` +
      `<span>${d.txns} sales · cash ${money(d.cash)} · card ${money(d.card)}${d.closed ? ' · <em class="rep-closed">Closed</em>' : ''}</span></div>`).join('') +
      `<div class="rep-row rep-total"><span>This week</span><b>${money(r.totals.gross)}</b>` +
      `<span>${r.totals.txns} sales · cash ${money(r.totals.cash)} · card ${money(r.totals.card)}</span></div>`;
    return;
  }
  const sum = await (await fetch(API + '?summary')).json(); // sum.date = the register's today
  let date = sum.date;
  if (mode === 'yesterday') {
    const d = new Date(sum.date + 'T12:00:00'); d.setDate(d.getDate() - 1);
    date = d.toISOString().slice(0, 10);
  }
  if (mode === 'custom' && dateVal) { date = dateVal; }
  const r = await (await fetch(API + '?report&date=' + date)).json();
  const src = r.close || r.summary;
  body.innerHTML =
    `<div class="rep-row rep-total"><span>${date}${r.close ? ' · <em class="rep-closed">Closed</em>' : ''}</span>` +
    `<b>${money(src.gross)}</b><span>${src.txns} sales · cash ${money(src.cash)} · card ${money(src.card)}</span></div>`;
}
document.querySelectorAll('[data-report]').forEach(b =>
  b.addEventListener('click', () => showReport(b.dataset.report)));
$('reportDate').addEventListener('change', (e) => { if (e.target.value) { showReport('custom', e.target.value); } });
$('receiptPrint').addEventListener('click', () => window.print());
$('dayCloseBtn').addEventListener('click', dayCloseAction);

// --- Settings dropdown: the counter operations, in ONE menu ------------------
// Owner decision (Oct 28): the counter's working settings live behind one
// Settings dropdown on the register — not loose buttons, not chat-only.
// Register UI language stays English; the chat's language mirroring is
// untouched. Every item here runs the register's existing paths: the batch
// seals through the Gol brain (same staging, same PIN gate), the day close
// through api.php close_day, the printer toggle writes the ONE settings
// store the owner panel form and the chat also write.
function setToggle(btn, on, text) {
  if (!btn) { return; }
  btn.classList.toggle('on', !!on);
  btn.setAttribute('aria-pressed', on ? 'true' : 'false');
  btn.textContent = text || (on ? 'On' : 'Off');
}
function settingsMenuOpen() { return !$('settingsPop').classList.contains('hidden'); }
function closeSettingsMenu() {
  $('settingsPop').classList.add('hidden');
  $('settingsBtn').setAttribute('aria-expanded', 'false');
}
function openSettingsMenu() {
  $('settingsPop').classList.remove('hidden');
  $('settingsBtn').setAttribute('aria-expanded', 'true');
  $('smBatchNote').classList.add('hidden');
  $('smBatchPin').classList.add('hidden');
  smCloseArmed = false; clearTimeout(smCloseTimer);
  const dc = $('smDayCloseBtn');
  dc.textContent = 'Close day'; dc.classList.remove('armed');
  refreshSettingsMenu();
}
async function refreshSettingsMenu() {
  await loadSettings();
  const st = receiptSettings || {};
  setToggle($('smPrinterBtn'), st.auto_open !== false);
  setToggle($('smStoreBtn'), storeIsOpen(), storeIsOpen() ? 'Open' : 'Closed');
  setToggle($('smTaxBtn'), taxExempt);
  refreshBatchRow();
}
// --- the batch row: state comes fresh from the server on every open --------
let smBatchStaged = null;   // a batch_close card the brain has staged
let smBatchSegCount = 0;    // unbatched card sales at last refresh
async function refreshBatchRow() {
  const info = $('smBatchInfo'); const btn = $('smBatchBtn');
  try {
    const b = await (await fetch(API + '?batch')).json();
    const p = await (await fetch(API + '?pending')).json();
    smBatchSegCount = (b.unbatched && b.unbatched.count) || 0;
    smBatchStaged = (p && p.type === 'batch_close') ? p : null;
    if (smBatchStaged) {
      info.textContent = 'Staged: ' + smBatchStaged.count + ' card sales · ' + money(smBatchStaged.total);
      btn.textContent = 'Confirm seal'; btn.disabled = false;
    } else if (smBatchSegCount > 0) {
      info.textContent = smBatchSegCount + ' card sales · ' + money(b.unbatched.total) + ' not in a batch';
      btn.textContent = 'Seal batch'; btn.disabled = false;
    } else {
      info.textContent = 'All card sales are in a batch.';
      btn.textContent = 'Seal batch'; btn.disabled = true;
    }
  } catch (e) {
    info.textContent = 'Batch state unavailable.'; btn.disabled = true;
  }
}
function smBatchNoteShow(text) {
  const n = $('smBatchNote');
  n.textContent = text; n.classList.remove('hidden');
}
async function smBatchAction() {
  const hadWork = !!smBatchStaged || smBatchSegCount > 0;
  const payload = smBatchStaged ? { confirm: true } : { message: 'Batch close' };
  const res = await fetch(BRAIN, { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(brainPayload(payload)) });
  const r = await res.json();
  if (r.card && r.card.type === 'batch_close') {
    // Staged — the seal itself is the next tap, still inside the menu.
    smBatchStaged = r.card;
    $('smBatchInfo').textContent = 'Staged: ' + r.card.count + ' card sales · ' + money(r.card.total);
    const btn = $('smBatchBtn'); btn.textContent = 'Confirm seal'; btn.disabled = false;
    smBatchNoteShow((r.reply || 'Card staged.') + ' Tap "Confirm seal" to finish.');
    return;
  }
  if (r.reply) { smBatchNoteShow(r.reply); }
  // No card and no completion after real work was on the table means the
  // access gate is holding the seal for the admin PIN — say where it goes.
  $('smBatchPin').classList.toggle('hidden', !(!r.card && !r.done && hadWork));
  smBatchStaged = null;
  await refreshBatchRow();
  if (r.done) {
    if (r.reply) { showToast(r.reply); }
    closeSettingsMenu();
  }
}
// --- the day-close row: same arm-then-run tap-confirm as the owner panel ---
let smCloseArmed = false;
let smCloseTimer = null;
async function smDayCloseAction() {
  const btn = $('smDayCloseBtn');
  if (!smCloseArmed) {
    smCloseArmed = true; btn.textContent = 'Tap again to confirm close'; btn.classList.add('armed');
    smCloseTimer = setTimeout(() => {
      if (smCloseArmed) { smCloseArmed = false; btn.textContent = 'Close day'; btn.classList.remove('armed'); }
    }, 4000);
    return;
  }
  smCloseArmed = false; clearTimeout(smCloseTimer);
  btn.textContent = 'Close day'; btn.classList.remove('armed');
  const r = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ close_day: true }) });
  const rec = await r.json();
  const nb = (rec.batches || []).length;
  const unb = rec.unbatched_card || { count: 0, total: 0 };
  showToast('Day closed — ' + money(rec.gross) + ' across ' + rec.txns + ' sales.'
    + (nb ? ' ' + nb + ' batch' + (nb === 1 ? '' : 'es') + ' sealed.' : '')
    + (unb.count ? ' ' + unb.count + ' card sale' + (unb.count === 1 ? '' : 's')
      + ' (' + money(unb.total) + ') not in a batch.' : ''));
  refreshToday();
  closeSettingsMenu();
}
// --- the reprint row: today's most recent sale, rendered like the original -
async function smReprintAction() {
  let sale = null;
  try { sale = await (await fetch(API + '?last_sale')).json(); } catch (e) { /* handled below */ }
  closeSettingsMenu();
  if (!sale || !sale.id) { showToast('No sales yet today — nothing to reprint.'); return; }
  await loadSettings();
  showReceipt(sale);
}
// --- toggles ------------------------------------------------------------------
async function saveSetting(key, value) {
  const r = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ settings: { [key]: value } }) });
  receiptSettings = await r.json();
}
async function smPrinterAction() {
  const on = (receiptSettings || {}).auto_open !== false;
  await saveSetting('auto_open', !on);
  setToggle($('smPrinterBtn'), !on);
  showToast(!on ? 'Printer on — the receipt prints after each sale.'
                : 'Printer off — receipts will not print after a sale.');
}
async function smStoreAction() {
  const open = storeIsOpen();
  await saveSetting('register_open', !open);
  applyStorePill();
  setToggle($('smStoreBtn'), !open, !open ? 'Open' : 'Closed');
  showToast(!open ? 'Store is open — sales are running.'
                  : 'Store is closed — new sales are paused.');
}
function smTaxAction() {
  taxExempt = !taxExempt;
  setToggle($('smTaxBtn'), taxExempt);
  renderCart();
}
$('settingsBtn').addEventListener('click', (e) => {
  e.stopPropagation();
  settingsMenuOpen() ? closeSettingsMenu() : openSettingsMenu();
});
$('settingsPop').addEventListener('click', (e) => e.stopPropagation());
document.addEventListener('click', () => { if (settingsMenuOpen()) { closeSettingsMenu(); } });
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && settingsMenuOpen()) { closeSettingsMenu(); }
});
$('smBatchBtn').addEventListener('click', smBatchAction);
$('smBatchPin').addEventListener('click', () => {
  closeSettingsMenu();
  $('chatDrawer').classList.remove('hidden');
  $('chatInput').focus();
});
$('smDayCloseBtn').addEventListener('click', smDayCloseAction);
$('smReprintBtn').addEventListener('click', smReprintAction);
$('smPrinterBtn').addEventListener('click', smPrinterAction);
$('smStoreBtn').addEventListener('click', smStoreAction);
$('smTaxBtn').addEventListener('click', smTaxAction);

// --- entry-mode tabs: Products grid ↔ register Keypad -----------------------
// The left panel holds two entry modes. Products is the scanning surface;
// Keypad is the manual-amount surface — a hand-typed amount, taxable or
// not, lands in the same cart as a line the sale records for what it is.
// The cart lives outside both panes, so flipping tabs never loses a sale,
// and the category chips belong to Products mode only.
let entryMode = 'products';
function setEntryMode(mode) {
  entryMode = mode === 'keypad' ? 'keypad' : 'products';
  $('productsPane').classList.toggle('hidden', entryMode !== 'products');
  $('keypadPane').classList.toggle('hidden', entryMode !== 'keypad');
  $('tabProducts').classList.toggle('on', entryMode === 'products');
  $('tabKeypad').classList.toggle('on', entryMode === 'keypad');
  $('tabProducts').setAttribute('aria-selected', entryMode === 'products' ? 'true' : 'false');
  $('tabKeypad').setAttribute('aria-selected', entryMode === 'keypad' ? 'true' : 'false');
}
// The keypad buffer is a decimal string the LCD readout formats as money;
// the cap matches the server's manual-amount limit ($9,999.99).
const MANUAL_CAP = 9999.99;
let kpBuffer = '';
function kpShow() {
  const v = kpBuffer === '' ? 0 : parseFloat(kpBuffer);
  $('kpAmount').textContent = money(Number.isFinite(v) ? v : 0);
}
function kpErrorShow(text) {
  const el = $('kpError');
  el.textContent = text; el.classList.remove('hidden');
}
function kpErrorHide() { $('kpError').classList.add('hidden'); }
function kpPress(key) {
  kpErrorHide();
  if (key === 'B') { kpBuffer = kpBuffer.slice(0, -1); kpShow(); return; }
  if (key === 'C') { kpBuffer = ''; kpShow(); return; }
  if (key === '.') {
    if (!kpBuffer.includes('.')) { kpBuffer = (kpBuffer === '' ? '0' : kpBuffer) + '.'; }
    kpShow(); return;
  }
  if (!/^\d$/.test(key)) { return; }
  const dot = kpBuffer.indexOf('.');
  if (dot >= 0) { if (kpBuffer.length - dot > 2) { return; } } // two decimals max
  else if (kpBuffer.replace(/^0+/, '').length >= 4) { return; } // ≤ $9,999 before the point
  kpBuffer = kpBuffer === '0' ? key : kpBuffer + key;
  kpShow();
}
function kpAdd(taxable) {
  if (!storeIsOpen()) { storeClosedNote(); return; }
  const v = kpBuffer === '' ? NaN : Math.round((parseFloat(kpBuffer) + Number.EPSILON) * 100) / 100;
  if (!(v > 0)) { kpErrorShow('Key an amount above $0.00 first.'); return; }
  if (v > MANUAL_CAP) { kpErrorShow('Manual amounts are capped at $9,999.99 — ring large items from the catalog.'); return; }
  manualLines.push({ id: 'm' + (++manualSeq), price: v, taxable });
  kpBuffer = ''; kpShow(); kpErrorHide();
  renderCart();
  showToast('Manual amount added — ' + (taxable ? 'TAXABLE ' : 'NON-TAX ') + money(v));
}
$('tabProducts').addEventListener('click', () => setEntryMode('products'));
$('tabKeypad').addEventListener('click', () => setEntryMode('keypad'));
$('manualBtn').addEventListener('click', () => {
  if (!storeIsOpen()) { storeClosedNote(); return; }
  setEntryMode('keypad');
});
document.querySelectorAll('[data-kp]').forEach(b =>
  b.addEventListener('click', () => kpPress(b.dataset.kp)));
$('kpClearBtn').addEventListener('click', () => kpPress('C'));
$('kpTaxableBtn').addEventListener('click', () => kpAdd(true));
$('kpNonTaxBtn').addEventListener('click', () => kpAdd(false));
// A physical keyboard drives the same pad while the Keypad tab is up —
// digits, ".", Backspace, and Enter for Add · TAXABLE — unless the
// cashier is typing into a field (scan, search, chat).
document.addEventListener('keydown', (e) => {
  if (entryMode !== 'keypad') { return; }
  if (e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA')) { return; }
  if (/^\d$/.test(e.key) || e.key === '.') { kpPress(e.key); }
  else if (e.key === 'Backspace') { e.preventDefault(); kpPress('B'); }
  else if (e.key === 'Enter') { e.preventDefault(); kpAdd(true); }
});
kpShow();

// --- launch binding: MR.STORE's settings link opens this register with
// ?store=<id>&name=<name>&unlock=1 — the same contract MR.STORE's own
// pos.html boot() reads. The launch context is recorded server-side; the
// MR.STORE link engages only for the store the link names (this demo
// build is fenced to one store — any other gets a plain note and the
// standalone register).
async function bindLaunch() {
  let sp;
  try { sp = new URLSearchParams(location.search); } catch (e) { return; }
  const store = sp.get('store');
  if (!store) { return; }
  try {
    const r = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ bind: { store, name: sp.get('name') || '', unlock: sp.get('unlock') === '1' } }) });
    const st = await r.json();
    if (st && st.note) { showToast(st.note); }
  } catch (e) { /* the register never stops for the link */ }
}

bindLaunch().then(() => { loadCatalog(); loadSettings(); });
