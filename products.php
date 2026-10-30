<?php
require_once __DIR__ . '/auth.php';
wajibLogin();

$role = $_SESSION['user']['role'] ?? '';
$isAdmin = ($role === 'admin');

$pesan = '';
$error = '';
$edit = null;

$perPage = 10;

/*
|--------------------------------------------------------------------------
| PROSES TAMBAH, EDIT, DAN HAPUS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!$isAdmin) {
        http_response_code(403);
        exit('Akses ditolak. Hanya Admin yang boleh mengelola data barang.');
    }

    $aksi = $_POST['aksi'] ?? '';

    try {

        if ($aksi === 'simpan') {

            $id = (int)($_POST['id'] ?? 0);
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $sku = trim($_POST['sku'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $price = trim($_POST['price'] ?? '0');
            $stock = filter_var($_POST['stock'] ?? 0, FILTER_VALIDATE_INT);
            $minimumStock = filter_var(
                $_POST['minimum_stock'] ?? 5,
                FILTER_VALIDATE_INT
            );
            $description = trim($_POST['description'] ?? '');

            $supplierIds = $_POST['supplier_ids'] ?? [];
            $supplierPrices = $_POST['supplier_price'] ?? [];

            if (
                $categoryId <= 0 ||
                $sku === '' ||
                $name === ''
            ) {
                throw new RuntimeException(
                    'Kategori, SKU, dan nama barang wajib diisi.'
                );
            }

            if (
                !is_numeric($price) ||
                (float)$price < 0 ||
                $stock === false ||
                $minimumStock === false ||
                $stock < 0 ||
                $minimumStock < 0
            ) {
                throw new RuntimeException(
                    'Harga dan jumlah stok harus berupa angka yang tidak negatif.'
                );
            }

            // Pastikan kategori tersedia.
            $checkCategory = $pdo->prepare(
                'SELECT COUNT(*) FROM categories WHERE id = ?'
            );
            $checkCategory->execute([$categoryId]);

            if ((int)$checkCategory->fetchColumn() === 0) {
                throw new RuntimeException('Kategori tidak ditemukan.');
            }

            // Validasi supplier.
            if (!is_array($supplierIds)) {
                $supplierIds = [];
            }

            $supplierIds = array_unique(
                array_filter(
                    array_map('intval', $supplierIds),
                    function ($value) {
                        return $value > 0;
                    }
                )
            );

            foreach ($supplierIds as $supplierId) {

                $checkSupplier = $pdo->prepare(
                    'SELECT COUNT(*) FROM suppliers WHERE id = ?'
                );
                $checkSupplier->execute([$supplierId]);

                if ((int)$checkSupplier->fetchColumn() === 0) {
                    throw new RuntimeException(
                        'Salah satu supplier yang dipilih tidak ditemukan.'
                    );
                }

                $supplierPrice = $supplierPrices[$supplierId] ?? '0';

                if (
                    !is_numeric($supplierPrice) ||
                    (float)$supplierPrice < 0
                ) {
                    throw new RuntimeException(
                        'Harga supplier tidak boleh negatif.'
                    );
                }
            }

            $pdo->beginTransaction();

            try {

                if ($id > 0) {

                    $stmt = $pdo->prepare(
                        'UPDATE products
                         SET category_id = ?,
                             sku = ?,
                             name = ?,
                             price = ?,
                             stock = ?,
                             minimum_stock = ?,
                             description = ?
                         WHERE id = ?'
                    );

                    $stmt->execute([
                        $categoryId,
                        $sku,
                        $name,
                        (float)$price,
                        $stock,
                        $minimumStock,
                        $description ?: null,
                        $id
                    ]);

                    // Hapus relasi supplier lama.
                    $deleteRelations = $pdo->prepare(
                        'DELETE FROM product_suppliers WHERE product_id = ?'
                    );
                    $deleteRelations->execute([$id]);

                    $pesan = 'Barang berhasil diperbarui.';

                } else {

                    $stmt = $pdo->prepare(
                        'INSERT INTO products
                        (
                            category_id,
                            sku,
                            name,
                            price,
                            stock,
                            minimum_stock,
                            description
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );

                    $stmt->execute([
                        $categoryId,
                        $sku,
                        $name,
                        (float)$price,
                        $stock,
                        $minimumStock,
                        $description ?: null
                    ]);

                    $id = (int)$pdo->lastInsertId();

                    $pesan = 'Barang berhasil ditambahkan.';
                }

                // Simpan relasi barang dengan supplier.
                if (!empty($supplierIds)) {

                    $insertRelation = $pdo->prepare(
                        'INSERT INTO product_suppliers
                        (product_id, supplier_id, supplier_price)
                        VALUES (?, ?, ?)'
                    );

                    foreach ($supplierIds as $supplierId) {

                        $supplierPrice = (float)(
                            $supplierPrices[$supplierId] ?? 0
                        );

                        $insertRelation->execute([
                            $id,
                            $supplierId,
                            $supplierPrice
                        ]);
                    }
                }

                $pdo->commit();

                // Setelah simpan, tampilkan form kosong.
                $edit = null;

            } catch (Throwable $ex) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $ex;
            }

        } elseif ($aksi === 'hapus') {

            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('ID barang tidak valid.');
            }

            $stmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM stock_movements
                 WHERE product_id = ?'
            );

            $stmt->execute([$id]);

            if ((int)$stmt->fetchColumn() > 0) {
                throw new RuntimeException(
                    'Barang tidak dapat dihapus karena sudah memiliki riwayat stok.'
                );
            }

            $pdo->beginTransaction();

            try {

                $pdo->prepare(
                    'DELETE FROM product_suppliers WHERE product_id = ?'
                )->execute([$id]);

                $pdo->prepare(
                    'DELETE FROM products WHERE id = ?'
                )->execute([$id]);

                $pdo->commit();

                $pesan = 'Barang berhasil dihapus.';

            } catch (Throwable $ex) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $ex;
            }
        }

    } catch (Throwable $ex) {

        $error = $ex instanceof PDOException
            ? 'Data gagal disimpan. Pastikan SKU belum digunakan dan data terkait tersedia.'
            : $ex->getMessage();
    }
}

/*
|--------------------------------------------------------------------------
| DATA KATEGORI DAN SUPPLIER
|--------------------------------------------------------------------------
*/

$categories = $pdo->query(
    'SELECT id, name FROM categories ORDER BY name ASC'
)->fetchAll(PDO::FETCH_ASSOC);

$suppliers = $pdo->query(
    'SELECT id, name, phone FROM suppliers ORDER BY name ASC'
)->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| DATA EDIT BARANG
|--------------------------------------------------------------------------
*/

if (isset($_GET['edit'])) {

    if (!$isAdmin) {
        http_response_code(403);
        exit('Akses ditolak. Hanya Admin yang boleh mengedit data barang.');
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM products WHERE id = ?'
    );

    $stmt->execute([(int)$_GET['edit']]);

    $edit = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$edit) {
        $error = 'Data barang yang ingin diedit tidak ditemukan.';
    }
}

$selectedSuppliers = [];

if ($edit) {

    $stmt = $pdo->prepare(
        'SELECT supplier_id, supplier_price
         FROM product_suppliers
         WHERE product_id = ?'
    );

    $stmt->execute([$edit['id']]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $relation) {

        $selectedSuppliers[(int)$relation['supplier_id']] =
            $relation['supplier_price'];
    }
}

/*
|--------------------------------------------------------------------------
| PENCARIAN, FILTER, SORTING, DAN PAGINATION
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');
$filterCategory = (int)($_GET['category'] ?? 0);
$filterStock = $_GET['stock_status'] ?? '';

$allowedSort = [
    'id' => 'p.id',
    'name' => 'p.name',
    'sku' => 'p.sku',
    'price' => 'p.price',
    'stock' => 'p.stock'
];

$sort = $_GET['sort'] ?? 'id';
$order = strtoupper($_GET['order'] ?? 'DESC');

if (!isset($allowedSort[$sort])) {
    $sort = 'id';
}

if (!in_array($order, ['ASC', 'DESC'], true)) {
    $order = 'DESC';
}

$where = [];
$params = [];

// Pencarian nama atau SKU.
if ($search !== '') {

    $where[] = '(p.name LIKE ? OR p.sku LIKE ?)';

    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

// Filter kategori.
if ($filterCategory > 0) {

    $where[] = 'p.category_id = ?';
    $params[] = $filterCategory;
}

// Filter status stok.
if ($filterStock === 'low') {

    $where[] = 'p.stock <= p.minimum_stock';

} elseif ($filterStock === 'available') {

    $where[] = 'p.stock > p.minimum_stock';
}

$whereSQL = '';

if (!empty($where)) {
    $whereSQL = ' WHERE ' . implode(' AND ', $where);
}

// Hitung jumlah data.
$countSQL = "
    SELECT COUNT(*)
    FROM products p
    JOIN categories c ON c.id = p.category_id
    $whereSQL
";

$countStmt = $pdo->prepare($countSQL);
$countStmt->execute($params);

$totalRows = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalRows / $perPage));

$page = max(1, (int)($_GET['page'] ?? 1));
$page = min($page, $totalPages);

$offset = ($page - 1) * $perPage;

// Ambil data barang.
$dataSQL = "
    SELECT
        p.*,
        c.name AS category_name
    FROM products p
    JOIN categories c ON c.id = p.category_id
    $whereSQL
    ORDER BY {$allowedSort[$sort]} $order
    LIMIT $perPage OFFSET $offset
";

$dataStmt = $pdo->prepare($dataSQL);
$dataStmt->execute($params);

$rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

// Membuat URL pagination dan sorting.
$queryParams = $_GET;
unset($queryParams['page']);

function productsUrl($params, $pageNumber) {
    $params['page'] = $pageNumber;
    return 'products.php?' . http_build_query($params);
}

function productsSortUrl($column) {

    $params = $_GET;

    $currentSort = $params['sort'] ?? 'id';
    $currentOrder = strtoupper($params['order'] ?? 'DESC');

    $params['sort'] = $column;
    $params['order'] = (
        $currentSort === $column && $currentOrder === 'ASC'
    ) ? 'DESC' : 'ASC';

    unset($params['page']);

    return 'products.php?' . http_build_query($params);
}

?>

<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Data Barang - StockWise</title>

    <link rel="stylesheet" href="style.css?v=stockwise-products-6">

    <style>

        body {
            background: #f2f7f8 !important;
            color: #183247 !important;
        }

        .sidebar {
            background: linear-gradient(
                180deg,
                #102a43,
                #164e63
            ) !important;
        }

        .brand-icon {
            background: linear-gradient(
                135deg,
                #55c6bd,
                #0f8b8d
            ) !important;
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

        h1, h2, h3 {
            color: #102a43;
        }

        .panel {
            background: #ffffff !important;
            border: 1px solid #dce7eb !important;
            border-radius: 17px;
            box-shadow: 0 7px 22px #17384b0a;
            margin-bottom: 22px;
        }

        .user-chip {
            background: #ffffff;
            border-color: #dce7eb;
        }

        input, select, textarea {
            border: 1px solid #cbd9df;
            border-radius: 10px;
            padding: 10px 12px;
            width: 100%;
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
            cursor: pointer;
            display: inline-block;
            padding: 10px 15px;
            text-decoration: none;
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

        .btn.small {
            padding: 7px 11px;
            font-size: 13px;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        table th {
            background: #eaf4f5 !important;
            color: #155568 !important;
            white-space: nowrap;
        }

        table td,
        table th {
            padding: 12px;
            border-bottom: 1px solid #e5edf0;
            text-align: left;
        }

        table td {
            color: #183247;
        }

        table tr:hover td {
            background: #f2f9f9 !important;
        }

        .badge {
            background: #dff3f1 !important;
            color: #087477 !important;
            padding: 5px 9px;
            border-radius: 20px;
            display: inline-block;
            font-size: 12px;
        }

        .badge.danger {
            background: #fee2e2 !important;
            color: #991b1b !important;
        }

        .alert.success {
            background: #dcfce7;
            color: #166534;
            padding: 13px;
            border-radius: 10px;
            margin-bottom: 15px;
        }

        .alert.error {
            background: #fee2e2;
            color: #991b1b;
            padding: 13px;
            border-radius: 10px;
            margin-bottom: 15px;
        }

        .staff-notice {
            padding: 14px 18px;
            margin-bottom: 20px;
            background: #e2f4f3;
            color: #0b686a;
            border: 1px solid #bce8e5;
            border-radius: 10px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr auto auto;
            gap: 12px;
            align-items: end;
            margin-top: 18px;
        }

        .filter-grid label,
        .field label {
            display: block;
            font-weight: 600;
            margin-bottom: 7px;
            color: #24465b;
        }

        .supplier-list {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-top: 10px;
        }

        .supplier-option {
            border: 1px solid #dce7eb;
            border-radius: 12px;
            padding: 12px;
            background: #f8fbfc;
        }

        .supplier-option input[type="checkbox"] {
            width: auto;
            margin-right: 6px;
        }

        .supplier-option label {
            display: inline;
            font-weight: 600;
        }

        .supplier-option input[type="number"] {
            margin-top: 10px;
        }

        .pagination {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
            margin-top: 20px;
            align-items: center;
        }

        .pagination a,
        .pagination span {
            padding: 8px 12px;
            border: 1px solid #dce7eb;
            border-radius: 8px;
            text-decoration: none;
            color: #155568;
            background: #ffffff;
        }

        .pagination .current {
            background: #0f8b8d;
            color: #ffffff;
            border-color: #0f8b8d;
        }

        .pagination .disabled {
            color: #9ca3af;
            background: #f3f4f6;
        }

        .table-wrap {
            overflow-x: auto;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .full-width {
            grid-column: 1 / -1;
        }

        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }

        .sort-link {
            color: inherit;
            text-decoration: none;
        }

        .muted {
            color: #718096;
        }

        @media(max-width: 850px) {

            .main {
                padding: 18px;
            }

            .filter-grid {
                grid-template-columns: 1fr 1fr;
            }

            .supplier-list,
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        @media(max-width: 500px) {

            .filter-grid {
                grid-template-columns: 1fr;
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
        <a class="active" href="products.php">📦 Data Barang</a>
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
            <h1>Data Barang</h1>

            <p class="muted">
                <?= $isAdmin
                    ? 'Tambah dan kelola data inventaris StockWise.'
                    : 'Lihat daftar barang inventaris StockWise.' ?>
            </p>
        </div>

        <div class="user-chip">
            👤 <?= e($_SESSION['user']['name']) ?>
        </div>

    </div>

    <?php if ($pesan): ?>
        <div class="alert success">
            <?= e($pesan) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert error">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if (!$isAdmin): ?>

        <div class="staff-notice">
            Anda login sebagai Staff. Anda hanya dapat melihat daftar barang.
            Penambahan, perubahan, dan penghapusan barang hanya dapat dilakukan oleh Admin.
        </div>

    <?php endif; ?>

    <?php if ($isAdmin): ?>

    <section class="panel">

        <h2>
            <?= $edit ? 'Edit Barang' : 'Tambah Barang Baru' ?>
        </h2>

        <?php if (!$categories): ?>

            <div class="alert error">
                Buat kategori terlebih dahulu di menu Kategori.
            </div>

        <?php else: ?>

        <form method="post">

            <input type="hidden" name="aksi" value="simpan">

            <input
                type="hidden"
                name="id"
                value="<?= e($edit['id'] ?? '') ?>"
            >

            <div class="form-grid">

                <div class="field">

                    <label>Kategori Barang</label>

                    <select name="category_id" required>

                        <option value="">Pilih kategori</option>

                        <?php foreach ($categories as $c): ?>

                            <option
                                value="<?= (int)$c['id'] ?>"
                                <?= (int)($edit['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>
                            >
                                <?= e($c['name']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="field">

                    <label>SKU / Kode Barang</label>

                    <input
                        type="text"
                        name="sku"
                        required
                        maxlength="100"
                        placeholder="Contoh: BRG001"
                        value="<?= e($edit['sku'] ?? '') ?>"
                    >

                </div>

                <div class="field">

                    <label>Nama Barang</label>

                    <input
                        type="text"
                        name="name"
                        required
                        placeholder="Masukkan nama barang"
                        value="<?= e($edit['name'] ?? '') ?>"
                    >

                </div>

                <div class="field">

                    <label>Harga Barang (Rp)</label>

                    <input
                        type="number"
                        name="price"
                        min="0"
                        step="0.01"
                        required
                        value="<?= e($edit['price'] ?? '0') ?>"
                    >

                </div>

                <div class="field">

                    <label>Stok Saat Ini</label>

                    <input
                        type="number"
                        name="stock"
                        min="0"
                        required
                        value="<?= e($edit['stock'] ?? '0') ?>"
                    >

                </div>

                <div class="field">

                    <label>Stok Minimum</label>

                    <input
                        type="number"
                        name="minimum_stock"
                        min="0"
                        required
                        value="<?= e($edit['minimum_stock'] ?? '5') ?>"
                    >

                </div>

                <div class="field full-width">

                    <label>Deskripsi Barang</label>

                    <textarea
                        name="description"
                        rows="3"
                        placeholder="Masukkan deskripsi barang"
                    ><?= e($edit['description'] ?? '') ?></textarea>

                </div>

                <div class="field full-width">

                    <h3>Supplier Barang</h3>

                    <p class="muted">
                        Pilih supplier yang menyediakan barang ini.
                        Kamu dapat memilih lebih dari satu supplier.
                    </p>

                    <?php if (!$suppliers): ?>

                        <div class="alert error">
                            Belum ada supplier. Tambahkan supplier terlebih dahulu.
                        </div>

                    <?php else: ?>

                        <div class="supplier-list">

                            <?php foreach ($suppliers as $supplier): ?>

                                <?php
                                $supplierId = (int)$supplier['id'];

                                $isSelected = array_key_exists(
                                    $supplierId,
                                    $selectedSuppliers
                                );

                                $supplierPrice = $selectedSuppliers[$supplierId] ?? '0';
                                ?>

                                <div class="supplier-option">

                                    <input
                                        type="checkbox"
                                        id="supplier_<?= $supplierId ?>"
                                        name="supplier_ids[]"
                                        value="<?= $supplierId ?>"
                                        <?= $isSelected ? 'checked' : '' ?>
                                    >

                                    <label for="supplier_<?= $supplierId ?>">
                                        <?= e($supplier['name']) ?>
                                    </label>

                                    <?php if (!empty($supplier['phone'])): ?>
                                        <div class="muted">
                                            <?= e($supplier['phone']) ?>
                                        </div>
                                    <?php endif; ?>

                                    <label>
                                        Harga Supplier (Rp)
                                    </label>

                                    <input
                                        type="number"
                                        name="supplier_price[<?= $supplierId ?>]"
                                        min="0"
                                        step="0.01"
                                        value="<?= e($supplierPrice) ?>"
                                        placeholder="Masukkan harga supplier"
                                    >

                                </div>

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>

                </div>

            </div>

            <div class="actions" style="margin-top:20px;">

                <button class="btn" type="submit">
                    <?= $edit ? 'Perbarui Barang' : 'Simpan Barang' ?>
                </button>

                <?php if ($edit): ?>

                    <a class="btn secondary" href="products.php">
                        Batal
                    </a>

                <?php endif; ?>

            </div>

        </form>

        <?php endif; ?>

    </section>

    <?php endif; ?>

    <section class="panel">

        <h2>Daftar Barang (<?= number_format($totalRows) ?>)</h2>

        <p class="muted">
            Cari, filter, dan urutkan data barang inventaris.
        </p>

        <!-- FORM PENCARIAN DAN FILTER -->

        <form method="get" action="products.php">

            <div class="filter-grid">

                <div>

                    <label for="search">Cari Barang</label>

                    <input
                        type="text"
                        id="search"
                        name="search"
                        placeholder="Cari SKU atau nama barang..."
                        value="<?= e($search) ?>"
                    >

                </div>

                <div>

                    <label for="category">Kategori</label>

                    <select id="category" name="category">

                        <option value="0">Semua Kategori</option>

                        <?php foreach ($categories as $c): ?>

                            <option
                                value="<?= (int)$c['id'] ?>"
                                <?= $filterCategory === (int)$c['id'] ? 'selected' : '' ?>
                            >
                                <?= e($c['name']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div>

                    <label for="stock_status">Status Stok</label>

                    <select id="stock_status" name="stock_status">

                        <option value="">Semua Status</option>

                        <option
                            value="low"
                            <?= $filterStock === 'low' ? 'selected' : '' ?>
                        >
                            Stok Menipis
                        </option>

                        <option
                            value="available"
                            <?= $filterStock === 'available' ? 'selected' : '' ?>
                        >
                            Tersedia
                        </option>

                    </select>

                </div>

                <div>

                    <button type="submit" class="btn">
                        Cari / Filter
                    </button>

                </div>

                <div>

                    <a href="products.php" class="btn secondary">
                        Reset
                    </a>

                </div>

            </div>

        </form>

        <div style="margin-top:18px;">

            <label for="sort">Urutkan Data</label>

            <form method="get" action="products.php">

                <input
                    type="hidden"
                    name="search"
                    value="<?= e($search) ?>"
                >

                <input
                    type="hidden"
                    name="category"
                    value="<?= $filterCategory ?>"
                >

                <input
                    type="hidden"
                    name="stock_status"
                    value="<?= e($filterStock) ?>"
                >

                <div class="actions">

                    <select
                        name="sort"
                        id="sort"
                        style="max-width:220px;"
                    >

                        <option value="id" <?= $sort === 'id' ? 'selected' : '' ?>>
                            Terbaru
                        </option>

                        <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>
                            Nama Barang
                        </option>

                        <option value="sku" <?= $sort === 'sku' ? 'selected' : '' ?>>
                            SKU
                        </option>

                        <option value="price" <?= $sort === 'price' ? 'selected' : '' ?>>
                            Harga
                        </option>

                        <option value="stock" <?= $sort === 'stock' ? 'selected' : '' ?>>
                            Stok
                        </option>

                    </select>

                    <select
                        name="order"
                        style="max-width:160px;"
                    >

                        <option value="ASC" <?= $order === 'ASC' ? 'selected' : '' ?>>
                            Naik (A-Z)
                        </option>

                        <option value="DESC" <?= $order === 'DESC' ? 'selected' : '' ?>>
                            Turun (Z-A)
                        </option>

                    </select>

                    <button type="submit" class="btn secondary">
                        Terapkan Urutan
                    </button>

                </div>

            </form>

        </div>

        <div class="table-wrap" style="margin-top:20px;">

            <table>

                <thead>

                    <tr>

                        <th>No.</th>

                        <th>
                            <a class="sort-link" href="<?= e(productsSortUrl('sku')) ?>">
                                SKU ↕
                            </a>
                        </th>

                        <th>
                            <a class="sort-link" href="<?= e(productsSortUrl('name')) ?>">
                                Nama Barang ↕
                            </a>
                        </th>

                        <th>Kategori</th>

                        <th>
                            <a class="sort-link" href="<?= e(productsSortUrl('price')) ?>">
                                Harga ↕
                            </a>
                        </th>

                        <th>
                            <a class="sort-link" href="<?= e(productsSortUrl('stock')) ?>">
                                Stok ↕
                            </a>
                        </th>

                        <th>Status</th>

                        <?php if ($isAdmin): ?>
                            <th>Aksi</th>
                        <?php endif; ?>

                    </tr>

                </thead>

                <tbody>

                    <?php foreach ($rows as $i => $r): ?>

                        <tr>

                            <td>
                                <?= $offset + $i + 1 ?>
                            </td>

                            <td>
                                <?= e($r['sku']) ?>
                            </td>

                            <td>
                                <?= e($r['name']) ?>
                            </td>

                            <td>
                                <?= e($r['category_name']) ?>
                            </td>

                            <td>
                                Rp <?= number_format(
                                    (float)$r['price'],
                                    0,
                                    ',',
                                    '.'
                                ) ?>
                            </td>

                            <td>
                                <?= number_format((int)$r['stock']) ?>
                            </td>

                            <td>

                                <?php if (
                                    (int)$r['stock'] <= (int)$r['minimum_stock']
                                ): ?>

                                    <span class="badge danger">
                                        Stok Menipis
                                    </span>

                                <?php else: ?>

                                    <span class="badge">
                                        Tersedia
                                    </span>

                                <?php endif; ?>

                            </td>

                            <?php if ($isAdmin): ?>

                            <td>

                                <div class="actions">

                                    <a
                                        class="btn small secondary"
                                        href="products.php?edit=<?= (int)$r['id'] ?>"
                                    >
                                        Edit
                                    </a>

                                    <form method="post">

                                        <input
                                            type="hidden"
                                            name="aksi"
                                            value="hapus"
                                        >

                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= (int)$r['id'] ?>"
                                        >

                                        <button
                                            class="btn small danger"
                                            type="submit"
                                        >
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

                            <td
                                colspan="<?= $isAdmin ? 8 : 7 ?>"
                                style="text-align:center;padding:25px;"
                            >
                                Data barang tidak ditemukan.
                            </td>

                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

        <!-- PAGINATION -->

        <?php if ($totalPages > 1): ?>

            <div class="pagination">

                <?php if ($page > 1): ?>

                    <a href="<?= e(productsUrl($queryParams, 1)) ?>">
                        Awal
                    </a>

                    <a href="<?= e(productsUrl($queryParams, $page - 1)) ?>">
                        Sebelumnya
                    </a>

                <?php else: ?>

                    <span class="disabled">Awal</span>
                    <span class="disabled">Sebelumnya</span>

                <?php endif; ?>

                <?php

                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);

                for ($p = $startPage; $p <= $endPage; $p++):

                ?>

                    <?php if ($p === $page): ?>

                        <span class="current">
                            <?= $p ?>
                        </span>

                    <?php else: ?>

                        <a href="<?= e(productsUrl($queryParams, $p)) ?>">
                            <?= $p ?>
                        </a>

                    <?php endif; ?>

                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>

                    <a href="<?= e(productsUrl($queryParams, $page + 1)) ?>">
                        Berikutnya
                    </a>

                    <a href="<?= e(productsUrl($queryParams, $totalPages)) ?>">
                        Akhir
                    </a>

                <?php else: ?>

                    <span class="disabled">Berikutnya</span>
                    <span class="disabled">Akhir</span>

                <?php endif; ?>

            </div>

        <?php endif; ?>

        <p class="muted" style="margin-top:15px;">

            Menampilkan
            <?= $totalRows > 0 ? $offset + 1 : 0 ?>
            sampai
            <?= min($offset + $perPage, $totalRows) ?>
            dari
            <?= number_format($totalRows) ?>
            barang.

        </p>

    </section>

</main>

</body>
</html>