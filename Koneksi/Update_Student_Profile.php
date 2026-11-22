<?php
header("Content-Type: application/json; charset=utf-8");
session_start();

function studentProfileRespond($statusCode, $success, $message)
{
    http_response_code($statusCode);
    echo json_encode(["success" => $success, "message" => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    studentProfileRespond(405, false, "Metode tidak diizinkan.");
}
if (!isset($_SESSION["username"]) || !is_string($_SESSION["username"])) {
    studentProfileRespond(401, false, "Silakan login terlebih dahulu.");
}

$submittedToken = $_POST["csrf_token"] ?? "";
if (
    !is_string($submittedToken) ||
    !isset($_SESSION["csrf_token"]) ||
    !hash_equals($_SESSION["csrf_token"], $submittedToken)
) {
    studentProfileRespond(400, false, "Permintaan tidak valid. Muat ulang halaman dan coba lagi.");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$newPhotoPath = null;
$transactionStarted = false;

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
        studentProfileRespond(403, false, "Perubahan profil hanya tersedia untuk akun mahasiswa.");
    }

    $fullName = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $gender = trim($_POST["gender"] ?? "");
    $country = trim($_POST["country"] ?? "");
    $province = trim($_POST["province"] ?? "");
    $regency = trim($_POST["regency"] ?? "");
    $district = trim($_POST["district"] ?? "");
    $village = trim($_POST["village"] ?? "");

    if ($fullName === "" || mb_strlen($fullName, "UTF-8") > 100) {
        studentProfileRespond(400, false, "Nama lengkap wajib diisi dan maksimal 100 karakter.");
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        studentProfileRespond(400, false, "Alamat email tidak valid.");
    }
    if (!in_array($gender, ["Laki-laki", "Perempuan", "Lainnya"], true)) {
        studentProfileRespond(400, false, "Jenis kelamin tidak valid.");
    }
    foreach (
        [
            "Negara" => $country,
            "Provinsi" => $province,
            "Kabupaten/kota" => $regency,
            "Kecamatan" => $district,
            "Desa/kelurahan" => $village,
        ] as $fieldName => $fieldValue
    ) {
        if ($fieldValue === "" || mb_strlen($fieldValue, "UTF-8") > 100) {
            studentProfileRespond(400, false, $fieldName . " wajib diisi dan maksimal 100 karakter.");
        }
    }

    $emailCheck = mysqli_prepare(
        $conn,
        "SELECT `Username` FROM `mahasiswa_profiles` WHERE `Email` = ? AND `Username` <> ?
         UNION ALL
         SELECT `Username` FROM `dosen_profiles` WHERE `Email` = ? AND `Username` <> ?
         UNION ALL
         SELECT `Username` FROM `account_profiles` WHERE `Email` = ? AND `Username` <> ?
         LIMIT 1"
    );
    mysqli_stmt_bind_param($emailCheck, "ssssss", $email, $username, $email, $username, $email, $username);
    mysqli_stmt_execute($emailCheck);
    mysqli_stmt_store_result($emailCheck);
    $emailExists = mysqli_stmt_num_rows($emailCheck) > 0;
    mysqli_stmt_close($emailCheck);
    if ($emailExists) {
        studentProfileRespond(409, false, "Email sudah digunakan akun lain.");
    }

    $profileLookup = mysqli_prepare(
        $conn,
        "SELECT `PhotoPath` FROM `mahasiswa_profiles` WHERE `Username` = ? LIMIT 1"
    );
    mysqli_stmt_bind_param($profileLookup, "s", $username);
    mysqli_stmt_execute($profileLookup);
    mysqli_stmt_bind_result($profileLookup, $oldPhotoPath);
    $profileFound = mysqli_stmt_fetch($profileLookup);
    mysqli_stmt_close($profileLookup);
    if (!$profileFound) {
        studentProfileRespond(404, false, "Profil mahasiswa tidak ditemukan.");
    }

    if (isset($_FILES["photo"]) && $_FILES["photo"]["error"] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES["photo"]["error"] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES["photo"]["tmp_name"])) {
            studentProfileRespond(400, false, "Foto tidak berhasil diunggah.");
        }
        if ((int) $_FILES["photo"]["size"] < 1 || (int) $_FILES["photo"]["size"] > 3 * 1024 * 1024) {
            studentProfileRespond(400, false, "Ukuran foto maksimal 3 MB.");
        }

        $allowedPhotoTypes = [
            "image/jpeg" => "jpg",
            "image/png" => "png",
            "image/webp" => "webp",
        ];
        $photoMime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES["photo"]["tmp_name"]);
        $imageInfo = getimagesize($_FILES["photo"]["tmp_name"]);
        if (!isset($allowedPhotoTypes[$photoMime]) || $imageInfo === false) {
            studentProfileRespond(400, false, "Foto harus berupa JPG, PNG, atau WebP yang valid.");
        }

        $uploadDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . "Uploads" . DIRECTORY_SEPARATOR . "Mahasiswa";
        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
            throw new RuntimeException("Student photo directory could not be created.");
        }
        $filename = bin2hex(random_bytes(16)) . "." . $allowedPhotoTypes[$photoMime];
        $targetPath = $uploadDirectory . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($_FILES["photo"]["tmp_name"], $targetPath)) {
            throw new RuntimeException("Student profile photo could not be saved.");
        }
        $newPhotoPath = "Uploads/Mahasiswa/" . $filename;
    }

    mysqli_begin_transaction($conn);
    $transactionStarted = true;
    $updateProfile = mysqli_prepare(
        $conn,
        "UPDATE `mahasiswa_profiles`
         SET `FullName` = ?, `Email` = ?, `Gender` = ?, `Country` = ?, `Province` = ?,
             `Regency` = ?, `District` = ?, `Village` = ?, `Region` = ?,
             `PhotoPath` = COALESCE(?, `PhotoPath`)
         WHERE `Username` = ?"
    );
    mysqli_stmt_bind_param(
        $updateProfile,
        "sssssssssss",
        $fullName,
        $email,
        $gender,
        $country,
        $province,
        $regency,
        $district,
        $village,
        $province,
        $newPhotoPath,
        $username
    );
    mysqli_stmt_execute($updateProfile);
    mysqli_stmt_close($updateProfile);

    $updateAccountProfile = mysqli_prepare(
        $conn,
        "UPDATE `account_profiles`
         SET `FullName` = ?, `Email` = ?, `Gender` = ?, `Country` = ?, `Province` = ?,
             `Regency` = ?, `District` = ?, `Village` = ?, `Region` = ?,
             `PhotoPath` = COALESCE(?, `PhotoPath`)
         WHERE `Username` = ?"
    );
    mysqli_stmt_bind_param(
        $updateAccountProfile,
        "sssssssssss",
        $fullName,
        $email,
        $gender,
        $country,
        $province,
        $regency,
        $district,
        $village,
        $province,
        $newPhotoPath,
        $username
    );
    mysqli_stmt_execute($updateAccountProfile);
    mysqli_stmt_close($updateAccountProfile);
    mysqli_commit($conn);
    $transactionStarted = false;

    if (is_string($newPhotoPath) && is_string($oldPhotoPath)
        && preg_match('/\AUploads\/Mahasiswa\/[a-f0-9]{32}\.(jpg|png|webp)\z/', $oldPhotoPath)) {
        $oldPhotoFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace("/", DIRECTORY_SEPARATOR, $oldPhotoPath);
        if (is_file($oldPhotoFile) && !unlink($oldPhotoFile)) {
            error_log("Replaced profile photo could not be deleted for " . $username);
        }
    }

    studentProfileRespond(200, true, "Profil berhasil diperbarui.");
} catch (Throwable $error) {
    if ($transactionStarted) {
        mysqli_rollback($conn);
    }
    if (is_string($newPhotoPath)) {
        $newPhotoFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace("/", DIRECTORY_SEPARATOR, $newPhotoPath);
        if (is_file($newPhotoFile)) {
            unlink($newPhotoFile);
        }
    }
    error_log("Student profile update error: " . $error->getMessage());
    studentProfileRespond(500, false, "Profil tidak dapat diperbarui.");
}
