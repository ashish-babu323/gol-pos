// Gol POS — employee invite page.
// The link carries a one-time token (?t=). Lookup names the invitee; the
// employee sets their own 4–6 digit PIN; the server flips the record to
// active and issues a device token. The token + email are kept in
// localStorage on THIS device only and ride along with register chat calls,
// so the device is recognised as that employee (cashier domain — an
// employee PIN never opens the admin domain).
'use strict';
const INVITE = '../server/invite.php';
const $ = (id) => document.getElementById(id);

const token = new URLSearchParams(location.search).get('t') || '';

async function post(body) {
  const r = await fetch(INVITE, {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  return { status: r.status, data: await r.json().catch(() => ({})) };
}

async function init() {
  if (!token) {
    $('invTitle').textContent = 'This link is missing its key';
    $('invSub').textContent = 'Open the exact link from your invite email — it carries a one-time key.';
    return;
  }
  const { status, data } = await post({ action: 'lookup', token });
  if (status !== 200 || !data.ok) {
    $('invTitle').textContent = 'This invite link is not valid any more';
    $('invSub').textContent = data.error || 'Ask the owner to send a fresh invite from the Gol chat.';
    return;
  }
  if (data.status === 'active') {
    $('invTitle').textContent = 'You are already set up';
    $('invSub').textContent = 'This device (or your first one) already holds your employee PIN. Just open the register.';
    $('openRegister').classList.remove('hidden');
    return;
  }
  $('invTitle').textContent = 'Welcome to ' + data.shop + (data.name ? ', ' + data.name : '');
  $('invSub').textContent = 'You were invited as an employee (' + data.email + '). '
    + 'Choose the PIN you will use at the register — Gol never sees it again after this.';
  $('pinForm').classList.remove('hidden');
}

$('pinForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const pin = $('pin1').value.trim();
  if (!/^\d{4,6}$/.test(pin)) { $('invSub').textContent = 'The PIN must be 4–6 digits.'; return; }
  if (pin !== $('pin2').value.trim()) { $('invSub').textContent = "Those two PINs don't match — try again."; return; }
  const { status, data } = await post({ action: 'setpin', token, pin });
  if (status !== 200 || !data.ok) {
    $('invSub').textContent = data.error || 'Something went wrong — try again.';
    return;
  }
  try {
    localStorage.setItem('golpos_employee', JSON.stringify({ email: data.email, token: data.emp_token }));
  } catch (err) { /* private mode: the register will simply not recognise the device */ }
  $('pinForm').classList.add('hidden');
  $('invTitle').textContent = 'All set, ' + (data.email || 'employee');
  $('invSub').classList.add('hidden');
  $('invDone').classList.remove('hidden');
  $('openRegister').classList.remove('hidden');
});

init();
