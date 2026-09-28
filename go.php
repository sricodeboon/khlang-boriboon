<?php
// ทางเข้าสู่ระบบผ่านตารางบริบูรณ์ แล้วให้กลับมาที่คลังบริบูรณ์ (ตารางบริบูรณ์อ่าน tt_next หลังเข้าสู่ระบบ/ลงทะเบียน)
require __DIR__ . '/lib/bootstrap.php';
$to = (string) ($_GET['to'] ?? $_POST['to'] ?? '');
$_SESSION['tt_next'] = 'khlang';
if ($to === 'guest' && is_post()) {
    csrf_check();
    // 307 = เบราว์เซอร์ส่ง POST เดิม (มี csrf) ต่อไปยังโหมดทดลองของตารางบริบูรณ์
    header('Location: ' . tt_url('auth/guest.php'), true, 307);
    exit;
}
if (in_array($to, ['google', 'line'], true)) redirect(tt_url('auth/login.php?p=' . $to));
redirect(current_user() ? tt_url('signup.php') : 'index.php');
