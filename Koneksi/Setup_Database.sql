CREATE DATABASE IF NOT EXISTS `arb.wcite`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;

USE `arb.wcite`;

DROP TABLE IF EXISTS `community_announcements`;
DROP TABLE IF EXISTS `community_events`;

CREATE TABLE IF NOT EXISTS `login` (
  `Username` VARCHAR(50) NOT NULL,
  `Password` VARCHAR(255) NOT NULL,
  `Role` ENUM('admin', 'dosen', 'mahasiswa') NOT NULL DEFAULT 'mahasiswa',
  PRIMARY KEY (`Username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `login`
  MODIFY COLUMN `Role` ENUM('admin', 'dosen', 'mahasiswa', 'user') NOT NULL DEFAULT 'mahasiswa';

UPDATE `login` SET `Role` = 'mahasiswa' WHERE `Role` = 'user';

ALTER TABLE `login`
  MODIFY COLUMN `Role` ENUM('admin', 'dosen', 'mahasiswa') NOT NULL DEFAULT 'mahasiswa';

CREATE TABLE IF NOT EXISTS `account_profiles` (
  `Username` VARCHAR(50) NOT NULL,
  `FullName` VARCHAR(100) NOT NULL,
  `Email` VARCHAR(254) NOT NULL,
  `Region` VARCHAR(100) NOT NULL DEFAULT '',
  `Country` VARCHAR(100) NOT NULL DEFAULT '',
  `Province` VARCHAR(100) NOT NULL DEFAULT '',
  `Regency` VARCHAR(100) NOT NULL DEFAULT '',
  `District` VARCHAR(100) NOT NULL DEFAULT '',
  `Village` VARCHAR(100) NOT NULL DEFAULT '',
  `Gender` VARCHAR(20) NOT NULL DEFAULT '',
  `PublicDirectory` TINYINT(1) NOT NULL DEFAULT 0,
  `Latitude` DECIMAL(10,7) NULL,
  `Longitude` DECIMAL(10,7) NULL,
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`Username`),
  UNIQUE KEY `uq_account_profiles_email` (`Email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `account_profiles`
  ADD COLUMN IF NOT EXISTS `Region` VARCHAR(100) NOT NULL DEFAULT '' AFTER `Email`;

ALTER TABLE `account_profiles`
  ADD COLUMN IF NOT EXISTS `Country` VARCHAR(100) NOT NULL DEFAULT '' AFTER `Region`;

ALTER TABLE `account_profiles`
  ADD COLUMN IF NOT EXISTS `Province` VARCHAR(100) NOT NULL DEFAULT '' AFTER `Country`,
  ADD COLUMN IF NOT EXISTS `Regency` VARCHAR(100) NOT NULL DEFAULT '' AFTER `Province`,
  ADD COLUMN IF NOT EXISTS `District` VARCHAR(100) NOT NULL DEFAULT '' AFTER `Regency`,
  ADD COLUMN IF NOT EXISTS `Village` VARCHAR(100) NOT NULL DEFAULT '' AFTER `District`,
  ADD COLUMN IF NOT EXISTS `Gender` VARCHAR(20) NOT NULL DEFAULT '' AFTER `Village`,
  ADD COLUMN IF NOT EXISTS `PublicDirectory` TINYINT(1) NOT NULL DEFAULT 0 AFTER `Gender`;

ALTER TABLE `account_profiles`
  ADD COLUMN IF NOT EXISTS `NIK` VARCHAR(16) NULL AFTER `Username`,
  ADD COLUMN IF NOT EXISTS `NIM` VARCHAR(50) NULL AFTER `NIK`,
  ADD COLUMN IF NOT EXISTS `BirthDate` DATE NULL AFTER `NIM`,
  ADD COLUMN IF NOT EXISTS `PhotoPath` VARCHAR(255) NULL AFTER `BirthDate`;


CREATE TABLE IF NOT EXISTS `class_rooms` (
  `Id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `RoomName` VARCHAR(100) NOT NULL,
  `RoomStatus` ENUM('open', 'locked') NOT NULL DEFAULT 'open',
  `UpdatedBy` VARCHAR(50) NULL,
  `UpdatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`Id`),
  UNIQUE KEY `uq_class_rooms_name` (`RoomName`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `class_rooms` (`RoomName`, `RoomStatus`) VALUES
  ('RK 3.16', 'open'),
  ('Lab Komputer 1', 'open'),
  ('Lab Komputer 2', 'open'),
  ('Lab Unprotek', 'open'),
  ('Lab Teknik Kendali', 'open');

CREATE TABLE IF NOT EXISTS `class_attendance` (
  `Id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `Username` VARCHAR(50) NOT NULL,
  `RoomName` VARCHAR(100) NOT NULL,
  `FingerprintVerified` TINYINT(1) NOT NULL DEFAULT 0,
  `FaceVerified` TINYINT(1) NOT NULL DEFAULT 0,
  `VerifiedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`Id`),
  KEY `idx_class_attendance_room` (`RoomName`, `VerifiedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `mahasiswa_face_enrollments` (
  `Username` VARCHAR(50) NOT NULL,
  `PhotoPath` VARCHAR(255) NOT NULL,
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`Username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `class_schedules` (
  `Id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `DayName` ENUM('Senin','Selasa','Rabu','Kamis','Sabtu') NOT NULL,
  `CourseName` VARCHAR(150) NOT NULL,
  `StartTime` TIME NULL,
  `EndTime` TIME NULL,
  `RoomName` VARCHAR(100) NOT NULL,
  `ScheduleType` ENUM('Teori','Praktikum') NOT NULL DEFAULT 'Teori',
  PRIMARY KEY (`Id`),
  KEY `idx_class_schedules_day` (`DayName`, `StartTime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `class_schedules`
  (`DayName`, `CourseName`, `StartTime`, `EndTime`, `RoomName`, `ScheduleType`) VALUES
  ('Senin', 'Sistem Operasi', '13:00:00', '14:40:00', 'RK 2.11', 'Teori'),
  ('Senin', 'Jaringan Komputasi', '14:40:00', '17:10:00', 'Lab Komputer 1', 'Teori'),
  ('Senin', 'Persamaan Diferensial', '08:00:00', '10:30:00', 'Lab Kendali', 'Teori'),
  ('Selasa', 'Fisika 2', '15:30:00', '18:00:00', 'UKS Lama', 'Teori'),
  ('Rabu', 'Sirkuit dan Elektronika', '13:00:00', '15:30:00', 'Lab Unit Produksi', 'Teori'),
  ('Rabu', 'Desain Digital dan Logika', '15:30:00', '17:10:00', 'Lab Kendali', 'Teori'),
  ('Kamis', 'Pemrograman Client/Server', '13:00:00', '15:30:00', 'Lab Komputer 1', 'Teori'),
  ('Sabtu', 'Praktikum Sirkuit dan Elektronika', NULL, NULL, 'Belum ditentukan', 'Praktikum'),
  ('Sabtu', 'Praktikum Pemrograman Client/Server', NULL, NULL, 'Belum ditentukan', 'Praktikum'),
  ('Sabtu', 'Praktikum Desain Digital dan Logika', NULL, NULL, 'Belum ditentukan', 'Praktikum');
