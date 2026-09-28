// ฟอร์มลงทะเบียน: เติมอายุการใช้งาน/หน่วยนับตามประเภท · แสดงเลขที่จะได้ก่อนบันทึก · หลายชิ้นกรอกเลขเครื่องรายชิ้น
import { api } from './net.js';

const $ = (id) => document.getElementById(id);
const cat = $('cat_code'), qty = $('qty'), date = $('acquired_on'), useOld = $('use_old'), oldNo = $('old_no');
const preview = $('no-preview');

cat?.addEventListener('change', () => {
  const o = cat.selectedOptions[0];
  if (!o?.value) return;
  const life = $('life_years'), unit = $('unit'), name = $('name');
  if (life && (!life.value || life.dataset.auto)) { life.value = o.dataset.life; life.dataset.auto = '1'; }
  if (unit && (!unit.value || unit.dataset.auto)) { unit.value = o.dataset.unit || ''; unit.dataset.auto = '1'; }
  if (name && !name.value) name.placeholder = 'เช่น ' + o.dataset.name;
  refresh();
});
$('life_years')?.addEventListener('input', (e) => { delete e.target.dataset.auto; });
$('unit')?.addEventListener('input', (e) => { delete e.target.dataset.auto; });

let timer = 0, seq = 0;
function refresh() {
  if (!preview) return;
  clearTimeout(timer);
  timer = setTimeout(async () => {
    const n = Math.max(1, Math.min(100, Number(qty?.value) || 1));
    $('serial-many').hidden = n < 2;
    $('serial-one').hidden = n > 1;
    if (useOld) { useOld.disabled = n > 1; if (n > 1) useOld.checked = false; oldNo.hidden = !useOld.checked; oldNo.required = useOld.checked; }
    const b = preview.querySelector('b'), more = preview.querySelector('.more');
    if (useOld?.checked) { b.textContent = oldNo.value || '(ใช้เลขเดิม)'; more.textContent = ''; return; }
    if (!cat.value) { b.textContent = '—'; more.textContent = 'เลือกประเภทก่อน'; return; }
    const my = ++seq;
    try {
      const r = await api('next.no?cat=' + encodeURIComponent(cat.value) + '&date=' + encodeURIComponent(date.value) + '&qty=' + n);
      if (my !== seq) return;
      b.textContent = r.numbers[0];
      more.textContent = n > 1 ? 'ถึง ' + r.numbers[r.numbers.length - 1] + ` (${n} เลข)` : '';
    } catch (e) { b.textContent = '—'; more.textContent = e.message; }
  }, 200);
}
[qty, date].forEach((el) => el?.addEventListener('input', refresh));
useOld?.addEventListener('change', refresh);
oldNo?.addEventListener('input', refresh);
refresh();

// ราคา: รับ 12,500 หรือ 12500.50
$('price')?.addEventListener('blur', (e) => {
  const v = Number(String(e.target.value).replace(/[, ]/g, ''));
  if (Number.isFinite(v) && e.target.value !== '') e.target.value = v.toFixed(2);
});
