<?php
header("Content-Type: application/json; charset=utf-8");
session_start();
function faceVerifyRespond($code, $success, $message, $extra = []) {
    http_response_code($code);
    echo json_encode(array_merge(["success" => $success, "message" => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER["REQUEST_METHOD"] !== "POST" || !isset($_SESSION["username"]) || !is_string($_SESSION["username"])) {
    faceVerifyRespond(401, false, "Silakan login sebagai mahasiswa terlebih dahulu.");
}
$csrf = $_POST["csrf_token"] ?? "";
if (!is_string($csrf) || !isset($_SESSION["csrf_token"]) || !hash_equals($_SESSION["csrf_token"], $csrf)) {
    faceVerifyRespond(400, false, "Token keamanan tidak valid. Muat ulang halaman.");
}
$raw = $_POST["descriptor"] ?? "";
$live = json_decode($raw, true);
if (!is_array($live) || count($live) !== 128) faceVerifyRespond(400, false, "Data wajah dari kamera tidak valid.");
foreach ($live as $v) if (!is_numeric($v) || !is_finite((float)$v)) faceVerifyRespond(400, false, "Data ciri wajah tidak valid.");
try {
    require_once __DIR__ . "/Koneksi.php";
    mysqli_set_charset($conn, "utf8mb4");
    $username = $_SESSION["username"];
    $q = mysqli_prepare($conn, "SELECT e.`FaceDescriptor`, l.`Role` FROM `mahasiswa_face_enrollments` e JOIN `login` l ON l.`Username`=e.`Username` WHERE e.`Username`=? LIMIT 1");
    mysqli_stmt_bind_param($q, "s", $username);
    mysqli_stmt_execute($q);
    mysqli_stmt_bind_result($q, $storedJson, $role);
    $found = mysqli_stmt_fetch($q);
    mysqli_stmt_close($q);
    if (!$found || $role !== "mahasiswa" || !$storedJson) faceVerifyRespond(403, false, "Wajah belum terdaftar dengan data pencocokan. Daftarkan ulang wajah Anda.");
    $stored = json_decode($storedJson, true);
    if (!is_array($stored) || count($stored) !== 128) faceVerifyRespond(409, false, "Data wajah terdaftar belum memiliki descriptor. Silakan daftar ulang wajah.");
    $sum = 0.0;
    for ($i=0; $i<128; $i++) {
        if (!isset($stored[$i]) || !is_numeric($stored[$i])) faceVerifyRespond(409, false, "Data wajah terdaftar rusak. Silakan daftar ulang.");
        $d = (float)$live[$i] - (float)$stored[$i];
        $sum += $d*$d;
    }
    $distance = sqrt($sum);
    // Ambang awal perlu dikalibrasi dengan kamera dan kondisi nyata.
    if ($distance > 0.52) faceVerifyRespond(403, false, "Wajah tidak cocok dengan data wajah akun ini. Coba lagi dengan pencahayaan yang baik.", ["distance" => round($distance, 4)]);
    $proof = bin2hex(random_bytes(32));
    $_SESSION["face_attendance_proof"] = $proof;
    $_SESSION["face_attendance_proof_expires"] = time() + 120;
    faceVerifyRespond(200, true, "Wajah cocok dengan data pendaftaran.", ["proof" => $proof, "distance" => round($distance, 4)]);
} catch (Throwable $e) {
    error_log("Face verification error: ".$e->getMessage());
    faceVerifyRespond(500, false, "Verifikasi wajah gagal diproses oleh server.");
}
