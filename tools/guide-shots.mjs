// ถ่ายภาพหน้าจอสำหรับคู่มือ (guide.php) จากระบบที่รันในเครื่อง → assets/guide/*.webp
//   ใช้: PW=<โฟลเดอร์ที่ติดตั้ง playwright> PHOTOS=<โฟลเดอร์รูปตัวอย่าง> node tools/guide-shots.mjs http://127.0.0.1:8093
//   ต้องเริ่มจากฐานข้อมูลว่าง (สำเนาใน scratchpad) · ใช้โหมดทดลอง ไม่แตะข้อมูลจริง · ต้องมี cwebp และ pdftoppm
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { tmpdir } from 'node:os';

const require = createRequire(join(process.env.PW || process.cwd(), 'package.json'));
const { chromium } = require('playwright');
const B = (process.argv[2] || 'http://127.0.0.1:8093') + '/khlang';
const PH = process.env.PHOTOS;
const OUT = join(dirname(fileURLToPath(import.meta.url)), '..', 'assets', 'guide');
const TMP = join(tmpdir(), 'khlang-shots');
mkdirSync(OUT, { recursive: true }); rmSync(TMP, { recursive: true, force: true }); mkdirSync(TMP, { recursive: true });

const webp = (png, name, q = 76) => execFileSync('cwebp', ['-quiet', '-q', String(q), png, '-o', join(OUT, name + '.webp')]);
const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1280, height: 860 }, deviceScaleFactor: 1, locale: 'th-TH', colorScheme: 'light' });
const page = await ctx.newPage();
const shot = async (name, opt = {}) => { const p = join(TMP, name + '.png'); await page.screenshot({ path: p, ...opt }); webp(p, name); console.log('•', name); };
const hideToasts = () => page.addStyleTag({ content: '.swal2-container,[data-flash]{display:none!important}' });

// ---------- ข้อมูลตัวอย่าง ----------
await page.goto(B + '/');

await page.click('text=ลองใช้ทันที ไม่ต้องสมัคร');
await page.waitForURL(/khlang\/$/);

await page.goto(B + '/settings.php');
await page.fill('#agency', 'สำนักงานเขตพื้นที่การศึกษาประถมศึกษานครพนม เขต 2');
await page.fill('#officer', 'นางสาวมาลัย ใจดี'); await page.fill('[name=officer_pos]', 'เจ้าหน้าที่พัสดุ');
await page.fill('#head', 'นายสมชาย รักงาน'); await page.fill('[name=head_pos]', 'หัวหน้าเจ้าหน้าที่');
await page.fill('#boss', 'นายวิทยา มีสุข'); await page.fill('[name=boss_pos]', 'ผู้อำนวยการโรงเรียน');
await page.fill('#checkers', 'นางสุดา ตรวจดี|ครู\nนายประเสริฐ ถี่ถ้วน|ครู\nนางสาวกานดา รอบคอบ|ครูผู้ช่วย');
await Promise.all([page.waitForNavigation(), page.click('form:has(#agency) button[type=submit]')]);

async function add(o) {
  await page.goto(B + '/edit.php');
  await page.selectOption('#cat_code', o.cat);
  for (const [k, v] of Object.entries(o.f)) await page.fill('#' + k, v);
  if (o.qty) { await page.fill('#qty', String(o.qty)); if (o.serials) await page.fill('#serials', o.serials); }
  if (o.old) { await page.check('#use_old'); await page.fill('#old_no', o.old); }
  if (o.photos) {
    await page.setInputFiles('input[data-photos]', o.photos.map((p) => join(PH, p)));
    await page.waitForFunction(() => /→/.test(document.getElementById('photo-status').textContent));
  }
  if (o.shot) { await page.waitForTimeout(700); await hideToasts(); await shot(o.shot, { fullPage: true }); }
  await Promise.all([page.waitForURL(/item\.php|items\.php/), page.click('button:has-text("บันทึกและออกเลข")')]);
  return page.url();
}
const laptop = await add({ cat: '7440-0109', photos: ['p-laptop.jpg', 'p-label.jpg'], shot: 'form', f: {
  name: 'เครื่องคอมพิวเตอร์โน้ตบุ๊ก', brand: 'Lenovo', model: 'ThinkPad E14 Gen5', serial_no: 'PF3XY123', spec: 'Core i5 RAM 16GB SSD 512GB จอ 14 นิ้ว',
  price: '24500', acquired_on: '2026-06-10', doc_no: 'ตร. 12/2569', vendor: 'หจก.นครพนมคอมพิวเตอร์', location: 'ห้องคอมพิวเตอร์ 1', custodian: 'นายอนุชา ขยันสอน' } });
await add({ cat: '4120-0102', qty: 3, serials: 'AC24-0091\nAC24-0092\nAC24-0093', photos: ['p-ac.jpg'], f: {
  name: 'เครื่องปรับอากาศแบบแยกส่วน 18,000 BTU', brand: 'Daikin', price: '18900', acquired_on: '2025-11-20', doc_no: 'ตร. 3/2569', vendor: 'ร้านนาทมแอร์', location: 'ห้องประชุม', custodian: 'นายสมชาย รักงาน' } });
const proj = await add({ cat: '6730-0105', photos: ['p-proj.jpg'], f: {
  name: 'เครื่องมัลติมีเดียโปรเจกเตอร์', brand: 'Epson', model: 'EB-X51', serial_no: 'X5K8812', price: '16000', acquired_on: '2019-04-25', location: 'ห้อง ป.6', custodian: 'นางสาวสุนิสา ใจเย็น' } });
await add({ cat: '7110-0737', old: '7110-006-0012/2558', f: { name: 'โต๊ะทำงานเหล็ก', price: '4500', acquired_on: '2015-05-20', location: 'ห้องธุรการ' } });
await add({ cat: '7440-0101', f: { name: 'เครื่องคอมพิวเตอร์ตั้งโต๊ะ', brand: 'Acer', price: '19000', acquired_on: '2022-08-15', location: 'ห้องธุรการ', custodian: 'นางสาวมาลัย ใจดี' } });

// ยืม (เกินกำหนด) + สถานะชำรุด + ตรวจนับ
await page.goto(proj);
await page.fill('#borrower', 'นางสาวสุนิสา ใจเย็น ครู'); await page.fill('#purpose', 'จัดนิทรรศการวันวิทยาศาสตร์');
await page.fill('#place', 'หอประชุมอำเภอนาทม'); await page.fill('#out_on', '2026-09-15'); await page.fill('#due_on', '2026-09-22');
await Promise.all([page.waitForNavigation(), page.click('button:has-text("บันทึกการยืม")')]);
await page.goto(B + '/items.php?q=Acer'); await page.click('a.no');
await page.selectOption('#st', 'broken'); await page.fill('#why', 'เปิดไม่ติด ส่งตรวจอาการ');
await Promise.all([page.waitForNavigation(), page.click('button:has-text("บันทึกสถานะ")')]);
for (const code of ['PF3XY123', 'AC24-0091', 'AC24-0092']) {
  await page.goto(B + '/scan.php?mode=check');
  await page.fill('#code', code); await page.press('#code', 'Enter');
  await page.click('#chk-ok'); await page.waitForTimeout(500);
}

// ---------- ภาพหน้าจอ ----------
await page.goto(B + '/'); await hideToasts(); await shot('dashboard', { fullPage: true });
await page.goto(B + '/items.php'); await hideToasts(); await shot('items', { fullPage: true });
await page.goto(laptop.replace(/&new=1$/, '')); await hideToasts(); await shot('item', { fullPage: true });
await page.goto(B + '/scan.php?mode=check');
await page.fill('#code', 'AC24-0093'); await page.press('#code', 'Enter'); await page.waitForSelector('#chk-ok');
await hideToasts(); await shot('scan-check');
await page.goto(B + '/loans.php'); await hideToasts(); await shot('loans');
await page.goto(B + '/print.php'); await hideToasts(); await shot('print', { fullPage: true });
await page.goto(B + '/settings.php'); await hideToasts();
await page.locator('#number').screenshot({ path: join(TMP, 'settings-number.png') }); webp(join(TMP, 'settings-number.png'), 'settings-number');

// PDF → ภาพ
for (const [name, u] of [['pdf-sticker', 'print.php?doc=sticker&go=1&paper=a4-24&barcode=1'], ['pdf-register', 'print.php?doc=register&q=X5K8812'], ['pdf-loan', null]]) {
  let url = u;
  if (!url) { await page.goto(proj); url = (await page.getAttribute('a:has-text("ใบยืม PDF")', 'href')).replace(/&amp;/g, '&'); }
  const r = await page.request.get(B + '/' + url);
  const pdf = join(TMP, name + '.pdf'); writeFileSync(pdf, await r.body());
  execFileSync('pdftoppm', ['-r', '110', '-f', '1', '-l', '1', '-png', '-singlefile', pdf, join(TMP, name)]);
  if (name === 'pdf-sticker') execFileSync('python3', ['-c', `from PIL import Image;im=Image.open('${join(TMP, name)}.png');w,h=im.size;im.crop((0,0,w,int(h*0.3))).save('${join(TMP, name)}.png')`]);
  webp(join(TMP, name + '.png'), name, 80); console.log('•', name);
}

// มือถือ
const m = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, locale: 'th-TH', storageState: await ctx.storageState() });
const mp = await m.newPage();
for (const [name, u] of [['m-scan', B + '/scan.php']]) {
  await mp.goto(u); await mp.addStyleTag({ content: '.swal2-container,[data-flash]{display:none!important}' });
  const p = join(TMP, name + '.png'); await mp.screenshot({ path: p }); webp(p, name, 72); console.log('•', name);
}
await browser.close();
