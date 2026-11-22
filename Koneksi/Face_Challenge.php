<?php
ini_set("display_errors", "0");
header("Content-Type: application/json; charset=utf-8");
session_start();
require_once __DIR__ . "/Face_Service_Client.php";

function faceChallengeRespond($statusCode, $success, $message, $challenge = null)
{
    http_response_code($statusCode);
    $result = ["success" => $success, "message" => $message];
    if (is_array($challenge)) {
        $result["challenge"] = $challenge["challenge"];
        $result["nonce"] = $challenge["nonce"];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" || !isset($_SESSION["username"]) || !is_string($_SESSION["username"])) {
    faceChallengeRespond(401, false, "Silakan login sebagai mahasiswa terlebih dahulu.");
}

$csrf = $_POST["csrf_token"] ?? "";
if (!is_string($csrf) || !isset($_SESSION["csrf_token"]) || !hash_equals($_SESSION["csrf_token"], $csrf)) {
    faceChallengeRespond(400, false, "Token keamanan tidak valid. Muat ulang halaman.");
}

$purpose = $_POST["purpose"] ?? "";
if (!in_array($purpose, ["enrollment", "attendance"], true)) {
    faceChallengeRespond(400, false, "Tujuan pemeriksaan kamera tidak valid.");
}

try {
    require_once __DIR__ . "/Koneksi.php";
    $username = $_SESSION["username"];
    $statement = mysqli_prepare($conn, "SELECT `Role` FROM `login` WHERE `Username` = ? LIMIT 1");
    mysqli_stmt_bind_param($statement, "s", $username);
    mysqli_stmt_execute($statement);
    mysqli_stmt_bind_result($statement, $role);
    $accountFound = mysqli_stmt_fetch($statement);
    mysqli_stmt_close($statement);
    if (!$accountFound || $role !== "mahasiswa") {
        faceChallengeRespond(403, false, "Pemeriksaan wajah hanya tersedia untuk akun mahasiswa.");
    }

    faceEnsureServiceRunning();
    $sessionKey = $purpose === "enrollment" ? "face_enrollment_liveness" : "face_attendance_liveness";
    faceChallengeRespond(
        200,
        true,
        "Tantangan kamera siap.",
        faceCreateLivenessChallenge($sessionKey)
    );
} catch (FaceServiceException $error) {
    error_log("Face service startup error: " . $error->getMessage());
    faceChallengeRespond($error->getStatusCode(), false, $error->getMessage());
} catch (Throwable $error) {
    error_log("Face challenge error: " . $error->getMessage());
    faceChallengeRespond(500, false, "Tantangan kamera tidak dapat disiapkan oleh server.");
}
