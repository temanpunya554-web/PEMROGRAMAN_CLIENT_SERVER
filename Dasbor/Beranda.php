<?php
require_once __DIR__ . "/Student_Auth.php";
$activeStudentPage = "home";

$dayNames = [1 => "Senin", "Selasa", "Rabu", "Kamis", "Jumat", "Sabtu", "Minggu"];
$todayName = $dayNames[(int) date("N")];
$scheduleStatement = mysqli_prepare(
    $conn,
    "SELECT `CourseName`, `StartTime`, `EndTime`, `RoomName`, `ScheduleType`
     FROM `class_schedules` WHERE `DayName` = ?
     ORDER BY `StartTime` IS NULL, `StartTime`, `Id`"
);
mysqli_stmt_bind_param($scheduleStatement, "s", $todayName);
mysqli_stmt_execute($scheduleStatement);
$scheduleResult = mysqli_stmt_get_result($scheduleStatement);
$todaySchedule = mysqli_fetch_all($scheduleResult, MYSQLI_ASSOC);
mysqli_stmt_close($scheduleStatement);

$attendanceStatement = mysqli_prepare(
    $conn,
    "SELECT COUNT(*) FROM `class_attendance`
     WHERE `Username` = ? AND DATE(`VerifiedAt`) = CURDATE()"
);
mysqli_stmt_bind_param($attendanceStatement, "s", $studentUsername);
mysqli_stmt_execute($attendanceStatement);
mysqli_stmt_bind_result($attendanceStatement, $todayAttendanceCount);
mysqli_stmt_fetch($attendanceStatement);
mysqli_stmt_close($attendanceStatement);

$faceStatement = mysqli_prepare(
    $conn,
    "SELECT `UpdatedAt` FROM `mahasiswa_face_enrollments` WHERE `Username` = ? LIMIT 1"
);
mysqli_stmt_bind_param($faceStatement, "s", $studentUsername);
mysqli_stmt_execute($faceStatement);
mysqli_stmt_bind_result($faceStatement, $faceUpdatedAt);
$faceEnrolled = mysqli_stmt_fetch($faceStatement);
mysqli_stmt_close($faceStatement);

// Rekap kehadiran per mata kuliah (berdasarkan pencatatan dosen).
$courseAttendanceStatement = mysqli_prepare(
    $conn,
    "SELECT schedules.`Id` AS ScheduleId, schedules.`CourseName`,
            COUNT(records.`Id`) AS TotalPertemuan,
            SUM(CASE WHEN records.`Status` = 'Hadir' THEN 1 ELSE 0 END) AS TotalHadir,
            SUM(CASE WHEN records.`Status` IN ('Izin','Sakit','Alpa') THEN 1 ELSE 0 END) AS TotalTidakHadir
     FROM `class_schedules` AS schedules
     LEFT JOIN `course_attendance` AS records
       ON records.`ScheduleId` = schedules.`Id` AND records.`Username` = ?
     GROUP BY schedules.`Id`, schedules.`CourseName`
     ORDER BY schedules.`CourseName`"
);
mysqli_stmt_bind_param($courseAttendanceStatement, "s", $studentUsername);
mysqli_stmt_execute($courseAttendanceStatement);
$courseAttendanceResult = mysqli_stmt_get_result($courseAttendanceStatement);
$courseAttendanceRows = mysqli_fetch_all($courseAttendanceResult, MYSQLI_ASSOC);
mysqli_stmt_close($courseAttendanceStatement);

require __DIR__ . "/Student_Header.php";
?>
<div id="network-status-banner" class="network-status-banner" hidden></div>

<div class="notif-banner-card" id="notif-banner">
    <div>
        <p><strong>🔔 Pengingat Jadwal & Notifikasi Absensi</strong></p>
        <span style="font-size: 13px; color: #475569;">Dapatkan pengingat di perangkat saat jadwal kelas dimulai dan ruangan kelas dibuka.</span>
    </div>
    <button type="button" id="enable-notif-btn">Aktifkan Notifikasi</button>
</div>

<section class="student-page-heading">
    <p class="home-eyebrow">DASHBOARD MAHASISWA</p>
    <h1>Selamat datang, <?= studentEscape($studentDisplayName) ?>.</h1>
    <p>Informasi singkat akun dan kegiatan akademikmu hari ini.</p>
</section>

<section class="student-summary-grid" aria-label="Ringkasan mahasiswa">
    <article class="student-summary-card">
        <span>Hari ini</span>
        <strong><?= studentEscape($todayName) ?></strong>
        <a href="Absensi.php">Buka halaman absensi</a>
    </article>
    <article class="student-summary-card">
        <span>Absensi hari ini</span>
        <strong><?= (int) $todayAttendanceCount ?></strong>
        <a href="Riwayat.php">Lihat riwayat</a>
    </article>
    <article class="student-summary-card">
        <span>Pendaftaran wajah</span>
        <strong><?= $faceEnrolled ? "Terdaftar" : "Belum terdaftar" ?></strong>
        <a href="Biometrik.php">Kelola biometrik</a>
    </article>
</section>

<section class="student-content-panel">
    <div class="student-panel-heading">
        <div>
            <p class="home-eyebrow">REKAP AKADEMIK</p>
            <h2>Persentase kehadiran per mata kuliah</h2>
        </div>
        <a href="Riwayat.php">Riwayat absensi</a>
    </div>
    <?php if (!$courseAttendanceRows): ?>
        <p class="admin-empty">Belum ada mata kuliah yang terdaftar.</p>
    <?php else: ?>
        <div class="attendance-table-wrap">
            <table class="attendance-table">
                <thead><tr><th>Mata Kuliah</th><th>Pertemuan tercatat</th><th>Hadir</th><th>Tidak hadir</th><th>Persentase</th></tr></thead>
                <tbody>
                <?php foreach ($courseAttendanceRows as $course): ?>
                    <?php $total = (int) $course["TotalPertemuan"]; $present = (int) $course["TotalHadir"]; $percentage = $total > 0 ? round(($present / $total) * 100, 1) : 0; ?>
                    <tr>
                        <td><?= studentEscape($course["CourseName"]) ?></td>
                        <td><?= $total ?></td><td><?= $present ?></td><td><?= (int) $course["TotalTidakHadir"] ?></td>
                        <td><strong><?= number_format($percentage, 1) ?>%</strong><?php if ($total > 0 && $percentage < 75): ?><div class="form-help" style="color:#b45309;font-weight:700">⚠ Kehadiran di bawah batas minimum 75%</div><?php elseif ($total > 0): ?><div class="form-help" style="color:#0e6251">Memenuhi batas minimum 75%</div><?php endif; ?><div style="height:8px;background:#e2e8f0;border-radius:8px;overflow:hidden;min-width:100px;margin-top:6px"><div style="height:8px;width:<?= $percentage ?>%;background:#0e6251;border-radius:8px"></div></div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="form-help">Persentase dihitung dari jumlah status Hadir dibagi seluruh pertemuan yang sudah dicatat dosen. Batas minimum kehadiran adalah 75%.</p>
    <?php endif; ?>
</section>

<section class="student-content-panel">
    <div class="student-panel-heading">
        <div>
            <p class="home-eyebrow">JADWAL KELAS A</p>
            <h2>Jadwal <?= studentEscape($todayName) ?></h2>
        </div>
        <a href="Absensi.php">Ke absensi</a>
    </div>
    <?php if (!$todaySchedule): ?>
        <p class="admin-empty">Tidak ada jadwal kuliah yang tercatat untuk hari ini.</p>
    <?php else: ?>
        <div class="attendance-table-wrap">
            <table class="attendance-table">
                <thead>
                    <tr><th>Mata Kuliah</th><th>Waktu</th><th>Ruangan</th><th>Jenis</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($todaySchedule as $schedule): ?>
                        <tr>
                            <td><?= studentEscape($schedule["CourseName"]) ?></td>
                            <td>
                                <?= $schedule["StartTime"] && $schedule["EndTime"]
                                    ? studentEscape(substr($schedule["StartTime"], 0, 5) . " - " . substr($schedule["EndTime"], 0, 5))
                                    : "Waktu menyusul" ?>
                            </td>
                            <td><?= studentEscape($schedule["RoomName"]) ?></td>
                            <td><?= studentEscape($schedule["ScheduleType"]) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="student-content-panel">
    <div class="student-panel-heading">
        <div>
            <p class="home-eyebrow">PINTASAN</p>
            <h2>Kelola akun</h2>
        </div>
    </div>
    <div class="student-quick-links">
        <a href="Profil.php">Lihat atau ubah biodata</a>
        <a href="Biometrik.php">Daftar atau perbarui foto wajah</a>
        <a href="Absensi.php">Mulai absensi</a>
    </div>
</section>

<script>
    const networkBanner = document.getElementById("network-status-banner");
    const notifBtn = document.getElementById("enable-notif-btn");
    const todayScheduleData = <?= json_encode($todaySchedule, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    // Cache jadwal hari ini untuk mode offline
    try {
        localStorage.setItem("siabak_today_schedule", JSON.stringify(todayScheduleData));
    } catch (e) {}

    // Deteksi status koneksi online / offline
    function updateNetwork() {
        if (!navigator.onLine) {
            networkBanner.textContent = "⚠️ Perangkat Anda dalam mode offline. Data jadwal tetap tersimpan di perangkat.";
            networkBanner.className = "network-status-banner offline";
            networkBanner.hidden = false;
        } else {
            networkBanner.textContent = "✅ Anda kembali online.";
            networkBanner.className = "network-status-banner online";
            networkBanner.hidden = false;
            setTimeout(() => { networkBanner.hidden = true; }, 3000);
        }
    }
    window.addEventListener("online", updateNetwork);
    window.addEventListener("offline", updateNetwork);
    if (!navigator.onLine) updateNetwork();

    // Pengaturan Notifikasi Web / Perangkat
    function checkNotificationStatus() {
        if (!("Notification" in window)) {
            notifBtn.textContent = "Notifikasi Tidak Didukung";
            notifBtn.disabled = true;
            return;
        }
        if (Notification.permission === "granted") {
            notifBtn.textContent = "✅ Notifikasi Aktif";
            notifBtn.style.background = "#0e6251";
            notifBtn.style.borderColor = "#0e6251";
        } else if (Notification.permission === "denied") {
            notifBtn.textContent = "Notifikasi Diblokir";
            notifBtn.disabled = true;
        }
    }

    notifBtn.addEventListener("click", async () => {
        if (!("Notification" in window)) return;
        try {
            const permission = await Notification.requestPermission();
            if (permission === "granted") {
                checkNotificationStatus();
                new Notification("SIABAK Akademik", {
                    body: "Pengingat berhasil diaktifkan! Anda akan menerima pemberitahuan saat jadwal kuliah berlangsung.",
                    icon: "../Logo.png"
                });
            }
        } catch (e) {}
    });

    checkNotificationStatus();
</script>
<?php require __DIR__ . "/Student_Footer.php"; ?>
