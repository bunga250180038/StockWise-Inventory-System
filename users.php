<?php
require_once __DIR__ . '/auth.php';
wajibLogin();

// Hanya Admin yang boleh mengakses halaman ini.
if (strtolower($_SESSION['user']['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('403 - Anda tidak memiliki izin mengakses Manajemen Pengguna.');
}

// Helper keamanan output.
function userEscape($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Helper URL pagination dan filter.
function usersUrl(array $changes = []): string {
    $params = $_GET;

    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }

    return 'users.php' . ($params ? '?' . http_build_query($params) : '');
}

// Token CSRF.
if (empty($_SESSION['users_csrf'])) {
    $_SESSION['users_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$success = $_SESSION['users_success'] ?? '';
unset($_SESSION['users_success']);

$editUser = null;

// Mengambil data pengguna yang akan diedit.
if (isset($_GET['edit'])) {
    $editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);

    if (!$editId || $editId < 1) {
        $error = 'ID pengguna tidak valid.';
    } else {
        $stmt = $pdo->prepare(
            "SELECT id, name, username, role FROM users WHERE id = ?"
        );
        $stmt->execute([$editId]);
        $editUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$editUser) {
            $error = 'Pengguna tidak ditemukan.';
        }
    }
}

// Memproses formulir.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) || !hash_equals($_SESSION['users_csrf'], $token)) {
        $error = 'Permintaan tidak valid. Silakan muat ulang halaman.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            // TAMBAH PENGGUNA
            if ($action === 'create') {
                $name = trim($_POST['name'] ?? '');
                $username = trim($_POST['username'] ?? '');
                $password = $_POST['password'] ?? '';
                $role = $_POST['role'] ?? 'staff';

                if ($name === '' || $username === '' || $password === '') {
                    throw new Exception('Semua kolom wajib diisi.');
                }

                if (strlen($password) < 8) {
                    throw new Exception('Password minimal 8 karakter.');
                }

                if (!in_array($role, ['admin', 'staff'], true)) {
                    throw new Exception('Role pengguna tidak valid.');
                }

                $stmt = $pdo->prepare(
                    "INSERT INTO users (name, username, password, role)
                     VALUES (?, ?, ?, ?)"
                );

                $stmt->execute([
                    $name,
                    $username,
                    password_hash($password, PASSWORD_DEFAULT),
                    $role
                ]);

                $_SESSION['users_success'] = 'Pengguna berhasil ditambahkan.';
                header('Location: users.php');
                exit;
            }

            // EDIT PENGGUNA
            elseif ($action === 'update') {
                $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
                $name = trim($_POST['name'] ?? '');
                $username = trim($_POST['username'] ?? '');
                $password = $_POST['password'] ?? '';
                $role = $_POST['role'] ?? '';

                if (!$id || $id < 1 || $name === '' || $username === '') {
                    throw new Exception('Data pengguna belum lengkap.');
                }

                if (!in_array($role, ['admin', 'staff'], true)) {
                    throw new Exception('Role pengguna tidak valid.');
                }

                // Pastikan pengguna yang diedit memang ada.
                $check = $pdo->prepare("SELECT id, role FROM users WHERE id = ?");
                $check->execute([$id]);
                $oldUser = $check->fetch(PDO::FETCH_ASSOC);

                if (!$oldUser) {
                    throw new Exception('Pengguna tidak ditemukan.');
                }

                // Admin tidak boleh menurunkan role akun sendiri.
                if (
                    (int) $id === (int) $_SESSION['user']['id']
                    && $role !== 'admin'
                ) {
                    throw new Exception(
                        'Role akun yang sedang digunakan tidak boleh diubah menjadi Staff.'
                    );
                }

                // Jangan menghilangkan Admin terakhir.
                if ($oldUser['role'] === 'admin' && $role !== 'admin') {
                    $countAdmin = (int) $pdo->query(
                        "SELECT COUNT(*) FROM users WHERE role = 'admin'"
                    )->fetchColumn();

                    if ($countAdmin <= 1) {
                        throw new Exception('Minimal harus ada satu akun Admin.');
                    }
                }

                if ($password !== '' && strlen($password) < 8) {
                    throw new Exception('Password baru minimal 8 karakter.');
                }

                if ($password !== '') {
                    $stmt = $pdo->prepare(
                        "UPDATE users
                         SET name = ?, username = ?, password = ?, role = ?
                         WHERE id = ?"
                    );

                    $stmt->execute([
                        $name,
                        $username,
                        password_hash($password, PASSWORD_DEFAULT),
                        $role,
                        $id
                    ]);
                } else {
                    $stmt = $pdo->prepare(
                        "UPDATE users
                         SET name = ?, username = ?, role = ?
                         WHERE id = ?"
                    );

                    $stmt->execute([$name, $username, $role, $id]);
                }

                $_SESSION['users_success'] = 'Data pengguna berhasil diperbarui.';
                header('Location: users.php');
                exit;
            }

            // HAPUS PENGGUNA
            elseif ($action === 'delete') {
                $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);

                if (!$id || $id < 1) {
                    throw new Exception('ID pengguna tidak valid.');
                }

                if ((int) $id === (int) $_SESSION['user']['id']) {
                    throw new Exception('Anda tidak dapat menghapus akun sendiri.');
                }

                $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                $stmt->execute([$id]);
                $target = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$target) {
                    throw new Exception('Pengguna tidak ditemukan.');
                }

                if ($target['role'] === 'admin') {
                    $countAdmin = (int) $pdo->query(
                        "SELECT COUNT(*) FROM users WHERE role = 'admin'"
                    )->fetchColumn();

                    if ($countAdmin <= 1) {
                        throw new Exception('Admin terakhir tidak boleh dihapus.');
                    }
                }

                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$id]);

                $_SESSION['users_success'] = 'Pengguna berhasil dihapus.';
                header('Location: users.php');
                exit;
            } else {
                throw new Exception('Aksi tidak dikenal.');
            }

        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $error = 'Username sudah digunakan atau pengguna memiliki riwayat transaksi.';
            } else {
                $error = 'Terjadi kesalahan database. Periksa kembali data pengguna.';
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// FILTER PENCARIAN DAN ROLE.
$search = trim($_GET['search'] ?? '');
$roleFilter = $_GET['role'] ?? '';

if (!in_array($roleFilter, ['', 'admin', 'staff'], true)) {
    $roleFilter = '';
}

// SORTING dengan allowlist.
$allowedSort = [
    'id' => 'id',
    'name' => 'name',
    'username' => 'username',
    'role' => 'role',
    'created_at' => 'created_at'
];

$sort = $_GET['sort'] ?? 'id';
$order = strtoupper($_GET['order'] ?? 'DESC');

if (!isset($allowedSort[$sort])) {
    $sort = 'id';
}

if (!in_array($order, ['ASC', 'DESC'], true)) {
    $order = 'DESC';
}

$orderColumn = $allowedSort[$sort];

// Kondisi pencarian.
$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(name LIKE ? OR username LIKE ?)";
    $keyword = '%' . $search . '%';
    $params[] = $keyword;
    $params[] = $keyword;
}

if ($roleFilter !== '') {
    $where[] = "role = ?";
    $params[] = $roleFilter;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Hitung jumlah hasil.
$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM users $whereSql"
);
$countStmt->execute($params);
$totalUsers = (int) $countStmt->fetchColumn();

// Pagination.
$perPage = 10;
$totalPages = max(1, (int) ceil($totalUsers / $perPage));

$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = $page && $page > 0 ? $page : 1;
$page = min($page, $totalPages);

$offset = ($page - 1) * $perPage;

// Ambil data pengguna.
$sql = "
    SELECT id, name, username, role, created_at
    FROM users
    $whereSql
    ORDER BY $orderColumn $order
    LIMIT $perPage OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$startNumber = $totalUsers > 0 ? $offset + 1 : 0;
$endNumber = min($offset + $perPage, $totalUsers);

// Link sorting.
function usersSortUrl(string $column): string {
    $currentSort = $_GET['sort'] ?? 'id';
    $currentOrder = strtoupper($_GET['order'] ?? 'DESC');

    $newOrder = (
        $currentSort === $column && $currentOrder === 'ASC'
    ) ? 'DESC' : 'ASC';

    return usersUrl([
        'sort' => $column,
        'order' => $newOrder,
        'page' => 1
    ]);
}

function usersSortIcon(string $column): string {
    if (($_GET['sort'] ?? 'id') !== $column) {
        return '';
    }

    return strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? ' ▲' : ' ▼';
}

$isEdit = $editUser !== null;
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manajemen Pengguna - StockWise</title>
    <link rel="stylesheet" href="style.css?v=3">

    <style>
        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: end;
            margin-bottom: 18px;
        }

        .filter-form .field {
            flex: 1;
            min-width: 180px;
        }

        .pagination {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
            align-items: center;
            margin-top: 18px;
        }

        .pagination a,
        .pagination span {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            text-decoration: none;
        }

        .pagination .current {
            background: #087f8c;
            color: white;
            border-color: #087f8c;
        }

        .table-wrap th a {
            color: inherit;
            text-decoration: none;
        }
    </style>
</head>

<body>

<aside class="sidebar">
    <div class="brand">
        <div class="brand-icon">📦</div>
        <span>StockWise</span>
    </div>

    <div class="menu-title">Menu Utama</div>

    <nav class="menu">
        <a href="index.php">▦ Dashboard</a>
        <a href="categories.php">▤ Kategori</a>
        <a href="products.php">📦 Data Barang</a>
        <a href="suppliers.php">🚚 Supplier</a>
        <a href="stock_in.php">⬇ Barang Masuk</a>
        <a href="stock_out.php">⬆ Barang Keluar</a>
        <a href="stock_history.php">◷ Riwayat Stok</a>
        <a href="reports.php">▥ Laporan</a>
        <a class="active" href="users.php">👥 Manajemen Pengguna</a>
        <a href="logout.php">⇥ Keluar</a>
    </nav>
</aside>

<main class="main">

    <div class="topbar">
        <div>
            <h1>Manajemen Pengguna</h1>
            <p class="muted">Kelola akun Admin dan Staff StockWise.</p>
        </div>

        <div class="user-chip">
            👤 <?= userEscape($_SESSION['user']['name']) ?> · Admin
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert success"><?= userEscape($success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert error"><?= userEscape($error) ?></div>
    <?php endif; ?>

    <section class="panel">
        <h2><?= $isEdit ? 'Edit Pengguna' : 'Tambah Pengguna Baru' ?></h2>

        <form method="POST" action="users.php<?= $isEdit ? '?edit=' . (int) $editUser['id'] : '' ?>">

            <input type="hidden" name="csrf_token"
                   value="<?= userEscape($_SESSION['users_csrf']) ?>">

            <input type="hidden" name="action"
                   value="<?= $isEdit ? 'update' : 'create' ?>">

            <?php if ($isEdit): ?>
                <input type="hidden" name="id"
                       value="<?= (int) $editUser['id'] ?>">
            <?php endif; ?>

            <div class="form-grid">

                <div class="field">
                    <label>Nama Lengkap</label>
                    <input type="text" name="name" required maxlength="150"
                           value="<?= userEscape($editUser['name'] ?? '') ?>"
                           placeholder="Masukkan nama lengkap">
                </div>

                <div class="field">
                    <label>Username</label>
                    <input type="text" name="username" required
                           value="<?= userEscape($editUser['username'] ?? '') ?>"
                           placeholder="Masukkan username">
                </div>

                <div class="field">
                    <label>Password <?= $isEdit ? '(Kosongkan jika tidak diubah)' : '' ?></label>
                    <input type="password" name="password"
                           <?= $isEdit ? '' : 'required' ?>
                           minlength="8"
                           placeholder="<?= $isEdit ? 'Password baru (opsional)' : 'Minimal 8 karakter' ?>">
                </div>

                <div class="field">
                    <label>Role Pengguna</label>
                    <select name="role" required>
                        <option value="admin"
                            <?= ($editUser['role'] ?? '') === 'admin' ? 'selected' : '' ?>>
                            Admin
                        </option>

                        <option value="staff"
                            <?= ($editUser['role'] ?? 'staff') === 'staff' ? 'selected' : '' ?>>
                            Staff
                        </option>
                    </select>
                </div>

            </div>

            <div class="actions">
                <button type="submit" class="btn">
                    <?= $isEdit ? 'Simpan Perubahan' : 'Tambah Pengguna' ?>
                </button>

                <?php if ($isEdit): ?>
                    <a href="users.php" class="btn secondary">Batal</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Daftar Pengguna</h2>

        <p class="muted">
            Total hasil: <?= $totalUsers ?> akun
        </p>

        <form method="GET" action="users.php" class="filter-form">
            <div class="field">
                <label>Cari pengguna</label>
                <input type="text" name="search"
                       value="<?= userEscape($search) ?>"
                       placeholder="Cari nama atau username">
            </div>

            <div class="field">
                <label>Filter Role</label>
                <select name="role">
                    <option value="">Semua Role</option>
                    <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>
                        Admin
                    </option>
                    <option value="staff" <?= $roleFilter === 'staff' ? 'selected' : '' ?>>
                        Staff
                    </option>
                </select>
            </div>

            <button type="submit" class="btn">Cari / Filter</button>
            <a href="users.php" class="btn secondary">Reset</a>
        </form>

        <p class="muted">
            Menampilkan <?= $startNumber ?>–<?= $endNumber ?>
            dari <?= $totalUsers ?> pengguna.
        </p>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th><a href="<?= userEscape(usersSortUrl('name')) ?>">Nama<?= userEscape(usersSortIcon('name')) ?></a></th>
                        <th><a href="<?= userEscape(usersSortUrl('username')) ?>">Username<?= userEscape(usersSortIcon('username')) ?></a></th>
                        <th><a href="<?= userEscape(usersSortUrl('role')) ?>">Role<?= userEscape(usersSortIcon('role')) ?></a></th>
                        <th><a href="<?= userEscape(usersSortUrl('created_at')) ?>">Tanggal Dibuat<?= userEscape(usersSortIcon('created_at')) ?></a></th>
                        <th>Aksi</th>
                    </tr>
                </thead>

                <tbody>
                <?php if ($users): ?>
                    <?php foreach ($users as $i => $user): ?>
                        <tr>
                            <td><?= $startNumber + $i ?></td>
                            <td><?= userEscape($user['name']) ?></td>
                            <td><?= userEscape($user['username']) ?></td>
                            <td>
                                <span class="badge">
                                    <?= userEscape(strtoupper($user['role'])) ?>
                                </span>
                            </td>
                            <td><?= userEscape($user['created_at'] ?? '-') ?></td>
                            <td>
                                <div class="actions">
                                    <a class="btn small secondary"
                                       href="users.php?edit=<?= (int) $user['id'] ?>">
                                        Edit
                                    </a>

                                    <?php if ((int) $user['id'] !== (int) $_SESSION['user']['id']): ?>
                                        <form method="POST" action="users.php"
                                              onsubmit="return confirm('Yakin ingin menghapus pengguna ini?')">

                                            <input type="hidden" name="csrf_token"
                                                   value="<?= userEscape($_SESSION['users_csrf']) ?>">

                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id"
                                                   value="<?= (int) $user['id'] ?>">

                                            <button type="submit" class="btn small danger">
                                                Hapus
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="muted">Akun Anda</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="empty">
                            Tidak ada pengguna yang sesuai dengan pencarian.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="<?= userEscape(usersUrl(['page' => $page - 1])) ?>">« Sebelumnya</a>
                <?php endif; ?>

                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <?php if ($p === $page): ?>
                        <span class="current"><?= $p ?></span>
                    <?php else: ?>
                        <a href="<?= userEscape(usersUrl(['page' => $p])) ?>"><?= $p ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="<?= userEscape(usersUrl(['page' => $page + 1])) ?>">Berikutnya »</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </section>

</main>

</body>
</html>