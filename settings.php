<?php
// ตั้งค่า: รูปแบบเลขครุภัณฑ์ · ผู้ลงนามในเอกสาร · การยืม · ประเภท/ชนิดครุภัณฑ์ของโรงเรียน
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/assets.php';
require __DIR__ . '/lib/layout.php';

[$u, $school] = require_school();
$sid = (int) $school['id'];
$cats = cats($sid);

const KL_PRESETS = [
    'std'  => ['{cat}-{seq}/{fy}', 'ชนิด-ลำดับ/ปีงบประมาณ'],
    'pre'  => ['{pre}.{cat}-{seq}/{fy}', 'อักษรย่อหน่วยงาน.ชนิด-ลำดับ/ปีงบประมาณ'],
    'fy2'  => ['{cat}-{seq}/{fy2}', 'ชนิด-ลำดับ/ปีงบประมาณ 2 หลัก'],
    'grp'  => ['{grp}-{seq}/{fy}', 'กลุ่ม-ประเภท 4 หลัก-ลำดับ/ปีงบประมาณ'],
];

if (is_post()) {
    csrf_check();
    $act = post('act', 20);
    try {
        if ($act === 'number') {
            $fmt = post('format', 80);
            if (!str_contains($fmt, '{seq}')) throw new InvalidArgumentException('รูปแบบเลขต้องมี {seq} (เลขลำดับ) ไม่งั้นเลขจะซ้ำกัน');
            if (!str_contains($fmt, '{cat}') && !str_contains($fmt, '{grp}')) throw new InvalidArgumentException('รูปแบบเลขต้องมี {cat} หรือ {grp}');
            $scope = post('scope', 10) === 'cat' ? 'cat' : 'cat_fy';
            if ($scope === 'cat_fy' && !str_contains($fmt, '{fy}') && !str_contains($fmt, '{fy2}')) throw new InvalidArgumentException('ถ้าเริ่มลำดับใหม่ทุกปี รูปแบบเลขต้องมีปี {fy} หรือ {fy2} ไม่งั้นเลขจะซ้ำกัน');
            settings_save($sid, ['format' => $fmt, 'prefix' => post('prefix', 30), 'digits' => max(2, min(6, (int) post('digits', 1))), 'scope' => $scope]);
            flash('บันทึกรูปแบบเลขแล้ว (มีผลกับครุภัณฑ์ที่ลงทะเบียนต่อจากนี้ เลขเดิมไม่เปลี่ยน)', 'ok');
        } elseif ($act === 'people') {
            $d = [];
            foreach (['officer', 'officer_pos', 'head', 'head_pos', 'boss', 'boss_pos'] as $k) $d[$k] = post($k, 150);
            $d['loan_days'] = max(1, min(365, (int) post('loan_days', 3)));
            $d['agency'] = post('agency', 200);
            $d['checkers'] = post('checkers', 600);
            settings_save($sid, $d);
            flash('บันทึกแล้ว', 'ok');
        } elseif ($act === 'cat.save') {
            $code = post('code', 20);
            if (!valid_cat_code($code)) throw new InvalidArgumentException('รหัสใช้ตัวเลข/อักษรอังกฤษ คั่นด้วย - เช่น 7440-0109');
            $name = post('name', 200);
            if ($name === '') throw new InvalidArgumentException('กรุณากรอกชื่อชนิด');
            $life = max(1, min(50, (int) post('life', 3)));
            $args = [$name, post('grp', 100), $life, post('unit', 30)];
            if (isset($cats[$code])) db_exec('UPDATE kl_cats SET name = ?, grp = ?, life_years = ?, unit = ? WHERE school_id = ? AND code = ?', [...$args, $sid, $code]);
            else db_exec('INSERT INTO kl_cats (name, grp, life_years, unit, school_id, code) VALUES (?,?,?,?,?,?)', [...$args, $sid, $code]);
            flash('บันทึกประเภท ' . $code . ' แล้ว', 'ok');
            redirect('settings.php#cats');
        } elseif ($act === 'cat.del') {
            $code = post('code', 20);
            if (db_val('SELECT 1 FROM kl_items WHERE school_id = ? AND cat_code = ?', [$sid, $code])) throw new InvalidArgumentException('ประเภทนี้มีครุภัณฑ์ใช้อยู่ ลบไม่ได้');
            db_exec('DELETE FROM kl_cats WHERE school_id = ? AND code = ?', [$sid, $code]);
            flash('ลบประเภท ' . $code . ' แล้ว', 'ok');
            redirect('settings.php#cats');
        }
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'error');
    }
    redirect('settings.php');
}

$set = settings($sid);
$fy = fiscal_year();
$used = array_column(db_all('SELECT cat_code, COUNT(*) AS n FROM kl_items WHERE school_id = ? GROUP BY cat_code', [$sid]), 'n', 'cat_code');
$grps = array_values(array_unique(array_filter(array_column($cats, 'grp'))));
$sample = format_asset_no($set, '7440-0109', 3, $fy);

page_head('ตั้งค่า · คลังบริบูรณ์', 'settings'); ?>
<main class="wrap page">
  <?= flash_script() ?>
  <div class="page-h"><div><h1>ตั้งค่า</h1><p><?= h($school['name']) ?></p></div></div>

  <div class="cols">
    <div>
      <form class="panel" method="post" id="number">
        <?= csrf_field() ?><input type="hidden" name="act" value="number">
        <div class="panel-h"><h2>รูปแบบเลขครุภัณฑ์</h2><span class="muted">ตอนนี้: <b class="mono"><?= h($sample) ?></b></span></div>
        <div class="panel-b grid">
          <p class="hint" style="margin:0">ระเบียบไม่ได้กำหนดรูปแบบเลขตายตัว ส่วนที่ใช้ร่วมกันคือรหัสกลุ่ม-ประเภท 4 หลักตามบัญชี FSN แต่ละหน่วยงานกำหนดส่วนที่เหลือเอง ถ้าต้นสังกัดกำหนดไว้ ให้ตั้งตามต้นสังกัด</p>
          <div class="field">
            <span class="lbl">แบบสำเร็จรูป</span>
            <div class="grid g2" style="gap:6px">
              <?php foreach (KL_PRESETS as $k => [$f, $label]): ?>
                <label class="check" style="border:1px solid var(--line);padding:8px 10px;border-radius:var(--r)"><input type="radio" name="preset" value="<?= h($f) ?>"<?= $f === $set['format'] ? ' checked' : '' ?>>
                  <span><span class="mono" data-demo="<?= h($f) ?>"></span><span class="sub"><?= h($label) ?></span></span></label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="grid g3">
            <div class="field span2"><label for="format">รูปแบบ (แก้เองได้)</label><input class="input mono" id="format" name="format" required maxlength="80" value="<?= h($set['format']) ?>">
              <p class="hint">{cat} ชนิด เช่น 7440-0109 · {grp} กลุ่ม-ประเภท 7440 · {seq} ลำดับ · {fy} ปีงบประมาณ <?= $fy ?> · {fy2} <?= substr((string) $fy, -2) ?> · {pre} อักษรย่อ</p></div>
            <div class="field"><label for="prefix">อักษรย่อหน่วยงาน {pre}</label><input class="input" id="prefix" name="prefix" maxlength="30" value="<?= h($set['prefix']) ?>" placeholder="เช่น ตชด.23"></div>
            <div class="field"><label for="digits">จำนวนหลักของลำดับ</label><select class="input" id="digits" name="digits"><?= options([2 => '2 หลัก (01)', 3 => '3 หลัก (001)', 4 => '4 หลัก (0001)', 5 => '5 หลัก', 6 => '6 หลัก'], (string) $set['digits'], true) ?></select></div>
            <div class="field span2"><label for="scope">การนับลำดับ</label><select class="input" id="scope" name="scope">
              <?= options(['cat_fy' => 'เริ่ม 0001 ใหม่ทุกปีงบประมาณ แยกตามชนิด (แบบคู่มือ ตร.)', 'cat' => 'นับต่อเนื่องตามชนิด ไม่เริ่มใหม่'], $set['scope'], true) ?></select></div>
          </div>
          <div class="no-preview">ตัวอย่าง: <b id="fmt-demo" class="mono"><?= h($sample) ?></b> <span class="more">(โน้ตบุ๊กชิ้นที่ 3 ของปีงบประมาณ <?= $fy ?>)</span></div>
          <div><button class="btn btn-primary" type="submit">บันทึกรูปแบบเลข</button> <span class="hint">มีผลกับครุภัณฑ์ใหม่เท่านั้น เลขที่ออกไปแล้วไม่เปลี่ยน</span></div>
        </div>
      </form>

      <section class="panel" id="cats">
        <div class="panel-h"><h2>ประเภท/ชนิดครุภัณฑ์</h2><span class="muted"><?= count($cats) ?> ชนิด</span></div>
        <p class="hint" style="padding:10px 16px 0;margin:0">รหัสเริ่มต้นมาจากบัญชีกลุ่ม/ประเภทครุภัณฑ์ (FSN) ในคู่มือกำหนดเลขครุภัณฑ์ของสำนักงานตำรวจแห่งชาติ · อายุการใช้งานตามตาราง สพฐ. ซึ่งอยู่ในช่วงของหนังสือกรมบัญชีกลาง ว 238 · รหัสชนิดแต่ละต้นสังกัดใช้ไม่ตรงกัน แก้ให้ตรงกับของโรงเรียนได้</p>
        <form class="filters" method="post" style="border-bottom:1px solid var(--line)">
          <?= csrf_field() ?><input type="hidden" name="act" value="cat.save">
          <input class="input mono" name="code" required maxlength="20" placeholder="รหัส 7440-0101" style="width:9.5rem" aria-label="รหัส" id="c-code">
          <input class="input grow" name="name" required maxlength="200" placeholder="ชื่อชนิด" aria-label="ชื่อชนิด" id="c-name">
          <input class="input" name="grp" maxlength="100" list="dl-grp" placeholder="หมวด (สำนักงบประมาณ)" aria-label="หมวด" style="width:12rem" id="c-grp">
          <input class="input" name="life" type="number" min="1" max="50" required placeholder="อายุ (ปี)" aria-label="อายุการใช้งาน" style="width:6.5rem" id="c-life">
          <input class="input" name="unit" maxlength="30" placeholder="หน่วยนับ" aria-label="หน่วยนับ" style="width:6.5rem" id="c-unit">
          <button class="btn" type="submit">เพิ่ม/แก้</button>
        </form>
        <datalist id="dl-grp"><?php foreach ($grps as $g): ?><option value="<?= h($g) ?>"><?php endforeach; ?></datalist>
        <div class="scroll-x"><table class="table">
          <thead><tr><th>รหัส</th><th>ชื่อ</th><th>หมวด</th><th class="num">อายุ (ปี)</th><th>หน่วย</th><th class="num">ใช้อยู่</th><th></th></tr></thead>
          <tbody><?php foreach ($cats as $c): ?>
            <tr><td class="no"><?= h($c['code']) ?></td><td><?= h($c['name']) ?></td><td><?= h($c['grp'] ?: '–') ?></td><td class="num"><?= (int) $c['life_years'] ?></td><td><?= h($c['unit'] ?: '–') ?></td><td class="num"><?= (int) ($used[$c['code']] ?? 0) ?></td>
              <td><div class="actions">
                <button class="btn btn-sm" type="button" data-edit='<?= h(json_encode([$c['code'], $c['name'], $c['grp'], (int) $c['life_years'], $c['unit']], JSON_UNESCAPED_UNICODE)) ?>'>แก้</button>
                <?php if (empty($used[$c['code']])): ?><form method="post" data-confirm="ลบประเภท <?= h($c['code']) ?>?" data-danger="1" data-ok-text="ลบ"><?= csrf_field() ?><input type="hidden" name="act" value="cat.del"><input type="hidden" name="code" value="<?= h($c['code']) ?>"><button class="btn btn-sm btn-danger" type="submit">ลบ</button></form><?php endif; ?>
              </div></td></tr>
          <?php endforeach; ?></tbody>
        </table></div>
      </section>
    </div>

    <div>
      <form class="panel" method="post">
        <?= csrf_field() ?><input type="hidden" name="act" value="people">
        <div class="panel-h"><h2>ผู้ลงนามในเอกสาร</h2></div>
        <div class="panel-b grid">
          <div class="field"><label for="agency">ส่วนราชการ/ต้นสังกัด</label><input class="input" id="agency" name="agency" maxlength="200" value="<?= h($set['agency']) ?>" placeholder="เช่น สพป.นครพนม เขต 2"><p class="hint">แสดงที่หัวทะเบียนคุมทรัพย์สิน</p></div>
          <?php foreach (['officer' => 'เจ้าหน้าที่พัสดุ', 'head' => 'หัวหน้าเจ้าหน้าที่', 'boss' => 'หัวหน้าหน่วยงาน (ผู้อนุมัติ)'] as $k => $label): ?>
            <div class="field"><label for="<?= $k ?>"><?= $label ?></label><input class="input" id="<?= $k ?>" name="<?= $k ?>" maxlength="150" value="<?= h($set[$k]) ?>" placeholder="ยศ/คำนำหน้า ชื่อ สกุล">
              <input class="input" name="<?= $k ?>_pos" maxlength="150" value="<?= h($set[$k . '_pos']) ?>" aria-label="ตำแหน่ง<?= $label ?>" placeholder="ตำแหน่ง" style="margin-top:4px"></div>
          <?php endforeach; ?>
          <div class="field"><label for="checkers">คณะกรรมการตรวจสอบพัสดุประจำปี</label><textarea class="input" id="checkers" name="checkers" rows="3" maxlength="600" placeholder="บรรทัดละคน: ชื่อ สกุล|ตำแหน่ง"><?= h($set['checkers']) ?></textarea><p class="hint">ระเบียบฯ ข้อ 213: แต่งตั้งในเดือน ก.ย. และต้องไม่ใช่เจ้าหน้าที่พัสดุ</p></div>
          <div class="field"><label for="loan_days">กำหนดคืนเริ่มต้น (วันหลังยืม)</label><input class="input" id="loan_days" name="loan_days" type="number" min="1" max="365" value="<?= (int) $set['loan_days'] ?>"></div>
          <button class="btn btn-primary" type="submit">บันทึก</button>
        </div>
      </form>
      <section class="panel">
        <div class="panel-h"><h2>บัญชีผู้ใช้</h2></div>
        <div class="panel-b"><p class="hint" style="margin:0">ใช้บัญชีเดียวกับตารางบริบูรณ์ · แก้ชื่อ/ที่ตั้ง/โลโก้โรงเรียนได้ที่ <a href="<?= h(tt_url('app.php')) ?>">ตารางบริบูรณ์ → ข้อมูล → โรงเรียน</a></p></div>
      </section>
    </div>
  </div>
</main>
<?php page_foot('<script type="module" nonce="' . csp_nonce() . '">
const fy = ' . $fy . ', f = document.getElementById("format"), pre = document.getElementById("prefix"), dg = document.getElementById("digits"), out = document.getElementById("fmt-demo");
const make = (t) => t.replaceAll("{pre}", pre.value).replaceAll("{cat}", "7440-0109").replaceAll("{grp}", "7440").replaceAll("{seq}", "3".padStart(Number(dg.value), "0")).replaceAll("{fy2}", String(fy).slice(-2)).replaceAll("{fy}", fy).replace(/^[.\\-\\/\\s]+/, "");
const upd = () => { out.textContent = make(f.value); document.querySelectorAll("[data-demo]").forEach((e) => { e.textContent = make(e.dataset.demo); }); };
document.querySelectorAll("input[name=preset]").forEach((r) => r.addEventListener("change", () => { f.value = r.value; upd(); }));
[f, pre, dg].forEach((e) => e.addEventListener("input", upd));
upd();
document.querySelectorAll("[data-edit]").forEach((b) => b.addEventListener("click", () => {
  const [code, name, grp, life, unit] = JSON.parse(b.dataset.edit);
  Object.entries({ "c-code": code, "c-name": name, "c-grp": grp || "", "c-life": life, "c-unit": unit || "" }).forEach(([id, v]) => { document.getElementById(id).value = v; });
  document.getElementById("c-name").focus();
}));
</script>');
