<?php
header("Content-Type: application/json; charset=utf-8");
session_start();
require_once __DIR__ . "/Face_Service_Client.php";

function faceVerifyRespond($code, $success, $message, $extra = [])
{
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

try {
    $challenge = faceConsumeLivenessChallenge(
        "face_attendance_liveness",
        $_POST["challenge"] ?? "",
        $_POST["challenge_nonce"] ?? ""
    );
    [$firstFrames, $secondFrames] = faceDecodeFrameGroups();

    require_once __DIR__ . "/Koneksi.php";
    mysqli_set_charset($conn, "utf8mb4");
    $username = $_SESSION["username"];
    $query = mysqli_prepare(
        $conn,
        "SELECT e.`FaceDescriptor`, COALESCE(e.`ModelVersion`, ''), l.`Role`
         FROM `mahasiswa_face_enrollments` e
         JOIN `login` l ON l.`Username` = e.`Username`
         WHERE e.`Username` = ? LIMIT 1"
    );
    mysqli_stmt_bind_param($query, "s", $username);
    mysqli_stmt_execute($query);
    mysqli_stmt_bind_result($query, $storedJson, $modelVersion, $role);
    $found = mysqli_stmt_fetch($query);
    mysqli_stmt_close($query);

    if (!$found || $role !== "mahasiswa" || !$storedJson) {
        faceVerifyRespond(403, false, "Wajah belum terdaftar. Daftarkan wajah melalui menu Pendaftaran biometrik.");
    }
    if ($modelVersion !== FACE_SERVICE_MODEL_VERSION) {
        faceVerifyRespond(409, false, "Pendaftaran menggunakan model lama. Daftarkan ulang wajah sebelum absensi.");
    }

    $reference = json_decode($storedJson, true);
    if (!is_array($reference) || count($reference) < 1 || count($reference) > 2048) {
        faceVerifyRespond(409, false, "Data pendaftaran wajah rusak. Silakan daftarkan ulang.");
    }
    foreach ($reference as $value) {
        if (!is_numeric($value) || !is_finite((float) $value)) {
            faceVerifyRespond(409, false, "Data pendaftaran wajah rusak. Silakan daftarkan ulang.");
        }
    }

    $faceResult = faceServiceRequest("/v1/verify", [
        "challenge" => $challenge,
        "first_frames" => $firstFrames,
        "second_frames" => $secondFrames,
        "reference" => array_map("floatval", $reference),
    ]);
    if (
        ($faceResult["success"] ?? false) !== true ||
        ($faceResult["model"] ?? "") !== FACE_SERVICE_MODEL_VERSION
    ) {
        throw new RuntimeException("Face service returned an invalid verification result.");
    }

    $proof = bin2hex(random_bytes(32));
    $_SESSION["face_attendance_proof"] = $proof;
    $_SESSION["face_attendance_proof_expires"] = time() + 120;
    faceVerifyRespond(200, true, "Wajah cocok dan pemeriksaan kamera berhasil.", ["proof" => $proof]);
} catch (FaceServiceException $error) {
    error_log("Face verification service error: " . $error->getMessage());
    faceVerifyRespond($error->getStatusCode(), false, $error->getMessage());
} catch (Throwable $error) {
    error_log("Face verification error: " . $error->getMessage());
    faceVerifyRespond(500, false, "Verifikasi wajah gagal diproses oleh server.");
}
