<?php
$host = "ym3jks.h.filess.io";
$dbUsername = "SistemAbsensi_Akademik_eventplan";
$dbPassword = "Razya11223";
$database = "SistemAbsensi_Akademik_eventplan";
$port = "3306";

$conn = mysqli_connect($host, $dbUsername, $dbPassword, $database, $port);

if (!$conn) {
    throw new RuntimeException("Database connection failed.");
}

?>
