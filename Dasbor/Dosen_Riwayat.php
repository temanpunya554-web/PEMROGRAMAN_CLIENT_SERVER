<?php
require_once __DIR__ . "/Dosen_Auth.php";
$activeDosenPage = "history";

$selectedDate = trim($_GET["date"] ?? date("Y-m-d"));
$dateParsed = DateTime::createFromFormat("Y-m-d", $selectedDate);
if (!$dateParsed || $dateParsed->format("Y-m-d") !== $selectedDate) {
    $selectedDate = date("Y-m-d");
}

try {
    $attendanceStatement = mysqli_prepare(
        $conn,
        "SELECT attendance.Username, COALESCE(profiles.FullName, attendance.Username) AS FullName,
                attendance.RoomName, attendance.FingerprintVerified, attendance.FaceVerified, attendance.VerifiedAt
         FROM class_attendance AS attendance
         LEFT JOIN mahasiswa_profiles AS profiles ON profiles.Username = attendance.Username
         WHERE DATE(attendance.VerifiedAt) = ?
         ORDER BY attendance.VerifiedAt DESC"
    );
    mysqli_stmt_bind_param($attendanceStatement, "s", $selectedDate);
    mysqli_stmt_execute($attendanceStatement);
    $attendanceResult = mysqli_stmt_get_result($attendanceStatement);
    $attendanceRows = mysqli_fetch_all($attendanceResult, MYSQLI_ASSOC);
    mysqli_stmt_close($attendanceStatement);
} catch (Throwable $error) {
    error_log("Dosen attendance history error: " . $error->getMessage());
    http_response_code(500);
    exit("Riwayat kehadiran tidak dapat dimuat.");
}

require __DIR__ . "/Dosen_Header.php";
?>
<section class="student-page-heading">
    <p class="home-eyebrow">RIWAYAT KEHADIRAN</p>
    <h1>Mahasiswa yang mengisi absensi</h1>
    <p>Pilih tanggal untuk melihat atau mengekspor data kehadiran.</p>
</section>

<section class="student-content-panel">
    <form class="student-history-filters dosen-history-filters" method="get" action="Dosen_Riwayat.php">
        <label for="attendance-date">
            Tanggal absensi
            <input id="attendance-date" name="date" type="date" value="<?= dosenEscape($selectedDate) ?>" />
        </label>
        <button class="home-button" type="submit">Tampilkan</button>
        <a class="home-button"
           href="../Koneksi/Export_Attendance.php?start_date=<?= urlencode($selectedDate) ?>&amp;end_date=<?= urlencode($selectedDate) ?>">
            Ekspor ke Excel
        </a>
    </form>

    <p class="student-history-count"><?= count($attendanceRows) ?> data pada tanggal ini.</p>

    <?php if (!$attendanceRows): ?>
        <p class="admin-empty">Belum ada absensi yang tercatat.</p>
    <?php else: ?>
        <div class="attendance-table-wrap">
            <table class="attendance-table">
                <thead>
                    <tr><th>NIM</th><th>Nama</th><th>Ruangan</th><th>Sidik Jari</th><th>Wajah</th><th>Waktu</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($attendanceRows as $row): ?>
                        <tr>
                            <td><?= dosenEscape($row["Username"]) ?></td>
                            <td><?= dosenEscape($row["FullName"]) ?></td>
                            <td><?= dosenEscape($row["RoomName"]) ?></td>
                            <td><?= $row["FingerprintVerified"] ? "Berhasil" : "Belum" ?></td>
                            <td><?= $row["FaceVerified"] ? "Berhasil" : "Belum" ?></td>
                            <td><?= dosenEscape($row["VerifiedAt"]) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . "/Dosen_Footer.php"; ?>
