<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/money.php';
require_once __DIR__ . '/../config/db.php';

require_login();
$user = current_user();

$orderId = filter_var($_GET['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($orderId === false || $orderId === null) {
    render_error_page('รหัสบิลไม่ถูกต้อง', 400, 'เปิดใบเสร็จไม่ได้', BASE_URL . 'pages/pos.php', 'กลับหน้าขาย');
}

$stmt = $pdo->prepare(
    'SELECT o.order_number, o.user_id, o.total_amount, o.discount_amount, o.tax_amount,
            o.net_amount, o.payment_method, o.received_amount, o.change_amount, o.created_at,
            u.full_name AS cashier_name
     FROM orders o
     LEFT JOIN users u ON u.id = o.user_id
     WHERE o.id = ?'
);
$stmt->execute([$orderId]);
$order = $stmt->fetch();

// ไม่พบบิล หรือไม่มีสิทธิ์ดู → ตอบ 404 เหมือนกันเพื่อไม่ให้เดา id ของบิลคนอื่นได้
$isAdmin = ($user['role'] ?? '') === 'admin';
$isOwner = $order !== false && (int) ($order['user_id'] ?? 0) === (int) ($user['id'] ?? 0);

if ($order === false || (!$isAdmin && !$isOwner)) {
    render_error_page('ไม่พบใบเสร็จของบิลนี้', 404, 'ไม่พบใบเสร็จ', BASE_URL . 'pages/pos.php', 'กลับหน้าขาย');
}

$items = $pdo->prepare(
    'SELECT product_name, qty, price_at_sale, subtotal
     FROM order_items
     WHERE order_id = ?
     ORDER BY id'
);
$items->execute([$orderId]);
$orderItems = $items->fetchAll();

// ถึงบรรทัดนี้แปลว่าเป็นบิลจริงและมีสิทธิ์ดู — เปิดให้ pos.php ฝังเป็น iframe เพื่อสั่งพิมพ์ได้
page_allow_same_origin_frame();

$autoPrint = isset($_GET['autoprint']);

$paymentLabels = ['cash' => 'เงินสด', 'qr' => 'QR', 'card' => 'บัตร'];
$paymentLabel = $paymentLabels[$order['payment_method']] ?? (string) $order['payment_method'];

$createdDisplay = $order['created_at'] ? date('d/m/Y H:i', strtotime((string) $order['created_at'])) : '';
$vatPercent = money_vat_percent();

$money = static fn ($value): string => number_format((float) $value, 2);
$esc = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ใบเสร็จ <?= $esc($order['order_number']) ?> - Mini POS</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/print.css">
</head>
<body class="receipt-page">
    <div class="receipt-toolbar">
        <button type="button" onclick="window.print()">พิมพ์ใบเสร็จ</button>
        <a href="pos.php">กลับหน้าขาย</a>
    </div>

    <div id="receipt-section">
        <div class="receipt-header">
            <h1><?= $esc(STORE_NAME) ?></h1>
            <?php if (STORE_ADDRESS !== ''): ?><p><?= $esc(STORE_ADDRESS) ?></p><?php endif; ?>
            <?php if (STORE_PHONE !== ''): ?><p>โทร. <?= $esc(STORE_PHONE) ?></p><?php endif; ?>
            <?php if (STORE_TAX_ID !== ''): ?><p>เลขประจำตัวผู้เสียภาษี <?= $esc(STORE_TAX_ID) ?></p><?php endif; ?>
        </div>

        <div class="receipt-meta">
            <div><span>เลขที่บิล</span><span><?= $esc($order['order_number']) ?></span></div>
            <div><span>วันที่</span><span><?= $esc($createdDisplay) ?></span></div>
            <div><span>พนักงาน</span><span><?= $esc($order['cashier_name'] ?? '-') ?></span></div>
        </div>

        <table class="receipt-items">
            <tbody>
                <?php foreach ($orderItems as $item): ?>
                <tr class="ri-name"><td colspan="2"><?= $esc($item['product_name']) ?></td></tr>
                <tr class="ri-detail">
                    <td><?= (int) $item['qty'] ?> × <?= $money($item['price_at_sale']) ?></td>
                    <td class="ri-sub"><?= $money($item['subtotal']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="receipt-totals">
            <div class="rt-row"><span>ยอดรวมสินค้า</span><span><?= $money($order['total_amount']) ?></span></div>
            <?php if ((float) $order['discount_amount'] > 0): ?>
            <div class="rt-row"><span>ส่วนลด</span><span>-<?= $money($order['discount_amount']) ?></span></div>
            <?php endif; ?>
            <div class="rt-row"><span>VAT <?= $esc($vatPercent) ?>%</span><span><?= $money($order['tax_amount']) ?></span></div>
            <div class="rt-row rt-net"><span>ยอดสุทธิ</span><span><?= $money($order['net_amount']) ?></span></div>
            <div class="rt-row"><span>รับเงิน (<?= $esc($paymentLabel) ?>)</span><span><?= $money($order['received_amount']) ?></span></div>
            <div class="rt-row"><span>เงินทอน</span><span><?= $money($order['change_amount']) ?></span></div>
        </div>

        <?php if (STORE_FOOTER_NOTE !== ''): ?>
        <div class="receipt-footer"><?= $esc(STORE_FOOTER_NOTE) ?></div>
        <?php endif; ?>
    </div>

    <?php if ($autoPrint): ?>
    <script>window.addEventListener('load', () => window.print());</script>
    <?php endif; ?>
</body>
</html>
