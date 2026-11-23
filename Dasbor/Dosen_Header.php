<?php
$dosenPageTitles = [
    "rooms" => "Kontrol Ruangan",
    "schedule" => "Jadwal Kelas",
    "history" => "Riwayat Kehadiran",
    "attendance" => "Kelola Kehadiran",
    "profile" => "Profil Dosen",
];
$dosenPageLinks = [
    "rooms" => ["Dosen.php", "Ruangan"],
    "schedule" => ["Dosen_Jadwal.php", "Jadwal"],
    "history" => ["Dosen_Riwayat.php", "Riwayat"],
    "attendance" => ["Dosen_Kehadiran.php", "Kelola Kehadiran"],
    "profile" => ["Dosen_Profil.php", "Profil"],
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="theme-color" content="#223a6f" />
    <title><?= dosenEscape($dosenPageTitles[$activeDosenPage] ?? "Dosen") ?> | Absensi Kampus</title>
    <link rel="stylesheet" href="../Visual.css?v=20261123-chat-hidden-state" />
</head>
<body class="home-page student-app dosen-app">
    <header class="header student-app-header">
        <div class="student-app-header-inner">
            <a class="logo" href="Dosen.php" aria-label="Beranda Dosen">
                <img src="../Logo SIABAK Akademik Modern.png" alt="" class="logo-image" />
                <span>Absensi Kampus</span>
            </a>
            <div class="student-account-actions">
                <span class="session-user">Dosen: <?= dosenEscape($dosenDisplayName) ?></span>
                <form action="../Koneksi/Logout.php" method="post">
                    <button class="nav-button nav-button-secondary" type="submit">Keluar</button>
                </form>
            </div>
        </div>
    </header>
    <main class="student-app-layout">
        <nav class="student-app-nav dosen-app-nav" aria-label="Menu dosen">
            <?php foreach ($dosenPageLinks as $pageKey => $pageLink): ?>
                <a href="<?= dosenEscape($pageLink[0]) ?>"
                   <?= $activeDosenPage === $pageKey ? 'aria-current="page"' : "" ?>>
                    <?= dosenEscape($pageLink[1]) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="student-app-content">
