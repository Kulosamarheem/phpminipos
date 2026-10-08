<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();

$user = current_user();
$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title>จัดการสินค้า - Mini POS</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script src="../assets/js/products.js" defer></script>
</head>
<body>
    <header class="topbar">
        <a class="brand" href="index.php">Mini POS</a>
        <div class="topbar-actions">
            <span><?= htmlspecialchars($user['full_name']) ?> (<?= htmlspecialchars($user['role']) ?>)</span>
            <a href="logout.php">ออกจากระบบ</a>
        </div>
    </header>
    <main class="products-page">
        <section class="pos-panel product-form-panel">
            <p class="eyebrow">Product Management</p>
            <h1>เพิ่มสินค้า</h1>
            <p class="muted">ผู้ใช้และผู้ดูแลระบบสามารถเพิ่มและปิดการขายสินค้าได้</p>
            <form id="product-form" class="product-form">
                <label>บาร์โค้ด<input name="barcode" type="text" maxlength="50" required autofocus></label>
                <label>ชื่อสินค้า<input name="name" type="text" maxlength="150" required></label>
                <label>ราคา (บาท)<input name="price" type="number" min="0" max="99999999.99" step="0.01" required></label>
                <label>จำนวนสต็อก<input name="stock_qty" type="number" min="0" step="1" required></label>
                <button type="submit">เพิ่มสินค้า</button>
            </form>
            <p id="product-message" class="cart-message" role="status" aria-live="polite"></p>
        </section>
        <section class="pos-panel product-list-panel">
            <div class="cart-heading">
                <div><h2>รายการสินค้า</h2><p id="product-count" class="muted">กำลังโหลด…</p></div>
                <div class="page-links">
                    <a href="pos.php" class="text-link">ไปหน้าขายสินค้า</a>
                    <?php if ($user['role'] === 'admin'): ?>
                    <a href="report_sales.php" class="text-link">รายงานยอดขาย</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="cart-table-wrap">
                <table class="cart-table">
                    <thead><tr><th>บาร์โค้ด</th><th>สินค้า</th><th>ราคา</th><th>สต็อก</th><th><span class="sr-only">จัดการ</span></th></tr></thead>
                    <tbody id="product-items"><tr><td colspan="5" class="empty-cart">กำลังโหลดสินค้า…</td></tr></tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
