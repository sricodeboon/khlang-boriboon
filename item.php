<?php
// รายละเอียดครุภัณฑ์หนึ่งชิ้น: รูป · ข้อมูลทะเบียน · ค่าเสื่อมราคา · ยืม/เบิก–คืน · เปลี่ยนสถานะ · ประวัติ
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/assets.php';
require __DIR__ . '/lib/photos.php';
require __DIR__ . '/lib/layout.php';

[$u, $school] = require_school();
$sid = (int) $school['id'];
$id = (int) ($_GET['id'] ?? 0);
$item = item_get($sid, $id);
if (!$item) { flash('ไม่พบครุภัณฑ์นี้'); redirect('items.php'); }
$set = settings($sid);
$uid = (int) $u['id'];
$today = date('Y-m-d');
$validDate = fn(string $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;

if (is_post()) {
    csrf_check();
    $act = post('act', 20);
    try {
        if ($act === 'status') {
            $st = post('status', 20);
            if (!isset(KL_STATUS[$st])) throw new InvalidArgumentException('สถานะไม่ถูกต้อง');
            if ($st !== $item['status']) {
                db_exec('UPDATE kl_items SET status = ?, updated_at = ? WHERE id = ?', [$st, now(), $id]);
                $why = post('why', 300);
                log_event($sid, $id, 'status', 'สถานะ: ' . status_label($item['status']) . ' → ' . status_label($st) . ($why !== '' ? ' · ' . $why : ''), $uid);
                flash('เปลี่ยนสถานะเป็น “' . status_label($st) . '” แล้ว', 'ok');
            }
        } elseif ($act === 'loan') {
            if ($item['on_loan']) throw new InvalidArgumentException('ครุภัณฑ์นี้ยังไม่ได้คืนจากการยืมครั้งก่อน');
            if (in_array($item['status'], ['lost', 'disposed', 'dispose', 'broken', 'fixing'], true)) throw new InvalidArgumentException('สถานะ “' . status_label($item['status']) . '” ให้ยืมไม่ได้');
            $who = post('borrower', 200);
            if ($who === '') throw new InvalidArgumentException('กรุณากรอกชื่อผู้ยืม/ผู้เบิก');
            $kind = post('kind', 10) === 'issue' ? 'issue' : 'loan';
            $out = $validDate(post('out_on', 10)) ?? $today;
            $due = $validDate(post('due_on', 10));
            if ($kind === 'loan' && !$due) throw new InvalidArgumentException('กรุณากำหนดวันส่งคืน');
            if ($due && $due < $out) throw new InvalidArgumentException('วันส่งคืนต้องไม่ก่อนวันยืม');
            db_tx(function () use ($sid, $id, $kind, $who, $out, $due, $uid, $item) {
                db_exec('INSERT INTO kl_loans (school_id, item_id, kind, borrower, purpose, place, out_on, due_on, approver, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                    [$sid, $id, $kind, $who, post('purpose', 500), post('place', 200), $out, $due, post('approver', 200), $uid, now()]);
                db_exec('UPDATE kl_items SET on_loan = 1, updated_at = ? WHERE id = ?', [now(), $id]);
                log_event($sid, $id, 'loan', ($kind === 'issue' ? 'เบิกไปใช้ · ' : 'ให้ยืม · ') . $who . ($due ? ' · กำหนดคืน ' . th_date($due) : ''), $uid);
            });
            flash(($kind === 'issue' ? 'บันทึกการเบิก' : 'บันทึกการยืม') . 'แล้ว · พิมพ์ใบยืมได้ที่ปุ่ม “ใบยืม”', 'ok');
        } elseif ($act === 'return') {
            $loan = open_loan($id);
            if (!$loan) throw new InvalidArgumentException('ไม่มีรายการยืมค้างอยู่');
            $back = $validDate(post('returned_on', 10)) ?? $today;
            $st = post('status', 20);
            if (!isset(KL_STATUS[$st])) $st = $item['status'];
            db_tx(function () use ($loan, $back, $st, $sid, $id, $uid, $item) {
                db_exec('UPDATE kl_loans SET returned_on = ?, return_note = ? WHERE id = ?', [$back, post('return_note', 500), $loan['id']]);
                db_exec('UPDATE kl_items SET on_loan = 0, status = ?, updated_at = ? WHERE id = ?', [$st, now(), $id]);
                $late = $loan['due_on'] && $back > $loan['due_on'] ? ' (เกินกำหนด ' . (int) round((strtotime($back) - strtotime($loan['due_on'])) / 86400) . ' วัน)' : '';
                log_event($sid, $id, 'return', 'รับคืนจาก ' . $loan['borrower'] . $late . ' · สภาพ ' . status_label($st), $uid);
            });
            flash('รับคืนแล้ว', 'ok');
        } elseif ($act === 'delete') {
            // ลบได้เฉพาะที่ลงผิดภายในวันเดียวกันและไม่เคยยืม — ของจริงต้องใช้ “จำหน่าย” ตามระเบียบ ไม่ลบทิ้ง
            if (substr($item['created_at'], 0, 10) !== $today || db_val('SELECT 1 FROM kl_loans WHERE item_id = ?', [$id])) {
                throw new InvalidArgumentException('ลบได้เฉพาะรายการที่ลงผิดภายในวันนี้ ครุภัณฑ์ที่ไม่ใช้แล้วให้เปลี่ยนสถานะเป็น “รอจำหน่าย/จำหน่ายแล้ว”');
            }
            foreach (photos($item) as $p) delete_photo(item_get($sid, $id), $p, $uid);
            db_tx(function () use ($sid, $id, $item, $uid) {
                db_exec('DELETE FROM kl_items WHERE id = ? AND school_id = ?', [$id, $sid]);
                db_exec('DELETE FROM kl_log WHERE item_id = ?', [$id]);
                log_event($sid, null, 'delete', 'ลบรายการที่ลงผิด ' . $item['asset_no'] . ' · ' . $item['name'], $uid);
            });
            flash('ลบ ' . $item['asset_no'] . ' แล้ว', 'ok');
            redirect('items.php');
        }
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'error');
    }
    redirect('item.php?id=' . $id);
}

$cats = cats($sid);
$cat = $cats[$item['cat_code']] ?? ['name' => '', 'grp' => ''];
$photos = photos($item);
$loan = open_loan($id);
$loans = db_all('SELECT * FROM kl_loans WHERE item_id = ? ORDER BY id DESC LIMIT 20', [$id]);
$log = db_all('SELECT at, kind, detail FROM kl_log WHERE item_id = ? ORDER BY id DESC LIMIT 50', [$id]);
$dep = depreciation((float) $item['price'], $item['acquired_on'], (int) $item['life_years']);
$fy = fiscal_year();
$canDelete = substr($item['created_at'], 0, 10) === $today && !$loans;
$people = array_column(db_all("SELECT DISTINCT borrower FROM kl_loans WHERE school_id = ? ORDER BY borrower LIMIT 200", [$sid]), 'borrower');

page_head($item['asset_no'] . ' ' . $item['name'] . ' · คลังบริบูรณ์', 'items'); ?>
<main class="wrap page">
  <?= flash_script() ?>
  <p style="margin:0 0 10px"><a href="items.php" class="muted">← ทะเบียนครุภัณฑ์</a></p>

  <section class="panel">
    <div class="panel-b item-h">
      <?php if ($item['thumb']): ?><img class="thumb" style="width:88px;height:88px" src="<?= h($item['thumb']) ?>" alt=""><?php else: ?><span class="thumb-empty" style="width:88px;height:88px">ไม่มีรูป</span><?php endif; ?>
      <div>
        <div class="item-no"><?= h($item['asset_no']) ?></div>
        <h1><?= h($item['name']) ?></h1>
        <p style="margin:6px 0 0;display:flex;gap:6px;flex-wrap:wrap;align-items:center">
          <?= status_badge($item['status']) ?>
          <?php if ($loan): ?><span class="st st-loan<?= $loan['due_on'] && $loan['due_on'] < $today ? ' st-late' : '' ?>"><?= $loan['kind'] === 'issue' ? 'เบิกไปใช้' : 'ถูกยืม' ?> · <?= h($loan['borrower']) ?></span><?php endif; ?>
          <?php if ((int) $item['checked_fy'] === $fy): ?><span class="st st-ok">ตรวจนับปี <?= $fy ?> แล้ว</span><?php endif; ?>
          <span class="muted" style="font-size:.84rem"><?= h($item['cat_code'] . ' ' . $cat['name']) ?></span>
        </p>
        <div class="actions" style="margin-top:12px">
          <a class="btn btn-primary" href="edit.php?id=<?= $id ?>">แก้ไข</a>
          <?php if ($loan): ?><a class="btn btn-gold" href="#return">รับคืน</a><?php else: ?><a class="btn" href="#loan">ยืม/เบิก</a><?php endif; ?>
          <a class="btn" href="#status">เปลี่ยนสถานะ</a>
          <a class="btn" href="print.php?doc=sticker&amp;ids=<?= $id ?>" target="_blank">สติกเกอร์</a>
          <a class="btn" href="print.php?doc=register&amp;ids=<?= $id ?>" target="_blank">ทะเบียนคุม PDF</a>
        </div>
      </div>
      <div class="qr-box" title="สแกนเพื่อเปิดหน้านี้"><img src="<?= h(qr_data_url(public_url($item))) ?>" alt="QR ของ <?= h($item['asset_no']) ?>"><small><?= h($item['token']) ?></small></div>
    </div>
  </section>

  <div class="cols" style="margin-top:16px">
    <div>
      <section class="panel">
        <div class="panel-h"><h2>รูปถ่าย</h2><span class="muted"><?= count($photos) ?>/<?= KL_PHOTO_MAX ?> รูป</span></div>
        <div class="panel-b">
          <div class="gallery" id="gallery">
            <?php foreach ($photos as $p): $src = photo_url($item, $p); ?>
              <figure><img src="<?= h($src) ?>" data-zoom="<?= h($src) ?>" alt="รูป <?= h($item['name']) ?>" loading="lazy">
                <button class="btn btn-sm del" type="button" data-del-photo="<?= h($p) ?>" aria-label="ลบรูป">ลบ</button></figure>
            <?php endforeach; ?>
            <?php if (count($photos) < KL_PHOTO_MAX): ?>
              <label class="add" style="border:1px dashed var(--line-2);aspect-ratio:4/3;display:grid;place-items:center;cursor:pointer;color:var(--muted);text-align:center;border-radius:2px">
                <span><?= icon('<path d="M4 7h3l2-3h6l2 3h3v13H4z"/><circle cx="12" cy="13" r="4"/>', 24) ?><br>ถ่าย/เพิ่มรูป</span>
                <input class="sr" type="file" accept="image/*" multiple id="add-photo" data-room="<?= KL_PHOTO_MAX - count($photos) ?>"></label>
            <?php endif; ?>
          </div>
          <p class="photo-status" id="photo-status"></p>
        </div>
      </section>

      <section class="panel">
        <div class="panel-h"><h2>ข้อมูลทะเบียน</h2><a class="muted" href="edit.php?id=<?= $id ?>">แก้ไข</a></div>
        <dl class="dl">
          <dt>เลขครุภัณฑ์</dt><dd class="no"><?= h($item['asset_no']) ?></dd>
          <dt>ประเภท/ชนิด</dt><dd><?= h($item['cat_code'] . ' ' . $cat['name']) ?><?= $cat['grp'] ? ' <span class="muted">· ' . h($cat['grp']) . '</span>' : '' ?></dd>
          <dt>ยี่ห้อ / รุ่น</dt><dd><?= h(trim($item['brand'] . ' ' . $item['model']) ?: '–') ?></dd>
          <dt>เลขเครื่อง</dt><dd class="mono"><?= h($item['serial_no'] ?: '–') ?></dd>
          <dt>คุณลักษณะ</dt><dd><?= nl2br(h($item['spec'] ?: '–')) ?></dd>
          <dt>ราคา</dt><dd><?= money($item['price']) ?> บาท / <?= h($item['unit'] ?: 'หน่วย') ?></dd>
          <dt>วันที่ได้มา</dt><dd><?= th_date($item['acquired_on']) ?> <span class="muted">· ปีงบประมาณ <?= (int) $item['fy'] ?></span></dd>
          <dt>ประเภทเงิน</dt><dd><?= h($item['fund'] ?: '–') ?></dd>
          <dt>วิธีการได้มา</dt><dd><?= h($item['method'] ?: '–') ?></dd>
          <dt>ที่เอกสาร</dt><dd><?= h($item['doc_no'] ?: '–') ?></dd>
          <dt>ผู้ขาย/ผู้บริจาค</dt><dd><?= h($item['vendor'] ?: '–') ?></dd>
          <dt>สถานที่ตั้ง</dt><dd><?= h($item['location'] ?: '–') ?></dd>
          <dt>ผู้รับผิดชอบ</dt><dd><?= h($item['custodian'] ?: '–') ?></dd>
          <dt>หมายเหตุ</dt><dd><?= h($item['note'] ?: '–') ?></dd>
        </dl>
      </section>

      <section class="panel">
        <div class="panel-h"><h2>ค่าเสื่อมราคา</h2><span class="muted">เส้นตรง · อายุ <?= (int) $item['life_years'] ?> ปี · อัตรา <?= money(100 / max(1, (int) $item['life_years'])) ?>% · คงมูลค่า 1 บาท</span></div>
        <?php if (below_threshold($item)): ?><p class="empty">ราคาต่อหน่วยต่ำกว่าเกณฑ์ (<?= ($t = dep_threshold((int) $item['fy'])) ? number_format($t) . ' บาท สำหรับปีงบประมาณ ' . (int) $item['fy'] : 'ได้มาก่อนปีงบประมาณ 2540' ?>) · ลงทะเบียนคุมไว้ แต่ไม่คิดค่าเสื่อมราคา</p>
        <?php elseif (!$dep): ?><p class="empty">ต้องมีราคาและวันที่ได้มาจึงคำนวณได้</p><?php else: ?>
        <div class="scroll-x"><table class="table">
          <thead><tr><th>ปีงบประมาณ</th><th class="num">ค่าเสื่อมราคาประจำปี</th><th class="num">ค่าเสื่อมราคาสะสม</th><th class="num">มูลค่าสุทธิ</th></tr></thead>
          <tbody><?php foreach ($dep as $d): ?><tr><td><?= $d['fy'] ?><?= $d['months'] < 12 ? ' <span class="muted">(' . $d['months'] . ' เดือน)</span>' : '' ?></td><td class="num"><?= money($d['dep']) ?></td><td class="num"><?= money($d['accum']) ?></td><td class="num"><b><?= money($d['net']) ?></b></td></tr><?php endforeach; ?></tbody>
        </table></div><?php endif; ?>
      </section>
    </div>

    <div>
      <?php if ($loan): ?>
      <section class="panel" id="return">
        <div class="panel-h"><h2>รับคืน</h2><a class="muted" href="print.php?doc=loan&amp;id=<?= (int) $loan['id'] ?>" target="_blank">ใบยืม PDF</a></div>
        <form class="panel-b grid" method="post"><?= csrf_field() ?><input type="hidden" name="act" value="return">
          <p class="hint" style="font-size:.9rem;color:var(--ink)"><?= $loan['kind'] === 'issue' ? 'เบิกโดย' : 'ยืมโดย' ?> <b><?= h($loan['borrower']) ?></b> ตั้งแต่ <?= th_date($loan['out_on']) ?><?= $loan['due_on'] ? ' · กำหนดคืน <b' . ($loan['due_on'] < $today ? ' style="color:var(--danger)"' : '') . '>' . th_date($loan['due_on']) . '</b>' : '' ?></p>
          <div class="field"><label for="returned_on">วันที่คืน</label><input class="input" type="date" id="returned_on" name="returned_on" value="<?= $today ?>"></div>
          <div class="field"><label for="rst">สภาพเมื่อคืน</label><select class="input" id="rst" name="status"><?= options(array_map(fn($s) => $s[0], array_intersect_key(KL_STATUS, array_flip(['normal', 'broken', 'worn', 'lost']))), $item['status'], true) ?></select></div>
          <div class="field"><label for="return_note">บันทึก</label><input class="input" id="return_note" name="return_note" maxlength="500" placeholder="เช่น ครบ สภาพดี"></div>
          <button class="btn btn-gold" type="submit">บันทึกรับคืน</button>
        </form>
      </section>
      <?php else: ?>
      <section class="panel" id="loan">
        <div class="panel-h"><h2>ยืม / เบิก</h2></div>
        <form class="panel-b grid" method="post"><?= csrf_field() ?><input type="hidden" name="act" value="loan">
          <div class="seg" role="radiogroup" aria-label="ชนิด">
            <label class="check" style="padding:6px 12px"><input type="radio" name="kind" value="loan" checked> ยืม (มีกำหนดคืน)</label>
            <label class="check" style="padding:6px 12px;border-left:1px solid var(--line-2)"><input type="radio" name="kind" value="issue"> เบิกไปประจำใช้</label>
          </div>
          <div class="field"><label for="borrower">ผู้ยืม/ผู้เบิก <span class="req">*</span></label><input class="input" id="borrower" name="borrower" required maxlength="200" list="dl-borrower" placeholder="ชื่อ–สกุล ตำแหน่ง"></div>
          <div class="field"><label for="purpose">เพื่อใช้ในงาน</label><input class="input" id="purpose" name="purpose" maxlength="500"></div>
          <div class="field"><label for="place">นำไปใช้ที่</label><input class="input" id="place" name="place" maxlength="200"></div>
          <div class="grid g2">
            <div class="field"><label for="out_on">วันที่ยืม</label><input class="input" type="date" id="out_on" name="out_on" value="<?= $today ?>"></div>
            <div class="field"><label for="due_on">กำหนดส่งคืน</label><input class="input" type="date" id="due_on" name="due_on" value="<?= date('Y-m-d', strtotime('+' . (int) $set['loan_days'] . ' days')) ?>"></div>
          </div>
          <div class="field"><label for="approver">ผู้อนุมัติ</label><input class="input" id="approver" name="approver" maxlength="200" value="<?= h($set['boss']) ?>"></div>
          <button class="btn btn-primary" type="submit">บันทึกการยืม</button>
          <p class="hint">ระเบียบฯ 2560 ข้อ 208: การยืมต้องมีหลักฐานเป็นลายลักษณ์อักษร แสดงเหตุผลและกำหนดวันส่งคืน ยืมใช้ในสถานที่ หัวหน้าหน่วยงานที่รับผิดชอบพัสดุอนุมัติ ยืมไปนอกสถานที่ ผู้อำนวยการอนุมัติ · ข้อ 211: ครบกำหนดแล้วต้องทวงคืนภายใน 7 วัน</p>
        </form>
        <datalist id="dl-borrower"><?php foreach ($people as $p): ?><option value="<?= h($p) ?>"><?php endforeach; ?></datalist>
      </section>
      <?php endif; ?>

      <section class="panel" id="status">
        <div class="panel-h"><h2>เปลี่ยนสถานะ</h2></div>
        <form class="panel-b grid" method="post"><?= csrf_field() ?><input type="hidden" name="act" value="status">
          <div class="field"><label for="st">สถานะ</label><select class="input" id="st" name="status"><?= options(array_map(fn($s) => $s[0], KL_STATUS), $item['status'], true) ?></select></div>
          <div class="field"><label for="why">เหตุผล/เลขที่หนังสือ</label><input class="input" id="why" name="why" maxlength="300" placeholder="เช่น ตรวจพบจอเสีย / อนุมัติจำหน่าย ที่ …"></div>
          <button class="btn" type="submit">บันทึกสถานะ</button>
        </form>
      </section>

      <?php if ($loans): ?>
      <section class="panel">
        <div class="panel-h"><h2>ประวัติยืม–คืน</h2></div>
        <ul class="feed"><?php foreach ($loans as $l): ?>
          <li><b><?= h($l['borrower']) ?></b> <span class="muted"><?= $l['kind'] === 'issue' ? 'เบิก' : 'ยืม' ?></span>
            <time><?= th_date($l['out_on']) ?> → <?= $l['returned_on'] ? 'คืน ' . th_date($l['returned_on']) : '<b>ยังไม่คืน</b>' ?> · <a href="print.php?doc=loan&amp;id=<?= (int) $l['id'] ?>" target="_blank">ใบยืม</a></time></li>
        <?php endforeach; ?></ul>
      </section>
      <?php endif; ?>

      <section class="panel">
        <div class="panel-h"><h2>ประวัติ</h2></div>
        <ul class="timeline"><?php foreach ($log as $g): ?>
          <li><time><?= th_date($g['at'], true) ?></time><span><?= h($g['detail']) ?></span></li>
        <?php endforeach; ?></ul>
      </section>

      <?php if ($canDelete): ?>
      <form method="post" style="margin-top:16px" data-confirm="ลบ <?= h($item['asset_no']) ?> ?" data-detail="ใช้เฉพาะกรณีลงทะเบียนผิด เลขนี้จะถูกนำกลับมาใช้ใหม่ได้" data-danger="1" data-ok-text="ลบรายการ">
        <?= csrf_field() ?><input type="hidden" name="act" value="delete">
        <button class="btn btn-danger btn-sm" type="submit">ลบรายการที่ลงผิด</button>
        <p class="hint">ลบได้เฉพาะวันที่ลงทะเบียน · หลังจากนั้นให้ใช้สถานะ “จำหน่าย” ตามระเบียบ</p>
      </form>
      <?php endif; ?>
    </div>
  </div>
</main>
<?php page_foot('<script type="module" nonce="' . csp_nonce() . '">
import { compress } from "./' . asset_v('assets/photo.js') . '";
import { api } from "./' . asset_v('assets/net.js') . '";
import * as n from "./' . asset_v('assets/notify.js') . '";
const id = ' . $id . ';
const st = document.getElementById("photo-status");
document.getElementById("add-photo")?.addEventListener("change", async (e) => {
  const files = [...e.target.files].slice(0, Number(e.target.dataset.room));
  if (!files.length) return;
  st.textContent = "กำลังย่อรูป…";
  try {
    const fd = new FormData(); fd.append("id", id);
    let thumb = "";
    for (const [i, f] of files.entries()) { const r = await compress(f); fd.append("photos[]", r.blob, "p" + i + ".jpg"); thumb ||= r.thumb; }
    fd.append("thumb", thumb);
    st.textContent = "กำลังอัปโหลด…";
    await api("photo.add", fd);
    location.reload();
  } catch (err) { st.textContent = ""; n.error(err.message); }
});
document.querySelectorAll("[data-del-photo]").forEach((b) => b.addEventListener("click", async () => {
  if (!(await n.confirm({ title: "ลบรูปนี้?", ok: "ลบรูป", icon: "warning" }))) return;
  try { await api("photo.del", { id, file: b.dataset.delPhoto }); location.reload(); } catch (err) { n.error(err.message); }
}));
</script>');
