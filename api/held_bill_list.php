<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/held_bill.php';
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    cart_json_response(['success' => false, 'message' => 'ไม่รองรับ HTTP method นี้'], 405);
}

cart_require_login();

// อ่านอย่างเดียวจึงไม่ต้องตรวจ CSRF (กติกาเดียวกับ cart_get.php)
cart_json_response([
    'success' => true,
    'message' => '',
    'held_bills' => held_bill_list($pdo),
]);
