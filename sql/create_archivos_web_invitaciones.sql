-- Metadatos de vigencia para invitaciones FVD (portal público).
-- Visible hasta fecha_limite (día del evento inclusive); después queda inactivo automáticamente.

CREATE TABLE IF NOT EXISTS `archivos_web_invitaciones` (
  `id` int NOT NULL AUTO_INCREMENT,
  `archivo` varchar(255) NOT NULL COMMENT 'Nombre de archivo en upload/invitaciones_fvd/',
  `ruta_relativa` varchar(500) NOT NULL,
  `titulo` varchar(255) DEFAULT NULL,
  `fecha_limite` date NOT NULL COMMENT 'Último día visible (fecha del evento)',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_archivo` (`archivo`),
  KEY `idx_fecha_limite` (`fecha_limite`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
