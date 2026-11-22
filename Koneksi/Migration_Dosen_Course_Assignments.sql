-- Jalankan sekali setelah memilih database siabak.
-- Setelah tabel dibuat, admin perlu mengisi penugasan dosen untuk setiap jadwal.
USE `siabak`;

CREATE TABLE IF NOT EXISTS `dosen_course_assignments` (
  `Id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `DosenUsername` VARCHAR(50) NOT NULL,
  `ScheduleId` INT UNSIGNED NOT NULL,
  `AssignedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`Id`),
  UNIQUE KEY `uq_dosen_schedule` (`DosenUsername`, `ScheduleId`),
  KEY `idx_dosen_course_schedule` (`ScheduleId`),
  CONSTRAINT `fk_dosen_course_user` FOREIGN KEY (`DosenUsername`) REFERENCES `login` (`Username`) ON DELETE CASCADE,
  CONSTRAINT `fk_dosen_course_schedule` FOREIGN KEY (`ScheduleId`) REFERENCES `class_schedules` (`Id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Contoh penugasan (ganti username dan ScheduleId sesuai data kampus):
-- INSERT INTO `dosen_course_assignments` (`DosenUsername`, `ScheduleId`) VALUES ('username_dosen', 1);
