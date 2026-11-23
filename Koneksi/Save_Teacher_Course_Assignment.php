<?php
header("Content-Type: application/json; charset=utf-8");
session_start();

function saveTeacherCourseAssignmentRespond($statusCode, $success, $message)
{
    http_response_code($statusCode);
    echo json_encode(["success" => $success, "message" => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION["username"]) || !is_string($_SESSION["username"])) {
    saveTeacherCourseAssignmentRespond(401, false, "Silakan login terlebih dahulu.");
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    saveTeacherCourseAssignmentRespond(405, false, "Metode tidak diizinkan.");
}

$submittedToken = $_POST["csrf_token"] ?? "";
if (
    !is_string($submittedToken) ||
    !isset($_SESSION["csrf_token"]) ||
    !is_string($_SESSION["csrf_token"]) ||
    !hash_equals($_SESSION["csrf_token"], $submittedToken)
) {
    saveTeacherCourseAssignmentRespond(400, false, "Permintaan tidak valid. Muat ulang halaman dan coba lagi.");
}

$dosenUsername = $_POST["dosen_username"] ?? "";
$scheduleIds = $_POST["schedule_ids"] ?? null;
$clearAssignments = ($_POST["clear_assignments"] ?? "") === "1";
if ($scheduleIds === null && $clearAssignments) {
    $scheduleIds = [];
}
if (
    !is_string($dosenUsername) ||
    trim($dosenUsername) === "" ||
    mb_strlen(trim($dosenUsername), "UTF-8") > 50 ||
    !is_array($scheduleIds)
) {
    saveTeacherCourseAssignmentRespond(400, false, "Data penugasan tidak valid.");
}
$dosenUsername = trim($dosenUsername);
if (count($scheduleIds) > 500) {
    saveTeacherCourseAssignmentRespond(400, false, "Maksimal 500 jadwal dapat ditetapkan sekaligus.");
}

$validatedScheduleIds = [];
foreach ($scheduleIds as $scheduleId) {
    if (!is_string($scheduleId) && !is_int($scheduleId)) {
        saveTeacherCourseAssignmentRespond(400, false, "Daftar jadwal tidak valid.");
    }
    $validatedId = filter_var($scheduleId, FILTER_VALIDATE_INT);
    if ($validatedId === false || $validatedId < 1) {
        saveTeacherCourseAssignmentRespond(400, false, "Daftar jadwal tidak valid.");
    }
    $validatedScheduleIds[$validatedId] = $validatedId;
}
$validatedScheduleIds = array_values($validatedScheduleIds);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$transactionStarted = false;

try {
    require_once __DIR__ . "/Koneksi.php";
    mysqli_set_charset($conn, "utf8mb4");

    $roleStatement = mysqli_prepare($conn, "SELECT `Role` FROM `login` WHERE `Username` = ? LIMIT 1");
    mysqli_stmt_bind_param($roleStatement, "s", $_SESSION["username"]);
    mysqli_stmt_execute($roleStatement);
    mysqli_stmt_bind_result($roleStatement, $actorRole);
    $actorFound = mysqli_stmt_fetch($roleStatement);
    mysqli_stmt_close($roleStatement);
    if (!$actorFound || $actorRole !== "admin") {
        saveTeacherCourseAssignmentRespond(403, false, "Hanya admin yang dapat mengubah penugasan dosen.");
    }

    $dosenStatement = mysqli_prepare(
        $conn,
        "SELECT 1 FROM `login` WHERE `Username` = ? AND `Role` = 'dosen' LIMIT 1"
    );
    mysqli_stmt_bind_param($dosenStatement, "s", $dosenUsername);
    mysqli_stmt_execute($dosenStatement);
    mysqli_stmt_store_result($dosenStatement);
    $dosenExists = mysqli_stmt_num_rows($dosenStatement) === 1;
    mysqli_stmt_close($dosenStatement);
    if (!$dosenExists) {
        saveTeacherCourseAssignmentRespond(404, false, "Akun dosen tidak ditemukan.");
    }

    $scheduleCheck = mysqli_prepare($conn, "SELECT 1 FROM `class_schedules` WHERE `Id` = ? LIMIT 1");
    foreach ($validatedScheduleIds as $scheduleId) {
        mysqli_stmt_bind_param($scheduleCheck, "i", $scheduleId);
        mysqli_stmt_execute($scheduleCheck);
        mysqli_stmt_store_result($scheduleCheck);
        $scheduleExists = mysqli_stmt_num_rows($scheduleCheck) === 1;
        mysqli_stmt_free_result($scheduleCheck);
        if (!$scheduleExists) {
            mysqli_stmt_close($scheduleCheck);
            saveTeacherCourseAssignmentRespond(404, false, "Salah satu jadwal tidak ditemukan.");
        }
    }
    mysqli_stmt_close($scheduleCheck);

    mysqli_begin_transaction($conn);
    $transactionStarted = true;

    $deleteStatement = mysqli_prepare(
        $conn,
        "DELETE FROM `dosen_course_assignments` WHERE `DosenUsername` = ?"
    );
    mysqli_stmt_bind_param($deleteStatement, "s", $dosenUsername);
    mysqli_stmt_execute($deleteStatement);
    mysqli_stmt_close($deleteStatement);

    if ($validatedScheduleIds) {
        $insertStatement = mysqli_prepare(
            $conn,
            "INSERT INTO `dosen_course_assignments` (`DosenUsername`, `ScheduleId`) VALUES (?, ?)"
        );
        foreach ($validatedScheduleIds as $scheduleId) {
            mysqli_stmt_bind_param($insertStatement, "si", $dosenUsername, $scheduleId);
            mysqli_stmt_execute($insertStatement);
        }
        mysqli_stmt_close($insertStatement);
    }

    mysqli_commit($conn);
    $transactionStarted = false;
    saveTeacherCourseAssignmentRespond(200, true, "Penugasan dosen berhasil disimpan.");
} catch (Throwable $error) {
    if ($transactionStarted) {
        mysqli_rollback($conn);
    }
    error_log("Save teacher course assignment error: " . $error->getMessage());
    saveTeacherCourseAssignmentRespond(500, false, "Penugasan dosen gagal disimpan.");
}
