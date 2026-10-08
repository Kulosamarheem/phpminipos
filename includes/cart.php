<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/money.php';

function cart_json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function cart_require_login(): void
{
    if (!is_logged_in()) {
        cart_json_response([
            'success' => false,
            'message' => 'กรุณาเข้าสู่ระบบก่อนใช้งานตะกร้าสินค้า',
        ], 401);
    }
}

function cart_request_data(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (stripos($contentType, 'application/json') !== false) {
        $body = file_get_contents('php://input');
        $data = json_decode($body ?: '{}', true);

        if (!is_array($data)) {
            cart_json_response(['success' => false, 'message' => 'รูปแบบข้อมูลไม่ถูกต้อง'], 400);
        }

        return $data;
    }

    return $_POST;
}

function cart_verify_csrf(array $data): void
{
    $token = (string) ($data['csrf_token'] ?? '');

    if (!is_valid_csrf_token($token)) {
        cart_json_response(['success' => false, 'message' => 'คำขอไม่ถูกต้อง กรุณาลองใหม่'], 403);
    }
}

function cart_items(): array
{
    $cart = $_SESSION['cart'] ?? [];

    return is_array($cart) ? $cart : [];
}

function cart_set_items(array $items): void
{
    $_SESSION['cart'] = $items;
}

function cart_is_empty(): bool
{
    return cart_items() === [];
}

function cart_state(): array
{
    $items = array_values(cart_items());
    $totalAmount = 0.0;
    $totalCents = 0;
    $itemCount = 0;

    foreach ($items as &$item) {
        $item['price'] = round((float) $item['price'], 2);
        $item['qty'] = (int) $item['qty'];
        $item['subtotal'] = round($item['price'] * $item['qty'], 2);
        $totalAmount += $item['subtotal'];
        // ยอดรวมหน่วยสตางค์ — ใช้เป็นฐานคำนวณ VAT ให้ตรงกับ checkout.php ที่คิดด้วย int เสมอ
        $totalCents += (money_to_cents(number_format($item['price'], 2, '.', '')) ?? 0) * $item['qty'];
        $itemCount += $item['qty'];
    }
    unset($item);

    return [
        'items' => $items,
        'item_count' => $itemCount,
        'total_amount' => round($totalAmount, 2),
        'total_cents' => $totalCents,
    ];
}

function cart_send_state(string $message = ''): void
{
    cart_json_response([
        'success' => true,
        'message' => $message,
        'cart' => cart_state(),
    ]);
}

function cart_find_active_product_by_barcode(PDO $pdo, string $barcode): array|false
{
    $stmt = $pdo->prepare(
        'SELECT id, barcode, name, price, stock_qty
         FROM products
         WHERE barcode = ? AND is_active = 1'
    );
    $stmt->execute([$barcode]);

    return $stmt->fetch();
}

function cart_find_active_product_by_id(PDO $pdo, int $productId): array|false
{
    $stmt = $pdo->prepare(
        'SELECT id, barcode, name, price, stock_qty
         FROM products
         WHERE id = ? AND is_active = 1'
    );
    $stmt->execute([$productId]);

    return $stmt->fetch();
}

function cart_add_product(array $product): void
{
    $productId = (int) $product['id'];
    $stockQty = (int) $product['stock_qty'];
    $items = cart_items();
    $currentQty = isset($items[$productId]) ? (int) $items[$productId]['qty'] : 0;

    if ($stockQty < 1 || $currentQty >= $stockQty) {
        cart_json_response(['success' => false, 'message' => 'สินค้าในสต็อกไม่เพียงพอ'], 422);
    }

    $items[$productId] = [
        'product_id' => $productId,
        'barcode' => (string) $product['barcode'],
        'name' => (string) $product['name'],
        'price' => (float) $product['price'],
        'qty' => $currentQty + 1,
    ];
    cart_set_items($items);
}

function cart_update_product(array $product, int $quantity): void
{
    $productId = (int) $product['id'];
    $items = cart_items();

    if (!isset($items[$productId])) {
        cart_json_response(['success' => false, 'message' => 'ไม่พบสินค้าในตะกร้า'], 404);
    }

    if ($quantity < 1) {
        unset($items[$productId]);
        cart_set_items($items);
        return;
    }

    if ($quantity > (int) $product['stock_qty']) {
        cart_json_response(['success' => false, 'message' => 'สินค้าในสต็อกไม่เพียงพอ'], 422);
    }

    $items[$productId] = [
        'product_id' => $productId,
        'barcode' => (string) $product['barcode'],
        'name' => (string) $product['name'],
        'price' => (float) $product['price'],
        'qty' => $quantity,
    ];
    cart_set_items($items);
}
