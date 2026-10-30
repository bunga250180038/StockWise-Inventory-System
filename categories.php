<?php
require_once __DIR__ . '/auth.php';
wajibLogin();

$role = $_SESSION['user']['role'] ?? '';
$isAdmin = ($role === 'admin');

$pesan = '';
$error = '';
$edit = null;

// Token CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Fungsi URL pagination dan sorting
function categoriesUrl(array $changes = []): string
{
    $params = [
        'q' => $_GET['q'] ?? '',
        'sort' => $_GET['sort'] ?? 'id',
        'order' => $_GET['order'] ?? 'DESC',
        'page' => $_GET['page'] ?? 1,
    ];

    foreach ($changes as $key => $value) {
        $params[$key] = $value;
    }

    return 'categories.php?' . http_build_query($params);
}

function categoriesSortUrl(string $column): string
{
    $currentSort = $_GET['sort'] ?? 'id';
    $currentOrder = strtoupper($_GET['order'] ?? 'DESC');

    $nextOrder = ($currentSort === $column && $currentOrder === 'ASC')
        ? 'DESC'
        : 'ASC';

    return categoriesUrl([
        'sort' => $column,
        'order' => $nextOrder,
        'page' => 1
    ]);
}

function categoriesSortIcon(string $column): string
{
    if (($_GET['sort'] ?? 'id') !== $column) {
        return '';
    }

    return strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? ' ▲' : ' ▼';
}

// Proses tambah, edit, dan hapus
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!$isAdmin) {
        http_response_code(403);
        exit('Akses ditolak. Hanya Admin yang boleh mengelola kategori.');
    }

    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
        http_response_code(419);
        exit('Token keamanan tidak valid. Silakan muat ulang halaman.');
    }

    $aksi = $_POST['aksi'] ?? '';

    try {
        if ($aksi === 'simpan') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if ($name === '') {
                throw new RuntimeException('Nama kategori wajib diisi.');
            }

            if (mb_strlen($name) > 150) {
                throw new RuntimeException('Nama kategori maksimal 150 karakter.');
            }

            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE categories SET name = ?, description = ? WHERE id = ?'
                );
                $stmt->execute([$name, $description ?: null, $id]);

                $pesan = 'Kategori berhasil diperbarui.';
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO categories (name, description) VALUES (?, ?)'
                );
                $stmt->execute([$name, $description ?: null]);

                $pesan = 'Kategori berhasil ditambahkan.';
            }

        } elseif ($aksi === 'hapus') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('Kategori tidak valid.');
            }

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM products WHERE category_id = ?'
            );
            $stmt->execute([$id]);

            if ((int)$stmt->fetchColumn() > 0) {
                throw new RuntimeException(
                    'Kategori tidak dapat dihapus karena masih digunakan oleh barang.'
                );
            }

            $stmt = $pdo->prepare('DELETE FROM categories WHERE id = ?');
            $stmt->execute([$id]);

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('Kategori tidak ditemukan.');
            }

            $pesan = 'Kategori berhasil dihapus.';
        }

    } catch (Throwable $ex) {
        $error = $ex instanceof PDOException
            ? 'Data gagal diproses. Pastikan nama kategori tidak sama dengan kategori lain.'
            : $ex->getMessage();
    }
}

// Staff tidak boleh membuka halaman edit
if (isset($_GET['edit'])) {
    if (!$isAdmin) {
        http_response_code(403);
        exit('Akses ditolak. Hanya Admin yang boleh mengedit kategori.');
    }

    $stmt = $pdo->prepare('SELECT * FROM categories WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}

// Pencarian
$q = trim($_GET['q'] ?? '');

// Sorting dengan allowlist
$allowedSort = [
    'id' => 'c.id',
    'name' => 'c.name',
    'created_at' => 'c.created_at',
    'products' => 'product_count'
];

$sort = $_GET['sort'] ?? 'id';
if (!isset($allowedSort[$sort])) {
    $sort = 'id';
}

$order = strtoupper($_GET['order'] ?? 'DESC');
if (!in_array($order, ['ASC', 'DESC'], true)) {
    $order = 'DESC';
}

$orderBy = $allowedSort[$sort];

// Pagination
$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));

// Kondisi pencarian
$where = '';
$params = [];

if ($q !== '') {
    $where = 'WHERE c.name LIKE ? OR c.description LIKE ?';
    $keyword = '%' . $q . '%';
    $params = [$keyword, $keyword];
}

// Hitung total kategori
$countSql = "
    SELECT COUNT(*)
    FROM categories c
    $where
";

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);

$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// Ambil kategori dan jumlah barang
$sql = "
    SELECT
        c.*,
        COUNT(p.id) AS product_count
    FROM categories c
    LEFT JOIN products p ON p.category_id = c.id
    $where
    GROUP BY c.id
    ORDER BY $orderBy $order
    LIMIT $perPage OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kategori Barang - StockWise</title>

    <link rel="stylesheet" href="style.css?v=stockwise-teal-6">

    <style>
        body {
            background: #f2f7f8 !important;
            color: #183247 !important;
        }

        .sidebar {
            background: linear-gradient(180deg, #102a43, #164e63) !important;
        }

        .sidebar .menu a {
            color: #eaf5f7 !important;
            background: transparent !important;
        }

        .sidebar .menu a.active,
        .sidebar .menu a:hover {
            background: #0f8b8d !important;
            color: #fff !important;
        }

        .sidebar .menu-title {
            color: #9bd8d5 !important;
        }

        .brand-icon {
            background: linear-gradient(135deg, #55c6bd, #0f8b8d) !important;
        }

        .panel {
            background: #fff !important;
            border-color: #dce7eb !important;
        }

        .btn:not(.danger) {
            background: #0f8b8d !important;
            color: #fff !important;
            border-color: #0f8b8d !important;
        }

        .btn:not(.danger):hover {
            background: #0b686a !important;
        }

        .btn.secondary {
            background: #e2f4f3 !important;
            color: #0b686a !important;
            border-color: #e2f4f3 !important;
        }

        .btn.danger {
            background: #fee2e2 !important;
            color: #991b1b !important;
            border-color: #fee2e2 !important;
        }

        table th {
            background: #eaf4f5 !important;
            color: #155568 !important;
        }

        table tr:hover td {
            background: #f2f9f9 !important;
        }

        input:focus,
        textarea:focus {
            border-color: #0f8b8d !important;
            outline: 3px solid #bce8e5 !important;
        }

        .staff-notice {
            padding: 14px 18px;
            margin-bottom: 20px;
            background: #e2f4f3;
            color: #0b686a;
            border: 1px solid #bce8e5;
            border-radius: 10px;
        }

        .search-bar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }

        .search-bar input {
            flex: 1;
            min-width: 180px;
        }

        .pagination {
            display: flex;
            gap: 7px;
            flex-wrap: wrap;
            align-items: center;
            margin-top: 20px;
        }

        .pagination a,
        .pagination span {
            padding: 8px 12px;
            border-radius: 7px;
            text-decoration: none;
            border: 1px solid #dce7eb;
            color: #155568;
            background: #fff;
        }

        .pagination .current {
            background: #0f8b8d;
            color: #fff;
            border-color: #0f8b8d;
        }

        .table-info {
            margin-top: 12px;
            color: #64748b;
            font-size: 13px;
        }

        .sort-link {
            color: inherit;
            text-decoration: none;
        }

        .sort-link:hover {
            color: #0f8b8d;
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
        <a class="active" href="categories.php">▤ Kategori</a>
        <a href="products.php">📦 Data Barang</a>
        <a href="suppliers.php">🚚 Supplier</a>
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
            <h1>Kategori Barang</h1>
            <p class="muted">Kelola kelompok barang inventaris.</p>
        </div>

        <div class="user-chip">
            <?= e($_SESSION['user']['name']) ?>
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
            Anda login sebagai Staff. Anda hanya dapat melihat daftar kategori.
            Penambahan, perubahan, dan penghapusan kategori hanya dapat dilakukan oleh Admin.
        </div>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
    <section class="panel">

        <h2><?= $edit ? 'Edit Kategori' : 'Tambah Kategori' ?></h2>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="aksi" value="simpan">
            <input type="hidden" name="id" value="<?= e($edit['id'] ?? '') ?>">

            <div class="form-grid">

                <div class="field">
                    <label>Nama Kategori</label>
                    <input
                        type="text"
                        name="name"
                        maxlength="150"
                        required
                        value="<?= e($edit['name'] ?? '') ?>"
                        placeholder="Masukkan nama kategori"
                    >
                </div>

                <div class="field">
                    <label>Deskripsi</label>
                    <textarea
                        name="description"
                        placeholder="Masukkan deskripsi kategori"
                    ><?= e($edit['description'] ?? '') ?></textarea>
                </div>

            </div>

            <div class="actions">
                <button class="btn" type="submit">Simpan</button>

                <?php if ($edit): ?>
                    <a class="btn secondary" href="categories.php">Batal</a>
                <?php endif; ?>
            </div>

        </form>

    </section>
    <?php endif; ?>

    <section class="panel">

        <h2>Daftar Kategori (<?= $totalRows ?>)</h2>

        <form method="get" class="search-bar">
            <input
                type="text"
                name="q"
                value="<?= e($q) ?>"
                placeholder="Cari nama atau deskripsi kategori..."
            >

            <input type="hidden" name="sort" value="<?= e($sort) ?>">
            <input type="hidden" name="order" value="<?= e($order) ?>">

            <button class="btn" type="submit">Cari</button>
            <a class="btn secondary" href="categories.php">Reset</a>
        </form>

        <div class="table-wrap">

            <table>

                <thead>
                    <tr>
                        <th>No.</th>
                        <th>
                            <a class="sort-link" href="<?= e(categoriesSortUrl('name')) ?>">
                                Nama<?= e(categoriesSortIcon('name')) ?>
                            </a>
                        </th>
                        <th>Deskripsi</th>
                        <th>
                            <a class="sort-link" href="<?= e(categoriesSortUrl('products')) ?>">
                                Jumlah Barang<?= e(categoriesSortIcon('products')) ?>
                            </a>
                        </th>
                        <th>
                            <a class="sort-link" href="<?= e(categoriesSortUrl('created_at')) ?>">
                                Dibuat<?= e(categoriesSortIcon('created_at')) ?>
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
                            <td><?= $offset + $i + 1 ?></td>
                            <td><?= e($r['name']) ?></td>
                            <td><?= e($r['description']) ?></td>
                            <td><?= (int)$r['product_count'] ?></td>
                            <td><?= e($r['created_at']) ?></td>

                            <?php if ($isAdmin): ?>
                            <td>
                                <div class="actions">

                                    <a
                                        class="btn small secondary"
                                        href="categories.php?edit=<?= (int)$r['id'] ?>"
                                    >
                                        Edit
                                    </a>

                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="aksi" value="hapus">
                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">

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
                            <td colspan="<?= $isAdmin ? 6 : 5 ?>" class="empty">
                                Tidak ada kategori yang ditemukan.
                            </td>
                        </tr>
                    <?php endif; ?>

                </tbody>

            </table>

        </div>

        <div class="table-info">
            Menampilkan <?= $totalRows > 0 ? $offset + 1 : 0 ?>
            sampai <?= min($offset + $perPage, $totalRows) ?>
            dari <?= $totalRows ?> kategori.
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pagination">

            <?php if ($page > 1): ?>
                <a href="<?= e(categoriesUrl(['page' => $page - 1])) ?>">‹ Sebelumnya</a>
            <?php endif; ?>

            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <?php if ($p === $page): ?>
                    <span class="current"><?= $p ?></span>
                <?php else: ?>
                    <a href="<?= e(categoriesUrl(['page' => $p])) ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
                <a href="<?= e(categoriesUrl(['page' => $page + 1])) ?>">Berikutnya ›</a>
            <?php endif; ?>

        </div>
        <?php endif; ?>

    </section>

</main>

</body>
</html>
