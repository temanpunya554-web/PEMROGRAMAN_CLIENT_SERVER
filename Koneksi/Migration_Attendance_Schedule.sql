-- Menghubungkan absensi mahasiswa dengan mata kuliah (class_schedules).
-- Jalankan sekali di phpMyAdmin pada database `siabak`. Aman diulang (XAMPP/MariaDB).
USE `siabak`;

ALTER TABLE `class_attendance`
  ADD COLUMN IF NOT EXISTS `ScheduleId` INT UNSIGNED NULL AFTER `RoomName`;

ALTER TABLE `class_attendance`
  ADD INDEX IF NOT EXISTS `idx_class_attendance_schedule` (`ScheduleId`, `Username`, `VerifiedAt`);

-- ---------------------------------------------------------------------------
-- PENGECEKAN (jalankan terpisah, bukan bagian dari migrasi):
-- Daftar ruangan di jadwal yang namanya TIDAK ADA di class_rooms. Hasilnya harus kosong,
-- kalau tidak, kelas di ruangan itu tidak akan pernah terdeteksi saat mahasiswa absen.
--
-- SELECT DISTINCT s.RoomName
-- FROM class_schedules s
-- LEFT JOIN class_rooms r ON TRIM(r.RoomName) = TRIM(s.RoomName)
-- WHERE r.RoomName IS NULL;
--
-- Jadwal yang belum punya jam (tidak bisa dipakai absensi):
-- SELECT Id, DayName, CourseName FROM class_schedules WHERE StartTime IS NULL OR EndTime IS NULL;
