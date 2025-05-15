<?php
// rekaman.php - Admin Melihat Data Rekaman Voting (Termasuk Foto Wajah)

require_once 'config.php'; // Memuat konfigurasi & memulai session

// Pastikan hanya admin yang bisa mengakses halaman ini
if (!is_admin()) {
    $_SESSION['flash_error_dashboard'] = "Hanya admin yang dapat mengakses halaman rekaman."; // Pesan opsional
    header("Location: dashboard.php");
    exit();
}

// Muat header HTML setelah cek akses
require 'template/header.php';

$daftar_rekaman_voting = []; // Array untuk menampung hasil query
$error_rekaman = '';     // Variabel untuk pesan error

try {
    // Query untuk mengambil data voting, join dengan tabel users (untuk nama & foto pendaftaran)
    // dan tabel kandidat (untuk nama kandidat)
    // Mengambil kolom foto_wajah_pendaftaran dari users dan foto_saat_vote dari voting
    $sql_get_rekaman = "SELECT v.id as id_vote,
                               v.waktu as waktu_vote,
                               v.foto_saat_vote,          -- Foto wajah saat voting
                               u.id as user_id_pemilih,   -- Ambil ID user untuk debug jika perlu
                               u.nama AS nama_pemilih,
                               u.username AS username_pemilih,
                               u.foto_wajah_pendaftaran, -- Foto wajah saat pendaftaran
                               k.nama AS nama_kandidat_dipilih
                        FROM voting v
                        LEFT JOIN users u ON v.user_id = u.id
                        LEFT JOIN kandidat k ON v.kandidat_id = k.id
                        ORDER BY v.id DESC"; // Urutkan berdasarkan vote terbaru

    $stmt_get_rekaman = $conn->prepare($sql_get_rekaman);

    if ($stmt_get_rekaman) {
        $stmt_get_rekaman->execute();
        $result_rekaman = $stmt_get_rekaman->get_result();

        while ($row_rekaman = $result_rekaman->fetch_assoc()) {
            $daftar_rekaman_voting[] = $row_rekaman;
        }
        $stmt_get_rekaman->close();
    } else {
        // Jika prepare statement gagal
        throw new Exception("Gagal menyiapkan query data rekaman: " . $conn->error);
    }

} catch (Exception $e) {
    // Tangani error jika query gagal
    $error_rekaman = "Terjadi kesalahan saat memuat data rekaman: " . htmlspecialchars($e->getMessage());
    error_log("Rekaman Error: " . $e->getMessage()); // Catat error lengkap di log server
    $daftar_rekaman_voting = []; // Kosongkan list rekaman
}

?>

<div class="container py-4"> <h3 class="mb-4"><i class="bi bi-person-bounding-box me-2"></i>Rekaman & Verifikasi Foto Voting</h3>
    <p class="text-muted">Halaman ini menampilkan data voting beserta foto wajah pemilih saat pendaftaran dan saat melakukan voting untuk keperluan verifikasi dan audit oleh administrator.</p>

    <?php if (!empty($error_rekaman)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?= $error_rekaman // Pesan sudah di-escape saat di-set ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-header bg-secondary text-white">
            <i class="bi bi-table me-2"></i>Tabel Rekaman Voting
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-striped table-hover align-middle caption-top">
                    <caption>Geser tabel ke kanan untuk melihat semua kolom jika perlu.</caption>
                    <thead class="table-dark text-center">
                        <tr>
                            <th scope="col">ID Vote</th>
                            <th scope="col">Pemilih</th>
                            <th scope="col">Kandidat Dipilih</th>
                            <th scope="col">Waktu Voting</th>
                            <th scope="col" style="min-width: 150px;">Foto (Registrasi)</th>
                            <th scope="col" style="min-width: 150px;">Foto (Saat Vote)</th>
                            </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($daftar_rekaman_voting) && empty($error_rekaman)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-5">
                                    <i class="bi bi-camera-video-off fs-1 d-block mb-2"></i>
                                    Belum ada data rekaman voting yang masuk.
                                </td>
                            </tr>
                        <?php elseif (!empty($daftar_rekaman_voting)): ?>
                            <?php foreach ($daftar_rekaman_voting as $rekaman): ?>
                            <tr>
                                <td class="text-center fw-bold"><?= (int)$rekaman['id_vote'] ?></td>
                                <td>
                                    <?= htmlspecialchars($rekaman['nama_pemilih'] ?? 'User Dihapus/Null', ENT_QUOTES, 'UTF-8') ?><br>
                                    <small class="text-muted">(<?= htmlspecialchars($rekaman['username_pemilih'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>)</small>
                                </td>
                                <td><?= htmlspecialchars($rekaman['nama_kandidat_dipilih'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="text-center" style="font-size: 0.9em;">
                                    <?php
                                    if (!empty($rekaman['waktu_vote'])) {
                                        try {
                                            echo (new DateTime($rekaman['waktu_vote']))->format('d/m/y H:i:s');
                                        } catch (Exception $ex) { echo htmlspecialchars($rekaman['waktu_vote']); }
                                    } else { echo '-'; }
                                    ?>
                                </td>
                                <td class="text-center align-middle"> <?php
                                    $file_reg = $rekaman['foto_wajah_pendaftaran'] ?? null;
                                    if (!empty($file_reg)) {
                                        $filename_reg = basename($file_reg);
                                        $path_fisik_reg = UPLOAD_FOTO_REGISTRASI_DIR . $filename_reg;
                                        $url_web_reg = BASE_URL . 'uploads/registrasi_foto/' . $filename_reg;
                                        if ($filename_reg === $file_reg && file_exists($path_fisik_reg)) {
                                            echo '<a href="'.htmlspecialchars($url_web_reg).'" target="_blank" title="Lihat Foto Pendaftaran">';
                                            echo '<img src="'.htmlspecialchars($url_web_reg).'" alt="Foto Reg" style="width: 100px; height: 75px; border-radius: 4px; object-fit: cover; border: 1px solid #ccc;">';
                                            echo '</a>';
                                        } else {
                                            echo '<span class="badge bg-danger"><i class="bi bi-x-octagon-fill me-1"></i>File Reg. Hilang</span>';
                                            error_log("Rekaman View: File foto registrasi hilang - " . $path_fisik_reg);
                                        }
                                    } else {
                                        echo '<span class="badge bg-secondary"><i class="bi bi-dash-circle me-1"></i>Tidak Ada</span>';
                                    }
                                    ?>
                                </td>
                                <td class="text-center align-middle"> <?php
                                    $file_vote = $rekaman['foto_saat_vote'] ?? null;
                                    if (!empty($file_vote)) {
                                        $filename_vote = basename($file_vote);
                                        $path_fisik_vote = UPLOAD_FOTO_SAAT_VOTE_DIR . $filename_vote; // Pastikan dir benar
                                        $url_web_vote = BASE_URL . 'uploads/foto_saat_vote/' . $filename_vote;
                                        if ($filename_vote === $file_vote && file_exists($path_fisik_vote)) {
                                            echo '<a href="'.htmlspecialchars($url_web_vote).'" target="_blank" title="Lihat Foto Saat Voting">';
                                            echo '<img src="'.htmlspecialchars($url_web_vote).'" alt="Foto Vote" style="width: 100px; height: 75px; border-radius: 4px; object-fit: cover; border: 1px solid #ccc;">';
                                            echo '</a>';
                                        } else {
                                            echo '<span class="badge bg-danger"><i class="bi bi-x-octagon-fill me-1"></i>File Vote Hilang</span>';
                                            error_log("Rekaman View: File foto saat vote hilang - " . $path_fisik_vote);
                                        }
                                    } else {
                                        echo '<span class="badge bg-secondary"><i class="bi bi-dash-circle me-1"></i>Tidak Ada</span>';
                                    }
                                    ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                         <?php elseif (!empty($error_rekaman)): ?>
                             <tr>
                                <td colspan="6" class="text-center text-danger py-5">
                                    <i class="bi bi-exclamation-diamond-fill fs-1 d-block mb-2"></i>
                                    Gagal memuat data rekaman voting karena terjadi kesalahan.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div> </div> </div> </div> <?php
require 'template/footer.php'; // Memuat footer HTML
?>