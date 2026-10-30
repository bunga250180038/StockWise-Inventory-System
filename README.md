# StockWise – Inventory Management System

Aplikasi manajemen inventaris berbasis PHP Native dan MySQL untuk membantu mengelola data barang, kategori, supplier, serta transaksi stok masuk dan stok keluar.

## 1. Deskripsi Project

**StockWise** merupakan aplikasi Inventory Management System yang dibuat untuk membantu proses pengelolaan persediaan barang secara terkomputerisasi.

Aplikasi ini menyediakan fitur pengelolaan data produk, kategori, supplier, transaksi persediaan, riwayat transaksi, laporan inventaris, serta pengaturan hak akses pengguna.

## 2. Teknologi yang Digunakan

- PHP Native
- MySQL
- PDO (PHP Data Objects)
- HTML
- CSS
- Bootstrap
- JavaScript
- XAMPP
- phpMyAdmin

## 3. Fitur Aplikasi

### A. Authentication
- Login pengguna.
- Logout pengguna.
- Password menggunakan hashing.
- Pengelolaan session.

### B. Role dan Hak Akses
Terdapat dua jenis pengguna:

**Admin**
- Mengelola pengguna.
- Menambah, mengubah, dan menghapus kategori.
- Mengelola produk.
- Mengelola supplier.
- Melakukan transaksi stok masuk dan stok keluar.
- Melihat laporan dan riwayat transaksi.

**Staff**
- Melihat data produk, kategori, dan supplier.
- Melakukan transaksi stok masuk.
- Melakukan transaksi stok keluar.
- Melihat riwayat transaksi dan laporan.
- Tidak dapat mengelola pengguna maupun mengubah data master.

### C. Master Data
- Manajemen kategori.
- Manajemen produk.
- Manajemen supplier.
- Hubungan produk dengan supplier.

### D. Transaksi Inventaris
- Stock IN untuk menambah persediaan.
- Stock OUT untuk mengurangi persediaan.
- Pencatatan jumlah barang.
- Pencatatan pengguna yang melakukan transaksi.
- Pencatatan tanggal dan catatan transaksi.

### E. Riwayat Transaksi
- Menampilkan transaksi stok masuk dan keluar.
- Pencarian transaksi.
- Filter jenis transaksi dan tanggal.
- Pengurutan data.
- Pagination.

### F. Dashboard dan Laporan
- Total produk.
- Total kategori.
- Total supplier.
- Total stok.
- Jumlah produk dengan stok rendah.
- Nilai persediaan barang.
- Total stok masuk dan stok keluar.
- Riwayat transaksi terbaru.
- Pencarian dan filter laporan.
- Cetak laporan.

### G. Keamanan
- Prepared statement menggunakan PDO.
- Password hashing.
- Validasi input.
- Escaping output menggunakan `htmlspecialchars`.
- Pembatasan akses berdasarkan role.
- CSRF protection pada form yang mendukungnya.
- Database transaction untuk transaksi stok.

## 4. Struktur Database

Database yang digunakan bernama:

`inventory_db`

Tabel utama:

| Tabel | Fungsi |
|---|---|
| `users` | Menyimpan data pengguna |
| `user_profiles` | Menyimpan profil pengguna |
| `categories` | Menyimpan kategori barang |
| `products` | Menyimpan data produk |
| `suppliers` | Menyimpan data supplier |
| `product_suppliers` | Menghubungkan produk dan supplier |
| `stock_movements` | Menyimpan riwayat transaksi stok |

### Relasi Database

1. One-to-One: `users` dengan `user_profiles`.
2. One-to-Many: `categories` dengan `products`.
3. Many-to-Many: `products` dengan `suppliers` melalui `product_suppliers`.
4. One-to-Many: `products` dengan `stock_movements`.
5. One-to-Many: `users` dengan `stock_movements`.

Database menggunakan InnoDB dan Foreign Key untuk menjaga integritas data.

## 5. Aturan Bisnis

- SKU produk harus unik.
- Harga produk tidak boleh negatif.
- Jumlah stok tidak boleh negatif.
- Minimum stok tidak boleh negatif.
- Jumlah transaksi harus lebih dari nol.
- Stok keluar tidak boleh melebihi stok tersedia.
- Setiap transaksi harus mencatat produk dan pengguna yang melakukan transaksi.
- Produk yang memiliki riwayat transaksi tidak boleh dihapus sembarangan.
- Staff tidak diperbolehkan mengakses manajemen pengguna.
- Pengguna harus login untuk mengakses halaman internal.

## 6. Cara Instalasi

### Persyaratan
- XAMPP
- PHP
- MySQL
- Browser
- phpMyAdmin

### Langkah Instalasi

1. Jalankan Apache dan MySQL melalui XAMPP Control Panel.
2. Letakkan folder project di dalam direktori `htdocs`.
3. Buka phpMyAdmin melalui:

   `http://localhost/phpmyadmin`

4. Buat database dengan nama:

   `inventory_db`

5. Impor file database SQL jika tersedia, atau gunakan database project yang sudah dibuat.
6. Periksa konfigurasi koneksi pada file `config.php`.

Konfigurasi database:

```php
$host = 'localhost';
$dbname = 'inventory_db';
$username = 'root';
$password = '';
```

7. Buka aplikasi melalui browser:

   `http://localhost/Mini_Project_1/p4-php-native/`

Sesuaikan alamat tersebut jika nama folder project berbeda.

## 7. Akun Pengguna

Akun awal untuk pengujian:

| Role | Username | Password |
|---|---|---|
| Admin | `admin` | `Admin12345` |
| Staff | `staff` | `staff123` |

**Catatan:** Ganti password akun setelah pengujian dan jangan gunakan password contoh untuk penggunaan sebenarnya.

## 8. Struktur Folder

```text
p4-php-native/
│
├── config.php
├── auth.php
├── login.php
├── logout.php
├── index.php
├── categories.php
├── products.php
├── suppliers.php
├── users.php
├── stock_in.php
├── stock_out.php
├── stock_history.php
├── reports.php
├── test_db.php
│
└── style.css
```

## 9. Pengujian Aplikasi

Pengujian dilakukan terhadap beberapa bagian aplikasi:

- Login dan logout.
- Hak akses Admin dan Staff.
- CRUD kategori.
- CRUD produk.
- CRUD supplier.
- Hubungan produk dan supplier.
- Transaksi Stock IN.
- Transaksi Stock OUT.
- Validasi stok tidak boleh negatif.
- Riwayat transaksi.
- Pencarian dan filter data.
- Dashboard dan laporan.
- Keamanan input dan hak akses.

## 10. Tujuan Project

Project ini dibuat sebagai implementasi pembelajaran PHP Native, relational database, pengelolaan transaksi, autentikasi, otorisasi, serta keamanan aplikasi berbasis web.

---

**Nama Project:** StockWise – Inventory Management System

**Bahasa Pemrograman:** PHP Native

**Database:** MySQL

**Framework:** Tidak menggunakan framework PHP

**Lingkungan Pengembangan:** XAMPP