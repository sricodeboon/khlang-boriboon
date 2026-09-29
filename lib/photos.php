<?php
// รูปถ่ายครุภัณฑ์: เบราว์เซอร์ย่อเป็น JPEG ด้านยาว ≤1280px (~100–250KB) ก่อนส่ง · เซิร์ฟเวอร์ตรวจชนิด/ขนาด และย่อซ้ำด้วย GD ถ้ายังใหญ่
// เก็บเป็นไฟล์ใน storage/photos/<โรงเรียน>/ (ไม่ยัดลงฐานข้อมูล) + รูปย่อ 160px เป็น data URL ในคอลัมน์ thumb สำหรับหน้ารายการ
declare(strict_types=1);

const KL_PHOTO_MAX = 3;          // รูปต่อชิ้น
const KL_PHOTO_BYTES = 450_000;  // ขนาดสูงสุดที่เก็บ
const KL_PHOTO_EDGE = 1280;      // ด้านยาวสูงสุด

/** แปลง $_FILES['photos'] (หลายไฟล์) เป็นรายการ path ชั่วคราว */
function uploaded_photos(string $field = 'photos'): array {
    $f = $_FILES[$field] ?? null;
    if (!$f || !is_array($f['tmp_name'])) return [];
    $out = [];
    foreach ($f['tmp_name'] as $i => $tmp) {
        if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        if ($f['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) throw new InvalidArgumentException('อัปโหลดรูปไม่สำเร็จ (ไฟล์อาจใหญ่เกิน) กรุณาลองใหม่');
        $out[] = $tmp;
    }
    return $out;
}

/** ตรวจ/ย่อรูปหนึ่งไฟล์ → คืน [bytes JPEG/WebP, นามสกุล] */
function normalize_photo(string $path): array {
    $bin = (string) file_get_contents($path);
    $info = @getimagesizefromstring($bin);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        throw new InvalidArgumentException('รับเฉพาะรูป JPG PNG WebP หรือ HEIC จาก iPhone (ระบบแปลงให้ก่อนส่ง) กรุณาเปิดหน้านี้ใหม่แล้วเลือกรูปอีกครั้ง');
    }
    [$w, $h] = $info;
    $ok = strlen($bin) <= KL_PHOTO_BYTES && max($w, $h) <= KL_PHOTO_EDGE && $info[2] !== IMAGETYPE_PNG;
    if ($ok) return [$bin, $info[2] === IMAGETYPE_WEBP ? 'webp' : 'jpg'];
    if (!function_exists('imagecreatefromstring')) throw new InvalidArgumentException('รูปใหญ่เกินไป กรุณาเปิดหน้านี้ใหม่แล้วถ่ายรูปผ่านระบบ (ระบบย่อรูปให้)');
    $src = @imagecreatefromstring($bin);
    if (!$src) throw new InvalidArgumentException('อ่านรูปไม่ได้');
    $scale = min(1, KL_PHOTO_EDGE / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    foreach ([78, 68, 58, 48] as $q) {
        ob_start();
        imagejpeg($dst, null, $q);
        $out = (string) ob_get_clean();
        if (strlen($out) <= KL_PHOTO_BYTES) break;
    }
    return [$out, 'jpg'];
}

/** รูปย่อ 160px (data URL) ถ้ามี GD · ไม่มี GD ใช้รูปย่อที่เบราว์เซอร์ส่งมา */
function make_thumb(string $bin, string $clientThumb = ''): ?string {
    if (function_exists('imagecreatefromstring') && ($src = @imagecreatefromstring($bin))) {
        $w = imagesx($src); $h = imagesy($src);
        $side = min($w, $h);
        $dst = imagecreatetruecolor(160, 160);
        imagecopyresampled($dst, $src, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), 160, 160, $side, $side);
        ob_start();
        imagejpeg($dst, null, 70);
        return 'data:image/jpeg;base64,' . base64_encode((string) ob_get_clean());
    }
    if (preg_match('#^data:image/(jpeg|webp);base64,[A-Za-z0-9+/=]+$#', $clientThumb) && strlen($clientThumb) <= 30000) return $clientThumb;
    return null;
}

/** เพิ่มรูปให้ครุภัณฑ์หลายชิ้น (ซื้อพร้อมกันใช้รูปเดียวกัน เก็บไฟล์ชุดเดียว) */
function add_photos(int $schoolId, array $itemIds, array $tmpPaths, int $userId, string $clientThumb = ''): int {
    if (!$tmpPaths || !$itemIds) return 0;
    $dir = photo_dir($schoolId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new RuntimeException('สร้างโฟลเดอร์รูปไม่ได้');
    $first = item_get($schoolId, $itemIds[0]);
    $room = KL_PHOTO_MAX - count(photos($first));
    if ($room <= 0) throw new InvalidArgumentException('มีรูปครบ ' . KL_PHOTO_MAX . ' รูปแล้ว ลบรูปเดิมก่อน');
    $saved = [];
    $thumb = null;
    foreach (array_slice($tmpPaths, 0, $room) as $tmp) {
        [$bin, $ext] = normalize_photo($tmp);
        $name = bin2hex(random_bytes(8)) . '.' . $ext;
        if (file_put_contents($dir . '/' . $name, $bin, LOCK_EX) === false) throw new RuntimeException('บันทึกรูปไม่ได้');
        $saved[] = $name;
        $thumb ??= make_thumb($bin, $clientThumb);
    }
    foreach ($itemIds as $id) {
        $it = item_get($schoolId, (int) $id);
        if (!$it) continue;
        $list = array_slice(array_merge(photos($it), $saved), 0, KL_PHOTO_MAX);
        db_exec('UPDATE kl_items SET photos_json = ?, thumb = COALESCE(thumb, ?), updated_at = ? WHERE id = ?',
            [json_encode($list), $thumb, now(), $it['id']]);
        log_event($schoolId, (int) $it['id'], 'photo', 'เพิ่มรูป ' . count($saved) . ' รูป', $userId);
    }
    return count($saved);
}

function delete_photo(array $item, string $file, int $userId): void {
    $list = photos($item);
    if (!in_array($file, $list, true)) throw new InvalidArgumentException('ไม่พบรูปนี้');
    $list = array_values(array_diff($list, [$file]));
    $sid = (int) $item['school_id'];
    $thumb = $item['thumb'];
    if (!$list) $thumb = null;
    elseif ($list[0] !== (photos($item)[0] ?? '')) {
        $bin = @file_get_contents(photo_dir($sid) . '/' . $list[0]);
        $thumb = $bin ? make_thumb($bin) ?? $thumb : $thumb;
    }
    db_exec('UPDATE kl_items SET photos_json = ?, thumb = ?, updated_at = ? WHERE id = ?', [json_encode($list), $thumb, now(), $item['id']]);
    // ไฟล์เดียวกันอาจใช้ร่วมกับชิ้นอื่นที่ลงทะเบียนพร้อมกัน → ลบไฟล์เมื่อไม่มีใครใช้แล้ว
    $still = (int) db_val('SELECT COUNT(*) FROM kl_items WHERE school_id = ? AND photos_json LIKE ?', [$sid, '%"' . $file . '"%']);
    if ($still === 0) @unlink(photo_dir($sid) . '/' . basename($file));
    log_event($sid, (int) $item['id'], 'photo', 'ลบรูป 1 รูป', $userId);
}
