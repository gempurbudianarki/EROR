<?php
// pemilih.php - Admin Mengelola Data Pemilih yang Mendaftar Mandiri

require_once 'config.php'; // Memuat konfigurasi dan memulai session

// Pastikan hanya admin yang bisa mengakses halaman ini
if (!is_admin()) {
    header("Location: dashboard.php");
    exit();
}

$error_pemilih = '';    // Variabel untuk pesan error di halaman ini
$success_pemilih = ''; // Variabel untuk pesan sukses di halaman ini

// Gunakan PRG Pattern dengan Flash Messages dari Session untuk operasi POST (hapus)
if (isset($_SESSION['flash_success_pemilih'])) {
    $success_pemilih = $_SESSION['flash_success_pemilih'];
    unset($_SESSION['flash_success_pemilih']);
}
if (isset($_SESSION['flash_error_pemilih'])) {
    $error_pemilih = $_SESSION['flash_error_pemilih'];
    unset($_SESSION['flash_error_pemilih']);
}


// --- Proses Hapus Pemilih (Tetap ada, dan sekarang juga hapus foto wajah pendaftaran) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus_pemilih_action'])) {

    // 1. Validasi CSRF Token
    if (!validate_csrf_token()) {
        $_SESSION['flash_error_pemilih'] = 'Error: Permintaan hapus tidak valid atau sesi telah berakhir. Coba lagi.';
        header("Location: pemilih.php");
        exit();
    }

    // 2. Validasi ID Pemilih dari POST
    $id_pemilih_to_delete = filter_input(INPUT_POST, 'pemilih_id_to_delete', FILTER_VALIDATE_INT);

    if ($id_pemilih_to_delete && $id_pemilih_to_delete > 0) {
        $username_pemilih_deleted = '';
        $file_foto_wajah_to_delete = null;
        $role_target = 'pemilih'; // Hanya boleh hapus pemilih

        // Ambil username dan nama file foto wajah sebelum hapus data dari DB
        $stmt_get_data_pemilih = $conn->prepare("SELECT username, foto_wajah_pendaftaran FROM users WHERE id = ? AND role = ?");
        if ($stmt_get_data_pemilih) {
            $stmt_get_data_pemilih->bind_param("is", $id_pemilih_to_delete, $role_target);
            $stmt_get_data_pemilih->execute();
            $stmt_get_data_pemilih->bind_result($username_pemilih_deleted, $file_foto_wajah_to_delete);
            $stmt_get_data_pemilih->fetch();
            $stmt_get_data_pemilih->close();
        } else {
             error_log("Pemilih Hapus: Gagal prepare statement get data pemilih - " . $conn->error);
             $_SESSION['flash_error_pemilih'] = "Terjadi kesalahan sistem saat verifikasi data pemilih untuk dihapus.";
             header("Location: pemilih.php");
             exit();
        }

        if (empty($username_pemilih_deleted)) {
             $_SESSION['flash_error_pemilih'] = "Pemilih dengan ID ($id_pemilih_to_delete) tidak ditemukan atau bukan pemilih yang valid.";
        } else {
            // Lanjutkan hapus data dari DB (Prepared Statement)
            $stmt_hapus_db = $conn->prepare("DELETE FROM users WHERE id = ? AND role = ?");
            if ($stmt_hapus_db) {
                $stmt_hapus_db->bind_param("is", $id_pemilih_to_delete, $role_target);

                if ($stmt_hapus_db->execute()) {
                    if ($stmt_hapus_db->affected_rows > 0) {
                        $_SESSION['flash_success_pemilih'] = "Pemilih '{$username_pemilih_deleted}' berhasil dihapus dari sistem.";
                        
                        // Hapus file foto wajah pendaftaran terkait jika ada dan valid
                        if (!empty($file_foto_wajah_to_delete)) {
                            // Pastikan nama file aman sebelum menghapus
                            $safe_filename_foto_wajah = basename($file_foto_wajah_to_delete);
                            if ($safe_filename_foto_wajah === $file_foto_wajah_to_delete) { // Cek sederhana path traversal
                                $path_foto_wajah_fisik = UPLOAD_FOTO_REGISTRASI_DIR . $safe_filename_foto_wajah;
                                if (file_exists($path_foto_wajah_fisik)) {
                                    if (!@unlink($path_foto_wajah_fisik)) {
                                        error_log("Pemilih Hapus: Gagal menghapus file foto wajah '{$path_foto_wajah_fisik}'. Periksa permission.");
                                        // Pesan error tambahan bisa ditambahkan ke session jika perlu, tapi utamakan pesan sukses hapus user
                                    }
                                }
                            } else {
                                error_log("Pemilih Hapus: Nama file foto wajah '{$file_foto_wajah_to_delete}' berpotensi tidak aman.");
                            }
                        }
                    } else {
                        // Seharusnya tidak terjadi jika username ditemukan, tapi sebagai fallback
                        $_SESSION['flash_error_pemilih'] = "Gagal menghapus pemilih '{$username_pemilih_deleted}' (kemungkinan sudah dihapus sebelumnya).";
                    }
                } else {
                    $_SESSION['flash_error_pemilih'] = "Gagal menghapus data pemilih '{$username_pemilih_deleted}' dari database.";
                    error_log("Pemilih Hapus: MySQLi delete pemilih execute failed - " . $stmt_hapus_db->error);
                }
                $stmt_hapus_db->close();
            } else {
                $_SESSION['flash_error_pemilih'] = "Terjadi kesalahan sistem (prepare delete pemilih).";
                error_log("Pemilih Hapus: MySQLi delete pemilih prepare failed - " . $conn->error);
            }
        }
    } else {
        $_SESSION['flash_error_pemilih'] = "ID Pemilih yang akan dihapus tidak valid.";
    }
    // Redirect setelah proses hapus (baik sukses maupun gagal) untuk mengikuti PRG pattern
    header("Location: pemilih.php");
    exit();
}
// --- Akhir Proses Hapus Pemilih ---


// Form Tambah Pemilih Manual oleh Admin DIHILANGKAN
// Logika PHP untuk tambah pemilih manual juga DIHILANGKAN


// --- Muat Template Header ---
require 'template/header.php'; // Header HTML sudah termasuk koneksi Bootstrap
?>

<div class="container py-4"> <h3 class="mb-4"><i class="bi bi-person-lines-fill me-2"></i>Kelola Data Pemilih Terdaftar</h3>

    <?php // Tampilkan pesan flash success/error dari operasi hapus ?>
    <?php if (!empty($error_pemilih)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error_pemilih) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($success_pemilih)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
             <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($success_pemilih) ?>
             <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm mt-4"> <div class="card-header bg-info text-white"> <i class="bi bi-list-ul me-2"></i>Daftar Pemilih yang Telah Mendaftar
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-striped table-hover align-middle">
                    <thead class="table-dark text-center">
                        <tr>
                            <th scope="col" style="width: 5%;">No.</th>
                            <th scope="col">Username</th>
                            <th scope="col">Nama Lengkap</th>
                            <th scope="col" style="width: 15%;">Foto Wajah Pendaftaran</th>
                            <th scope="col" style="width: 10%;">Sudah Voting?</th>
                            <th scope="col" style="width: 15%;">Waktu Registrasi</th>
                            <th scope="col" style="width: 10%;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Ambil data pemilih termasuk foto_wajah_pendaftaran
                        $sql_get_pemilih = "SELECT id, username, nama, sudah_vote, created_at, foto_wajah_pendaftaran 
                                            FROM users 
                                            WHERE role = 'pemilih' 
                                            ORDER BY id DESC";
                        $result_data_pemilih = $conn->query($sql_get_pemilih);
                        $nomor_urut = 1;
                        
                        // Ambil token CSRF sekali untuk semua form hapus di loop
                        $csrf_token_for_delete_form = generate_csrf_token(); 

                        if ($result_data_pemilih && $result_data_pemilih->num_rows > 0):
                            while ($row_pemilih = $result_data_pemilih->fetch_assoc()):
                        ?>
                        <tr>
                            <td class="text-center"><?= $nomor_urut++ ?></td>
                            <td><?= htmlspecialchars($row_pemilih['username'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($row_pemilih['nama'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-center">
                                <?php
                                if (!empty($row_pemilih['foto_wajah_pendaftaran'])) {
                                    $path_foto_registrasi = UPLOAD_FOTO_REGISTRASI_DIR . basename($row_pemilih['foto_wajah_pendaftaran']);
                                    $url_foto_registrasi = BASE_URL . 'uploads/registrasi_foto/' . basename($row_pemilih['foto_wajah_pendaftaran']);
                                    if (file_exists($path_foto_registrasi)) {
                                        // Tampilkan sebagai gambar kecil yang bisa di-klik untuk versi lebih besar jika perlu
                                        echo '<a href="' . htmlspecialchars($url_foto_registrasi, ENT_QUOTES, 'UTF-8') . '" target="_blank" title="Lihat Foto Pendaftaran">';
                                        echo '<img src="' . htmlspecialchars($url_foto_registrasi, ENT_QUOTES, 'UTF-8') . '" alt="Foto Pendaftaran ' . htmlspecialchars($row_pemilih['username'], ENT_QUOTES, 'UTF-8') . '" style="max-width: 80px; max-height: 60px; border-radius: 5px; object-fit: cover; cursor:pointer;">';
                                        echo '</a>';
                                    } else {
                                        echo '<span class="text-muted small fst-italic">Foto tidak ditemukan di server.</span>';
                                        error_log("Foto pendaftaran hilang: " . $path_foto_registrasi);
                                    }
                                } else {
                                    echo '<span class="text-muted small">Belum Ada</span>';
                                }
                                ?>
                            </td>
                            <td class="text-center">
                                <?php if ($row_pemilih['sudah_vote']): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle-fill"></i> Ya</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split"></i> Belum</span>
                                <?php endif; ?>
                            </td>
                            <td>
                               <?php
                                    try {
                                        $tanggal_registrasi = new DateTime($row_pemilih['created_at']);
                                        echo htmlspecialchars($tanggal_registrasi->format('d M Y, H:i'), ENT_QUOTES, 'UTF-8');
                                    } catch (Exception $e) {
                                        // Tampilkan apa adanya jika format tidak dikenal
                                        echo htmlspecialchars($row_pemilih['created_at'], ENT_QUOTES, 'UTF-8');
                                    }
                               ?>
                            </td>
                            <td class="text-center">
                                <form method="POST" action="pemilih.php" style="display: inline;" onsubmit="return confirm('PERHATIAN! Anda akan menghapus pemilih \'<?= htmlspecialchars(addslashes($row_pemilih['username']), ENT_QUOTES, 'UTF-8') ?>\'. Data voting terkait (jika ada) juga akan terpengaruh atau terhapus karena relasi database. Tindakan ini tidak bisa dibatalkan. Lanjutkan?');">
                                    <input type="hidden" name="pemilih_id_to_delete" value="<?= (int)$row_pemilih['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token_for_delete_form); ?>">
                                    <button type="submit" name="hapus_pemilih_action" class="btn btn-danger btn-sm" title="Hapus Pemilih">
                                        <i class="bi bi-trash3-fill"></i> Hapus
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php
                            endwhile;
                            $result_data_pemilih->free(); // Bebaskan memori hasil query
                        else:
                        ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted p-4">
                               <i class="bi bi-people fs-3 d-block mb-2"></i>
                               Belum ada data pemilih yang mendaftar melalui sistem.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div> </div> </div> </div> <?php
require 'template/footer.php'; // Footer HTML
// Koneksi $conn akan ditutup otomatis oleh PHP di akhir script.
?>