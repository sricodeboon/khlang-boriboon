<?php
// ตรรกะครุภัณฑ์: ค่าตั้ง, ประเภท, ออกเลขอัตโนมัติ, สถานะ, ค่าเสื่อมราคา, ประวัติ
declare(strict_types=1);

/** สถานะตามการบริหารพัสดุ (ระเบียบกระทรวงการคลังฯ 2560 หมวด 9) · สีไม่มีเขียวตามกฎแบรนด์ */
const KL_STATUS = [
    'normal'   => ['ใช้งานได้', 'ok'],
    'broken'   => ['ชำรุด', 'warn'],
    'fixing'   => ['ส่งซ่อม', 'warn'],
    'worn'     => ['เสื่อมสภาพ', 'warn'],
    'lost'     => ['สูญไป', 'bad'],
    'unneeded' => ['ไม่จำเป็นต้องใช้', 'mute'],
    'dispose'  => ['รอจำหน่าย', 'pink'],
    'disposed' => ['จำหน่ายแล้ว', 'mute'],
];
// ข้อ 214: ชำรุด เสื่อมสภาพ สูญไป ไม่จำเป็นต้องใช้ → ตั้งกรรมการสอบข้อเท็จจริง แล้วจำหน่าย (ข้อ 215: ขาย แลกเปลี่ยน โอน แปรสภาพ/ทำลาย · ข้อ 217 จำหน่ายเป็นสูญ)

// ช่องติ๊กในแบบทะเบียนคุมทรัพย์สินของกรมบัญชีกลาง
const KL_FUNDS = ['เงินงบประมาณ', 'เงินนอกงบประมาณ', 'เงินบริจาค/เงินช่วยเหลือ', 'อื่น ๆ'];
// ชื่อวิธีตาม พ.ร.บ.จัดซื้อจัดจ้างฯ 2560 (แบบฟอร์มเดิมยังใช้ชื่อตามระเบียบ 2535)
const KL_METHODS = ['วิธีเฉพาะเจาะจง', 'วิธีคัดเลือก', 'วิธีประกาศเชิญชวนทั่วไป (e-bidding)', 'วิธีประกาศเชิญชวนทั่วไป (e-market)', 'รับบริจาค', 'รับโอน', 'แลกเปลี่ยน', 'อื่น ๆ'];

function status_label(string $s): string { return KL_STATUS[$s][0] ?? $s; }
function status_tone(string $s): string { return KL_STATUS[$s][1] ?? 'mute'; }
function status_badge(string $s): string {
    return '<span class="st st-' . status_tone($s) . '">' . h(status_label($s)) . '</span>';
}

/** ปีงบประมาณไทย (ต.ค.–ก.ย.) เป็น พ.ศ. */
function fiscal_year(?string $date = null): int {
    $t = $date ? strtotime($date) : time();
    if ($t === false) $t = time();
    $y = (int) date('Y', $t) + 543;
    return (int) date('n', $t) >= 10 ? $y + 1 : $y;
}

const TH_MONTHS = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
function th_date(?string $d, bool $withTime = false): string {
    if (!$d) return '–';
    $t = strtotime($d);
    if ($t === false) return '–';
    $s = (int) date('j', $t) . ' ' . TH_MONTHS[(int) date('n', $t)] . ' ' . ((int) date('Y', $t) + 543);
    return $withTime ? $s . ' ' . date('H:i', $t) : $s;
}
function money(float|string|null $n, int $dp = 2): string { return number_format((float) $n, $dp); }

// ---------------- ค่าตั้งต่อโรงเรียน ----------------
function settings_default(): array {
    return [
        'agency' => '',                 // ส่วนราชการ/ต้นสังกัด (หัวทะเบียนคุมทรัพย์สิน) เช่น สพป.นครพนม เขต 2
        'prefix' => '',                 // อักษรย่อหน่วยงาน (ถ้าใช้) เช่น "สพป.นพ.1"
        'format' => '{cat}-{seq}/{fy}', // รูปแบบเลข: {pre} {cat} {grp} {seq} {fy} {fy2}
        'digits' => 4,
        'scope' => 'cat_fy',            // cat_fy = เริ่ม 0001 ใหม่ทุกปีงบประมาณแยกตามชนิด (แบบคู่มือ ตร.) · cat = นับต่อเนื่องตามชนิด
        'loan_days' => 7,
        'officer' => '', 'officer_pos' => 'เจ้าหน้าที่พัสดุ',
        'head' => '', 'head_pos' => 'หัวหน้าเจ้าหน้าที่',
        'boss' => '', 'boss_pos' => 'ผู้อำนวยการโรงเรียน',
        'sticker' => 'a4-24',
        'checkers' => '',               // คณะกรรมการตรวจสอบพัสดุประจำปี (บรรทัดละคน: ชื่อ|ตำแหน่ง) ต้องไม่ใช่เจ้าหน้าที่พัสดุ (ข้อ 213)
    ];
}

function settings(int $schoolId): array {
    static $cache = [];
    if (isset($cache[$schoolId])) return $cache[$schoolId];
    $row = db_one('SELECT data_json FROM kl_settings WHERE school_id = ?', [$schoolId]);
    $d = $row ? json_decode((string) $row['data_json'], true) : null;
    return $cache[$schoolId] = array_merge(settings_default(), is_array($d) ? $d : []);
}

function settings_save(int $schoolId, array $data): void {
    $d = array_intersect_key(array_merge(settings($schoolId), $data), settings_default());
    $json = json_encode($d, JSON_UNESCAPED_UNICODE);
    $n = db_exec('UPDATE kl_settings SET data_json = ?, updated_at = ? WHERE school_id = ?', [$json, now(), $schoolId]);
    if ($n === 0) db_exec('INSERT INTO kl_settings (school_id, data_json, updated_at) VALUES (?,?,?)', [$schoolId, $json, now()]);
}

// ---------------- ประเภท/ชนิดครุภัณฑ์ ----------------
function cat_catalog(): array {
    static $c = null;
    return $c ??= require APP_ROOT . '/data/categories.php';
}

/** ประเภทของโรงเรียน · ครั้งแรกคัดลอกจากบัญชีมาตรฐาน */
function cats(int $schoolId): array {
    $rows = db_all('SELECT code, name, grp, life_years, unit FROM kl_cats WHERE school_id = ? ORDER BY code', [$schoolId]);
    if ($rows) return array_column($rows, null, 'code');
    db_tx(function () use ($schoolId) {
        if (db_val('SELECT COUNT(*) FROM kl_cats WHERE school_id = ?', [$schoolId])) return;
        foreach (cat_catalog() as $c) {
            db_exec('INSERT INTO kl_cats (school_id, code, name, grp, life_years, unit) VALUES (?,?,?,?,?,?)',
                [$schoolId, $c['code'], $c['name'], $c['grp'], $c['life'], $c['unit']]);
        }
    });
    return array_column(db_all('SELECT code, name, grp, life_years, unit FROM kl_cats WHERE school_id = ? ORDER BY code', [$schoolId]), null, 'code');
}

/** รหัสชนิดที่รับ: ตัวเลข/อักษรละติน คั่นด้วย - เช่น 7440-001 */
function valid_cat_code(string $c): bool { return (bool) preg_match('/^[0-9A-Za-z]{1,6}(-[0-9A-Za-z]{1,6}){0,2}$/', $c); }

// ---------------- ออกเลขครุภัณฑ์ ----------------
function format_asset_no(array $set, string $cat, int $seq, int $fy): string {
    $digits = max(1, min(6, (int) $set['digits']));
    $grp = explode('-', $cat)[0];
    $out = strtr((string) $set['format'], [
        '{pre}' => (string) $set['prefix'],
        '{cat}' => $cat,
        '{grp}' => $grp,
        '{seq}' => str_pad((string) $seq, $digits, '0', STR_PAD_LEFT),
        '{fy}' => (string) $fy,
        '{fy2}' => substr((string) $fy, -2),
    ]);
    // เว้น {pre} ว่างแล้วเหลือจุด/ขีดนำหน้า → ตัดออก
    return trim(preg_replace('/^[.\-\/\s]+/u', '', $out) ?? $out);
}

function next_seq(int $schoolId, string $cat, int $fy, string $scope): int {
    $sql = 'SELECT MAX(seq) FROM kl_items WHERE school_id = ? AND cat_code = ?';
    $args = [$schoolId, $cat];
    if ($scope === 'cat_fy') { $sql .= ' AND fy = ?'; $args[] = $fy; }
    return (int) db_val($sql, $args) + 1;
}

/** เลขที่จะได้ถ้าบันทึกตอนนี้ (แสดงตัวอย่างในฟอร์ม) */
function preview_numbers(int $schoolId, string $cat, int $fy, int $qty): array {
    $set = settings($schoolId);
    $seq = next_seq($schoolId, $cat, $fy, $set['scope']);
    $out = [];
    for ($i = 0; $i < $qty; $i++) {
        $no = format_asset_no($set, $cat, $seq + $i, $fy);
        // เลขที่ตั้งเองไว้ก่อนแล้วชน → ข้ามไปเลขถัดไป (ตรวจจริงอีกรอบตอนบันทึก)
        $out[] = $no;
    }
    return $out;
}

function new_token(): string {
    $abc = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    for ($try = 0; $try < 5; $try++) {
        $t = '';
        for ($i = 0; $i < 10; $i++) $t .= $abc[random_int(0, 31)];
        if (!db_val('SELECT 1 FROM kl_items WHERE token = ?', [$t])) return $t;
    }
    throw new RuntimeException('สร้างรหัส QR ไม่สำเร็จ');
}

/** ฟิลด์ที่แก้ได้จากฟอร์ม (ทำความสะอาดแล้ว) */
function item_fields_from_post(array $cats): array {
    $cat = post('cat_code', 20);
    if (!isset($cats[$cat])) throw new InvalidArgumentException('กรุณาเลือกประเภทครุภัณฑ์');
    $name = post('name', 200);
    if ($name === '') throw new InvalidArgumentException('กรุณากรอกชื่อครุภัณฑ์');
    $price = (float) str_replace([',', ' '], '', post('price', 20));
    if ($price < 0 || $price > 999999999) throw new InvalidArgumentException('ราคาไม่ถูกต้อง');
    $date = post('acquired_on', 10);
    if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new InvalidArgumentException('วันที่ได้มาไม่ถูกต้อง');
    $life = (int) post('life_years', 3);
    if ($life < 1 || $life > 50) $life = (int) $cats[$cat]['life_years'];
    $status = post('status', 20);
    if (!isset(KL_STATUS[$status])) $status = 'normal';
    return [
        'cat_code' => $cat, 'name' => $name,
        'brand' => post('brand', 120), 'model' => post('model', 120), 'serial_no' => post('serial_no', 120),
        'spec' => post('spec', 1000), 'unit' => post('unit', 30) ?: ($cats[$cat]['unit'] ?: 'หน่วย'),
        'price' => round($price, 2), 'acquired_on' => $date ?: null, 'doc_no' => post('doc_no', 120),
        'fund' => post('fund', 60), 'method' => post('method', 60), 'vendor' => post('vendor', 200),
        'location' => post('location', 200), 'custodian' => post('custodian', 200),
        'status' => $status, 'life_years' => $life, 'note' => post('note', 1000),
    ];
}

/**
 * บันทึกครุภัณฑ์ใหม่ $qty ชิ้น เลขเรียงต่อกัน (ในธุรกรรมเดียว กันเลขซ้ำ)
 * $serials: เลขเครื่องรายชิ้น (ถ้ามี) · คืน id ทั้งหมด
 */
function items_create(int $schoolId, int $userId, array $f, int $qty, array $serials = [], ?string $manualNo = null): array {
    $qty = max(1, min(100, $qty));
    return db_tx(function () use ($schoolId, $userId, $f, $qty, $serials, $manualNo) {
        $set = settings($schoolId);
        $fy = fiscal_year($f['acquired_on']);
        $seq = next_seq($schoolId, $f['cat_code'], $fy, $set['scope']);
        $ids = [];
        for ($i = 0; $i < $qty; $i++) {
            if ($manualNo !== null && $qty === 1) {
                $no = $manualNo;
                if (db_val('SELECT 1 FROM kl_items WHERE school_id = ? AND asset_no = ?', [$schoolId, $no])) {
                    throw new InvalidArgumentException('หมายเลข ' . $no . ' มีอยู่ในทะเบียนแล้ว');
                }
            } else {
                // เลขที่มีคนตั้งเองไว้ก่อนแล้ว (นำเข้าของเดิม) → ขยับไปเลขถัดไป
                do { $no = format_asset_no($set, $f['cat_code'], $seq, $fy); $seq++; }
                while (db_val('SELECT 1 FROM kl_items WHERE school_id = ? AND asset_no = ?', [$schoolId, $no]));
            }
            $row = $f + ['serial_no' => ''];
            if (!empty($serials[$i])) $row['serial_no'] = mb_substr(trim((string) $serials[$i]), 0, 120);
            db_exec('INSERT INTO kl_items (school_id, token, asset_no, cat_code, seq, fy, name, brand, model, serial_no, spec, unit, price,
                        acquired_on, doc_no, fund, method, vendor, location, custodian, status, life_years, note, created_at, updated_at, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                $schoolId, new_token(), $no, $f['cat_code'], $manualNo !== null && $qty === 1 ? 0 : $seq - 1, $fy,
                $row['name'], $row['brand'], $row['model'], $row['serial_no'], $row['spec'], $row['unit'], $row['price'],
                $row['acquired_on'], $row['doc_no'], $row['fund'], $row['method'], $row['vendor'], $row['location'], $row['custodian'],
                $row['status'], $row['life_years'], $row['note'], now(), now(), $userId,
            ]);
            $id = (int) db()->lastInsertId();
            $ids[] = $id;
            log_event($schoolId, $id, 'create', 'ลงทะเบียน ' . $no . ' · ' . $row['name'] . ' ราคา ' . money($row['price']) . ' บาท', $userId);
        }
        return $ids;
    });
}

const KL_FIELD_LABELS = [
    'name' => 'ชื่อ', 'brand' => 'ยี่ห้อ', 'model' => 'รุ่น/แบบ', 'serial_no' => 'เลขเครื่อง', 'spec' => 'คุณลักษณะ', 'unit' => 'หน่วยนับ',
    'price' => 'ราคา', 'acquired_on' => 'วันที่ได้มา', 'doc_no' => 'ที่เอกสาร', 'fund' => 'ประเภทเงิน', 'method' => 'วิธีการได้มา',
    'vendor' => 'ผู้ขาย/ผู้บริจาค', 'location' => 'สถานที่ตั้ง', 'custodian' => 'ผู้รับผิดชอบ', 'status' => 'สถานะ',
    'life_years' => 'อายุการใช้งาน', 'note' => 'หมายเหตุ', 'cat_code' => 'ประเภท',
];

/** แก้ไข (ไม่เปลี่ยนเลขครุภัณฑ์ — เลขออกแล้วถือว่าติดตัวครุภัณฑ์) · บันทึกประวัติเฉพาะช่องที่เปลี่ยน */
function item_update(array $item, array $f, int $userId): void {
    unset($f['cat_code']);
    $changed = [];
    foreach ($f as $k => $v) {
        $old = $item[$k] ?? null;
        $same = $k === 'price' ? abs((float) $old - (float) $v) < 0.005 : (string) $old === (string) ($v ?? '');
        if (!$same) $changed[$k] = $v;
    }
    if (!$changed) return;
    $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($changed)));
    db_exec("UPDATE kl_items SET $sets, updated_at = ? WHERE id = ? AND school_id = ?",
        [...array_values($changed), now(), $item['id'], $item['school_id']]);
    $parts = [];
    foreach ($changed as $k => $v) {
        $label = KL_FIELD_LABELS[$k] ?? $k;
        if ($k === 'status') $parts[] = 'สถานะ: ' . status_label((string) $item['status']) . ' → ' . status_label((string) $v);
        elseif (in_array($k, ['location', 'custodian'], true)) $parts[] = $label . ': ' . ($item[$k] ?: '–') . ' → ' . ($v ?: '–');
        else $parts[] = $label;
    }
    log_event((int) $item['school_id'], (int) $item['id'], isset($changed['status']) ? 'status' : 'edit', 'แก้ไข ' . implode(' · ', $parts), $userId);
}

function item_get(int $schoolId, int $id): ?array {
    return db_one('SELECT * FROM kl_items WHERE id = ? AND school_id = ?', [$id, $schoolId]);
}

function item_by_code(int $schoolId, string $code): ?array {
    $code = trim($code);
    if ($code === '') return null;
    // สแกน QR ได้ URL ทั้งเส้น → ดึง t=… ออกมา
    if (preg_match('#[?&]t=([0-9A-Z]{10})#', $code, $m)) $code = $m[1];
    $row = db_one('SELECT * FROM kl_items WHERE school_id = ? AND (token = ? OR asset_no = ?)', [$schoolId, strtoupper($code), $code]);
    if ($row) return $row;
    return db_one('SELECT * FROM kl_items WHERE school_id = ? AND serial_no = ? AND serial_no <> \'\'', [$schoolId, $code]);
}

function log_event(int $schoolId, ?int $itemId, string $kind, string $detail, ?int $userId): void {
    db_exec('INSERT INTO kl_log (school_id, item_id, at, kind, detail, user_id) VALUES (?,?,?,?,?,?)',
        [$schoolId, $itemId, now(), $kind, mb_substr($detail, 0, 1000), $userId]);
}

// ---------------- ค่าเสื่อมราคา ----------------
// หนังสือกรมบัญชีกลาง ว 238 (2557): วิธีเส้นตรง ไม่มีราคาซากแต่คงมูลค่า 1 บาท · ค่าเสื่อมต่อปี = ราคาทุน ÷ อายุ
// นับเวลาตามคู่มือ สพฐ./สพป.: ได้มาวันที่ 1–15 นับเดือนนั้นเต็มเดือน วันที่ 16 ขึ้นไปเริ่มเดือนถัดไป · ปิดรอบ 30 ก.ย.

/** เกณฑ์ราคาต่อหน่วยที่ต้องคิดค่าเสื่อม ตามปีงบประมาณที่ได้มา (ว 43/2562: ≥10,000 ตั้งแต่ปีงบฯ 2563) · null = ไม่คิดค่าเสื่อม */
function dep_threshold(int $fy): ?float {
    if ($fy >= 2563) return 10000.0;
    if ($fy >= 2546) return 5000.0;
    if ($fy >= 2540) return 30000.0;
    return null;
}

/** ต่ำกว่าเกณฑ์ = ยังลงทะเบียนคุม แต่ไม่คิดค่าเสื่อม */
function below_threshold(array $item): bool {
    $t = dep_threshold((int) $item['fy']);
    return $t === null || (float) $item['price'] < $t;
}

function depreciation(float $price, ?string $acquired, int $life, ?int $untilFy = null): array {
    if ($price <= 1 || !$acquired || $life < 1) return [];
    $t = strtotime($acquired);
    if ($t === false) return [];
    $annual = $price / $life;
    $untilFy ??= fiscal_year();
    // เดือนแรกที่นับ (เลขเดือนต่อเนื่อง: ปี ค.ศ.×12 + เดือน)
    $m = (int) date('Y', $t) * 12 + (int) date('n', $t) - 1 + ((int) date('j', $t) > 15 ? 1 : 0);
    $rows = [];
    $accum = 0.0;
    $fy = fiscal_year(sprintf('%04d-%02d-01', intdiv($m, 12), $m % 12 + 1));
    while ($fy <= $untilFy && $accum < $price - 1 - 0.004) {
        $fyLastMonth = ($fy - 543) * 12 + 8; // ก.ย. ของปีงบประมาณนั้น
        $months = min(12, $fyLastMonth - $m + 1);
        $dep = round($annual * $months / 12, 2);
        $dep = min($dep, round($price - 1 - $accum, 2));
        $accum = round($accum + $dep, 2);
        $rows[] = ['fy' => $fy, 'months' => $months, 'dep' => $dep, 'accum' => $accum, 'net' => round($price - $accum, 2)];
        $m = $fyLastMonth + 1;
        $fy++;
    }
    return $rows;
}

function net_value(array $item): float {
    if (below_threshold($item)) return (float) $item['price'];
    $rows = depreciation((float) $item['price'], $item['acquired_on'], (int) $item['life_years']);
    return $rows ? end($rows)['net'] : (float) $item['price'];
}

// ---------------- รูปถ่าย ----------------
function photos(array $item): array {
    $p = json_decode((string) ($item['photos_json'] ?? ''), true);
    return is_array($p) ? array_values(array_filter($p, 'is_string')) : [];
}

function photo_dir(int $schoolId): string { return APP_ROOT . '/storage/photos/' . $schoolId; }

function photo_url(array $item, string $file): string {
    return 'photo.php?i=' . (int) $item['id'] . '&f=' . rawurlencode($file);
}

// ---------------- ยืม/เบิก ----------------
function open_loan(int $itemId): ?array {
    return db_one('SELECT * FROM kl_loans WHERE item_id = ? AND returned_on IS NULL ORDER BY id DESC LIMIT 1', [$itemId]);
}

function public_url(array $item): string { return base_url('s.php?t=' . $item['token']); }

/** ลบข้อมูลของโรงเรียนที่ไม่มีแล้ว (โรงเรียนทดลองของตารางบริบูรณ์ถูกลบเองหลัง 48 ชม.) */
function cleanup_orphans(): void {
    $gone = db_all('SELECT DISTINCT i.school_id FROM kl_items i LEFT JOIN schools s ON s.id = i.school_id WHERE s.id IS NULL');
    foreach ($gone as $g) {
        $sid = (int) $g['school_id'];
        db_tx(function () use ($sid) {
            foreach (['kl_items', 'kl_loans', 'kl_log', 'kl_cats', 'kl_settings'] as $t) db_exec("DELETE FROM $t WHERE school_id = ?", [$sid]);
        });
        $dir = photo_dir($sid);
        foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($dir);
    }
}

/** QR เป็น SVG (data URL) สำหรับแสดงบนหน้าเว็บ */
function qr_data_url(string $text, int $size = 112): string {
    require_once APP_ROOT . '/vendor/autoload.php';
    $svg = (new \Mpdf\QrCode\Output\Svg())->output(new \Mpdf\QrCode\QrCode($text, 'M'), $size, 'white', '#0F2438');
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}
