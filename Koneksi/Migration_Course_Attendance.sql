-- Jalankan sekali setelah memilih database siabak
USE `siabak`;

CREATE TABLE IF NOT EXISTS `course_attendance` (
  `Id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ScheduleId` INT UNSIGNED NOT NULL,
  `Username` VARCHAR(50) NOT NULL,
  `AttendanceDate` DATE NOT NULL,
  `Status` ENUM('Hadir','Izin','Sakit','Alpa') NOT NULL DEFAULT 'Alpa',
  `Notes` VARCHAR(255) NULL,
  `MarkedBy` VARCHAR(50) NOT NULL,
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`Id`),
  UNIQUE KEY `uq_course_attendance_session_student` (`ScheduleId`,`Username`,`AttendanceDate`),
  KEY `idx_course_attendance_student` (`Username`,`ScheduleId`,`AttendanceDate`),
  CONSTRAINT `fk_course_attendance_schedule` FOREIGN KEY (`ScheduleId`) REFERENCES `class_schedules` (`Id`) ON DELETE CASCADE,
  CONSTRAINT `fk_course_attendance_user` FOREIGN KEY (`Username`) REFERENCES `login` (`Username`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
