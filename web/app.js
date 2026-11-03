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
  // The open sale rides every chat turn (brain pack, Nov 2): the brain
  // answers "total" about THIS sale and rings new items into it — it
  // cannot reason over a cart it cannot see.
  try {
    if (typeof cart !== 'undefined' && typeof catalog !== 'undefined' && catalog && catalog.products) {
      const items = [];
      cart.forEach((qty, sku) => {
        const pr = catalog.products.find(x => x.sku === sku);
        if (pr) { items.push({ sku, name: pr.name, qty, price: Number(pr.price) || 0 }); }
      });
      (manualLines || []).forEach(m => items.push({ sku: null, name: 'Manual line', qty: 1, price: Number(m.price) || 0 }));
      if (items.length) {
        p.cart = { items, subtotal: cartTotals.subtotal || 0, tax: cartTotals.tax || 0,
          total: cartTotals.total || 0, count: items.reduce((s, i) => s + (i.qty || 1), 0) };
      }
    }
  } catch (e) { /* the cart simply does not ride this call */ }
  return p;
}
const LOYALTY = '../server/loyalty.php';
const $ = (id) => document.getElementById(id);
const money = (n) => '$' + Number(n).toFixed(2);
const IC_CAM = '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13" r="3.5"/></svg>';
const IC_CHECK = '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12.5 9.5 18 20 6.5"/></svg>';
// --- staged-card death paint (audit GOL-04) ----------------------------------
// A cancelled or stale card must LOOK dead: badge flipped, every
// button disabled, card dimmed. Called from every chat response that
// carries a cancelled/stale marker, and directly by the card's own
// Cancel button.
function markStagedCancelled(pid, el) {
  let targets = [];
  if (el) { targets.push(el); }
  if (pid) { targets = targets.concat(Array.from(document.querySelectorAll('[data-pid="' + pid + '"]'))); }
  if (!targets.length) {
    const live = Array.from(document.querySelectorAll('.msg.card:not(.sc-cancelled)'));
    if (live.length) { targets = [live[live.length - 1]]; }
  }
  targets.forEach((t) => {
    if (!t || t.classList.contains('sc-cancelled')) { return; }
    t.classList.add('sc-cancelled');
    const badge = t.querySelector('.sc-badge');
    if (badge) { badge.textContent = 'CANCELLED'; }
    t.querySelectorAll('button').forEach((b) => { b.disabled = true; });
  });
}
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
  loadWoe();
}
// The Out view (owner, Oct 28): every product whose live stock is 0,
// from the same stockOf() the tiles read — a sale that rings the last
// unit adds the product here, a receive takes it back out, because the
// view is re-derived on every render, never snapshotted.
function outOf(p) { const s = stockOf(p); return s !== null && s <= 0; }
// --- What's Out AI Engine (Oct 29): the Out tab reads the ONE engine list.
// The chip count is the ENGINE's count — the same number Floor, Gol and the
// owner's morning email read. Till stock-zero stays a tile signal; a product
// the engine carries also joins the Out view with a REPORTED OUT tag, even
// if this till has not counted it yet. One truth, every surface.
let woeList = null;
const WOE_BY_NAME = { 'Sunrise Mart (demo)': '22af5007-3fa8-4804-ac6a-5cf19e64c697', 'Sunrise Mart': '22af5007-3fa8-4804-ac6a-5cf19e64c697' };
function woeSquash(s) { return String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, ''); }
function woeHit(p) {
  if (!woeList || !woeList.entries) return false;
  const sq = woeSquash(p.name);
  return woeList.entries.some(e =>
    (e.upc && p.barcode && e.upc === p.barcode) ||
    (sq.length >= 7 && woeSquash(e.name).length >= 7 && (woeSquash(e.name).includes(sq) || sq.includes(woeSquash(e.name)))));
}
async function loadWoe() {
  try {
    const sid = (catalog.store && catalog.store.store_id) || WOE_BY_NAME[catalog.store && catalog.store.name] || '';
    if (!sid) return;
    const res = await fetch('https://luckymart.biz/store-api/whatsout-engine.php?store=' + encodeURIComponent(sid), { cache: 'no-store' });
    const j = await res.json();
    if (j && j.ok) { woeList = j; renderCats(); if (activeCat === 'Out') renderGrid(); }
  } catch (e) { /* engine unreachable — the tab keeps its stock-only view */ }
}
function outCount() {
  if (woeList && typeof woeList.count === 'number') return woeList.count;
  return catalog.products.filter(outOf).length;
}
// Category tabs are the store's REAL categories now (Oct 30): the
// MR.STORE projection carries each product's own category (Grocery,
// Alcohol, Vapes, Tobacco, Drinks, Candy, Snacks…), so the tabs are
// built from what is actually on this shelf — owner order first, any
// other category after, each tab carrying its product count. A product
// with no category gets NO tab; it lives under All only.
const CAT_ORDER = ['Grocery', 'Alcohol', 'Tobacco', 'Vapes', 'Drinks', 'Snacks', 'Candy'];
function shelfCats() {
  const counts = {};
  catalog.products.forEach(p => { if (p.category) { counts[p.category] = (counts[p.category] || 0) + 1; } });
  const names = Object.keys(counts).sort((a, b) => {
    const ia = CAT_ORDER.indexOf(a), ib = CAT_ORDER.indexOf(b);
    if (ia !== -1 || ib !== -1) { return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib); }
    return a.localeCompare(b);
  });
  return names.map(c => ({ name: c, count: counts[c] }));
}
function renderCats() {
  const cats = shelfCats();
  const oc = outCount();
  $('catRow').innerHTML =
    `<button type="button" class="${'All' === activeCat ? 'on' : ''}" data-cat="All">All</button>` +
    cats.map(c =>
    `<button type="button" class="${c.name === activeCat ? 'on' : ''}" data-cat="${esc(c.name)}">${esc(c.name)}<span class="cat-count">${c.count}</span></button>`).join('') +
    `<button type="button" class="out-chip${activeCat === 'Out' ? ' on' : ''}" data-cat="Out">Out${oc ? `<span class="out-count">${oc}</span>` : ''}</button>`;
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
  // Search hears the shelf the way customers say it: name, barcode, and
  // the product's own catalog aliases — the same `aliases` field the Gol
  // chat brain resolves (fix POS-D2, Oct 30: "coke" / "कोक" / "redbull"
  // found nothing in this box while the brain answered fine).
  const aliasHit = (p) => Array.isArray(p.aliases) &&
    p.aliases.some(a => String(a).toLowerCase().includes(q));
  const items = catalog.products.filter(p =>
    (activeCat === 'All' || (activeCat === 'Out' ? (outOf(p) || woeHit(p)) : p.category === activeCat)) &&
    (!q || p.name.toLowerCase().includes(q) || p.barcode.includes(q) || aliasHit(p)));
  // Shelf honesty order (owner catch, Oct 30 — "all out?"): on the Lucky
  // catalog order the register's first screen was a wall of OUT tiles,
  // because most SKUs carry a recorded 0 from import and only ~1 in 5 has
  // a shelf count. The store is not empty; the shelf just led with the
  // dead stock. Order every shelf except the Out view: in-stock first,
  // then untracked (never counted — no OUT badge, rings normally), then
  // recorded zeros last. The sort is stable, so catalog order survives
  // inside each group; search still narrows first and this only orders
  // what the search already matched. The Out view keeps its own
  // derivation (engine list + stock zeros) untouched.
  if (activeCat !== 'Out') {
    const shelfRank = (p) => { const s = stockOf(p); return s === null ? 1 : (s > 0 ? 0 : 2); };
    items.sort((a, b) => shelfRank(a) - shelfRank(b));
  }
  $('grid').innerHTML = items.map(p => {
    const s = stockOf(p);
    const out = s !== null && s <= 0;
    return `<div class="tile${out ? ' is-out' : ''}" data-sku="${p.sku}" role="button" tabindex="0" aria-label="${esc(p.name)} — tap to ring">
       <span class="tile-media"><span class="tile-art ${artClass(p)}">${(p.name || 'G').trim().charAt(0).toUpperCase()}</span>${p.img ? `<img class="tile-img" src="${esc(p.img)}" alt="" loading="lazy" onerror="this.remove()">` : ''}${out ? '<span class="tile-out-tag">OUT</span>' : (woeHit(p) ? '<span class="tile-out-tag" style="background:#b45309">REPORTED OUT</span>' : '')}</span>
       <span class="tile-name">${p.name}</span>${stockHtml(p)}<span class="tile-foot"><span class="tile-price">${money(p.price)}</span><button type="button" class="tile-stock-add${out ? ' rescue' : ''}" data-stock-sku="${p.sku}" aria-label="Add stock to ${esc(p.name)}">＋ Stock</button></span>
     </div>`;
  }).join('') || `<p class="empty">${activeCat === 'Out' && !q ? 'Nothing is out right now.' : 'No products match.'}</p>`;
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
  const cats = [{ name: 'All', count: 0 }, ...shelfCats()];
  $('kpCatRow').innerHTML = cats.map(c =>
    `<button type="button" class="${c.name === kpCat ? 'on' : ''}" data-kcat="${esc(c.name)}">${esc(c.name)}${c.count ? `<span class="cat-count">${c.count}</span>` : ''}</button>`).join('');
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
  // Clear lives in the cart header now (tender declutter, Oct 28) and
  // exists only when there is something to clear.
  const clearEl = $('clearBtn');
  if (clearEl) { clearEl.classList.toggle('hidden', count === 0); }
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
  // Replies are authored with real line breaks (lists, reports, sale
  // lines); with the default white-space the browser collapses them
  // into one wrapped paragraph — the wall of text the owner hit on
  // the open-tasks answer (Nov 2, 20:40). Honour the authored breaks
  // in the circle and the rectangle panel alike.
  d.style.whiteSpace = 'pre-wrap';
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
  } else if (card.type === 'pin_set') {
    body = `<div class="sc-what">PIN — ${esc(card.name || '')}</div>` +
      `<div class="sc-line">A new PIN the owner hands out in person. The digits are never shown here — confirm lands it and the old PIN stops working.</div>`;
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
  } else if (card.type === 'gol_stage') {
    // A card staged by the store's REAL Gol brain (routed here by the
    // brain.php bridge): its title + its own lines — the same card the
    // MR.STORE chat shows for the same turn. The Confirm button below
    // posts {confirm:true}, which the bridge carries to the big brain's
    // confirm — one staged-card law on every surface.
    const gl = (card.lines || []).map(l => `<div class="sc-line">${esc(l)}</div>`).join('');
    body = `<div class="sc-what">${esc(card.title || 'Staged — Gol')}</div>${gl}`;
  } else if (card.type === 'photo_read') {
    const found = card.regions === null || card.regions === undefined
      ? 'SAM gave no read on this photo'
      : `SAM found ${card.regions} region(s) for "${card.prompt}"`;
    const matchLine = card.match_sku ? `<div class="sc-line">Matches: ${card.match_name} (${money(card.match_price)}) → cart on "gol"</div>` : '';
    body = `<img src="${card.image}" alt="Your photo"><div class="sc-line">${found}</div>${matchLine}`;
  } else {
    body = `<div class="sc-what">New item — ${card.name} · ${money(card.price)}</div>`;
  }
  const cardPid = card.pid || card.id || '';
  if (cardPid) { d.dataset.pid = cardPid; }
  d.innerHTML = `<span class="sc-badge">STAGED</span>${body}<div class="sc-btns"><button type="button" class="sc-confirm">${IC_CHECK} Confirm — Gol</button><button type="button" class="sc-cancel">Cancel</button></div>`;
  const confirmBtn = d.querySelector('.sc-confirm');
  confirmBtn.addEventListener('click', async () => {
    const res = await fetch(BRAIN, { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(brainPayload({ confirm: true, pending_pid: cardPid })) });
    const r = await res.json();
    chatMsg(r.reply, 'gol');
    if (r.stale) { markStagedCancelled(cardPid, d); }
    try { golSpeak(posSayFor(r, '', null)); } catch (e) {}
    applyLockState(r.locked, r.actor);
    notePinState(r);
    handleChatAction(r);
    loadCatalog(); loadSettings();
  });
  const cancelBtn = d.querySelector('.sc-cancel');
  if (cancelBtn) {
    cancelBtn.addEventListener('click', async () => {
      cancelBtn.disabled = true; confirmBtn.disabled = true;
      try {
        const res2 = await fetch(BRAIN, { method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(brainPayload({ cancel_card: true, pending_pid: cardPid })) });
        const r2 = await res2.json();
        if (r2.reply) { chatMsg(r2.reply, 'gol'); }
        if (r2.cancelled_pid) { markStagedCancelled(r2.cancelled_pid); }
        else if (r2.cancelled) { markStagedCancelled(cardPid, d); }
        applyLockState(r2.locked, r2.actor);
        notePinState(r2);
        handleChatAction(r2);
        if (r2.done) { loadCatalog(); loadSettings(); }
      } catch (e) {
        cancelBtn.disabled = false; confirmBtn.disabled = false;
        chatMsg('Could not reach the brain to cancel — the card is still staged; try again.', 'gol');
      }
    });
  }
  $('chatBody').appendChild(d); $('chatBody').scrollTop = 1e6;
  return d;
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
      s += `<rect class="gbar-h" style="animation-delay:${i * 70}ms" x="${nameW}" y="${y + 2}" width="${w.toFixed(1)}" height="13" rx="3" fill="#2563eb"></rect>`;
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
    s += `<polygon class="gsparkfill" points="${padX},${base} ${pts} ${W - padX},${base}" fill="#2563eb" opacity="0.12"></polygon>`;
    s += `<polyline class="gspark" pathLength="1" points="${pts}" fill="none" stroke="#2563eb" stroke-width="2.2" stroke-linejoin="round" stroke-linecap="round"></polyline>`;
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
    s += `<rect class="gbar${v < 0 ? ' gbar-neg' : ''}" style="animation-delay:${i * 70}ms" x="${x.toFixed(1)}" y="${y.toFixed(1)}" width="${barW.toFixed(1)}" height="${h.toFixed(1)}" rx="3" fill="${fill}"></rect>`;
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
  d.dataset.calStore = g.cal_store || '';
  let inner = `<div class="graph-head"><span class="graph-title">${esc(g.title || '')}</span><span class="graph-total">${money(g.total)}</span></div>`;
  if (g.empty) {
    inner += `<div class="graph-empty">${esc(g.empty_text || 'No sales in this range yet.')}</div>`;
  } else {
    inner += graphSvg(g);
  }
  // Cash card (owner, Oct 28): the period's tender split, summed by the
  // brain from the same journal reads as the bars. No split recorded ->
  // the note says so plainly; a zero-sales period draws no cash row.
  if (g.cash) {
    const c = g.cash;
    if (!c.split && c.txns > 0) {
      inner += `<p class="cash-note">${esc(c.note || 'Tender split not recorded for these sales.')}</p>`;
    } else if (c.txns > 0) {
      inner += `<div class="cash-row"><span><b>${esc(c.cash_lbl || 'Cash')}</b> ${money(c.cash)}</span>` +
        `<span><b>${esc(c.card_lbl || 'Card')}</b> ${money(c.card)}</span>` +
        (c.credit ? `<span><b>${esc(c.credit_lbl || 'Credit')}</b> ${money(c.credit)}</span>` : '') + `</div>`;
    }
    // Tender reconciliation line (audit GOL-07): composed server-side
    // from the same closes as the bars — expected cash tying to the
    // total, counted cash and recorded over/short beside it.
    if (c.recon && c.txns > 0) {
      inner += `<p class="cash-note">${esc(c.recon)}</p>`;
    }
  }
  // Calendar card (owner, Oct 28): the register's own month. Days with
  // sales are buttons — a tap silently peeks that day's compact card
  // in place (dayDetailTap below; never a chat turn, Oct 31 fix);
  // days without sales are plain text, and today is ringed.
  if (g.calendar && Array.isArray(g.calendar.days)) {
    const cal = g.calendar;
    const dows = (cal.dows || []).map(x => `<span class="cal-dow">${esc(x)}</span>`).join('');
    const blanks = '<span class="cal-blank"></span>'.repeat(cal.lead || 0);
    const cells = cal.days.map(x => x.has
      ? `<button type="button" class="cal-day has${x.today ? ' today' : ''}" data-ymd="${x.ymd}" title="${esc(x.ymd)} — ${money(x.value)}">${x.d}</button>`
      : `<span class="cal-day${x.today ? ' today' : ''}">${x.d}</span>`).join('');
    inner += `<div class="cal-card"><div class="cal-head">${esc(cal.month)}</div><div class="cal-grid">${dows}${blanks}${cells}</div>${cal.hint ? `<div class="cal-hint">${esc(cal.hint)}</div>` : ''}</div>`;
  }
  if (g.focus) {
    inner += `<div class="graph-foot">${esc(g.focus.label)} — <b>${money(g.focus.value)}</b></div>`;
  }
  d.innerHTML = inner;
  const gtEl = d.querySelector('.graph-total');
  if (gtEl && Number(g.total) > 0) {
    const target = Number(g.total); const t0 = performance.now();
    const countStep = (t) => { const k = Math.min(1, (t - t0) / 900); gtEl.textContent = money(target * (1 - Math.pow(1 - k, 3))); if (k < 1) { requestAnimationFrame(countStep); } };
    requestAnimationFrame(countStep);
  }
  d.querySelectorAll('.cal-day.has').forEach(b => b.addEventListener('click', () => {
    dayDetailTap(b, d);
  }));
  $('chatBody').appendChild(d); $('chatBody').scrollTop = 1e6;
}

// --- Who is on this register: a designed info card, never a staged card -----
// The brain's who payload (live session record at answer time) renders
// as its own card — no STAGED badge, no Confirm button: nothing is
// staged by a question. Since-times arrive as ISO and render in the
// register's own local time; a null since is stated honestly (the
// session record carries no unlock timestamp and sheet-gate unlocks
// are not journaled).
// --- Designed display cards (Lane 4, Oct 31) --------------------------------
// Read answers (price / overview / stock / vendor profile) arrive as a
// 'dcard' payload — the same structured envelope the backend Gol chat
// renders — and paint as designed cards under the reply text. Display
// only: no STAGED badge, no Confirm button; staged cards keep chatCard.
function chatDCard(c) {
  if (!c || !c.type) { return; }
  const d = document.createElement('div');
  d.className = 'msg gol graph-card dcard';
  const row = (k, v) => `<div class="cash-row" style="justify-content:space-between"><span>${esc(k)}</span><span><b>${esc(v)}</b></span></div>`;
  const m = (v) => v === null || v === undefined ? 'not set' : money(v);
  let inner = '';
  if (c.type === 'price') {
    const st = c.stock_state || (c.onHand === null || c.onHand === undefined ? '' : (Number(c.onHand) <= 0 ? 'Out of stock' : (Number(c.onHand) <= 2 ? 'Low · ' + c.onHand + ' left' : 'In stock · ' + c.onHand)));
    inner = `<div class="graph-head"><span class="graph-title">${esc(c.name || 'Price')}</span><span class="graph-total">${m(c.price)}</span></div>`
      + (st || c.store ? `<div class="cash-row"><span>${esc(st)}</span>${c.store ? `<span>${esc(c.store)}</span>` : ''}</div>` : '');
  } else if (c.type === 'price_list') {
    inner = `<div class="graph-head"><span class="graph-title">Prices${c.store ? ' — ' + esc(c.store) : ''}</span></div>`
      + (c.items || []).map(it => row(it.name + (it.stock_state ? ' · ' + it.stock_state : ''), m(it.price))).join('')
      + (c.more ? `<p class="cash-note">+ ${esc(String(c.more))} more</p>` : '');
  } else if (c.type === 'overview') {
    inner = `<div class="graph-head"><span class="graph-title">${esc(c.label || 'Overview')}</span><span class="graph-total">${money(c.profit)}</span></div>`
      + row('Sales', money(c.sales)) + row('Purchases', money(c.purchases)) + row('Expenses', money(c.expenses))
      + row('Pay (inside Expenses)', money(c.pay))
      + (c.tax === null || c.tax === undefined ? row('Tax', '—') : row('Tax', money(c.tax)))
      + (c.tax_note ? `<p class="cash-note">${esc(c.tax_note)}</p>` : '')
      + row('Profit', money(c.profit))
      + ((c.exp_top && (c.exp_top.lines || []).length) ? c.exp_top.lines.map(ln => row(ln.date + ' · ' + ln.label, money(ln.amount))).join('') : '');
  } else if (c.type === 'stock_list') {
    inner = `<div class="graph-head"><span class="graph-title">${esc(c.title || 'Stock')}</span></div>`
      + (c.items || []).map(it => row(it.name, (it.qty === null || it.qty === undefined) ? (it.stock_state || 'not tracked') : (String(it.qty) + (it.stock_state ? ' · ' + it.stock_state : '')))).join('')
      + (c.more ? `<p class="cash-note">+ ${esc(String(c.more))} more</p>` : '');
  } else if (c.type === 'vendor_profile') {
    inner = `<div class="graph-head"><span class="graph-title">${esc(c.vendor || 'Vendor')}</span><span class="graph-total">${c.median_visit !== null && c.median_visit !== undefined ? money(c.median_visit) : ''}</span></div>`
      + (c.kind ? row('Vendor', c.kind) : '') + row('Learned from', String(c.bills || 0) + ' bills')
      + (c.last_bill ? row('Last bill', c.last_bill + (c.next_expected ? ' · next ' + c.next_expected : '')) : '')
      + (c.total_learned !== null && c.total_learned !== undefined ? row('Total learned', money(c.total_learned)) : '')
      + (c.status_line ? `<p class="cash-note">${esc(c.status_line)}</p>` : '');
  } else { return; }
  d.innerHTML = inner;
  $('chatBody').appendChild(d); $('chatBody').scrollTop = 1e6;
}
function chatWho(list) {
  const d = document.createElement('div');
  d.className = 'msg gol who-card';
  const rows = (list || []).map(w => {
    const since = w.since ? new Date(w.since).toLocaleString() : 'time not recorded';
    return `<div class="who-line"><b>${esc(w.name || '')}</b> · ${esc(w.role || '')}${w.you ? ' · this session' : ''}<span class="who-since">since ${esc(since)}</span></div>`;
  }).join('');
  d.innerHTML = `<div class="who-title">On this register now</div>${rows || '<div class="who-line">Nobody is logged in.</div>'}`;
  $('chatBody').appendChild(d); $('chatBody').scrollTop = 1e6;
}

// --- Calendar day tap: a silent peek, never a chat turn ------------------------
// Owner catch, Oct 31: a tap used to send "YYYY-MM-DD report" through
// sendChat, so every tap added a 'me' bubble AND a whole new report
// stack. Now the tap fetches ONE compact day payload from the brain's
// day_detail action and swaps a single day card in place under the
// report it belongs to — no bubble, no new stack, nothing journaled.
async function dayDetailTap(btn, graphEl) {
  const ymd = btn.dataset.ymd || '';
  if (!ymd) { return; }
  graphEl.querySelectorAll('.cal-day.has').forEach((b) => b.classList.toggle('sel', b === btn));
  try {
    const res = await fetch(BRAIN, { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(brainPayload({ action: 'day_detail', ymd, store_id: graphEl.dataset.calStore || '' })) });
    const r = await res.json();
    if (r && r.day_detail) { renderDayDetail(r.day_detail, graphEl); }
  } catch (e) { /* a peek that cannot load leaves the report untouched */ }
}
function renderDayDetail(dd, graphEl) {
  let el = document.getElementById('dayDetailCard');
  if (!el) {
    el = document.createElement('div');
    el.id = 'dayDetailCard';
    el.className = 'msg gol day-card';
  }
  let meta = '';
  if (dd.tax !== null && dd.tax !== undefined) { meta += `<span>Tax <b>${money(dd.tax)}</b></span>`; }
  if (dd.result) { meta += `<span>Result <b>${esc(dd.result)}</b></span>`; }
  if (dd.counted !== null && dd.counted !== undefined && Math.abs(dd.counted - dd.cash) >= 0.01) {
    meta += `<span>Counted cash <b>${money(dd.counted)}</b></span>`;
  }
  if (dd.cash_derived) { meta += `<span>Cash split was not recorded that night — cash shown is gross minus card.</span>`; }
  el.innerHTML =
    `<div class="graph-head"><span class="graph-title">${esc(dd.label)}${dd.store ? ' — ' + esc(dd.store) : ''}</span><span class="graph-total">${money(dd.gross)}</span></div>` +
    `<div class="cash-row"><span>💵 Cash <b>${money(dd.cash)}</b></span><span>💳 Card <b>${money(dd.card)}</b></span></div>` +
    (meta ? `<div class="day-meta">${meta}</div>` : '');
  graphEl.insertAdjacentElement('afterend', el);
  el.scrollIntoView({ block: 'nearest' });
}

// --- Gol's mouth: ONE natural server voice (Oct 29) -----------------------------
// The ears live further down (press-to-talk mic -> voiceFinal -> sendChat).
// The mouth is the SAME natural server voice every Gol surface speaks with:
// store-api/gol-say.php renders a SHORT say payload to a cached MP3 (voice
// "nova", gpt-4o-mini-tts). Say-payload discipline, same as the desk chat:
// a staged card speaks its visible summary (the card's own headline +
// prices, read off the rendered card so voice and screen can never drift)
// plus the "gol" confirm line; a plain reply speaks its first line only.
// NO SpeechSynthesis on this surface — if the voice service fails, the
// turn stays text-only. Silence beats robot. The speaker toggle in the
// chat composer (localStorage golpos_speech, default on) governs; turning
// it off stops any playback already running.
function golDetectLang(s) {
  s = String(s || '');
  if (/[\u0900-\u097F]/.test(s)) {
    if (/(है|हैं|नहीं|क्या|चाहिए|क्यों|रुपये|लिए)/.test(s)) return 'hi';
    return 'ne';
  }
  var w = ' ' + s.toLowerCase().replace(/[^a-z\s]/g, ' ') + ' ';
  if (/ (hai|karo|karna|chahiye|kitna|kitne|rupaye|kya|nahi|mera|mujhe) /.test(w)) return 'hi';
  if (/ (chha|cha|gara|garna|bhannus|bhanne|kati|rupiya|paisa|bhayo|aayo|gayo|dine|huncha|chaina) /.test(w)) return 'ne';
  return 'en';
}
function golCleanSay(s) {
  return String(s || '')
    .replace(/[\u{1F000}-\u{1FAFF}\u{2600}-\u{27BF}\u{2190}-\u{21FF}\u{2B00}-\u{2BFF}\u{FE0F}]/gu, '')
    .replace(/[*_`#>|]/g, '')
    .replace(/\bgol\b/ig, 'confirm')
    .replace(/गोल/g, 'confirm')
    .replace(/\s+/g, ' ')
    .trim();
}
let golSpokeGolAt = 0;
function golNoteSpoken(text) {
  if (/\bgol\b|गोल/i.test(String(text || ''))) golSpokeGolAt = Date.now();
}
function golIsEchoConfirm(text) {
  var t = String(text || '').trim().toLowerCase();
  if (t !== 'gol' && t !== 'गोल') return false;
  return golSpokeGolAt > 0 && (Date.now() - golSpokeGolAt) < 8000;
}
let golLastSpoken = '';
function golIsSelfEcho(text) {
  var h = String(text || '').toLowerCase().replace(/[^a-z0-9\u0900-\u097f ]/g, '').replace(/\s+/g, ' ').trim();
  var s = String(golLastSpoken || '').toLowerCase().replace(/[^a-z0-9\u0900-\u097f ]/g, '').replace(/\s+/g, ' ').trim();
  if (!h || !s) return false;
  if (s.indexOf(h) >= 0 || h.indexOf(s) >= 0) return true;
  var hw = h.split(' ').filter(Boolean);
  var sw = {};
  s.split(' ').forEach(function (w) { sw[w] = 1; });
  var hit = hw.filter(function (w) { return sw[w]; }).length;
  return hw.length > 0 && hit / hw.length >= 0.75;
}
function golConfirmLine(lang) {
  // The mouth must not say the confirm word. The call ear sends whatever it
  // hears, and a heard "gol" is an execute. The card on screen still says Confirm.
  return lang === 'ne' ? 'कार्ड स्क्रिनमा छ। म चुप लागेपछि Confirm थिच्नुहोस्।'
    : (lang === 'hi' ? 'कार्ड स्क्रीन पर है। मैं चुप होने के बाद Confirm दबाइए।'
    : 'The card is on screen. Tap Confirm after I stop talking.');
}
/* Mirror language: the first text (Gol's own reply first — it mirrors
   the conversation) that reads as Nepali/Hindi wins; else English. */
function golPickLang() {
  for (var i = 0; i < arguments.length; i++) {
    var l = golDetectLang(arguments[i] || '');
    if (l !== 'en') return l;
  }
  return 'en';
}
function golVoiceOn() {
  try { return localStorage.getItem('golpos_speech') !== '0'; } catch (e) { return true; }
}
function golSetVoiceOn(on) {
  try { localStorage.setItem('golpos_speech', on ? '1' : '0'); } catch (e) {}
  if (!on) golStopVoice();
  syncVoiceBtn();
}
function syncVoiceBtn() {
  var b = null;
  try { b = $('chatVoiceBtn'); } catch (e) {}
  if (!b) return;
  var on = golVoiceOn();
  b.style.opacity = on ? '1' : '0.45';
  b.title = on ? 'Gol voice on — tap to mute' : 'Gol voice off — tap to unmute';
  b.setAttribute('aria-pressed', on ? 'true' : 'false');
}
var golAudioEl = null;
var golSayGen = 0; /* speech generation: stopping the mouth (barge-in,
   mute, end call) bumps it, and a gol-say fetch that resolves afterwards
   must NOT start playing — the mouth stays stopped until a NEW line is
   spoken. Without this, a barge-in could be undone a beat later by the
   very fetch it interrupted. */
function golStopVoice() {
  golSayGen++;
  if (golAudioEl) { try { golAudioEl.pause(); } catch (e) {} golAudioEl = null; }
}
var golSayInflight = 0;
function golSpeak(say) {
  if (!say || !say.text || !golVoiceOn()) return;
  var text = String(say.text).slice(0, 400);
  golLastSpoken = text;
  golNoteSpoken(text);
  var lang = say.lang || 'en';
  golSayInflight++;
  var myGen = golSayGen;
  var saySettled = false;
  var sayDone = function () { if (!saySettled) { saySettled = true; golSayInflight--; } };
  try {
    fetch('/store-api/gol-say.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ text: text, lang: lang })
    }).then(function (r) {
      if (!r.ok) throw new Error('say');
      return r.blob();
    }).then(function (b) {
      sayDone();
      if (myGen !== golSayGen) return; /* the mouth was stopped while this line rendered — stay stopped */
      if (!golVoiceOn() || !b || !b.size) return;
      golStopVoice();
      var url = URL.createObjectURL(b);
      golAudioEl = new Audio(url);
      var p = golAudioEl.play();
      if (p && p.catch) p.catch(function () { if (myGen === golSayGen) { golSayPendingUrl = url; } });
    }).catch(function () { sayDone(); });
  } catch (e) { sayDone(); }
}
/* If a play() is ever rejected (autoplay policy), the line is queued and
   spoken on the very next tap instead of vanishing silently. */
var golSayPendingUrl = null;
document.addEventListener('pointerdown', function () {
  if (golSayPendingUrl && golVoiceOn()) {
    var u = golSayPendingUrl; golSayPendingUrl = null;
    try { golAudioEl = new Audio(u); var pr = golAudioEl.play(); if (pr && pr.catch) pr.catch(function () {}); } catch (e) {}
  }
}, true);
function leadOfSay(text) {
  var t = String(text || '');
  var i = t.indexOf('\n');
  return i > 0 ? t.slice(0, i) : t;
}
/* The staged card's spoken summary, read off the RENDERED card: its
   headline (.sc-what) plus its price line (.sc-prices) when it has one —
   exactly what the cashier sees, never a second, drifting copy. */
function posCardSayText(cardEl) {
  if (!cardEl) return '';
  var what = cardEl.querySelector('.sc-what');
  var prices = cardEl.querySelector('.sc-prices');
  var t;
  if (what) { t = what.textContent + (prices ? ' ' + prices.textContent : ''); }
  else { t = cardEl.textContent || ''; }
  t = String(t || '').replace(/→/g, ' to ').replace(/\s+/g, ' ').trim();
  t = t.replace(/^STAGED\s*/, '').replace(/\s*Confirm — Gol\s*$/, '');
  if (t.length > 220) t = t.slice(0, 220);
  return t;
}
/* Spoken discipline for LONG replies (Oct 29 real-device fix): a
   list/report answer is never read out line by line — the mouth gets
   header + total + top line + an on-screen pointer. */
function posShortSay(t, lang) {
  var raw = String(t || '');
  var lines = raw.split('\n');
  var kept = [];
  for (var i = 0; i < lines.length; i++) { if (lines[i].trim()) kept.push(lines[i].trim()); }
  var cleanAll = golCleanSay(raw);
  var isList = kept.length >= 5;
  if (!isList && cleanAll.length <= 320) {
    var lead = leadOfSay(raw);
    if (lead.length > 200) lead = lead.slice(0, 200);
    return golCleanSay(lead) || null;
  }
  var head = golCleanSay(kept[0] || '');
  if (head.length > 90) head = head.slice(0, 90);
  var parts = [];
  if (head) parts.push(head.replace(/[:.]$/, '') + '.');
  var total = '';
  for (var a = 0; a < kept.length; a++) {
    var tm = kept[a].match(/total[^$]*(\$[\d,]+(?:\.\d{2})?)/i);
    if (tm) { total = tm[1]; break; }
  }
  if (total) parts.push('Total ' + total + '.');
  for (var b = 0; b < kept.length; b++) {
    if (/^[\u2022\-*]/.test(kept[b])) {
      var bl = golCleanSay(kept[b].replace(/^[\u2022\-*]\s*/, ''));
      var bm = bl.match(/^(.*?)[\s\u2014-]*(\$[\d,]+(?:\.\d{2})?)/);
      var top = bm ? (bm[1].replace(/[\s\u2014-]+$/, '') + ', ' + bm[2]) : bl.slice(0, 60);
      if (top) parts.push('Top: ' + top + '.');
      break;
    }
  }
  parts.push(lang === 'ne' ? 'Pura suchi screen ma chha.' : (lang === 'hi' ? 'Poori list screen par hai.' : 'Full list is on screen.'));
  var out = parts.join(' ');
  return out.length > 260 ? out.slice(0, 260) : out;
}
/* Build the SHORT say payload for one fresh server response. */
function posSayFor(r, sentText, cardEl) {
  if (!r) return null;
  // PIN waits are SILENT (owner lock, TASKS 229): while the server is
  // waiting for a PIN (r.pin_ask — register locked or a gate armed),
  // Gol never speaks. The pad asks with its dots, not with a voice.
  if (r.pin_ask) return null;
  if (r.card) {
    var sum = posCardSayText(cardEl);
    if (sum) {
      var langS = golPickLang(r.reply, sentText, sum);
      var cleanS = golCleanSay(sum);
      if (cleanS) return { text: cleanS + '. ' + golConfirmLine(langS), lang: langS };
    }
  }
  var t = r.reply || '';
  if (!t) return null;
  var langP = golPickLang(t, sentText);
  var short = posShortSay(t, langP);
  if (!short) return null;
  return { text: short, lang: langP };
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
  let photoCardEl = null;
  if (r.card) { photoCardEl = chatCard(r.card); }
  try { golSpeak(posSayFor(r, promptText, photoCardEl)); } catch (e) {}
}

async function sendChat(text, callSeq) {
  noteChatLang(text); // the voice door follows the language of the chat
  // A counter-sensing ask is a camera errand, not a brain question: the
  // frame goes to the vision lane and its answer comes back as Gol's
  // reply — the message never reaches the brain's catalog lanes.
  if (isSenseAsk(text)) { await senseFlow(text); return; }
  const mine = chatMsg(text, 'me');
  const res = await fetch(BRAIN, { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(brainPayload({ message: text })) });
  const r = await res.json();
  /* Call turn crossing (Nov 2): a newer Call turn was delivered while
     this one was in flight (or the call has ended) — this reply
     belongs to a superseded transcript and is dropped WHOLE: no
     bubble, no card, no cart action, no speech. The user's own bubble
     above stays — it is the transcript of what was actually said. */
  if (typeof callSeq === 'number' && (callSeq < callTurnSeq || !callActive)) { return; }
  // The server consumed that message as a PIN entry — never leave the
  // digits standing in the chat history.
  if (r.pin_mask && mine) { mine.textContent = '••••'; }
  if (r.reply) { chatMsg(r.reply, 'gol'); }
  if (r.cancelled_pid) { markStagedCancelled(r.cancelled_pid); }
  else if (r.cancelled) { markStagedCancelled(''); }
  if (r.graph) { chatGraph(r.graph); }
  if (r.who) { chatWho(r.who); }
  if (r.dcard) { chatDCard(r.dcard); }
  applyLockState(r.locked, r.actor);
  notePinState(r);
  if (r.unlock_event && r.unlock_event.id) { attachUnlockFrame(r.unlock_event.id); }
  let cardEl = null;
  if (r.card) { cardEl = chatCard(r.card); }
  try { golSpeak(posSayFor(r, text, cardEl)); } catch (e) {}
  handleChatAction(r);
  if (r.done) {
    document.querySelectorAll('#chatBody .msg.card:not(.sc-cancelled)').forEach(function (t) {
      var badge = t.querySelector('.sc-badge');
      if (badge) badge.textContent = 'ENTERED';
      t.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
    });
    loadCatalog(); loadSettings();
  }
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
    const rec = await (await fetch(API + '?order_sheet=' + encodeURIComponent(id) + '&session=' + encodeURIComponent(SESSION_ID))).json();
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
// Opening the chat must be something the owner can SEE (Oct 30 catch:
// the lock modal's "Talk to Gol" looked dead because the panel was
// already open and nothing moved). Open the drawer, bring the latest
// line into view, and land the cursor — in the PIN pad when the
// register is locked, else in the composer with a visible flash.
function openChatToGol() {
  $('chatDrawer').classList.remove('chat-vanish');
  $('chatDrawer').classList.remove('hidden');
  const cb = $('chatBody'); if (cb) { cb.scrollTop = 1e9; }
  const pad = $('pinPad');
  if (pad && !pad.classList.contains('hidden')) { focusPinPad(); return; }
  const inp = $('chatInput');
  try { inp.focus({ preventScroll: true }); } catch (e) { inp.focus(); }
  inp.classList.remove('composer-flash'); void inp.offsetWidth; inp.classList.add('composer-flash');
}
$('golBtn').addEventListener('click', openChatToGol);
$('chatClose').addEventListener('click', () => { if (window.__golFoldPanel) { window.__golFoldPanel(); } else { if (!(typeof callActive !== 'undefined' && callActive)) { try { golStopVoice(); } catch (e) {} golSayPendingUrl = null; } $('chatDrawer').classList.add('hidden'); } loadCatalog(); });
// --- quick-ask chips: one tap sends the everyday asks — no typing at the till.
document.querySelectorAll('[data-chip]').forEach(b =>
  b.addEventListener('click', () => sendChat(b.dataset.chip)));
$('chatForm').addEventListener('submit', (e) => {
  e.preventDefault();
  const v = $('chatInput').value.trim();
  if (v) { $('chatInput').value = ''; sendChat(v); }
});
// Enter sends (owner, Nov 2): the composer owns its Enter instead of
// leaning on the form's implicit submission, which the skinned
// panel/circle forms do not fire reliably. Same send path as the button
// (the submit handler above): preventDefault stops the implicit submit,
// and if a submit still slips through it reads the already-cleared box
// and no-ops — one send, always. An IME composition (Nepali input) is
// never a send: Enter mid-word just picks the composed word.
$('chatInput').addEventListener('keydown', (e) => {
  if (e.key !== 'Enter' || e.shiftKey) { return; }
  if (e.isComposing || e.keyCode === 229) { return; }
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
$('lockChatBtn').addEventListener('click', openChatToGol);
// ---- The chat's PIN state (Oct 30) ----------------------------------
// When the register is locked — or a gated ask demands the admin PIN —
// Gol ASKS in the chat: a masked pad inside the drawer. Digits live
// only in the pad: never in the message box, never as a chat bubble,
// never in the transcript or history. The brain drives the state via
// r.pin_ask on every reply (true exactly while it waits for a PIN);
// typed composer digits still work and stay masked by the brain's
// pin_mask, but the pad is the front door. One PIN story: the pad
// submits to the same brain.php turn the chat uses, so the chat-made
// admin PIN is the register unlock.
let pinDigits = '';
function renderPinDots() {
  const el = $('pinPadDots'); if (el) { el.textContent = '•'.repeat(pinDigits.length); }
  const go = $('pinPadGo'); if (go) { go.disabled = pinDigits.length < 4; }
}
function focusPinPad() { const p = $('pinPad'); if (p) { p.tabIndex = -1; try { p.focus({ preventScroll: true }); } catch (e) { p.focus(); } } }
function openPinPad() {
  const p = $('pinPad'); if (!p) { return; }
  const wasHidden = p.classList.contains('hidden');
  p.classList.remove('hidden'); renderPinDots();
  // PIN LOCK TAKEOVER (owner, Nov 2 late): while Gol waits for a PIN the
  // pad owns the WHOLE chat surface — chat, chips and composer hide, the
  // pad fills the disc/panel on the globe. closePinPad hands it back.
  const dr = $('chatDrawer'); if (dr) { dr.classList.add('chat-locked'); }
  if (wasHidden && !$('chatDrawer').classList.contains('hidden')) { focusPinPad(); }
  if (window.__golPlaceMoon) { window.__golPlaceMoon(); }
}
function closePinPad() { const p = $('pinPad'); if (p) { p.classList.add('hidden'); } const dr = $('chatDrawer'); if (dr) { dr.classList.remove('chat-locked'); } pinDigits = ''; renderPinDots(); if (window.__golPlaceMoon) { window.__golPlaceMoon(); } }
function notePinState(r) {
  if (!r) { return; }
  if (typeof r.pin_ask === 'boolean') { if (r.pin_ask) { openPinPad(); } else { closePinPad(); } }
}
function pinKey(k) {
  if (k === 'clear') { pinDigits = ''; }
  else if (k === 'back') { pinDigits = pinDigits.slice(0, -1); }
  else if (/^[0-9]$/.test(k) && pinDigits.length < 6) { pinDigits += k; }
  renderPinDots();
}
async function pinPadSubmit() {
  if (pinDigits.length < 4) { return; }
  const digits = pinDigits; pinDigits = ''; renderPinDots();
  let r = null;
  try {
    const res = await fetch(BRAIN, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(brainPayload({ message: digits })) });
    r = await res.json();
  } catch (e) { chatMsg('Connection hiccup — try the PIN again.', 'gol'); return; }
  if (r.reply) { chatMsg(r.reply, 'gol'); }
  if (r.cancelled_pid) { markStagedCancelled(r.cancelled_pid); }
  else if (r.cancelled) { markStagedCancelled(''); }
  if (r.graph) { chatGraph(r.graph); }
  if (r.who) { chatWho(r.who); }
  if (r.dcard) { chatDCard(r.dcard); }
  applyLockState(r.locked, r.actor);
  if (r.unlock_event && r.unlock_event.id) { attachUnlockFrame(r.unlock_event.id); }
  let cardEl = null;
  if (r.card) { cardEl = chatCard(r.card); }
  try { golSpeak(posSayFor(r, '', cardEl)); } catch (e) {}
  handleChatAction(r);
  notePinState(r);
  if (r.done) { loadCatalog(); loadSettings(); }
}
$('pinPadGrid').querySelectorAll('button').forEach(b => b.addEventListener('click', () => pinKey(b.dataset.pin)));
$('pinPadGo').addEventListener('click', pinPadSubmit);
// --- the lock screen's own pad (TASKS 229, Nov 2) ----------------------------
// The PIN screen is the register's STARTING screen: the pad sits on the
// lock card itself, silent — Gol never asks for a PIN aloud (posSayFor
// returns null while the server waits for one). Digits ride the same
// brain turn as the chat pad; a failed try answers as TEXT on the lock
// card only — never a chat bubble, never a voice.
let lockPinDigits = '';
function renderLockPinDots() {
  const el = $('lockPinDots'); if (el) { el.textContent = '•'.repeat(lockPinDigits.length); }
  const go = $('lockPinGo'); if (go) { go.disabled = lockPinDigits.length < 4; }
}
function lockPinKey(k) {
  if (k === 'clear') { lockPinDigits = ''; }
  else if (k === 'back') { lockPinDigits = lockPinDigits.slice(0, -1); }
  else if (/^[0-9]$/.test(k) && lockPinDigits.length < 6) { lockPinDigits += k; }
  renderLockPinDots();
}
async function lockPinSubmit() {
  if (lockPinDigits.length < 4) { return; }
  const digits = lockPinDigits; lockPinDigits = ''; renderLockPinDots();
  const msgEl = $('lockPinMsg'); if (msgEl) { msgEl.textContent = ''; }
  let r = null;
  try {
    const res = await fetch(BRAIN, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(brainPayload({ message: digits })) });
    r = await res.json();
  } catch (e) { if (msgEl) { msgEl.textContent = 'Connection hiccup — try the PIN again.'; } return; }
  applyLockState(r.locked, r.actor);
  notePinState(r);
  if (r.locked) { if (msgEl && r.reply) { msgEl.textContent = r.reply; } return; }
  if (r.unlock_event && r.unlock_event.id) { attachUnlockFrame(r.unlock_event.id); }
  if (r.reply) { chatMsg(r.reply, 'gol'); }
  if (r.done) { loadCatalog(); loadSettings(); }
}
$('lockPinGrid').querySelectorAll('button').forEach(b => b.addEventListener('click', () => lockPinKey(b.dataset.lpin)));
$('lockPinGo').addEventListener('click', lockPinSubmit);
document.addEventListener('keydown', (e) => {
  const ov = $('lockOverlay');
  if (!ov || ov.classList.contains('hidden')) { return; }
  // When the chat drawer is open its own pad owns the keyboard.
  if (!$('chatDrawer').classList.contains('hidden')) { return; }
  const tag = (e.target && e.target.tagName) || '';
  if (tag === 'INPUT' || tag === 'TEXTAREA') { return; }
  if (/^[0-9]$/.test(e.key)) { lockPinKey(e.key); e.preventDefault(); }
  else if (e.key === 'Backspace') { lockPinKey('back'); e.preventDefault(); }
  else if (e.key === 'Enter') { lockPinSubmit(); e.preventDefault(); }
});
renderLockPinDots();
// --- owner PIN setting sheet (TASKS 230/231, Nov 2) ---------------------------
// The manual door to the same write the Gol chat stages as a card: the
// owner sets every PIN here (Owner panel → Team) or by chat; an
// employee session never reaches it — the Team card is owner-domain and
// the endpoint re-checks the admin session server-side.
let pinSetTarget = null;
function pinSetError(text) {
  const e = $('pinSetError');
  if (!e) { return; }
  e.textContent = text || '';
  e.classList.toggle('hidden', !text);
}
function openPinSetSheet(target, name) {
  pinSetTarget = target;
  $('pinSetTitle').textContent = target === 'admin' ? 'Reset the admin PIN' : 'Set PIN — ' + name;
  $('pinSetInput').value = ''; $('pinSetInput2').value = '';
  pinSetError('');
  $('pinSetModal').classList.remove('hidden');
  try { $('pinSetInput').focus({ preventScroll: true }); } catch (e) {}
}
function closePinSetSheet() { $('pinSetModal').classList.add('hidden'); pinSetTarget = null; }
async function pinSetSave() {
  const p1 = $('pinSetInput').value.trim(), p2 = $('pinSetInput2').value.trim();
  if (!/^\d{4,6}$/.test(p1)) { pinSetError('The PIN must be 4–6 digits.'); return; }
  if (p1 !== p2) { pinSetError("Those two PINs don't match — try again."); return; }
  let r = null;
  try {
    const res = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ pin_set: { target: pinSetTarget, pin: p1 }, session: SESSION_ID }) });
    r = await res.json();
  } catch (e) { pinSetError('Connection hiccup — nothing was saved. Try again.'); return; }
  if (!r || !r.ok) {
    pinSetError(r && r.error === 'pin_required'
      ? 'That needs the admin PIN first — unlock as owner, then set PINs.'
      : ((r && r.error) || 'Could not save that PIN.'));
    return;
  }
  const who = pinSetTarget === 'admin' ? 'Admin PIN' : (r.name || 'PIN');
  closePinSetSheet();
  showToast(who + ' updated — hand it over yourself; it is not shown again.');
  $('ownerBtn').click();
}
$('pinSetCancel').addEventListener('click', closePinSetSheet);
$('pinSetSave').addEventListener('click', pinSetSave);
document.addEventListener('keydown', (e) => {
  const p = $('pinPad');
  if (!p || p.classList.contains('hidden') || $('chatDrawer').classList.contains('hidden')) { return; }
  const tag = (e.target && e.target.tagName) || '';
  if (tag === 'INPUT' || tag === 'TEXTAREA') { return; }
  if (/^[0-9]$/.test(e.key)) { pinKey(e.key); e.preventDefault(); }
  else if (e.key === 'Backspace') { pinKey('back'); e.preventDefault(); }
  else if (e.key === 'Enter') { pinPadSubmit(); e.preventDefault(); }
});
renderPinDots();
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
{ const mb = $('smMrstoreBtn'); if (mb) { mb.addEventListener('click', () => { $('settingsPop').classList.add('hidden'); openMrstoreDoor(); }); } }
{ const tb = $('smTeamBtn'); if (tb) { tb.addEventListener('click', () => { $('settingsPop').classList.add('hidden'); openOwnerAtTeam(); }); } }
{ const ac = $('actorChip'); if (ac) { ac.addEventListener('click', () => { if (lockActor && lockActor.role === 'admin') { $('ownerBtn').click(); } }); } }
// Team lives in the Owner panel, and the owner kept looking for it in
// the Settings gear menu (Nov 2 late, twice: "Team aaena"). Two extra
// doors now lead to the same panel: the Settings → Team row (wired
// just above, rendered admin-only like the MR.STORE row) and the
// "Owner · admin" chip, which read as a button but was a status label
// — it opens the panel for an admin and stays a plain label for an
// employee (the leak rules keep every team door admin-only).
// openOwnerAtTeam runs the one panel load, then walks the view down
// to the Team card once its content lands (or its retry fallback does).
function openOwnerAtTeam() {
  $('ownerBtn').click();
  const card = $('ownerTeam');
  if (!card || !card.scrollIntoView) { return; }
  let tries = 0;
  const iv = setInterval(() => {
    tries++;
    if (card.querySelector('.team-person, #teamRetryBtn') || tries >= 30) {
      clearInterval(iv);
      card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }, 100);
}
$('ownerBtn').addEventListener('click', async () => {
  $('ownerPanel').classList.remove('hidden');
  $('ownerStoreName').textContent = $('storeName').textContent;
  const res = await fetch(API + '?summary'); const s = await res.json();
  const pres = await fetch(API + '?pending'); const p = await pres.json();
  const fres = await fetch(API + '?feed&session=' + encodeURIComponent(SESSION_ID)); const feed = await fres.json();
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
    const od = await (await fetch(API + '?orders&session=' + encodeURIComponent(SESSION_ID))).json();
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
  // LIGHT MIRROR (leak fix, Nov 2): the payload now carries counts,
  // names and statuses only — no task item texts (the price-gap items
  // name cost work), no note texts, no trail texts — so this card
  // renders the counts and points at the Gol chat for the detail.
  // A failed team read (a mid-deploy blip, a 403 from a session whose
  // admin flag lagged the unlock) used to leave this card SILENTLY
  // empty — the owner saw "Team is gone" with no trace and no way back
  // (owner catch, Nov 2 late). The card now says so and offers a retry
  // (a plain re-run of this panel load). The fallback carries no team
  // data at all — the light-mirror leak fix stays closed.
  const teamFail = () => {
    $('ownerTeam').innerHTML =
      `<h3 class="ocard-title">Team <small>— tasks, notes, the day's trail</small></h3>` +
      `<p class="owner-muted">Team couldn't load just now. <button type="button" class="pin-set-btn" id="teamRetryBtn">Retry</button></p>`;
    const rb = $('teamRetryBtn');
    if (rb) { rb.addEventListener('click', () => $('ownerBtn').click()); }
  };
  try {
    const tm = await (await fetch(API + '?team&session=' + encodeURIComponent(SESSION_ID))).json();
    if (tm && Array.isArray(tm.people)) {
      const tn = (v) => Array.isArray(v) ? v.length : (Number(v) || 0);
      $('ownerTeam').innerHTML =
        `<h3 class="ocard-title">Team <small>— tasks, notes, the day's trail</small></h3>` +
        // PIN setting lives HERE (TASKS 230/231, Nov 2): the panel's one
        // deliberate exception to the mirror rule — the owner sets every
        // PIN on this card and hands it out; the same write is staged
        // from the Gol chat. PINs are never displayed, only their state.
        `<div class="team-person"><b>Owner</b> <small>admin PIN — the register's master key</small>` +
        ` <span class="pin-state${tm.admin_has_pin ? '' : ' no'}">${tm.admin_has_pin ? 'PIN set' : 'No PIN yet'}</span>` +
        `<button type="button" class="pin-set-btn" data-pinset="admin" data-pinname="Owner (admin PIN)">${tm.admin_has_pin ? 'Reset PIN' : 'Set PIN'}</button></div>` +
        (tm.people.length ? tm.people.map(pp => {
          const tr = pp.trail || {};
          return `<div class="team-person"><b>${esc(pp.name)}</b> <small>${esc(pp.role)} · ${pp.tasks_open} open · ${pp.tasks_done} done${pp.notes_unread ? ' · ' + pp.notes_unread + ' unread note(s)' : ''}</small>` +
            ` <span class="pin-state${pp.has_pin ? '' : ' no'}">${pp.has_pin ? 'PIN set' : 'No PIN yet'}</span>` +
            `<button type="button" class="pin-set-btn" data-pinset="${esc(pp.email)}" data-pinname="${esc(pp.name)}">${pp.has_pin ? 'Reset PIN' : 'Set PIN'}</button>` +
            `<div class="sc-line">Din bhari: ${tn(tr.done)} done · ${tn(tr.notes)} note(s) · ${tn(tr.outs)} out report(s) · ${tn(tr.counts)} count(s) · ${tn(tr.questions)} question(s) — the work itself lives in the Gol chat.</div></div>`;
        }).join('') : '<p class="owner-muted">No employees yet — add one in the Gol chat.</p>') +
        (() => {
          // Unassigned (owner inbox) tasks: a tidy summary, never a raw
          // dump of every task text (owner catch, Oct 31 — the inbox held
          // 700+ generated tasks and the joined wall read as a data leak).
          const rt = tm.role_tasks || [];
          const counts = rt.filter(t => t.kind === 'whatsout_aging');
          const countNames = counts.map(t => String(t.subject || '').replace(/^count:/, ''))
            .filter(Boolean).slice(0, 5);
          const otherOpen = rt.filter(t => t.kind !== 'whatsout_aging')
            .reduce((n, t) => n + (t.open_count !== undefined ? (Number(t.open_count) || 0)
              : (t.items || []).filter(i => !i.done).length), 0);
          let s = '';
          if (counts.length) {
            s += `<p class="owner-muted">Stock counts waiting: ${counts.length}</p>` +
              countNames.map(n => `<div class="sc-line">• ${esc(n)}</div>`).join('') +
              (counts.length > countNames.length ? `<p class="owner-muted">…and ${counts.length - countNames.length} more — work them in the Gol chat.</p>` : '');
          }
          if (otherOpen) {
            s += `<p class="owner-muted">${otherOpen} more task${otherOpen === 1 ? '' : 's'} waiting in the owner inbox — see them in the Gol chat.</p>`;
          }
          return s;
        })() +
        ((tm.pay_requests || []).length
          ? '<h3 class="ocard-title">Pay requests <small>— waiting on you; decide in the Gol chat ("pay requests")</small></h3>' +
            tm.pay_requests.map(r =>
              `<div class="team-person"><b>${esc(r.name)}</b> <small>${esc(r.period)} · ${String(Number(r.hours) || 0)} hrs = ${money(r.amount)}</small></div>`
            ).join('')
          : '');
      $('ownerTeam').querySelectorAll('[data-pinset]').forEach(b =>
        b.addEventListener('click', () => openPinSetSheet(b.dataset.pinset, b.dataset.pinname)));
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
    } else { teamFail(); }
  } catch (e) { teamFail(); }
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
    const r = await (await fetch(API + '?report&date=' + sum.date + '&session=' + encodeURIComponent(SESSION_ID))).json();
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
    const r = await (await fetch(API + '?report&week=1&session=' + encodeURIComponent(SESSION_ID))).json();
    const wkTxns = r.days.every(d => d.txns != null) ? r.totals.txns + ' sales · ' : '';
    body.innerHTML = r.days.map(d =>
      `<div class="rep-row"><span>${d.date}</span><b>${money(d.gross)}</b>` +
      `<span>${d.txns != null ? d.txns + ' sales · ' : ''}cash ${money(d.cash)} · card ${money(d.card)}${d.closed ? ' · <em class="rep-closed">Closed</em>' : ''}</span></div>`).join('') +
      `<div class="rep-row rep-total"><span>This week</span><b>${money(r.totals.gross)}</b>` +
      `<span>${wkTxns}cash ${money(r.totals.cash)} · card ${money(r.totals.card)}</span></div>`;
    return;
  }
  const sum = await (await fetch(API + '?summary')).json(); // sum.date = the register's today
  let date = sum.date;
  if (mode === 'yesterday') {
    const d = new Date(sum.date + 'T12:00:00'); d.setDate(d.getDate() - 1);
    date = d.toISOString().slice(0, 10);
  }
  if (mode === 'custom' && dateVal) { date = dateVal; }
  const r = await (await fetch(API + '?report&date=' + date + '&session=' + encodeURIComponent(SESSION_ID))).json();
  const src = r.close || r.summary;
  body.innerHTML =
    `<div class="rep-row rep-total"><span>${date}${r.close ? ' · <em class="rep-closed">Closed</em>' : ''}</span>` +
    `<b>${money(src.gross)}</b><span>${src.txns != null ? src.txns + ' sales · ' : ''}cash ${money(src.cash)} · card ${money(src.card)}</span></div>`;
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
    if (interim) { callCapShow(interim); } /* display-only caption (TASKS 215): interim words never become the input value */
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
  recog.onend = () => { setVoiceUi(false); callCapHide(); };
  setVoiceUi(true);
  try { recog.start(); } catch (e) { setVoiceUi(false); }
}
// THE seam: a final transcript becomes a chat message through the same
// sendChat a typed message takes — same session, same brain, same card.
function voiceFinal(text) {
  setVoiceUi(false);
  callCapHide();
  $('chatInput').value = '';
  if (golIsEchoConfirm(text)) {
    chatMsg('Ignored my own voice. The card is still waiting — tap Confirm, or say gol after I am quiet.', 'gol');
    return;
  }
  sendChat(text);
}

// --- Gol CALL: the phone-metaphor voice loop (owner lock, Oct 29) ------------
// The Call button in the Gol panel header turns the panel into a call:
// tap Call -> Gol speaks her greeting -> the ear opens only once her
// voice has finished -> whatever is heard rides the SAME sendChat a
// typed message takes (staged cards, spoken "gol" confirms, spoken
// entered summaries all come from that untouched path) -> the ear
// re-opens for the next turn -> End call (or a stop word) hangs up:
// recognition stops, audio stops, the pre-call voice pref is restored.
// The composer/panel press-to-talk mic above is unchanged.
const IC_PHONE = '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>';
const IC_PHONE_DOWN = '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g transform="rotate(135 12 12)"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></g></svg>';
let callActive = false;
let callListening = false;
let callRecog = null;
let callHeard = false;
let callJustSent = false;
let callWatcher = null;
let callLastActivity = 0;
let callMinWait = 1200;
let callTurnSeq = 0;        // stamps every delivered Call turn, newest last
let callSendBusy = false;   // a Call turn's brain reply is in flight
let callPendingTurn = null; // the ONE queued Call turn — latest wins, replaced whole
let callPrevVoiceOn = true;
/* CALL-EAR SUPERVISOR (Oct 29 real-device fix): recognition sessions
   that end without a delivered turn are counted; after 3 the ear
   stops auto-retrying and the state pill offers "Tap to talk". */
let callEarFails = 0;
let callEarTap = false;
let callEngine = '';
let callTriedHear = false;
let callTriedSR = false;
/* --- TRUE BARGE-IN + LIVE CAPTION + SERVER-STT EAR (owner order Nov 2,
   TASKS 215 — the Muse-call benchmark behaviours) ---------------------
   The old loop was WINDOWED: the ear opened only after Gol's voice
   ended + 1.5s of quiet, so the owner had to talk over a reply that
   never stopped (TASKS 195 live catch). Now the ear stays OPEN while
   Gol speaks:
   - SR engine: a continuous WATCHER recognition runs during speech.
     Its words type live into the chat as a display-only caption; a
     transcript matching Gol's own spoken line is self-hearing and is
     dropped by the Nov 2 guards (golIsSelfEcho / golIsEchoConfirm);
     a DIVERGING human line stops the mouth mid-reply and takes the
     turn — the watcher hands the words it already heard to the turn
     ear as a seed, so nothing the human said is lost.
   - hear engine (Safari / no usable SR): the mic stream stays open
     during speech behind a voice-activity watch; sustained human
     loudness stops the mouth and the capture ear takes the turn, its
     transcript passing the same self-echo guard before it can send.
   The caption NEVER touches the composer: the Oct 29 buffered-send
   discipline is unchanged — interim words are display only, and a
   turn sends only through callDeliverTurn -> sendChat, exactly as a
   typed message sends. */
function callEarLang() {
  /* The cold-Call ear bug (owner live catch, Nov 2 morning): the
     recognizer defaulted to en-US and only learned Nepali AFTER a
     Nepali chat turn, so a cold Call garbled "Coke" into "Google
     stock price". The Call ear now opens in the conversation's mix:
     a stored preference wins; otherwise ne-NP — Chrome's Nepali
     recognizer carries English words acceptably, while en-US wrecks
     Nepali. The fleet server ear (gol-hear, lang=ne) is built on the
     same finding. The press-to-talk mic keeps voiceLangPref(). */
  const p = voiceLangPref();
  return p && p !== 'en-US' ? p : 'ne-NP';
}
function callHearSupported() {
  try { return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && (window.MediaRecorder || window.AudioContext || window.webkitAudioContext)); } catch (e) { return false; }
}
function callSupported() { return voiceSupported() || callHearSupported(); }
function callPickEngine() {
  /* EAR FIX (Nov 2, TASKS 215 slice 2 — owner: "majja le timro jasto
     banau", the Muse-call benchmark): the SERVER ear is now the
     PRIMARY Call ear on every browser that can capture mic audio.
     Browser SpeechRecognition garbles the Nepali+English mix (the
     Nov 2 morning "Coke" -> "Google stock price" failure: a word
     never spoken, invented by the recognizer), while gol-hear with
     lang=ne runs gpt-4o-mini-transcribe first and transcribes the
     mix faithfully — measured Nov 2 on fleet-TTS lines through the
     live endpoint: "Red Bull ko stock kati cha" came back
     near-verbatim and the English control perfect, where the
     no-hint whisper-first road returned Hindi-flavoured nonsense.
     SR survives as the FALLBACK only: it is the pick when capture
     is unavailable, and a failing hear ear hands the call to it
     (callEarSwitchToSR). Apple-vendor browsers were already
     hear-only — their SR cannot be trusted (fleet finding). */
  if (callHearSupported()) { return 'hear'; }
  if (voiceSupported()) { return 'sr'; }
  return '';
}
function callEarSwitchToHear() {
  if (callEngine === 'sr' && !callTriedHear && callHearSupported()) {
    callTriedHear = true; callEngine = 'hear'; callEarFails = 0; callEarTap = false;
    return true;
  }
  return false;
}
function callEarSwitchToSR() {
  if (callEngine === 'hear' && !callTriedSR && voiceSupported()) {
    callTriedSR = true; callEngine = 'sr'; callEarFails = 0; callEarTap = false;
    return true;
  }
  return false;
}
/* --- the live caption: the human's words typing in the chat ---------- */
function callCapShow(text) {
  const t = String(text || '').trim();
  if (!t) { return; }
  const body = $('chatBody');
  if (!body) { return; }
  let cap = document.getElementById('chatLiveCap');
  if (!cap) {
    cap = document.createElement('div');
    cap.id = 'chatLiveCap';
    cap.className = 'msg me live-cap';
    cap.setAttribute('aria-hidden', 'true');
  }
  if (cap.parentNode !== body) { body.appendChild(cap); }
  if (cap.textContent !== t) { cap.textContent = t; }
  body.scrollTop = 1e6;
}
function callCapHide() {
  const cap = document.getElementById('chatLiveCap');
  if (cap && cap.parentNode) { cap.parentNode.removeChild(cap); }
}
function callCapText() {
  const cap = document.getElementById('chatLiveCap');
  return cap ? cap.textContent : null;
}
/* --- the ONE delivery seam for a heard turn (every ear engine) ------- */
/* Fragment gate (owner ear test, Nov 2): cross-talk blips and mic
   noise transcribe as 1–2 letter scraps ("bu?") and were DELIVERED as
   turns — Gol answered "I didn't catch that" to nobody, and the
   spoken reply fed the loop. A sub-threshold fragment is dropped
   silently at this seam: no bubble, no brain call, the call stays
   live and the ear re-opens on the next tick. Real short turns are
   never fragments: the confirm word, yes/no words and greetings
   (which carry meaning at the till — a bare greeting cancels an
   armed card) always pass, as does anything carrying a digit. The
   honest "didn't catch that" still answers real FULL utterances the
   brain cannot resolve — those are sentences, not scraps. */
function callIsFragment(text) {
  const core = String(text || '').replace(/[^\p{L}\p{N}]+/gu, '');
  if (core.length === 0) { return true; }
  if (core.length > 2) { return false; }
  if (/\p{N}/u.test(core)) { return false; }
  return !/^(ho|ok|no|hi|हो)$/iu.test(core);
}
function callDeliverTurn(text) {
  callCapHide();
  if (callIsFragment(text)) {
    /* dropped silently — no turn, no reply; the ear re-opens. The
       turn ear's state is closed exactly as for a delivered turn, so
       nothing waits on a recognizer end-event that may never come. */
    callHeard = true;
    callListening = false;
    callLastActivity = Date.now(); callMinWait = 400; callPaint();
    return;
  }
  callEarFails = 0; callEarTap = false;
  callHeard = true;
  callListening = false;
  if (/^(bas|बस|stop|end call|hang up|रोक)\b/i.test(text)) { endCall(); return; }
  if (golIsEchoConfirm(text)) {
    chatMsg('Ignored my own voice. The card is still waiting — tap Confirm, or say gol after I am quiet.', 'gol');
    callLastActivity = Date.now(); callMinWait = 800; callPaint();
    return;
  }
  callJustSent = true;
  callMinWait = 2600;
  callLastActivity = Date.now();
  callPaint();
  /* Latest-wins turn queue (owner ear test, Nov 2 — turn crossing):
     while a brain reply is in flight the ear can already deliver the
     next transcript. Turns used to fire independent fetches whose
     replies rendered in COMPLETION order, so a slow reply to an older
     cross-talk sentence landed as the "answer" under the newest
     question (the M&M's sentence answered a stock question). Now a
     turn delivered mid-flight takes the single queue slot — replacing
     any turn already queued, which is thereby superseded BEFORE its
     processing began and is never sent — and the in-flight reply is
     dropped on arrival by sendChat's sequence check. Only the latest
     user intent is ever answered. */
  const seq = ++callTurnSeq;
  if (callSendBusy) {
    callPendingTurn = { text: text, seq: seq };
    return;
  }
  callSendTurn(text, seq);
}
function callSendTurn(text, seq) {
  callSendBusy = true;
  const finish = () => {
    callSendBusy = false;
    if (callPendingTurn) {
      const nx = callPendingTurn; callPendingTurn = null;
      if (callActive) { callSendTurn(nx.text, nx.seq); }
    }
  };
  sendChat(text, seq).then(finish, finish);
}
function callBargeWorthy(t) {
  const s = String(t || '').trim();
  if (!s) { return false; }
  return s.split(/\s+/).length >= 2 || s.length >= 5;
}
/* --- the barge-in watcher (SR engine): ears open while Gol speaks ---- */
let callWatchRec = null;
function callStopWatch() {
  if (callWatchRec) {
    const r = callWatchRec; callWatchRec = null;
    try { r.onresult = null; r.onerror = null; r.onend = null; } catch (e) { /* noop */ }
    try { r.stop(); } catch (e) { try { r.abort(); } catch (e2) { /* noop */ } }
  }
}
function callOpenWatch() {
  if (!callActive || callWatchRec || callListening || callEngine !== 'sr' || !voiceSupported()) { return; }
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  let rec;
  try { rec = new SR(); } catch (e) { return; }
  callWatchRec = rec;
  rec.lang = callEarLang();
  rec.interimResults = true;
  rec.continuous = true;
  let wF = '', wI = '', acted = false;
  const liveText = () => (wF + ' ' + wI).trim();
  rec.onresult = (ev) => {
    if (callWatchRec !== rec || acted) { return; }
    for (let i = ev.resultIndex || 0; i < ev.results.length; i++) {
      const r = ev.results[i];
      if (!r) { continue; }
      const tx = (r[0] && r[0].transcript) || '';
      if (r.isFinal) { wF += tx; } else { wI = tx; }
    }
    const live = liveText();
    if (!live) { return; }
    if (golIsSelfEcho(live) || golIsEchoConfirm(live)) { return; } /* Gol hearing itself: no caption, no barge, no turn */
    callCapShow(live);
    if (!callAudioIdle()) {
      if (callBargeWorthy(live)) {
        acted = true;
        callStopWatch();
        golStopVoice(); golSayPendingUrl = null;
        callOpenEar(live);
      }
    } else if (wF.trim()) {
      acted = true;
      const said = wF.trim();
      callStopWatch();
      callDeliverTurn(said);
    }
  };
  rec.onerror = () => { if (callWatchRec === rec) { callStopWatch(); } };
  rec.onend = () => {
    if (callWatchRec === rec) { callWatchRec = null; }
    if (acted || !callActive) { return; }
    const heard = liveText();
    if (!heard || golIsSelfEcho(heard) || golIsEchoConfirm(heard)) { return; }
    if (!callAudioIdle()) {
      if (callBargeWorthy(heard)) { golStopVoice(); golSayPendingUrl = null; callOpenEar(heard); }
    } else {
      callDeliverTurn(heard);
    }
  };
  try { rec.start(); } catch (e) { if (callWatchRec === rec) { callWatchRec = null; } }
}
/* --- the server-STT ear (hear engine): mic audio -> gol-hear.php -----
   Ported from the fleet storefront ear (Oct 30) to this register: the
   browser captures the mic itself (MediaRecorder, else a WebAudio WAV
   recorder) and posts each turn to /store-api/gol-hear.php with
   lang=ne — gpt-4o-mini-transcribe goes first there and carries the
   Nepali+English mix (English transcribes cleanly under the Nepali
   hint; measured on the fleet lane). The returned text rides
   callDeliverTurn, the ONE send path. A ~1.1s pause ends the turn
   (20s cap); a failed transcription is an empty turn, never a dead
   call. The mic stream is kept for the whole call so the barge-in
   watch below can listen while Gol speaks. */
const callHear = { stream: null, ctx: null, an: null, rec: null, chunks: [], wav: null, timer: null, watchTimer: null, speech: false, lastLoud: 0, loudSince: 0, started: 0, busy: false, ext: 'webm' };
function callHearWavBlob(wav) {
  let len = 0, i;
  for (i = 0; i < wav.bufs.length; i++) { len += wav.bufs[i].length; }
  const pcm = new Float32Array(len); let off = 0;
  for (i = 0; i < wav.bufs.length; i++) { pcm.set(wav.bufs[i], off); off += wav.bufs[i].length; }
  const buf = new ArrayBuffer(44 + len * 2), v = new DataView(buf);
  function ws(o, s) { for (let j = 0; j < s.length; j++) { v.setUint8(o + j, s.charCodeAt(j)); } }
  ws(0, 'RIFF'); v.setUint32(4, 36 + len * 2, true); ws(8, 'WAVEfmt ');
  v.setUint32(16, 16, true); v.setUint16(20, 1, true); v.setUint16(22, 1, true);
  v.setUint32(24, wav.sr, true); v.setUint32(28, wav.sr * 2, true);
  v.setUint16(32, 2, true); v.setUint16(34, 16, true);
  ws(36, 'data'); v.setUint32(40, len * 2, true);
  for (i = 0; i < len; i++) { const s = Math.max(-1, Math.min(1, pcm[i])); v.setInt16(44 + i * 2, s < 0 ? s * 32768 : s * 32767, true); }
  return new Blob([buf], { type: 'audio/wav' });
}
function callHearStopAll() {
  if (callHear.timer) { clearInterval(callHear.timer); callHear.timer = null; }
  callHearWatchStop();
  if (callHear.rec) { try { callHear.rec.stop(); } catch (e) { /* noop */ } callHear.rec = null; }
  if (callHear.wav) { try { callHear.wav.proc.disconnect(); } catch (e) { /* noop */ } callHear.wav = null; }
  if (callHear.stream) { try { callHear.stream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) { /* noop */ } callHear.stream = null; }
  if (callHear.ctx) { try { callHear.ctx.close(); } catch (e) { /* noop */ } callHear.ctx = null; callHear.an = null; }
  callHear.busy = false;
}
function callHearGiveUp() {
  callListening = false;
  callEarFails++;
  if (callEarFails >= 3 && !callEarSwitchToSR()) { callEarTap = true; }
  callLastActivity = Date.now(); callMinWait = 1200;
  callPaint();
}
function callHearFinish(discard) {
  if (callHear.timer) { clearInterval(callHear.timer); callHear.timer = null; }
  const rec = callHear.rec, wav = callHear.wav;
  callHear.rec = null; callHear.wav = null;
  callListening = false;
  function got(blob) {
    if (!callActive) { return; }
    if (discard || !blob || !callHear.speech) { callLastActivity = Date.now(); callMinWait = 400; callPaint(); return; }
    callHear.busy = true; callPaint();
    const fd = new FormData();
    fd.append('audio', blob, 'hear.' + (callHear.ext || 'webm'));
    fd.append('lang', 'ne');
    fetch('/store-api/gol-hear.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        callHear.busy = false;
        if (!callActive) { callPaint(); return; }
        const said = (j && j.ok && j.text ? String(j.text) : '').trim();
        if (said && !golIsSelfEcho(said)) { callDeliverTurn(said); }
        else { callLastActivity = Date.now(); callMinWait = 400; }
        callPaint();
      })
      .catch(function () {
        callHear.busy = false;
        if (!callActive) { return; }
        callHearGiveUp();
      });
  }
  if (rec) {
    let done = false;
    rec.onstop = function () { if (done) { return; } done = true; got(new Blob(callHear.chunks, { type: rec.mimeType || 'audio/webm' })); };
    try { rec.stop(); } catch (e) { if (!done) { done = true; got(new Blob(callHear.chunks, { type: 'audio/webm' })); } }
  } else if (wav) {
    try { wav.proc.disconnect(); } catch (e) { /* noop */ }
    got(callHearWavBlob(wav));
  } else {
    got(null);
  }
  callPaint();
}
function callHearEnsureAn(stream) {
  callHear.stream = stream;
  try {
    const AC = window.AudioContext || window.webkitAudioContext;
    if (!callHear.ctx && AC) { callHear.ctx = new AC(); }
    if (callHear.ctx && callHear.ctx.state === 'suspended') { callHear.ctx.resume(); }
    if (callHear.ctx && !callHear.an) {
      callHear.an = callHear.ctx.createAnalyser();
      callHear.an.fftSize = 512;
      callHear.ctx.createMediaStreamSource(stream).connect(callHear.an);
    }
  } catch (e) { callHear.an = null; }
}
function callHearLiveStream() {
  try { return !!(callHear.stream && callHear.stream.active && callHear.stream.getAudioTracks().some(function (t) { return t.readyState === 'live'; })); } catch (e) { return false; }
}
function callOpenEarHear() {
  if (!callActive || callListening || callHear.busy) { return; }
  callHearWatchStop();
  function begin(stream) {
    if (!callActive) { return; }
    callHearEnsureAn(stream);
    callHear.chunks = []; callHear.wav = null; callHear.speech = false;
    callHear.started = Date.now(); callHear.lastLoud = 0;
    let ext = 'webm';
    if (window.MediaRecorder) {
      let mime = '';
      const cand = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus', 'audio/ogg'];
      for (let ci = 0; ci < cand.length; ci++) { try { if (MediaRecorder.isTypeSupported(cand[ci])) { mime = cand[ci]; break; } } catch (eT) { /* try next */ } }
      try {
        const mr = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined);
        callHear.rec = mr;
        ext = mime.indexOf('mp4') >= 0 ? 'm4a' : (mime.indexOf('ogg') >= 0 ? 'ogg' : 'webm');
        mr.ondataavailable = function (ev) { if (ev.data && ev.data.size) { callHear.chunks.push(ev.data); } };
        mr.start(250);
      } catch (eR) { callHear.rec = null; }
    }
    if (!callHear.rec && callHear.ctx) {
      /* WAV fallback for browsers without MediaRecorder. */
      try {
        const src = callHear.ctx.createMediaStreamSource(stream);
        const proc = callHear.ctx.createScriptProcessor(4096, 1, 1);
        const gain = callHear.ctx.createGain(); gain.gain.value = 0;
        const wv = { bufs: [], sr: callHear.ctx.sampleRate, proc: proc };
        proc.onaudioprocess = function (ev) { try { wv.bufs.push(new Float32Array(ev.inputBuffer.getChannelData(0))); } catch (eP) { /* keep recording */ } };
        src.connect(proc); proc.connect(gain); gain.connect(callHear.ctx.destination);
        callHear.wav = wv;
        ext = 'wav';
      } catch (eW) { callHear.wav = null; }
    }
    if (!callHear.rec && !callHear.wav) { callHearGiveUp(); return; }
    callHear.ext = ext;
    callListening = true;
    callPaint();
    const data = new Uint8Array(callHear.an ? callHear.an.fftSize : 0);
    callHear.timer = setInterval(function () {
      if (!callActive) { callHearStopAll(); return; }
      let loud = false;
      if (callHear.an) {
        callHear.an.getByteTimeDomainData(data);
        let sum = 0;
        for (let i = 0; i < data.length; i++) { const d = data[i] - 128; sum += d * d; }
        loud = Math.sqrt(sum / Math.max(1, data.length)) > 5.5;
      }
      const now = Date.now();
      if (loud) { callHear.speech = true; callHear.lastLoud = now; }
      const age = now - callHear.started;
      if ((callHear.speech && now - callHear.lastLoud > 1100) || age > 20000) { callHearFinish(false); }
      else if (!callHear.speech && age > 25000) { callHearFinish(true); }
    }, 120);
  }
  if (callHearLiveStream()) { begin(callHear.stream); }
  else { navigator.mediaDevices.getUserMedia({ audio: true }).then(begin).catch(function () { callHearGiveUp(); }); }
}
/* --- the barge-in watch (hear engine): loudness while Gol speaks ---- */
function callHearWatchStop() {
  if (callHear.watchTimer) { clearInterval(callHear.watchTimer); callHear.watchTimer = null; }
  callHear.loudSince = 0;
}
function callHearWatchStart() {
  if (callHear.watchTimer || callListening || callHear.busy) { return; }
  const beginWatch = function (stream) {
    if (!callActive) { return; }
    callHearEnsureAn(stream);
    if (!callHear.an) { return; }
    const data = new Uint8Array(callHear.an.fftSize);
    callHear.loudSince = 0;
    callHear.watchTimer = setInterval(function () {
      if (!callActive || callListening || callHear.busy || callAudioIdle()) { callHearWatchStop(); return; }
      callHear.an.getByteTimeDomainData(data);
      let sum = 0;
      for (let i = 0; i < data.length; i++) { const d = data[i] - 128; sum += d * d; }
      const rms = Math.sqrt(sum / Math.max(1, data.length));
      const now = Date.now();
      /* Louder + longer than the turn VAD: Gol's own speaker output
         feeds this mic too, so the barge bar sits above her voice's
         typical bleed; the transcript still passes the self-echo
         guard before anything can send. THRESHOLD TUNED Nov 2 (ear
         fix): 9 -> 14, sustain unchanged at 450 ms. Measured on
         Gol's own TTS line in the watch's byte-RMS units (120 ms
         windows): at FULL level her voice sustains >9 for 720 ms —
         a self-barge waiting to happen through a loud speaker —
         but never sustains >14 past 240 ms; at a plausible -6 dB
         bleed nothing crosses either bar for 450 ms. Close human
         speech clears 14 easily on vowels, so a real interruption
         still barges. Device AGC varies, so this is a better
         first cut, not a calibration — the owner's ear test is
         the final word. */
      if (rms > 14) { if (!callHear.loudSince) { callHear.loudSince = now; } }
      else { callHear.loudSince = 0; }
      if (callHear.loudSince && now - callHear.loudSince >= 450) {
        callHearWatchStop();
        golStopVoice(); golSayPendingUrl = null;
        callOpenEarHear();
      }
    }, 120);
  };
  if (callHearLiveStream()) { beginWatch(callHear.stream); }
  else if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
    navigator.mediaDevices.getUserMedia({ audio: true }).then(beginWatch).catch(function () { /* the turn ear surfaces a blocked mic once Gol goes quiet */ });
  }
}
function callAudioIdle() {
  if (golSayPendingUrl) { return false; }
  if (typeof golSayInflight !== 'undefined' && golSayInflight > 0) { return false; }
  const a = golAudioEl;
  if (!a) { return true; }
  if (a.ended) { return true; }
  return !!a.paused;
}
function callPaint() {
  const b = $('chatCallBtn');
  if (b) {
    const supported = callSupported();
    b.style.display = supported ? '' : 'none';
    b.classList.toggle('in-call', callActive);
    b.classList.toggle('call-listening', callActive && callListening);
    b.setAttribute('aria-pressed', callActive ? 'true' : 'false');
    b.title = callActive ? 'End call with Gol' : 'Call Gol — talk hands-free';
    b.innerHTML = callActive
      ? IC_PHONE_DOWN + '<span>End call</span>'
      : IC_PHONE + '<span>Call</span>';
  }
  const pill = $('voicePill');
  if (pill && callActive) {
    // Listening face (owner, Nov 2 late: "listening style babal
    // banau"): in the CIRCLE the breathing centre ring + the caption
    // carry the state, so the pill stays down; in the rectangle the
    // pill shows — in its blue glass dress (CIRCLE v42), never red.
    const drw = $('chatDrawer');
    const circle = !!(drw && drw.classList.contains('chat-circle'));
    pill.classList.toggle('hidden', !callListening || circle);
  }
  const drwEl = $('chatDrawer');
  if (drwEl) { drwEl.classList.toggle('gol-listening', !!(callActive && callListening)); }
  const st = $('chatCallState');
  if (st) {
    st.classList.toggle('hidden', !callActive);
    if (callActive) {
      st.classList.toggle('tap', !!callEarTap);
      st.textContent = callEarTap ? 'Tap to talk'
        : (callListening ? 'Listening\u2026'
        : (!callAudioIdle() ? 'Gol is speaking\u2026'
        : (callJustSent ? 'Gol is thinking\u2026' : 'Listening\u2026')));
    }
  }
}
function callStopRecog() {
  if (callRecog) {
    const r = callRecog; callRecog = null;
    try { r.onresult = null; r.onerror = null; r.onend = null; } catch (e) { /* noop */ }
    try { r.stop(); } catch (e) { try { r.abort(); } catch (e2) { /* noop */ } }
  }
  callListening = false;
}
function startCall() {
  if (callActive) { return; }
  if (!callSupported()) { showToast('Voice is not available in this browser — typing works as always.'); callPaint(); return; }
  callEngine = callPickEngine();
  callTriedHear = callEngine === 'hear';
  callTriedSR = callEngine === 'sr';
  callPrevVoiceOn = golVoiceOn();
  if (!callPrevVoiceOn) { golSetVoiceOn(true); }
  callEarFails = 0; callEarTap = false;
  callActive = true;
  callJustSent = false;
  callMinWait = 1200;
  callLastActivity = Date.now();
  const drawer = $('chatDrawer'); if (drawer) { drawer.classList.remove('hidden'); }
  // Greeting: the first Gol bubble rendered in the panel (the panel's
  // own greeting line), spoken through the same golSpeak mouth.
  let greet = '';
  try {
    const first = document.querySelector('#chatBody .msg.gol');
    if (first) { greet = first.textContent || ''; }
  } catch (e) { /* fall through to the standard line */ }
  if (!greet || !greet.trim()) { greet = "Hi, I'm Gol. Add an item, ask a price, or say \"today's total\"."; } /* greeting (#200 fix, Nov 2): the panel's first Gol bubble IS the brain-composed unlock greeting (store-local clock, greeting law) - speak it as composed. The old validator (prefix must be namaste|hello|hi|welcome, no $, <=200 chars) rejected composed greetings like 'Good evening...' and forced the canned line on every Call. Fallback now only when the panel carries no greeting at all. */
  /* greeting law: the spoken intro happens once per page session - a later Call in the same session opens the ear straight to business. */
  if (!window.__golCallIntroDone) {
    window.__golCallIntroDone = true;
    try { golSpeak({ text: golCleanSay(greet).slice(0, 200), lang: golPickLang(greet) }); } catch (e) { /* text greeting still stands */ }
  }
  if (callWatcher) { clearInterval(callWatcher); }
  callWatcher = setInterval(callEarTick, 300);
  callPaint();
}
function endCall() {
  if (!callActive && !callListening && !callRecog) { callPaint(); return; }
  callActive = false;
  callJustSent = false;
  callPendingTurn = null; // a queued turn dies with the call — never sent after hangup
  if (callWatcher) { clearInterval(callWatcher); callWatcher = null; }
  callStopRecog();
  callStopWatch();
  callHearStopAll();
  callCapHide();
  golStopVoice();
  golSayPendingUrl = null;
  if (golVoiceOn() !== callPrevVoiceOn) { golSetVoiceOn(callPrevVoiceOn); }
  const pill = $('voicePill'); if (pill) { pill.classList.add('hidden'); }
  callPaint();
}
function callOpenEar(seed) {
  if (!callActive || callListening) { return; }
  callStopWatch();
  if (!callEngine) { callEngine = callPickEngine(); }
  if (callEngine === 'hear') { callOpenEarHear(); return; }
  if (!voiceSupported()) { return; }
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  callHeard = false;
  const rec = new SR();
  callRecog = rec;
  rec.lang = callEarLang();
  rec.interimResults = true;
  rec.continuous = false;
  /* Buffered speech (Oct 29 fix) + the live caption (Nov 2, TASKS 215):
     interim words type into the caption bubble only — never the
     composer — and a final sends through callDeliverTurn, the same
     seam a typed message takes. A seed arrives when the barge-in
     watcher already heard the start of this turn while Gol was
     speaking; those words are this turn's beginning, not lost. */
  let bufF = '', bufI = seed ? String(seed) : '', delivered = false, errKind = '';
  if (bufI) { callCapShow(bufI); }
  const deliver = (text) => {
    delivered = true;
    if (callRecog === rec) { callRecog = null; }
    try { rec.stop(); } catch (e) { /* already ending */ }
    callDeliverTurn(text);
  };
  rec.onresult = (ev) => {
    for (let i = ev.resultIndex || 0; i < ev.results.length; i++) {
      const r = ev.results[i];
      if (!r) { continue; }
      const tx = (r[0] && r[0].transcript) || '';
      if (r.isFinal) { bufF += tx; } else { bufI = tx; }
    }
    var live = (bufF + ' ' + bufI).trim();
    if (live && !golIsSelfEcho(live)) { callCapShow(live); }
    if (live && !callAudioIdle() && !golIsSelfEcho(live) && !golIsEchoConfirm(live)) { golStopVoice(); }
    const finalText = bufF.trim();
    if (!finalText || callHeard || delivered) { return; }
    if (golIsSelfEcho(finalText)) { return; }
    deliver(finalText);
  };
  rec.onerror = (ev) => {
    if (callRecog === rec) { callRecog = null; }
    callListening = false;
    const kind = ev && ev.error;
    errKind = kind || 'error';
    if (kind === 'not-allowed' || kind === 'service-not-allowed') {
      showToast('Microphone is blocked — allow the mic in the browser, then try again.');
      endCall();
      return;
    }
    if (callActive && kind !== 'aborted') {
      callEarFails++;
      if (callEarFails >= 3 && !callEarSwitchToHear()) { callEarTap = true; }
      callLastActivity = Date.now(); callMinWait = 1200;
    }
    callCapHide();
    callPaint();
  };
  rec.onend = () => {
    if (callRecog === rec) { callRecog = null; }
    callListening = false;
    if (callActive && !delivered && !callHeard) {
      const heard = (bufF || bufI || '').trim();
      if (heard && !golIsSelfEcho(heard)) { deliver(heard); return; }
      if (!errKind) {
        callEarFails++;
        if (callEarFails >= 3 && !callEarSwitchToHear()) { callEarTap = true; }
      }
    }
    if (callActive && !callJustSent) { callLastActivity = Date.now(); callMinWait = 800; }
    if (callJustSent) { callJustSent = false; callLastActivity = Date.now(); callMinWait = 2600; }
    if (!delivered) { callCapHide(); }
    callPaint();
  };
  callListening = true;
  callPaint();
  try { rec.start(); } catch (e) { callListening = false; if (callRecog === rec) { callRecog = null; } callLastActivity = Date.now(); callMinWait = 1200; callCapHide(); callPaint(); }
}
let callQuietSince = 0;
function callEarTick() {
  if (!callActive) { return; }
  callPaint();
  if (callEarTap) { return; }
  if (callListening || callHear.busy) { return; }
  if (!callAudioIdle()) {
    /* Gol is speaking — the ear stays OPEN (true barge-in, TASKS 215):
       the watcher hears over her voice; her own line is dropped by
       the self-echo guards, a diverging human line stops the mouth
       mid-reply and takes the turn. */
    callQuietSince = 0;
    if (callEngine === 'sr') { callOpenWatch(); }
    else if (callEngine === 'hear') { callHearWatchStart(); }
    return;
  }
  if (callWatchRec) { callStopWatch(); }
  callHearWatchStop();
  if (!callQuietSince) { callQuietSince = Date.now(); }
  if (Date.now() - callQuietSince < 1500) { return; }
  if (Date.now() - callLastActivity < callMinWait) { return; }
  callOpenEar();
}
function initCallBtn() {
  if ($('chatCallBtn')) { callPaint(); return; }
  const head = document.querySelector('.chat-head');
  const voiceBtn = $('chatVoiceBtn');
  const host = head || (voiceBtn && voiceBtn.parentNode);
  if (!host) { return; }
  if (!document.getElementById('callBtnStyle')) {
    const st = document.createElement('style');
    st.id = 'callBtnStyle';
    st.textContent = '.call-btn{display:inline-flex;align-items:center;gap:6px;margin-left:auto;background:#2563eb;color:#fff;border:none;border-radius:999px;padding:7px 13px;font-size:13px;font-weight:600;cursor:pointer;flex:none}'
      + '.call-btn .ic{width:16px;height:16px}'
      + '.call-btn.in-call{background:#dc2626;margin-left:auto}'
      + '@keyframes callPulse{0%{box-shadow:0 0 0 0 rgba(220,38,38,.55)}70%{box-shadow:0 0 0 9px rgba(220,38,38,0)}100%{box-shadow:0 0 0 0 rgba(220,38,38,0)}}'
      + '.call-btn.call-listening{animation:callPulse 1.1s ease-out infinite}'
      + '.call-state{display:inline-flex;align-items:center;height:30px;padding:0 11px;border-radius:999px;border:1.5px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:700;white-space:nowrap;flex:none}'
      + '.call-state.hidden{display:none}'
      + '.call-state.tap{background:#2563eb;border-color:#2563eb;color:#fff;cursor:pointer;box-shadow:0 0 0 3px rgba(37,99,235,.25)}'
      + '.chat-head .call-state{order:10;flex:1 1 100%;justify-content:center;margin-top:2px}'
      + '.msg.me.live-cap{opacity:.78;border:1.5px dashed rgba(255,255,255,.9);font-style:italic}';
    document.head.appendChild(st);
  }
  const b = document.createElement('button');
  b.id = 'chatCallBtn';
  b.type = 'button';
  b.className = 'call-btn';
  b.setAttribute('aria-label', 'Call Gol');
  b.addEventListener('click', () => { if (callActive) { endCall(); } else { startCall(); } });
  const closeBtn = $('chatClose');
  if (head && closeBtn && closeBtn.parentNode === head) { head.insertBefore(b, closeBtn); }
  else { host.appendChild(b); }
  if (!$('chatCallState')) {
    const st = document.createElement('button');
    st.id = 'chatCallState';
    st.type = 'button';
    st.className = 'call-state hidden';
    st.addEventListener('click', () => {
      if (!callActive || !callEarTap) { return; }
      callEarFails = 0; callEarTap = false;
      callLastActivity = 0; callMinWait = 0;
      callPaint();
      callEarTick();
    });
    if (b.parentNode) { b.parentNode.insertBefore(st, b.nextSibling); }
  }
  callPaint();
}
try { window.__golCall = { start: startCall, end: endCall, tick: callEarTick, state: () => ({ active: callActive, listening: callListening, audioIdle: callAudioIdle(), minWait: callMinWait, engine: callEngine, watching: !!callWatchRec, cap: callCapText(), busy: callSendBusy, queued: !!callPendingTurn, seq: callTurnSeq }) }; } catch (e) { /* harness aid only */ }

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
  if (locked !== undefined && locked !== null) {
    // Cold-start hint: the head script pre-shows the round PIN disc when
    // this device locked last time, so a fresh load never flashes the
    // register (or the old white card) before the lock state lands.
    try { localStorage.setItem('golpos_lock_hint', locked ? '1' : '0'); } catch (e) {}
    document.documentElement.classList.remove('boot-lock');
  }
  if (locked === false) {
    lockPinDigits = ''; renderLockPinDots();
    const lm = $('lockPinMsg'); if (lm) { lm.textContent = ''; }
  }
  const chip = $('actorChip');
  if (chip) {
    if (lockActor && lockActor.name) {
      chip.textContent = lockActor.role === 'admin' ? 'Owner · admin' : lockActor.name;
      chip.classList.remove('hidden');
    } else { chip.classList.add('hidden'); }
    // For an admin the chip is also a door to the Owner panel (it sits
    // beside the Owner button and reads like one); for an employee it
    // stays the plain name label it has always been — no affordance.
    const chipDoor = !!(lockActor && lockActor.role === 'admin');
    chip.style.cursor = chipDoor ? 'pointer' : '';
    chip.title = chipDoor ? 'Open the Owner panel' : '';
  }
  const mt = $('myTasksBtn');
  if (mt) { mt.classList.toggle('hidden', !(lockActor && lockActor.role === 'employee')); }
  const mr = $('smMrstoreRow');
  if (mr) { mr.classList.toggle('hidden', !(lockActor && lockActor.role === 'admin')); }
  const tr = $('smTeamRow');
  if (tr) { tr.classList.toggle('hidden', !(lockActor && lockActor.role === 'admin')); }
  // The Owner panel is admin-domain (leak fix, Nov 2): its button is
  // not part of the cashier's till. Hidden only while the session's
  // actor is an employee — admin and the no-actor setup/lock states
  // keep it (the data layer gates the reads regardless).
  const ob = $('ownerBtn');
  if (ob) { ob.classList.toggle('hidden', !!(lockActor && lockActor.role === 'employee')); }
}
async function refreshLockState() {
  try {
    const r = await (await fetch(API + '?lock&session=' + encodeURIComponent(SESSION_ID))).json();
    applyLockState(r.locked, r.actor);
    // Armed at boot: a locked register means the chat wears its PIN
    // state from the first open — Gol asks, nobody guesses.
    if (r.locked) { openPinPad(); } else { closePinPad(); }
  } catch (e) { /* the register never stops for a state read */ }
}

// Auto-lock (owner, Nov 2 night: "lock screen after 5 min inactivity"):
// five quiet minutes on the till and the register returns to its PIN
// screen, through the same 'lock' conversation the Settings button
// uses. Any touch or key counts as life; a live Gol Call counts too —
// talking to Gol is working, not idling.
const LOCK_IDLE_MS = 5 * 60 * 1000;
let lastTillActivity = Date.now();
['pointerdown', 'keydown', 'touchstart'].forEach((ev) => {
  document.addEventListener(ev, () => { lastTillActivity = Date.now(); }, { capture: true, passive: true });
});
setInterval(() => {
  if (Date.now() - lastTillActivity < LOCK_IDLE_MS) { return; }
  const ov = $('lockOverlay');
  if (ov && !ov.classList.contains('hidden')) { return; }
  const pad = $('pinPad');
  if (pad && !pad.classList.contains('hidden')) { return; }
  try {
    const st = window.__golCall && window.__golCall.state ? window.__golCall.state() : null;
    if (st && st.active) { lastTillActivity = Date.now(); return; }
  } catch (e) { /* no call state, no problem */ }
  lastTillActivity = Date.now();
  sendChat('lock');
}, 20000);
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
    try { golSpeak(posSayFor(r, '', null)); } catch (e) {}
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
      let inboxCardEl = null;
      if (e.card) { inboxCardEl = chatCard(e.card); }
      try { golSpeak(posSayFor(e, '', inboxCardEl)); } catch (err) {}
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

// Lock-state self-heal (Oct 31): the overlay used to move ONLY when a
// brain reply was applied in this page (or at boot). A successful PIN
// unlock that landed while a reply was missed — or on an older cached
// build whose PIN-pad handler never applied the state — left the till
// showing "PIN please" while the server already stood open (owner hit
// exactly this: server unlock recorded, screen still locked). Poll the
// server's truth and converge; also re-check the moment the window is
// seen again. The poll is read-only and never locks a till the server
// says is open, nor opens one the server says is locked.
setInterval(refreshLockState, 5000);
document.addEventListener('visibilitychange', () => { if (!document.hidden) { refreshLockState(); } });
window.addEventListener('focus', () => { refreshLockState(); });

$('camBtn').addEventListener('click', () => openScanSheet());
$('voiceBtn').addEventListener('click', () => { $('chatDrawer').classList.remove('hidden'); startVoice(); });
$('chatMicBtn').addEventListener('click', () => startVoice());
initCallBtn();
{ const vb = $('chatVoiceBtn'); if (vb) { vb.addEventListener('click', () => golSetVoiceOn(!golVoiceOn())); } syncVoiceBtn(); }
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

// --- the Scan bills sheet: the top-bar camera button's home (owner order,
// Oct 31) — phone pairing is the headline, the register camera and the
// plain file upload stay inside as the other two doors. openCam keeps its
// own fallbacks for its other callers (counter sensing, chat asks).
function openScanSheet() {
  const canCam = !camMasterHeld() && hasGUM();
  $('ssCamera').classList.toggle('hidden', !canCam);
  $('scanSheet').classList.remove('hidden');
}
function closeScanSheet() { $('scanSheet').classList.add('hidden'); }
$('ssClose').addEventListener('click', closeScanSheet);
$('ssPhone').addEventListener('click', () => { closeScanSheet(); openScanModal(); });
$('ssCamera').addEventListener('click', () => { closeScanSheet(); openCam('photo'); });
$('ssUpload').addEventListener('click', () => {
  closeScanSheet();
  $('chatDrawer').classList.remove('hidden'); $('photoInput').click();
});
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

// --- the MR.STORE door (owner, Oct 31): the Settings panel carries the
// owner from this register into this store's full MR.STORE view. The
// store is resolved exactly as the What's Out lane resolves it — the
// linked store id on the catalog, else the demo store by name — and
// app.html itself honors ?store= at boot (its desk painter reads the
// param before its own last-store fallback), so the door always lands
// on THIS register's store, never another. Same origin: a browser
// already signed into MR.STORE opens recognized; otherwise MR.STORE's
// own sign-in answers, with the store already in hand. Admin sessions
// only — the settings row is hidden for everyone else, and this guard
// refuses honestly if it is ever reached without one.
function openMrstoreDoor() {
  if (!(lockActor && lockActor.role === 'admin')) { showToast('Owner only — MR.STORE opens for the admin session.'); return; }
  const sid = (catalog.store && catalog.store.store_id) || WOE_BY_NAME[catalog.store && catalog.store.name] || '';
  const url = 'https://luckymart.biz/app.html' + (sid ? '?store=' + encodeURIComponent(sid) : '');
  window.open(url, '_blank');
}

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

// --- Gol panel: a companion that lives around the register -------------------
// Owner, Nov 2: "like a conscious chat around" + "float icon click ma
// khulne ani afai banda hune". Three behaviours, layout only — no data
// path, no Call logic, no brain change:
//  1. Collapsed, Gol is the floating icon (#golFab) — a solid Gol-blue
//     disc with the white open-ring mark — present on every view. The
//     circle itself is free-style: drag it anywhere and the spot is
//     remembered; a tap that is not a drag opens the panel.
//  2. The open panel can be dragged by its head anywhere on screen and
//     resized free-style from either side edge, the bottom edge, or the
//     bottom corners (desktop; mobile stays full-screen by law). The
//     spot and size are saved on this device and restored on every
//     open and every reload.
//  3. When the conversation has been quiet for IDLE_MS the panel folds
//     itself back to the icon — NEVER while a Call is live, never while
//     a staged card waits for the owner's "gol", never while the PIN
//     pad or the voice pill waits on him. Folding only hides the panel;
//     the chat itself is untouched, so reopening shows the same thread.
(function initGolPanel() {
  const drawer = $('chatDrawer'); if (!drawer) { return; }
  const head = drawer.querySelector('.chat-head');
  const fab = $('golFab');
  const POS_KEY = 'golpos_chat_pos';
  const FAB_KEY = 'golpos_fab_pos';
  const IDLE_MS = 60000;
  const MIN_W = 300, MIN_H = 360;
  const mqMobile = window.matchMedia('(max-width: 820px)');
  let lastActivity = Date.now();
  let dragging = null;

  function noteActivity() { lastActivity = Date.now(); }
  function isOpen() { return !drawer.classList.contains('hidden'); }

  // -- vanish into the circle -------------------------------------------------
  // Owner, Nov 2 (evening): on close the panel must not blink away — it
  // folds back into the round Gol shape (the CSS .chat-vanish animation
  // shrinks it to a circle at its bottom-right corner) and only then
  // hides, leaving the circular orb. Any touch on the panel mid-fold, or
  // reopening, cancels the fold.
  let vanishTimer = null;
  function foldPanel() {
    if (!isOpen() || drawer.classList.contains('chat-vanish')) { return; }
    // Owner, Nov 2 late: "close garda bolna chodena" — closing the chat
    // silences the mouth in the same tap. golStopVoice() cuts the line
    // playing right now, generation-stops any TTS fetch still rendering
    // (it can never start after the fold), and the autoplay-queued line
    // is dropped — nothing speaks into a closed window, and reopening
    // never resumes what was killed; the thread itself is untouched.
    // A live Call is the one exception: the Call owns the mouth while
    // it runs and its own End stops both, so a mid-call fold leaves
    // call speech and call state exactly as they were.
    if (!(typeof callActive !== 'undefined' && callActive)) {
      try { golStopVoice(); } catch (e) {}
      golSayPendingUrl = null;
    }
    closeRing();
    drawer.classList.add('chat-vanish');
    vanishTimer = setTimeout(() => {
      vanishTimer = null;
      if (!drawer.classList.contains('chat-vanish')) { return; }
      drawer.classList.remove('chat-vanish');
      drawer.classList.add('hidden');
      placeMoon();
    }, 300);
  }
  // Circle-float extras (owner, Nov 2 evening): ONE float button holds the
  // quick-ask menu inside the circle (hidden until tapped, like the
  // MR.STORE menu float), and a glowing Call orb is the circle's idle
  // hero — it drives the very same Call as the head button and steps
  // aside once the chat is working, the pad is up, or a call is live.
  // Circle float, flower menu (owner, Nov 2 evening): the circle stays
  // EMPTY — header, chat bubbles and the typing form are the only
  // furniture, the Gol logo glows at the centre, and ONE float button
  // blooms every action (quick asks + Call) into a RING around the
  // circle, like a flower opening. A ring tap runs the action in the
  // centre and the ring STAYS (owner correction, Nov 2 late) — the
  // flower folds, and the buttons go home to their strip, only via
  // its ✕, the Moon toggle, or the panel's own fold.
  const menuFab = $('golMenuFab');
  const chipsEl = $('chatChips');
  const chipBtns = chipsEl ? Array.prototype.slice.call(chipsEl.querySelectorAll('button')) : [];
  // Cam + voice join the ring too (owner, Nov 2 late): inside the circle
  // only the typing field and the send button stay — photo, mic and
  // speaker bloom OUTSIDE with the flower and come home to the form
  // when it folds (in circle mode the form hides them; see styles v12).
  const formEl = $('chatForm');
  const photoEl = formEl ? formEl.querySelector('.photo-btn') : null;
  const micEl = $('chatMicBtn');
  const voiceEl = $('chatVoiceBtn');
  const formBtns = [photoEl, micEl, voiceEl].filter(Boolean);
  formBtns.forEach((b) => { b.classList.add('gol-ring-el'); });
  if (photoEl) { photoEl.setAttribute('data-chip', 'Photo'); }
  if (micEl) { micEl.setAttribute('data-chip', 'Voice'); }
  if (voiceEl) { voiceEl.setAttribute('data-chip', 'Speaker'); }
  const ringEl = $('golRing');
  const ringCall = document.createElement('button');
  ringCall.type = 'button';
  ringCall.className = 'gol-ring-call';
  ringCall.setAttribute('aria-label', 'Call Gol');
  ringCall.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6.6 10.8c1.4 2.8 3.8 5.1 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C10.6 21 3 13.4 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.2.2 2.4.6 3.6.1.3 0 .7-.2 1l-2.3 2.2z"/></svg>';
  ringCall.addEventListener('click', () => { const cb = $('chatCallBtn'); if (cb) { cb.click(); } });
  const ringClose = document.createElement('button');
  ringClose.type = 'button';
  ringClose.className = 'gol-ring-close';
  ringClose.setAttribute('aria-label', 'Close chat');
  ringClose.setAttribute('data-chip', 'Close');
  ringClose.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>';
  ringClose.addEventListener('click', () => { foldPanel(); });
  function openRing() {
    if (!ringEl || !chipBtns.length) { return; }
    const r = drawer.getBoundingClientRect();
    const R = Math.round(r.width / 2) + 46;
    ringEl.style.width = (R * 2) + 'px'; ringEl.style.height = (R * 2) + 'px';
    ringEl.style.left = Math.round(r.left + r.width / 2 - R) + 'px';
    ringEl.style.top = Math.round(r.top + r.height / 2 - R) + 'px';
    ringEl.style.setProperty('--rr', R + 'px');
    const items = [ringClose].concat(formBtns, chipBtns);
    const SHORT = { 'Photo': 'Photo', 'Voice': 'Voice', 'Speaker': 'Speaker', "Today's total": 'Today', 'Close the day': 'Close day', 'Batch close': 'Batch', 'Loyalty leaderboard': 'Loyalty', 'Employees list': 'Staff', "This week's sales": 'Week sales', 'Why was today slow?': 'Why slow?', 'What sold best this week?': 'Best sellers', 'How much is a Coke?': 'Stock price', 'Close': 'Close' };
    items.forEach((b, i) => {
      const lbl = b.getAttribute('data-chip') || '';
      b.setAttribute('data-rlabel', SHORT[lbl] || lbl);
      b.style.setProperty('--a', (-90 + i * (360 / items.length)) + 'deg');
      b.style.setProperty('--i', i);
      ringEl.appendChild(b);
    });
    ringEl.classList.remove('hidden');
    drawer.classList.add('menu-open');
    placeMoon();
  }
  function closeRing() {
    if (chipsEl) { chipBtns.forEach((b) => { chipsEl.appendChild(b); }); }
    if (formEl) { const inp = $('chatInput'); formBtns.forEach((b) => { formEl.insertBefore(b, inp); }); }
    if (ringCall.parentNode) { ringCall.parentNode.removeChild(ringCall); }
    if (ringClose.parentNode) { ringClose.parentNode.removeChild(ringClose); }
    if (ringEl) { ringEl.classList.add('hidden'); }
    drawer.classList.remove('menu-open');
    placeMoon();
  }
  if (menuFab) { menuFab.addEventListener('click', () => { if (drawer.classList.contains('menu-open')) { closeRing(); } else { openRing(); } }); }
  // The Moon (owner, Nov 2 late: "Earth-Moon, bahira moon jasari"): in
  // circle mode the menu button leaves the drawer and orbits the circle
  // just off its upper-right rim. v27: NO observer (v26's subtree
  // observer wedged the page) — placeMoon is called only from the
  // explicit moments that can move it: circle apply, fold, ring
  // open/close, drag/resize end, PIN pad open/close.
  let moonBusy = false;
  function placeMoon() {
    if (!menuFab || moonBusy) { return; }
    moonBusy = true;
    try {
      const circle = drawer.classList.contains('chat-circle') && isOpen();
      if (circle) {
        if (menuFab.parentNode !== document.body) { document.body.appendChild(menuFab); }
        menuFab.classList.add('gol-moon');
        menuFab.classList.toggle('moon-hide', !!drawer.querySelector('.pin-pad:not(.hidden)'));
        menuFab.classList.toggle('moon-open', drawer.classList.contains('menu-open'));
        const r = drawer.getBoundingClientRect();
        const ang = -21 * Math.PI / 180;
        const dist = r.width / 2 + 34;
        const cx = r.left + r.width / 2, chy = r.top + r.height / 2;
        menuFab.style.left = Math.round(cx + dist * Math.cos(ang) - 23) + 'px';
        menuFab.style.top = Math.round(chy + dist * Math.sin(ang) - 23) + 'px';
      } else {
        menuFab.classList.remove('gol-moon'); menuFab.classList.remove('moon-hide'); menuFab.classList.remove('moon-open');
        menuFab.style.left = ''; menuFab.style.top = '';
        if (menuFab.parentNode !== drawer) { drawer.appendChild(menuFab); }
      }
    } catch (e) { /* the moon never breaks the till */ }
    moonBusy = false;
  }
  window.__golPlaceMoon = placeMoon;
  placeMoon();
  // The ring STAYS (owner correction, Nov 2 late: "click garda harayo
  // menu"): a tap on a ring item runs its action in the circle's centre
  // and the flower stays open — it folds only via its own ✕ (ringClose),
  // the Moon toggle, or the panel's fold. Until now ANY click bubbling
  // up to the ring closed it 60ms later, so using one item dismissed
  // the whole menu. Ring taps are also ACTIVITY: the ring lives OUTSIDE
  // the drawer, so the drawer's idle listeners never see them — count
  // them here with the same events typing uses, or the idle fold would
  // take the panel mid-menu (Photo's file picker and the Speaker toggle
  // append no chat line, so nothing else resets their clock).
  if (ringEl) {
    ['pointerdown', 'keydown', 'input', 'wheel', 'touchstart'].forEach((ev) =>
      ringEl.addEventListener(ev, noteActivity, true));
  }
  // THE Call (owner, Nov 2 late: "call double bho — gol icon mathi hala,
  // call circle bahira side ma"): ONE Call only, a blue disc stacked just
  // above the Gol orb, OUTSIDE the circle on the side strip the circle
  // can never enter. The header Call hides in circle mode and the ring
  // no longer carries one. A tap opens the panel if needed, then drives
  // the very same Call button; in-call it turns red (End).
  const callSide = $('golCallSide');
  if (callSide) {
    const syncSide = () => { document.body.classList.toggle('gol-circle-on', !mqMobile.matches && window.innerWidth >= 1024 && window.innerHeight >= 700); };
    syncSide();
    window.addEventListener('resize', syncSide);
    callSide.addEventListener('click', () => {
      const cb = $('chatCallBtn'); if (!cb) { return; }
      if (!isOpen()) { foldOpen(); setTimeout(() => { cb.click(); }, 380); }
      else { cb.click(); }
    });
    const cbEl = $('chatCallBtn');
    if (cbEl) { new MutationObserver(() => { callSide.classList.toggle('in-call', cbEl.classList.contains('in-call')); }).observe(cbEl, { attributes: true, attributeFilter: ['class'] }); }
  }
  // Dim the shop while Gol is open (owner, Nov 2 late: "gol khulda bg dim
  // gardeu, ani gol minimize huda wapas"): a scrim under the panel darkens
  // the register; minimizing Gol restores it. A tap on the dim folds Gol.
  const scrim = $('golScrim');
  if (scrim) {
    const syncScrim = () => { scrim.classList.toggle('hidden', drawer.classList.contains('hidden')); };
    syncScrim();
    new MutationObserver(syncScrim).observe(drawer, { attributes: true, attributeFilter: ['class'] });
    scrim.addEventListener('click', () => { foldPanel(); });
  }
  const heroLogo = $('golCenterLogo');
  if (heroLogo) {
    const syncHero = () => {
      const body = $('chatBody');
      const msgs = body ? body.querySelectorAll('.msg').length : 0;
      const pad = $('pinPad');
      const locked = pad && !pad.classList.contains('hidden');
      const pill = $('voicePill');
      const pillUp = pill && !pill.classList.contains('hidden');
      const cb = $('chatCallBtn');
      const inCall = cb && cb.classList.contains('in-call');
      const listening = !!(cb && cb.classList.contains('call-listening'));
      // While the ear is open at the start of a call the centre ring
      // IS the listening face (it breathes — CIRCLE v42); once the
      // thread grows, or Gol is speaking/thinking, it steps aside.
      heroLogo.classList.toggle('hidden', !!(locked || pillUp || (inCall && !listening) || msgs > 1));
      heroLogo.classList.toggle('gol-listening', !!(inCall && listening && !locked && !pillUp && msgs <= 1));
    };
    const heroObs = new MutationObserver(syncHero);
    const bEl = $('chatBody'); if (bEl) { heroObs.observe(bEl, { childList: true }); }
    const pEl = $('pinPad'); if (pEl) { heroObs.observe(pEl, { attributes: true, attributeFilter: ['class'] }); }
    const vEl = $('voicePill'); if (vEl) { heroObs.observe(vEl, { attributes: true, attributeFilter: ['class'] }); }
    const cEl = $('chatCallBtn'); if (cEl) { heroObs.observe(cEl, { attributes: true, attributeFilter: ['class'] }); }
    syncHero();
  }
  window.__golFoldPanel = foldPanel;
  window.addEventListener('resize', () => { if (drawer.classList.contains('chat-circle')) { applyCircle(); } });
  drawer.addEventListener('pointerdown', () => {
    if (drawer.classList.contains('chat-vanish')) {
      drawer.classList.remove('chat-vanish');
      if (vanishTimer) { clearTimeout(vanishTimer); vanishTimer = null; }
    }
  }, true);

  // -- remembered position ----------------------------------------------------
  function loadPos() {
    try {
      const p = JSON.parse(localStorage.getItem(POS_KEY));
      if (p && isFinite(p.l) && isFinite(p.t) && isFinite(p.w) && isFinite(p.h)) { return p; }
    } catch (e) { /* no saved spot yet */ }
    return null;
  }
  function savePos(p) { try { localStorage.setItem(POS_KEY, JSON.stringify(p)); } catch (e) { /* private mode */ } }
  function clampPos(p) {
    const vw = window.innerWidth, vh = window.innerHeight;
    p.w = Math.min(p.w, vw); p.h = Math.min(p.h, vh);
    if (p.w < Math.min(MIN_W, vw)) { p.w = Math.min(MIN_W, vw); }
    if (p.h < Math.min(MIN_H, vh)) { p.h = Math.min(MIN_H, vh); }
    p.l = Math.max(0, Math.min(p.l, Math.max(0, vw - p.w)));
    p.t = Math.max(0, Math.min(p.t, Math.max(0, vh - p.h)));
    return p;
  }
  function currentPos() { const r = drawer.getBoundingClientRect(); return { l: r.left, t: r.top, w: r.width, h: r.height }; }
  function applyPos(p) {
    if (mqMobile.matches) { return; }
    p = clampPos(p);
    drawer.classList.add('chat-free');
    drawer.style.left = p.l + 'px'; drawer.style.top = p.t + 'px';
    drawer.style.width = p.w + 'px'; drawer.style.height = p.h + 'px';
  }
  function applySavedPos() { const p = loadPos(); if (p) { applyPos(p); } }

  // -- the circle form (owner, Nov 2 evening: "bhaena golo chat") -------------
  // On desktop the open panel takes Gol's own form: a true circle. It is
  // squared around wherever the panel already lives, so the owner's saved
  // spot survives as the circle's centre. One class (.chat-circle) carries
  // the whole shape — removing it restores the rectangle untouched. Mobile
  // keeps the full-screen law: no circle there.
  function circleSize() { return Math.round(Math.min(window.innerWidth * 0.92, window.innerHeight * 0.86, 620)); }
  function applyCircle() {
    // The circle lives on big screens only (owner, Nov 2 late: "phone ma
    // pahila jasatai chodnu — square and flexible"): under 1024x700 the
    // panel keeps its earlier forms untouched. On a big screen the circle
    // is clamped with RING ROOM (112px) on every side, so the flower ring
    // and its buttons can never spill off-screen or over the top bar.
    if (mqMobile.matches || window.innerWidth < 1024 || window.innerHeight < 700) { drawer.classList.remove('chat-circle'); placeMoon(); return; }
    drawer.classList.add('chat-circle');
    if (!isOpen()) { return; }
    const M = 112, vw = window.innerWidth, vh = window.innerHeight;
    let d = Math.round(Math.min(600, vw - 2 * M, vh - 2 * M));
    if (d < 400) { drawer.classList.remove('chat-circle'); placeMoon(); return; }
    const saved = loadPos();
    let cx, cy;
    if (saved) { cx = saved.l + saved.w / 2; cy = saved.t + saved.h / 2; }
    else { cx = vw - d / 2 - 28; cy = vh / 2; }
    cx = Math.max(d / 2 + M, Math.min(vw - d / 2 - M, cx));
    cy = Math.max(d / 2 + M, Math.min(vh - d / 2 - M, cy));
    drawer.classList.add('chat-free');
    drawer.style.left = (cx - d / 2) + 'px'; drawer.style.top = (cy - d / 2) + 'px';
    drawer.style.width = d + 'px'; drawer.style.height = d + 'px';
    placeMoon();
  }

  // -- drag by the head ---------------------------------------------------------
  if (head) {
    head.addEventListener('pointerdown', (e) => {
      if (mqMobile.matches || !isOpen()) { return; }
      if (e.button !== undefined && e.button !== 0) { return; }
      if (!e.target.closest || e.target.closest('button, a, input, select, textarea')) { return; }
      let p;
      if (drawer.classList.contains('chat-free')) { p = currentPos(); }
      else {
        // First grab: lift the docked panel into a floating window where
        // it already sits — a little shorter than the screen so it can
        // travel anywhere, the cart included no longer trapped under it.
        const r = drawer.getBoundingClientRect();
        const h = Math.min(r.height, Math.round(window.innerHeight * 0.88));
        p = clampPos({ l: r.left, t: Math.max(0, window.innerHeight - h - 12), w: r.width, h });
        applyPos(p);
      }
      dragging = { dx: e.clientX - p.l, dy: e.clientY - p.t, pos: p };
      drawer.classList.add('chat-dragging');
      try { head.setPointerCapture(e.pointerId); } catch (err) { /* mouse still drags */ }
      e.preventDefault();
    });
    head.addEventListener('pointermove', (e) => {
      if (!dragging) { return; }
      const p = clampPos({ l: e.clientX - dragging.dx, t: e.clientY - dragging.dy, w: dragging.pos.w, h: dragging.pos.h });
      dragging.pos = p;
      drawer.style.left = p.l + 'px'; drawer.style.top = p.t + 'px';
      noteActivity();
    });
    const endDrag = () => {
      if (!dragging) { return; }
      savePos(dragging.pos); dragging = null;
      placeMoon();
      drawer.classList.remove('chat-dragging');
      noteActivity();
    };
    head.addEventListener('pointerup', endDrag);
    head.addEventListener('pointercancel', endDrag);
  }

  // -- free-style resize: both sides, the bottom, both bottom corners -------
  // Dragging the left edge anchors the right edge (the left compensates);
  // the new size lands in the same saved record as the position.
  let resizing = null;
  [['l', 'chat-rz chat-rz-l'], ['r', 'chat-rz chat-rz-r'], ['b', 'chat-rz chat-rz-b'],
   ['bl', 'chat-rz chat-rz-bl'], ['br', 'chat-rz chat-rz-br']].forEach(function (spec) {
    const mode = spec[0];
    const handle = document.createElement('div');
    handle.className = spec[1];
    handle.setAttribute('aria-hidden', 'true');
    handle.addEventListener('pointerdown', (e) => {
      if (mqMobile.matches || !isOpen() || !drawer.classList.contains('chat-free')) { return; }
      if (e.button !== undefined && e.button !== 0) { return; }
      resizing = { mode: mode, x: e.clientX, y: e.clientY, pos: currentPos(), live: null };
      drawer.classList.add('chat-resizing');
      try { handle.setPointerCapture(e.pointerId); } catch (err) { /* mouse still resizes */ }
      e.preventDefault(); e.stopPropagation();
    });
    handle.addEventListener('pointermove', (e) => {
      if (!resizing) { return; }
      const s = resizing.pos, m = resizing.mode;
      const dx = e.clientX - resizing.x, dy = e.clientY - resizing.y;
      const vw = window.innerWidth, vh = window.innerHeight;
      let w = s.w, h = s.h, l = s.l;
      if (m.indexOf('r') >= 0) { w = s.w + dx; }
      if (m.indexOf('l') >= 0) { w = s.w - dx; }
      if (m.indexOf('b') >= 0) { h = s.h + dy; }
      w = Math.max(Math.min(MIN_W, vw), Math.min(w, vw));
      h = Math.max(Math.min(MIN_H, vh), Math.min(h, vh));
      if (m.indexOf('l') >= 0) {
        l = Math.max(0, (s.l + s.w) - w);            // right edge stays anchored
        if (l + w > vw) { w = vw - l; }
      }
      const p = { l: l, t: s.t, w: w, h: h };
      resizing.live = p;
      drawer.style.left = p.l + 'px'; drawer.style.top = p.t + 'px';
      drawer.style.width = p.w + 'px'; drawer.style.height = p.h + 'px';
      noteActivity();
    });
    const endResize = () => {
      if (!resizing) { return; }
      if (resizing.live) { savePos(resizing.live); }
      placeMoon();
      resizing = null;
      drawer.classList.remove('chat-resizing');
      noteActivity();
    };
    handle.addEventListener('pointerup', endResize);
    handle.addEventListener('pointercancel', endResize);
    drawer.appendChild(handle);
  });

  window.addEventListener('resize', () => {
    if (fab && fab.style.left) { const r = fab.getBoundingClientRect(); placeFab(r.left, r.top); }
    if (!isOpen()) { return; }
    if (mqMobile.matches) {
      drawer.classList.remove('chat-free'); drawer.classList.remove('chat-circle');
      drawer.style.left = drawer.style.top = drawer.style.width = drawer.style.height = '';
      return;
    }
    if (drawer.classList.contains('chat-free')) { applyPos(currentPos()); applyCircle(); }
  });

  // -- the floating circle ------------------------------------------------------
  function syncFab() { if (fab) { fab.classList.toggle('hidden', isOpen()); } }
  // The circle itself is free-style: drag it to any spot on the register
  // and the spot is remembered on this device. A press that travels past
  // TAP_SLOP px is a drag (and never opens); a press that stays put is a
  // tap and opens the chat, exactly as before.
  const TAP_SLOP = 7;
  let fabDrag = null, fabMoved = false;
  function clampFab(l, t) {
    const w = (fab && fab.offsetWidth) || 56, h = (fab && fab.offsetHeight) || 56;
    return { l: Math.max(0, Math.min(l, Math.max(0, window.innerWidth - w))),
             t: Math.max(0, Math.min(t, Math.max(0, window.innerHeight - h))) };
  }
  function placeFab(l, t) {
    const p = clampFab(l, t);
    fab.style.left = p.l + 'px'; fab.style.top = p.t + 'px';
    fab.style.right = 'auto'; fab.style.bottom = 'auto';
    return p;
  }
  function applySavedFab() {
    try {
      const p = JSON.parse(localStorage.getItem(FAB_KEY));
      if (p && isFinite(p.l) && isFinite(p.t)) { placeFab(p.l, p.t); }
    } catch (e) { /* no saved spot yet — the default corner stands */ }
  }
  if (fab) {
    fab.addEventListener('pointerdown', (e) => {
      if (e.button !== undefined && e.button !== 0) { return; }
      const r = fab.getBoundingClientRect();
      fabDrag = { x: e.clientX, y: e.clientY, l: r.left, t: r.top, moved: false };
      try { fab.setPointerCapture(e.pointerId); } catch (err) { /* a plain tap still works */ }
    });
    fab.addEventListener('pointermove', (e) => {
      if (!fabDrag) { return; }
      const dx = e.clientX - fabDrag.x, dy = e.clientY - fabDrag.y;
      if (!fabDrag.moved && Math.sqrt(dx * dx + dy * dy) < TAP_SLOP) { return; }
      if (!fabDrag.moved) { fabDrag.moved = true; fab.classList.add('gol-fab-dragging'); }
      placeFab(fabDrag.l + dx, fabDrag.t + dy);
    });
    const endFabDrag = () => {
      if (!fabDrag) { return; }
      if (fabDrag.moved) {
        // Swallow the click this press is about to fire: it was a drag.
        fabMoved = true;
        setTimeout(() => { fabMoved = false; }, 150);
        const r = fab.getBoundingClientRect();
        try { localStorage.setItem(FAB_KEY, JSON.stringify({ l: Math.round(r.left), t: Math.round(r.top) })); } catch (err) { /* private mode */ }
      }
      fabDrag = null;
      fab.classList.remove('gol-fab-dragging');
    };
    fab.addEventListener('pointerup', endFabDrag);
    fab.addEventListener('pointercancel', endFabDrag);
    fab.addEventListener('click', () => {
      if (fabMoved) { fabMoved = false; return; }
      noteActivity(); openChatToGol();
    });
    applySavedFab();
  }
  let wasOpen = isOpen();
  new MutationObserver(() => {
    const open = isOpen();
    if (open && !wasOpen) { noteActivity(); applySavedPos(); applyCircle(); if (window.__golPlaceMoon) { setTimeout(window.__golPlaceMoon, 380); setTimeout(window.__golPlaceMoon, 950); } }
    wasOpen = open; syncFab();
  }).observe(drawer, { attributes: true, attributeFilter: ['class'] });

  // -- idle fold-back -------------------------------------------------------------
  const body = $('chatBody');
  if (body) { new MutationObserver(noteActivity).observe(body, { childList: true }); }
  ['pointerdown', 'keydown', 'input', 'wheel', 'touchstart'].forEach((ev) =>
    drawer.addEventListener(ev, noteActivity, true));
  function stagedWaiting() {
    return !!document.querySelector('#chatBody .msg.card:not(.sc-cancelled) .sc-confirm:not([disabled])');
  }
  function pinWaiting() { const p = $('pinPad'); return !!(p && !p.classList.contains('hidden')); }
  function voiceWaiting() { const v = $('voicePill'); return !!(v && !v.classList.contains('hidden')); }
  setInterval(() => {
    if (!isOpen() || dragging || resizing) { return; }
    if (typeof callActive !== 'undefined' && callActive) { noteActivity(); return; }
    if (stagedWaiting() || pinWaiting() || voiceWaiting()) { noteActivity(); return; }
    if (Date.now() - lastActivity >= IDLE_MS) { foldPanel(); }
  }, 4000);

  syncFab();
  applyCircle();
  if (isOpen()) { applySavedPos(); applyCircle(); }
})();
