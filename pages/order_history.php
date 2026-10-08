<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/report.php';
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    render_error_page('หน้าประวัติบิลรองรับเฉพาะการเปิดดูเท่านั้น', 405, 'ไม่รองรับคำขอนี้');
}

require_role('admin');
$user = current_user();

$cashiers = report_cashiers($pdo);
$filters = report_parse_filters($_GET, $cashiers);

// หน้าประวัติแสดงบิลที่ถูกยกเลิกด้วย (ต่างจาก report_sales.php ที่นับเฉพาะ completed)
// $where ประกอบจากค่าคงที่ในโค้ด ค่าจากผู้ใช้ส่งผ่าน $params เท่านั้น
[$where, $params] = report_where($filters, false);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM orders o WHERE {$where}");
$countStmt->execute($params);
$totalOrders = (int) $countStmt->fetchColumn();

$totalPages = max(1, (int) ceil($totalOrders / REPORT_PAGE_SIZE));
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($page === false) {
    $page = 1;
}
$page = min($page, $totalPages);
$offset = ($page - 1) * REPORT_PAGE_SIZE;

$listStmt = $pdo->prepare(
    "SELECT o.id, o.order_number, o.created_at, o.net_amount, o.payment_method, o.status,
            u.full_name AS cashier_name,
            (SELECT COALESCE(SUM(oi.qty), 0) FROM order_items oi WHERE oi.order_id = o.id) AS item_qty
     FROM orders o
     LEFT JOIN users u ON u.id = o.user_id
     WHERE {$where}
     ORDER BY o.created_at DESC, o.id DESC
     LIMIT ? OFFSET ?"
);

// EMULATE_PREPARES ปิดอยู่ (config/db.php) → LIMIT/OFFSET ต้อง bind เป็น PARAM_INT ไม่ใช่ string
$position = 1;
foreach ($params as $param) {
    $listStmt->bindValue($position++, $param);
}
$listStmt->bindValue($position++, REPORT_PAGE_SIZE, PDO::PARAM_INT);
$listStmt->bindValue($position, $offset, PDO::PARAM_INT);
$listStmt->execute();
$orders = $listStmt->fetchAll();

// ---------------------------------------------------------
// รายละเอียดของบิลที่เลือก — ค้นด้วย id อย่างเดียว ไม่ผูกกับตัวกรอง
// เพื่อให้เปิดดูบิลที่อยู่นอกช่วงวันที่ปัจจุบันได้
// ---------------------------------------------------------
$detailOrder = null;
$detailItems = [];
$detailError = '';
$detailId = null;

if (isset($_GET['order_id']) && $_GET['order_id'] !== '') {
    $detailId = filter_var($_GET['order_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($detailId === false) {
        $detailError = 'รหัสบิลไม่ถูกต้อง';
        $detailId = null;
    } else {
        $detailStmt = $pdo->prepare(
            'SELECT o.id, o.order_number, o.total_amount, o.discount_amount, o.tax_amount,
                    o.net_amount, o.payment_method, o.received_amount, o.change_amount,
                    o.status, o.created_at, u.full_name AS cashier_name
             FROM orders o
             LEFT JOIN users u ON u.id = o.user_id
             WHERE o.id = ?'
        );
        $detailStmt->execute([$detailId]);
        $detailOrder = $detailStmt->fetch() ?: null;

        if ($detailOrder === null) {
            $detailError = 'ไม่พบบิลที่ต้องการดู อาจถูกลบไปแล้ว';
        } else {
            $itemsStmt = $pdo->prepare(
                'SELECT product_name, qty, price_at_sale, subtotal
                 FROM order_items
                 WHERE order_id = ?
                 ORDER BY id'
            );
            $itemsStmt->execute([$detailId]);
            $detailItems = $itemsStmt->fetchAll();
        }
    }
}

$statusLabels = ['completed' => 'สำเร็จ', 'voided' => 'ยกเลิก'];
$vatPercent = money_vat_percent();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ประวัติบิล - Mini POS</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <?php report_render_topbar($user); ?>
    <main class="report-page">
        <section class="pos-panel">
            <div class="cart-heading">
                <div>
                    <p class="eyebrow">Reports</p>
                    <h1>ประวัติบิล</h1>
                    <p class="muted">พบ <?= number_format($totalOrders) ?> บิล · หน้า <?= number_format($page) ?> จาก <?= number_format($totalPages) ?></p>
                </div>
                <div class="page-links">
                    <a class="text-link" href="report_sales.php<?= page_esc(report_query_string($filters)) ?>">รายงานยอดขาย</a>
                    <a class="text-link" href="index.php">กลับหน้าหลัก</a>
                </div>
            </div>
            <?php report_render_filter_form('order_history.php', $filters, $cashiers, true); ?>
            <?php report_render_filter_errors($filters['errors']); ?>
        </section>

        <?php if ($detailError !== ''): ?>
        <section class="pos-panel">
            <p class="cart-message error" role="status"><?= page_esc($detailError) ?></p>
        </section>
        <?php elseif ($detailOrder !== null): ?>
        <section class="pos-panel order-detail-panel">
            <div class="cart-heading">
                <div>
                    <h2>บิล <?= page_esc($detailOrder['order_number']) ?></h2>
                    <p class="muted">
                        <?= page_esc(date('d/m/Y H:i', strtotime((string) $detailOrder['created_at']))) ?>
                        · <?= page_esc($detailOrder['cashier_name'] ?? '-') ?>
                        · <?= page_esc(REPORT_PAYMENT_LABELS[$detailOrder['payment_method']] ?? $detailOrder['payment_method']) ?>
                        · <?= page_esc($statusLabels[$detailOrder['status']] ?? $detailOrder['status']) ?>
                    </p>
                </div>
                <div class="page-links">
                    <a class="text-link" href="receipt.php?order_id=<?= (int) $detailOrder['id'] ?>" target="_blank" rel="noopener">เปิดใบเสร็จ</a>
                    <a class="text-link" href="order_history.php<?= page_esc(report_query_string($filters, ['page' => $page > 1 ? (string) $page : ''])) ?>">ปิดรายละเอียด</a>
                </div>
            </div>
            <div class="cart-table-wrap">
                <table class="cart-table">
                    <thead>
                        <tr><th>สินค้า</th><th>ราคา</th><th>จำนวน</th><th>รวม</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($detailItems as $item): ?>
                        <tr>
                            <td><?= page_esc($item['product_name']) ?></td>
                            <td><?= money_display($item['price_at_sale']) ?></td>
                            <td><?= (int) $item['qty'] ?></td>
                            <td><?= money_display($item['subtotal']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if ($detailItems === []): ?>
                        <tr><td colspan="4" class="empty-cart">บิลนี้ไม่มีรายการสินค้า</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="summary-grid detail-summary">
                <div class="summary-card">
                    <span class="summary-label">ยอดรวมสินค้า</span>
                    <strong class="summary-value"><?= money_display($detailOrder['total_amount']) ?></strong>
                </div>
                <div class="summary-card">
                    <span class="summary-label">ส่วนลด</span>
                    <strong class="summary-value">-<?= money_display($detailOrder['discount_amount']) ?></strong>
                </div>
                <div class="summary-card">
                    <span class="summary-label">VAT <?= page_esc($vatPercent) ?>%</span>
                    <strong class="summary-value"><?= money_display($detailOrder['tax_amount']) ?></strong>
                </div>
                <div class="summary-card is-primary">
                    <span class="summary-label">ยอดสุทธิ</span>
                    <strong class="summary-value"><?= money_display($detailOrder['net_amount']) ?></strong>
                </div>
                <div class="summary-card">
                    <span class="summary-label">รับเงิน</span>
                    <strong class="summary-value"><?= money_display($detailOrder['received_amount']) ?></strong>
                </div>
                <div class="summary-card">
                    <span class="summary-label">เงินทอน</span>
                    <strong class="summary-value"><?= money_display($detailOrder['change_amount']) ?></strong>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <section class="pos-panel">
            <h2>รายการบิล</h2>
            <div class="cart-table-wrap">
                <table class="cart-table">
                    <thead>
                        <tr>
                            <th>เลขที่บิล</th>
                            <th>วันที่</th>
                            <th>พนักงาน</th>
                            <th>จำนวน</th>
                            <th>ยอดสุทธิ</th>
                            <th>ชำระโดย</th>
                            <th>สถานะ</th>
                            <th><span class="sr-only">จัดการ</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($orders === []): ?>
                        <tr><td colspan="8" class="empty-cart">ไม่พบบิลตามเงื่อนไขที่เลือก</td></tr>
                        <?php endif; ?>
                        <?php foreach ($orders as $order): ?>
                        <tr<?= $detailId === (int) $order['id'] ? ' class="is-selected"' : '' ?>>
                            <td><?= page_esc($order['order_number']) ?></td>
                            <td><?= page_esc(date('d/m/Y H:i', strtotime((string) $order['created_at']))) ?></td>
                            <td><?= page_esc($order['cashier_name'] ?? '-') ?></td>
                            <td><?= number_format((int) $order['item_qty']) ?></td>
                            <td><?= money_display($order['net_amount']) ?></td>
                            <td><?= page_esc(REPORT_PAYMENT_LABELS[$order['payment_method']] ?? $order['payment_method']) ?></td>
                            <td><span class="status-badge status-<?= page_esc($order['status']) ?>"><?= page_esc($statusLabels[$order['status']] ?? $order['status']) ?></span></td>
                            <td class="row-actions">
                                <a class="text-link" href="order_history.php<?= page_esc(report_query_string($filters, ['page' => $page > 1 ? (string) $page : '', 'order_id' => (string) $order['id']])) ?>">ดูรายการ</a>
                                <a class="text-link" href="receipt.php?order_id=<?= (int) $order['id'] ?>" target="_blank" rel="noopener">ใบเสร็จ</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="แบ่งหน้า">
                <?php if ($page > 1): ?>
                <a class="text-link" href="order_history.php<?= page_esc(report_query_string($filters, ['page' => $page - 1 > 1 ? (string) ($page - 1) : ''])) ?>">‹ ก่อนหน้า</a>
                <?php endif; ?>
                <span class="muted">หน้า <?= number_format($page) ?> / <?= number_format($totalPages) ?></span>
                <?php if ($page < $totalPages): ?>
                <a class="text-link" href="order_history.php<?= page_esc(report_query_string($filters, ['page' => (string) ($page + 1)])) ?>">ถัดไป ›</a>
                <?php endif; ?>
            </nav>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
