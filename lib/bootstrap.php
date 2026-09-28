<?php
// แกนกลางของคลังบริบูรณ์: ค่าตั้ง, session ร่วมกับตารางบริบูรณ์, header ความปลอดภัย, ตัวช่วยทั่วไป
declare(strict_types=1);

date_default_timezone_set('Asia/Bangkok');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_exception_handler(function (Throwable $e): void {
    error_log('khlang ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'เกิดข้อผิดพลาดในระบบ กรุณาลองใหม่อีกครั้ง';
});
if (PHP_SAPI !== 'cli' && extension_loaded('zlib') && !ini_get('zlib.output_compression') && !defined('KL_RAW_OUTPUT')) ob_start('ob_gzhandler');
define('APP_ROOT', dirname(__DIR__));

$cfgFile = APP_ROOT . '/config.php';
$GLOBALS['CFG'] = is_file($cfgFile) ? require $cfgFile : require APP_ROOT . '/config.sample.php';

function cfg(string $path, $default = null) {
    $v = $GLOBALS['CFG'];
    foreach (explode('.', $path) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) return $default;
        $v = $v[$k];
    }
    return $v;
}

/** โฟลเดอร์ของตารางบริบูรณ์ (ใช้ฐานข้อมูลและบัญชีผู้ใช้ร่วมกัน) */
function tt_dir(): string {
    return rtrim((string) cfg('timetable_dir', dirname(APP_ROOT) . '/timetable'), '/');
}

/** ค่าตั้งของตารางบริบูรณ์ (อ่านเฉพาะส่วน db — ไม่แตะ secret ของ Google/LINE) */
function tt_cfg(string $key, $default = null) {
    static $c = null;
    if ($c === null) {
        $f = tt_dir() . '/config.php';
        $c = is_file($f) ? require $f : (is_file(tt_dir() . '/config.sample.php') ? require tt_dir() . '/config.sample.php' : []);
        if (!is_array($c)) $c = [];
    }
    $v = $c;
    foreach (explode('.', $key) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) return $default;
        $v = $v[$k];
    }
    return $v;
}

function base_url(string $path = ''): string {
    $base = PHP_SAPI === 'cli-server' ? '' : rtrim((string) cfg('base_url', ''), '/');
    if ($base === '') {
        $https = !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/' . basename(APP_ROOT);
    }
    return $base . ($path === '' ? '' : '/' . ltrim($path, '/'));
}

/** URL ของหน้าในตารางบริบูรณ์ (โฟลเดอร์ข้างเคียงในโดเมนเดียวกัน) */
function tt_url(string $path = ''): string {
    return '/' . basename(tt_dir()) . '/' . ltrim($path, '/');
}

function csp_nonce(): string {
    static $n = null;
    return $n ??= base64_encode(random_bytes(16));
}

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    $isHttps = !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    // สคริปต์: ไฟล์ในระบบ + cdnjs (SweetAlert2 มี SRI) + inline ที่มี nonce · กล้อง: อนุญาตเฉพาะหน้าในเว็บนี้ (สแกน QR/บาร์โค้ด)
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-" . csp_nonce() . "' https://cdnjs.cloudflare.com; "
        . "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; img-src 'self' data: blob: https:; font-src 'self' data:; "
        . "connect-src 'self'; media-src 'self' blob:; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
    if ($isHttps) header('Strict-Transport-Security: max-age=15552000');
}

// ---------- session: ชื่อและ path เดียวกับตารางบริบูรณ์ → สมัคร/เข้าสู่ระบบที่ไหนก็ใช้ได้ทั้งสองระบบ ----------
if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    $secure = !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('ttsid');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---------- helpers ----------
function h(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

function redirect(string $to): never {
    header('Location: ' . (preg_match('#^(https?://|/)#', $to) ? $to : base_url($to)));
    exit;
}

function json_out($data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** token เดียวกับตารางบริบูรณ์ (session ร่วมกัน) */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_valid(): bool {
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    return is_string($sent) && $sent !== '' && hash_equals(csrf_token(), $sent);
}

function csrf_check(): void {
    if (csrf_valid()) return;
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'json') || isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        json_out(['error' => 'หมดเวลาการใช้งาน กรุณารีเฟรชหน้าแล้วลองอีกครั้ง', 'csrf' => true], 419);
    }
    flash('หน้านี้เปิดค้างไว้นานจนหมดเวลา กรุณากดอีกครั้ง');
    redirect(back_path());
}

function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'; }

/** หน้าก่อนหน้าในระบบนี้เท่านั้น (กัน open redirect) */
function back_path(string $fallback = 'index.php'): string {
    $u = parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''));
    $host = ($u['host'] ?? '') . (isset($u['port']) ? ':' . $u['port'] : '');
    $base = '/' . basename(APP_ROOT) . '/';
    if (!$u || $host !== ($_SERVER['HTTP_HOST'] ?? '') || !str_starts_with($u['path'] ?? '', $base)) return $fallback;
    $rel = substr($u['path'], strlen($base));
    if (!preg_match('#^[a-z]*(\.php)?$#', $rel)) return $fallback;
    return ($rel === '' ? 'index.php' : $rel) . (isset($u['query']) ? '?' . $u['query'] : '');
}

/** ข้อความแจ้งครั้งเดียวข้ามหน้า (แยกกุญแจจากตารางบริบูรณ์ ไม่ให้ข้อความไปโผล่อีกระบบ) · kind: ok|info|warn|error */
function flash(?string $msg = null, string $kind = 'warn'): ?array {
    if ($msg !== null) { $_SESSION['kl_flash'] = [$msg, $kind]; return null; }
    $m = $_SESSION['kl_flash'] ?? null;
    unset($_SESSION['kl_flash']);
    return is_array($m) ? $m : null;
}

function post(string $k, int $max = 500): string {
    return mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
}

function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; }

require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
