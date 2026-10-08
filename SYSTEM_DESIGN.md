# Mini POS & Billing System — System Design

> เอกสารนี้แยก **สถานะโค้ดที่สำรวจจริง ณ ปัจจุบัน** ออกจาก **แบบออกแบบ/แผนงานในอนาคต** เพื่อไม่ให้เข้าใจว่าไฟล์หรือ endpoint ที่เสนอไว้มีอยู่แล้ว

## 0. สถานะการติดตั้งปัจจุบัน (สำรวจจากโค้ด)

### ภาพรวมสถาปัตยกรรม

โปรเจกต์เป็นเว็บ PHP 8 แบบ server-rendered และไม่มี framework: URL map ไปยังไฟล์ `.php` โดยตรง, ใช้ PHP session สำหรับ authentication/cart, ใช้ PDO เชื่อม MySQL/MariaDB และใช้ JavaScript `fetch()` สำหรับตะกร้า. ไม่มี Composer, router กลาง, controller/model/service class หรือ build tool

ขอบเขตที่ใช้งานได้จริงครอบคลุมเฟส 0–6: ล็อกอิน, ล็อกเอาต์, จัดการสินค้า, หน้า POS/ตะกร้า, checkout แบบ transaction, ใบเสร็จ 80mm, รายงานยอดขาย/ประวัติบิลสำหรับ admin และงาน hardening (validate input, error handling กลาง, session/CSRF)

### โครงสร้างโฟลเดอร์และหน้าที่

| ตำแหน่ง | หน้าที่ | สถานะ |
|---|---|---|
| `index.php` | หน้าแรกที่ต้องล็อกอิน; แสดงชื่อและ role จาก session | ใช้งานจริง |
| `login.php` | หน้า/handler ของ login; ตรวจ credentials จาก `users` | ใช้งานจริง |
| `logout.php` | ล้าง session แล้ว redirect ไป login | ใช้งานจริง |
| `pos.php` | หน้าขายสินค้า: รับบาร์โค้ดและแสดง/จัดการตะกร้า | ใช้งานจริง |
| `cart_add.php`, `cart_update.php`, `cart_remove.php`, `cart_get.php` | JSON endpoints สำหรับเพิ่ม/แก้จำนวน/ลบ/อ่าน session cart | ใช้งานจริง |
| `checkout.php` | ยืนยันชำระเงิน, สร้างบิล, ตัด stock และบันทึก stock movement ใน transaction | ใช้งานจริง |
| `products.php`, `product_*.php` | เพิ่ม/แสดง/ปิดการขายสินค้า โดยผู้ใช้ที่ล็อกอินทุก role | ใช้งานจริง |
| `receipt.php` | ใบเสร็จ 80mm ของบิลที่ระบุ; หน้าขายฝังเป็น iframe ซ่อนแล้วสั่งพิมพ์ ส่วน `autoprint` ใช้กับลิงก์ที่เปิดตรง | ใช้งานจริง |
| `report_sales.php` | สรุปยอดขายตามช่วงวันที่/พนักงาน — `admin` เท่านั้น | ใช้งานจริง |
| `order_history.php` | ประวัติบิลแบ่งหน้า + รายละเอียดรายการสินค้า — `admin` เท่านั้น | ใช้งานจริง |
| `config/` | constants ของแอป, error handler กลาง และ PDO connection | ใช้งานจริง |
| `includes/` | shared authentication/session guard, cart, money, page และ report helpers | ใช้งานจริง |
| `assets/` | CSS และ JavaScript ของทุกหน้า รวม `print.css` ของใบเสร็จ | ใช้งานจริง |
| `sql/schema.sql` | สร้าง schema และ seed admin | ใช้สำหรับ setup DB |
| `tests/concurrent_checkout.php` | สคริปต์ CLI ทดสอบ race condition ของสต็อก | รันด้วยมือ |
| `Database.txt` | flow transaction ของการขาย/ตัดสต็อก | เอกสารออกแบบ |
| `Flow.txt` | flow หน้าขายจนชำระเงิน | เอกสารออกแบบ |
| `.claude/` | การตั้งค่าของเครื่องมือพัฒนา ไม่ใช่ runtime | tooling |

### Entry points, routing และ endpoints ที่มีจริง

ไม่มี routing table หรือ front controller; เว็บเซิร์ฟเวอร์เรียกไฟล์ PHP โดยตรง และ `BASE_URL` ใน `config/settings.php` เป็น URL prefix (ค่า `/`).

| Route | Method | การทำงาน | ผลลัพธ์ |
|---|---|---|---|
| `/login.php` | GET | ถ้ามี session ให้ redirect ไป index; ไม่เช่นนั้นแสดงฟอร์ม | HTML/redirect |
| `/login.php` | POST | validate input, query active user, `password_verify`, regenerate session และเก็บ identity | redirect/HTML error |
| `/index.php` | GET | `require_login()` แล้ว render หน้าแรก | HTML/redirect |
| `/logout.php` | GET | ล้างและทำลาย session | redirect |
| `/pos.php` | GET | `require_login()` และ render หน้าขาย/ตะกร้า | HTML/redirect |
| `/cart_get.php` | GET | อ่าน session cart และคำนวณยอดรวม | JSON |
| `/cart_add.php` | POST | ตรวจ CSRF, สินค้า active และ stock ก่อนเพิ่มจาก barcode | JSON |
| `/cart_update.php` | POST | ตรวจ CSRF และ stock ก่อนแก้จำนวนสินค้า | JSON |
| `/cart_remove.php` | POST | ตรวจ CSRF แล้วลบสินค้าออกจาก session cart | JSON |
| `/checkout.php` | POST | ตรวจ input/cart/stock, lock แถวสินค้า, บันทึกบิลและตัด stock ใน transaction | JSON |
| `/products.php` | GET | `require_login()` แล้ว render หน้าจัดการสินค้า | HTML/redirect |
| `/product_get.php`, `/product_add.php`, `/product_remove.php` | GET/POST | อ่าน/เพิ่ม/ปิดการขายสินค้า โดยตรวจ login และ CSRF สำหรับ mutation | JSON |
| `/receipt.php` | GET | อ่านบิลตาม `order_id` และตรวจสิทธิ์เจ้าของบิล/admin แล้วผ่อน `X-Frame-Options` เป็น `SAMEORIGIN` (หน้า error ยังเป็น `DENY`) | HTML (80mm) |
| `/report_sales.php`, `/order_history.php` | GET | `require_role('admin')` แล้วสรุปยอด/list บิลตามตัวกรองช่วงวันที่และพนักงาน | HTML |

หน้า POS ใช้ `fetch()` เรียก cart และ checkout endpoints โดยไม่ reload หน้า ส่วนใบเสร็จและหน้ารายงานเป็น server-rendered ล้วน ไม่มี AJAX

### ชั้นงานหลักและ dependency flow

ยังไม่มี service layer แบบ class แยก: business logic การล็อกอินอยู่ใน `login.php`; shared guard อยู่ใน `includes/auth.php`; ส่วน logic ที่ใช้ร่วมกันของ cart อยู่ใน `includes/cart.php`.

```
Browser
  ├─ GET/POST login.php ──► includes/auth.php ──► config/settings.php
  │                          └───────────────► config/db.php ──► MySQL: users
  ├─ GET index.php ───────► includes/auth.php ──► session user
  └─ GET logout.php ──────► includes/auth.php ──► destroy session
  └─ pos.php / cart_*.php ─► includes/cart.php ──► session cart / MySQL: products
```

| ไฟล์ | หน้าที่ |
|---|---|
| `config/settings.php` | `APP_ENV`, `VAT_RATE`, `STORE_*`, `SESSION_NAME`, `app_fail()` และ exception/shutdown handler กลาง |
| `config/db.php` | สร้าง PDO `$pdo`, exception mode, associative fetch, native prepared statements |
| `includes/auth.php` | ตั้ง session cookie flags + security headers และให้ `current_user()`, `is_logged_in()`, `require_login()`, `require_role()`, CSRF helpers |
| `includes/cart.php` | JSON response, CSRF validation, session-cart state, ตรวจสินค้า/stock และคำนวณยอดรวม |
| `includes/money.php` | validate/แปลงจำนวนเงินเป็นสตางค์ ใช้ร่วมกันโดย `checkout.php` และ `product_add.php` |
| `includes/page.php` | `page_esc()`, security headers และ `render_error_page()` |
| `includes/report.php` | validate ตัวกรองรายงาน, สร้างเงื่อนไข WHERE และ render ฟอร์ม/topbar ของหน้ารายงาน |
| `login.php` | validation เบื้องต้น, prepared `SELECT`, password verification และ session identity |
| `assets/js/pos.js` | เรียก JSON endpoints และ render ตะกร้าแบบไม่ reload |
| `assets/js/products.js` | เรียก API สำหรับเพิ่ม/ปิดการขายสินค้า และ render ตารางสินค้า |
| `assets/css/style.css` | presentation ของ auth, top bar, main และ POS; ไม่มี component library |

### Flow จากหน้าจอไป backend ที่มีจริง

1. ผู้ใช้เปิด `/login.php`; `includes/auth.php` โหลด config และเริ่ม session หากจำเป็น
2. เมื่อส่ง `POST /login.php`, server ตรวจ username/password แล้ว query `users` ด้วย prepared statement (`username = ? AND is_active = 1`)
3. เมื่อ `password_verify()` ผ่าน ระบบ regenerate session ID, เก็บ `{id, username, full_name, role}` ใน `$_SESSION['user']` และ redirect ไป `/index.php`
4. `index.php` เรียก `require_login()`; หากไม่มี session จะ redirect กลับ login, หากมีจะแสดงข้อมูลผ่าน `htmlspecialchars()`
5. `logout.php` ล้าง/ทำลาย session แล้ว redirect กลับ login
6. ที่ `/pos.php` ผู้ใช้ส่ง barcode ไป `cart_add.php`; server query `products`, ตรวจ stock, แล้วเก็บ/แก้ `$_SESSION['cart']`. JavaScript รับ JSON state กลับมาเพื่อ render ตะกร้าใหม่
7. เมื่อ checkout, `checkout.php` lock สินค้าด้วย `SELECT ... FOR UPDATE`, คำนวณ total/discount/VAT บน server, บันทึก `orders` + `order_items` + `stock_movements`, ตัด stock และ clear cart เฉพาะหลัง commit สำเร็จ

### ฐานข้อมูลที่ schema รองรับ

`sql/schema.sql` สร้างฐาน `pos_system` และตาราง `users`, `products`, `orders`, `order_items`, `stock_movements`. ความสัมพันธ์หลักคือ order → user, order item → order/product, และ stock movement → product/order. Runtime ปัจจุบันใช้ครบทุกตาราง: `users` ตอน login และเป็นตัวกรองพนักงานในรายงาน, `products` สำหรับ cart/สต็อก, `orders`+`order_items` สำหรับบิล ใบเสร็จ และรายงาน, `stock_movements` เป็น audit trail ตอน checkout

### ความสัมพันธ์กับแบบออกแบบด้านล่าง

เนื้อหาหัวข้อ 1–8 ด้านล่างเป็น target architecture ที่สร้างครบแล้วทุกเฟส (0–6) ดูสถานะรายเฟสได้ที่ตารางในหัวข้อ 8

---

## แบบออกแบบและแผนพัฒนาในอนาคต

> พัฒนาด้วย PHP 8 (PDO) / MySQL / CSS
> อ้างอิง Flow การขายจาก `Flow.txt` และ Flow การบันทึกลง DB จาก `Database.txt`

---

## 1. ภาพรวม Flow การทำงาน

1. เปิดหน้า POS → พนักงานสแกน/เลือกสินค้า
2. ระบบเช็คสต็อกจาก DB แบบ real-time (ผ่าน Fetch API)
   - สต็อก ≤ 0 → แจ้งเตือน ขายไม่ได้
   - สต็อก > 0 → เพิ่มลงตะกร้า (เก็บใน Session)
3. คำนวณยอดรวม / ส่วนลด / ภาษี ทุกครั้งที่ตะกร้าเปลี่ยน
4. กด Checkout → เลือกวิธีชำระเงิน → ตรวจสอบยอดเงินรับ
5. ยอดพอ → คำนวณเงินทอน → บันทึกลง MySQL แบบ **Transaction** (กัน stock/order ไม่ตรงกันถ้า error กลางทาง)
6. Commit สำเร็จ → พิมพ์ใบเสร็จ → เคลียร์ตะกร้า → พร้อมรับลูกค้าคนถัดไป

---

## 2. โครงสร้างฐานข้อมูล (Database Schema)

ออกแบบทั้งหมด **5 ตาราง** (4 ตารางหลัก `users`, `products`, `orders`, `order_items` ที่จำเป็นต่อ Flow จริงรวมถึงระบบ Login + 1 ตารางเสริม `stock_movements` สำหรับ audit trail สต็อกเชิงลึก) MVP ที่ครบ Flow ตามที่ตัดสินใจไว้ต้องมีอย่างน้อย 4 ตารางหลัก (มีระบบ login ตามข้อกำหนด)

### 2.1 ตาราง `products`

เก็บข้อมูลสินค้าและสต็อกปัจจุบัน (ใช้เป็นแหล่งความจริงเดียว — Single Source of Truth — สำหรับการตรวจสต็อกก่อนขาย)

```sql
CREATE TABLE products (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    barcode     VARCHAR(50)     NOT NULL UNIQUE,
    name        VARCHAR(150)    NOT NULL,
    price       DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    stock_qty   INT             NOT NULL DEFAULT 0,
    is_active   TINYINT(1)      NOT NULL DEFAULT 1,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_barcode (barcode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

| คอลัมน์ | หน้าที่ |
|---|---|
| `barcode` | ใช้ค้นหาสินค้าตอนสแกน (Unique เพื่อกันบาร์โค้ดซ้ำ) |
| `stock_qty` | ค่าที่ถูก "ตัด" ทุกครั้งที่ commit บิลสำเร็จ ตรงกับขั้นตอน `UPDATE products SET stock = stock - qty` ใน Database.txt |
| `is_active` | ซ่อนสินค้าที่เลิกขายโดยไม่ต้องลบข้อมูล (กันปัญหา FK กับ order_items เก่า) |

### 2.2 ตาราง `users`

พนักงาน/ผู้ดูแลระบบสำหรับ login เข้าใช้งานระบบ POS

```sql
CREATE TABLE users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name     VARCHAR(100) NOT NULL,
    role          ENUM('admin','staff') NOT NULL DEFAULT 'staff',
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

| คอลัมน์ | หน้าที่ |
|---|---|
| `password_hash` | เก็บ hash จาก `password_hash()` ของ PHP เท่านั้น (ห้ามเก็บ plaintext) ตรวจสอบด้วย `password_verify()` ตอน login |
| `role` | `staff` = ขายของหน้า POS ได้อย่างเดียว, `admin` = เข้าหน้ารายงานยอดขาย/ประวัติบิล และจัดการสินค้า/พนักงานเพิ่มเติมได้ |
| `is_active` | ปิดสิทธิ์การใช้งานพนักงานที่ลาออกโดยไม่ต้องลบ (รักษา FK กับ `orders.user_id`) |

### 2.3 ตาราง `orders`

หัวบิล — 1 บิล = 1 แถว

```sql
CREATE TABLE orders (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_number    VARCHAR(30)     NOT NULL UNIQUE,
    user_id         INT UNSIGNED    NULL,
    total_amount    DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    tax_amount      DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    net_amount      DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    payment_method  ENUM('cash','qr','card') NOT NULL,
    received_amount DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    change_amount   DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    status          ENUM('completed','voided') NOT NULL DEFAULT 'completed',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_orders_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

| คอลัมน์ | หน้าที่ |
|---|---|
| `order_number` | เลขที่บิลสำหรับแสดงบนใบเสร็จ (แนะนำ format `YYYYMMDD-0001`) |
| `user_id` | พนักงาน/ผู้ที่ออกบิลนี้ (ดึงจาก session หลัง login) ใช้กรองรายงานยอดขายแยกตามพนักงานได้ `ON DELETE SET NULL` เพื่อไม่ให้ลบพนักงานแล้วบิลเก่าหาย |
| `total_amount` | ยอดรวมสินค้าก่อนหักส่วนลด (ยังไม่รวม VAT) |
| `discount_amount` | **ส่วนลดแบบจำนวนเงิน (บาท)** ไม่ใช่เปอร์เซ็นต์ — พนักงานกรอกจำนวนเงินส่วนลดตรงๆ ตอน checkout (ต้องไม่เกิน `total_amount`) |
| `tax_amount` / `net_amount` | ภาษี (VAT) คำนวณแยกตอน checkout จากยอดหลังหักส่วนลด ดูสูตรคำนวณเต็มในหัวข้อ 4 |
| `received_amount` / `change_amount` | รองรับขั้นตอน "ตรวจสอบยอดเงินรับ" และ "คำนวณเงินทอน" |
| `status` | เผื่อกรณียกเลิกบิลย้อนหลัง (ไม่ลบข้อมูลจริงเพื่อรักษา audit trail) |

### 2.4 ตาราง `order_items`

รายการสินค้าภายในบิล — 1 บิลมีได้หลายแถว

```sql
CREATE TABLE order_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id        INT UNSIGNED NOT NULL,
    product_id      INT UNSIGNED NOT NULL,
    product_name    VARCHAR(150) NOT NULL,
    qty             INT UNSIGNED NOT NULL,
    price_at_sale   DECIMAL(10,2) NOT NULL,
    subtotal        DECIMAL(10,2) NOT NULL,

    CONSTRAINT fk_order_items_order
        FOREIGN KEY (order_id)   REFERENCES orders(id)   ON DELETE CASCADE,
    CONSTRAINT fk_order_items_product
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,

    INDEX idx_order_id (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

| คอลัมน์ | หน้าที่ |
|---|---|
| `price_at_sale` | **สำคัญมาก** — เก็บราคา ณ เวลาขายจริง ไม่ใช้ราคาปัจจุบันจาก `products` เพราะถ้าร้านปรับราคาสินค้าในอนาคต ใบเสร็จเก่าต้องยังถูกต้องเสมอ |
| `product_name` | เก็บชื่อสินค้า ณ เวลาขาย (denormalized) เผื่อกรณีสินค้าถูกเปลี่ยนชื่อ/ลบภายหลัง ใบเสร็จเก่ายังอ่านได้ |
| `ON DELETE RESTRICT` (product) | ห้ามลบสินค้าที่เคยถูกขายไปแล้ว เพื่อรักษาความถูกต้องของประวัติการขาย (ใช้ `is_active = 0` แทนการลบ) |
| `ON DELETE CASCADE` (order) | ถ้าลบบิล ให้ลบรายการสินค้าของบิลนั้นตามไปด้วย |

### 2.5 (เสริม) ตาราง `stock_movements` — Log การตัด/เติมสต็อก

ไม่บังคับตาม requirement เดิม แต่แนะนำถ้าต้องการ audit trail ว่าสต็อกเปลี่ยนจากอะไรบ้าง (ขาย, รับของเข้า, ปรับสต็อก)

```sql
CREATE TABLE stock_movements (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id  INT UNSIGNED NOT NULL,
    change_qty  INT NOT NULL,               -- ลบ = ตัดสต็อก, บวก = เติมสต็อก
    reason      ENUM('sale','restock','adjustment') NOT NULL,
    ref_order_id INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_stock_movements_product
        FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_stock_movements_order
        FOREIGN KEY (ref_order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

> **แนะนำ:** เริ่มจาก 4 ตารางหลัก (`users`, `products`, `orders`, `order_items`) ให้ระบบทำงานได้ครบ Flow พร้อมระบบ login ก่อน แล้วค่อยเพิ่ม `stock_movements` ทีหลังถ้าต้องการรายงานเชิงลึก

### 2.6 ตาราง `held_bills` — หัวบิลที่พักไว้ชั่วคราว

รองรับกรณี "ลูกค้าลืมหยิบของ" — พักตะกร้าไว้เพื่อคิดเงินลูกค้าคนถัดไปก่อน แล้วค่อยเรียกกลับมาคิดต่อ

**บิลพักไม่ใช่บิลขาย** จึงแยกตารางออกจาก `orders` ไม่ใช้ `orders.status` เพิ่ม เพราะจะไปเปลืองเลข `order_number` ที่เป็น UNIQUE, ชนกับ `payment_method` ที่เป็น NOT NULL และเล็ดลอดเข้าไปในหน้าประวัติบิล

```sql
CREATE TABLE held_bills (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hold_number VARCHAR(20)     NULL UNIQUE,   -- 'HOLD-0001'
    user_id     INT UNSIGNED    NULL,
    note        VARCHAR(100)    NOT NULL DEFAULT '',
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_held_bills_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

`hold_number` เป็น `NULL UNIQUE` เพราะเลขบิลได้จาก `AUTO_INCREMENT`: INSERT ด้วยค่า `NULL` ก่อน แล้ว `UPDATE` ด้วย `lastInsertId()` ภายในทรานแซกชันเดียวกัน MySQL อนุญาตให้ค่า `NULL` ซ้ำกันใน UNIQUE index จึงไม่ชนกันแม้พักบิลพร้อมกันหลายเครื่อง และไม่ต้องใช้ `MAX()+1` พร้อม retry loop

### 2.7 ตาราง `held_bill_items` — รายการสินค้าของบิลพัก

```sql
CREATE TABLE held_bill_items (
    id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    held_bill_id  INT UNSIGNED  NOT NULL,
    product_id    INT UNSIGNED  NOT NULL,
    barcode       VARCHAR(50)   NOT NULL,
    product_name  VARCHAR(150)  NOT NULL,
    qty           INT UNSIGNED  NOT NULL,
    price_at_hold DECIMAL(10,2) NOT NULL,

    CONSTRAINT fk_held_bill_items_bill
        FOREIGN KEY (held_bill_id) REFERENCES held_bills(id) ON DELETE CASCADE,
    CONSTRAINT fk_held_bill_items_product
        FOREIGN KEY (product_id)   REFERENCES products(id)   ON DELETE CASCADE,

    UNIQUE KEY uq_held_bill_product (held_bill_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

ต่างจาก `order_items` สามจุดโดยตั้งใจ:

| จุด | `order_items` | `held_bill_items` | เหตุผล |
|---|---|---|---|
| FK `product_id` | `RESTRICT` | `CASCADE` | บิลขายเป็นหลักฐานถาวรที่ห้ามขาดการอ้างอิง ส่วนบิลพักเป็นข้อมูลชั่วคราวที่ไม่ควรไปบล็อกการลบสินค้า |
| คอลัมน์ `subtotal` | มี | ไม่มี | ยอดของบิลพักคำนวณใหม่จากราคาปัจจุบันตอนเรียกคืนเสมอ เก็บไว้ก็มีแต่จะเก่า |
| index | `idx_order_id` | `UNIQUE (held_bill_id, product_id)` | ตะกร้าใน session ใช้ product_id เป็น key อยู่แล้ว จึงมีได้บรรทัดเดียวต่อสินค้า และคอลัมน์แรกใช้เป็น index ของ FK ได้ในตัว |

`price_at_hold` ใช้ **แสดงผลและ audit เท่านั้น** ตอนเรียกคืนต้องอ่านราคาสดจาก `products` เสมอ เพราะ `checkout.php` ก็อ่านราคาจาก DB ไม่สนค่าที่อยู่ในตะกร้า — ถ้าคืนราคาเก่าเข้าไป หน้าจอจะโชว์เลขหนึ่งแต่เก็บเงินจริงอีกเลขหนึ่ง

### 2.8 แผนภาพความสัมพันธ์ (ER Diagram)

```
users (1) ────────< (many) orders (1) ────< (many) order_items (many) >──── (1) products
   id                        id                        order_id                    id
   username                  user_id ──────┘            product_id ────────────────┘
   role                      order_number

users (1) ────< (many) held_bills (1) ────< (many) held_bill_items (many) >──── (1) products
   id                       id                          held_bill_id                  id
                            user_id ──────┘             product_id ───────────────────┘
                            hold_number
```

สายบิลพักแยกขาดจากสายบิลขาย — ไม่มีความสัมพันธ์ระหว่าง `held_bills` กับ `orders` เลย บิลพักถูกลบทิ้งตอนเรียกคืน ไม่ได้แปลงร่างเป็นบิลขาย

---

## 3. การจัดการตะกร้าสินค้า (Cart Management)

**แนวทางที่แนะนำ: PHP Session (`$_SESSION['cart']`) + Fetch API**

เหตุผล: ปลอดภัยกว่า localStorage (ราคาสินค้าไม่ถูกฝั่ง client ปลอมแปลงได้ง่าย เพราะทุกครั้งที่เพิ่มสินค้า ระบบจะ query ราคาจริงจาก DB ฝั่ง server เสมอ) และไม่ต้องพึ่ง JavaScript state ทั้งหมด

### โครงสร้างข้อมูลใน Session

```php
$_SESSION['cart'] = [
    // product_id => item
    12 => [
        'product_id' => 12,
        'barcode'    => '8850001234567',
        'name'       => 'น้ำดื่ม 600ml',
        'price'      => 10.00,
        'qty'        => 2,
    ],
    45 => [
        'product_id' => 45,
        'barcode'    => '8850009876543',
        'name'       => 'ขนมปัง',
        'price'      => 25.00,
        'qty'        => 1,
    ],
];
```

### การไหลของข้อมูล (ไม่ต้อง reload หน้า)

```
[ พนักงานสแกนบาร์โค้ด ]
        │  JS: fetch('cart_add.php', {method:'POST', body: barcode})
        ▼
[ cart_add.php ]
   1. หา product จาก barcode ใน DB
   2. เช็ค stock_qty > 0 ?  ไม่พอ → return JSON error
   3. เพิ่ม/เพิ่มจำนวนใน $_SESSION['cart']
   4. คำนวณยอดรวมใหม่ทั้งหมด (คำนวณที่ server เท่านั้น)
   5. return JSON: { success, cart, total_amount }
        │
        ▼
[ JS รับ JSON → render ตะกร้าใหม่บนหน้าเว็บ (ไม่ reload) ]
```

### Endpoint ที่ควรมี (ตัวอย่าง)

| Endpoint | หน้าที่ |
|---|---|
| `cart_add.php` | เพิ่มสินค้าลงตะกร้า (เช็ค stock ก่อนเสมอ) |
| `cart_update.php` | แก้จำนวนสินค้าในตะกร้า |
| `cart_remove.php` | ลบสินค้าออกจากตะกร้า |
| `cart_get.php` | ดึงตะกร้าปัจจุบัน (เผื่อ sync ตอน refresh หน้า) |
| `checkout.php` | ประมวลผลการชำระเงิน + บันทึกลง MySQL (Transaction) + เคลียร์ session cart |

> **หลักการสำคัญ:** ราคาและการเช็คสต็อกต้องคำนวณ/ตรวจสอบที่ฝั่ง Server (PHP) เสมอ ห้ามเชื่อค่าที่ส่งมาจาก client เพราะสามารถถูกแก้ไขผ่าน DevTools ได้

### 3.1 พักบิล (Hold / Park) — ตะกร้ามากกว่าหนึ่งใบพร้อมกัน

ข้อจำกัดของการเก็บตะกร้าใน session คือ **มีได้ใบเดียวต่อ session** พอลูกค้าลืมหยิบของแล้วเดินกลับไปหยิบ แคชเชียร์จะติดอยู่กับตะกร้าเดิมและคิดเงินคนถัดไปไม่ได้ ทั้งคิวจึงหยุดรอคนเดียว

ทางแก้คือย้ายตะกร้าที่ยังไม่คิดเงินไป "พัก" ไว้ใน DB ชั่วคราว (ตาราง `held_bills` / `held_bill_items` หัวข้อ 2.6–2.7) แล้วล้าง session cart เพื่อเริ่มบิลใหม่ทันที

```
[ ลูกค้าลืมหยิบของ ]
        │
        ▼
[ พักบิล ] → held_bill_park.php → snapshot ตะกร้าลง DB → cart_set_items([])
        │
        ▼
[ คิดลูกค้าคนต่อไป ] → ตะกร้าว่าง ใช้งานได้ตามปกติ → checkout.php
        │
        ▼
[ กลับมาคิดต่อ ] → held_bill_resume.php → ยึดบิล + ตรวจสินค้าใหม่ → เขียนกลับเป็น session cart
```

**ข้อตกลงการออกแบบ**

- **บิลพักเป็นของส่วนกลาง** แคชเชียร์คนไหนก็เรียกคืนได้ (ร้านที่สลับเครื่อง/สลับกะ) หน้าจอจึงแสดงชื่อคนพักและเวลากำกับไว้
- **ไม่จองสต็อก** การพักบิลไม่ใช่การขาย ตะกร้าที่พักไว้อาจขายไม่ได้ถ้าคนอื่นซื้อของหมดไปก่อน — ตรวจจริงใต้ `FOR UPDATE` ตอน checkout เท่านั้น
- **ไม่เก็บส่วนลดและโหมด VAT** พักเฉพาะรายการสินค้า เรียกคืนแล้วต้องกรอกส่วนลดและเลือกโหมด VAT ใหม่
- **ตอนเรียกคืนต้องตรวจสินค้าใหม่ทุกบรรทัด** สินค้าอาจถูกปิดการขายหรือสต็อกลดลงระหว่างที่บิลพักอยู่ กติกาคือ ปิดการขาย/สต็อกหมด = ตัดบรรทัดทิ้ง, สต็อกไม่พอ = ลดจำนวนเท่าที่มี, ราคาต่าง = ใช้ราคาปัจจุบัน แล้วสรุปทั้งหมดเป็นข้อความแจ้งแคชเชียร์
- **การเรียกคืนต้องเป็น atomic** ใช้ `SELECT ... FOR UPDATE` แล้ว `DELETE` โดยตรวจ `rowCount() === 1` เป็นการ "ยึด" บิล ถ้าแคชเชียร์สองคนกดพร้อมกัน จะได้ตะกร้าไปแค่คนเดียว อีกคนได้ 404
- **ถ้าตะกร้าปัจจุบันยังมีของ ระบบจะไม่เดาแทนผู้ใช้** ตอบ 409 (`code: cart_not_empty`) ให้หน้าจอถามก่อน แล้วค่อยส่ง `on_conflict = park` มาเพื่อพักตะกร้าปัจจุบันและเรียกบิลใหม่ **ในทรานแซกชันเดียวกัน** — ห้ามรวมสองตะกร้าเข้าด้วยกันเด็ดขาด เพราะจะได้บิลผิดที่สังเกตไม่เห็น

---

## 4. การบันทึกข้อมูลลง MySQL (Transaction)

ตรงตาม Flow ใน `Database.txt` — ใช้ PDO Transaction เพื่อรับประกันว่า "บันทึกบิล" กับ "ตัดสต็อก" ต้องสำเร็จไปด้วยกันทั้งคู่ หรือไม่สำเร็จเลยทั้งคู่ (Atomicity)

### 4.1 สูตรคำนวณส่วนลด + ภาษี (VAT)

ราคาสินค้าใน `products.price` เป็น**ราคาไม่รวมภาษี**เสมอ (ค่าที่เก็บใน DB ไม่เปลี่ยนตามโหมด) ส่วนลดเป็น**จำนวนเงิน (บาท)** ที่พนักงานกรอกตอน checkout ส่วน VAT จะถูกคำนวณแยกต่างหากในขั้นตอนนี้เท่านั้น (ไม่ปะปนกับราคาสินค้าต่อชิ้น)

หน้าขายสินค้า (`pos.php`) มีตัวเลือก **`vat_mode`** ให้แคชเชียร์เลือกได้ **ต่อบิล** (ไม่ persist เป็น session/global — ส่งมาใหม่ทุกครั้งที่พรีวิวยอดหรือกดชำระเงิน) มี 2 ค่า: `inclusive` (ค่าเริ่มต้น) และ `exclusive` ทั้งสองโหมดคำนวณผ่านฟังก์ชันเดียวกัน `money_order_totals()` ใน `includes/money.php` ซึ่ง `checkout.php` และ `cart_totals.php` เรียกใช้ร่วมกัน ตัวเลขจึงตรงกันเสมอไม่ว่าจะเป็นโหมดไหน ไม่มีการเพิ่มคอลัมน์ `vat_mode` ในตาราง `orders` — ค่า `tax_amount`/`net_amount` ที่บันทึกไว้ก็สะท้อนผลของโหมดที่เลือกอยู่แล้ว (ข้อจำกัด: บิลเก่าจะดูย้อนหลังไม่ได้ว่าใช้โหมดไหน เห็นได้แค่ตัวเลขที่คำนวณออกมา)

**โหมด `exclusive` (ราคาไม่รวม VAT) — บวก VAT เพิ่มจากยอดหลังหักส่วนลด:**

```
total_amount   = Σ(price_at_sale × qty)         // ยอดรวมสินค้าก่อนหักส่วนลด
taxable_base   = total_amount − discount_amount  // ฐานภาษีหลังหักส่วนลด (discount ≤ total_amount)
tax_amount     = taxable_base × VAT_RATE          // VAT_RATE = 0.07 (ค่าคงที่ใน config/settings.php)
net_amount     = taxable_base + tax_amount        // ยอดสุทธิที่ลูกค้าต้องชำระ
change_amount  = received_amount − net_amount
```

**โหมด `inclusive` (ราคารวม VAT) — แยกภาษีออกจากยอดหลังหักส่วนลดโดยไม่บวกเพิ่ม:**

```
total_amount   = Σ(price_at_sale × qty)          // ยอดรวมสินค้าก่อนหักส่วนลด (รวม VAT อยู่แล้ว)
taxable_base   = (total_amount − discount_amount) × 1/(1 + VAT_RATE)   // แยกฐานภาษีออกจากยอด
tax_amount     = (total_amount − discount_amount) − taxable_base       // ส่วนที่เป็น VAT
net_amount     = total_amount − discount_amount   // ไม่บวก VAT เพิ่ม — ยอดที่ลูกค้าจ่ายเท่ากับยอดหลังหักส่วนลด
change_amount  = received_amount − net_amount
```

> `VAT_RATE` เก็บเป็นค่าคงที่ (`define('VAT_RATE', 0.07)`) ใน `config/settings.php` ไม่ต้องมีตาราง config แยกสำหรับ MVP หากอนาคตอัตราภาษีเปลี่ยน แก้ที่จุดเดียว ทั้งสองโหมดปัดเศษ VAT แบบครึ่งขึ้นด้วยจำนวนเต็มล้วน (หน่วยสตางค์) ไม่ใช้ float

```php
<?php
// checkout.php
require __DIR__ . '/includes/auth.php';
require_login(); // ต้อง login ก่อนถึงจะ checkout ได้ ดูหัวข้อ 8 เฟส 1

try {
    $pdo->beginTransaction();

    // 1) เช็คสต็อกล่าสุดอีกครั้งก่อนตัด (กันกรณีมีคนซื้อพร้อมกัน - race condition)
    foreach ($_SESSION['cart'] as $item) {
        $stmt = $pdo->prepare(
            'SELECT stock_qty FROM products WHERE id = ? FOR UPDATE'
        );
        $stmt->execute([$item['product_id']]);
        $stock = $stmt->fetchColumn();

        if ($stock === false || $stock < $item['qty']) {
            throw new RuntimeException("สินค้า {$item['name']} มีไม่พอ");
        }
    }

    // 2) INSERT หัวบิล (total/discount/tax/net คำนวณตามสูตรในหัวข้อ 4.1)
    $stmt = $pdo->prepare(
        'INSERT INTO orders (order_number, user_id, total_amount, discount_amount,
            tax_amount, net_amount, payment_method, received_amount, change_amount)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $orderNumber, $_SESSION['user_id'], $totalAmount, $discountAmount,
        $taxAmount, $netAmount, $paymentMethod, $receivedAmount, $changeAmount,
    ]);
    $orderId = $pdo->lastInsertId();

    // 3) Loop INSERT รายการสินค้า + ตัดสต็อก
    $insertItem = $pdo->prepare(
        'INSERT INTO order_items (order_id, product_id, product_name, qty, price_at_sale, subtotal)
         VALUES (?,?,?,?,?,?)'
    );
    $deductStock = $pdo->prepare(
        'UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?'
    );

    foreach ($_SESSION['cart'] as $item) {
        $subtotal = $item['price'] * $item['qty'];
        $insertItem->execute([
            $orderId, $item['product_id'], $item['name'],
            $item['qty'], $item['price'], $subtotal,
        ]);
        $deductStock->execute([$item['qty'], $item['product_id']]);
    }

    $pdo->commit();

    unset($_SESSION['cart']); // เคลียร์ตะกร้า

    echo json_encode(['success' => true, 'order_id' => $orderId]);

} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
```

> **จุดสำคัญ:** ใช้ `SELECT ... FOR UPDATE` ตอนเช็คสต็อกซ้ำใน transaction เพื่อ lock แถวกันปัญหาลูกค้า 2 คนซื้อสินค้าชิ้นสุดท้ายพร้อมกัน (race condition) ซึ่งเป็นเคสที่ Flow.txt ไม่ได้พูดถึงแต่จำเป็นในระบบจริง

---

## 5. หน้าพิมพ์ใบเสร็จ (CSS Print)

ใช้ `@media print` ซ่อนทุกอย่างยกเว้นส่วนใบเสร็จ และปรับความกว้างให้พอดีกระดาษสลิปความร้อน

### HTML โครงสร้าง

```html
<body>
  <nav id="pos-navbar">...เมนู/ปุ่มต่างๆ...</nav>
  <main id="pos-main">...หน้าจอขายสินค้า...</main>

  <div id="receipt-section">
    <div class="receipt-header">
      <h3>ชื่อร้านค้า</h3>
      <p>เลขที่บิล: <?= $orderNumber ?></p>
      <p><?= $createdAt ?></p>
    </div>

    <table class="receipt-items">
      <?php foreach ($items as $it): ?>
      <tr>
        <td><?= $it['product_name'] ?></td>
        <td><?= $it['qty'] ?></td>
        <td><?= number_format($it['subtotal'], 2) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>

    <div class="receipt-total">
      <p>รวม: <?= number_format($netAmount, 2) ?> บาท</p>
      <p>รับเงิน: <?= number_format($receivedAmount, 2) ?></p>
      <p>เงินทอน: <?= number_format($changeAmount, 2) ?></p>
    </div>
  </div>
</body>
```

### CSS

```css
/* ----- ใช้แสดงผลปกติ (จอคอม) ----- */
#receipt-section {
    display: none; /* ซ่อนไว้ปกติ แสดงเฉพาะตอนพิมพ์ */
}

/* ----- เฉพาะตอนสั่งพิมพ์ ----- */
@media print {
    body * {
        visibility: hidden;
    }

    #receipt-section,
    #receipt-section * {
        visibility: visible;
    }

    #receipt-section {
        display: block;
        position: absolute;
        left: 0;
        top: 0;
        width: 80mm;          /* เปลี่ยนเป็น 58mm ตามขนาดเครื่องพิมพ์ */
        font-family: 'TH Sarabun New', monospace;
        font-size: 12px;
        color: #000;
    }

    .receipt-header {
        text-align: center;
        margin-bottom: 6px;
    }

    .receipt-items {
        width: 100%;
        border-collapse: collapse;
        font-size: 11px;
    }

    .receipt-items td {
        padding: 2px 0;
    }

    .receipt-total {
        border-top: 1px dashed #000;
        margin-top: 6px;
        padding-top: 4px;
        font-weight: bold;
    }

    /* ตัดขอบ/เงา/สีพื้นหลังทุกอย่างที่ไม่จำเป็นตอนพิมพ์ */
    @page {
        margin: 0;
    }
}
```

> **หมายเหตุ:** ของจริงที่ทำไว้ไม่ได้เรียก `window.print()` จากหน้าขายตรง ๆ — หลัง `checkout.php` ตอบ `success: true` แล้ว `pos.js` จะโหลด `receipt.php` ลง iframe ซ่อน (`.receipt-print-frame`) ตรวจว่ามี `#receipt-section` จริงก่อน แล้วจึงเรียก `contentWindow.print()` การตรวจนี้กันไม่ให้หน้า error ของ `render_error_page()` ถูกสั่งพิมพ์ออกมาเป็นกระดาษ และถ้าพิมพ์ไม่สำเร็จจะมีลิงก์ "พิมพ์ใบเสร็จอีกครั้ง" เป็น fallback เสมอ

---

## 6. สรุปไฟล์ที่ควรมีในโปรเจกต์ (แนะนำโครงสร้าง)

```
project/
├── config/
│   ├── db.php                  # PDO connection
│   └── settings.php            # VAT_RATE และค่าคงที่อื่นๆ
├── includes/
│   ├── auth.php                # require_login(), require_role('admin'), session guard
│   ├── header.php
│   └── footer.php
├── login.php                   # ฟอร์ม login พนักงาน/ผู้ดูแลระบบ
├── logout.php                  # ทำลาย session แล้ว redirect ไป login.php
├── pos.php                     # หน้าขายหลัก (เลือกสินค้า + ตะกร้า) — ต้อง login ก่อน
├── cart_add.php                 # AJAX: เพิ่มสินค้าลงตะกร้า
├── cart_update.php              # AJAX: แก้จำนวน
├── cart_remove.php              # AJAX: ลบสินค้าออกจากตะกร้า
├── checkout.php                 # AJAX: บันทึกบิล + ตัดสต็อก (Transaction)
├── receipt.php                  # แสดง/พิมพ์ใบเสร็จของบิลที่เพิ่งขาย
├── report_sales.php             # รายงานยอดขาย สรุปตามช่วงวัน/พนักงาน — admin เท่านั้น
├── order_history.php            # ประวัติบิลย้อนหลัง (list + ดูรายละเอียดบิล) — admin เท่านั้น
├── assets/
│   ├── css/
│   │   ├── style.css
│   │   └── print.css           # @media print
│   └── js/
│       └── pos.js              # fetch() เรียก endpoint ต่างๆ
└── sql/
    └── schema.sql              # SQL สร้างตารางทั้งหมดด้านบน (รวม users)
```

---

## 7. สรุปการตัดสินใจ (ตัดสินใจแล้ว)

| ประเด็น | การตัดสินใจ | รายละเอียดเพิ่มเติม |
|---|---|---|
| ระบบ Login พนักงาน/ผู้ดูแลระบบ | ✅ มี | ตาราง `users` (หัวข้อ 2.2), `login.php`/`logout.php`/`includes/auth.php`, สิทธิ์ `staff` และ `admin` |
| รูปแบบส่วนลด | ✅ จำนวนเงิน (บาท) | คอลัมน์ `orders.discount_amount` เดิมใช้ได้ทันที — ดูหัวข้อ 2.3 |
| การคำนวณ VAT | ✅ คำนวณแยกตอน checkout | ราคาสินค้าไม่รวมภาษี, สูตรคำนวณเต็มในหัวข้อ 4.1 |
| ขนาดกระดาษสลิป | ✅ 80mm | ค่าเริ่มต้นใน CSS หัวข้อ 5 ตั้งเป็น 80mm อยู่แล้ว ไม่ต้องแก้ |
| หน้ารายงานยอดขาย/ประวัติบิล | ✅ มี | `report_sales.php` + `order_history.php` ใช้ตาราง `orders`+`order_items` เดิม กรองตาม `orders.user_id` ได้ด้วย — ดูหัวข้อ 6 และ 8 |

---

## 8. แผนการพัฒนาแบ่งเฟส (Development Phases)

แบ่งงานเป็น 8 เฟส (0–7) เรียงตามลำดับที่ควรพัฒนา แต่ละเฟสอ้างอิงตาราง/endpoint ที่ออกแบบไว้แล้วในหัวข้อ 2, 4, 5 และ 6 ด้านบน — ไม่มีการออกแบบซ้ำซ้อน ทำเฟสก่อนหน้าให้เสร็จและทดสอบผ่านก่อนขึ้นเฟสถัดไป

| เฟส | เป้าหมาย | งานหลัก | ไฟล์/ตารางที่เกี่ยวข้อง | เกณฑ์ว่าเสร็จ (Definition of Done) |
|---|---|---|---|---|
| ✅ **0. Foundation** | โครงโปรเจกต์พร้อมต่อฐานข้อมูล | สร้าง `sql/schema.sql` ครบทุกตาราง (`users`, `products`, `orders`, `order_items`, และ `stock_movements` ถ้าต้องการ), เขียน `config/db.php` (PDO + `ERRMODE_EXCEPTION`), `config/settings.php` (ตั้ง `VAT_RATE`) | หัวข้อ 2, `config/db.php`, `sql/schema.sql` | import schema เข้า MySQL สำเร็จ, เชื่อมต่อ DB จาก PHP ได้ |
| ✅ **1. Auth** | ระบบ login พนักงาน/ผู้ดูแลระบบ | ตาราง `users` + seed แอดมิน 1 คน (`password_hash()`), `login.php` (ตรวจ username/password), `logout.php`, `includes/auth.php` (`require_login()`, `require_role('admin')`) | `login.php`, `logout.php`, `includes/auth.php` | login/logout ได้จริง, เข้าหน้าที่ป้องกันไว้โดยไม่ login แล้วถูกเด้งไป `login.php` |
| ✅ **2. Product & Cart** | ขายของหน้าจอหลักได้ (ยังไม่ checkout) | หน้า `pos.php` (ต้อง login), `cart_add/update/remove/get.php`, เช็ค `stock_qty` real-time ผ่าน `$_SESSION['cart']` | หัวข้อ 3, `pos.php`, `cart_*.php` | เพิ่ม/ลด/ลบสินค้าในตะกร้าแบบไม่ reload หน้าได้, สต็อกไม่พอแจ้งเตือนถูกต้อง |
| ✅ **3. Checkout & Transaction** | บันทึกบิลจริงลง DB | ฟอร์มกรอกส่วนลด (บาท) + เลือกวิธีชำระเงิน, คำนวณ VAT ตามสูตรหัวข้อ 4.1, `checkout.php` (PDO transaction + `SELECT...FOR UPDATE` กัน race condition + บันทึก `user_id` จาก session) | หัวข้อ 4, `checkout.php` | checkout สำเร็จบันทึกทั้ง `orders`+`order_items`+ตัดสต็อกถูกต้อง, ทดสอบกรณี error แล้ว rollback ไม่ทิ้งข้อมูลค้าง |
| ✅ **4. Receipt** | พิมพ์ใบเสร็จขนาด 80mm | `receipt.php` แสดงบิลที่เพิ่งขาย, `assets/css/print.css` (`@media print`, `width:80mm`), หลัง checkout สำเร็จ `pos.js` ฝัง `receipt.php` เป็น iframe ซ่อนแล้วเรียก `contentWindow.print()` | หัวข้อ 5, `receipt.php`, `print.css` | พิมพ์ใบเสร็จจากเบราว์เซอร์แล้วได้กระดาษ 80mm ตรงสัดส่วน ไม่มีเมนู/ปุ่มอื่นติดมา |
| ✅ **5. Reports** | รายงานยอดขาย/ประวัติบิล (admin เท่านั้น) | `report_sales.php` (สรุปยอดขายตามช่วงวันที่/พนักงาน, join `orders`+`order_items`), `order_history.php` (list บิลย้อนหลัง + ดูรายละเอียดรายการสินค้าต่อบิล) | หัวข้อ 2.3–2.4, `report_sales.php`, `order_history.php` | กรองรายงานตามช่วงวันที่ได้, ยอดรวมตรงกับข้อมูลใน `orders`, เข้าถึงได้เฉพาะ role `admin` |
| ✅ **6. Hardening** | พร้อมใช้งานจริง | validate input ทุก endpoint (ชนิดข้อมูล, ช่วงค่า เช่น `discount_amount ≤ total_amount`), เพิ่ม error handling ที่ผู้ใช้อ่านเข้าใจ, ทดสอบ race condition สต็อก (2 คำสั่งซื้อพร้อมกัน), ทดสอบพิมพ์ใบเสร็จบนเครื่องพิมพ์ความร้อนจริง | ทุกไฟล์ | ไม่มี query ที่รับค่าจาก client ตรงๆ โดยไม่ validate, ทดสอบ concurrent checkout ไม่ทำให้สต็อกติดลบ |
| ✅ **7. Hold Bill** | พักบิลไว้คิดลูกค้าคนต่อไปก่อน | ตาราง `held_bills`/`held_bill_items`, `includes/held_bill.php`, `held_bill_park/list/resume/delete.php`, panel "บิลที่พักไว้" ใน `pos.php` | หัวข้อ 2.6–2.7 และ 3.1 | พักบิลแล้วตะกร้าว่างทันที, เรียกคืนได้จากทุกเครื่อง, สองคนกดเรียกคืนบิลเดียวกันได้ตะกร้าไปคนเดียว, สินค้าที่ปิดการขาย/ราคาเปลี่ยนถูกแจ้งตอนเรียกคืน |
