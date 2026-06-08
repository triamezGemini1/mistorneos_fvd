-- Banca en equipos: 1 = titular (asignación de mesas), 0 = banca (estadísticas, sin mesas).
ALTER TABLE `inscritos`
  ADD COLUMN `activo_mesa` TINYINT(1) NOT NULL DEFAULT 1
  COMMENT '1=titular mesas, 0=banca/suplente' AFTER `codigo_equipo`;

CREATE TABLE IF NOT EXISTS `equipo_sustituciones` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `torneo_id` INT UNSIGNED NOT NULL,
  `codigo_equipo` VARCHAR(32) NOT NULL,
  `id_usuario_sale` INT UNSIGNED NOT NULL,
  `id_usuario_entra` INT UNSIGNED NOT NULL,
  `registrado_por` INT UNSIGNED NULL,
  `observacion` VARCHAR(500) NULL,
  `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_es_torneo_equipo` (`torneo_id`, `codigo_equipo`),
  KEY `idx_es_torneo_fecha` (`torneo_id`, `creado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Historial de sustituciones titular/banca en torneos por equipos';
