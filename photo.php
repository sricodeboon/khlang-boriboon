<?php
// ส่งรูปครุภัณฑ์ให้เฉพาะผู้ใช้ของโรงเรียนนั้น (ไฟล์จริงอยู่ใน storage/ ที่เปิดตรงไม่ได้)
define('KL_RAW_OUTPUT', true);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/assets.php';
[$u, $s] = require_school();
$item = item_get((int) $s['id'], (int) ($_GET['i'] ?? 0));
$f = (string) ($_GET['f'] ?? '');
if (!$item || !in_array($f, photos($item), true) || !preg_match('/^[0-9a-f]{16}\.(jpg|webp)$/', $f)) { http_response_code(404); exit; }
$path = photo_dir((int) $s['id']) . '/' . $f;
if (!is_file($path)) { http_response_code(404); exit; }
header('Content-Type: ' . (str_ends_with($f, '.webp') ? 'image/webp' : 'image/jpeg'));
header('Content-Length: ' . filesize($path));
// ชื่อไฟล์สุ่มไม่ซ้ำ เนื้อหาไม่เปลี่ยน → แคชในเครื่องได้นาน (private: ไม่ให้พร็อกซีกลางเก็บ)
header('Cache-Control: private, max-age=2592000, immutable');
readfile($path);
