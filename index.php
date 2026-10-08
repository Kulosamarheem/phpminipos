<?php

declare(strict_types=1);

// หน้าเว็บทั้งหมดอยู่ใน pages/ — ไฟล์นี้มีไว้ให้เปิดโดเมนเปล่า (/) แล้วเข้าระบบได้ทันที
// pages/index.php จะส่งต่อไปหน้า login เองถ้ายังไม่ได้เข้าสู่ระบบ
header('Location: pages/index.php');
exit;
