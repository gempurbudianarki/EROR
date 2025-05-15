<?php
require 'config.php'; // Memuat konfigurasi, koneksi DB, fungsi dasar

// --- PENTING: Hanya admin atau user terlogin yang boleh lihat hasil? ---
// Jika hanya admin:
// if (!is_admin()) {
//     header("Location: dashboard.php");
//     exit();
// }
// Jika semua user terlogin boleh lihat:
if (!is_login()) {
     header("Location: login.php");
     exit();
}
// --- Sesuaikan aturan akses di atas jika perlu ---


// Muat header HTML setelah cek akses
require 'template/header.php';

// Inisialisasi variabel data dan pesan error
$kandidat = [];
$suara_kandidat = [];
$total_pemilih = 0;
$suara_masuk = 0;
$suara_belum = 0;
$error_msg = '';

try {
    // --- (Opsional tapi disarankan) Bersihkan data voting orphan ---
    // Hapus vote dari user yang sudah tidak ada di tabel users atau bukan pemilih lagi
    // Menggunakan LEFT JOIN untuk efisiensi dan keamanan
    $sql_delete_orphan = "DELETE v FROM voting v
                          LEFT JOIN users u ON v.user_id = u.id
                          WHERE u.id IS NULL OR u.role != 'pemilih'";
    if (!$conn->query($sql_delete_orphan)) {
        // Catat error tapi jangan hentikan proses utama jika tidak kritis
        error_log("Gagal menghapus orphan votes: " . $conn->error);
    }

    // --- Ambil semua kandidat (Gunakan Prepared Statement) ---
    $stmt_get_kandidat = $conn->prepare("SELECT * FROM kandidat ORDER BY nama ASC");
    if ($stmt_get_kandidat) {
        $stmt_get_kandidat->execute();
        $result_kandidat = $stmt_get_kandidat->get_result();
        while ($row = $result_kandidat->fetch_assoc()) {
            $kandidat[] = $row;
        }
        $stmt_get_kandidat->close();
    } else {
        throw new Exception("Gagal prepare statement ambil kandidat: " . $conn->error);
    }

    // --- Hitung total pemilih terdaftar (Gunakan Prepared Statement) ---
    $role_pemilih = 'pemilih';
    $stmt_total_pemilih = $conn->prepare("SELECT COUNT(*) FROM users WHERE role = ?");
    if ($stmt_total_pemilih) {
        $stmt_total_pemilih->bind_param("s", $role_pemilih);
        $stmt_total_pemilih->execute();
        $stmt_total_pemilih->bind_result($total_pemilih);
        $stmt_total_pemilih->fetch();
        $stmt_total_pemilih->close();
    } else {
         throw new Exception("Gagal prepare statement total pemilih: " . $conn->error);
    }
    $total_pemilih = (int)$total_pemilih; // Pastikan integer

    // --- Hitung suara masuk (user unik yang sudah voting & masih terdaftar sbg pemilih) ---
    // Menggunakan JOIN untuk efisiensi
    $stmt_suara_masuk = $conn->prepare("SELECT COUNT(DISTINCT v.user_id)
                                        FROM voting v
                                        JOIN users u ON v.user_id = u.id
                                        WHERE u.role = ?");
     if ($stmt_suara_masuk) {
        $stmt_suara_masuk->bind_param("s", $role_pemilih);
        $stmt_suara_masuk->execute();
        $stmt_suara_masuk->bind_result($suara_masuk);
        $stmt_suara_masuk->fetch();
        $stmt_suara_masuk->close();
    } else {
         throw new Exception("Gagal prepare statement suara masuk: " . $conn->error);
    }
    $suara_masuk = (int)$suara_masuk; // Pastikan integer

    // Hitung suara belum masuk
    $suara_belum = $total_pemilih - $suara_masuk;
    if ($suara_belum < 0) $suara_belum = 0; // Pastikan tidak negatif

    // --- Hitung suara per kandidat (hanya dari pemilih valid) ---
    // Menggunakan JOIN untuk efisiensi
    $stmt_suara_per_kandidat = $conn->prepare("SELECT v.kandidat_id, COUNT(v.id) as jumlah
                                               FROM voting v
                                               JOIN users u ON v.user_id = u.id
                                               WHERE u.role = ?
                                               GROUP BY v.kandidat_id");
     if ($stmt_suara_per_kandidat) {
        $stmt_suara_per_kandidat->bind_param("s", $role_pemilih);
        $stmt_suara_per_kandidat->execute();
        $result_suara = $stmt_suara_per_kandidat->get_result();
        while ($row = $result_suara->fetch_assoc()) {
            $suara_kandidat[$row['kandidat_id']] = (int)$row['jumlah']; // Simpan ID kandidat => jumlah suara
        }
        $stmt_suara_per_kandidat->close();
    } else {
         throw new Exception("Gagal prepare statement suara per kandidat: " . $conn->error);
    }

    // Siapkan data untuk Chart.js
    $chart_labels = [];
    $chart_data = [];
    $chart_colors = [];
    // Palet warna yang lebih beragam dan modern
    $color_palette = [
        '#4f46e5', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#22d3ee',
        '#f97316', '#ec4899', '#6366f1', '#34d399', '#a3e635', '#fbbf24'
    ];
    foreach ($kandidat as $i => $k) {
        $chart_labels[] = $k['nama']; // Nama kandidat untuk label chart
        // Ambil jumlah suara dari array $suara_kandidat, default 0 jika tidak ada
        $jumlah_suara_k = isset($suara_kandidat[$k['id']]) ? $suara_kandidat[$k['id']] : 0;
        $chart_data[] = $jumlah_suara_k;
        // Ambil warna dari palet secara berulang
        $chart_colors[] = $color_palette[$i % count($color_palette)];
    }

} catch (Exception $e) {
    // Tangani error jika salah satu query gagal
    $error_msg = "Terjadi kesalahan saat memuat data hasil voting: " . $e->getMessage();
    error_log($error_msg); // Catat error lengkap di log server
    // Kosongkan data agar tidak error di tampilan
    $kandidat = [];
    $suara_kandidat = [];
    $chart_labels = [];
    $chart_data = [];
    $chart_colors = [];
}

?>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
    body {
        font-family: 'Inter', Arial, sans-serif;
        background-color: #f8fafc;
    }
    .main-content {
        max-width: 1100px; /* Sedikit lebih lebar */
        margin: 1rem auto;
        padding: 2.5rem 1.5rem;
    }
    .hasil-title {
        text-align: center;
        font-weight: 700;
        color: #4f46e5; /* Warna utama tema dashboard */
        margin-bottom: 3rem;
        font-size: 2.3rem;
    }
    .statistik-suara {
        display: flex;
        justify-content: center;
        gap: 2rem; /* Jarak antar box */
        margin-bottom: 3rem;
        flex-wrap: wrap; /* Wrap jika layar kecil */
    }
    .stat-box {
        background: #ffffff;
        border-radius: 0.8rem;
        box-shadow: 0 3px 12px rgba(0,0,0,0.06);
        padding: 1.5rem 2rem;
        text-align: center;
        min-width: 200px; /* Lebar minimal box */
        border-top: 4px solid; /* Border atas berwarna */
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .stat-box:hover {
        transform: translateY(-4px);
        box-shadow: 0 6px 20px rgba(0,0,0,0.09);
    }
    .stat-box.total { border-color: #4f46e5; } /* Biru */
    .stat-box.masuk { border-color: #10b981; } /* Hijau */
    .stat-box.belum { border-color: #ef4444; } /* Merah */
    .stat-box .stat-label {
        color: #6c757d;
        font-size: 1rem;
        margin-bottom: 0.5rem;
        font-weight: 500;
    }
    .stat-box .stat-value {
        font-size: 2rem; /* Ukuran angka statistik */
        font-weight: 700;
        line-height: 1.2;
    }
    .stat-box.total .stat-value { color: #4f46e5; }
    .stat-box.masuk .stat-value { color: #10b981; }
    .stat-box.belum .stat-value { color: #ef4444; }

    /* Container untuk chart dan daftar kandidat */
    .result-container {
        display: grid;
        grid-template-columns: 1fr; /* Default 1 kolom */
        gap: 2.5rem;
    }
     @media (min-width: 992px) { /* Layar besar: 2 kolom */
         .result-container {
             grid-template-columns: 450px 1fr; /* Kolom chart tetap, kolom list fleksibel */
         }
     }

    .chart-container {
        background: #ffffff;
        border-radius: 1rem;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        padding: 2rem;
        display: flex;
        justify-content: center;
        align-items: center;
        max-width: 450px; /* Lebar max chart */
        margin: 0 auto; /* Tengah di layar kecil */
    }
     @media (min-width: 992px) {
        .chart-container {
             margin: 0; /* Tidak perlu margin auto di layout grid */
        }
     }


    .kandidat-detail-list {
        display: flex;
        flex-direction: column; /* Susun kartu ke bawah */
        gap: 1.5rem; /* Jarak antar kartu */
    }
    .kandidat-detail-card {
        background: #ffffff;
        border-radius: 0.8rem;
        box-shadow: 0 3px 12px rgba(0,0,0,0.06);
        padding: 1.5rem;
        display: flex; /* Gunakan flexbox untuk layout internal */
        align-items: center; /* Align vertikal tengah */
        gap: 1.5rem; /* Jarak antara foto dan teks */
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
     .kandidat-detail-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 18px rgba(0,0,0,0.08);
    }
    .kandidat-detail-photo {
        width: 80px; /* Ukuran foto */
        height: 80px;
        object-fit: cover;
        border-radius: 50%; /* Foto bulat */
        border: 3px solid #dee2e6; /* Border abu */
        flex-shrink: 0; /* Agar foto tidak mengecil */
    }
    .kandidat-detail-info {
        flex-grow: 1; /* Agar info mengisi sisa ruang */
    }
    .kandidat-detail-name {
        font-size: 1.25rem;
        font-weight: 600;
        color: #212529;
        margin-bottom: 0.2rem;
    }
    .kandidat-detail-jurusan {
        color: #6c757d;
        font-size: 0.95rem;
        margin-bottom: 0.6rem;
    }
    .kandidat-detail-suara {
        display: flex;
        align-items: baseline; /* Align baseline angka dan teks */
        gap: 0.8rem; /* Jarak antara persen dan jumlah */
    }
    .persen-suara {
        font-size: 1.1rem;
        color: #10b981; /* Hijau */
        font-weight: 700;
        background-color: #e8f9f1; /* Latar hijau muda */
        padding: 0.3rem 0.8rem;
        border-radius: 50px;
        display: inline-block;
    }
    .badge-suara {
        background-color: #e7e5ff; /* Latar ungu muda */
        color: #4f46e5; /* Biru/Ungu */
        font-size: 0.95rem;
        font-weight: 600;
        border-radius: 50px;
        padding: 0.3rem 0.8rem;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
    }

     /* Alert jika terjadi error */
     .alert-error-container {
         background-color: #f8d7da;
         color: #721c24;
         border: 1px solid #f5c6cb;
         border-radius: 0.5rem;
         padding: 1.5rem;
         text-align: center;
     }
      .alert-error-container i {
          font-size: 1.5rem;
          margin-right: 0.5rem;
      }

    /* Responsive tambahan */
    @media (max-width: 600px) {
        .main-content { padding: 1.5rem 0.8rem; }
        .hasil-title { font-size: 1.9rem; margin-bottom: 2rem;}
        .statistik-suara { gap: 1rem; }
        .stat-box { min-width: 150px; padding: 1rem 1.2rem; }
        .stat-box .stat-value { font-size: 1.6rem; }
        .kandidat-detail-card { flex-direction: column; text-align: center; gap: 1rem; }
        .kandidat-detail-photo { width: 90px; height: 90px; }
        .kandidat-detail-suara { justify-content: center; }
        .chart-container { padding: 1rem;}
    }
</style>

<div class="main-content">
    <div class="hasil-title"><i class="bi bi-bar-chart-line-fill me-2"></i>Hasil Perolehan Suara</div>

    <?php if (!empty($error_msg)): ?>
        <div class="alert-error-container mb-4" role="alert">
            <i class="bi bi-exclamation-triangle-fill"></i><?= htmlspecialchars(explode(":", $error_msg, 2)[0]) // Tampilkan pesan error umum ?>
            <p class="mt-2"><small>Detail error sudah dicatat. Silakan hubungi administrator jika masalah berlanjut.</small></p>
        </div>
    <?php else: ?>
        <div class="statistik-suara">
            <div class="stat-box total">
                <div class="stat-label">Pemilih Terdaftar</div>
                <div class="stat-value"><?= $total_pemilih ?></div>
            </div>
            <div class="stat-box masuk">
                <div class="stat-label">Suara Masuk</div>
                <div class="stat-value"><?= $suara_masuk ?></div>
            </div>
            <div class="stat-box belum">
                <div class="stat-label">Belum Memilih</div>
                <div class="stat-value"><?= $suara_belum ?></div>
            </div>
        </div>

        <div class="result-container">
            <div class="chart-container">
                <?php if ($suara_masuk > 0 && !empty($chart_data)): ?>
                    <canvas id="chartHasilVoting" style="max-width: 100%; height: auto;"></canvas>
                <?php else: ?>
                    <div class="text-center text-muted p-5">
                        <i class="bi bi-pie-chart" style="font-size: 3rem;"></i>
                        <p class="mt-2">Belum ada suara masuk untuk ditampilkan dalam grafik.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="kandidat-detail-list">
                <h4 class="mb-0 text-center text-md-start">Rincian Suara per Kandidat</h4>
                <?php if (empty($kandidat)): ?>
                    <div class="alert alert-secondary text-center" role="alert">
                        Belum ada data kandidat.
                    </div>
                <?php else: ?>
                    <?php foreach ($kandidat as $k): ?>
                    <?php
                        // Hitung suara dan persentase untuk kandidat ini
                        $jumlah_suara = isset($suara_kandidat[$k['id']]) ? $suara_kandidat[$k['id']] : 0;
                        $persen = $suara_masuk > 0 ? round(($jumlah_suara / $suara_masuk) * 100, 2) : 0;

                        // Tentukan path foto
                         $foto_path = UPLOAD_DIR . basename($k['foto']);
                         $foto_url = BASE_URL . 'uploads/' . basename($k['foto']);
                         $placeholder_foto = BASE_URL . 'assets/img/default-user.png'; // Sesuaikan path
                         if (empty($k['foto']) || !file_exists($foto_path)) {
                             $gambar_kandidat = $placeholder_foto;
                         } else {
                             $gambar_kandidat = $foto_url;
                         }
                    ?>
                    <div class="kandidat-detail-card">
                        <img src="<?= htmlspecialchars($gambar_kandidat) ?>" class="kandidat-detail-photo" alt="Foto <?= htmlspecialchars($k['nama']) ?>">
                        <div class="kandidat-detail-info">
                            <div class="kandidat-detail-name"><?= htmlspecialchars($k['nama']) ?></div>
                            <div class="kandidat-detail-jurusan"><?= htmlspecialchars($k['jurusan']) ?></div>
                            <div class="kandidat-detail-suara">
                                <span class="persen-suara"><?= $persen ?>%</span>
                                <span class="badge-suara">
                                    <i class="bi bi-person-check"></i> <?= $jumlah_suara ?> suara
                                </span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                 <?php endif; ?>
            </div>
        </div>
    <?php endif; // Akhir blok jika tidak ada error_msg ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
    // Hanya jalankan script chart jika data ada dan tidak ada error
    <?php if (empty($error_msg) && $suara_masuk > 0 && !empty($chart_data)): ?>
    const ctx = document.getElementById('chartHasilVoting').getContext('2d');
    const chartHasilVoting = new Chart(ctx, {
        type: 'pie', // Tipe chart: pie
        data: {
            labels: <?= json_encode($chart_labels) ?>, // Label dari nama kandidat
            datasets: [{
                label: 'Jumlah Suara',
                data: <?= json_encode($chart_data) ?>, // Data jumlah suara per kandidat
                backgroundColor: <?= json_encode($chart_colors) ?>, // Warna dari palet
                borderColor: '#ffffff', // Warna border antar slice
                borderWidth: 2 // Lebar border
            }]
        },
        options: {
            responsive: true, // Chart responsif
            maintainAspectRatio: true, // Pertahankan rasio aspek
            plugins: {
                legend: {
                    position: 'bottom', // Posisi legenda di bawah
                    labels: {
                        padding: 20, // Jarak label legenda
                        font: {
                            family: 'Inter', // Font legenda
                            size: 13
                        },
                        usePointStyle: true, // Gunakan style titik untuk legenda
                        boxWidth: 10 // Lebar kotak warna
                    }
                },
                tooltip: {
                    enabled: true, // Aktifkan tooltip
                    backgroundColor: 'rgba(0, 0, 0, 0.8)', // Background tooltip
                    titleFont: { size: 14, weight: 'bold', family: 'Inter' },
                    bodyFont: { size: 13, family: 'Inter' },
                    padding: 10, // Padding tooltip
                    cornerRadius: 4, // Sudut tooltip
                    displayColors: false, // Jangan tampilkan kotak warna di tooltip
                    callbacks: {
                        // Kustomisasi teks tooltip
                        label: function(context) {
                            let label = context.label || '';
                            if (label) {
                                label += ': ';
                            }
                            let value = context.parsed || 0;
                            let totalSuara = <?= (int)$suara_masuk ?>;
                            let percent = totalSuara > 0 ? ((value / totalSuara) * 100).toFixed(2) : 0;
                            label += `${value} suara (${percent}%)`;
                            return label;
                        }
                    }
                },
                title: {
                    display: true, // Tampilkan judul chart
                    text: 'Distribusi Suara Kandidat', // Judul chart
                    position: 'top', // Posisi judul
                    padding: { top: 10, bottom: 25 }, // Padding judul
                    font: { size: 16, weight: '600', family: 'Inter' } // Font judul
                }
            }
        }
    });
    <?php endif; ?>
</script>

<?php
require 'template/footer.php'; // Memuat footer HTML
?>