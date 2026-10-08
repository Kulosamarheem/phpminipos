<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/money.php';
require_login();

$user = current_user();
$csrfToken = csrf_token();
$vatPercent = money_vat_percent();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="cart-csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title>ขายสินค้า - Mini POS</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script src="../assets/js/pos.js" defer></script>
</head>
<body>
    <header class="topbar">
        <a class="brand" href="index.php">Mini POS</a>
        <div class="topbar-actions">
            <span><?= htmlspecialchars($user['full_name']) ?> (<?= htmlspecialchars($user['role']) ?>)</span>
            <a href="logout.php">ออกจากระบบ</a>
        </div>
    </header>
    <main class="pos-page">
        <section class="pos-panel scanner-panel" aria-labelledby="pos-title">
            <p class="eyebrow">Phase 3 · Checkout &amp; Transaction</p>
            <h1 id="pos-title">ขายสินค้า</h1>
            <p class="muted">สแกนหรือกรอกบาร์โค้ดเพื่อเพิ่มสินค้าในตะกร้า</p>
            <form id="add-product-form" class="barcode-form">
                <label for="barcode">บาร์โค้ดสินค้า</label>
                <div class="barcode-input-row">
                    <input id="barcode" name="barcode" type="text" inputmode="numeric" autocomplete="off" required autofocus>
                    <button type="submit">เพิ่มสินค้า</button>
                </div>
            </form>
            <p id="cart-message" class="cart-message" role="status" aria-live="polite"></p>
        </section>

        <section class="pos-panel cart-panel" aria-labelledby="cart-title">
            <div class="cart-heading">
                <div>
                    <h2 id="cart-title">ตะกร้าสินค้า</h2>
                    <p id="cart-item-count" class="muted">0 ชิ้น</p>
                </div>
                <div class="page-links">
                    <button type="button" id="clear-cart-button" class="text-link clear-cart-button">ล้างรายการ</button>
                    <a href="products.php" class="text-link">จัดการสินค้า</a>
                    <?php if ($user['role'] === 'admin'): ?>
                    <a href="report_sales.php" class="text-link">รายงานยอดขาย</a>
                    <a href="order_history.php" class="text-link">ประวัติบิล</a>
                    <?php endif; ?>
                    <a href="index.php" class="text-link">กลับหน้าหลัก</a>
                </div>
            </div>
            <div class="cart-table-wrap">
                <table class="cart-table">
                    <thead>
                        <tr>
                            <th>สินค้า</th>
                            <th>ราคา</th>
                            <th>จำนวน</th>
                            <th>รวม</th>
                            <th><span class="sr-only">จัดการ</span></th>
                        </tr>
                    </thead>
                    <tbody id="cart-items">
                        <tr><td colspan="5" class="empty-cart">กำลังโหลดตะกร้า…</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="cart-totals">
                <div class="ct-row"><span>ยอดรวมสินค้า</span><strong id="cart-total">0.00 บาท</strong></div>
                <div class="ct-row"><span>ส่วนลด</span><strong id="cart-discount">0.00 บาท</strong></div>
                <div class="ct-row"><span>VAT <?= htmlspecialchars($vatPercent, ENT_QUOTES, 'UTF-8') ?>%</span><strong id="cart-tax">0.00 บาท</strong></div>
                <div class="ct-row ct-net"><span>ยอดสุทธิ</span><strong id="cart-net">0.00 บาท</strong></div>
                <div class="ct-row ct-change" id="cart-change-row" hidden><span>เงินทอน</span><strong id="cart-change">0.00 บาท</strong></div>
            </div>
            <form id="checkout-form" class="checkout-form">
                <h3>ชำระเงิน</h3>
                <div class="checkout-fields">
                    <label for="discount-amount">ส่วนลด (บาท)
                        <input id="discount-amount" name="discount_amount" type="number" min="0" step="0.01" value="0.00" required>
                    </label>
                    <label>ประเภทภาษี
                        <select id="vat-mode" name="vat_mode">
                            <option value="inclusive" selected>ราคารวม VAT</option>
                            <option value="exclusive">ราคาไม่รวม VAT</option>
                        </select>
                    </label>
                    <label>วิธีชำระเงิน
                        <select id="payment-method" name="payment_method">
                            <option value="cash">เงินสด</option>
                            <option value="qr">QR</option>
                            <option value="card">บัตร</option>
                        </select>
                    </label>
                    <label id="received-amount-field">รับเงิน (บาท)
                        <input id="received-amount" name="received_amount" type="number" min="0" step="0.01" required>
                    </label>
                </div>
                <button id="checkout-button" type="submit" disabled>ยืนยันการชำระเงิน</button>
                <p id="checkout-message" class="cart-message" role="status" aria-live="polite"></p>
                <p id="last-receipt" class="cart-message" hidden></p>
            </form>
        </section>

        <section class="pos-panel held-bills-panel" aria-labelledby="held-bills-title">
            <div class="cart-heading">
                <div>
                    <h2 id="held-bills-title">บิลที่พักไว้</h2>
                    <p id="held-bills-count" class="muted">0 บิล</p>
                </div>
                <div class="page-links">
                    <button type="button" id="held-bills-refresh" class="text-link clear-cart-button">รีเฟรช</button>
                </div>
            </div>
            <form id="hold-bill-form" class="barcode-form">
                <label for="hold-note">หมายเหตุ (ไม่บังคับ)</label>
                <div class="barcode-input-row">
                    <input id="hold-note" name="note" type="text" maxlength="<?= HELD_BILL_NOTE_MAX ?>" autocomplete="off" placeholder="เช่น ลูกค้าเสื้อแดง">
                    <button type="submit" id="hold-bill-button">พักบิล</button>
                </div>
            </form>
            <div class="cart-table-wrap">
                <table class="cart-table held-bills-table">
                    <thead>
                        <tr>
                            <th>เลขที่</th>
                            <th>รายการ</th>
                            <th>ยอดรวม</th>
                            <th><span class="sr-only">จัดการ</span></th>
                        </tr>
                    </thead>
                    <tbody id="held-bill-items">
                        <tr><td colspan="4" class="empty-cart">กำลังโหลด…</td></tr>
                    </tbody>
                </table>
            </div>
            <p id="held-bill-message" class="cart-message" role="status" aria-live="polite"></p>
        </section>
    </main>
</body>
</html>
