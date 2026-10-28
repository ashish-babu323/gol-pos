// Gol POS — scan-by-phone capture page.
// The phone is the register's borrowed camera: this page holds ONLY the
// pairing token (from the ?t= link, or traded for the 6-char code the
// register shows) and uploads through the ordinary photo lane
// (../server/photo.php) with pair_token riding along. The server binds
// the photo to the register's session and answers there too — this
// page just shows the same reply so the person holding the phone sees
// that it landed.
'use strict';
const $ = (id) => document.getElementById(id);
let token = new URLSearchParams(location.search).get('t') || '';
let mode = 'bill';

function say(text) { $('reply').textContent = text || ''; }

function ready() {
  $('codeRow').classList.add('hidden');
  $('main').classList.remove('hidden');
}

async function connectWithCode() {
  const code = $('codeInput').value.trim().toUpperCase();
  if (!code) { say('Type the 6-letter code the register is showing.'); return; }
  say('Connecting…');
  try {
    const r = await fetch('../server/pair.php?resolve=' + encodeURIComponent(code));
    const j = await r.json();
    if (!r.ok || !j.token) { say(j.error || 'That code did not connect.'); return; }
    token = j.token;
    say('Connected — take the photo.');
    ready();
  } catch (e) { say('Could not reach the register. Check the connection and try again.'); }
}

async function upload(file) {
  if (!file) { return; }
  say('Sending to the register…');
  const fd = new FormData();
  fd.append('photo', file);
  fd.append('pair_token', token);
  if (mode === 'bill') { fd.append('mode', 'bill'); }
  try {
    const r = await fetch('../server/photo.php', { method: 'POST', body: fd });
    const j = await r.json();
    say((j.reply || 'Sent.') + (j.card ? '\n\nA card is staged on the register — the owner confirms it there with "gol".' : ''));
  } catch (e) { say('The photo could not be sent. Check the connection and try again.'); }
}

$('modeBill').addEventListener('click', () => {
  mode = 'bill';
  $('modeBill').classList.add('on'); $('modePhoto').classList.remove('on');
});
$('modePhoto').addEventListener('click', () => {
  mode = 'photo';
  $('modePhoto').classList.add('on'); $('modeBill').classList.remove('on');
});
$('file').addEventListener('change', (e) => { upload(e.target.files[0]); e.target.value = ''; });
$('codeBtn').addEventListener('click', connectWithCode);

if (token) { ready(); } else { $('codeRow').classList.remove('hidden'); }
