<?php
// หน้าแรก: ยังไม่เข้าสู่ระบบ = แนะนำระบบ + ปุ่มเข้าสู่ระบบ (บัญชีตารางบริบูรณ์) · เข้าแล้ว = ภาพรวมครุภัณฑ์ของโรงเรียน
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/assets.php';
require __DIR__ . '/lib/layout.php';

$u = current_user();
// ข้อความจากตารางบริบูรณ์หลังเข้าสู่ระบบ/โหมดทดลอง → แสดงที่นี่แทน (ไม่ให้ค้างไปโผล่อีกระบบ)
if (isset($_SESSION['flash'])) {
    $m = (string) $_SESSION['flash'];
    unset($_SESSION['flash'], $_SESSION['flash_kind']);
    if (str_starts_with($m, 'เข้าสู่ระบบสำเร็จ')) flash($m, 'ok');
    elseif (str_starts_with($m, 'เข้าสู่โหมดทดลอง')) flash('เข้าสู่โหมดทดลองแล้ว ลองลงทะเบียนครุภัณฑ์ได้เลย (ข้อมูลลบเองใน 2 วัน)', 'ok');
}

if (!$u) {
    $ready = providers_ready();
    page_head('คลังบริบูรณ์ · ทะเบียนครุภัณฑ์โรงเรียนออนไลน์'); ?>
<main class="wrap">
  <header class="land-top">
    <a class="brand" href="index.php"><img src="assets/brand/mark-color.svg" alt="" width="34" height="34"><span>คลัง<span class="b">{</span>บริบูรณ์<span class="b">}</span></span></a>
    <a class="btn btn-sm" href="<?= h(tt_url('')) ?>">ตารางบริบูรณ์</a>
  </header>
  <section class="hero">
    <div>
      <p class="mono muted" style="margin:0;letter-spacing:.1em;font-size:.8rem">ระบบทะเบียนครุภัณฑ์โรงเรียน · ฟรี</p>
      <h1>ครุภัณฑ์ทุกชิ้น<br><em>มีเลข มีรูป สแกนเจอ</em></h1>
      <p class="lead">กรอกชื่อกับราคา ระบบออกเลขครุภัณฑ์ให้ตามรูปแบบของโรงเรียน ถ่ายรูปจากมือถือได้ทันที พิมพ์สติกเกอร์ QR ไปติด แล้วสแกนตรวจนับหรือยืม–คืนได้จากโทรศัพท์</p>
      <ul class="feats">
        <li><span class="k">01</span><span><b>ออกเลขอัตโนมัติ</b> รูปแบบ ประเภท-ชนิด-ลำดับ/ปีงบประมาณ ไม่ซ้ำ ไม่ข้าม ซื้อหลายชิ้นได้เลขเรียงกัน</span></li>
        <li><span class="k">02</span><span><b>รูปถ่าย + เลขเครื่อง</b> ระบบย่อรูปให้เล็กก่อนอัปโหลด ประหยัดพื้นที่และเน็ต</span></li>
        <li><span class="k">03</span><span><b>สติกเกอร์ QR/บาร์โค้ด</b> พิมพ์ลงกระดาษสติกเกอร์ A4 สแกนด้วยกล้องมือถือหรือเครื่องยิงบาร์โค้ด</span></li>
        <li><span class="k">04</span><span><b>ทะเบียนคุม · ยืม–คืน · สถานะ</b> คิดค่าเสื่อมราคาให้ ออก PDF พร้อมเซ็น</span></li>
      </ul>
    </div>
    <div class="panel login">
      <h2>เข้าใช้งาน</h2>
      <p class="hint" style="margin-top:-4px">ใช้บัญชีเดียวกับ <b>ตารางบริบูรณ์</b> — เคยสมัครแล้วเข้าได้เลย</p>
      <?= flash_script() ?>
      <?php if ($ready['google']): ?><a class="btn g-btn" href="go.php?to=google">เข้าสู่ระบบด้วย Google</a>
      <?php else: ?><button class="btn g-btn" disabled>เข้าสู่ระบบด้วย Google (เร็ว ๆ นี้)</button><?php endif; ?>
      <?php if ($ready['line']): ?><a class="btn line-btn" href="go.php?to=line">เข้าสู่ระบบด้วย LINE</a>
      <?php else: ?><button class="btn line-btn" disabled>เข้าสู่ระบบด้วย LINE (เร็ว ๆ นี้)</button><?php endif; ?>
      <div class="or">หรือ</div>
      <form method="post" action="go.php"><?= csrf_field() ?><input type="hidden" name="to" value="guest">
        <button class="btn btn-block" type="submit">ลองใช้ทันที ไม่ต้องสมัคร</button></form>
      <p class="hint">โหมดทดลองลบข้อมูลเองใน 2 วัน</p>
      <div class="label-demo" aria-hidden="true">
        <svg width="58" height="58" viewBox="0 0 29 29"><path fill="#0F2438" d="M0 0h7v7H0zM1 1v5h5V1zM2 2h3v3H2zM22 0h7v7h-7zM23 1v5h5V1zM24 2h3v3h-3zM0 22h7v7H0zM1 23v5h5v-5zM2 24h3v3H2zM9 0h2v2H9zM13 1h2v3h-2zM9 4h3v2H9zM17 2h3v2h-3zM9 9h4v2H9zM15 8h2v4h-2zM19 9h3v2h-3zM24 9h4v3h-4zM0 9h3v2H0zM4 12h3v3H4zM9 13h2v4H9zM13 14h5v2h-5zM20 13h2v5h-2zM24 15h5v2h-5zM0 16h2v4H0zM4 18h3v2H4zM12 18h3v3h-3zM17 19h2v2h-2zM23 20h3v3h-3zM9 23h3v2H9zM14 23h2v4h-2zM18 24h3v3h-3zM23 25h2v4h-2zM27 24h2v2h-2zM9 27h3v2H9z"/></svg>
        <div><div class="t">โรงเรียนตัวอย่าง · ครุภัณฑ์คอมพิวเตอร์</div><div class="n">7440-0109-0003/2569</div><div class="t">เครื่องคอมพิวเตอร์โน้ตบุ๊ก · ห้องคอม 1</div></div>
      </div>
    </div>
  </section>
</main>
<?php page_foot(); exit; }

[$u, $school] = require_school();
$sid = (int) $school['id'];
$set = settings($sid);
cats($sid); // เตรียมประเภทมาตรฐานครั้งแรก
if (random_int(1, 200) === 1) cleanup_orphans();

$fy = fiscal_year();
$sum = db_one("SELECT COUNT(*) AS n, COALESCE(SUM(price),0) AS v,
    SUM(CASE WHEN photos_json IS NULL OR photos_json = '[]' THEN 1 ELSE 0 END) AS nophoto,
    SUM(CASE WHEN checked_fy = ? THEN 1 ELSE 0 END) AS checked,
    SUM(CASE WHEN fy = ? THEN 1 ELSE 0 END) AS thisyear
    FROM kl_items WHERE school_id = ? AND status <> 'disposed'", [$fy, $fy, $sid]);
$total = (int) $sum['n'];
$byStatus = array_column(db_all('SELECT status, COUNT(*) AS n FROM kl_items WHERE school_id = ? GROUP BY status', [$sid]), 'n', 'status');
$byCat = db_all("SELECT i.cat_code, c.name, COUNT(*) AS n, SUM(i.price) AS v FROM kl_items i
    LEFT JOIN kl_cats c ON c.school_id = i.school_id AND c.code = i.cat_code
    WHERE i.school_id = ? AND i.status <> 'disposed' GROUP BY i.cat_code, c.name ORDER BY n DESC LIMIT 8", [$sid]);
$today = date('Y-m-d');
$loans = db_all('SELECT l.*, i.asset_no, i.name AS item_name FROM kl_loans l JOIN kl_items i ON i.id = l.item_id
    WHERE l.school_id = ? AND l.returned_on IS NULL ORDER BY l.due_on IS NULL, l.due_on LIMIT 8', [$sid]);
$openLoans = (int) db_val('SELECT COUNT(*) FROM kl_loans WHERE school_id = ? AND returned_on IS NULL', [$sid]);
$late = (int) db_val('SELECT COUNT(*) FROM kl_loans WHERE school_id = ? AND returned_on IS NULL AND due_on < ?', [$sid, $today]);
$feed = db_all('SELECT g.at, g.kind, g.detail, g.item_id, i.asset_no FROM kl_log g LEFT JOIN kl_items i ON i.id = g.item_id
    WHERE g.school_id = ? ORDER BY g.id DESC LIMIT 8', [$sid]);

$statusColor = ['normal' => '#1F5FA8', 'broken' => '#C8962E', 'fixing' => '#E3B85A', 'worn' => '#8A6212', 'lost' => '#B3261E',
    'unneeded' => '#9AA7AD', 'dispose' => '#E58FA8', 'disposed' => '#C5CEC9'];
$setupDone = $set['boss'] !== '' || $set['officer'] !== '';

page_head('ภาพรวม · คลังบริบูรณ์', 'home'); ?>
<main class="wrap page">
  <?= flash_script() ?>
  <div class="page-h">
    <div><h1>ภาพรวมครุภัณฑ์</h1><p><?= h($school['name']) ?> · ปีงบประมาณ <?= $fy ?></p></div>
    <div class="actions">
      <a class="btn" href="scan.php"><?= icon(KL_NAV['scan'][2], 18) ?>สแกน</a>
      <a class="btn btn-primary" href="edit.php">+ ลงทะเบียนครุภัณฑ์</a>
    </div>
  </div>

  <dl class="kpi">
    <div><dt>ครุภัณฑ์ในทะเบียน</dt><dd><?= number_format($total) ?></dd><small>ไม่นับที่จำหน่ายแล้ว · ปีนี้เพิ่ม <?= (int) $sum['thisyear'] ?></small></div>
    <div><dt>มูลค่าตามราคาทุน</dt><dd><?= number_format((float) $sum['v']) ?></dd><small>บาท</small></div>
    <div><dt>กำลังยืม/เบิกออก</dt><dd><?= $openLoans ?></dd><small><?= $late ? '<b style="color:var(--danger)">เกินกำหนดคืน ' . $late . ' รายการ</b>' : 'ไม่มีรายการเกินกำหนด' ?></small></div>
    <div><dt>ตรวจนับปี <?= $fy ?></dt><dd><?= (int) $sum['checked'] ?><span class="muted" style="font-size:1rem"> / <?= $total ?></span></dd><small><a href="scan.php?mode=check">สแกนตรวจนับประจำปี</a></small></div>
  </dl>

  <?php if ($total === 0): ?>
  <section class="panel" style="margin-top:16px">
    <div class="panel-h"><h2>เริ่มต้นใช้งาน 5 ขั้น</h2><span class="muted">ใช้เวลาไม่ถึง 10 นาที</span></div>
    <ol class="steps">
      <li class="<?= $setupDone ? 'done' : '' ?>"><span><a href="settings.php">ตั้งค่ารูปแบบเลขครุภัณฑ์และผู้ลงนาม</a> — ค่าเริ่มต้นใช้แบบ <span class="mono"><?= h(format_asset_no($set, '7440-0109', 1, $fy)) ?></span></span></li>
      <li><span><a href="edit.php">ลงทะเบียนครุภัณฑ์ชิ้นแรก</a> — เลือกประเภท ใส่ชื่อ ราคา วันที่ได้มา ระบบออกเลขให้</span></li>
      <li><span>ถ่ายรูปตัวเครื่องและป้ายเลขเครื่อง (ระบบย่อรูปให้เอง)</span></li>
      <li><span><a href="print.php">พิมพ์สติกเกอร์ QR</a> ลงกระดาษสติกเกอร์ A4 แล้วติดที่ตัวครุภัณฑ์</span></li>
      <li><span><a href="scan.php">สแกนด้วยมือถือ</a> เพื่อดูข้อมูล ยืม–คืน หรือตรวจนับประจำปี</span></li>
    </ol>
  </section>
  <?php else: ?>
  <div class="cols" style="margin-top:16px">
    <div>
      <section class="panel">
        <div class="panel-h"><h2>สถานะ</h2><a class="muted" href="items.php">ดูทะเบียนทั้งหมด</a></div>
        <div class="panel-b">
          <?php $all = array_sum(array_map('intval', $byStatus)) ?: 1; ?>
          <div class="stack" role="img" aria-label="สัดส่วนสถานะครุภัณฑ์">
            <?php foreach (KL_STATUS as $k => [$label]): if (empty($byStatus[$k])) continue; ?>
              <span style="width:<?= round($byStatus[$k] / $all * 100, 2) ?>%;background:<?= $statusColor[$k] ?>" title="<?= h($label) ?> <?= (int) $byStatus[$k] ?>"></span>
            <?php endforeach; ?>
          </div>
          <ul class="legend">
            <?php foreach (KL_STATUS as $k => [$label]): if (empty($byStatus[$k])) continue; ?>
              <li><a href="items.php?status=<?= $k ?>" style="color:inherit;text-decoration:none"><i style="background:<?= $statusColor[$k] ?>"></i><?= h($label) ?> <b class="mono"><?= (int) $byStatus[$k] ?></b></a></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </section>
      <section class="panel">
        <div class="panel-h"><h2>แยกตามประเภท</h2><span class="muted">จำนวนชิ้น · มูลค่า (บาท)</span></div>
        <div class="panel-b">
          <?php $max = max(array_map(fn($r) => (int) $r['n'], $byCat) ?: [1]); ?>
          <ul class="bars">
            <?php foreach ($byCat as $r): ?>
              <li><a class="lab" href="items.php?cat=<?= h($r['cat_code']) ?>" style="color:inherit" title="<?= h($r['cat_code'] . ' ' . $r['name']) ?>"><?= h($r['name'] ?: $r['cat_code']) ?></a>
                <span class="track"><span class="fill" style="width:<?= round($r['n'] / $max * 100, 1) ?>%"></span></span>
                <span class="val"><?= (int) $r['n'] ?> · <?= number_format((float) $r['v']) ?></span></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </section>
      <?php if ((int) $sum['nophoto'] > 0): ?>
      <p class="hint" style="margin-top:10px">มีครุภัณฑ์ที่ยังไม่มีรูป <?= (int) $sum['nophoto'] ?> ชิ้น · <a href="items.php?nophoto=1">ดูรายการ</a></p>
      <?php endif; ?>
    </div>
    <div>
      <section class="panel">
        <div class="panel-h"><h2>ยืมอยู่</h2><a class="muted" href="loans.php">ทั้งหมด</a></div>
        <?php if (!$loans): ?><p class="empty">ไม่มีครุภัณฑ์ที่ยืมออกไป</p><?php else: ?>
        <ul class="feed">
          <?php foreach ($loans as $l): $isLate = $l['due_on'] && $l['due_on'] < $today; ?>
            <li><a class="rowlink" href="item.php?id=<?= (int) $l['item_id'] ?>"><?= h($l['item_name']) ?></a> <span class="no muted"><?= h($l['asset_no']) ?></span>
              <time><?= h($l['borrower']) ?> · คืน <?= th_date($l['due_on']) ?> <?= $isLate ? '<span class="st st-late">เกินกำหนด</span>' : '' ?></time></li>
          <?php endforeach; ?>
        </ul><?php endif; ?>
      </section>
      <section class="panel">
        <div class="panel-h"><h2>ความเคลื่อนไหวล่าสุด</h2></div>
        <ul class="feed">
          <?php foreach ($feed as $f): ?>
            <li><?= $f['item_id'] ? '<a class="no" href="item.php?id=' . (int) $f['item_id'] . '">' . h($f['asset_no'] ?? '') . '</a> ' : '' ?><?= h(mb_strimwidth((string) $f['detail'], 0, 120, '…')) ?><time><?= th_date($f['at'], true) ?></time></li>
          <?php endforeach; ?>
        </ul>
      </section>
    </div>
  </div>
  <?php endif; ?>
</main>
<?php page_foot();
