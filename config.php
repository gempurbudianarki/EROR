<?php
// config.php - Konfigurasi Utama Aplikasi E-Voting (Sudah Diperbaiki & Disesuaikan)

// ==================================================
// PENGATURAN KEAMANAN & ERROR HANDLING
// ==================================================

// Untuk DEVELOPMENT (Saat Anda Coding):
// error_reporting(E_ALL);
// ini_set('display_errors', 1);

// Untuk PRODUCTION (Saat Website Sudah Live): (WAJIB!)
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1); // Aktifkan logging error ke file
ini_set('error_log', __DIR__ . '/php-error.log'); // File log error di folder yang sama

// ==================================================
// PENGATURAN SESSION AMAN
// ==================================================
function secure_session_start() {
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => $cookieParams['lifetime'],
        'path' => $cookieParams['path'],
        'domain' => $cookieParams['domain'],
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on', // WAJIB HTTPS di produksi!
        'httponly' => true, // Cegah akses cookie via JavaScript
        'samesite' => 'Lax' // Perlindungan dasar CSRF
    ]);
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}
secure_session_start();

// ==================================================
// KONFIGURASI DASAR APLIKASI
// ==================================================

// BASE_URL: Otomatis mendeteksi. Pastikan sesuai dengan URL root aplikasi Anda.
// Untuk XAMPP, jika folder Anda 'EROR', maka akan jadi http://localhost/EROR/
define('BASE_URL', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://{$_SERVER['HTTP_HOST']}" . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/');

// Direktori Upload
define('UPLOAD_DIR', __DIR__ . '/uploads/'); // Direktori utama
define('UPLOAD_FOTO_KANDIDAT_DIR', UPLOAD_DIR . 'kandidat_foto/'); // Untuk foto kandidat
define('UPLOAD_FOTO_SAAT_VOTE_DIR', UPLOAD_DIR . 'foto_saat_vote/');   // Untuk foto wajah saat voting
define('UPLOAD_FOTO_REGISTRASI_DIR', UPLOAD_DIR . 'registrasi_foto/'); // Untuk foto wajah saat pendaftaran

// Buat direktori jika belum ada
$upload_dirs_to_check = [UPLOAD_DIR, UPLOAD_FOTO_KANDIDAT_DIR, UPLOAD_FOTO_SAAT_VOTE_DIR, UPLOAD_FOTO_REGISTRASI_DIR];
foreach ($upload_dirs_to_check as $dir_path) {
    if (!is_dir($dir_path)) {
        if (!@mkdir($dir_path, 0755, true)) { // 0755 adalah permission yang umum
            $error_message_dir = "GAGAL MEMBUAT DIREKTORI UPLOAD: " . $dir_path . ". Periksa permission folder atau buat manual.";
            error_log($error_message_dir);
            // Untuk setup awal, mungkin lebih baik die() jika direktori penting gagal dibuat.
            // die($error_message_dir);
        }
    }
}

// ==================================================
// KONEKSI DATABASE
// ==================================================
// **PENTING!** Ganti dengan kredensial database Anda yang BENAR.
// Ini adalah penyebab error "Access denied" di log Anda jika salah.
define('DB_HOST', 'localhost');
define('DB_USER', 'user_voting_app'); // Pastikan user ini ADA di MySQL dan punya HAK AKSES ke db_voting
define('DB_PASS', 'Vot1n9!App_S3cur3'); // Ganti dengan password yang BENAR untuk user_voting_app
define('DB_NAME', 'db_voting');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    error_log("Koneksi database gagal: (" . $conn->connect_errno . ") " . $conn->connect_error);
    die("Tidak dapat terhubung ke sistem. Silakan coba beberapa saat lagi atau hubungi administrator.");
}
if (!$conn->set_charset("utf8mb4")) {
    error_log("Error saat mengatur character set utf8mb4: " . $conn->error);
}

// ==================================================
// FUNGSI HELPER KEAMANAN (CSRF)
// ==================================================
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        try {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            $_SESSION['csrf_token'] = md5(uniqid((string) rand(), true));
            error_log("random_bytes gagal untuk CSRF token: " . $e->getMessage());
        }
    }
    return $_SESSION['csrf_token'];
}

function validate_csrf_token() {
    if (!isset($_POST['csrf_token']) || empty($_POST['csrf_token']) || empty($_SESSION['csrf_token'])) {
        error_log("Validasi CSRF gagal: Token POST atau token session tidak ada.");
        return false;
    }
    if (hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        return true;
    } else {
        error_log("Validasi CSRF gagal: Token tidak cocok.");
        return false;
    }
}

// ==================================================
// FUNGSI HELPER APLIKASI LAINNYA
// ==================================================
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

function is_login() {
    return isset($_SESSION['user']) && is_array($_SESSION['user']) && !empty($_SESSION['user']['id']);
}

function is_admin() {
    return is_login() && isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'admin';
}

function validate_password_strength($password) {
    $errors = [];
    if (strlen($password) < 8) {
        $errors[] = "Password minimal 8 karakter.";
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password harus mengandung setidaknya satu huruf besar.";
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password harus mengandung setidaknya satu huruf kecil.";
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password harus mengandung setidaknya satu angka.";
    }
    if (!preg_match('/[\W_]/', $password)) { // \W adalah karakter non-word (simbol), _ juga dianggap simbol
        $errors[] = "Password harus mengandung setidaknya satu simbol.";
    }
    return $errors;
}

function get_setting($key, $conn_param) {
    if (!$conn_param) return null;
    $setting_db_value = null; // Variabel untuk menyimpan hasil dari DB
    $stmt = $conn_param->prepare("SELECT value FROM settings WHERE `key` = ?");
    if ($stmt) {
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $stmt->bind_result($setting_db_value); // PERBAIKAN: Bind ke variabel yang benar
        if (!$stmt->fetch()) { // Jika fetch tidak menghasilkan apa-apa (tidak ada baris atau error)
            $setting_db_value = null; // Pastikan nilainya null
        }
        $stmt->close();
    } else {
        error_log("Gagal prepare get_setting untuk key '$key': " . $conn_param->error);
    }
    return $setting_db_value; // Return nilai yang di-bind (atau null jika gagal/tidak ada)
}

function set_setting($key, $value, $conn_param) {
    if (!$conn_param) return false;
    $stmt = $conn_param->prepare("INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
    if ($stmt) {
        $stmt->bind_param("ss", $key, $value);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    } else {
        error_log("Gagal prepare set_setting untuk key '$key': " . $conn_param->error);
        return false;
    }
}

// ==================================================
// INISIALISASI STRUKTUR DATABASE
// ==================================================
// Mengubah nama fungsi agar tidak ada potensi redeclaration jika ada fungsi dengan nama sama di scope global
function _create_table_if_not_exists_cfg($conn_param, $sql_query, $table_name_cfg) {
    if (!$conn_param->query($sql_query)) {
        error_log("Gagal membuat/memeriksa tabel '{$table_name_cfg}': " . $conn_param->error);
    }
}

// Tabel 'users'
$sql_users_table_cfg = "CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    nama VARCHAR(100) NOT NULL,
    role ENUM('admin','pemilih') DEFAULT 'pemilih',
    sudah_vote BOOLEAN DEFAULT 0,
    foto_wajah_pendaftaran VARCHAR(255) DEFAULT NULL, /* NAMA FILE FOTO WAJAH PENDAFTARAN */
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
_create_table_if_not_exists_cfg($conn, $sql_users_table_cfg, 'users');

// Tabel 'kandidat'
$sql_kandidat_table_cfg = "CREATE TABLE IF NOT EXISTS kandidat (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama VARCHAR(100) NOT NULL,
    jurusan VARCHAR(100) NOT NULL,
    foto VARCHAR(255) DEFAULT NULL, /* Nama file foto kandidat, path ditentukan dari UPLOAD_FOTO_KANDIDAT_DIR */
    visi_misi TEXT,
    jumlah_suara INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
_create_table_if_not_exists_cfg($conn, $sql_kandidat_table_cfg, 'kandidat');

// Tabel 'voting'
$sql_voting_table_cfg = "CREATE TABLE IF NOT EXISTS voting (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    kandidat_id INT NOT NULL,
    waktu TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    foto_saat_vote VARCHAR(255) DEFAULT NULL, /* NAMA FILE FOTO WAJAH SAAT VOTING */
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (kandidat_id) REFERENCES kandidat(id) ON DELETE RESTRICT, 
    UNIQUE KEY unique_vote_per_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
_create_table_if_not_exists_cfg($conn, $sql_voting_table_cfg, 'voting');

// Tabel 'settings'
$sql_settings_table_cfg = "CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(64) NOT NULL UNIQUE,
    value TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
_create_table_if_not_exists_cfg($conn, $sql_settings_table_cfg, 'settings');

// Tambahkan user admin default (jika belum ada)
$default_admin_username = 'adminubbg';
$default_admin_password_plain = '@adminubbgvoting2025'; // GANTI INI DENGAN PASSWORD YANG SANGAT KUAT!
$default_admin_nama = 'Administrator Utama Sistem';

$stmt_check_admin = $conn->prepare("SELECT id FROM users WHERE username = ? AND role = 'admin'");
if ($stmt_check_admin) {
    $stmt_check_admin->bind_param("s", $default_admin_username);
    $stmt_check_admin->execute();
    $stmt_check_admin->store_result();
    if ($stmt_check_admin->num_rows === 0) {
        $default_admin_password_hash = password_hash($default_admin_password_plain, PASSWORD_DEFAULT);
        $stmt_insert_admin = $conn->prepare("INSERT INTO users (username, password, nama, role) VALUES (?, ?, ?, 'admin')");
        if ($stmt_insert_admin) {
            $stmt_insert_admin->bind_param("sss", $default_admin_username, $default_admin_password_hash, $default_admin_nama);
            if (!$stmt_insert_admin->execute()) {
                error_log("Gagal membuat user admin default: " . $stmt_insert_admin->error);
            }
            $stmt_insert_admin->close();
        } else {
             error_log("Gagal prepare statement untuk insert admin default: " . $conn->error);
        }
    }
    $stmt_check_admin->close();
} else {
    error_log("Gagal prepare statement untuk cek admin default: " . $conn->error);
}

// Inisialisasi setting waktu default jika belum ada
if (get_setting('evoting_start', $conn) === null) {
    set_setting('evoting_start', '', $conn);
}
if (get_setting('evoting_end', $conn) === null) {
    set_setting('evoting_end', '', $conn);
}

?>