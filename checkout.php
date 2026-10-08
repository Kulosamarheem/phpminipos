<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/cart.php';
require_once __DIR__ . '/config/db.php';

// การแปลงจำนวนเงิน ↔ สตางค์ ย้ายไปอยู่ที่ includes/money.php (โหลดผ่าน includes/cart.php)
// เพื่อให้ทุก endpoint ที่รับจำนวนเงินใช้กติกา validate ชุดเดียวกัน

function checkout_order_number(): string
{
    return 'POS' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cart_json_response(['success' => false, 'message' => 'ไม่รองรับ HTTP method นี้'], 405);
}

cart_require_login();
$data = cart_request_data();
cart_verify_csrf($data);

$paymentMethod = (string) ($data['payment_method'] ?? '');
if (!in_array($paymentMethod, ['cash', 'qr', 'card'], true)) {
    cart_json_response(['success' => false, 'message' => 'กรุณาเลือกวิธีชำระเงิน'], 422);
}

$vatMode = (string) ($data['vat_mode'] ?? 'inclusive');
if (!in_array($vatMode, ['inclusive', 'exclusive'], true)) {
    cart_json_response(['success' => false, 'message' => 'ประเภทภาษีไม่ถูกต้อง'], 422);
}

$discountCents = money_to_cents($data['discount_amount'] ?? '0');
if ($discountCents === null) {
    cart_json_response(['success' => false, 'message' => 'ส่วนลดต้องเป็นจำนวนเงินตั้งแต่ 0.00 บาท'], 422);
}

$receivedCents = null;
if ($paymentMethod === 'cash') {
    $receivedCents = money_to_cents($data['received_amount'] ?? '');
    if ($receivedCents === null) {
        cart_json_response(['success' => false, 'message' => 'กรุณาระบุจำนวนเงินที่รับ'], 422);
    }
}

$requestedItems = [];
foreach (cart_items() as $item) {
    $productId = filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $qty = filter_var($item['qty'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($productId === false || $qty === false) {
        cart_json_response(['success' => false, 'message' => 'ข้อมูลในตะกร้าไม่ถูกต้อง กรุณาเพิ่มสินค้าใหม่'], 422);
    }

    $requestedItems[(int) $productId] = ($requestedItems[(int) $productId] ?? 0) + (int) $qty;
}

if ($requestedItems === []) {
    cart_json_response(['success' => false, 'message' => 'ตะกร้าสินค้าว่างเปล่า'], 422);
}

ksort($requestedItems, SORT_NUMERIC);
$user = current_user();

try {
    $pdo->beginTransaction();

    $lockProduct = $pdo->prepare(
        'SELECT id, name, price, stock_qty
         FROM products
         WHERE id = ? AND is_active = 1
         FOR UPDATE'
    );
    $saleItems = [];
    $totalCents = 0;

    foreach ($requestedItems as $productId => $qty) {
        $lockProduct->execute([$productId]);
        $product = $lockProduct->fetch();

        if ($product === false) {
            throw new RuntimeException('มีสินค้าที่ถูกลบหรือปิดการขายแล้ว');
        }

        if ((int) $product['stock_qty'] < $qty) {
            throw new RuntimeException("สินค้า {$product['name']} มีสต็อกไม่เพียงพอ");
        }

        $priceCents = money_to_cents($product['price']);
        if ($priceCents === null) {
            throw new RuntimeException('ราคาสินค้าไม่ถูกต้อง');
        }

        $subtotalCents = $priceCents * $qty;
        $totalCents += $subtotalCents;
        if ($totalCents > MONEY_MAX_CENTS) {
            throw new RuntimeException('ยอดขายเกินขอบเขตที่ระบบรองรับ');
        }

        $saleItems[] = [
            'product_id' => (int) $product['id'],
            'name' => (string) $product['name'],
            'qty' => $qty,
            'price_cents' => $priceCents,
            'subtotal_cents' => $subtotalCents,
        ];
    }

    if ($discountCents > $totalCents) {
        throw new RuntimeException('ส่วนลดต้องไม่เกินยอดรวมสินค้า');
    }

    // สูตรเดียวกับที่หน้าขายใช้แสดงยอดสด (cart_totals.php) — ดู includes/money.php
    ['tax_cents' => $taxCents, 'net_cents' => $netCents] = money_order_totals($totalCents, $discountCents, $vatMode);

    if ($paymentMethod === 'cash') {
        if ($receivedCents < $netCents) {
            throw new RuntimeException('จำนวนเงินที่รับน้อยกว่ายอดสุทธิ');
        }
        $changeCents = $receivedCents - $netCents;
    } else {
        $receivedCents = $netCents;
        $changeCents = 0;
    }

    $orderNumber = checkout_order_number();
    $insertOrder = $pdo->prepare(
        'INSERT INTO orders (
            order_number, user_id, total_amount, discount_amount, tax_amount,
            net_amount, payment_method, received_amount, change_amount
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insertOrder->execute([
        $orderNumber,
        (int) $user['id'],
        money_from_cents($totalCents),
        money_from_cents($discountCents),
        money_from_cents($taxCents),
        money_from_cents($netCents),
        $paymentMethod,
        money_from_cents($receivedCents),
        money_from_cents($changeCents),
    ]);
    $orderId = (int) $pdo->lastInsertId();

    $insertItem = $pdo->prepare(
        'INSERT INTO order_items (order_id, product_id, product_name, qty, price_at_sale, subtotal)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $deductStock = $pdo->prepare(
        'UPDATE products
         SET stock_qty = stock_qty - ?
         WHERE id = ? AND stock_qty >= ?'
    );
    $insertMovement = $pdo->prepare(
        'INSERT INTO stock_movements (product_id, change_qty, reason, ref_order_id)
         VALUES (?, ?, \'sale\', ?)'
    );

    foreach ($saleItems as $item) {
        $insertItem->execute([
            $orderId,
            $item['product_id'],
            $item['name'],
            $item['qty'],
            money_from_cents($item['price_cents']),
            money_from_cents($item['subtotal_cents']),
        ]);
        $deductStock->execute([$item['qty'], $item['product_id'], $item['qty']]);
        if ($deductStock->rowCount() !== 1) {
            throw new RuntimeException('ไม่สามารถตัดสต็อกสินค้าได้');
        }
        $insertMovement->execute([$item['product_id'], -$item['qty'], $orderId]);
    }

    $pdo->commit();
    unset($_SESSION['cart']);

    cart_json_response([
        'success' => true,
        'message' => 'บันทึกการขายสำเร็จ',
        'order' => [
            'id' => $orderId,
            'order_number' => $orderNumber,
            'total_amount' => money_from_cents($totalCents),
            'discount_amount' => money_from_cents($discountCents),
            'tax_amount' => money_from_cents($taxCents),
            'net_amount' => money_from_cents($netCents),
            'received_amount' => money_from_cents($receivedCents),
            'change_amount' => money_from_cents($changeCents),
        ],
        'cart' => cart_state(),
    ]);
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    cart_json_response(['success' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    cart_json_response(['success' => false, 'message' => 'ไม่สามารถบันทึกการขายได้'], 500);
}
