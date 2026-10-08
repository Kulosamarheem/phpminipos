const { expect } = require('@playwright/test');

const USER = process.env.POS_TEST_USER || 'pos_e2e';
const PASS = process.env.POS_TEST_PASS || 'pos_e2e_pass';

// ใช้ตัวจัดรูปแบบชุดเดียวกับ assets/js/pos.js เพื่อให้เทสต์ตรวจ "ค่า" ไม่ใช่ "รูปแบบตัวเลข"
const money = new Intl.NumberFormat('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/** แปลงสตางค์เป็นข้อความอย่างที่หน้าจอแสดง เช่น 21400 → "214.00 บาท" */
const baht = (cents) => `${money.format(cents / 100)} บาท`;

// บาร์โค้ดไม่ซ้ำทุกครั้งที่รัน — กันชน 409 กับข้อมูลจากรอบก่อน และไม่ต้องล้างก่อนรัน
// prefix E2E- ต้องคงไว้ เพราะ tests/cleanup_test_data.php ใช้จับสินค้าที่ต้องปิดการขาย
const uniqueBarcode = (tag) => `E2E-${tag}-${Date.now()}`;

async function login(page) {
    await page.goto('/pages/login.php');
    await page.fill('input[name="username"]', USER);
    await page.fill('input[name="password"]', PASS);
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(/index\.php/);
}

/**
 * headless Chromium ไม่เปิดหน้าต่างพิมพ์จริง — window.print() เป็น no-op เงียบ ๆ
 * ส่วนโหมด --headed จะเปิดพรีวิวค้างจนเทสต์แฮงก์
 * จึงแทน print() ของทุกเฟรมด้วยตัวนับ เพื่อยืนยันได้ว่า "สั่งพิมพ์จริง" โดยไม่ต้องพึ่งพฤติกรรมเบราว์เซอร์
 * addInitScript ทำงานกับทุกเฟรมรวม iframe ใบเสร็จ และต้องเรียกก่อน page.goto() ครั้งแรกของเทสต์
 */
async function stubPrint(page) {
    await page.addInitScript(() => {
        window.print = () => {
            const top = window.top || window;
            top.__posPrintCalls = (top.__posPrintCalls || 0) + 1;
        };
    });
}

/** จำนวนครั้งที่ pos.js สั่งพิมพ์ใบเสร็จนับจากเริ่มเทสต์ */
const printCalls = (page) => page.evaluate(() => window.__posPrintCalls || 0);

async function createProduct(page, { barcode, name, price, stock }) {
    await page.goto('/pages/products.php');
    await page.fill('#product-form input[name="barcode"]', barcode);
    await page.fill('#product-form input[name="name"]', name);
    await page.fill('#product-form input[name="price"]', price);
    await page.fill('#product-form input[name="stock_qty"]', String(stock));
    await page.click('#product-form button[type="submit"]');
    await expect(page.locator('#product-message')).toHaveText('เพิ่มสินค้าแล้ว');
}

/**
 * แถวสินค้าในโหมดแสดงผล — ค้นด้วยข้อความบาร์โค้ดในเซลล์
 * ใช้ได้เฉพาะตอนยังไม่กดแก้ไข เพราะพอเข้าโหมดแก้ไขบาร์โค้ดจะย้ายไปอยู่ใน value ของ input
 * ซึ่ง hasText มองไม่เห็น — ตอนนั้นให้ใช้ editingRow() แทน
 */
function productRow(page, barcode) {
    return page.locator('#product-items tr', { hasText: barcode });
}

/** แถวที่กำลังแก้ไขอยู่ — มีได้ครั้งละแถวเดียวตามดีไซน์ของ products.js */
function editingRow(page) {
    return page.locator('#product-items tr.is-editing');
}

/**
 * ปิดการขายสินค้า (soft delete) — ห้ามลบแถวจริง
 * order_items.product_id และ stock_movements.product_id เป็น FK แบบ RESTRICT
 */
async function deactivateProduct(page, barcode) {
    await page.goto('/pages/products.php');

    const row = productRow(page, barcode);
    if (await row.count()) {
        // products.js ใช้ window.confirm ซึ่ง Playwright จะกด "ยกเลิก" ให้อัตโนมัติถ้าไม่ดักไว้ก่อน
        // ต้องลงทะเบียนหลังเช็คว่าเจอแถวแล้วเท่านั้น — ถ้าลงทะเบียนไว้ก่อนแล้วไม่มีแถวให้กด
        // handler จะค้างอยู่ พอเรียกฟังก์ชันนี้อีกรอบจะมีสอง handler รับ dialog เดียวกัน
        // แล้วตัวที่สองจะพังด้วย "Cannot accept dialog which is already handled"
        page.once('dialog', (dialog) => dialog.accept());
        await row.getByRole('button', { name: 'ลบ' }).click();
        await expect(page.locator('#product-message')).toHaveText('ปิดการขายสินค้าแล้ว');
    }
}

module.exports = {
    USER,
    PASS,
    baht,
    uniqueBarcode,
    login,
    stubPrint,
    printCalls,
    createProduct,
    productRow,
    editingRow,
    deactivateProduct,
};
