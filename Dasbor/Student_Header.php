<?php
$studentPageTitles = [
    "home" => "Beranda",
    "attendance" => "Absensi",
    "profile" => "Profil",
    "biometrics" => "Biometrik",
    "history" => "Riwayat",
];
$studentPageLinks = [
    "home" => ["Beranda.php", "Beranda"],
    "attendance" => ["Absensi.php", "Absensi"],
    "profile" => ["Profil.php", "Profil"],
    "biometrics" => ["Biometrik.php", "Biometrik"],
    "history" => ["Riwayat.php", "Riwayat"],
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="theme-color" content="#223a6f" />
    <title><?= studentEscape($studentPageTitles[$activeStudentPage] ?? "Mahasiswa") ?> | Absensi Kampus</title>
    <link rel="stylesheet" href="../Visual.css?v=20261121-student-menu" />
</head>
<body class="home-page student-app">
    <header class="header student-app-header">
        <div class="student-app-header-inner">
            <a class="logo" href="Beranda.php" aria-label="Beranda Mahasiswa">
                <img src="../Logo SIABAK Akademik Modern.png" alt="" class="logo-image" />
                <span>Absensi Kampus</span>
            </a>
            <div class="student-account-actions">
                <span class="session-user"><?= studentEscape($studentDisplayName) ?></span>
                <form action="../Koneksi/Logout.php" method="post">
                    <button class="nav-button nav-button-secondary" type="submit">Keluar</button>
                </form>
            </div>
        </div>
    </header>
    <main class="student-app-layout">
        <nav class="student-app-nav" aria-label="Menu mahasiswa">
            <?php foreach ($studentPageLinks as $pageKey => $pageLink): ?>
                <a href="<?= studentEscape($pageLink[0]) ?>"
                   <?= $activeStudentPage === $pageKey ? 'aria-current="page"' : "" ?>>
                    <?= studentEscape($pageLink[1]) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="student-app-content">
