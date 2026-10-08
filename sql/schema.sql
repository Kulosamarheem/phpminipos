-- Mini POS & Billing System — Database Schema
-- อ้างอิงตาม SYSTEM_DESIGN.md (หัวข้อ 2)
-- ใช้กับ MySQL / MariaDB (InnoDB)

CREATE DATABASE IF NOT EXISTS pos_system
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE pos_system;

-- ---------------------------------------------------------
-- 2.1 products
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
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

-- ---------------------------------------------------------
-- 2.2 users
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name     VARCHAR(100) NOT NULL,
    role          ENUM('admin','staff') NOT NULL DEFAULT 'staff',
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 2.3 orders
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
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

-- ---------------------------------------------------------
-- 2.4 order_items
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS order_items (
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

-- ---------------------------------------------------------
-- 2.5 stock_movements (เสริม — audit trail การตัด/เติมสต็อก)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_movements (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id   INT UNSIGNED NOT NULL,
    change_qty   INT NOT NULL,               -- ลบ = ตัดสต็อก, บวก = เติมสต็อก
    reason       ENUM('sale','restock','adjustment') NOT NULL,
    ref_order_id INT UNSIGNED NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_stock_movements_product
        FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_stock_movements_order
        FOREIGN KEY (ref_order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 2.6 held_bills (พักบิล — หัวบิลที่พักไว้ชั่วคราว)
-- ไม่ใช่บิลขาย: ไม่จองสต็อก ไม่มียอดสุทธิ/VAT/ส่วนลด และถูกลบทิ้งเมื่อเรียกคืน
-- hold_number ประกาศ NULL ได้เพราะ INSERT รอบแรกยังไม่รู้ค่า id
-- (MySQL ยอมให้ค่า NULL ซ้ำกันใน UNIQUE index) แล้วค่อย UPDATE ในทรานแซกชันเดียวกัน
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS held_bills (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hold_number VARCHAR(20)     NULL UNIQUE,
    user_id     INT UNSIGNED    NULL,
    note        VARCHAR(100)    NOT NULL DEFAULT '',
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_held_bills_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 2.7 held_bill_items (รายการสินค้าของบิลที่พักไว้ — snapshot)
-- price_at_hold เก็บไว้แสดงผลเท่านั้น ตอนเรียกคืนใช้ราคาปัจจุบันจาก products เสมอ
-- product_id ใช้ CASCADE (ไม่ใช่ RESTRICT แบบ order_items) เพราะบิลพักเป็นข้อมูลชั่วคราว
-- ไม่ควรไปบล็อกการลบสินค้า — เสียไปหนึ่งบรรทัดแล้วให้ขั้นตอนเรียกคืนแจ้งผู้ใช้แทน
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS held_bill_items (
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

    -- ตะกร้าใน session ใช้ product_id เป็น key อยู่แล้ว จึงมีได้บรรทัดเดียวต่อสินค้า
    -- คอลัมน์แรกเป็น held_bill_id จึงใช้เป็น index ของ FK และของการค้นรายบิลได้ในตัว
    UNIQUE KEY uq_held_bill_product (held_bill_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Seed: ผู้ดูแลระบบเริ่มต้น
-- username: admin / password: admin1234
-- เปลี่ยนรหัสผ่านทันทีหลังใช้งานจริง
-- ---------------------------------------------------------
INSERT INTO users (username, password_hash, full_name, role)
VALUES (
    'admin',
    '$2y$10$vWB1yyGNL4t5LQZwllegiON0EF.93yIFDGq0GGsZ3yf0goexBsr4K',
    'ผู้ดูแลระบบ',
    'admin'
) ON DUPLICATE KEY UPDATE username = username;
