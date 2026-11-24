<?php
// [TAMBAHAN CLAUDE] Poin d: halaman dosen untuk menyetujui/menolak pengajuan izin-sakit mahasiswa.
// File baru. Hanya menampilkan pengajuan untuk jadwal yang ditugaskan ke dosen ini
// (tabel dosen_course_assignments, diisi lewat Admin > Penugasan Dosen).
require_once __DIR__ . "/Dosen_Auth.php";
$activeDosenPage = "leave";

$notice = "";
$noticeType = "success";
$tableReady = false;
$requests = [];
$statusLabels = ["Pending" => "Menunggu", "Approved" => "Disetujui", "Rejected" => "Ditolak"];

try {
    $tableReady = mysqli_num_rows(mysqli_query($conn, "SHOW TABLES LIKE 'student_leave_requests'")) === 1
        && mysqli_num_rows(mysqli_query($conn, "SHOW TABLES LIKE 'dosen_course_assignments'")) === 1;

    if ($tableReady && $_SERVER["REQUEST_METHOD"] === "POST") {
        $submittedToken = $_POST["csrf_token"] ?? "";
        if (!is_string($submittedToken) || !hash_equals($roomCsrfToken, $submittedToken)) {
            throw new RuntimeException("Permintaan tidak valid. Muat ulang halaman dan coba lagi.");
        }
        $decision = $_POST["decision"] ?? "";
        $requestId = filter_input(INPUT_POST, "request_id", FILTER_VALIDATE_INT);
        if (!$requestId || !in_array($decision, ["approve", "reject"], true)) {
            throw new RuntimeException("Data keputusan tidak valid.");
        }

        // Pastikan pengajuan masih Menunggu DAN jadwalnya diampu dosen ini.
        $find = mysqli_prepare(
            $conn,
            "SELECT r.`StudentUsername`, r.`ScheduleId`, r.`RequestDate`, r.`Type`
             FROM `student_leave_requests` AS r
             INNER JOIN `dosen_course_assignments` AS a
                     ON a.`ScheduleId` = r.`ScheduleId` AND a.`DosenUsername` = ?
             WHERE r.`Id` = ? AND r.`Status` = 'Pending'
             LIMIT 1"
        );
        mysqli_stmt_bind_param($find, "si", $dosenUsername, $requestId);
        mysqli_stmt_execute($find);
        mysqli_stmt_bind_result($find, $reqStudent, $reqSchedule, $reqDate, $reqType);
        $found = mysqli_stmt_fetch($find);
        mysqli_stmt_close($find);
        if (!$found) {
            throw new RuntimeException("Pengajuan tidak ditemukan, sudah diproses, atau bukan mata kuliah yang Anda ampu.");
        }

        $newStatus = $decision === "approve" ? "Approved" : "Rejected";
        mysqli_begin_transaction($conn);
        try {
            $setStatus = mysqli_prepare($conn, "UPDATE `student_leave_requests` SET `Status` = ? WHERE `Id` = ? AND `Status` = 'Pending'");
            mysqli_stmt_bind_param($setStatus, "si", $newStatus, $requestId);
            mysqli_stmt_execute($setStatus);
            mysqli_stmt_close($setStatus);

            // Bila disetujui, catat ke rekap kehadiran mata kuliah (Izin/Sakit).
            // Kehadiran yang sudah berstatus 'Hadir' tidak ditimpa.
            if ($newStatus === "Approved"
                && mysqli_num_rows(mysqli_query($conn, "SHOW TABLES LIKE 'course_attendance'")) === 1) {
                $notes = "Pengajuan " . $reqType . " disetujui dosen";
                $sync = mysqli_prepare(
                    $conn,
                    "INSERT INTO `course_attendance` (`ScheduleId`, `Username`, `AttendanceDate`, `Status`, `Notes`, `MarkedBy`)
                     VALUES (?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        `Notes` = IF(`Status` = 'Hadir', `Notes`, VALUES(`Notes`)),
                        `MarkedBy` = IF(`Status` = 'Hadir', `MarkedBy`, VALUES(`MarkedBy`)),
                        `Status` = IF(`Status` = 'Hadir', `Status`, VALUES(`Status`))"
                );
                mysqli_stmt_bind_param($sync, "isssss", $reqSchedule, $reqStudent, $reqDate, $reqType, $notes, $dosenUsername);
                mysqli_stmt_execute($sync);
                mysqli_stmt_close($sync);
            }
            mysqli_commit($conn);
        } catch (Throwable $inner) {
            mysqli_rollback($conn);
            throw $inner;
        }
        $notice = $newStatus === "Approved" ? "Pengajuan disetujui dan dicatat ke rekap kehadiran." : "Pengajuan ditolak.";
    }

    if ($tableReady) {
        $list = mysqli_prepare(
            $conn,
            "SELECT r.`Id`, r.`StudentUsername`, r.`RequestDate`, r.`Type`, r.`Status`, r.`Note`,
                    s.`CourseName`, COALESCE(NULLIF(p.`FullName`, ''), r.`StudentUsername`) AS `StudentName`
             FROM `student_leave_requests` AS r
             INNER JOIN `dosen_course_assignments` AS a
                     ON a.`ScheduleId` = r.`ScheduleId` AND a.`DosenUsername` = ?
             INNER JOIN `class_schedules` AS s ON s.`Id` = r.`ScheduleId`
             LEFT JOIN `mahasiswa_profiles` AS p ON p.`Username` = r.`StudentUsername`
             ORDER BY (r.`Status` = 'Pending') DESC, r.`RequestDate` DESC, r.`Id` DESC
             LIMIT 100"
        );
        mysqli_stmt_bind_param($list, "s", $dosenUsername);
        mysqli_stmt_execute($list);
        $requests = mysqli_fetch_all(mysqli_stmt_get_result($list), MYSQLI_ASSOC);
        mysqli_stmt_close($list);
    }
} catch (RuntimeException $error) {
    $notice = $error->getMessage();
    $noticeType = "error";
} catch (Throwable $error) {
    error_log("Dosen leave request error: " . $error->getMessage());
    $notice = "Terjadi kesalahan pada server. Coba lagi nanti.";
    $noticeType = "error";
}

require __DIR__ . "/Dosen_Header.php";
?>
<section class="student-page-heading">
    <p class="home-eyebrow">PENGELOLAAN AKADEMIK</p>
    <h1>Pengajuan izin / sakit mahasiswa</h1>
    <p>Pengajuan untuk mata kuliah yang Anda ampu. Pengajuan yang disetujui otomatis tercatat di rekap kehadiran.</p>
</section>

<section class="student-content-panel">
    <?php if ($notice !== ""): ?>
        <p class="community-data-status" role="status" style="color:<?= $noticeType === "error" ? "#a93226" : "#0e6251" ?>"><?= dosenEscape($notice) ?></p>
    <?php endif; ?>

    <?php if (!$tableReady): ?>
        <p class="admin-empty">Fitur ini belum aktif. Jalankan <code>Migration_Student_Leave_Requests.sql</code> dan <code>Migration_Dosen_Course_Assignments.sql</code> terlebih dahulu.</p>
    <?php elseif (!$requests): ?>
        <p class="admin-empty">Belum ada pengajuan izin atau sakit untuk mata kuliah yang Anda ampu. Pastikan admin sudah menugaskan jadwal kepada akun Anda.</p>
    <?php else: ?>
        <div class="attendance-table-wrap">
            <table class="attendance-table">
                <thead>
                    <tr><th>Mahasiswa</th><th>Mata kuliah</th><th>Tanggal</th><th>Jenis</th><th>Keterangan</th><th>Status</th><th>Aksi</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $request): ?>
                        <tr>
                            <td><?= dosenEscape($request["StudentName"]) ?><br /><small><?= dosenEscape($request["StudentUsername"]) ?></small></td>
                            <td><?= dosenEscape($request["CourseName"]) ?></td>
                            <td><?= dosenEscape($request["RequestDate"]) ?></td>
                            <td><?= dosenEscape($request["Type"]) ?></td>
                            <td><?= dosenEscape($request["Note"] ?? "-") ?></td>
                            <td><?= dosenEscape($statusLabels[$request["Status"]] ?? $request["Status"]) ?></td>
                            <td>
                                <?php if ($request["Status"] === "Pending"): ?>
                                    <form method="post" action="Dosen_Izin.php" style="display:flex;gap:6px">
                                        <input type="hidden" name="csrf_token" value="<?= dosenEscape($roomCsrfToken) ?>" />
                                        <input type="hidden" name="request_id" value="<?= (int) $request["Id"] ?>" />
                                        <button class="home-button" type="submit" name="decision" value="approve">Setujui</button>
                                        <button class="room-action lock-action" type="submit" name="decision" value="reject">Tolak</button>
                                    </form>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . "/Dosen_Footer.php"; ?>
<?php // [/TAMBAHAN CLAUDE] ?>
