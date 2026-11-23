<?php
header("Content-Type: application/json; charset=utf-8");
session_start();

function chatRespond($statusCode, $success, $message, $extra = [])
{
    http_response_code($statusCode);
    echo json_encode(array_merge([
        "success" => $success,
        "message" => $message,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION["username"]) || !is_string($_SESSION["username"])) {
    chatRespond(401, false, "Silakan login terlebih dahulu.");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    require_once __DIR__ . "/Koneksi.php";
    mysqli_set_charset($conn, "utf8mb4");

    // Pastikan tabel admin_chats ada secara otomatis jika belum diimpor
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `admin_chats` (
        `Id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `SenderUsername` VARCHAR(50) NOT NULL,
        `ReceiverUsername` VARCHAR(50) NOT NULL,
        `SenderRole` ENUM('mahasiswa', 'dosen', 'admin') NOT NULL,
        `Message` TEXT NOT NULL,
        `IsRead` TINYINT(1) NOT NULL DEFAULT 0,
        `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`Id`),
        KEY `idx_chat_users` (`SenderUsername`, `ReceiverUsername`, `CreatedAt`),
        KEY `idx_chat_receiver` (`ReceiverUsername`, `IsRead`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

    $username = $_SESSION["username"];
    $roleStatement = mysqli_prepare($conn, "SELECT `Role` FROM `login` WHERE `Username` = ? LIMIT 1");
    mysqli_stmt_bind_param($roleStatement, "s", $username);
    mysqli_stmt_execute($roleStatement);
    mysqli_stmt_bind_result($roleStatement, $userRole);
    $accountFound = mysqli_stmt_fetch($roleStatement);
    mysqli_stmt_close($roleStatement);

    if (!$accountFound) {
        chatRespond(401, false, "Akun tidak ditemukan.");
    }

    $action = trim($_GET["action"] ?? ($_POST["action"] ?? ""));

    // 1. MENDAPATKAN PESAN PERCAKAPAN
    if ($action === "get_messages") {
        if ($userRole === "admin") {
            $targetUser = trim($_GET["target_user"] ?? "");
            if ($targetUser === "") {
                chatRespond(400, false, "Target pengguna harus ditentukan.");
            }

            // Tandai pesan dari pengguna ke admin sebagai telah dibaca
            $markRead = mysqli_prepare($conn, "UPDATE `admin_chats` SET `IsRead` = 1 WHERE `SenderUsername` = ? AND `ReceiverUsername` = 'admin' AND `IsRead` = 0");
            mysqli_stmt_bind_param($markRead, "s", $targetUser);
            mysqli_stmt_execute($markRead);
            mysqli_stmt_close($markRead);

            $stmt = mysqli_prepare($conn, "SELECT `Id`, `SenderUsername`, `ReceiverUsername`, `SenderRole`, `Message`, `IsRead`, `CreatedAt`
                FROM `admin_chats`
                WHERE (`SenderUsername` = ? AND `ReceiverUsername` = 'admin')
                   OR (`SenderUsername` = 'admin' AND `ReceiverUsername` = ?)
                ORDER BY `CreatedAt` ASC, `Id` ASC");
            mysqli_stmt_bind_param($stmt, "ss", $targetUser, $targetUser);
        } else {
            // Mahasiswa atau Dosen chat dengan admin
            $markRead = mysqli_prepare($conn, "UPDATE `admin_chats` SET `IsRead` = 1 WHERE `SenderUsername` = 'admin' AND `ReceiverUsername` = ? AND `IsRead` = 0");
            mysqli_stmt_bind_param($markRead, "s", $username);
            mysqli_stmt_execute($markRead);
            mysqli_stmt_close($markRead);

            $stmt = mysqli_prepare($conn, "SELECT `Id`, `SenderUsername`, `ReceiverUsername`, `SenderRole`, `Message`, `IsRead`, `CreatedAt`
                FROM `admin_chats`
                WHERE (`SenderUsername` = ? AND `ReceiverUsername` = 'admin')
                   OR (`SenderUsername` = 'admin' AND `ReceiverUsername` = ?)
                ORDER BY `CreatedAt` ASC, `Id` ASC");
            mysqli_stmt_bind_param($stmt, "ss", $username, $username);
        }

        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $messages = mysqli_fetch_all($result, MYSQLI_ASSOC);
        mysqli_stmt_close($stmt);

        chatRespond(200, true, "Pesan berhasil dimuat.", ["messages" => $messages]);
    }

    // 2. MENDAPATKAN DAFTAR PERCAKAPAN (KHUSUS ADMIN)
    if ($action === "get_conversations") {
        if ($userRole !== "admin") {
            chatRespond(403, false, "Hanya admin yang dapat mengakses daftar percakapan.");
        }

        $query = "SELECT u.Username,
                         COALESCE(mp.FullName, dp.FullName, u.Username) AS DisplayName,
                         l.Role,
                         last_chat.Message AS LastMessage,
                         last_chat.CreatedAt AS LastMessageTime,
                         COALESCE(unread.UnreadCount, 0) AS UnreadCount
                  FROM (
                      SELECT DISTINCT IF(SenderUsername = 'admin', ReceiverUsername, SenderUsername) AS Username
                      FROM admin_chats
                      WHERE SenderUsername = 'admin' OR ReceiverUsername = 'admin'
                  ) AS u
                  INNER JOIN `login` AS l ON l.Username = u.Username
                  LEFT JOIN `mahasiswa_profiles` AS mp ON mp.Username = u.Username
                  LEFT JOIN `dosen_profiles` AS dp ON dp.Username = u.Username
                  LEFT JOIN (
                      SELECT IF(SenderUsername = 'admin', ReceiverUsername, SenderUsername) AS ChatUser,
                             Message, CreatedAt
                      FROM admin_chats
                      WHERE Id IN (
                          SELECT MAX(Id)
                          FROM admin_chats
                          WHERE SenderUsername = 'admin' OR ReceiverUsername = 'admin'
                          GROUP BY IF(SenderUsername = 'admin', ReceiverUsername, SenderUsername)
                      )
                  ) AS last_chat ON last_chat.ChatUser = u.Username
                  LEFT JOIN (
                      SELECT SenderUsername, COUNT(*) AS UnreadCount
                      FROM admin_chats
                      WHERE ReceiverUsername = 'admin' AND IsRead = 0
                      GROUP BY SenderUsername
                  ) AS unread ON unread.SenderUsername = u.Username
                  ORDER BY last_chat.CreatedAt DESC";

        $res = mysqli_query($conn, $query);
        $conversations = mysqli_fetch_all($res, MYSQLI_ASSOC);

        chatRespond(200, true, "Percakapan berhasil dimuat.", ["conversations" => $conversations]);
    }

    // 3. MENDAPATKAN JUMLAH PESAN BELUM TERBACA
    if ($action === "get_unread_count") {
        if ($userRole === "admin") {
            $countStmt = mysqli_query($conn, "SELECT COUNT(*) AS total FROM `admin_chats` WHERE `ReceiverUsername` = 'admin' AND `IsRead` = 0");
            $row = mysqli_fetch_assoc($countStmt);
            $unreadCount = (int) ($row["total"] ?? 0);
        } else {
            $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM `admin_chats` WHERE `SenderUsername` = 'admin' AND `ReceiverUsername` = ? AND `IsRead` = 0");
            mysqli_stmt_bind_param($stmt, "s", $username);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $row = mysqli_fetch_assoc($res);
            $unreadCount = (int) ($row["total"] ?? 0);
            mysqli_stmt_close($stmt);
        }

        chatRespond(200, true, "Jumlah belum terbaca berhasil dimuat.", ["unread_count" => $unreadCount]);
    }

    // 4. MENGIRIM PESAN
    if ($action === "send_message") {
        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            chatRespond(405, false, "Metode harus POST.");
        }

        $message = trim($_POST["message"] ?? "");
        if ($message === "") {
            chatRespond(400, false, "Pesan tidak boleh kosong.");
        }
        if (mb_strlen($message, "UTF-8") > 2000) {
            chatRespond(400, false, "Pesan maksimal 2000 karakter.");
        }

        if ($userRole === "admin") {
            $receiver = trim($_POST["receiver"] ?? "");
            if ($receiver === "") {
                chatRespond(400, false, "Penerima pesan harus ditentukan.");
            }
        } else {
            $receiver = "admin";
        }

        $insert = mysqli_prepare($conn, "INSERT INTO `admin_chats` (`SenderUsername`, `ReceiverUsername`, `SenderRole`, `Message`, `IsRead`) VALUES (?, ?, ?, ?, 0)");
        mysqli_stmt_bind_param($insert, "ssss", $username, $receiver, $userRole, $message);
        mysqli_stmt_execute($insert);
        $insertId = mysqli_insert_id($conn);
        mysqli_stmt_close($insert);

        chatRespond(200, true, "Pesan berhasil dikirim.", [
            "message_id" => $insertId,
            "created_at" => date("Y-m-d H:i:s"),
        ]);
    }

    chatRespond(400, false, "Aksi chat tidak dikenali.");
} catch (Throwable $error) {
    error_log("Admin chat error: " . $error->getMessage());
    chatRespond(500, false, "Terjadi kesalahan pada server chat: " . $error->getMessage());
}
