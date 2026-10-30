<?php
require_once __DIR__ . '/auth.php';
wajibLogin();

$pesan = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $quantity = (int)($_POST['quantity'] ?? 0);
    $note = trim($_POST['note'] ?? '');

    if ($productId <= 0 || $quantity <= 0) {
        $error = 'Pilih barang dan masukkan jumlah lebih dari nol.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT id, stock FROM products WHERE id=? FOR UPDATE');
            $stmt->execute([$productId]);
            $product = $stmt->fetch();

            if (!$product) {
                throw new RuntimeException('Barang tidak ditemukan.');
            }

            if ((int)$product['stock'] < $quantity) {
                throw new RuntimeException(
                    'Stok tidak cukup. Stok saat ini: ' . (int)$product['stock'] . '.'
                );
            }

            $stmt = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id=?');
            $stmt->execute([$quantity, $productId]);

            $stmt = $pdo->prepare(
                "INSERT INTO stock_movements (product_id, user_id, type, quantity, note)
                 VALUES (?, ?, 'OUT', ?, ?)"
            );

            $stmt->execute([
                $productId,
                (int)$_SESSION['user']['id'],
                $quantity,
                $note ?: null
            ]);

            $pdo->commit();
            $pesan = 'Barang keluar berhasil disimpan dan stok sudah diperbarui.';
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $ex instanceof PDOException
                ? 'Gagal menyimpan barang keluar. Periksa data barang dan akun pengguna.'
                : $ex->getMessage();
        }
    }
}

$products = $pdo->query(
    'SELECT id, sku, name, stock FROM products ORDER BY name'
)->fetchAll();

$recent = $pdo->query(
    "SELECT sm.*, p.name AS product_name, u.name AS user_name
     FROM stock_movements sm
     JOIN products p ON p.id=sm.product_id
     JOIN users u ON u.id=sm.user_id
     WHERE sm.type='OUT'
     ORDER BY sm.id DESC LIMIT 10"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Barang Keluar - StockWise</title>
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

.field {
    margin-bottom: 17px;
}

.field label {
    display: block;
    color: var(--text);
    font-weight: 600;
    font-size: 13px;
    margin-bottom: 8px;
}

.field input,
.field select,
.field textarea {
    width: 100%;
    box-sizing: border-box;
    padding: 11px 13px;
    border: 1px solid #d5e0e7;
    border-radius: 8px;
    background: white;
    color: var(--text);
    outline: none;
    transition: 0.2s;
    font-family: inherit;
}

.field textarea {
    min-height: 100px;
    resize: vertical;
}

.field input:focus,
.field select:focus,
.field textarea:focus {
    border-color: var(--teal);
    box-shadow: 0 0 0 3px rgba(15,139,141,0.12);
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 11px 20px;
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

.alert {
    padding: 13px 16px;
    border-radius: 9px;
    margin-bottom: 18px;
    font-size: 14px;
}

.alert.success {
    background: #e2f4e9;
    border: 1px solid #b8e2c7;
    color: #267344;
}

.alert.error {
    background: #fdeaea;
    border: 1px solid #f4caca;
    color: #b83232;
}

.table-wrap {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 650px;
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

.empty {
    text-align: center;
    color: var(--muted);
    padding: 25px;
}

@media (max-width: 768px) {
    .panel {
        padding: 17px;
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
        <a class="active" href="stock_out.php">⬆ Barang Keluar</a>
        <a href="stock_history.php">◷ Riwayat Stok</a>
        <a href="reports.php">▥ Laporan</a>
        <a href="logout.php">⇥ Keluar</a>
    </nav>
</aside>

<main class="main">

    <div class="topbar">
        <div>
            <h1>Barang Keluar</h1>
            <p class="muted">Mencatat pengurangan stok barang.</p>
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

    <section class="panel">
        <h2>Form Barang Keluar</h2>

        <?php if (!$products): ?>

            <div class="alert error">
                Belum ada barang. Tambahkan barang terlebih dahulu melalui menu Data Barang.
            </div>

        <?php else: ?>

            <form method="post">

                <div class="field">
                    <label>Pilih Barang</label>

                    <select name="product_id" required>
                        <option value="">Pilih barang</option>

                        <?php foreach ($products as $p): ?>
                            <option value="<?= (int)$p['id'] ?>">
                                <?= e($p['sku']) ?> —
                                <?= e($p['name']) ?>
                                (stok: <?= (int)$p['stock'] ?>)
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <div class="field">
                    <label>Jumlah Keluar</label>
                    <input
                        type="number"
                        name="quantity"
                        min="1"
                        required
                    >
                </div>

                <div class="field">
                    <label>Catatan</label>
                    <textarea
                        name="note"
                        placeholder="Contoh: barang digunakan atau dikeluarkan"
                    ></textarea>
                </div>

                <button class="btn" type="submit">
                    Simpan Barang Keluar
                </button>

            </form>

        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Barang Keluar Terbaru</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Barang</th>
                        <th>Jumlah</th>
                        <th>Petugas</th>
                        <th>Catatan</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($recent as $r): ?>
                    <tr>
                        <td><?= e($r['created_at']) ?></td>
                        <td><?= e($r['product_name']) ?></td>
                        <td><?= (int)$r['quantity'] ?></td>
                        <td><?= e($r['user_name']) ?></td>
                        <td><?= e($r['note']) ?></td>
                    </tr>
                    <?php endforeach; ?>

                    <?php if (!$recent): ?>
                    <tr>
                        <td colspan="5" class="empty">
                            Belum ada transaksi barang keluar.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</main>
</body>
</html>
