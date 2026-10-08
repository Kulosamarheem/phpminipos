-- ล้างข้อมูลที่ผู้เข้าชมลองเล่นบนเว็บ demo แล้วใส่ข้อมูลตัวอย่างใหม่
-- ใช้ผ่าน phpMyAdmin บนโฮสต์ demo เท่านั้น — ห้ามรันกับฐานข้อมูลร้านจริง
-- (build_deploy.ps1 จะต่อท้ายด้วย demo_data.sql ให้เป็นไฟล์ deploy/pos_demo_reset.sql)

DELETE FROM held_bills;        -- held_bill_items ลบตามด้วย CASCADE
DELETE FROM stock_movements;
DELETE FROM orders;            -- order_items ลบตามด้วย CASCADE
DELETE FROM products;
DELETE FROM users WHERE username NOT IN ('demo', 'cashier');
