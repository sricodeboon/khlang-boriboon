<?php
// หัว/ท้ายหน้าร่วม · แถบหัวสีฟ้าค่ำ + เมนูหลัก (จอเล็กย้ายเมนูไปแถบล่าง ใช้นิ้วโป้งกดได้)
declare(strict_types=1);

const KL_NAME = 'คลังบริบูรณ์';
const KL_DESC = 'คลังบริบูรณ์ ระบบทะเบียนครุภัณฑ์โรงเรียนออนไลน์ฟรี ออกเลขครุภัณฑ์อัตโนมัติ สติกเกอร์ QR สแกนตรวจสอบ ทะเบียนคุม ยืม–คืน โดยศรีโค้ดบูรณ์';

const KL_NAV = [
    'home'  => ['index.php', 'ภาพรวม', '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>'],
    'items' => ['items.php', 'ทะเบียน', '<rect x="4" y="3" width="16" height="18" rx="1"/><path d="M8 8h8M8 12h8M8 16h5"/>'],
    'scan'  => ['scan.php', 'สแกน', '<path d="M4 8V4h4M16 4h4v4M20 16v4h-4M8 20H4v-4"/><path d="M7 12h10"/>'],
    'loans' => ['loans.php', 'ยืม–คืน', '<path d="M4 8h13l-3-3M20 16H7l3 3"/>'],
    'print' => ['print.php', 'พิมพ์', '<path d="M7 9V3h10v6"/><rect x="3" y="9" width="18" height="8" rx="1"/><path d="M7 14h10v7H7z"/>'],
    'settings' => ['settings.php', 'ตั้งค่า', '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>'],
];

function icon(string $paths, int $size = 20): string {
    return '<svg class="ic" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="square" stroke-linejoin="miter" aria-hidden="true">' . $paths . '</svg>';
}

function asset_v(string $rel): string { return $rel . '?v=' . (int) @filemtime(APP_ROOT . '/' . $rel); }

function page_head(string $title, string $active = '', string $extraHead = '', string $desc = KL_DESC): void {
    $u = current_user();
    $s = $u ? current_school() : null; ?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($title) ?></title>
<meta name="description" content="<?= h($desc) ?>">
<meta name="theme-color" content="#0F2438">
<meta property="og:type" content="website">
<meta property="og:site_name" content="ศรีโค้ดบูรณ์">
<meta property="og:title" content="<?= h($title) ?>">
<meta property="og:description" content="<?= h($desc) ?>">
<meta property="og:image" content="<?= h(base_url('og.png')) ?>">
<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">
<meta property="og:url" content="<?= h(base_url(basename($_SERVER['SCRIPT_NAME'] ?? '') === 'index.php' ? '' : basename($_SERVER['SCRIPT_NAME'] ?? ''))) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="csrf" content="<?= h(csrf_token()) ?>">
<link rel="icon" href="assets/brand/favicon.svg" type="image/svg+xml">
<link rel="icon" href="assets/brand/favicon-32.png" sizes="32x32" type="image/png">
<link rel="apple-touch-icon" href="assets/brand/app-icon-180.png">
<link rel="preload" href="/assets/fonts/IBMPlexSansThai-400-thai.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/ChakraPetch-700-thai.woff2" as="font" type="font/woff2" crossorigin>
<style><?php @readfile(dirname(APP_ROOT) . '/assets/fonts/fonts.css'); ?></style>
<style><?php readfile(APP_ROOT . '/assets/app.css'); ?></style>
<?= $extraHead ?>
</head>
<body class="<?= $s ? 'in' : 'out' ?>">
<?php if ($s): ?>
<header class="top">
  <div class="top-in">
    <a class="brand" href="index.php"><img src="assets/brand/mark-on-dark.svg" alt="" width="30" height="30"><span>คลัง<span class="b">{</span>บริบูรณ์<span class="b">}</span></span></a>
    <nav class="nav" aria-label="เมนูหลัก">
      <?php foreach (KL_NAV as $k => [$href, $label, $ic]): ?>
        <a href="<?= $href ?>"<?= $k === $active ? ' aria-current="page"' : '' ?>><?= icon($ic, 18) ?><span><?= $label ?></span></a>
      <?php endforeach; ?>
    </nav>
    <div class="who">
      <a class="linkish" href="guide.php" style="color:#BFCAD3">คู่มือ</a>
      <span class="school" title="<?= h($s['name']) ?>"><?= h($s['name']) ?></span>
      <form method="post" action="logout.php"><?= csrf_field() ?><button class="linkish" type="submit">ออก</button></form>
    </div>
  </div>
</header>
<?php if (is_guest($u)): ?><div class="guestbar">โหมดทดลอง · ข้อมูลและรูปถ่ายจะถูกลบเองภายใน 2 วัน · <a href="<?= h(tt_url('index.php')) ?>">เข้าสู่ระบบด้วย Google/LINE</a> เพื่อใช้งานจริง</div><?php endif; ?>
<?php endif; ?>
<?php
}

function flash_script(): string {
    $f = flash();
    if (!$f) return '';
    [$msg, $kind] = $f;
    $j = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return '<div class="flash flash-' . h($kind) . '" data-flash role="status">' . h($msg) . '</div>'
        . '<script type="module" nonce="' . csp_nonce() . '">import * as n from "./' . asset_v('assets/notify.js') . '";'
        . 'const m=' . $j($msg) . ',k=' . $j($kind) . ';'
        . 'n.load().then((S)=>{if(!S)return;document.querySelectorAll("[data-flash]").forEach((e)=>{e.hidden=true;});'
        . 'if(k==="ok"||k==="info")n.toast(m,k,5000);else n.alertBox(k==="error"?"เกิดข้อผิดพลาด":"แจ้งเตือน",m,k==="error"?"error":"warning");});</script>';
}

function page_foot(string $scripts = ''): void { ?>
<footer class="foot"><div class="wrap">
  <span><?= KL_NAME ?> · ระบบทะเบียนครุภัณฑ์โรงเรียน · <a href="guide.php">คู่มือการใช้งาน</a></span>
  <span>ใช้บัญชีเดียวกับ <a href="<?= h(tt_url('')) ?>">ตารางบริบูรณ์</a> · โดย <a href="/">ศรีโค้ดบูรณ์</a></span>
</div></footer>
<script type="module" nonce="<?= csp_nonce() ?>" src="<?= asset_v('assets/app.js') ?>"></script>
<?= $scripts ?>
</body>
</html>
<?php }

/** ตัวเลือก <option> */
function options(array $list, ?string $sel, bool $assoc = false): string {
    $o = '';
    foreach ($list as $k => $v) {
        $val = $assoc ? (string) $k : (string) $v;
        $o .= '<option value="' . h($val) . '"' . ($val === (string) $sel ? ' selected' : '') . '>' . h((string) $v) . '</option>';
    }
    return $o;
}
