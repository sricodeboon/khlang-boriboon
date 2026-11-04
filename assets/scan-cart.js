// ตะกร้าสแกนหลายรายการ: สะสมครุภัณฑ์ แล้วยืม/เบิก/รับคืนพร้อมกันทั้งชุด
// เก็บใน sessionStorage (แยกตามโรงเรียน+โหมด) กันรายการหายเมื่อรีเฟรชหรือสลับแอประหว่างสแกน
import { api } from './net.js';
import * as notify from './notify.js';

const MAX = 100;
const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

export function makeCart(mode) {
  const root = document.querySelector('.scan');
  const KEY = `kl-cart-${root.dataset.sid}-${mode}`;
  const list = $('cart-list'), form = $('cart-form'), submit = $('cart-submit'), msg = $('cart-msg'), done = $('cart-done');
  let items = [];

  const save = () => { try { sessionStorage.setItem(KEY, JSON.stringify(items)); } catch { /* โหมดส่วนตัว: ใช้ต่อได้แต่ไม่จำ */ } };
  const say = (text) => { msg.textContent = text || ''; msg.hidden = !text; };
  const isIssue = () => mode === 'loan' && form.elements.kind.value === 'issue';

  function submitLabel() {
    const verb = mode === 'return' ? 'รับคืน' : isIssue() ? 'การเบิก' : 'การยืม';
    submit.textContent = `บันทึก${verb} ${items.length} รายการ`;
    if (mode === 'loan') $('due-field').hidden = isIssue();
  }

  function render() {
    $('cart-count').textContent = items.length;
    $('cart-empty').hidden = items.length > 0;
    $('cart-clear').hidden = items.length === 0;
    form.hidden = items.length === 0;
    list.innerHTML = items.map((it) => `<li data-id="${it.id}">
      <div class="cart-row"><div><span class="no">${esc(it.no)}</span><b>${esc(it.name)}</b>
        ${mode === 'return' && it.loan ? `<span class="sub">${it.loan.kind === 'issue' ? 'เบิกโดย' : 'ยืมโดย'} ${esc(it.loan.borrower)}${it.loan.due !== '–' ? ` · กำหนดคืน ${esc(it.loan.due)}` : ''}</span>` : ''}</div>
        <button class="cart-del" type="button" data-del aria-label="เอา ${esc(it.no)} ออก">×</button></div>
      ${mode === 'return' ? `<div class="cart-ret">
        <select class="input" data-status aria-label="สภาพเมื่อคืน ${esc(it.no)}">${it.returnOptions.map((o) => `<option value="${esc(o.v)}"${o.v === it.pickStatus ? ' selected' : ''}>${esc(o.l)}</option>`).join('')}</select>
        <input class="input" data-note maxlength="500" placeholder="บันทึก" aria-label="บันทึก ${esc(it.no)}" value="${esc(it.note)}">
      </div>` : ''}</li>`).join('');
    submitLabel();
  }

  /** คืนข้อความเมื่อเพิ่มไม่ได้ */
  function add(it, code) {
    const same = items.find((x) => x.id === it.id);
    if (same) { if (code && !same.codes.includes(code)) same.codes.push(code); return `${it.no} อยู่ในรายการแล้ว`; }
    if (items.length >= MAX) return `เลือกได้ครั้งละไม่เกิน ${MAX} รายการ`;
    if (mode === 'loan' && it.loanBlock) return `${it.no} ${it.loanBlock}`;
    if (mode === 'return' && !it.loan) return `${it.no} ไม่มีรายการยืมค้างอยู่`;
    items.unshift({ ...it, codes: code ? [code] : [], pickStatus: it.status, note: '' });
    return null;
  }

  async function lookup(code) {
    done.hidden = true;
    const known = items.find((x) => x.codes.includes(code));
    if (known) { say(`${known.no} อยู่ในรายการแล้ว`); return; }
    try {
      const { item } = await api('lookup?code=' + encodeURIComponent(code));
      const problem = add(item, code);
      say(problem);
      if (problem) { navigator.vibrate?.([60, 60, 60]); notify.warn(problem, 2500); return; } // บนมือถือกล่องข้อความอยู่ใต้กล้อง
      save(); render();
      notify.ok(`เพิ่ม ${item.no}`, 1200);
    } catch (e) {
      navigator.vibrate?.([60, 60, 60]);
      say(`${code.slice(0, 40)}: ${e.message}`);
      notify.warn(e.message, 2500);
    }
  }

  function finish(text, pdfId, pdfLabel) {
    items = []; save(); render(); say('');
    $('cart-done-text').textContent = text;
    const pdf = $('cart-done-pdf');
    pdf.hidden = !pdfId;
    if (pdfId) { pdf.href = `print.php?doc=loan&id=${pdfId}`; pdf.textContent = pdfLabel; }
    done.hidden = false;
  }

  list.addEventListener('click', (e) => {
    if (!e.target.closest('[data-del]')) return;
    const id = Number(e.target.closest('li').dataset.id);
    items = items.filter((x) => x.id !== id);
    save(); render();
  });
  list.addEventListener('change', (e) => {
    const it = items.find((x) => x.id === Number(e.target.closest('li')?.dataset.id));
    if (!it) return;
    if (e.target.matches('[data-status]')) it.pickStatus = e.target.value;
    if (e.target.matches('[data-note]')) it.note = e.target.value;
    save();
  });
  $('cart-clear').addEventListener('click', async () => {
    if (!await notify.confirm({ title: 'ล้างรายการทั้งหมด?', text: `${items.length} รายการที่สแกนไว้จะถูกเอาออก (ยังไม่มีการบันทึกใด ๆ)`, ok: 'ล้างรายการ' })) return;
    items = []; save(); render(); say('');
  });
  form.addEventListener('change', submitLabel);
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    // บันทึกที่กำลังพิมพ์ในช่องบันทึกยังไม่เกิด change ถ้ากด Enter ทันที
    list.querySelectorAll('li').forEach((li) => {
      const it = items.find((x) => x.id === Number(li.dataset.id));
      const note = li.querySelector('[data-note]');
      if (it && note) it.note = note.value;
    });
    submit.disabled = true;
    try {
      const data = Object.fromEntries(new FormData(form));
      if (mode === 'loan') {
        const issue = isIssue();
        const r = await api('loan.batch', { ...data, ids: items.map((x) => x.id) });
        finish(`บันทึก${issue ? 'การเบิก' : 'การยืม'} ${r.count} รายการแล้ว`, r.loanId, issue ? 'พิมพ์ใบเบิก PDF' : 'พิมพ์ใบยืม PDF');
      } else {
        const r = await api('loan.return', { ...data, items: items.map((x) => ({ id: x.id, status: x.pickStatus, note: x.note })) });
        finish(`รับคืน ${r.count} รายการแล้ว`);
      }
    } catch (err) {
      say(err.message); // รายการยังอยู่ครบ แก้แล้วกดใหม่ได้
      notify.error('ยังไม่ได้บันทึก');
    } finally { submit.disabled = false; }
  });

  async function preload() {
    const { ids, batch } = root.dataset;
    if (!ids && !batch) return;
    try {
      const { items: found } = await api('items.brief?' + (batch ? 'batch=' + encodeURIComponent(batch) : 'ids=' + encodeURIComponent(ids)));
      items = [];
      const problems = found.map((it) => add(it)).filter(Boolean);
      say(problems.join(' · '));
      save(); render();
      history.replaceState(null, '', 'scan.php?mode=' + mode); // รีเฟรชแล้วไม่เขียนทับตะกร้าที่แก้ไปแล้ว
    } catch (e) { say(e.message); }
  }

  try { items = JSON.parse(sessionStorage.getItem(KEY) || '[]'); } catch { items = []; }
  render();
  preload();

  return { lookup, has: (code) => items.some((x) => x.codes.includes(code)) };
}
