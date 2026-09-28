<?php
// ฐานข้อมูลเดียวกับตารางบริบูรณ์ (SQLite ค่าเริ่มต้น หรือ MySQL ตาม config ของตารางบริบูรณ์)
// ตารางของระบบนี้ขึ้นต้นด้วย kl_ ทั้งหมด · ห้ามแตะ PRAGMA user_version (ตารางบริบูรณ์ใช้เป็นตัวบอกเวอร์ชัน migrate ของตัวเอง)
declare(strict_types=1);

function db_driver(): string { return tt_cfg('db.driver', 'sqlite') === 'mysql' ? 'mysql' : 'sqlite'; }

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    if (db_driver() === 'mysql') {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', tt_cfg('db.host'), tt_cfg('db.name'));
        $pdo = new PDO($dsn, (string) tt_cfg('db.user'), (string) tt_cfg('db.pass'), $opts);
    } else {
        $path = (string) tt_cfg('db.sqlite_path', tt_dir() . '/storage/timetable.sqlite');
        $pdo = new PDO('sqlite:' . $path, null, null, $opts + [PDO::ATTR_TIMEOUT => 4]);
        $pdo->exec('PRAGMA busy_timeout = 4000');
        // โหมด journal ตารางบริบูรณ์ตั้งไว้แล้ว (WAL คงอยู่ในไฟล์) · synchronous ต้องตั้งทุกการเชื่อมต่อ
        $mode = strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn());
        $pdo->exec('PRAGMA synchronous = ' . ($mode === 'wal' ? 'NORMAL' : 'FULL'));
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    $stamp = (string) @filemtime(__FILE__);
    $have = null;
    try { $have = $pdo->query("SELECT v FROM kl_meta WHERE k = 'schema'")->fetchColumn(); } catch (PDOException) { /* ยังไม่มีตาราง */ }
    if ($have !== $stamp) {
        kl_migrate($pdo, db_driver());
        $st = $pdo->prepare(db_driver() === 'mysql' ? 'REPLACE INTO kl_meta (k, v) VALUES (?, ?)' : 'INSERT OR REPLACE INTO kl_meta (k, v) VALUES (?, ?)');
        $st->execute(['schema', $stamp]);
    }
    return $pdo;
}

/** สร้าง/ปรับตาราง — ทุกคำสั่งต้องรันซ้ำได้ (รันใหม่ทุกครั้งที่ไฟล์นี้เปลี่ยน) */
function kl_migrate(PDO $pdo, string $driver): void {
    $id = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $text = $driver === 'mysql' ? 'MEDIUMTEXT' : 'TEXT';
    $tail = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS kl_meta (k VARCHAR(40) NOT NULL PRIMARY KEY, v VARCHAR(191) NULL)$tail");
    // ค่าตั้งต่อโรงเรียน: รูปแบบเลข อักษรย่อหน่วยงาน ผู้ลงนาม ฯลฯ (JSON)
    $pdo->exec("CREATE TABLE IF NOT EXISTS kl_settings (
        school_id INT NOT NULL PRIMARY KEY,
        data_json $text NOT NULL,
        updated_at DATETIME NOT NULL
    )$tail");
    // ประเภท/ชนิดครุภัณฑ์ของโรงเรียน (คัดลอกจากบัญชีมาตรฐานครั้งแรก แล้วเพิ่ม/แก้เองได้)
    $pdo->exec("CREATE TABLE IF NOT EXISTS kl_cats (
        id $id,
        school_id INT NOT NULL,
        code VARCHAR(20) NOT NULL,
        name VARCHAR(200) NOT NULL,
        grp VARCHAR(100) NULL,
        life_years INT NOT NULL DEFAULT 5,
        unit VARCHAR(30) NULL,
        UNIQUE (school_id, code)
    )$tail");
    // ครุภัณฑ์ 1 แถว = 1 หมายเลข (ซื้อพร้อมกันหลายชิ้นก็ได้หลายแถว เลขเรียงกัน)
    $pdo->exec("CREATE TABLE IF NOT EXISTS kl_items (
        id $id,
        school_id INT NOT NULL,
        token VARCHAR(16) NOT NULL,
        asset_no VARCHAR(80) NOT NULL,
        cat_code VARCHAR(20) NOT NULL,
        seq INT NOT NULL,
        fy INT NOT NULL,
        name VARCHAR(200) NOT NULL,
        brand VARCHAR(120) NULL,
        model VARCHAR(120) NULL,
        serial_no VARCHAR(120) NULL,
        spec VARCHAR(1000) NULL,
        unit VARCHAR(30) NULL,
        price DECIMAL(14,2) NOT NULL DEFAULT 0,
        acquired_on DATE NULL,
        doc_no VARCHAR(120) NULL,
        fund VARCHAR(60) NULL,
        method VARCHAR(60) NULL,
        vendor VARCHAR(200) NULL,
        location VARCHAR(200) NULL,
        custodian VARCHAR(200) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'normal',
        life_years INT NOT NULL DEFAULT 5,
        note VARCHAR(1000) NULL,
        photos_json $text NULL,
        thumb $text NULL,
        on_loan INT NOT NULL DEFAULT 0,
        checked_fy INT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        created_by INT NULL,
        UNIQUE (school_id, asset_no),
        UNIQUE (token)
    )$tail");
    // เบิก/ยืม–คืน
    $pdo->exec("CREATE TABLE IF NOT EXISTS kl_loans (
        id $id,
        school_id INT NOT NULL,
        item_id INT NOT NULL,
        kind VARCHAR(10) NOT NULL DEFAULT 'loan',
        borrower VARCHAR(200) NOT NULL,
        purpose VARCHAR(500) NULL,
        place VARCHAR(200) NULL,
        out_on DATE NOT NULL,
        due_on DATE NULL,
        approver VARCHAR(200) NULL,
        returned_on DATE NULL,
        return_note VARCHAR(500) NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL
    )$tail");
    // ประวัติทุกความเคลื่อนไหวของครุภัณฑ์ (ใช้เป็นบันทึกในทะเบียนคุม)
    $pdo->exec("CREATE TABLE IF NOT EXISTS kl_log (
        id $id,
        school_id INT NOT NULL,
        item_id INT NULL,
        at DATETIME NOT NULL,
        kind VARCHAR(20) NOT NULL,
        detail VARCHAR(1000) NULL,
        user_id INT NULL
    )$tail");
    foreach ([
        'kl_items_school_cat' => 'kl_items (school_id, cat_code, seq)',
        'kl_items_school_status' => 'kl_items (school_id, status)',
        'kl_items_school_upd' => 'kl_items (school_id, updated_at)',
        'kl_loans_school_open' => 'kl_loans (school_id, returned_on, due_on)',
        'kl_loans_item' => 'kl_loans (item_id, out_on)',
        'kl_log_item' => 'kl_log (item_id, at)',
        'kl_log_school' => 'kl_log (school_id, at)',
    ] as $name => $on) {
        if ($driver === 'mysql') {
            try { $pdo->exec("CREATE INDEX $name ON $on"); } catch (PDOException) { /* มีแล้ว */ }
        } else {
            $pdo->exec("CREATE INDEX IF NOT EXISTS $name ON $on");
        }
    }
}

function now(): string { return date('Y-m-d H:i:s'); }

function db_one(string $sql, array $args = []): ?array {
    $st = db()->prepare($sql);
    $st->execute($args);
    $row = $st->fetch();
    return $row ?: null;
}

function db_all(string $sql, array $args = []): array {
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

function db_val(string $sql, array $args = []) {
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchColumn();
}

function db_exec(string $sql, array $args = []): int {
    return db_retry(function () use ($sql, $args) {
        $st = db()->prepare($sql);
        $st->execute($args);
        return $st->rowCount();
    });
}

function db_is_busy(PDOException $e): bool {
    $code = (int) ($e->errorInfo[1] ?? 0);
    return in_array($code, [5, 6], true) || str_contains($e->getMessage(), 'database is locked');
}

function db_retry(callable $fn, int $tries = 3) {
    for ($i = 1; ; $i++) {
        try { return $fn(); }
        catch (PDOException $e) {
            if ($i >= $tries || !db_is_busy($e) || !empty($GLOBALS['DB_IN_TX'])) throw $e;
            usleep(random_int(50_000, 250_000) * $i);
        }
    }
}

/** ธุรกรรม (IMMEDIATE จองสิทธิ์เขียนตั้งแต่ต้น — กันเลขครุภัณฑ์ซ้ำเมื่อสองคนบันทึกพร้อมกัน) */
function db_tx(callable $fn) {
    return db_retry(function () use ($fn) {
        $pdo = db();
        $pdo->exec(db_driver() === 'mysql' ? 'START TRANSACTION' : 'BEGIN IMMEDIATE');
        $GLOBALS['DB_IN_TX'] = true;
        try {
            $r = $fn();
            $pdo->exec('COMMIT');
            return $r;
        } catch (Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (PDOException) { /* ไม่มีธุรกรรมค้าง */ }
            throw $e;
        } finally {
            $GLOBALS['DB_IN_TX'] = false;
        }
    });
}
