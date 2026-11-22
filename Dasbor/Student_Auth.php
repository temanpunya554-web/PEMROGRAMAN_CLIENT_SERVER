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

    $roleStatement = mysqli_prepare($conn, "SELECT `Role` FROM `login` WHERE `Username` = ? LIMIT 1");
    mysqli_stmt_bind_param($roleStatement, "s", $_SESSION["username"]);
    mysqli_stmt_execute($roleStatement);
    mysqli_stmt_bind_result($roleStatement, $studentRole);
    $accountFound = mysqli_stmt_fetch($roleStatement);
    mysqli_stmt_close($roleStatement);

    if (!$accountFound) {
        $_SESSION = [];
        session_destroy();
        header("Location: ../SIABAK.html?login=1");
        exit;
    }

    if ($studentRole === "dosen") {
        header("Location: Dosen.php");
        exit;
    }
    if ($studentRole === "admin") {
        header("Location: Admin.php");
        exit;
    }

    $_SESSION["role"] = "mahasiswa";
    $studentUsername = $_SESSION["username"];
    $profileStatement = mysqli_prepare(
        $conn,
        "SELECT `NIK`, `NIM`, `FullName`, `Email`, `Country`, `Province`, `Regency`,
                `District`, `Village`, `Gender`, `BirthDate`, `PhotoPath`
         FROM `mahasiswa_profiles` WHERE `Username` = ? LIMIT 1"
    );
    mysqli_stmt_bind_param($profileStatement, "s", $studentUsername);
    mysqli_stmt_execute($profileStatement);
    $profileResult = mysqli_stmt_get_result($profileStatement);
    $studentProfile = mysqli_fetch_assoc($profileResult) ?: [];
    mysqli_stmt_close($profileStatement);
} catch (Throwable $error) {
    error_log("Student dashboard initialization error: " . $error->getMessage());
    http_response_code(500);
    exit("Dashboard mahasiswa tidak dapat dimuat.");
}

if (!isset($_SESSION["csrf_token"]) || !is_string($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}
$studentCsrfToken = $_SESSION["csrf_token"];
$studentDisplayName = trim((string) ($studentProfile["FullName"] ?? "")) ?: $studentUsername;

function studentEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
