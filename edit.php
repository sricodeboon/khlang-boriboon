<?php
// ลงทะเบียนครุภัณฑ์ใหม่ (ออกเลขอัตโนมัติ ซื้อหลายชิ้นได้เลขเรียงกัน) / แก้ไขรายละเอียด (เลขเดิมไม่เปลี่ยน)
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/assets.php';
require __DIR__ . '/lib/photos.php';
require __DIR__ . '/lib/layout.php';

[$u, $school] = require_school();
$sid = (int) $school['id'];
$cats = cats($sid);
$id = (int) ($_GET['id'] ?? 0);
$item = $id ? item_get($sid, $id) : null;
if ($id && !$item) { flash('ไม่พบครุภัณฑ์นี้'); redirect('items.php'); }
const GUEST_MAX_ITEMS = 40;

if (is_post()) {
    csrf_check();
    try {
        $f = item_fields_from_post($cats);
        $tmp = uploaded_photos();
        if ($item) {
            item_update($item, $f, (int) $u['id']);
            if ($tmp) add_photos($sid, [$id], $tmp, (int) $u['id'], (string) ($_POST['thumb'] ?? ''));
            flash('บันทึกการแก้ไขแล้ว', 'ok');
            redirect('item.php?id=' . $id);
        }
        $qty = max(1, min(100, (int) post('qty', 3)));
        if (is_guest($u) && (int) db_val('SELECT COUNT(*) FROM kl_items WHERE school_id = ?', [$sid]) + $qty > GUEST_MAX_ITEMS) {
            throw new InvalidArgumentException('โหมดทดลองลงทะเบียนได้ไม่เกิน ' . GUEST_MAX_ITEMS . ' ชิ้น');
        }
        $manual = !empty($_POST['use_old']) ? post('old_no', 80) : null;
        if ($manual === '') throw new InvalidArgumentException('กรุณากรอกเลขครุภัณฑ์เดิม');
        if ($manual !== null && $qty > 1) throw new InvalidArgumentException('ใส่เลขเดิมได้ทีละ 1 ชิ้น');
        $serials = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) ($_POST['serials'] ?? '')) ?: [])));
        if ($qty === 1 && $f['serial_no'] !== '') $serials = [$f['serial_no']];
        $ids = items_create($sid, (int) $u['id'], $f, $qty, $serials, $manual);
        if ($tmp) add_photos($sid, $ids, $tmp, (int) $u['id'], (string) ($_POST['thumb'] ?? ''));
        unset($_SESSION['kl_draft']);
        if (count($ids) === 1) {
            $no = db_val('SELECT asset_no FROM kl_items WHERE id = ?', [$ids[0]]);
            flash('ลงทะเบียนแล้ว เลขครุภัณฑ์ ' . $no, 'ok');
            redirect('item.php?id=' . $ids[0] . '&new=1');
        }
        flash('ลงทะเบียนแล้ว ' . count($ids) . ' ชิ้น · เลือกทั้งหมดแล้วพิมพ์สติกเกอร์ได้เลย', 'ok');
        redirect('items.php?batch=' . $ids[0] . '-' . end($ids));
    } catch (InvalidArgumentException $e) {
        $_SESSION['kl_draft'] = array_diff_key($_POST, ['csrf' => 1, 'thumb' => 1]);
        flash($e->getMessage(), 'error');
        redirect('edit.php' . ($id ? '?id=' . $id : ''));
    }
}

// ค่าในฟอร์ม: ฉบับร่าง (กลับมาจาก error) > ข้อมูลเดิม > ค่าเริ่มต้น
$draft = $_SESSION['kl_draft'] ?? null;
unset($_SESSION['kl_draft']);
$v = $item ?? ['cat_code' => '', 'name' => '', 'brand' => '', 'model' => '', 'serial_no' => '', 'spec' => '', 'unit' => '', 'price' => '',
    'acquired_on' => date('Y-m-d'), 'doc_no' => '', 'fund' => 'เงินงบประมาณ', 'method' => 'วิธีเฉพาะเจาะจง', 'vendor' => '', 'location' => '',
    'custodian' => '', 'status' => 'normal', 'life_years' => '', 'note' => ''];
if (is_array($draft)) $v = array_merge($v, array_map(fn($x) => is_string($x) ? $x : '', $draft));
if (!$item && $v['cat_code'] === '' && isset($_GET['cat'], $cats[$_GET['cat']])) $v['cat_code'] = (string) $_GET['cat'];

$places = array_column(db_all("SELECT DISTINCT location FROM kl_items WHERE school_id = ? AND location <> '' ORDER BY location LIMIT 200", [$sid]), 'location');
$people = array_column(db_all("SELECT DISTINCT custodian FROM kl_items WHERE school_id = ? AND custodian <> '' ORDER BY custodian LIMIT 200", [$sid]), 'custodian');
$vendors = array_column(db_all("SELECT DISTINCT vendor FROM kl_items WHERE school_id = ? AND vendor <> '' ORDER BY vendor LIMIT 200", [$sid]), 'vendor');
$groups = [];
foreach ($cats as $c) $groups[$c['grp'] ?: 'อื่น ๆ'][] = $c;
$room = $item ? KL_PHOTO_MAX - count(photos($item)) : KL_PHOTO_MAX;

page_head(($item ? 'แก้ไข ' . $item['asset_no'] : 'ลงทะเบียนครุภัณฑ์') . ' · คลังบริบูรณ์', 'items'); ?>
<main class="wrap page">
  <?= flash_script() ?>
  <div class="page-h">
    <div>
      <?php if ($item): ?><p class="no"><?= h($item['asset_no']) ?></p><h1>แก้ไขรายละเอียด</h1><p>เลขครุภัณฑ์ออกแล้วไม่เปลี่ยน · ทุกการแก้ไขถูกบันทึกในประวัติ</p>
      <?php else: ?><h1>ลงทะเบียนครุภัณฑ์</h1><p>กรอกช่องที่มี <span style="color:var(--danger)">*</span> ก็พอ ที่เหลือเติมทีหลังได้</p><?php endif; ?>
    </div>
    <a class="btn" href="<?= $item ? 'item.php?id=' . $id : 'items.php' ?>">ยกเลิก</a>
  </div>

  <form class="panel" method="post" enctype="multipart/form-data" id="item-form" autocomplete="off">
    <?= csrf_field() ?>
    <div class="panel-b grid" style="gap:22px">
      <fieldset class="grid g4">
        <legend>1 · ครุภัณฑ์อะไร</legend>
        <div class="field span2">
          <label for="cat_code">ประเภท/ชนิด <span class="req">*</span></label>
          <select class="input" id="cat_code" name="cat_code" required <?= $item ? 'disabled' : '' ?>>
            <option value="">— เลือกประเภท —</option>
            <?php foreach ($groups as $g => $list): ?><optgroup label="<?= h($g) ?>">
              <?php foreach ($list as $c): ?><option value="<?= h($c['code']) ?>" data-life="<?= (int) $c['life_years'] ?>" data-unit="<?= h((string) $c['unit']) ?>" data-name="<?= h($c['name']) ?>"<?= $c['code'] === $v['cat_code'] ? ' selected' : '' ?>><?= h($c['code'] . ' ' . $c['name']) ?></option><?php endforeach; ?>
            </optgroup><?php endforeach; ?>
          </select>
          <?php if ($item): ?><input type="hidden" name="cat_code" value="<?= h($item['cat_code']) ?>"><?php endif; ?>
          <p class="hint">ไม่มีในรายการ? <a href="settings.php#cats">เพิ่มประเภทในตั้งค่า</a></p>
        </div>
        <div class="field span2">
          <label for="name">ชื่อครุภัณฑ์ <span class="req">*</span></label>
          <input class="input" id="name" name="name" required maxlength="200" value="<?= h((string) $v['name']) ?>" placeholder="เช่น เครื่องคอมพิวเตอร์โน้ตบุ๊ก">
        </div>
        <div class="field"><label for="brand">ยี่ห้อ</label><input class="input" id="brand" name="brand" maxlength="120" value="<?= h((string) $v['brand']) ?>"></div>
        <div class="field"><label for="model">รุ่น/แบบ</label><input class="input" id="model" name="model" maxlength="120" value="<?= h((string) $v['model']) ?>"></div>
        <div class="field span2" id="serial-one">
          <label for="serial_no">เลขเครื่อง (Serial No.)</label>
          <input class="input mono" id="serial_no" name="serial_no" maxlength="120" value="<?= h((string) $v['serial_no']) ?>" placeholder="ดูที่ป้ายใต้เครื่อง/หลังเครื่อง">
        </div>
        <div class="field span-all"><label for="spec">คุณลักษณะ/รายละเอียด</label><textarea class="input" id="spec" name="spec" maxlength="1000" rows="2" placeholder="เช่น CPU Core i5, RAM 16GB, SSD 512GB, จอ 14 นิ้ว"><?= h((string) $v['spec']) ?></textarea></div>
      </fieldset>

      <?php if (!$item): ?>
      <fieldset class="grid g4">
        <legend>2 · จำนวนและเลขครุภัณฑ์</legend>
        <div class="field">
          <label for="qty">จำนวนที่ได้มา</label>
          <input class="input" id="qty" name="qty" type="number" min="1" max="100" value="<?= (int) ($draft['qty'] ?? 1) ?>">
          <p class="hint">หลายชิ้น = หลายเลข เรียงกัน</p>
        </div>
        <div class="field span2">
          <span class="lbl">เลขที่จะได้</span>
          <div class="no-preview" id="no-preview" aria-live="polite"><b>—</b> <span class="more"></span></div>
          <p class="hint">รูปแบบตั้งได้ที่ <a href="settings.php">ตั้งค่า</a> · ปีงบประมาณคิดจากวันที่ได้มา (ต.ค.–ก.ย.)</p>
        </div>
        <div class="field">
          <label class="check"><input type="checkbox" name="use_old" value="1" id="use_old"<?= !empty($draft['use_old']) ? ' checked' : '' ?>> มีเลขเดิมอยู่แล้ว</label>
          <input class="input mono" name="old_no" id="old_no" maxlength="80" placeholder="เลขครุภัณฑ์เดิม" value="<?= h((string) ($draft['old_no'] ?? '')) ?>" hidden>
          <p class="hint">สำหรับย้ายทะเบียนเก่าเข้าระบบ</p>
        </div>
        <div class="field span-all" id="serial-many" hidden>
          <label for="serials">เลขเครื่องรายชิ้น (บรรทัดละ 1 เลข ตามลำดับเลขครุภัณฑ์)</label>
          <textarea class="input mono" id="serials" name="serials" rows="4"><?= h((string) ($draft['serials'] ?? '')) ?></textarea>
        </div>
      </fieldset>
      <?php endif; ?>

      <fieldset class="grid g4">
        <legend><?= $item ? '2' : '3' ?> · การได้มา (ลงทะเบียนคุมทรัพย์สิน)</legend>
        <div class="field"><label for="price">ราคาต่อหน่วย (บาท) <span class="req">*</span></label><input class="input num" id="price" name="price" inputmode="decimal" required value="<?= h($v['price'] === '' ? '' : number_format((float) $v['price'], 2, '.', '')) ?>" placeholder="0.00"></div>
        <div class="field"><label for="acquired_on">วันที่ได้มา <span class="req">*</span></label><input class="input" id="acquired_on" name="acquired_on" type="date" required value="<?= h((string) $v['acquired_on']) ?>"></div>
        <div class="field"><label for="unit">หน่วยนับ</label><input class="input" id="unit" name="unit" maxlength="30" value="<?= h((string) $v['unit']) ?>" placeholder="เครื่อง"></div>
        <div class="field"><label for="life_years">อายุการใช้งาน (ปี)</label><input class="input" id="life_years" name="life_years" type="number" min="1" max="50" value="<?= h((string) $v['life_years']) ?>"><p class="hint">ใช้คิดค่าเสื่อมราคา</p></div>
        <div class="field"><label for="fund">ประเภทเงิน</label><select class="input" id="fund" name="fund"><?= options(KL_FUNDS, (string) $v['fund']) ?></select></div>
        <div class="field"><label for="method">วิธีการได้มา</label><select class="input" id="method" name="method"><?= options(KL_METHODS, (string) $v['method']) ?></select></div>
        <div class="field"><label for="doc_no">ที่เอกสาร</label><input class="input" id="doc_no" name="doc_no" maxlength="120" value="<?= h((string) $v['doc_no']) ?>" placeholder="เลขที่ใบสั่งซื้อ/ใบตรวจรับ"></div>
        <div class="field"><label for="vendor">ผู้ขาย/ผู้รับจ้าง/ผู้บริจาค</label><input class="input" id="vendor" name="vendor" maxlength="200" list="dl-vendor" value="<?= h((string) $v['vendor']) ?>"></div>
      </fieldset>

      <fieldset class="grid g4">
        <legend><?= $item ? '3' : '4' ?> · อยู่ที่ไหน ใครดูแล</legend>
        <div class="field span2"><label for="location">สถานที่ตั้ง/ห้อง</label><input class="input" id="location" name="location" maxlength="200" list="dl-place" value="<?= h((string) $v['location']) ?>" placeholder="เช่น ห้องคอมพิวเตอร์ 1"></div>
        <div class="field"><label for="custodian">ผู้รับผิดชอบ</label><input class="input" id="custodian" name="custodian" maxlength="200" list="dl-people" value="<?= h((string) $v['custodian']) ?>"></div>
        <div class="field"><label for="status">สถานะ</label><select class="input" id="status" name="status"><?= options(array_map(fn($s) => $s[0], KL_STATUS), (string) $v['status'], true) ?></select></div>
        <div class="field span-all"><label for="note">หมายเหตุ</label><input class="input" id="note" name="note" maxlength="1000" value="<?= h((string) $v['note']) ?>"></div>
      </fieldset>

      <fieldset>
        <legend><?= $item ? '4' : '5' ?> · รูปถ่าย <span class="muted" style="font-weight:400;font-size:.86rem">ตัวเครื่อง + ป้ายเลขเครื่อง ได้ <?= $room ?> รูป</span></legend>
        <?php if ($room > 0): ?>
        <div class="gallery" id="photo-preview"></div>
        <label class="btn" style="margin-top:8px"><?= icon('<path d="M4 7h3l2-3h6l2 3h3v13H4z"/><circle cx="12" cy="13" r="4"/>', 18) ?>ถ่ายรูป/เลือกรูป
          <input class="sr" type="file" name="photos[]" accept="image/*" multiple data-photos data-room="<?= $room ?>"></label>
        <p class="photo-status" id="photo-status">ระบบย่อรูปให้เล็กก่อนส่ง (ประมาณ 100–300 KB ต่อรูป)</p>
        <?php else: ?><p class="hint">มีรูปครบแล้ว ลบรูปเดิมได้ที่หน้ารายละเอียด</p><?php endif; ?>
      </fieldset>
    </div>
    <div class="formbar">
      <a class="btn" href="<?= $item ? 'item.php?id=' . $id : 'items.php' ?>">ยกเลิก</a>
      <button class="btn btn-primary" type="submit"><?= $item ? 'บันทึกการแก้ไข' : 'บันทึกและออกเลข' ?></button>
    </div>
  </form>
  <datalist id="dl-place"><?php foreach ($places as $p): ?><option value="<?= h($p) ?>"><?php endforeach; ?></datalist>
  <datalist id="dl-people"><?php foreach ($people as $p): ?><option value="<?= h($p) ?>"><?php endforeach; ?></datalist>
  <datalist id="dl-vendor"><?php foreach ($vendors as $p): ?><option value="<?= h($p) ?>"><?php endforeach; ?></datalist>
</main>
<?php page_foot('<script type="module" nonce="' . csp_nonce() . '" src="' . asset_v('assets/photo.js') . '"></script>'
    . '<script type="module" nonce="' . csp_nonce() . '" src="' . asset_v('assets/edit.js') . '"></script>');
