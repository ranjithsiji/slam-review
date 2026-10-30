// Public check-in pages: tiny, no framework.
import '../theme/index.js';
import { initThemeToggle } from '../shared/theme-toggle.js';
import { initSiteMenu } from '../shared/site-menu.js';

initThemeToggle();
initSiteMenu();
const input = document.querySelector('[data-badge-input]');
if (input) {
  // Letters first (text keyboard), then digits: switch the keyboard after two letters.
  const sync = () => {
    input.value = input.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 7);
    input.inputMode = input.value.length >= 2 ? 'numeric' : 'text';
  };
  input.addEventListener('input', sync);
  sync();
}
document.querySelectorAll('form[data-once]').forEach((f) => f.addEventListener('submit', () => {
  f.querySelectorAll('button[type="submit"]').forEach((b) => { b.disabled = true; b.dataset.label = b.textContent; b.textContent = 'Checking in…'; });
}));

// Scan the badge QR with the camera: the QR holds {site}/in/{ID} (or the plain ID). Only the ID is taken from it.
const scanStart = document.querySelector('[data-scan-start]');
if (scanStart) {
  const box = document.querySelector('[data-scan-box]');
  const hint = document.querySelector('[data-scan-hint]');
  const errBox = document.querySelector('[data-scan-error]');
  const form = scanStart.closest('form');
  let scanner = null;
  const fail = (msg) => { errBox.textContent = msg; errBox.hidden = false; };
  async function stop() {
    try { await scanner?.stop(); scanner?.clear(); } catch { /* already stopped */ }
    scanner = null;
    box.hidden = true;
    scanStart.hidden = false;
  }
  /** The badge ID inside a scanned text, or null (a QR that is not one of ours is ignored). */
  const badgeFrom = (text) => {
    const t = String(text).trim();
    const m = /^[A-Za-z]{2}\d{5}$/.test(t) ? t : (t.match(/\/in\/([A-Za-z]{2}\d{5})\/?(?:[?#].*)?$/) || [])[1];
    return m ? m.toUpperCase() : null;
  };
  scanStart.addEventListener('click', async () => {
    errBox.hidden = true;
    hint.textContent = 'Starting the camera… allow access if your phone asks.';
    if (!navigator.mediaDevices?.getUserMedia) {
      fail('Camera scanning needs a secure (https) connection. Type the ID above instead.');
      return;
    }
    scanStart.hidden = true;
    box.hidden = false;
    try {
      const { Html5Qrcode } = await import('html5-qrcode');
      scanner = new Html5Qrcode('qr-reader', { verbose: false });
      hint.textContent = 'Starting the camera… allow access if your phone asks.';
      await scanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 240, height: 240 } }, async (text) => {
        const id = badgeFrom(text);
        if (!id) { hint.textContent = 'That QR code is not a Science Slam badge. Try the one on your badge.'; return; }
        if (navigator.vibrate) navigator.vibrate(60);
        document.querySelector('#badge_id').value = id;
        hint.textContent = `Found ${id}…`;
        await stop();
        form.requestSubmit();
      });
      hint.textContent = 'Point the camera at the QR code on your badge.';
    } catch (e) {
      await stop();
      fail(/permission|denied|notallowed/i.test(String(e)) ? 'Camera permission was refused. Allow the camera for this site, or type the ID above.' : 'The camera could not be started. Type the ID above instead.');
    }
  });
  document.querySelector('[data-scan-stop]')?.addEventListener('click', stop);
}

// After Gate Close: the waiting screen polls until a volunteer has checked this person in, then moves on to the review screen.
const wait = document.querySelector('[data-late-wait]');
if (wait) {
  const msg = wait.querySelector('[data-late-message]');
  const title = wait.querySelector('[data-late-title]');
  let timer = null;
  const say = (html, cls = 'alert-info') => { msg.className = `alert ${cls} text-start mb-0`; msg.innerHTML = html; };
  const poll = async () => {
    clearTimeout(timer);
    if (!document.hidden) {
      try {
        const res = await fetch(wait.dataset.lateWait, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (res.status === 429) { timer = setTimeout(poll, 6000); return; }
        const d = await res.json();
        if (d.status === 'approved') { title.textContent = 'You are checked in'; say('<strong>Checked in.</strong> Opening your review screen…', 'alert-success'); window.location.href = d.redirect || wait.dataset.lateReview; return; }
        if (['expired', 'cancelled', 'unknown', 'none'].includes(d.status)) {
          title.textContent = 'Request ended';
          say(`This request has ended. <a href="${wait.dataset.lateRestart}">Scan your badge again</a>.`, 'alert-warning');
          return;
        }
      } catch { /* keep waiting; the connection may be flaky */ }
    }
    timer = setTimeout(poll, 2500 + Math.random() * 1000);
  };
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
  timer = setTimeout(poll, 1500);
}
