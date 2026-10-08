const { execFileSync } = require('node:child_process');
const path = require('node:path');
const { test, expect } = require('@playwright/test');
const {
    login,
    createProduct,
    productRow,
    editingRow,
    deactivateProduct,
    uniqueBarcode,
    stubPrint,
} = require('../fixtures/pos');

const phpBin = process.env.POS_PHP_BIN || 'C:\\xampp\\php\\php.exe';
const resetScript = path.join(__dirname, '..', '..', 'reset_held_bills.php');

/**
 * ล้างบิลพักผ่าน CLI ไม่ใช่ผ่านหน้าจอ
 * บิลพักอยู่ใน DB ร่วมกันทุก session ไม่ได้ผูกกับ browser context ที่ Playwright สร้างใหม่ทุกเทสต์
 * การล้างผ่าน UI ต้องกด confirm ทีละใบซึ่งไปชนกับ dialog handler ของ deactivateProduct
 */
function resetHeldBills() {
    execFileSync(phpBin, [resetScript], { encoding: 'utf8', env: process.env });
}

/**
 * พักบิล — ลูกค้าลืมหยิบของ ให้พักตะกร้าไว้ คิดลูกค้าคนถัดไปก่อน แล้วค่อยเรียกกลับมาคิดต่อ
 *
 * เทสต์ชุดนี้ตรวจ "ยอดรวมสินค้า" (#cart-total) ไม่ใช่ยอดสุทธิ เพราะยอดสุทธิขึ้นกับโหมด VAT
 * ที่เลือกไว้ในฟอร์ม ส่วนการพักบิลไม่ได้เก็บโหมด VAT ไปด้วยตามดีไซน์
 *
 * บิลที่พักไว้อยู่ในฐานข้อมูลร่วมกันทุก session จึงต้องล้างท้ายเทสต์ทุกตัว
 * ไม่งั้นจะไปกินโควตา HELD_BILL_MAX และรบกวนเทสต์ตัวถัดไป
 */
test.describe('pos.php — พักบิล', () => {
    const created = [];

    const heldRows = (page) => page.locator('#held-bill-items tr');
    const heldCount = (page) => page.locator('#held-bills-count');

    async function addToCart(page, barcode) {
        await page.fill('#barcode', barcode);
        await page.click('#add-product-form button[type="submit"]');
    }

    async function parkBill(page, note = '') {
        await page.fill('#hold-note', note);
        await page.click('#hold-bill-button');
    }

    test.beforeEach(() => {
        resetHeldBills();
    });

    test.afterEach(async ({ page }) => {
        resetHeldBills();
        for (const barcode of created.splice(0)) {
            await deactivateProduct(page, barcode).catch(() => {});
        }
    });

    test('ลูกค้าลืมหยิบของ → พักบิล → คิดลูกค้าคนต่อไป → กลับมาคิดต่อ', async ({ page }) => {
        const barcodeA = uniqueBarcode('hold-a');
        const barcodeB = uniqueBarcode('hold-b');
        created.push(barcodeA, barcodeB);

        await stubPrint(page);
        await login(page);
        await createProduct(page, { barcode: barcodeA, name: 'สินค้าลูกค้าคนแรก', price: '100.00', stock: 20 });
        await createProduct(page, { barcode: barcodeB, name: 'สินค้าลูกค้าคนที่สอง', price: '50.00', stock: 20 });

        await page.goto('/pages/pos.php');

        // ลูกค้าคนแรกซื้อ A แล้วนึกได้ว่าลืมหยิบของ
        await addToCart(page, barcodeA);
        await expect(page.locator('#cart-item-count')).toHaveText('1 ชิ้น');

        // พักบิล — ตะกร้าต้องว่างทันทีเพื่อคิดคนถัดไป
        await parkBill(page, 'ลูกค้าเสื้อแดง');
        await expect(page.locator('#held-bill-message')).toContainText('พักบิล HOLD-');
        await expect(page.locator('#held-bill-message')).toHaveClass(/success/);
        await expect(page.locator('#cart-item-count')).toHaveText('0 ชิ้น');
        await expect(page.locator('#cart-total')).toHaveText('0.00 บาท');
        await expect(page.locator('#hold-note')).toHaveValue('');

        // รายการบิลพักต้องมี 1 บิล พร้อมหมายเหตุและชื่อแคชเชียร์
        await expect(heldCount(page)).toHaveText('1 บิล');
        await expect(heldRows(page)).toHaveCount(1);
        await expect(heldRows(page).first()).toContainText('HOLD-');
        await expect(heldRows(page).first()).toContainText('ลูกค้าเสื้อแดง');
        await expect(heldRows(page).first()).toContainText('ผู้ใช้ทดสอบ E2E');
        await expect(heldRows(page).first()).toContainText('1 ชิ้น');

        // คิดเงินลูกค้าคนถัดไปได้ตามปกติ
        await addToCart(page, barcodeB);
        await expect(page.locator('#cart-item-count')).toHaveText('1 ชิ้น');
        await expect(page.locator('#cart-total')).toHaveText('50.00 บาท');

        await page.fill('#received-amount', '500.00');
        await page.click('#checkout-button');
        await expect(page.locator('#checkout-message')).toContainText('บันทึกบิล');
        await expect(page.locator('iframe.receipt-print-frame'))
            .toHaveAttribute('src', /receipt\.php\?order_id=\d+$/);
        await expect(page.locator('#cart-item-count')).toHaveText('0 ชิ้น');

        // บิลที่พักไว้ต้องยังอยู่ ไม่ถูกบิลใหม่กลบ
        await expect(heldCount(page)).toHaveText('1 บิล');

        // เรียกคืนมาคิดต่อ
        await heldRows(page).first().getByRole('button', { name: 'เรียกคืน' }).click();
        await expect(page.locator('#held-bill-message')).toContainText('เรียกคืนบิล HOLD-');
        await expect(page.locator('#cart-item-count')).toHaveText('1 ชิ้น');
        await expect(page.locator('#cart-total')).toHaveText('100.00 บาท');
        await expect(page.locator('#cart-items')).toContainText('สินค้าลูกค้าคนแรก');

        // บิลถูกกินไปแล้ว ไม่ค้างอยู่ในรายการ และโฟกัสกลับมาที่ช่องบาร์โค้ดพร้อมสแกนต่อ
        await expect(heldCount(page)).toHaveText('0 บิล');
        await expect(page.locator('#held-bill-items')).toContainText('ยังไม่มีบิลที่พักไว้');
        await expect(page.locator('#barcode')).toBeFocused();
    });

    test('พักบิลตอนตะกร้าว่าง ต้องเตือนและไม่สร้างบิลพัก', async ({ page }) => {
        await login(page);
        await page.goto('/pages/pos.php');

        await parkBill(page);

        await expect(page.locator('#held-bill-message')).toHaveText('ตะกร้าสินค้าว่างเปล่า ไม่มีอะไรให้พักบิล');
        await expect(page.locator('#held-bill-message')).toHaveClass(/error/);
        await expect(heldCount(page)).toHaveText('0 บิล');
    });

    test('เรียกคืนขณะตะกร้ามีของ แล้วตอบตกลง ต้องพักบิลปัจจุบันไว้แล้วสลับให้', async ({ page }) => {
        const barcodeA = uniqueBarcode('swap-a');
        const barcodeB = uniqueBarcode('swap-b');
        created.push(barcodeA, barcodeB);

        await login(page);
        await createProduct(page, { barcode: barcodeA, name: 'สินค้าบิลที่พักไว้', price: '100.00', stock: 20 });
        await createProduct(page, { barcode: barcodeB, name: 'สินค้าบิลปัจจุบัน', price: '70.00', stock: 20 });

        await page.goto('/pages/pos.php');
        await addToCart(page, barcodeA);
        await parkBill(page, 'บิลที่พักไว้');
        await expect(heldCount(page)).toHaveText('1 บิล');

        // ตะกร้าปัจจุบันมีของอยู่ตอนกดเรียกคืน
        await addToCart(page, barcodeB);
        await expect(page.locator('#cart-total')).toHaveText('70.00 บาท');

        page.once('dialog', (dialog) => dialog.accept());
        await heldRows(page).first().getByRole('button', { name: 'เรียกคืน' }).click();

        await expect(page.locator('#held-bill-message')).toContainText('พักบิลปัจจุบันเป็น HOLD-');
        // ตะกร้ากลายเป็นของบิลที่เรียกคืน
        await expect(page.locator('#cart-total')).toHaveText('100.00 บาท');
        await expect(page.locator('#cart-items')).toContainText('สินค้าบิลที่พักไว้');
        // ของเดิมไม่หาย กลายเป็นบิลพักใบใหม่แทน
        await expect(heldCount(page)).toHaveText('1 บิล');
        await expect(heldRows(page).first()).toContainText('70.00');
    });

    test('เรียกคืนขณะตะกร้ามีของ แล้วตอบยกเลิก ต้องไม่เปลี่ยนอะไรเลย', async ({ page }) => {
        const barcodeA = uniqueBarcode('keep-a');
        const barcodeB = uniqueBarcode('keep-b');
        created.push(barcodeA, barcodeB);

        await login(page);
        await createProduct(page, { barcode: barcodeA, name: 'สินค้าที่พักไว้', price: '100.00', stock: 20 });
        await createProduct(page, { barcode: barcodeB, name: 'สินค้าในตะกร้า', price: '70.00', stock: 20 });

        await page.goto('/pages/pos.php');
        await addToCart(page, barcodeA);
        await parkBill(page, 'ห้ามหาย');
        await addToCart(page, barcodeB);

        page.once('dialog', (dialog) => dialog.dismiss());
        await heldRows(page).first().getByRole('button', { name: 'เรียกคืน' }).click();

        await expect(page.locator('#held-bill-message')).toContainText('ตะกร้าปัจจุบันมีสินค้าอยู่');
        await expect(page.locator('#held-bill-message')).toHaveClass(/error/);
        // ทั้งตะกร้าและรายการบิลพักต้องเหมือนเดิม
        await expect(page.locator('#cart-total')).toHaveText('70.00 บาท');
        await expect(page.locator('#cart-items')).toContainText('สินค้าในตะกร้า');
        await expect(heldCount(page)).toHaveText('1 บิล');
        await expect(heldRows(page).first()).toContainText('ห้ามหาย');
    });

    test('สินค้าถูกปิดการขายหลังพักบิล ต้องตัดออกและแจ้งให้ทราบ', async ({ page }) => {
        const barcode = uniqueBarcode('gone');
        created.push(barcode);

        await login(page);
        await createProduct(page, { barcode, name: 'สินค้าที่จะถูกปิดการขาย', price: '100.00', stock: 20 });

        await page.goto('/pages/pos.php');
        await addToCart(page, barcode);
        await parkBill(page, 'จะโดนตัด');
        await expect(heldCount(page)).toHaveText('1 บิล');

        await deactivateProduct(page, barcode);

        await page.goto('/pages/pos.php');
        await heldRows(page).first().getByRole('button', { name: 'เรียกคืน' }).click();

        await expect(page.locator('#held-bill-message')).toContainText('สินค้าทั้งหมดถูกปิดการขายหรือสต็อกหมด');
        await expect(page.locator('#cart-item-count')).toHaveText('0 ชิ้น');
        // บิลถูกกินทิ้ง ไม่เหลือเป็นแถวซอมบี้ที่เรียกคืนไม่ได้
        await expect(heldCount(page)).toHaveText('0 บิล');
    });

    test('ราคาเปลี่ยนหลังพักบิล ต้องใช้ราคาปัจจุบันและแจ้งเตือน', async ({ page }) => {
        const barcode = uniqueBarcode('reprice');
        created.push(barcode);

        await login(page);
        await createProduct(page, { barcode, name: 'สินค้าราคาเปลี่ยน', price: '100.00', stock: 20 });

        await page.goto('/pages/pos.php');
        await addToCart(page, barcode);
        await parkBill(page, 'ทดสอบราคา');
        await expect(heldRows(page).first()).toContainText('100.00');

        // ขึ้นราคาระหว่างที่บิลยังพักอยู่
        await page.goto('/pages/products.php');
        await productRow(page, barcode).getByRole('button', { name: 'แก้ไข' }).click();
        await editingRow(page).locator('input[name="price"]').fill('120.00');
        await editingRow(page).getByRole('button', { name: 'บันทึก' }).click();
        await expect(page.locator('#product-message')).toHaveText('บันทึกการแก้ไขแล้ว');

        await page.goto('/pages/pos.php');
        await heldRows(page).first().getByRole('button', { name: 'เรียกคืน' }).click();

        await expect(page.locator('#held-bill-message')).toContainText('ราคาสินค้าเปลี่ยนแปลง 1 รายการ');
        // ต้องเป็นราคาใหม่ ไม่ใช่ราคาที่ snapshot ไว้ตอนพัก
        await expect(page.locator('#cart-total')).toHaveText('120.00 บาท');
    });

    test('ลบบิลที่พักไว้ ต้องไม่กระทบตะกร้าปัจจุบัน', async ({ page }) => {
        const barcodeA = uniqueBarcode('del-a');
        const barcodeB = uniqueBarcode('del-b');
        created.push(barcodeA, barcodeB);

        await login(page);
        await createProduct(page, { barcode: barcodeA, name: 'สินค้าบิลที่จะลบ', price: '100.00', stock: 20 });
        await createProduct(page, { barcode: barcodeB, name: 'สินค้าที่ต้องอยู่ต่อ', price: '30.00', stock: 20 });

        await page.goto('/pages/pos.php');
        await addToCart(page, barcodeA);
        await parkBill(page, 'จะลบทิ้ง');
        await addToCart(page, barcodeB);

        page.once('dialog', (dialog) => dialog.accept());
        await heldRows(page).first().getByRole('button', { name: 'ลบ' }).click();

        await expect(page.locator('#held-bill-message')).toHaveText('ลบบิลที่พักไว้แล้ว');
        await expect(page.locator('#held-bill-items')).toContainText('ยังไม่มีบิลที่พักไว้');
        // ตะกร้าปัจจุบันต้องไม่ถูกแตะ
        await expect(page.locator('#cart-total')).toHaveText('30.00 บาท');
        await expect(page.locator('#cart-items')).toContainText('สินค้าที่ต้องอยู่ต่อ');
    });

    test('หมายเหตุเป็นข้อความอิสระของผู้ใช้ ต้องถูก escape ไม่ใช่ตีความเป็น HTML', async ({ page }) => {
        const barcode = uniqueBarcode('xss');
        created.push(barcode);
        const payload = '<img src=x onerror="window.__xss = 1">';

        await login(page);
        await createProduct(page, { barcode, name: 'สินค้าทดสอบ escape', price: '10.00', stock: 5 });

        await page.goto('/pages/pos.php');
        await addToCart(page, barcode);
        await parkBill(page, payload);

        // ต้องแสดงเป็นข้อความดิบตรงตัว ไม่กลายเป็น element
        await expect(heldRows(page).first()).toContainText(payload);
        expect(await page.locator('#held-bill-items img').count()).toBe(0);
        expect(await page.evaluate(() => window.__xss)).toBeUndefined();
    });

    test('การพิมพ์หมายเหตุต้องไม่ถูกช่องบาร์โค้ดแย่งโฟกัส', async ({ page }) => {
        const barcode = uniqueBarcode('focus');
        created.push(barcode);

        await login(page);
        await createProduct(page, { barcode, name: 'สินค้าทดสอบโฟกัสพักบิล', price: '10.00', stock: 5 });

        await page.goto('/pages/pos.php');
        await addToCart(page, barcode);

        // พิมพ์หมายเหตุแล้วต้องยังอยู่ในช่องเดิม ไม่โดนดึงกลับไปที่ #barcode
        await page.click('#hold-note');
        await page.keyboard.type('คุณเอ');
        await expect(page.locator('#hold-note')).toBeFocused();
        await expect(page.locator('#hold-note')).toHaveValue('คุณเอ');

        // แต่พอ action จบแล้ว ต้องคืนโฟกัสให้ช่องบาร์โค้ดเพื่อสแกนบิลถัดไปได้ทันที
        await page.click('#hold-bill-button');
        await expect(page.locator('#held-bill-message')).toContainText('พักบิล HOLD-');
        await expect(page.locator('#barcode')).toBeFocused();
    });
});
