<?php
// voting.php - Halaman Proses Voting (Integrasi API Python Verifikasi + Fitur Otomatis + Fallback Senyum)

require_once 'config.php'; // Memuat konfigurasi & memulai session

// --- 1. Validasi Akses, Status Vote, Jadwal ---
if (!is_login() || !isset($_SESSION['user']['role']) || $_SESSION['user']['role'] !== 'pemilih') {
    $_SESSION['flash_error_login'] = "Anda harus login sebagai pemilih untuk voting.";
    header('Location: login.php'); exit;
}

$user_id_pemilih = $_SESSION['user']['id'];
$status_sudah_vote_session = isset($_SESSION['user']['sudah_vote']) ? (int)$_SESSION['user']['sudah_vote'] : 0;
$nama_file_foto_pendaftaran_user = null;
$nama_file_foto_senyum_user = null; // BARU: Untuk menyimpan nama file foto senyum

if ($conn) {
    // Ambil foto pendaftaran DAN foto senyum dalam satu query
    $stmt_get_user_data = $conn->prepare("SELECT sudah_vote, foto_wajah_pendaftaran, foto_wajah_senyum FROM users WHERE id = ? AND role = 'pemilih'");
    if ($stmt_get_user_data) {
        $stmt_get_user_data->bind_param("i", $user_id_pemilih);
        $stmt_get_user_data->execute();
        // Tambahkan $db_foto_senyum untuk bind_result
        $stmt_get_user_data->bind_result($db_sudah_vote, $db_foto_pendaftaran, $db_foto_senyum); 
        if ($stmt_get_user_data->fetch()) {
            $status_sudah_vote_session = (int)$db_sudah_vote;
            $_SESSION['user']['sudah_vote'] = $status_sudah_vote_session;
            $nama_file_foto_pendaftaran_user = $db_foto_pendaftaran;
            $nama_file_foto_senyum_user = $db_foto_senyum; // BARU: Simpan nama file foto senyum

            if ($status_sudah_vote_session > 0) {
                $_SESSION['flash_info_dashboard'] = "Anda sudah menggunakan hak suara Anda (berdasarkan data terbaru).";
                header('Location: dashboard.php'); exit;
            }
        } else {
             error_log("Voting: User ID {$user_id_pemilih} tidak ditemukan / bukan pemilih.");
             $_SESSION['flash_error_login'] = "Data pengguna tidak valid.";
             header('Location: login.php'); exit;
        }
        $stmt_get_user_data->close();
    } else {
        error_log("Voting: Gagal prepare get user data - " . $conn->error);
        die("Terjadi kesalahan saat memverifikasi data pengguna.");
    }
} else { die("Koneksi database bermasalah."); }

// Redirect jika sudah vote (double check, bisa jadi session belum terupdate dari tab lain)
if ($status_sudah_vote_session > 0) { // Ini adalah pengecekan $db_sudah_vote yang sudah diupdate ke session
    $_SESSION['flash_info_dashboard'] = "Anda sudah menggunakan hak suara Anda.";
    header('Location: dashboard.php'); exit;
}

// Pastikan $nama_file_foto_pendaftaran_user tetap divalidasi keberadaannya
if (empty($nama_file_foto_pendaftaran_user)) {
    error_log("Voting: User ID {$user_id_pemilih} tidak memiliki foto pendaftaran utama (foto_wajah_pendaftaran).");
    $_SESSION['flash_error_dashboard'] = "Data foto pendaftaran utama Anda tidak ditemukan. Hubungi administrator.";
    header('Location: dashboard.php'); exit;
}

// Cek Jadwal Voting Aktif
$jadwal_voting_aktif = false;
$pesan_jadwal_voting = "Informasi jadwal voting tidak tersedia atau belum diatur.";
$evoting_start_str_db = get_setting('evoting_start', $conn);
$evoting_end_str_db = get_setting('evoting_end', $conn);
if (!empty($evoting_start_str_db) && !empty($evoting_end_str_db)) {
    try {
        $tz = new DateTimeZone('Asia/Jakarta'); // Sesuaikan dengan timezone server atau aplikasi Anda
        $start = new DateTime($evoting_start_str_db, $tz);
        $end = new DateTime($evoting_end_str_db, $tz);
        $now = new DateTime('now', $tz);

        if ($now >= $start && $now <= $end) {
            $jadwal_voting_aktif = true;
        } elseif ($now < $start) {
            $pesan_jadwal_voting = "Periode voting belum dimulai (Mulai: " . $start->format('d/m/Y H:i') . " WIB).";
        } else { // $now > $end
            $pesan_jadwal_voting = "Periode voting telah berakhir (Selesai: " . $end->format('d/m/Y H:i') . " WIB).";
        }
    } catch (Exception $e) {
        error_log("Voting: Error parsing jadwal - ".$e->getMessage());
        $pesan_jadwal_voting = "Error format jadwal.";
    }
}

if (!$jadwal_voting_aktif) {
    $_SESSION['flash_error_dashboard'] = $pesan_jadwal_voting;
    header('Location: dashboard.php'); exit;
}

// Ambil Data Kandidat
$daftar_kandidat_voting = [];
if ($conn) {
    $stmt_get_kdt = $conn->prepare("SELECT id, nama, jurusan, foto, visi_misi FROM kandidat ORDER BY nama ASC");
    if ($stmt_get_kdt) {
        $stmt_get_kdt->execute();
        $result_kdt = $stmt_get_kdt->get_result();
        while ($kdt_row = $result_kdt->fetch_assoc()) {
            $daftar_kandidat_voting[] = $kdt_row;
        }
        $stmt_get_kdt->close();
    } else {
        // Handle error jika prepare statement gagal
        error_log("Voting: Gagal prepare statement ambil kandidat - " . $conn->error);
        die("Terjadi kesalahan saat memuat data kandidat.");
    }
}

// --- 4. Proses Submit Voting ---
$error_submit_vote = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_vote_action'])) {
    if (!validate_csrf_token()) {
        $error_submit_vote = "Sesi tidak valid. Ulangi proses voting.";
    } else {
        $id_kandidat_dipilih = filter_input(INPUT_POST, 'kandidat_id_final', FILTER_VALIDATE_INT);
        $foto_wajah_lolos_verifikasi_base64 = $_POST['foto_wajah_saat_vote_data'] ?? '';

        if (!$id_kandidat_dipilih || $id_kandidat_dipilih <= 0) {
            $error_submit_vote = "Pilihan kandidat tidak valid.";
        } elseif (empty($foto_wajah_lolos_verifikasi_base64)) {
            $error_submit_vote = "Data verifikasi foto wajah tidak lengkap.";
        } else {
            // Cek ulang status vote
            $stmt_cek_final = $conn->prepare("SELECT COUNT(*) FROM voting WHERE user_id = ?");
            $stmt_cek_final->bind_param("i", $user_id_pemilih);
            $stmt_cek_final->execute();
            $stmt_cek_final->bind_result($count_final);
            $stmt_cek_final->fetch();
            $stmt_cek_final->close();

            if ($count_final > 0) {
                $_SESSION['user']['sudah_vote'] = 1; // Pastikan session juga terupdate
                $_SESSION['flash_info_dashboard'] = "Anda sudah tercatat melakukan voting sebelumnya.";
                header('Location: dashboard.php'); exit;
            }

            // Simpan foto wajah saat voting
            $nama_file_foto_saat_vote_db = null;
            $path_simpan_foto_saat_vote = null;
            if (!is_writable(UPLOAD_FOTO_SAAT_VOTE_DIR)) {
                 $error_submit_vote = "Error: Server tidak bisa menulis file foto voting.";
                 error_log("Voting Submit: Direktori UPLOAD_FOTO_SAAT_VOTE_DIR tidak writable.");
            } else {
                if (preg_match('/^data:image\/(png|jpeg|jpg);base64,/', $foto_wajah_lolos_verifikasi_base64, $type_m)) {
                    $ext = $type_m[1];
                    $data = substr($foto_wajah_lolos_verifikasi_base64, strpos($foto_wajah_lolos_verifikasi_base64, ',') + 1);
                    $data = base64_decode($data);
                    if ($data === false) { $error_submit_vote = "Error: Gagal decode foto voting."; }
                    else {
                        $nama_file_foto_saat_vote_db = 'vote_wajah_' . $user_id_pemilih . '_' . uniqid() . '.' . $ext;
                        $path_simpan_foto_saat_vote = UPLOAD_FOTO_SAAT_VOTE_DIR . $nama_file_foto_saat_vote_db;
                        if (file_put_contents($path_simpan_foto_saat_vote, $data) === false) {
                            $error_submit_vote = "Error: Gagal simpan file foto voting.";
                            $nama_file_foto_saat_vote_db = null; // Gagalkan jika file tidak tersimpan
                        }
                    }
                } else { $error_submit_vote = "Error: Format data foto voting tidak valid."; }
            }

            // Lanjutkan ke DB jika tidak ada error sebelumnya DAN foto berhasil disimpan
            if (empty($error_submit_vote) && $nama_file_foto_saat_vote_db !== null) {
                $conn->begin_transaction();
                try {
                    // 1. Insert ke tabel voting
                    $stmt_ins = $conn->prepare("INSERT INTO `voting` (`user_id`, `kandidat_id`, `foto_saat_vote`) VALUES (?, ?, ?)");
                    if ($stmt_ins === false) {
                        throw new Exception("MySQL Prepare Error (INSERT voting): " . $conn->error);
                    }
                    $stmt_ins->bind_param("iis", $user_id_pemilih, $id_kandidat_dipilih, $nama_file_foto_saat_vote_db);
                    if (!$stmt_ins->execute()) throw new Exception("Gagal execute insert data voting: " . $stmt_ins->error);
                    $stmt_ins->close();

                    // 2. Update status 'sudah_vote' di tabel 'users'
                    $stmt_upd_u = $conn->prepare("UPDATE `users` SET `sudah_vote` = 1 WHERE `id` = ?");
                    if ($stmt_upd_u === false) {
                        throw new Exception("MySQL Prepare Error (UPDATE users): " . $conn->error);
                    }
                    $stmt_upd_u->bind_param("i", $user_id_pemilih);
                    if (!$stmt_upd_u->execute()) throw new Exception("Gagal execute update status user: " . $stmt_upd_u->error);
                    $stmt_upd_u->close();

                    // 3. Update jumlah suara di tabel 'kandidat'
                    $stmt_upd_k = $conn->prepare("UPDATE `kandidat` SET `jumlah_suara` = `jumlah_suara` + 1 WHERE `id` = ?");
                    if ($stmt_upd_k === false) {
                         throw new Exception("MySQL Prepare Error (UPDATE kandidat): " . $conn->error);
                    }
                    $stmt_upd_k->bind_param("i", $id_kandidat_dipilih);
                    if (!$stmt_upd_k->execute()) throw new Exception("Gagal execute update suara kandidat: " . $stmt_upd_k->error);
                    $stmt_upd_k->close();

                    $conn->commit();
                    $_SESSION['user']['sudah_vote'] = 1; // Update session juga
                    $_SESSION['flash_success_dashboard'] = "Terima kasih! Suara Anda telah berhasil direkam.";
                    header('Location: dashboard.php'); exit;

                } catch (Exception $e) {
                    $conn->rollback();
                    $error_submit_vote = "Terjadi kesalahan teknis: " . htmlspecialchars($e->getMessage());
                    error_log("Voting Submit Transaksi Gagal: " . $e->getMessage());
                    if ($path_simpan_foto_saat_vote && file_exists($path_simpan_foto_saat_vote)) {
                       @unlink($path_simpan_foto_saat_vote);
                       error_log("Voting Submit: File foto '{$nama_file_foto_saat_vote_db}' dihapus karena transaksi DB gagal.");
                    }
                }
            }
        }
    }
}

define('PYTHON_API_URL_VOTING', 'http://localhost:5000/api');
require 'template/header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
    body { font-family: 'Inter', Arial, sans-serif; background-color: #f0f2f5; }
    .voting-main-container { max-width: 1000px; margin: 1.5rem auto; padding: 0 1rem; }
    .voting-page-title { text-align: center; font-weight: 700; color: var(--primary-color, #4f46e5); margin-bottom: 2rem; font-size: 2rem; }
    .face-verification-step { background-color: #ffffff; padding: 2rem; border-radius: 12px; box-shadow: 0 6px 20px rgba(0,0,0,0.07); margin-bottom: 2.5rem; text-align: center; }
    .face-verification-step h4 { font-weight: 600; color: #333; margin-bottom: 0.75rem; }
    .face-verification-step p.instruction { color: #555; margin-bottom: 1.5rem; font-size: 0.95rem; }
    #cameraPreviewVotingEl { width: 100%; max-width: 320px; height: auto; aspect-ratio: 4 / 3; border-radius: 8px; background-color: #333; margin: 0 auto 1rem auto; display: block; border: 3px solid #e0e0e0; }
    #captureCanvasVotingEl { display: none; }
    #photoStatusVotingEl { font-style: italic; color: #6c757d; margin-top: 0.75rem; min-height: 22px; font-size: 0.9rem;}
    #verificationApiErrorEl { font-weight: bold; color: #dc3545; }
    #verificationSpinner, #autoVerificationSpinner { display: none; margin-right: 5px;}
    .snapshot-preview-voting img { max-width: 120px; max-height: 90px; border:1px solid #ccc; border-radius:5px; margin-top:0.5rem;}
    .candidate-selection-step { display: none; }
    .kandidat-grid-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.8rem; }
    .kandidat-card-vote { background: #ffffff; border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.06); padding: 1.5rem; text-align: center; display: flex; flex-direction: column; transition: transform 0.2s ease, box-shadow 0.2s ease; }
    .kandidat-card-vote:hover { transform: translateY(-6px); box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
    .kandidat-card-vote .kandidat-photo-vote { width: 100px; height: 100px; object-fit: cover; border-radius: 50%; margin: 0 auto 1rem auto; border: 4px solid var(--primary-color, #4f46e5); padding: 3px; background-color: #e9ecef; }
    .kandidat-card-vote .kandidat-name-vote { font-size: 1.2rem; font-weight: 600; color: #212529; margin-bottom: 0.25rem; }
    .kandidat-card-vote .kandidat-jurusan-vote { color: #6c757d; font-size: 0.9rem; margin-bottom: 0.75rem; }
    .kandidat-card-vote .kandidat-visimisi-vote { font-size: 0.85rem; color: #495057; margin-bottom: 1rem; text-align: left; background-color: #f8f9fa; border-radius: 8px; padding: 0.75rem; white-space: pre-line; overflow-y: auto; max-height: 100px; flex-grow: 1; border: 1px solid #e9ecef; }
    .kandidat-card-vote .btn-pilih-kandidat { font-size: 1rem; padding: 0.6rem 1.2rem; border-radius: 50px; font-weight: 600; margin-top: auto; width: 90%; margin-left: auto; margin-right: auto; }
    .modal-vote-confirm { display: none; position: fixed; z-index: 2050; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.55); backdrop-filter: blur(4px); align-items: center; justify-content: center; opacity: 0; transition: opacity 0.25s ease; }
    .modal-vote-confirm.show { display: flex; opacity: 1; }
    .modal-content-vote-confirm { background: #fff; border-radius: 15px; padding: 2rem; box-shadow: 0 8px 25px rgba(0,0,0,0.2); text-align: center; max-width: 420px; width: 90%; margin: 1rem; transform: scale(0.95); transition: transform 0.25s ease; }
    .modal-vote-confirm.show .modal-content-vote-confirm { transform: scale(1); }
    .modal-content-vote-confirm .icon-confirm { font-size: 2.8rem; color: var(--primary-color); margin-bottom: 1rem; }
    .modal-content-vote-confirm h5 { font-weight: 600; margin-bottom: 0.75rem; }
    .modal-content-vote-confirm #modalTextConfirmVote { margin-bottom: 1.5rem; color: #495057; font-size: 1.05rem; }
    .modal-content-vote-confirm .btn { min-width: 120px; margin: 0 0.5rem; border-radius: 50px; padding: 0.7rem 1.3rem; font-weight: 600; }
    .alert-voting-page { margin-bottom: 1.5rem; }
    #btnCaptureAndVerifyFaceEl { display: none; }
</style>

<div class="voting-main-container">
    <div class="voting-page-title"><i class="bi bi-pencil-square"></i> Halaman Pemilihan Suara</div>

    <?php if (!empty($error_submit_vote)): ?>
        <div class="alert alert-danger alert-dismissible fade show alert-voting-page" role="alert">
            <i class="bi bi-exclamation-octagon-fill me-2"></i> Voting Gagal: <?= htmlspecialchars($error_submit_vote) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="face-verification-step" id="faceVerificationStepContainer">
        <h4><i class="bi bi-shield-check"></i> Verifikasi Wajah untuk Voting</h4>
        <p class="instruction" id="votingInstructionEl">Posisikan wajah Anda di depan kamera. Sistem akan mencoba verifikasi secara otomatis setelah kamera aktif.</p>
        <video id="cameraPreviewVotingEl" autoplay muted playsinline></video>
        <canvas id="captureCanvasVotingEl"></canvas>
        <div class="btn-group mt-2" role="group">
            <button type="button" class="btn btn-outline-primary" id="btnStartCameraVotingEl"><i class="bi bi-camera-video"></i> Mulai Kamera</button>
            <button type="button" class="btn btn-success" id="btnCaptureAndVerifyFaceEl">
                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true" id="verificationSpinner"></span>
                <i class="bi bi-person-bounding-box"></i> Verifikasi Manual
            </button>
        </div>
        <div id="photoStatusVotingEl" class="mt-2">
             <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true" id="autoVerificationSpinner"></span>
             <span id="autoVerificationStatusText">Kamera belum aktif.</span>
        </div>
        <div id="snapshotPreviewVotingContainerEl" class="snapshot-preview-voting"></div>
        <div id="verificationApiErrorEl" class="text-danger mt-2 small"></div>
    </div>

    <div class="candidate-selection-step" id="candidateSelectionStepContainer">
        <h4 class="text-center mb-3" style="color: #333;">Verifikasi Berhasil! Silakan Pilih Kandidat:</h4>
        <?php if (empty($daftar_kandidat_voting)): ?>
             <div class="alert alert-warning text-center" role="alert"> Belum ada data kandidat.</div>
        <?php else: ?>
            <div class="kandidat-grid-container">
                <?php foreach ($daftar_kandidat_voting as $kandidat_item_vote):
                        $path_foto_kdt_vote_server = UPLOAD_FOTO_KANDIDAT_DIR . basename($kandidat_item_vote['foto'] ?? '');
                        $url_foto_kdt_vote_web = BASE_URL . 'uploads/kandidat_foto/' . basename($kandidat_item_vote['foto'] ?? '');
                        // Ganti dengan path default user image Anda jika ada, atau biarkan seperti ini jika tidak ada
                        $gambar_kdt_display_vote = (!empty($kandidat_item_vote['foto']) && file_exists($path_foto_kdt_vote_server)) ? $url_foto_kdt_vote_web : BASE_URL . 'assets/img/default-kandidat.png'; 
                ?>
                <div class="kandidat-card-vote">
                    <img src="<?= htmlspecialchars($gambar_kdt_display_vote) ?>" class="kandidat-photo-vote" alt="Foto <?= htmlspecialchars($kandidat_item_vote['nama']) ?>">
                    <div class="kandidat-name-vote"><?= htmlspecialchars($kandidat_item_vote['nama']) ?></div>
                    <div class="kandidat-jurusan-vote"><?= htmlspecialchars($kandidat_item_vote['jurusan']) ?></div>
                    <div class="kandidat-visimisi-vote"><strong>Visi & Misi:</strong><br><?= nl2br(htmlspecialchars($kandidat_item_vote['visi_misi'])) ?></div>
                    <button class="btn btn-primary btn-pilih-kandidat" data-kandidatid="<?= (int)$kandidat_item_vote['id'] ?>" data-kandidatnama="<?= htmlspecialchars($kandidat_item_vote['nama'], ENT_QUOTES) ?>">
                       <i class="bi bi-check-circle-fill me-1"></i> Pilih Kandidat Ini
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal-vote-confirm" id="modalConfirmVoteChoice">
    <div class="modal-content-vote-confirm">
        <div class="icon-confirm"><i class="bi bi-person-check-fill"></i></div>
        <h5>Konfirmasi Pilihan Suara Anda</h5>
        <div id="modalTextConfirmVote"></div>
        <form method="post" id="formSubmitActualVote" action="voting.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()); ?>">
            <input type="hidden" name="kandidat_id_final" id="inputKandidatIdFinalVote">
            <input type="hidden" name="foto_wajah_saat_vote_data" id="inputFotoWajahSaatVoteFinal">
            <input type="hidden" name="submit_vote_action" value="1">
            <div class="d-flex justify-content-center mt-4">
                <button type="submit" class="btn btn-success" id="btnConfirmAndSubmitVote">
                    <span id="btnTextConfirmVote"><i class="bi bi-send-check-fill"></i> Ya, Kirim Suara Saya</span>
                    <span id="btnSpinnerConfirmVote" class="spinner-border spinner-border-sm" role="status" aria-hidden="true" style="display: none;"></span>
                </button>
                <button type="button" class="btn btn-outline-secondary ms-2" id="btnCancelConfirmVote">Tidak, Batalkan</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Variabel global untuk data dari PHP
    const registeredFacePhotoFilename = <?= json_encode($nama_file_foto_pendaftaran_user) ?>;
    const registeredSmilePhotoFilename = <?= json_encode($nama_file_foto_senyum_user) ?>; // BARU DITAMBAHKAN
    const pythonApiVerifyEndpoint = <?= json_encode(PYTHON_API_URL_VOTING . '/verify_face_match') ?>;

    // Variabel untuk logika verifikasi otomatis
    const MAX_AUTO_ATTEMPTS = 3;
    const AUTO_ATTEMPT_INTERVAL_MS = 2000;
    let autoAttemptCount = 0;
    let autoVerificationIntervalId = null;
    let isAutoVerifying = false;
</script>

<script>
// SELURUH BLOK JAVASCRIPT DI BAWAH INI AKAN KITA MODIFIKASI DI LANGKAH SELANJUTNYA
// UNTUK SAAT INI, BIARKAN SEPERTI VERSI SEBELUMNYA YANG SUDAH ADA LOGIKA VERIFIKASI OTOMATISNYA.
// Perubahan hanya pada cara kita memanggil API (akan mengirimkan registeredSmilePhotoFilename juga).

document.addEventListener('DOMContentLoaded', function () {
    const faceVerificationContainerEl = document.getElementById('faceVerificationStepContainer');
    const cameraPreviewVotingEl = document.getElementById('cameraPreviewVotingEl');
    const captureCanvasVotingEl = document.getElementById('captureCanvasVotingEl');
    const btnStartCameraVotingEl = document.getElementById('btnStartCameraVotingEl');
    const btnCaptureAndVerifyFaceEl = document.getElementById('btnCaptureAndVerifyFaceEl');
    const photoStatusVotingEl = document.getElementById('photoStatusVotingEl');
    const autoVerificationSpinnerEl = document.getElementById('autoVerificationSpinner'); 
    const autoVerificationStatusTextEl = document.getElementById('autoVerificationStatusText');
    const votingInstructionEl = document.getElementById('votingInstructionEl'); 

    const snapshotPreviewVotingContainerEl = document.getElementById('snapshotPreviewVotingContainerEl');
    const verificationApiErrorEl = document.getElementById('verificationApiErrorEl');
    const verificationSpinnerEl = document.getElementById('verificationSpinner');
    const inputFotoWajahSaatVoteFinalEl = document.getElementById('inputFotoWajahSaatVoteFinal');
    const candidateSelectionContainerEl = document.getElementById('candidateSelectionStepContainer');
    const modalConfirmVoteChoiceEl = document.getElementById('modalConfirmVoteChoice');
    const modalTextConfirmVoteEl = document.getElementById('modalTextConfirmVote');
    const inputKandidatIdFinalVoteEl = document.getElementById('inputKandidatIdFinalVote');
    const btnConfirmAndSubmitVoteEl = document.getElementById('btnConfirmAndSubmitVote');
    const btnCancelConfirmVoteEl = document.getElementById('btnCancelConfirmVote');
    const btnTextConfirmVoteEl = document.getElementById('btnTextConfirmVote');
    const btnSpinnerConfirmVoteEl = document.getElementById('btnSpinnerConfirmVote');
    const formSubmitActualVoteEl = document.getElementById('formSubmitActualVote');

    let currentVotingStream = null;
    let cameraVotingIsActive = false;
    let capturedAndVerifiedFotoWajah = null;

    function stopCameraStream() {
        if (currentVotingStream) {
            currentVotingStream.getTracks().forEach(track => track.stop());
            currentVotingStream = null;
        }
        cameraVotingIsActive = false;
        btnStartCameraVotingEl.innerHTML = '<i class="bi bi-camera-video"></i> Mulai Kamera';
        btnStartCameraVotingEl.disabled = false;
        if (autoVerificationIntervalId) {
            clearInterval(autoVerificationIntervalId);
            autoVerificationIntervalId = null;
        }
        isAutoVerifying = false;
        autoVerificationSpinnerEl.style.display = 'none';
    }

    async function performVerificationAttempt(isManualAttempt = false) {
        if (!cameraVotingIsActive || !currentVotingStream || !currentVotingStream.active) {
            if(isManualAttempt) setStatusUpdate('Kamera tidak aktif.', 'error');
            else autoVerificationStatusTextEl.textContent = 'Kamera tidak aktif untuk verifikasi otomatis.';
            return false;
        }
        if (!registeredFacePhotoFilename) { // Pengecekan utama tetap pada foto pendaftaran
            if(isManualAttempt) setStatusUpdate('Error: Data foto pendaftaran tidak ditemukan.', 'error');
            else autoVerificationStatusTextEl.textContent = 'Error: Foto pendaftaran tidak ada.';
            return false;
        }

        if (isManualAttempt) {
            verificationSpinnerEl.style.display = 'inline-block';
            btnCaptureAndVerifyFaceEl.disabled = true;
            btnStartCameraVotingEl.disabled = true;
            setStatusUpdate('Mengambil foto untuk verifikasi manual...', 'info', false);
        }
        verificationApiErrorEl.textContent = '';

        captureCanvasVotingEl.width = cameraPreviewVotingEl.videoWidth;
        captureCanvasVotingEl.height = cameraPreviewVotingEl.videoHeight;
        const context = captureCanvasVotingEl.getContext('2d');
        context.drawImage(cameraPreviewVotingEl, 0, 0, captureCanvasVotingEl.width, captureCanvasVotingEl.height);
        const voteImageDataURL = captureCanvasVotingEl.toDataURL('image/jpeg', 0.85);

        if (isManualAttempt) {
            snapshotPreviewVotingContainerEl.innerHTML = `<img src="${voteImageDataURL}" alt="Preview Foto Verifikasi Manual">`;
        }

        const formData = new FormData();
        formData.append('image_vote_base64', voteImageDataURL);
        formData.append('registered_filename', registeredFacePhotoFilename);
        // BARU: Kirim nama file foto senyum jika ada
        if (registeredSmilePhotoFilename) {
            formData.append('registered_smile_filename', registeredSmilePhotoFilename);
        }

        try {
            const response = await fetch(pythonApiVerifyEndpoint, { method: 'POST', body: formData });
            if (!response.ok) {
                const errorText = await response.text();
                let apiMessage = `API Verifikasi (${response.status}): ${response.statusText}`;
                 try { const errJson = JSON.parse(errorText); if(errJson && errJson.message) apiMessage = errJson.message; } catch(e){}
                throw new Error(apiMessage);
            }
            const result = await response.json();

            if (result.success && result.match) {
                setStatusUpdate('Verifikasi wajah berhasil!', 'success', false);
                capturedAndVerifiedFotoWajah = voteImageDataURL;
                inputFotoWajahSaatVoteFinalEl.value = capturedAndVerifiedFotoWajah;
                faceVerificationContainerEl.style.display = 'none';
                candidateSelectionContainerEl.style.display = 'block';
                stopCameraStream();
                return true;
            } else {
                const message = result.message || "Verifikasi wajah gagal. Wajah tidak cocok atau kualitas foto kurang baik.";
                throw new Error(message);
            }
        } catch (error) {
            console.error('Error calling verification API:', error);
            if (isManualAttempt) {
                setStatusUpdate(`Verifikasi Gagal: ${error.message}. Coba lagi.`, 'error', false);
            } else {
                autoVerificationStatusTextEl.textContent = `Percobaan Otomatis Gagal: ${error.message.substring(0, 50)}...`;
            }
            verificationApiErrorEl.textContent = `Error: ${error.message}`;
            return false;
        } finally {
            if (isManualAttempt) {
                verificationSpinnerEl.style.display = 'none';
                btnCaptureAndVerifyFaceEl.disabled = false;
                btnStartCameraVotingEl.disabled = false;
            }
        }
    }

    function startAutomaticVerificationLoop() {
        if (!cameraVotingIsActive) return;
        if (autoVerificationIntervalId) clearInterval(autoVerificationIntervalId);

        autoAttemptCount = 0;
        isAutoVerifying = false;
        btnCaptureAndVerifyFaceEl.style.display = 'none';
        verificationApiErrorEl.textContent = '';
        votingInstructionEl.textContent = "Sistem sedang mencoba verifikasi wajah Anda secara otomatis. Mohon tunggu...";

        autoVerificationIntervalId = setInterval(async () => {
            if (!cameraVotingIsActive || capturedAndVerifiedFotoWajah) {
                stopCameraStream();
                return;
            }
            if (isAutoVerifying) return;

            autoAttemptCount++;
            if (autoAttemptCount > MAX_AUTO_ATTEMPTS) {
                stopCameraStream();
                setStatusUpdate(`Verifikasi otomatis gagal setelah ${MAX_AUTO_ATTEMPTS} percobaan. Silakan coba verifikasi manual.`, 'warning', true);
                btnCaptureAndVerifyFaceEl.style.display = 'inline-block';
                votingInstructionEl.textContent = "Verifikasi otomatis tidak berhasil. Klik tombol 'Verifikasi Manual' saat Anda siap.";
                return;
            }

            isAutoVerifying = true;
            setStatusUpdate(`Mencoba verifikasi otomatis (${autoAttemptCount}/${MAX_AUTO_ATTEMPTS})...`, 'info', true);
            const success = await performVerificationAttempt(false);
            isAutoVerifying = false;
        }, AUTO_ATTEMPT_INTERVAL_MS);
    }

    btnStartCameraVotingEl.addEventListener('click', async () => {
        if (cameraVotingIsActive) {
            stopCameraStream();
            setStatusUpdate('Kamera dimatikan. Klik "Mulai Kamera" untuk mencoba lagi.', 'info', false);
            btnCaptureAndVerifyFaceEl.style.display = 'none';
            votingInstructionEl.textContent = "Posisikan wajah Anda di depan kamera. Sistem akan mencoba verifikasi secara otomatis setelah kamera aktif.";
            snapshotPreviewVotingContainerEl.innerHTML = '';
            verificationApiErrorEl.textContent = '';
            return;
        }

        snapshotPreviewVotingContainerEl.innerHTML = ''; verificationApiErrorEl.textContent = '';
        btnCaptureAndVerifyFaceEl.style.display = 'none';
        setStatusUpdate('Meminta izin kamera...', 'info', true);

        try {
            const constraints = { video: { width: { ideal: 640 }, height: { ideal: 480 } }, audio: false };
            currentVotingStream = await navigator.mediaDevices.getUserMedia(constraints);
            cameraPreviewVotingEl.srcObject = currentVotingStream;
            await cameraPreviewVotingEl.play();
            cameraVotingIsActive = true;
            setStatusUpdate('Kamera aktif. Verifikasi otomatis dimulai...', 'info', true);
            btnStartCameraVotingEl.innerHTML = '<i class="bi bi-camera-video-off"></i> Stop Kamera & Ulangi';
            btnStartCameraVotingEl.disabled = false;
            startAutomaticVerificationLoop();
        } catch (err) {
            console.error('Error starting camera for voting:', err);
            setStatusUpdate(`Error kamera: ${err.name}. ${err.message}`, 'error', false);
            verificationApiErrorEl.textContent = `Gagal memulai kamera. Harap izinkan akses dan coba lagi.`;
            btnStartCameraVotingEl.innerHTML = '<i class="bi bi-camera-video"></i> Mulai Kamera';
        }
    });

    btnCaptureAndVerifyFaceEl.addEventListener('click', async () => {
        if (isAutoVerifying || autoVerificationIntervalId) {
             stopCameraStream();
        }
        setStatusUpdate('Memproses verifikasi manual...', 'info', false);
        await performVerificationAttempt(true);
    });

    function setStatusUpdate(message, type = 'info', showAutoSpinner = false) {
        if (showAutoSpinner) {
            autoVerificationSpinnerEl.style.display = 'inline-block';
            autoVerificationStatusTextEl.textContent = message;
        } else {
            autoVerificationSpinnerEl.style.display = 'none';
            autoVerificationStatusTextEl.textContent = message;
        }
        photoStatusVotingEl.className = 'mt-2';
        if (type === 'error') photoStatusVotingEl.classList.add('text-danger', 'fw-bold');
        else if (type === 'success') photoStatusVotingEl.classList.add('text-success', 'fw-bold');
        else if (type === 'warning') photoStatusVotingEl.classList.add('text-warning');
        else photoStatusVotingEl.classList.add('text-muted');
    }

    document.querySelectorAll('.btn-pilih-kandidat').forEach(button => {
        button.addEventListener('click', function () {
            if (!capturedAndVerifiedFotoWajah) {
                alert("Verifikasi wajah belum berhasil atau data foto hilang. Ulangi proses verifikasi.");
                faceVerificationContainerEl.style.display = 'block';
                candidateSelectionContainerEl.style.display = 'none';
                verificationApiErrorEl.textContent = 'Harap lakukan verifikasi wajah terlebih dahulu.';
                stopCameraStream();
                setStatusUpdate('Kamera belum aktif.', 'info', false);
                btnCaptureAndVerifyFaceEl.style.display = 'none';
                return;
            }
            const kandidatId = this.dataset.kandidatid; const kandidatNama = this.dataset.kandidatnama;
            inputKandidatIdFinalVoteEl.value = kandidatId;
            modalTextConfirmVoteEl.innerHTML = `Anda akan memilih <strong>${kandidatNama}</strong>. Pastikan pilihan Anda sudah benar.`;
            modalConfirmVoteChoiceEl.classList.add('show');
        });
    });

    function closeModalVoteConfirm() {
        modalConfirmVoteChoiceEl.classList.remove('show');
        btnConfirmAndSubmitVoteEl.disabled = false;
        btnTextConfirmVoteEl.style.display = 'inline';
        btnSpinnerConfirmVoteEl.style.display = 'none';
    }
    btnCancelConfirmVoteEl.addEventListener('click', closeModalVoteConfirm);
    modalConfirmVoteChoiceEl.addEventListener('click', (event) => { if (event.target === modalConfirmVoteChoiceEl) closeModalVoteConfirm(); });

    formSubmitActualVoteEl.addEventListener('submit', function(event) {
        if (!capturedAndVerifiedFotoWajah || !inputFotoWajahSaatVoteFinalEl.value) {
            alert("Data foto verifikasi wajah hilang. Ulangi proses.");
            event.preventDefault();
            closeModalVoteConfirm();
            faceVerificationContainerEl.style.display = 'block';
            candidateSelectionContainerEl.style.display = 'none';
            verificationApiErrorEl.textContent = 'Harap ulangi verifikasi wajah.';
            stopCameraStream();
            setStatusUpdate('Kamera belum aktif.', 'info', false);
            btnCaptureAndVerifyFaceEl.style.display = 'none';
            return false;
        }
        btnConfirmAndSubmitVoteEl.disabled = true;
        btnTextConfirmVoteEl.style.display = 'none';
        btnSpinnerConfirmVoteEl.style.display = 'inline';
    });

    window.addEventListener('beforeunload', () => {
        stopCameraStream();
    });
});
</script>

<?php
require 'template/footer.php';
?>