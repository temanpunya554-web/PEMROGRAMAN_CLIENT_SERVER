<?php
session_start();
if (!isset($_SESSION["username"]) || !is_string($_SESSION["username"])) {
    header("Location: ../SIABAK.html?login=1");
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function fetchLabelCounts($conn, $sql)
{
    $result = mysqli_query($conn, $sql);
    $counts = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $counts[$row["label"]] = (int) $row["total"];
    }
    return $counts;
}

function buildGenderCounts($rawCounts)
{
    $counts = ["Laki-laki" => 0, "Perempuan" => 0, "Lainnya" => 0];
    foreach ($rawCounts as $label => $total) {
        if (isset($counts[$label])) {
            $counts[$label] += $total;
        } else {
            $counts["Belum diisi"] = ($counts["Belum diisi"] ?? 0) + $total;
        }
    }
    return $counts;
}

try {
    require_once __DIR__ . "/../Koneksi/Koneksi.php";
    mysqli_set_charset($conn, "utf8mb4");

    $roleStatement = mysqli_prepare($conn, "SELECT `Role` FROM `login` WHERE `Username` = ? LIMIT 1");
    mysqli_stmt_bind_param($roleStatement, "s", $_SESSION["username"]);
    mysqli_stmt_execute($roleStatement);
    mysqli_stmt_bind_result($roleStatement, $role);
    $roleFound = mysqli_stmt_fetch($roleStatement);
    mysqli_stmt_close($roleStatement);
    if (!$roleFound || $role !== "admin") {
        header("Location: " . ($role === "dosen" ? "Dosen.php" : "Beranda.php"));
        exit;
    }

    if (!isset($_SESSION["csrf_token"]) || !is_string($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }
    $adminCsrfToken = $_SESSION["csrf_token"];

    $displayName = $_SESSION["username"];
    $profileStatement = mysqli_prepare($conn, "SELECT `FullName` FROM `account_profiles` WHERE `Username` = ? LIMIT 1");
    mysqli_stmt_bind_param($profileStatement, "s", $_SESSION["username"]);
    mysqli_stmt_execute($profileStatement);
    mysqli_stmt_bind_result($profileStatement, $profileName);
    if (mysqli_stmt_fetch($profileStatement) && is_string($profileName) && trim($profileName) !== "") {
        $displayName = $profileName;
    }
    mysqli_stmt_close($profileStatement);

    $genderColumn = mysqli_query(
        $conn,
        "SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'dosen_profiles' AND column_name = 'Gender' LIMIT 1"
    );
    $dosenHasGender = mysqli_num_rows($genderColumn) === 1;

    // Jumlah akun
    $totalMahasiswa = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM `login` WHERE `Role` = 'mahasiswa'"))["total"];
    $totalDosen = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM `login` WHERE `Role` = 'dosen'"))["total"];
    $totalUsers = $totalMahasiswa + $totalDosen;

    // Jenis kelamin: mahasiswa, dosen, dan gabungan
    $mahasiswaGender = buildGenderCounts(fetchLabelCounts(
        $conn,
        "SELECT COALESCE(NULLIF(TRIM(profiles.`Gender`), ''), 'Belum diisi') AS label, COUNT(*) AS total
         FROM `login`
         LEFT JOIN `mahasiswa_profiles` AS profiles ON profiles.`Username` = login.`Username`
         WHERE login.`Role` = 'mahasiswa'
         GROUP BY label"
    ));

    if ($dosenHasGender) {
        $dosenGender = buildGenderCounts(fetchLabelCounts(
            $conn,
            "SELECT COALESCE(NULLIF(TRIM(profiles.`Gender`), ''), 'Belum diisi') AS label, COUNT(*) AS total
             FROM `login`
             LEFT JOIN `dosen_profiles` AS profiles ON profiles.`Username` = login.`Username`
             WHERE login.`Role` = 'dosen'
             GROUP BY label"
        ));
    } else {
        $dosenGender = buildGenderCounts($totalDosen > 0 ? ["Belum diisi" => $totalDosen] : []);
    }

    $combinedGender = [];
    foreach (array_unique(array_merge(array_keys($mahasiswaGender), array_keys($dosenGender))) as $label) {
        $combinedGender[$label] = ($mahasiswaGender[$label] ?? 0) + ($dosenGender[$label] ?? 0);
    }

    // Kelompok usia mahasiswa
    $ageRaw = fetchLabelCounts(
        $conn,
        "SELECT CASE
                    WHEN profiles.`BirthDate` IS NULL THEN 'Belum diisi'
                    WHEN TIMESTAMPDIFF(YEAR, profiles.`BirthDate`, CURDATE()) < 18 THEN 'Di bawah 18 tahun'
                    WHEN TIMESTAMPDIFF(YEAR, profiles.`BirthDate`, CURDATE()) <= 20 THEN '18-20 tahun'
                    WHEN TIMESTAMPDIFF(YEAR, profiles.`BirthDate`, CURDATE()) <= 22 THEN '21-22 tahun'
                    WHEN TIMESTAMPDIFF(YEAR, profiles.`BirthDate`, CURDATE()) <= 25 THEN '23-25 tahun'
                    ELSE 'Di atas 25 tahun'
                END AS label, COUNT(*) AS total
         FROM `login`
         LEFT JOIN `mahasiswa_profiles` AS profiles ON profiles.`Username` = login.`Username`
         WHERE login.`Role` = 'mahasiswa'
         GROUP BY label"
    );
    $ageCounts = [];
    foreach (["Di bawah 18 tahun", "18-20 tahun", "21-22 tahun", "23-25 tahun", "Di atas 25 tahun", "Belum diisi"] as $ageLabel) {
        if (isset($ageRaw[$ageLabel])) {
            $ageCounts[$ageLabel] = $ageRaw[$ageLabel];
        }
    }

    // Provinsi asal mahasiswa (5 teratas)
    $provinceRaw = fetchLabelCounts(
        $conn,
        "SELECT COALESCE(NULLIF(TRIM(profiles.`Province`), ''), 'Belum diisi') AS label, COUNT(*) AS total
         FROM `login`
         LEFT JOIN `mahasiswa_profiles` AS profiles ON profiles.`Username` = login.`Username`
         WHERE login.`Role` = 'mahasiswa'
         GROUP BY label
         ORDER BY total DESC, label ASC"
    );
    $provinceCounts = array_slice($provinceRaw, 0, 5, true);
    $provinceOthers = array_sum(array_slice($provinceRaw, 5, null, true));
    if ($provinceOthers > 0) {
        $provinceCounts["Provinsi lainnya"] = $provinceOthers;
    }

    // Pendaftaran wajah
    $faceRow = mysqli_fetch_assoc(mysqli_query(
        $conn,
        "SELECT SUM(faces.`Username` IS NOT NULL) AS enrolled
         FROM `login`
         LEFT JOIN `mahasiswa_face_enrollments` AS faces ON faces.`Username` = login.`Username`
         WHERE login.`Role` = 'mahasiswa'"
    ));
    $faceEnrolled = (int) ($faceRow["enrolled"] ?? 0);
    $faceCounts = ["Sudah terdaftar" => $faceEnrolled, "Belum terdaftar" => max(0, $totalMahasiswa - $faceEnrolled)];

    $attendanceResult = mysqli_query(
        $conn,
        "SELECT attendance.Username, attendance.RoomName, attendance.FingerprintVerified,
                attendance.FaceVerified, attendance.VerifiedAt
         FROM class_attendance AS attendance
         ORDER BY attendance.VerifiedAt DESC
         LIMIT 100"
    );
    $attendanceRows = mysqli_fetch_all($attendanceResult, MYSQLI_ASSOC);

    $roomResult = mysqli_query(
        $conn,
        "SELECT RoomName, RoomStatus FROM class_rooms ORDER BY Id ASC"
    );
    $rooms = mysqli_fetch_all($roomResult, MYSQLI_ASSOC);

    $roomCounts = ["Terbuka" => 0, "Terkunci" => 0];
    foreach ($rooms as $room) {
        $roomCounts[$room["RoomStatus"] === "open" ? "Terbuka" : "Terkunci"]++;
    }
    // [TAMBAHAN CLAUDE] Poin c: data untuk form penugasan dosen
    $assignReady = false;
    $assignDosenList = [];
    $assignScheduleList = [];
    $assignMap = [];
    try {
        $assignReady = mysqli_num_rows(mysqli_query($conn, "SHOW TABLES LIKE 'dosen_course_assignments'")) === 1;
        if ($assignReady) {
            $assignDosenList = mysqli_fetch_all(mysqli_query(
                $conn,
                "SELECT l.`Username`, COALESCE(NULLIF(p.`FullName`, ''), l.`Username`) AS `FullName`
                 FROM `login` AS l
                 LEFT JOIN `dosen_profiles` AS p ON p.`Username` = l.`Username`
                 WHERE l.`Role` = 'dosen'
                 ORDER BY `FullName`"
            ), MYSQLI_ASSOC);
            $assignScheduleList = mysqli_fetch_all(mysqli_query(
                $conn,
                "SELECT `Id`, `DayName`, `CourseName`, `StartTime`, `EndTime`, `RoomName`
                 FROM `class_schedules`
                 ORDER BY FIELD(`DayName`, 'Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'),
                          `StartTime` IS NULL, `StartTime`, `Id`"
            ), MYSQLI_ASSOC);
            $assignRows = mysqli_fetch_all(mysqli_query(
                $conn,
                "SELECT `DosenUsername`, `ScheduleId` FROM `dosen_course_assignments`"
            ), MYSQLI_ASSOC);
            foreach ($assignRows as $assignRow) {
                $assignMap[$assignRow["DosenUsername"]][] = (int) $assignRow["ScheduleId"];
            }
        }
    } catch (Throwable $assignError) {
        error_log("Admin assignment data error: " . $assignError->getMessage());
        $assignReady = false;
    }
    // [/TAMBAHAN CLAUDE]
} catch (Throwable $error) {
    error_log("Admin attendance dashboard error: " . $error->getMessage());
    http_response_code(500);
    exit("Dashboard admin tidak dapat dimuat.");
}

function escapeHtml($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

function formatPercent($part, $total)
{
    $value = $total > 0 ? ($part / $total) * 100 : 0;
    return number_format($value, 1, ",", ".") . "%";
}

function percentBarClass($label)
{
    $classes = [
        "Laki-laki" => "is-male",
        "Perempuan" => "is-female",
        "Lainnya" => "is-other",
        "Belum diisi" => "is-empty",
        "Sudah terdaftar" => "is-good",
        "Terbuka" => "is-good",
        "Belum terdaftar" => "is-warn",
        "Terkunci" => "is-warn",
    ];
    return $classes[$label] ?? "";
}

function renderPercentList($counts)
{
    $total = array_sum($counts);
    if ($total === 0) {
        echo '<p class="admin-empty">Belum ada data.</p>';
        return;
    }
    echo '<ul class="admin-percent-list">';
    foreach ($counts as $label => $count) {
        $percent = ($count / $total) * 100;
        echo '<li class="admin-percent-row">';
        echo '<div class="admin-percent-head"><span>' . escapeHtml($label) . '</span>';
        echo '<strong>' . escapeHtml($count) . ' &middot; ' . escapeHtml(formatPercent($count, $total)) . '</strong></div>';
        echo '<div class="admin-percent-track" role="img" aria-label="' . escapeHtml($label . ": " . formatPercent($count, $total)) . '">';
        echo '<div class="admin-percent-fill ' . escapeHtml(percentBarClass($label)) . '" style="width: ' . round($percent, 1) . '%"></div>';
        echo '</div></li>';
    }
    echo '</ul>';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Dashboard Operator | Absensi Kampus</title>
    <link rel="stylesheet" href="../Visual.css?v=20261125-admin-dashboard-tabs" />
</head>
<body class="home-page student-app admin-app">
<header class="header student-app-header">
    <div class="student-app-header-inner">
        <a class="logo" href="Admin.php">
            <img src="../Logo SIABAK Akademik Modern.png" alt="" class="logo-image" />
            <span>Absensi Kampus</span>
        </a>
        <div class="student-account-actions">
            <span class="session-user">Operator: <?= escapeHtml($displayName) ?></span>
            <form action="../Koneksi/Logout.php" method="post">
                <button class="nav-button nav-button-secondary" type="submit">Keluar</button>
            </form>
        </div>
    </div>
</header>

<main class="student-app-layout admin-app-layout">
    <nav class="student-app-nav admin-app-nav" aria-label="Menu operator">
        <a href="#ringkasan">Ringkasan</a>
        <a href="#persentase">Persentase</a>
        <a href="#data-akun">Data Akun</a>
        <a href="#penugasan-dosen">Penugasan Dosen</a> <!-- [TAMBAHAN CLAUDE] poin c -->
        <a href="#ruangan">Ruangan</a>
        <a href="#absensi">Absensi</a>
        <a href="#pesan-bantuan">Pesan & Bantuan <span id="admin-nav-chat-badge" class="nav-chat-badge" hidden>0</span></a>
    </nav>
    <div class="student-app-content admin-app-content">
      <div class="admin-dashboard home-container">
    <section id="ringkasan" class="admin-view-section">
        <div class="admin-heading">
            <p class="home-eyebrow">PANEL OPERATOR</p>
            <h1>Ringkasan sistem absensi</h1>
            <p>Data akun Mahasiswa dan Dosen serta riwayat absensi biometrik.</p>
        </div>

        <div class="admin-stat-grid" aria-label="Ringkasan akun">
            <article class="admin-stat-card admin-stat-total">
                <p>Total akun terdaftar</p>
                <strong><?= $totalUsers ?></strong>
                <span>Mahasiswa dan dosen</span>
            </article>
            <article class="admin-stat-card">
                <p>Mahasiswa</p>
                <strong><?= $totalMahasiswa ?></strong>
                <span><?= escapeHtml(formatPercent($totalMahasiswa, $totalUsers)) ?> dari total akun</span>
            </article>
            <article class="admin-stat-card">
                <p>Dosen</p>
                <strong><?= $totalDosen ?></strong>
                <span><?= escapeHtml(formatPercent($totalDosen, $totalUsers)) ?> dari total akun</span>
            </article>
            <article class="admin-stat-card">
                <p>Wajah terdaftar</p>
                <strong><?= escapeHtml(formatPercent($faceEnrolled, $totalMahasiswa)) ?></strong>
                <span><?= $faceEnrolled ?> dari <?= $totalMahasiswa ?> mahasiswa</span>
            </article>
        </div>
    </section>

    <section class="admin-view-section" id="persentase" aria-label="Persentase data">
        <section class="admin-panel">
            <div class="admin-panel-heading">
                <div>
                    <p class="home-eyebrow">PERSENTASE</p>
                    <h2>Jenis kelamin</h2>
                </div>
                <span><?= $totalUsers ?> akun</span>
            </div>
            <?php if (!$dosenHasGender): ?>
                <p class="admin-notice">
                    Data jenis kelamin Dosen belum tersedia. Import <code>Koneksi/Migration_Admin_Dosen_Gender.sql</code>
                    melalui phpMyAdmin, lalu isi lewat tabel Data Akun.
                </p>
            <?php endif; ?>
            <div class="admin-percent-grid admin-percent-grid-3">
                <div class="admin-percent-block">
                    <h3>Mahasiswa <span class="admin-muted">(<?= $totalMahasiswa ?>)</span></h3>
                    <?php renderPercentList($mahasiswaGender); ?>
                </div>
                <div class="admin-percent-block">
                    <h3>Dosen <span class="admin-muted">(<?= $totalDosen ?>)</span></h3>
                    <?php renderPercentList($dosenGender); ?>
                </div>
                <div class="admin-percent-block">
                    <h3>Gabungan <span class="admin-muted">(<?= $totalUsers ?>)</span></h3>
                    <?php renderPercentList($combinedGender); ?>
                </div>
            </div>
        </section>

        <section class="admin-panel">
            <div class="admin-panel-heading">
                <div>
                    <p class="home-eyebrow">PERSENTASE</p>
                    <h2>Profil mahasiswa dan ruangan</h2>
                </div>
            </div>
            <div class="admin-percent-grid">
                <div class="admin-percent-block">
                    <h3>Kelompok usia mahasiswa</h3>
                    <?php renderPercentList($ageCounts); ?>
                </div>
                <div class="admin-percent-block">
                    <h3>Provinsi asal mahasiswa</h3>
                    <?php renderPercentList($provinceCounts); ?>
                </div>
                <div class="admin-percent-block">
                    <h3>Pendaftaran wajah</h3>
                    <?php renderPercentList($faceCounts); ?>
                </div>
                <div class="admin-percent-block">
                    <h3>Status ruangan</h3>
                    <?php renderPercentList($roomCounts); ?>
                </div>
            </div>
        </section>
    </section>

    <section class="admin-panel admin-view-section" id="data-akun">
        <div class="admin-panel-heading">
            <div>
                <p class="home-eyebrow">KELOLA DATA</p>
                <h2>Data Mahasiswa dan Dosen</h2>
            </div>
            <span id="user-count"></span>
        </div>
        <div class="admin-toolbar">
            <div class="admin-tabs" role="group" aria-label="Pilih jenis akun">
                <button type="button" class="admin-tab is-active" data-type="mahasiswa" aria-pressed="true">Mahasiswa</button>
                <button type="button" class="admin-tab" data-type="dosen" aria-pressed="false">Dosen</button>
            </div>
            <input type="search" id="user-search" class="admin-search" placeholder="Cari nama, username, email..." aria-label="Cari data akun" />
        </div>
        <p id="user-status" class="admin-status" role="status">Memuat data...</p>
        <div class="attendance-table-wrap">
            <table class="attendance-table" id="user-table">
                <thead></thead>
                <tbody></tbody>
            </table>
        </div>
    </section>

    <!-- [TAMBAHAN CLAUDE] Poin c: penugasan dosen ke jadwal/mata kuliah -->
    <section class="admin-panel admin-view-section" id="penugasan-dosen">
        <div class="admin-panel-heading">
            <div>
                <p class="home-eyebrow">PENGAMPU MATA KULIAH</p>
                <h2>Penugasan Dosen</h2>
            </div>
        </div>
        <?php if (!$assignReady): ?>
            <p class="admin-empty">Tabel penugasan belum tersedia. Jalankan <code>Koneksi/Migration_Teacher_Course_Assignment.sql</code> terlebih dahulu.</p>
        <?php elseif (!$assignDosenList || !$assignScheduleList): ?>
            <p class="admin-empty">Belum ada akun dosen atau jadwal kuliah yang bisa ditugaskan.</p>
        <?php else: ?>
            <form id="assign-form" class="student-profile-form" novalidate>
                <label for="assign-dosen">Pilih dosen
                    <select id="assign-dosen">
                        <?php foreach ($assignDosenList as $assignDosen): ?>
                            <option value="<?= escapeHtml($assignDosen["Username"]) ?>"><?= escapeHtml($assignDosen["FullName"] . " (" . $assignDosen["Username"] . ")") ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <p class="admin-muted">Centang jadwal yang diampu dosen ini. Menyimpan akan menggantikan seluruh penugasan lama dosen tersebut.</p>
                <div class="admin-assignment-grid">
                    <?php foreach ($assignScheduleList as $assignSchedule): ?>
                        <label class="admin-assignment-option">
                            <input type="checkbox" name="schedule_ids[]" value="<?= (int) $assignSchedule["Id"] ?>" />
                            <span>
                                <strong><?= escapeHtml($assignSchedule["CourseName"]) ?></strong><br />
                                <small><?= escapeHtml($assignSchedule["DayName"]) ?> &middot;
                                    <?= $assignSchedule["StartTime"] && $assignSchedule["EndTime"]
                                        ? escapeHtml(substr($assignSchedule["StartTime"], 0, 5) . "-" . substr($assignSchedule["EndTime"], 0, 5))
                                        : "waktu menyusul" ?>
                                    &middot; <?= escapeHtml($assignSchedule["RoomName"]) ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p id="assign-status" class="admin-status" role="status"></p>
                <button type="submit" class="home-button" id="assign-save">Simpan penugasan</button>
            </form>
        <?php endif; ?>
    </section>
    <!-- [/TAMBAHAN CLAUDE] -->

    <section class="admin-panel admin-view-section" id="ruangan">
        <div class="admin-panel-heading">
            <div>
                <p class="home-eyebrow">STATUS AKSES</p>
                <h2>Ruangan</h2>
            </div>
        </div>
        <ul class="admin-ranking-list">
            <?php foreach ($rooms as $room): ?>
                <li>
                    <span><?= escapeHtml($room["RoomName"]) ?></span>
                    <strong><?= $room["RoomStatus"] === "open" ? "Terbuka" : "Terkunci" ?></strong>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="admin-panel admin-view-section" id="absensi">
        <div class="admin-panel-heading">
            <div>
                <p class="home-eyebrow">RIWAYAT KEHADIRAN</p>
                <h2>Absensi Mahasiswa</h2>
            </div>
            <span><?= count($attendanceRows) ?> data</span>
        </div>
        <?php if (count($attendanceRows) === 0): ?>
            <p class="admin-empty">Belum ada absensi yang tercatat.</p>
        <?php else: ?>
            <div class="attendance-table-wrap">
                <table class="attendance-table">
                    <thead>
                        <tr><th>Mahasiswa</th><th>Ruangan</th><th>Sidik Jari</th><th>Wajah</th><th>Waktu</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attendanceRows as $row): ?>
                            <tr>
                                <td><?= escapeHtml($row["Username"]) ?></td>
                                <td><?= escapeHtml($row["RoomName"]) ?></td>
                                <td><?= $row["FingerprintVerified"] ? "Berhasil" : "Belum" ?></td>
                                <td><?= $row["FaceVerified"] ? "Berhasil" : "Belum" ?></td>
                                <td><?= escapeHtml($row["VerifiedAt"]) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="admin-panel admin-view-section" id="pesan-bantuan">
        <div class="admin-panel-heading">
            <div>
                <p class="home-eyebrow">PUSAT BANTUAN & PESAN</p>
                <h2>Chat Mahasiswa & Dosen</h2>
            </div>
            <span id="admin-chat-unread-summary" class="admin-muted">Memuat percakapan...</span>
        </div>

        <div class="admin-chat-layout">
            <aside class="admin-chat-user-list">
                <div class="admin-chat-user-list-header">
                    <strong>Percakapan Masuk</strong>
                    <button type="button" id="refresh-chat-users" class="chat-refresh-btn" title="Segarkan daftar">🔄</button>
                </div>
                <div id="admin-chat-users-stream" class="admin-chat-users-stream">
                    <p class="admin-empty" style="padding: 16px;">Memuat pengguna...</p>
                </div>
            </aside>

            <div class="admin-chat-conversation">
                <div id="admin-chat-empty-state" class="admin-chat-empty-state">
                    <div style="font-size: 38px; margin-bottom: 8px;">💬</div>
                    <p>Pilih salah satu mahasiswa atau dosen dari daftar di sebelah kiri untuk melihat dan membalas pesan bantuan.</p>
                </div>

                <div id="admin-chat-active-box" class="admin-chat-active-box" hidden>
                    <div class="admin-chat-active-header">
                        <div>
                            <strong id="active-chat-user-name">-</strong>
                            <span id="active-chat-user-role" class="admin-chat-role-badge">-</span>
                        </div>
                        <span id="active-chat-user-username" class="admin-muted" style="font-size: 13px;"></span>
                    </div>

                    <div id="admin-chat-messages-container" class="admin-chat-messages-container">
                        <div id="admin-chat-stream" class="chat-stream"></div>
                    </div>

                    <form id="admin-chat-form" class="admin-chat-form">
                        <input type="text" id="admin-chat-input" class="chat-input-field" placeholder="Ketik balasan untuk pengguna..." autocomplete="off" required />
                        <button type="submit" id="admin-chat-send-btn" class="home-button" style="margin: 0; padding: 0 18px;">
                            Kirim
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </section>
      </div>
    </div>
</main>

<script>
(() => {
    const navigation = document.querySelector(".admin-app-nav");
    const views = Array.from(document.querySelectorAll(".admin-view-section"));
    if (!navigation || views.length === 0) return;

    const links = Array.from(navigation.querySelectorAll('a[href^="#"]'));

    function showView(id) {
        const activeView = views.find((view) => view.id === id) || views[0];
        views.forEach((view) => {
            view.hidden = view !== activeView;
        });
        links.forEach((link) => {
            if (link.hash === `#${activeView.id}`) {
                link.setAttribute("aria-current", "page");
            } else {
                link.removeAttribute("aria-current");
            }
        });
    }

    navigation.addEventListener("click", (event) => {
        const link = event.target.closest('a[href^="#"]');
        if (!link || !navigation.contains(link)) return;

        const view = views.find((candidate) => candidate.id === link.hash.slice(1));
        if (!view) return;

        event.preventDefault();
        if (window.location.hash !== link.hash) {
            window.history.pushState(null, "", link.hash);
        }
        showView(view.id);
        window.scrollTo(0, 0);
    });

    window.addEventListener("hashchange", () => showView(window.location.hash.slice(1)));
    window.addEventListener("popstate", () => showView(window.location.hash.slice(1)));
    showView(window.location.hash.slice(1));
})();
</script>

<dialog id="edit-dialog" class="admin-edit-dialog">
    <form id="edit-form" class="student-profile-form">
        <h2 id="edit-title">Ubah data</h2>
        <input type="hidden" name="username" />
        <p class="admin-muted" id="edit-username-label"></p>
        <div class="admin-form-grid">
            <label>Nama lengkap
                <input type="text" name="full_name" maxlength="100" required />
            </label>
            <label>Email
                <input type="email" name="email" maxlength="254" required />
            </label>
            <label>Jenis kelamin
                <select name="gender" required>
                    <option value="">Pilih</option>
                    <option value="Laki-laki">Laki-laki</option>
                    <option value="Perempuan">Perempuan</option>
                    <option value="Lainnya">Lainnya</option>
                </select>
            </label>
            <label data-only="mahasiswa">NIM
                <input type="text" name="nim" maxlength="50" />
            </label>
            <label data-only="mahasiswa">NIK (16 digit)
                <input type="text" name="nik" inputmode="numeric" maxlength="16" pattern="[0-9]{16}" />
            </label>
            <label data-only="mahasiswa">Tanggal lahir
                <input type="date" name="birth_date" />
            </label>
            <label data-only="mahasiswa">Negara
                <input type="text" name="country" maxlength="100" />
            </label>
            <label data-only="mahasiswa">Provinsi
                <input type="text" name="province" maxlength="100" />
            </label>
            <label data-only="mahasiswa">Kabupaten/kota
                <input type="text" name="regency" maxlength="100" />
            </label>
            <label data-only="mahasiswa">Kecamatan
                <input type="text" name="district" maxlength="100" />
            </label>
            <label data-only="mahasiswa">Desa/kelurahan
                <input type="text" name="village" maxlength="100" />
            </label>
        </div>
        <p id="edit-note" class="admin-notice" hidden></p>
        <p id="edit-message" class="admin-status" role="status"></p>
        <div class="admin-form-actions">
            <button type="submit" class="home-button" id="edit-save">Simpan perubahan</button>
            <button type="button" class="room-action lock-action" id="edit-cancel">Batal</button>
        </div>
    </form>
</dialog>

<script>
    (() => {
        const csrfToken = <?= json_encode($adminCsrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const apiUrl = "../Koneksi/Admin_Users.php";
        const columns = {
            mahasiswa: [
                ["Username", "Username"], ["NIM", "NIM"], ["NIK", "NIK"], ["FullName", "Nama"],
                ["Email", "Email"], ["Gender", "Jenis Kelamin"], ["BirthDate", "Tgl Lahir"], ["Address", "Alamat"]
            ],
            dosen: [
                ["Username", "Username"], ["FullName", "Nama"], ["Email", "Email"], ["Gender", "Jenis Kelamin"]
            ]
        };
        const fieldMap = [
            ["Username", "username"], ["FullName", "full_name"], ["Email", "email"], ["Gender", "gender"],
            ["NIM", "nim"], ["NIK", "nik"], ["BirthDate", "birth_date"], ["Country", "country"],
            ["Province", "province"], ["Regency", "regency"], ["District", "district"], ["Village", "village"]
        ];

        const tableHead = document.querySelector("#user-table thead");
        const tableBody = document.querySelector("#user-table tbody");
        const statusBox = document.getElementById("user-status");
        const countBox = document.getElementById("user-count");
        const searchBox = document.getElementById("user-search");
        const tabs = document.querySelectorAll(".admin-tab");
        const dialog = document.getElementById("edit-dialog");
        const form = document.getElementById("edit-form");
        const editTitle = document.getElementById("edit-title");
        const editUsername = document.getElementById("edit-username-label");
        const editNote = document.getElementById("edit-note");
        const editMessage = document.getElementById("edit-message");
        const editSave = document.getElementById("edit-save");

        let currentType = "mahasiswa";
        let rows = [];
        let genderSupported = true;

        function addressOf(row) {
            return [row.Village, row.District, row.Regency, row.Province, row.Country]
                .filter((part) => part && String(part).trim() !== "")
                .join(", ");
        }

        function cellValue(row, key) {
            return key === "Address" ? addressOf(row) : row[key];
        }

        function renderTable() {
            const query = searchBox.value.trim().toLowerCase();
            const config = columns[currentType];
            const visible = rows.filter((row) =>
                query === "" || config.some(([key]) => String(cellValue(row, key) ?? "").toLowerCase().includes(query))
            );

            tableHead.replaceChildren();
            const headRow = document.createElement("tr");
            config.forEach(([, label]) => {
                const th = document.createElement("th");
                th.textContent = label;
                headRow.appendChild(th);
            });
            const actionHead = document.createElement("th");
            actionHead.textContent = "Aksi";
            headRow.appendChild(actionHead);
            tableHead.appendChild(headRow);

            tableBody.replaceChildren();
            visible.forEach((row) => {
                const tr = document.createElement("tr");
                config.forEach(([key]) => {
                    const td = document.createElement("td");
                    const value = cellValue(row, key);
                    if (value === null || value === undefined || String(value).trim() === "") {
                        const empty = document.createElement("span");
                        empty.className = "admin-muted";
                        empty.textContent = "Belum diisi";
                        td.appendChild(empty);
                    } else {
                        td.textContent = value;
                    }
                    tr.appendChild(td);
                });
                const actionTd = document.createElement("td");
                const button = document.createElement("button");
                button.type = "button";
                button.className = "admin-edit-button";
                button.textContent = "Ubah";
                button.addEventListener("click", () => openEditor(row));
                actionTd.appendChild(button);
                tr.appendChild(actionTd);
                tableBody.appendChild(tr);
            });

            countBox.textContent = `${visible.length} dari ${rows.length} akun`;
            statusBox.textContent = rows.length === 0
                ? `Belum ada akun ${currentType}.`
                : (visible.length === 0 ? "Tidak ada data yang cocok dengan pencarian." : "");
        }

        async function loadRows() {
            statusBox.textContent = "Memuat data...";
            tableBody.replaceChildren();
            try {
                const response = await fetch(`${apiUrl}?type=${currentType}`, { headers: { Accept: "application/json" } });
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.message);
                rows = result.rows;
                genderSupported = result.gender_supported !== false;
                renderTable();
            } catch (error) {
                rows = [];
                countBox.textContent = "";
                statusBox.textContent = error.message || "Data tidak dapat dimuat.";
            }
        }

        function openEditor(row) {
            form.reset();
            fieldMap.forEach(([key, name]) => {
                const input = form.elements[name];
                if (input) input.value = row[key] ?? "";
            });
            editTitle.textContent = currentType === "mahasiswa" ? "Ubah data mahasiswa" : "Ubah data dosen";
            editUsername.textContent = `Username: ${row.Username} (tidak dapat diubah)`;
            form.querySelectorAll("[data-only]").forEach((label) => {
                const show = label.dataset.only === currentType;
                label.hidden = !show;
                label.querySelectorAll("input, select").forEach((input) => { input.disabled = !show; });
            });

            const genderBlocked = currentType === "dosen" && !genderSupported;
            form.elements.gender.disabled = genderBlocked;
            form.elements.gender.required = !genderBlocked;
            editSave.disabled = genderBlocked;
            editNote.hidden = !genderBlocked;
            editNote.textContent = genderBlocked
                ? "Import Koneksi/Migration_Admin_Dosen_Gender.sql terlebih dahulu agar data dosen dapat diubah."
                : "";
            editMessage.textContent = "";
            dialog.showModal();
        }

        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            editSave.disabled = true;
            editMessage.textContent = "Menyimpan...";
            const body = new FormData(form);
            body.set("action", currentType === "mahasiswa" ? "update_mahasiswa" : "update_dosen");
            body.set("csrf_token", csrfToken);
            try {
                const response = await fetch(apiUrl, { method: "POST", body, headers: { Accept: "application/json" } });
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.message);
                editMessage.textContent = result.message;
                // Muat ulang agar kartu dan persentase ikut diperbarui.
                history.replaceState(null, "", "#data-akun");
                setTimeout(() => location.reload(), 700);
            } catch (error) {
                editMessage.textContent = error.message || "Data tidak dapat disimpan.";
                editSave.disabled = false;
            }
        });

        document.getElementById("edit-cancel").addEventListener("click", () => dialog.close());

        tabs.forEach((tab) => {
            tab.addEventListener("click", () => {
                currentType = tab.dataset.type;
                tabs.forEach((other) => {
                    const active = other === tab;
                    other.classList.toggle("is-active", active);
                    other.setAttribute("aria-pressed", String(active));
                });
                searchBox.value = "";
                loadRows();
            });
        });

        searchBox.addEventListener("input", renderTable);
        loadRows();
    })();
</script>

<?php if ($assignReady && $assignDosenList && $assignScheduleList): ?>
<!-- [TAMBAHAN CLAUDE] Poin c: skrip form penugasan dosen -->
<script>
(() => {
    const form = document.getElementById("assign-form");
    const dosenSelect = document.getElementById("assign-dosen");
    const statusLine = document.getElementById("assign-status");
    const saveButton = document.getElementById("assign-save");
    const checkboxes = Array.from(form.querySelectorAll('input[name="schedule_ids[]"]'));
    const assignmentMap = <?= json_encode($assignMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_FORCE_OBJECT) ?>;
    const assignCsrf = <?= json_encode($adminCsrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    function applySelection() {
        const assigned = (assignmentMap[dosenSelect.value] || []).map(String);
        checkboxes.forEach((box) => { box.checked = assigned.includes(box.value); });
        statusLine.textContent = "";
    }

    dosenSelect.addEventListener("change", applySelection);
    applySelection();

    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        const chosen = checkboxes.filter((box) => box.checked).map((box) => box.value);
        const body = new FormData();
        body.set("csrf_token", assignCsrf);
        body.set("dosen_username", dosenSelect.value);
        if (chosen.length === 0) {
            body.set("clear_assignments", "1");
        } else {
            chosen.forEach((id) => body.append("schedule_ids[]", id));
        }
        saveButton.disabled = true;
        statusLine.textContent = "Menyimpan...";
        try {
            const response = await fetch("../Koneksi/Save_Teacher_Course_Assignment.php", {
                method: "POST", body, headers: { Accept: "application/json" }
            });
            const result = await response.json();
            statusLine.textContent = result.message || (response.ok ? "Tersimpan." : "Gagal menyimpan.");
            if (response.ok && result.success) {
                assignmentMap[dosenSelect.value] = chosen.map(Number);
            }
        } catch (error) {
            statusLine.textContent = "Tidak dapat terhubung ke server.";
        } finally {
            saveButton.disabled = false;
        }
    });
})();
</script>
<!-- [/TAMBAHAN CLAUDE] -->
<?php endif; ?>

<script>
(function() {
    const navBadge = document.getElementById("admin-nav-chat-badge");
    const summarySpan = document.getElementById("admin-chat-unread-summary");
    const usersStream = document.getElementById("admin-chat-users-stream");
    const refreshBtn = document.getElementById("refresh-chat-users");
    const emptyState = document.getElementById("admin-chat-empty-state");
    const activeBox = document.getElementById("admin-chat-active-box");
    const activeName = document.getElementById("active-chat-user-name");
    const activeRole = document.getElementById("active-chat-user-role");
    const activeUsername = document.getElementById("active-chat-user-username");
    const messagesContainer = document.getElementById("admin-chat-messages-container");
    const chatStream = document.getElementById("admin-chat-stream");
    const chatForm = document.getElementById("admin-chat-form");
    const chatInput = document.getElementById("admin-chat-input");
    const sendBtn = document.getElementById("admin-chat-send-btn");

    let activeUser = null;
    let pollInterval = null;
    let lastMsgCount = 0;

    function escape(str) {
        const div = document.createElement("div");
        div.textContent = str || "";
        return div.innerHTML;
    }

    function formatTime(dateStr) {
        try {
            const date = new Date(dateStr);
            return date.toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" });
        } catch (e) {
            return "";
        }
    }

    async function loadConversations() {
        try {
            const res = await fetch("../Koneksi/Admin_Chat.php?action=get_conversations", { headers: { Accept: "application/json" } });
            const data = await res.json();
            if (!data.success) return;

            const convs = data.conversations || [];
            let totalUnread = 0;

            if (convs.length === 0) {
                usersStream.innerHTML = `<p class="admin-empty" style="padding: 16px;">Belum ada percakapan masuk dari mahasiswa atau dosen.</p>`;
                summarySpan.textContent = "0 percakapan";
                if (navBadge) navBadge.hidden = true;
                return;
            }

            usersStream.innerHTML = "";
            convs.forEach((c) => {
                totalUnread += parseInt(c.UnreadCount || 0, 10);
                const item = document.createElement("div");
                item.className = "admin-chat-user-item" + (activeUser && activeUser.Username === c.Username ? " is-active" : "");
                item.dataset.username = c.Username;

                const unreadPill = c.UnreadCount > 0 ? `<span class="admin-chat-unread-pill">${c.UnreadCount}</span>` : "";

                item.innerHTML = `
                    <div class="admin-chat-item-header">
                        <span class="admin-chat-item-name">${escape(c.DisplayName || c.Username)}</span>
                        <span class="admin-chat-item-time">${formatTime(c.LastMessageTime)}</span>
                    </div>
                    <div class="admin-chat-item-body">
                        <span class="admin-chat-role-badge ${escape(c.Role)}">${escape(c.Role)}</span>
                        <span class="admin-chat-item-preview">${escape(c.LastMessage || "")}</span>
                        ${unreadPill}
                    </div>
                `;

                item.addEventListener("click", () => selectUser(c));
                usersStream.appendChild(item);
            });

            summarySpan.textContent = `${convs.length} percakapan (${totalUnread} belum dibaca)`;
            if (navBadge) {
                if (totalUnread > 0) {
                    navBadge.textContent = totalUnread > 99 ? "99+" : totalUnread;
                    navBadge.hidden = false;
                } else {
                    navBadge.hidden = true;
                }
            }
        } catch (e) {}
    }

    async function selectUser(userObj) {
        activeUser = userObj;
        emptyState.hidden = true;
        activeBox.hidden = false;

        activeName.textContent = userObj.DisplayName || userObj.Username;
        activeRole.textContent = userObj.Role;
        activeRole.className = `admin-chat-role-badge ${userObj.Role}`;
        activeUsername.textContent = `@${userObj.Username}`;

        document.querySelectorAll(".admin-chat-user-item").forEach((el) => {
            el.classList.toggle("is-active", el.dataset.username === userObj.Username);
        });

        lastMsgCount = 0;
        await loadMessages(true);
        chatInput.focus();

        if (pollInterval) clearInterval(pollInterval);
        pollInterval = setInterval(() => loadMessages(false), 3500);
    }

    async function loadMessages(scrollToBottom = false) {
        if (!activeUser) return;
        try {
            const res = await fetch(`../Koneksi/Admin_Chat.php?action=get_messages&target_user=${encodeURIComponent(activeUser.Username)}`, { headers: { Accept: "application/json" } });
            const data = await res.json();
            if (!data.success) return;

            const messages = data.messages || [];
            if (messages.length !== lastMsgCount) {
                renderMessages(messages);
                lastMsgCount = messages.length;
                if (scrollToBottom || messagesContainer.scrollHeight - messagesContainer.scrollTop - messagesContainer.clientHeight < 120) {
                    messagesContainer.scrollTop = messagesContainer.scrollHeight;
                }
            }
        } catch (e) {}
    }

    function renderMessages(messages) {
        chatStream.innerHTML = "";
        messages.forEach((msg) => {
            const isAdmin = msg.SenderRole === "admin";
            const row = document.createElement("div");
            row.className = isAdmin ? "chat-row chat-row-user" : "chat-row chat-row-admin";

            const bubble = document.createElement("div");
            bubble.className = isAdmin ? "chat-bubble chat-bubble-user" : "chat-bubble chat-bubble-admin";
            bubble.innerHTML = `
                <div class="chat-bubble-text">${escape(msg.Message)}</div>
                <span class="chat-bubble-time">${formatTime(msg.CreatedAt)}</span>
            `;

            row.appendChild(bubble);
            chatStream.appendChild(row);
        });
    }

    chatForm.addEventListener("submit", async (e) => {
        e.preventDefault();
        if (!activeUser) return;
        const text = chatInput.value.trim();
        if (!text) return;

        sendBtn.disabled = true;
        const formData = new FormData();
        formData.set("action", "send_message");
        formData.set("receiver", activeUser.Username);
        formData.set("message", text);

        try {
            const res = await fetch("../Koneksi/Admin_Chat.php", {
                method: "POST",
                body: formData,
                headers: { Accept: "application/json" }
            });
            const data = await res.json();
            if (res.ok && data.success) {
                chatInput.value = "";
                await loadMessages(true);
                loadConversations();
            } else {
                alert(data.message || "Gagal mengirim balasan.");
            }
        } catch (err) {
            alert("Gagal terhubung ke server chat.");
        } finally {
            sendBtn.disabled = false;
            chatInput.focus();
        }
    });

    refreshBtn.addEventListener("click", loadConversations);
    loadConversations();
    setInterval(loadConversations, 8000);
})();
</script>
</body>
</html>
