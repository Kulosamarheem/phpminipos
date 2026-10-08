(() => {
    'use strict';

    const csrfToken = document.querySelector('meta[name="cart-csrf-token"]')?.content || '';
    const form = document.querySelector('#add-product-form');
    const barcodeInput = document.querySelector('#barcode');
    const itemsContainer = document.querySelector('#cart-items');
    const totalElement = document.querySelector('#cart-total');
    const countElement = document.querySelector('#cart-item-count');
    const messageElement = document.querySelector('#cart-message');
    const checkoutForm = document.querySelector('#checkout-form');
    const checkoutButton = document.querySelector('#checkout-button');
    const checkoutMessage = document.querySelector('#checkout-message');
    const lastReceipt = document.querySelector('#last-receipt');
    const paymentMethod = document.querySelector('#payment-method');
    const vatMode = document.querySelector('#vat-mode');
    const receivedAmount = document.querySelector('#received-amount');
    const receivedAmountField = document.querySelector('#received-amount-field');
    const clearCartButton = document.querySelector('#clear-cart-button');
    const discountInput = document.querySelector('#discount-amount');
    const discountElement = document.querySelector('#cart-discount');
    const taxElement = document.querySelector('#cart-tax');
    const netElement = document.querySelector('#cart-net');
    const changeRow = document.querySelector('#cart-change-row');
    const changeElement = document.querySelector('#cart-change');
    const holdForm = document.querySelector('#hold-bill-form');
    const holdNoteInput = document.querySelector('#hold-note');
    const holdButton = document.querySelector('#hold-bill-button');
    const heldItemsContainer = document.querySelector('#held-bill-items');
    const heldCountElement = document.querySelector('#held-bills-count');
    const heldMessageElement = document.querySelector('#held-bill-message');
    const heldRefreshButton = document.querySelector('#held-bills-refresh');

    const money = new Intl.NumberFormat('th-TH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    // ยอดสุทธิล่าสุดที่ฝั่ง PHP คำนวณให้ (สตางค์) ใช้เป็นฐานของพรีวิวเงินทอน
    let netCents = 0;
    let totalsTimer = null;
    let lastWarning = '';

    function showMessage(message = '', type = '') {
        messageElement.textContent = message;
        messageElement.className = `cart-message ${type}`.trim();
    }

    function showCheckoutMessage(message = '', type = '') {
        checkoutMessage.textContent = message;
        checkoutMessage.className = `cart-message ${type}`.trim();
    }

    function showHoldMessage(message = '', type = '') {
        heldMessageElement.textContent = message;
        heldMessageElement.className = `cart-message ${type}`.trim();
    }

    function updatePaymentFields() {
        const isCash = paymentMethod.value === 'cash';
        receivedAmount.disabled = !isCash;
        receivedAmount.required = isCash;
        receivedAmountField.classList.toggle('is-disabled', !isCash);
        if (!isCash) {
            receivedAmount.value = '';
        }
        renderChange();
    }

    async function request(url, data) {
        const options = data === undefined
            ? { headers: { Accept: 'application/json' } }
            : {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ ...data, csrf_token: csrfToken }),
            };

        const response = await fetch(url, options);
        const payload = await response.json().catch(() => ({ success: false, message: 'ไม่สามารถอ่านข้อมูลจากระบบได้' }));

        if (!response.ok || !payload.success) {
            // แนบ status/code ไว้ให้ผู้เรียกแยกแยะเคสได้ เช่น 409 ตอนเรียกคืนบิลขณะตะกร้าไม่ว่าง
            const error = new Error(payload.message || 'เกิดข้อผิดพลาด กรุณาลองใหม่');
            error.status = response.status;
            error.code = payload.code || '';
            throw error;
        }

        return payload;
    }

    function quantityControls(item) {
        return `
            <div class="quantity-controls">
                <button type="button" data-action="decrease" data-product-id="${item.product_id}" data-qty="${item.qty - 1}" aria-label="ลดจำนวน ${escapeHtml(item.name)}">−</button>
                <span>${item.qty}</span>
                <button type="button" data-action="increase" data-product-id="${item.product_id}" data-qty="${item.qty + 1}" aria-label="เพิ่มจำนวน ${escapeHtml(item.name)}">+</button>
            </div>`;
    }

    function escapeHtml(value) {
        const element = document.createElement('span');
        element.textContent = String(value);
        return element.innerHTML;
    }

    function baht(amount) {
        return `${money.format(amount)} บาท`;
    }

    /** มิเรอร์ money_to_cents() ฝั่ง PHP — รับเฉพาะ "123" หรือ "123.45" ไม่ติดลบ */
    function toCents(value) {
        const amount = String(value ?? '').trim();
        if (!/^\d+(?:\.\d{1,2})?$/.test(amount)) {
            return null;
        }
        const [whole, fraction = ''] = amount.split('.');
        return (Number(whole) * 100) + Number(fraction.padEnd(2, '0'));
    }

    /**
     * เขียนบรรทัดสรุปยอดจากผลลัพธ์ของ cart_totals.php
     * ตัวเลข VAT/ยอดสุทธิมาจาก PHP ทั้งหมด ฝั่ง JS ไม่คำนวณภาษีเอง
     */
    function renderTotals(totals) {
        netCents = Number(totals.net_cents) || 0;
        const discount = Number(totals.discount_amount);

        totalElement.textContent = baht(totals.total_amount);
        discountElement.textContent = discount > 0 ? `-${baht(discount)}` : baht(0);
        taxElement.textContent = baht(totals.tax_amount);
        netElement.textContent = baht(totals.net_amount);

        renderChange();
    }

    /**
     * พรีวิวเงินทอน = เงินที่รับ − ยอดสุทธิ เป็นการลบจำนวนเต็มล้วน
     * ไม่มีการปัดเศษจึงคำนวณฝั่ง JS ได้โดยไม่ต้องถาม server ทุกครั้งที่พิมพ์
     */
    function renderChange() {
        const receivedCents = toCents(receivedAmount.value);

        if (paymentMethod.value !== 'cash' || receivedCents === null) {
            changeRow.hidden = true;
            return;
        }

        const changeCents = receivedCents - netCents;
        changeRow.hidden = false;
        changeElement.textContent = changeCents < 0
            ? `ยังขาดอีก ${baht(-changeCents / 100)}`
            : baht(changeCents / 100);
        changeElement.classList.toggle('is-short', changeCents < 0);
    }

    async function refreshTotals() {
        try {
            const payload = await request('../api/cart_totals.php', {
                discount_amount: discountInput.value.trim() || '0',
                vat_mode: vatMode.value,
            });
            renderTotals(payload.totals);

            // แสดง/เก็บคำเตือนเรื่องส่วนลดโดยไม่ไปทับข้อความอื่น เช่น ผลลัพธ์การชำระเงิน
            if (payload.warning) {
                showCheckoutMessage(payload.warning, 'error');
                lastWarning = payload.warning;
            } else if (lastWarning && checkoutMessage.textContent === lastWarning) {
                showCheckoutMessage();
                lastWarning = '';
            }
        } catch (error) {
            showCheckoutMessage(error.message, 'error');
        }
    }

    // ช่องส่วนลดเป็น type="number" จึงยิง input ทุกตัวอักษร — หน่วงไว้ก่อนค่อยถาม server
    function scheduleTotalsRefresh() {
        window.clearTimeout(totalsTimer);
        totalsTimer = window.setTimeout(refreshTotals, 250);
    }

    function renderCart(cart) {
        countElement.textContent = `${cart.item_count} ชิ้น`;
        checkoutButton.disabled = cart.items.length === 0;

        itemsContainer.innerHTML = cart.items.length
            ? cart.items.map((item) => `
            <tr>
                <td><strong>${escapeHtml(item.name)}</strong><small>${escapeHtml(item.barcode)}</small></td>
                <td>${money.format(item.price)}</td>
                <td>${quantityControls(item)}</td>
                <td>${money.format(item.subtotal)}</td>
                <td><button type="button" class="remove-button" data-action="remove" data-product-id="${item.product_id}">ลบ</button></td>
            </tr>
        `).join('')
            : '<tr><td colspan="5" class="empty-cart">ยังไม่มีสินค้าในตะกร้า</td></tr>';

        // ตะกร้าเปลี่ยน = ยอด VAT/สุทธิเปลี่ยนตาม ให้ PHP คำนวณใหม่ทุกครั้ง
        refreshTotals();
    }

    async function loadCart() {
        try {
            const payload = await request('../api/cart_get.php');
            renderCart(payload.cart);
        } catch (error) {
            showMessage(error.message, 'error');
            itemsContainer.innerHTML = '<tr><td colspan="5" class="empty-cart">ไม่สามารถโหลดตะกร้าได้</td></tr>';
        }
    }

    /**
     * เรนเดอร์รายการบิลที่พักไว้
     * หมายเหตุและชื่อแคชเชียร์เป็นข้อความอิสระที่ "ผู้ใช้คนอื่น" เป็นคนกรอก
     * จึงต้องผ่าน escapeHtml() ทุกฟิลด์ก่อนต่อเข้า innerHTML เสมอ
     */
    function renderHeldBills(heldBills) {
        heldCountElement.textContent = `${heldBills.length} บิล`;

        heldItemsContainer.innerHTML = heldBills.length
            ? heldBills.map((bill) => {
                const note = bill.note
                    ? `<small>${escapeHtml(bill.note)}</small>`
                    : '';
                const staleBadge = bill.is_stale
                    ? ' <span class="status-badge status-stale">ค้างข้ามวัน</span>'
                    : '';
                const cashier = bill.cashier_name || 'ไม่ระบุ';

                return `
            <tr>
                <td>
                    <strong>${escapeHtml(bill.hold_number)}</strong>${staleBadge}
                    ${note}
                    <small>${escapeHtml(cashier)} · ${escapeHtml(bill.created_at_display)}</small>
                </td>
                <td>${bill.item_count} ชิ้น</td>
                <td>${money.format(bill.total_amount)}</td>
                <td>
                    <button type="button" class="resume-button" data-action="resume" data-held-bill-id="${bill.id}" data-hold-number="${escapeHtml(bill.hold_number)}">เรียกคืน</button>
                    <button type="button" class="remove-button" data-action="discard" data-held-bill-id="${bill.id}" data-hold-number="${escapeHtml(bill.hold_number)}">ลบ</button>
                </td>
            </tr>
        `;
            }).join('')
            : '<tr><td colspan="4" class="empty-cart">ยังไม่มีบิลที่พักไว้</td></tr>';
    }

    async function loadHeldBills() {
        try {
            const payload = await request('../api/held_bill_list.php');
            renderHeldBills(payload.held_bills);
        } catch (error) {
            showHoldMessage(error.message, 'error');
            heldItemsContainer.innerHTML = '<tr><td colspan="4" class="empty-cart">ไม่สามารถโหลดบิลที่พักไว้ได้</td></tr>';
        }
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const barcode = barcodeInput.value.trim();

        if (!barcode) {
            return;
        }

        try {
            const payload = await request('../api/cart_add.php', { barcode });
            renderCart(payload.cart);
            showMessage(payload.message, 'success');
            form.reset();
            barcodeInput.focus();
        } catch (error) {
            showMessage(error.message, 'error');
            barcodeInput.focus();
        }
    });

    itemsContainer.addEventListener('click', async (event) => {
        const button = event.target.closest('button[data-action]');
        if (!button) {
            return;
        }

        const productId = Number(button.dataset.productId);
        const action = button.dataset.action;
        button.disabled = true;

        try {
            const payload = action === 'remove'
                ? await request('../api/cart_remove.php', { product_id: productId })
                : await request('../api/cart_update.php', { product_id: productId, qty: Number(button.dataset.qty) });
            renderCart(payload.cart);
            showMessage(payload.message, 'success');
        } catch (error) {
            showMessage(error.message, 'error');
        } finally {
            button.disabled = false;
        }
    });

    clearCartButton.addEventListener('click', async () => {
        if (!confirm('ยืนยันล้างรายการสินค้าทั้งหมดในตะกร้า?')) {
            return;
        }

        clearCartButton.disabled = true;
        try {
            const payload = await request('../api/cart_clear.php', {});
            renderCart(payload.cart);
            showMessage(payload.message, 'success');
            barcodeInput.focus();
        } catch (error) {
            showMessage(error.message, 'error');
        } finally {
            clearCartButton.disabled = false;
        }
    });

    holdForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        holdButton.disabled = true;

        try {
            const payload = await request('../api/held_bill_park.php', { note: holdNoteInput.value.trim() });
            renderCart(payload.cart);
            renderHeldBills(payload.held_bills);
            holdForm.reset();
            showHoldMessage(payload.message, 'success');
        } catch (error) {
            showHoldMessage(error.message, 'error');
        } finally {
            holdButton.disabled = false;
            barcodeInput.focus();
        }
    });

    heldItemsContainer.addEventListener('click', async (event) => {
        const button = event.target.closest('button[data-action]');
        if (!button) {
            return;
        }

        const heldBillId = Number(button.dataset.heldBillId);
        const holdNumber = button.dataset.holdNumber || '';
        const action = button.dataset.action;

        if (action === 'discard' && !confirm(`ยืนยันลบบิลที่พักไว้ ${holdNumber}?`)) {
            return;
        }

        button.disabled = true;

        try {
            let payload;
            if (action === 'discard') {
                payload = await request('../api/held_bill_delete.php', { held_bill_id: heldBillId });
            } else {
                try {
                    payload = await request('../api/held_bill_resume.php', {
                        held_bill_id: heldBillId,
                        on_conflict: 'reject',
                    });
                } catch (error) {
                    // ตะกร้าปัจจุบันยังมีของ — ถามก่อนว่าจะพักบิลปัจจุบันไว้แล้วสลับหรือไม่
                    // ฝั่ง server ทำทั้งสองอย่างในทรานแซกชันเดียว จึงไม่มีทางทำตะกร้าหาย
                    if (error.code !== 'cart_not_empty') {
                        throw error;
                    }
                    if (!confirm('ตะกร้าปัจจุบันมีสินค้าอยู่ ต้องการพักบิลปัจจุบันไว้ก่อนแล้วเรียกบิลนี้ขึ้นมาหรือไม่?')) {
                        showHoldMessage(error.message, 'error');
                        return;
                    }
                    payload = await request('../api/held_bill_resume.php', {
                        held_bill_id: heldBillId,
                        on_conflict: 'park',
                    });
                }
            }

            renderCart(payload.cart);
            renderHeldBills(payload.held_bills);
            showHoldMessage(payload.message, 'success');
        } catch (error) {
            showHoldMessage(error.message, 'error');
            // รายการอาจไม่ตรงกับความจริงแล้ว เช่น แคชเชียร์อีกคนเพิ่งเรียกคืนบิลนี้ไป
            await loadHeldBills();
        } finally {
            // ปุ่มอาจหลุดจาก DOM ไปแล้วหลัง renderHeldBills() เขียนตารางใหม่
            if (button.isConnected) {
                button.disabled = false;
            }
            barcodeInput.focus();
        }
    });

    heldRefreshButton.addEventListener('click', async () => {
        heldRefreshButton.disabled = true;
        try {
            await loadHeldBills();
        } finally {
            heldRefreshButton.disabled = false;
        }
    });

    paymentMethod.addEventListener('change', updatePaymentFields);
    vatMode.addEventListener('change', refreshTotals);
    discountInput.addEventListener('input', scheduleTotalsRefresh);
    receivedAmount.addEventListener('input', renderChange);

    // ปิดแท็บพิมพ์ใบเสร็จซ้ำ หรือสลับกลับมาจากแท็บ/แอปอื่น ต้องโฟกัสช่องบาร์โค้ดให้เสมอ
    // เพื่อให้แคชเชียร์สแกนสินค้าบิลถัดไปได้ทันทีโดยไม่ต้องคลิกเมาส์เอง
    // ไม่ใช่กลไกคืนโฟกัสหลังพิมพ์อัตโนมัติ — พรีวิวของ Chrome/Firefox เป็น overlay ในแท็บเดิม
    // หน้ายังเป็น visible อยู่ ทั้งสอง event นี้จึงไม่ยิง ดู printReceipt() ที่คืนโฟกัสเอง
    window.addEventListener('focus', () => barcodeInput.focus());
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            barcodeInput.focus();
        }
    });

    // ใบเสร็จพิมพ์ผ่าน iframe ซ่อน แทนการ window.open()
    // แคชเชียร์ไม่ต้องคอยปิดแท็บใบเสร็จทุกบิล และไม่ต้องพึ่งว่า popup blocker จะยอมให้เปิด
    const RECEIPT_LOAD_TIMEOUT = 10000;
    let receiptFrame = null;

    function receiptUrl(orderId, autoPrint = false) {
        return `receipt.php?order_id=${encodeURIComponent(orderId)}${autoPrint ? '&autoprint=1' : ''}`;
    }

    /**
     * ลิงก์พิมพ์ซ้ำของบิลล่าสุด — ขึ้นทุกครั้งที่บันทึกบิลสำเร็จ ไม่ว่าการพิมพ์อัตโนมัติจะสำเร็จหรือไม่
     * บิลถูกบันทึกลงฐานข้อมูลไปแล้ว ใบเสร็จจึงต้องไม่หายไปเงียบ ๆ เพราะเครื่องพิมพ์มีปัญหา
     * แยกจาก #checkout-message เพราะ refreshTotals() เขียนทับข้อความนั้นได้ตลอด
     */
    function showLastReceiptLink(order) {
        lastReceipt.innerHTML = `<a class="text-link" href="${receiptUrl(order.id, true)}" target="_blank" rel="noopener">พิมพ์ใบเสร็จอีกครั้ง (${escapeHtml(order.order_number)})</a>`;
        lastReceipt.hidden = false;
    }

    /**
     * โหลดใบเสร็จลง iframe ซ่อนแล้วสั่งพิมพ์ — คืน false เมื่อสั่งพิมพ์ไม่สำเร็จ (ไม่ throw)
     * สร้าง iframe ใหม่ทุกบิลแทนการใช้ตัวเดิมซ้ำ เพราะการเซ็ต src เป็น URL เดิมซ้ำ
     * เบราว์เซอร์จะไม่โหลดใหม่และไม่ยิง load ให้ ทำให้พิมพ์บิลเดิมซ้ำแล้วค้างรอจนหมดเวลา
     */
    function printReceipt(orderId) {
        return new Promise((resolve) => {
            if (receiptFrame) {
                receiptFrame.remove();
            }

            const frame = document.createElement('iframe');
            receiptFrame = frame;
            frame.className = 'receipt-print-frame';
            frame.title = 'ใบเสร็จสำหรับพิมพ์';
            // ห้ามใส่ sandbox (ถ้าไม่มี allow-modals จะทำให้ print() เป็น no-op เงียบ ๆ)
            // และห้ามใส่ loading="lazy" เพราะ frame ที่อยู่นอกจออาจไม่ถูกโหลดเลย

            let settled = false;
            const finish = (printed) => {
                if (settled) {
                    return;
                }
                settled = true;
                window.clearTimeout(timer);
                resolve(printed);
            };

            // กันเคสโหลดค้าง — แคชเชียร์ต้องได้คำตอบเสมอว่าพิมพ์ได้หรือไม่ ไม่ใช่รอเงียบ ๆ
            const timer = window.setTimeout(() => finish(false), RECEIPT_LOAD_TIMEOUT);

            frame.addEventListener('load', () => {
                let doc = null;
                try {
                    if (frame.contentWindow.location.href === 'about:blank') {
                        return;
                    }
                    doc = frame.contentDocument;
                } catch (error) {
                    // เข้าถึงเอกสารไม่ได้ = ถูกบล็อกด้วย X-Frame-Options: DENY
                    // ซึ่งคือหน้า error 400/404 ของ receipt.php นั่นเอง
                    doc = null;
                }

                // หน้า error ของ receipt.php ก็เป็น HTML เต็มหน้าเหมือนกัน
                // ต้องเช็ค #receipt-section ก่อน จะได้ไม่พิมพ์หน้า "ไม่พบใบเสร็จ" ออกมาเป็นกระดาษ
                if (!doc || !doc.querySelector('#receipt-section')) {
                    finish(false);
                    return;
                }

                try {
                    // Firefox/Safari ต้องให้ iframe ได้โฟกัสก่อน ไม่งั้น print() ไปเล็งเอกสารของหน้าขายแทน
                    frame.contentWindow.focus();
                    // ปิดหน้าต่างพิมพ์แล้วเบราว์เซอร์ไม่ยิง window 'focus' ให้เสมอ เพราะพรีวิวอยู่ในแท็บเดิม
                    // จึงคืนโฟกัสด้วย afterprint ควบคู่กับ finally ข้างล่าง
                    frame.contentWindow.addEventListener('afterprint', () => barcodeInput.focus(), { once: true });
                    frame.contentWindow.print();
                } catch (error) {
                    finish(false);
                    return;
                } finally {
                    // Chrome คืนค่าจาก print() หลังปิดพรีวิว ส่วน Firefox/headless คืนทันที
                    // เรียกทั้งสองทางเพื่อให้แคชเชียร์ได้โฟกัสกลับมาที่ช่องบาร์โค้ดแน่นอน
                    barcodeInput.focus();
                }

                finish(true);
            });

            frame.addEventListener('error', () => finish(false));
            // ต้องเซ็ต src ก่อนแทรกเข้า DOM ไม่งั้นเบราว์เซอร์จะยิง load ของ about:blank มาก่อนหนึ่งครั้ง
            frame.src = receiptUrl(orderId);
            document.body.append(frame);
        });
    }

    checkoutForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (checkoutButton.disabled) {
            return;
        }

        checkoutButton.disabled = true;
        try {
            const data = Object.fromEntries(new FormData(checkoutForm));
            const payload = await request('../api/checkout.php', data);
            // ล้างฟอร์มก่อน renderCart เพื่อให้ยอดที่คำนวณใหม่ใช้ส่วนลด 0.00 ของบิลถัดไป
            checkoutForm.reset();
            updatePaymentFields();
            renderCart(payload.cart);

            const summary = `บันทึกบิล ${payload.order.order_number} สำเร็จ · เงินทอน ${money.format(payload.order.change_amount)} บาท`;
            showCheckoutMessage(summary, 'success');
            showLastReceiptLink(payload.order);
            barcodeInput.focus();

            // ไม่ await ผลการพิมพ์ — คืนคิวให้แคชเชียร์สแกนบิลถัดไปได้ทันทีระหว่างที่ใบเสร็จกำลังพิมพ์
            printReceipt(payload.order.id).then((printed) => {
                if (!printed) {
                    showCheckoutMessage(`${summary} · เปิดใบเสร็จอัตโนมัติไม่ได้ กดลิงก์ "พิมพ์ใบเสร็จอีกครั้ง" ด้านล่าง`, 'error');
                }
            });
        } catch (error) {
            showCheckoutMessage(error.message, 'error');
            await loadCart();
        }
    });

    updatePaymentFields();
    loadCart();
    loadHeldBills();
})();
