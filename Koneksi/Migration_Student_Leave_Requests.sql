-- Jalankan setelah Setup_Database.sql dan Migration_Attendance_Schedule.sql.
USE `arb.wcite`;

CREATE TABLE IF NOT EXISTS `student_leave_requests` (
  `Id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `StudentUsername` VARCHAR(50) NOT NULL,
  `ScheduleId` INT UNSIGNED NOT NULL,
  `RequestDate` DATE NOT NULL,
  `Status` ENUM('Pending', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending',
  `Type` ENUM('Izin', 'Sakit') NOT NULL,
  `Note` VARCHAR(255) NULL,
  `Attachment` VARCHAR(255) NULL,
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`Id`),
  UNIQUE KEY `uq_student_leave_request` (`StudentUsername`, `ScheduleId`, `RequestDate`),
  KEY `idx_student_leave_status` (`Status`, `RequestDate`),
  CONSTRAINT `fk_student_leave_user` FOREIGN KEY (`StudentUsername`) REFERENCES `login` (`Username`) ON DELETE CASCADE,
  CONSTRAINT `fk_student_leave_schedule` FOREIGN KEY (`ScheduleId`) REFERENCES `class_schedules` (`Id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
