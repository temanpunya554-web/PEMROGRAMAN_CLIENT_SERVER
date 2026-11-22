<?php
require_once __DIR__ . "/Dosen_Auth.php";
$activeDosenPage = "schedule";

try {
    $scheduleResult = mysqli_query(
        $conn,
        "SELECT DayName, CourseName, StartTime, EndTime, RoomName, ScheduleType
         FROM class_schedules
         ORDER BY FIELD(DayName, 'Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'),
                  StartTime IS NULL, StartTime, Id"
    );
    $scheduleRows = mysqli_fetch_all($scheduleResult, MYSQLI_ASSOC);
} catch (Throwable $error) {
    error_log("Dosen schedule error: " . $error->getMessage());
    http_response_code(500);
    exit("Jadwal kelas tidak dapat dimuat.");
}

require __DIR__ . "/Dosen_Header.php";
?>
<section class="student-page-heading">
    <p class="home-eyebrow">JADWAL KELAS A</p>
    <h1>Jadwal mata kuliah</h1>
    <p>Daftar jadwal kuliah beserta ruangan yang digunakan.</p>
</section>

<section class="student-content-panel">
    <?php if (!$scheduleRows): ?>
        <p class="admin-empty">Belum ada jadwal kuliah yang tercatat.</p>
    <?php else: ?>
        <div class="attendance-table-wrap">
            <table class="attendance-table">
                <thead>
                    <tr><th>Hari</th><th>Mata Kuliah</th><th>Waktu</th><th>Ruangan</th><th>Jenis</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($scheduleRows as $schedule): ?>
                        <tr>
                            <td><?= dosenEscape($schedule["DayName"]) ?></td>
                            <td><?= dosenEscape($schedule["CourseName"]) ?></td>
                            <td>
                                <?= $schedule["StartTime"] && $schedule["EndTime"]
                                    ? dosenEscape(substr($schedule["StartTime"], 0, 5) . " - " . substr($schedule["EndTime"], 0, 5))
                                    : "Waktu menyusul" ?>
                            </td>
                            <td><?= dosenEscape($schedule["RoomName"]) ?></td>
                            <td><?= dosenEscape($schedule["ScheduleType"]) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . "/Dosen_Footer.php"; ?>
