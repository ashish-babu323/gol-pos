// Gol POS — Receipt Loyalty customer page.
// Join once (name / phone / email), then send receipt PHOTOS: the server
// vision-reads each receipt, dedupes by receipt number, and answers with
// the running total + rank. Phone is the master key; it is kept in
// localStorage on this device only, so joining is truly a one-time form.
'use strict';
const LOYAL = '../server/loyalty.php';
const $ = (id) => document.getElementById(id);
const money = (n) => '$' + Number(n || 0).toFixed(2);
const esc = (s) => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

let myPhone = '';
try { myPhone = localStorage.getItem('golpos_loyalty_phone') || ''; } catch (e) { /* private mode */ }

function chatMsg(text, who) {
  const d = document.createElement('div');
  d.className = 'msg ' + who; d.textContent = text;
  $('loyChat').appendChild(d);
  $('loyChat').scrollTop = 1e6;
  return d;
}
function setStanding(s) {
  if (!s) { return; }
  $('stTotal').textContent = money(s.total);
  $('stCount').textContent = String(s.receipts);
  $('stRank').textContent = '#' + s.rank + ' of ' + s.of;
  const cr = $('stCredit');
  if (cr) { cr.textContent = money(s.credit || 0); }
}
async function loadBoard() {
  try {
    const r = await (await fetch(LOYAL + '?leaderboard')).json();
    const board = Array.isArray(r.board) ? r.board : [];
    $('loyBoard').innerHTML = board.length ? board.map(row =>
      `<div class="rep-row${row.phone === myPhone ? ' loy-me' : ''}"><span>#${row.rank} ${esc(row.name)}</span>` +
      `<b>${money(row.total)}</b><span>${row.receipts} receipt${row.receipts === 1 ? '' : 's'}</span></div>`).join('')
      : '<p class="empty">No receipts yet — be the first on the board.</p>';
  } catch (e) { /* the board refreshes on the next action; the page never breaks */ }
}
function showMember() {
  $('joinCard').classList.add('hidden');
  $('memberCard').classList.remove('hidden');
}
async function refreshStanding() {
  if (!myPhone) { return; }
  try {
    const r = await (await fetch(LOYAL, { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'standing', phone: myPhone }) })).json();
    if (r.standing) { setStanding(r.standing); showMember(); return true; }
  } catch (e) { /* fall through to the join form */ }
  return false;
}

$('joinForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const payload = { action: 'register',
    name: $('jName').value.trim(), phone: $('jPhone').value.trim(), email: $('jEmail').value.trim() };
  try {
    const r = await (await fetch(LOYAL, { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload) })).json();
    if (r.invalid) { chatMsg(r.reply, 'gol'); showMember(); return; }
    myPhone = (r.customer && r.customer.phone) || payload.phone;
    try { localStorage.setItem('golpos_loyalty_phone', myPhone); } catch (err) { /* private mode */ }
    showMember();
    if (r.reply) { chatMsg(r.reply, 'gol'); }
    if (r.standing) { setStanding(r.standing); }
    loadBoard();
  } catch (err) {
    showMember();
    chatMsg('The loyalty desk did not answer just now — try again in a moment.', 'gol');
  }
});

$('receiptInput').addEventListener('change', async (e) => {
  const f = e.target.files[0];
  e.target.value = '';
  if (!f || !myPhone) { return; }
  chatMsg('📷 Receipt photo sent', 'me');
  const wait = chatMsg('Reading your receipt…', 'gol');
  const fd = new FormData();
  fd.append('phone', myPhone);
  fd.append('receipt', f);
  try {
    const r = await (await fetch(LOYAL, { method: 'POST', body: fd })).json();
    wait.remove();
    if (r.reply) { chatMsg(r.reply, 'gol'); }
    if (r.standing) { setStanding(r.standing); }
    loadBoard();
  } catch (err) {
    wait.remove();
    chatMsg('That send did not reach Gol — check your connection and try the photo again.', 'gol');
  }
});

$('switchBtn').addEventListener('click', () => {
  try { localStorage.removeItem('golpos_loyalty_phone'); } catch (e) { /* private mode */ }
  myPhone = '';
  $('memberCard').classList.add('hidden');
  $('joinCard').classList.remove('hidden');
  loadBoard();
});

(async () => {
  loadBoard();
  if (myPhone && await refreshStanding()) {
    chatMsg('Welcome back! Send a photo of your next Sunrise Mart receipt and I\'ll add it to your total.', 'gol');
  }
})();
