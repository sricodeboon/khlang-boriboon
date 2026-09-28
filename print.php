<?php
// ศูนย์พิมพ์เอกสาร: สติกเกอร์ QR · ทะเบียนคุมทรัพย์สิน · บัญชีครุภัณฑ์ · ใบยืม · รายงานตรวจสอบประจำปี (PDF จาก mPDF)
$doc = (string) ($_GET['doc'] ?? '');
$makePdf = $doc !== '' && ($doc !== 'sticker' || isset($_GET['go']));
if ($makePdf) define('KL_RAW_OUTPUT', true);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/assets.php';
require __DIR__ . '/lib/layout.php';
require __DIR__ . '/lib/pdf.php';

[$u, $school] = require_school();
$sid = (int) $school['id'];
$set = settings($sid);
$cats = cats($sid);
const PDF_MAX = 300; // ต่อไฟล์ (โฮสต์ฟรีจำกัดหน่วยความจำ/เวลา)

/** รายการตามตัวกรอง: ids (จากการเลือก) หรือ cat/place/status/batch */
function pick_items(int $sid, array $cats): array {
    $ids = $_GET['ids'] ?? [];
    if (is_string($ids)) $ids = explode(',', $ids);
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
    if ($ids) {
        $ids = array_slice($ids, 0, PDF_MAX);
        $in = implode(',', array_fill(0, count($ids), '?'));
        return db_all("SELECT * FROM kl_items WHERE school_id = ? AND id IN ($in) ORDER BY cat_code, fy, seq, id", [$sid, ...$ids]);
    }
    $w = ['school_id = ?']; $a = [$sid];
    $cat = (string) ($_GET['cat'] ?? '');
    if (isset($cats[$cat])) { $w[] = 'cat_code = ?'; $a[] = $cat; }
    $st = (string) ($_GET['status'] ?? '');
    if (isset(KL_STATUS[$st])) { $w[] = 'status = ?'; $a[] = $st; }
    elseif (($_GET['view'] ?? '') !== 'all') $w[] = "status <> 'disposed'";
    if (($_GET['place'] ?? '') !== '') { $w[] = 'location = ?'; $a[] = (string) $_GET['place']; }
    if (($_GET['q'] ?? '') !== '') { $w[] = '(asset_no LIKE ? OR name LIKE ? OR serial_no LIKE ?)'; $l = '%' . $_GET['q'] . '%'; array_push($a, $l, $l, $l); }
    if (($_GET['fy'] ?? '') !== '') { $w[] = 'fy = ?'; $a[] = (int) $_GET['fy']; }
    return db_all('SELECT * FROM kl_items WHERE ' . implode(' AND ', $w) . ' ORDER BY cat_code, fy, seq, id LIMIT ' . PDF_MAX, $a);
}

function send_pdf(\Mpdf\Mpdf $m, string $name): never {
    while (ob_get_level() > 0) ob_end_clean();
    header('Cache-Control: private, no-store');
    $m->Output($name, \Mpdf\Output\Destination::INLINE);
    exit;
}

if ($makePdf) {
    @set_time_limit(60);
    switch ($doc) {
        case 'sticker':
            $items = pick_items($sid, $cats);
            if (!$items) break;
            $preset = isset(KL_LABELS[$_GET['paper'] ?? '']) ? (string) $_GET['paper'] : $set['sticker'];
            if ($preset !== $set['sticker']) settings_save($sid, ['sticker' => $preset]);
            send_pdf(pdf_stickers($items, $school, $preset, (int) ($_GET['skip'] ?? 0),
                max(-10, min(10, (float) ($_GET['dx'] ?? 0))), max(-10, min(10, (float) ($_GET['dy'] ?? 0))), !empty($_GET['barcode'])), 'sticker.pdf');
        case 'register':
            $items = pick_items($sid, $cats);
            if (!$items) break;
            send_pdf(pdf_register($items, $school, $set, $cats), 'register.pdf');
        case 'list':
            $items = pick_items($sid, $cats);
            $sub = [];
            if (isset($cats[$_GET['cat'] ?? ''])) $sub[] = $cats[$_GET['cat']]['name'];
            if (($_GET['place'] ?? '') !== '') $sub[] = (string) $_GET['place'];
            if (isset(KL_STATUS[$_GET['status'] ?? ''])) $sub[] = status_label((string) $_GET['status']);
            send_pdf(pdf_list($items, $school, $set, implode(' · ', $sub)), 'list.pdf');
        case 'loan':
            $loan = db_one('SELECT * FROM kl_loans WHERE id = ? AND school_id = ?', [(int) ($_GET['id'] ?? 0), $sid]);
            if (!$loan) break;
            send_pdf(pdf_loan($loan, item_get($sid, (int) $loan['item_id']), $school, $set), 'loan.pdf');
        case 'check':
            $items = db_all("SELECT * FROM kl_items WHERE school_id = ? AND status <> 'disposed' ORDER BY cat_code, fy, seq", [$sid]);
            send_pdf(pdf_check($items, $school, $set, fiscal_year()), 'check.pdf');
    }
    // ไม่มีรายการ → กลับหน้าเลือก (ต้องปิด gzip ที่ไม่ได้เปิด: ส่ง header redirect ได้เลย)
    flash('ไม่พบรายการที่จะพิมพ์', 'error');
    redirect('print.php');
}

// ---------- หน้าเลือกสิ่งที่จะพิมพ์ ----------
$preIds = $_GET['ids'] ?? [];
$preIds = is_string($preIds) ? $preIds : implode(',', array_map('intval', (array) $preIds));
$usedCats = db_all('SELECT i.cat_code, COUNT(*) AS n FROM kl_items i WHERE i.school_id = ? GROUP BY i.cat_code ORDER BY i.cat_code', [$sid]);
$places = array_column(db_all("SELECT DISTINCT location FROM kl_items WHERE school_id = ? AND location <> '' ORDER BY location", [$sid]), 'location');
$years = array_column(db_all('SELECT DISTINCT fy FROM kl_items WHERE school_id = ? ORDER BY fy DESC', [$sid]), 'fy');
$filters = function () use ($usedCats, $cats, $places, $years) { ?>
  <div class="field"><label>ประเภท</label><select class="input" name="cat"><option value="">ทุกประเภท</option>
    <?php foreach ($usedCats as $c): ?><option value="<?= h($c['cat_code']) ?>"><?= h($c['cat_code'] . ' ' . ($cats[$c['cat_code']]['name'] ?? '')) ?> (<?= (int) $c['n'] ?>)</option><?php endforeach; ?></select></div>
  <?php if ($places): ?><div class="field"><label>สถานที่</label><select class="input" name="place"><option value="">ทุกสถานที่</option><?= options($places, null) ?></select></div><?php endif; ?>
  <div class="field"><label>ปีงบประมาณที่ได้มา</label><select class="input" name="fy"><option value="">ทุกปี</option><?= options($years, null) ?></select></div>
<?php };

page_head('พิมพ์เอกสาร · คลังบริบูรณ์', 'print'); ?>
<main class="wrap page">
  <?= flash_script() ?>
  <div class="page-h"><div><h1>พิมพ์</h1><p>ไฟล์ PDF เปิดในแท็บใหม่ · ครั้งละไม่เกิน <?= PDF_MAX ?> รายการ</p></div></div>

  <div class="grid g2" style="align-items:start">
    <form class="panel" method="get" target="_blank">
      <input type="hidden" name="doc" value="sticker"><input type="hidden" name="go" value="1">
      <div class="panel-h"><h2>สติกเกอร์ QR</h2><span class="muted">ติดที่ตัวครุภัณฑ์</span></div>
      <div class="panel-b grid">
        <?php if ($preIds !== ''): ?><input type="hidden" name="ids" value="<?= h($preIds) ?>"><p class="no-preview" style="margin:0">พิมพ์เฉพาะที่เลือกไว้ <b><?= count(array_filter(explode(',', $preIds))) ?></b> รายการ · <a href="print.php">เลือกใหม่</a></p>
        <?php else: $filters(); endif; ?>
        <div class="field"><label>กระดาษสติกเกอร์</label><select class="input" name="paper"><?= options(array_map(fn($l) => $l[0], KL_LABELS), $set['sticker'], true) ?></select></div>
        <div class="grid g3">
          <div class="field"><label>เริ่มที่ดวงที่</label><input class="input" type="number" name="skip" min="1" max="65" value="1" data-minus1></div>
          <div class="field"><label>เลื่อนขวา (มม.)</label><input class="input" type="number" name="dx" step="0.5" min="-10" max="10" value="0"></div>
          <div class="field"><label>เลื่อนลง (มม.)</label><input class="input" type="number" name="dy" step="0.5" min="-10" max="10" value="0"></div>
        </div>
        <label class="check"><input type="checkbox" name="barcode" value="1"> เพิ่มบาร์โค้ด (สำหรับเครื่องยิงบาร์โค้ด)</label>
        <p class="hint" style="margin:0">กระดาษใช้ไปบางดวงแล้ว ใส่ “เริ่มที่ดวงที่” เป็นดวงว่างดวงแรก · พิมพ์ที่ขนาดจริง 100% (ปิด “พอดีกับหน้า”) · ถ้าเครื่องพิมพ์เยื้อง ใช้เลื่อนขวา/ลงชดเชย</p>
        <button class="btn btn-gold" type="submit">สร้างสติกเกอร์ PDF</button>
      </div>
    </form>

    <div class="grid" style="gap:16px">
      <form class="panel" method="get" target="_blank">
        <input type="hidden" name="doc" value="register">
        <div class="panel-h"><h2>ทะเบียนคุมทรัพย์สิน</h2><span class="muted">1 แผ่นต่อ 1 รายการ + ด้านหลังประวัติซ่อม</span></div>
        <div class="panel-b grid">
          <?php if ($preIds !== ''): ?><input type="hidden" name="ids" value="<?= h($preIds) ?>"><?php else: ?><div class="grid g3"><?php $filters(); ?></div><?php endif; ?>
          <button class="btn" type="submit">สร้างทะเบียนคุม PDF</button>
        </div>
      </form>
      <form class="panel" method="get" target="_blank">
        <input type="hidden" name="doc" value="list">
        <div class="panel-h"><h2>บัญชีครุภัณฑ์</h2><span class="muted">รายการเดียวต่อบรรทัด พร้อมช่องลงนาม</span></div>
        <div class="panel-b grid"><div class="grid g3"><?php $filters(); ?></div><button class="btn" type="submit">สร้างบัญชี PDF</button></div>
      </form>
      <section class="panel">
        <div class="panel-h"><h2>รายงานผลการตรวจสอบพัสดุประจำปี</h2></div>
        <div class="panel-b"><p class="hint" style="margin-top:0">สรุปจากการสแกนตรวจนับปีงบประมาณ <?= fiscal_year() ?> · ตั้งรายชื่อกรรมการได้ที่ <a href="settings.php">ตั้งค่า</a></p>
          <div class="actions"><a class="btn" href="print.php?doc=check" target="_blank">สร้างรายงาน PDF</a><a class="btn" href="scan.php?mode=check">ไปสแกนตรวจนับ</a></div></div>
      </section>
    </div>
  </div>
</main>
<?php page_foot('<script type="module" nonce="' . csp_nonce() . '">
// ช่อง "เริ่มที่ดวงที่" ให้ผู้ใช้นับจาก 1 · ส่งไปเซิร์ฟเวอร์เป็นจำนวนดวงที่ข้าม
document.querySelectorAll("form").forEach((f) => f.addEventListener("formdata", (e) => { const v = e.formData.get("skip"); if (v !== null) e.formData.set("skip", Math.max(0, Number(v) - 1)); }));
</script>');
