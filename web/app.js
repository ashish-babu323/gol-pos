// Gol POS — register front end (Day 1 skeleton: full ring-out path + Gol chat).
'use strict';
const API = '../server/api.php';
const BRAIN = '../server/brain.php';
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
}
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
let cartTotals = { subtotal: 0, tax: 0, total: 0 };
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
  const roundMoney = (n) => Math.round((n + Number.EPSILON) * 100) / 100;
  const sub = roundMoney(lines.reduce((s, l) => s + l.total, 0));
  const tax = roundMoney(sub * (catalog.store.tax_rate || 0));
  cartTotals = { subtotal: sub, tax, total: roundMoney(sub + tax) };
  $('subTotal').textContent = money(sub);
  $('taxTotal').textContent = money(tax);
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
  rows.push(...sale.lines.map(l => `${l.name} x${l.qty}  ${money(l.line_total)}`));
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
async function tender(kind, tendered) {
  if (!cart.size) { return false; }
  const lines = [...cart.entries()].map(([sku, qty]) => ({ sku, qty }));
  const salePayload = { lines, tender: kind };
  if (kind === 'cash') { salePayload.tendered = Number(Number(tendered || 0).toFixed(2)); }
  const res = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ sale: salePayload }) });
  const sale = await res.json();
  if (!res.ok || sale.error) {
    showToast(sale.error || 'The sale could not be recorded — the cart is unchanged.');
    return false;
  }
  await loadSettings();
  const st = receiptSettings || {};
  $('receiptLogo').style.display = st.logo ? 'block' : 'none';
  const card = document.querySelector('.receipt-card');
  card.classList.toggle('w58', st.paper_width === '58mm');
  card.classList.toggle('w80', st.paper_width !== '58mm');
  const n = parseInt(st.copies, 10) || 1;
  $('receiptTitle').textContent = 'Receipt' + (n > 1 ? ' ×' + n : '');
  $('receiptBody').textContent = receiptText(sale);
  cart.clear(); renderCart(); refreshToday();
  if (st.auto_open === false) {
    showToast('Sale rung — receipt auto-open is off. Gol chat: "auto print on gara" turns it back on.');
  } else {
    $('receiptModal').classList.remove('hidden');
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
  if (!cart.size) { return; }
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
  chatMsg(text, 'me');
  const res = await fetch(BRAIN, { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ message: text }) });
  const r = await res.json();
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
  await loadSettings(); renderSettingsForm();
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
    `<h3>Receipt print settings <small>— the same store Gol chat writes</small></h3>
     <div class="os-grid">
       <label>Store name <input id="os_store_name" type="text" value="${esc(st.store_name || '')}"></label>
       <label>Address <input id="os_address" type="text" value="${esc(st.address || '')}"></label>
       <label>Phone <input id="os_phone" type="text" value="${esc(st.phone || '')}"></label>
       <label>Footer message <input id="os_footer" type="text" value="${esc(st.footer || '')}"></label>
       <label>Copies <select id="os_copies">${opts([1, 2, 3], st.copies)}</select></label>
       <label>Paper width <select id="os_paper_width">${opts(['80mm', '58mm'], st.paper_width)}</select></label>
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
    show_address: $('os_show_address').checked, show_phone: $('os_show_phone').checked,
    logo: $('os_logo').checked, show_tax: $('os_show_tax').checked,
    auto_open: $('os_auto_open').checked } };
  const r = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload) });
  receiptSettings = await r.json();
  $('osSaved').textContent = 'Saved ✓ — Gol chat reads these same settings.';
}

// --- owner panel: Reports card (manual twin of the chat's numbers) ----------
// Same day-summary data the chat speaks, for a picked period; a date with a
// saved close record shows the record's frozen figures, marked Closed.
let closeArmed = false;
async function showReport(mode, dateVal) {
  closeArmed = false;
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
    `<b>${money(src.gross)}</b><span>${src.txns} sales · cash ${money(src.cash)} · card ${money(src.card)}</span></div>` +
    (mode === 'today' ? `<button id="repClose" type="button">${r.close ? 'Re-close today (refresh the record)' : 'Close the day'}</button>` : '');
  if (mode === 'today') { $('repClose').addEventListener('click', manualClose); }
}
// Manual close: same golpos_close_day path as the chat, behind a tap-confirm
// (first tap arms, second tap runs) — never one-tap destructive.
async function manualClose() {
  const b = $('repClose'); if (!b) { return; }
  if (!closeArmed) {
    closeArmed = true; b.textContent = 'Tap again to confirm close'; b.classList.add('armed');
    setTimeout(() => { if (closeArmed) { closeArmed = false; showReport('today'); } }, 4000);
    return;
  }
  closeArmed = false;
  const r = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ close_day: true }) });
  const rec = await r.json();
  showToast('Day closed — ' + money(rec.gross) + ' across ' + rec.txns + ' sales.');
  refreshToday(); showReport('today');
}
document.querySelectorAll('[data-report]').forEach(b =>
  b.addEventListener('click', () => showReport(b.dataset.report)));
$('reportDate').addEventListener('change', (e) => { if (e.target.value) { showReport('custom', e.target.value); } });
$('receiptPrint').addEventListener('click', () => window.print());

loadCatalog(); loadSettings();
