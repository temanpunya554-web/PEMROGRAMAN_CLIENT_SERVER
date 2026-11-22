USE `siabak`;

-- Menambahkan jenis kelamin pada profil Dosen agar bisa dihitung persentasenya di dashboard admin.
-- Aman dijalankan berulang kali.
ALTER TABLE `dosen_profiles`
  ADD COLUMN IF NOT EXISTS `Gender` VARCHAR(20) NOT NULL DEFAULT '' AFTER `Email`;

-- Salin jenis kelamin lama (jika ada) dari account_profiles untuk Dosen yang belum terisi.
UPDATE `dosen_profiles` AS dosen
INNER JOIN `account_profiles` AS account ON account.`Username` = dosen.`Username`
SET dosen.`Gender` = account.`Gender`
WHERE dosen.`Gender` = ''
  AND account.`Gender` IN ('Laki-laki', 'Perempuan', 'Lainnya');
