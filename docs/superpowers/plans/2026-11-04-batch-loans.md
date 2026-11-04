# ยืม/เบิก/รับคืนหลายรายการ Implementation Plan

> ครูแจ็กสั่ง "ทำเลย" 4 พ.ย. 2569 · ทำเองในเซสชันเดียว (งานพึ่งกันแน่น) · ติ๊กเมื่อทดสอบผ่านแล้ว

**Goal:** สแกนสะสมหลายชิ้น แล้วยืม/เบิก/รับคืนพร้อมกัน ออกใบยืมใบเดียวต่อชุด

**Architecture:** `kl_loans` ยังเป็น 1 แถวต่อ 1 ชิ้น เพิ่ม `batch_id` = id แถวแรกของชุด (แถวเดี่ยว = NULL) · ตรรกะอยู่ใน `lib/assets.php` ใช้ร่วมทั้ง item.php และ api.php · ชุดสำเร็จทั้งหมดหรือไม่บันทึกเลย

**Tech Stack:** PHP 8 · SQLite/MySQL · mPDF · JS module · Playwright (scratchpad)

## Global Constraints
- ใบยืมเก่าและลิงก์ `print.php?doc=loan&id=` ต้องใช้ได้เหมือนเดิม; ห้ามแตะ PRAGMA user_version; migrate ต้องรันซ้ำได้
- จำกัด 100 รายการต่อชุด · ห้ามเขียว · ห้ามแถบสีขอบซ้าย · PDF ด้วย mPDF
- กฎเดิมคงอยู่: สถานะ lost/disposed/dispose/broken/fixing ยืมไม่ได้, เบิกไม่มีวันคืน, ยืมต้องมีกำหนดคืน, คืนไม่ก่อนวันยืม/ไม่เป็นอนาคต

## Review Focus
1. ชุดมีชิ้นที่ยืมไม่ได้ปนมา (เช่นคนอื่นเพิ่งยืมตัดหน้า) → ปฏิเสธทั้งชุด บอกเลขชิ้นนั้น ไม่บันทึกบางส่วน
2. กล้องอ่านสติกเกอร์เดิมซ้ำ → ไม่เพิ่มซ้ำ ไม่เด้งเตือนรัว
3. รีเฟรช/สลับแอประหว่างสแกน → ตะกร้าไม่หาย (sessionStorage แยกตามโรงเรียน+โหมด)
4. รับคืนบางชิ้นในชุด/คนละสภาพ → ชิ้นที่เหลือยังค้าง ใบยืมแสดงการคืนรายชิ้น
5. id ของโรงเรียนอื่นส่งเข้ามา → ปฏิเสธ

---

## Tasks
- [x] **1. DB + ตรรกะ** `lib/db.php` เพิ่ม `batch_id` + index · `lib/assets.php`: `valid_date`, `item_loan_block(item): ?string`, `return_statuses(item): array`, `loans_create(sid, ids, data, uid): int` (id แถวแรก), `loans_return(sid, rows, back, uid): int`, `loan_slip(sid, loanId): ?array` · item.php ใช้ฟังก์ชันชุดนี้ · ทดสอบ `tests/loans_test.php`
- [x] **2. API** `api.php`: `items.brief?ids=`, `loan.batch`, `loan.return`; `item_brief` เพิ่ม `loanBlock`, `returnOptions`
- [x] **3. ใบยืม PDF** `pdf_loan(loans, items, school, set)` หลายแถว + คอลัมน์การส่งคืนเมื่อเป็นชุด · print.php ใช้ `loan_slip`
- [x] **4. หน้าสแกน** โหมด `loan`/`return` ใน scan.php + scan.js + app.css · preload จาก `ids[]`/`batch`
- [x] **5. จุดเข้า** items.php ปุ่มยืม/คืนรายการที่เลือก · loans.php ปุ่มรับคืนทั้งชุด + ป้ายชุด
- [x] **6. เอกสาร + e2e** guide.php, PLAN.md, guide-shots · Playwright ครบวงจรบนสำเนา รวมจอ 360px

## พบระหว่างทำ
- `.scan-result` เดิมมีแถบสีขอบซ้าย ขัดกฎ UI ของครู → ยังไม่แก้ (นอกแผน)
- โหมดตรวจนับยังต้องกดยืนยันทีละชิ้น → ยังไม่ทำ (นอกแผน)
- กด Enter ในช่องเลขก่อนสคริปต์โหลดเสร็จ → ฟอร์มส่งแบบปกติไป `scan.php?code=…` หลุดโหมดตะกร้า (รายการเดิมไม่หาย แต่เลขนั้นไม่ถูกเพิ่ม) พบตอนทดสอบบนโฮสต์ → ยังไม่แก้
