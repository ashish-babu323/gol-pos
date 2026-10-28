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
const IC_CAM = '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13" r="3.5"/></svg>';
const IC_CHECK = '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12.5 9.5 18 20 6.5"/></svg>';
const esc = (s) => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
// A surcharge percent as the owner says it: 3, 2.5 — never 3.0.
const fmtPct = (p) => String(Math.round(Number(p) * 10) / 10);

// --- receipt print settings: ONE store, two doors -----------------------------
// Gol chat stages changes as cards; the owner panel edits a form. Both write
// the same server-side settings (api.php ?settings) and this register renders
// every receipt from them — there is no settings menu anywhere else.
let receiptSettings = null;
async function loadSettings() {
  try { const r = await fetch(API + '?settings'); receiptSettings = await r.json(); }
  catch (e) { /* keep the last known settings; the register never stops */ }
  applyStorePill();
  syncWatchCam(); // the cash-frame camera follows the effective camera state
}
// --- Register Camera: the effective state (Part B) ----------------------------
// api.php ?settings carries a regcam block computed server-side from the
// SAME facts in both modes — the MR.STORE master + this till's flip in
// link mode, the register's own keys standalone. Every camera decision
// below reads it through these three doors, so the till can never drift
// from the truth: stream = master && flip (link) / flip (standalone).
function regcam() { return (receiptSettings && receiptSettings.regcam) || null; }
function camWanted() {
  const rc = regcam();
  if (rc) { return rc.stream === true; }
  return !!(receiptSettings && receiptSettings.watch_frame === true); // pre-regcam fallback
}
function camAudioWanted() { const rc = regcam(); return !!(rc && rc.audio === true); }
function camRecordWanted() { const rc = regcam(); return !!(rc && rc.record === true); }
function camMasterHeld() { const rc = regcam(); return !!(rc && rc.mode === 'link' && rc.master !== true); }
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
// A scanned barcode the catalog doesn't know gets a door, not a dead
// end: one tap opens the Add Product sheet with the code already in its
// barcode field (creation still PIN-gates inside the sheet, exactly as
// the Add Product button's path does). A later plain toast replaces the
// offer wholesale — showToast's textContent assignment clears the button.
function scanUnknownOffer(code) {
  const el = $('toast'); if (!el) { return; }
  el.innerHTML = '';
  const label = document.createElement('span');
  label.textContent = 'Not in the catalog — ' + code + ' ';
  const btn = document.createElement('button');
  btn.type = 'button'; btn.textContent = 'Add it?';
  btn.addEventListener('click', () => {
    el.classList.add('hidden'); el.innerHTML = '';
    $('scanInput').value = '';
    openProductSheet(code);
  });
  el.appendChild(label); el.appendChild(btn);
  el.classList.remove('hidden');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.classList.add('hidden'), 9000);
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
let lastBusyAt = Date.now(); // till activity clock — renderCart stamps it; the price-gap poll reads it
let activeCat = 'All';
let taxExempt = false; // Settings menu, current sale only — never persisted
let cardTenderPreview = false; // a card payment is being taken: the totals
// panel shows the card surcharge (the server computes the real one)
let creditApplied = null; // store credit applied to THIS sale only:
// {phone, name, balance, amount} — set by the Credit tender sheet,
// validated again by the server at tender, and dies with the sale.

async function loadCatalog() {
  const res = await fetch(API + '?products');
  catalog = await res.json();
  $('storeName').textContent = catalog.store.name || 'Register';
  renderCats(); renderGrid(); renderKpCats(); renderKpList(); refreshToday();
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
// --- the living shelf ---------------------------------------------------------
// Every tile carries its live inventory: the packshot, the count on hand,
// a state light, and a fill bar against the product's par (shelf capacity).
// Stock is tracked two ways on purpose: standalone products carry `stock`
// (the POS's own shelf, decremented by api.php as sales record); linked
// MR.STORE products carry `onHand` (already net of the sale by the time
// the payload lands). A product with neither is untracked — no bar, no
// count, and it rings exactly as before.
function stockOf(p) {
  const v = (p.stock !== undefined && p.stock !== null) ? p.stock
    : (p.onHand !== undefined ? p.onHand : null);
  if (v === null || v === undefined || isNaN(Number(v))) { return null; }
  return Number(v);
}
const PAR_BY_CAT = { 'Drinks': 24, 'Snacks': 20, 'Grocery': 12, 'Household': 10,
                     'Health': 15, 'Beer and Wine': 24, 'Counter': 30 };
function parOf(p) {
  const par = Number(p.par);
  return par > 0 ? par : (PAR_BY_CAT[p.category] || 24);
}
// The bar wears the product's nature: liquids (Drinks, Beer and Wine —
// and their MR.STORE namesakes) pour, solids sit. In link mode every
// product arrives under one category, so the name carries the nature.
const LIQUID_WORDS = /(cola|pepsi|sprite|dew|pepper|gatorade|red bull|monster|tea|coffee|juice|water|beer|wine|heineken|bud light|corona|milk)/i;
function isLiquid(p) {
  if (p.category === 'Drinks' || p.category === 'Beer and Wine') { return true; }
  if (p.category && p.category !== 'MR.STORE') { return false; }
  return LIQUID_WORDS.test(p.name || '');
}
function stockState(stock, par) {
  if (stock <= 0) { return 'out'; }
  const pct = par > 0 ? (stock / par) * 100 : 100;
  return pct < 20 ? 'crit' : (pct < 50 ? 'low' : 'ok');
}
const fmtCount = (n) => Number.isInteger(n) ? String(n) : String(Math.round(n * 10) / 10);
function stockHtml(p) {
  const s = stockOf(p);
  if (s === null) { return ''; }
  const par = parOf(p);
  const pct = Math.max(0, Math.min(100, par > 0 ? (s / par) * 100 : 0));
  const state = stockState(s, par);
  const count = s <= 0 ? 'Out of stock'
    : (state === 'ok' ? fmtCount(s) + ' in stock' : 'Only ' + fmtCount(s) + ' left');
  return `<span class="tile-stock">
      <span class="tile-stock-row"><span class="tile-dot st-${state}"></span><span class="tile-count st-${state}">${count}</span></span>
      <span class="stockbar"><span class="stockfill ${isLiquid(p) ? 'liquid' : 'solid'} st-${state}" data-sku="${p.sku}" data-pct="${pct}" style="width:${pct}%"></span></span>
    </span>`;
}
// The last fill each tile showed, by sku — a refresh starts the bar at
// the level it last showed and lets the CSS transition pour/drain it to
// the new one, instead of snapping. One tracker serves both shelf
// presentations (grid tiles and Keypad-pane rows): whichever renders a
// fill first after a change animates it, the other lands on the truth.
let lastFill = {};
function animateFills(scopeEl) {
  scopeEl.querySelectorAll('.stockfill').forEach(f => {
    const pct = parseFloat(f.dataset.pct);
    const prev = lastFill[f.dataset.sku];
    if (prev !== undefined && Math.abs(prev - pct) > 0.01) {
      f.style.width = prev + '%';
      requestAnimationFrame(() => requestAnimationFrame(() => { f.style.width = pct + '%'; }));
    }
    lastFill[f.dataset.sku] = pct;
  });
}
function renderGrid() {
  const q = $('searchInput').value.trim().toLowerCase();
  const items = catalog.products.filter(p =>
    (activeCat === 'All' || p.category === activeCat) &&
    (!q || p.name.toLowerCase().includes(q) || p.barcode.includes(q)));
  $('grid').innerHTML = items.map(p => {
    const s = stockOf(p);
    const out = s !== null && s <= 0;
    return `<div class="tile${out ? ' is-out' : ''}" data-sku="${p.sku}" role="button" tabindex="0" aria-label="${esc(p.name)} — tap to ring">
       <span class="tile-media"><span class="tile-art ${artClass(p)}">${(p.name || 'G').trim().charAt(0).toUpperCase()}</span>${p.img ? `<img class="tile-img" src="${esc(p.img)}" alt="" loading="lazy" onerror="this.remove()">` : ''}${out ? '<span class="tile-out-tag">OUT</span>' : ''}</span>
       <span class="tile-name">${p.name}</span>${stockHtml(p)}<span class="tile-foot"><span class="tile-price">${money(p.price)}</span><button type="button" class="tile-stock-add${out ? ' rescue' : ''}" data-stock-sku="${p.sku}" aria-label="Add stock to ${esc(p.name)}">＋ Stock</button></span>
     </div>`;
  }).join('') || '<p class="empty">No products match.</p>';
  $('grid').querySelectorAll('.tile').forEach(t => {
    t.addEventListener('click', () => addToCart(t.dataset.sku));
    t.addEventListener('keydown', (e) => {
      if ((e.key === 'Enter' || e.key === ' ') && e.target === t) {
        e.preventDefault(); addToCart(t.dataset.sku);
      }
    });
  });
  $('grid').querySelectorAll('[data-stock-sku]').forEach(b =>
    b.addEventListener('click', (e) => { e.stopPropagation(); openStockSheet(b.dataset.stockSku); }));
  animateFills($('grid'));
}
function addToCart(sku, opts) {
  if (!storeIsOpen()) { storeClosedNote(); return; }
  const p = catalog.products.find(x => x.sku === sku);
  // An empty shelf refuses the tap with plain words (the Gol chat's add
  // path passes viaChat and keeps its own behavior, untouched).
  if (p && !(opts && opts.viaChat)) {
    const s = stockOf(p);
    if (s !== null && s <= 0) { showToast('Out of stock'); return; }
  }
  const n = (opts && opts.qty) ? Math.max(1, Math.floor(opts.qty)) : 1;
  cart.set(sku, (cart.get(sku) || 0) + n);
  renderCart();
}
// --- the living shelf, second presentation: Keypad-pane product rows -------
// The Keypad pane pairs the pad with the same catalog as compact rows, so
// a cashier mid-manual-entry can still tap products in (owner directive,
// Oct 28: the pane's empty middle gets the products list). One catalog,
// one stock model — the rows read the same helpers the tiles do
// (stockOf / parOf / stockState / isLiquid) and a tap runs the very same
// addToCart, OUT refusal and toast included. The pane's own category
// chips filter the list only; the Products tab keeps its own filter.
let kpCat = 'All';
function renderKpCats() {
  const cats = ['All', ...new Set(catalog.products.map(p => p.category))];
  $('kpCatRow').innerHTML = cats.map(c =>
    `<button type="button" class="${c === kpCat ? 'on' : ''}" data-kcat="${c}">${c}</button>`).join('');
  $('kpCatRow').querySelectorAll('button').forEach(b =>
    b.addEventListener('click', () => { kpCat = b.dataset.kcat; renderKpCats(); renderKpList(); }));
}
function rowStockHtml(p) {
  const s = stockOf(p);
  if (s === null) { return ''; }
  const par = parOf(p);
  const pct = Math.max(0, Math.min(100, par > 0 ? (s / par) * 100 : 0));
  const state = stockState(s, par);
  const count = s <= 0 ? 'Out of stock'
    : (state === 'ok' ? fmtCount(s) + ' in stock' : 'Only ' + fmtCount(s) + ' left');
  return `<span class="kp-row-signals">
      <span class="kp-row-count"><span class="tile-dot st-${state}"></span><span class="tile-count st-${state}">${count}</span></span>
      <span class="stockbar kp-bar"><span class="stockfill ${isLiquid(p) ? 'liquid' : 'solid'} st-${state}" data-sku="${p.sku}" data-pct="${pct}" style="width:${pct}%"></span></span>
    </span>`;
}
function renderKpList() {
  const items = catalog.products.filter(p => kpCat === 'All' || p.category === kpCat);
  $('kpList').innerHTML = items.map(p => {
    const s = stockOf(p);
    const out = s !== null && s <= 0;
    return `<button type="button" class="kp-row${out ? ' is-out' : ''}" data-sku="${p.sku}">
       <span class="kp-thumb"><span class="tile-art ${artClass(p)}">${(p.name || 'G').trim().charAt(0).toUpperCase()}</span>${p.img ? `<img src="${esc(p.img)}" alt="" loading="lazy" onerror="this.remove()">` : ''}</span>
       <span class="kp-row-main">
         <span class="kp-row-top"><span class="kp-row-name">${p.name}</span><span class="kp-row-price">${money(p.price)}</span></span>${rowStockHtml(p)}
       </span>
     </button>`;
  }).join('') || '<p class="empty">No products match.</p>';
  $('kpList').querySelectorAll('.kp-row').forEach(r =>
    r.addEventListener('click', () => addToCart(r.dataset.sku)));
  animateFills($('kpList'));
}
function renderCart() {
  lastBusyAt = Date.now();
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
  if (!lines.length && !manualLines.length && taxExempt) {
    // An exemption armed for a sale that no longer exists dies with it —
    // a lingering EXEMPT badge on an empty cart reads as a bug, not a
    // setting (owner screenshot, Oct 28). The Settings toggle re-reads
    // this same flag on open, so the menu never disagrees.
    taxExempt = false; setToggle($('smTaxBtn'), false);
  }
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
  // Card surcharge (owner setting, Oct 28): a percent of subtotal + tax,
  // added to CARD sales only — cash never carries it. While a card
  // payment is being taken the panel shows the line and the total the
  // customer will pay; the server computes the recorded surcharge from
  // the same setting, so this display never decides the charge.
  // Credit applied to a cart that no longer exists dies with it, like
  // the tax exemption above — a lingering credit on an empty cart
  // would read as money from nowhere.
  if (!lines.length && !manualLines.length && creditApplied) { creditApplied = null; }
  const pct = Number((receiptSettings || {}).card_surcharge_pct) || 0;
  // With a credit part, the card surcharge falls only on the card-paid
  // share — the same base the server computes from.
  const creditAmt = creditApplied ? Math.min(creditApplied.amount, roundMoney(sub + tax)) : 0;
  const surcharge = (cardTenderPreview && pct > 0)
    ? roundMoney((sub + tax - creditAmt) * pct / 100) : 0;
  cartTotals = { subtotal: sub, tax, surcharge, total: roundMoney(sub + tax + surcharge), credit: creditAmt };
  $('subTotal').textContent = money(sub);
  $('taxTotal').textContent = money(tax);
  $('taxBadge').classList.toggle('hidden', !taxExempt);
  $('surchargeRow').classList.toggle('hidden', !(surcharge > 0));
  if (surcharge > 0) {
    $('surchargeLabel').textContent = 'Card surcharge (' + fmtPct(pct) + '%)';
    $('surchargeTotal').textContent = money(surcharge);
  }
  $('creditRow').classList.toggle('hidden', !(creditAmt > 0));
  $('creditDueRow').classList.toggle('hidden', !(creditAmt > 0));
  if (creditAmt > 0) {
    $('creditTotal').textContent = '−' + money(creditAmt);
    $('creditDue').textContent = money(cartTotals.total - creditAmt);
  }
  $('grandTotal').textContent = money(cartTotals.total);
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
  if (Number(sale.surcharge) > 0) {
    const p = (sale.surcharge_pct !== undefined && sale.surcharge_pct !== null)
      ? ' (' + fmtPct(sale.surcharge_pct) + '%)' : '';
    rows.push(`Card surcharge${p}  ${money(sale.surcharge)}`);
  }
  rows.push(`TOTAL     ${money(sale.total)}`);
  // Tender lines: a split sale names every part — credit is a tender,
  // printed like one, never folded into a discount line.
  if (sale.tenders && typeof sale.tenders === 'object') {
    if (Number(sale.tenders.credit) > 0) { rows.push(`Store credit −${money(sale.tenders.credit)}`); }
    if (Number(sale.tenders.card) > 0) { rows.push(`Paid by card ${money(sale.tenders.card)}`); }
    if (Number(sale.tenders.cash) > 0) { rows.push(`Paid by cash ${money(sale.tenders.cash)}`); }
  } else {
    rows.push(`Paid by ${sale.tender}`);
  }
  if (sale.tender === 'cash' && sale.tendered !== undefined && sale.tendered !== null) {
    rows.push(`Cash given ${money(sale.tendered)}`, `Change    ${money(sale.change_due || 0)}`);
  }
  if (Number(sale.refunded_total) > 0) {
    rows.push(`Refunded  ${money(sale.refunded_total)}` + (sale.refund_status === 'full' ? ' (in full)' : ' (partial)'));
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
  // (the session rides as a sibling of sale below — api.php reads it
  // for cashier attribution on the record)
  if (kind === 'cash') { salePayload.tendered = Number(Number(tendered || 0).toFixed(2)); }
  if (creditApplied && creditApplied.amount > 0) {
    // Clamp to this sale's credit-coverable base (subtotal + tax): the
    // cart may have shrunk since the credit was applied. The server
    // validates the same bounds again before anything records.
    const cap = Math.round(((cartTotals.subtotal || 0) + (cartTotals.tax || 0)) * 100) / 100;
    salePayload.credit = { phone: creditApplied.phone, amount: Math.min(creditApplied.amount, cap) };
  }
  if (taxExempt) { salePayload.tax_exempt = true; }
  const res = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ sale: salePayload, session: SESSION_ID }) });
  const sale = await res.json();
  if (!res.ok || sale.error) {
    showToast(sale.error || 'The sale could not be recorded — the cart is unchanged.');
    return false;
  }
  await loadSettings();
  await loadCatalog(); // the rung units leave the shelf — counts and bars move
  const st = receiptSettings || {};
  taxExempt = false; // the exemption dies with the sale it was set for
  cardTenderPreview = false; // and the surcharge preview with it
  creditApplied = null; // and the applied credit with it
  setToggle($('smTaxBtn'), false);
  cart.clear(); manualLines = []; renderCart(); refreshToday();
  if (st.auto_open === false) {
    showToast('Sale rung — receipt auto-open is off. Turn Printer on in Settings to bring it back.');
  } else {
    showReceipt(sale);
  }
  // Register Watch: a completed CASH sale pairs its counter frame when
  // the owner turned the watch on and a camera is live. Fire-and-forget
  // AFTER the receipt — the frame is a passenger, never a gate.
  if (kind === 'cash') { attachCashFrame(sale.id); recordCashClip(sale.id); }
  return true;
}
// Cash is a register step, not a blind button: the cashier sees the total,
// enters or taps the cash handed over, and sees the change before the sale
// can complete. The server repeats the same calculation and refuses a
// short tender, so the recorded receipt can never invent change.
// The cash a customer still owes on this sale: the total minus any
// store credit applied (credit is a tender — the cash drawer only
// ever sees the remainder).
function cashDueNow() {
  return Math.round(((cartTotals.total || 0) - (cartTotals.credit || 0)) * 100) / 100;
}
function updateCashPanel() {
  const total = cashDueNow();
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
  $('cashReceived').value = cashDueNow().toFixed(2);
  updateCashPanel();
  $('cashModal').classList.remove('hidden');
  $('cashReceived').focus(); $('cashReceived').select();
}
async function completeCashSale() {
  const tendered = parseFloat($('cashReceived').value || '0') || 0;
  if (tendered < cashDueNow()) { updateCashPanel(); return; }
  if (await tender('cash', tendered)) { $('cashModal').classList.add('hidden'); }
}
async function refreshToday() {
  const res = await fetch(API + '?summary');
  const s = await res.json();
  $('todayTotal').textContent = 'Today: ' + money(s.gross) + ' (' + s.txns + ')';
}

// --- add stock from the tile: the receive sheet ------------------------------
// Every tile wears a quiet "+ Stock" button (on an OUT tile it is the
// rescue). The sheet is a register step like the cash panel: pick the
// quantity, tap Add — the receive runs the server's ONE receive path
// (api.php stock_add → golpos_receive_stock, the same stock write the
// Gol chat's stock_receive card makes). Receiving is admin work, one
// rule everywhere: an unelevated session writes nothing, and the PIN
// is asked INSIDE the sheet — but the PIN itself runs through
// brain.php, driven with the same session messages the chat sends, so
// the create flow, wrong-PIN attempts and the 3-strike lockout stay
// the brain's own. Once the session is elevated, receives never ask
// again on this device.
let stockSheet = null; // { sku, pinStep, armed }
function openStockSheet(sku) {
  const p = catalog.products.find(x => x.sku === sku);
  if (!p) { return; }
  stockSheet = { sku, pinStep: null, armed: false };
  const art = $('stockArt');
  art.textContent = (p.name || 'G').trim().charAt(0).toUpperCase();
  art.className = 'tile-art ' + artClass(p);
  const img = $('stockImg');
  if (p.img) { img.src = p.img; img.classList.remove('hidden'); img.onerror = () => img.classList.add('hidden'); }
  else { img.classList.add('hidden'); img.removeAttribute('src'); }
  $('stockName').textContent = p.name;
  const s = stockOf(p);
  $('stockNow').textContent = s === null ? 'Stock is not tracked yet'
    : (s <= 0 ? 'Now: out of stock' : 'Now: ' + fmtCount(s) + ' in stock');
  $('stockQty').value = '12';
  updateStockAddLabel();
  stockNoteShow('');
  $('stockQtyStep').classList.remove('hidden');
  $('stockPinStep').classList.add('hidden');
  $('stockModal').classList.remove('hidden');
  $('stockQty').focus(); $('stockQty').select();
}
function closeStockSheet() {
  $('stockModal').classList.add('hidden');
  stockSheet = null;
}
function stockQtyValue() { return Number($('stockQty').value); }
function updateStockAddLabel() {
  const q = stockQtyValue();
  $('stockAddBtn').textContent = Number.isInteger(q) && q >= 1 ? 'Add ' + q + ' to stock' : 'Add to stock';
}
function stockNoteShow(text) {
  const el = $('stockNote');
  el.textContent = text || '';
  el.classList.toggle('hidden', !text);
}
function stockPinNoteShow(text) {
  const el = $('stockPinError');
  el.textContent = text || '';
  el.classList.toggle('hidden', !text);
}
async function stockAddRequest(qty, sku) {
  const res = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ stock_add: { sku: sku || (stockSheet && stockSheet.sku), qty }, session: SESSION_ID }) });
  let body = {};
  try { body = await res.json(); } catch (e) { /* handled by caller */ }
  return body;
}
async function brainSay(text) {
  const res = await fetch(BRAIN, { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(brainPayload({ message: text })) });
  return res.json();
}
async function submitStockAdd() {
  if (!stockSheet) { return; }
  const q = stockQtyValue();
  if (!Number.isInteger(q) || q < 1 || q > 9999) {
    stockNoteShow('Enter a whole number from 1 to 9999.');
    $('stockQty').focus();
    return;
  }
  stockNoteShow('');
  const body = await stockAddRequest(q);
  if (!stockSheet) { return; } // the sheet was cancelled mid-flight
  if (body && body.ok) { await finishStockReceive(body); return; }
  if (body && body.pin) { await enterStockPinStep(body); return; }
  stockNoteShow((body && body.error) || 'The receive could not be written — stock is unchanged.');
}
// The PIN step: arm the brain's own gate with the bare admin ask (the
// gate sets the stage — create or verify — and the lockout state,
// exactly as a chat ask would), then let the brain consume the digits.
async function enterStockPinStep(info) {
  if (info.pin === 'locked') {
    setStockPinStep('locked', 'Admin entry is locked after too many wrong PINs — try again in '
      + (info.locked_minutes || 10) + ' min.');
    return;
  }
  const arm = await brainSay('admin');
  stockSheet.armed = true;
  setStockPinStep(info.pin === 'create' ? 'create1' : 'verify',
                  (arm && arm.reply) || 'Enter the admin PIN.');
}
function setStockPinStep(step, note) {
  if (!stockSheet) { return; }
  stockSheet.pinStep = step;
  $('stockQtyStep').classList.add('hidden');
  $('stockPinStep').classList.remove('hidden');
  $('stockPinNote').textContent = note || '';
  stockPinNoteShow('');
  const locked = step === 'locked';
  $('stockPinLabel').classList.toggle('hidden', locked);
  $('stockPinBtn').classList.toggle('hidden', locked);
  $('stockPinCancel').textContent = locked ? 'Close' : 'Cancel';
  $('stockPinBtn').textContent = step === 'create1' ? 'Create PIN & continue'
    : (step === 'create2' ? 'Confirm & add' : 'Unlock & add');
  if (!locked) { $('stockPin').value = ''; $('stockPin').focus(); }
}
// The PIN as the brain accepts it: Devanagari digits fold, 4–6 digits —
// checked here first so a malformed entry never reaches the brain's
// stage machine (its create flow advances on any well-formed entry).
function stockPinShape(v) {
  const d = String(v).trim().replace(/[०१२३४५६७८९]/g, c => '०१२३४५६७८९'.indexOf(c));
  return /^\d{4,6}$/.test(d) ? d : null;
}
async function submitStockPin() {
  if (!stockSheet || !stockSheet.pinStep || stockSheet.pinStep === 'locked') { return; }
  const pin = stockPinShape($('stockPin').value);
  if (pin === null) { stockPinNoteShow('PINs are 4 to 6 digits.'); return; }
  const stepWas = stockSheet.pinStep;
  const sku = stockSheet.sku;
  const r = await brainSay(pin);
  $('stockPin').value = '';
  if (!stockSheet) { return; } // the sheet was cancelled mid-flight
  // Elevation is the server's truth, not the reply's wording: retry the
  // receive. If it lands, the PIN did its work.
  const body = await stockAddRequest(stockQtyValue(), sku);
  if (body && body.ok) { await finishStockReceive(body); return; }
  if (body && body.pin === 'locked') {
    setStockPinStep('locked', (r && r.reply) || 'Admin entry is locked — try again later.');
    return;
  }
  if (stepWas === 'create1') {
    // A well-formed first entry always advances the brain to confirm.
    setStockPinStep('create2', (r && r.reply) || 'Once more — type the same PIN to confirm it.');
    return;
  }
  if (stepWas === 'create2') {
    // The two entries did not match — the brain reset to the start.
    setStockPinStep('create1', '');
    stockPinNoteShow((r && r.reply) || 'The PINs did not match — create it again.');
    return;
  }
  stockPinNoteShow((r && r.reply) || 'That PIN did not work.');
}
async function finishStockReceive(body) {
  const name = body.name || '';
  const now = body.stock;
  closeStockSheet();
  await loadCatalog(); // the tile's truth — the bar pours up to the new level
  showToast(name + ' — stock now ' + fmtCount(now) + '.');
}
$('stockMinus').addEventListener('click', () => {
  const q = stockQtyValue();
  $('stockQty').value = String(Math.max(1, (Number.isInteger(q) ? q : 12) - 1));
  stockNoteShow(''); updateStockAddLabel();
});
$('stockPlus').addEventListener('click', () => {
  const q = stockQtyValue();
  $('stockQty').value = String(Math.min(9999, (Number.isInteger(q) ? q : 12) + 1));
  stockNoteShow(''); updateStockAddLabel();
});
document.querySelectorAll('[data-stock-qty]').forEach(b =>
  b.addEventListener('click', () => {
    $('stockQty').value = b.dataset.stockQty;
    stockNoteShow(''); updateStockAddLabel();
  }));
$('stockQty').addEventListener('input', updateStockAddLabel);
$('stockAddBtn').addEventListener('click', submitStockAdd);
$('stockCancel').addEventListener('click', closeStockSheet);
$('stockPinBtn').addEventListener('click', submitStockPin);
$('stockPin').addEventListener('keydown', (e) => { if (e.key === 'Enter') { submitStockPin(); } });
$('stockPinCancel').addEventListener('click', () => {
  // Disarm the brain's PIN wait we armed, so the next chat message is
  // not mistaken for a PIN entry — the chat's own cancel word does it.
  if (stockSheet && stockSheet.armed) { brainSay('cancel'); }
  closeStockSheet();
});

// --- shared admin PIN sheet ---------------------------------------------------
// The register UI's admin actions — day close, batch seal, the Store
// toggle — post to api.php behind the sheet PIN gate (golpos_sheet_pin:
// the Add Product gate, on the chat's own session store). gatedApiPost
// drives it: post the action; when the
// gate answers pin_required this sheet collects the PIN — the create
// flow walks its two entries exactly as the server stages them — and
// the SAME action re-posts with the PIN riding along, so the request
// that unlocks is the request that completes the action. Cancelling
// the sheet cancels the action; nothing half-runs. Once the session is
// elevated, later admin actions pass silently — the server, never this
// sheet, decides who is admin (owner rule, Oct 28).
let pinSheetResolve = null;
function pinSheetErrorShow(text) {
  const el = $('pinSheetError');
  el.textContent = text || '';
  el.classList.toggle('hidden', !text);
}
function pinSheetAsk(gate) {
  return new Promise((resolve) => {
    pinSheetResolve = resolve;
    $('pinSheetNote').textContent = (gate && gate.note) || (gate && gate.stage === 'create1'
      ? 'No admin PIN yet — choose one (4–6 digits) to continue.'
      : "That's an admin action — enter the admin PIN.");
    pinSheetErrorShow((gate && gate.error) || '');
    $('pinSheetBtn').textContent = gate && gate.stage === 'create1' ? 'Create PIN & continue'
      : (gate && gate.stage === 'create2' ? 'Confirm & continue' : 'Unlock & continue');
    $('pinSheetInput').value = '';
    $('pinModal').classList.remove('hidden');
    $('pinSheetInput').focus();
  });
}
function pinSheetDone(pin) {
  $('pinModal').classList.add('hidden');
  $('pinSheetInput').value = '';
  const resolve = pinSheetResolve;
  pinSheetResolve = null;
  if (resolve) { resolve(pin); }
}
$('pinSheetBtn').addEventListener('click', () => {
  const pin = stockPinShape($('pinSheetInput').value);
  if (pin === null) { pinSheetErrorShow('PINs are 4 to 6 digits.'); return; }
  pinSheetDone(pin);
});
$('pinSheetCancel').addEventListener('click', () => pinSheetDone(null));
$('pinSheetInput').addEventListener('keydown', (e) => {
  if (e.key === 'Enter') { e.preventDefault(); $('pinSheetBtn').click(); }
});
async function gatedApiPost(body) {
  let pin = null;
  for (;;) {
    const payload = pin === null ? body : Object.assign({}, body, { pin });
    const res = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload) });
    const r = await res.json().catch(() => ({}));
    if (r && r.pin_required) {
      pin = await pinSheetAsk(r);
      if (pin === null) { return { cancelled: true }; }
      continue;
    }
    if (r && r.locked) {
      showToast(r.error || 'PIN entry is locked — try again later.');
      return { locked: true, error: r.error };
    }
    return r || {};
  }
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
  } else if (card.type === 'price_fill') {
    body = `<div class="sc-what">Price fill — ${esc(card.name || '')}</div>` +
      `<div class="sc-prices"><span class="sc-old">no price yet</span><span class="sc-arrow">→</span><span class="sc-new">${money(card.new_price)}</span></div>` +
      `<div class="sc-line">Read off the shelf tag by ${esc(card.by_name || 'the till')} — confirm writes it to the catalog once, and the gap closes.</div>`;
  } else if (card.type === 'cost_fill') {
    body = `<div class="sc-what">Cost fill — ${esc(card.name || '')}</div>` +
      `<div class="sc-prices"><span class="sc-old">no cost on record</span><span class="sc-arrow">→</span><span class="sc-new">${money(card.new_cost)}</span></div>` +
      `<div class="sc-line">What the store pays, entered by ${esc(card.by_name || 'the till')} — confirm writes it to the product record once.</div>`;
  } else if (card.type === 'poster') {
    body = `<div class="sc-what">Poster — ${card.name} · ${money(card.price)}</div><img src="${card.image}" alt="Offer poster preview">`;
  } else if (card.type === 'setting_change') {
    const fmt = (v) => typeof v === 'boolean' ? (v ? 'on' : 'off') : String(v);
    body = `<div class="sc-what">Print setting — ${esc(card.label || card.setting)}</div>` +
      `<div class="sc-prices"><span class="sc-old">${esc(fmt(card.old))}</span><span class="sc-arrow">→</span><span class="sc-new">${esc(fmt(card.new))}</span></div>`;
  } else if (card.type === 'batch_close') {
    body = `<div class="sc-what">Batch close — ${card.count} card sales</div>` +
      `<div class="sc-prices"><span class="sc-new">${money(card.total)}</span></div>`;
  } else if (card.type === 'credit_attach') {
    body = `<div class="sc-what">Store credit — ${esc(card.name || '')}</div>` +
      `<div class="sc-prices"><span class="sc-new">${money(card.balance)}</span></div>` +
      `<div class="sc-line">Confirm and the credit sits on this sale — it spends once, at the tender, for this sale only.</div>`;
  } else if (card.type === 'refund') {
    body = `<div class="sc-what">Refund — sale ${esc(card.sale_id || '')} · ${esc(card.sale_when || '')}</div>` +
      `<div class="sc-line">${esc(card.lines_label || '')}</div>` +
      `<div class="sc-prices"><span class="sc-new">${money(card.total)}</span></div>` +
      `<div class="sc-line">Back to ${esc(card.back_to || 'the original tender')} · returned items go back on the shelf.</div>`;
  } else if (card.type === 'employee_add') {
    const duties = (card.duties || []).map(d => `<div class="sc-line">✓ ${esc(d)}</div>`).join('');
    body = `<div class="sc-what">Add employee — ${esc(card.name ? card.name + ' · ' : '')}${esc(card.email)}</div>` +
      `<div class="sc-line">Persona: ${esc(card.role || 'cashier')} · ${esc(String((card.duties || []).length))} duties — this persona starts knowing:</div>${duties}` +
      `<div class="sc-line">Say a duty + "hata" to uncheck it, or "role closer" to re-pick the preset — or confirm.</div>`;
  } else if (card.type === 'task_assign') {
    const items = (card.items || []).map((it, i) => `<div class="sc-line">${i + 1}. ${esc(it)}</div>`).join('');
    body = `<div class="sc-what">Tasks for ${esc(card.employee_name || '')}</div>${items}` +
      `<div class="sc-line">Confirm records them and emails the list to ${esc(card.employee_email || '')}. Edit: "dosro kam hata" / "arko thap: …"</div>`;
  } else if (card.type === 'team_note') {
    body = `<div class="sc-what">Note to the boss — from ${esc(card.from_name || '')}</div>` +
      `<div class="sc-line">"${esc(card.text || '')}"</div>`;
  } else if (card.type === 'pay_send') {
    const hrs = (v) => String(Number(v) || 0);
    const segs = (card.segments || []).map(s => `${hrs(s.hours)} hrs × ${money(s.rate)}`).join(' + ');
    const corr = (card.corrections || []).map(c => `<div class="sc-line">Her words: "${esc(c)}"</div>`).join('');
    body = `<div class="sc-what">Pay request — ${esc(card.employee_name || '')}</div>` +
      `<div class="sc-line">${esc(card.period_from || '')} → ${esc(card.period_to || '')}</div>` +
      `<div class="sc-prices"><span class="sc-new">${segs ? segs + ' = ' : ''}${money(card.amount)}</span></div>${corr}` +
      `<div class="sc-line">Confirm sends this to the owner — he decides; nothing is paid on this card.</div>`;
  } else if (card.type === 'pay_approve') {
    const hrs = (v) => String(Number(v) || 0);
    const segs = (card.segments || []).map(s => `${hrs(s.hours)} hrs × ${money(s.rate)}`).join(' + ');
    const corr = (card.corrections || []).map(c => `<div class="sc-line">She adds, in her words: "${esc(c)}"</div>`).join('');
    body = `<div class="sc-what">Pay approval — ${esc(card.employee_name || '')} is asking</div>` +
      `<div class="sc-line">${esc(card.period_from || '')} → ${esc(card.period_to || '')} · evidenced hours only</div>` +
      `<div class="sc-prices"><span class="sc-new">${segs ? segs + ' = ' : ''}${money(card.amount)}</span></div>${corr}` +
      `<div class="sc-line">Confirm approves and books the pay once. To decline, say "decline" in chat.</div>`;
  } else if (card.type === 'rate_change') {
    body = `<div class="sc-what">Rate change — ${esc(card.employee_name || '')}</div>` +
      `<div class="sc-prices"><span class="sc-old">${card.old_rate === null || card.old_rate === undefined ? 'no rate on record' : money(card.old_rate)}</span><span class="sc-arrow">→</span><span class="sc-new">${money(card.new_rate)}</span></div>` +
      `<div class="sc-line">From today. Past periods keep the rate they were worked at.</div>`;
  } else if (card.type === 'whats_out') {
    body = `<div class="sc-what">What's Out report — ${esc(card.name || '')}</div>` +
      `<div class="sc-line">By ${esc(card.by_name || 'Register')} · the stock count does not change — this files the report only.</div>`;
  } else if (card.type === 'stock_count') {
    const fmt = (v) => v === null || v === undefined ? '—' : String(v);
    const varTxt = card.variance === null || card.variance === undefined ? '—'
      : (Number(card.variance) > 0 ? '+' + card.variance : String(card.variance));
    body = `<div class="sc-what">Stock count — ${esc(card.name || '')}</div>` +
      `<div class="sc-line">Counted ${fmt(card.counted)} · system ${fmt(card.system)} · variance ${varTxt}</div>` +
      `<div class="sc-line">By ${esc(card.by_name || 'Register')} · confirming files the count — the shelf stock does not change.</div>`;
  } else if (card.type === 'employee_remove') {
    body = `<div class="sc-what">Remove employee — ${esc(card.name ? card.name + ' · ' : '')}${esc(card.email)}</div>`;
  } else if (card.type === 'new_item') {
    body = `<div class="sc-what">New item — ${esc(card.name)} · ${money(card.price)}</div>` +
      (card.barcode ? `<div class="sc-line">Barcode ${esc(card.barcode)}</div>` : '');
  } else if (card.type === 'stock_receive') {
    body = `<div class="sc-what">Stock received — ${esc(card.name)} · ${card.qty}</div>` +
      `<div class="sc-line">MR.STORE stock goes up when you confirm</div>`;
  } else if (card.type === 'bill') {
    // The whole bill on one card: matched lines receive stock; NEW lines
    // are badged and show the shelf price the owner gave next to the
    // bill's cost — the two numbers never blur.
    const lineHtml = (card.lines || []).map(l => {
      const qtyCost = `${l.qty} × cost ${money(l.unit_cost)}`;
      return l.new
        ? `<div class="sc-line"><span class="sc-newtag">NEW</span>${esc(l.name)} — ${qtyCost} · shelf ${money(l.price)}${l.barcode ? ' · barcode ' + esc(l.barcode) : ''}</div>`
        : `<div class="sc-line">${esc(l.name)} — ${qtyCost}</div>`;
    }).join('');
    body = `<div class="sc-what">Bill${card.vendor ? ' — ' + esc(card.vendor) : ''} · ${money(card.total)}</div>${lineHtml}` +
      (card.image ? `<img src="${card.image}" alt="Bill photo">` : '');
  } else if (card.type === 'order_prep') {
    // The vendor order draft: every suggested line with the shelf truth
    // that earned it (OUT / on hand vs par / sold since last delivery)
    // and its recorded cost — the owner edits in chat before confirming.
    const flag = (l) => l.flag === 'out' ? ' · OUT'
      : (l.on_hand !== null && l.on_hand !== undefined
        ? ` · ${fmtCount(l.on_hand)} on hand${l.par ? ' / par ' + l.par : ''}${l.sold ? ' · sold ' + l.sold : ''}` : '');
    const lineHtml = (card.lines || []).map(l =>
      `<div class="sc-line">${esc(l.name)} × ${l.qty}${l.cost !== null && l.cost !== undefined ? ' · ' + money(l.cost) + ' ea' : ''}${flag(l)}</div>`).join('');
    body = `<div class="sc-what">Order — ${esc(card.vendor)} · est. ${money(card.est_total)}</div>${lineHtml}` +
      (card.uncosted ? `<div class="sc-line">${card.uncosted} line(s) have no cost on record — outside the estimate</div>` : '');
  } else if (card.type === 'vendor_teach') {
    const dayName = (k) => ({ sun: 'Sunday', mon: 'Monday', tue: 'Tuesday', wed: 'Wednesday', thu: 'Thursday', fri: 'Friday', sat: 'Saturday' }[k] || k);
    const val = (v) => Array.isArray(v) ? (v.length ? v.map(dayName).join(' + ') : '—') : dayName(v);
    const field = card.field === 'delivery_day' ? 'Delivery day' : 'Rep day';
    body = `<div class="sc-what">${card.create ? 'New vendor' : 'Vendor'} — ${esc(card.vendor_name)}</div>` +
      `<div class="sc-prices"><span class="sc-old">${field}: ${esc(val(card.old))}</span><span class="sc-arrow">→</span><span class="sc-new">${esc(val(card.new))}</span></div>`;
  } else if (card.type === 'photo_read') {
    const found = card.regions === null || card.regions === undefined
      ? 'SAM gave no read on this photo'
      : `SAM found ${card.regions} region(s) for "${card.prompt}"`;
    const matchLine = card.match_sku ? `<div class="sc-line">Matches: ${card.match_name} (${money(card.match_price)}) → cart on "gol"</div>` : '';
    body = `<img src="${card.image}" alt="Your photo"><div class="sc-line">${found}</div>${matchLine}`;
  } else {
    body = `<div class="sc-what">New item — ${card.name} · ${money(card.price)}</div>`;
  }
  d.innerHTML = `<span class="sc-badge">STAGED</span>${body}<button type="button">${IC_CHECK} Confirm — Gol</button>`;
  d.querySelector('button').addEventListener('click', async () => {
    const res = await fetch(BRAIN, { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(brainPayload({ confirm: true })) });
    const r = await res.json();
    chatMsg(r.reply, 'gol');
    applyLockState(r.locked, r.actor);
    handleChatAction(r);
    loadCatalog(); loadSettings();
  });
  $('chatBody').appendChild(d); $('chatBody').scrollTop = 1e6;
}
// --- visual reports: the graph card -------------------------------------------
// Pure renderer: brain payload -> inline SVG, no other data source. The
// figures were computed ONCE, server-side, by the same lane that wrote
// the sentence above the card (provenance law) — this function only draws.
function gfmt(v) {
  const n = Number(v) || 0;
  if (Math.abs(n) >= 1000) { return '$' + Math.round(n).toLocaleString('en-US'); }
  return money(n);
}
function graphSvg(g) {
  const vals = g.series.map(p => Number(p.value) || 0);
  const max = Math.max(...vals, 0);
  if (g.kind === 'hbars') {
    const W = 336, rowH = 27, nameW = 112, barMax = 130;
    const H = g.series.length * rowH + 4;
    let s = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(g.title || '')}">`;
    g.series.forEach((p, i) => {
      const y = i * rowH + 4;
      const w = max > 0 ? Math.max(2, (Number(p.value) / max) * barMax) : 2;
      const name = p.label.length > 16 ? p.label.slice(0, 15).trimEnd() + '…' : p.label;
      s += `<text x="0" y="${y + 13}" font-size="11.5" fill="#334155">${esc(name)}</text>`;
      s += `<rect x="${nameW}" y="${y + 2}" width="${w.toFixed(1)}" height="13" rx="3" fill="#2563eb"></rect>`;
      s += `<text x="${(nameW + w + 6).toFixed(1)}" y="${y + 13}" font-size="11.5" font-weight="700" fill="#0f172a">${gfmt(p.value)}${p.sub ? `<tspan fill="#64748b" font-weight="400"> ${esc(p.sub)}</tspan>` : ''}</text>`;
    });
    return s + '</svg>';
  }
  if (g.kind === 'spark') {
    const W = 336, H = 118, padX = 8, top = 16, base = H - 16;
    const n = g.series.length;
    const min = Math.min(...vals, 0);
    const span = (max - min) || 1;
    const X = (i) => n === 1 ? W / 2 : padX + (i * (W - 2 * padX)) / (n - 1);
    const Y = (v) => base - ((v - min) / span) * (base - top);
    const pts = g.series.map((p, i) => `${X(i).toFixed(1)},${Y(Number(p.value) || 0).toFixed(1)}`).join(' ');
    let s = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(g.title || '')}">`;
    s += `<polygon points="${padX},${base} ${pts} ${W - padX},${base}" fill="#2563eb" opacity="0.12"></polygon>`;
    s += `<polyline points="${pts}" fill="none" stroke="#2563eb" stroke-width="2.2" stroke-linejoin="round" stroke-linecap="round"></polyline>`;
    const peak = vals.indexOf(max);
    const seen = new Set();
    [[0, g.series[0]], [peak, g.series[peak]], [n - 1, g.series[n - 1]]].forEach(([i, p]) => {
      if (seen.has(i) || !p) { return; } seen.add(i);
      const anchor = i === 0 ? 'start' : (i === n - 1 ? 'end' : 'middle');
      s += `<circle cx="${X(i).toFixed(1)}" cy="${Y(Number(p.value) || 0).toFixed(1)}" r="3" fill="#2563eb"></circle>`;
      s += `<text x="${X(i).toFixed(1)}" y="${(Y(Number(p.value) || 0) - 6).toFixed(1)}" font-size="10.5" font-weight="700" fill="#0f172a" text-anchor="${anchor}">${gfmt(p.value)}</text>`;
    });
    s += `<text x="${padX}" y="${H - 3}" font-size="10" fill="#64748b">${esc(g.series[0].label)}</text>`;
    s += `<text x="${W - padX}" y="${H - 3}" font-size="10" fill="#64748b" text-anchor="end">${esc(g.series[n - 1].label)}</text>`;
    return s + '</svg>';
  }
  // bars + compare share the column renderer.
  const W = 336, top = 20, base = 126, H = 148, padX = 8;
  const n = g.series.length;
  const slot = (W - 2 * padX) / n;
  const barW = Math.min(g.kind === 'compare' ? 64 : 42, slot * 0.58);
  const neg = Math.min(...vals, 0);
  const span = (max - neg) || 1;
  const zeroY = base - ((0 - neg) / span) * (base - top);
  let s = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(g.title || '')}">`;
  s += `<line x1="${padX}" y1="${zeroY.toFixed(1)}" x2="${W - padX}" y2="${zeroY.toFixed(1)}" stroke="#cbd5e1" stroke-width="1"></line>`;
  g.series.forEach((p, i) => {
    const v = Number(p.value) || 0;
    const x = padX + slot * i + (slot - barW) / 2;
    const h = Math.max(2, (Math.abs(v) / span) * (base - top));
    const y = v >= 0 ? zeroY - h : zeroY;
    const fill = v === 0 ? '#cbd5e1' : (p.highlight ? '#2563eb' : '#93c5fd');
    s += `<rect x="${x.toFixed(1)}" y="${y.toFixed(1)}" width="${barW.toFixed(1)}" height="${h.toFixed(1)}" rx="3" fill="${fill}"></rect>`;
    s += `<text x="${(x + barW / 2).toFixed(1)}" y="${(v >= 0 ? y - 5 : y + h + 12).toFixed(1)}" font-size="11" font-weight="700" fill="#0f172a" text-anchor="middle">${gfmt(v)}</text>`;
    const maxLbl = g.kind === 'compare' ? 20 : 12;
    const lbl = p.label.length > maxLbl ? p.label.slice(0, maxLbl - 1).trimEnd() + '…' : p.label;
    s += `<text x="${(x + barW / 2).toFixed(1)}" y="${H - 4}" font-size="10.5" ${p.highlight ? 'font-weight="700" fill="#2563eb"' : 'fill="#64748b"'} text-anchor="middle">${esc(lbl)}</text>`;
  });
  return s + '</svg>';
}
function chatGraph(g) {
  if (!g || !Array.isArray(g.series)) { return; }
  const d = document.createElement('div');
  d.className = 'msg gol graph-card';
  let inner = `<div class="graph-head"><span class="graph-title">${esc(g.title || '')}</span><span class="graph-total">${money(g.total)}</span></div>`;
  if (g.empty) {
    inner += `<div class="graph-empty">${esc(g.empty_text || 'No sales in this range yet.')}</div>`;
  } else {
    inner += graphSvg(g);
  }
  if (g.focus) {
    inner += `<div class="graph-foot">${esc(g.focus.label)} — <b>${money(g.focus.value)}</b></div>`;
  }
  d.innerHTML = inner;
  $('chatBody').appendChild(d); $('chatBody').scrollTop = 1e6;
}

// --- photo lane: upload -> SAM reads it -> staged card ------------------------
async function sendPhoto(file, promptText) {
  chatMsg('📷 Photo sent' + (promptText ? ' — "' + promptText + '"' : ''), 'me');
  const fd = new FormData();
  fd.append('photo', file);
  // The session rides along: a bill's price wait waits in this session,
  // and the owner's chat answers complete it here.
  fd.append('session', SESSION_ID);
  if (promptText) { fd.append('prompt', promptText); }
  if (/\b(bill|invoice)\b/i.test(promptText || '')) { fd.append('mode', 'bill'); }
  const res = await fetch('../server/photo.php', { method: 'POST', body: fd });
  const r = await res.json();
  if (r.reply) { chatMsg(r.reply, 'gol'); }
  if (r.card) { chatCard(r.card); }
}

async function sendChat(text) {
  noteChatLang(text); // the voice door follows the language of the chat
  // A counter-sensing ask is a camera errand, not a brain question: the
  // frame goes to the vision lane and its answer comes back as Gol's
  // reply — the message never reaches the brain's catalog lanes.
  if (isSenseAsk(text)) { await senseFlow(text); return; }
  const mine = chatMsg(text, 'me');
  const res = await fetch(BRAIN, { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(brainPayload({ message: text })) });
  const r = await res.json();
  // The server consumed that message as a PIN entry — never leave the
  // digits standing in the chat history.
  if (r.pin_mask && mine) { mine.textContent = '••••'; }
  if (r.reply) { chatMsg(r.reply, 'gol'); }
  if (r.graph) { chatGraph(r.graph); }
  applyLockState(r.locked, r.actor);
  if (r.unlock_event && r.unlock_event.id) { attachUnlockFrame(r.unlock_event.id); }
  if (r.card) { chatCard(r.card); }
  handleChatAction(r);
  if (r.done) { loadCatalog(); loadSettings(); }
}

// --- wiring -------------------------------------------------------------------
$('searchInput').addEventListener('input', renderGrid);
// Autofill guard: Chrome fills the till's text boxes with the store's
// saved email despite autocomplete="off", and a filled search box hides
// the whole shelf ("No products match"). The boxes open READONLY —
// autofill skips readonly fields — and wake on the first human touch;
// the boot also clears any fill that still lands, so the register
// always opens on the full shelf.
['searchInput', 'scanInput'].forEach((id) => {
  const el = $(id);
  if (!el) { return; }
  const wake = () => el.removeAttribute('readonly');
  el.addEventListener('pointerdown', wake);
  el.addEventListener('focus', wake);
});
function clearTillSearch() {
  const s = $('searchInput'); const b = $('scanInput');
  if (s && s.value) { s.value = ''; renderGrid(); }
  if (b && b.value) { b.value = ''; }
}
window.addEventListener('pageshow', clearTillSearch);
$('scanInput').addEventListener('keydown', (e) => {
  if (e.key !== 'Enter') { return; }
  const code = $('scanInput').value.trim();
  const p = catalog.products.find(x => x.barcode === code || x.sku === code);
  if (p) { addToCart(p.sku); $('scanInput').value = ''; }
  // A genuine barcode shape (8-14 digits) with no match earns the Add
  // door; anything else keeps the plain not-found word in chat.
  else if (/^\d{8,14}$/.test(code)) { scanUnknownOffer(code); }
  else { chatMsg('Barcode ' + code + ' not found — tell Gol the name and I\'ll add it.', 'gol'); $('chatDrawer').classList.remove('hidden'); }
});
$('cashBtn').addEventListener('click', () => {
  cardTenderPreview = false; renderCart(); // cash never carries the surcharge
  if (creditApplied && cashDueNow() <= 0) { openCreditSheet(); return; } // credit covers it all
  openCashPanel();
});
$('cashBack').addEventListener('click', () => $('cashModal').classList.add('hidden'));
$('cashComplete').addEventListener('click', completeCashSale);
$('cashReceived').addEventListener('input', updateCashPanel);
$('cashExact').addEventListener('click', () => { $('cashReceived').value = cashDueNow().toFixed(2); updateCashPanel(); });
$('cashClear').addEventListener('click', () => { $('cashReceived').value = ''; updateCashPanel(); $('cashReceived').focus(); });
document.querySelectorAll('[data-cash-add]').forEach(b =>
  b.addEventListener('click', () => {
    const cur = parseFloat($('cashReceived').value || '0') || 0;
    $('cashReceived').value = (cur + parseFloat(b.dataset.cashAdd)).toFixed(2);
    updateCashPanel();
  }));
$('cardBtn').addEventListener('click', async () => {
  // Taking a card payment: the totals panel first shows the surcharge
  // line and the total the customer will pay, then the sale runs.
  cardTenderPreview = true; renderCart();
  await tender('card');
});
$('clearBtn').addEventListener('click', () => {
  cart.clear(); manualLines = []; taxExempt = false; cardTenderPreview = false;
  creditApplied = null;
  setToggle($('smTaxBtn'), false); renderCart();
});
$('receiptClose').addEventListener('click', () => $('receiptModal').classList.add('hidden'));

// Chat actions: the brain's hands on this till. 'add' rings an item;
// 'credit' (the credit_attach card's confirm — the employee chat door)
// sits a customer's credit on the CURRENT sale: it becomes the exact
// creditApplied state the Credit sheet sets, so the tender spends it
// through the one server path, once per sale.
function handleChatAction(r) {
  if (!r || !r.action) { return; }
  if (r.action.type === 'add') { addToCart(r.action.sku, { viaChat: true, qty: r.action.qty || 1 }); }
  if (r.action.type === 'credit') {
    creditApplied = { phone: r.action.phone, name: r.action.name,
                      balance: Number(r.action.balance) || 0, amount: Number(r.action.balance) || 0 };
    renderCart();
    showToast('Store credit ' + money(creditApplied.amount) + ' on this sale for '
      + (r.action.name || r.action.phone) + ' — it spends at the tender.');
  }
}

// --- store credit tender --------------------------------------------------------
// The Credit button beside Cash/Card (owner directive, Oct 28): look
// the customer up by phone — the loyalty master key — read the balance
// from the store's OWN ledger, and apply up to the balance. The rest
// splits to cash/card exactly as usual. This sheet only carries the
// cashier's choices: the server re-validates the account, the balance
// and the amount at tender, and the spend records once per sale id.
let creditAcct = null; // the last balance read: {name, phone, balance}
function creditNoteShow(text) {
  const el = $('creditNote');
  el.textContent = text || '';
  el.classList.toggle('hidden', !text);
}
function creditBase() { // what credit may cover: subtotal + tax (never the card fee)
  return Math.round(((cartTotals.subtotal || 0) + (cartTotals.tax || 0)) * 100) / 100;
}
function openCreditSheet() {
  if (!cart.size && !manualLines.length) { return; }
  if (!storeIsOpen()) { storeClosedNote(); return; }
  creditNoteShow('');
  creditAcct = null;
  $('creditMatches').classList.add('hidden');
  $('creditMatches').innerHTML = '';
  $('creditResult').classList.add('hidden');
  $('creditRemove').classList.toggle('hidden', !creditApplied);
  if (creditApplied) { $('creditPhone').value = creditApplied.phone; }
  $('creditModal').classList.remove('hidden');
  $('creditPhone').focus(); $('creditPhone').select();
}
async function creditLookup() {
  const phone = $('creditPhone').value.trim();
  creditNoteShow('');
  if (phone.replace(/\D/g, '').length < 7) { creditNoteShow('Enter the customer\'s phone number.'); return; }
  let r = null;
  try {
    const res = await fetch(API + '?credit&phone=' + encodeURIComponent(phone));
    r = await res.json();
    if (!res.ok || !r.ok) {
      creditNoteShow((r && r.error) || 'No credit on this number.');
      $('creditResult').classList.add('hidden');
      return;
    }
  } catch (e) { creditNoteShow('The credit ledger did not answer — try again.'); return; }
  creditAcct = r;
  $('creditName').textContent = r.name || r.phone;
  $('creditBalance').textContent = money(r.balance);
  $('creditAmount').value = Math.min(Number(r.balance) || 0, creditBase()).toFixed(2);
  $('creditResult').classList.remove('hidden');
  updateCreditSheet();
}
// Name-first (owner directive, Oct 28): the customer says their name,
// the sheet lists the records it matches — name, phone tail, balance —
// and the HUMAN taps the right one. A pick only fills the phone box
// and runs the same phone lookup, so money never moves on a guess.
async function creditFindByName() {
  const q = $('creditNameIn').value.trim();
  creditNoteShow('');
  const box = $('creditMatches');
  box.innerHTML = ''; box.classList.add('hidden');
  if (q.length < 2) { creditNoteShow('Type at least 2 letters of the name.'); return; }
  let r = null;
  try {
    const res = await fetch(API + '?credit&name=' + encodeURIComponent(q));
    r = await res.json();
  } catch (e) { creditNoteShow('The credit ledger did not answer — try again.'); return; }
  const hits = (r && r.matches) || [];
  if (!hits.length) { creditNoteShow('No customer record matches that name.'); return; }
  box.innerHTML = '';
  hits.forEach((h) => {
    const b = document.createElement('button');
    b.type = 'button'; b.className = 'ghost';
    b.textContent = (h.name || h.phone) + ' · …' + h.phone_tail + ' · ' + money(h.balance);
    b.addEventListener('click', () => {
      $('creditPhone').value = h.phone;
      box.classList.add('hidden');
      creditLookup();
    });
    box.appendChild(b);
  });
  box.classList.remove('hidden');
}
function updateCreditSheet() {
  if (!creditAcct) { return; }
  const amt = parseFloat($('creditAmount').value || '0') || 0;
  const left = Math.round((creditBase() - amt) * 100) / 100;
  $('creditLeft').textContent = left > 0 ? money(left) + ' still due by cash or card' : 'Credit covers the whole sale';
  $('creditApply').textContent = left > 0 ? 'Apply credit' : 'Complete sale with credit';
}
async function applyCredit() {
  if (!creditAcct) { return; }
  const amt = Math.round((parseFloat($('creditAmount').value || '0') || 0) * 100) / 100;
  if (!(amt > 0)) { creditNoteShow('Enter an amount above $0.00.'); return; }
  if (amt > Number(creditAcct.balance)) {
    creditNoteShow('Balance is ' + money(creditAcct.balance) + ' — apply that much or less.');
    return;
  }
  if (amt > creditBase()) {
    creditNoteShow('Credit can cover at most ' + money(creditBase()) + ' on this sale.');
    return;
  }
  if (amt >= creditBase()) {
    // Credit covers it all: the tender IS the credit.
    creditApplied = { phone: creditAcct.phone, name: creditAcct.name, balance: creditAcct.balance, amount: amt };
    if (await tender('credit')) { $('creditModal').classList.add('hidden'); }
    return;
  }
  creditApplied = { phone: creditAcct.phone, name: creditAcct.name, balance: creditAcct.balance, amount: amt };
  $('creditModal').classList.add('hidden');
  renderCart();
  showToast('Store credit ' + money(amt) + ' applied — ' + money(cashDueNow()) + ' still due by cash or card.');
}
$('creditFindName').addEventListener('click', creditFindByName);
$('creditNameIn').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); creditFindByName(); } });
$('creditBtn').addEventListener('click', openCreditSheet);
$('creditBack').addEventListener('click', () => $('creditModal').classList.add('hidden'));
$('creditCheck').addEventListener('click', creditLookup);
$('creditPhone').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); creditLookup(); } });
$('creditAmount').addEventListener('input', updateCreditSheet);
$('creditApply').addEventListener('click', applyCredit);
$('creditRemove').addEventListener('click', () => {
  creditApplied = null;
  $('creditModal').classList.add('hidden');
  renderCart();
  showToast('Store credit removed from this sale.');
});

// --- refunds from Transactions ---------------------------------------------------
// Pick a recorded sale, pick the lines coming back, and the refund
// runs the ONE execution path (api.php refund_execute →
// golpos_execute_refund — the same one the Gol chat's refund card
// runs), behind the sheet PIN gate whenever the store's refund_pin
// setting is on. The quote comes from the server on every pick change,
// so the number on the button is the number that will move.
let refundCtx = null; // {sale, picks|null, quote}
function refundNoteShow(text) {
  const el = $('refundNote');
  el.textContent = text || '';
  el.classList.toggle('hidden', !text);
}
function txnLineKey(l, i) { return l.sku ? 'sku:' + l.sku : 'manual:' + i; }
async function refundPost(body) {
  const res = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body) });
  return res.json().catch(() => ({}));
}
async function openRefundSheet(sale) {
  refundCtx = { sale, picks: null, quote: null };
  $('refundSaleLine').textContent = 'Sale ' + (sale.id || '') + ' · '
    + new Date(sale.at || Date.now()).toLocaleString('en-US') + ' · ' + money(sale.total);
  refundNoteShow('');
  $('refundLines').innerHTML = '<p class="txn-empty">Reading the sale…</p>';
  $('refundModal').classList.remove('hidden');
  await refreshRefundSheet();
}
async function refreshRefundSheet() {
  if (!refundCtx) { return; }
  const r = await refundPost({ refund_quote: { sale_id: refundCtx.sale.id, picks: refundCtx.picks } });
  if (!r || r.error || !r.quote) {
    refundNoteShow((r && r.error) || 'That refund cannot be quoted.');
    $('refundLines').innerHTML = '';
    $('refundGo').disabled = true;
    return;
  }
  refundCtx.quote = r.quote;
  const q = r.quote;
  $('refundLines').innerHTML = '';
  (q.states || []).forEach(st => {
    if (!(st.remaining > 0)) { return; }
    const row = document.createElement('div'); row.className = 'refund-line';
    const nm = document.createElement('span'); nm.className = 'refund-line-name';
    nm.textContent = st.name + ' — sold ' + st.sold
      + (st.refunded_qty > 0 ? ' · ' + st.refunded_qty + ' already back' : '')
      + ' · ' + st.remaining + ' left';
    const stepper = document.createElement('div'); stepper.className = 'stepper';
    const dec = document.createElement('button'); dec.type = 'button'; dec.textContent = '−';
    const val = document.createElement('b');
    const inc = document.createElement('button'); inc.type = 'button'; inc.textContent = '+';
    const cur = () => {
      if (!refundCtx.picks) { return st.remaining; }
      const p = refundCtx.picks.find(x => x.key === st.key);
      return p ? p.qty : 0;
    };
    val.textContent = String(cur());
    const setQty = (n) => {
      n = Math.max(0, Math.min(st.remaining, n));
      if (!refundCtx.picks) {
        refundCtx.picks = (q.states || []).filter(s2 => s2.remaining > 0)
          .map(s2 => ({ key: s2.key, qty: s2.remaining }));
      }
      refundCtx.picks = refundCtx.picks.filter(x => x.key !== st.key);
      if (n > 0) { refundCtx.picks.push({ key: st.key, qty: n }); }
      refreshRefundSheet();
    };
    dec.addEventListener('click', () => setQty(cur() - 1));
    inc.addEventListener('click', () => setQty(cur() + 1));
    stepper.appendChild(dec); stepper.appendChild(val); stepper.appendChild(inc);
    row.appendChild(nm); row.appendChild(stepper);
    $('refundLines').appendChild(row);
  });
  $('refundTotal').textContent = money(q.total);
  const parts = [];
  if (q.tenders.cash > 0) { parts.push(money(q.tenders.cash) + ' cash'); }
  if (q.tenders.card > 0) { parts.push(money(q.tenders.card) + ' card'); }
  if (q.tenders.credit > 0) { parts.push(money(q.tenders.credit) + ' store credit'); }
  $('refundBackLine').textContent = 'Back to the original tender: ' + (parts.join(' + ') || '—')
    + (q.batch_effect === 'negative_in_current'
      ? ' · the sale sits in a sealed batch, so this lands as a negative entry in the current batch — the sealed batch is never edited.'
      : '');
  $('refundGo').disabled = !(q.total > 0);
  $('refundGo').textContent = 'Refund ' + money(q.total);
}
async function executeRefund() {
  if (!refundCtx || !refundCtx.quote) { return; }
  const r = await gatedApiPost({ refund_execute: { sale_id: refundCtx.sale.id, picks: refundCtx.picks },
    session: SESSION_ID });
  if (!r || r.cancelled) { return; }
  if (r.locked) { refundNoteShow(r.error || 'PIN entry is locked — try again later.'); return; }
  if (r.error) { refundNoteShow(r.error); return; }
  if (r.ok && r.refund) {
    $('refundModal').classList.add('hidden');
    showToast('Refunded ' + money(r.refund.total) + ' — returned items are back on the shelf.');
    refundCtx = null;
    refreshToday();
    smHistoryAction(); // re-read the log: badges and remaining amounts move
    loadCatalog();     // the restock moved the shelf counts
  }
}
$('refundCancel').addEventListener('click', () => $('refundModal').classList.add('hidden'));
$('refundGo').addEventListener('click', executeRefund);
// --- order sheet: the confirmed vendor order, printed or shared for the rep --
let orderSheetRec = null;
function orderSheetText(rec) {
  const store = (receiptSettings && receiptSettings.store_name) || $('storeName').textContent || 'Store';
  const dayFull = new Date(rec.date + 'T12:00:00').toLocaleDateString('en-US',
    { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
  const lines = [store.toUpperCase() + ' — ORDER', 'Vendor: ' + rec.vendor,
    'Rep day: ' + dayFull + ' · Status: ' + String(rec.status || '').toUpperCase(),
    '----------------------------------------'];
  (rec.lines || []).forEach(l => {
    lines.push(l.name + ' × ' + l.qty + (l.cost !== null && l.cost !== undefined
      ? ' — ' + money(l.cost) + ' ea = ' + money(l.line_total || 0) : ''));
  });
  lines.push('----------------------------------------');
  lines.push('Estimated total: ' + money(rec.est_total || 0));
  if (rec.uncosted) { lines.push('(' + rec.uncosted + ' line(s) have no cost on record — outside the estimate.)'); }
  lines.push('Prepared by Gol at the register; confirmed by the owner.');
  lines.push('Handed to the rep in person — nothing was sent electronically.');
  return lines.join('\n');
}
async function openOrderSheet(id) {
  try {
    const rec = await (await fetch(API + '?order_sheet=' + encodeURIComponent(id))).json();
    if (!rec || !rec.id) { showToast('That order sheet is gone.'); return; }
    orderSheetRec = rec;
    $('orderSheetTitle').textContent = 'Order — ' + rec.vendor;
    $('orderSheetBody').textContent = orderSheetText(rec);
    $('orderSheetModal').classList.remove('hidden');
  } catch (e) { showToast('The order sheet could not be read.'); }
}
$('orderSheetClose').addEventListener('click', () => $('orderSheetModal').classList.add('hidden'));
$('orderSheetPrint').addEventListener('click', () => window.print());
$('orderSheetShare').addEventListener('click', async () => {
  if (!orderSheetRec) { return; }
  const text = orderSheetText(orderSheetRec);
  if (navigator.share) {
    try { await navigator.share({ title: 'Order — ' + orderSheetRec.vendor, text }); return; }
    catch (e) { if (e && e.name === 'AbortError') { return; } }
  }
  try { await navigator.clipboard.writeText(text); showToast('Order copied — paste it anywhere to send.'); return; } catch (e) { /* fall through */ }
  const ta = document.createElement('textarea');
  ta.value = text; document.body.appendChild(ta); ta.select();
  try { document.execCommand('copy'); showToast('Order copied — paste it anywhere to send.'); }
  catch (e) { showToast('Copy did not work here — the sheet is on screen to photograph.'); }
  ta.remove();
});
$('golBtn').addEventListener('click', () => { $('chatDrawer').classList.remove('hidden'); $('chatInput').focus(); });
$('chatClose').addEventListener('click', () => { $('chatDrawer').classList.add('hidden'); loadCatalog(); });
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
$('lockChatBtn').addEventListener('click', () => { $('chatDrawer').classList.remove('hidden'); $('chatInput').focus(); });
$('myTasksBtn').addEventListener('click', openMyTasks);
$('tasksClose').addEventListener('click', () => $('tasksModal').classList.add('hidden'));
$('taskNoteSend').addEventListener('click', () => {
  const v = $('taskNoteInput').value.trim();
  if (!v) { return; }
  $('taskNoteInput').value = '';
  $('tasksModal').classList.add('hidden');
  $('chatDrawer').classList.remove('hidden');
  sendChat('boss lai bhandeu: ' + v);
});
{ const lb = $('smLockBtn'); if (lb) { lb.addEventListener('click', () => { $('settingsPop').classList.add('hidden'); sendChat('lock'); }); } }
$('ownerBtn').addEventListener('click', async () => {
  $('ownerPanel').classList.remove('hidden');
  $('ownerStoreName').textContent = $('storeName').textContent;
  const res = await fetch(API + '?summary'); const s = await res.json();
  const pres = await fetch(API + '?pending'); const p = await pres.json();
  const fres = await fetch(API + '?feed'); const feed = await fres.json();
  const feedHtml = Array.isArray(feed) && feed.length
    ? `<h3 class="ocard-title">Demo feed <span class="ocard-count">${feed.length}</span></h3>` + feed.slice().reverse().map(f =>
        `<p class="feed-line">🖼️ ${f.caption || (f.name + ' — ' + money(f.price))}</p>`).join('')
    : '<h3 class="ocard-title">Demo feed</h3><p class="owner-muted">Demo feed is empty — offer posters are made in MR.STORE\'s Gol.</p>';
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
        `<span>${r.receipts} receipt${r.receipts === 1 ? '' : 's'}${Number(r.credit) > 0 ? ' · credit ' + money(r.credit) : ''}</span></div>`).join('')
        : '<p class="owner-muted">No loyalty receipts yet.</p>');
  } catch (e) { $('ownerLoyalty').innerHTML = ''; }
  // Today's rep visits — the register order prep state: which vendors are
  // due today and where each order stands (prepared / confirmed / given).
  // The sheet button opens the confirmed order to print or share for the
  // rep; staging and confirming themselves live in the Gol chat.
  try {
    const od = await (await fetch(API + '?orders')).json();
    const stWord = { prepared: 'Prepared — waiting for "gol"', confirmed: 'Confirmed — sheet ready', given: 'Given to the rep', none: 'Not prepared yet' };
    const dayName = (k) => ({ sun: 'Sun', mon: 'Mon', tue: 'Tue', wed: 'Wed', thu: 'Thu', fri: 'Fri', sat: 'Sat' }[k] || k);
    const due = (od.visits || []).filter(v => v.due_today);
    const rest = (od.visits || []).filter(v => !v.due_today);
    const sheetBtn = (oid) => oid ? ` <button type="button" class="rep-sheet-btn" data-sheet="${esc(oid)}">Order sheet</button>` : '';
    $('ownerVendors').innerHTML =
      `<h3 class="ocard-title">Today's rep visits <small>— orders are prepared in the Gol chat</small></h3>` +
      (due.length ? due.map(v =>
        `<div class="rep-row"><span>${esc(v.name)}</span><b>${stWord[v.status] || v.status}</b>` +
        `<span>${v.est_total !== null && v.est_total !== undefined ? 'est. ' + money(v.est_total) : ''}${sheetBtn(v.order_id)}</span></div>`).join('')
        : '<p class="owner-muted">No rep visits today.</p>') +
      (rest.length ? '<p class="owner-muted">Pack: ' + rest.map(v =>
        esc(v.name) + ' — rep ' + (v.rep_days || []).map(dayName).join(' + ')).join(' · ') + '</p>' : '');
    $('ownerVendors').querySelectorAll('[data-sheet]').forEach(b =>
      b.addEventListener('click', () => openOrderSheet(b.dataset.sheet)));
  } catch (e) { $('ownerVendors').innerHTML = ''; }
  // Team card + unlock feed + What's Out: the employee lane's owner
  // side, from the one ?team read (owner session — the endpoint 403s
  // an unelevated session and the cards simply stay empty).
  try {
    const tm = await (await fetch(API + '?team&session=' + encodeURIComponent(SESSION_ID))).json();
    if (tm && Array.isArray(tm.people)) {
      const srcTag = (t) => t.source === 'gol' ? ' <small>(Gol)</small>' : ' <small>(owner)</small>';
      $('ownerTeam').innerHTML =
        `<h3 class="ocard-title">Team <small>— tasks, notes, the day's trail</small></h3>` +
        (tm.people.length ? tm.people.map(pp => {
          const tr = pp.trail || {};
          const myTasks = (tm.tasks || []).filter(t => t.employee_email === pp.email && t.status !== 'superseded');
          const openLines = myTasks.flatMap(t => (t.items || []).filter(i => !i.done)
            .map(i => `<div class="sc-line">• ${esc(i.text)}${srcTag(t)}</div>`)).join('');
          const noteLines = (pp.notes || []).map(n =>
            `<div class="sc-line">${n.read ? '' : '🔵 '}"${esc(n.text)}" — ${esc(new Date(n.at).toLocaleString())}</div>`).join('');
          return `<div class="team-person"><b>${esc(pp.name)}</b> <small>${esc(pp.role)} · ${pp.tasks_open} open · ${pp.tasks_done} done${pp.notes_unread ? ' · ' + pp.notes_unread + ' unread note(s)' : ''}</small>` +
            (openLines || '') + (noteLines || '') +
            `<div class="sc-line">Din bhari: ${(tr.done || []).length} done · ${(tr.notes || []).length} note(s) · ${(tr.outs || []).length} out report(s) · ${(tr.counts || []).length} count(s) · ${(tr.questions || []).length} question(s)</div></div>`;
        }).join('') : '<p class="owner-muted">No employees yet — add one in the Gol chat.</p>') +
        ((tm.role_tasks || []).length
          ? '<p class="owner-muted">Unassigned (role) tasks: ' + tm.role_tasks.flatMap(t => (t.items || []).filter(i => !i.done).map(i => esc(i.text))).join(' · ') + '</p>' : '') +
        ((tm.pay_requests || []).length
          ? '<h3 class="ocard-title">Pay requests <small>— waiting on you; decide in the Gol chat ("pay requests")</small></h3>' +
            tm.pay_requests.map(r => {
              const segs = (r.segments || []).map(s => `${String(Number(s.hours) || 0)} hrs × ${money(s.rate)}`).join(' + ');
              const corr = (r.corrections || []).map(c => `<div class="sc-line">Her words: "${esc(c)}"</div>`).join('');
              return `<div class="team-person"><b>${esc(r.name)}</b> <small>${esc(r.period)} · ${segs} = ${money(r.amount)}</small>${corr}</div>`;
            }).join('')
          : '');
      $('ownerUnlocks').innerHTML =
        `<h3 class="ocard-title">Unlock feed <small>— who opened the register, and when</small></h3>` +
        ((tm.unlocks || []).length ? tm.unlocks.map(u =>
          `<div class="rep-row"><span>${u.kind === 'lockout' ? '⚠️ Lockout — 3 wrong PINs' : esc(u.name || 'Owner') + (u.role ? ' · ' + esc(u.role) : '')}</span>` +
          `<b>${esc(new Date(u.at).toLocaleString())}</b>` +
          (u.photo ? `<span><img class="unlock-thumb" src="../server/frame.php?unlock=${esc(u.id)}" alt="Unlock photo"></span>` : '<span>no photo</span>') +
          `</div>`).join('')
          : '<p class="owner-muted">No unlocks recorded yet.</p>');
      $('ownerWhatsout').innerHTML =
        `<h3 class="ocard-title">What's Out <small>— reports from the shelf (counts never change on a report)</small></h3>` +
        ((tm.whatsout || []).length ? tm.whatsout.map(w =>
          `<div class="rep-row"><span>${esc(w.name)}</span><b>${esc(w.by_name || '')}</b><span>${esc(new Date(w.at).toLocaleString())}</span></div>`).join('')
          : '<p class="owner-muted">Nothing reported out.</p>') +
        ((tm.counts || []).length
          ? '<h3 class="ocard-title">Counts filed</h3>' + tm.counts.map(c =>
            `<div class="rep-row"><span>${esc(c.name)}</span><b>counted ${esc(String(c.counted))} · system ${c.system === null || c.system === undefined ? '—' : esc(String(c.system))}</b><span>${esc(c.by_name || '')}</span></div>`).join('') : '');
    }
  } catch (e) { /* team read is owner-domain; an unelevated panel just skips it */ }
  await loadSettings(); renderSettingsView();
  renderDayClose();
  showReport('today');
});
$('ownerClose').addEventListener('click', () => $('ownerPanel').classList.add('hidden'));

// --- owner panel: print settings — a MIRROR, never a form -------------------
// Owner 85/15 rule (Oct 28): the panel is a light mirror only — there is
// NO second MR.STORE in this POS. The values below are read-only lines;
// every change runs through Gol chat (a staged setting_change card,
// confirmed with "gol"), and the instant flips live in the Settings
// dropdown where the counter moment needs them. No typed-in form exists
// anywhere in the panel.
function renderSettingsView() {
  const st = receiptSettings || {};
  const onoff = (b) => b ? 'On' : 'Off';
  const shown = (b) => b ? 'shown' : 'hidden';
  const row = (k, v) => `<div class="rep-row"><span>${k}</span><b>${esc(v)}</b><span></span></div>`;
  $('ownerSettings').innerHTML =
    `<h3 class="ocard-title">Receipt print settings <small>— mirror only · ask Gol in chat to change these</small></h3>` +
    row('Store name', st.store_name || '') +
    row('Address', (st.address || '') + ' · ' + shown(st.show_address !== false)) +
    row('Phone', (st.phone || '') + ' · ' + shown(st.show_phone !== false)) +
    row('Footer message', st.footer || '') +
    row('Copies', String(st.copies ?? 1)) +
    row('Paper width', st.paper_width || '') +
    row('Tax line', shown(st.show_tax !== false)) +
    row('Logo on receipt', onoff(st.logo !== false)) +
    row('Receipt after each sale', onoff(st.auto_open !== false)) +
    row('Store hours', (st.store_open || '08:00') + ' – ' + (st.store_close || '21:00')) +
    row('Batch close', st.batch_mode || 'manual') +
    row('Card surcharge', fmtPct(st.card_surcharge_pct ?? 0) + '% · card sales only') +
    row('Camera frame on cash sales', onoff(st.watch_frame === true)) +
    (st.regcam ? (st.regcam.mode === 'link'
      ? row('Register Camera — MR.STORE master', onoff(st.regcam.master === true))
        + row('Camera audio (MR.STORE)', onoff(st.regcam.audio_raw === true) + (st.regcam.master ? '' : ' · held by master'))
        + row('Event footage (MR.STORE)', onoff(st.regcam.record_raw === true) + (st.regcam.master ? '' : ' · held by master'))
        + row('Camera stream at this till', st.regcam.stream ? 'On' : (st.regcam.master ? 'Off — the till flip is off' : 'Off — held by the MR.STORE master'))
      : row('Camera audio (this register)', onoff(st.regcam.audio === true))
        + row('Event footage (this register)', onoff(st.regcam.record === true))) : '') +
    row('Refunds allowed', onoff(st.refunds_allowed !== false)) +
    row('Refund PIN', onoff(st.refund_pin !== false)) +
    row('Refund window', String(st.refund_window_days ?? 30) + ' days') +
    row('Credit redeem at the till', onoff(st.credit_redeem !== false)) +
    '<p class="owner-muted">Changes run through Gol chat — e.g. "card surcharge 3%", "refund pin off gara" or "refund window 15 din rakha" — staged as a card, confirmed with "gol". Refunds run from Transactions (pick the sale → Refund) or by asking Gol; a customer\'s credit balance reads in chat with their name or phone number, and spends at the till with the Credit button.</p>';
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
  const rec = await gatedApiPost({ close_day: true, session: SESSION_ID });
  if (rec.cancelled || rec.locked) { renderDayClose(); return; }
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
// untouched. Every item here runs the register's existing paths: the
// batch seal and the day close run through api.php as admin actions
// behind the sheet PIN gate (the chat keeps its own staged doors to the
// same seal and the same close), the printer toggle writes the ONE
// settings store the owner panel form and the chat also write.
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
  smCloseArmed = false; clearTimeout(smCloseTimer);
  const dc = $('smDayCloseBtn');
  dc.textContent = 'Close day'; dc.classList.remove('armed');
  refreshSettingsMenu();
}
async function refreshSettingsMenu() {
  await loadSettings();
  const st = receiptSettings || {};
  setToggle($('smPrinterBtn'), st.auto_open !== false);
  setToggle($('smWatchBtn'), st.watch_frame === true);
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
      info.textContent = 'Staged in Gol chat: ' + smBatchStaged.count + ' card sales · ' + money(smBatchStaged.total);
      btn.textContent = 'Seal batch'; btn.disabled = false;
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
  // The menu's seal is a direct admin action (api.php seal_batch, behind
  // the sheet PIN gate) over the same unbatched segment the Gol chat's
  // batch_close card seals — one seal, two doors; the chat door keeps
  // its staged card, this button seals on the tap once the PIN lands.
  const r = await gatedApiPost({ seal_batch: true, session: SESSION_ID });
  if (r.cancelled || r.locked) { return; }
  smBatchStaged = null;
  if (r.sealed && r.batch) {
    showToast('Batch #' + r.batch.n + ' sealed — ' + r.batch.count + ' card sales · '
      + money(r.batch.total) + '.');
    closeSettingsMenu();
  } else {
    smBatchNoteShow('All card sales are already in a batch.');
  }
  await refreshBatchRow();
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
  const rec = await gatedApiPost({ close_day: true, session: SESSION_ID });
  if (rec.cancelled || rec.locked) { return; }
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
// --- transaction history: every recorded sale, items named -------------------
// The Settings menu's Transactions door: the till's own sales log
// (api.php ?history), newest first, grouped by day. A row is the sale
// at a glance — time, receipt no., tender, item count, total; tapping
// it opens the sale's line items with their names, and the Reprint
// button there renders THAT sale through the same showReceipt() the
// tender path and Reprint Last use — no second receipt renderer, and
// reprinting writes nothing back to the log. Reading money records
// carries no new gate: the panel sits behind the same Settings
// dropdown as Reprint Last, which is ungated (cashier-visible
// receipts are counter-normal).
let txnSales = new Map(); // sale id -> the recorded sale (the reprint source)
function txnYmd(d) {
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0')
    + '-' + String(d.getDate()).padStart(2, '0');
}
function txnDayLabel(dateStr) {
  const now = new Date();
  if (dateStr === txnYmd(now)) { return 'Today'; }
  const y = new Date(now); y.setDate(y.getDate() - 1);
  if (dateStr === txnYmd(y)) { return 'Yesterday'; }
  const d = new Date(dateStr + 'T12:00:00');
  return isNaN(d) ? dateStr : d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
}
function txnItemCount(sale) {
  return (sale.lines || []).reduce((n, l) => n + (Number(l.qty) || 0), 0);
}
function txnRowEl(sale) {
  const wrap = document.createElement('div');
  wrap.className = 'txn-item';
  const row = document.createElement('button');
  row.type = 'button'; row.className = 'txn-row'; row.setAttribute('aria-expanded', 'false');
  const left = document.createElement('span'); left.className = 'txn-when';
  const time = document.createElement('b');
  time.textContent = new Date(sale.at || Date.now())
    .toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
  const sub = document.createElement('small');
  const n = txnItemCount(sale);
  const tenderLbl = sale.tenders
    ? [sale.tenders.credit > 0 ? 'Credit' : null, sale.tenders.card > 0 ? 'Card' : null,
       sale.tenders.cash > 0 ? 'Cash' : null].filter(Boolean).join(' + ')
    : (sale.tender === 'card' ? 'Card' : (sale.tender === 'credit' ? 'Credit' : 'Cash'));
  sub.textContent = '#' + (sale.id || '') + ' · ' + tenderLbl
    + ' · ' + n + (n === 1 ? ' item' : ' items') + (sale.tax_exempt ? ' · EXEMPT' : '')
    + (sale.refund_status === 'full' ? ' · REFUNDED'
      : (sale.refund_status === 'partial' ? ' · PART REFUNDED' : ''))
    + '';
  if (sale.frame) { const ft = document.createElement('span'); ft.innerHTML = ' \u00b7 ' + IC_CAM + ' frame'; sub.appendChild(ft); }
  if (sale.clip) { const ct = document.createElement('span'); ct.innerHTML = ' \u00b7 \u25b6 clip'; sub.appendChild(ct); }
  left.appendChild(time); left.appendChild(sub);
  const total = document.createElement('span'); total.className = 'txn-total';
  total.textContent = money(sale.total);
  const caret = document.createElement('span'); caret.className = 'txn-caret'; caret.textContent = '▾';
  row.appendChild(left); row.appendChild(total); row.appendChild(caret);

  const detail = document.createElement('div');
  detail.className = 'txn-detail hidden';
  (sale.lines || []).forEach((l, li2) => {
    const li = document.createElement('div'); li.className = 'txn-line';
    const nm = document.createElement('span');
    const backQty = sale.refunded_lines ? (sale.refunded_lines[txnLineKey(l, li2)] || 0) : 0;
    nm.textContent = (l.name || '') + ' ×' + (l.qty ?? 1)
      + (l.manual ? (l.taxable ? ' (TAX)' : ' (NO-TAX)') : '')
      + (backQty > 0 ? ' · ' + backQty + ' refunded' : '');
    const lt = document.createElement('b'); lt.textContent = money(l.line_total);
    li.appendChild(nm); li.appendChild(lt); detail.appendChild(li);
  });
  const tots = document.createElement('div'); tots.className = 'txn-totals';
  const trow = (label, val) => {
    const r = document.createElement('div'); r.className = 'txn-trow';
    const a = document.createElement('span'); a.textContent = label;
    const b = document.createElement('b'); b.textContent = val;
    r.appendChild(a); r.appendChild(b); return r;
  };
  tots.appendChild(trow('Subtotal', money(sale.subtotal)));
  tots.appendChild(trow('Tax' + (sale.tax_exempt ? ' — EXEMPT' : ''), money(sale.tax)));
  if (Number(sale.surcharge) > 0) {
    const p = (sale.surcharge_pct !== undefined && sale.surcharge_pct !== null)
      ? ' (' + fmtPct(sale.surcharge_pct) + '%)' : '';
    tots.appendChild(trow('Card surcharge' + p, money(sale.surcharge)));
  }
  tots.appendChild(trow('Total', money(sale.total)));
  if (sale.tenders) {
    if (Number(sale.tenders.credit) > 0) { tots.appendChild(trow('Store credit', '−' + money(sale.tenders.credit))); }
    if (Number(sale.tenders.card) > 0) { tots.appendChild(trow('Paid by card', money(sale.tenders.card))); }
    if (Number(sale.tenders.cash) > 0) { tots.appendChild(trow('Paid by cash', money(sale.tenders.cash))); }
  } else {
    tots.appendChild(trow('Paid by', sale.tender === 'card' ? 'Card' : (sale.tender === 'credit' ? 'Credit' : 'Cash')));
  }
  if (sale.tender === 'cash' && sale.tendered !== undefined && sale.tendered !== null) {
    tots.appendChild(trow('Cash given', money(sale.tendered)));
    tots.appendChild(trow('Change', money(sale.change_due || 0)));
  }
  if (Number(sale.refunded_total) > 0) {
    tots.appendChild(trow('Refunded back', money(sale.refunded_total)
      + (sale.refund_status === 'full' ? ' (in full)' : ' (partial)')));
  }
  detail.appendChild(tots);
  const meta = document.createElement('p'); meta.className = 'txn-meta';
  meta.textContent = 'Receipt ' + (sale.id || '') + ' · '
    + new Date(sale.at || Date.now()).toLocaleString('en-US');
  detail.appendChild(meta);
  if (sale.frame) {
    const fb = document.createElement('button');
    fb.type = 'button'; fb.className = 'txn-frame'; fb.innerHTML = IC_CAM + ' View counter frame';
    fb.addEventListener('click', () => {
      $('frameImg').src = '../server/frame.php?txn=' + encodeURIComponent(sale.id || '');
      $('frameModal').classList.remove('hidden');
    });
    detail.appendChild(fb);
  }
  if (sale.clip) {
    const cb = document.createElement('button');
    cb.type = 'button'; cb.className = 'txn-frame';
    cb.innerHTML = '\u25b6 Watch event clip' + (sale.clip.audio ? ' · with sound' : '');
    cb.addEventListener('click', () => {
      $('clipVideo').src = '../server/frame.php?clip=' + encodeURIComponent(sale.id || '');
      $('clipModal').classList.remove('hidden');
    });
    detail.appendChild(cb);
  }
  const rp = document.createElement('button');
  rp.type = 'button'; rp.className = 'txn-reprint'; rp.textContent = 'Reprint this receipt';
  rp.addEventListener('click', async () => {
    closeTxnHistory();
    await loadSettings();
    showReceipt(sale);
  });
  detail.appendChild(rp);
  // Refund: offered while the store allows refunds and the sale still
  // has units that can come back (the server re-checks both, plus the
  // window and the PIN, at execution — this button only opens the sheet).
  if ((receiptSettings || {}).refunds_allowed !== false && sale.refund_status !== 'full') {
    const rb = document.createElement('button');
    rb.type = 'button'; rb.className = 'txn-refund'; rb.textContent = 'Refund…';
    rb.addEventListener('click', () => openRefundSheet(sale));
    detail.appendChild(rb);
  }

  row.addEventListener('click', () => {
    const open = detail.classList.toggle('hidden') === false;
    row.setAttribute('aria-expanded', open ? 'true' : 'false');
    wrap.classList.toggle('open', open);
  });
  wrap.appendChild(row); wrap.appendChild(detail);
  return wrap;
}
function renderTxnHistory(days) {
  txnSales = new Map();
  const body = $('txnBody');
  body.innerHTML = '';
  if (!days.length) {
    body.innerHTML = '<p class="txn-empty">No sales recorded yet — the first sale rung will appear here.</p>';
    return;
  }
  days.forEach(day => {
    (day.sales || []).forEach(s => { if (s && s.id) { txnSales.set(s.id, s); } });
    const head = document.createElement('div');
    head.className = 'txn-day';
    const lbl = document.createElement('b'); lbl.textContent = txnDayLabel(day.date);
    const sum = (day.sales || []).reduce((t, s) => t + (Number(s.total) || 0), 0);
    const info = document.createElement('span');
    info.textContent = (day.count ?? (day.sales || []).length) + ' sales · ' + money(sum)
      + (day.truncated ? ' · latest 100 shown' : '');
    head.appendChild(lbl); head.appendChild(info);
    body.appendChild(head);
    (day.sales || []).forEach(s => body.appendChild(txnRowEl(s)));
  });
}
async function smHistoryAction() {
  closeSettingsMenu();
  $('txnModal').classList.remove('hidden');
  const body = $('txnBody');
  body.innerHTML = '<p class="txn-empty">Loading…</p>';
  let data = null;
  try { data = await (await fetch(API + '?history')).json(); } catch (e) { /* handled below */ }
  if (!data || !Array.isArray(data.days)) {
    body.innerHTML = '<p class="txn-empty">History is unavailable right now.</p>';
    return;
  }
  renderTxnHistory(data.days);
}
function closeTxnHistory() { $('txnModal').classList.add('hidden'); }

// --- toggles ------------------------------------------------------------------
async function saveSetting(key, value) {
  // One key per save, through the gated post: the server lets the two
  // instant flips (printer auto_open, camera-frame watch_frame) through
  // ungated and holds every other key — the Store toggle's
  // register_open above all — for the admin PIN.
  const r = await gatedApiPost({ settings: { [key]: value }, session: SESSION_ID });
  if (r && !r.cancelled && !r.locked) { receiptSettings = r; }
  return r;
}
async function smPrinterAction() {
  const on = (receiptSettings || {}).auto_open !== false;
  const r = await saveSetting('auto_open', !on);
  if (!r || r.cancelled || r.locked) { return; }
  setToggle($('smPrinterBtn'), !on);
  showToast(!on ? 'Printer on — the receipt prints after each sale.'
                : 'Printer off — receipts will not print after a sale.');
}
async function smWatchAction() {
  // The camera-frame flip: an instant counter switch like the printer
  // toggle (owner 85/15 rule) — it posts watch_frame ALONE, the save the
  // server lets through ungated; configuration beyond the flip (like
  // everything else) lives in Gol chat. In link mode the MR.STORE
  // master stands above the flip: while it is off the flip is HELD —
  // tapping explains instead of saving a state the stream would defy.
  if (camMasterHeld()) {
    showToast('Held off — Register Camera is off in MR.STORE Settings for this store. The flip works again when the master is on.');
    return;
  }
  const on = (receiptSettings || {}).watch_frame === true;
  const r = await saveSetting('watch_frame', !on);
  if (!r || r.cancelled || r.locked) { return; }
  setToggle($('smWatchBtn'), !on);
  camDenied = false; // a fresh word — the watch may retry its camera
  syncWatchCam();
  showToast(!on ? 'Camera frame on — one counter photo is saved with each cash sale.'
                : 'Camera frame off — cash sales record no photo.');
}
async function smStoreAction() {
  const open = storeIsOpen();
  const r = await saveSetting('register_open', !open);
  if (!r || r.cancelled || r.locked) { return; } // nothing saved — the toggle stays as it was
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
$('smDayCloseBtn').addEventListener('click', smDayCloseAction);
$('smReprintBtn').addEventListener('click', smReprintAction);
$('smHistoryBtn').addEventListener('click', smHistoryAction);
$('txnClose').addEventListener('click', closeTxnHistory);
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && !$('txnModal').classList.contains('hidden')) { closeTxnHistory(); }
});
$('smPrinterBtn').addEventListener('click', smPrinterAction);
$('smWatchBtn').addEventListener('click', smWatchAction);
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
// The keypad buffer is an integer CENTS count, entered the way every
// register and ATM takes money (owner directive, Oct 28): digits shift
// in from the right as cents — 2, 9, 9 reads $2.99, never $299.00. The
// "." key of the old decimal pad is now "00", appending two zeros.
// Six digits caps the buffer at $9,999.99 — the server's manual-amount
// limit — so an over-cap amount can never even be keyed.
const MANUAL_CAP = 9999.99;
const KP_MAX_DIGITS = 6;
let kpCents = '';
function kpShow() {
  const cents = kpCents === '' ? 0 : parseInt(kpCents, 10);
  $('kpAmount').textContent = money(cents / 100);
}
function kpErrorShow(text) {
  const el = $('kpError');
  el.textContent = text; el.classList.remove('hidden');
}
function kpErrorHide() { $('kpError').classList.add('hidden'); }
function kpPress(key) {
  kpErrorHide();
  if (key === 'B') { kpCents = kpCents.slice(0, -1); kpShow(); return; }
  if (key === 'C') { kpCents = ''; kpShow(); return; }
  if (key === '00') {
    kpCents = (kpCents + '00').slice(0, KP_MAX_DIGITS);
    kpShow(); return;
  }
  if (!/^\d$/.test(key)) { return; } // "." is gone from the pad — ignored
  if (kpCents.length >= KP_MAX_DIGITS) { return; }
  kpCents += key;
  kpShow();
}
function kpAdd(taxable) {
  if (!storeIsOpen()) { storeClosedNote(); return; }
  const v = kpCents === '' ? NaN
    : Math.round((parseInt(kpCents, 10) / 100 + Number.EPSILON) * 100) / 100;
  if (!(v > 0)) { kpErrorShow('Key an amount above $0.00 first.'); return; }
  if (v > MANUAL_CAP) { kpErrorShow('Manual amounts are capped at $9,999.99 — ring large items from the catalog.'); return; }
  manualLines.push({ id: 'm' + (++manualSeq), price: v, taxable });
  kpCents = ''; kpShow(); kpErrorHide();
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
// digits, Backspace, and Enter for Add · TAXABLE — unless the
// cashier is typing into a field (scan, search, chat). "." is ignored:
// the cents-shift pad has no decimal key.
document.addEventListener('keydown', (e) => {
  if (entryMode !== 'keypad') { return; }
  if (e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA')) { return; }
  if (/^\d$/.test(e.key)) { kpPress(e.key); }
  else if (e.key === 'Backspace') { e.preventDefault(); kpPress('B'); }
  else if (e.key === 'Enter') { e.preventDefault(); kpAdd(true); }
});
kpShow();

// --- Add Product sheet --------------------------------------------------------
// The catalog's full richness from the register's own top row: name, the
// live category list (or a brand-new category), price and cost with the
// margin shown LIVE both ways — typing price + cost fills the margin %;
// typing a margin % against a cost re-prices to the cent. Opening stock
// and par ride the living shelf; a blank barcode takes the generated
// internal code; no image means the tile keeps its letter fallback.
// Creation itself is ONE path: api.php product_create -> the constructor
// in store.php the Gol chat's new-item execution also builds with (in
// link mode the same call lands the product in MR.STORE's catalog).
// Creation is an admin execution, so when this session is not elevated
// the sheet swaps to its PIN step and the same save re-posts with the
// PIN; the gate's rules (no PIN yet -> set one in two entries, wrong
// PINs, the 3-strike lockout) are the server's, on the chat's own
// session store — the sheet never decides who is admin.
let npParDirty = false;
function npCats() { return [...new Set(catalog.products.map(p => p.category).filter(Boolean))]; }
function npMarginOf(price, cost) {
  if (!(price > 0) || !(cost > 0)) { return null; }
  return (price - cost) / price * 100;
}
function npPriceOf(cost, marginPct) {
  if (!(cost > 0) || !(marginPct >= 0) || marginPct >= 100) { return null; }
  return Math.round((cost / (1 - marginPct / 100) + Number.EPSILON) * 100) / 100;
}
function npSyncMargin(from) {
  if (from === 'margin') {
    const p = npPriceOf(parseFloat($('npCost').value), parseFloat($('npMargin').value));
    if (p !== null) { $('npPrice').value = p.toFixed(2); }
    return;
  }
  const m = npMarginOf(parseFloat($('npPrice').value), parseFloat($('npCost').value));
  $('npMargin').value = m === null ? '' : m.toFixed(1);
}
function npShowStep(step) {
  $('npFormStep').classList.toggle('hidden', step !== 'form');
  $('npPinStep').classList.toggle('hidden', step !== 'pin');
}
function npErrorShow(t) {
  const el = $('npError'); el.textContent = t || ''; el.classList.toggle('hidden', !t);
}
function npPinErrorShow(t) {
  const el = $('npPinError'); el.textContent = t || ''; el.classList.toggle('hidden', !t);
}
function openProductSheet(prefillBarcode) {
  const cats = npCats();
  $('npCategory').innerHTML = cats.map(c =>
    `<option value="${esc(c)}">${esc(c)}</option>`).join('')
    + '<option value="__new__">＋ New category…</option>';
  $('npName').value = ''; $('npPrice').value = ''; $('npCost').value = ''; $('npMargin').value = '';
  // The Add Product button wires this function as its click handler, so
  // the argument is an event there — only a real string pre-fills.
  $('npBarcode').value = typeof prefillBarcode === 'string' ? prefillBarcode : '';
  $('npImg').value = ''; $('npCategoryNew').value = '';
  $('npStock').value = 0; $('npTaxable').checked = true; $('npPin').value = '';
  npParDirty = false;
  $('npPar').value = PAR_BY_CAT[$('npCategory').value] || 24;
  $('npCategoryNewWrap').classList.add('hidden');
  npErrorShow(''); npPinErrorShow('');
  npShowStep('form');
  $('productModal').classList.remove('hidden');
  $('npName').focus();
}
function npPayload() {
  const price = parseFloat($('npPrice').value);
  const costRaw = $('npCost').value.trim();
  const par = parseInt($('npPar').value, 10);
  const stock = parseInt($('npStock').value, 10);
  const f = {
    name: $('npName').value.trim(),
    category: $('npCategory').value === '__new__' ? $('npCategoryNew').value.trim() : $('npCategory').value,
    price: Number.isFinite(price) ? price : '',
    barcode: $('npBarcode').value.trim(),
    stock: Number.isFinite(stock) ? stock : 0,
    taxable: $('npTaxable').checked,
    img: $('npImg').value.trim(),
  };
  if (costRaw !== '' && Number.isFinite(parseFloat(costRaw))) { f.cost = parseFloat(costRaw); }
  if (Number.isFinite(par) && par > 0) { f.par = par; }
  return f;
}
async function npSubmit(withPin) {
  const body = { product_create: npPayload(), session: SESSION_ID };
  if (withPin) { body.pin = $('npPin').value.trim(); }
  const res = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body) });
  const r = await res.json();
  if (r && r.product) {
    const p = r.product;
    $('productModal').classList.add('hidden');
    $('npPin').value = '';
    showToast('Added — ' + p.name + ' · ' + money(p.price));
    await loadCatalog();
    // Land the cashier on the new tile: its own category, grid showing.
    setEntryMode('products');
    activeCat = npCats().includes(p.category) ? p.category : 'All';
    $('searchInput').value = '';
    renderCats(); renderGrid();
    return;
  }
  if (r && r.pin_required) {
    // The gate wants a PIN (or another one): the form keeps its values
    // and the PIN step takes over, carrying the gate's own words.
    if (r.note) { $('npPinNote').textContent = r.note; }
    else if (r.stage === 'create1') {
      $('npPinNote').textContent = 'No admin PIN yet — choose one (4–6 digits) to continue.';
    }
    npShowStep('pin');
    npPinErrorShow(r.error || '');
    $('npPin').value = '';
    $('npPin').focus();
    return;
  }
  if (r && r.error) {
    if (r.locked) { npShowStep('pin'); npPinErrorShow(r.error); return; }
    // A plain validation refusal — back on the form, where it is fixed.
    npShowStep('form'); npErrorShow(r.error);
    return;
  }
  npErrorShow('The product could not be added.');
}
$('addProductBtn').addEventListener('click', openProductSheet);
$('npCancel').addEventListener('click', () => $('productModal').classList.add('hidden'));
$('npPinCancel').addEventListener('click', () => {
  $('productModal').classList.add('hidden'); $('npPin').value = '';
});
$('npSave').addEventListener('click', () => npSubmit(false));
$('npPinBtn').addEventListener('click', () => npSubmit(true));
$('npPin').addEventListener('keydown', (e) => {
  if (e.key === 'Enter') { e.preventDefault(); npSubmit(true); }
});
$('npCategory').addEventListener('change', () => {
  const isNew = $('npCategory').value === '__new__';
  $('npCategoryNewWrap').classList.toggle('hidden', !isNew);
  if (isNew) { $('npCategoryNew').focus(); }
  if (!npParDirty) { $('npPar').value = PAR_BY_CAT[$('npCategory').value] || 24; }
});
$('npPar').addEventListener('input', () => { npParDirty = true; });
$('npPrice').addEventListener('input', () => npSyncMargin('price'));
$('npCost').addEventListener('input', () => npSyncMargin('cost'));
$('npMargin').addEventListener('input', () => npSyncMargin('margin'));
// The barcode field is scanner-ready: a hardware scanner is a keyboard
// wedge — it types the code fast and ends with Enter. That Enter is
// swallowed here (never a submit, never a create) and focus moves on to
// the stock field, the next thing a received product needs.
$('npBarcode').addEventListener('keydown', (e) => {
  if (e.key === 'Enter') { e.preventDefault(); $('npStock').focus(); }
});

// --- voice + camera: Gol's ears and eyes (owner directive, Oct 28) -----------
// Mic = ears: press-to-talk only (never always-on). A spoken sentence
// enters through voiceFinal -> sendChat — the EXACT path a typed message
// takes — so "add 3 dollars in coke" said aloud stages the same card as
// the same words typed. Camera = eyes: one stream, one visible "Camera
// on" pill whenever it is live, and three honest jobs — a photo for the
// chat lanes, a scan-by-phone pairing for registers with no camera, and
// (when the owner turns the watch on) one frame paired to each cash
// sale. None of it can block the register: every failure degrades to a
// plain word and the till keeps ringing.
function hasGUM() {
  return typeof navigator !== 'undefined' && !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
}

// --- the recognizer's language follows the conversation ----------------------
// The brain mirrors the user's language per message; the recognizer
// makes the same call on the client BEFORE the words exist. Choice,
// documented: the stored preference is the language of the last chat
// message this device sent (Devanagari -> ne-NP; the brain's own
// Romanized Nepali stems -> ne-NP; its Hindi stems -> hi-IN), default
// en-US. noteChatLang runs inside sendChat, so typing teaches it too.
function voiceLangOf(text) {
  if (/[\u0900-\u097F]/.test(String(text || ''))) { return 'ne-NP'; }
  const t = ' ' + String(text || '').toLowerCase() + ' ';
  const np = ['kati', 'thap', 'mulya', 'hisab', 'aaja', 'chha', 'gara', 'deu', 'dinus',
              'chahiyo', 'bikri', 'saman', 'naya', 'pasal', 'banda'];
  const hi = ['kitna', 'kitne', 'daam', 'jodo', 'lagao', 'saaman', 'karo', 'badlo',
              'hisaab', ' hai ', ' aaj ', ' mein '];
  let n = 0, h = 0;
  np.forEach(w => { if (t.includes(w)) { n++; } });
  hi.forEach(w => { if (t.includes(w)) { h++; } });
  if (n === 0 && h === 0) { return null; }
  return n >= h ? 'ne-NP' : 'hi-IN';
}
function noteChatLang(text) {
  const l = voiceLangOf(text);
  if (l) { try { localStorage.setItem('golpos_voice_lang', l); } catch (e) { /* private mode */ } }
}
function voiceLangPref() {
  try { return localStorage.getItem('golpos_voice_lang') || 'en-US'; } catch (e) { return 'en-US'; }
}

let recog = null;
let voiceOn = false;
function voiceSupported() {
  return typeof window !== 'undefined' && !!(window.SpeechRecognition || window.webkitSpeechRecognition);
}
function setVoiceUi(on) {
  voiceOn = on;
  ['voiceBtn', 'chatMicBtn'].forEach(id => { const b = $(id); if (b) { b.classList.toggle('listening', on); } });
  const pill = $('voicePill'); if (pill) { pill.classList.toggle('hidden', !on); }
}
function startVoice() {
  if (!voiceSupported()) {
    showToast('Voice is not available in this browser — typing works as always.');
    return;
  }
  if (voiceOn && recog) { try { recog.stop(); } catch (e) { /* already stopping */ } return; }
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  recog = new SR();
  recog.lang = voiceLangPref();
  recog.interimResults = true;
  recog.continuous = false;
  recog.onresult = (ev) => {
    let interim = '', finalText = '';
    for (let i = ev.resultIndex || 0; i < ev.results.length; i++) {
      const r = ev.results[i];
      const s = (r[0] && r[0].transcript) || '';
      if (r.isFinal) { finalText += s; } else { interim += s; }
    }
    if (interim) { $('chatInput').value = interim; }
    if (finalText.trim()) { voiceFinal(finalText.trim()); }
  };
  recog.onerror = (ev) => {
    setVoiceUi(false);
    const kind = ev && ev.error;
    if (kind === 'not-allowed' || kind === 'service-not-allowed') {
      showToast('Microphone is blocked — allow the mic in the browser, then try again.');
    } else if (kind === 'no-speech') {
      showToast("Didn't catch that — tap the mic and speak again.");
    } else if (kind !== 'aborted') {
      showToast('Voice stumbled — typing works as always.');
    }
  };
  recog.onend = () => setVoiceUi(false);
  setVoiceUi(true);
  try { recog.start(); } catch (e) { setVoiceUi(false); }
}
// THE seam: a final transcript becomes a chat message through the same
// sendChat a typed message takes — same session, same brain, same card.
function voiceFinal(text) {
  setVoiceUi(false);
  $('chatInput').value = '';
  sendChat(text);
}

// --- the one camera stream ----------------------------------------------------
let camStream = null;  // the live stream, whoever opened it (watch or modal)
let camDenied = false; // a refusal is remembered so the till never nags
function setCamPill(on) {
  const p = $('camPill'); if (p) { p.classList.toggle('hidden', !on); }
}
let camStreamAudio = false; // whether the live stream carries the mic
async function camStart(videoEl, soft) {
  if (!hasGUM() || camDenied) { return null; }
  try {
    // The mic joins only when audio is effective (Part B: the master's
    // audio child in link mode, the register's own key standalone). A
    // stream already running with the wrong audio-ness is restarted —
    // a mic flag that changed while the camera idled must still land.
    const wantAudio = camAudioWanted();
    if (camStream && camStreamAudio !== wantAudio) { camStopAll(); }
    if (!camStream) {
      camStream = await navigator.mediaDevices.getUserMedia(
        { video: { facingMode: 'environment' }, audio: wantAudio });
      camStreamAudio = camStream.getAudioTracks().length > 0;
    }
    if (videoEl) {
      videoEl.srcObject = camStream;
      try { await videoEl.play(); } catch (e) { /* autoplay dress; frames still grab */ }
    }
    setCamPill(true);
    return camStream;
  } catch (e) { if (!soft) { camDenied = true; } return null; }
}
function camStopAll() {
  if (camStream) { camStream.getTracks().forEach(t => t.stop()); camStream = null; }
  camStreamAudio = false;
  // Detach the dead stream from its screens too — a stopped stream left
  // attached would keep the last frame (and a live-looking srcObject)
  // on the watch video after the setting flips off.
  const wv = $('watchVideo'); if (wv) { wv.srcObject = null; }
  const cv = $('camVideo'); if (cv) { cv.srcObject = null; }
  setCamPill(false);
}
// The watch stream follows the EFFECTIVE camera state (called from
// loadSettings and the settings save): wanted -> a live stream waits in
// the hidden watch video with the pill showing; not wanted -> the
// stream stops, unless the capture view is holding it right now.
// AUTO-ON is the owner's ruling (Oct 28): setting ON means the camera is
// simply ON — the stream starts at register boot with no click. A boot
// attempt can still meet a camera permission the browser has not granted
// yet; that failure is SOFT (it never latches camDenied) and arms a
// one-time retry on the first touch or keypress anywhere on the
// register, so by the time a person is actually at the till the stream
// is live — still with zero camera-specific clicks.
let watchRetryArmed = false;
function armWatchRetry() {
  if (watchRetryArmed) { return; }
  watchRetryArmed = true;
  const retry = async () => {
    window.removeEventListener('pointerdown', retry, true);
    window.removeEventListener('keydown', retry, true);
    watchRetryArmed = false;
    if (camWanted() && !camStream) {
      camDenied = false; // the person's first touch is the fresh ask
      await camStart($('watchVideo'));
    }
  };
  window.addEventListener('pointerdown', retry, true);
  window.addEventListener('keydown', retry, true);
}
async function syncWatchCam() {
  const want = camWanted();
  if (want) {
    if (!camStream) {
      const s = await camStart($('watchVideo'), true);
      if (!s) { armWatchRetry(); }
    }
    return;
  }
  if (camStream && $('camModal').classList.contains('hidden')) { camStopAll(); }
}
function grabFrame(videoEl) {
  return new Promise((resolve) => {
    try {
      if (!videoEl || !videoEl.videoWidth || !videoEl.videoHeight) { resolve(null); return; }
      const c = document.createElement('canvas');
      c.width = videoEl.videoWidth; c.height = videoEl.videoHeight;
      const g = c.getContext('2d');
      if (!g) { resolve(null); return; }
      g.drawImage(videoEl, 0, 0, c.width, c.height);
      if (c.toBlob) { c.toBlob(b => resolve(b || null), 'image/jpeg', 0.85); }
      else { resolve(null); }
    } catch (e) { resolve(null); }
  });
}
// Cash-tender frame pairing: after a cash sale completes, one frame from
// the live watch camera rides to frame.php against the sale's id. No
// live camera -> no frame and no fuss; the sale already stands. The
// stream exists only when the effective state wants it (Part B: master
// && flip in link mode), so obedience lives in camWanted(), not here.
async function attachCashFrame(txnId) {
  try {
    if (!camWanted()) { return; }
    if (!camStream || !txnId) { return; }
    const blob = await grabFrame($('watchVideo'));
    if (!blob) { return; }
    const fd = new FormData();
    fd.append('photo', new File([blob], 'frame.jpg', { type: 'image/jpeg' }));
    fd.append('txn', txnId);
    await fetch('../server/frame.php', { method: 'POST', body: fd });
  } catch (e) { /* the frame is a passenger, never a gate */ }
}
// Event-clip recording (Part B): when footage recording is effective, a
// cash tender also keeps a short clip — a few seconds from the tender
// moment, off the same live stream, the mic included when audio is
// effective. The clip rides to frame.php's clip door with its audio
// fact as metadata and lands beside the still frame. No MediaRecorder
// (or no stream) -> the still stands alone; the sale never waits.
function recordCashClip(txnId) {
  try {
    if (!camRecordWanted() || !camStream || !txnId) { return; }
    if (typeof MediaRecorder === 'undefined') { return; }
    let mime = '';
    for (const m of ['video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm', 'video/mp4']) {
      try { if (MediaRecorder.isTypeSupported(m)) { mime = m; break; } } catch (e) { /* keep looking */ }
    }
    const rec = new MediaRecorder(camStream, mime ? { mimeType: mime } : undefined);
    const chunks = [];
    rec.ondataavailable = (e) => { if (e.data && e.data.size) { chunks.push(e.data); } };
    rec.onstop = async () => {
      try {
        if (!chunks.length) { return; }
        const type = rec.mimeType || mime || 'video/webm';
        const blob = new Blob(chunks, { type });
        const fd = new FormData();
        fd.append('clip', new File([blob], 'clip.' + (type.indexOf('mp4') >= 0 ? 'mp4' : 'webm'), { type }));
        fd.append('txn', txnId);
        fd.append('audio', camAudioWanted() ? '1' : '0');
        await fetch('../server/frame.php', { method: 'POST', body: fd });
      } catch (e) { /* the clip is evidence, never a gate */ }
    };
    rec.start(250);
    setTimeout(() => { try { if (rec.state !== 'inactive') { rec.stop(); } } catch (e) { /* already done */ } }, 4000);
  } catch (e) { /* no recorder, no clip, no fuss */ }
}

// --- employee lane: the morning lock, the actor, unlock photos -----------------
// The register boots LOCKED when the roster holds a PIN: the overlay
// covers the till, Gol chat stays reachable above it, and the only
// conversation is "PIN please." The brain's replies carry the lock
// state + actor; this applies them. The lock is the register UI's
// (v1) — honest scope: it gates the till surface the cashier uses.
let lockActor = null;
function applyLockState(locked, actor) {
  if (actor !== undefined) { lockActor = actor || null; }
  const ov = $('lockOverlay');
  if (ov && locked !== undefined && locked !== null) { ov.classList.toggle('hidden', !locked); }
  const chip = $('actorChip');
  if (chip) {
    if (lockActor && lockActor.name) {
      chip.textContent = lockActor.role === 'admin' ? 'Owner · admin' : lockActor.name;
      chip.classList.remove('hidden');
    } else { chip.classList.add('hidden'); }
  }
  const mt = $('myTasksBtn');
  if (mt) { mt.classList.toggle('hidden', !(lockActor && lockActor.role === 'employee')); }
}
async function refreshLockState() {
  try {
    const r = await (await fetch(API + '?lock&session=' + encodeURIComponent(SESSION_ID))).json();
    applyLockState(r.locked, r.actor);
  } catch (e) { /* the register never stops for a state read */ }
}
// The unlock stamp (spec §3b): one frame from the live watch stream —
// or a one-shot capture when the camera can start — rides to frame.php
// against the unlock event's id. No camera -> the event simply stands
// as "no photo"; the unlock itself never waits on a picture.
async function attachUnlockFrame(eventId) {
  try {
    if (!eventId) { return; }
    let blob = null;
    if (camStream) { blob = await grabFrame($('watchVideo')); }
    if (!blob && camWanted()) {
      const st = await camStart($('watchVideo'));
      if (st) { blob = await grabFrame($('watchVideo')); }
    }
    if (!blob) { return; }
    const fd = new FormData();
    fd.append('photo', new File([blob], 'unlock.jpg', { type: 'image/jpeg' }));
    fd.append('unlock', eventId);
    await fetch('../server/frame.php', { method: 'POST', body: fd });
  } catch (e) { /* a photo is evidence, never a gate */ }
}

// --- her tasks view: the next due duty first, the list as overview --------------
async function openMyTasks() {
  try {
    const r = await (await fetch(API + '?mytasks&session=' + encodeURIComponent(SESSION_ID))).json();
    const nx = r.next;
    $('tasksNext').innerHTML = nx
      ? `<div class="task-next"><span class="task-next-tag">NEXT</span><b>${esc(nx.text)}</b>` +
        (nx.reason ? `<div class="task-next-reason">${esc(nx.reason)}</div>` : '') +
        `<button type="button" class="task-done-btn" data-done="${esc(nx.task_id)}:${nx.index}">Done</button></div>`
      : '';
    const items = r.items || [];
    $('tasksList').innerHTML = items.length
      ? items.map(it => `<div class="task-row"><span>${esc(it.text)}${it.source === 'gol' ? ' <small>(from Gol)</small>' : ''}</span>` +
          `<button type="button" class="task-done-btn" data-done="${esc(it.task_id)}:${it.index}">Done</button></div>`).join('')
      : '<p class="owner-muted">No open tasks — nothing waiting for you.</p>';
    $('tasksModal').classList.remove('hidden');
    $('tasksList').querySelectorAll('[data-done]').forEach(b =>
      b.addEventListener('click', async () => {
        const [tid, idx] = b.dataset.done.split(':');
        await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ task_done: { task_id: tid, index: Number(idx) }, session: SESSION_ID }) });
        openMyTasks();
      }));
    const nb = $('tasksNext').querySelector('[data-done]');
    if (nb) { nb.addEventListener('click', async () => {
      const [tid, idx] = nb.dataset.done.split(':');
      await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ task_done: { task_id: tid, index: Number(idx) }, session: SESSION_ID }) });
      openMyTasks();
    }); }
  } catch (e) { showToast('Tasks could not be read.'); }
}

// --- the capture view -----------------------------------------------------------
let camMode = 'photo'; // 'photo' | 'sense'
let camShot = null;    // the captured Blob waiting for its job
async function openCam(mode) {
  camMode = mode || 'photo';
  camDenied = false; // an explicit tap is a fresh ask
  if (camMasterHeld()) {
    // Part B obedience: while the MR.STORE master is off, NO camera
    // stream starts on this register — not even for a look. A photo or
    // bill still reaches Gol through the native capture door (the same
    // door a camera-less register uses); sensing answers honestly.
    if (camMode === 'sense') {
      showToast('Register Camera is off in MR.STORE Settings — no camera can start on this register.');
      return false;
    }
    if (camMode === 'photo') { $('chatDrawer').classList.remove('hidden'); $('photoInput').click(); }
    return false;
  }
  if (!hasGUM()) {
    // No in-app camera: the native capture input is the same door.
    if (camMode === 'photo') { $('chatDrawer').classList.remove('hidden'); $('photoInput').click(); }
    return false;
  }
  camShot = null;
  $('camPreview').classList.add('hidden');
  $('camVideo').classList.remove('hidden');
  $('camLiveBtns').classList.remove('hidden');
  $('camShotBtns').classList.add('hidden');
  $('camUseSense').classList.toggle('hidden', camMode !== 'sense');
  $('camUsePhoto').classList.toggle('hidden', camMode === 'sense');
  $('camUseBill').classList.toggle('hidden', camMode === 'sense');
  $('camNote').textContent = camMode === 'sense'
    ? 'Point the camera at the counter, then capture — Gol answers whether a customer is there.'
    : 'Point the camera, then capture. The photo goes to Gol\'s chat.';
  $('camModal').classList.remove('hidden');
  const s = await camStart($('camVideo'));
  if (!s) {
    closeCam();
    showToast('No camera on this register — pair a phone instead: Settings → Scan with phone.');
    return false;
  }
  return true;
}
function closeCam() {
  $('camModal').classList.add('hidden');
  camShot = null;
  if (camWanted()) { camStart($('watchVideo')); }
  else { camStopAll(); }
}
async function camSnapNow() {
  const blob = await grabFrame($('camVideo'));
  if (!blob) { showToast('The camera gave no picture — try again.'); return; }
  camShot = blob;
  try { $('camPreview').src = URL.createObjectURL(blob); } catch (e) { /* preview is dress */ }
  $('camPreview').classList.remove('hidden');
  $('camVideo').classList.add('hidden');
  $('camLiveBtns').classList.add('hidden');
  $('camShotBtns').classList.remove('hidden');
}
function camShotFile() { return new File([camShot], 'photo.jpg', { type: 'image/jpeg' }); }

// --- customer sensing on demand ---------------------------------------------------
// The asks this lane answers (English + the shop's Nepali/Hindi shapes):
// a counter word AND a customer word in one breath. Everything else
// stays a brain question.
function isSenseAsk(text) {
  const t = String(text || '');
  const counter = /(counter|काउन्टर|register|till)/i.test(t);
  const customer = /(customer|ग्राहक|visitor|anyone|someone|people|person|मान्छे|कोही|कोई)/i.test(t);
  return counter && customer;
}
async function senseFlow(text) {
  chatMsg(text, 'me');
  $('chatDrawer').classList.remove('hidden');
  if (camMasterHeld()) { // the master's word: no stream, no seeing — said plainly
    chatMsg('Register Camera is off in MR.STORE Settings for this store, so no camera runs on this register and I can\'t see the counter from here.', 'gol');
    return;
  }
  if (camStream) { // the watch camera is live — answer from it at once
    const blob = await grabFrame($('watchVideo'));
    if (blob) { sendSensePhoto(new File([blob], 'counter.jpg', { type: 'image/jpeg' })); return; }
  }
  if (hasGUM()) { openCam('sense'); return; }
  chatMsg('No camera on this register — I can\'t see the counter from here. Pair a phone (Settings → Scan with phone) or use a register with a camera, and I\'ll answer that at a glance.', 'gol');
}
async function sendSensePhoto(file) {
  const fd = new FormData();
  fd.append('photo', file);
  fd.append('session', SESSION_ID);
  fd.append('mode', 'sense');
  try {
    const res = await fetch('../server/photo.php', { method: 'POST', body: fd });
    const r = await res.json();
    if (r.reply) { chatMsg(r.reply, 'gol'); }
  } catch (e) { chatMsg('The counter photo could not be sent just now.', 'gol'); }
}

// --- scan-by-phone: the register side ---------------------------------------------
let scanPairUrl = '';
async function openScanModal() {
  $('scanModal').classList.remove('hidden');
  $('scanCode').textContent = '······';
  $('scanUrl').textContent = 'Making a fresh link…';
  $('scanQr').innerHTML = '';
  try {
    const r = await fetch('../server/pair.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ mint: true, session: SESSION_ID }) });
    const j = await r.json();
    if (!j.token) { $('scanNote').textContent = j.error || 'The link could not be made just now.'; return; }
    $('scanCode').textContent = j.code;
    scanPairUrl = new URL('scan.html?t=' + j.token, location.href).href;
    $('scanUrl').textContent = scanPairUrl;
    try {
      const qr = qrcode(0, 'M'); qr.addData(scanPairUrl); qr.make();
      $('scanQr').innerHTML = qr.createSvgTag({ cellSize: 4, margin: 8, scalable: true });
    } catch (e) { $('scanQr').innerHTML = ''; }
    const enc = encodeURIComponent(scanPairUrl);
    $('scanWa').href = 'https://wa.me/?text=' + enc;
    $('scanSms').href = 'sms:?&body=' + enc;
    $('scanMail').href = 'mailto:?subject=' + encodeURIComponent('Gol POS — scan link') + '&body=' + enc;
    $('scanShare').classList.add('hidden');
    $('scanNote').textContent = 'The link lives for about ' + Math.max(1, Math.round((j.expires_in || 600) / 60))
      + ' minutes. Photos the phone takes land in this register\'s Gol chat.';
  } catch (e) { $('scanNote').textContent = 'The link could not be made just now.'; }
}

// --- the session inbox: paired-phone results land in this chat ---------------------
async function fetchInbox() {
  try {
    const r = await fetch(API + '?inbox&session=' + encodeURIComponent(SESSION_ID));
    const j = await r.json();
    const entries = (j && j.entries) || [];
    if (!entries.length) { return; }
    entries.forEach(e => {
      if (e.reply) { chatMsg(e.reply, 'gol'); }
      if (e.card) { chatCard(e.card); }
    });
    $('chatDrawer').classList.remove('hidden');
    showToast('📷 A phone scan landed in Gol chat.');
  } catch (e) { /* polling never breaks the register */ }
}
setInterval(fetchInbox, 4000);

// --- the price-gap prompt: Gol's small ask at a calm moment ----------------------
// The server owns every rule (who, what, how often, which backoffs);
// the client only reports the till's truth — the cart is empty and the
// last activity is this long ago — and renders the one bubble it gets
// back. Her answer goes through the ordinary chat send, where the
// brain's gap wait is listening.
async function fetchGap() {
  try {
    if (cart.size) { lastBusyAt = Date.now(); return; }
    const idle = Math.floor((Date.now() - lastBusyAt) / 1000);
    if (idle < 60) { return; }
    const r = await fetch(API + '?gap&session=' + encodeURIComponent(SESSION_ID)
      + '&cart=0&idle=' + idle);
    const j = await r.json();
    if (j && j.prompt && j.prompt.reply) {
      chatMsg(j.prompt.reply, 'gol');
      showToast('💲 Gol has one small price question.');
    }
  } catch (e) { /* polling never breaks the register */ }
}
setInterval(fetchGap, 15000);

$('camBtn').addEventListener('click', () => openCam('photo'));
$('voiceBtn').addEventListener('click', () => { $('chatDrawer').classList.remove('hidden'); startVoice(); });
$('chatMicBtn').addEventListener('click', () => startVoice());
$('camCancel').addEventListener('click', closeCam);
$('camSnap').addEventListener('click', camSnapNow);
$('camRetake').addEventListener('click', () => {
  camShot = null;
  $('camPreview').classList.add('hidden');
  $('camVideo').classList.remove('hidden');
  $('camLiveBtns').classList.remove('hidden');
  $('camShotBtns').classList.add('hidden');
});
$('camUsePhoto').addEventListener('click', () => {
  if (!camShot) { return; }
  const f = camShotFile(); closeCam();
  $('chatDrawer').classList.remove('hidden'); sendPhoto(f, '');
});
$('camUseBill').addEventListener('click', () => {
  if (!camShot) { return; }
  const f = camShotFile(); closeCam();
  $('chatDrawer').classList.remove('hidden'); sendPhoto(f, 'bill');
});
$('camUseSense').addEventListener('click', () => {
  if (!camShot) { return; }
  const f = camShotFile(); closeCam(); sendSensePhoto(f);
});
$('frameClose').addEventListener('click', () => $('frameModal').classList.add('hidden'));
$('clipClose').addEventListener('click', () => {
  const v = $('clipVideo'); try { v.pause(); } catch (e) { /* nothing playing */ }
  v.removeAttribute('src'); v.load();
  $('clipModal').classList.add('hidden');
});
$('smScanBtn').addEventListener('click', () => { closeSettingsMenu(); openScanModal(); });
$('scanClose').addEventListener('click', () => $('scanModal').classList.add('hidden'));
$('scanNew').addEventListener('click', openScanModal);
$('scanCopy').addEventListener('click', async () => {
  if (!scanPairUrl) { showToast('The link is still being made — one moment.'); return; }
  try { await navigator.clipboard.writeText(scanPairUrl); showToast('Link copied ✓'); return; } catch (e) { /* fall through */ }
  const ta = document.createElement('textarea');
  ta.value = scanPairUrl; document.body.appendChild(ta); ta.select();
  try { document.execCommand('copy'); showToast('Link copied ✓'); }
  catch (e) { showToast('Copy did not work here — the link is written above.'); }
  ta.remove();
});
$('scanSend').addEventListener('click', async () => {
  if (!scanPairUrl) { showToast('The link is still being made — one moment.'); return; }
  if (navigator.share) {
    try { await navigator.share({ title: 'Gol POS — scan link', url: scanPairUrl }); return; }
    catch (e) { if (e && e.name === 'AbortError') { return; } }
  }
  $('scanShare').classList.toggle('hidden');
});

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

// A linked register hides the Credit tender: the Festival balance
// lives MR.STORE-side as derived points and the register's spend lane
// for linked stores is a named follow-up — a button that cannot spend
// is worse than no button. Refunds stay: their restock rides the same
// MR.STORE bridge a receive uses.
async function applyLinkMode() {
  try {
    const st = await (await fetch(API + '?bind')).json();
    if (st && st.bound) { $('creditBtn').classList.add('hidden'); }
  } catch (e) { /* standalone until proven linked */ }
}
bindLaunch().then(() => { loadCatalog(); loadSettings(); refreshLockState(); applyLinkMode(); clearTillSearch(); setTimeout(clearTillSearch, 600); });
