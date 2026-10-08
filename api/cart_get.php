<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/cart.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    cart_json_response(['success' => false, 'message' => 'ไม่รองรับ HTTP method นี้'], 405);
}

cart_require_login();
cart_send_state();
