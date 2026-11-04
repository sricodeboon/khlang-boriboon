<?php
// ทดสอบตรรกะยืม/เบิก/รับคืนหลายรายการบนฐานข้อมูลชั่วคราว (ไม่แตะข้อมูลจริง) · รัน: php tests/loans_test.php
declare(strict_types=1);
date_default_timezone_set('Asia/Bangkok');
define('APP_ROOT', dirname(__DIR__));
$GLOBALS['TEST_DB'] = tempnam(sys_get_temp_dir(), 'kl');
function cfg(string $p, $d = null) { return $d; }
function tt_dir(): string { return sys_get_temp_dir(); }
function tt_cfg(string $k, $d = null) { return $k === 'db.sqlite_path' ? $GLOBALS['TEST_DB'] : $d; }
require APP_ROOT . '/lib/db.php';
require APP_ROOT . '/lib/assets.php';

$fails = 0;
function ok(string $name, bool $cond): void { global $fails; echo ($cond ? 'PASS ' : 'FAIL ') . $name . "\n"; if (!$cond) $fails++; }
function rejects(string $name, callable $fn, string $needle = ''): void {
    try { $fn(); ok($name, false); }
    catch (InvalidArgumentException $e) { ok($name, $needle === '' || str_contains($e->getMessage(), $needle)); }
}
function seed(int $sid, string $no, string $status = 'normal'): int {
    static $n = 0; $n++;
    db_exec('INSERT INTO kl_items (school_id, token, asset_no, cat_code, seq, fy, name, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
        [$sid, 'T' . $n, $no, '7440', $n, 2570, 'ของ ' . $no, $status, now(), now()]);
    return (int) db()->lastInsertId();
}
$open = fn(int $id) => open_loan($id) !== null;
$today = date('Y-m-d');
$next = date('Y-m-d', strtotime('+7 days'));
$base = ['kind' => 'loan', 'borrower' => 'ครูเอ', 'purpose' => 'กีฬาสี', 'place' => 'สนาม', 'out_on' => $today, 'due_on' => $next, 'approver' => 'ผอ.'];

$a = seed(1, 'A'); $b = seed(1, 'B'); $c = seed(1, 'C');
$first = loans_create(1, [$a, $b, $c, $a], $base, 9);
ok('ยืม 3 ชิ้น ตัดรายการซ้ำ ได้ 3 แถว', (int) db_val('SELECT COUNT(*) FROM kl_loans WHERE batch_id = ?', [$first]) === 3);
ok('ทุกชิ้น on_loan', (int) db_val('SELECT COUNT(*) FROM kl_items WHERE on_loan = 1') === 3);
$slip = loan_slip(1, (int) db_val('SELECT id FROM kl_loans WHERE item_id = ?', [$c]));
ok('เปิดใบจากแถวไหนก็ได้ทั้งชุด', count($slip['loans']) === 3 && $slip['no'] === $first && isset($slip['items'][$a]));
$rev = loan_slip(1, loans_create(1, [seed(1, 'Z-2'), seed(1, 'Z-10'), seed(1, 'Z-1')], $base, 9));
ok('ในใบเรียงตามเลขครุภัณฑ์', array_map(fn($l) => $rev['items'][(int) $l['item_id']]['asset_no'], $rev['loans']) === ['Z-1', 'Z-2', 'Z-10']);
ok('โรงเรียนอื่นเปิดใบไม่ได้', loan_slip(2, $first) === null);

$d = seed(1, 'D'); $bro = seed(1, 'BROKEN', 'broken'); $other = seed(2, 'OTHER');
rejects('ชุดมีของที่ถูกยืมอยู่ → ปฏิเสธและบอกเลข', fn() => loans_create(1, [$d, $a], $base, 9), 'A ยังไม่ได้คืน');
ok('ชิ้นที่ยืมได้ไม่ถูกบันทึกไปด้วย', !$open($d) && (int) db_val('SELECT on_loan FROM kl_items WHERE id = ?', [$d]) === 0);
rejects('ของเสียยืมไม่ได้', fn() => loans_create(1, [$d, $bro], $base, 9), 'BROKEN');
rejects('ของโรงเรียนอื่นยืมไม่ได้', fn() => loans_create(1, [$d, $other], $base, 9), 'ไม่พบ');
rejects('ไม่มีชื่อผู้ยืม', fn() => loans_create(1, [$d], ['borrower' => ' '] + $base, 9), 'ผู้ยืม');
rejects('ยืมไม่มีกำหนดคืน', fn() => loans_create(1, [$d], ['due_on' => ''] + $base, 9), 'กำหนด');
rejects('กำหนดคืนก่อนวันยืม', fn() => loans_create(1, [$d], ['due_on' => '2020-01-01'] + $base, 9), 'ก่อนวันยืม');
rejects('ไม่เลือกของ', fn() => loans_create(1, [], $base, 9));
rejects('เกิน 100 รายการ', fn() => loans_create(1, range(1000, 1100), $base, 9), '100');

$single = loans_create(1, [$d], ['kind' => 'issue'] + $base, 9);
$row = db_one('SELECT * FROM kl_loans WHERE id = ?', [$single]);
ok('เบิกชิ้นเดียว: batch_id ว่าง ไม่มีวันคืน', $row['batch_id'] === null && $row['due_on'] === null && $row['kind'] === 'issue');
ok('ใบเดี่ยวมีแถวเดียว', count(loan_slip(1, $single)['loans']) === 1);

$n = loans_return(1, [['id' => $a, 'status' => 'broken', 'note' => 'จอแตก'], ['id' => $b, 'status' => 'normal', 'note' => '']], $today, 9);
ok('รับคืนบางชิ้น คนละสภาพ', $n === 2 && !$open($a) && !$open($b) && $open($c));
ok('สภาพแยกรายชิ้น', item_get(1, $a)['status'] === 'broken' && item_get(1, $b)['status'] === 'normal');
ok('บันทึกสภาพลงแถวยืม', db_val('SELECT return_note FROM kl_loans WHERE item_id = ?', [$a]) === 'จอแตก');
rejects('คืนชิ้นที่ไม่ได้ถูกยืม → ไม่บันทึกทั้งชุด', fn() => loans_return(1, [['id' => $c, 'status' => 'normal'], ['id' => $a, 'status' => 'normal']], $today, 9), 'A: ไม่มีรายการยืม');
ok('ชิ้นที่คืนได้ยังค้างอยู่หลังปฏิเสธ', $open($c));
rejects('คืนก่อนวันยืม', fn() => loans_return(1, [['id' => $c]], '2020-01-01', 9), 'ก่อนวันที่ยืม');
rejects('ของโรงเรียนอื่นคืนไม่ได้', fn() => loans_return(2, [['id' => $c]], $today, 9), 'ไม่พบ');
db_exec("UPDATE kl_items SET status = 'fixing' WHERE id = ?", [$c]);
loans_return(1, [['id' => $c, 'status' => 'แฮก']], $today, 9);
ok('สภาพแปลกปลอม → คงสถานะเดิม ไม่กลายเป็นใช้งานได้', item_get(1, $c)['status'] === 'fixing');
ok('ยืมอีกครั้งหลังคืนได้', loans_create(1, [$b], $base, 9) > 0);
kl_migrate(db(), 'sqlite');
ok('migrate รันซ้ำได้', true);

// ฐานข้อมูลบนเว็บจริงสร้างก่อนมี batch_id: แถวเดิมต้องอยู่ครบและเปิดใบยืมได้
$oldId = loans_create(1, [seed(1, 'OLD')], $base, 9);
$before = (int) db_val('SELECT COUNT(*) FROM kl_loans');
db()->exec('DROP INDEX kl_loans_batch');
db()->exec('ALTER TABLE kl_loans DROP COLUMN batch_id');
kl_migrate(db(), 'sqlite');
ok('ฐานเดิม: เพิ่มคอลัมน์แล้วแถวเดิมอยู่ครบ', (int) db_val('SELECT COUNT(*) FROM kl_loans') === $before);
$oldSlip = loan_slip(1, $oldId);
ok('ฐานเดิม: ใบยืมเก่าเปิดได้เป็นใบเดี่ยว', count($oldSlip['loans']) === 1 && $oldSlip['no'] === $oldId);

@unlink($GLOBALS['TEST_DB']);
echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit($fails ? 1 : 0);
