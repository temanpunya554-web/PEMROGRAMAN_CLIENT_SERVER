<?php
$host = getenv("SIABAK_DB_HOST");
$dbUsername = getenv("SIABAK_DB_USERNAME");
$dbPassword = getenv("SIABAK_DB_PASSWORD");
$database = getenv("SIABAK_DB_NAME");
$portValue = getenv("SIABAK_DB_PORT");

if (
    $host === false || $host === "" ||
    $dbUsername === false || $dbUsername === "" ||
    $dbPassword === false ||
    $database === false || $database === ""
) {
    throw new RuntimeException(
        "Konfigurasi database belum lengkap. Atur SIABAK_DB_HOST, SIABAK_DB_USERNAME, SIABAK_DB_PASSWORD, dan SIABAK_DB_NAME."
    );
}

$port = 3306;
if ($portValue !== false && $portValue !== "") {
    $port = filter_var($portValue, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 65535]]);
    if ($port === false) {
        throw new RuntimeException("SIABAK_DB_PORT harus berupa angka antara 1 dan 65535.");
    }
}

$conn = mysqli_connect($host, $dbUsername, $dbPassword, $database, $port);

if (!$conn) {
    throw new RuntimeException("Database connection failed.");
}
