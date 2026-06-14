<?php
// Simulasi Cicilan - PHP sederhana dengan penyimpanan MySQL dan upload bukti bayar
$uploadDir = __DIR__ . '/uploads';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

session_start();

$dbHost = '127.0.0.1';
$dbName = 'simulasi';
$dbUser = 'root';
$dbPass = '';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'Unknown database')) {
        $tmp = new PDO("mysql:host=$dbHost;charset=utf8mb4", $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $tmp->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } else {
        die('Koneksi database gagal: ' . $e->getMessage());
    }
}

function initDatabase(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        loan_amount DECIMAL(12,2) NOT NULL,
        term_months INT NOT NULL,
        monthly_payment DECIMAL(12,2) NOT NULL,
        password_hash VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $columnCheck = $pdo->query("SHOW COLUMNS FROM settings LIKE 'password_hash'")->fetch();
    if (!$columnCheck) {
        $pdo->exec("ALTER TABLE settings ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        installment_number INT NOT NULL,
        due_amount DECIMAL(12,2) NOT NULL,
        payment_amount DECIMAL(12,2) DEFAULT NULL,
        payment_date DATE DEFAULT NULL,
        proof_filename VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function getCurrentLoan(PDO $pdo): ?array
{
    $stmt = $pdo->query('SELECT * FROM settings ORDER BY id DESC LIMIT 1');
    return $stmt->fetch() ?: null;
}

function getPayments(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT * FROM payments ORDER BY installment_number ASC');
    return $stmt->fetchAll();
}

function formatRupiah($value): string
{
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

function normalizeNumber(string $value): float
{
    // Remove "Rp" dan spasi
    $clean = str_replace(['Rp', ' '], '', $value);
    // Remove titik (pemisah ribuan)
    $clean = str_replace('.', '', $clean);
    // Ganti koma dengan titik (untuk float)
    $clean = str_replace(',', '.', $clean);
    // Extract hanya angka dan titik
    $clean = preg_replace('/[^0-9.]/', '', $clean);
    return $clean === '' ? 0.0 : (float)$clean;
}

function createSchedule(PDO $pdo, float $loanAmount, int $termMonths): void
{
    $paymentBase = floor($loanAmount / $termMonths);
    $pdo->exec('DELETE FROM payments');
    $stmt = $pdo->prepare('INSERT INTO payments (installment_number, due_amount) VALUES (?, ?)');
    for ($month = 1; $month <= $termMonths; $month++) {
        $due = $month === $termMonths
            ? $loanAmount - ($paymentBase * ($termMonths - 1))
            : $paymentBase;
        $stmt->execute([$month, $due]);
    }
}

initDatabase($pdo);

$messages = [];
$errors = [];
$currentLoan = getCurrentLoan($pdo);
$passwordRequired = !empty($currentLoan['password_hash']);
$authenticated = $passwordRequired ? !empty($_SESSION['authenticated']) : true;
$loginError = '';
$payments = getPayments($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'login') {
        $password = trim($_POST['page_password'] ?? '');
        if ($passwordRequired && $currentLoan && !empty($currentLoan['password_hash'])) {
            if (password_verify($password, $currentLoan['password_hash'])) {
                $_SESSION['authenticated'] = true;
                $authenticated = true;
            } else {
                $loginError = 'Password salah. Silakan coba lagi.';
            }
        } else {
            $_SESSION['authenticated'] = true;
            $authenticated = true;
        }
    }

    if ($action !== 'login' && !$authenticated) {
        $errors[] = 'Silakan masuk terlebih dahulu.';
    }

    if ($action === 'save_settings' && $authenticated) {
        $loanAmount = normalizeNumber($_POST['loan_amount'] ?? '0');
        $termMonths = intval($_POST['term_months'] ?? 0);

        if ($loanAmount <= 0) {
            $errors[] = 'Masukkan nominal pinjaman yang valid.';
        }
        if (!in_array($termMonths, [3, 6, 12, 18, 24], true)) {
            $errors[] = 'Pilih jumlah angsuran yang tersedia.';
        }

        $password = trim($_POST['password'] ?? '');
        $passwordHash = null;
        if ($password !== '') {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        } elseif (!empty($currentLoan['password_hash'])) {
            $passwordHash = $currentLoan['password_hash'];
        }

        if (empty($errors)) {
            // Hitung estimasi angsuran bulanan dalam satuan rupiah dan bulat ke 10 terdekat
            $monthlyPayment = round($loanAmount / $termMonths / 10) * 10;
            $stmt = $pdo->prepare('INSERT INTO settings (loan_amount, term_months, monthly_payment, password_hash) VALUES (?, ?, ?, ?)');
            $stmt->execute([$loanAmount, $termMonths, $monthlyPayment, $passwordHash]);
            createSchedule($pdo, $loanAmount, $termMonths);
            $messages[] = 'Pengaturan pinjaman berhasil disimpan. Silakan cek halaman Home.';
            if ($passwordHash !== null) {
                $_SESSION['authenticated'] = true;
                $authenticated = true;
            }
            $currentLoan = getCurrentLoan($pdo);
            $payments = getPayments($pdo);
        }
    }

    if ($action === 'pay_installment') {
        $installmentId = intval($_POST['installment_id'] ?? 0);
        $paymentAmount = normalizeNumber($_POST['payment_amount'] ?? '0');
        $customAmount = normalizeNumber($_POST['custom_amount'] ?? '0');
        $uploadFile = $_FILES['proof'] ?? null;

        if ($installmentId <= 0) {
            $errors[] = 'Data angsuran tidak valid.';
        }
        if ($customAmount > 0) {
            $paymentAmount = $customAmount;
        }
        if ($paymentAmount <= 0) {
            $errors[] = 'Masukkan nominal bayar yang valid.';
        }
        if (empty($uploadFile) || $uploadFile['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Upload bukti bayar diperlukan.';
        }

        if (empty($errors)) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            $info = pathinfo($uploadFile['name']);
            $ext = strtolower($info['extension'] ?? '');
            if (!in_array($ext, $allowed, true)) {
                $errors[] = 'Format bukti bayar harus JPG, JPEG, PNG, atau GIF.';
            }
        }

        if (empty($errors)) {
            $filename = 'bukti_' . uniqid() . '.' . $ext;
            $destination = $uploadDir . '/' . $filename;
            if (!move_uploaded_file($uploadFile['tmp_name'], $destination)) {
                $errors[] = 'Gagal menyimpan bukti bayar.';
            }
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare('UPDATE payments SET payment_amount = ?, payment_date = ?, proof_filename = ? WHERE id = ?');
            $stmt->execute([$paymentAmount, date('Y-m-d'), $filename, $installmentId]);
            $messages[] = 'Pembayaran angsuran berhasil disimpan.';
            $payments = getPayments($pdo);
        }
    }

    if ($action === 'delete_proof') {
        $installmentId = intval($_POST['installment_id'] ?? 0);
        if ($installmentId <= 0) {
            $errors[] = 'Data angsuran tidak valid.';
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare('SELECT proof_filename FROM payments WHERE id = ?');
            $stmt->execute([$installmentId]);
            $row = $stmt->fetch();
            if ($row && $row['proof_filename']) {
                $filePath = $uploadDir . '/' . $row['proof_filename'];
                if (is_file($filePath)) {
                    unlink($filePath);
                }
            }
            $stmt = $pdo->prepare('UPDATE payments SET payment_amount = NULL, payment_date = NULL, proof_filename = NULL WHERE id = ?');
            $stmt->execute([$installmentId]);
            $messages[] = 'Bukti bayar berhasil dihapus. Angsuran kembali menjadi belum dibayar.';
            $payments = getPayments($pdo);
        }
    }
}

$remainingAmount = 0.0;
foreach ($payments as $payment) {
    $remainingAmount += (float)$payment['payment_amount'];
}
$remainingAmount = max(0.0, ($currentLoan['loan_amount'] ?? 0) - $remainingAmount);
$paidCount = count(array_filter($payments, fn($row) => !empty($row['payment_amount'])));

$page = $_GET['page'] ?? 'home';
if (!in_array($page, ['home', 'settings'], true)) {
    $page = 'home';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simulasi Cicilan</title>
    <style>
        :root {
            --bg: #f4f7fb;
            --card: #ffffff;
            --primary: #20315f;
            --accent: #3f83f8;
            --success: #1f8a4f;
            --danger: #d04554;
            --border: #d5dce6;
            --text: #1b2437;
        }
        * {
            box-sizing: border-box;
        }
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: var(--bg);
            color: var(--text);
        }
        body.modal-open {
            overflow: hidden;
        }
        body.modal-open > header,
        body.modal-open > main {
            filter: blur(8px);
            transition: filter .2s ease;
        }
        header {
            padding: 0px 20px;
        }
        header h1 {
            margin: 0;
            font-size: 24px;
        }
        nav {
            margin-top: 0px;
        }
        nav a {
            color: rgba(255, 255, 255, 0.85);
            text-decoration: none;
            margin-right: 18px;
            font-weight: 600;
        }
        nav a.active {
            color: #fff;
            text-decoration: underline;
        }
header .container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--primary);
    color: #fff;
    padding: 20px 20px;
    margin: 10px auto;
    max-width: 1040px;
    border-radius: 16px;
    box-shadow: 0 10px 30px rgba(24, 42, 77, 0.05);
}
        .container {
            max-width: 1040px;
            margin: 25px auto;
            padding: 0 20px;
        }
        .grid {
            display: grid;
            gap: 20px;
        }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 22px;
            box-shadow: 0 10px 30px rgba(24, 42, 77, 0.05);
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }
        .summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
        }
        .summary-item {
            background: #f8fbff;
            padding: 18px;
            border-radius: 12px;
            border: 1px solid #e6efff;
        }
        .summary-item span {
            display: block;
            color: #6b7a9f;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .summary-item strong {
            font-size: 22px;
        }
        .notice {
            padding: 14px 18px;
            border-radius: 12px;
            background: #eef8ff;
            border: 1px solid #d7edff;
            color: #1d3b59;
            margin-bottom: 18px;
        }
        .alert {
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 18px;
        }
        .alert.success { background: #eaf7ed; border: 1px solid #c8e6d9; color: #1f5d38; }
        .alert.error { background: #ffe8e9; border: 1px solid #f3c2c6; color: #78212b; }
        .installment-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .installment-item {
            border: 1px solid var(--border);
            border-radius: 14px;
            margin-bottom: 14px;
            overflow: hidden;
            background: #fff;
        }
        .installment-head {
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 18px 20px;
            background: #f9fbff;
            cursor: pointer;
            border: none;
            text-align: left;
        }
        .installment-head h3 {
            margin: 0;
            font-size: 16px;
        }
        .installment-head span {
            color: #55627d;
        }
        .status {
            padding: 8px 12px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 700;
        }
        .status.paid { background: rgba(31, 138, 79, 0.13); color: var(--success); }
        .status.unpaid { background: rgba(64, 131, 248, 0.13); color: var(--accent); }
        .installment-body {
            display: none;
            padding: 18px 20px 24px;
            border-top: 1px solid var(--border);
            background: #fff;
        }
        .installment-body.active {
            display: block;
        }
        .installment-row {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 20px;
            align-items: center;
            margin-bottom: 16px;
        }
        .installment-row p {
            margin: 0;
            color: #4b5d7a;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 16px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            transition: transform .15s ease, box-shadow .15s ease;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary { background: var(--accent); color: white; }
        .btn-secondary { background: #f4f6fb; color: var(--text); }
        .btn-danger { background: var(--danger); color: white; }
        .btn-small { padding: 8px 12px; font-size: 14px; }
        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
        }
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #c4d0df;
            border-radius: 12px;
            font-size: 15px;
            background: #fff;
        }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .estimate-card {
            background: #f8fbff;
            border: 1px dashed #cfe2ff;
            border-radius: 14px;
            padding: 18px;
        }
        .term-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 8px;
        }
        .term-button {
            border: 1px solid #c4d0df;
            background: #fff;
            color: var(--text);
            padding: 10px 14px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: background .2s ease, border-color .2s ease;
        }
        .term-button.active {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
        }
        .term-button:hover {
            border-color: var(--accent);
        }
        .hidden {
            display: none;
        }
        .proof-preview {
            max-width: 100%;
            border-radius: 12px;
            margin-top: 14px;
            border: 1px solid #dfe6ee;
        }
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(17, 34, 68, 0.65);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            z-index: 999;
        }
        .modal-backdrop.show {
            display: flex;
        }
        .modal-content {
            background: #ffffff;
            border-radius: 18px;
            max-width: 920px;
            width: 100%;
            max-height: 90vh;
            overflow: hidden;
            position: relative;
            box-shadow: 0 20px 45px rgba(16, 40, 80, 0.18);
        }
        .modal-content img {
            width: 100%;
            height: auto;
            display: block;
            object-fit: contain;
            max-height: 80vh;
            background: #f5f8ff;
        }
        .modal-close {
            position: absolute;
            top: 14px;
            right: 14px;
            width: 36px;
            height: 36px;
            border: none;
            border-radius: 50%;
            background: var(--primary);
            color: #fff;
            cursor: pointer;
            font-size: 20px;
            line-height: 1;
        }
        @media (max-width: 720px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            .installment-row {
                grid-template-columns: 1fr;
            }
            nav {
                display: flex;
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body class="<?= !$authenticated ? 'modal-open' : '' ?>">
<header>
    <div class="container">
        <h1>Simulasi Cicilan</h1>
        <nav>
            <a href="?page=home" class="<?= $page === 'home' ? 'active' : '' ?>">Home</a>
            <a href="?page=settings" class="<?= $page === 'settings' ? 'active' : '' ?>">Setting</a>
        </nav>
    </div>
</header>
<main class="container">
    <?php if (!empty($messages)): ?>
        <div class="alert success"><?= implode('<br>', array_map('htmlspecialchars', $messages)) ?></div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="alert error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
    <?php endif; ?>

    <?php if ($page === 'home'): ?>
        <div class="grid">
            <div class="card">
                <div class="card-header">
                    <div>
                        <h2>Ringkasan Pinjaman</h2>
                        <p>Informasi total cicilan dan sisa pembayaran.</p>
                    </div>
                </div>
                <?php if (!$currentLoan): ?>
                    <div class="notice">Belum ada data pinjaman. Silakan atur nominal dan jumlah cicilan di halaman Setting.</div>
                <?php else: ?>
                    <div class="summary" style="margin-top: 16px;">
                        <div class="summary-item">
                            <span>Total Pinjaman</span>
                            <strong><?= formatRupiah($currentLoan['loan_amount']) ?></strong>
                        </div>
                        <div class="summary-item">
                            <span>Sisa Jumlah Pinjaman</span>
                            <strong><?= formatRupiah($remainingAmount) ?></strong>
                        </div>
                        <div class="summary-item">
                            <span>Angsuran Terbayar</span>
                            <strong><?= $paidCount ?> / <?= $currentLoan['term_months'] ?></strong>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($currentLoan): ?>
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2>Daftar Cicilan</h2>
                            <p>Klik pada tiap angsuran untuk melihat detail pembayaran dan bukti.</p>
                        </div>
                    </div>
                    <ul class="installment-list">
                        <?php foreach ($payments as $payment): ?>
                            <?php $paid = !empty($payment['payment_amount']); ?>
                            <li class="installment-item">
                                <button type="button" class="installment-head" data-target="installment-<?= $payment['id'] ?>">
                                    <div>
                                        <h3>Bulan <?= $payment['installment_number'] ?> - <?= formatRupiah($payment['due_amount']) ?></h3>
                                        <span><?= $paid ? 'Sudah dibayar' : 'Belum dibayar' ?></span>
                                    </div>
                                    <span class="status <?= $paid ? 'paid' : 'unpaid' ?>"><?= $paid ? 'Lunas' : 'Bayar' ?></span>
                                </button>
                                <div class="installment-body" id="installment-<?= $payment['id'] ?>">
                                    <div class="installment-row">
                                        <div>
                                            <p><strong>Jumlah Bayar:</strong> <?= $paid ? formatRupiah($payment['payment_amount']) : formatRupiah($payment['due_amount']) ?></p>
                                            <p><strong>Tanggal Pembayaran:</strong> <?= $paid ? htmlspecialchars($payment['payment_date']) : 'Belum dibayar' ?></p>
                                            <?php if ($payment['proof_filename']): ?>
                                                <p><strong>Bukti Bayar:</strong> <button type="button" class="btn btn-secondary btn-small" onclick="openProofModal('uploads/<?= htmlspecialchars($payment['proof_filename']) ?>')">Lihat bukti</button></p>
                                            <?php endif; ?>
                                        </div>
                                        <div style="text-align:right; display:flex; gap:10px; flex-wrap:wrap; justify-content:flex-end;">
                                            <?php if (!$paid): ?>
                                                <button type="button" class="btn btn-primary btn-small" onclick="showPayForm(<?= $payment['id'] ?>)">Bayar</button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-secondary btn-small" onclick="toggleEdit(<?= $payment['id'] ?>)">Edit</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <?php if (!$paid): ?>
                                        <div class="pay-form hidden" id="pay-form-<?= $payment['id'] ?>">
                                            <form action="?page=home" method="post" enctype="multipart/form-data">
                                                <input type="hidden" name="action" value="pay_installment">
                                                <input type="hidden" name="installment_id" value="<?= $payment['id'] ?>">
                                                <div class="form-group">
                                                    <label>Nominal Bayar</label>
                                                    <input type="text" name="payment_amount" value="<?= formatRupiah($payment['due_amount']) ?>" class="amount-input" data-default="<?= $payment['due_amount'] ?>" required>
                                                </div>
                                                <div class="form-group">
                                                    <label>Upload Bukti Bayar</label>
                                                    <input type="file" name="proof" accept="image/*" required>
                                                </div>
                                                <button type="submit" class="btn btn-primary">Simpan Pembayaran</button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <div class="edit-form hidden" id="edit-form-<?= $payment['id'] ?>">
                                            <div class="notice">Hapus bukti bayar untuk mengembalikan angsuran ke status belum dibayar.</div>
                                            <form action="?page=home" method="post">
                                                <input type="hidden" name="action" value="delete_proof">
                                                <input type="hidden" name="installment_id" value="<?= $payment['id'] ?>">
                                                <button type="submit" class="btn btn-danger">Hapus Bukti Bayar</button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    <?php elseif ($page === 'settings'): ?>
        <div class="grid">
            <div class="card">
                <div class="card-header">
                    <div>
                        <h2>Pengaturan Pinjaman</h2>
                        <p>Atur nominal pinjaman dan pilih tenor cicilan.</p>
                    </div>
                </div>
                <form action="?page=settings" method="post">
                    <input type="hidden" name="action" value="save_settings">
                    <div class="form-group">
                        <label>Nominal Pinjaman</label>
                        <input type="text" name="loan_amount" id="loan_amount" value="<?= htmlspecialchars(isset($currentLoan['loan_amount']) ? number_format($currentLoan['loan_amount'], 0, ',', '.') : '') ?>" placeholder="5.000.000" required>
                    </div>
                    <div class="form-group">
                        <label>Jumlah Angsuran</label>
                        <input type="hidden" name="term_months" id="term_months" value="<?= htmlspecialchars($currentLoan['term_months'] ?? '') ?>" required>
                        <div class="term-buttons" id="term-buttons">
                            <?php foreach ([3, 6, 12, 18, 24] as $option): ?>
                                <button type="button" class="term-button <?= ($currentLoan['term_months'] ?? '') == $option ? 'active' : '' ?>" data-term="<?= $option ?>"><?= $option ?> bulan</button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Estimasi Angsuran</label>
                        <input type="text" id="estimation" readonly value="<?= $currentLoan ? formatRupiah($currentLoan['monthly_payment']) : 'Rp 0' ?>">
                    </div>
                    <div class="form-group">
                        <label><?= $currentLoan ? 'Ubah Password (opsional)' : 'Password' ?></label>
                        <input type="password" name="password" id="password" autocomplete="new-password" placeholder="<?= $currentLoan ? 'Kosongkan jika tidak ingin mengubah' : 'Masukkan password akses' ?>">
                    </div>
                    <button type="submit" class="btn btn-primary">Simpan Pengaturan</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</main>
<div class="modal-backdrop <?= !$authenticated ? 'show' : '' ?>" id="login-modal">
    <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="login-modal-title" onclick="event.stopPropagation()">
        <div style="padding: 16px;">
            <h3 id="login-modal-title" style="margin:0 0 12px; font-size:18px; color:#20315f;">Masuk</h3>
            <?php if ($loginError): ?>
                <div class="alert error" style="margin-bottom: 14px;"><?= htmlspecialchars($loginError) ?></div>
            <?php endif; ?>
            <form action="?page=<?= htmlspecialchars($page) ?>" method="post">
                <input type="hidden" name="action" value="login">
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="page_password" required autofocus>
                </div>
                <button type="submit" class="btn btn-primary">Masuk</button>
            </form>
        </div>
    </div>
</div>
<div class="modal-backdrop" id="proof-modal" onclick="closeProofModal(event)">
    <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="proof-modal-title" onclick="event.stopPropagation()">
        <button type="button" class="modal-close" onclick="closeProofModal()" aria-label="Tutup modal">×</button>
        <div style="padding: 16px;">
            <h3 id="proof-modal-title" style="margin:0 0 12px; font-size:18px; color:#20315f;">Bukti Bayar</h3>
            <img src="" alt="Bukti bayar" id="proof-image" class="proof-preview">
        </div>
    </div>
</div>
<script>
    document.querySelectorAll('.installment-head').forEach(function(button) {
        button.addEventListener('click', function() {
            var target = document.getElementById(button.dataset.target);
            if (target) {
                target.classList.toggle('active');
            }
        });
    });

    function showPayForm(id) {
        var form = document.getElementById('pay-form-' + id);
        if (form) {
            form.classList.toggle('hidden');
            form.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    function openProofModal(imagePath) {
        var modal = document.getElementById('proof-modal');
        var image = document.getElementById('proof-image');
        if (!modal || !image) return;
        image.src = imagePath;
        modal.classList.add('show');
    }

    function closeProofModal(event) {
        if (event && event.target !== event.currentTarget) {
            return;
        }
        var modal = document.getElementById('proof-modal');
        var image = document.getElementById('proof-image');
        if (!modal || !image) return;
        modal.classList.remove('show');
        image.src = '';
    }

    function closeLoginModal(event) {
        if (event && event.target !== event.currentTarget) {
            return;
        }
        var modal = document.getElementById('login-modal');
        if (!modal) return;
        if (!modal.classList.contains('show')) return;
        if (<?= $authenticated ? 'true' : 'false' ?>) {
            modal.classList.remove('show');
        }
    }

    function toggleEdit(id) {
        var form = document.getElementById('edit-form-' + id);
        if (form) {
            form.classList.toggle('hidden');
            form.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    function parseNumber(value) {
        // Remove "Rp" dan spasi
        value = value.replace(/Rp/gi, '').trim();
        // Remove titik (pemisah ribuan)
        value = value.replace(/\./g, '');
        // Ganti koma dengan titik (untuk float)
        value = value.replace(/,/g, '.');
        // Extract hanya angka dan titik
        value = value.replace(/[^0-9.]/g, '');
        return Number(value) || 0;
    }

    function formatRupiah(value) {
        if (value === null || value === undefined) {
            return 'Rp 0';
        }
        var number = Number(value);
        if (isNaN(number)) {
            return 'Rp 0';
        }
        var parts = number.toString().split('.');
        var integerPart = parts[0];
        var decimalPart = parts[1] || '';
        integerPart = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        if (decimalPart.length > 0) {
            return 'Rp ' + integerPart + ',' + decimalPart;
        }
        return 'Rp ' + integerPart;
    }

    function formatPlainNumber(value) {
        var number = parseNumber(value);
        if (number <= 0) return '';
        return number.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    var loanInput = document.getElementById('loan_amount');
    var termInput = document.getElementById('term_months');
    var estField = document.getElementById('estimation');
    var summaryLoan = document.getElementById('summary_loan');
    var summaryTerm = document.getElementById('summary_term');
    var termButtons = document.querySelectorAll('.term-button');

    termButtons.forEach(function(button) {
        button.addEventListener('click', function() {
            var termValue = button.dataset.term;
            if (termInput) {
                termInput.value = termValue;
            }
            termButtons.forEach(function(btn) { btn.classList.remove('active'); });
            button.classList.add('active');
            updateEstimate();
        });
    });

    function updateEstimate() {
        if (!loanInput || !termInput || !estField) return;
        var loan = parseNumber(loanInput.value);
        var term = parseInt(termInput.value, 10) || 0;
        if (loan > 0 && term > 0) {
            var monthly = Math.round((loan / term) / 10) * 10;
            if (monthly < 1) monthly = loan / term;
            estField.value = formatRupiah(monthly);
            summaryLoan.value = formatRupiah(loan);
            summaryTerm.value = term + ' bulan';
        } else {
            estField.value = 'Rp 0';
            summaryLoan.value = 'Rp 0';
            summaryTerm.value = '-';
        }
    }

    if (loanInput) {
        loanInput.addEventListener('input', updateEstimate);
        loanInput.addEventListener('blur', function() {
            loanInput.value = formatPlainNumber(loanInput.value);
        });
    }
    if (termInput) termInput.addEventListener('change', updateEstimate);
    updateEstimate();

    document.querySelectorAll('.amount-input').forEach(function(input) {
        input.addEventListener('input', function() {
            var value = parseNumber(input.value);
            input.value = formatRupiah(value);
        });
    });
</script>
</body>
</html>
