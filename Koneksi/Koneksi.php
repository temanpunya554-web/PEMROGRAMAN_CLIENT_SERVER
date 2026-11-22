<?php
$host = "ym3jks.h.filess.io";
$dbUsername = "SistemAbsensi_Akademik_eventplan";
$dbPassword = "8fcp5yHLcxgExWc8ddeM6znVTA3jFXNbdVcpRTcr7TY4";
$database = "SistemAbsensi_Akademik_eventplan";
$port = "3306"

$conn = mysqli_connect($host, $dbUsername, $dbPassword, $database, $port);

if (!$conn) {
    throw new RuntimeException("Database connection failed.");
}

?>
