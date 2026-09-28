// สแกน QR/บาร์โค้ด: ใช้ BarcodeDetector ของเบราว์เซอร์ (Chrome/Android) ถ้าไม่มี (iPhone Safari, Firefox) โหลด ZXing จากในระบบ
import { api } from './net.js';
import * as notify from './notify.js';

const $ = (id) => document.getElementById(id);
const mode = document.querySelector('.scan')?.dataset.mode || 'view';
const video = $('video'), msg = $('cam-msg'), result = $('result');
const FORMATS = ['qr_code', 'code_128', 'code_39', 'ean_13', 'ean_8'];
let stream = null, running = false, facing = 'environment', detect = null;
let last = { code: '', at: 0 };

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

async function makeDetector() {
  if ('BarcodeDetector' in window) {
    try {
      const ok = await window.BarcodeDetector.getSupportedFormats();
      const use = FORMATS.filter((f) => ok.includes(f));
      if (use.includes('qr_code')) {
        const d = new window.BarcodeDetector({ formats: use });
        $('engine').textContent = 'ตัวอ่านของเบราว์เซอร์';
        return async (src) => (await d.detect(src))[0]?.rawValue || null;
      }
    } catch { /* ใช้ ZXing แทน */ }
  }
  await new Promise((ok, fail) => {
    if (window.ZXing) return ok();
    const s = document.createElement('script');
    s.src = 'assets/vendor/zxing.min.js';
    s.onload = ok; s.onerror = () => fail(new Error('โหลดตัวอ่านบาร์โค้ดไม่ได้'));
    document.head.append(s);
  });
  const Z = window.ZXing;
  const hints = new Map();
  hints.set(Z.DecodeHintType.POSSIBLE_FORMATS, [Z.BarcodeFormat.QR_CODE, Z.BarcodeFormat.CODE_128, Z.BarcodeFormat.CODE_39, Z.BarcodeFormat.EAN_13]);
  hints.set(Z.DecodeHintType.TRY_HARDER, true);
  const reader = new Z.MultiFormatReader();
  reader.setHints(hints);
  const canvas = document.createElement('canvas');
  const g = canvas.getContext('2d', { willReadFrequently: true });
  $('engine').textContent = 'ตัวอ่าน ZXing';
  return async (src) => {
    // อ่านเฉพาะกลางภาพ (กรอบเล็ง) ย่อให้กว้างไม่เกิน 640px → เร็วบนมือถือรุ่นเก่า
    const w = src.videoWidth, h = src.videoHeight;
    if (!w) return null;
    const cw = Math.round(w * 0.72), ch = Math.round(h * 0.64);
    const s = Math.min(1, 640 / cw);
    canvas.width = Math.round(cw * s); canvas.height = Math.round(ch * s);
    g.drawImage(src, (w - cw) / 2, (h - ch) / 2, cw, ch, 0, 0, canvas.width, canvas.height);
    try {
      return reader.decode(new Z.BinaryBitmap(new Z.HybridBinarizer(new Z.HTMLCanvasElementLuminanceSource(canvas)))).getText();
    } catch { return null; } finally { reader.reset(); }
  };
}

async function start() {
  if (!navigator.mediaDevices?.getUserMedia) { notify.error('เบราว์เซอร์นี้เปิดกล้องไม่ได้ ใช้ช่องพิมพ์เลขแทน'); return; }
  try {
    detect ??= await makeDetector();
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: facing }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false });
  } catch (e) {
    const denied = e?.name === 'NotAllowedError';
    notify.alertBox('เปิดกล้องไม่ได้', denied ? 'กรุณาอนุญาตให้เว็บนี้ใช้กล้องในการตั้งค่าเบราว์เซอร์ แล้วลองอีกครั้ง' : (e.message || 'ไม่พบกล้อง'), 'warning');
    return;
  }
  video.srcObject = stream;
  await video.play().catch(() => {});
  video.hidden = false; $('aim').hidden = false; msg.hidden = true;
  $('cam-stop').hidden = false; $('cam-flip').hidden = false;
  running = true;
  loop();
}

function stop() {
  running = false;
  stream?.getTracks().forEach((t) => t.stop());
  stream = null;
  video.hidden = true; $('aim').hidden = true; msg.hidden = false;
  $('cam-stop').hidden = true; $('cam-flip').hidden = true;
}

async function loop() {
  while (running) {
    if (video.readyState >= 2) {
      let code = null;
      try { code = await detect(video); } catch { /* เฟรมนี้อ่านไม่ได้ */ }
      const now = Date.now();
      if (code && !(code === last.code && now - last.at < 4000)) {
        last = { code, at: now };
        navigator.vibrate?.(60);
        lookup(code);
      }
    }
    await new Promise((r) => setTimeout(r, 180));
  }
}

async function lookup(code) {
  result.hidden = false;
  result.innerHTML = '<div class="panel-b muted">กำลังค้นหา…</div>';
  try {
    const { item } = await api('lookup?code=' + encodeURIComponent(code));
    show(item);
  } catch (e) {
    result.innerHTML = `<div class="panel-b"><b>ไม่พบ</b><p class="hint" style="margin:4px 0 0">${esc(e.message)}</p><p class="mono hint">${esc(code.slice(0, 80))}</p></div>`;
  }
}

function show(it) {
  const img = it.thumb ? `<img class="thumb" src="${esc(it.thumb)}" alt="">` : '<span class="thumb-empty">–</span>';
  const loan = it.loan ? `<span class="st st-loan${it.loan.late ? ' st-late' : ''}">ถูกยืม · ${esc(it.loan.borrower)} · คืน ${esc(it.loan.due)}</span>` : '';
  const checkBox = mode === 'check' ? `
    <div class="grid" style="margin-top:12px;gap:8px">
      <label class="field"><span class="lbl">สภาพที่พบ</span><select class="input" id="chk-status">${$('t-status').innerHTML}</select></label>
      <button class="btn btn-gold" type="button" id="chk-ok">${it.checked ? 'ตรวจแล้ว · บันทึกซ้ำ' : 'ยืนยันพบ'}</button>
    </div>` : '';
  result.innerHTML = `<div class="panel-b">
    <div class="found">${img}<div>
      <div class="no">${esc(it.no)}</div><b>${esc(it.name)}</b>
      <span class="sub">${esc([it.brand, it.model].filter(Boolean).join(' '))}${it.serial ? ' · S/N ' + esc(it.serial) : ''}</span>
      <span class="sub">${esc(it.location || '–')}${it.custodian ? ' · ' + esc(it.custodian) : ''}</span>
      <p style="margin:6px 0 0;display:flex;gap:6px;flex-wrap:wrap"><span class="st st-${it.status === 'normal' ? 'ok' : 'warn'}">${esc(it.statusLabel)}</span>${loan}${it.checked ? '<span class="st st-ok">ตรวจนับปีนี้แล้ว</span>' : ''}</p>
    </div></div>
    ${checkBox}
    <div class="actions" style="margin-top:12px"><a class="btn btn-sm" href="item.php?id=${it.id}">เปิดรายละเอียด</a>${mode === 'view' ? `<a class="btn btn-sm" href="item.php?id=${it.id}#${it.loan ? 'return' : 'loan'}">${it.loan ? 'รับคืน' : 'ยืม/เบิก'}</a>` : ''}</div>
  </div>`;
  if (mode === 'check') {
    const sel = $('chk-status');
    sel.value = it.status in { disposed: 1 } ? 'normal' : it.status;
    $('chk-ok').addEventListener('click', async (e) => {
      e.target.disabled = true;
      try {
        const r = await api('check', { id: it.id, status: sel.value });
        $('done').textContent = r.done; $('all').textContent = r.all;
        $('bar').style.width = (r.all ? (r.done / r.all) * 100 : 0) + '%';
        notify.ok(`${it.no} ตรวจพบแล้ว (${r.done}/${r.all})`, 2500);
        e.target.textContent = 'บันทึกแล้ว ✓';
      } catch (err) { notify.error(err.message); e.target.disabled = false; }
    });
  }
}

$('cam-start').addEventListener('click', start);
$('cam-stop').addEventListener('click', stop);
$('cam-flip').addEventListener('click', () => { facing = facing === 'environment' ? 'user' : 'environment'; stop(); start(); });
$('manual').addEventListener('submit', (e) => { e.preventDefault(); const c = $('code').value.trim(); if (c) { lookup(c); $('code').select(); } });
document.addEventListener('visibilitychange', () => { if (document.hidden && running) stop(); });
