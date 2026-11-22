USE `siabak`;

-- Menambahkan kolom lengkap pada tabel dosen_profiles agar setara dengan profil mahasiswa
-- Aman dijalankan berulang kali.
ALTER TABLE `dosen_profiles`
  ADD COLUMN IF NOT EXISTS `NIP` VARCHAR(50) NULL AFTER `Username`,
  ADD COLUMN IF NOT EXISTS `Gender` VARCHAR(20) NOT NULL DEFAULT '' AFTER `Email`,
  ADD COLUMN IF NOT EXISTS `Country` VARCHAR(100) NOT NULL DEFAULT 'Indonesia' AFTER `Gender`,
  ADD COLUMN IF NOT EXISTS `Province` VARCHAR(100) NOT NULL DEFAULT '' AFTER `Country`,
  ADD COLUMN IF NOT EXISTS `Regency` VARCHAR(100) NOT NULL DEFAULT '' AFTER `Province`,
  ADD COLUMN IF NOT EXISTS `District` VARCHAR(100) NOT NULL DEFAULT '' AFTER `Regency`,
  ADD COLUMN IF NOT EXISTS `Village` VARCHAR(100) NOT NULL DEFAULT '' AFTER `District`,
  ADD COLUMN IF NOT EXISTS `Region` VARCHAR(100) NOT NULL DEFAULT '' AFTER `Village`,
  ADD COLUMN IF NOT EXISTS `BirthDate` DATE NULL AFTER `Village`,
  ADD COLUMN IF NOT EXISTS `PhotoPath` VARCHAR(255) NULL AFTER `BirthDate`;

-- Sinkronisasi data alamat dan jenis kelamin lama dari account_profiles jika tersedia

