// Gol POS — employee invite page.
// The link carries a one-time token (?t=). Lookup names the invitee;
// Activate accepts the invite and the server issues a device token.
// PIN OWNERSHIP (TASKS 231, Nov 2): the OWNER sets every PIN — his own
// and each employee's — and hands it out. This page never asks for,
// accepts or changes a PIN; activation writes none. The token + email
// are kept in localStorage on THIS device only and ride along with
// register chat calls, so the device is recognised as that employee
// (cashier domain — an employee PIN never opens the admin domain).
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
    $('invSub').textContent = 'This invite was already accepted. Just open the register and use the PIN your owner gave you.';
    $('openRegister').classList.remove('hidden');
    return;
  }
  $('invTitle').textContent = 'Welcome to ' + data.shop + (data.name ? ', ' + data.name : '');
  $('invSub').textContent = 'You were invited as an employee (' + data.email + ').';
  $('activateBox').classList.remove('hidden');
}

$('activateBtn').addEventListener('click', async () => {
  const btn = $('activateBtn');
  btn.disabled = true;
  const { status, data } = await post({ action: 'activate', token });
  if (status !== 200 || !data.ok) {
    btn.disabled = false;
    $('invSub').textContent = data.error || 'Something went wrong — try again.';
    return;
  }
  try {
    localStorage.setItem('golpos_employee', JSON.stringify({ email: data.email, token: data.emp_token }));
  } catch (err) { /* private mode: the register will simply not recognise the device */ }
  $('activateBox').classList.add('hidden');
  $('invTitle').textContent = 'All set' + (data.email ? ', ' + data.email : '');
  $('invSub').classList.add('hidden');
  $('invDone').classList.remove('hidden');
  if (!data.has_pin) {
    $('invDone').textContent = 'You are set — this device now rings sales as you at the register. '
      + 'Your owner has not given you a PIN yet: ask them for it — they set it and hand it to you.';
  }
  $('openRegister').classList.remove('hidden');
});

init();
