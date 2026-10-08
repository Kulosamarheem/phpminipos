<?php

declare(strict_types=1);

require_once __DIR__ . '/cart.php';

// ---------------------------------------------------------
// พักบิล (held bills)
// เก็บตะกร้าปัจจุบันลง DB ชั่วคราวเพื่อให้แคชเชียร์คิดลูกค้าคนถัดไปได้ก่อน
// แล้วค่อยเรียกกลับมาคิดต่อ — แคชเชียร์คนไหนก็เรียกคืนบิลของคนอื่นได้
//
// บิลพักไม่ใช่บิลขาย: ไม่จองสต็อก ไม่เก็บส่วนลด/โหมด VAT และไม่มี audit trail
// ตอนเรียกคืนจะตรวจสินค้ากับ products ใหม่ทั้งหมด และใช้ราคาปัจจุบันเสมอ
// ---------------------------------------------------------

/** รูปแบบเลขบิลพัก — นิยามไว้ที่เดียว ใช้ทั้งตอนสร้างและตอนอ่านสำรอง */
function held_bill_format_number(int $id): string
{
    return 'HOLD-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
}

/**
 * หมายเหตุประกอบบิลพัก — ไม่บังคับกรอก
 * ตัดอักขระควบคุมทิ้งเพื่อไม่ให้ข้อความขึ้นบรรทัดใหม่ไปทำตารางเพี้ยน
 * นับความยาวด้วย mb_strlen เพราะข้อความไทยหนึ่งตัวกินหลายไบต์
 */
function held_bill_note_input(array $data): string
{
    $note = trim((string) ($data['note'] ?? ''));
    $note = preg_replace('/[\x00-\x1F\x7F]/u', '', $note) ?? '';

    if (mb_strlen($note, 'UTF-8') > HELD_BILL_NOTE_MAX) {
        cart_json_response([
            'success' => false,
            'message' => 'หมายเหตุยาวเกิน ' . HELD_BILL_NOTE_MAX . ' ตัวอักษร',
        ], 422);
    }

    return $note;
}

function held_bill_id_input(array $data): int
{
    $heldBillId = filter_var(
        $data['held_bill_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($heldBillId === false) {
        cart_json_response(['success' => false, 'message' => 'ไม่ได้ระบุบิลที่พักไว้'], 422);
    }

    return (int) $heldBillId;
}

function held_bill_count(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM held_bills')->fetchColumn();
}

/**
 * อ่านตะกร้าใน session แล้วพับเป็น snapshot พร้อมบันทึก คีย์ด้วย product_id
 * ป้องกัน session ที่เสียหาย และการันตีว่าไม่มีสินค้าซ้ำจนชน uq_held_bill_product
 * (กติกาเดียวกับที่ checkout.php ใช้ก่อนตัดสต็อก)
 *
 * @return array<int, array{product_id:int, barcode:string, name:string, price_cents:int, qty:int}>
 */
function held_bill_cart_snapshot(): array
{
    $snapshot = [];

    foreach (cart_items() as $item) {
        $productId = filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $qty = filter_var($item['qty'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $priceCents = money_to_cents(number_format((float) ($item['price'] ?? -1), 2, '.', ''));

        if ($productId === false || $qty === false || $priceCents === null) {
            cart_json_response([
                'success' => false,
                'message' => 'ข้อมูลในตะกร้าไม่ถูกต้อง กรุณาเพิ่มสินค้าใหม่',
            ], 422);
        }

        $productId = (int) $productId;

        if (isset($snapshot[$productId])) {
            $snapshot[$productId]['qty'] += (int) $qty;
            continue;
        }

        $snapshot[$productId] = [
            'product_id' => $productId,
            'barcode' => (string) ($item['barcode'] ?? ''),
            'name' => (string) ($item['name'] ?? ''),
            'price_cents' => $priceCents,
            'qty' => (int) $qty,
        ];
    }

    ksort($snapshot, SORT_NUMERIC);

    return $snapshot;
}

/**
 * บันทึกบิลพักหนึ่งใบ — ต้องเรียกภายในทรานแซกชันที่เปิดไว้แล้ว
 * เลขบิลได้จาก AUTO_INCREMENT ซึ่งปลอดภัยจาก race อยู่แล้ว จึงไม่ต้อง MAX()+1
 * แถวจะมี hold_number เป็น NULL อยู่ชั่วครู่ แต่ยังไม่ commit จึงไม่มี session อื่นเห็น
 *
 * @param array<int, array{product_id:int, barcode:string, name:string, price_cents:int, qty:int}> $snapshot
 * @return array{id:int, hold_number:string}
 */
function held_bill_create(PDO $pdo, array $snapshot, string $note): array
{
    if (held_bill_count($pdo) >= HELD_BILL_MAX) {
        throw new RuntimeException(
            'พักบิลได้สูงสุด ' . HELD_BILL_MAX . ' บิล กรุณาเรียกคืนหรือลบบิลที่พักไว้ก่อน'
        );
    }

    $user = current_user();

    $insertBill = $pdo->prepare('INSERT INTO held_bills (hold_number, user_id, note) VALUES (NULL, ?, ?)');
    $insertBill->execute([(int) $user['id'], $note]);
    $heldBillId = (int) $pdo->lastInsertId();

    $holdNumber = held_bill_format_number($heldBillId);
    $pdo->prepare('UPDATE held_bills SET hold_number = ? WHERE id = ?')
        ->execute([$holdNumber, $heldBillId]);

    $insertItem = $pdo->prepare(
        'INSERT INTO held_bill_items (held_bill_id, product_id, barcode, product_name, qty, price_at_hold)
         VALUES (?, ?, ?, ?, ?, ?)'
    );

    foreach ($snapshot as $item) {
        $insertItem->execute([
            $heldBillId,
            $item['product_id'],
            $item['barcode'],
            $item['name'],
            $item['qty'],
            money_from_cents($item['price_cents']),
        ]);
    }

    return ['id' => $heldBillId, 'hold_number' => $holdNumber];
}

/**
 * รายการบิลพักทั้งหมดพร้อมยอดสรุป — query เดียวไม่ว่าจะมีกี่บิล
 *
 * total_amount ที่ได้เป็นยอด ณ เวลาที่พักไว้ ใช้แสดงผลเท่านั้น
 * ยอดจริงคำนวณใหม่จากราคาปัจจุบันตอนเรียกคืนและตอนคิดเงิน
 *
 * หมายเหตุ: คำเตือนใน order_history.php เรื่องห้าม JOIN ตารางลูกเข้ากับ SUM
 * ไม่ใช้กับที่นี่ เพราะ held_bills ไม่มีคอลัมน์ยอดรวมของตัวเองให้ถูกคูณซ้ำ
 * และ qty * DECIMAL ใน MySQL เป็นเลขทศนิยมแบบตายตัว ไม่ใช่ float
 */
function held_bill_list(PDO $pdo): array
{
    // is_stale คำนวณด้วย NOW() ของ MySQL ไม่ใช่ time() ของ PHP
    // เพราะสองฝั่งอาจตั้ง timezone ไม่ตรงกัน การเทียบข้ามฝั่งจะได้ผลเพี้ยน
    // HELD_BILL_STALE_HOURS เป็นค่าคงที่ในโค้ด ไม่ใช่ข้อมูลจากผู้ใช้ จึงต่อสตริงได้
    $staleHours = (int) HELD_BILL_STALE_HOURS;

    $stmt = $pdo->query(
        "SELECT hb.id,
                COALESCE(hb.hold_number, CONCAT('HOLD-', LPAD(hb.id, 4, '0'))) AS hold_number,
                hb.note,
                hb.created_at,
                (hb.created_at <= DATE_SUB(NOW(), INTERVAL {$staleHours} HOUR)) AS is_stale,
                u.full_name AS cashier_name,
                COALESCE(SUM(hbi.qty), 0)                     AS item_count,
                COALESCE(SUM(hbi.qty * hbi.price_at_hold), 0) AS total_amount
         FROM held_bills hb
         LEFT JOIN users u             ON u.id = hb.user_id
         LEFT JOIN held_bill_items hbi ON hbi.held_bill_id = hb.id
         GROUP BY hb.id
         ORDER BY hb.created_at DESC, hb.id DESC"
    );

    $bills = [];

    foreach ($stmt->fetchAll() as $row) {
        $bills[] = [
            'id' => (int) $row['id'],
            'hold_number' => (string) $row['hold_number'],
            'note' => (string) $row['note'],
            'cashier_name' => (string) ($row['cashier_name'] ?? ''),
            'item_count' => (int) $row['item_count'],
            'total_amount' => money_from_cents(money_to_cents($row['total_amount']) ?? 0),
            // จัดรูปแบบวันที่ฝั่ง PHP เพื่อไม่ให้ pos.js ต้องมีโค้ดจัดการวันที่
            // (รูปแบบเดียวกับ order_history.php)
            'created_at_display' => date('d/m/Y H:i', strtotime((string) $row['created_at']) ?: time()),
            'is_stale' => (bool) $row['is_stale'],
        ];
    }

    return $bills;
}

function held_bill_load_items(PDO $pdo, int $heldBillId): array
{
    $stmt = $pdo->prepare(
        'SELECT product_id, barcode, product_name, qty, price_at_hold
         FROM held_bill_items
         WHERE held_bill_id = ?
         ORDER BY id'
    );
    $stmt->execute([$heldBillId]);

    return $stmt->fetchAll();
}

/**
 * ตอบกลับสถานะทั้งสองฝั่งในรอบเดียว (ตะกร้า + รายการบิลพัก)
 * คู่ขนานกับ cart_send_state() — ทำให้ pos.js ไม่ต้องยิง request ซ้ำหลังทุก action
 */
function held_bill_send_state(PDO $pdo, string $message = '', array $extra = []): void
{
    cart_json_response(array_merge([
        'success' => true,
        'message' => $message,
        'cart' => cart_state(),
        'held_bills' => held_bill_list($pdo),
    ], $extra));
}
