// Progressive enhancements for server-rendered forms.
import { dialog, toast, esc } from './ui.js';
import { post, get } from './api.js';

export function initForms(root = document) {
  // <form data-confirm="Message" [data-reason="field_name"] [data-danger]>: confirm (and ask a reason) before submit.
  root.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', async (e) => {
      if (form.dataset.confirmed === '1') return;
      e.preventDefault();
      const reasonField = form.dataset.reason;
      const r = await dialog({
        title: form.dataset.title || 'Please confirm', message: form.dataset.confirm, danger: 'danger' in form.dataset,
        reason: !!reasonField, confirmText: form.dataset.ok || 'Confirm',
      });
      if (r === null) return;
      if (reasonField) {
        let input = form.querySelector(`input[name="${reasonField}"]`);
        if (!input) { input = document.createElement('input'); input.type = 'hidden'; input.name = reasonField; form.appendChild(input); }
        input.value = r;
      }
      form.dataset.confirmed = '1';
      form.requestSubmit ? form.requestSubmit() : form.submit();
    });
  });

  // <button data-api-post="/api/..." data-confirm="..." data-reason data-reload data-form="#id">: JSON POST action
  // (data-form adds that form's fields to the body).
  root.querySelectorAll('[data-api-post]').forEach((btn) => {
    const ownForm = btn.dataset.form ? document.querySelector(btn.dataset.form) : null;
    // Enter in the form runs the button instead of a page submit.
    ownForm?.addEventListener('submit', (ev) => { ev.preventDefault(); btn.click(); });
    btn.addEventListener('click', async () => {
      if (ownForm && !ownForm.reportValidity()) return;
      let reason = '';
      if (btn.dataset.confirm !== undefined || btn.dataset.reason !== undefined) {
        const r = await dialog({ title: btn.dataset.title || btn.textContent.trim(), message: btn.dataset.confirm || '', danger: 'danger' in btn.dataset, reason: btn.dataset.reason !== undefined, input: btn.dataset.reasonDefault ? { value: btn.dataset.reasonDefault } : null });
        if (r === null) return;
        reason = r;
      }
      try {
        const extra = btn.dataset.body ? JSON.parse(btn.dataset.body) : {};
        const fields = ownForm ? Object.fromEntries(new FormData(ownForm).entries()) : {};
        const res = await post(btn.dataset.apiPost, { reason, ...fields, ...extra });
        toast(btn.dataset.success || res?.message || 'Done.');
        if ('reload' in btn.dataset) setTimeout(() => window.location.reload(), 600);
      } catch (e) {
        toast(e.message, 'error');
      }
    });
  });

  // <select data-nav-select>: choosing an option opens its URL (slam switcher).
  root.querySelectorAll('select[data-nav-select]').forEach((sel) => {
    sel.addEventListener('change', () => { if (sel.value) window.location.href = sel.value; });
  });

  // <select data-toggle-target="#id" data-toggle-value="x">: show #id only while that option is chosen.
  root.querySelectorAll('select[data-toggle-target]').forEach((sel) => {
    const target = root.querySelector(sel.dataset.toggleTarget);
    const sync = () => { if (target) target.hidden = sel.value !== sel.dataset.toggleValue; };
    sel.addEventListener('change', sync);
    sync();
  });

  // <button data-copy="text">: copy to clipboard.
  root.querySelectorAll('[data-copy]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      try { await navigator.clipboard.writeText(btn.dataset.copy); toast('Copied.'); } catch { toast('Copy failed — select and copy manually.', 'error'); }
    });
  });

  // Character counters: <textarea data-maxlength="2000">.
  root.querySelectorAll('[data-maxlength]').forEach((el) => {
    const max = Number(el.dataset.maxlength);
    const hint = document.createElement('div');
    hint.className = 'form-hint text-end';
    el.after(hint);
    const upd = () => { const n = [...el.value].length; hint.textContent = `${n} / ${max}`; hint.classList.toggle('text-danger', n > max); };
    el.addEventListener('input', upd);
    upd();
  });

  // <select data-autosubmit>: submit its form on change (no inline handlers under the CSP).
  root.querySelectorAll('[data-autosubmit]').forEach((el) => el.addEventListener('change', () => el.form?.submit()));

  // QR screen: single-attendee search, preview, downloads and hand-picking for the badge PDF.
  const qrInput = root.querySelector('[data-qr-search]');
  if (qrInput) {
    const out = root.querySelector('[data-qr-results]');
    const pickedInput = root.querySelector('[data-qr-picked]');
    const pickedLabel = root.querySelector('[data-qr-picked-label]');
    const picked = new Map();
    const showPicked = () => {
      pickedInput.value = [...picked.keys()].join(',');
      pickedLabel.textContent = picked.size ? `Hand-picked: ${[...picked.values()].join(', ')}` : '';
    };
    let t = null;
    qrInput.addEventListener('input', () => {
      clearTimeout(t);
      t = setTimeout(async () => {
        const q = qrInput.value.trim();
        if (q.length < 2) { out.innerHTML = ''; return; }
        try {
          const res = await get(`${qrInput.dataset.qrSearch}&q=${encodeURIComponent(q)}`);
          out.innerHTML = res.data.length ? '' : '<div class="text-secondary p-2">No match.</div>';
          res.data.forEach((a) => {
            const row = document.createElement('div');
            row.className = 'list-group-item';
            row.innerHTML = `<div class="d-flex gap-3 align-items-center">
                <div class="qr-box" style="width:84px"><img src="/admin/qr/${a.id}.svg?inline=1" alt=""></div>
                <div class="flex-fill"><div class="fw-bold ml-text">${esc(a.name)}</div><div><code>${esc(a.badge_id)}</code> · ${esc(a.user_type)} · printed ${esc(a.badge_print_count)}×</div>
                <div class="btn-list mt-1"><a class="btn btn-sm" href="/admin/qr/${a.id}.svg">SVG</a><a class="btn btn-sm" href="/admin/qr/${a.id}.png">PNG</a>
                <button type="button" class="btn btn-sm" data-c>Copy link</button><button type="button" class="btn btn-sm" data-p>Pick</button></div></div></div>`;
            row.querySelector('[data-c]').onclick = () => navigator.clipboard?.writeText(a.url).then(() => toast('Copied.'));
            row.querySelector('[data-p]').onclick = () => { picked.set(a.id, a.name); showPicked(); const f = root.querySelector('#filter'); if (f) f.value = 'selected'; };
            out.appendChild(row);
          });
        } catch (e) { toast(e.message, 'error'); }
      }, 350);
    });
  }

  root.querySelectorAll('[data-print]').forEach((b) => b.addEventListener('click', () => window.print()));

  // Uppercase badge-ID inputs.
  root.querySelectorAll('[data-uppercase]').forEach((el) => el.addEventListener('input', () => {
    const p = el.selectionStart;
    el.value = el.value.toUpperCase().replace(/\s+/g, '');
    try { el.setSelectionRange(p, p); } catch {}
  }));
}

// Finalists screen: a running count of the ticks, and "Tick the top N by marks" when marks exist.
document.querySelectorAll('[data-finalists]').forEach((form) => {
  const boxes = [...form.querySelectorAll('input[name="finalists[]"]')];
  const out = form.querySelector('[data-pick-count]');
  const count = () => { if (out) out.textContent = `${boxes.filter((b) => b.checked).length} chosen`; };
  form.querySelector('[data-pick-top]')?.addEventListener('click', (e) => {
    const n = Number(form.querySelector('#pick-n')?.value || 0);
    const ids = JSON.parse(e.currentTarget.dataset.pickTop).slice(0, n).map(String);
    boxes.forEach((b) => { b.checked = ids.includes(b.value); });
    count();
  });
  boxes.forEach((b) => b.addEventListener('change', count));
  count();
});

// Finalists poster: drawn on a canvas in the browser (so Malayalam names are shaped correctly) and saved as PNG.
document.querySelectorAll('[data-poster]').forEach((box) => {
  const cfg = JSON.parse(box.dataset.poster);
  const canvas = box.querySelector('[data-poster-canvas]');
  const sizeSel = box.querySelector('[data-poster-size]');
  const titleIn = box.querySelector('[data-poster-title]');
  const whoSel = box.querySelector('[data-poster-who]');
  const styleSel = box.querySelector('[data-poster-style]');
  const lightBox = box.querySelector('[data-poster-light]');
  const val = (k) => box.querySelector(`[data-poster-${k}]`)?.value || '';
  const load = (src) => new Promise((ok) => { if (!src) return ok(null); const i = new Image(); i.onload = () => ok(i); i.onerror = () => ok(null); i.src = src; });
  const imgs = Promise.all([load(cfg.logo), ...cfg.people.map((p) => load(p.photo))]);
  const extras = Promise.all([load(cfg.logoLight), load(cfg.partners), load(cfg.logoMain || cfg.logo)]);
  const FONT = '"Poppins", "Noto Sans Malayalam", "Noto Sans", system-ui, sans-serif';
  const wrap = (ctx, text, max, maxLines = 2) => {
    const words = String(text || '').split(/\s+/).filter(Boolean); const lines = []; let line = '';
    for (const w of words) { const t = line ? `${line} ${w}` : w; if (ctx.measureText(t).width > max && line) { lines.push(line); line = w; } else line = t; }
    if (line) lines.push(line);
    return lines.slice(0, maxLines);
  };
  // The largest font size (down to a floor) at which the text fits in maxLines; returns the size and the lines.
  const fit = (ctx, text, max, maxLines, start, floor, weight) => {
    for (let fs = start; fs >= floor; fs -= 1) {
      ctx.font = `${weight} ${fs}px ${FONT}`;
      const words = String(text || '').split(/\s+/).filter(Boolean);
      const all = wrap(ctx, text, max, 99);
      if (all.length <= maxLines && !words.some((w) => ctx.measureText(w).width > max)) return { fs, lines: all };
    }
    ctx.font = `${weight} ${floor}px ${FONT}`;
    return { fs: floor, lines: wrap(ctx, text, max, maxLines) };
  };
  // Photo drawn to cover a box, keeping the upper part (where the face usually is).
  const cover = (ctx, img, x, y, w, h) => {
    const s = Math.max(w / img.naturalWidth, h / img.naturalHeight);
    const iw = img.naturalWidth * s; const ih = img.naturalHeight * s;
    ctx.drawImage(img, x - (iw - w) / 2, y - Math.max(0, (ih - h) * 0.2), iw, ih);
  };
  const rounded = (ctx, x, y, w, h, r) => { ctx.beginPath(); ctx.roundRect(x, y, w, h, r); };
  // Light card: one person, white page, date corner, Malayalam heading, photo card, logo and partners.
  async function drawLight(p, photo) {
    const [logoL, partners] = await extras;
    const [W, H] = sizeSel.value.split('x').map(Number);
    canvas.width = W; canvas.height = H;
    const ctx = canvas.getContext('2d'); const m = Math.min(W, H * 0.85) / 1080;
    ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, W, H);
    ctx.textAlign = 'left'; ctx.textBaseline = 'alphabetic';
    // Pale corner with the date and the regional slam.
    ctx.fillStyle = '#eef2c4'; ctx.beginPath(); ctx.ellipse(-0.02 * W, -0.06 * H, 0.52 * W, 0.31 * H, 0, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = '#6e5a12'; ctx.font = `800 ${50 * m}px ${FONT}`; ctx.fillText(val('date'), 0.05 * W, 0.075 * H);
    ctx.fillStyle = '#1f1f1f'; ctx.font = `700 ${30 * m}px ${FONT}`;
    wrap(ctx, val('region'), 0.31 * W, 3).forEach((l, k) => ctx.fillText(l, 0.05 * W, 0.075 * H + (52 + k * 40) * m));
    // Heading, top right.
    const hd = fit(ctx, val('heading'), 0.55 * W, 3, Math.round(58 * m), Math.round(30 * m), 800);
    ctx.fillStyle = '#7b1a1a'; ctx.font = `800 ${hd.fs}px ${FONT}`;
    hd.lines.forEach((l, k) => ctx.fillText(l, 0.40 * W, 0.15 * H + k * hd.fs * 1.3));
    // Photo card on the right; the bottom-left of it is cut away in white for the name.
    const px = 0.45 * W; const py = 0.29 * H; const pw = 0.50 * W; const ph = 0.51 * H; const cut = py + ph * 0.84;
    ctx.save(); rounded(ctx, px, py, pw, ph, 30 * m); ctx.clip();
    if (photo) cover(ctx, photo, px, py, pw, ph);
    else {
      ctx.fillStyle = '#d9dfc0'; ctx.fillRect(px, py, pw, ph);
      ctx.fillStyle = '#6e5a12'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.font = `700 ${pw * 0.3}px ${FONT}`;
      ctx.fillText(p.name.split(/\s+/).map((w) => w[0]).slice(0, 2).join(''), px + pw / 2, py + ph * 0.42);
      ctx.textAlign = 'left'; ctx.textBaseline = 'alphabetic';
    }
    ctx.restore();
    ctx.fillStyle = '#ffffff'; rounded(ctx, px + 14 * m, cut, pw + 40 * m, H, [60 * m, 0, 0, 0]); ctx.fill();
    // Name and institution in the cut-away.
    const tx = px + 0.09 * W; const tw = px + pw - tx;
    const nm = fit(ctx, p.name, tw, 2, Math.round(40 * m), Math.round(24 * m), 800);
    ctx.fillStyle = '#1f1f1f'; ctx.font = `800 ${nm.fs}px ${FONT}`;
    let y = cut + 0.045 * H;
    nm.lines.forEach((l) => { ctx.fillText(l, tx, y); y += nm.fs * 1.2; });
    if (p.designation) {
      const inst = fit(ctx, p.designation, tw, 3, Math.round(24 * m), Math.round(16 * m), 700);
      ctx.fillStyle = '#4f7a28'; ctx.font = `700 ${inst.fs}px ${FONT}`;
      inst.lines.forEach((l) => { ctx.fillText(l, tx, y); y += inst.fs * 1.3; });
    }
    // Logo bottom left, tagline under it.
    if (logoL) {
      const lw = 0.36 * W; const lh = lw * (logoL.naturalHeight / logoL.naturalWidth || 0.66);
      const ly = Math.min(0.88 * H - 60 * m, cut + 0.08 * H) - lh;
      ctx.drawImage(logoL, 0.05 * W, ly, lw, lh);
      ctx.fillStyle = '#1f1f1f'; ctx.font = `700 ${32 * m}px ${FONT}`; ctx.textAlign = 'center';
      ctx.fillText(val('tagline'), 0.05 * W + lw / 2, ly + lh + 42 * m);
      ctx.fillStyle = '#7b1a1a'; ctx.font = `700 ${30 * m}px ${FONT}`;
      ctx.fillText('scienceslam.in', 0.05 * W + lw / 2, ly + lh + 92 * m); ctx.textAlign = 'left';
    }
    // Rule and the partner logos in one row (the strip has two rows; they are placed side by side).
    ctx.strokeStyle = '#b59a6a'; ctx.lineWidth = Math.max(1.5, 2 * m);
    ctx.beginPath(); ctx.moveTo(0.05 * W, 0.905 * H); ctx.lineTo(0.95 * W, 0.905 * H); ctx.stroke();
    if (partners) {
      const k = partners.naturalWidth / 1200;
      const rows = [[40, 30, 1120, 140], [60, 205, 1115, 140]].map(([x, yy, w, h]) => [x * k, yy * k, w * k, h * k]);
      const total = rows.reduce((a, r) => a + r[2], 0) + 60 * k;
      const s = Math.min((0.9 * W) / total, (0.07 * H) / (140 * k));
      let x = (W - total * s) / 2; const yy = 0.95 * H - (70 * k * s);
      rows.forEach((r) => { ctx.drawImage(partners, r[0], r[1], r[2], r[3], x, yy, r[2] * s, r[3] * s); x += (r[2] + 60 * k) * s; });
    }
  }
  const syncUi = () => {
    const single = Number(whoSel?.value ?? -1) >= 0;
    // Both styles for one person and for everyone; the light card's own fields are for one person only.
    const light = single && styleSel?.value === 'light';
    if (lightBox) lightBox.hidden = !light;
    titleIn.closest('[data-poster-title-wrap]').hidden = light;
  };
  // who: -1 draws everyone on one poster; an index draws that one person on their own poster.
  async function draw(who = Number(whoSel?.value ?? -1)) {
    if (who >= 0 && styleSel?.value === 'light') { const all = await imgs; await document.fonts?.ready; return drawLight(cfg.people[who], all[1 + who]); }
    const [, ...allPhotos] = await imgs;
    const [logoLight, partners, logoDark] = await extras;
    // Light: the light card's colours (white page, pale corner, maroon, green) with the site's orange; dark: navy and gold.
    const L = who < 0 && styleSel?.value === 'light';
    const C = L
      ? { ink: '#1f1f1f', accent: '#ff9a00', ribbon: '#7b1a1a', ribbonText: '#ffffff', slam: '#7b1a1a', date: '#1f1f1f', venue: '#555555', inst: '#4f7a28',
          disc: '#d9dfc0', discText: '#6e5a12', halo: 'rgba(123,26,26,.35)', dots: 'rgba(123,26,26,.06)', rings: 'rgba(255,154,0,.20)', site: '#7b1a1a' }
      : { ink: '#ffffff', accent: '#ffb400', ribbon: '#ffb400', ribbonText: '#0c2a40', slam: '#ffb400', date: '#ffffff', venue: '#c9d6e2', inst: '#d5e0ea',
          disc: '#2b5272', discText: '#ffffff', halo: 'rgba(255,255,255,.45)', dots: 'rgba(255,255,255,.055)', rings: 'rgba(255,180,0,.16)', site: '#ffb400' };
    const logo = L ? (logoLight || logoDark) : logoDark;
    const people = who < 0 ? cfg.people : [cfg.people[who]];
    const photos = who < 0 ? allPhotos : [allPhotos[who]];
    const single = people.length === 1;
    await document.fonts?.ready;
    const [W, H] = sizeSel.value.split('x').map(Number);
    canvas.width = W; canvas.height = H;
    const ctx = canvas.getContext('2d'); const u = Math.min(W, H) / 1080; const wide = W > H * 1.2;
    // A story or status (9:16) is covered by the app's own bars at the top and bottom: keep clear of them.
    const safe = H / W > 1.6 ? 0.085 * H : 0;
    ctx.textBaseline = 'alphabetic';
    const GOLD = C.accent;
    if (L) {
      ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, W, H);
      ctx.fillStyle = '#eef2c4'; ctx.beginPath(); ctx.ellipse(-0.02 * W, -0.04 * H, 0.50 * W, 0.24 * H, 0, 0, Math.PI * 2); ctx.fill();
    } else {
      const bg = ctx.createLinearGradient(0, 0, W, H); bg.addColorStop(0, '#15405c'); bg.addColorStop(1, '#0a2233');
      ctx.fillStyle = bg; ctx.fillRect(0, 0, W, H);
    }
    // Dot grid and two soft rings, like the home page hero.
    ctx.fillStyle = C.dots;
    for (let y = 18 * u; y < H; y += 36 * u) for (let x = 18 * u; x < W; x += 36 * u) { ctx.beginPath(); ctx.arc(x, y, 2.2 * u, 0, Math.PI * 2); ctx.fill(); }
    ctx.strokeStyle = C.rings; ctx.lineWidth = 26 * u;
    ctx.beginPath(); ctx.arc(W + 40 * u, 60 * u, 300 * u, 0, Math.PI * 2); ctx.stroke();
    ctx.beginPath(); ctx.arc(-60 * u, H * 0.62, 220 * u, 0, Math.PI * 2); ctx.stroke();

    // Bottom band (white): every organiser and partner logo.
    const pad = 54 * u; let bandH = safe;
    if (partners) {
      const k = partners.naturalWidth / 1200;
      const rows = [[30, 25, 1140, 150], [60, 200, 1115, 150]].map((r) => r.map((v) => v * k));
      const gap = 50 * k; const inner = W - 2 * pad;
      // One slim row, so the people get the room.
      const s = Math.min(inner / (rows[0][2] + rows[1][2] + gap), (0.075 * H) / rows[0][3]);
      bandH = rows[0][3] * s + 36 * u + safe;
      let x = (W - (rows[0][2] + rows[1][2] + gap) * s) / 2;
      const place = rows.map((r) => { const at = [x, H - bandH + 18 * u, r[2] * s, r[3] * s]; x += (r[2] + gap) * s; return at; });
      ctx.fillStyle = '#ffffff'; ctx.fillRect(0, H - bandH, W, bandH);
      ctx.fillStyle = GOLD; ctx.fillRect(0, H - bandH - 8 * u, W, 8 * u);
      rows.forEach((r, i) => ctx.drawImage(partners, r[0], r[1], r[2], r[3], ...place[i]));
    }

    // Top: the Kerala Science Slam logo on the left; slam, date and venue on the right.
    const y0 = pad + safe; let top = y0;
    if (logo) {
      const lh = (wide ? 170 : 190) * u; const lw = lh * (logo.naturalWidth / logo.naturalHeight || 1.47);
      ctx.drawImage(logo, pad, y0, lw, lh); top = y0 + lh;
      const rw = W - 2 * pad - lw - 40 * u;
      ctx.textAlign = 'right';
      const sl = fit(ctx, cfg.slam, rw, 2, Math.round(40 * u), Math.round(24 * u), 800);
      ctx.fillStyle = C.slam; ctx.font = `800 ${sl.fs}px ${FONT}`;
      let ry = y0 + sl.fs * 1.1;
      sl.lines.forEach((l) => { ctx.fillText(l, W - pad, ry); ry += sl.fs * 1.2; });
      ctx.fillStyle = C.date; ctx.font = `600 ${28 * u}px ${FONT}`;
      if (cfg.date) { ctx.fillText(cfg.date, W - pad, ry + 8 * u); ry += 40 * u; }
      if (cfg.venue) {
        const vn = fit(ctx, cfg.venue, rw, 2, Math.round(24 * u), Math.round(18 * u), 400);
        ctx.fillStyle = C.venue; ctx.font = `400 ${vn.fs}px ${FONT}`;
        vn.lines.forEach((l) => { ctx.fillText(l, W - pad, ry + 6 * u); ry += vn.fs * 1.25; });
      }
      top = Math.max(top, ry);
    }
    // Heading on a gold ribbon.
    ctx.textAlign = 'center';
    const hd = fit(ctx, titleIn.value || '', W - 2 * pad - 80 * u, 2, Math.round(60 * u), Math.round(32 * u), 800);
    const ribH = hd.lines.length * hd.fs * 1.25 + 30 * u; const ribY = top + 34 * u;
    if (hd.lines.length) {
      ctx.font = `800 ${hd.fs}px ${FONT}`;
      const ribW = Math.min(W - 2 * pad, Math.max(...hd.lines.map((l) => ctx.measureText(l).width)) + 90 * u);
      ctx.fillStyle = C.ribbon; rounded(ctx, (W - ribW) / 2, ribY, ribW, ribH, ribH / 2); ctx.fill();
      ctx.fillStyle = C.ribbonText;
      hd.lines.forEach((l, i) => ctx.fillText(l, W / 2, ribY + 15 * u + hd.fs * (0.95 + i * 1.25)));
    }
    // People: round photo with a gold ring, name below, institution smaller.
    const footH = bandH + 8 * u + 62 * u;
    const areaTop = ribY + (hd.lines.length ? ribH : 0) + 40 * u; const areaH = H - areaTop - footH; const areaW = W - 2 * pad;
    // The arrangement (several rows when that helps) that gives the biggest faces: a row is a photo plus the text under it.
    const n = people.length; let g = null;
    for (let cols = 1; cols <= n; cols++) {
      const rows = Math.ceil(n / cols); const rad = Math.min((areaW / cols) * 0.4, (areaH / rows) / 3.9);
      if (!g || rad > g.rad) g = { cols, rows, rad };
    }
    const rowH = areaH / g.rows; const r = g.rad;
    people.forEach((p, i) => {
      const row = Math.floor(i / g.cols); const inRow = Math.min(g.cols, n - row * g.cols);
      const colW = areaW / g.cols; const x0 = pad + (areaW - inRow * colW) / 2;
      const cx = x0 + (i - row * g.cols + 0.5) * colW; const cy = areaTop + row * rowH + r + 14 * u;
      ctx.save(); ctx.beginPath(); ctx.arc(cx, cy, r, 0, Math.PI * 2); ctx.closePath(); ctx.fillStyle = C.disc; ctx.fill(); ctx.clip();
      const ph = photos[i];
      if (ph) {
        // Cover the circle, keeping the upper part of the photo (where the face usually is).
        const s = Math.max((2 * r) / ph.naturalWidth, (2 * r) / ph.naturalHeight);
        const w = ph.naturalWidth * s; const h = ph.naturalHeight * s;
        ctx.drawImage(ph, cx - w / 2, cy - r - Math.max(0, (h - 2 * r) * 0.15), w, h);
      } else {
        ctx.fillStyle = C.discText; ctx.font = `700 ${r * 0.8}px ${FONT}`; ctx.textBaseline = 'middle';
        ctx.fillText(p.name.split(/\s+/).map((w) => w[0]).slice(0, 2).join(''), cx, cy); ctx.textBaseline = 'alphabetic';
      }
      ctx.restore();
      ctx.beginPath(); ctx.arc(cx, cy, r, 0, Math.PI * 2); ctx.lineWidth = Math.max(4 * u, r * 0.055); ctx.strokeStyle = GOLD; ctx.stroke();
      ctx.beginPath(); ctx.arc(cx, cy, r + Math.max(7 * u, r * 0.09), 0, Math.PI * 2); ctx.lineWidth = 2 * u; ctx.strokeStyle = C.halo; ctx.stroke();
      // Name, then the institution: smaller rather than cut off, and never below the row.
      const width = colW * 0.92; const room = rowH - (2 * r + 24 * u);
      const top0 = Math.max(20 * u, Math.min((single ? 64 : 40) * u, r * 0.3, room * 0.22));
      const nm = fit(ctx, p.name, width, 2, Math.round(top0), Math.round(16 * u), 700);
      ctx.fillStyle = C.ink; ctx.font = `700 ${nm.fs}px ${FONT}`;
      let y = cy + r + nm.fs * 1.55;
      nm.lines.forEach((l) => { ctx.fillText(l, cx, y); y += nm.fs * 1.2; });
      if (p.designation) {
        const left = areaTop + (row + 1) * rowH - y;
        const start = Math.round(nm.fs * 0.7);
        const lines = Math.max(2, Math.min(4, Math.floor(left / (start * 1.25))));
        const inst = fit(ctx, p.designation, width, lines, start, Math.round(15 * u), 500);
        ctx.fillStyle = C.inst; ctx.font = `${L ? 600 : 500} ${inst.fs}px ${FONT}`;
        y += inst.fs * 0.3;
        inst.lines.forEach((l) => { ctx.fillText(l, cx, y); y += inst.fs * 1.25; });
      }
    });
    ctx.fillStyle = C.site; ctx.font = `700 ${28 * u}px ${FONT}`; ctx.fillText('scienceslam.in', W / 2, H - bandH - 8 * u - 22 * u);
  }
  const save = (name) => new Promise((ok) => canvas.toBlob((blob) => {
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = `${name}-${sizeSel.value}.png`; a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 5000); ok();
  }, 'image/png'));
  const personFile = (i) => `${cfg.file}-${i + 1}-${cfg.people[i].name.trim().replace(/[\s/\\:*?"<>|.]+/g, '-')}`;
  const redraw = () => { syncUi(); draw(); };
  sizeSel.addEventListener('change', redraw);
  titleIn.addEventListener('input', redraw);
  whoSel?.addEventListener('change', redraw);
  styleSel?.addEventListener('change', redraw);
  lightBox?.addEventListener('input', redraw);
  // Share straight to WhatsApp, Instagram or Facebook from a phone (the system share sheet); elsewhere it downloads.
  const shareBtn = box.querySelector('[data-poster-share]');
  shareBtn?.addEventListener('click', () => canvas.toBlob(async (blob) => {
    const who = Number(whoSel?.value ?? -1);
    const name = `${who < 0 ? cfg.file : personFile(who)}-${sizeSel.value}.png`;
    const file = new File([blob], name, { type: 'image/png' });
    if (navigator.canShare?.({ files: [file] })) {
      try { await navigator.share({ files: [file], title: cfg.slam, text: `${titleIn.value || ''} · scienceslam.in`.trim() }); } catch { /* closed without sharing */ }
    } else {
      toast('Sharing is not available in this browser: the picture is downloaded instead.');
      save(who < 0 ? cfg.file : personFile(who));
    }
  }, 'image/png'));
  box.querySelector('[data-poster-png]').addEventListener('click', () => {
    const who = Number(whoSel?.value ?? -1);
    save(who < 0 ? cfg.file : personFile(who));
  });
  // One PNG per person, one after another (the browser may ask once to allow several downloads).
  box.querySelector('[data-poster-each]')?.addEventListener('click', async (e) => {
    const btn = e.currentTarget; btn.disabled = true;
    for (let i = 0; i < cfg.people.length; i++) {
      await draw(i); await save(personFile(i));
      await new Promise((ok) => setTimeout(ok, 400));
    }
    await draw(); btn.disabled = false;
  });
  syncUi(); draw();
});
