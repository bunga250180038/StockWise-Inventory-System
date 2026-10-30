<?php
require_once __DIR__ . '/auth.php';
wajibLogin();

$role = $_SESSION['user']['role'] ?? '';
$isAdmin = ($role === 'admin');

// Ringkasan data utama
$totalProduk = (int)$pdo->query(
    "SELECT COUNT(*) FROM products"
)->fetchColumn();

$totalKategori = (int)$pdo->query(
    "SELECT COUNT(*) FROM categories"
)->fetchColumn();

$totalSupplier = (int)$pdo->query(
    "SELECT COUNT(*) FROM suppliers"
)->fetchColumn();

$totalStok = (int)$pdo->query(
    "SELECT COALESCE(SUM(stock), 0) FROM products"
)->fetchColumn();

$stokRendah = (int)$pdo->query(
    "SELECT COUNT(*) FROM products WHERE stock <= minimum_stock"
)->fetchColumn();

$nilaiPersediaan = (float)$pdo->query(
    "SELECT COALESCE(SUM(stock * price), 0) FROM products"
)->fetchColumn();

// Total transaksi masuk dan keluar
$movement = $pdo->query(
    "SELECT
        COALESCE(SUM(CASE WHEN type = 'IN' THEN quantity ELSE 0 END), 0) AS total_in,
        COALESCE(SUM(CASE WHEN type = 'OUT' THEN quantity ELSE 0 END), 0) AS total_out
     FROM stock_movements"
)->fetch();

$totalMasuk = (int)($movement['total_in'] ?? 0);
$totalKeluar = (int)($movement['total_out'] ?? 0);

// Riwayat transaksi terbaru
$recent = $pdo->query(
    "SELECT
        sm.*,
        p.name AS product_name,
        p.sku,
        u.name AS user_name
     FROM stock_movements sm
     INNER JOIN products p ON p.id = sm.product_id
     INNER JOIN users u ON u.id = sm.user_id
     ORDER BY sm.id DESC
     LIMIT 8"
)->fetchAll();

// Format Rupiah
function formatRupiah($angka)
{
    return 'Rp ' . number_format((float)$angka, 0, ',', '.');
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Dashboard - StockWise</title>

<link rel="stylesheet" href="style.css?v=stockwise-teal-6">

<style>
.main {
    background: #f1f7f8;
}

.stat {
    position: relative;
    overflow: hidden;
    transition: .2s ease;
}

.stat:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 28px #17384b18;
}

.stat-number {
    color: #0f8b8d;
}

.stat:nth-child(5) .stat-number {
    color: <?= $stokRendah > 0 ? "'#b91c1c'" : "'#15803d'" ?>;
}

.panel {
    border-radius: 16px;
}

.panel h2 {
    color: #102a43;
}

.table-wrap {
    border-radius: 10px;
}

th {
    background: #e2f4f3;
    color: #0b686a;
}

.badge {
    background: #dff3f1;
    color: #087477;
}

.badge.danger {
    background: #fee2e2;
    color: #991b1b;
}

.stat-number.currency {
    font-size: 22px;
}

.dashboard-note {
    padding: 15px;
    border-radius: 10px;
    background: #e2f4f3;
    color: #0b686a;
    font-size: 13px;
}

@media(max-width:850px) {
    .stat-number.currency {
        font-size: 19px;
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

        <a class="active" href="index.php">▦ Dashboard</a>
        <a href="categories.php">▤ Kategori</a>
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
            <h1>Dashboard</h1>
            <p class="muted">
                Selamat datang di StockWise. Berikut ringkasan persediaan barang.
            </p>
        </div>

        <div class="user-chip">
            👤 <?= e($_SESSION['user']['name'] ?? '') ?>
            · <?= e($role) ?>
        </div>

    </div>

    <div class="stats">

        <div class="stat">
            <div class="stat-label">Total Barang</div>
            <div class="stat-number">
                <?= number_format($totalProduk) ?>
            </div>
        </div>

        <div class="stat">
            <div class="stat-label">Total Kategori</div>
            <div class="stat-number">
                <?= number_format($totalKategori) ?>
            </div>
        </div>

        <div class="stat">
            <div class="stat-label">Total Supplier</div>
            <div class="stat-number">
                <?= number_format($totalSupplier) ?>
            </div>
        </div>

        <div class="stat">
            <div class="stat-label">Jumlah Stok</div>
            <div class="stat-number">
                <?= number_format($totalStok) ?>
            </div>
        </div>

        <div class="stat">
            <div class="stat-label">Stok Menipis</div>
            <div class="stat-number">
                <?= number_format($stokRendah) ?>
            </div>
        </div>

        <div class="stat">
            <div class="stat-label">Total Barang Masuk</div>
            <div class="stat-number">
                <?= number_format($totalMasuk) ?>
            </div>
        </div>

        <div class="stat">
            <div class="stat-label">Total Barang Keluar</div>
            <div class="stat-number">
                <?= number_format($totalKeluar) ?>
            </div>
        </div>

        <div class="stat">
            <div class="stat-label">Nilai Persediaan</div>
            <div class="stat-number currency">
                <?= e(formatRupiah($nilaiPersediaan)) ?>
            </div>
        </div>

    </div>

    <section class="panel">

        <h2>Riwayat Pergerakan Stok Terbaru</h2>

        <p class="muted">
            Menampilkan 8 aktivitas barang masuk dan keluar terbaru.
        </p>

        <div class="table-wrap">

            <table>

                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Tanggal</th>
                        <th>SKU</th>
                        <th>Nama Barang</th>
                        <th>Jenis</th>
                        <th>Jumlah</th>
                        <th>Petugas</th>
                        <th>Catatan</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($recent): ?>

                    <?php foreach ($recent as $index => $r): ?>

                        <tr>
                            <td><?= $index + 1 ?></td>

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
                                    <span class="badge">
                                        Barang Masuk
                                    </span>
                                <?php else: ?>
                                    <span class="badge danger">
                                        Barang Keluar
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= number_format((int)$r['quantity']) ?>
                            </td>

                            <td>
                                <?= e($r['user_name']) ?>
                            </td>

                            <td>
                                <?= e($r['note'] ?? '-') ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="8" class="empty">
                            Belum ada riwayat pergerakan stok.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

    <section class="panel">

        <h2>Status Persediaan</h2>

        <?php if ($stokRendah > 0): ?>

            <div class="alert error">
                Perhatian! Ada <?= number_format($stokRendah) ?>
                produk dengan stok menipis.
                Segera lakukan pengadaan barang.
            </div>

        <?php else: ?>

            <div class="alert success">
                Kondisi stok aman. Tidak ada produk yang mencapai batas minimum.
            </div>

        <?php endif; ?>

        <div class="dashboard-note">
            Nilai persediaan dihitung dari jumlah stok dikalikan harga setiap produk.
        </div>

    </section>

</main>

</body>
</html>
