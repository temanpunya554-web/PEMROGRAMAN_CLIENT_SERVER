USE `siabak`;

-- Menambahkan kolom geofencing pada tabel class_rooms
-- Koordinat default kampus (-6.2000000, 106.8166667) dengan radius 100 meter
ALTER TABLE `class_rooms`
  ADD COLUMN IF NOT EXISTS `Latitude` DECIMAL(10,7) NULL DEFAULT -6.2000000,
  ADD COLUMN IF NOT EXISTS `Longitude` DECIMAL(10,7) NULL DEFAULT 106.8166667,
  ADD COLUMN IF NOT EXISTS `RadiusMeters` INT UNSIGNED NOT NULL DEFAULT 100;

-- Menambahkan kolom pencatatan koordinat GPS mahasiswa dan jarak pada class_attendance
ALTER TABLE `class_attendance`
  ADD COLUMN IF NOT EXISTS `UserLatitude` DECIMAL(10,7) NULL AFTER `FaceVerified`,
  ADD COLUMN IF NOT EXISTS `UserLongitude` DECIMAL(10,7) NULL AFTER `UserLatitude`,
  ADD COLUMN IF NOT EXISTS `DistanceMeters` INT NULL AFTER `UserLongitude`;

-- Berikan nilai default pada ruangan yang belum memiliki koordinat
UPDATE `class_rooms`
SET `Latitude` = -6.2000000,
    `Longitude` = 106.8166667,
    `RadiusMeters` = 100
WHERE `Latitude` IS NULL;
