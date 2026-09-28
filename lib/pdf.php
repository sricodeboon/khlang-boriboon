<?php
// เอกสาร PDF ด้วย mPDF (สร้างที่เซิร์ฟเวอร์): สติกเกอร์ QR · ทะเบียนคุมทรัพย์สิน · บัญชีครุภัณฑ์ · ใบยืมพัสดุ · รายงานตรวจสอบพัสดุประจำปี
// ฟอนต์ TH Sarabun New (ฟอนต์ราชการ) + Sarabun · ทั้งสองมี glyph U+200B ว่างแล้ว (ตัดคำไทยด้วย ICU ผ่าน thai_lbr)
declare(strict_types=1);

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;

function thai_lbr(string $html): string {
    if (!class_exists('IntlBreakIterator')) return $html;
    static $bi = null;
    $bi ??= IntlBreakIterator::createWordInstance('th');
    // ไม่แตะข้อความในแท็ก (แอตทริบิวต์) — แทรกเฉพาะข้อความไทย
    return preg_replace_callback('/(<[^>]*>)|(\p{Thai}+)/u', function ($r) use ($bi) {
        if ($r[1] !== '') return $r[1];
        $t = $r[2];
        $bi->setText($t);
        $out = ''; $prev = 0;
        foreach ($bi as $pos) {
            if ($pos === 0) continue;
            $out .= ($out !== '' ? "\u{200B}" : '') . substr($t, $prev, $pos - $prev);
            $prev = $pos;
        }
        return $out;
    }, $html) ?? $html;
}

function pdf_write(Mpdf $m, string $html): void { $m->WriteHTML(thai_lbr($html)); }

function make_mpdf(string $format = 'A4', array $opt = []): Mpdf {
    require_once APP_ROOT . '/vendor/autoload.php';
    $tmp = APP_ROOT . '/storage/tmp';
    if (!is_dir($tmp)) @mkdir($tmp, 0775, true);
    $m = new Mpdf($opt + [
        'mode' => 'utf-8', 'format' => $format, 'tempDir' => $tmp,
        'fontDir' => array_merge([APP_ROOT . '/fonts'], (new ConfigVariables())->getDefaults()['fontDir']),
        'fontdata' => [
            'thsarabun' => ['R' => 'THSarabunNew-Regular.ttf', 'B' => 'THSarabunNew-Bold.ttf', 'useOTL' => 0xFF],
            'sarabun' => ['R' => 'Sarabun-Regular.ttf', 'B' => 'Sarabun-Bold.ttf', 'useOTL' => 0xFF],
            'dejavusanscondensed' => (new FontVariables())->getDefaults()['fontdata']['dejavusanscondensed'],
        ],
        'default_font' => 'thsarabun',
        'useDictionaryLBR' => !class_exists('IntlBreakIterator'),
        'margin_top' => 14, 'margin_bottom' => 14, 'margin_left' => 15, 'margin_right' => 12,
    ]);
    $m->SetCreator('คลังบริบูรณ์ · ศรีโค้ดบูรณ์');
    return $m;
}

function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function box(bool $on): string { return $on ? '&#9745;' : '&#9744;'; } // ☑ ☐ (DejaVu)

const PDF_CSS = '<style>
body { font-family: thsarabun; font-size: 15pt; line-height: 1.2; color: #000; }
h1 { font-size: 20pt; text-align: center; margin: 0 0 2mm; font-weight: bold; }
.sub { text-align: center; font-size: 15pt; margin: 0 0 3mm; }
table.t { width: 100%; border-collapse: collapse; }
table.t th, table.t td { border: 0.2mm solid #000; padding: 0.6mm 1.4mm; vertical-align: top; font-size: 13.5pt; }
table.t th { font-weight: bold; text-align: center; vertical-align: middle; background: #EEF0EC; }
.r { text-align: right; } .c { text-align: center; }
.cb { font-family: dejavusanscondensed; font-size: 10pt; }
.meta td { padding: 0.4mm 1mm; font-size: 15pt; vertical-align: top; }
.dot { border-bottom: 0.2mm dotted #000; }
.sign { width: 100%; margin-top: 8mm; }
.sign td { text-align: center; vertical-align: top; font-size: 15pt; padding-top: 4mm; }
.small { font-size: 12pt; color: #333; }
</style>';

/** ช่องลงนาม: ลงชื่อ ...... / (ชื่อ) / ตำแหน่ง */
function sign_cell(string $role, string $name, string $pos): string {
    $n = trim($name) !== '' ? '(' . e($name) . ')' : '(....................................................)';
    return '<td>ลงชื่อ ....................................................' . ($role !== '' ? ' ' . e($role) : '') . '<br>' . $n . '<br>' . e($pos ?: '.......................................') . '</td>';
}

/** ข้อความผู้ลงนามจากบรรทัด "ชื่อ|ตำแหน่ง" */
function parse_people(string $txt): array {
    $out = [];
    foreach (preg_split('/\R/u', $txt) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') continue;
        [$n, $p] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
        $out[] = [$n, $p];
    }
    return $out;
}

// ---------------- สติกเกอร์ ----------------
const KL_LABELS = [
    'a4-24' => ['A4 24 ดวง (70 × 37 มม.)', 3, 8, 70.0, 37.0, 0.0, 0.5, 0.0, 0.0],
    'a4-21' => ['A4 21 ดวง (70 × 42.3 มม.)', 3, 7, 70.0, 42.3, 0.0, 0.0, 0.0, 0.0],
    'a4-14' => ['A4 14 ดวง (99.1 × 38.1 มม.)', 2, 7, 99.1, 38.1, 4.65, 15.15, 2.5, 0.0],
    'a4-10' => ['A4 10 ดวง (99.1 × 57 มม.)', 2, 5, 99.1, 57.0, 4.65, 6.0, 2.5, 0.0],
];
// [ชื่อ, คอลัมน์, แถว, กว้าง, สูง, ขอบซ้าย, ขอบบน, ช่องไฟแนวนอน, ช่องไฟแนวตั้ง] (มม.)

function pdf_stickers(array $items, array $school, string $preset, int $skip, float $shiftX, float $shiftY, bool $barcode): Mpdf {
    [, $cols, $rows, $w, $h, $ml, $mt, $gx, $gy] = KL_LABELS[$preset] ?? KL_LABELS['a4-24'];
    $m = make_mpdf('A4', ['margin_top' => 0, 'margin_bottom' => 0, 'margin_left' => 0, 'margin_right' => 0]);
    $m->SetTitle('สติกเกอร์ครุภัณฑ์');
    $m->SetAutoPageBreak(false);
    $qrOut = new \Mpdf\QrCode\Output\Mpdf();
    $per = $cols * $rows;
    $slot = max(0, min($per - 1, $skip));
    $m->AddPage();
    foreach ($items as $it) {
        if ($slot >= $per) { $m->AddPage(); $slot = 0; }
        $x = $ml + $shiftX + ($slot % $cols) * ($w + $gx);
        $y = $mt + $shiftY + intdiv($slot, $cols) * ($h + $gy);
        $pad = 2.5;
        $q = min($h - 2 * $pad, $w * 0.42);
        $qr = new \Mpdf\QrCode\QrCode(public_url($it), 'M');
        $qr->disableBorder();
        $qrOut->output($qr, $m, $x + $pad, $y + ($h - $q) / 2, $q);
        $tx = $x + $pad + $q + 1.5;
        $tw = $w - ($tx - $x) - $pad;
        $long = $w > 90;
        $html = '<div style="font-family:thsarabun;line-height:1.02">'
            . '<div style="font-size:' . ($long ? 11 : 9.5) . 'pt;color:#333">' . e(mb_strimwidth($school['name'], 0, $long ? 60 : 40, '…')) . '</div>'
            . '<div style="font-size:' . ($long ? 16 : 13.5) . 'pt;font-weight:bold">' . e($it['asset_no']) . '</div>'
            . '<div style="font-size:' . ($long ? 13 : 11.5) . 'pt">' . e(mb_strimwidth($it['name'], 0, $long ? 70 : 44, '…')) . '</div>'
            . ($it['serial_no'] !== '' && $h >= 37 ? '<div style="font-size:9.5pt;color:#333">S/N ' . e(mb_strimwidth((string) $it['serial_no'], 0, 30, '…')) . '</div>' : '')
            . ($barcode ? '<div style="margin-top:0.6mm"><barcode code="' . e($it['token']) . '" type="C128B" size="' . ($long ? 0.62 : 0.5) . '" height="0.45" pr="0" /></div>' : '')
            . '</div>';
        $m->WriteFixedPosHTML(thai_lbr($html), $tx, $y + $pad, $tw, $h - 2 * $pad, 'hidden');
        $slot++;
    }
    return $m;
}

// ---------------- ทะเบียนคุมทรัพย์สิน (1 หน้าต่อ 1 รายการ ตามแบบกรมบัญชีกลาง) ----------------
function pdf_register(array $items, array $school, array $set, array $cats): Mpdf {
    $m = make_mpdf('A4-L', ['margin_top' => 10, 'margin_bottom' => 10, 'margin_left' => 12, 'margin_right' => 10]);
    $m->SetTitle('ทะเบียนคุมทรัพย์สิน');
    $first = true;
    foreach ($items as $it) {
        if (!$first) $m->AddPage();
        $first = false;
        $cat = $cats[$it['cat_code']] ?? ['name' => '', 'grp' => ''];
        $life = (int) $it['life_years'];
        $below = below_threshold($it);
        $dep = $below ? [] : depreciation((float) $it['price'], $it['acquired_on'], $life);
        $funds = ''; foreach (KL_FUNDS as $f) $funds .= '<span class="cb">' . box($it['fund'] === $f) . '</span> ' . e($f) . '&nbsp;&nbsp; ';
        $methods = ''; foreach (KL_METHODS as $f) $methods .= '<span class="cb">' . box($it['method'] === $f) . '</span> ' . e($f) . '&nbsp;&nbsp; ';
        $rate = $below ? '-' : money(100 / max(1, $life));
        $rows = '<tr><td class="c">' . th_date($it['acquired_on']) . '</td><td>' . e($it['doc_no']) . '</td><td>' . e($it['name'] . ($it['serial_no'] ? ' S/N ' . $it['serial_no'] : '')) . '</td>'
            . '<td class="c">1</td><td class="r">' . money($it['price']) . '</td><td class="r">' . money($it['price']) . '</td>'
            . '<td class="c">' . ($below ? '-' : $life) . '</td><td class="c">' . $rate . '</td><td class="r">-</td><td class="r">-</td><td class="r">' . money($it['price']) . '</td><td>' . ($below ? 'ต่ำกว่าเกณฑ์ ไม่คิดค่าเสื่อม' : '') . '</td></tr>';
        foreach ($dep as $d) {
            $rows .= '<tr><td class="c">30 ก.ย. ' . $d['fy'] . '</td><td></td><td>ค่าเสื่อมราคาประจำปีงบประมาณ ' . $d['fy'] . ($d['months'] < 12 ? ' (' . $d['months'] . ' เดือน)' : '') . '</td>'
                . '<td></td><td></td><td></td><td></td><td></td><td class="r">' . money($d['dep']) . '</td><td class="r">' . money($d['accum']) . '</td><td class="r">' . money($d['net']) . '</td><td></td></tr>';
        }
        for ($i = count($dep); $i < 8; $i++) $rows .= '<tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>';
        $html = PDF_CSS . '<h1>ทะเบียนคุมทรัพย์สิน</h1>
<table class="meta" width="100%"><tr>
 <td width="50%">ส่วนราชการ <span class="dot">&nbsp;' . e($set['agency'] ?: '') . '&nbsp;</span></td>
 <td width="50%">หน่วยงาน <span class="dot">&nbsp;' . e($school['name']) . '&nbsp;</span></td></tr>
<tr><td>ประเภท <span class="dot">&nbsp;ครุภัณฑ์' . e(str_replace('ครุภัณฑ์', '', (string) $cat['grp'])) . '&nbsp;</span></td>
 <td>รหัส <span class="dot">&nbsp;<b>' . e($it['asset_no']) . '</b>&nbsp;</span></td></tr>
<tr><td>ลักษณะ/คุณสมบัติ <span class="dot">&nbsp;' . e($it['spec'] ?: $it['name']) . '&nbsp;</span></td>
 <td>รุ่น/แบบ <span class="dot">&nbsp;' . e(trim($it['brand'] . ' ' . $it['model'])) . '&nbsp;</span></td></tr>
<tr><td>สถานที่ตั้ง/หน่วยงานที่รับผิดชอบ <span class="dot">&nbsp;' . e(trim($it['location'] . ($it['custodian'] ? ' · ' . $it['custodian'] : ''))) . '&nbsp;</span></td>
 <td>ชื่อผู้ขาย/ผู้รับจ้าง/ผู้บริจาค <span class="dot">&nbsp;' . e($it['vendor']) . '&nbsp;</span></td></tr>
<tr><td colspan="2">ประเภทเงิน&nbsp; ' . $funds . '</td></tr>
<tr><td colspan="2">วิธีการได้มา&nbsp; ' . $methods . '</td></tr></table>
<table class="t" style="margin-top:2mm"><thead><tr>
 <th width="9%">วัน เดือน ปี</th><th width="8%">ที่เอกสาร</th><th>รายการ</th><th width="5%">จำนวน<br>หน่วย</th><th width="8%">ราคาต่อหน่วย<br>/ชุด/กลุ่ม</th><th width="8%">มูลค่ารวม</th>
 <th width="5%">อายุ<br>ใช้งาน</th><th width="5%">อัตรา<br>ค่าเสื่อม</th><th width="8%">ค่าเสื่อมราคา<br>ประจำปี</th><th width="8%">ค่าเสื่อมราคา<br>สะสม</th><th width="8%">มูลค่าสุทธิ</th><th width="9%">หมายเหตุ</th>
</tr></thead><tbody>' . $rows . '</tbody></table>
<p class="small" style="margin-top:2mm">คิดค่าเสื่อมราคาวิธีเส้นตรงตามหลักเกณฑ์กรมบัญชีกลาง (ว 238 ลว. 9 ก.ย. 2557) นับเดือนแรกเมื่อได้มาภายในวันที่ 15 · คงมูลค่าไว้ 1 บาท · พิมพ์จากคลังบริบูรณ์ ' . th_date(date('Y-m-d')) . '</p>';
        pdf_write($m, $html);
        // ด้านหลัง: ประวัติการซ่อมบำรุงรักษาทรัพย์สิน
        $m->AddPage();
        $fix = db_all("SELECT at, detail FROM kl_log WHERE item_id = ? AND kind IN ('status','return') ORDER BY id", [$it['id']]);
        $r = '';
        foreach ($fix as $i => $f) $r .= '<tr><td class="c">' . ($i + 1) . '</td><td class="c">' . th_date($f['at']) . '</td><td>' . e($f['detail']) . '</td><td></td><td></td></tr>';
        for ($i = count($fix); $i < 12; $i++) $r .= '<tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>';
        pdf_write($m, PDF_CSS . '<h1>ประวัติการซ่อมบำรุงรักษาทรัพย์สิน</h1><p class="sub">รหัส ' . e($it['asset_no']) . ' · ' . e($it['name']) . '</p>
<table class="t"><thead><tr><th width="7%">ครั้งที่</th><th width="13%">วัน เดือน ปี</th><th>รายการ</th><th width="13%">จำนวนเงิน</th><th width="20%">หมายเหตุ<br>(ค่าใช้จ่าย/เพิ่มทุน)</th></tr></thead><tbody>' . $r . '</tbody></table>');
    }
    return $m;
}

// ---------------- บัญชีครุภัณฑ์ ----------------
function pdf_list(array $items, array $school, array $set, string $subtitle): Mpdf {
    $m = make_mpdf('A4-L', ['margin_top' => 12, 'margin_bottom' => 14, 'margin_left' => 12, 'margin_right' => 10]);
    $m->SetTitle('บัญชีครุภัณฑ์');
    $m->SetHTMLFooter('<div style="font-family:thsarabun;font-size:12pt;text-align:right">หน้า {PAGENO}/{nbpg}</div>');
    $rows = '';
    $sum = 0.0;
    foreach ($items as $i => $it) {
        $sum += (float) $it['price'];
        $rows .= '<tr><td class="c">' . ($i + 1) . '</td><td style="white-space:nowrap">' . e($it['asset_no']) . '</td><td>' . e($it['name']) . '</td><td>' . e(trim($it['brand'] . ' ' . $it['model'] . ($it['serial_no'] ? ' S/N ' . $it['serial_no'] : ''))) . '</td>'
            . '<td class="c">' . th_date($it['acquired_on']) . '</td><td class="r">' . money($it['price']) . '</td><td>' . e($it['location']) . '</td><td>' . e(status_label($it['status'])) . '</td></tr>';
    }
    $html = PDF_CSS . '<h1>บัญชีครุภัณฑ์</h1><p class="sub">' . e($school['name']) . ($subtitle !== '' ? ' · ' . e($subtitle) : '') . ' · ข้อมูล ณ ' . th_date(date('Y-m-d')) . '</p>
<table class="t" repeat_header="1"><thead><tr><th width="5%">ที่</th><th width="15%">เลขครุภัณฑ์</th><th>รายการ</th><th width="19%">ยี่ห้อ/รุ่น/เลขเครื่อง</th><th width="9%">วันที่ได้มา</th><th width="9%">ราคา (บาท)</th><th width="13%">สถานที่ตั้ง</th><th width="9%">สถานะ</th></tr></thead>
<tbody>' . $rows . '<tr><td colspan="5" class="r"><b>รวม ' . count($items) . ' รายการ</b></td><td class="r"><b>' . money($sum) . '</b></td><td colspan="2"></td></tr></tbody></table>
<table class="sign"><tr>' . sign_cell('', $set['officer'], $set['officer_pos']) . sign_cell('', $set['head'], $set['head_pos']) . '</tr></table>';
    pdf_write($m, $html);
    return $m;
}

// ---------------- ใบยืมพัสดุ ----------------
function pdf_loan(array $loan, array $it, array $school, array $set): Mpdf {
    $m = make_mpdf('A4', ['margin_top' => 18, 'margin_left' => 25, 'margin_right' => 20]);
    $m->SetTitle('ใบยืมพัสดุ ' . $it['asset_no']);
    $issue = $loan['kind'] === 'issue';
    $html = PDF_CSS . '<div style="text-align:right;font-size:13pt">เลขที่ ' . (int) $loan['id'] . '/' . fiscal_year($loan['out_on']) . '</div>
<h1>' . ($issue ? 'ใบเบิกพัสดุ' : 'ใบยืมพัสดุ') . '</h1>
<p class="sub">' . e($school['name']) . '</p>
<p style="text-align:right">วันที่ ' . th_date($loan['out_on']) . '</p>
<p style="text-indent:15mm;text-align:justify">ข้าพเจ้า ' . e($loan['borrower']) . ' ขอ' . ($issue ? 'เบิก' : 'ยืม') . 'พัสดุตามรายการข้างล่างนี้ เพื่อใช้ในงาน ' . e($loan['purpose'] ?: '..................................................') . ' ณ ' . e($loan['place'] ?: '..................................................')
        . ($loan['due_on'] ? ' และจะส่งคืนภายในวันที่ ' . th_date($loan['due_on']) : '') . '</p>
<table class="t" style="margin:3mm 0"><thead><tr><th width="8%">ที่</th><th width="28%">เลขครุภัณฑ์</th><th>รายการ</th><th width="12%">จำนวน</th></tr></thead>
<tbody><tr><td class="c">1</td><td>' . e($it['asset_no']) . '</td><td>' . e($it['name'] . (trim($it['brand'] . ' ' . $it['model']) ? ' ' . trim($it['brand'] . ' ' . $it['model']) : '') . ($it['serial_no'] ? ' S/N ' . $it['serial_no'] : '')) . '</td><td class="c">1 ' . e($it['unit'] ?: 'หน่วย') . '</td></tr></tbody></table>
<p style="text-indent:15mm;text-align:justify">หากพัสดุที่' . ($issue ? 'เบิก' : 'ยืม') . 'ไปเกิดชำรุดเสียหาย หรือใช้การไม่ได้ หรือสูญหายไป ข้าพเจ้าจะจัดการแก้ไขซ่อมแซมให้คงสภาพเดิม หรือชดใช้เป็นพัสดุประเภท ชนิด ขนาด ลักษณะ และคุณภาพอย่างเดียวกัน หรือชดใช้เป็นเงินตามราคาที่เป็นอยู่ในขณะยืม ตามระเบียบกระทรวงการคลังว่าด้วยการจัดซื้อจัดจ้างและการบริหารพัสดุภาครัฐ พ.ศ. 2560 ข้อ 209</p>
<table class="sign"><tr>' . sign_cell('ผู้' . ($issue ? 'เบิก' : 'ยืม'), $loan['borrower'], '') . sign_cell('ผู้อนุมัติ', $loan['approver'] ?: $set['boss'], $loan['approver'] && $loan['approver'] !== $set['boss'] ? '' : $set['boss_pos']) . '</tr>
<tr>' . sign_cell('ผู้จ่ายพัสดุ', $set['officer'], $set['officer_pos']) . '<td></td></tr></table>
<div style="margin-top:8mm;border-top:0.2mm solid #000;padding-top:3mm"><b>การส่งคืน</b><br>
ได้รับพัสดุคืนแล้ว เมื่อวันที่ ' . ($loan['returned_on'] ? th_date($loan['returned_on']) : '..........................................') . ' สภาพ ' . e($loan['return_note'] ?: '..........................................................') . '</div>
<table class="sign" style="margin-top:2mm"><tr>' . sign_cell('ผู้ส่งคืน', $loan['borrower'], '') . sign_cell('ผู้รับคืน', $set['officer'], $set['officer_pos']) . '</tr></table>';
    pdf_write($m, $html);
    return $m;
}

// ---------------- รายงานผลการตรวจสอบพัสดุประจำปี ----------------
function pdf_check(array $items, array $school, array $set, int $fy): Mpdf {
    $m = make_mpdf('A4', ['margin_top' => 15, 'margin_left' => 20, 'margin_right' => 15]);
    $m->SetTitle('รายงานผลการตรวจสอบพัสดุประจำปี ' . $fy);
    $m->SetHTMLFooter('<div style="font-family:thsarabun;font-size:12pt;text-align:right">หน้า {PAGENO}/{nbpg}</div>');
    $found = array_filter($items, fn($i) => (int) $i['checked_fy'] === $fy);
    $missing = array_filter($items, fn($i) => (int) $i['checked_fy'] !== $fy);
    $byStatus = [];
    foreach ($found as $i) $byStatus[$i['status']] = ($byStatus[$i['status']] ?? 0) + 1;
    $sumRows = '';
    foreach (KL_STATUS as $k => [$label]) if (!empty($byStatus[$k])) $sumRows .= '<tr><td>' . e($label) . '</td><td class="r">' . $byStatus[$k] . '</td></tr>';
    $list = function (array $rows) {
        $o = ''; $n = 0;
        foreach ($rows as $it) $o .= '<tr><td class="c">' . (++$n) . '</td><td>' . e($it['asset_no']) . '</td><td>' . e($it['name']) . '</td><td>' . e($it['location']) . '</td><td>' . e(status_label($it['status'])) . '</td></tr>';
        return $o ?: '<tr><td colspan="5" class="c">– ไม่มี –</td></tr>';
    };
    $attention = array_filter($found, fn($i) => $i['status'] !== 'normal');
    $signs = '';
    $people = parse_people($set['checkers']);
    $roles = ['ประธานกรรมการ', 'กรรมการ', 'กรรมการ'];
    for ($i = 0; $i < max(3, count($people)); $i++) {
        $signs .= ($i % 2 === 0 ? '<tr>' : '') . sign_cell($roles[$i] ?? 'กรรมการ', $people[$i][0] ?? '', $people[$i][1] ?? '') . ($i % 2 === 1 ? '</tr>' : '');
    }
    if (!str_ends_with($signs, '</tr>')) $signs .= '<td></td></tr>';
    $html = PDF_CSS . '<h1>รายงานผลการตรวจสอบพัสดุประจำปีงบประมาณ ' . $fy . '</h1><p class="sub">' . e($school['name']) . ' · ข้อมูล ณ ' . th_date(date('Y-m-d')) . '</p>
<p style="text-indent:15mm;text-align:justify">คณะกรรมการตรวจสอบพัสดุประจำปีได้ตรวจสอบการรับจ่ายพัสดุและตรวจนับพัสดุคงเหลือตามระเบียบกระทรวงการคลังว่าด้วยการจัดซื้อจัดจ้างและการบริหารพัสดุภาครัฐ พ.ศ. 2560 ข้อ 213 ผลปรากฏดังนี้</p>
<table class="t" style="width:60%;margin:2mm auto"><tbody>
<tr><td>ครุภัณฑ์ในทะเบียน (ไม่รวมที่จำหน่ายแล้ว)</td><td class="r" width="20%">' . count($items) . '</td></tr>
<tr><td>ตรวจพบตัวครุภัณฑ์</td><td class="r">' . count($found) . '</td></tr>' . $sumRows . '
<tr><td><b>ยังไม่พบ/ยังไม่ได้ตรวจ</b></td><td class="r"><b>' . count($missing) . '</b></td></tr></tbody></table>
<p><b>รายการที่ชำรุด เสื่อมสภาพ หรือไม่จำเป็นต้องใช้</b> (เสนอพิจารณาดำเนินการตามข้อ 214)</p>
<table class="t" repeat_header="1"><thead><tr><th width="7%">ที่</th><th width="24%">เลขครุภัณฑ์</th><th>รายการ</th><th width="20%">สถานที่</th><th width="15%">สภาพ</th></tr></thead><tbody>' . $list($attention) . '</tbody></table>
<p style="margin-top:4mm"><b>รายการที่ยังไม่พบตัว</b></p>
<table class="t" repeat_header="1"><thead><tr><th width="7%">ที่</th><th width="24%">เลขครุภัณฑ์</th><th>รายการ</th><th width="20%">สถานที่</th><th width="15%">สถานะในทะเบียน</th></tr></thead><tbody>' . $list($missing) . '</tbody></table>
<table class="sign" style="page-break-inside:avoid">' . $signs . '</table>';
    pdf_write($m, $html);
    return $m;
}
