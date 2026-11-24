<?php
header("Content-Type: application/json; charset=utf-8");
session_start();

// Zona waktu kampus (WITA). Ubah ke Asia/Jakarta (WIB) atau Asia/Jayapura (WIT) bila perlu.
date_default_timezone_set("Asia/Makassar");
// Absensi dibuka sekian menit sebelum kelas dimulai dan ditutup saat kelas selesai.
const ATTENDANCE_OPEN_MINUTES_BEFORE = 15;
const ATTENDANCE_TEST_MODE = true;

function roomRespond($statusCode, $success, $message, $extra = [])
{
    http_response_code($statusCode);
    echo json_encode(array_merge([
        "success" => $success,
        "message" => $message,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function haversineDistance($lat1, $lon1, $lat2, $lon2)
{
    $earthRadius = 6371000; // dalam meter
    $latFrom = deg2rad((float) $lat1);
    $lonFrom = deg2rad((float) $lon1);
    $latTo = deg2rad((float) $lat2);
    $lonTo = deg2rad((float) $lon2);

    $latDelta = $latTo - $latFrom;
    $lonDelta = $lonTo - $lonFrom;

    $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
        cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));
    return (int) round($angle * $earthRadius);
}

/**
 * Nama hari (Indonesia) sesuai ENUM class_schedules.DayName untuk sebuah timestamp.
 * Minggu tidak ada di ENUM, sehingga tidak pernah cocok dengan jadwal manapun.
 */
function scheduleDayName($timestamp)
{
    $names = [1 => "Senin", 2 => "Selasa", 3 => "Rabu", 4 => "Kamis", 5 => "Jumat", 6 => "Sabtu", 7 => "Minggu"];
    return $names[(int) date("N", $timestamp)];
}

/**
 * In test mode, returns every schedule, including entries without a time.
 * Otherwise, returns schedules within the normal attendance window.
 * If $roomName is provided, results are limited to that room.
 */
function findActiveSchedules($conn, $timestamp, $roomName = null)
{
    $day = scheduleDayName($timestamp);
    $now = date("H:i:s", $timestamp);
    $windowEnd = date("Y-m-d", $timestamp) === date("Y-m-d", $timestamp + ATTENDANCE_OPEN_MINUTES_BEFORE * 60)
        ? date("H:i:s", $timestamp + ATTENDANCE_OPEN_MINUTES_BEFORE * 60)
        : "23:59:59";

    $sql = "SELECT `Id`, `CourseName`, `RoomName`, `StartTime`, `EndTime`, `ScheduleType`, `DayName`
            FROM `class_schedules`
            WHERE 1 = 1";
    $types = "";
    $params = [];
    if (!ATTENDANCE_TEST_MODE) {
        $sql .= " AND `DayName` = ? AND `StartTime` IS NOT NULL AND `EndTime` IS NOT NULL
                  AND `StartTime` <= ? AND `EndTime` >= ?";
        $types .= "sss";
        $params[] = $day;
        $params[] = $windowEnd;
        $params[] = $now;
    }
    if ($roomName !== null) {
        $sql .= " AND TRIM(`RoomName`) = TRIM(?)";
        $types .= "s";
        $params[] = $roomName;
    }
    $sql .= " ORDER BY `StartTime`, `CourseName`";

    $statement = mysqli_prepare($conn, $sql);
    if ($params) {
        mysqli_stmt_bind_param($statement, $types, ...$params);
    }
    mysqli_stmt_execute($statement);
    $result = mysqli_stmt_get_result($statement);
    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
    mysqli_stmt_close($statement);
    return $rows;
}

if (!isset($_SESSION["username"]) || !is_string($_SESSION["username"])) {
    roomRespond(401, false, "Silakan login terlebih dahulu.");
}

if (!isset($_SESSION["csrf_token"]) || !is_string($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    require_once __DIR__ . "/Koneksi.php";
    mysqli_set_charset($conn, "utf8mb4");

    $username = $_SESSION["username"];
    $roleStatement = mysqli_prepare($conn, "SELECT `Role` FROM `login` WHERE `Username` = ? LIMIT 1");
    mysqli_stmt_bind_param($roleStatement, "s", $username);
    mysqli_stmt_execute($roleStatement);
    mysqli_stmt_bind_result($roleStatement, $storedRole);
    $accountFound = mysqli_stmt_fetch($roleStatement);
    mysqli_stmt_close($roleStatement);

    if (!$accountFound) {
        roomRespond(401, false, "Akun tidak ditemukan.");
    }

    $role = $storedRole === "admin" ? "admin" : ($storedRole === "dosen" ? "dosen" : "mahasiswa");

    if ($_SERVER["REQUEST_METHOD"] === "GET" && ($_GET["view"] ?? "") === "schedules") {
        $nowTs = time();
        roomRespond(200, true, "Jadwal aktif berhasil dimuat.", [
            "schedules" => findActiveSchedules($conn, $nowTs),
            "server_time" => date("Y-m-d H:i:s", $nowTs),
            "open_minutes_before" => ATTENDANCE_OPEN_MINUTES_BEFORE,
            "test_mode" => ATTENDANCE_TEST_MODE,
        ]);
    }

    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        $colCheck = mysqli_query($conn, "SHOW COLUMNS FROM `class_rooms` LIKE 'Latitude'");
        $hasGeofence = mysqli_num_rows($colCheck) === 1;

        if ($hasGeofence) {
            $result = mysqli_query($conn, "SELECT `RoomName`, `RoomStatus`, `UpdatedAt`, `Latitude`, `Longitude`, `RadiusMeters` FROM `class_rooms` ORDER BY `Id` ASC");
        } else {
            $result = mysqli_query($conn, "SELECT `RoomName`, `RoomStatus`, `UpdatedAt` FROM `class_rooms` ORDER BY `Id` ASC");
        }
        $rooms = mysqli_fetch_all($result, MYSQLI_ASSOC);
        roomRespond(200, true, "Data ruangan berhasil dimuat.", ["rooms" => $rooms, "role" => $role]);
    }

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        roomRespond(405, false, "Metode tidak diizinkan.");
    }

    $submittedToken = $_POST["csrf_token"] ?? "";
    if (!is_string($submittedToken) || !hash_equals($_SESSION["csrf_token"], $submittedToken)) {
        roomRespond(400, false, "Permintaan tidak valid. Muat ulang halaman dan coba lagi.");
    }

    $action = trim($_POST["action"] ?? "");

    if ($action === "set_room_status") {
        if ($role !== "dosen" && $role !== "admin") {
            roomRespond(403, false, "Hanya dosen atau admin yang dapat mengatur status ruangan.");
        }

        $roomName = trim($_POST["room_name"] ?? "");
        $roomStatus = trim($_POST["room_status"] ?? "");
        if ($roomName === "" || !in_array($roomStatus, ["open", "locked"], true)) {
            roomRespond(400, false, "Nama ruangan dan status tidak valid.");
        }

        $roomCheck = mysqli_prepare($conn, "SELECT 1 FROM `class_rooms` WHERE `RoomName` = ? LIMIT 1");
        mysqli_stmt_bind_param($roomCheck, "s", $roomName);
        mysqli_stmt_execute($roomCheck);
        mysqli_stmt_store_result($roomCheck);
        if (mysqli_stmt_num_rows($roomCheck) !== 1) {
            mysqli_stmt_close($roomCheck);
            roomRespond(404, false, "Ruangan tidak ditemukan.");
        }
        mysqli_stmt_close($roomCheck);

        $update = mysqli_prepare(
            $conn,
            "UPDATE `class_rooms` SET `RoomStatus` = ?, `UpdatedBy` = ? WHERE `RoomName` = ?"
        );
        mysqli_stmt_bind_param($update, "sss", $roomStatus, $username, $roomName);
        mysqli_stmt_execute($update);
        mysqli_stmt_close($update);

        roomRespond(200, true, "Status " . $roomName . " berhasil diperbarui.");
    }

    if ($action === "verify_access") {
        if ($role !== "mahasiswa") {
            roomRespond(403, false, "Hanya mahasiswa yang dapat melakukan verifikasi masuk.");
        }

        $roomName = trim($_POST["room_name"] ?? "");
        $fingerprintVerified = (($_POST["fingerprint_verified"] ?? "") === "1") ? 1 : 0;
        $faceVerified = (($_POST["face_verified"] ?? "") === "1") ? 1 : 0;

        $scheduleId = isset($_POST["schedule_id"]) && ctype_digit((string) $_POST["schedule_id"]) ? (int) $_POST["schedule_id"] : 0;
        if ($scheduleId < 1) {
            roomRespond(400, false, "Pilih mata kuliah yang sedang berlangsung terlebih dahulu.");
        }
        // Validasi jadwal di server (hari & jam server), bukan dari browser.
        $activeSchedule = null;
        foreach (findActiveSchedules($conn, time(), $roomName) as $candidate) {
            if ((int) $candidate["Id"] === $scheduleId) {
                $activeSchedule = $candidate;
                break;
            }
        }
        if ($activeSchedule === null) {
            if (ATTENDANCE_TEST_MODE) {
                roomRespond(403, false, "Jadwal tersebut tidak terdaftar di ruangan ini.");
            }
            roomRespond(403, false, "Mata kuliah tersebut tidak sedang berlangsung di ruangan ini. Absensi dibuka " . ATTENDANCE_OPEN_MINUTES_BEFORE . " menit sebelum kelas dimulai sampai kelas selesai.");
        }
        $attScheduleCol = mysqli_query($conn, "SHOW COLUMNS FROM `class_attendance` LIKE 'ScheduleId'");
        if (mysqli_num_rows($attScheduleCol) !== 1) {
            roomRespond(500, false, "Kolom ScheduleId belum ada. Jalankan Koneksi/Migration_Attendance_Schedule.sql terlebih dahulu.");
        }

        $submittedFaceProof = $_POST["face_proof"] ?? "";
        $sessionFaceProof = $_SESSION["face_attendance_proof"] ?? "";
        $proofExpires = (int) ($_SESSION["face_attendance_proof_expires"] ?? 0);
        if ($roomName === "" || $fingerprintVerified !== 1 || $faceVerified !== 1) {
            roomRespond(400, false, "Sidik jari dan pencocokan wajah harus berhasil terlebih dahulu.");
        }
        if (!is_string($submittedFaceProof) || !is_string($sessionFaceProof) || $sessionFaceProof === "" ||
            $proofExpires < time() || !hash_equals($sessionFaceProof, $submittedFaceProof)) {
            roomRespond(403, false, "Bukti pencocokan wajah tidak valid atau kedaluwarsa. Ulangi verifikasi wajah.");
        }
        // Bukti hanya boleh digunakan satu kali.
        unset($_SESSION["face_attendance_proof"], $_SESSION["face_attendance_proof_expires"]);

        $roomHasGeofence = false;
        $roomLat = null;
        $roomLon = null;
        $radiusMeters = 100;

        $roomColCheck = mysqli_query($conn, "SHOW COLUMNS FROM `class_rooms` LIKE 'Latitude'");
        if (mysqli_num_rows($roomColCheck) === 1) {
            $roomStatement = mysqli_prepare($conn, "SELECT `RoomStatus`, `Latitude`, `Longitude`, `RadiusMeters` FROM `class_rooms` WHERE `RoomName` = ? LIMIT 1");
            mysqli_stmt_bind_param($roomStatement, "s", $roomName);
            mysqli_stmt_execute($roomStatement);
            mysqli_stmt_bind_result($roomStatement, $roomStatus, $roomLat, $roomLon, $radiusMeters);
            $roomFound = mysqli_stmt_fetch($roomStatement);
            mysqli_stmt_close($roomStatement);
            $roomHasGeofence = ($roomLat !== null && $roomLon !== null);
        } else {
            $roomStatement = mysqli_prepare($conn, "SELECT `RoomStatus` FROM `class_rooms` WHERE `RoomName` = ? LIMIT 1");
            mysqli_stmt_bind_param($roomStatement, "s", $roomName);
            mysqli_stmt_execute($roomStatement);
            mysqli_stmt_bind_result($roomStatement, $roomStatus);
            $roomFound = mysqli_stmt_fetch($roomStatement);
            mysqli_stmt_close($roomStatement);
        }

        if (!$roomFound) {
            roomRespond(404, false, "Ruangan tidak ditemukan.");
        }

        if ($roomStatus !== "open") {
            roomRespond(403, false, "Akses ditolak. Ruangan sedang dikunci.");
        }

        if (
            (ATTENDANCE_TEST_MODE && !$roomHasGeofence) ||
            ($roomHasGeofence && (
                !is_numeric($roomLat) || !is_numeric($roomLon) ||
                (float) $roomLat < -90 || (float) $roomLat > 90 ||
                (float) $roomLon < -180 || (float) $roomLon > 180
            ))
        ) {
            roomRespond(409, false, "Koordinat dan radius ruangan belum dikonfigurasi dengan benar. Periksa geofence ruangan.");
        }

        // Validasi Lokasi (Geofencing)
        $userLat = isset($_POST["user_latitude"]) && is_numeric($_POST["user_latitude"]) ? (float) $_POST["user_latitude"] : null;
        $userLon = isset($_POST["user_longitude"]) && is_numeric($_POST["user_longitude"]) ? (float) $_POST["user_longitude"] : null;
        $distance = null;

        if ($roomHasGeofence) {
            if (
                $userLat === null || $userLon === null ||
                $userLat < -90 || $userLat > 90 ||
                $userLon < -180 || $userLon > 180
            ) {
                roomRespond(400, false, "Koordinat GPS tidak valid. Izinkan akses lokasi dan coba lagi.");
            }
            $distance = haversineDistance($userLat, $userLon, $roomLat, $roomLon);
            $maxRadius = (int) ($radiusMeters > 0 ? $radiusMeters : 100);

            if ($distance > $maxRadius) {
                roomRespond(403, false, "Akses ditolak: Anda berada di luar radius absensi (" . $distance . " meter dari ruangan, batas maksimal " . $maxRadius . " meter).");
            }
        }

        $duplicateCheck = mysqli_prepare(
            $conn,
            "SELECT 1 FROM `class_attendance`
             WHERE `Username` = ? AND `ScheduleId` = ? AND DATE(`VerifiedAt`) = ?
             LIMIT 1"
        );
        $todayDate = date("Y-m-d");
        mysqli_stmt_bind_param($duplicateCheck, "sis", $username, $scheduleId, $todayDate);
        mysqli_stmt_execute($duplicateCheck);
        mysqli_stmt_store_result($duplicateCheck);
        if (mysqli_stmt_num_rows($duplicateCheck) > 0) {
            mysqli_stmt_close($duplicateCheck);
            roomRespond(409, false, "Absensi Anda untuk mata kuliah " . $activeSchedule["CourseName"] . " sudah tercatat hari ini.");
        }
        mysqli_stmt_close($duplicateCheck);

        // Catat absensi beserta data lokasi jika kolom tersedia
        $attColCheck = mysqli_query($conn, "SHOW COLUMNS FROM `class_attendance` LIKE 'UserLatitude'");
        if (mysqli_num_rows($attColCheck) === 1) {
            $insert = mysqli_prepare(
                $conn,
                "INSERT INTO `class_attendance` (`Username`, `RoomName`, `ScheduleId`, `FingerprintVerified`, `FaceVerified`, `UserLatitude`, `UserLongitude`, `DistanceMeters`, `VerifiedAt`) VALUES (?, ?, ?, 1, 1, ?, ?, ?, ?)"
            );
            $verifiedAt = date("Y-m-d H:i:s");
            mysqli_stmt_bind_param($insert, "ssiddis", $username, $roomName, $scheduleId, $userLat, $userLon, $distance, $verifiedAt);
        } else {
            $insert = mysqli_prepare(
                $conn,
                "INSERT INTO `class_attendance` (`Username`, `RoomName`, `ScheduleId`, `FingerprintVerified`, `FaceVerified`, `VerifiedAt`) VALUES (?, ?, ?, 1, 1, ?)"
            );
            $verifiedAt = date("Y-m-d H:i:s");
            mysqli_stmt_bind_param($insert, "ssis", $username, $roomName, $scheduleId, $verifiedAt);
        }
        mysqli_stmt_execute($insert);
        mysqli_stmt_close($insert);

        // Teruskan ke rekap kehadiran mata kuliah (dilihat dosen). Status dari dosen
        // (Izin/Sakit) tidak ditimpa; hanya 'Alpa' yang berubah menjadi 'Hadir'.
        $courseTable = mysqli_query($conn, "SHOW TABLES LIKE 'course_attendance'");
        if (mysqli_num_rows($courseTable) === 1) {
            $sync = mysqli_prepare(
                $conn,
                "INSERT INTO `course_attendance` (`ScheduleId`, `Username`, `AttendanceDate`, `Status`, `Notes`, `MarkedBy`)
                 VALUES (?, ?, ?, 'Hadir', 'Absensi mandiri (GPS + sidik jari + wajah)', ?)
                 ON DUPLICATE KEY UPDATE `Status` = IF(`Status` = 'Alpa', 'Hadir', `Status`)"
            );
            mysqli_stmt_bind_param($sync, "isss", $scheduleId, $username, $todayDate, $username);
            mysqli_stmt_execute($sync);
            mysqli_stmt_close($sync);
        }

        $successMsg = "Absensi " . $activeSchedule["CourseName"] . " berhasil dicatat di " . $roomName . ".";
        if ($distance !== null) {
            $successMsg .= " (Jarak terverifikasi: " . $distance . "m)";
        }
        roomRespond(200, true, $successMsg, ["distance_meters" => $distance]);
    }

    roomRespond(400, false, "Aksi ruangan tidak dikenali.");
} catch (Throwable $error) {
    error_log("Room API error: " . $error->getMessage());
    roomRespond(500, false, "Data ruangan tidak dapat diproses. Pastikan Setup_Database.sql sudah dijalankan.");
}
