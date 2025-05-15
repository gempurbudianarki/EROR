<?php
// kandidat.php - Admin Mengelola Data Kandidat (Revisi Desain Maksimal)

require_once 'config.php';

if (!is_admin()) {
    header("Location: dashboard.php");
    exit();
}

$error_kandidat = '';
$success_kandidat = '';

// Flash Messages
if (isset($_SESSION['flash_success_kandidat'])) {
    $success_kandidat = $_SESSION['flash_success_kandidat'];
    unset($_SESSION['flash_success_kandidat']);
}
if (isset($_SESSION['flash_error_kandidat'])) {
    $error_kandidat = $_SESSION['flash_error_kandidat'];
    unset($_SESSION['flash_error_kandidat']);
}

// --- Proses Hapus Kandidat (Logika PHP sama) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus_kandidat_action'])) {
    // ... (Logika Hapus Kandidat lengkap seperti versi sebelumnya) ...
    if (!validate_csrf_token()) { $_SESSION['flash_error_kandidat'] = 'Error: Permintaan hapus tidak valid.'; header("Location: kandidat.php"); exit(); }
    $id_kandidat_to_delete = filter_input(INPUT_POST, 'kandidat_id_to_delete', FILTER_VALIDATE_INT);
    if ($id_kandidat_to_delete && $id_kandidat_to_delete > 0) {
        $nama_kdt_del = ''; $file_foto_kdt_del = null;
        $stmt_get = $conn->prepare("SELECT nama, foto FROM kandidat WHERE id = ?");
        if ($stmt_get) { $stmt_get->bind_param("i", $id_kandidat_to_delete); $stmt_get->execute(); $stmt_get->bind_result($nama_kdt_del, $file_foto_kdt_del); $stmt_get->fetch(); $stmt_get->close(); }
        else { $_SESSION['flash_error_kandidat'] = "Gagal cek data kandidat."; header("Location: kandidat.php"); exit(); }
        if (empty($nama_kdt_del)) { $_SESSION['flash_error_kandidat'] = "Kandidat tidak ditemukan."; }
        else {
            $stmt_del = $conn->prepare("DELETE FROM kandidat WHERE id = ?");
            if ($stmt_del) {
                $stmt_del->bind_param("i", $id_kandidat_to_delete);
                if ($stmt_del->execute()) {
                    if ($stmt_del->affected_rows > 0) {
                        $_SESSION['flash_success_kandidat'] = "Kandidat '{$nama_kdt_del}' berhasil dihapus.";
                        if (!empty($file_foto_kdt_del)) { $safe_filename = basename($file_foto_kdt_del); if ($safe_filename === $file_foto_kdt_del) { $path_foto_fisik = UPLOAD_FOTO_KANDIDAT_DIR . $safe_filename; if (file_exists($path_foto_fisik)) @unlink($path_foto_fisik); } }
                    } else { $_SESSION['flash_error_kandidat'] = "Gagal hapus kandidat '{$nama_kdt_del}'."; }
                } else { $_SESSION['flash_error_kandidat'] = "Gagal hapus data kandidat."; error_log("Kdt Del Exec Err: ".$stmt_del->error); }
                $stmt_del->close();
            } else { $_SESSION['flash_error_kandidat'] = "Gagal prepare hapus kandidat."; error_log("Kdt Del Prep Err: ".$conn->error); }
        }
    } else { $_SESSION['flash_error_kandidat'] = "ID Kandidat tidak valid."; }
    header("Location: kandidat.php"); exit();
}

// --- Proses Tambah Kandidat Baru (Logika PHP sama, termasuk format teks) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tambah_kandidat_action'])) {
    // ... (Logika Tambah Kandidat lengkap seperti versi sebelumnya, termasuk format teks otomatis) ...
    if (!validate_csrf_token()) { $_SESSION['flash_error_kandidat'] = 'Error: Permintaan tambah tidak valid.'; header("Location: kandidat.php"); exit(); }
    $nama_kdt_raw = sanitize_input($_POST['nama_kandidat'] ?? ''); $jurusan_kdt_raw = sanitize_input($_POST['jurusan_kandidat'] ?? ''); $visi_misi_kdt_raw = trim(sanitize_input($_POST['visi_misi_kandidat'] ?? '')); $foto_kdt_file_info = $_FILES['foto_kandidat'] ?? null;
    // Format Teks
    if (function_exists('mb_convert_case')) { $nama_kdt_formatted = mb_convert_case($nama_kdt_raw, MB_CASE_TITLE, "UTF-8"); $jurusan_kdt_formatted = mb_convert_case($jurusan_kdt_raw, MB_CASE_TITLE, "UTF-8"); } else { $nama_kdt_formatted = ucwords(strtolower($nama_kdt_raw)); $jurusan_kdt_formatted = ucwords(strtolower($jurusan_kdt_raw)); }
    $visi_misi_kdt_formatted = ucfirst($visi_misi_kdt_raw);
    // Validasi dasar
    if (empty($nama_kdt_formatted) || empty($jurusan_kdt_formatted) || empty($visi_misi_kdt_formatted)) { $error_kandidat = "Nama, Jurusan, serta Visi & Misi wajib diisi!"; }
    elseif ($foto_kdt_file_info === null || $foto_kdt_file_info['error'] == UPLOAD_ERR_NO_FILE) { $error_kandidat = "Foto kandidat wajib diunggah!"; }
    elseif ($foto_kdt_file_info['error'] !== UPLOAD_ERR_OK) { $error_kandidat = "Error upload file foto."; }
    else { // Validasi file detail
         $file_tmp_path_kdt = $foto_kdt_file_info['tmp_name']; $file_original_name_kdt = $foto_kdt_file_info['name']; $file_size_kdt = $foto_kdt_file_info['size'];
         $file_extension_kdt = strtolower(pathinfo($file_original_name_kdt, PATHINFO_EXTENSION)); $new_server_filename_kdt = 'kdt_foto_' . uniqid() . '_' . time() . '.' . $file_extension_kdt;
         $allowed_extensions_kdt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
         if (!in_array($file_extension_kdt, $allowed_extensions_kdt)) { $error_kandidat = 'Ekstensi foto tidak diizinkan.'; }
         else { // Validasi mime
             if (function_exists('finfo_open')) { $finfo = finfo_open(FILEINFO_MIME_TYPE); $mime = finfo_file($finfo, $file_tmp_path_kdt); finfo_close($finfo); } else { $mime = mime_content_type($file_tmp_path_kdt); }
             $allowed_mime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
             if (!in_array($mime, $allowed_mime)) { $error_kandidat = 'Tipe file gambar tidak valid.'; }
             else { // Validasi ukuran
                 $max_size = 2 * 1024 * 1024;
                 if ($file_size_kdt > $max_size) { $error_kandidat = "Ukuran foto melebihi 2MB."; }
                 else { // Pindahkan file & Simpan DB
                     $dest_path = UPLOAD_FOTO_KANDIDAT_DIR . $new_server_filename_kdt;
                     if (!is_writable(UPLOAD_FOTO_KANDIDAT_DIR)) { $error_kandidat = 'Error: Direktori upload kandidat tidak bisa ditulis.'; }
                     elseif (move_uploaded_file($file_tmp_path_kdt, $dest_path)) {
                         $stmt_ins = $conn->prepare("INSERT INTO kandidat (nama, jurusan, foto, visi_misi) VALUES (?, ?, ?, ?)");
                         if ($stmt_ins) {
                             $stmt_ins->bind_param("ssss", $nama_kdt_formatted, $jurusan_kdt_formatted, $new_server_filename_kdt, $visi_misi_kdt_formatted);
                             if ($stmt_ins->execute()) { $_SESSION['flash_success_kandidat'] = "Kandidat '{$nama_kdt_formatted}' berhasil ditambahkan!"; header("Location: kandidat.php"); exit(); }
                             else { $error_kandidat = "Gagal simpan data kandidat."; if (file_exists($dest_path)) @unlink($dest_path); error_log("Kdt Add Exec Err: ".$stmt_ins->error); }
                             $stmt_ins->close();
                         } else { $error_kandidat = "Gagal prepare simpan kandidat."; if (file_exists($dest_path)) @unlink($dest_path); error_log("Kdt Add Prep Err: ".$conn->error); }
                     } else { $error_kandidat = 'Gagal upload file foto kandidat.'; }
                 }
             }
         }
    }
    if (!empty($error_kandidat)) { $_SESSION['flash_error_kandidat'] = $error_kandidat; header("Location: kandidat.php"); exit(); }
}


// Muat Template Header
require 'template/header.php';
?>

<style>
    /* Variabel Warna (konsisten dengan dashboard biru cerah) */
    :root {
        --primary-blue: #0D6EFD;
        --primary-blue-dark: #0A58CA;
        --primary-blue-light: #6EB3F0; /* Lebih soft dari sebelumnya */
        --secondary-blue: #e7f1ff; /* Biru sangat pucat untuk background */
        --text-dark: #212529;
        --text-muted: #6C757D;
        --text-light: #f8f9fa;
        --card-bg: #FFFFFF;
        --card-border: #e0e5ec; /* Border lebih soft */
        --card-shadow: 0 6px 25px rgba(13, 110, 253, 0.09); /* Shadow lebih soft */
        --pattern-color: rgba(13, 110, 253, 0.05);
        --danger-color: #DC3545;
        --danger-hover: #BB2D3B;
        --success-color: #198754;
        --bs-border-radius-lg: 1rem; /* 16px */
        --bs-border-radius: 0.625rem; /* 10px */
        --bs-font-sans-serif: 'Poppins', sans-serif;
    }

    /* Terapkan background pattern ke body */
    body {
        background-color: var(--secondary-blue);
        background-image:
            linear-gradient(var(--pattern-color) 1px, transparent 1px),
            linear-gradient(90deg, var(--pattern-color) 1px, transparent 1px);
        background-size: 22px 22px; /* Ukuran kotak pattern lebih kecil lagi */
        font-family: var(--bs-font-sans-serif);
    }

    .container.py-4 { padding-top: 2.5rem !important; padding-bottom: 4rem !important; } /* Padding container utama */

    /* Styling Card */
    .card {
        border: 1px solid var(--card-border);
        border-radius: var(--bs-border-radius-lg);
        box-shadow: var(--card-shadow);
        margin-bottom: 2.5rem; /* Jarak lebih besar antar card */
    }
    .card-header {
        background: linear-gradient(120deg, var(--primary-blue) 0%, var(--primary-blue-light) 100%);
        color: #fff;
        font-weight: 600;
        font-size: 1.1rem;
        border-bottom: none;
        border-top-left-radius: var(--bs-border-radius-lg);
        border-top-right-radius: var(--bs-border-radius-lg);
        padding: 1.1rem 1.5rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .card-header .bi { margin-right: 0.7rem; font-size: 1.2em; color: #fff; }
    .card-body { padding: 2rem; }

    /* Styling Tombol Collapse untuk Form */
    .btn-collapse-form {
        background-color: var(--primary-blue);
        color: #fff;
        border: none;
        border-radius: var(--bs-border-radius);
        padding: 0.75rem 1.5rem;
        font-weight: 500;
        margin-bottom: 1rem;
        transition: all 0.3s ease;
    }
    .btn-collapse-form:hover {
        background-color: var(--primary-blue-dark);
        box-shadow: 0 4px 10px rgba(13, 110, 253, 0.3);
    }
    .btn-collapse-form .bi-chevron-down { transition: transform 0.3s ease; }
    .btn-collapse-form[aria-expanded="true"] .bi-chevron-down { transform: rotate(180deg); }


    /* Form Styling */
    .form-label { font-weight: 500; margin-bottom: 0.5rem; color: #495057; font-size: 0.9rem; }
    .form-control, .form-control-sm, .form-select { border-radius: var(--bs-border-radius); }
    .form-control:focus, .form-select:focus { border-color: var(--primary-blue); box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.15); }
    textarea.form-control { min-height: 110px; }
    .form-text { font-size: 0.85rem; }
    #imagePreviewKandidat { display: none; width: 160px; height: 160px; margin: 10px auto 5px auto; border: 4px solid #fff; border-radius: 10px; object-fit: cover; box-shadow: 0 5px 15px rgba(0,0,0,0.15); background-color: #e9ecef; }
    .preview-container { text-align: center; }
    .custom-file-input-wrapper { /* Wrapper jika ingin custom file input */
        position: relative; overflow: hidden; display: inline-block;
    }
    /* Styling bisa ditambahkan untuk membuat tombol file input custom */


    /* Tabel Kandidat yang Lebih Menarik */
    .table-kandidat { border-collapse: separate; border-spacing: 0 5px; /* Jarak antar baris */ }
    .table-kandidat th { background-color: transparent; border: none; font-weight: 600; color: var(--primary-blue); padding-bottom: 1rem; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.5px; }
    .table-kandidat tbody tr { background-color: #fff; border-radius: var(--bs-border-radius); box-shadow: 0 2px 8px rgba(0,0,0,0.05); transition: all 0.2s ease; border: 1px solid transparent; }
    .table-kandidat tbody tr:hover { box-shadow: var(--card-shadow); transform: translateY(-3px) scale(1.01); border-left: 4px solid var(--primary-blue); z-index: 1; position: relative; }
    .table-kandidat td { border: none; padding: 1rem; }
    .table-kandidat td:first-child { border-top-left-radius: var(--bs-border-radius); border-bottom-left-radius: var(--bs-border-radius); }
    .table-kandidat td:last-child { border-top-right-radius: var(--bs-border-radius); border-bottom-right-radius: var(--bs-border-radius); }

    .kandidat-photo-table-modern { /* Class baru */
        width: 55px; height: 55px; object-fit: cover; border-radius: 50%;
        border: 3px solid var(--bs-body-bg); box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }
    .visi-misi-cell { font-size: 0.85rem; color: var(--text-muted); max-height: 60px; overflow: hidden; position: relative; padding-right: 10px; /* Ruang untuk tombol 'more' */ }
    .visi-misi-cell.expanded { max-height: none; }
    .read-more-btn {
        font-size: 0.75rem; color: var(--primary-blue); cursor: pointer;
        text-decoration: none; font-weight: 600; display: inline-block; margin-top: 5px;
    }
    .read-more-btn:hover { text-decoration: underline; }

    .btn-aksi-kandidat { padding: 0.3rem 0.6rem; font-size: 0.8rem; border-radius: 0.4rem;}
    .btn-aksi-kandidat .bi { vertical-align: -0.1em; }

    /* Alert Styling */
    .alert { border-radius: var(--bs-border-radius); border-left: 5px solid; }
    .alert-danger { border-left-color: var(--danger-color); }
    .alert-success { border-left-color: var(--success-color); }

</style>

<div class="container py-4">
    <h3 class="mb-4 fw-bold" style="color: var(--primary-blue-dark);"><i class="bi bi-people-fill me-2"></i>Manajemen Data Kandidat</h3>

    <?php if (!empty($error_kandidat)): ?> <div class="alert alert-danger alert-dismissible fade show" role="alert"> <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error_kandidat) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button> </div> <?php endif; ?>
    <?php if (!empty($success_kandidat)): ?> <div class="alert alert-success alert-dismissible fade show" role="alert"> <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($success_kandidat) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button> </div> <?php endif; ?>

    <p>
        <button class="btn btn-primary btn-collapse-form" type="button" data-bs-toggle="collapse" data-bs-target="#tambahKandidatCollapse" aria-expanded="false" aria-controls="tambahKandidatCollapse">
            <i class="bi bi-plus-circle-fill me-1"></i> Tambah Kandidat Baru <i class="bi bi-chevron-down ms-2"></i>
        </button>
    </p>

    <div class="collapse" id="tambahKandidatCollapse">
        <div class="card mb-4">
            <div class="card-header">
                <span><i class="bi bi-person-plus-fill"></i> Form Input Data Kandidat</span>
                <button type="button" class="btn-close btn-close-white p-2" aria-label="Close" data-bs-toggle="collapse" data-bs-target="#tambahKandidatCollapse"></button> </div>
            <div class="card-body">
                <form method="POST" action="kandidat.php" enctype="multipart/form-data" id="formTambahKandidat">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()); ?>">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="namaKandidatInput" class="form-label">Nama Kandidat <span class="text-danger">*</span></label>
                            <input name="nama_kandidat" id="namaKandidatInput" type="text" class="form-control" placeholder="Nama Lengkap" required>
                        </div>
                        <div class="col-md-6">
                            <label for="jurusanKandidatInput" class="form-label">Jurusan / Keterangan <span class="text-danger">*</span></label>
                            <input name="jurusan_kandidat" id="jurusanKandidatInput" type="text" class="form-control" placeholder="Contoh: Prodi TI / Angkatan 2022" required>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-7">
                             <label for="visiMisiKandidatInput" class="form-label">Visi & Misi <span class="text-danger">*</span></label>
                            <textarea name="visi_misi_kandidat" id="visiMisiKandidatInput" class="form-control" rows="6" placeholder="Jelaskan visi dan misi..." required></textarea>
                        </div>
                        <div class="col-md-5">
                            <label for="fotoKandidatInput" class="form-label">Upload Foto Kandidat <span class="text-danger">*</span></label>
                            <input type="file" name="foto_kandidat" id="fotoKandidatInput" class="form-control mb-2" required accept=".jpg, .jpeg, .png, .gif, .webp">
                            <small class="form-text text-muted d-block">Format: JPG, PNG, GIF, WEBP. Max 2MB.</small>
                            <div class="preview-container mt-2">
                                 <img id="imagePreviewKandidat" src="#" alt="Preview Foto Kandidat"/>
                            </div>
                        </div>
                        <div class="col-12 text-end mt-4">
                            <button type="submit" class="btn btn-primary px-4 py-2" name="tambah_kandidat_action">
                               <i class="bi bi-database-fill-add me-2"></i> Simpan Data
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <i class="bi bi-list-stars me-2"></i>Daftar Kandidat Terdaftar
        </div>
        <div class="card-body pt-0"> <div class="table-responsive">
                <table class="table table-borderless align-middle table-kandidat caption-top"> <caption>Daftar kandidat yang terdaftar dalam sistem.</caption>
                    <thead>
                        <tr class="text-center align-middle">
                            <th style="width: 10%;">Foto</th>
                            <th>Nama</th>
                            <th>Jurusan/Ket.</th>
                            <th>Visi & Misi</th>
                            <th class="text-center">Suara</th>
                            <th class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $sql_get_kdt_list = "SELECT id, nama, jurusan, foto, visi_misi, jumlah_suara FROM kandidat ORDER BY nama ASC"; // Urutkan berdasarkan Nama
                        $result_kdt_list = $conn->query($sql_get_kdt_list);
                        $csrf_token_del_kdt = generate_csrf_token();

                        if ($result_kdt_list && $result_kdt_list->num_rows > 0):
                            while ($row_kdt_list = $result_kdt_list->fetch_assoc()):
                                $url_gambar_kdt_list = BASE_URL . 'assets/img/default-user.png';
                                if (!empty($row_kdt_list['foto'])) {
                                    $safe_filename_list = basename($row_kdt_list['foto']);
                                    $path_foto_list_server = UPLOAD_FOTO_KANDIDAT_DIR . $safe_filename_list;
                                    $url_foto_list_web = BASE_URL . 'uploads/kandidat_foto/' . $safe_filename_list;
                                    if ($safe_filename_list === $row_kdt_list['foto'] && file_exists($path_foto_list_server)) {
                                         $url_gambar_kdt_list = $url_foto_list_web;
                                    }
                                }
                        ?>
                        <tr>
                            <td class="text-center">
                                <img src="<?= htmlspecialchars($url_gambar_kdt_list) ?>" alt="<?= htmlspecialchars($row_kdt_list['nama']) ?>" class="kandidat-photo-table-modern" loading="lazy">
                            </td>
                            <td class="fw-medium"><?= htmlspecialchars($row_kdt_list['nama']) ?></td>
                            <td class="small text-muted"><?= htmlspecialchars($row_kdt_list['jurusan']) ?></td>
                            <td class="visi-misi-cell">
                                <div class="visi-misi-content" data-fulltext="<?= htmlspecialchars($row_kdt_list['visi_misi']) ?>">
                                    <?= nl2br(htmlspecialchars($row_kdt_list['visi_misi'])) // Teks awal akan dipotong oleh JS ?>
                                </div>
                                <a href="#" class="read-more-btn" style="display: none;">Baca Selengkapnya...</a>
                            </td>
                            <td class="text-center fw-bold fs-5 text-primary"><?= (int)$row_kdt_list['jumlah_suara'] ?></td>
                            <td class="text-center">
                                <form method="POST" action="kandidat.php" style="display: inline;" onsubmit="return confirm('Hapus kandidat \'<?= htmlspecialchars(addslashes($row_kdt_list['nama'])) ?>\'? Ini tidak bisa dibatalkan.');">
                                    <input type="hidden" name="kandidat_id_to_delete" value="<?= (int)$row_kdt_list['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token_del_kdt); ?>">
                                    <button type="submit" name="hapus_kandidat_action" class="btn btn-outline-danger btn-sm btn-aksi-kandidat" title="Hapus Kandidat">
                                        <i class="bi bi-trash-fill"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php
                            endwhile;
                             $result_kdt_list->free();
                        else:
                        ?>
                        <tr> <td colspan="6" class="text-center text-muted p-5"> <i class="bi bi-person-video3 fs-2 d-block mb-2"></i> Belum ada data kandidat. </td> </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- Script Preview Gambar (Sama seperti sebelumnya) ---
    const fotoInput = document.getElementById('fotoKandidatInput');
    const imagePreview = document.getElementById('imagePreviewKandidat');
    if (fotoInput && imagePreview) {
        fotoInput.addEventListener('change', function(event) {
            const file = event.target.files[0];
            if (file) {
                const fileType = file['type']; const validImageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (!validImageTypes.includes(fileType)) { alert('Format file tidak valid.'); imagePreview.style.display = 'none'; imagePreview.src = '#'; fotoInput.value = ''; return; }
                 const maxSizeInBytes = 2 * 1024 * 1024; 
                 if (file.size > maxSizeInBytes) { alert('Ukuran file terlalu besar (Max 2MB).'); imagePreview.style.display = 'none'; imagePreview.src = '#'; fotoInput.value = ''; return; }
                const reader = new FileReader();
                reader.onload = function(e) { imagePreview.src = e.target.result; imagePreview.style.display = 'block'; }
                reader.readAsDataURL(file);
            } else { imagePreview.style.display = 'none'; imagePreview.src = '#'; }
        });
    }

    // --- Script Read More untuk Visi Misi ---
    const visiMisiCells = document.querySelectorAll('.visi-misi-cell');
    const maxChars = 100; // Jumlah karakter maksimum sebelum dipotong

    visiMisiCells.forEach(cell => {
        const contentDiv = cell.querySelector('.visi-misi-content');
        const readMoreBtn = cell.querySelector('.read-more-btn');
        const fullText = contentDiv.dataset.fulltext.trim(); // Ambil teks lengkap dari data attribute

        if (fullText.length > maxChars) {
            const truncatedText = fullText.substring(0, maxChars) + "...";
            contentDiv.innerHTML = nl2br(escapeHtml(truncatedText)); // Tampilkan teks terpotong
            readMoreBtn.style.display = 'inline'; // Tampilkan tombol 'Baca Selengkapnya'

            readMoreBtn.addEventListener('click', function(e) {
                e.preventDefault();
                cell.classList.toggle('expanded'); // Toggle class untuk expand/collapse
                if (cell.classList.contains('expanded')) {
                    contentDiv.innerHTML = nl2br(escapeHtml(fullText)); // Tampilkan teks lengkap
                    readMoreBtn.textContent = 'Sembunyikan';
                } else {
                    contentDiv.innerHTML = nl2br(escapeHtml(truncatedText)); // Kembalikan ke teks terpotong
                    readMoreBtn.textContent = 'Baca Selengkapnya...';
                    // Scroll ke atas sedikit jika perlu setelah collapse (opsional)
                    // cell.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); 
                }
            });
        } else {
             contentDiv.innerHTML = nl2br(escapeHtml(fullText)); // Jika tidak panjang, tampilkan saja
        }
    });

    // Fungsi helper untuk escape HTML dan nl2br di JS
    function escapeHtml(unsafe) {
        if (unsafe === null || unsafe === undefined) return '';
        return unsafe
             .replace(/&/g, "&amp;")
             .replace(/</g, "&lt;")
             .replace(/>/g, "&gt;")
             .replace(/"/g, "&quot;")
             .replace(/'/g, "&#039;");
    }
    function nl2br(str) {
        if (typeof str === 'undefined' || str === null) { return ''; }
        return (str + '').replace(/([^>\r\n]?)(\r\n|\n\r|\r|\n)/g, '$1<br>$2');
    }

});
</script>

<?php
require 'template/footer.php';
?>