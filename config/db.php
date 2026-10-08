<?php

declare(strict_types=1);

require_once __DIR__ . '/settings.php';

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    // ห้ามส่ง $e->getMessage() ออกหน้าเว็บ เพราะมี host/user/ชื่อฐานข้อมูลอยู่ในข้อความ
    app_fail(
        'DB connection failed: ' . $e->getMessage(),
        'เชื่อมต่อฐานข้อมูลไม่สำเร็จ กรุณาตรวจสอบว่าเซิร์ฟเวอร์ฐานข้อมูลทำงานอยู่'
    );
}
