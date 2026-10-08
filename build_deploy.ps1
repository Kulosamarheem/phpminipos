# ---------------------------------------------------------
# เตรียมไฟล์สำหรับอัปขึ้น shared hosting (เช่น InfinityFree)
#   powershell -ExecutionPolicy Bypass -File build_deploy.ps1
#
# ผลลัพธ์ในโฟลเดอร์ deploy/ (ไม่เข้า git):
#   htdocs/               → อัปทั้งหมดขึ้นโฟลเดอร์ htdocs บนโฮสต์
#   pos_hosting.sql       → import ผ่าน phpMyAdmin ครั้งแรก (ตาราง + ข้อมูล demo)
#   pos_demo_reset.sql    → import เมื่ออยากล้างข้อมูลที่คนลองเล่นไว้
# ---------------------------------------------------------
$ErrorActionPreference = 'Stop'

$root   = $PSScriptRoot
$out    = Join-Path $root 'deploy'
$htdocs = Join-Path $out 'htdocs'
$utf8   = New-Object System.Text.UTF8Encoding($false)

if (Test-Path $out) { Remove-Item $out -Recurse -Force }
New-Item -ItemType Directory -Force $htdocs | Out-Null

# ไฟล์ PHP ของแอปที่หน้าราก + โฟลเดอร์ที่แอปใช้จริงเท่านั้น
# (ไม่เอา tests/, sql/, เอกสาร, start.bat ฯลฯ ขึ้นเว็บ)
Copy-Item (Join-Path $root '*.php') $htdocs
foreach ($dir in 'assets', 'includes', 'config') {
    Copy-Item (Join-Path $root $dir) $htdocs -Recurse
}

# config บนโฮสต์: ต้องสร้าง env.production.php เองจาก .example
# ห้ามติด env.development.php (root/ไม่มีรหัส, แสดง error) หรือ env.production.php ของเครื่องนี้ไปด้วย
foreach ($f in 'env.development.php', 'env.production.php', 'env.local.php') {
    $p = Join-Path $htdocs "config\$f"
    if (Test-Path $p) { Remove-Item $p -Force }
}

# กันไม่ให้เปิดดูไฟล์ใน config/ includes/ ผ่านเว็บโดยตรง
$deny = "Require all denied`r`n"
[IO.File]::WriteAllText((Join-Path $htdocs 'config\.htaccess'), $deny, $utf8)
[IO.File]::WriteAllText((Join-Path $htdocs 'includes\.htaccess'), $deny, $utf8)

# ---------------------------------------------------------
# SQL: โฮสต์ฟรีสร้าง/เลือกฐานข้อมูลเองไม่ได้ จึงตัด CREATE DATABASE / USE ออก
# และไม่ seed บัญชี admin (รหัส admin1234 เปิดเผยอยู่ใน repo) — ใช้บัญชี demo แทน
# ---------------------------------------------------------
$schema = [IO.File]::ReadAllText((Join-Path $root 'sql\schema.sql'), $utf8)
$schema = [regex]::Replace($schema, '(?s)CREATE DATABASE.*?;\s*USE\s+\w+;', '')
$schema = [regex]::Replace($schema, '(?s)-- -+\s*-- Seed: .*$', '')
if ($schema -match 'CREATE DATABASE|USE pos_system|admin1234') {
    throw 'ตัด schema.sql ไม่สำเร็จ — รูปแบบไฟล์อาจเปลี่ยนไป'
}

$demoData  = [IO.File]::ReadAllText((Join-Path $root 'sql\demo_data.sql'), $utf8)
$demoReset = [IO.File]::ReadAllText((Join-Path $root 'sql\demo_reset.sql'), $utf8)

[IO.File]::WriteAllText((Join-Path $out 'pos_hosting.sql'), $schema.TrimEnd() + "`r`n`r`n" + $demoData, $utf8)
[IO.File]::WriteAllText((Join-Path $out 'pos_demo_reset.sql'), $demoReset + "`r`n" + $demoData, $utf8)

Write-Host "เสร็จแล้ว → $out"
Write-Host '  1) import pos_hosting.sql ผ่าน phpMyAdmin'
Write-Host '  2) คัดลอก htdocs\config\env.production.php.example เป็น env.production.php แล้วใส่ค่า DB ของโฮสต์'
Write-Host '  3) อัปทุกอย่างใน htdocs\ ขึ้นโฟลเดอร์ htdocs บนโฮสต์'
