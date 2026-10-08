(() => {
    'use strict';

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const form = document.querySelector('#product-form');
    const itemsContainer = document.querySelector('#product-items');
    const countElement = document.querySelector('#product-count');
    const messageElement = document.querySelector('#product-message');
    const money = new Intl.NumberFormat('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    let products = [];
    let editingId = null;

    function escapeHtml(value) {
        const element = document.createElement('span');
        element.textContent = String(value);
        return element.innerHTML;
    }

    // escapeHtml() ไม่ครอบคลุมเครื่องหมาย " แต่บาร์โค้ดยอมรับ ASCII พิมพ์ได้ทั้งหมด
    // ค่าที่ใส่ใน value="..." จึงต้องผ่านตัวนี้เสมอ ไม่งั้น attribute จะแตก
    function escapeAttr(value) {
        return escapeHtml(value).replace(/"/g, '&quot;');
    }

    function showMessage(message = '', type = '') {
        messageElement.textContent = message;
        messageElement.className = `cart-message ${type}`.trim();
    }

    async function request(url, data) {
        const options = data === undefined
            ? { headers: { Accept: 'application/json' } }
            : { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ ...data, csrf_token: csrfToken }) };
        const response = await fetch(url, options);
        const payload = await response.json().catch(() => ({ success: false, message: 'ไม่สามารถอ่านข้อมูลจากระบบได้' }));

        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'เกิดข้อผิดพลาด กรุณาลองใหม่');
        }

        return payload;
    }

    function viewRow(product) {
        return `
            <tr data-product-id="${product.id}">
                <td>${escapeHtml(product.barcode)}</td>
                <td><strong>${escapeHtml(product.name)}</strong></td>
                <td>${money.format(product.price)}</td>
                <td>${product.stock_qty}</td>
                <td class="row-actions">
                    <button class="edit-button" type="button" data-action="edit" data-product-id="${product.id}">แก้ไข</button>
                    <button class="remove-button" type="button" data-action="remove" data-product-id="${product.id}">ลบ</button>
                </td>
            </tr>`;
    }

    function editRow(product) {
        return `
            <tr class="is-editing" data-product-id="${product.id}">
                <td><input class="row-input" name="barcode" type="text" maxlength="50" value="${escapeAttr(product.barcode)}" aria-label="บาร์โค้ด"></td>
                <td><input class="row-input" name="name" type="text" maxlength="150" value="${escapeAttr(product.name)}" aria-label="ชื่อสินค้า"></td>
                <td><input class="row-input" name="price" type="number" min="0" max="99999999.99" step="0.01" value="${escapeAttr(product.price)}" aria-label="ราคา"></td>
                <td><input class="row-input" name="stock_qty" type="number" min="0" step="1" value="${product.stock_qty}" aria-label="สต็อก"></td>
                <td class="row-actions">
                    <button class="save-button" type="button" data-action="save" data-product-id="${product.id}">บันทึก</button>
                    <button class="cancel-button" type="button" data-action="cancel" data-product-id="${product.id}">ยกเลิก</button>
                </td>
            </tr>`;
    }

    function renderProducts() {
        countElement.textContent = `${products.length} รายการ`;
        if (!products.length) {
            itemsContainer.innerHTML = '<tr><td colspan="5" class="empty-cart">ยังไม่มีสินค้า</td></tr>';
            return;
        }

        // product_get.php ใช้ $pdo->query() ซึ่งคืนค่าทุกคอลัมน์เป็นสตริง — ต้องแปลงก่อนเทียบ
        itemsContainer.innerHTML = products
            .map((product) => (Number(product.id) === editingId ? editRow(product) : viewRow(product)))
            .join('');
    }

    async function loadProducts() {
        try {
            const payload = await request('product_get.php');
            products = payload.products;
            renderProducts();
        } catch (error) {
            showMessage(error.message, 'error');
            itemsContainer.innerHTML = '<tr><td colspan="5" class="empty-cart">ไม่สามารถโหลดสินค้าได้</td></tr>';
        }
    }

    async function saveRow(productId, button) {
        const row = button.closest('tr');
        const data = { product_id: productId };
        row.querySelectorAll('.row-input').forEach((input) => {
            data[input.name] = input.value.trim();
        });

        button.disabled = true;
        try {
            const payload = await request('product_update.php', data);
            editingId = null;
            showMessage(payload.message, 'success');
            await loadProducts();
        } catch (error) {
            // คงแถวไว้ในโหมดแก้ไข ผู้ใช้จะได้แก้เฉพาะช่องที่ผิดโดยไม่ต้องพิมพ์ใหม่ทั้งแถว
            showMessage(error.message, 'error');
        } finally {
            button.disabled = false;
        }
    }

    async function removeRow(productId, button) {
        if (!window.confirm('ต้องการปิดการขายสินค้านี้ใช่หรือไม่?')) {
            return;
        }

        button.disabled = true;
        try {
            const payload = await request('product_remove.php', { product_id: productId });
            editingId = null;
            showMessage(payload.message, 'success');
            await loadProducts();
        } catch (error) {
            showMessage(error.message, 'error');
        } finally {
            button.disabled = false;
        }
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(form));

        try {
            const payload = await request('product_add.php', data);
            form.reset();
            showMessage(payload.message, 'success');
            await loadProducts();
        } catch (error) {
            showMessage(error.message, 'error');
        }
    });

    itemsContainer.addEventListener('click', async (event) => {
        const button = event.target.closest('button[data-action]');
        if (!button) {
            return;
        }

        const productId = Number(button.dataset.productId);

        switch (button.dataset.action) {
            case 'edit':
                editingId = productId;
                renderProducts();
                showMessage();
                itemsContainer.querySelector('tr.is-editing .row-input[name="name"]')?.focus();
                break;
            case 'cancel':
                editingId = null;
                renderProducts();
                showMessage();
                break;
            case 'save':
                await saveRow(productId, button);
                break;
            case 'remove':
                await removeRow(productId, button);
                break;
        }
    });

    // ในโหมดแก้ไข: Enter = บันทึก, Escape = ยกเลิก
    itemsContainer.addEventListener('keydown', (event) => {
        if (!event.target.classList.contains('row-input')) {
            return;
        }

        if (event.key === 'Enter') {
            event.preventDefault();
            event.target.closest('tr')?.querySelector('button[data-action="save"]')?.click();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            editingId = null;
            renderProducts();
            showMessage();
        }
    });

    loadProducts();
})();
