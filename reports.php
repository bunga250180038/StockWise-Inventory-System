<?php
require_once __DIR__ . '/auth.php';
wajibLogin();

$error = '';
$barang = [];
$kategori = [];

$totalBarang = 0;
$totalStok = 0;
$stokMenipis = 0;
$nilaiPersediaan = 0;
$totalMasuk = 0;
$totalKeluar = 0;

// ==========================================
// FILTER LAPORAN
// ==========================================
$search = trim($_GET['search'] ?? '');
$category = filter_input(INPUT_GET, 'category', FILTER_VALIDATE_INT);
$category = ($category && $category > 0) ? $category : 0;

$status = $_GET['status'] ?? '';

if (!in_array($status, ['', 'low', 'safe'], true)) {
    $status = '';
}

// ==========================================
// SORTING DENGAN ALLOWLIST
// ==========================================
$allowedSort = [
    'name' => 'p.name',
    'sku' => 'p.sku',
    'stock' => 'p.stock',
    'price' => 'p.price',
    'category' => 'c.name',
    'value' => '(p.stock * p.price)'
];

$sort = $_GET['sort'] ?? 'name';
$order = strtoupper($_GET['order'] ?? 'ASC');

if (!array_key_exists($sort, $allowedSort)) {
    $sort = 'name';
}

if (!in_array($order, ['ASC', 'DESC'], true)) {
    $order = 'ASC';
}

$orderColumn = $allowedSort[$sort];

// ==========================================
// FUNGSI URL
// ==========================================
function reportsUrl($changes = [])
{
    $params = $_GET;

    foreach ($changes as $key => $value) {
        if ($value === null) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }

    return 'reports.php?' . http_build_query($params);
}

function reportsSortUrl($column)
{
    global $sort, $order;

    $nextOrder = ($sort === $column && $order === 'ASC')
        ? 'DESC'
        : 'ASC';

    return reportsUrl([
        'sort' => $column,
        'order' => $nextOrder,
        'page' => 1
    ]);
}

function reportsSortIcon($column)
{
    global $sort, $order;

    if ($sort !== $column) {
        return ' ↕';
    }

    return $order === 'ASC' ? ' ↑' : ' ↓';
}

// ==========================================
// AMBIL DATA LAPORAN
// ==========================================
$totalPages = 1;
$page = 1;
$perPage = 10;
$offset = 0;
$totalFiltered = 0;

try {

    // Daftar kategori
    $stmtKategori = $pdo->query(
        "SELECT id, name FROM categories ORDER BY name ASC"
    );

    $kategori = $stmtKategori->fetchAll(PDO::FETCH_ASSOC);

    // Ringkasan seluruh inventaris
    $stmtRingkasan = $pdo->query(
        "SELECT
            COUNT(*) AS total_barang,
            COALESCE(SUM(stock), 0) AS total_stok,
            COALESCE(SUM(stock * price), 0) AS nilai_persediaan,
            COALESCE(SUM(
                CASE
                    WHEN stock <= minimum_stock THEN 1
                    ELSE 0
                END
            ), 0) AS stok_menipis
         FROM products"
    );

    $ringkasan = $stmtRingkasan->fetch(PDO::FETCH_ASSOC);

    $totalBarang = (int) $ringkasan['total_barang'];
    $totalStok = (int) $ringkasan['total_stok'];
    $nilaiPersediaan = (float) $ringkasan['nilai_persediaan'];
    $stokMenipis = (int) $ringkasan['stok_menipis'];

    // Total barang masuk
    $totalMasuk = (int) $pdo->query(
        "SELECT COALESCE(SUM(quantity), 0)
         FROM stock_movements
         WHERE type = 'IN'"
    )->fetchColumn();

    // Total barang keluar
    $totalKeluar = (int) $pdo->query(
        "SELECT COALESCE(SUM(quantity), 0)
         FROM stock_movements
         WHERE type = 'OUT'"
    )->fetchColumn();

    // ======================================
    // FILTER DATA BARANG
    // ======================================
    $where = " WHERE 1=1";
    $params = [];

    if ($search !== '') {
        $where .= " AND (p.name LIKE ? OR p.sku LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }

    if ($category > 0) {
        $where .= " AND p.category_id = ?";
        $params[] = $category;
    }

    if ($status === 'low') {
        $where .= " AND p.stock <= p.minimum_stock";
    } elseif ($status === 'safe') {
        $where .= " AND p.stock > p.minimum_stock";
    }

    // Hitung hasil filter
    $countSql = "
        SELECT COUNT(*)
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        $where
    ";

    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);

    $totalFiltered = (int) $countStmt->fetchColumn();

    // ======================================
    // PAGINATION
    // ======================================
    $totalPages = max(
        1,
        (int) ceil($totalFiltered / $perPage)
    );

    $pageInput = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
    $page = ($pageInput && $pageInput > 0) ? $pageInput : 1;
    $page = min($page, $totalPages);

    $offset = ($page - 1) * $perPage;

    // ======================================
    // AMBIL DATA BARANG
    // ======================================
    $sql = "
        SELECT
            p.id,
            p.name,
            p.sku,
            p.stock,
            p.minimum_stock,
            p.price,
            c.name AS category_name,
            (p.stock * p.price) AS inventory_value
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        $where
        ORDER BY $orderColumn $order, p.id DESC
        LIMIT $perPage OFFSET $offset
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $barang = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $ex) {
    $error = 'Data laporan gagal dimuat. Periksa struktur tabel dan kolom database.';
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Laporan Inventaris - StockWise</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
      rel="stylesheet">

<style>
:root {
    --teal: #0f8b8d;
    --teal-dark: #0b686a;
    --teal-light: #e2f4f3;
    --navy: #102a43;
    --background: #f2f7f8;
    --text: #243b53;
    --muted: #829ab1;
    --border: #e1e9ee;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: var(--background);
    color: var(--text);
    font-family: Arial, Helvetica, sans-serif;
}

.sidebar {
    width: 295px;
    min-height: 100vh;
    position: fixed;
    top: 0;
    left: 0;
    padding: 28px 25px;
    background: linear-gradient(180deg, #102a43, #164e63);
    color: white;
    overflow-y: auto;
}

.brand {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 25px;
    font-weight: 700;
    margin-bottom: 4px;
}

.brand-icon {
    background: var(--teal);
    padding: 7px 10px;
    border-radius: 9px;
}

.brand-subtitle {
    color: #b8cbd8;
    font-size: 14px;
    margin-bottom: 34px;
}

.menu-label {
    color: #9fb3c8;
    font-size: 13px;
    letter-spacing: 1px;
    margin: 25px 0 12px;
}

.menu-link {
    display: block;
    color: #d9e5ed;
    text-decoration: none;
    padding: 14px 13px;
    border-radius: 9px;
    margin-bottom: 5px;
    font-size: 15px;
    transition: 0.2s;
}

.menu-link:hover {
    background: rgba(255,255,255,0.10);
    color: white;
}

.menu-link.active {
    background: var(--teal);
    color: white;
    font-weight: 600;
}

.main {
    margin-left: 295px;
    padding: 34px 36px;
}

.page-title {
    font-size: 32px;
    font-weight: 700;
    color: var(--navy);
    margin-bottom: 6px;
}

.subtitle {
    color: var(--muted);
}

.login-info {
    background: var(--teal-light);
    color: var(--teal-dark);
    border: 1px solid #c5e8e5;
    padding: 12px 18px;
    border-radius: 30px;
}

.stat-card,
.content-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 14px;
    box-shadow: 0 4px 14px rgba(16,42,67,0.04);
}

.stat-card {
    padding: 22px;
    height: 100%;
    border-left: 5px solid var(--teal);
}

.stat-label {
    color: var(--muted);
    margin-bottom: 10px;
    font-size: 14px;
}

.stat-number {
    color: var(--navy);
    font-size: 27px;
    font-weight: 700;
    overflow-wrap: anywhere;
}

.stat-note {
    color: var(--muted);
    margin-top: 8px;
    font-size: 13px;
}

.content-card {
    padding: 26px;
    margin-top: 26px;
}

.section-title {
    color: var(--navy);
    font-size: 21px;
    font-weight: 700;
    margin-bottom: 5px;
}

.form-label {
    color: var(--text);
    font-weight: 600;
    font-size: 13px;
}

.form-control,
.form-select {
    border: 1px solid #d5e0e7;
    border-radius: 8px;
    padding: 11px 13px;
}

.form-control:focus,
.form-select:focus {
    border-color: var(--teal);
    box-shadow: 0 0 0 3px rgba(15,139,141,0.12);
}

.btn-teal {
    background: var(--teal);
    color: white;
    border: none;
    border-radius: 9px;
    padding: 11px 20px;
    font-weight: 600;
    text-decoration: none;
}

.btn-teal:hover {
    background: var(--teal-dark);
    color: white;
}

.btn-light-custom {
    background: #e8eef2;
    color: var(--navy);
    border-radius: 9px;
    padding: 11px 20px;
    text-decoration: none;
    font-weight: 600;
}

.table {
    margin-bottom: 0;
}

.table thead th {
    background: #edf6f6;
    color: var(--navy);
    white-space: nowrap;
}

.table td,
.table th {
    padding: 13px 12px;
    vertical-align: middle;
}

.table tbody tr:hover {
    background: #f7fbfb;
}

.table th a {
    color: var(--navy);
    text-decoration: none;
}

.table th a:hover {
    color: var(--teal);
}

.badge-aman {
    background: #e2f4e9;
    color: #267344;
}

.badge-menipis {
    background: #fff0d7;
    color: #8b5700;
}

.pagination {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 7px;
    margin-top: 22px;
}

.pagination a,
.pagination span {
    padding: 9px 13px;
    border: 1px solid var(--border);
    border-radius: 7px;
    background: white;
    color: var(--navy);
    text-decoration: none;
    font-size: 13px;
}

.pagination a:hover,
.pagination .current {
    background: var(--teal);
    border-color: var(--teal);
    color: white;
}

.pagination .disabled {
    color: #aab7c4;
    background: #f3f6f8;
}

.result-info {
    color: var(--muted);
    font-size: 13px;
    margin-top: 17px;
}

@media (max-width: 900px) {
    .sidebar {
        position: relative;
        width: 100%;
        min-height: auto;
    }

    .main {
        margin-left: 0;
        padding: 22px 16px;
    }

    .page-title {
        font-size: 27px;
    }

    .content-card {
        padding: 18px;
    }
}

@media print {
    .sidebar,
    .no-print,
    .login-info {
        display: none !important;
    }

    .main {
        margin: 0;
        padding: 0;
    }

    body {
        background: white;
    }

    .stat-card,
    .content-card {
        box-shadow: none;
        border: 1px solid #ddd;
    }

    .table {
        font-size: 11px;
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

    <div class="brand-subtitle">Sistem Informasi Persediaan</div>

    <div class="menu-label">MENU UTAMA</div>
    <a class="menu-link" href="index.php">▦ Dashboard</a>
    <a class="menu-link" href="products.php">▣ Data Barang</a>
    <a class="menu-link" href="categories.php">▤ Kategori</a>
    <a class="menu-link" href="suppliers.php">♧ Supplier</a>

    <div class="menu-label">PERSEDIAAN</div>
    <a class="menu-link" href="stock_in.php">⬇ Barang Masuk</a>
    <a class="menu-link" href="stock_out.php">⬆ Barang Keluar</a>
    <a class="menu-link" href="stock_history.php">◷ Riwayat Stok</a>

    <div class="menu-label">LAPORAN</div>
    <a class="menu-link active" href="reports.php">▥ Laporan</a>

    <?php if (($_SESSION['user']['role'] ?? '') === 'admin'): ?>
        <div class="menu-label">ADMINISTRASI</div>
        <a class="menu-link" href="users.php">👥 Manajemen Pengguna</a>
    <?php endif; ?>

    <div class="menu-label">AKUN</div>
    <a class="menu-link" href="logout.php">⇥ Keluar</a>

</aside>

<main class="main">

    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">

        <div>
            <h1 class="page-title">Laporan Persediaan</h1>
            <div class="subtitle">
                Ringkasan dan laporan persediaan barang pada StockWise.
            </div>
        </div>

        <div class="login-info">
            Login sebagai:
            <strong><?= e($_SESSION['user']['name'] ?? 'Pengguna') ?></strong>
        </div>

    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <!-- RINGKASAN INVENTARIS -->
    <div class="row g-4">

        <div class="col-lg-4 col-md-6">
            <div class="stat-card">
                <div class="stat-label">Total Jenis Barang</div>
                <div class="stat-number">
                    <?= number_format($totalBarang, 0, ',', '.') ?>
                </div>
                <div class="stat-note">
                    Jumlah jenis barang terdaftar
                </div>
            </div>
        </div>

        <div class="col-lg-4 col-md-6">
            <div class="stat-card">
                <div class="stat-label">Total Stok Barang</div>
                <div class="stat-number">
                    <?= number_format($totalStok, 0, ',', '.') ?>
                </div>
                <div class="stat-note">
                    Akumulasi seluruh stok barang
                </div>
            </div>
        </div>

        <div class="col-lg-4 col-md-6">
            <div class="stat-card">
                <div class="stat-label">Stok Menipis</div>
                <div class="stat-number">
                    <?= number_format($stokMenipis, 0, ',', '.') ?>
                </div>
                <div class="stat-note">
                    Stok sama dengan atau di bawah batas minimum
                </div>
            </div>
        </div>

        <div class="col-lg-4 col-md-6">
            <div class="stat-card">
                <div class="stat-label">Nilai Persediaan</div>
                <div class="stat-number">
                    Rp <?= number_format($nilaiPersediaan, 0, ',', '.') ?>
                </div>
                <div class="stat-note">
                    Total stok dikalikan harga barang
                </div>
            </div>
        </div>

        <div class="col-lg-4 col-md-6">
            <div class="stat-card">
                <div class="stat-label">Total Barang Masuk</div>
                <div class="stat-number">
                    <?= number_format($totalMasuk, 0, ',', '.') ?>
                </div>
                <div class="stat-note">
                    Akumulasi seluruh transaksi IN
                </div>
            </div>
        </div>

        <div class="col-lg-4 col-md-6">
            <div class="stat-card">
                <div class="stat-label">Total Barang Keluar</div>
                <div class="stat-number">
                    <?= number_format($totalKeluar, 0, ',', '.') ?>
                </div>
                <div class="stat-note">
                    Akumulasi seluruh transaksi OUT
                </div>
            </div>
        </div>

    </div>

    <!-- FILTER LAPORAN -->
    <section class="content-card no-print">

        <h2 class="section-title mb-3">Filter Laporan Barang</h2>

        <form method="get" action="reports.php">

            <div class="row g-3">

                <div class="col-lg-4">
                    <label class="form-label">Cari Barang</label>
                    <input
                        type="text"
                        name="search"
                        class="form-control"
                        value="<?= e($search) ?>"
                        placeholder="Nama barang atau SKU"
                    >
                </div>

                <div class="col-lg-3">
                    <label class="form-label">Kategori</label>

                    <select name="category" class="form-select">
                        <option value="0">Semua Kategori</option>

                        <?php foreach ($kategori as $k): ?>
                            <option
                                value="<?= (int)$k['id'] ?>"
                                <?= $category === (int)$k['id'] ? 'selected' : '' ?>
                            >
                                <?= e($k['name']) ?>
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <div class="col-lg-3">
                    <label class="form-label">Status Stok</label>

                    <select name="status" class="form-select">
                        <option value="">Semua Status</option>
                        <option value="low" <?= $status === 'low' ? 'selected' : '' ?>>
                            Stok Menipis
                        </option>
                        <option value="safe" <?= $status === 'safe' ? 'selected' : '' ?>>
                            Stok Aman
                        </option>
                    </select>
                </div>

                <div class="col-lg-2 d-flex align-items-end gap-2">
                    <button type="submit" class="btn-teal w-100">
                        Cari
                    </button>
                </div>

            </div>

            <div class="mt-3">
                <a href="reports.php" class="btn-light-custom">
                    Reset Filter
                </a>
            </div>

        </form>

    </section>

    <!-- TABEL LAPORAN -->
    <section class="content-card">

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">

            <div>
                <h2 class="section-title">Laporan Data Barang</h2>
                <div class="subtitle">
                    Daftar barang, stok, harga, dan nilai persediaan.
                </div>
            </div>

            <button
                type="button"
                class="btn-teal no-print"
                onclick="window.print()"
            >
                Cetak Laporan
            </button>

        </div>

        <div class="table-responsive">

            <table class="table table-hover">

                <thead>
                    <tr>
                        <th>No.</th>

                        <th>
                            <a href="<?= e(reportsSortUrl('sku')) ?>">
                                SKU<?= reportsSortIcon('sku') ?>
                            </a>
                        </th>

                        <th>
                            <a href="<?= e(reportsSortUrl('name')) ?>">
                                Nama Barang<?= reportsSortIcon('name') ?>
                            </a>
                        </th>

                        <th>
                            <a href="<?= e(reportsSortUrl('category')) ?>">
                                Kategori<?= reportsSortIcon('category') ?>
                            </a>
                        </th>

                        <th>
                            <a href="<?= e(reportsSortUrl('price')) ?>">
                                Harga<?= reportsSortIcon('price') ?>
                            </a>
                        </th>

                        <th>
                            <a href="<?= e(reportsSortUrl('stock')) ?>">
                                Stok<?= reportsSortIcon('stock') ?>
                            </a>
                        </th>

                        <th>Status</th>

                        <th>
                            <a href="<?= e(reportsSortUrl('value')) ?>">
                                Nilai Persediaan<?= reportsSortIcon('value') ?>
                            </a>
                        </th>
                    </tr>
                </thead>

                <tbody>

                    <?php if ($error !== ''): ?>

                        <tr>
                            <td colspan="8" class="text-center text-secondary">
                                Laporan belum dapat dimuat.
                            </td>
                        </tr>

                    <?php elseif (!$barang): ?>

                        <tr>
                            <td colspan="8" class="text-center text-secondary py-4">
                                Tidak ada data barang yang ditemukan.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($barang as $index => $item): ?>

                            <tr>
                                <td><?= $offset + $index + 1 ?></td>

                                <td><?= e($item['sku']) ?></td>

                                <td>
                                    <strong><?= e($item['name']) ?></strong>
                                </td>

                                <td>
                                    <?= e($item['category_name'] ?? '-') ?>
                                </td>

                                <td>
                                    Rp <?= number_format((float)$item['price'], 0, ',', '.') ?>
                                </td>

                                <td>
                                    <?= number_format((int)$item['stock'], 0, ',', '.') ?>
                                </td>

                                <td>
                                    <?php if ((int)$item['stock'] <= (int)$item['minimum_stock']): ?>

                                        <span class="badge rounded-pill badge-menipis">
                                            Stok Menipis
                                        </span>

                                    <?php else: ?>

                                        <span class="badge rounded-pill badge-aman">
                                            Stok Aman
                                        </span>

                                    <?php endif; ?>
                                </td>

                                <td>
                                    Rp <?= number_format((float)$item['inventory_value'], 0, ',', '.') ?>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

        <!-- INFORMASI DATA -->
        <div class="result-info">

            <?php if ($totalFiltered > 0): ?>

                Menampilkan
                <?= $offset + 1 ?>
                sampai
                <?= min($offset + $perPage, $totalFiltered) ?>
                dari
                <?= number_format($totalFiltered, 0, ',', '.') ?>
                barang.

            <?php else: ?>

                Tidak ada barang untuk ditampilkan.

            <?php endif; ?>

        </div>

        <!-- PAGINATION -->
        <?php if ($totalPages > 1 && $error === ''): ?>

            <div class="pagination">

                <?php if ($page > 1): ?>

                    <a href="<?= e(reportsUrl(['page' => 1])) ?>">
                        Awal
                    </a>

                    <a href="<?= e(reportsUrl(['page' => $page - 1])) ?>">
                        Sebelumnya
                    </a>

                <?php else: ?>

                    <span class="disabled">Awal</span>
                    <span class="disabled">Sebelumnya</span>

                <?php endif; ?>

                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                ?>

                <?php for ($p = $startPage; $p <= $endPage; $p++): ?>

                    <?php if ($p === $page): ?>

                        <span class="current"><?= $p ?></span>

                    <?php else: ?>

                        <a href="<?= e(reportsUrl(['page' => $p])) ?>">
                            <?= $p ?>
                        </a>

                    <?php endif; ?>

                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>

                    <a href="<?= e(reportsUrl(['page' => $page + 1])) ?>">
                        Berikutnya
                    </a>

                    <a href="<?= e(reportsUrl(['page' => $totalPages])) ?>">
                        Akhir
                    </a>

                <?php else: ?>

                    <span class="disabled">Berikutnya</span>
                    <span class="disabled">Akhir</span>

                <?php endif; ?>

            </div>

        <?php endif; ?>

    </section>

</main>

</body>
</html>