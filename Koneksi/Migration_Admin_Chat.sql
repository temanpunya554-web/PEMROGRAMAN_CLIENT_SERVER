USE `arb.wcite`;

-- Tabel penyimpanan pesan percakapan antara mahasiswa/dosen dengan admin (operator)
CREATE TABLE IF NOT EXISTS `admin_chats` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
