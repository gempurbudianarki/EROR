<?php
// dashboard.php - Halaman Utama Setelah Login (Desain Baru - Biru Cerah)

require_once 'config.php'; // Memuat konfigurasi & memulai session aman
date_default_timezone_set('Asia/Jakarta'); // Set timezone

// --- Inisialisasi Variabel ---
$error_dashboard = '';
$success_dashboard = '';
$evoting_start_datetime_obj = null;
$evoting_end_datetime_obj = null;
$evoting_start_setting_str = '';
$evoting_end_setting_str = '';
$stat_total_pemilih = 0;
$stat_sudah_voting = 0;
$stat_total_kandidat = 0;

// --- Cek Koneksi & Inisialisasi Settings ---
if ($conn) {
    try {
        // ... (Logika cek/inisialisasi settings sama seperti sebelumnya) ...
        $check_settings_table_exists = $conn->query("SHOW TABLES LIKE 'settings'");
        if ($check_settings_table_exists && $check_settings_table_exists->num_rows > 0) {
             if (get_setting('evoting_start', $conn) === null) { set_setting('evoting_start', '', $conn); }
             if (get_setting('evoting_end', $conn) === null) { set_setting('evoting_end', '', $conn); }
        } else { error_log("Dashboard: Tabel 'settings' tidak ditemukan."); }
    } catch (Exception $e) { error_log("Dashboard: Error saat cek/inisialisasi settings - " . $e->getMessage()); }
} else { $error_dashboard = "Koneksi database tidak tersedia."; /* Config should handle die() */ }

// --- Identifikasi User & Role ---
$is_currently_admin = is_admin();
$is_currently_pemilih = (is_login() && isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'pemilih');

// --- Ambil Data User Login & Status Vote ---
$nama_user_login = '';
$user_id_login = 0;
$status_sudah_vote_user = 0;
if (is_login() && isset($_SESSION['user'])) {
    // ... (Logika ambil data user & cek ulang status vote sama seperti sebelumnya) ...
    $user_id_login = $_SESSION['user']['id'] ?? 0;
    $nama_user_login = $_SESSION['user']['nama'] ?? 'Pengguna';
    $status_sudah_vote_user = isset($_SESSION['user']['sudah_vote']) ? (int)$_SESSION['user']['sudah_vote'] : 0;
    if ($is_currently_pemilih && !$status_sudah_vote_user && $conn && $user_id_login > 0) {
        try {
            $stmt_cek_vote_db_pemilih = $conn->prepare("SELECT 1 FROM voting WHERE user_id = ? LIMIT 1");
            if ($stmt_cek_vote_db_pemilih) {
                 $stmt_cek_vote_db_pemilih->bind_param("i", $user_id_login); $stmt_cek_vote_db_pemilih->execute(); $stmt_cek_vote_db_pemilih->store_result();
                 if ($stmt_cek_vote_db_pemilih->num_rows > 0) { $status_sudah_vote_user = 1; $_SESSION['user']['sudah_vote'] = 1; }
                 $stmt_cek_vote_db_pemilih->close();
            } else { error_log("Dashboard: Gagal prepare cek status vote pemilih DB - " . $conn->error); }
        } catch (Exception $e) { error_log("Dashboard: Exception saat cek status vote pemilih DB - " . $e->getMessage()); }
    }
} else { /* ... (Handle jika tidak login) ... */ }

// --- Logika Khusus Admin (Pengaturan Waktu & Statistik) ---
if ($is_currently_admin && $conn) {
    // ... (Logika proses form pengaturan waktu SAMA seperti sebelumnya) ...
     if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_set_waktu_voting'])) {
        if (!validate_csrf_token()) { $error_dashboard = 'Error: Permintaan tidak valid.'; }
        elseif (isset($_POST['evoting_start_time'], $_POST['evoting_end_time'])) {
            // ... (Validasi input waktu) ...
            $start_time_input_admin = $_POST['evoting_start_time']; $end_time_input_admin = $_POST['evoting_end_time'];
            $datetime_pattern_admin = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/';
             if (!preg_match($datetime_pattern_admin, $start_time_input_admin) || !preg_match($datetime_pattern_admin, $end_time_input_admin)) {
                 $error_dashboard = 'Format waktu tidak valid.';
             } else {
                 $start_timestamp_admin = strtotime($start_time_input_admin); $end_timestamp_admin = strtotime($end_time_input_admin);
                 if ($start_timestamp_admin === false || $end_timestamp_admin === false) { $error_dashboard = 'Format waktu tidak dapat diproses.'; }
                 elseif ($end_timestamp_admin <= $start_timestamp_admin) { $error_dashboard = 'Waktu berakhir harus setelah waktu mulai.'; }
                 else {
                    $set_start_result = set_setting('evoting_start', $start_time_input_admin, $conn);
                    $set_end_result = set_setting('evoting_end', $end_time_input_admin, $conn);
                    if ($set_start_result && $set_end_result) {
                        $success_dashboard = 'Jadwal e-voting berhasil diperbarui.';
                        $evoting_start_setting_str = $start_time_input_admin; $evoting_end_setting_str = $end_time_input_admin;
                    } else { $error_dashboard = 'Gagal menyimpan pengaturan jadwal.'; error_log("Dashboard Admin: Gagal set_setting waktu."); }
                 }
             }
        } else { $error_dashboard = 'Data waktu tidak lengkap.'; }
    }

    // ... (Logika ambil data statistik SAMA seperti sebelumnya) ...
    try {
        $role_pemilih_stat = 'pemilih';
        $stmt_total_pemilih = $conn->prepare("SELECT COUNT(*) FROM users WHERE role = ?");
        if($stmt_total_pemilih){ $stmt_total_pemilih->bind_param("s", $role_pemilih_stat); $stmt_total_pemilih->execute(); $stmt_total_pemilih->bind_result($stat_total_pemilih); $stmt_total_pemilih->fetch(); $stmt_total_pemilih->close(); } else { throw new Exception("Prep fail tot pemilih: " . $conn->error); }
        $stmt_sudah_voting = $conn->prepare("SELECT COUNT(*) FROM users WHERE role = ? AND sudah_vote = 1");
        if($stmt_sudah_voting){ $stmt_sudah_voting->bind_param("s", $role_pemilih_stat); $stmt_sudah_voting->execute(); $stmt_sudah_voting->bind_result($stat_sudah_voting); $stmt_sudah_voting->fetch(); $stmt_sudah_voting->close(); } else { throw new Exception("Prep fail sdh vote: " . $conn->error); }
        $stmt_total_kandidat = $conn->prepare("SELECT COUNT(*) FROM kandidat");
        if($stmt_total_kandidat){ $stmt_total_kandidat->execute(); $stmt_total_kandidat->bind_result($stat_total_kandidat); $stmt_total_kandidat->fetch(); $stmt_total_kandidat->close(); } else { throw new Exception("Prep fail tot kandidat: " . $conn->error); }
    } catch (Exception $e) { $error_dashboard = "Gagal muat statistik: " . $e->getMessage(); error_log("Dash Admin Stat Err: " . $error_dashboard); $stat_total_pemilih = $stat_sudah_voting = $stat_total_kandidat = 0; }
}

// --- Ambil & Proses Jadwal Voting (SAMA seperti sebelumnya) ---
if ($conn && empty($error_dashboard)) {
    if (empty($evoting_start_setting_str)) { $evoting_start_setting_str = get_setting('evoting_start', $conn) ?? ''; }
    if (empty($evoting_end_setting_str)) { $evoting_end_setting_str = get_setting('evoting_end', $conn) ?? ''; }
    $timezone_obj = new DateTimeZone('Asia/Jakarta');
    if (!empty($evoting_start_setting_str)) { try { $evoting_start_datetime_obj = new DateTime($evoting_start_setting_str, $timezone_obj); } catch (Exception $e) { /* log error */ } }
    if (!empty($evoting_end_setting_str)) { try { $evoting_end_datetime_obj = new DateTime($evoting_end_setting_str, $timezone_obj); } catch (Exception $e) { /* log error */ } }
}

// --- Muat Template Header ---
// Header kemungkinan masih pakai style lama (biru tua). Nanti bisa disesuaikan jika perlu.
require 'template/header.php';
?>

<style>
    :root {
        --primary-blue: #0D6EFD; /* Bootstrap primary blue */
        --primary-blue-dark: #0A58CA;
        --primary-blue-light: #3DA5F4; /* Biru cerah sekunder */
        --text-dark: #212529;
        --text-muted: #6C757D;
        --text-light: #f8f9fa;
        --background-body: #EBF4FF; /* Biru sangat pucat / abu kebiruan */
        --background-pattern: rgba(0, 98, 255, 0.05); /* Warna pattern biru transparan */
        --card-bg: #FFFFFF;
        --card-border: #DEE2E6;
        --card-shadow: 0 4px 15px rgba(0, 98, 255, 0.08);
        --success-color: #198754;
        --warning-color: #FFC107;
        --info-color: #0DCAF0;
        --danger-color: #DC3545;
        --border-radius: 0.75rem; /* 12px */
    }

    /* Background Body dengan Pola Kotak Kecil */
    body {
        background-color: var(--background-body);
        background-image:
            linear-gradient(var(--background-pattern) 1px, transparent 1px),
            linear-gradient(90deg, var(--background-pattern) 1px, transparent 1px);
        background-size: 20px 20px; /* Ukuran kotak kecil */
        color: var(--text-dark); /* Default teks gelap */
        font-family: 'Poppins', sans-serif; /* Pastikan font Poppins diterapkan jika belum global */
    }

    /* Override container padding default dari header jika perlu */
    .container.py-4 { /* Target container dari header */
        padding-top: 2rem !important;
        padding-bottom: 3rem !important;
        max-width: 1140px; /* Lebar maksimum konten */
    }

    /* Card Styling Umum */
    .card {
        border: 1px solid var(--card-border);
        border-radius: var(--border-radius);
        box-shadow: var(--card-shadow);
        margin-bottom: 1.8rem; /* Jarak antar card */
        overflow: hidden; /* Agar border-radius rapi */
    }
    .card-header {
        background-color: #F8F9FA; /* Header card abu-abu muda */
        border-bottom: 1px solid var(--card-border);
        font-weight: 600;
        color: var(--text-dark);
        padding: 1rem 1.25rem;
    }
    .card-header .bi {
        margin-right: 0.5rem;
        color: var(--primary-blue); /* Ikon di header berwarna biru */
    }
    .card-body {
        padding: 1.5rem;
    }

    /* Welcome Card */
    .dashboard-welcome {
        background: linear-gradient(135deg, var(--primary-blue) 0%, var(--primary-blue-light) 100%);
        color: #fff;
        padding: 2rem 1.5rem;
        border-radius: var(--border-radius);
        text-align: center;
        margin-bottom: 2.5rem;
    }
    .dashboard-welcome h2 { font-weight: 700; margin-bottom: 0.5rem; }
    .dashboard-welcome p { font-size: 1.1rem; opacity: 0.9; }
    .dashboard-welcome .bi { font-size: 1.1em; vertical-align: baseline; }


    /* Statistik Admin Card */
    .stats-card-light {
        background-color: var(--card-bg);
        border-radius: var(--border-radius);
        padding: 1.25rem;
        border: 1px solid var(--card-border);
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        text-align: center;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        display: flex;
        align-items: center; /* Icon dan teks sejajar */
        gap: 1rem;
    }
    .stats-card-light:hover {
        transform: translateY(-4px);
        box-shadow: 0 6px 15px rgba(0,0,0,0.1);
    }
    .stats-card-light .stat-icon-wrapper {
        flex-shrink: 0;
        background-color: rgba(13, 110, 253, 0.1); /* Latar ikon biru muda transparan */
        color: var(--primary-blue);
        border-radius: 50%;
        width: 60px;
        height: 60px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .stats-card-light .stat-icon-wrapper .bi {
        font-size: 1.8rem; /* Ukuran ikon */
    }
    .stats-card-light .stat-info {
        text-align: left;
    }
    .stats-card-light .stat-title {
        font-size: 0.9rem;
        color: var(--text-muted);
        margin-bottom: 0.1rem;
    }
    .stats-card-light .stat-value {
        font-size: 1.6rem;
        font-weight: 700;
        color: var(--text-dark);
    }
     /* Warna ikon spesifik jika perlu */
    .stats-card-light.stat-success .stat-icon-wrapper { background-color: rgba(25, 135, 84, 0.1); color: var(--success-color); }
    .stats-card-light.stat-info .stat-icon-wrapper { background-color: rgba(13, 202, 240, 0.1); color: var(--info-color); }


    /* Panel Jadwal Voting Admin */
    .jadwal-voting-panel .form-label {
        font-size: 0.85rem;
        font-weight: 500;
        color: var(--text-muted);
        margin-bottom: 0.3rem;
    }
    .jadwal-voting-panel .form-control {
        font-size: 0.9rem;
        border-radius: 0.5rem;
    }
    .jadwal-voting-panel input[type="datetime-local"] {
        color-scheme: light; /* Pastikan kalender sesuai tema terang */
    }
    .jadwal-voting-panel .btn-primary {
        font-size: 0.9rem;
        padding: 0.6rem 1rem;
    }
    .jadwal-info-display strong { color: var(--primary-blue); }
    .jadwal-info-display .badge { font-size: 0.85em; padding: 0.4em 0.7em;}


    /* Kartu Info Pemilih */
    .pemilih-info-card-light {
        background-color: var(--card-bg);
        border: 1px solid var(--card-border);
        box-shadow: var(--card-shadow);
        border-radius: var(--border-radius);
        padding: 2rem;
        text-align: center;
    }
    .pemilih-info-card-light .icon-status {
        font-size: 3.5rem; /* Icon lebih besar */
        margin-bottom: 1rem;
        display: inline-block; /* Agar bisa di tengah */
    }
    .pemilih-info-card-light .icon-status.sudah-vote { color: var(--success-color); }
    .pemilih-info-card-light .icon-status.belum-vote { color: var(--warning-color); }
    .pemilih-info-card-light .icon-status.info-jadwal { color: var(--info-color); }
    .pemilih-info-card-light h3 {
        font-weight: 600;
        color: var(--text-dark);
        margin-bottom: 0.75rem;
    }
    .pemilih-info-card-light p {
        color: var(--text-muted);
        font-size: 1.05rem; /* Sedikit lebih besar */
        margin-bottom: 1.5rem;
    }
    .pemilih-info-card-light .btn { /* Styling tombol aksi pemilih */
        font-size: 1.1rem;
        font-weight: 500;
        padding: 0.75rem 1.8rem;
        border-radius: 50px; /* Tombol bulat */
    }
    .pemilih-info-card-light .btn-success { background-color: var(--success-color); border-color: var(--success-color); }
    .pemilih-info-card-light .btn-info { background-color: var(--info-color); border-color: var(--info-color); color: #fff; }


    /* Alert Messages (jika ada) */
    .alert { border-radius: var(--border-radius); font-size: 0.9rem;}

    /* Responsiveness */
    @media (max-width: 768px) {
        .stats-card-light { flex-direction: column; text-align: center; gap: 0.5rem; }
        .stats-card-light .stat-info { text-align: center; }
    }

</style>

<div class="container py-4"> <?php include 'template/global_alert.php'; // Asumsi ada file terpisah atau tampilkan langsung ?>
    <?php if (!empty($error_dashboard)): ?> <div class="alert alert-danger"><?= htmlspecialchars($error_dashboard) ?></div> <?php endif; ?>
    <?php if (!empty($success_dashboard)): ?> <div class="alert alert-success"><?= htmlspecialchars($success_dashboard) ?></div> <?php endif; ?>
    <?php if (isset($_SESSION['flash_info_dashboard'])): ?> <div class="alert alert-info"><?= htmlspecialchars($_SESSION['flash_info_dashboard']) ?></div> <?php unset($_SESSION['flash_info_dashboard']); endif; ?>
     <?php if (isset($_SESSION['flash_error_dashboard'])): ?> <div class="alert alert-warning"><?= htmlspecialchars($_SESSION['flash_error_dashboard']) ?></div> <?php unset($_SESSION['flash_error_dashboard']); endif; ?>
     <?php if (isset($_SESSION['flash_success_dashboard'])): ?> <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash_success_dashboard']) ?></div> <?php unset($_SESSION['flash_success_dashboard']); endif; ?>

    <?php if ($is_currently_admin): ?>
        <section class="dashboard-welcome mb-4">
            <h2><i class="bi bi-person-gear"></i> Dashboard Administrator</h2>
            <p>Selamat datang, <?= htmlspecialchars($nama_user_login) ?>. Kelola sistem e-voting dari sini.</p>
        </section>

        <section class="row g-4 mb-4"> <div class="col-lg-4 col-md-6">
                 <div class="stats-card-light">
                    <div class="stat-icon-wrapper"><i class="bi bi-people-fill"></i></div>
                    <div class="stat-info">
                        <div class="stat-title">Total Pemilih</div>
                        <div class="stat-value"><?= (int)$stat_total_pemilih ?></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4 col-md-6">
                 <div class="stats-card-light stat-success"> <div class="stat-icon-wrapper"><i class="bi bi-person-check-fill"></i></div>
                     <div class="stat-info">
                        <div class="stat-title">Sudah Memilih</div>
                        <div class="stat-value"><?= (int)$stat_sudah_voting ?></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4 col-md-12"> <div class="stats-card-light stat-info"> <div class="stat-icon-wrapper"><i class="bi bi-person-vcard-fill"></i></div>
                     <div class="stat-info">
                        <div class="stat-title">Total Kandidat</div>
                        <div class="stat-value"><?= (int)$stat_total_kandidat ?></div>
                    </div>
                </div>
            </div>
        </section>

        <section class="jadwal-voting-panel card" id="jadwal-voting-panel"> <div class="card-header">
                <i class="bi bi-calendar2-week-fill"></i>Pengaturan Jadwal E-Voting
            </div>
            <div class="card-body">
                <form method="POST" action="dashboard.php#jadwal-voting-panel">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()); ?>">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-5 mb-2 mb-md-0">
                            <label for="evotingStartTimeInput" class="form-label">Waktu Mulai</label>
                            <input type="datetime-local" class="form-control form-control-sm" id="evotingStartTimeInput" name="evoting_start_time" value="<?= htmlspecialchars($evoting_start_setting_str) ?>" required>
                        </div>
                        <div class="col-md-5 mb-2 mb-md-0">
                            <label for="evotingEndTimeInput" class="form-label">Waktu Berakhir</label>
                            <input type="datetime-local" class="form-control form-control-sm" id="evotingEndTimeInput" name="evoting_end_time" value="<?= htmlspecialchars($evoting_end_setting_str) ?>" required>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" name="action_set_waktu_voting" class="btn btn-primary w-100 btn-sm">
                                <i class="bi bi-save"></i> Simpan
                            </button>
                        </div>
                    </div>
                </form>
                <hr class="my-3">
                <div class="jadwal-info-display">
                    <h6 class="mb-2">Jadwal Aktif Saat Ini:</h6>
                    <?php
                        $display_start_time = '<em class="text-muted">Belum diatur</em>';
                        $display_end_time = '<em class="text-muted">Belum diatur</em>';
                        $status_voting_text = '<span class="badge bg-secondary"><i class="bi bi-gear-wide"></i> Belum Diatur</span>';
                        $now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
                        if ($evoting_start_datetime_obj) $display_start_time = '<strong>' . $evoting_start_datetime_obj->format('d M Y, H:i') . '</strong>';
                        if ($evoting_end_datetime_obj) $display_end_time = '<strong>' . $evoting_end_datetime_obj->format('d M Y, H:i') . '</strong>';
                        if ($evoting_start_datetime_obj && $evoting_end_datetime_obj) {
                            if ($now < $evoting_start_datetime_obj) $status_voting_text = '<span class="badge bg-warning text-dark"><i class="bi bi-alarm-fill"></i> Segera</span>';
                            elseif ($now >= $evoting_start_datetime_obj && $now <= $evoting_end_datetime_obj) $status_voting_text = '<span class="badge bg-success"><i class="bi bi-play-circle-fill"></i> Aktif</span>';
                            else $status_voting_text = '<span class="badge bg-danger"><i class="bi bi-stop-circle-fill"></i> Selesai</span>';
                        }
                    ?>
                    <p class="mb-1 small"><i class="bi bi-box-arrow-in-right text-success"></i> Mulai: <?= $display_start_time ?></p>
                    <p class="mb-1 small"><i class="bi bi-box-arrow-left text-danger"></i> Selesai: <?= $display_end_time ?></p>
                    <p class="mb-0 small"><i class="bi bi-activity text-primary"></i> Status: <?= $status_voting_text ?></p>
                </div>
            </div>
        </section>

    <?php elseif ($is_currently_pemilih): ?>
        <section class="dashboard-welcome mb-4">
             <h2><i class="bi bi-person-check"></i> Halo, <?= htmlspecialchars($nama_user_login) ?>!</h2>
            <p class="lead">Selamat datang di portal E-Voting Universitas Bina Bangsa Getsempena.</p>
        </section>

        <section class="pemilih-info-card-light"> <?php if ($status_sudah_vote_user): ?>
                <div class="icon-status sudah-vote"><i class="bi bi-check2-circle"></i></div>
                <h3><i class="bi bi-hand-thumbs-up"></i> Terima Kasih!</h3>
                <p>Anda telah berhasil menggunakan hak suara Anda pada pemilihan ini.</p>
                <a href="hasil.php" class="btn btn-outline-info">
                    <i class="bi bi-bar-chart-line"></i> Lihat Hasil Sementara
                </a>
            <?php else:
                $pesan_status_vote = ''; $tombol_aksi_vote = ''; $icon_vote_status = 'info-jadwal'; $judul_vote_status = 'Informasi Jadwal Voting';

                if (!$evoting_start_datetime_obj || !$evoting_end_datetime_obj || empty($evoting_start_setting_str) || empty($evoting_end_setting_str)) {
                    $pesan_status_vote = "Jadwal pemilihan belum diatur oleh panitia. Mohon tunggu informasi selanjutnya.";
                    $icon_vote_status = 'info-jadwal';
                } else {
                    $now_vote = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
                    if ($now_vote < $evoting_start_datetime_obj) {
                        $pesan_status_vote = "Pemilihan belum dimulai. Voting akan dibuka pada: <br><b>" . $evoting_start_datetime_obj->format('d F Y, \p\u\k\u\l H:i') . " WIB</b>";
                        $icon_vote_status = 'info-jadwal';
                        $judul_vote_status = 'Pemilihan Segera Dimulai';
                    } elseif ($now_vote >= $evoting_start_datetime_obj && $now_vote <= $evoting_end_datetime_obj) {
                        $pesan_status_vote = "Periode pemilihan sedang berlangsung! Gunakan hak suara Anda sebelum:<br><b>" . $evoting_end_datetime_obj->format('d F Y, \p\u\k\u\l H:i') . " WIB</b>";
                        $tombol_aksi_vote = '<a href="voting.php" class="btn btn-success btn-lg mt-3 shadow-sm"><i class="bi bi-pencil-square"></i> Mulai Voting Sekarang</a>';
                        $icon_vote_status = 'belum-vote';
                        $judul_vote_status = 'Saatnya Memilih!';
                    } else {
                        $pesan_status_vote = "Periode pemilihan telah berakhir pada:<br><b>" . $evoting_end_datetime_obj->format('d F Y, \p\u\k\u\l H:i') . " WIB</b>";
                        $tombol_aksi_vote = '<a href="hasil.php" class="btn btn-info btn-lg mt-3 shadow-sm"><i class="bi bi-bar-chart-line-fill"></i> Lihat Hasil Pemilihan</a>';
                        $icon_vote_status = 'info-jadwal';
                         $judul_vote_status = 'Pemilihan Telah Selesai';
                    }
                }
            ?>
                <div class="icon-status <?= $icon_vote_status ?>">
                    <?php if($icon_vote_status === 'belum-vote'): ?><i class="bi bi-pencil-fill"></i>
                    <?php elseif($icon_vote_status === 'info-jadwal'): ?><i class="bi bi-calendar-x-fill"></i>
                    <?php else: ?><i class="bi bi-info-circle-fill"></i><?php endif; ?>
                </div>
                <h3><?= $judul_vote_status ?></h3>
                <p class="fs-5"><?= $pesan_status_vote // Jangan escape HTML karena kita pakai <br> dan <b> ?></p>
                <?= $tombol_aksi_vote ?>
            <?php endif; ?>
        </section>

    <?php elseif (is_login()): ?>
        <div class="alert alert-warning">Peran pengguna Anda tidak dikenali. Hubungi administrator.</div>
    <?php endif; ?>

</div> <?php
require 'template/footer.php';
?>