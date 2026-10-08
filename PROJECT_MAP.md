# Project Map — Mini POS & Billing System

> แผนที่โปรเจกต์นี้บันทึกจากโค้ดที่มีอยู่จริง ณ ปัจจุบัน และแยกสิ่งที่เป็นแผนพัฒนาใน `SYSTEM_DESIGN.md` ไว้ชัดเจน

## สรุปสถาปัตยกรรม

เป็นเว็บ PHP 8 แบบ server-rendered ที่ไม่มี framework หรือ router กลาง: URL แต่ละรายการชี้ไปยังไฟล์ PHP โดยตรง ใช้ PHP session จัดการผู้ใช้/ตะกร้า และ PDO ติดต่อ MySQL/MariaDB. เฟส 0–6 ตาม `SYSTEM_DESIGN.md` สร้างครบแล้ว (Foundation, Auth, Product & Cart, Checkout, Receipt, Reports, Hardening)

```
Browser ──HTTP──► PHP page/handler ──► Session / PDO ──► MySQL
                         │
                         └────────────────────────────► HTML + CSS
```

## โครงสร้างโฟลเดอร์

```
Project PHP/
├── assets/
│   ├── css/
│   │   ├── style.css          # หน้าตา login, หน้าหลัก, POS และหน้ารายงาน
│   │   └── print.css          # ใบเสร็จ 80mm และ @media print
│   └── js/
│       ├── pos.js             # AJAX และ render ตะกร้า
│       └── products.js        # AJAX ของหน้าจัดการสินค้า
├── config/
│   ├── db.php                 # สร้าง PDO connection
│   ├── settings.php           # VAT, store info, session และ error handler กลาง
│   ├── env.development.php    # ค่า DB/BASE_URL ของ development
│   └── env.production.php.example
├── includes/
│   ├── auth.php               # เริ่ม session, security headers และ auth/role helpers
│   ├── cart.php               # JSON, CSRF และ session-cart helpers
│   ├── held_bill.php          # พักบิล: snapshot ตะกร้า, สร้าง/อ่านบิลพัก และส่ง state กลับ
│   ├── money.php              # แปลงจำนวนเงิน ↔ สตางค์, สูตร VAT (money_order_totals) และ validate รูปแบบเงิน
│   ├── page.php               # escape, security headers และหน้า error กลาง
│   ├── product.php            # validate ข้อมูลสินค้าชุดเดียวที่ product_add.php/product_update.php ใช้ร่วมกัน
│   └── report.php             # ตัวกรอง, เงื่อนไข WHERE และ UI ของหน้ารายงาน
├── sql/
│   └── schema.sql             # schema MySQL และ seed admin
├── tests/
│   ├── concurrent_checkout.php # สคริปต์ CLI ทดสอบ race condition ของสต็อก
│   ├── vat_breakdown.php      # สคริปต์ CLI ตรวจสูตร VAT ของ money_order_totals() ด้วยค่าที่คำนวณด้วยมือ
│   ├── create_test_user.php   # สคริปต์ CLI สร้าง/รีเซ็ตผู้ใช้ pos_e2e สำหรับรัน e2e test
│   ├── cleanup_test_data.php  # สคริปต์ CLI ปิดการขายสินค้า/ผู้ใช้ และลบบิลพักที่ e2e test สร้างไว้
│   ├── reset_held_bills.php   # สคริปต์ CLI ล้างบิลพักของเทสต์ — spec เรียกก่อน/หลังทุกเทสต์เพื่อแยกสถานะ
│   └── e2e/                   # Playwright end-to-end test — Node ล้วน แยกจาก dependency ของแอป PHP
│       ├── package.json / package-lock.json
│       ├── playwright.config.js  # baseURL, dev server (php -S 127.0.0.1:8123), video/trace/screenshot
│       ├── global-setup.js / global-teardown.js  # สร้าง/ปิดผู้ใช้ทดสอบรอบรันเทสต์
│       ├── fixtures/          # ข้อมูลตั้งต้นของเทสต์
│       ├── specs/             # เทสต์เคส: แก้ไขสินค้า (products.php), ยอดสุทธิรวม VAT + ขั้นตอนขาย + พักบิล (pos.php)
│       └── README.md          # วิธีติดตั้ง/รัน/ตั้งค่า environment variable
├── index.php                  # หน้าแรกหลังเข้าสู่ระบบ
├── login.php                  # หน้าจอและ handler การเข้าสู่ระบบ (CSRF + throttle)
├── logout.php                 # handler ออกจากระบบ
├── pos.php                    # หน้าขายสินค้าและตะกร้า
├── cart_add.php               # เพิ่มสินค้าจาก barcode (JSON)
├── cart_update.php            # แก้จำนวนสินค้า (JSON)
├── cart_remove.php            # ลบสินค้า (JSON)
├── cart_clear.php             # ล้างตะกร้าทั้งหมด (JSON)
├── cart_get.php                # อ่านตะกร้าปัจจุบัน (JSON)
├── cart_totals.php            # คำนวณยอดรวม/ส่วนลด/VAT/สุทธิของตะกร้าปัจจุบันแบบสด ไม่แตะ DB (JSON)
├── held_bill_park.php         # พักตะกร้าปัจจุบันเป็นบิลพัก (JSON/transaction)
├── held_bill_list.php         # อ่านรายการบิลที่พักไว้ (JSON)
├── held_bill_resume.php       # เรียกคืนบิลพักกลับเป็นตะกร้าปัจจุบัน (JSON/transaction)
├── held_bill_delete.php       # ลบบิลที่พักไว้ทิ้ง (JSON)
├── checkout.php               # บันทึกการชำระเงินและตัดสต็อก (JSON/transaction, รองรับ vat_mode)
├── receipt.php                # ใบเสร็จ 80mm ของบิลที่ระบุ
├── report_sales.php           # รายงานสรุปยอดขาย — admin เท่านั้น
├── order_history.php          # ประวัติบิล + รายละเอียดรายการ — admin เท่านั้น
├── products.php               # หน้าเพิ่ม/แก้ไข/ปิดการขายสินค้า
├── product_get.php            # อ่านสินค้า active (JSON)
├── product_add.php            # เพิ่มหรือเปิดขายสินค้าเดิม (JSON)
├── product_update.php         # แก้ไขสินค้าที่มีอยู่ (JSON)
├── product_remove.php         # ปิดการขายสินค้า (JSON)
├── start.bat                  # ตัวช่วยเปิด MySQL + PHP dev server
├── SYSTEM_DESIGN.md           # การออกแบบและ roadmap ของระบบ POS
├── Flow.txt                   # flow POS ที่เสนอไว้
├── Database.txt               # flow transaction ที่เสนอไว้
└── graphify-out/              # knowledge graph ของโปรเจกต์ (สร้างโดย graphify) — GRAPH_REPORT.md, graph.json, graph.html
```

| โฟลเดอร์/ไฟล์ | ความรับผิดชอบ |
|---|---|
| `assets/` | static frontend assets: CSS และ JavaScript สำหรับ POS/จัดการสินค้า |
| `config/` | configuration ที่ทุกหน้าใช้ร่วมกัน และ database bootstrap |
| `includes/` | reusable cross-cutting logic โดยเฉพาะ session/authentication |
| `sql/` | โครงสร้างฐานข้อมูลสำหรับ setup MySQL/MariaDB |
| `tests/` | สคริปต์ CLI ทดสอบแบบรันมือ (VAT, concurrency, e2e user fixtures) + `tests/e2e/` ชุด Playwright แยก dependency |
| root `*.php` | frontend routes และ backend handlers รวมอยู่ในไฟล์เดียว — ไม่มี router กลาง ห้ามย้ายไฟล์เหล่านี้เข้าโฟลเดอร์ย่อยเพราะ URL ผูกกับพาธไฟล์โดยตรง |
| `SYSTEM_DESIGN.md`, `Flow.txt`, `Database.txt` | เอกสารออกแบบ ไม่ใช่ executable code |
| `graphify-out/` | knowledge graph ที่สร้างขึ้น (generated) — cache ภายในและพาธ interpreter เฉพาะเครื่องถูก gitignore ไว้ |

## Frontend routes และ UI ที่มีจริง

| Route | หน้า UI | การเข้าถึง | องค์ประกอบหลัก |
|---|---|---|---|
| `GET /login.php` | หน้าเข้าสู่ระบบ | public; ถ้าล็อกอินแล้วจะ redirect | ฟอร์ม username/password, ปุ่มเข้าสู่ระบบ, พื้นที่แสดง error |
| `GET /index.php` | หน้าหลัก | ต้องมี session ผู้ใช้ | top bar แสดงชื่อ/role, ลิงก์ออกจากระบบ และลิงก์ไป POS |
| `GET /pos.php` | หน้าขายสินค้า | ต้องมี session ผู้ใช้ | ช่อง barcode, ข้อความสถานะ, ตารางตะกร้า, ปุ่มเพิ่ม/ลด/ลบ และ panel บิลที่พักไว้ (ช่องหมายเหตุ + ปุ่มพักบิล/เรียกคืน/ลบ) |
| `GET /products.php` | จัดการสินค้า | ผู้ใช้ที่ล็อกอินทุก role | ฟอร์มเพิ่มสินค้า, ตารางสินค้า active แก้ไขในแถวได้ (barcode/ชื่อ/ราคา/สต็อก) พร้อมปุ่มบันทึก/ยกเลิก/ลบ |
| `GET /receipt.php?order_id=` | ใบเสร็จ 80mm | เจ้าของบิลหรือ admin | หัวร้าน, รายการสินค้า, ยอดรวม, ปุ่มพิมพ์; หน้าขายฝังหน้านี้เป็น iframe ซ่อนแล้วสั่งพิมพ์เอง ส่วน `autoprint=1` ใช้กับลิงก์ที่เปิดตรง (พิมพ์ซ้ำ) |
| `GET /report_sales.php` | รายงานยอดขาย | `admin` เท่านั้น | ตัวกรองช่วงวันที่/พนักงาน และการ์ดสรุปยอด |
| `GET /order_history.php` | ประวัติบิล | `admin` เท่านั้น | ตัวกรอง + ค้นเลขที่บิล, ตารางบิลแบ่งหน้า, panel รายละเอียดรายการสินค้า |
| `GET /logout.php` | ไม่มีหน้า render | ผู้ใช้ใน session | ล้าง session, ลบ cookie และ redirect กลับ login |

UI ทั้งหมดอ้างอิง `assets/css/style.css`:

- `.auth-page`, `.auth-box` — layout และ form ของ login
- `.error` — แสดง validation/authentication error
- `.topbar` — ส่วนหัวหลังล็อกอิน
- `main` — เนื้อหาหน้าหลัก
- `.pos-page`, `.pos-panel`, `.cart-table` — layout และตารางตะกร้าของ POS
- `.held-bills-panel`, `.held-bills-table`, `.resume-button`, `.status-stale` — panel บิลที่พักไว้
- `.products-page`, `.product-form` — layout และฟอร์มจัดการสินค้า
- `.report-page`, `.filter-form`, `.summary-grid`/`.summary-card`, `.pagination`, `.status-badge` — หน้ารายงานและประวัติบิล
- `.auth-box.error-box` — หน้า error กลางจาก `render_error_page()`

ใบเสร็จใช้ `assets/css/print.css` แยกต่างหาก (`.receipt-page`, `#receipt-section`, `@media print` ที่ความกว้าง 80mm)

## Backend endpoints ที่มีจริง

ไม่มี REST API แบบ resource-oriented แต่มี JSON endpoints สำหรับ session cart; page routes ทำหน้าที่เป็น backend handler ด้วย

| Endpoint | Method | Request | Backend action | Response |
|---|---|---|---|---|
| `/login.php` | GET | session cookie | ตรวจว่าล็อกอินแล้วหรือไม่ | HTML login หรือ `302 /index.php` |
| `/login.php` | POST | `username`, `password`, CSRF token | ตรวจ throttle, CSRF, prepared `SELECT` ผู้ใช้ active, `password_verify`, regenerate session | `302 /index.php` หรือ HTML พร้อม error |
| `/index.php` | GET | session cookie | `require_login()` และอ่าน `current_user()` | HTML หรือ `302 /login.php` |
| `/logout.php` | GET | session cookie | ล้าง `$_SESSION`, `session_destroy()` | `302 /login.php` |
| `/cart_get.php` | GET | session cookie | คืน current session cart และยอดรวม | JSON |
| `/cart_add.php` | POST | barcode + CSRF token | ตรวจ product/stock แล้วเพิ่ม session cart | JSON |
| `/cart_update.php` | POST | product ID, quantity + CSRF token | ตรวจ stock แล้วแก้ session cart | JSON |
| `/cart_remove.php` | POST | product ID + CSRF token | ลบจาก session cart | JSON |
| `/cart_clear.php` | POST | CSRF token | ล้างตะกร้าทั้งหมดในครั้งเดียว | JSON |
| `/cart_totals.php` | POST | discount, `vat_mode` + CSRF token | คำนวณยอดรวม/ส่วนลด/VAT/สุทธิของตะกร้าปัจจุบันด้วย `money_order_totals()` ตัวเดียวกับ checkout — ไม่แตะ DB และไม่แก้ cart | JSON |
| `/held_bill_park.php` | POST | `note` (ไม่บังคับ) + CSRF token | snapshot ตะกร้าลง `held_bills`/`held_bill_items` แล้วล้างตะกร้า; 422 ถ้าตะกร้าว่างหรือพักครบ `HELD_BILL_MAX` | JSON |
| `/held_bill_list.php` | GET | session cookie | คืนบิลที่พักไว้ทั้งหมดพร้อมยอดรวมและ flag `is_stale` | JSON |
| `/held_bill_resume.php` | POST | `held_bill_id`, `on_conflict` (`reject`/`park`) + CSRF token | ยึดบิลด้วย `FOR UPDATE` + `DELETE`, ตรวจสินค้าและใช้ราคาปัจจุบัน แล้วเขียนกลับเป็นตะกร้า; 409 `cart_not_empty` เมื่อตะกร้ายังมีของและ `on_conflict = reject` | JSON |
| `/held_bill_delete.php` | POST | `held_bill_id` + CSRF token | ลบบิลพักทิ้ง (รายการ cascade); 404 ถ้าถูกเรียกคืน/ลบไปแล้ว | JSON |
| `/checkout.php` | POST | discount, `vat_mode` (`inclusive`/`exclusive`), payment, received amount + CSRF token | lock stock, คำนวณ VAT ตาม `vat_mode`, สร้างบิล/รายการ/stock movement และ commit พร้อมกัน | JSON |
| `/product_get.php` | GET | session cookie | คืนรายการสินค้า active | JSON |
| `/product_add.php` | POST | barcode, name, price, stock + CSRF token | เพิ่มสินค้า หรือเปิดขาย barcode ที่ถูกปิดไว้ | JSON |
| `/product_update.php` | POST | product ID, barcode, name, price, stock + CSRF token | แก้ไขสินค้า active ที่มีอยู่ (409 ถ้า barcode ชนกับแถวอื่นแม้ปิดการขายอยู่) | JSON |
| `/product_remove.php` | POST | product ID + CSRF token | ตั้ง `is_active = 0`; ไม่ลบแถวจริง | JSON |
| `/receipt.php` | GET | `order_id` (+ `autoprint`) | อ่านบิลและรายการ ตรวจสิทธิ์เจ้าของบิล/admin แล้วผ่อน `X-Frame-Options` เป็น `SAMEORIGIN` ให้ `pos.php` ฝังได้ | HTML ใบเสร็จ (`SAMEORIGIN`) หรือหน้า error 400/404 (ยังเป็น `DENY`) |
| `/report_sales.php` | GET | `date_from`, `date_to`, `user_id` | `require_role('admin')`, aggregate `orders` และ `SUM(order_items.qty)` | HTML รายงาน หรือหน้า error 403 |
| `/order_history.php` | GET | ตัวกรองเดียวกัน + `q`, `page`, `order_id` | `require_role('admin')`, list บิลแบบแบ่งหน้า + รายละเอียดบิลที่เลือก | HTML ประวัติบิล หรือหน้า error 403 |

### Shared backend modules

| โมดูล | สิ่งที่ให้ใช้ |
|---|---|
| `config/settings.php` | `APP_ENV`, `VAT_RATE`, `STORE_*`, `SESSION_NAME`, `app_fail()` และ exception/shutdown handler กลาง (โหลดค่า DB/`BASE_URL` จาก `config/env.<APP_ENV>.php`) |
| `config/db.php` | ตัวแปร PDO `$pdo`; exception mode และ prepared statements แบบ native |
| `includes/auth.php` | session cookie flags, security headers, `current_user()`, `is_logged_in()`, `require_login()`, `require_role()`, `csrf_token()` และ `is_valid_csrf_token()` |
| `includes/cart.php` | JSON response/input, cart state, stock lookup, `cart_is_empty()` และ cart CSRF guard |
| `includes/held_bill.php` | `held_bill_format_number()`, `held_bill_note_input()`, `held_bill_id_input()`, `held_bill_cart_snapshot()`, `held_bill_create()` (ใช้ร่วมกันระหว่าง park กับ resume แบบสลับบิล), `held_bill_count()`, `held_bill_list()`, `held_bill_load_items()`, `held_bill_send_state()` |
| `includes/money.php` | `money_to_cents()`, `money_from_cents()`, `money_display()`, `money_order_totals()` (สูตร VAT ชุดเดียวที่ checkout.php และ cart_totals.php ใช้ร่วมกัน, รองรับ `vat_mode` inclusive/exclusive), `money_vat_percent()`, `MONEY_MAX_CENTS` |
| `includes/page.php` | `page_esc()`, `page_security_headers()`, `render_error_page()` |
| `includes/product.php` | `product_validated_input()` — validate barcode/ชื่อ/ราคา/สต็อกชุดเดียวที่ product_add.php และ product_update.php ใช้ร่วมกัน |
| `includes/report.php` | `report_parse_filters()`, `report_where()`, `report_cashiers()`, `report_query_string()` และตัว render ของ topbar/ฟอร์มตัวกรอง |

## Data flow ที่ทำงานจริง: Login

```text
Login form
  │ POST username/password
  ▼
login.php
  │ validate ว่าไม่ว่าง
  ▼
PDO prepared query: users (username + is_active)
  │ password_verify(password, password_hash)
  ├─ ไม่ผ่าน ──► render login + error message
  └─ ผ่าน
       │ session_regenerate_id(true)
       ▼
$_SESSION['user'] = {id, username, full_name, role}
       │ 302 redirect
       ▼
index.php ──► require_login() ──► render user identity
```

ข้อมูลผู้ใช้ใน session เป็นแหล่งข้อมูลสำหรับ authorization ของ request ถัดไป ขณะที่ `users` ใน MySQL เป็นแหล่งข้อมูลสำหรับการพิสูจน์ตัวตนตอน login เท่านั้น

## Data flow: เพิ่ม/แก้ไข/ลบสินค้า (Product Management)

```text
products.php / products.js
  ├─ เพิ่มสินค้า
  │    → POST product_add.php (barcode, name, price, stock_qty, CSRF)
  │    → validate (includes/product.php) + INSERT products
  │    → ถ้า barcode เดิมถูกปิดการขาย: UPDATE และ is_active = 1
  │    → reload รายการสินค้าจาก product_get.php
  ├─ แก้ไขสินค้า (แก้ในแถวตาราง)
  │    → POST product_update.php (product_id, barcode, name, price, stock_qty, CSRF)
  │    → validate (includes/product.php) + ตรวจว่าสินค้ายัง active อยู่
  │    → UPDATE products; 409 ถ้า barcode ชนกับแถวอื่น (รวมแถวที่ปิดการขายอยู่)
  │    → reload รายการสินค้า
  └─ ลบสินค้า
       → POST product_remove.php (product_id, CSRF)
       → UPDATE products SET is_active = 0
       → ลบสินค้านั้นออกจาก cart ของ session ปัจจุบัน
       → reload รายการสินค้า
```

ทุก role ที่ผ่าน `require_login()` (ทั้ง `staff` และ `admin`) ใช้ Product Management ได้. การ “ลบ” เป็น soft delete: แถวสินค้าและบาร์โค้ดยังคงอยู่เพื่อรักษาความสัมพันธ์กับบิลในอนาคต; เพิ่ม barcode เดิมอีกครั้งจะเปิดการขายสินค้านั้นกลับมา

## Workflow ที่มีจริง

### 1. เข้าสู่ระบบ

1. ผู้ใช้เปิด `login.php`
2. กรอก username และ password แล้ว submit form
3. Server ค้นหาผู้ใช้ที่ `is_active = 1`
4. หากรหัสผ่านถูกต้อง ระบบเปลี่ยน session ID และบันทึกข้อมูลผู้ใช้ใน session
5. Browser ถูก redirect ไป `index.php`

### 2. เข้า protected page

1. Browser ขอ `index.php` พร้อม session cookie
2. `require_login()` ตรวจ `$_SESSION['user']`
3. ถ้าไม่พบ session ให้ redirect ไป `login.php`; หากพบให้ render หน้าแรก

### 3. ออกจากระบบ

1. ผู้ใช้กดลิงก์ `logout.php`
2. Server ล้างข้อมูลและทำลาย session
3. Browser ถูก redirect ไป `login.php`

### 4. จัดการสินค้า

1. ผู้ใช้ที่ล็อกอินเปิด `products.php`
2. Browser โหลดสินค้า `is_active = 1` จาก `product_get.php`
3. การเพิ่มสินค้าตรวจ barcode, ชื่อ, ราคา และจำนวนสต็อกที่ server ก่อนเขียนลง `products`
4. การลบตั้งค่า `is_active = 0` โดยไม่ลบข้อมูลจริง และนำรายการนั้นออกจากตะกร้าของ session ปัจจุบัน

### 5. แสดงยอดสด และ Checkout

1. ระหว่างแก้ตะกร้า/ส่วนลด POS เรียก `cart_totals.php` (discount, `vat_mode`) เพื่อแสดงยอดรวม/VAT/สุทธิแบบสดโดยไม่แตะ DB — ใช้สูตรเดียวกับตอนบันทึกบิลจริง (`money_order_totals()`) จึงตัวเลขตรงกันเสมอ
2. เมื่อกดชำระเงิน POS ส่งส่วนลด, `vat_mode`, วิธีชำระเงิน และเงินรับไป `checkout.php`; server ไม่รับยอดรวมจาก client
3. Server lock สินค้าใน cart เรียงตาม product ID ด้วย `SELECT ... FOR UPDATE` และตรวจ stock ล่าสุด
4. Server คำนวณยอดรวม, VAT (อัตราจาก `VAT_RATE`, โหมด `inclusive`/`exclusive` ตาม `vat_mode`), ยอดสุทธิ และเงินทอนจากราคาสินค้าใน DB
5. ใน transaction เดียวกัน ระบบสร้าง `orders`, `order_items`, `stock_movements` และตัด `products.stock_qty`
6. เมื่อ commit สำเร็จจึงล้าง session cart; หากเกิด error จะ rollback ทุกตารางและเก็บ cart ไว้ให้แก้ไข

`vat_mode = 'exclusive'` (ค่าเริ่มต้น) คือราคาสินค้าไม่รวม VAT แล้วบวกเพิ่มจากยอดหลังหักส่วนลด; `'inclusive'` คือราคาสินค้ารวม VAT อยู่แล้ว จึงแยกภาษีออกจากยอดโดยไม่บวกเพิ่ม (ยอดที่ลูกค้าจ่ายไม่เปลี่ยนตามโหมด มีแค่สัดส่วน tax/taxable ต่างกัน) — ดู `includes/money.php`

## Database map

`sql/schema.sql` กำหนดฐาน `pos_system` และตารางต่อไปนี้

| ตาราง | บทบาท | Runtime ปัจจุบัน |
|---|---|---|
| `users` | บัญชีผู้ใช้, password hash, role, สถานะ active | ใช้โดย login และเป็น dropdown พนักงานในหน้ารายงาน |
| `products` | สินค้า ราคา และจำนวนคงเหลือ | ใช้โดย Product Management และตรวจ stock ตอนจัดการ cart |
| `orders` | หัวบิลและยอดชำระเงิน | เขียนเมื่อ checkout สำเร็จ; อ่านโดย `receipt.php`, `report_sales.php`, `order_history.php` |
| `order_items` | รายการสินค้าในบิล | เขียนเมื่อ checkout สำเร็จ; อ่านโดยใบเสร็จและหน้ารายงาน |
| `stock_movements` | audit trail ของการเปลี่ยนสต็อก | บันทึก `sale` เมื่อ checkout สำเร็จ (ยังไม่มีหน้าจออ่าน) |
| `held_bills` | หัวบิลที่พักไว้ชั่วคราว (เลขที่, คนพัก, หมายเหตุ) | เขียนเมื่อพักบิล; ลบทิ้งเมื่อเรียกคืนหรือกดลบ — ไม่ใช่บิลขายและไม่เข้ารายงาน |
| `held_bill_items` | รายการสินค้าของบิลพัก (snapshot) | อ่านตอนเรียกคืนเพื่อตรวจกับ `products` ใหม่ |

ความสัมพันธ์ที่ออกแบบไว้คือ `users → orders → order_items → products` และ `stock_movements` อ้างอิงสินค้า/บิลได้
ฝั่งบิลพักคือ `users → held_bills → held_bill_items → products` ซึ่งแยกขาดจากสายบิลขายโดยสิ้นเชิง

### 6. ใบเสร็จ

1. หลัง `checkout.php` ตอบสำเร็จ `pos.js` แสดงข้อความสรุปกับลิงก์ "พิมพ์ใบเสร็จอีกครั้ง" ก่อน แล้วจึงโหลด `receipt.php?order_id=...` ลง iframe ซ่อน (`.receipt-print-frame`) ตรวจว่ามี `#receipt-section` จริงแล้วเรียก `contentWindow.print()` — ไม่ใช้ `window.open()` แล้ว จึงไม่ต้องพึ่ง popup blocker และไม่มีแท็บให้แคชเชียร์ปิด
2. `receipt.php` อ่านบิลและรายการสินค้า และให้ดูได้เฉพาะเจ้าของบิลหรือ `admin` (กรณีอื่นตอบ 404 เหมือนกันหมด เพื่อไม่ให้เดา id ของบิลคนอื่น)
3. `print.css` ตัด toolbar ออกและจัดความกว้าง 80mm ตอนสั่งพิมพ์
4. `X-Frame-Options: SAMEORIGIN` ถูกส่งหลังตรวจสิทธิ์ผ่านแล้วเท่านั้น หน้า error 400/404 ยังเป็น `DENY` จึงฝังไม่ได้ — เป็นด่านที่สองไม่ให้หน้า "ไม่พบใบเสร็จ" ถูกสั่งพิมพ์ออกมาเป็นกระดาษ
5. โหลดไม่สำเร็จ, ถูกบล็อก, หรือ `print()` พัง จะไม่เงียบ — `pos.js` เปลี่ยนข้อความเป็นสีแดงให้กดลิงก์พิมพ์ซ้ำแทน (มี timeout 10 วินาทีกันโหลดค้าง)

### 7. รายงานยอดขายและประวัติบิล (admin เท่านั้น)

```text
report_sales.php  ─ ตัวกรอง (date_from, date_to, user_id)
                  ├─ SELECT COUNT/SUM จาก orders          → การ์ดสรุปยอด
                  └─ SELECT SUM(qty) จาก order_items+orders → จำนวนชิ้น

order_history.php ─ ตัวกรองเดียวกัน + q (เลขที่บิล) + page
                  ├─ COUNT(*) → จำนวนหน้า
                  ├─ list บิล 20 รายการ/หน้า (LIMIT/OFFSET bind แบบ PARAM_INT)
                  └─ order_id → panel รายละเอียด (ค้นด้วย id อย่างเดียว)
```

- ตัวกรองทุกตัวถูก validate ใน `report_parse_filters()` ค่าที่ผิดจะถูกแทนด้วยค่าเริ่มต้นพร้อมข้อความอธิบาย
- ช่วงวันที่ใช้แบบ half-open (`>= from 00:00:00` และ `< to+1day`) เพื่อให้ index `idx_created_at` ทำงาน
- `report_sales.php` นับเฉพาะบิล `status = 'completed'` ส่วน `order_history.php` แสดงบิล `voided` ด้วยพร้อม badge สถานะ
- ยอดสรุปแยกเป็นสอง query เสมอ ห้าม join `order_items` เข้ากับ query ที่ `SUM` ยอดหัวบิล เพราะยอดจะถูกคูณตามจำนวนรายการ

### 8. พักบิล (ลูกค้าลืมหยิบของ)

```text
pos.php / pos.js — panel "บิลที่พักไว้"
  ├─ พักบิล
  │    → POST held_bill_park.php (note, CSRF)
  │    → held_bill_cart_snapshot() พับตะกร้าตาม product_id (กติกาเดียวกับ checkout.php)
  │    → INSERT held_bills (hold_number = NULL) → lastInsertId() → UPDATE เป็น HOLD-0001
  │    → INSERT held_bill_items แล้ว commit → cart_set_items([])
  ├─ เรียกคืน
  │    → POST held_bill_resume.php (held_bill_id, on_conflict, CSRF)
  │    → ตะกร้าไม่ว่าง + reject → 409 code=cart_not_empty ให้หน้าจอถามก่อน
  │    → ตะกร้าไม่ว่าง + park   → พักตะกร้าปัจจุบันและเรียกคืนในทรานแซกชันเดียว
  │    → SELECT ... FOR UPDATE + DELETE rowCount()===1 = การยึดบิล (กันสองคนแย่งกัน)
  │    → ตรวจทุกบรรทัดกับ products: ปิดการขาย/สต็อกหมด = ตัดทิ้ง, สต็อกไม่พอ = ลดจำนวน,
  │      ราคาต่าง = ใช้ราคาปัจจุบัน แล้วสรุปเป็นข้อความไทยและ array `warnings`
  └─ ลบ
       → POST held_bill_delete.php (held_bill_id, CSRF) → รายการ cascade
```

- บิลพัก **ไม่จองสต็อก** — เป็นแค่ที่พักของตะกร้า สต็อกถูกตรวจจริงใต้ `FOR UPDATE` ตอน checkout เท่านั้น
- **ไม่เก็บส่วนลดและโหมด VAT** ไปกับบิลพัก เรียกคืนแล้วต้องกรอกใหม่
- `price_at_hold` มีไว้แสดงผล/audit เท่านั้น ห้ามคืนกลับเข้าตะกร้า เพราะ `checkout.php` อ่านราคาจาก DB เสมอ — จะกลายเป็นโชว์เลขหนึ่งแต่เก็บเงินอีกเลขหนึ่ง
- ทุก endpoint ตอบทั้ง `cart` และ `held_bills` ในรอบเดียวผ่าน `held_bill_send_state()` ฝั่ง JS จึงไม่ต้องยิงซ้ำ
- `note` และ `cashier_name` เป็นข้อความอิสระที่ผู้ใช้ "คนอื่น" กรอก และถูก render ด้วย `innerHTML` จึงต้องผ่าน `escapeHtml()` ทุกฟิลด์
- บิลค้างเกิน `HELD_BILL_STALE_HOURS` จะติด badge `ค้างข้ามวัน` แต่ระบบไม่ลบให้อัตโนมัติ (คำนวณด้วย `NOW()` ของ MySQL ไม่ใช่ `time()` ของ PHP เพราะ timezone สองฝั่งอาจไม่ตรงกัน)

## ข้อสังเกตสำหรับการพัฒนาต่อ

- ยังไม่มี service layer แบบ class: logic หลักยังอยู่ใน route files และไฟล์ใน `includes/`
- มี JavaScript สำหรับ POS/Product Management แต่ยังไม่มี client-side routing
- ยังไม่มีหน้าจัดการผู้ใช้: การเพิ่มพนักงานต้อง INSERT ลงตาราง `users` เอง
- `orders.status = 'voided'` มีในสคีมาและหน้าประวัติรองรับแล้ว แต่ยังไม่มี endpoint สำหรับยกเลิกบิล
- throttle การ login นับต่อ session เท่านั้น ยังไม่ได้นับต่อ IP
- บิลพักยังไม่มี audit trail: ลบแล้วไม่เหลือร่องรอย และไม่มีหน้าจอฝั่ง admin สำหรับดูบิลค้างสะสม
- รายการบิลพักไม่ได้ poll เอง แคชเชียร์อีกเครื่องต้องกด `รีเฟรช` หรือทำ action ก่อนจึงจะเห็นบิลของคนอื่น
