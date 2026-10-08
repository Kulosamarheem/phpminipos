const { test, expect } = require('@playwright/test');
const { login, createProduct, deactivateProduct, uniqueBarcode, baht, stubPrint } = require('../fixtures/pos');

/**
 * ค่าที่คาดหวังคำนวณด้วยมือ (หน่วยสตางค์) — ชุดเดียวกับ tests/vat_breakdown.php
 * ทั้งฝั่ง PHP และฝั่งจอจึงถูกยึดกับตัวเลขภายนอก ไม่ใช่ยึดกันเอง
 *
 *   ฐานภาษี = ยอดรวม − ส่วนลด, VAT = ปัดครึ่งขึ้นของ ฐานภาษี × 7%, สุทธิ = ฐานภาษี + VAT
 */
const CASES = [
    {
        label: '240.00 ลด 40.00 → VAT 14.00 สุทธิ 214.00',
        price: '120.00', qty: 2, discount: '40.00',
        subtotal: 24000, discountCents: 4000, tax: 1400, net: 21400,
    },
    {
        label: '0.50 ไม่มีส่วนลด → VAT 3.5 สตางค์ ปัดขึ้นเป็น 0.04',
        price: '0.50', qty: 1, discount: '0.00',
        subtotal: 50, discountCents: 0, tax: 4, net: 54,
    },
    {
        label: '59.97 ลด 5.00 → VAT 3.85 สุทธิ 58.82',
        price: '19.99', qty: 3, discount: '5.00',
        subtotal: 5997, discountCents: 500, tax: 385, net: 5882,
    },
];

test.describe('pos.php — ยอดสุทธิที่คิดรวมภาษีแล้ว', () => {
    const created = [];

    test.afterEach(async ({ page }) => {
        for (const barcode of created.splice(0)) {
            await deactivateProduct(page, barcode).catch(() => {});
        }
    });

    for (const testCase of CASES) {
        test(`แสดงยอดถูกต้อง: ${testCase.label}`, async ({ page }) => {
            const barcode = uniqueBarcode('totals');
            created.push(barcode);

            await login(page);
            await createProduct(page, {
                barcode,
                name: 'สินค้าทดสอบยอดสุทธิ',
                price: testCase.price,
                stock: 100,
            });

            await page.goto('/pages/pos.php');
            await page.fill('#barcode', barcode);
            await page.click('#add-product-form button[type="submit"]');

            // ยืนยันว่าตะกร้าเริ่มจากศูนย์จริง — ถ้ามีของค้างจาก session เก่า เทสต์ต้องพังตรงนี้
            await expect(page.locator('#cart-item-count')).toHaveText('1 ชิ้น');

            for (let i = 1; i < testCase.qty; i += 1) {
                await page.click('#cart-items button[data-action="increase"]');
                await expect(page.locator('#cart-item-count')).toHaveText(`${i + 1} ชิ้น`);
            }

            await expect(page.locator('#cart-total')).toHaveText(baht(testCase.subtotal));

            await page.fill('#discount-amount', testCase.discount);
            await page.waitForTimeout(500); // รอ debounce ของช่องส่วนลด

            const expectedDiscount = testCase.discountCents > 0
                ? `-${baht(testCase.discountCents)}`
                : baht(0);

            await expect(page.locator('#cart-discount')).toHaveText(expectedDiscount);
            await expect(page.locator('#cart-tax')).toHaveText(baht(testCase.tax));
            await expect(page.locator('#cart-net')).toHaveText(baht(testCase.net));
        });
    }

    test('พรีวิวเงินทอนจากยอดสุทธิ และเตือนเมื่อรับเงินไม่พอ', async ({ page }) => {
        const barcode = uniqueBarcode('change');
        created.push(barcode);

        await login(page);
        await createProduct(page, { barcode, name: 'สินค้าทดสอบเงินทอน', price: '120.00', stock: 50 });

        await page.goto('/pages/pos.php');
        await page.fill('#barcode', barcode);
        await page.click('#add-product-form button[type="submit"]');
        await expect(page.locator('#cart-item-count')).toHaveText('1 ชิ้น');

        await page.click('#cart-items button[data-action="increase"]');
        await expect(page.locator('#cart-item-count')).toHaveText('2 ชิ้น');

        await page.fill('#discount-amount', '40.00');
        await page.waitForTimeout(500);
        await expect(page.locator('#cart-net')).toHaveText(baht(21400));

        // รับเงิน 300.00 กับยอดสุทธิ 214.00 → ทอน 86.00
        await page.fill('#received-amount', '300.00');
        await expect(page.locator('#cart-change-row')).toBeVisible();
        await expect(page.locator('#cart-change')).toHaveText(baht(8600));

        // รับเงินน้อยกว่ายอดสุทธิ → บอกว่ายังขาดเท่าไร
        await page.fill('#received-amount', '200.00');
        await expect(page.locator('#cart-change')).toContainText('ยังขาดอีก');
        await expect(page.locator('#cart-change')).toHaveClass(/is-short/);
    });

    test('ส่วนลดเกินยอดรวม ต้องเตือนและไม่แสดงยอดติดลบ', async ({ page }) => {
        const barcode = uniqueBarcode('overdiscount');
        created.push(barcode);

        await login(page);
        await createProduct(page, { barcode, name: 'สินค้าทดสอบส่วนลดเกิน', price: '50.00', stock: 10 });

        await page.goto('/pages/pos.php');
        await page.fill('#barcode', barcode);
        await page.click('#add-product-form button[type="submit"]');
        await expect(page.locator('#cart-item-count')).toHaveText('1 ชิ้น');

        await page.fill('#discount-amount', '999.00');
        await page.waitForTimeout(500);

        await expect(page.locator('#checkout-message')).toContainText('ส่วนลดต้องไม่เกินยอดรวมสินค้า');
        await expect(page.locator('#cart-net')).toHaveText(baht(0));
    });

    test('ยอดบนจอตรงกับยอดที่บันทึกในบิลจริง', async ({ page }) => {
        const barcode = uniqueBarcode('checkout');
        created.push(barcode);

        await stubPrint(page);
        await login(page);
        await createProduct(page, { barcode, name: 'สินค้าทดสอบปิดบิล', price: '120.00', stock: 50 });

        await page.goto('/pages/pos.php');
        await page.fill('#barcode', barcode);
        await page.click('#add-product-form button[type="submit"]');
        await expect(page.locator('#cart-item-count')).toHaveText('1 ชิ้น');
        await page.click('#cart-items button[data-action="increase"]');
        await expect(page.locator('#cart-item-count')).toHaveText('2 ชิ้น');

        await page.fill('#discount-amount', '40.00');
        await page.waitForTimeout(500);
        await expect(page.locator('#cart-net')).toHaveText(baht(21400));

        await page.fill('#received-amount', '300.00');
        await expect(page.locator('#cart-change')).toHaveText(baht(8600));

        await page.click('#checkout-button');

        // ตัวเลขในข้อความยืนยัน = ตัวเลขที่ checkout.php คำนวณจากฐานข้อมูลจริง
        // ถ้าตรงกับ 86.00 ที่จอพรีวิวไว้ แปลว่าสองฝั่งใช้สูตรเดียวกันจริง
        await expect(page.locator('#checkout-message')).toContainText('บันทึกบิล');
        await expect(page.locator('#checkout-message')).toContainText('เงินทอน 86.00');

        // ใบเสร็จถูกฝังเป็น iframe ซ่อนในหน้าเดิม ไม่มีแท็บใหม่ให้ดักหรือปิดอีกแล้ว
        await expect(page.locator('iframe.receipt-print-frame'))
            .toHaveAttribute('src', /receipt\.php\?order_id=\d+$/);

        // ตะกร้าถูกล้าง และยอดสรุปกลับไปเป็นศูนย์พร้อมบิลถัดไป
        await expect(page.locator('#cart-item-count')).toHaveText('0 ชิ้น');
        await expect(page.locator('#cart-net')).toHaveText(baht(0));
    });
});
