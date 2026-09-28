<?php
// ผู้ใช้และโรงเรียน: ใช้บัญชีของตารางบริบูรณ์ (ตาราง users/schools) — ระบบนี้ไม่มีการสมัครแยก
declare(strict_types=1);

function current_user(): ?array {
    static $cache = false;
    if ($cache !== false) return $cache;
    $id = $_SESSION['uid'] ?? null;
    $cache = $id ? db_one('SELECT id, school_id, provider, name, email, avatar, role FROM users WHERE id = ?', [$id]) : null;
    return $cache;
}

function current_school(): ?array {
    $u = current_user();
    if (!$u || !$u['school_id']) return null;
    return db_one('SELECT id, name, school_code, tambon, amphoe, province,
                          CASE WHEN logo IS NULL THEN 0 ELSE logo_rev END AS logo_rev FROM schools WHERE id = ?', [$u['school_id']]);
}

function is_guest(?array $u): bool { return ($u['provider'] ?? '') === 'guest'; }

/** หน้าที่ต้องล็อกอินและมีโรงเรียน · ยังไม่ลงทะเบียนโรงเรียน → ไปหน้าลงทะเบียนของตารางบริบูรณ์ แล้วกลับมาที่นี่ */
function require_school(bool $json = false): array {
    $u = current_user();
    if (!$u) {
        if ($json) json_out(['error' => 'กรุณาเข้าสู่ระบบ'], 401);
        redirect('index.php');
    }
    $s = current_school();
    if (!$s) {
        if ($json) json_out(['error' => 'กรุณาลงทะเบียนโรงเรียนก่อน'], 403);
        $_SESSION['tt_next'] = 'khlang';
        redirect(tt_url('signup.php'));
    }
    return [$u, $s];
}

function providers_ready(): array {
    return [
        'google' => (string) tt_cfg('google.client_id', '') !== '' && (string) tt_cfg('google.client_secret', '') !== '',
        'line' => (string) tt_cfg('line.channel_id', '') !== '' && (string) tt_cfg('line.channel_secret', '') !== '',
    ];
}
