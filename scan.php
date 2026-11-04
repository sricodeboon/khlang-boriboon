<?php
// สแกน QR/บาร์โค้ดด้วยกล้องมือถือ หรือเครื่องยิงบาร์โค้ด (พิมพ์ลงช่องค้นหา)
// โหมดดูข้อมูล: เจอแล้วแสดงการ์ด + ปุ่มเปิด/ยืม/คืน · โหมดตรวจนับประจำปี: เจอแล้วกดยืนยันพบ (บันทึกปีงบประมาณที่ตรวจ)
// โหมด loan/return: สแกนสะสมเป็นตะกร้า แล้วยืม/เบิก/รับคืนพร้อมกันทั้งชุด (assets/scan-cart.js)
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/assets.php';
require __DIR__ . '/lib/layout.php';

[$u, $school] = require_school();
$sid = (int) $school['id'];
$fy = fiscal_year();
$mode = in_array($_GET['mode'] ?? '', ['check', 'loan', 'return'], true) ? $_GET['mode'] : 'view';
$isCart = $mode === 'loan' || $mode === 'return';
$set = settings($sid);
$today = date('Y-m-d');
// รายการที่ส่งมาจากหน้าอื่น: ติ๊กในทะเบียน (ids[]) หรือรับคืนทั้งชุด (batch)
$preIds = is_array($_GET['ids'] ?? null) ? implode(',', array_map('intval', $_GET['ids'])) : '';
$preBatch = (int) ($_GET['batch'] ?? 0);
$people = $mode === 'loan' ? array_column(db_all('SELECT DISTINCT borrower FROM kl_loans WHERE school_id = ? ORDER BY borrower LIMIT 200', [$sid]), 'borrower') : [];
$titles = ['view' => 'สแกนครุภัณฑ์', 'loan' => 'ยืม/เบิกหลายรายการ', 'return' => 'รับคืนหลายรายการ', 'check' => 'ตรวจนับประจำปี ' . $fy];
$leads = [
    'view' => 'ส่องกล้องที่สติกเกอร์ QR หรือบาร์โค้ด หรือพิมพ์/ยิงเลขลงช่องด้านขวา',
    'loan' => 'สแกนหรือพิมพ์เลขไปเรื่อย ๆ รายการจะสะสมไว้ แล้วกรอกผู้ยืมครั้งเดียว ได้ใบยืมใบเดียว',
    'return' => 'สแกนของที่นำมาคืนทีละชิ้น เลือกสภาพแยกแต่ละชิ้นได้ แล้วบันทึกพร้อมกัน',
    'check' => 'สแกนทีละชิ้น แล้วกด “ยืนยันพบ” · ระเบียบฯ ข้อ 213 ให้ผู้ที่ไม่ใช่เจ้าหน้าที่พัสดุเป็นผู้ตรวจ',
];
$done = (int) db_val("SELECT COUNT(*) FROM kl_items WHERE school_id = ? AND checked_fy = ? AND status <> 'disposed'", [$sid, $fy]);
$all = (int) db_val("SELECT COUNT(*) FROM kl_items WHERE school_id = ? AND status <> 'disposed'", [$sid]);

page_head('สแกน · คลังบริบูรณ์', 'scan'); ?>
<main class="wrap page">
  <?= flash_script() ?>
  <div class="page-h">
    <div><h1><?= h($titles[$mode]) ?></h1><p><?= h($leads[$mode]) ?></p></div>
    <nav class="seg seg-scroll" aria-label="โหมด">
      <a href="scan.php" class="<?= $mode === 'view' ? 'on' : '' ?>">ดูข้อมูล</a>
      <a href="scan.php?mode=loan" class="<?= $mode === 'loan' ? 'on' : '' ?>">ยืม/เบิกหลายชิ้น</a>
      <a href="scan.php?mode=return" class="<?= $mode === 'return' ? 'on' : '' ?>">คืนหลายชิ้น</a>
      <a href="scan.php?mode=check" class="<?= $mode === 'check' ? 'on' : '' ?>">ตรวจนับ</a>
    </nav>
  </div>

  <div class="scan" data-mode="<?= $mode ?>" data-sid="<?= $sid ?>" data-ids="<?= h($preIds) ?>" data-batch="<?= $preBatch ?: '' ?>">
    <div>
      <div class="viewer" id="viewer">
        <video id="video" playsinline muted hidden></video>
        <div class="aim" hidden id="aim"></div>
        <div class="msg" id="cam-msg"><div>
          <p style="margin:0 0 12px">ใช้กล้องสแกน QR/บาร์โค้ดบนสติกเกอร์ครุภัณฑ์</p>
          <button class="btn btn-gold" type="button" id="cam-start">เปิดกล้อง</button>
        </div></div>
      </div>
      <div class="actions" style="margin-top:8px">
        <button class="btn btn-sm" type="button" id="cam-stop" hidden>ปิดกล้อง</button>
        <button class="btn btn-sm" type="button" id="cam-flip" hidden>สลับกล้อง</button>
        <span class="hint" id="engine"></span>
      </div>
    </div>

    <div class="grid" style="gap:16px">
      <form class="panel panel-b" id="manual" autocomplete="off">
        <label for="code" class="lbl" style="font-size:.86rem;color:var(--muted)">เลขครุภัณฑ์ / รหัส QR / เลขเครื่อง</label>
        <div style="display:flex;gap:8px;margin-top:4px"><input class="input mono" id="code" name="code" required autofocus inputmode="text" placeholder="เช่น 7440-0109-0001/2569"><button class="btn btn-primary" type="submit">ค้นหา</button></div>
        <p class="hint" style="margin-top:6px">เครื่องยิงบาร์โค้ดแบบ USB/บลูทูธ ยิงใส่ช่องนี้ได้เลย</p>
      </form>

      <section class="panel scan-result" id="result" hidden aria-live="polite"></section>

      <?php if ($isCart): ?>
      <section class="panel" id="cart">
        <div class="panel-h"><h2>รายการที่เลือก <span class="mono" id="cart-count">0</span></h2><button class="btn btn-sm" type="button" id="cart-clear" hidden>ล้างรายการ</button></div>
        <p class="cart-msg" id="cart-msg" role="alert" hidden></p>
        <p class="empty" id="cart-empty">ยังไม่มีรายการ เริ่มสแกนได้เลย</p>
        <ul class="cart-list" id="cart-list"></ul>
        <?php if ($mode === 'loan'): ?>
        <form class="panel-b grid cart-form" id="cart-form" autocomplete="off" hidden>
          <div class="seg" role="radiogroup" aria-label="ชนิด">
            <label class="check" style="padding:6px 12px"><input type="radio" name="kind" value="loan" checked> ยืม (มีกำหนดคืน)</label>
            <label class="check" style="padding:6px 12px;border-left:1px solid var(--line-2)"><input type="radio" name="kind" value="issue"> เบิกไปประจำใช้</label>
          </div>
          <div class="field"><label for="borrower">ผู้ยืม/ผู้เบิก <span class="req">*</span></label><input class="input" id="borrower" name="borrower" required maxlength="200" list="dl-borrower" placeholder="ชื่อ–สกุล ตำแหน่ง"></div>
          <div class="field"><label for="purpose">เพื่อใช้ในงาน</label><input class="input" id="purpose" name="purpose" maxlength="500"></div>
          <div class="field"><label for="place">นำไปใช้ที่</label><input class="input" id="place" name="place" maxlength="200"></div>
          <div class="grid g2">
            <div class="field"><label for="out_on">วันที่ยืม</label><input class="input" type="date" id="out_on" name="out_on" value="<?= $today ?>" max="<?= $today ?>"></div>
            <div class="field" id="due-field"><label for="due_on">กำหนดส่งคืน</label><input class="input" type="date" id="due_on" name="due_on" value="<?= date('Y-m-d', strtotime('+' . (int) $set['loan_days'] . ' days')) ?>"></div>
          </div>
          <div class="field"><label for="approver">ผู้อนุมัติ</label><input class="input" id="approver" name="approver" maxlength="200" value="<?= h($set['boss']) ?>"></div>
          <button class="btn btn-primary" type="submit" id="cart-submit">บันทึก</button>
          <datalist id="dl-borrower"><?php foreach ($people as $p): ?><option value="<?= h($p) ?>"><?php endforeach; ?></datalist>
        </form>
        <?php else: ?>
        <form class="panel-b grid cart-form" id="cart-form" autocomplete="off" hidden>
          <div class="field"><label for="returned_on">วันที่คืน</label><input class="input" type="date" id="returned_on" name="returned_on" value="<?= $today ?>" max="<?= $today ?>"></div>
          <button class="btn btn-gold" type="submit" id="cart-submit">บันทึก</button>
        </form>
        <?php endif; ?>
        <div class="panel-b cart-done" id="cart-done" hidden>
          <b id="cart-done-text"></b>
          <div class="actions" style="margin-top:10px"><a class="btn btn-primary" id="cart-done-pdf" target="_blank" hidden></a><a class="btn" href="loans.php">ดูหน้ายืม–คืน</a></div>
        </div>
      </section>
      <?php endif; ?>

      <?php if ($mode === 'check'): ?>
      <section class="panel panel-b">
        <div style="display:flex;justify-content:space-between;align-items:baseline"><b>ตรวจแล้ว</b><span class="mono"><span id="done"><?= $done ?></span> / <span id="all"><?= $all ?></span></span></div>
        <div class="stack" style="margin:8px 0"><span id="bar" style="width:<?= $all ? round($done / $all * 100, 1) : 0 ?>%;background:#1F5FA8"></span></div>
        <div class="actions"><a class="btn btn-sm" href="items.php?view=unchecked">รายการที่ยังไม่พบ</a><a class="btn btn-sm" href="print.php?doc=check" target="_blank">รายงานผลการตรวจสอบ PDF</a></div>
      </section>
      <?php endif; ?>
    </div>
  </div>
</main>
<template id="t-status"><?= options(array_map(fn($s) => $s[0], array_diff_key(KL_STATUS, ['disposed' => 1])), 'normal', true) ?></template>
<?php page_foot('<script type="module" nonce="' . csp_nonce() . '" src="' . asset_v('assets/scan.js') . '"></script>');
