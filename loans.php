<?php
// ทะเบียนยืม/เบิก–คืน: ค้างคืน (เรียงตามกำหนดคืน, เกินกำหนดขึ้นก่อน) · ประวัติที่คืนแล้ว
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/assets.php';
require __DIR__ . '/lib/layout.php';

[$u, $school] = require_school();
$sid = (int) $school['id'];
$today = date('Y-m-d');

// ยืมใหม่: พิมพ์/ยิงเลขครุภัณฑ์ → ไปฟอร์มยืมของชิ้นนั้น
if (isset($_GET['code'])) {
    $it = item_by_code($sid, (string) $_GET['code']);
    if ($it) redirect('item.php?id=' . (int) $it['id'] . ($it['on_loan'] ? '#return' : '#loan'));
    flash('ไม่พบครุภัณฑ์ “' . mb_substr((string) $_GET['code'], 0, 60) . '”', 'error');
    redirect('loans.php');
}

$open = db_all('SELECT l.*, i.asset_no, i.name AS item_name FROM kl_loans l JOIN kl_items i ON i.id = l.item_id
    WHERE l.school_id = ? AND l.returned_on IS NULL ORDER BY l.due_on IS NULL, l.due_on, l.id', [$sid]);
$done = db_all('SELECT l.*, i.asset_no, i.name AS item_name FROM kl_loans l JOIN kl_items i ON i.id = l.item_id
    WHERE l.school_id = ? AND l.returned_on IS NOT NULL ORDER BY l.returned_on DESC, l.id DESC LIMIT 100', [$sid]);

page_head('ยืม–คืน · คลังบริบูรณ์', 'loans'); ?>
<main class="wrap page">
  <?= flash_script() ?>
  <div class="page-h">
    <div><h1>ยืม–คืนครุภัณฑ์</h1><p>ค้างคืน <?= count($open) ?> รายการ · ครบกำหนดแล้วต้องติดตามทวงคืนภายใน 7 วัน (ระเบียบฯ ข้อ 211)</p></div>
    <form class="actions" method="get" role="search">
      <input class="input mono" name="code" required placeholder="เลขครุภัณฑ์ที่จะยืม/คืน" aria-label="เลขครุภัณฑ์" style="width:16rem">
      <button class="btn btn-primary" type="submit">ถัดไป</button>
      <a class="btn" href="scan.php">สแกน</a>
    </form>
  </div>

  <section class="panel">
    <div class="panel-h"><h2>ค้างคืน</h2></div>
    <?php if (!$open): ?><p class="empty">ไม่มีครุภัณฑ์ค้างคืน</p><?php else: ?>
    <div class="scroll-x"><table class="table">
      <thead><tr><th>ครุภัณฑ์</th><th>ผู้ยืม/ผู้เบิก</th><th>วันที่ยืม</th><th>กำหนดคืน</th><th>ติดตาม</th><th></th></tr></thead>
      <tbody><?php foreach ($open as $l):
        $late = $l['due_on'] && $l['due_on'] < $today;
        $chase = $l['due_on'] ? date('Y-m-d', strtotime($l['due_on'] . ' +7 days')) : null; ?>
        <tr>
          <td><a class="no rowlink" href="item.php?id=<?= (int) $l['item_id'] ?>"><?= h($l['asset_no']) ?></a><span class="sub"><?= h($l['item_name']) ?></span></td>
          <td><?= h($l['borrower']) ?><span class="sub"><?= $l['kind'] === 'issue' ? 'เบิกไปประจำใช้' : 'ยืม' ?><?= $l['place'] ? ' · ' . h($l['place']) : '' ?></span></td>
          <td><?= th_date($l['out_on']) ?></td>
          <td><?= $l['due_on'] ? th_date($l['due_on']) : '–' ?></td>
          <td><?php if ($late): ?><span class="st st-late">เกิน <?= (int) round((strtotime($today) - strtotime($l['due_on'])) / 86400) ?> วัน</span><span class="sub">ทวงคืนภายใน <?= th_date($chase) ?></span><?php else: ?><span class="muted">–</span><?php endif; ?></td>
          <td><div class="actions"><a class="btn btn-sm" href="item.php?id=<?= (int) $l['item_id'] ?>#return">รับคืน</a><a class="btn btn-sm" href="print.php?doc=loan&amp;id=<?= (int) $l['id'] ?>" target="_blank">ใบยืม</a></div></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
  </section>

  <section class="panel">
    <div class="panel-h"><h2>คืนแล้ว</h2><span class="muted">100 รายการล่าสุด</span></div>
    <?php if (!$done): ?><p class="empty">ยังไม่มีประวัติ</p><?php else: ?>
    <div class="scroll-x"><table class="table">
      <thead><tr><th>ครุภัณฑ์</th><th>ผู้ยืม</th><th>ยืม</th><th>คืน</th><th>บันทึก</th></tr></thead>
      <tbody><?php foreach ($done as $l): ?>
        <tr><td><a class="no rowlink" href="item.php?id=<?= (int) $l['item_id'] ?>"><?= h($l['asset_no']) ?></a><span class="sub"><?= h($l['item_name']) ?></span></td>
          <td><?= h($l['borrower']) ?></td><td><?= th_date($l['out_on']) ?></td>
          <td><?= th_date($l['returned_on']) ?><?= $l['due_on'] && $l['returned_on'] > $l['due_on'] ? ' <span class="st st-late">ช้า</span>' : '' ?></td>
          <td><?= h($l['return_note'] ?: '–') ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
  </section>
</main>
<?php page_foot();
