<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/report.php';
require_once __DIR__ . '/config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    render_error_page('หน้ารายงานรองรับเฉพาะการเปิดดูเท่านั้น', 405, 'ไม่รองรับคำขอนี้');
}

require_role('admin');
$user = current_user();

$cashiers = report_cashiers($pdo);
$filters = report_parse_filters($_GET, $cashiers);

// $where ประกอบจากค่าคงที่ในโค้ดเท่านั้น ค่าจากผู้ใช้ทั้งหมดถูกส่งผ่าน $params (prepared statement)
[$where, $params] = report_where($filters);

// สรุปจากหัวบิลอย่างเดียว — ห้าม join order_items ที่นี่ ไม่งั้น SUM จะถูกคูณตามจำนวนรายการในบิล
$summaryStmt = $pdo->prepare(
    "SELECT COUNT(*) AS bill_count,
            COALESCE(SUM(o.total_amount), 0)    AS total_amount,
            COALESCE(SUM(o.discount_amount), 0) AS discount_amount,
            COALESCE(SUM(o.tax_amount), 0)      AS tax_amount,
            COALESCE(SUM(o.net_amount), 0)      AS net_amount
     FROM orders o
     WHERE {$where}"
);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch();

// จำนวนชิ้นที่ขายได้ต้องนับจาก order_items จึงแยกเป็นอีก query
$itemStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(oi.qty), 0) AS item_qty
     FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     WHERE {$where}"
);
$itemStmt->execute($params);
$itemQty = (int) $itemStmt->fetchColumn();

$billCount = (int) $summary['bill_count'];
$averageNet = $billCount > 0 ? (float) $summary['net_amount'] / $billCount : 0.0;

$rangeLabel = $filters['date_from'] === $filters['date_to']
    ? date('d/m/Y', strtotime($filters['date_from']))
    : date('d/m/Y', strtotime($filters['date_from'])) . ' – ' . date('d/m/Y', strtotime($filters['date_to']));
$cashierLabel = report_cashier_name($cashiers, $filters['user_id']);
$vatPercent = money_vat_percent();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>รายงานยอดขาย - Mini POS</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <?php report_render_topbar($user); ?>
    <main class="report-page">
        <section class="pos-panel">
            <div class="cart-heading">
                <div>
                    <p class="eyebrow">Reports</p>
                    <h1>รายงานยอดขาย</h1>
                    <p class="muted">นับเฉพาะบิลสถานะ completed · <?= page_esc($rangeLabel) ?> · <?= page_esc($cashierLabel) ?></p>
                </div>
                <div class="page-links">
                    <a class="text-link" href="order_history.php<?= page_esc(report_query_string($filters)) ?>">ประวัติบิล</a>
                    <a class="text-link" href="index.php">กลับหน้าหลัก</a>
                </div>
            </div>
            <?php report_render_filter_form('report_sales.php', $filters, $cashiers); ?>
            <?php report_render_filter_errors($filters['errors']); ?>
        </section>

        <section class="pos-panel">
            <h2>สรุปยอดขาย</h2>
            <?php if ($billCount === 0): ?>
            <p class="empty-report">ไม่มีบิลในช่วงเวลาที่เลือก</p>
            <?php else: ?>
            <div class="summary-grid">
                <div class="summary-card">
                    <span class="summary-label">จำนวนบิล</span>
                    <strong class="summary-value"><?= number_format($billCount) ?> <small>บิล</small></strong>
                </div>
                <div class="summary-card">
                    <span class="summary-label">จำนวนชิ้นที่ขาย</span>
                    <strong class="summary-value"><?= number_format($itemQty) ?> <small>ชิ้น</small></strong>
                </div>
                <div class="summary-card">
                    <span class="summary-label">ยอดรวมสินค้า</span>
                    <strong class="summary-value"><?= money_display($summary['total_amount']) ?> <small>บาท</small></strong>
                </div>
                <div class="summary-card">
                    <span class="summary-label">ส่วนลด</span>
                    <strong class="summary-value">-<?= money_display($summary['discount_amount']) ?> <small>บาท</small></strong>
                </div>
                <div class="summary-card">
                    <span class="summary-label">VAT <?= page_esc($vatPercent) ?>%</span>
                    <strong class="summary-value"><?= money_display($summary['tax_amount']) ?> <small>บาท</small></strong>
                </div>
                <div class="summary-card is-primary">
                    <span class="summary-label">ยอดสุทธิ</span>
                    <strong class="summary-value"><?= money_display($summary['net_amount']) ?> <small>บาท</small></strong>
                </div>
                <div class="summary-card">
                    <span class="summary-label">เฉลี่ยต่อบิล</span>
                    <strong class="summary-value"><?= money_display($averageNet) ?> <small>บาท</small></strong>
                </div>
            </div>
            <p class="muted">ยอดสุทธิ = ยอดรวมสินค้า − ส่วนลด + VAT <?= page_esc($vatPercent) ?>%</p>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
