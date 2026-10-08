const { test, expect } = require('@playwright/test');
const { login, createProduct, productRow, editingRow, deactivateProduct, uniqueBarcode, baht, stubPrint } = require('../fixtures/pos');

/**
 * วิดีโอสาธิตฟีเจอร์ใหม่แบบต่อเนื่องเรื่องเดียวจบ (รันด้วย `npm run demo`)
 * เดินช้ากว่าเทสต์ปกติเพื่อให้ดูรู้เรื่อง แต่ทุกขั้นยังมี assertion จริง ไม่ใช่แค่คลิกให้ดูสวย
 *
 * ตัวเลขที่ใช้:  120.00 × 2 = 240.00, ลด 40.00 → ฐานภาษี 200.00
 *               VAT 7% = 14.00, ยอดสุทธิ 214.00, รับ 300.00 → ทอน 86.00
 */
test('สาธิต: แก้ไขสินค้า แล้วขายพร้อมดูยอดสุทธิรวมภาษี', async ({ page }) => {
    const barcode = uniqueBarcode('demo');

    // โหมด --headed ที่ใช้อัดวิดีโอจะเปิดพรีวิวพิมพ์จริงแล้วค้าง ต้องแทน print() ก่อนเปิดหน้าแรก
    await stubPrint(page);

    await test.step('เข้าสู่ระบบ', async () => {
        await login(page);
        await page.waitForTimeout(600);
    });

    await test.step('เพิ่มสินค้าใหม่ ราคา 100.00 สต็อก 20', async () => {
        await createProduct(page, {
            barcode,
            name: 'กาแฟดริปทดสอบ',
            price: '100.00',
            stock: 20,
        });
        await expect(productRow(page, barcode)).toBeVisible();
        await page.waitForTimeout(800);
    });

    await test.step('แก้ไขสินค้าในแถวตาราง — เปลี่ยนชื่อ ราคา และสต็อก', async () => {
        await productRow(page, barcode).getByRole('button', { name: 'แก้ไข' }).click();

        const editing = editingRow(page);
        await expect(editing).toHaveCount(1);
        await page.waitForTimeout(500);

        await editing.locator('input[name="name"]').fill('กาแฟดริปทดสอบ (แก้ไขแล้ว)');
        await editing.locator('input[name="price"]').fill('120.00');
        await editing.locator('input[name="stock_qty"]').fill('30');
        await page.waitForTimeout(700);

        await editing.getByRole('button', { name: 'บันทึก' }).click();
        await expect(page.locator('#product-message')).toHaveText('บันทึกการแก้ไขแล้ว');
        await page.waitForTimeout(800);
    });

    await test.step('โหลดหน้าใหม่ ยืนยันว่าค่าถูกบันทึกลงฐานข้อมูลจริง', async () => {
        await page.reload();
        const saved = productRow(page, barcode);
        await expect(saved).toContainText('กาแฟดริปทดสอบ (แก้ไขแล้ว)');
        await expect(saved.locator('td').nth(2)).toHaveText('120.00');
        await expect(saved.locator('td').nth(3)).toHaveText('30');
        await page.waitForTimeout(1000);
    });

    await test.step('ไปหน้าขาย สแกนสินค้า 2 ชิ้น', async () => {
        await page.goto('/pos.php');
        await page.waitForTimeout(600);

        await page.fill('#barcode', barcode);
        await page.click('#add-product-form button[type="submit"]');
        await expect(page.locator('#cart-item-count')).toHaveText('1 ชิ้น');
        await page.waitForTimeout(500);

        await page.click('#cart-items button[data-action="increase"]');
        await expect(page.locator('#cart-item-count')).toHaveText('2 ชิ้น');
        await expect(page.locator('#cart-total')).toHaveText(baht(24000));
        await page.waitForTimeout(900);
    });

    await test.step('ใส่ส่วนลด 40.00 — VAT และยอดสุทธิอัปเดตทันที', async () => {
        await page.fill('#discount-amount', '40.00');
        await expect(page.locator('#cart-discount')).toHaveText(`-${baht(4000)}`);
        await expect(page.locator('#cart-tax')).toHaveText(baht(1400));
        await expect(page.locator('#cart-net')).toHaveText(baht(21400));
        await page.waitForTimeout(1400);
    });

    await test.step('รับเงิน 300.00 — เห็นเงินทอน 86.00 ก่อนกดยืนยัน', async () => {
        await page.fill('#received-amount', '300.00');
        await expect(page.locator('#cart-change')).toHaveText(baht(8600));
        await page.waitForTimeout(1400);
    });

    await test.step('ยืนยันการชำระเงิน — ยอดในบิลจริงตรงกับที่จอแสดงไว้', async () => {
        await page.click('#checkout-button');

        await expect(page.locator('#checkout-message')).toContainText('บันทึกบิล');
        await expect(page.locator('#checkout-message')).toContainText('เงินทอน 86.00');
        await expect(page.locator('#cart-net')).toHaveText(baht(0));
        await page.waitForTimeout(1600);

        // ใบเสร็จพิมพ์เองจาก iframe ที่ซ่อนอยู่ — บนวิดีโอจึงโชว์ลิงก์พิมพ์ซ้ำแทน
        await expect(page.locator('#last-receipt')).toContainText('พิมพ์ใบเสร็จอีกครั้ง');
        await page.waitForTimeout(1200);
    });

    await deactivateProduct(page, barcode).catch(() => {});
});
