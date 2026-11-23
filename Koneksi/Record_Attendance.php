<?php
/**
 * Call only after the attendance endpoint has validated the student's biometric and location proofs.
 */
function recordAttendance($conn, $username, $roomName, $scheduleId)
{
    if (
        !is_string($username) ||
        trim($username) === "" ||
        !is_string($roomName) ||
        trim($roomName) === "" ||
        (!is_string($scheduleId) && !is_int($scheduleId))
    ) {
        throw new InvalidArgumentException("Username, nama ruangan, dan jadwal wajib diisi.");
    }
    $scheduleId = filter_var($scheduleId, FILTER_VALIDATE_INT);
    if ($scheduleId === false || $scheduleId < 1) {
        throw new InvalidArgumentException("Jadwal absensi tidak valid.");
    }

    $timezone = new DateTimeZone("Asia/Makassar");
    $now = new DateTimeImmutable("now", $timezone);
    $today = $now->format("Y-m-d");
    $dayNames = [1 => "Senin", 2 => "Selasa", 3 => "Rabu", 4 => "Kamis", 5 => "Jumat", 6 => "Sabtu", 7 => "Minggu"];
    $dayName = $dayNames[(int) $now->format("N")];
    $windowEnd = $now->modify("+15 minutes");
    $latestStartTime = $windowEnd->format("Y-m-d") === $today ? $windowEnd->format("H:i:s") : "23:59:59";
    $currentTime = $now->format("H:i:s");

    $accountStatement = mysqli_prepare(
        $conn,
        "SELECT rooms.`RoomStatus`
         FROM `login`
         INNER JOIN `class_rooms` AS rooms ON rooms.`RoomName` = ?
         WHERE `login`.`Username` = ? AND `login`.`Role` = 'mahasiswa'
         LIMIT 1"
    );
    if (!$accountStatement) {
        throw new RuntimeException("Gagal memeriksa akun dan ruangan.");
    }
    mysqli_stmt_bind_param($accountStatement, "ss", $roomName, $username);
    if (!mysqli_stmt_execute($accountStatement)) {
        mysqli_stmt_close($accountStatement);
        throw new RuntimeException("Gagal memeriksa akun dan ruangan.");
    }
    mysqli_stmt_bind_result($accountStatement, $roomStatus);
    $accountFound = mysqli_stmt_fetch($accountStatement);
    mysqli_stmt_close($accountStatement);
    if (!$accountFound || $roomStatus !== "open") {
        return false;
    }

    $scheduleStatement = mysqli_prepare(
        $conn,
        "SELECT `Id`
         FROM `class_schedules`
         WHERE `Id` = ? AND `DayName` = ? AND TRIM(`RoomName`) = TRIM(?)
           AND `StartTime` IS NOT NULL AND `EndTime` IS NOT NULL
           AND `StartTime` <= ? AND `EndTime` >= ?
         LIMIT 1"
    );
    if (!$scheduleStatement) {
        throw new RuntimeException("Gagal memeriksa jadwal kelas.");
    }
    mysqli_stmt_bind_param($scheduleStatement, "issss", $scheduleId, $dayName, $roomName, $latestStartTime, $currentTime);
    if (!mysqli_stmt_execute($scheduleStatement)) {
        mysqli_stmt_close($scheduleStatement);
        throw new RuntimeException("Gagal memeriksa jadwal kelas.");
    }
    mysqli_stmt_store_result($scheduleStatement);
    $scheduleFound = mysqli_stmt_num_rows($scheduleStatement) > 0;
    mysqli_stmt_close($scheduleStatement);
    if (!$scheduleFound) {
        return false;
    }

    $scheduleColumn = mysqli_query($conn, "SHOW COLUMNS FROM `class_attendance` LIKE 'ScheduleId'");
    if (!$scheduleColumn || mysqli_num_rows($scheduleColumn) !== 1) {
        throw new RuntimeException("Jalankan Migration_Attendance_Schedule.sql terlebih dahulu.");
    }

    $duplicateStatement = mysqli_prepare(
        $conn,
        "SELECT 1 FROM `class_attendance`
         WHERE `Username` = ? AND `ScheduleId` = ? AND DATE(`VerifiedAt`) = ?
         LIMIT 1"
    );
    if (!$duplicateStatement) {
        throw new RuntimeException("Gagal memeriksa riwayat absensi.");
    }
    mysqli_stmt_bind_param($duplicateStatement, "sis", $username, $scheduleId, $today);
    if (!mysqli_stmt_execute($duplicateStatement)) {
        mysqli_stmt_close($duplicateStatement);
        throw new RuntimeException("Gagal memeriksa riwayat absensi.");
    }
    mysqli_stmt_store_result($duplicateStatement);
    $alreadyRecorded = mysqli_stmt_num_rows($duplicateStatement) > 0;
    mysqli_stmt_close($duplicateStatement);
    if ($alreadyRecorded) {
        return false;
    }

    $insertStatement = mysqli_prepare(
        $conn,
        "INSERT INTO `class_attendance`
            (`Username`, `RoomName`, `ScheduleId`, `FingerprintVerified`, `FaceVerified`, `VerifiedAt`)
         VALUES (?, ?, ?, 1, 1, ?)"
    );
    if (!$insertStatement) {
        throw new RuntimeException("Gagal menyiapkan pencatatan absensi.");
    }
    $verifiedAt = $now->format("Y-m-d H:i:s");
    mysqli_stmt_bind_param($insertStatement, "ssis", $username, $roomName, $scheduleId, $verifiedAt);
    if (!mysqli_stmt_execute($insertStatement)) {
        mysqli_stmt_close($insertStatement);
        throw new RuntimeException("Gagal mencatat absensi.");
    }
    mysqli_stmt_close($insertStatement);

    return true;
}
