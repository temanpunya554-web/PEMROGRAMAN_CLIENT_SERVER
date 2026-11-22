<?php
header("Content-Type: application/json; charset=utf-8");
session_start();

function dosenProfileRespond($statusCode, $success, $message)
{
    http_response_code($statusCode);
    echo json_encode(["success" => $success, "message" => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    dosenProfileRespond(405, false, "Metode tidak diizinkan.");
}
if (!isset($_SESSION["username"]) || !is_string($_SESSION["username"])) {
    dosenProfileRespond(401, false, "Silakan login terlebih dahulu.");
}

$submittedToken = $_POST["csrf_token"] ?? "";
if (
    !is_string($submittedToken) ||
    !isset($_SESSION["csrf_token"]) ||
    !hash_equals($_SESSION["csrf_token"], $submittedToken)
) {
    dosenProfileRespond(400, false, "Permintaan tidak valid. Muat ulang halaman dan coba lagi.");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$transactionStarted = false;
$newPhotoPath = null;

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

    if (!$accountFound || $role !== "dosen") {
        dosenProfileRespond(403, false, "Perubahan profil ini hanya tersedia untuk akun dosen.");
    }

    $fullName = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $gender = trim($_POST["gender"] ?? "");
    $nip = trim($_POST["nip"] ?? "");
    $country = trim($_POST["country"] ?? "Indonesia");
    $province = trim($_POST["province"] ?? "");
    $regency = trim($_POST["regency"] ?? "");
    $district = trim($_POST["district"] ?? "");
    $village = trim($_POST["village"] ?? "");

    if ($fullName === "" || mb_strlen($fullName, "UTF-8") > 100) {
        dosenProfileRespond(400, false, "Nama lengkap wajib diisi dan maksimal 100 karakter.");
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        dosenProfileRespond(400, false, "Alamat email tidak valid.");
    }
    if (!in_array($gender, ["Laki-laki", "Perempuan", "Lainnya"], true)) {
        dosenProfileRespond(400, false, "Pilih jenis kelamin yang valid.");
    }
    if ($nip !== "" && mb_strlen($nip, "UTF-8") > 50) {
        dosenProfileRespond(400, false, "NIP maksimal 50 karakter.");
    }

    foreach ([
        "Negara" => $country,
        "Provinsi" => $province,
        "Kabupaten/kota" => $regency,
        "Kecamatan" => $district,
        "Desa/kelurahan" => $village,
    ] as $fieldName => $fieldValue) {
        if ($fieldValue === "" || mb_strlen($fieldValue, "UTF-8") > 100) {
            dosenProfileRespond(400, false, $fieldName . " wajib diisi dan maksimal 100 karakter.");
        }
    }

    $nipValue = $nip === "" ? null : $nip;

    // Cek keunikan email
    $emailCheck = mysqli_prepare(
        $conn,
        "SELECT 1 FROM `mahasiswa_profiles` WHERE `Email` = ? AND `Username` <> ?
         UNION ALL
         SELECT 1 FROM `dosen_profiles` WHERE `Email` = ? AND `Username` <> ?
         UNION ALL
         SELECT 1 FROM `account_profiles` WHERE `Email` = ? AND `Username` <> ?
         LIMIT 1"
    );
    mysqli_stmt_bind_param($emailCheck, "ssssss", $email, $username, $email, $username, $email, $username);
    mysqli_stmt_execute($emailCheck);
    mysqli_stmt_store_result($emailCheck);
    if (mysqli_stmt_num_rows($emailCheck) > 0) {
        mysqli_stmt_close($emailCheck);
        dosenProfileRespond(409, false, "Email sudah digunakan oleh akun lain.");
    }
    mysqli_stmt_close($emailCheck);

    // Dapatkan foto profil lama jika ada
    $oldPhotoPath = null;
    $photoLookup = mysqli_prepare($conn, "SELECT `PhotoPath` FROM `dosen_profiles` WHERE `Username` = ? LIMIT 1");
    if ($photoLookup) {
        mysqli_stmt_bind_param($photoLookup, "s", $username);
        mysqli_stmt_execute($photoLookup);
        mysqli_stmt_bind_result($photoLookup, $oldPhotoPath);
        mysqli_stmt_fetch($photoLookup);
        mysqli_stmt_close($photoLookup);
    }

    // Pemrosesan unggah foto profil dosen
    if (isset($_FILES["photo"]) && $_FILES["photo"]["error"] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES["photo"]["error"] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES["photo"]["tmp_name"])) {
            dosenProfileRespond(400, false, "Foto tidak berhasil diunggah.");
        }
        if ((int) $_FILES["photo"]["size"] < 1 || (int) $_FILES["photo"]["size"] > 3 * 1024 * 1024) {
            dosenProfileRespond(400, false, "Ukuran foto maksimal 3 MB.");
        }

        $allowedPhotoTypes = [
            "image/jpeg" => "jpg",
            "image/png" => "png",
            "image/webp" => "webp",
        ];
        $photoMime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES["photo"]["tmp_name"]);
        $imageInfo = getimagesize($_FILES["photo"]["tmp_name"]);
        if (!isset($allowedPhotoTypes[$photoMime]) || $imageInfo === false) {
            dosenProfileRespond(400, false, "Foto harus berupa file JPG, PNG, atau WebP yang valid.");
        }

        $uploadDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . "Uploads" . DIRECTORY_SEPARATOR . "Dosen";
        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
            throw new RuntimeException("Direktori foto dosen tidak dapat dibuat.");
        }

        $filename = bin2hex(random_bytes(16)) . "." . $allowedPhotoTypes[$photoMime];
        $targetPath = $uploadDirectory . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($_FILES["photo"]["tmp_name"], $targetPath)) {
            throw new RuntimeException("Foto profil dosen tidak dapat disimpan.");
        }
        $newPhotoPath = "Uploads/Dosen/" . $filename;
    }

    // Cek kolom tabel dosen_profiles secara dinamis
    $colResult = mysqli_query($conn, "SHOW COLUMNS FROM `dosen_profiles`");
    $existingCols = [];
    while ($col = mysqli_fetch_assoc($colResult)) {
        $existingCols[] = $col["Field"];
    }

    mysqli_begin_transaction($conn);
    $transactionStarted = true;

    // Cek apakah data dosen sudah ada di tabel dosen_profiles
    $existsStatement = mysqli_prepare($conn, "SELECT 1 FROM `dosen_profiles` WHERE `Username` = ? LIMIT 1");
    mysqli_stmt_bind_param($existsStatement, "s", $username);
    mysqli_stmt_execute($existsStatement);
    mysqli_stmt_store_result($existsStatement);
    $profileExists = mysqli_stmt_num_rows($existsStatement) === 1;
    mysqli_stmt_close($existsStatement);

    if ($profileExists) {
        $updateFields = [
            "`FullName` = ?",
            "`Email` = ?"
        ];
        $types = "ss";
        $params = [&$fullName, &$email];

        if (in_array("Gender", $existingCols, true)) {
            $updateFields[] = "`Gender` = ?";
            $types .= "s";
            $params[] = &$gender;
        }
        if (in_array("NIP", $existingCols, true)) {
            $updateFields[] = "`NIP` = ?";
            $types .= "s";
            $params[] = &$nipValue;
        }
        if (in_array("Country", $existingCols, true)) {
            $updateFields[] = "`Country` = ?";
            $types .= "s";
            $params[] = &$country;
        }
        if (in_array("Province", $existingCols, true)) {
            $updateFields[] = "`Province` = ?";
            $types .= "s";
            $params[] = &$province;
        }
        if (in_array("Regency", $existingCols, true)) {
            $updateFields[] = "`Regency` = ?";
            $types .= "s";
            $params[] = &$regency;
        }
        if (in_array("District", $existingCols, true)) {
            $updateFields[] = "`District` = ?";
            $types .= "s";
            $params[] = &$district;
        }
        if (in_array("Village", $existingCols, true)) {
            $updateFields[] = "`Village` = ?";
            $types .= "s";
            $params[] = &$village;
        }
        if (in_array("Region", $existingCols, true)) {
            $updateFields[] = "`Region` = ?";
            $types .= "s";
            $params[] = &$province;
        }
        if (in_array("PhotoPath", $existingCols, true) && $newPhotoPath !== null) {
            $updateFields[] = "`PhotoPath` = ?";
            $types .= "s";
            $params[] = &$newPhotoPath;
        }

        $types .= "s";
        $params[] = &$username;

        $updateSql = "UPDATE `dosen_profiles` SET " . implode(", ", $updateFields) . " WHERE `Username` = ?";
        $update = mysqli_prepare($conn, $updateSql);
        mysqli_stmt_bind_param($update, $types, ...$params);
        mysqli_stmt_execute($update);
        mysqli_stmt_close($update);
    } else {
        // Jika belum ada record
        $photoToInsert = $newPhotoPath ?? null;
        $insert = mysqli_prepare(
            $conn,
            "INSERT INTO `dosen_profiles` (`Username`, `FullName`, `Email`, `Gender`, `NIP`, `Country`, `Province`, `Regency`, `District`, `Village`, `Region`, `PhotoPath`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param(
            $insert,
            "ssssssssssss",
            $username, $fullName, $email, $gender, $nipValue,
            $country, $province, $regency, $district, $village, $province, $photoToInsert
        );
        mysqli_stmt_execute($insert);
        mysqli_stmt_close($insert);
    }

    // Sinkronisasi data ke account_profiles (legacy) jika tabel ada
    $legacyCheck = mysqli_query($conn, "SHOW TABLES LIKE 'account_profiles'");
    if (mysqli_num_rows($legacyCheck) === 1) {
        $legacy = mysqli_prepare(
            $conn,
            "UPDATE `account_profiles`
             SET `FullName` = ?, `Email` = ?, `Gender` = ?, `Country` = ?, `Province` = ?,
                 `Regency` = ?, `District` = ?, `Village` = ?, `Region` = ?,
                 `PhotoPath` = COALESCE(?, `PhotoPath`)
             WHERE `Username` = ?"
        );
        mysqli_stmt_bind_param(
            $legacy,
            "sssssssssss",
            $fullName, $email, $gender, $country, $province,
            $regency, $district, $village, $province, $newPhotoPath, $username
        );
        mysqli_stmt_execute($legacy);
        mysqli_stmt_close($legacy);
    }

    mysqli_commit($conn);
    $transactionStarted = false;

    // Hapus foto lama jika foto baru berhasil disimpan
    if ($newPhotoPath !== null && is_string($oldPhotoPath) && $oldPhotoPath !== "") {
        $oldAbsolute = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace("/", DIRECTORY_SEPARATOR, $oldPhotoPath);
        if (is_file($oldAbsolute) && strpos($oldPhotoPath, "Uploads/Dosen/") === 0) {
            @unlink($oldAbsolute);
        }
    }

    dosenProfileRespond(200, true, "Profil dosen dan biodata berhasil diperbarui.");
} catch (Throwable $error) {
    if ($transactionStarted && isset($conn)) {
        mysqli_rollback($conn);
    }
    if ($newPhotoPath !== null) {
        $uploadedFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace("/", DIRECTORY_SEPARATOR, $newPhotoPath);
        if (is_file($uploadedFile)) {
            @unlink($uploadedFile);
        }
    }
    error_log("Update dosen profile error: " . $error->getMessage());
    dosenProfileRespond(500, false, "Profil tidak dapat diperbarui. Terjadi kesalahan pada server.");
}
