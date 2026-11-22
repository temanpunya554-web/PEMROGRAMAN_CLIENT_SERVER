<?php
session_start();

if (!isset($_SESSION["username"]) || !is_string($_SESSION["username"])) {
    header("Location: ../SIABAK.html?login=1");
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    require_once __DIR__ . "/../Koneksi/Koneksi.php";
    mysqli_set_charset($conn, "utf8mb4");

    $databaseRole = "";
    $roleStatement = mysqli_prepare($conn, "SELECT `Role` FROM `login` WHERE `Username` = ? LIMIT 1");
    mysqli_stmt_bind_param($roleStatement, "s", $_SESSION["username"]);
    mysqli_stmt_execute($roleStatement);
    mysqli_stmt_bind_result($roleStatement, $databaseRole);
    $roleFound = mysqli_stmt_fetch($roleStatement);
    mysqli_stmt_close($roleStatement);

    if (!$roleFound) {
        $_SESSION = [];
        session_destroy();
        header("Location: ../SIABAK.html?login=1");
        exit;
    }
    if ($databaseRole !== "dosen") {
        header("Location: " . ($databaseRole === "admin" ? "Admin.php" : "Beranda.php"));
        exit;
    }

    $_SESSION["role"] = "dosen";
    $dosenUsername = $_SESSION["username"];
    $dosenDisplayName = $dosenUsername;

    $dosenProfile = [
        "FullName" => $dosenDisplayName,
        "Email" => "",
        "Gender" => "",
        "NIP" => "",
        "Country" => "Indonesia",
        "Province" => "",
        "Regency" => "",
        "District" => "",
        "Village" => "",
        "BirthDate" => "",
        "PhotoPath" => "",
    ];

    $profileStatement = mysqli_prepare($conn, "SELECT * FROM `dosen_profiles` WHERE `Username` = ? LIMIT 1");
    mysqli_stmt_bind_param($profileStatement, "s", $dosenUsername);
    mysqli_stmt_execute($profileStatement);
    $profileResult = mysqli_stmt_get_result($profileStatement);
    if ($profileRow = mysqli_fetch_assoc($profileResult)) {
        $dosenProfile = array_merge($dosenProfile, $profileRow);
        if (!empty($profileRow["FullName"])) {
            $dosenDisplayName = $profileRow["FullName"];
        }
    }
    mysqli_stmt_close($profileStatement);
} catch (Throwable $error) {
    error_log("Dosen role verification error: " . $error->getMessage());
    http_response_code(500);
    exit("Peran dosen tidak dapat diverifikasi.");
}

if (!isset($_SESSION["csrf_token"]) || !is_string($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}
$roomCsrfToken = $_SESSION["csrf_token"];

function dosenEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
