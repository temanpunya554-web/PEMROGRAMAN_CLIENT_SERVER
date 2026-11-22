<?php
require_once __DIR__ . "/Dosen_Auth.php";
$activeDosenPage = "attendance";
$notice = "";
$noticeType = "success";
$selectedScheduleId = filter_input(INPUT_GET, "schedule_id", FILTER_VALIDATE_INT);
$selectedDate = trim($_GET["date"] ?? date("Y-m-d"));
$dateParsed = DateTime::createFromFormat("Y-m-d", $selectedDate);
if (!$dateParsed || $dateParsed->format("Y-m-d") !== $selectedDate) {
    $selectedDate = date("Y-m-d");
}

try {
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $submittedToken = $_POST["csrf_token"] ?? "";
        if (!is_string($submittedToken) || !hash_equals($roomCsrfToken, $submittedToken)) {
            throw new RuntimeException("Permintaan tidak valid. Muat ulang halaman.");
        }
        $postScheduleId = filter_input(INPUT_POST, "schedule_id", FILTER_VALIDATE_INT);
        $postDate = trim($_POST["attendance_date"] ?? "");
        $parsedPostDate = DateTime::createFromFormat("Y-m-d", $postDate);
        if (!$postScheduleId || !$parsedPostDate || $parsedPostDate->format("Y-m-d") !== $postDate) {
            throw new RuntimeException("Mata kuliah atau tanggal tidak valid.");
        }
        $scheduleCheck = mysqli_prepare($conn, "SELECT 1 FROM `dosen_course_assignments` WHERE `ScheduleId` = ? AND `DosenUsername` = ? LIMIT 1");
        mysqli_stmt_bind_param($scheduleCheck, "is", $postScheduleId, $dosenUsername);
        mysqli_stmt_execute($scheduleCheck);
        mysqli_stmt_store_result($scheduleCheck);
        $scheduleExists = mysqli_stmt_num_rows($scheduleCheck) === 1;
        mysqli_stmt_close($scheduleCheck);
        if (!$scheduleExists) throw new RuntimeException("Mata kuliah tidak ditemukan atau bukan mata kuliah yang Anda ampu.");

        $statuses = $_POST["status"] ?? [];
        if (!is_array($statuses)) throw new RuntimeException("Data kehadiran tidak valid.");
        mysqli_begin_transaction($conn);
        $save = mysqli_prepare($conn,
            "INSERT INTO `course_attendance` (`ScheduleId`,`Username`,`AttendanceDate`,`Status`,`MarkedBy`)\n             VALUES (?,?,?,?,?)\n             ON DUPLICATE KEY UPDATE `Status` = VALUES(`Status`), `MarkedBy` = VALUES(`MarkedBy`), `UpdatedAt` = CURRENT_TIMESTAMP"
        );
        foreach ($statuses as $studentUsername => $status) {
            if (!is_string($studentUsername) || !in_array($status, ["Hadir", "Izin", "Sakit", "Alpa"], true)) continue;
            $studentCheck = mysqli_prepare($conn, "SELECT 1 FROM `login` WHERE `Username` = ? AND `Role` = 'mahasiswa' LIMIT 1");
            mysqli_stmt_bind_param($studentCheck, "s", $studentUsername);
            mysqli_stmt_execute($studentCheck);
            mysqli_stmt_store_result($studentCheck);
            $validStudent = mysqli_stmt_num_rows($studentCheck) === 1;
            mysqli_stmt_close($studentCheck);
            if (!$validStudent) continue;
            mysqli_stmt_bind_param($save, "issss", $postScheduleId, $studentUsername, $postDate, $status, $dosenUsername);
            mysqli_stmt_execute($save);
        }
        mysqli_stmt_close($save);
        mysqli_commit($conn);
        $selectedScheduleId = $postScheduleId;
        $selectedDate = $postDate;
        $notice = "Data kehadiran berhasil disimpan.";
    }

    $scheduleStatement = mysqli_prepare($conn,
        "SELECT schedules.`Id`, schedules.`CourseName`, schedules.`DayName`, schedules.`StartTime`, schedules.`EndTime`, schedules.`RoomName`, schedules.`ScheduleType`\n         FROM `class_schedules` AS schedules\n         INNER JOIN `dosen_course_assignments` AS assignments ON assignments.`ScheduleId` = schedules.`Id`\n         WHERE assignments.`DosenUsername` = ?\n         ORDER BY schedules.`CourseName`, schedules.`DayName`, schedules.`StartTime`, schedules.`Id`"
    );
    mysqli_stmt_bind_param($scheduleStatement, "s", $dosenUsername);
    mysqli_stmt_execute($scheduleStatement);
    $scheduleResult = mysqli_stmt_get_result($scheduleStatement);
    $schedules = mysqli_fetch_all($scheduleResult, MYSQLI_ASSOC);
    mysqli_stmt_close($scheduleStatement);
    if (!$selectedScheduleId && $schedules) $selectedScheduleId = (int) $schedules[0]["Id"];
    $selectedSchedule = null;
    foreach ($schedules as $schedule) if ((int) $schedule["Id"] === (int) $selectedScheduleId) $selectedSchedule = $schedule;

    $studentRows = [];
    $summary = ["total" => 0, "Hadir" => 0, "Izin" => 0, "Sakit" => 0, "Alpa" => 0];
    if ($selectedSchedule) {
        $studentStatement = mysqli_prepare($conn,
            "SELECT accounts.`Username`, COALESCE(profiles.`FullName`, accounts.`Username`) AS FullName,
                    attendance.`Status`,
                    COALESCE(stats.`TotalPertemuan`, 0) AS TotalPertemuan,
                    COALESCE(stats.`TotalHadir`, 0) AS TotalHadir
             FROM `login` AS accounts
             LEFT JOIN `mahasiswa_profiles` AS profiles ON profiles.`Username` = accounts.`Username`
             LEFT JOIN `course_attendance` AS attendance
               ON attendance.`Username` = accounts.`Username` AND attendance.`ScheduleId` = ? AND attendance.`AttendanceDate` = ?
             LEFT JOIN (
                SELECT `Username`, COUNT(*) AS `TotalPertemuan`,
                       SUM(CASE WHEN `Status` = 'Hadir' THEN 1 ELSE 0 END) AS `TotalHadir`
                FROM `course_attendance` WHERE `ScheduleId` = ? GROUP BY `Username`
             ) AS stats ON stats.`Username` = accounts.`Username`
             WHERE accounts.`Role` = 'mahasiswa'
             ORDER BY FullName, accounts.`Username`"
        );
        mysqli_stmt_bind_param($studentStatement, "isi", $selectedScheduleId, $selectedDate, $selectedScheduleId);
        mysqli_stmt_execute($studentStatement);
        $studentResult = mysqli_stmt_get_result($studentStatement);
        $studentRows = mysqli_fetch_all($studentResult, MYSQLI_ASSOC);
        mysqli_stmt_close($studentStatement);
        foreach ($studentRows as &$student) {
            $student["Status"] = $student["Status"] ?: "Alpa";
            $summary["total"]++;
            $summary[$student["Status"]]++;
        }
        unset($student);
    }
} catch (Throwable $error) {
    if ($conn && mysqli_errno($conn) === 0) { /* keep connection diagnostics quiet */ }
    try { mysqli_rollback($conn); } catch (Throwable $ignored) {}
    if ($notice === "") { $notice = $error->getMessage(); $noticeType = "error"; }
}

require __DIR__ . "/Dosen_Header.php";
?>
<section class="student-page-heading">
    <p class="home-eyebrow">PENGELOLAAN AKADEMIK</p>
    <h1>Kelola kehadiran mahasiswa</h1>
    <p>Pilih mata kuliah yang Anda ampu dan tanggal pertemuan, lalu tetapkan status kehadiran setiap mahasiswa.</p>
</section>

<section class="student-content-panel">
    <?php if ($notice !== ""): ?>
        <p class="community-data-status" role="status" style="color:<?= $noticeType === "error" ? "#a93226" : "#0e6251" ?>"><?= dosenEscape($notice) ?></p>
    <?php endif; ?>
    <form class="student-history-filters" method="get" action="Dosen_Kehadiran.php">
        <label for="schedule-id">Mata kuliah
            <select id="schedule-id" name="schedule_id" required>
                <?php foreach ($schedules ?? [] as $schedule): ?>
                    <option value="<?= (int) $schedule["Id"] ?>" <?= (int) $selectedScheduleId === (int) $schedule["Id"] ? "selected" : "" ?>>
                        <?= dosenEscape($schedule["CourseName"] . " — " . $schedule["DayName"] . " — " . $schedule["RoomName"]) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label for="attendance-date">Tanggal pertemuan
            <input id="attendance-date" type="date" name="date" value="<?= dosenEscape($selectedDate) ?>" required />
        </label>
        <button class="home-button" type="submit">Tampilkan</button>
    </form>
</section>

<?php if (!empty($selectedSchedule)): ?>
<section class="student-summary-grid" aria-label="Ringkasan kehadiran">
    <article class="student-summary-card"><span>Total mahasiswa</span><strong><?= (int) $summary["total"] ?></strong></article>
    <article class="student-summary-card"><span>Hadir</span><strong><?= (int) $summary["Hadir"] ?></strong></article>
    <article class="student-summary-card"><span>Izin / Sakit / Alpa</span><strong><?= (int) ($summary["Izin"] + $summary["Sakit"] + $summary["Alpa"]) ?></strong></article>
    <article class="student-summary-card"><span>Persentase hadir</span><strong><?= $summary["total"] ? number_format($summary["Hadir"] / $summary["total"] * 100, 1) : "0.0" ?>%</strong></article>
</section>
<section class="student-content-panel">
    <div class="student-panel-heading"><div><p class="home-eyebrow">DAFTAR PESERTA</p><h2><?= dosenEscape($selectedSchedule["CourseName"]) ?></h2><p><?= dosenEscape($selectedSchedule["DayName"] . " · " . $selectedSchedule["RoomName"] . " · " . $selectedDate) ?></p></div></div>
    <?php if (!$studentRows): ?>
        <p class="admin-empty">Belum ada akun mahasiswa terdaftar.</p>
    <?php else: ?>
    <form method="post" action="Dosen_Kehadiran.php">
        <input type="hidden" name="csrf_token" value="<?= dosenEscape($roomCsrfToken) ?>" />
        <input type="hidden" name="schedule_id" value="<?= (int) $selectedScheduleId ?>" />
        <input type="hidden" name="attendance_date" value="<?= dosenEscape($selectedDate) ?>" />
        <div class="attendance-table-wrap"><table class="attendance-table">
            <thead><tr><th>No.</th><th>Username / NIM</th><th>Nama mahasiswa</th><th>Persentase hadir</th><th>Status kehadiran (tanggal dipilih)</th></tr></thead>
            <tbody><?php foreach ($studentRows as $index => $student): ?>
                <tr><td><?= $index + 1 ?></td><td><?= dosenEscape($student["Username"]) ?></td><td><?= dosenEscape($student["FullName"]) ?></td>
                <td><?php $studentTotal = (int) $student["TotalPertemuan"]; $studentPresent = (int) $student["TotalHadir"]; $studentPercent = $studentTotal ? round($studentPresent / $studentTotal * 100, 1) : 0; ?>
                    <strong><?= number_format($studentPercent, 1) ?>%</strong> <small>(<?= $studentPresent ?>/<?= $studentTotal ?>)</small><?php if ($studentTotal > 0 && $studentPercent < 75): ?><div class="form-help" style="color:#b45309;font-weight:700">⚠ Di bawah batas 75%</div><?php elseif ($studentTotal > 0): ?><div class="form-help" style="color:#0e6251">Memenuhi batas minimum</div><?php endif; ?></td>
                <td><select name="status[<?= dosenEscape($student["Username"]) ?>]" aria-label="Status <?= dosenEscape($student["FullName"]) ?>">
                    <?php foreach (["Hadir", "Izin", "Sakit", "Alpa"] as $status): ?><option value="<?= $status ?>" <?= $student["Status"] === $status ? "selected" : "" ?>><?= $status ?></option><?php endforeach; ?>
                </select></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <button class="home-button" type="submit" style="margin-top:18px">Simpan kehadiran mahasiswa</button>
    </form>
    <?php endif; ?>
</section>
<?php elseif (empty($schedules)): ?>
<section class="student-content-panel"><p class="admin-empty">Belum ada mata kuliah yang ditugaskan kepada akun dosen ini. Minta admin menambahkan penugasan dosen pada tabel dosen_course_assignments.</p></section>
<?php endif; ?>
<?php require __DIR__ . "/Dosen_Footer.php"; ?>
