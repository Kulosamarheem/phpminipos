<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/page.php';
require_once __DIR__ . '/money.php';

// ---------------------------------------------------------
// Reports — ตัวกรอง, เงื่อนไข WHERE และ UI ที่ report_sales.php
// กับ order_history.php ใช้ร่วมกัน (หน้าเรียก config/db.php เอง)
// ---------------------------------------------------------

// จำกัดช่วงวันที่สูงสุด กัน query กวาดทั้งตาราง
const REPORT_MAX_RANGE_DAYS = 366;

// จำนวนบิลต่อหน้าใน order_history.php — ค่าคงที่ ไม่รับจาก client
const REPORT_PAGE_SIZE = 20;

const REPORT_PAYMENT_LABELS = ['cash' => 'เงินสด', 'qr' => 'QR', 'card' => 'บัตร'];

/** ตรวจว่าเป็นวันที่รูปแบบ Y-m-d ที่มีอยู่จริง คืน null ถ้าไม่ใช่ */
function report_valid_date(mixed $value): ?string
{
    if (!is_string($value) || $value === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    return ($date !== false && $date->format('Y-m-d') === $value) ? $value : null;
}

/** รายชื่อพนักงานสำหรับ dropdown — รวมคนที่ปิดใช้งานแล้ว เพื่อให้บิลเก่ายังกรองได้ */
function report_cashiers(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, full_name, username, is_active FROM users ORDER BY full_name, id');
    $cashiers = [];

    foreach ($stmt->fetchAll() as $row) {
        $cashiers[] = [
            'id' => (int) $row['id'],
            'full_name' => (string) $row['full_name'],
            'username' => (string) $row['username'],
            'is_active' => (int) $row['is_active'] === 1,
        ];
    }

    return $cashiers;
}

/**
 * อ่านและ validate ตัวกรองจาก query string
 * คืน ['date_from', 'date_to', 'user_id', 'q', 'errors']
 * ค่าที่ไม่ผ่านจะถูกแทนด้วยค่าเริ่มต้นพร้อมข้อความอธิบายใน errors เสมอ — ไม่ปล่อยผ่านไปถึง SQL
 */
function report_parse_filters(array $query, array $cashiers): array
{
    $errors = [];
    $today = date('Y-m-d');

    $from = report_valid_date($query['date_from'] ?? null);
    if ($from === null && isset($query['date_from']) && $query['date_from'] !== '') {
        $errors[] = 'รูปแบบวันที่เริ่มต้นไม่ถูกต้อง ระบบใช้วันที่ปัจจุบันแทน';
    }

    $to = report_valid_date($query['date_to'] ?? null);
    if ($to === null && isset($query['date_to']) && $query['date_to'] !== '') {
        $errors[] = 'รูปแบบวันที่สิ้นสุดไม่ถูกต้อง ระบบใช้วันที่ปัจจุบันแทน';
    }

    $from ??= $today;
    $to ??= $today;

    if ($from > $to) {
        $errors[] = 'วันเริ่มต้นต้องไม่เกินวันสิ้นสุด ระบบปรับให้เป็นวันเดียวกันแล้ว';
        $from = $to;
    }

    $fromDate = new DateTimeImmutable($from);
    $toDate = new DateTimeImmutable($to);
    $rangeDays = (int) $fromDate->diff($toDate)->days + 1;

    if ($rangeDays > REPORT_MAX_RANGE_DAYS) {
        $from = $toDate->modify('-' . (REPORT_MAX_RANGE_DAYS - 1) . ' days')->format('Y-m-d');
        $errors[] = 'ช่วงวันที่ยาวเกิน ' . REPORT_MAX_RANGE_DAYS . ' วัน ระบบย่อช่วงให้อัตโนมัติ';
    }

    $userId = 0;
    $rawUserId = $query['user_id'] ?? '';

    if ($rawUserId !== '' && $rawUserId !== '0') {
        $candidate = filter_var($rawUserId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($candidate === false || !in_array($candidate, array_column($cashiers, 'id'), true)) {
            $errors[] = 'ไม่พบพนักงานที่เลือก ระบบแสดงข้อมูลของพนักงานทุกคนแทน';
        } else {
            $userId = $candidate;
        }
    }

    $search = $query['q'] ?? '';
    if (!is_string($search)) {
        $search = '';
    }
    $search = mb_substr(trim($search), 0, 30);

    return [
        'date_from' => $from,
        'date_to' => $to,
        'user_id' => $userId,
        'q' => $search,
        'errors' => $errors,
    ];
}

/**
 * สร้างเงื่อนไข WHERE ของรายงาน (ตาราง orders ต้องใช้ alias `o`)
 *
 * ใช้ช่วงเวลาแบบ half-open (>= วันเริ่ม 00:00:00 และ < วันถัดจากวันสิ้นสุด 00:00:00)
 * ห้ามใช้ DATE(o.created_at) เพราะจะทำให้ index idx_created_at ใช้ไม่ได้
 */
function report_where(array $filters, bool $completedOnly = true): array
{
    $conditions = [];
    $params = [];

    if ($completedOnly) {
        $conditions[] = "o.status = 'completed'";
    }

    $conditions[] = 'o.created_at >= ?';
    $params[] = $filters['date_from'] . ' 00:00:00';

    $conditions[] = 'o.created_at < ?';
    $params[] = (new DateTimeImmutable($filters['date_to']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

    if (($filters['user_id'] ?? 0) > 0) {
        $conditions[] = 'o.user_id = ?';
        $params[] = $filters['user_id'];
    }

    if (($filters['q'] ?? '') !== '') {
        // escape wildcard ของ LIKE เองเพื่อไม่ให้ผู้ใช้ค้นด้วย % หรือ _ ได้
        $conditions[] = 'o.order_number LIKE ?';
        $params[] = '%' . addcslashes($filters['q'], '%_\\') . '%';
    }

    return [implode(' AND ', $conditions), $params];
}

/** สร้าง query string ที่คงตัวกรองเดิมไว้ ใช้กับลิงก์ paging/ดูรายละเอียด */
function report_query_string(array $filters, array $overrides = []): string
{
    $params = array_merge([
        'date_from' => $filters['date_from'],
        'date_to' => $filters['date_to'],
        'user_id' => ($filters['user_id'] ?? 0) > 0 ? (string) $filters['user_id'] : '',
        'q' => $filters['q'] ?? '',
    ], $overrides);

    $params = array_filter($params, static fn ($value): bool => $value !== '' && $value !== null);

    return $params === [] ? '' : '?' . http_build_query($params);
}

/** ชื่อพนักงานจาก id — ใช้แสดงหัวเรื่องของรายงาน */
function report_cashier_name(array $cashiers, int $userId): string
{
    foreach ($cashiers as $cashier) {
        if ($cashier['id'] === $userId) {
            return $cashier['full_name'];
        }
    }

    return 'พนักงานทุกคน';
}

/** แถบเมนูด้านบนของหน้ารายงาน */
function report_render_topbar(array $user): void
{
    ?>
    <header class="topbar">
        <a class="brand" href="index.php">Mini POS</a>
        <div class="topbar-actions">
            <span><?= page_esc($user['full_name']) ?> (<?= page_esc($user['role']) ?>)</span>
            <a href="logout.php">ออกจากระบบ</a>
        </div>
    </header>
    <?php
}

/** ฟอร์มตัวกรองที่ทั้งสองหน้ารายงานใช้ร่วมกัน */
function report_render_filter_form(string $action, array $filters, array $cashiers, bool $withSearch = false): void
{
    $today = date('Y-m-d');
    $monthStart = date('Y-m-01');
    $weekStart = date('Y-m-d', strtotime('-6 days'));
    ?>
    <form class="filter-form" method="get" action="<?= page_esc($action) ?>">
        <label>ตั้งแต่วันที่
            <input type="date" name="date_from" value="<?= page_esc($filters['date_from']) ?>" max="<?= page_esc($today) ?>">
        </label>
        <label>ถึงวันที่
            <input type="date" name="date_to" value="<?= page_esc($filters['date_to']) ?>" max="<?= page_esc($today) ?>">
        </label>
        <label>พนักงาน
            <select name="user_id">
                <option value="">ทุกคน</option>
                <?php foreach ($cashiers as $cashier): ?>
                <option value="<?= (int) $cashier['id'] ?>"<?= $filters['user_id'] === $cashier['id'] ? ' selected' : '' ?>>
                    <?= page_esc($cashier['full_name']) ?><?= $cashier['is_active'] ? '' : ' (ปิดใช้งาน)' ?>
                </option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if ($withSearch): ?>
        <label>เลขที่บิล
            <input type="search" name="q" maxlength="30" placeholder="เช่น POS20260806" value="<?= page_esc($filters['q']) ?>">
        </label>
        <?php endif; ?>
        <div class="filter-actions">
            <button type="submit">แสดงรายงาน</button>
            <span class="filter-shortcuts">
                <a class="text-link" href="<?= page_esc($action . report_query_string($filters, ['date_from' => $today, 'date_to' => $today])) ?>">วันนี้</a>
                <a class="text-link" href="<?= page_esc($action . report_query_string($filters, ['date_from' => $weekStart, 'date_to' => $today])) ?>">7 วันล่าสุด</a>
                <a class="text-link" href="<?= page_esc($action . report_query_string($filters, ['date_from' => $monthStart, 'date_to' => $today])) ?>">เดือนนี้</a>
            </span>
        </div>
    </form>
    <?php
}

/** แสดงข้อความเตือนจากการ validate ตัวกรอง */
function report_render_filter_errors(array $errors): void
{
    if ($errors === []) {
        return;
    }
    ?>
    <p class="cart-message error" role="status"><?= page_esc(implode(' · ', $errors)) ?></p>
    <?php
}
