<?php
// JSON API ของหน้าเว็บ: ตัวอย่างเลขครุภัณฑ์ · ค้นจากรหัสที่สแกน · ตรวจนับ · เพิ่ม/ลบรูป
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/assets.php';
require __DIR__ . '/lib/photos.php';

[$u, $s] = require_school(true);
$sid = (int) $s['id'];
$r = (string) ($_GET['r'] ?? '');
$q = $_GET;
if (is_post()) csrf_check();

function item_brief(array $it): array {
    $loan = $it['on_loan'] ? open_loan((int) $it['id']) : null;
    return [
        'id' => (int) $it['id'], 'no' => $it['asset_no'], 'name' => $it['name'], 'brand' => $it['brand'], 'model' => $it['model'],
        'serial' => $it['serial_no'], 'location' => $it['location'], 'custodian' => $it['custodian'],
        'status' => $it['status'], 'statusLabel' => status_label($it['status']), 'thumb' => $it['thumb'],
        'checked' => (int) $it['checked_fy'] === fiscal_year(),
        'loan' => $loan ? ['borrower' => $loan['borrower'], 'due' => th_date($loan['due_on']), 'late' => $loan['due_on'] && $loan['due_on'] < date('Y-m-d')] : null,
    ];
}

try {
    switch ($r) {
        case 'next.no':
            $cat = (string) ($q['cat'] ?? '');
            if (!isset(cats($sid)[$cat])) json_out(['error' => 'ไม่พบประเภทนี้'], 422);
            $date = (string) ($q['date'] ?? '');
            $fy = fiscal_year(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null);
            json_out(['numbers' => preview_numbers($sid, $cat, $fy, max(1, min(100, (int) ($q['qty'] ?? 1)))), 'fy' => $fy]);

        case 'lookup':
            $it = item_by_code($sid, (string) ($q['code'] ?? ''));
            if (!$it) json_out(['error' => 'ไม่พบครุภัณฑ์รหัสนี้ในทะเบียนของโรงเรียน'], 404);
            json_out(['item' => item_brief($it)]);

        case 'check':
            // ตรวจนับประจำปี: สแกนเจอ = ยืนยันว่ามีตัวตนอยู่จริงในปีงบประมาณนี้
            $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
            $it = item_get($sid, (int) ($in['id'] ?? 0));
            if (!$it) json_out(['error' => 'ไม่พบครุภัณฑ์'], 404);
            $fy = fiscal_year();
            $status = (string) ($in['status'] ?? $it['status']);
            if (!isset(KL_STATUS[$status])) $status = $it['status'];
            if ((int) $it['checked_fy'] !== $fy || $status !== $it['status']) {
                db_exec('UPDATE kl_items SET checked_fy = ?, status = ?, updated_at = ? WHERE id = ?', [$fy, $status, now(), $it['id']]);
                log_event($sid, (int) $it['id'], 'check', 'ตรวจนับประจำปี ' . $fy . ' พบตัวครุภัณฑ์ · ' . status_label($status), (int) $u['id']);
            }
            $done = (int) db_val("SELECT COUNT(*) FROM kl_items WHERE school_id = ? AND checked_fy = ? AND status <> 'disposed'", [$sid, $fy]);
            $all = (int) db_val("SELECT COUNT(*) FROM kl_items WHERE school_id = ? AND status <> 'disposed'", [$sid]);
            json_out(['item' => item_brief(item_get($sid, (int) $it['id'])), 'done' => $done, 'all' => $all]);

        case 'photo.add':
            $it = item_get($sid, (int) ($_POST['id'] ?? 0));
            if (!$it) json_out(['error' => 'ไม่พบครุภัณฑ์'], 404);
            $n = add_photos($sid, [(int) $it['id']], uploaded_photos(), (int) $u['id'], (string) ($_POST['thumb'] ?? ''));
            json_out(['ok' => true, 'added' => $n]);

        case 'photo.del':
            $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
            $it = item_get($sid, (int) ($in['id'] ?? 0));
            if (!$it) json_out(['error' => 'ไม่พบครุภัณฑ์'], 404);
            delete_photo($it, (string) ($in['file'] ?? ''), (int) $u['id']);
            json_out(['ok' => true]);
    }
    json_out(['error' => 'ไม่รู้จักคำสั่ง'], 404);
} catch (InvalidArgumentException $e) {
    json_out(['error' => $e->getMessage()], 422);
}
