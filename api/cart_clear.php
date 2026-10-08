<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/cart.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cart_json_response(['success' => false, 'message' => 'ไม่รองรับ HTTP method นี้'], 405);
}

cart_require_login();
$data = cart_request_data();
cart_verify_csrf($data);

cart_set_items([]);
cart_send_state('ล้างตะกร้าสินค้าแล้ว');
