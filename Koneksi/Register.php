<?php
header("Content-Type: application/json; charset=utf-8");

function respond($statusCode, $success, $message, $extra = [])
{
    http_response_code($statusCode);
    echo json_encode(
        array_merge(["success" => $success, "message" => $message], $extra),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(405, false, "Metode tidak diizinkan.");
}

$fullName = trim($_POST["full_name"] ?? "");
$username = trim($_POST["username"] ?? "");
$nik = trim($_POST["nik"] ?? "");
$birthDate = trim($_POST["birth_date"] ?? "");
$email = trim($_POST["email"] ?? "");
$province = trim($_POST["province"] ?? ($_POST["region"] ?? ""));
$regency = trim($_POST["regency"] ?? "");
$district = trim($_POST["district"] ?? "");
$village = trim($_POST["village"] ?? "");
$gender = trim($_POST["gender"] ?? "");
$country = trim($_POST["country"] ?? "");
$role = "mahasiswa";
$password = $_POST["password"] ?? "";
$passwordConfirm = $_POST["password_confirm"] ?? "";
$action = trim($_POST["action"] ?? "send_code");
$verificationCode = trim($_POST["verification_code"] ?? "");
$latitudeValue = $_POST["latitude"] ?? "";
$longitudeValue = $_POST["longitude"] ?? "";

if (!in_array($action, ["send_code", "complete_registration"], true)) {
    respond(400, false, "Permintaan pendaftaran tidak valid.");
}

if ($fullName === "" || mb_strlen($fullName, "UTF-8") > 100) {
    respond(400, false, "Nama lengkap wajib diisi dan maksimal 100 karakter.");
}

if (!preg_match('/\A[A-Za-z0-9._-]{3,50}\z/', $username)) {
    respond(400, false, "Nama pengguna harus 3-50 karakter dan hanya berisi huruf, angka, titik, garis bawah, atau tanda hubung.");
}

if (!preg_match('/\A[0-9]{16}\z/', $nik)) {
    respond(400, false, "NIK harus terdiri dari tepat 16 digit.");
}

$birthDateObject = DateTime::createFromFormat("Y-m-d", $birthDate);
if (!$birthDateObject || $birthDateObject->format("Y-m-d") !== $birthDate) {
    respond(400, false, "Tanggal lahir tidak valid.");
}
if ($birthDateObject >= new DateTime("today")) {
    respond(400, false, "Tanggal lahir harus sebelum hari ini.");
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
    respond(400, false, "Alamat email tidak valid.");
}

foreach (
    [
        "Provinsi/daerah" => $province,
        "Kabupaten/kota" => $regency,
        "Kecamatan" => $district,
        "Desa/kelurahan" => $village,
    ] as $locationName => $locationValue
) {
    if ($locationValue === "" || mb_strlen($locationValue, "UTF-8") > 100) {
        respond(400, false, $locationName . " wajib diisi dan maksimal 100 karakter.");
    }
}

if (!in_array($gender, ["Laki-laki", "Perempuan", "Lainnya"], true)) {
    respond(400, false, "Jenis kelamin tidak valid.");
}

if ($country === "" || mb_strlen($country, "UTF-8") > 100) {
    respond(400, false, "Negara wajib diisi dan maksimal 100 karakter.");
}

if (strlen($password) < 8 || strlen($password) > 72) {
    respond(400, false, "Kata sandi harus terdiri dari 8-72 byte.");
}

if (!hash_equals($password, $passwordConfirm)) {
    respond(400, false, "Kata sandi dan konfirmasi kata sandi tidak sama.");
}

$photoPath = null;
if (!isset($_FILES["photo"]) || !is_uploaded_file($_FILES["photo"]["tmp_name"])) {
    respond(400, false, "Foto Mahasiswa wajib diunggah.");
}
if ($_FILES["photo"]["error"] !== UPLOAD_ERR_OK) {
    respond(400, false, "Foto gagal diunggah.");
}
if ((int) $_FILES["photo"]["size"] > 3 * 1024 * 1024) {
    respond(400, false, "Ukuran foto maksimal 3 MB.");
}
$allowedPhotoTypes = [
    "image/jpeg" => "jpg",
    "image/png" => "png",
    "image/webp" => "webp",
];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$photoMime = $finfo->file($_FILES["photo"]["tmp_name"]);
if (!isset($allowedPhotoTypes[$photoMime])) {
    respond(400, false, "Foto harus berformat JPG, PNG, atau WebP.");
}

$latitude = null;
$longitude = null;
if ($latitudeValue !== "" || $longitudeValue !== "") {
    if (
        $latitudeValue === "" ||
        $longitudeValue === "" ||
        !is_numeric($latitudeValue) ||
        !is_numeric($longitudeValue)
    ) {
        respond(400, false, "Koordinat lokasi tidak valid.");
    }

    $latitude = (float) $latitudeValue;
    $longitude = (float) $longitudeValue;
    if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        respond(400, false, "Koordinat lokasi berada di luar rentang yang valid.");
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$transactionStarted = false;

try {
    require_once __DIR__ . "/Koneksi.php";
    mysqli_set_charset($conn, "utf8mb4");

    $profileTableName = "mahasiswa_profiles";
    $profileTable = mysqli_prepare(
        $conn,
        "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1"
    );
    mysqli_stmt_bind_param($profileTable, "s", $profileTableName);
    mysqli_stmt_execute($profileTable);
    mysqli_stmt_store_result($profileTable);
    $profileTableExists = mysqli_stmt_num_rows($profileTable) === 1;
    mysqli_stmt_close($profileTable);
    if (!$profileTableExists) {
        respond(500, false, "Tabel profil belum tersedia. Import Koneksi/Setup_Database.sql melalui phpMyAdmin terlebih dahulu.");
    }

    $verificationTableName = "email_verification_codes";
    $verificationTable = mysqli_prepare(
        $conn,
        "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1"
    );
    mysqli_stmt_bind_param($verificationTable, "s", $verificationTableName);
    mysqli_stmt_execute($verificationTable);
    mysqli_stmt_store_result($verificationTable);
    $verificationTableExists = mysqli_stmt_num_rows($verificationTable) === 1;
    mysqli_stmt_close($verificationTable);
    if (!$verificationTableExists) {
        respond(500, false, "Tabel verifikasi email belum tersedia. Jalankan Koneksi/Migration_Email_Verification.sql terlebih dahulu.");
    }

    $checkUsername = mysqli_prepare($conn, "SELECT `Username` FROM `login` WHERE `Username` = ? LIMIT 1");
    mysqli_stmt_bind_param($checkUsername, "s", $username);
    mysqli_stmt_execute($checkUsername);
    mysqli_stmt_store_result($checkUsername);
    $usernameExists = mysqli_stmt_num_rows($checkUsername) > 0;
    mysqli_stmt_close($checkUsername);

    if ($usernameExists) {
        respond(409, false, "Nama pengguna sudah digunakan.");
    }

    $checkEmail = mysqli_prepare(
        $conn,
        "SELECT `Email` FROM `mahasiswa_profiles` WHERE `Email` = ?
         UNION ALL
         SELECT `Email` FROM `dosen_profiles` WHERE `Email` = ?
         LIMIT 1"
    );
    mysqli_stmt_bind_param($checkEmail, "ss", $email, $email);
    mysqli_stmt_execute($checkEmail);
    mysqli_stmt_store_result($checkEmail);
    $emailExists = mysqli_stmt_num_rows($checkEmail) > 0;
    mysqli_stmt_close($checkEmail);

    if ($emailExists) {
        respond(409, false, "Email sudah digunakan.");
    }

    $checkNik = mysqli_prepare($conn, "SELECT `NIK` FROM `mahasiswa_profiles` WHERE `NIK` = ? LIMIT 1");
    mysqli_stmt_bind_param($checkNik, "s", $nik);
    mysqli_stmt_execute($checkNik);
    mysqli_stmt_store_result($checkNik);
    $nikExists = mysqli_stmt_num_rows($checkNik) > 0;
    mysqli_stmt_close($checkNik);
    if ($nikExists) {
        respond(409, false, "NIK sudah digunakan.");
    }

    $checkNim = mysqli_prepare($conn, "SELECT `NIM` FROM `mahasiswa_profiles` WHERE `NIM` = ? LIMIT 1");
    mysqli_stmt_bind_param($checkNim, "s", $username);
    mysqli_stmt_execute($checkNim);
    mysqli_stmt_store_result($checkNim);
    $nimExists = mysqli_stmt_num_rows($checkNim) > 0;
    mysqli_stmt_close($checkNim);
    if ($nimExists) {
        respond(409, false, "NIM sudah digunakan.");
    }

    if ($action === "send_code") {
        mysqli_query(
            $conn,
            "DELETE FROM `email_verification_codes` WHERE `ExpiresAt` <= UTC_TIMESTAMP()"
        );

        $checkRecentCode = mysqli_prepare(
            $conn,
            "SELECT 1 FROM `email_verification_codes`
             WHERE `Email` = ? AND `LastSentAt` > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 SECOND)
             LIMIT 1"
        );
        mysqli_stmt_bind_param($checkRecentCode, "s", $email);
        mysqli_stmt_execute($checkRecentCode);
        mysqli_stmt_store_result($checkRecentCode);
        $codeSentRecently = mysqli_stmt_num_rows($checkRecentCode) > 0;
        mysqli_stmt_close($checkRecentCode);
        if ($codeSentRecently) {
            respond(429, false, "Tunggu 60 detik sebelum meminta kode verifikasi baru.");
        }

        $senderEmail = getenv("MAIL_FROM_EMAIL");
        if ($senderEmail === false || $senderEmail === "") {
            $senderEmail = ini_get("sendmail_from");
        }
        if (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
            respond(500, false, "Email pengirim belum dikonfigurasi. Atur MAIL_FROM_EMAIL atau sendmail_from pada PHP.");
        }

        $verificationCode = str_pad((string) random_int(0, 999999), 6, "0", STR_PAD_LEFT);
        $codeHash = password_hash($verificationCode, PASSWORD_DEFAULT);
        if ($codeHash === false) {
            throw new RuntimeException("Verification code hashing failed.");
        }

        $saveCode = mysqli_prepare(
            $conn,
            "INSERT INTO `email_verification_codes` (`Email`, `CodeHash`, `ExpiresAt`, `LastSentAt`, `Attempts`)
             VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE), UTC_TIMESTAMP(), 0)
             ON DUPLICATE KEY UPDATE `CodeHash` = VALUES(`CodeHash`),
               `ExpiresAt` = VALUES(`ExpiresAt`), `LastSentAt` = VALUES(`LastSentAt`), `Attempts` = 0"
        );
        mysqli_stmt_bind_param($saveCode, "ss", $email, $codeHash);
        mysqli_stmt_execute($saveCode);
        mysqli_stmt_close($saveCode);

        $subject = "Kode verifikasi pendaftaran SIABAK";
        $message = "Kode verifikasi pendaftaran akun SIABAK kamu adalah: " . $verificationCode .
            "\n\nKode berlaku selama 10 menit. Jika kamu tidak meminta kode ini, abaikan email ini.";
        $headers = "From: SIABAK <" . $senderEmail . ">\r\n" .
            "Content-Type: text/plain; charset=UTF-8";
        if (!mail($email, $subject, $message, $headers)) {
            $deleteCode = mysqli_prepare(
                $conn,
                "DELETE FROM `email_verification_codes` WHERE `Email` = ? AND `CodeHash` = ?"
            );
            mysqli_stmt_bind_param($deleteCode, "ss", $email, $codeHash);
            mysqli_stmt_execute($deleteCode);
            mysqli_stmt_close($deleteCode);
            error_log("Registration verification email could not be sent.");
            respond(500, false, "Email verifikasi gagal dikirim. Periksa konfigurasi SMTP/server email.");
        }

        respond(200, true, "Kode verifikasi telah dikirim ke email kamu.", ["verification_required" => true]);
    }

    if (!preg_match('/\A[0-9]{6}\z/', $verificationCode)) {
        respond(400, false, "Masukkan kode verifikasi 6 digit yang dikirim ke email.");
    }

    $findCode = mysqli_prepare(
        $conn,
        "SELECT `CodeHash`, `ExpiresAt` > UTC_TIMESTAMP() AS `IsValid`, `Attempts`
         FROM `email_verification_codes` WHERE `Email` = ? LIMIT 1"
    );
    mysqli_stmt_bind_param($findCode, "s", $email);
    mysqli_stmt_execute($findCode);
    $codeResult = mysqli_stmt_get_result($findCode);
    $codeRecord = mysqli_fetch_assoc($codeResult);
    mysqli_stmt_close($findCode);

    if (!$codeRecord || !(bool) $codeRecord["IsValid"]) {
        respond(400, false, "Kode verifikasi tidak ditemukan atau sudah kedaluwarsa. Minta kode baru.");
    }
    if ((int) $codeRecord["Attempts"] >= 5) {
        respond(429, false, "Terlalu banyak percobaan. Minta kode verifikasi baru.");
    }
    if (!password_verify($verificationCode, $codeRecord["CodeHash"])) {
        $updateAttempts = mysqli_prepare(
            $conn,
            "UPDATE `email_verification_codes` SET `Attempts` = `Attempts` + 1
             WHERE `Email` = ? AND `ExpiresAt` > UTC_TIMESTAMP() AND `Attempts` < 5"
        );
        mysqli_stmt_bind_param($updateAttempts, "s", $email);
        mysqli_stmt_execute($updateAttempts);
        mysqli_stmt_close($updateAttempts);
        respond(400, false, "Kode verifikasi salah.");
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    if ($hashedPassword === false) {
        throw new RuntimeException("Password hashing failed.");
    }

    mysqli_begin_transaction($conn);
    $transactionStarted = true;

    $insertLogin = mysqli_prepare($conn, "INSERT INTO `login` (`Username`, `Password`, `Role`) VALUES (?, ?, ?)");
    mysqli_stmt_bind_param($insertLogin, "sss", $username, $hashedPassword, $role);
    mysqli_stmt_execute($insertLogin);
    mysqli_stmt_close($insertLogin);

    $latitudeForDatabase = $latitude === null ? null : (string) $latitude;
    $longitudeForDatabase = $longitude === null ? null : (string) $longitude;

    $uploadDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . "Uploads" . DIRECTORY_SEPARATOR . "Mahasiswa";
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException("Photo directory could not be created.");
    }
    $photoExtension = $allowedPhotoTypes[$photoMime];
    $photoFilename = bin2hex(random_bytes(16)) . "." . $photoExtension;
    $photoTarget = $uploadDirectory . DIRECTORY_SEPARATOR . $photoFilename;
    if (!move_uploaded_file($_FILES["photo"]["tmp_name"], $photoTarget)) {
        throw new RuntimeException("Photo upload could not be saved.");
    }
    $photoPath = "Uploads/Mahasiswa/" . $photoFilename;

    $insertProfile = mysqli_prepare(
        $conn,
        "INSERT INTO `mahasiswa_profiles`
         (`Username`, `NIK`, `NIM`, `FullName`, `Email`, `Region`, `Country`, `Province`, `Regency`, `District`, `Village`, `Gender`, `BirthDate`, `PhotoPath`, `Latitude`, `Longitude`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param(
        $insertProfile,
        "ssssssssssssssss",
        $username,
        $nik,
        $username,
        $fullName,
        $email,
        $province,
        $country,
        $province,
        $regency,
        $district,
        $village,
        $gender,
        $birthDate,
        $photoPath,
        $latitudeForDatabase,
        $longitudeForDatabase
    );
    mysqli_stmt_execute($insertProfile);
    mysqli_stmt_close($insertProfile);

    $consumeCode = mysqli_prepare(
        $conn,
        "DELETE FROM `email_verification_codes`
         WHERE `Email` = ? AND `CodeHash` = ? AND `ExpiresAt` > UTC_TIMESTAMP() AND `Attempts` < 5"
    );
    mysqli_stmt_bind_param($consumeCode, "ss", $email, $codeRecord["CodeHash"]);
    mysqli_stmt_execute($consumeCode);
    $codeConsumed = mysqli_stmt_affected_rows($consumeCode) === 1;
    mysqli_stmt_close($consumeCode);
    if (!$codeConsumed) {
        throw new RuntimeException("Verification code expired or was already used.");
    }

    mysqli_commit($conn);
    $transactionStarted = false;
    respond(201, true, "Akun berhasil dibuat.");
} catch (mysqli_sql_exception $error) {
    if ($transactionStarted) {
        mysqli_rollback($conn);
    }

    error_log("Registration database error: " . $error->getMessage());
    if ((int) $error->getCode() === 1062) {
        respond(409, false, "Nama pengguna atau email sudah digunakan.");
    }
    respond(500, false, "Terjadi kesalahan pada server. Periksa konfigurasi database dan tabel pendaftaran.");
} catch (Throwable $error) {
    if ($transactionStarted) {
        mysqli_rollback($conn);
    }

    error_log("Registration error: " . $error->getMessage());
    respond(500, false, "Terjadi kesalahan pada server.");
}
