<?php
header("Content-Type: application/json; charset=utf-8");
session_start();

function adminUsersRespond($statusCode, $success, $message, $extra = [])
{
    http_response_code($statusCode);
    echo json_encode(array_merge([
        "success" => $success,
        "message" => $message,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function adminUsersExists($conn, $sql, $types, ...$params)
{
    $statement = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($statement, $types, ...$params);
    mysqli_stmt_execute($statement);
    mysqli_stmt_store_result($statement);
    $exists = mysqli_stmt_num_rows($statement) > 0;
    mysqli_stmt_close($statement);
    return $exists;
}

function adminUsersText($key, $maxLength = 100)
{
    $value = trim($_POST[$key] ?? "");
    return mb_strlen($value, "UTF-8") > $maxLength ? false : $value;
}

if (!isset($_SESSION["username"]) || !is_string($_SESSION["username"])) {
    adminUsersRespond(401, false, "Silakan login terlebih dahulu.");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$transactionStarted = false;
$genderOptions = ["Laki-laki", "Perempuan", "Lainnya"];

try {
    require_once __DIR__ . "/Koneksi.php";
    mysqli_set_charset($conn, "utf8mb4");

    $roleStatement = mysqli_prepare($conn, "SELECT `Role` FROM `login` WHERE `Username` = ? LIMIT 1");
    mysqli_stmt_bind_param($roleStatement, "s", $_SESSION["username"]);
    mysqli_stmt_execute($roleStatement);
    mysqli_stmt_bind_result($roleStatement, $adminRole);
    $adminFound = mysqli_stmt_fetch($roleStatement);
    mysqli_stmt_close($roleStatement);
    if (!$adminFound || $adminRole !== "admin") {
        adminUsersRespond(403, false, "Hanya admin yang dapat mengakses data ini.");
    }

    $genderColumn = mysqli_query(
        $conn,
        "SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'dosen_profiles' AND column_name = 'Gender' LIMIT 1"
    );
    $dosenHasGender = mysqli_num_rows($genderColumn) === 1;

    // ---------- Membaca data ----------
    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        $type = $_GET["type"] ?? "";

        if ($type === "mahasiswa") {
            $result = mysqli_query(
                $conn,
                "SELECT login.`Username`, profiles.`NIK`, profiles.`NIM`, profiles.`FullName`, profiles.`Email`,
                        profiles.`Gender`, profiles.`BirthDate`, profiles.`Country`, profiles.`Province`,
                        profiles.`Regency`, profiles.`District`, profiles.`Village`
                 FROM `login`
                 LEFT JOIN `mahasiswa_profiles` AS profiles ON profiles.`Username` = login.`Username`
                 WHERE login.`Role` = 'mahasiswa'
                 ORDER BY COALESCE(profiles.`FullName`, login.`Username`) ASC"
            );
            adminUsersRespond(200, true, "Data mahasiswa berhasil dimuat.", [
                "rows" => mysqli_fetch_all($result, MYSQLI_ASSOC),
            ]);
        }

        if ($type === "dosen") {
            $genderSelect = $dosenHasGender ? "profiles.`Gender`" : "'' AS `Gender`";
            $result = mysqli_query(
                $conn,
                "SELECT login.`Username`, profiles.`FullName`, profiles.`Email`, " . $genderSelect . "
                 FROM `login`
                 LEFT JOIN `dosen_profiles` AS profiles ON profiles.`Username` = login.`Username`
                 WHERE login.`Role` = 'dosen'
                 ORDER BY COALESCE(profiles.`FullName`, login.`Username`) ASC"
            );
            adminUsersRespond(200, true, "Data dosen berhasil dimuat.", [
                "rows" => mysqli_fetch_all($result, MYSQLI_ASSOC),
                "gender_supported" => $dosenHasGender,
            ]);
        }

        adminUsersRespond(400, false, "Jenis data tidak valid.");
    }

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        adminUsersRespond(405, false, "Metode tidak diizinkan.");
    }

    $submittedToken = $_POST["csrf_token"] ?? "";
    if (
        !is_string($submittedToken) ||
        !isset($_SESSION["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $submittedToken)
    ) {
        adminUsersRespond(400, false, "Permintaan tidak valid. Muat ulang halaman dan coba lagi.");
    }

    $action = trim($_POST["action"] ?? "");
    $targetUsername = trim($_POST["username"] ?? "");
    $fullName = adminUsersText("full_name");
    $email = trim($_POST["email"] ?? "");
    $gender = trim($_POST["gender"] ?? "");

    if ($targetUsername === "") {
        adminUsersRespond(400, false, "Akun yang diubah tidak valid.");
    }
    if ($fullName === false || $fullName === "") {
        adminUsersRespond(400, false, "Nama lengkap wajib diisi dan maksimal 100 karakter.");
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        adminUsersRespond(400, false, "Alamat email tidak valid.");
    }

    $emailSql = "SELECT 1 FROM `mahasiswa_profiles` WHERE `Email` = ? AND `Username` <> ?
                 UNION ALL
                 SELECT 1 FROM `dosen_profiles` WHERE `Email` = ? AND `Username` <> ?
                 UNION ALL
                 SELECT 1 FROM `account_profiles` WHERE `Email` = ? AND `Username` <> ?
                 LIMIT 1";

    // ---------- Mengubah mahasiswa ----------
    if ($action === "update_mahasiswa") {
        if (!in_array($gender, $genderOptions, true)) {
            adminUsersRespond(400, false, "Jenis kelamin tidak valid.");
        }

        $nik = trim($_POST["nik"] ?? "");
        $nim = adminUsersText("nim", 50);
        $birthDate = trim($_POST["birth_date"] ?? "");
        $country = adminUsersText("country");
        $province = adminUsersText("province");
        $regency = adminUsersText("regency");
        $district = adminUsersText("district");
        $village = adminUsersText("village");

        if ($nik !== "" && !preg_match('/\A[0-9]{16}\z/', $nik)) {
            adminUsersRespond(400, false, "NIK harus terdiri dari tepat 16 digit.");
        }
        if ($nim === false) {
            adminUsersRespond(400, false, "NIM maksimal 50 karakter.");
        }
        if ($birthDate !== "") {
            $birthDateObject = DateTime::createFromFormat("Y-m-d", $birthDate);
            if (!$birthDateObject || $birthDateObject->format("Y-m-d") !== $birthDate) {
                adminUsersRespond(400, false, "Tanggal lahir tidak valid.");
            }
            if ($birthDateObject >= new DateTime("today")) {
                adminUsersRespond(400, false, "Tanggal lahir harus sebelum hari ini.");
            }
        }
        foreach (["Negara" => $country, "Provinsi" => $province, "Kabupaten/kota" => $regency, "Kecamatan" => $district, "Desa/kelurahan" => $village] as $label => $value) {
            if ($value === false) {
                adminUsersRespond(400, false, $label . " maksimal 100 karakter.");
            }
        }

        $nikValue = $nik === "" ? null : $nik;
        $nimValue = $nim === "" ? null : $nim;
        $birthDateValue = $birthDate === "" ? null : $birthDate;

        if (!adminUsersExists($conn, "SELECT 1 FROM `login` WHERE `Username` = ? AND `Role` = 'mahasiswa' LIMIT 1", "s", $targetUsername)) {
            adminUsersRespond(404, false, "Akun mahasiswa tidak ditemukan.");
        }
        if (adminUsersExists($conn, $emailSql, "ssssss", $email, $targetUsername, $email, $targetUsername, $email, $targetUsername)) {
            adminUsersRespond(409, false, "Email sudah digunakan akun lain.");
        }
        if ($nikValue !== null && adminUsersExists($conn, "SELECT 1 FROM `mahasiswa_profiles` WHERE `NIK` = ? AND `Username` <> ? LIMIT 1", "ss", $nikValue, $targetUsername)) {
            adminUsersRespond(409, false, "NIK sudah digunakan akun lain.");
        }
        if ($nimValue !== null && adminUsersExists($conn, "SELECT 1 FROM `mahasiswa_profiles` WHERE `NIM` = ? AND `Username` <> ? LIMIT 1", "ss", $nimValue, $targetUsername)) {
            adminUsersRespond(409, false, "NIM sudah digunakan akun lain.");
        }

        $profileExists = adminUsersExists($conn, "SELECT 1 FROM `mahasiswa_profiles` WHERE `Username` = ? LIMIT 1", "s", $targetUsername);

        mysqli_begin_transaction($conn);
        $transactionStarted = true;

        if ($profileExists) {
            $statement = mysqli_prepare(
                $conn,
                "UPDATE `mahasiswa_profiles`
                 SET `NIK` = ?, `NIM` = ?, `FullName` = ?, `Email` = ?, `Gender` = ?, `BirthDate` = ?,
                     `Country` = ?, `Province` = ?, `Regency` = ?, `District` = ?, `Village` = ?, `Region` = ?
                 WHERE `Username` = ?"
            );
            mysqli_stmt_bind_param(
                $statement,
                "sssssssssssss",
                $nikValue, $nimValue, $fullName, $email, $gender, $birthDateValue,
                $country, $province, $regency, $district, $village, $province, $targetUsername
            );
        } else {
            $statement = mysqli_prepare(
                $conn,
                "INSERT INTO `mahasiswa_profiles`
                 (`NIK`, `NIM`, `FullName`, `Email`, `Gender`, `BirthDate`, `Country`, `Province`, `Regency`, `District`, `Village`, `Region`, `Username`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            mysqli_stmt_bind_param(
                $statement,
                "sssssssssssss",
                $nikValue, $nimValue, $fullName, $email, $gender, $birthDateValue,
                $country, $province, $regency, $district, $village, $province, $targetUsername
            );
        }
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);

        // Tabel lama account_profiles ikut disinkronkan (sama seperti perubahan profil oleh mahasiswa).
        $legacy = mysqli_prepare(
            $conn,
            "UPDATE `account_profiles`
             SET `NIK` = ?, `NIM` = ?, `FullName` = ?, `Email` = ?, `Gender` = ?, `BirthDate` = ?,
                 `Country` = ?, `Province` = ?, `Regency` = ?, `District` = ?, `Village` = ?, `Region` = ?
             WHERE `Username` = ?"
        );
        mysqli_stmt_bind_param(
            $legacy,
            "sssssssssssss",
            $nikValue, $nimValue, $fullName, $email, $gender, $birthDateValue,
            $country, $province, $regency, $district, $village, $province, $targetUsername
        );
        mysqli_stmt_execute($legacy);
        mysqli_stmt_close($legacy);

        mysqli_commit($conn);
        $transactionStarted = false;
        adminUsersRespond(200, true, "Data mahasiswa berhasil diperbarui.");
    }

    // ---------- Mengubah dosen ----------
    if ($action === "update_dosen") {
        if (!$dosenHasGender) {
            adminUsersRespond(500, false, "Jalankan Koneksi/Migration_Admin_Dosen_Gender.sql terlebih dahulu.");
        }
        if (!in_array($gender, $genderOptions, true)) {
            adminUsersRespond(400, false, "Jenis kelamin tidak valid.");
        }
        if (!adminUsersExists($conn, "SELECT 1 FROM `login` WHERE `Username` = ? AND `Role` = 'dosen' LIMIT 1", "s", $targetUsername)) {
            adminUsersRespond(404, false, "Akun dosen tidak ditemukan.");
        }
        if (adminUsersExists($conn, $emailSql, "ssssss", $email, $targetUsername, $email, $targetUsername, $email, $targetUsername)) {
            adminUsersRespond(409, false, "Email sudah digunakan akun lain.");
        }

        $profileExists = adminUsersExists($conn, "SELECT 1 FROM `dosen_profiles` WHERE `Username` = ? LIMIT 1", "s", $targetUsername);

        mysqli_begin_transaction($conn);
        $transactionStarted = true;

        if ($profileExists) {
            $statement = mysqli_prepare($conn, "UPDATE `dosen_profiles` SET `FullName` = ?, `Email` = ?, `Gender` = ? WHERE `Username` = ?");
        } else {
            $statement = mysqli_prepare($conn, "INSERT INTO `dosen_profiles` (`FullName`, `Email`, `Gender`, `Username`) VALUES (?, ?, ?, ?)");
        }
        mysqli_stmt_bind_param($statement, "ssss", $fullName, $email, $gender, $targetUsername);
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);

        $legacy = mysqli_prepare($conn, "UPDATE `account_profiles` SET `FullName` = ?, `Email` = ?, `Gender` = ? WHERE `Username` = ?");
        mysqli_stmt_bind_param($legacy, "ssss", $fullName, $email, $gender, $targetUsername);
        mysqli_stmt_execute($legacy);
        mysqli_stmt_close($legacy);

        mysqli_commit($conn);
        $transactionStarted = false;
        adminUsersRespond(200, true, "Data dosen berhasil diperbarui.");
    }

    adminUsersRespond(400, false, "Aksi tidak dikenal.");
} catch (mysqli_sql_exception $error) {
    if ($transactionStarted) {
        mysqli_rollback($conn);
    }
    if ((int) $error->getCode() === 1062) {
        adminUsersRespond(409, false, "Data duplikat: email, NIK, atau NIM sudah dipakai akun lain.");
    }
    error_log("Admin users error: " . $error->getMessage());
    adminUsersRespond(500, false, "Data tidak dapat diproses.");
} catch (Throwable $error) {
    if ($transactionStarted) {
        mysqli_rollback($conn);
    }
    error_log("Admin users error: " . $error->getMessage());
    adminUsersRespond(500, false, "Data tidak dapat diproses.");
}
