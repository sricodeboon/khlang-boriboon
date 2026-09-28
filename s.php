<?php
// ปลายทางของ QR บนสติกเกอร์: คนในโรงเรียน (เข้าสู่ระบบแล้ว) → หน้ารายละเอียดเต็ม · คนทั่วไป → ข้อมูลยืนยันตัวตนครุภัณฑ์แบบย่อ (ไม่มีราคา/เลขเครื่อง/ผู้ยืม)
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/assets.php';
require __DIR__ . '/lib/layout.php';

$t = strtoupper((string) ($_GET['t'] ?? ''));
$item = preg_match('/^[0-9A-Z]{10}$/', $t) ? db_one('SELECT * FROM kl_items WHERE token = ?', [$t]) : null;
$u = current_user();
if ($item && $u && (int) $u['school_id'] === (int) $item['school_id']) redirect('item.php?id=' . (int) $item['id']);
if (!$item) http_response_code(404);
$school = $item ? db_one('SELECT name, amphoe, province FROM schools WHERE id = ?', [$item['school_id']]) : null;
header('X-Robots-Tag: noindex');

page_head($item ? $item['asset_no'] . ' · คลังบริบูรณ์' : 'ไม่พบครุภัณฑ์ · คลังบริบูรณ์'); ?>
<main class="wrap page" style="max-width:560px">
  <header class="land-top"><a class="brand" href="index.php"><img src="assets/brand/mark-color.svg" alt="" width="30" height="30"><span>คลัง<span class="b">{</span>บริบูรณ์<span class="b">}</span></span></a></header>
  <?php if (!$item): ?>
    <section class="panel empty"><b>ไม่พบครุภัณฑ์จาก QR นี้</b>สติกเกอร์อาจถูกยกเลิก หรือรายการถูกลบจากทะเบียนแล้ว</section>
  <?php else: ?>
    <section class="panel">
      <div class="panel-h"><h2>ครุภัณฑ์ของทางราชการ</h2><?= status_badge($item['status']) ?></div>
      <dl class="dl">
        <dt>เลขครุภัณฑ์</dt><dd class="no"><?= h($item['asset_no']) ?></dd>
        <dt>รายการ</dt><dd><?= h($item['name']) ?></dd>
        <dt>หน่วยงาน</dt><dd><?= h($school['name'] ?? '–') ?><?= $school ? '<span class="sub">อ.' . h($school['amphoe']) . ' จ.' . h($school['province']) . '</span>' : '' ?></dd>
        <dt>สถานที่ตั้ง</dt><dd><?= h($item['location'] ?: '–') ?></dd>
      </dl>
    </section>
    <p class="hint" style="margin-top:12px">พบครุภัณฑ์นี้นอกสถานที่หรือชำรุด กรุณาแจ้งโรงเรียน · เจ้าหน้าที่ของโรงเรียน <a href="index.php">เข้าสู่ระบบ</a> เพื่อดูข้อมูลเต็มและบันทึกยืม–คืน</p>
  <?php endif; ?>
</main>
<?php page_foot();
