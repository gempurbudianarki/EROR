<?php
// register.php - Halaman Registrasi Pengguna Baru
// MODIFIKASI: Menghilangkan pose kiri/kanan, menambahkan capture foto senyum.

require_once 'config.php';

if (is_login()) {
    header("Location: dashboard.php");
    exit();
}

$errors_register = [];
$input_username_register = '';
$input_nama_lengkap_register = '';

define('PYTHON_API_BASE_URL', 'http://localhost:5000/api'); // Pastikan ini sesuai

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token()) {
        $errors_register[] = 'Sesi tidak valid atau telah berakhir. Silakan muat ulang halaman dan coba lagi.';
    } else {
        $input_username_register = sanitize_input($_POST['username'] ?? '');
        $input_nama_lengkap_register = sanitize_input($_POST['nama_lengkap'] ?? '');
        $password_register = $_POST['password'] ?? '';
        $password_confirm_register = $_POST['password_confirm'] ?? '';

        $final_verified_main_photo_filename = sanitize_input($_POST['foto_wajah_filename'] ?? ''); // Foto utama (netral)
        $final_verified_smile_photo_filename = sanitize_input($_POST['foto_wajah_senyum_filename'] ?? ''); // Foto senyum BARU

        if (empty($input_username_register)) $errors_register[] = "Username tidak boleh kosong.";
        elseif (strlen($input_username_register) < 4) $errors_register[] = "Username minimal 4 karakter.";
        elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $input_username_register)) $errors_register[] = "Username hanya boleh mengandung huruf, angka, dan underscore (_).";
        if (empty($input_nama_lengkap_register)) $errors_register[] = "Nama Lengkap tidak boleh kosong.";
        if (empty($password_register)) $errors_register[] = "Password tidak boleh kosong.";
        if ($password_register !== $password_confirm_register) $errors_register[] = "Konfirmasi password tidak cocok.";
        if (!empty($password_register) && empty($errors_register)) {
            $password_strength_errors = validate_password_strength($password_register);
            if (!empty($password_strength_errors)) $errors_register = array_merge($errors_register, $password_strength_errors);
        }
        if (empty($errors_register) && !empty($input_username_register)) {
            $stmt_check_username = $conn->prepare("SELECT id FROM users WHERE username = ?");
            if ($stmt_check_username) {
                $stmt_check_username->bind_param("s", $input_username_register);
                $stmt_check_username->execute();
                $stmt_check_username->store_result();
                if ($stmt_check_username->num_rows > 0) {
                    $errors_register[] = "Username '{$input_username_register}' sudah digunakan.";
                }
                $stmt_check_username->close();
            } else { $errors_register[]="Gagal cek database untuk username."; error_log("Reg: Prepare fail cek uname ".$conn->error); }
        }

        if (empty($errors_register) && empty($final_verified_main_photo_filename)) {
            $errors_register[] = "Verifikasi foto wajah utama (kedipan) diperlukan.";
        }
        // Validasi untuk foto senyum BARU
        if (empty($errors_register) && empty($final_verified_smile_photo_filename)) {
            $errors_register[] = "Verifikasi foto wajah tersenyum diperlukan.";
        }

        error_log("Registrasi (PHP): Data diterima. Foto Utama: {$final_verified_main_photo_filename}. Foto Senyum: {$final_verified_smile_photo_filename}.");

        if (empty($errors_register) && !empty($final_verified_main_photo_filename) && !empty($final_verified_smile_photo_filename)) {
            $hashed_password_register = password_hash($password_register, PASSWORD_DEFAULT);
            $default_role_register = 'pemilih';
            
            // Sanitasi nama file sekali lagi sebelum ke DB (meskipun API sudah memberi nama aman)
            $nama_file_utama_db = basename($final_verified_main_photo_filename);
            $nama_file_senyum_db = basename($final_verified_smile_photo_filename);

            // PERSIAPKAN INSERT DENGAN KOLOM BARU `foto_wajah_senyum`
            $stmt_insert_db = $conn->prepare("INSERT INTO users (username, password, nama, role, foto_wajah_pendaftaran, foto_wajah_senyum) VALUES (?, ?, ?, ?, ?, ?)");
            if ($stmt_insert_db) {
                $stmt_insert_db->bind_param("ssssss", // Tambah satu 's'
                    $input_username_register,
                    $hashed_password_register,
                    $input_nama_lengkap_register,
                    $default_role_register,
                    $nama_file_utama_db, // foto_wajah_pendaftaran
                    $nama_file_senyum_db  // foto_wajah_senyum BARU
                );
                if ($stmt_insert_db->execute()) {
                    $_SESSION['flash_success_register'] = "Pendaftaran Berhasil! Akun untuk '{$input_username_register}' telah dibuat. Silakan login.";
                    header("Location: login.php");
                    exit();
                } else {
                    $errors_register[] = "Terjadi kesalahan saat menyimpan data Anda ke database.";
                    error_log("Registrasi: Gagal execute insert user - " . $stmt_insert_db->error);
                }
                $stmt_insert_db->close();
            } else {
                $errors_register[] = "Terjadi kesalahan sistem saat menyiapkan penyimpanan data pengguna.";
                error_log("Registrasi: Gagal prepare statement insert user - " . $conn->error);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrasi Akun - Sistem E-Voting UBBG</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --primary-color: #4f46e5; --primary-hover: #4338ca; --background-gradient-start: #1F229B;
            --background-gradient-end: #252A60; --card-background: #ffffff; --text-light: #f8fafc;
            --shadow-color: rgba(0, 0, 0, 0.1); --danger-bg: #f8d7da; --danger-text: #721c24;
            --danger-border: #f5c6cb; --success-text: #0f5132; --warning-text: #664d03;
            --info-bg-light: #cff4fc; --info-text-dark: #055160; --info-border: #b6effb;
        }
        body { background-color: var(--background-gradient-start); background-image: linear-gradient(135deg, var(--background-gradient-start) 0%, var(--background-gradient-end) 100%), linear-gradient(rgba(255,255,255,0.02) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.02) 1px, transparent 1px); background-size: cover, 10px 10px, 10px 10px; font-family: 'Poppins', sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; margin: 0; padding: 20px 10px; }
        .register-container { width: 100%; max-width: 650px; }
        .card-register { background-color: var(--card-background); border-radius: 20px; box-shadow: 0 10px 30px var(--shadow-color); color: #333; overflow: hidden; }
        .card-register-header { background-color: var(--primary-color); color: var(--text-light); padding: 1.25rem 1.5rem; font-size: 1.4rem; font-weight: 600; }
        .card-register-body { padding: 2rem; }
        .form-control { border-radius: 8px; padding: 0.75rem 1rem; font-size: 0.9rem; }
        .form-control:focus { border-color: var(--primary-color); box-shadow: 0 0 0 0.2rem rgba(79, 70, 229, 0.2); }
        .btn-register-submit { background-color: var(--primary-color); border-color: var(--primary-color); padding: 0.8rem 1.5rem; border-radius: 8px; font-weight: 600; font-size: 1rem; width: 100%; }
        .btn-register-submit:hover { background-color: var(--primary-hover); border-color: var(--primary-hover); }
        .password-strength-info { font-size: 0.8rem; color: #6c757d; margin-top: 0.25rem; margin-bottom: 0.5rem; }
        .alert-danger ul { margin-bottom: 0; padding-left: 1.25rem; font-size: 0.85rem; }
        .camera-area-register { margin-top: 1.5rem; margin-bottom: 1.5rem; background: #f0f2f5; border: 1px solid #e0e5ec; border-radius: 10px; padding: 15px; text-align: center; }

        #cameraDisplayArea {
            position: relative; width: 100%; max-width: 280px;
            height: auto; margin: 0 auto 10px auto;
            border-radius: 8px; overflow: hidden; background-color: #333;
            display: none;
        }
        #cameraPreviewRegisterEl { width: 100%; height: auto; aspect-ratio: 4 / 3; display: block; border-radius: 8px; }
        #faceOverlayEl { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 65%; height: 80%; border: 3px dashed rgba(255, 255, 255, 0.7); border-radius: 40% / 50%; box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.35); display: none; pointer-events: none; }
        #captureCanvasRegisterEl { display: none; }

        #photoTakingInstructionEl, #smileInstructionEl /* BARU */ { font-size: 0.85rem; color: #333; padding: 10px; border-radius: 5px; background-color: #e9ecef; margin-bottom: 15px !important; text-align: center; border: 1px solid #ced4da; }
        #photoStatusRegisterEl, #smileStatusEl /* BARU */ { font-weight: 500; margin-top: 10px; min-height: 20px; font-size: 0.9rem; padding: 8px; border-radius: 5px; display: none; }
        #photoStatusRegisterEl.status-error, #smileStatusEl.status-error /* BARU */ { color: var(--danger-text); background-color: var(--danger-bg); border: 1px solid var(--danger-border); }
        #photoStatusRegisterEl.status-success, #smileStatusEl.status-success /* BARU */ { color: var(--success-text); background-color: #d1e7dd; border: 1px solid #badbcc; }
        #photoStatusRegisterEl.status-info, #smileStatusEl.status-info /* BARU */ { color: var(--warning-text); background-color: #fff3cd; border: 1px solid #ffecb5; }

        .snapshot-preview-container, .smile-preview-container /* BARU */ { margin-top:10px; }
        .snapshot-preview-container img, .smile-preview-container img /* BARU */ {
            max-width: 100px; max-height: 75px; border: 1px solid var(--primary-color);
            border-radius: 5px; margin: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            object-fit: cover; background-color: #e9ecef;
        }
        .login-link-container { margin-top: 1.5rem; text-align: center; font-size: 0.9rem; }
        .login-link-container a { color: var(--primary-color); text-decoration: none; font-weight: 600; }
        .alert-danger { background-color: var(--danger-bg); color: var(--danger-text); border: 1px solid var(--danger-border); }

        #livenessBlinkUiArea, #smileCaptureUiArea /* BARU */ { margin-top: 15px; padding-top: 10px; /* Awalnya disembunyikan JS */ }
        /* HAPUS: .pose-challenge-section dan semua style terkait pose kiri/kanan */
    </style>
</head>
<body>
<div class="register-container"> <div class="card card-register"> <div class="card-register-header text-center"><i class="bi bi-person-plus-fill"></i> Buat Akun Pemilih Baru</div>
<div class="card-register-body">
<?php if (!empty($errors_register)): ?> <div class="alert alert-danger" role="alert"> <div class="d-flex"> <div class="flex-shrink-0"><i class="bi bi-exclamation-triangle-fill"></i></div> <div class="flex-grow-1 ms-2"> <span class="fw-bold">Pendaftaran Gagal:</span> <ul> <?php foreach ($errors_register as $err_msg): ?> <li><?= $err_msg ?></li> <?php endforeach; ?> </ul> </div> </div> </div> <?php endif; ?>
<form method="POST" action="register.php" id="formRegisterUser">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()); ?>">
<input type="hidden" name="foto_wajah_filename" id="fotoWajahFilenameInputEl">
<input type="hidden" name="foto_wajah_senyum_filename" id="fotoWajahSenyumFilenameInputEl"> <div class="mb-3"> <label for="usernameRegisterInput" class="form-label">Username <span class="text-danger">*</span></label> <input name="username" type="text" class="form-control" id="usernameRegisterInput" placeholder="Buat username unik (min. 4 karakter, huruf, angka, _)" required value="<?= htmlspecialchars($input_username_register) ?>" pattern="^[a-zA-Z0-9_]{4,}$" title="Minimal 4 karakter, hanya huruf, angka, dan underscore."> </div>
<div class="mb-3"> <label for="namaLengkapRegisterInput" class="form-label">Nama Lengkap <span class="text-danger">*</span></label> <input name="nama_lengkap" type="text" class="form-control" id="namaLengkapRegisterInput" placeholder="Nama sesuai identitas Anda" required value="<?= htmlspecialchars($input_nama_lengkap_register) ?>"> </div>
<div class="mb-3"> <label for="passwordRegisterInput" class="form-label">Password <span class="text-danger">*</span></label> <input name="password" type="password" class="form-control" id="passwordRegisterInput" placeholder="Buat password yang kuat" required> <div class="password-strength-info">Min. 8 karakter, harus ada huruf besar, huruf kecil, angka, dan simbol.</div> </div>
<div class="mb-3"> <label for="passwordConfirmRegisterInput" class="form-label">Konfirmasi Password <span class="text-danger">*</span></label> <input name="password_confirm" type="password" class="form-control" id="passwordConfirmRegisterInput" placeholder="Ulangi password Anda" required> </div>

<div class="camera-area-register">
    <label class="form-label fw-bold">Verifikasi Wajah Lengkap <span class="text-danger">*</span></label>
    <div id="cameraDisplayArea"> <div class="camera-preview-wrapper"> <video id="cameraPreviewRegisterEl" autoplay muted playsinline></video> <div id="faceOverlayEl"></div> </div> <canvas id="captureCanvasRegisterEl"></canvas> </div>
    <button type="button" class="btn btn-outline-primary btn-sm mb-3" id="btnStartCameraRegisterEl"><i class="bi bi-camera-video"></i> Mulai Kamera</button>
    <hr>
    <div id="livenessBlinkUiArea" style="display: none;">
        <p class="fw-bold">Tahap 1: Verifikasi Keaktifan (Kedipan Mata)</p>
        <div id="photoTakingInstructionEl" class="small mb-2"></div>
        <button type="button" class="btn btn-success btn-sm" id="btnStartLivenessEl" disabled><i class="bi bi-record-circle-fill"></i> Mulai Verifikasi Kedipan</button>
        <div id="photoStatusRegisterEl" class="mt-2"></div>
        <div id="snapshotPreviewContainerEl" class="snapshot-preview-container"></div>
    </div>

    <div id="smileCaptureUiArea" style="display: none;"> <p class="fw-bold">Tahap 2: Verifikasi Wajah Tersenyum</p>
        <div id="smileInstructionEl" class="small mb-2">Mohon tersenyum dengan jelas ke kamera, lalu klik tombol di bawah.</div>
        <button type="button" class="btn btn-info btn-sm" id="btnCaptureSmileEl" disabled>
            <i class="bi bi-emoji-smile-fill"></i> Ambil Foto Senyum
        </button>
        <div id="smileStatusEl" class="mt-2"></div>
        <div id="smilePreviewContainerEl" class="smile-preview-container"></div> </div>
    </div>
<div class="d-grid mt-4"> <button type="submit" class="btn btn-primary btn-register-submit" id="btnSubmitRegisterEl" disabled><i class="bi bi-send-check-fill"></i> Daftar Akun</button> </div>
</form>
<div class="login-link-container"> Sudah punya akun? <a href="login.php">Login di sini</a> </div>
</div> </div> </div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const cameraPreviewEl = document.getElementById('cameraPreviewRegisterEl');
    const fotoWajahFilenameInputEl = document.getElementById('fotoWajahFilenameInputEl'); // Untuk foto utama/netral
    const fotoWajahSenyumFilenameInputEl = document.getElementById('fotoWajahSenyumFilenameInputEl'); // BARU: Untuk foto senyum

    const btnStartCameraEl = document.getElementById('btnStartCameraRegisterEl');
    const btnStartLivenessEl = document.getElementById('btnStartLivenessEl');
    const photoStatusEl = document.getElementById('photoStatusRegisterEl'); // Status untuk liveness
    const btnSubmitRegisterEl = document.getElementById('btnSubmitRegisterEl');
    const captureCanvasEl = document.getElementById('captureCanvasRegisterEl');
    const snapshotPreviewContainerEl = document.getElementById('snapshotPreviewContainerEl'); // Preview foto utama
    const photoTakingInstructionEl = document.getElementById('photoTakingInstructionEl');
    const faceOverlayEl = document.getElementById('faceOverlayEl');

    const cameraDisplayAreaEl = document.getElementById('cameraDisplayArea');
    const livenessBlinkUiAreaEl = document.getElementById('livenessBlinkUiArea');

    // BARU: Elemen untuk UI Capture Senyum
    const smileCaptureUiAreaEl = document.getElementById('smileCaptureUiArea');
    const smileInstructionEl = document.getElementById('smileInstructionEl');
    const btnCaptureSmileEl = document.getElementById('btnCaptureSmileEl');
    const smileStatusEl = document.getElementById('smileStatusEl');
    const smilePreviewContainerEl = document.getElementById('smilePreviewContainerEl');

    // URL API (pastikan PYTHON_API_BASE_URL sudah didefinisikan di PHP dan di-output ke JS)
    const pythonApiLivenessUrl = '<?= PYTHON_API_BASE_URL . "/detect_and_store_face" ?>';
    const pythonApiSmileUrl = '<?= PYTHON_API_BASE_URL . "/capture_smile_photo" ?>'; // BARU

    let currentStream = null;
    let cameraIsActive = false;
    let isProcessingLiveness = false;
    let isProcessingSmile = false; // BARU

    let livenessBlinkVerified = false;
    let smilePhotoVerified = false; // BARU

    const NUM_FRAMES_TO_CAPTURE = 10;
    const FRAME_CAPTURE_INTERVAL_MS = 300;
    let capturedFramesDataURLs = [];

    function setStatus(element, message, type = 'info') {
        element.textContent = message;
        element.className = ''; // Reset class
        // Tentukan class spesifik untuk elemen status (disesuaikan dari versi sebelumnya)
        if (element.id === 'photoStatusRegisterEl' || element.id === 'smileStatusEl') {
            element.classList.add('mt-2');
        }

        if (type === 'error') element.classList.add('status-error');
        else if (type === 'success') element.classList.add('status-success');
        else element.classList.add('status-info');
        element.style.display = 'block';
    }

    function checkAndEnableSubmitButton() {
        // Tombol submit hanya aktif jika kedua verifikasi foto berhasil
        if (livenessBlinkVerified && fotoWajahFilenameInputEl.value && smilePhotoVerified && fotoWajahSenyumFilenameInputEl.value) {
            btnSubmitRegisterEl.disabled = false;
            setStatus(smileStatusEl, "Semua verifikasi wajah selesai. Silakan lengkapi formulir dan daftar.", "success");
        } else {
            btnSubmitRegisterEl.disabled = true;
        }
    }

    function resetAllVerificationStages() {
        snapshotPreviewContainerEl.innerHTML = '';
        smilePreviewContainerEl.innerHTML = ''; // BARU

        fotoWajahFilenameInputEl.value = '';
        fotoWajahSenyumFilenameInputEl.value = ''; // BARU

        btnSubmitRegisterEl.disabled = true;
        isProcessingLiveness = false;
        isProcessingSmile = false; // BARU
        capturedFramesDataURLs = [];
        livenessBlinkVerified = false;
        smilePhotoVerified = false; // BARU

        livenessBlinkUiAreaEl.style.display = 'none';
        smileCaptureUiAreaEl.style.display = 'none'; // BARU
        btnStartLivenessEl.disabled = true;
        btnCaptureSmileEl.disabled = true; // BARU

        photoTakingInstructionEl.innerHTML = '';
        photoStatusEl.style.display = 'none';
        smileInstructionEl.innerHTML = 'Mohon tersenyum dengan jelas ke kamera, lalu klik tombol di bawah.'; // Default
        smileStatusEl.style.display = 'none'; // BARU
    }

    function resetCamera(initial = false) {
        resetAllVerificationStages();

        if (currentStream) {
            currentStream.getTracks().forEach(track => track.stop());
            currentStream = null;
            cameraIsActive = false;
        }
        if (faceOverlayEl) faceOverlayEl.style.display = 'none';
        cameraDisplayAreaEl.style.display = 'none';
        btnStartCameraEl.innerHTML = '<i class="bi bi-camera-video"></i> Mulai Kamera';
        btnStartCameraEl.disabled = false;

        if (initial) {
            photoTakingInstructionEl.innerHTML = 'Klik "Mulai Kamera" untuk mengaktifkan kamera Anda dan memulai proses verifikasi wajah.';
        } else {
             photoTakingInstructionEl.innerHTML = '';
             // setStatus(photoStatusEl, '', 'info'); photoStatusEl.style.display = 'none'; // Tidak perlu lagi
        }
    }

    resetCamera(true); // Panggil saat awal load

    btnStartCameraEl.addEventListener('click', async () => {
        if (cameraIsActive || isProcessingLiveness || isProcessingSmile) {
            resetCamera();
            photoTakingInstructionEl.innerHTML = 'Klik "Mulai Kamera" untuk mengaktifkan kamera Anda dan memulai proses verifikasi wajah.';
            return;
        }
        setStatus(photoStatusEl, 'Meminta izin kamera...', 'info');
        resetAllVerificationStages(); // Pastikan semua direset

        btnStartCameraEl.disabled = true;

        try {
            const constraints = { video: { width: { ideal: 640 }, height: { ideal: 480 }, facingMode: "user" }, audio: false };
            currentStream = await navigator.mediaDevices.getUserMedia(constraints);
            cameraDisplayAreaEl.style.display = 'block';
            cameraPreviewEl.srcObject = currentStream;
            cameraPreviewEl.onloadedmetadata = () => {
                cameraPreviewEl.play();
                cameraIsActive = true;
                livenessBlinkUiAreaEl.style.display = 'block'; // Tampilkan area verifikasi kedipan
                smileCaptureUiAreaEl.style.display = 'none'; // Sembunyikan area senyum dulu

                photoTakingInstructionEl.innerHTML = 'Kamera aktif. Posisikan wajah di <strong>bingkai panduan</strong>. Saat siap, klik "Mulai Verifikasi Kedipan" dan <strong>berkedip alami</strong>.';
                setStatus(photoStatusEl, 'Kamera siap. Lanjut ke verifikasi kedipan.', 'info');
                btnStartLivenessEl.disabled = false;
                btnStartCameraEl.innerHTML = '<i class="bi bi-camera-video-off"></i> Stop & Ulangi Semua';
                btnStartCameraEl.disabled = false;
                if (faceOverlayEl) faceOverlayEl.style.display = 'block';
            };
        } catch (err) {
            resetCamera();
            photoTakingInstructionEl.innerHTML = 'Gagal memulai kamera. Pastikan Anda telah memberikan izin akses kamera di browser Anda.';
            setStatus(photoStatusEl, `Error kamera: ${err.name}. Periksa izin.`, 'error');
        }
    });

    btnStartLivenessEl.addEventListener('click', async () => {
        // ... (Logika btnStartLivenessEl SAMA SEPERTI SEBELUMNYA, sampai bagian `response_data["filename"]`) ...
        // Yang berbeda adalah apa yang terjadi SETELAH LIVENESS BERHASIL
        if (!cameraIsActive || !currentStream || !currentStream.active || isProcessingLiveness) {
            setStatus(photoStatusEl, 'Kamera tidak aktif atau proses lain sedang berjalan.', 'error'); return;
        }
        const usernameFromInput = document.getElementById('usernameRegisterInput').value;
        if (!usernameFromInput) {
            setStatus(photoStatusEl, 'Username wajib diisi sebelum memulai verifikasi wajah.', 'error'); return;
        }
        isProcessingLiveness = true; capturedFramesDataURLs = [];
        btnStartLivenessEl.disabled = true; btnStartCameraEl.disabled = true;
        snapshotPreviewContainerEl.innerHTML = ''; fotoWajahFilenameInputEl.value = ''; livenessBlinkVerified = false;
        checkAndEnableSubmitButton(); // Reset tombol submit

        photoTakingInstructionEl.innerHTML = `SIAP-SIAP! Perekaman untuk verifikasi kedipan akan dimulai. Lihat ke kamera dan <strong>BERKEDIPLAH SECARA ALAMI</strong> beberapa kali selama ${ (NUM_FRAMES_TO_CAPTURE * FRAME_CAPTURE_INTERVAL_MS) / 1000 } detik.`;
        setStatus(photoStatusEl, 'Bersiap untuk perekaman kedipan...', 'info');
        await new Promise(resolve => setTimeout(resolve, 1500));
        if (!cameraIsActive || !currentStream || !currentStream.active) { resetCamera(); setStatus(photoStatusEl, 'Perekaman dibatalkan, kamera tidak aktif.', 'error'); return; }

        for (let i = 0; i < NUM_FRAMES_TO_CAPTURE; i++) {
            if (!cameraIsActive || !currentStream || !currentStream.active) { setStatus(photoStatusEl, 'Perekaman dihentikan, kamera tidak aktif.', 'error'); isProcessingLiveness = false; btnStartCameraEl.disabled = false; return; }
            photoTakingInstructionEl.innerHTML = `Merekam Kedipan... Frame ${i + 1}/${NUM_FRAMES_TO_CAPTURE}. <strong>Terus berkedip alami!</strong>`;
            setStatus(photoStatusEl, `Merekam frame ${i + 1}/${NUM_FRAMES_TO_CAPTURE}...`, 'info');
            captureCanvasEl.width = cameraPreviewEl.videoWidth; captureCanvasEl.height = cameraPreviewEl.videoHeight;
            const context = captureCanvasEl.getContext('2d');
            context.drawImage(cameraPreviewEl, 0, 0, captureCanvasEl.width, captureCanvasEl.height);
            capturedFramesDataURLs.push(captureCanvasEl.toDataURL('image/jpeg', 0.8));
            if (i < NUM_FRAMES_TO_CAPTURE - 1) await new Promise(resolve => setTimeout(resolve, FRAME_CAPTURE_INTERVAL_MS));
        }
        if (capturedFramesDataURLs.length < NUM_FRAMES_TO_CAPTURE) { setStatus(photoStatusEl, 'Perekaman kedipan tidak lengkap. Coba lagi.', 'error'); isProcessingLiveness = false; btnStartCameraEl.disabled = false; if (cameraIsActive) btnStartLivenessEl.disabled = false; return; }

        photoTakingInstructionEl.innerHTML = 'Perekaman kedipan selesai. Mengirim data ke server...';
        setStatus(photoStatusEl, 'Memvalidasi kedipan mata...', 'info');

        const formDataBlink = new FormData();
        formDataBlink.append('frames_base64_json', JSON.stringify(capturedFramesDataURLs));
        formDataBlink.append('username', usernameFromInput);

        try {
            const response = await fetch(pythonApiLivenessUrl, { method: 'POST', body: new URLSearchParams(formDataBlink) }); // Kirim sebagai x-www-form-urlencoded
            if (!response.ok) {
                const errorText = await response.text(); let Rmessage = `API Error (${response.status})`;
                try { const errJson = JSON.parse(errorText); if (errJson && errJson.message) Rmessage = `Validasi Kedipan Gagal (${response.status}): ${errJson.message}`; } catch (e) {}
                throw new Error(Rmessage);
            }
            const resultBlink = await response.json();
            if (resultBlink.success && resultBlink.filename) {
                livenessBlinkVerified = true;
                fotoWajahFilenameInputEl.value = resultBlink.filename; // Simpan nama file foto utama
                if (resultBlink.best_frame_base64) {
                    snapshotPreviewContainerEl.innerHTML = `<p class="small mt-2 text-center">Kedipan Terverifikasi (Foto Utama):</p><img src="${resultBlink.best_frame_base64}" alt="Preview Foto Wajah Utama Anda">`;
                } else if (capturedFramesDataURLs.length > 0) { // Fallback jika API tidak kirim best_frame
                    snapshotPreviewContainerEl.innerHTML = `<p class="small mt-2 text-center">Kedipan Terverifikasi (Contoh Frame):</p><img src="${capturedFramesDataURLs[0]}" alt="Preview Foto Wajah Anda">`;
                }
                setStatus(photoStatusEl, `${resultBlink.message || 'Verifikasi kedipan berhasil.'} Lanjut ke tahap foto senyum.`, 'success');

                // MODIFIKASI DI SINI: Pindah ke tahap capture senyum
                livenessBlinkUiAreaEl.style.display = 'none'; // Sembunyikan UI kedipan
                smileCaptureUiAreaEl.style.display = 'block'; // Tampilkan UI senyum
                btnCaptureSmileEl.disabled = false; // Aktifkan tombol capture senyum
                if (faceOverlayEl) faceOverlayEl.style.display = 'block'; // Pastikan overlay tetap ada jika diperlukan
                setStatus(smileStatusEl, "Silakan tersenyum ke kamera dan klik tombol 'Ambil Foto Senyum'.", "info");

            } else { // Liveness gagal
                livenessBlinkVerified = false;
                snapshotPreviewContainerEl.innerHTML = ''; fotoWajahFilenameInputEl.value = '';
                setStatus(photoStatusEl, `Verifikasi Kedipan Gagal: ${resultBlink.message || 'Tidak ada detail.'}`, 'error');
                photoTakingInstructionEl.innerHTML = 'Verifikasi kedipan gagal. Coba lagi atau klik "Stop & Ulangi Semua".';
                btnStartLivenessEl.disabled = false;
            }
        } catch (error) {
            livenessBlinkVerified = false; snapshotPreviewContainerEl.innerHTML = ''; fotoWajahFilenameInputEl.value = '';
            setStatus(photoStatusEl, `Error Verifikasi Kedipan: ${error.message || 'Tidak dapat terhubung.'}`, 'error');
            photoTakingInstructionEl.innerHTML = 'Terjadi kesalahan. Klik "Stop & Ulangi Semua" untuk mencoba lagi.';
            btnStartLivenessEl.disabled = false;
        } finally {
            isProcessingLiveness = false;
            btnStartCameraEl.disabled = false; // Selalu aktifkan tombol stop/ulangi kamera
            if (!livenessBlinkVerified && cameraIsActive) {
                 btnStartLivenessEl.disabled = false; // Aktifkan lagi tombol liveness jika gagal & kamera masih on
            }
            checkAndEnableSubmitButton(); // Cek apakah tombol submit bisa diaktifkan
        }
    });


    // BARU: Event Listener untuk Tombol Capture Senyum
    btnCaptureSmileEl.addEventListener('click', async () => {
        if (!cameraIsActive || !currentStream || !currentStream.active || isProcessingSmile) {
            setStatus(smileStatusEl, 'Kamera tidak aktif atau proses lain sedang berjalan.', 'error');
            return;
        }
        const usernameFromInput = document.getElementById('usernameRegisterInput').value;
        if (!usernameFromInput) {
            setStatus(smileStatusEl, 'Username wajib diisi sebelum mengambil foto senyum.', 'error');
            return;
        }
        if (!livenessBlinkVerified || !fotoWajahFilenameInputEl.value) {
            setStatus(smileStatusEl, 'Harap selesaikan verifikasi kedipan (foto utama) terlebih dahulu.', 'error');
            // Arahkan kembali ke tahap liveness jika belum
            livenessBlinkUiAreaEl.style.display = 'block';
            smileCaptureUiAreaEl.style.display = 'none';
            return;
        }

        isProcessingSmile = true;
        btnCaptureSmileEl.disabled = true;
        btnStartCameraEl.disabled = true; // Nonaktifkan tombol stop kamera selama proses
        smilePreviewContainerEl.innerHTML = '';
        fotoWajahSenyumFilenameInputEl.value = '';
        smilePhotoVerified = false;
        checkAndEnableSubmitButton(); // Reset tombol submit

        setStatus(smileStatusEl, 'Mengambil foto senyum...', 'info');

        // Ambil satu frame gambar
        captureCanvasEl.width = cameraPreviewEl.videoWidth;
        captureCanvasEl.height = cameraPreviewEl.videoHeight;
        const context = captureCanvasEl.getContext('2d');
        context.drawImage(cameraPreviewEl, 0, 0, captureCanvasEl.width, captureCanvasEl.height);
        const smileImageDataURL = captureCanvasEl.toDataURL('image/jpeg', 0.85); // Kualitas bisa disesuaikan

        // Tampilkan preview langsung dari frame yang diambil JS
        smilePreviewContainerEl.innerHTML = `<p class="small mt-2 text-center">Preview Foto Senyum:</p><img src="${smileImageDataURL}" alt="Preview Foto Senyum Anda">`;

        setStatus(smileStatusEl, 'Mengirim foto senyum untuk diverifikasi...', 'info');

        const formDataSmile = new FormData();
        formDataSmile.append('image_base64', smileImageDataURL);
        formDataSmile.append('username', usernameFromInput);

        try {
            const response = await fetch(pythonApiSmileUrl, { method: 'POST', body: formDataSmile }); // Perhatikan: tidak pakai URLSearchParams
            if (!response.ok) {
                // Coba baca response error dari API jika ada
                let errorMessage = `API Error (${response.status})`;
                try {
                    const errorResult = await response.json();
                    if (errorResult && errorResult.message) {
                        errorMessage = `Verifikasi Senyum Gagal (${response.status}): ${errorResult.message}`;
                    }
                } catch (e) { /* Gagal parse JSON error, biarkan error message default */ }
                throw new Error(errorMessage);
            }

            const resultSmile = await response.json();

            if (resultSmile.success && resultSmile.filename) {
                smilePhotoVerified = true;
                fotoWajahSenyumFilenameInputEl.value = resultSmile.filename; // Simpan nama file foto senyum
                setStatus(smileStatusEl, `${resultSmile.message || 'Verifikasi foto senyum berhasil!'}`, 'success');
                // Tidak perlu preview lagi karena sudah ditampilkan dari JS, atau bisa update jika API kirim balik base64 yang sudah diproses
                // smilePreviewContainerEl.innerHTML = ... (jika API kirim base64 preview)

                // Kamera bisa distop di sini karena semua tahap foto selesai, atau biarkan user yang stop
                // if (currentStream) { currentStream.getTracks().forEach(track => track.stop()); cameraIsActive = false; }
                // btnStartCameraEl.innerHTML = '<i class="bi bi-camera-video"></i> Mulai Kamera';
                // faceOverlayEl.style.display = 'none';
                // btnCaptureSmileEl.innerHTML = '<i class="bi bi-check-circle-fill"></i> Senyum Terverifikasi'; // Ganti teks tombol

            } else { // API merespons success:false atau tidak ada filename
                smilePhotoVerified = false;
                setStatus(smileStatusEl, `Verifikasi Senyum Gagal: ${resultSmile.message || 'Tidak ada detail dari API.'}. Mohon coba lagi.`, 'error');
                btnCaptureSmileEl.disabled = false; // Izinkan coba lagi
            }
        } catch (error) {
            smilePhotoVerified = false;
            setStatus(smileStatusEl, `Error Verifikasi Senyum: ${error.message || 'Tidak dapat terhubung ke API.'}. Coba lagi.`, 'error');
            btnCaptureSmileEl.disabled = false; // Izinkan coba lagi
        } finally {
            isProcessingSmile = false;
            btnStartCameraEl.disabled = false; // Aktifkan kembali tombol stop kamera
            checkAndEnableSubmitButton(); // Cek apakah tombol submit utama bisa diaktifkan
        }
    });

    // HAPUS: Logika untuk pose kiri/kanan (btnCapturePoseLeftEl, btnCapturePoseRightEl, dll.)

    window.addEventListener('beforeunload', () => {
        resetCamera();
    });
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>