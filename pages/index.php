<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();

$user = current_user();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>หน้าหลัก - Mini POS</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <header class="topbar">
        <span>ยินดีต้อนรับ, <?= htmlspecialchars($user['full_name']) ?> (<?= htmlspecialchars($user['role']) ?>)</span>
        <a href="logout.php">ออกจากระบบ</a>
    </header>
    <main>
        <p>ระบบขายสินค้า บันทึกบิล พิมพ์ใบเสร็จ และรายงานยอดขาย พร้อมใช้งานแล้ว</p>
        <p class="quick-links">
            <a class="primary-link" href="pos.php">ไปหน้าขายสินค้า</a>
            <a class="secondary-link" href="products.php">จัดการสินค้า</a>
            <?php if ($user['role'] === 'admin'): ?>
            <a class="secondary-link" href="report_sales.php">รายงานยอดขาย</a>
            <a class="secondary-link" href="order_history.php">ประวัติบิล</a>
            <?php endif; ?>
        </p>
        <?php if ($user['role'] !== 'admin'): ?>
        <p class="muted">รายงานยอดขายและประวัติบิลเปิดให้เฉพาะผู้ดูแลระบบ</p>
        <?php endif; ?>
    </main>
</body>
</html>
