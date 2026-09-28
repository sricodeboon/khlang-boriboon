<?php
// ทะเบียนครุภัณฑ์: ค้นหา · กรองตามประเภท/สถานะ/สถานที่ · เลือกหลายชิ้นเพื่อพิมพ์สติกเกอร์หรือทะเบียนคุม
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/assets.php';
require __DIR__ . '/lib/layout.php';

[$u, $school] = require_school();
$sid = (int) $school['id'];
$cats = cats($sid);
$q = trim((string) ($_GET['q'] ?? ''));
$cat = (string) ($_GET['cat'] ?? '');
$status = (string) ($_GET['status'] ?? '');
$place = (string) ($_GET['place'] ?? '');
$view = (string) ($_GET['view'] ?? '');
$batch = (string) ($_GET['batch'] ?? '');
$page = max(1, (int) ($_GET['p'] ?? 1));
const PER = 50;

$where = ['school_id = ?'];
$args = [$sid];
if ($q !== '') {
    $where[] = '(asset_no LIKE ? OR name LIKE ? OR serial_no LIKE ? OR brand LIKE ? OR model LIKE ? OR custodian LIKE ? OR token = ?)';
    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
    array_push($args, $like, $like, $like, $like, $like, $like, strtoupper($q));
}
if (isset($cats[$cat])) { $where[] = 'cat_code = ?'; $args[] = $cat; }
if (isset(KL_STATUS[$status])) { $where[] = 'status = ?'; $args[] = $status; }
elseif ($view !== 'all') { $where[] = "status <> 'disposed'"; }
if ($place !== '') { $where[] = 'location = ?'; $args[] = $place; }
if (!empty($_GET['nophoto'])) $where[] = "(photos_json IS NULL OR photos_json = '[]')";
if (!empty($_GET['loan'])) $where[] = 'on_loan = 1';
if ($view === 'unchecked') { $where[] = '(checked_fy IS NULL OR checked_fy <> ?)'; $args[] = fiscal_year(); }
if (preg_match('/^(\d+)-(\d+)$/', $batch, $m)) { $where[] = 'id BETWEEN ? AND ?'; array_push($args, (int) $m[1], (int) $m[2]); }
$w = implode(' AND ', $where);
$total = (int) db_val("SELECT COUNT(*) FROM kl_items WHERE $w", $args);
$sumPrice = (float) db_val("SELECT COALESCE(SUM(price),0) FROM kl_items WHERE $w", $args);
$pages = max(1, (int) ceil($total / PER));
$page = min($page, $pages);
$rows = db_all("SELECT id, asset_no, cat_code, name, brand, model, serial_no, price, acquired_on, location, custodian, status, on_loan, thumb, checked_fy
    FROM kl_items WHERE $w ORDER BY cat_code, fy, seq, id LIMIT " . PER . ' OFFSET ' . (($page - 1) * PER), $args);
$places = array_column(db_all("SELECT DISTINCT location FROM kl_items WHERE school_id = ? AND location <> '' ORDER BY location", [$sid]), 'location');
$usedCats = array_column(db_all('SELECT DISTINCT cat_code FROM kl_items WHERE school_id = ?', [$sid]), 'cat_code');
$qs = fn(array $over) => '?' . http_build_query(array_filter(array_merge(['q' => $q, 'cat' => $cat, 'status' => $status, 'place' => $place, 'view' => $view, 'batch' => $batch], $over), fn($x) => $x !== '' && $x !== null));
$fy = fiscal_year();

page_head('ทะเบียนครุภัณฑ์ · คลังบริบูรณ์', 'items'); ?>
<main class="wrap page">
  <?= flash_script() ?>
  <div class="page-h">
    <div><h1>ทะเบียนครุภัณฑ์</h1><p><?= number_format($total) ?> รายการ · รวม <?= money($sumPrice) ?> บาท</p></div>
    <div class="actions">
      <a class="btn" href="print.php?doc=list&amp;<?= h(ltrim($qs([]), '?')) ?>" target="_blank">พิมพ์บัญชีนี้ PDF</a>
      <a class="btn btn-primary" href="edit.php">+ ลงทะเบียน</a>
    </div>
  </div>

  <section class="panel">
    <form class="filters" method="get" role="search">
      <input class="input grow" type="search" name="q" value="<?= h($q) ?>" placeholder="ค้นหาเลขครุภัณฑ์ ชื่อ เลขเครื่อง ยี่ห้อ ผู้รับผิดชอบ" aria-label="ค้นหา" autofocus>
      <select class="input" name="cat" data-autosubmit aria-label="ประเภท"><option value="">ทุกประเภท</option>
        <?php foreach ($cats as $c): if (!in_array($c['code'], $usedCats, true)) continue; ?><option value="<?= h($c['code']) ?>"<?= $c['code'] === $cat ? ' selected' : '' ?>><?= h($c['code'] . ' ' . $c['name']) ?></option><?php endforeach; ?>
      </select>
      <select class="input" name="status" data-autosubmit aria-label="สถานะ"><option value="">ทุกสถานะ (ยกเว้นจำหน่ายแล้ว)</option><?= options(array_map(fn($s) => $s[0], KL_STATUS), $status, true) ?></select>
      <?php if ($places): ?><select class="input" name="place" data-autosubmit aria-label="สถานที่"><option value="">ทุกสถานที่</option><?= options($places, $place) ?></select><?php endif; ?>
      <?php if ($view): ?><input type="hidden" name="view" value="<?= h($view) ?>"><?php endif; ?>
      <button class="btn" type="submit">ค้นหา</button>
      <?php if ($q || $cat || $status || $place || $view || $batch || !empty($_GET['nophoto']) || !empty($_GET['loan'])): ?><a class="btn" href="items.php">ล้างตัวกรอง</a><?php endif; ?>
    </form>
    <div class="filters" style="padding-block:8px">
      <nav class="seg" aria-label="มุมมอง">
        <a href="<?= h($qs(['view' => null])) ?>" class="<?= $view === '' ? 'on' : '' ?>">ใช้อยู่</a>
        <a href="<?= h($qs(['view' => 'unchecked'])) ?>" class="<?= $view === 'unchecked' ? 'on' : '' ?>">ยังไม่ตรวจนับปี <?= $fy ?></a>
        <a href="<?= h($qs(['view' => 'all'])) ?>" class="<?= $view === 'all' ? 'on' : '' ?>">ทั้งหมด</a>
      </nav>
      <?php if ($batch): ?><span class="st st-warn">ชุดที่เพิ่งลงทะเบียน</span><?php endif; ?>
    </div>

    <?php if (!$rows): ?>
      <div class="empty"><b><?= $total === 0 && !$q && !$cat && !$status ? 'ยังไม่มีครุภัณฑ์ในทะเบียน' : 'ไม่พบรายการที่ตรงกับตัวกรอง' ?></b>
        <?php if ($total === 0 && !$q): ?><a class="btn btn-primary" href="edit.php" style="margin-top:10px">+ ลงทะเบียนชิ้นแรก</a><?php endif; ?></div>
    <?php else: ?>
    <form method="get" action="print.php" target="_blank">
      <div class="scroll-x"><table class="table">
        <thead><tr>
          <th style="width:36px"><input type="checkbox" class="check" data-check-all aria-label="เลือกทั้งหมดในหน้านี้"<?= $batch ? ' checked' : '' ?>></th>
          <th style="width:56px" class="hide-m"><span class="sr">รูป</span></th><th>เลขครุภัณฑ์</th><th>รายการ</th><th class="hide-m">สถานที่ / ผู้รับผิดชอบ</th><th class="num">ราคา</th><th>สถานะ</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" aria-label="เลือก <?= h($r['asset_no']) ?>"<?= $batch ? ' checked' : '' ?>></td>
            <td class="hide-m"><?= $r['thumb'] ? '<img class="thumb" src="' . h($r['thumb']) . '" alt="" loading="lazy">' : '<span class="thumb-empty" title="ยังไม่มีรูป">–</span>' ?></td>
            <td><a class="no rowlink" href="item.php?id=<?= (int) $r['id'] ?>"><?= h($r['asset_no']) ?></a></td>
            <td><a class="rowlink" href="item.php?id=<?= (int) $r['id'] ?>"><?= h($r['name']) ?></a><span class="sub"><?= h(trim($r['brand'] . ' ' . $r['model'])) ?><?= $r['serial_no'] ? ' · S/N <span class="mono">' . h($r['serial_no']) . '</span>' : '' ?></span></td>
            <td class="hide-m"><?= h($r['location'] ?: '–') ?><span class="sub"><?= h($r['custodian'] ?: '') ?></span></td>
            <td class="num"><?= money($r['price']) ?><span class="sub"><?= th_date($r['acquired_on']) ?></span></td>
            <td><?= status_badge($r['status']) ?><?= $r['on_loan'] ? ' <span class="st st-loan">ถูกยืม</span>' : '' ?><?= (int) $r['checked_fy'] === $fy ? '<span class="sub">ตรวจนับแล้ว</span>' : '' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="bulk">
        <span>เลือก <b data-check-count>0</b> รายการ</span>
        <button class="btn btn-sm" name="doc" value="sticker" data-needs-check>พิมพ์สติกเกอร์ QR</button>
        <button class="btn btn-sm" name="doc" value="register" data-needs-check>ทะเบียนคุม PDF</button>
      </div>
    </form>
    <?php if ($pages > 1): ?>
      <nav class="pager" aria-label="หน้า">
        <?php if ($page > 1): ?><a class="btn btn-sm" href="<?= h($qs(['p' => $page - 1])) ?>">← ก่อนหน้า</a><?php endif; ?>
        <span class="muted" style="align-self:center">หน้า <?= $page ?> / <?= $pages ?></span>
        <?php if ($page < $pages): ?><a class="btn btn-sm" href="<?= h($qs(['p' => $page + 1])) ?>">ถัดไป →</a><?php endif; ?>
      </nav>
    <?php endif; ?>
    <?php endif; ?>
  </section>
</main>
<?php page_foot();
