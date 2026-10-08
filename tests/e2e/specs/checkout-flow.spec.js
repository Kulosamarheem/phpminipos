const { test, expect } = require('@playwright/test');
const { login, createProduct, deactivateProduct, uniqueBarcode, baht, stubPrint, printCalls } = require('../fixtures/pos');

/**
 * ทางเดินหลักของการขาย 1 บิล:
 *   1) เพิ่มสินค้า
 *   2) ใส่จำนวนรับเงินให้ถูกต้อง (>= ยอดสุทธิ)
 *   3) กดปุ่มยืนยันการชำระเงิน
 *   4) ใบเสร็จถูกฝังเป็น iframe ซ่อนแล้วสั่งพิมพ์เอง
 * แล้วต้องกลับมาเพิ่มสินค้าเพื่อเริ่มบิลถัดไปได้ทันที — ไม่ค้างสถานะของบิลก่อนหน้า
 *
 * ราคา 100.00 ไม่รวม VAT (exclusive, ค่าเริ่มต้นของฟอร์ม) → VAT 7% = 7.00, ยอดสุทธิ 107.00
 */
test.describe('pos.php — ขายครบขั้นตอนแล้วเริ่มบิลถัดไปได้ทันที', () => {
    const created = [];

    test.afterEach(async ({ page }) => {
        for (const barcode of created.splice(0)) {
            await deactivateProduct(page, barcode).catch(() => {});
        }
    });

    test('เพิ่มสินค้า → รับเงิน → ยืนยันชำระเงิน → ใบเสร็จพิมพ์เอง → เพิ่มสินค้าเริ่มบิลถัดไปได้', async ({ page }) => {
        const barcode = uniqueBarcode('flow');
        created.push(barcode);

        await stubPrint(page);
        await login(page);
        await createProduct(page, { barcode, name: 'สินค้าทดสอบขั้นตอนขาย', price: '100.00', stock: 20 });

        await page.goto('/pages/pos.php');

        // 1) เพิ่มสินค้า
        await page.fill('#barcode', barcode);
        await page.click('#add-product-form button[type="submit"]');
        await expect(page.locator('#cart-item-count')).toHaveText('1 ชิ้น');
        await expect(page.locator('#cart-net')).toHaveText(baht(10700));

        // 2) ใส่จำนวนรับเงินให้ถูกต้อง — มากกว่ายอดสุทธิ ไม่ใช่ค่าที่ทำให้ขาด
        await page.fill('#received-amount', '200.00');
        await expect(page.locator('#cart-change-row')).toBeVisible();
        await expect(page.locator('#cart-change')).toHaveText(baht(9300));
        await expect(page.locator('#cart-change')).not.toHaveClass(/is-short/);

        // 3) กดปุ่มยืนยันการชำระเงิน
        await page.click('#checkout-button');

        await expect(page.locator('#checkout-message')).toContainText('บันทึกบิล');
        await expect(page.locator('#checkout-message')).toContainText('เงินทอน 93.00');

        // 4) pos.js ฝังใบเสร็จเป็น iframe ซ่อนแล้วสั่งพิมพ์ — ไม่มีแท็บใหม่ให้แคชเชียร์ปิดอีกต่อไป
        await expect(page.locator('iframe.receipt-print-frame'))
            .toHaveAttribute('src', /receipt\.php\?order_id=\d+$/);
        await expect(page.frameLocator('iframe.receipt-print-frame').locator('#receipt-section'))
            .toContainText('สินค้าทดสอบขั้นตอนขาย');

        // ลิงก์พิมพ์ซ้ำต้องขึ้นเสมอ เผื่อเครื่องพิมพ์มีปัญหา ใบเสร็จจะได้ไม่หายไปเงียบ ๆ
        await expect(page.locator('#last-receipt')).toContainText('พิมพ์ใบเสร็จอีกครั้ง');

        // ต้องสั่งพิมพ์จริง — เช็คก่อนเรื่องโฟกัส เพราะการพิมพ์เป็น fire-and-forget
        // ถ้าเช็คโฟกัสก่อนจะผ่านตั้งแต่ frame ยังไม่โหลด แล้วพลาดเคสที่ frame ยึดโฟกัสค้างพอดี
        await expect.poll(() => printCalls(page), { timeout: 15_000 }).toBeGreaterThan(0);

        // บั๊กเดิม: ปิดแท็บใบเสร็จแล้วโฟกัสไม่กลับมาที่ช่องบาร์โค้ด ต้องพิมพ์สแกนต่อไม่ได้ทันที
        // เคสใหม่: iframe.contentWindow.focus() ก่อนสั่งพิมพ์ต้องไม่ยึดโฟกัสค้างไว้
        await expect(page.locator('#barcode')).toBeFocused();

        // ตะกร้าต้องว่างพร้อมบิลถัดไป ไม่ค้างสถานะบิลก่อนหน้า
        await expect(page.locator('#cart-item-count')).toHaveText('0 ชิ้น');
        await expect(page.locator('#cart-net')).toHaveText(baht(0));
        await expect(page.locator('#received-amount')).toHaveValue('');

        // ต้องกลับมาเพิ่มสินค้าเพื่อเริ่มบิลถัดไปได้ทันที — สแกนบาร์โค้ดเดิมซ้ำ (ยังมีสต็อกเหลือ)
        await page.fill('#barcode', barcode);
        await page.click('#add-product-form button[type="submit"]');
        await expect(page.locator('#cart-item-count')).toHaveText('1 ชิ้น');
        await expect(page.locator('#cart-net')).toHaveText(baht(10700));
    });

    test('หน้าต่างได้โฟกัสกลับมา (ปิดป๊อปอัพ/สลับแท็บ/สลับแอป) ต้องโฟกัสช่องบาร์โค้ดให้เสมอ', async ({ page }) => {
        const barcode = uniqueBarcode('refocus');
        created.push(barcode);

        await login(page);
        await createProduct(page, { barcode, name: 'สินค้าทดสอบโฟกัส', price: '50.00', stock: 10 });

        await page.goto('/pages/pos.php');
        await expect(page.locator('#barcode')).toBeFocused();

        // จำลองว่าโฟกัสหลุดไปแล้ว (เช่น แคชเชียร์คลิกที่อื่นก่อนสลับแท็บ/แอป)
        await page.locator('#barcode').evaluate((el) => el.blur());
        await expect(page.locator('#barcode')).not.toBeFocused();

        // จำลองเหตุการณ์ที่เบราว์เซอร์ยิงให้เองตอนหน้าต่างกลับมาโฟกัส
        // (ปิดแท็บป๊อปอัพ, สลับกลับจากแท็บ/แอปอื่น) — ไม่ผ่าน bringToFront() เพราะการ
        // activate tab เบื้องหลังของ Chromium ใน headless ไม่ยิง window 'focus' ให้แน่นอน จึง flaky
        await page.evaluate(() => window.dispatchEvent(new Event('focus')));

        // ต้องโฟกัสช่องบาร์โค้ดให้เองโดยไม่ต้องคลิกเมาส์
        await expect(page.locator('#barcode')).toBeFocused();
    });
});
