<?php
// login.php - Halaman Login Pengguna (Desain Baru - Dark Blue Modern)

require_once 'config.php';

if (is_login()) {
    header("Location: dashboard.php");
    exit();
}

$error_login = '';
$input_username_login = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_login_submit'])) {
    $input_username_login = trim($_POST['username'] ?? '');
    $password_login = $_POST['password'] ?? '';

    if (empty($input_username_login) || empty($password_login)) {
        $error_login = "Username dan Password tidak boleh kosong!";
    } else {
        $stmt_login = $conn->prepare("SELECT id, username, password, nama, role, sudah_vote FROM users WHERE username = ?");
        if ($stmt_login) {
            $stmt_login->bind_param("s", $input_username_login);
            if (!$stmt_login->execute()) {
                error_log("MySQLi execute failed for login: " . $stmt_login->error);
                $error_login = "Terjadi kesalahan sistem saat mencoba login.";
            } else {
                $result_login = $stmt_login->get_result();
                if ($result_login && $result_login->num_rows === 1) {
                    $user_data = $result_login->fetch_assoc();
                    if (password_verify($password_login, $user_data['password'])) {
                        session_regenerate_id(true);
                        $_SESSION['user'] = [
                            'id' => $user_data['id'],
                            'username' => $user_data['username'],
                            'nama' => $user_data['nama'],
                            'role' => $user_data['role'],
                            'sudah_vote' => $user_data['sudah_vote']
                        ];
                        header("Location: dashboard.php");
                        exit();
                    } else {
                        $error_login = "Kombinasi Username dan Password salah!";
                    }
                } else {
                    $error_login = "Kombinasi Username dan Password salah!";
                }
            }
            $stmt_login->close();
        } else {
            error_log("MySQLi prepare failed for login: " . $conn->error);
            $error_login = "Terjadi kesalahan pada sistem (prepare login).";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Sistem E-Voting UBBG</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        /* === CSS Modern - Dark Blue Grid Theme === */
        :root {
            --primary-color: #818CF8; /* Indigo-300 (Tailwind) - Warna cerah untuk kontras */
            --primary-hover: #6366F1; /* Indigo-500 */
            --primary-focus-shadow: rgba(99, 102, 241, 0.3);
            --background-color:rgb(0, 81, 255); /* Gray-900 - Biru sangat gelap */
            --card-bg: rgba(31, 41, 55, 0.6); /* Gray-800 dengan transparansi */
            --card-border: rgba(75, 85, 99, 0.4); /* Gray-600 transparan */
            --text-primary-darkbg: #F3F4F6; /* Gray-100 */
            --text-secondary-darkbg: #9CA3AF; /* Gray-400 */
            --input-bg: rgba(55, 65, 81, 0.5); /* Gray-700 transparan */
            --input-border: #4B5563; /* Gray-600 */
            --input-focus-border: var(--primary-color);
            --input-placeholder: #9CA3AF;
            --link-color: var(--primary-color);
            --link-hover: #A5B4FC; /* Indigo-200 */
            --danger-bg: rgba(153, 27, 27, 0.2); /* Red-800 transparan */
            --danger-text: #FECACA; /* Red-200 */
            --danger-border: rgba(220, 38, 38, 0.3); /* Red-600 transparan */
            --success-bg: rgba(21, 128, 61, 0.2); /* Green-700 transparan */
            --success-text: #A7F3D0; /* Green-200 */
            --success-border: rgba(22, 163, 74, 0.3); /* Green-600 transparan */

            --shadow-color-light: rgba(200, 200, 255, 0.05);
            --shadow-color-dark: rgba(0, 0, 0, 0.3);
            --border-radius-lg: 1rem; /* 16px */
            --border-radius-xl: 1.5rem; /* 24px */
            --pattern-color: rgba(75, 85, 99, 0.15); /* Warna garis/kotak pattern */
        }

        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--background-color);
            /* Background Biru Tua + Pola Kotak Halus */
            background-image:
                linear-gradient(var(--pattern-color) 1px, transparent 1px),
                linear-gradient(90deg, var(--pattern-color) 1px, transparent 1px);
            background-size: 40px 40px; /* Ukuran kotak */
            color: var(--text-primary-darkbg);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            overflow-x: hidden;
        }

        .login-wrapper {
            width: 100%;
            max-width: 450px; /* Lebar card disesuaikan lagi */
            text-align: center;
        }

        .logo-container {
            margin-bottom: -60px; /* Lebih menjorok lagi */
            position: relative;
            z-index: 2;
            filter: drop-shadow(0px 12px 20px rgba(0, 0, 0, 0.4));
        }

        .logo-image {
            height: 240px; /* Ukuran logo disesuaikan */
            width: auto;
            max-width: 80%;
            object-fit: contain;
        }

        /* Efek Glassmorphism pada Card */
        .card-login-glass {
            background: var(--card-bg);
            backdrop-filter: blur(12px); /* Efek blur */
            -webkit-backdrop-filter: blur(12px); /* Untuk Safari */
            border-radius: var(--border-radius-xl);
            border: 1px solid var(--card-border);
            box-shadow: 0 8px 32px 0 var(--shadow-color-dark);
            padding: 70px 2.5rem 2.5rem 2.5rem; /* Padding atas lebih besar */
            margin-top: -15px;
            color: var(--text-primary-darkbg); /* Teks di dalam card terang */
            position: relative;
            z-index: 1;
        }

        .login-title {
            font-size: 1.7rem; /* Sedikit lebih besar */
            font-weight: 700;
            color: var(--text-primary-darkbg);
            margin-bottom: 0.5rem;
            margin-top: 1rem; /* Jarak setelah logo */
        }

        .login-subtitle {
            font-size: 0.95rem;
            color: var(--text-secondary-darkbg);
            margin-bottom: 2.5rem; /* Jarak lebih besar */
        }

        /* Input dengan Floating Labels versi Dark */
        .form-floating > .form-control {
            background-color: var(--input-bg);
            border: 1px solid var(--input-border);
            border-radius: 0.75rem;
            color: var(--text-primary-darkbg);
            height: calc(3.5rem + 2px);
            line-height: 1.25;
            padding: 1rem 1rem; /* Sesuaikan padding floating label */
        }
        /* Set warna placeholder secara eksplisit */
         .form-control::placeholder { color: var(--input-placeholder); opacity: 1;} /* Firefox */
         .form-control::-ms-input-placeholder { color: var(--input-placeholder); } /* IE */
         .form-control::-webkit-input-placeholder { color: var(--input-placeholder); } /* Chrome, Safari, etc */

        .form-floating > label {
            color: var(--input-placeholder);
            padding: 1rem 1rem;
        }
        /* Saat fokus atau ada isi */
        .form-floating > .form-control:focus,
        .form-floating > .form-control:not(:placeholder-shown) {
            padding-top: 1.625rem;
            padding-bottom: 0.625rem;
            background-color: rgba(55, 65, 81, 0.7); /* Sedikit lebih solid saat fokus */
            border-color: var(--input-focus-border);
            box-shadow: 0 0 0 0.2rem var(--input-focus-shadow);
            color: var(--text-primary-darkbg);
        }
        .form-floating > .form-control:focus ~ label,
        .form-floating > .form-control:not(:placeholder-shown) ~ label {
            opacity: 0.8;
            transform: scale(0.85) translateY(-0.5rem) translateX(0.15rem);
            color: var(--primary-color); /* Warna label saat naik */
        }

        .btn-login-glow { /* Tombol dengan efek glow */
            background: linear-gradient(90deg, var(--primary-hover) 0%, var(--primary-color) 100%);
            border: none;
            color: #ffffff;
            padding: 0.85rem 1.5rem;
            border-radius: 0.75rem;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s ease;
            width: 100%;
            box-shadow: 0 0 5px var(--primary-color), /* Inner glow */
                        0 0 10px var(--primary-color), /* Middle glow */
                        0 0 15px rgba(99, 102, 241, 0.5); /* Outer soft glow */
            position: relative;
            z-index: 1;
        }
        .btn-login-glow:hover {
            transform: translateY(-3px);
            box-shadow: 0 0 8px var(--primary-color),
                        0 0 18px var(--primary-color),
                        0 0 30px rgba(99, 102, 241, 0.7);
            filter: brightness(1.1);
        }
        .btn-login-glow:active {
            transform: translateY(0px);
            box-shadow: 0 0 5px var(--primary-color),
                        0 0 10px var(--primary-color);
        }

        .alert {
            border-radius: 0.75rem;
            padding: 0.9rem 1.25rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            margin-bottom: 1.5rem;
            background-color: transparent; /* Background transparan agar efek glass terlihat */
            border: 1px solid; /* Border akan diberi warna spesifik */
        }
        .alert .bi { margin-right: 0.75rem; font-size: 1.2rem; }
        .alert-danger { color: var(--danger-text); border-color: var(--danger-border); background-color: var(--danger-bg); }
        .alert-success { color: var(--success-text); border-color: var(--success-border); background-color: var(--success-bg); }

        .register-link-container {
            margin-top: 2rem;
            font-size: 0.9rem;
            color: var(--text-secondary-darkbg);
        }
        .register-link-container a {
            color: var(--link-color);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s ease;
        }
        .register-link-container a:hover {
            color: var(--link-hover);
            text-decoration: underline;
        }

        /* Responsiveness */
        @media (max-width: 576px) {
            .logo-image { height: 180px; }
            .logo-container { margin-bottom: -45px; }
            .card-login-glass { border-radius: var(--border-radius-lg); padding: 60px 1.5rem 2rem 1.5rem; }
            .login-title { font-size: 1.5rem; }
            .login-subtitle { font-size: 0.9rem; margin-bottom: 1.8rem; }
        }
    </style>
</head>
<body>
<div class="login-wrapper">
    <div class="logo-container">
        <img src="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>foto/Wallpaper-UBBG-PC05V-removebg-preview.png"
             alt="Logo Universitas Bina Bangsa Getsempena"
             class="logo-image">
    </div>

    <div class="card card-login-glass"> <div class="card-login-body">
            <h2 class="login-title">Login E-Voting</h2>
            <p class="login-subtitle">Masukkan username dan password Anda.</p>

            <?php if (!empty($error_login)): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="bi bi-x-octagon-fill"></i> <?= htmlspecialchars($error_login, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php
            // Tampilkan pesan sukses dari registrasi jika ada
            if (isset($_SESSION['flash_success_register'])) {
                echo '<div class="alert alert-success" role="alert"><i class="bi bi-check-circle-fill"></i> ' . htmlspecialchars($_SESSION['flash_success_register']) . '</div>';
                unset($_SESSION['flash_success_register']);
            }
            ?>

            <form method="POST" action="login.php" class="mt-4">
                <input type="hidden" name="form_login_submit" value="1">

                <div class="form-floating mb-3">
                    <input name="username" type="text" class="form-control" id="usernameInput" placeholder="Username" required value="<?= htmlspecialchars($input_username_login, ENT_QUOTES, 'UTF-8') ?>" autocomplete="username">
                    <label for="usernameInput"><i class="bi bi-person-fill me-1"></i> Username</label>
                </div>

                <div class="form-floating mb-4">
                    <input name="password" type="password" class="form-control" id="passwordInput" placeholder="Password" required autocomplete="current-password">
                    <label for="passwordInput"><i class="bi bi-lock-fill me-1"></i> Password</label>
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-login-glow"><i class="bi bi-box-arrow-in-right me-1"></i> Login Sekarang</button>
                </div>
            </form>

            <div class="register-link-container text-center">
                Belum punya akun? <a href="register.php">Buat Akun Baru</a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>