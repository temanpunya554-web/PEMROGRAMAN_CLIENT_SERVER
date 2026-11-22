<?php
require_once __DIR__ . "/Student_Auth.php";
$activeStudentPage = "history";
$historyDate = trim($_GET["date"] ?? "");
$roomFilter = trim($_GET["room"] ?? "");
$historyNotice = "";

if ($historyDate !== "") {
    $parsedDate = DateTime::createFromFormat("Y-m-d", $historyDate);
    if (!$parsedDate || $parsedDate->format("Y-m-d") !== $historyDate) {
        $historyDate = "";
        $historyNotice = "Format tanggal tidak valid. Ditampilkan absensi terbaru.";
    }
}

$hasScheduleColumn = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM `class_attendance` LIKE 'ScheduleId'")) === 1;
$courseSelect = $hasScheduleColumn ? "schedules.`CourseName`" : "NULL AS `CourseName`";
$courseJoin = $hasScheduleColumn ? "LEFT JOIN `class_schedules` AS schedules ON schedules.`Id` = attendance.`ScheduleId`" : "";
$historySql = "SELECT attendance.`RoomName`, {$courseSelect}, attendance.`FingerprintVerified`,
                      attendance.`FaceVerified`, attendance.`VerifiedAt`
               FROM `class_attendance` AS attendance
               {$courseJoin}
               WHERE attendance.`Username` = ?";
$historyTypes = "s";
$historyParams = [$studentUsername];
if ($historyDate !== "") {
    $historySql .= " AND DATE(attendance.`VerifiedAt`) = ?";
    $historyTypes .= "s";
    $historyParams[] = $historyDate;
}
if ($roomFilter !== "") {
    $historySql .= " AND attendance.`RoomName` = ?";
    $historyTypes .= "s";
    $historyParams[] = $roomFilter;
}
$historySql .= " ORDER BY attendance.`VerifiedAt` DESC LIMIT 200";
$historyStatement = mysqli_prepare($conn, $historySql);
mysqli_stmt_bind_param($historyStatement, $historyTypes, ...$historyParams);
mysqli_stmt_execute($historyStatement);
$historyResult = mysqli_stmt_get_result($historyStatement);
$attendanceHistory = mysqli_fetch_all($historyResult, MYSQLI_ASSOC);
mysqli_stmt_close($historyStatement);

$roomResult = mysqli_query($conn, "SELECT DISTINCT `RoomName` FROM `class_rooms` ORDER BY `RoomName`");
$historyRooms = mysqli_fetch_all($roomResult, MYSQLI_ASSOC);
require __DIR__ . "/Student_Header.php";
?>
<section class="student-page-heading">
    <p class="home-eyebrow">CATATAN KEHADIRAN</p>
    <h1>Riwayat absensi</h1>
    <p>Riwayat yang tercatat untuk akun <?= studentEscape($studentDisplayName) ?>.</p>
</section>

<section class="student-content-panel">
    <form class="student-history-filters" method="get" action="Riwayat.php">
        <label for="history-date">Tanggal
            <input id="history-date" type="date" name="date" value="<?= studentEscape($historyDate) ?>" />
        </label>
        <label for="history-room">Ruangan
            <select id="history-room" name="room">
                <option value="">Semua ruangan</option>
                <?php foreach ($historyRooms as $historyRoom): ?>
                    <option value="<?= studentEscape($historyRoom["RoomName"]) ?>"
                        <?= $roomFilter === $historyRoom["RoomName"] ? "selected" : "" ?>>
                        <?= studentEscape($historyRoom["RoomName"]) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="home-button" type="submit">Terapkan filter</button>
        <a class="student-clear-filter" href="Riwayat.php">Hapus filter</a>
    </form>

    <?php if ($historyNotice !== ""): ?>
        <p class="community-data-status" role="status"><?= studentEscape($historyNotice) ?></p>
    <?php endif; ?>
    <p class="student-history-count">Menampilkan <?= count($attendanceHistory) ?> catatan terbaru (maksimal 200).</p>

    <?php if (!$attendanceHistory): ?>
        <p class="admin-empty">Belum ada data absensi untuk filter yang dipilih.</p>
    <?php else: ?>
        <div class="attendance-table-wrap">
            <table class="attendance-table">
                <thead>
                    <tr><th>Mata kuliah</th><th>Ruangan</th><th>Sidik jari</th><th>Wajah</th><th>Waktu</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($attendanceHistory as $attendance): ?>
                        <tr>
                            <td><?= $attendance["CourseName"] !== null ? studentEscape($attendance["CourseName"]) : "-" ?></td>
                            <td><?= studentEscape($attendance["RoomName"]) ?></td>
                            <td><?= $attendance["FingerprintVerified"] ? "Tercatat" : "Tidak tercatat" ?></td>
                            <td><?= $attendance["FaceVerified"] ? "Tercatat" : "Tidak tercatat" ?></td>
                            <td><?= studentEscape($attendance["VerifiedAt"]) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . "/Student_Footer.php"; ?>
