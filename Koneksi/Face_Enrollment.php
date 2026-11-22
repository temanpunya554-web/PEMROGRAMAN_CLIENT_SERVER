<?php
header("Content-Type: application/json; charset=utf-8");
session_start();

function faceEnrollmentRespond($statusCode, $success, $message)
{
    http_response_code($statusCode);
    echo json_encode(["success" => $success, "message" => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    faceEnrollmentRespond(405, false, "Metode tidak diizinkan.");
}

if (!isset($_SESSION["username"]) || !is_string($_SESSION["username"])) {
    faceEnrollmentRespond(401, false, "Silakan login terlebih dahulu.");
}

$submittedToken = $_POST["csrf_token"] ?? "";
if (
    !is_string($submittedToken) ||
    !isset($_SESSION["csrf_token"]) ||
    !hash_equals($_SESSION["csrf_token"], $submittedToken)
) {
    faceEnrollmentRespond(400, false, "Permintaan tidak valid. Muat ulang halaman dan coba lagi.");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$savedPhotoPath = null;

try {
    require_once __DIR__ . "/Koneksi.php";
    mysqli_set_charset($conn, "utf8mb4");

    $username = $_SESSION["username"];
    $roleStatement = mysqli_prepare($conn, "SELECT `Role` FROM `login` WHERE `Username` = ? LIMIT 1");
    mysqli_stmt_bind_param($roleStatement, "s", $username);
    mysqli_stmt_execute($roleStatement);
    mysqli_stmt_bind_result($roleStatement, $role);
    $accountFound = mysqli_stmt_fetch($roleStatement);
    mysqli_stmt_close($roleStatement);
    if (!$accountFound || $role !== "mahasiswa") {
        faceEnrollmentRespond(403, false, "Pendaftaran wajah hanya tersedia untuk akun mahasiswa.");
    }

    $action = $_POST["action"] ?? "";
    if ($action === "delete") {
        $lookup = mysqli_prepare($conn, "SELECT `PhotoPath` FROM `mahasiswa_face_enrollments` WHERE `Username` = ? LIMIT 1");
        mysqli_stmt_bind_param($lookup, "s", $username);
        mysqli_stmt_execute($lookup);
        mysqli_stmt_bind_result($lookup, $oldPhotoPath);
        $hasEnrollment = mysqli_stmt_fetch($lookup);
        mysqli_stmt_close($lookup);

        if ($hasEnrollment) {
            $delete = mysqli_prepare($conn, "DELETE FROM `mahasiswa_face_enrollments` WHERE `Username` = ?");
            mysqli_stmt_bind_param($delete, "s", $username);
            mysqli_stmt_execute($delete);
            mysqli_stmt_close($delete);

            if (preg_match('/\A[a-f0-9]{32}\.jpg\z/', basename($oldPhotoPath))) {
                $oldPhotoFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . "Uploads" . DIRECTORY_SEPARATOR
                    . "Mahasiswa" . DIRECTORY_SEPARATOR . "FaceEnrollment" . DIRECTORY_SEPARATOR . basename($oldPhotoPath);
                if (is_file($oldPhotoFile) && !unlink($oldPhotoFile)) {
                    error_log("Face enrollment photo could not be deleted for " . $username);
                }
            }
        }

        faceEnrollmentRespond(200, true, "Data pendaftaran wajah berhasil dihapus.");
    }

    if ($action !== "save") {
        faceEnrollmentRespond(400, false, "Aksi pendaftaran tidak dikenali.");
    }

    if (($_POST["consent"] ?? "") !== "1") {
        faceEnrollmentRespond(400, false, "Persetujuan penyimpanan foto wajah diperlukan.");
    }

    if (!isset($_FILES["face_photo"]) || !is_array($_FILES["face_photo"])) {
        faceEnrollmentRespond(400, false, "Ambil foto wajah terlebih dahulu.");
    }

    $photo = $_FILES["face_photo"];
    if ($photo["error"] !== UPLOAD_ERR_OK || !is_uploaded_file($photo["tmp_name"])) {
        faceEnrollmentRespond(400, false, "Foto tidak berhasil diunggah. Silakan coba lagi.");
    }
    if ($photo["size"] < 1 || $photo["size"] > 2 * 1024 * 1024) {
        faceEnrollmentRespond(400, false, "Ukuran foto maksimal 2 MB.");
    }

    $imageInfo = getimagesize($photo["tmp_name"]);
    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($photo["tmp_name"]);
    if (
        $imageInfo === false ||
        $mimeType !== "image/jpeg" ||
        $imageInfo[0] < 320 ||
        $imageInfo[1] < 320 ||
        $imageInfo[0] > 3000 ||
        $imageInfo[1] > 3000
    ) {
        faceEnrollmentRespond(400, false, "Foto harus berupa JPEG dengan ukuran sisi 320 sampai 3000 piksel.");
    }

    $descriptorRaw = $_POST["face_descriptor"] ?? "";
    $descriptor = json_decode($descriptorRaw, true);
    if (!is_array($descriptor) || count($descriptor) !== 128) {
        faceEnrollmentRespond(400, false, "Ciri wajah tidak berhasil dibuat. Pastikan hanya satu wajah terlihat, lalu ambil foto ulang.");
    }
    foreach ($descriptor as $value) {
        if (!is_numeric($value) || !is_finite((float) $value)) {
            faceEnrollmentRespond(400, false, "Data ciri wajah tidak valid.");
        }
    }
    $descriptorJson = json_encode(array_map("floatval", $descriptor));

    $uploadDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . "Uploads" . DIRECTORY_SEPARATOR
        . "Mahasiswa" . DIRECTORY_SEPARATOR . "FaceEnrollment";
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException("Face enrollment directory could not be created.");
    }

    $filename = bin2hex(random_bytes(16)) . ".jpg";
    $photoFile = $uploadDirectory . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($photo["tmp_name"], $photoFile)) {
        throw new RuntimeException("Face enrollment photo could not be saved.");
    }
    $savedPhotoPath = $filename;

    $lookup = mysqli_prepare($conn, "SELECT `PhotoPath` FROM `mahasiswa_face_enrollments` WHERE `Username` = ? LIMIT 1");
    mysqli_stmt_bind_param($lookup, "s", $username);
    mysqli_stmt_execute($lookup);
    mysqli_stmt_bind_result($lookup, $oldPhotoPath);
    $hasEnrollment = mysqli_stmt_fetch($lookup);
    mysqli_stmt_close($lookup);

    $save = mysqli_prepare(
        $conn,
        "INSERT INTO `mahasiswa_face_enrollments` (`Username`, `PhotoPath`, `FaceDescriptor`) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE `PhotoPath` = VALUES(`PhotoPath`), `FaceDescriptor` = VALUES(`FaceDescriptor`), `UpdatedAt` = CURRENT_TIMESTAMP"
    );
    mysqli_stmt_bind_param($save, "sss", $username, $filename, $descriptorJson);
    mysqli_stmt_execute($save);
    mysqli_stmt_close($save);

    if ($hasEnrollment && preg_match('/\A[a-f0-9]{32}\.jpg\z/', basename($oldPhotoPath))) {
        $oldPhotoFile = $uploadDirectory . DIRECTORY_SEPARATOR . basename($oldPhotoPath);
        if (is_file($oldPhotoFile) && !unlink($oldPhotoFile)) {
            error_log("Replaced face enrollment photo could not be deleted for " . $username);
        }
    }

    faceEnrollmentRespond(200, true, "Pendaftaran wajah dan ciri biometrik berhasil disimpan.");
} catch (Throwable $error) {
    if (is_string($savedPhotoPath)) {
        $savedPhotoFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . "Uploads" . DIRECTORY_SEPARATOR
            . "Mahasiswa" . DIRECTORY_SEPARATOR . "FaceEnrollment" . DIRECTORY_SEPARATOR . $savedPhotoPath;
        if (is_file($savedPhotoFile)) {
            unlink($savedPhotoFile);
        }
    }
    error_log("Face enrollment error: " . $error->getMessage());
    faceEnrollmentRespond(500, false, "Pendaftaran foto wajah tidak dapat disimpan.");
}
