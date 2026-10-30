<?php
require_once __DIR__ . '/auth.php';
wajibLogin();

// ==========================================
// FILTER DAN PENCARIAN
// ==========================================
$type = $_GET['type'] ?? '';
$search = trim($_GET['search'] ?? '');
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

if (!in_array($type, ['', 'IN', 'OUT'], true)) {
    $type = '';
}

// Validasi format tanggal
if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}

if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    $dateFrom = '';
    $dateTo = '';
}

// ==========================================
// PENGURUTAN DATA
// ==========================================
$allowedSort = [
    'date' => 'sm.created_at',
    'sku' => 'p.sku',
    'name' => 'p.name',
    'type' => 'sm.type',
    'quantity' => 'sm.quantity',
    'user' => 'u.name'
];

$sort = $_GET['sort'] ?? 'date';
$order = strtoupper($_GET['order'] ?? 'DESC');

if (!array_key_exists($sort, $allowedSort)) {
    $sort = 'date';
}

if (!in_array($order, ['ASC', 'DESC'], true)) {
    $order = 'DESC';
}

$orderColumn = $allowedSort[$sort];

// ==========================================
// QUERY DASAR
// ==========================================
$where = " WHERE 1=1";
$params = [];

if ($type !== '') {
    $where .= " AND sm.type = ?";
    $params[] = $type;
}

if ($search !== '') {
    $where .= " AND (
        p.name LIKE ?
        OR p.sku LIKE ?
        OR sm.note LIKE ?
        OR u.name LIKE ?
    )";

    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}

if ($dateFrom !== '') {
    $where .= " AND sm.created_at >= ?";
    $params[] = $dateFrom . ' 00:00:00';
}

if ($dateTo !== '') {
    $where .= " AND sm.created_at <= ?";
    $params[] = $dateTo . ' 23:59:59';
}

// ==========================================
// HITUNG TOTAL TRANSAKSI SESUAI FILTER
// ==========================================
$countSql = "
    SELECT COUNT(*)
    FROM stock_movements sm
    JOIN products p ON p.id = sm.product_id
    JOIN users u ON u.id = sm.user_id
    $where
";

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();

// ==========================================
// PAGINATION
// ==========================================
$perPage = 10;
$totalPages = max(1, (int) ceil($totalRows / $perPage));

$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = ($page && $page > 0) ? $page : 1;
$page = min($page, $totalPages);

$offset = ($page - 1) * $perPage;

// ==========================================
// AMBIL DATA RIWAYAT
// ==========================================
$sql = "
    SELECT
        sm.*,
        p.name AS product_name,
        p.sku,
        u.name AS user_name
    FROM stock_movements sm
    JOIN products p ON p.id = sm.product_id
    JOIN users u ON u.id = sm.user_id
    $where
    ORDER BY $orderColumn $order, sm.id DESC
    LIMIT $perPage OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// ==========================================
// RINGKASAN TOTAL TRANSAKSI
// ==========================================
$totalMasuk = (int) $pdo->query(
    "SELECT COALESCE(SUM(quantity), 0)
     FROM stock_movements
     WHERE type = 'IN'"
)->fetchColumn();

$totalKeluar = (int) $pdo->query(
    "SELECT COALESCE(SUM(quantity), 0)
     FROM stock_movements
     WHERE type = 'OUT'"
)->fetchColumn();

// ==========================================
// FUNGSI LINK PAGINATION DAN SORTING
// ==========================================
function historyUrl($changes = [])
{
    $params = $_GET;

    foreach ($changes as $key => $value) {
        if ($value === null) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }

    return 'stock_history.php?' . http_build_query($params);
}

function historySortUrl($column)
{
    global $sort, $order;

    $nextOrder = ($sort === $column && $order === 'ASC')
        ? 'DESC'
        : 'ASC';

    return historyUrl([
        'sort' => $column,
        'order' => $nextOrder,
        'page' => 1
    ]);
}

function historySortIcon($column)
{
    global $sort, $order;

    if ($sort !== $column) {
        return ' ↕';
    }

    return $order === 'ASC' ? ' ↑' : ' ↓';
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Riwayat Stok - StockWise</title>

<link rel="stylesheet" href="style.css">

<style>
:root {
    --teal: #0f8b8d;
    --teal-dark: #0b686a;
    --teal-light: #e2f4f3;
    --navy: #102a43;
    --background: #f2f7f8;
    --white: #ffffff;
    --text: #243b53;
    --muted: #829ab1;
    --border: #e1e9ee;
}

body {
    background: var(--background);
    color: var(--text);
}

.sidebar {
    background: linear-gradient(180deg, #102a43, #164e63);
}

.brand-icon {
    background: var(--teal);
    color: white;
}

.menu-title {
    color: #9fb3c8;
}

.menu a {
    color: #d9e5ed;
    border-radius: 9px;
    transition: 0.2s;
}

.menu a:hover {
    background: rgba(255,255,255,0.10);
    color: white;
}

.menu a.active {
    background: var(--teal);
    color: white;
    font-weight: 600;
}

.main {
    background: var(--background);
}

.topbar {
    margin-bottom: 25px;
}

.topbar h1 {
    color: var(--navy);
    font-weight: 700;
}

.muted {
    color: var(--muted);
}

.user-chip {
    background: var(--teal-light);
    color: var(--teal-dark);
    border: 1px solid #c5e8e5;
    border-radius: 30px;
    padding: 9px 16px;
    font-weight: 600;
}

.stats {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
    margin-bottom: 24px;
}

.stat {
    background: var(--white);
    border: 1px solid var(--border);
    border-left: 5px solid var(--teal);
    border-radius: 13px;
    padding: 22px;
    box-shadow: 0 4px 14px rgba(16,42,67,0.04);
}

.stat-label {
    color: var(--muted);
    font-size: 14px;
    font-weight: 500;
}

.stat-number {
    color: var(--navy);
    font-size: 30px;
    font-weight: 700;
    margin-top: 8px;
}

.panel {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 13px;
    padding: 24px;
    margin-bottom: 22px;
    box-shadow: 0 4px 14px rgba(16,42,67,0.04);
}

.panel h2 {
    color: var(--navy);
    font-size: 18px;
    margin-top: 0;
    margin-bottom: 20px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.field label {
    display: block;
    color: var(--text);
    font-weight: 600;
    font-size: 13px;
    margin-bottom: 8px;
}

.field input,
.field select {
    width: 100%;
    box-sizing: border-box;
    padding: 11px 13px;
    border: 1px solid #d5e0e7;
    border-radius: 8px;
    background: white;
    color: var(--text);
    outline: none;
    transition: 0.2s;
}

.field input:focus,
.field select:focus {
    border-color: var(--teal);
    box-shadow: 0 0 0 3px rgba(15,139,141,0.12);
}

.actions {
    display: flex;
    gap: 10px;
    margin-top: 18px;
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 10px 19px;
    border: none;
    border-radius: 8px;
    background: var(--teal);
    color: white;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: 0.2s;
}

.btn:hover {
    background: var(--teal-dark);
}

.btn.secondary {
    background: #e8eef2;
    color: var(--navy);
}

.btn.secondary:hover {
    background: #d8e3e9;
}

.table-wrap {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 850px;
}

table thead {
    background: #edf6f6;
}

table th {
    color: var(--navy);
    font-size: 13px;
    font-weight: 700;
    text-align: left;
    padding: 14px 12px;
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
}

table th a {
    color: var(--navy);
    text-decoration: none;
}

table th a:hover {
    color: var(--teal);
}

table td {
    color: var(--text);
    font-size: 13px;
    padding: 14px 12px;
    border-bottom: 1px solid #edf1f4;
}

table tbody tr:hover {
    background: #f7fbfb;
}

.badge {
    display: inline-block;
    padding: 5px 11px;
    border-radius: 20px;
    background: var(--teal-light);
    color: var(--teal-dark);
    font-size: 12px;
    font-weight: 700;
}

.badge.danger {
    background: #fdeaea;
    color: #c03939;
}

.empty {
    text-align: center;
    color: var(--muted);
    padding: 25px;
}

.pagination {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-wrap: wrap;
    gap: 7px;
    margin-top: 22px;
}

.pagination a,
.pagination span {
    padding: 9px 13px;
    border-radius: 7px;
    border: 1px solid var(--border);
    text-decoration: none;
    color: var(--navy);
    font-size: 13px;
    background: white;
}

.pagination a:hover,
.pagination .current {
    background: var(--teal);
    color: white;
    border-color: var(--teal);
}

.pagination .disabled {
    color: #aab7c4;
    background: #f3f6f8;
}

.result-info {
    margin-top: 18px;
    color: var(--muted);
    font-size: 13px;
}

@media (max-width: 768px) {
    .stats,
    .form-grid {
        grid-template-columns: 1fr;
    }

    .panel {
        padding: 17px;
    }

    .actions {
        flex-wrap: wrap;
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
        <a href="suppliers.php">🚚 Supplier</a>
        <a href="stock_in.php">⬇ Barang Masuk</a>
        <a href="stock_out.php">⬆ Barang Keluar</a>
        <a class="active" href="stock_history.php">◷ Riwayat Stok</a>
        <a href="reports.php">▥ Laporan</a>

        <?php if (($_SESSION['user']['role'] ?? '') === 'admin'): ?>
            <a href="users.php">👥 Manajemen Pengguna</a>
        <?php endif; ?>

        <a href="logout.php">⇥ Keluar</a>
    </nav>
</aside>

<main class="main">

    <div class="topbar">
        <div>
            <h1>Riwayat Stok</h1>
            <p class="muted">
                Semua catatan perubahan persediaan barang.
            </p>
        </div>

        <div class="user-chip">
            <?= e($_SESSION['user']['name']) ?>
        </div>
    </div>

    <!-- RINGKASAN -->
    <div class="stats">
        <div class="stat">
            <div class="stat-label">Total Barang Masuk</div>
            <div class="stat-number">
                <?= number_format($totalMasuk, 0, ',', '.') ?>
            </div>
        </div>

        <div class="stat">
            <div class="stat-label">Total Barang Keluar</div>
            <div class="stat-number">
                <?= number_format($totalKeluar, 0, ',', '.') ?>
            </div>
        </div>
    </div>

    <!-- FILTER -->
    <section class="panel">
        <h2>Filter Riwayat Transaksi</h2>

        <form method="get" action="stock_history.php">

            <div class="form-grid">

                <div class="field">
                    <label>Jenis Transaksi</label>

                    <select name="type">
                        <option value="">Semua transaksi</option>

                        <option value="IN"
                            <?= $type === 'IN' ? 'selected' : '' ?>>
                            Barang masuk
                        </option>

                        <option value="OUT"
                            <?= $type === 'OUT' ? 'selected' : '' ?>>
                            Barang keluar
                        </option>
                    </select>
                </div>

                <div class="field">
                    <label>Cari Barang / SKU / Petugas / Catatan</label>

                    <input
                        type="text"
                        name="search"
                        value="<?= e($search) ?>"
                        placeholder="Masukkan kata kunci"
                    >
                </div>

                <div class="field">
                    <label>Tanggal Mulai</label>

                    <input
                        type="date"
                        name="date_from"
                        value="<?= e($dateFrom) ?>"
                    >
                </div>

                <div class="field">
                    <label>Tanggal Akhir</label>

                    <input
                        type="date"
                        name="date_to"
                        value="<?= e($dateTo) ?>"
                    >
                </div>

            </div>

            <div class="actions">
                <button class="btn" type="submit">
                    Cari / Filter
                </button>

                <a class="btn secondary" href="stock_history.php">
                    Reset
                </a>
            </div>

        </form>
    </section>

    <!-- DAFTAR TRANSAKSI -->
    <section class="panel">

        <h2>Daftar Transaksi (<?= number_format($totalRows, 0, ',', '.') ?>)</h2>

        <div class="table-wrap">

            <table>
                <thead>
                    <tr>
                        <th>No.</th>

                        <th>
                            <a href="<?= e(historySortUrl('date')) ?>">
                                Tanggal<?= historySortIcon('date') ?>
                            </a>
                        </th>

                        <th>
                            <a href="<?= e(historySortUrl('sku')) ?>">
                                SKU<?= historySortIcon('sku') ?>
                            </a>
                        </th>

                        <th>
                            <a href="<?= e(historySortUrl('name')) ?>">
                                Nama Barang<?= historySortIcon('name') ?>
                            </a>
                        </th>

                        <th>
                            <a href="<?= e(historySortUrl('type')) ?>">
                                Jenis<?= historySortIcon('type') ?>
                            </a>
                        </th>

                        <th>
                            <a href="<?= e(historySortUrl('quantity')) ?>">
                                Jumlah<?= historySortIcon('quantity') ?>
                            </a>
                        </th>

                        <th>
                            <a href="<?= e(historySortUrl('user')) ?>">
                                Petugas<?= historySortIcon('user') ?>
                            </a>
                        </th>

                        <th>Catatan</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($rows as $i => $r): ?>
                    <tr>

                        <td>
                            <?= $offset + $i + 1 ?>
                        </td>

                        <td>
                            <?= e($r['created_at']) ?>
                        </td>

                        <td>
                            <?= e($r['sku']) ?>
                        </td>

                        <td>
                            <?= e($r['product_name']) ?>
                        </td>

                        <td>
                            <?php if ($r['type'] === 'IN'): ?>
                                <span class="badge">Barang Masuk</span>
                            <?php else: ?>
                                <span class="badge danger">Barang Keluar</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?= number_format((int)$r['quantity'], 0, ',', '.') ?>
                        </td>

                        <td>
                            <?= e($r['user_name']) ?>
                        </td>

                        <td>
                            <?= e($r['note'] ?? '-') ?>
                        </td>

                    </tr>
                    <?php endforeach; ?>

                    <?php if (!$rows): ?>
                    <tr>
                        <td colspan="8" class="empty">
                            Tidak ada riwayat transaksi yang ditemukan.
                        </td>
                    </tr>
                    <?php endif; ?>

                </tbody>
            </table>

        </div>

        <!-- INFORMASI HASIL -->
        <div class="result-info">
            <?php if ($totalRows > 0): ?>
                Menampilkan
                <?= $offset + 1 ?>
                sampai
                <?= min($offset + $perPage, $totalRows) ?>
                dari
                <?= number_format($totalRows, 0, ',', '.') ?>
                transaksi.
            <?php else: ?>
                Tidak ada transaksi untuk ditampilkan.
            <?php endif; ?>
        </div>

        <!-- PAGINATION -->
        <?php if ($totalPages > 1): ?>

        <div class="pagination">

            <?php if ($page > 1): ?>
                <a href="<?= e(historyUrl(['page' => 1])) ?>">
                    Awal
                </a>

                <a href="<?= e(historyUrl(['page' => $page - 1])) ?>">
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
                    <a href="<?= e(historyUrl(['page' => $p])) ?>">
                        <?= $p ?>
                    </a>
                <?php endif; ?>

            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
                <a href="<?= e(historyUrl(['page' => $page + 1])) ?>">
                    Berikutnya
                </a>

                <a href="<?= e(historyUrl(['page' => $totalPages])) ?>">
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