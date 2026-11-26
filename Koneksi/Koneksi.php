<?php
$readDatabaseEnv = static function ($name, $legacyName) {
    $value = getenv($name);
    return $value !== false ? $value : getenv($legacyName);
};

$host = $readDatabaseEnv("DB_HOST", "SIABAK_DB_HOST");
$dbUsername = $readDatabaseEnv("DB_USER", "SIABAK_DB_USERNAME");
$dbPassword = $readDatabaseEnv("DB_PASSWORD", "SIABAK_DB_PASSWORD");
$database = $readDatabaseEnv("DB_NAME", "SIABAK_DB_NAME");
$portValue = $readDatabaseEnv("DB_PORT", "SIABAK_DB_PORT");

if (
    $host === false || $host === "" ||
    $dbUsername === false || $dbUsername === "" ||
    $dbPassword === false ||
    $database === false || $database === ""
) {
    throw new RuntimeException(
        "Konfigurasi database belum lengkap. Atur DB_HOST, DB_USER, DB_PASSWORD, dan DB_NAME."
    );
}

$port = 3306;
if ($portValue !== false && $portValue !== "") {
    $port = filter_var($portValue, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 65535]]);
    if ($port === false) {
        throw new RuntimeException("DB_PORT harus berupa angka antara 1 dan 65535.");
    }
}

$conn = mysqli_connect($host, $dbUsername, $dbPassword, $database, $port);

if (!$conn) {
    throw new RuntimeException("Database connection failed.");
}
