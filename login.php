<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';

if (is_logged_in()) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

// จำกัดจำนวนครั้งที่กรอกรหัสผิด — นับต่อ session (ไม่ใช่ต่อ IP)
// ช่วยชะลอการเดารหัสผ่านจากเครื่องเดิม แต่ไม่ได้กันการยิงจากหลายเครื่อง
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCK_SECONDS = 900;

$error = '';

$attempts = $_SESSION['login_attempts'] ?? ['count' => 0, 'first_at' => 0];
if ((int) $attempts['first_at'] + LOGIN_LOCK_SECONDS < time()) {
    $attempts = ['count' => 0, 'first_at' => time()];
}
$lockedUntil = (int) $attempts['first_at'] + LOGIN_LOCK_SECONDS;
$isLocked = (int) $attempts['count'] >= LOGIN_MAX_ATTEMPTS;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($isLocked) {
        $error = 'กรอกรหัสผ่านผิดเกินกำหนด กรุณารออีก '
            . max(1, (int) ceil(($lockedUntil - time()) / 60)) . ' นาทีแล้วลองใหม่';
    } elseif (!is_valid_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'คำขอไม่ถูกต้องหรือหมดอายุ กรุณาลองใหม่อีกครั้ง';
    } elseif ($username === '' || $password === '') {
        $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } else {
        $stmt = $pdo->prepare(
            'SELECT id, username, password_hash, full_name, role
             FROM users WHERE username = ? AND is_active = 1'
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            unset($_SESSION['login_attempts'], $_SESSION['csrf_token']);
            $_SESSION['user'] = [
                'id'        => (int) $user['id'],
                'username'  => $user['username'],
                'full_name' => $user['full_name'],
                'role'      => $user['role'],
            ];
            header('Location: ' . BASE_URL . 'index.php');
            exit;
        }

        $attempts = [
            'count' => (int) $attempts['count'] + 1,
            'first_at' => (int) $attempts['first_at'] ?: time(),
        ];
        $_SESSION['login_attempts'] = $attempts;
        $isLocked = $attempts['count'] >= LOGIN_MAX_ATTEMPTS;

        $error = $isLocked
            ? 'กรอกรหัสผ่านผิดครบ ' . LOGIN_MAX_ATTEMPTS . ' ครั้ง กรุณารอ '
                . (int) (LOGIN_LOCK_SECONDS / 60) . ' นาทีแล้วลองใหม่'
            : 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
    }
}

$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>เข้าสู่ระบบ - Mini POS</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-page">
    <form class="auth-box" method="post" action="login.php">
        <h1>เข้าสู่ระบบ</h1>

        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <?php if ($error !== ''): ?>
            <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <label>
            ชื่อผู้ใช้
            <input type="text" name="username" required autofocus>
        </label>
        <label>
            รหัสผ่าน
            <input type="password" name="password" required>
        </label>
        <button type="submit"<?= $isLocked ? ' disabled' : '' ?>>เข้าสู่ระบบ</button>
    </form>
</body>
</html>
