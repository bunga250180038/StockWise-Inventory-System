<?php
require_once __DIR__ . '/auth.php';
wajibLogin();

$role = strtolower($_SESSION['user']['role'] ?? '');
$isAdmin = ($role === 'admin');

$pesan = $_SESSION['supplier_success'] ?? '';
unset($_SESSION['supplier_success']);

$error = '';
$edit = null;

// Token CSRF.
if (empty($_SESSION['supplier_csrf'])) {
    $_SESSION['supplier_csrf'] = bin2hex(random_bytes(32));
}

// Helper URL filter, sorting, dan pagination.
function suppliersUrl(array $changes = []): string {
    $params = $_GET;

    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }

    return 'suppliers.php' . ($params ? '?' . http_build_query($params) : '');
}

// Helper sorting.
function suppliersSortUrl(string $column): string {
    $currentSort = $_GET['sort'] ?? 'id';
    $currentOrder = strtoupper($_GET['order'] ?? 'DESC');

    $newOrder = (
        $currentSort === $column && $currentOrder === 'ASC'
    ) ? 'DESC' : 'ASC';

    return suppliersUrl([
        'sort' => $column,
        'order' => $newOrder,
        'page' => 1
    ]);
}

function suppliersSortIcon(string $column): string {
    if (($_GET['sort'] ?? 'id') !== $column) {
        return '';
    }

    return strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? ' ▲' : ' ▼';
}

// Staff tidak boleh melakukan tambah, edit, atau hapus.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isAdmin) {
    http_response_code(403);
    exit('Akses ditolak. Hanya Admin yang dapat mengelola data supplier.');
}

// Memproses formulir.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token)
        || !hash_equals($_SESSION['supplier_csrf'], $token)
    ) {
        $error = 'Permintaan tidak valid. Silakan muat ulang halaman.';
    } else {
        $aksi = $_POST['aksi'] ?? '';

        try {
            // TAMBAH ATAU EDIT SUPPLIER.
            if ($aksi === 'simpan') {
                $id = filter_var($_POST['id'] ?? '0', FILTER_VALIDATE_INT);
                $id = $id === false ? 0 : (int) $id;

                $name = trim($_POST['name'] ?? '');
                $address = trim($_POST['address'] ?? '');
                $phone = trim($_POST['phone'] ?? '');

                if ($name === '') {
                    throw new RuntimeException('Nama supplier wajib diisi.');
                }

                if (strlen($name) > 150) {
                    throw new RuntimeException('Nama supplier maksimal 150 karakter.');
                }

                if (strlen($phone) > 30) {
                    throw new RuntimeException('Nomor telepon maksimal 30 karakter.');
                }

                if ($id > 0) {
                    $check = $pdo->prepare(
                        'SELECT id FROM suppliers WHERE id = ?'
                    );
                    $check->execute([$id]);

                    if (!$check->fetchColumn()) {
                        throw new RuntimeException('Supplier tidak ditemukan.');
                    }

                    $stmt = $pdo->prepare(
                        'UPDATE suppliers
                         SET name = ?, address = ?, phone = ?
                         WHERE id = ?'
                    );

                    $stmt->execute([
                        $name,
                        $address !== '' ? $address : null,
                        $phone !== '' ? $phone : null,
                        $id
                    ]);

                    $_SESSION['supplier_success'] = 'Supplier berhasil diperbarui.';
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO suppliers (name, address, phone)
                         VALUES (?, ?, ?)'
                    );

                    $stmt->execute([
                        $name,
                        $address !== '' ? $address : null,
                        $phone !== '' ? $phone : null
                    ]);

                    $_SESSION['supplier_success'] = 'Supplier berhasil ditambahkan.';
                }

                header('Location: suppliers.php');
                exit;
            }

            // HAPUS SUPPLIER.
            elseif ($aksi === 'hapus') {
                $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);

                if (!$id || $id < 1) {
                    throw new RuntimeException('Supplier tidak valid.');
                }

                $check = $pdo->prepare(
                    'SELECT id FROM suppliers WHERE id = ?'
                );
                $check->execute([$id]);

                if (!$check->fetchColumn()) {
                    throw new RuntimeException('Supplier tidak ditemukan.');
                }

                // Supplier yang masih terhubung dengan produk tidak boleh dihapus.
                $stmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM product_suppliers
                     WHERE supplier_id = ?'
                );
                $stmt->execute([$id]);

                if ((int) $stmt->fetchColumn() > 0) {
                    throw new RuntimeException(
                        'Supplier masih terhubung dengan barang dan tidak dapat dihapus.'
                    );
                }

                $stmt = $pdo->prepare(
                    'DELETE FROM suppliers WHERE id = ?'
                );
                $stmt->execute([$id]);

                $_SESSION['supplier_success'] = 'Supplier berhasil dihapus.';

                header('Location: suppliers.php');
                exit;
            } else {
                throw new RuntimeException('Aksi tidak dikenal.');
            }

        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') {
                $error = 'Supplier tidak dapat diproses karena masih memiliki hubungan data.';
            } else {
                $error = 'Terjadi kesalahan database saat memproses supplier.';
            }
        } catch (Throwable $ex) {
            $error = $ex->getMessage();
        }
    }
}

// STAFF tidak boleh membuka halaman edit melalui URL.
if (isset($_GET['edit'])) {
    if (!$isAdmin) {
        http_response_code(403);
        exit('Akses ditolak. Hanya Admin yang dapat mengedit supplier.');
    }

    $editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);

    if (!$editId || $editId < 1) {
        $error = 'ID supplier tidak valid.';
    } else {
        $stmt = $pdo->prepare(
            'SELECT * FROM suppliers WHERE id = ?'
        );
        $stmt->execute([$editId]);
        $edit = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$edit) {
            $error = 'Supplier tidak ditemukan.';
        }
    }
}

// PENCARIAN.
$search = trim($_GET['search'] ?? '');

// SORTING dengan allowlist.
$allowedSort = [
    'id' => 's.id',
    'name' => 's.name',
    'phone' => 's.phone',
    'created_at' => 's.created_at',
    'products' => 'product_count'
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
$whereSql = '';
$params = [];

if ($search !== '') {
    $whereSql = 'WHERE (s.name LIKE ? OR s.phone LIKE ? OR s.address LIKE ?)';
    $keyword = '%' . $search . '%';

    $params = [$keyword, $keyword, $keyword];
}

// Hitung total hasil.
$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM suppliers s $whereSql"
);
$countStmt->execute($params);
$totalSuppliers = (int) $countStmt->fetchColumn();

// Pagination.
$perPage = 10;
$totalPages = max(1, (int) ceil($totalSuppliers / $perPage));

$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = $page && $page > 0 ? $page : 1;
$page = min($page, $totalPages);

$offset = ($page - 1) * $perPage;

// Ambil data supplier beserta jumlah produk yang terhubung.
$sql = "
    SELECT
        s.id,
        s.name,
        s.phone,
        s.address,
        s.created_at,
        COUNT(ps.product_id) AS product_count
    FROM suppliers s
    LEFT JOIN product_suppliers ps ON ps.supplier_id = s.id
    $whereSql
    GROUP BY
        s.id,
        s.name,
        s.phone,
        s.address,
        s.created_at
    ORDER BY $orderColumn $order, s.id DESC
    LIMIT $perPage OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$startNumber = $totalSuppliers > 0 ? $offset + 1 : 0;
$endNumber = min($offset + $perPage, $totalSuppliers);

$isEdit = $edit !== null;
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Supplier - StockWise</title>

    <link rel="stylesheet" href="style.css?v=stockwise-suppliers-4">

    <style>
        body {
            background: #f2f7f8 !important;
            color: #183247 !important;
        }

        .sidebar {
            background: linear-gradient(180deg, #102a43, #164e63) !important;
        }

        .brand-icon {
            background: linear-gradient(135deg, #55c6bd, #0f8b8d) !important;
        }

        .menu-title {
            color: #9bd8d5 !important;
        }

        .menu a {
            color: #eaf5f7 !important;
            background: transparent !important;
            border-radius: 10px;
        }

        .menu a.active,
        .menu a:hover {
            background: #0f8b8d !important;
            color: #ffffff !important;
        }

        .main {
            background: #f2f7f8;
        }

        h1, h2 {
            color: #102a43;
        }

        .panel {
            background: #ffffff !important;
            border: 1px solid #dce7eb !important;
            border-radius: 17px;
            box-shadow: 0 7px 22px #17384b0a;
        }

        .user-chip {
            background: #ffffff;
            border-color: #dce7eb;
        }

        input, select, textarea {
            border: 1px solid #cbd9df;
            border-radius: 10px;
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: 3px solid #bce8e5 !important;
            border-color: #0f8b8d !important;
        }

        .btn {
            background: #0f8b8d !important;
            color: #ffffff !important;
            border: 0 !important;
            border-radius: 10px;
        }

        .btn:hover {
            background: #0b686a !important;
        }

        .btn.secondary {
            background: #e2f4f3 !important;
            color: #0b686a !important;
        }

        .btn.danger {
            background: #fee2e2 !important;
            color: #991b1b !important;
        }

        table {
            border-collapse: collapse;
        }

        table th {
            background: #eaf4f5 !important;
            color: #155568 !important;
        }

        table th a {
            color: inherit;
            text-decoration: none;
        }

        table td {
            color: #183247;
        }

        table tr:hover td {
            background: #f2f9f9 !important;
        }

        .alert.success {
            background: #dcfce7;
            color: #166534;
        }

        .alert.error {
            background: #fee2e2;
            color: #991b1b;
        }

        .staff-notice {
            padding: 14px 17px;
            margin-bottom: 20px;
            background: #e2f4f3;
            color: #0b686a;
            border: 1px solid #bce8e5;
            border-radius: 12px;
        }

        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: end;
            margin-bottom: 18px;
        }

        .filter-form .field {
            flex: 1;
            min-width: 220px;
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
            border: 1px solid #dce7eb;
            border-radius: 7px;
            text-decoration: none;
        }

        .pagination .current {
            background: #0f8b8d;
            color: white;
            border-color: #0f8b8d;
        }

        @media(max-width: 850px) {
            .main {
                padding: 18px;
            }
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
        <a class="active" href="suppliers.php">🚚 Supplier</a>
        <a href="stock_in.php">⬇ Barang Masuk</a>
        <a href="stock_out.php">⬆ Barang Keluar</a>
        <a href="stock_history.php">◷ Riwayat Stok</a>
        <a href="reports.php">▥ Laporan</a>

        <?php if ($isAdmin): ?>
            <a href="users.php">👥 Manajemen Pengguna</a>
        <?php endif; ?>

        <a href="logout.php">⇥ Keluar</a>
    </nav>
</aside>

<main class="main">

    <div class="topbar">
        <div>
            <h1>Data Supplier</h1>
            <p class="muted">Kelola informasi pemasok barang.</p>
        </div>

        <div class="user-chip">
            👤 <?= e($_SESSION['user']['name'] ?? '') ?>
            · <?= e(ucfirst($role)) ?>
        </div>
    </div>

    <?php if ($pesan): ?>
        <div class="alert success"><?= e($pesan) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (!$isAdmin): ?>
        <div class="staff-notice">
            Anda login sebagai Staff. Anda hanya dapat melihat daftar supplier.
            Penambahan, perubahan, dan penghapusan supplier hanya dapat dilakukan oleh Admin.
        </div>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
    <section class="panel">

        <h2><?= $isEdit ? 'Edit Supplier' : 'Tambah Supplier' ?></h2>

        <form method="POST" action="suppliers.php<?= $isEdit ? '?edit=' . (int) $edit['id'] : '' ?>">

            <input type="hidden" name="csrf_token"
                   value="<?= e($_SESSION['supplier_csrf']) ?>">

            <input type="hidden" name="aksi" value="simpan">

            <input type="hidden" name="id"
                   value="<?= e($edit['id'] ?? '') ?>">

            <div class="form-grid">

                <div class="field">
                    <label>Nama Supplier</label>
                    <input
                        type="text"
                        name="name"
                        required
                        maxlength="150"
                        placeholder="Masukkan nama supplier"
                        value="<?= e($edit['name'] ?? '') ?>"
                    >
                </div>

                <div class="field">
                    <label>Nomor Telepon</label>
                    <input
                        type="text"
                        name="phone"
                        maxlength="30"
                        placeholder="Masukkan nomor telepon"
                        value="<?= e($edit['phone'] ?? '') ?>"
                    >
                </div>

                <div class="field full-width">
                    <label>Alamat</label>
                    <textarea
                        name="address"
                        placeholder="Masukkan alamat supplier"
                    ><?= e($edit['address'] ?? '') ?></textarea>
                </div>

            </div>

            <div class="actions">
                <button class="btn" type="submit">
                    <?= $isEdit ? 'Perbarui Supplier' : 'Simpan Supplier' ?>
                </button>

                <?php if ($isEdit): ?>
                    <a class="btn secondary" href="suppliers.php">Batal</a>
                <?php endif; ?>
            </div>

        </form>
    </section>
    <?php endif; ?>

    <section class="panel">

        <h2>Daftar Supplier</h2>

        <p class="muted">
            Total hasil: <?= $totalSuppliers ?> supplier
        </p>

        <form method="GET" action="suppliers.php" class="filter-form">

            <div class="field">
                <label>Cari Supplier</label>
                <input
                    type="text"
                    name="search"
                    value="<?= e($search) ?>"
                    placeholder="Cari nama, telepon, atau alamat"
                >
            </div>

            <button type="submit" class="btn">Cari</button>
            <a href="suppliers.php" class="btn secondary">Reset</a>

        </form>

        <p class="muted">
            Menampilkan <?= $startNumber ?>–<?= $endNumber ?>
            dari <?= $totalSuppliers ?> supplier.
        </p>

        <div class="table-wrap">
            <table>

                <thead>
                    <tr>
                        <th>No.</th>
                        <th>
                            <a href="<?= e(suppliersSortUrl('name')) ?>">
                                Nama<?= e(suppliersSortIcon('name')) ?>
                            </a>
                        </th>
                        <th>
                            <a href="<?= e(suppliersSortUrl('phone')) ?>">
                                Telepon<?= e(suppliersSortIcon('phone')) ?>
                            </a>
                        </th>
                        <th>Alamat</th>
                        <th>
                            <a href="<?= e(suppliersSortUrl('products')) ?>">
                                Jumlah Produk<?= e(suppliersSortIcon('products')) ?>
                            </a>
                        </th>
                        <th>
                            <a href="<?= e(suppliersSortUrl('created_at')) ?>">
                                Dibuat<?= e(suppliersSortIcon('created_at')) ?>
                            </a>
                        </th>

                        <?php if ($isAdmin): ?>
                            <th>Aksi</th>
                        <?php endif; ?>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($rows as $i => $r): ?>
                        <tr>
                            <td><?= $startNumber + $i ?></td>
                            <td><?= e($r['name']) ?></td>
                            <td><?= e($r['phone'] ?? '-') ?></td>
                            <td><?= e($r['address'] ?? '-') ?></td>
                            <td><?= (int) $r['product_count'] ?></td>
                            <td><?= e($r['created_at'] ?? '-') ?></td>

                            <?php if ($isAdmin): ?>
                            <td>
                                <div class="actions">

                                    <a
                                        class="btn small secondary"
                                        href="suppliers.php?edit=<?= (int) $r['id'] ?>"
                                    >
                                        Edit
                                    </a>

                                    <form method="POST" action="suppliers.php"
                                          onsubmit="return confirm('Yakin ingin menghapus supplier ini?')">

                                        <input type="hidden" name="csrf_token"
                                               value="<?= e($_SESSION['supplier_csrf']) ?>">

                                        <input type="hidden" name="aksi" value="hapus">

                                        <input type="hidden" name="id"
                                               value="<?= (int) $r['id'] ?>">

                                        <button class="btn small danger" type="submit">
                                            Hapus
                                        </button>

                                    </form>

                                </div>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="<?= $isAdmin ? 7 : 6 ?>" class="empty">
                                Tidak ada supplier yang sesuai dengan pencarian.
                            </td>
                        </tr>
                    <?php endif; ?>

                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">

                <?php if ($page > 1): ?>
                    <a href="<?= e(suppliersUrl(['page' => $page - 1])) ?>">
                        « Sebelumnya
                    </a>
                <?php endif; ?>

                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <?php if ($p === $page): ?>
                        <span class="current"><?= $p ?></span>
                    <?php else: ?>
                        <a href="<?= e(suppliersUrl(['page' => $p])) ?>">
                            <?= $p ?>
                        </a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="<?= e(suppliersUrl(['page' => $page + 1])) ?>">
                        Berikutnya »
                    </a>
                <?php endif; ?>

            </div>
        <?php endif; ?>

    </section>

</main>

</body>
</html>