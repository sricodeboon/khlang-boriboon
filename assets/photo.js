// ย่อรูปในเครื่องก่อนอัปโหลด: ด้านยาว ≤1280px JPEG ≤ ~350KB + รูปย่อ 160px · ประหยัดพื้นที่โฮสต์ฟรีและเน็ตมือถือ
// ใช้กับ <input type="file" data-photos> ในฟอร์ม: ตอนส่งฟอร์มจะแทนไฟล์ต้นฉบับด้วยไฟล์ที่ย่อแล้ว
import * as notify from './notify.js';

const EDGE = 1280, TARGET = 350_000, MAX = 3;

async function decode(file) {
  try { return await createImageBitmap(file, { imageOrientation: 'from-image' }); }
  catch {
    // เบราว์เซอร์เก่า/ไฟล์บางชนิด: ผ่าน <img>
    const url = URL.createObjectURL(file);
    try {
      const img = new Image();
      img.src = url;
      await img.decode();
      return img;
    } finally { URL.revokeObjectURL(url); }
  }
}

const toBlob = (canvas, q) => new Promise((ok) => canvas.toBlob(ok, 'image/jpeg', q));

export async function compress(file) {
  let bmp;
  try { bmp = await decode(file); }
  catch { throw new Error(`เปิดรูป “${file.name}” ไม่ได้ (รูป HEIC ให้ถ่ายผ่านปุ่มถ่ายรูป หรือแปลงเป็น JPG ก่อน)`); }
  const w = bmp.width, h = bmp.height;
  const s = Math.min(1, EDGE / Math.max(w, h));
  const c = document.createElement('canvas');
  c.width = Math.round(w * s); c.height = Math.round(h * s);
  const g = c.getContext('2d');
  g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height);
  g.drawImage(bmp, 0, 0, c.width, c.height);
  let blob = null;
  for (const q of [0.82, 0.74, 0.66, 0.56, 0.46]) {
    blob = await toBlob(c, q);
    if (blob && blob.size <= TARGET) break;
  }
  // รูปย่อสี่เหลี่ยมจัตุรัส 160px สำหรับหน้ารายการ
  const t = document.createElement('canvas');
  t.width = t.height = 160;
  const side = Math.min(w, h);
  t.getContext('2d').drawImage(bmp, (w - side) / 2, (h - side) / 2, side, side, 0, 0, 160, 160);
  bmp.close?.();
  return { blob, thumb: t.toDataURL('image/jpeg', 0.7), before: file.size, after: blob.size };
}

const kb = (n) => (n / 1024 < 1000 ? Math.round(n / 1024) + ' KB' : (n / 1048576).toFixed(1) + ' MB');

/** ผูกกับ input[data-photos] ในฟอร์ม: แสดงตัวอย่าง + ส่งฟอร์มด้วย fetch เมื่อมีรูป */
export function bind(input) {
  const form = input.form;
  const preview = document.querySelector(input.dataset.preview || '#photo-preview');
  const status = document.querySelector(input.dataset.status || '#photo-status');
  const limit = Number(input.dataset.room || MAX);
  let ready = [];
  let busy = null;

  input.addEventListener('change', () => {
    const files = [...input.files].slice(0, limit);
    if (input.files.length > limit) notify.warn(`เลือกได้อีก ${limit} รูป ใช้ ${limit} รูปแรก`);
    busy = (async () => {
      ready = [];
      if (preview) preview.replaceChildren();
      if (!files.length) { if (status) status.textContent = ''; return; }
      if (status) status.textContent = 'กำลังย่อรูป…';
      let before = 0, after = 0;
      for (const f of files) {
        try {
          const r = await compress(f);
          ready.push(r);
          before += r.before; after += r.after;
          if (preview) {
            const fig = document.createElement('figure');
            const img = document.createElement('img');
            img.src = URL.createObjectURL(r.blob); img.alt = '';
            fig.append(img); preview.append(fig);
          }
        } catch (e) { notify.error(e.message); }
      }
      if (status) status.textContent = ready.length ? `ย่อรูป ${ready.length} รูป ${kb(before)} → ${kb(after)}` : '';
    })();
  });

  form.addEventListener('submit', async (e) => {
    if (!input.files.length || e.defaultPrevented) return;
    e.preventDefault();
    if (!form.reportValidity()) return;
    const btn = e.submitter;
    if (btn) btn.disabled = true;
    const done = notify.loading('กำลังบันทึก…');
    try {
      await busy;
      const fd = new FormData(form);
      fd.delete(input.name);
      ready.forEach((r, i) => fd.append(input.name, r.blob, `photo-${i + 1}.jpg`));
      if (ready[0]) fd.append('thumb', ready[0].thumb);
      const res = await fetch(form.action || location.href, { method: 'POST', body: fd, credentials: 'same-origin' });
      if (!res.ok) throw new Error('บันทึกไม่สำเร็จ (' + res.status + ')');
      location.href = res.url; // เซิร์ฟเวอร์ redirect ไปหน้าผลลัพธ์ (หรือกลับฟอร์มพร้อมข้อความ)
    } catch (err) {
      done?.close?.();
      notify.error(err.message || 'บันทึกไม่สำเร็จ');
      if (btn) btn.disabled = false;
    }
  });
}

document.querySelectorAll('input[type=file][data-photos]').forEach(bind);
