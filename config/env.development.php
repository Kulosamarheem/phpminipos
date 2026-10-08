<?php

declare(strict_types=1);

// ---------------------------------------------------------
// Database (development)
// ---------------------------------------------------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'pos_system');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// ---------------------------------------------------------
// App (development)
// ---------------------------------------------------------
// path ฐานของแอป เผื่อรันในโฟลเดอร์ย่อย เช่น '/pos/' — ต้องลงท้ายด้วย '/'
define('BASE_URL', '/');

ini_set('display_errors', '1');
error_reporting(E_ALL);
