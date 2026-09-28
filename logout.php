<?php
// ออกจากระบบ (session เดียวกับตารางบริบูรณ์ → ออกทั้งสองระบบ)
require __DIR__ . '/lib/bootstrap.php';
if (is_post() && current_user()) {
    csrf_check();
    $_SESSION = [];
    session_regenerate_id(true);
    flash('ออกจากระบบแล้ว', 'ok');
}
redirect('index.php');
