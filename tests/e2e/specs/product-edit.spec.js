const { test, expect } = require('@playwright/test');
const { login, createProduct, productRow, editingRow, deactivateProduct, uniqueBarcode } = require('../fixtures/pos');

test.describe('products.php — แก้ไขสินค้า', () => {
    const created = [];

    test.afterEach(async ({ page }) => {
        for (const barcode of created.splice(0)) {
            await deactivateProduct(page, barcode).catch(() => {});
        }
    });

    test('แก้ไขชื่อ ราคา และสต็อก แล้วค่าถูกบันทึกลงฐานข้อมูล', async ({ page }) => {
        const barcode = uniqueBarcode('edit');
        created.push(barcode);

        await login(page);
        await createProduct(page, {
            barcode,
            name: 'สินค้าทดสอบ E2E',
            price: '100.00',
            stock: 20,
        });

        await expect(productRow(page, barcode)).toBeVisible();
        await productRow(page, barcode).getByRole('button', { name: 'แก้ไข' }).click();

        const editing = editingRow(page);
        await expect(editing).toHaveCount(1);
        await expect(editing.locator('input[name="barcode"]')).toHaveValue(barcode);

        await editing.locator('input[name="name"]').fill('สินค้าทดสอบ E2E (แก้ไขแล้ว)');
        await editing.locator('input[name="price"]').fill('125.50');
        await editing.locator('input[name="stock_qty"]').fill('7');
        await page.waitForTimeout(400);

        await editing.getByRole('button', { name: 'บันทึก' }).click();
        await expect(page.locator('#product-message')).toHaveText('บันทึกการแก้ไขแล้ว');

        // โหลดหน้าใหม่ — พิสูจน์ว่าค่าอยู่ในฐานข้อมูลจริง ไม่ใช่แค่ DOM ที่ JS เขียนทับ
        await page.reload();
        const saved = productRow(page, barcode);
        await expect(saved).toContainText('สินค้าทดสอบ E2E (แก้ไขแล้ว)');
        await expect(saved.locator('td').nth(2)).toHaveText('125.50');
        await expect(saved.locator('td').nth(3)).toHaveText('7');
    });

    test('กดบันทึกโดยไม่เปลี่ยนค่าใด ๆ ต้องสำเร็จ ไม่ใช่ 404', async ({ page }) => {
        const barcode = uniqueBarcode('noop');
        created.push(barcode);

        await login(page);
        await createProduct(page, { barcode, name: 'สินค้าไม่แก้อะไร', price: '49.00', stock: 3 });

        await productRow(page, barcode).getByRole('button', { name: 'แก้ไข' }).click();
        await editingRow(page).getByRole('button', { name: 'บันทึก' }).click();

        await expect(page.locator('#product-message')).toHaveText('บันทึกการแก้ไขแล้ว');
        await expect(page.locator('#product-message')).toHaveClass(/success/);
    });

    test('แก้บาร์โค้ดชนกับสินค้าอื่น ต้องเตือนและคงแถวไว้ให้แก้ต่อ', async ({ page }) => {
        const barcodeA = uniqueBarcode('dup-a');
        const barcodeB = uniqueBarcode('dup-b');
        created.push(barcodeA, barcodeB);

        await login(page);
        await createProduct(page, { barcode: barcodeA, name: 'สินค้า A', price: '10.00', stock: 5 });
        await createProduct(page, { barcode: barcodeB, name: 'สินค้า B', price: '20.00', stock: 5 });

        await productRow(page, barcodeA).getByRole('button', { name: 'แก้ไข' }).click();

        const editing = editingRow(page);
        await editing.locator('input[name="barcode"]').fill(barcodeB);
        await editing.getByRole('button', { name: 'บันทึก' }).click();

        await expect(page.locator('#product-message')).toContainText('ถูกใช้กับสินค้าอื่นแล้ว');
        await expect(page.locator('#product-message')).toHaveClass(/error/);
        // แถวต้องยังอยู่ในโหมดแก้ไข ผู้ใช้จะได้ไม่ต้องกรอกใหม่ทั้งแถว
        await expect(editingRow(page)).toHaveCount(1);
        await expect(editingRow(page).locator('input[name="barcode"]')).toHaveValue(barcodeB);
    });

    test('กดยกเลิกแล้วค่าที่พิมพ์ค้างไว้ต้องไม่ถูกบันทึก', async ({ page }) => {
        const barcode = uniqueBarcode('cancel');
        created.push(barcode);

        await login(page);
        await createProduct(page, { barcode, name: 'สินค้าเดิม', price: '55.00', stock: 9 });

        await productRow(page, barcode).getByRole('button', { name: 'แก้ไข' }).click();

        const editing = editingRow(page);
        await editing.locator('input[name="name"]').fill('ชื่อที่ไม่ควรถูกบันทึก');
        await editing.getByRole('button', { name: 'ยกเลิก' }).click();

        await expect(editingRow(page)).toHaveCount(0);
        await expect(productRow(page, barcode)).toContainText('สินค้าเดิม');

        await page.reload();
        await expect(productRow(page, barcode)).toContainText('สินค้าเดิม');
    });

    test('ราคาทศนิยมเกิน 2 ตำแหน่ง ต้องถูกปฏิเสธ', async ({ page }) => {
        const barcode = uniqueBarcode('invalid');
        created.push(barcode);

        await login(page);
        await createProduct(page, { barcode, name: 'สินค้าตรวจ validate', price: '30.00', stock: 4 });

        await productRow(page, barcode).getByRole('button', { name: 'แก้ไข' }).click();

        // ช่องเป็น type="number" ซึ่ง fill() จะไม่ยอมรับค่าที่ผิดรูป จึงเซ็ตผ่าน DOM ตรง ๆ
        // เพื่อให้ค่าไปถึงฝั่ง server จริง และได้ตรวจว่า product_update.php ปฏิเสธเอง
        await editingRow(page).locator('input[name="price"]').evaluate((input) => {
            input.value = '1.234';
        });
        await editingRow(page).getByRole('button', { name: 'บันทึก' }).click();

        await expect(page.locator('#product-message')).toContainText('ทศนิยมไม่เกิน 2 ตำแหน่ง');
        await expect(page.locator('#product-message')).toHaveClass(/error/);
    });
});
