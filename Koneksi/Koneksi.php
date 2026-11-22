<?php
$host = "localhost";
$dbUsername = "root";
$dbPassword = "";
$database = "siabak";

$conn = mysqli_connect($host, $dbUsername, $dbPassword, $database);

if (!$conn) {
    throw new RuntimeException("Database connection failed.");
}

?>