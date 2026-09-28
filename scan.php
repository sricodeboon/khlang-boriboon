<?php
// สแกน QR/บาร์โค้ดด้วยกล้องมือถือ หรือเครื่องยิงบาร์โค้ด (พิมพ์ลงช่องค้นหา)
// โหมดดูข้อมูล: เจอแล้วแสดงการ์ด + ปุ่มเปิด/ยืม/คืน · โหมดตรวจนับประจำปี: เจอแล้วกดยืนยันพบ (บันทึกปีงบประมาณที่ตรวจ)
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/assets.php';
require __DIR__ . '/lib/layout.php';

[$u, $school] = require_school();
$sid = (int) $school['id'];
$mode = ($_GET['mode'] ?? '') === 'check' ? 'check' : 'view';
$fy = fiscal_year();
$done = (int) db_val("SELECT COUNT(*) FROM kl_items WHERE school_id = ? AND checked_fy = ? AND status <> 'disposed'", [$sid, $fy]);
$all = (int) db_val("SELECT COUNT(*) FROM kl_items WHERE school_id = ? AND status <> 'disposed'", [$sid]);

page_head('สแกน · คลังบริบูรณ์', 'scan'); ?>
<main class="wrap page">
  <?= flash_script() ?>
  <div class="page-h">
    <div><h1><?= $mode === 'check' ? 'ตรวจนับประจำปี ' . $fy : 'สแกนครุภัณฑ์' ?></h1>
      <p><?= $mode === 'check' ? 'สแกนทีละชิ้น แล้วกด “ยืนยันพบ” · ระเบียบฯ ข้อ 213 ให้ผู้ที่ไม่ใช่เจ้าหน้าที่พัสดุเป็นผู้ตรวจ' : 'ส่องกล้องที่สติกเกอร์ QR หรือบาร์โค้ด หรือพิมพ์/ยิงเลขลงช่องด้านขวา' ?></p></div>
    <nav class="seg" aria-label="โหมด">
      <a href="scan.php" class="<?= $mode === 'view' ? 'on' : '' ?>">ดูข้อมูล</a>
      <a href="scan.php?mode=check" class="<?= $mode === 'check' ? 'on' : '' ?>">ตรวจนับประจำปี</a>
    </nav>
  </div>

  <div class="scan" data-mode="<?= $mode ?>">
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
