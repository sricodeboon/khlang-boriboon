// สคริปต์ร่วมทุกหน้า: ยืนยันก่อนส่งฟอร์มสำคัญ · ขยายรูป · เลือกทั้งหมด · ตัวช่วย fetch พร้อม CSRF
import * as notify from './notify.js';
export { api, csrf } from './net.js';

// ฟอร์มที่มี data-confirm → ถามก่อนส่ง (data-danger = ปุ่มสีแดง)
document.addEventListener('submit', async (e) => {
  const f = e.target;
  if (!(f instanceof HTMLFormElement) || !f.dataset.confirm || f.dataset.ok) return;
  e.preventDefault();
  const yes = await notify.confirm({ title: f.dataset.confirm, text: f.dataset.detail || '', ok: f.dataset.okText || 'ยืนยัน', icon: f.dataset.danger ? 'warning' : 'question' });
  if (!yes) return;
  f.dataset.ok = '1';
  f.requestSubmit(e.submitter || undefined);
});

// ขยายรูป
document.addEventListener('click', (e) => {
  const img = e.target.closest('img[data-zoom]');
  if (!img) return;
  const box = document.createElement('div');
  box.className = 'lightbox';
  box.innerHTML = '<img alt="">';
  box.firstChild.src = img.dataset.zoom;
  box.addEventListener('click', () => box.remove());
  document.addEventListener('keydown', function esc(k) { if (k.key === 'Escape') { box.remove(); document.removeEventListener('keydown', esc); } });
  document.body.append(box);
});

// เลือกทั้งหมดในตาราง + นับจำนวนที่เลือก
const all = document.querySelector('[data-check-all]');
if (all) {
  const boxes = () => [...document.querySelectorAll('input[name="ids[]"]')];
  const count = document.querySelector('[data-check-count]');
  const sync = () => {
    const n = boxes().filter((b) => b.checked).length;
    if (count) count.textContent = n;
    document.querySelectorAll('[data-needs-check]').forEach((b) => { b.disabled = n === 0; });
  };
  all.addEventListener('change', () => { boxes().forEach((b) => { b.checked = all.checked; }); sync(); });
  document.addEventListener('change', (e) => { if (e.target.matches('input[name="ids[]"]')) sync(); });
  sync();
}

// ช่องกรองที่ติด data-autosubmit → ส่งฟอร์มเมื่อเปลี่ยน
document.querySelectorAll('[data-autosubmit]').forEach((el) => el.addEventListener('change', () => el.form?.requestSubmit()));

notify.warm?.();
