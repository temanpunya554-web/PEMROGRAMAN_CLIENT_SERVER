<?php
session_start();

if (!isset($_SESSION["username"]) || !is_string($_SESSION["username"])) {
    http_response_code(401);
    exit("Silakan login terlebih dahulu.");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    require_once __DIR__ . "/Koneksi.php";
    mysqli_set_charset($conn, "utf8mb4");

    $role = null;
    $roleStatement = mysqli_prepare($conn, "SELECT `Role` FROM `login` WHERE `Username` = ? LIMIT 1");
    mysqli_stmt_bind_param($roleStatement, "s", $_SESSION["username"]);
    mysqli_stmt_execute($roleStatement);
    mysqli_stmt_bind_result($roleStatement, $role);
    $accountFound = mysqli_stmt_fetch($roleStatement);
    mysqli_stmt_close($roleStatement);

    if (!$accountFound || !in_array($role, ["dosen", "admin"], true)) {
        http_response_code(403);
        exit("Hanya dosen atau operator yang dapat mengekspor data kehadiran.");
    }

    $startDate = trim($_GET["start_date"] ?? "");
    $endDate = trim($_GET["end_date"] ?? "");
    $roomName = trim($_GET["room"] ?? "");
    $isValidDate = static function ($value) {
        if ($value === "") return false;
        $parsed = DateTime::createFromFormat("Y-m-d", $value);
        return $parsed && $parsed->format("Y-m-d") === $value;
    };

    if ($startDate === "") $startDate = date("Y-m-d");
    if ($endDate === "") $endDate = $startDate;
    if (!$isValidDate($startDate) || !$isValidDate($endDate) || $endDate < $startDate) {
        http_response_code(400);
        exit("Rentang tanggal ekspor tidak valid.");
    }

    $sql = "SELECT attendance.Username, COALESCE(profiles.FullName, attendance.Username) AS FullName,
                   attendance.RoomName, attendance.FingerprintVerified, attendance.FaceVerified, attendance.VerifiedAt
            FROM class_attendance AS attendance
            LEFT JOIN mahasiswa_profiles AS profiles ON profiles.Username = attendance.Username
            WHERE DATE(attendance.VerifiedAt) BETWEEN ? AND ?";
    $types = "ss";
    $params = [$startDate, $endDate];
    if ($roomName !== "") {
        $sql .= " AND attendance.RoomName = ?";
        $types .= "s";
        $params[] = $roomName;
    }
    $sql .= " ORDER BY attendance.VerifiedAt DESC";

    $statement = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($statement, $types, ...$params);
    mysqli_stmt_execute($statement);
    $result = mysqli_stmt_get_result($statement);
    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
    mysqli_stmt_close($statement);

    $filename = "kehadiran_mahasiswa_" . date("Y-m-d_His") . ".xls";
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"" . $filename . "\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo "\xEF\xBB\xBF";
    echo "<html><head><meta charset=\"UTF-8\"><style>";
    echo "table{border-collapse:collapse;width:100%;font-family:Calibri,Arial,sans-serif}";
    echo "th{background:#223a6f;color:#fff}th,td{border:1px solid #808080;padding:8px;text-align:left}";
    echo "</style></head><body>";
    echo "<h2>Data Kehadiran Mahasiswa</h2>";
    echo "<p>Periode: " . htmlspecialchars($startDate, ENT_QUOTES, "UTF-8");
    if ($endDate !== $startDate) {
        echo " sampai " . htmlspecialchars($endDate, ENT_QUOTES, "UTF-8");
    }
    echo $roomName !== "" ? " | Ruangan: " . htmlspecialchars($roomName, ENT_QUOTES, "UTF-8") : "";
    echo "</p>";
    echo "<table><thead><tr>";
    echo "<th>NIM</th><th>Nama</th><th>Ruangan</th><th>Sidik Jari</th><th>Pengenalan Wajah</th><th>Waktu Verifikasi</th>";
    echo "</tr></thead><tbody>";

    foreach ($rows as $row) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row["Username"], ENT_QUOTES, "UTF-8") . "</td>";
        echo "<td>" . htmlspecialchars($row["FullName"], ENT_QUOTES, "UTF-8") . "</td>";
        echo "<td>" . htmlspecialchars($row["RoomName"], ENT_QUOTES, "UTF-8") . "</td>";
        echo "<td>" . ($row["FingerprintVerified"] ? "Berhasil" : "Belum") . "</td>";
        echo "<td>" . ($row["FaceVerified"] ? "Berhasil" : "Belum") . "</td>";
        echo "<td>" . htmlspecialchars($row["VerifiedAt"], ENT_QUOTES, "UTF-8") . "</td>";
        echo "</tr>";
    }

    echo "</tbody></table>";
    echo "<p>Total data: " . count($rows) . "</p>";
    echo "</body></html>";
} catch (Throwable $error) {
    error_log("Attendance export error: " . $error->getMessage());
    http_response_code(500);
    exit("Data kehadiran tidak dapat diekspor.");
}
