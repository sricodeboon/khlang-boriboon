#!/bin/sh
# สร้าง khlang.zip สำหรับอัปโหลดขึ้นโฮสต์ (File Manager → Upload & Extract ใน /htdocs)
#   ใช้: sh tools/build-zip.sh /path/to/composer.phar
# ไม่รวม storage/ (รูปถ่ายบนโฮสต์) config.php README LICENSE docs .git และฟอนต์ mPDF ที่ไม่ได้ใช้
# ต้องอัปโหลดคู่กับตารางบริบูรณ์เวอร์ชันที่ใช้คุกกี้ path=/ (lib/bootstrap.php) ไม่งั้นล็อกอินไม่ถึงกัน
set -e
cd "$(dirname "$0")/.."
COMPOSER=${1:-composer}
if [ -f "$COMPOSER" ]; then php "$COMPOSER" install --no-dev --no-interaction --no-progress --optimize-autoloader; else $COMPOSER install --no-dev --no-interaction --no-progress --optimize-autoloader; fi
find vendor/mpdf/mpdf/ttfonts -type f ! -name 'DejaVuSansCondensed*' -delete
printf 'Require all denied\n' > vendor/.htaccess
cd ..
rm -f ../khlang.zip
zip -qr -X ../khlang.zip khlang -x "*.DS_Store" "khlang/.git/*" "khlang/.gitignore" "khlang/storage/photos/*" "khlang/storage/tmp/*" \
  "khlang/config.php" "khlang/README.md" "khlang/LICENSE" "khlang/docs/*" "khlang/tools/*" "khlang/composer.json" "khlang/composer.lock" "khlang/*.pdf"
ls -la ../khlang.zip
