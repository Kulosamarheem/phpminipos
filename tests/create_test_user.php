<?php

declare(strict_types=1);

/**
 * สร้าง/รีเซ็ตผู้ใช้สำหรับ e2e test — รันก่อนรัน Playwright
 *   "C:\xampp\php\php.exe" tests/create_test_user.php
 *
 * ตั้งค่าผ่าน environment variable: POS_TEST_USER, POS_TEST_PASS, POS_TEST_ROLE
 * ใช้ผู้ใช้แยกต่างหากเสมอ ห้ามรันเทสต์ด้วยบัญชีจริงของร้าน
 *
 * แฮชสร้างจาก PHP ตัวที่รันจริงบนเครื่องนี้ จึง verify ผ่านแน่นอน
 * ไม่ต้องพึ่งค่าที่ seed ไว้ใน sql/schema.sql
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("สคริปต์นี้รันได้จาก command line เท่านั้น\n");
}

require_once __DIR__ . '/../config/db.php';

$username = getenv('POS_TEST_USER') ?: 'pos_e2e';
$password = getenv('POS_TEST_PASS') ?: 'pos_e2e_pass';
$role = getenv('POS_TEST_ROLE') ?: 'admin';

$pdo->prepare(
    'INSERT INTO users (username, password_hash, full_name, role, is_active)
     VALUES (?, ?, ?, ?, 1)
     ON DUPLICATE KEY UPDATE
        password_hash = VALUES(password_hash),
        full_name = VALUES(full_name),
        role = VALUES(role),
        is_active = 1'
)->execute([$username, password_hash($password, PASSWORD_DEFAULT), 'ผู้ใช้ทดสอบ E2E', $role]);

echo "พร้อมใช้งาน: username={$username} role={$role}\n";
