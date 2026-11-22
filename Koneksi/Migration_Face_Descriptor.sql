USE `siabak`;

-- Tambahkan kolom descriptor secara aman untuk MySQL/MariaDB yang belum memilikinya.
SET @face_descriptor_column_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'mahasiswa_face_enrollments'
    AND COLUMN_NAME = 'FaceDescriptor'
);
SET @face_descriptor_ddl = IF(
  @face_descriptor_column_exists = 0,
  'ALTER TABLE `mahasiswa_face_enrollments` ADD COLUMN `FaceDescriptor` LONGTEXT NULL AFTER `PhotoPath`',
  'SELECT ''Kolom FaceDescriptor sudah tersedia'''
);
PREPARE face_descriptor_stmt FROM @face_descriptor_ddl;
EXECUTE face_descriptor_stmt;
DEALLOCATE PREPARE face_descriptor_stmt;
