<?php
require_once __DIR__ . '/config.php';

// Jika sudah login, langsung ke dashboard
if (!empty($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$error = '';

// Membuat token CSRF
if (empty($_SESSION['login_csrf_token'])) {
    $_SESSION['login_csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $usernameInput = trim($_POST['username'] ?? '');
    $passwordInput = $_POST['password'] ?? '';
    $csrfInput = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['login_csrf_token'], $csrfInput)) {
        $error = 'Permintaan tidak valid. Silakan muat ulang halaman.';
    } elseif ($usernameInput === '' || $passwordInput === '') {
        $error = 'Username dan password wajib diisi.';
    } else {

        try {
            $stmt = $pdo->prepare(
                'SELECT id, name, username, password, role
                 FROM users
                 WHERE username = ?
                 LIMIT 1'
            );

            $stmt->execute([$usernameInput]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Verifikasi password menggunakan password_hash/password_verify
            if (
                $user &&
                password_verify($passwordInput, $user['password']) &&
                in_array($user['role'], ['admin', 'staff'], true)
            ) {

                // Perbarui hash jika algoritma perlu diperbarui
                if (password_needs_rehash(
                    $user['password'],
                    PASSWORD_DEFAULT
                )) {
                    $newHash = password_hash(
                        $passwordInput,
                        PASSWORD_DEFAULT
                    );

                    $update = $pdo->prepare(
                        'UPDATE users SET password = ? WHERE id = ?'
                    );

                    $update->execute([$newHash, $user['id']]);
                }

                // Regenerasi session ID untuk keamanan
                session_regenerate_id(true);

                $_SESSION['user'] = [
                    'id' => $user['id'],
                    'name' => $user['name'],
                    'username' => $user['username'],
                    'role' => $user['role']
                ];

                // Hapus token login setelah berhasil
                unset($_SESSION['login_csrf_token']);

                header('Location: index.php');
                exit;

            } else {
                $error = 'Username atau password salah.';
            }

        } catch (PDOException $ex) {
            $error = 'Terjadi kesalahan saat memproses login. Silakan coba kembali.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Login - StockWise</title>

<link rel="stylesheet" href="style.css?v=stockwise-teal-6">

<style>
body.login-body {
    min-height: 100vh;
    margin: 0;
    padding: 20px;
    box-sizing: border-box;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f2f7f8 !important;
    font-family: Arial, sans-serif;
}

.login-body .login-card {
    width: 100%;
    max-width: 440px;
    padding: 38px;
    box-sizing: border-box;
    background: #ffffff !important;
    border: 1px solid #e1e9ed;
    border-radius: 16px;
    box-shadow: 0 12px 35px rgba(16, 42, 67, 0.09);
}

.login-body .brand-icon {
    width: 54px;
    height: 54px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 18px;
    border-radius: 13px;
    background: #e2f4f3 !important;
    font-size: 28px;
}

.login-body .login-card h1 {
    margin: 0 0 10px;
    color: #102a43 !important;
    font-size: 29px;
    font-weight: 700;
}

.login-body .login-card .muted {
    margin-bottom: 26px;
    color: #64748b !important;
    font-size: 15px;
}

.login-body .login-card label {
    display: block;
    margin: 16px 0 8px;
    color: #102a43 !important;
    font-size: 14px;
    font-weight: 600;
}

.login-body .login-card input {
    width: 100%;
    height: 46px;
    padding: 0 13px;
    box-sizing: border-box;
    border: 1px solid #d5e0e5 !important;
    border-radius: 8px;
    background: #ffffff !important;
    color: #102a43;
    font-size: 14px;
    outline: none;
}

.login-body .login-card input:focus {
    border-color: #0f8b8d !important;
    box-shadow: 0 0 0 3px rgba(15, 139, 141, 0.12);
}

.login-body .login-card .btn.full {
    width: 100%;
    min-height: 46px;
    margin-top: 22px;
    border: none;
    border-radius: 8px;
    background: #0f8b8d !important;
    color: #ffffff !important;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s ease;
}

.login-body .login-card .btn.full:hover {
    background: #0b686a !important;
}

.login-body .alert.error {
    padding: 12px 14px;
    margin-bottom: 16px;
    border: 1px solid #fecaca;
    border-radius: 8px;
    background: #fef2f2;
    color: #b91c1c;
    font-size: 14px;
}

@media (max-width: 480px) {
    .login-body .login-card {
        padding: 26px 22px;
    }
}
</style>
</head>

<body class="login-body">

<div class="login-card">

    <div class="brand-icon">📦</div>

    <h1>StockWise</h1>
    <p class="muted">Silakan masuk ke akun Anda</p>

    <?php if ($error): ?>
        <div class="alert error">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <form method="post">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($_SESSION['login_csrf_token']) ?>"
        >

        <label for="username">Username</label>

        <input
            type="text"
            id="username"
            name="username"
            maxlength="100"
            autocomplete="username"
            required
            autofocus
        >

        <label for="password">Password</label>

        <input
            type="password"
            id="password"
            name="password"
            autocomplete="current-password"
            required
        >

        <button type="submit" class="btn full">
            Masuk
        </button>

    </form>

</div>

</body>
</html>
