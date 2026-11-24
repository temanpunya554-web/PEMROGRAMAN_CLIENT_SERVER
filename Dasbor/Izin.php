<?php
// [TAMBAHAN CLAUDE] Poin d: halaman pengajuan izin/sakit untuk mahasiswa.
// File baru. Memakai tabel `student_leave_requests` (Koneksi/Migration_Student_Leave_Requests.sql).
require_once __DIR__ . "/Student_Auth.php";
date_default_timezone_set("Asia/Makassar");
$activeStudentPage = "leave";

$notice = "";
$noticeType = "success";
$tableReady = false;
$schedules = [];
$requests = [];
$dayNames = [1 => "Senin", 2 => "Selasa", 3 => "Rabu", 4 => "Kamis", 5 => "Jumat", 6 => "Sabtu", 7 => "Minggu"];
$statusLabels = ["Pending" => "Menunggu", "Approved" => "Disetujui", "Rejected" => "Ditolak"];
$minDate = date("Y-m-d", strtotime("-7 days"));
$maxDate = date("Y-m-d", strtotime("+30 days"));

try {
    $tableReady = mysqli_num_rows(mysqli_query($conn, "SHOW TABLES LIKE 'student_leave_requests'")) === 1;

    if ($tableReady && $_SERVER["REQUEST_METHOD"] === "POST") {
        $submittedToken = $_POST["csrf_token"] ?? "";
        if (!is_string($submittedToken) || !hash_equals($studentCsrfToken, $submittedToken)) {
            throw new RuntimeException("Permintaan tidak valid. Muat ulang halaman dan coba lagi.");
        }
        $action = $_POST["action"] ?? "submit";

        if ($action === "cancel") {
            $cancelId = filter_input(INPUT_POST, "request_id", FILTER_VALIDATE_INT);
            if (!$cancelId) {
                throw new RuntimeException("Pengajuan tidak valid.");
            }
            $cancel = mysqli_prepare(
                $conn,
                "DELETE FROM `student_leave_requests` WHERE `Id` = ? AND `StudentUsername` = ? AND `Status` = 'Pending'"
            );
            mysqli_stmt_bind_param($cancel, "is", $cancelId, $studentUsername);
            mysqli_stmt_execute($cancel);
            $cancelled = mysqli_stmt_affected_rows($cancel) === 1;
            mysqli_stmt_close($cancel);
            if (!$cancelled) {
                throw new RuntimeException("Hanya pengajuan berstatus Menunggu milik Anda yang dapat dibatalkan.");
            }
            $notice = "Pengajuan berhasil dibatalkan.";
        } else {
            $scheduleId = filter_input(INPUT_POST, "schedule_id", FILTER_VALIDATE_INT);
            $requestDate = trim((string) ($_POST["request_date"] ?? ""));
            $type = (string) ($_POST["type"] ?? "");
            $note = trim((string) ($_POST["note"] ?? ""));

            $parsedDate = DateTime::createFromFormat("Y-m-d", $requestDate);
            if (!$scheduleId || !$parsedDate || $parsedDate->format("Y-m-d") !== $requestDate) {
                throw new RuntimeException("Mata kuliah atau tanggal tidak valid.");
            }
            if (!in_array($type, ["Izin", "Sakit"], true)) {
                throw new RuntimeException("Pilih jenis pengajuan: Izin atau Sakit.");
            }
            if ($note === "" || mb_strlen($note, "UTF-8") > 255) {
                throw new RuntimeException("Keterangan wajib diisi (maksimal 255 karakter).");
            }
            if ($requestDate < $minDate || $requestDate > $maxDate) {
                throw new RuntimeException("Tanggal harus antara 7 hari lalu dan 30 hari ke depan.");
            }

            $scheduleStatement = mysqli_prepare($conn, "SELECT `DayName` FROM `class_schedules` WHERE `Id` = ? LIMIT 1");
            mysqli_stmt_bind_param($scheduleStatement, "i", $scheduleId);
            mysqli_stmt_execute($scheduleStatement);
            mysqli_stmt_bind_result($scheduleStatement, $scheduleDay);
            $scheduleFound = mysqli_stmt_fetch($scheduleStatement);
            mysqli_stmt_close($scheduleStatement);
            if (!$scheduleFound) {
                throw new RuntimeException("Mata kuliah tidak ditemukan.");
            }
            $dateDay = $dayNames[(int) $parsedDate->format("N")];
            if ($dateDay !== $scheduleDay) {
                throw new RuntimeException("Tanggal tersebut hari {$dateDay}, sedangkan kuliah ini berlangsung hari {$scheduleDay}.");
            }

            // Jangan menimpa kehadiran yang sudah tercatat Hadir.
            if (mysqli_num_rows(mysqli_query($conn, "SHOW TABLES LIKE 'course_attendance'")) === 1) {
                $presentCheck = mysqli_prepare(
                    $conn,
                    "SELECT 1 FROM `course_attendance`
                     WHERE `ScheduleId` = ? AND `Username` = ? AND `AttendanceDate` = ? AND `Status` = 'Hadir' LIMIT 1"
                );
                mysqli_stmt_bind_param($presentCheck, "iss", $scheduleId, $studentUsername, $requestDate);
                mysqli_stmt_execute($presentCheck);
                mysqli_stmt_store_result($presentCheck);
                $alreadyPresent = mysqli_stmt_num_rows($presentCheck) > 0;
                mysqli_stmt_close($presentCheck);
                if ($alreadyPresent) {
                    throw new RuntimeException("Anda sudah tercatat hadir pada kuliah dan tanggal tersebut.");
                }
            }

            $existing = mysqli_prepare(
                $conn,
                "SELECT `Id`, `Status` FROM `student_leave_requests`
                 WHERE `StudentUsername` = ? AND `ScheduleId` = ? AND `RequestDate` = ? LIMIT 1"
            );
            mysqli_stmt_bind_param($existing, "sis", $studentUsername, $scheduleId, $requestDate);
            mysqli_stmt_execute($existing);
            mysqli_stmt_bind_result($existing, $existingId, $existingStatus);
            $existingFound = mysqli_stmt_fetch($existing);
            mysqli_stmt_close($existing);

            if ($existingFound) {
                if ($existingStatus !== "Pending") {
                    throw new RuntimeException("Pengajuan untuk kuliah dan tanggal ini sudah diproses dosen dan tidak dapat diubah.");
                }
                $update = mysqli_prepare($conn, "UPDATE `student_leave_requests` SET `Type` = ?, `Note` = ? WHERE `Id` = ?");
                mysqli_stmt_bind_param($update, "ssi", $type, $note, $existingId);
                mysqli_stmt_execute($update);
                mysqli_stmt_close($update);
                $notice = "Pengajuan yang masih menunggu berhasil diperbarui.";
            } else {
                $insert = mysqli_prepare(
                    $conn,
                    "INSERT INTO `student_leave_requests` (`StudentUsername`, `ScheduleId`, `RequestDate`, `Type`, `Note`)
                     VALUES (?, ?, ?, ?, ?)"
                );
                mysqli_stmt_bind_param($insert, "sisss", $studentUsername, $scheduleId, $requestDate, $type, $note);
                mysqli_stmt_execute($insert);
                mysqli_stmt_close($insert);
                $notice = "Pengajuan {$type} berhasil dikirim dan menunggu persetujuan dosen.";
            }
        }
    }

    $schedules = mysqli_fetch_all(mysqli_query(
        $conn,
        "SELECT `Id`, `DayName`, `CourseName`, `StartTime`, `EndTime`
         FROM `class_schedules`
         ORDER BY FIELD(`DayName`, 'Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'),
                  `StartTime` IS NULL, `StartTime`, `Id`"
    ), MYSQLI_ASSOC);

    if ($tableReady) {
        $listStatement = mysqli_prepare(
            $conn,
            "SELECT r.`Id`, r.`RequestDate`, r.`Type`, r.`Status`, r.`Note`, r.`CreatedAt`, s.`CourseName`
             FROM `student_leave_requests` AS r
             INNER JOIN `class_schedules` AS s ON s.`Id` = r.`ScheduleId`
             WHERE r.`StudentUsername` = ?
             ORDER BY r.`RequestDate` DESC, r.`Id` DESC
             LIMIT 50"
        );
        mysqli_stmt_bind_param($listStatement, "s", $studentUsername);
        mysqli_stmt_execute($listStatement);
        $requests = mysqli_fetch_all(mysqli_stmt_get_result($listStatement), MYSQLI_ASSOC);
        mysqli_stmt_close($listStatement);
    }
} catch (RuntimeException $error) {
    $notice = $error->getMessage();
    $noticeType = "error";
} catch (Throwable $error) {
    error_log("Student leave request error: " . $error->getMessage());
    $notice = "Terjadi kesalahan pada server. Coba lagi nanti.";
    $noticeType = "error";
}

require __DIR__ . "/Student_Header.php";
?>
<section class="student-page-heading">
    <p class="home-eyebrow">PERMOHONAN KEHADIRAN</p>
    <h1>Pengajuan izin / sakit</h1>
    <p>Ajukan izin atau sakit untuk pertemuan kuliah. Dosen pengampu akan menyetujui atau menolak pengajuan Anda.</p>
</section>

<section class="student-content-panel">
    <?php if ($notice !== ""): ?>
        <p class="community-data-status" role="status" style="color:<?= $noticeType === "error" ? "#a93226" : "#0e6251" ?>"><?= studentEscape($notice) ?></p>
    <?php endif; ?>

    <?php if (!$tableReady): ?>
        <p class="admin-empty">Fitur ini belum aktif. Jalankan <code>Koneksi/Migration_Student_Leave_Requests.sql</code> pada database terlebih dahulu.</p>
    <?php else: ?>
        <form class="student-profile-form" method="post" action="Izin.php">
            <input type="hidden" name="csrf_token" value="<?= studentEscape($studentCsrfToken) ?>" />
            <label for="leave-schedule">Mata kuliah
                <select id="leave-schedule" name="schedule_id" required>
                    <option value="">Pilih mata kuliah</option>
                    <?php foreach ($schedules as $schedule): ?>
                        <option value="<?= (int) $schedule["Id"] ?>">
                            <?= studentEscape($schedule["CourseName"] . " — " . $schedule["DayName"]
                                . ($schedule["StartTime"] && $schedule["EndTime"]
                                    ? " (" . substr($schedule["StartTime"], 0, 5) . "-" . substr($schedule["EndTime"], 0, 5) . ")"
                                    : "")) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label for="leave-date">Tanggal pertemuan
                <input id="leave-date" type="date" name="request_date" min="<?= studentEscape($minDate) ?>" max="<?= studentEscape($maxDate) ?>" required />
            </label>
            <label for="leave-type">Jenis
                <select id="leave-type" name="type" required>
                    <option value="Izin">Izin</option>
                    <option value="Sakit">Sakit</option>
                </select>
            </label>
            <label for="leave-note">Keterangan
                <input id="leave-note" type="text" name="note" maxlength="255" placeholder="Contoh: keperluan keluarga / demam" required />
            </label>
            <button class="home-button" type="submit">Kirim pengajuan</button>
        </form>
    <?php endif; ?>
</section>

<?php if ($tableReady): ?>
<section class="student-content-panel">
    <h2>Riwayat pengajuan</h2>
    <?php if (!$requests): ?>
        <p class="admin-empty">Belum ada pengajuan izin atau sakit.</p>
    <?php else: ?>
        <div class="attendance-table-wrap">
            <table class="attendance-table">
                <thead>
                    <tr><th>Tanggal</th><th>Mata kuliah</th><th>Jenis</th><th>Keterangan</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $request): ?>
                        <tr>
                            <td><?= studentEscape($request["RequestDate"]) ?></td>
                            <td><?= studentEscape($request["CourseName"]) ?></td>
                            <td><?= studentEscape($request["Type"]) ?></td>
                            <td><?= studentEscape($request["Note"] ?? "-") ?></td>
                            <td><?= studentEscape($statusLabels[$request["Status"]] ?? $request["Status"]) ?></td>
                            <td>
                                <?php if ($request["Status"] === "Pending"): ?>
                                    <form method="post" action="Izin.php" onsubmit="return confirm('Batalkan pengajuan ini?');">
                                        <input type="hidden" name="csrf_token" value="<?= studentEscape($studentCsrfToken) ?>" />
                                        <input type="hidden" name="action" value="cancel" />
                                        <input type="hidden" name="request_id" value="<?= (int) $request["Id"] ?>" />
                                        <button class="room-action lock-action" type="submit">Batalkan</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php require __DIR__ . "/Student_Footer.php"; ?>
<?php // [/TAMBAHAN CLAUDE] ?>
