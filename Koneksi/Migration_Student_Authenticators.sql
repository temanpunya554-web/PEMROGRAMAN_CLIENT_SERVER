CREATE TABLE IF NOT EXISTS `mahasiswa_authenticators` (
  `Id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `Username` VARCHAR(50) NOT NULL,
  `CredentialId` VARCHAR(1400) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `PublicKeyCose` TEXT CHARACTER SET ascii NOT NULL,
  `SignCount` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`Id`),
  UNIQUE KEY `uq_mahasiswa_authenticators_credential` (`CredentialId`),
  KEY `idx_mahasiswa_authenticators_username` (`Username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
