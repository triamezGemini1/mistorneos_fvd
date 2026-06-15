-- Reportes de pago por donación para habilitar reportes personales (PDF ranking).
-- El administrador verifica y confirma; al confirmar se activa permite_reportes_personales.

CREATE TABLE IF NOT EXISTS reportes_pago_donacion (
  id INT NOT NULL AUTO_INCREMENT,
  id_usuario INT DEFAULT NULL COMMENT 'Usuario que reporta (sesión) o resuelto por admin',
  numfvd_reportado INT DEFAULT NULL COMMENT 'NUMFVD indicado en el reporte',
  id_usuario_resuelto INT DEFAULT NULL COMMENT 'Usuario FVD vinculado tras verificación',
  fecha DATE NOT NULL COMMENT 'Fecha del pago',
  hora TIME NOT NULL COMMENT 'Hora del pago',
  tipo_pago ENUM('transferencia', 'pagomovil', 'efectivo', 'zelle', 'otro') NOT NULL DEFAULT 'pagomovil',
  banco VARCHAR(100) DEFAULT NULL,
  monto DECIMAL(10,2) NOT NULL,
  referencia VARCHAR(100) DEFAULT NULL,
  comentarios TEXT DEFAULT NULL,
  estatus ENUM('pendiente', 'confirmado', 'rechazado') NOT NULL DEFAULT 'pendiente',
  activado_en TIMESTAMP NULL DEFAULT NULL COMMENT 'Momento en que se habilitó reportes personales',
  activado_por INT DEFAULT NULL COMMENT 'Admin que confirmó',
  notas_admin TEXT DEFAULT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rpd_usuario (id_usuario),
  KEY idx_rpd_numfvd (numfvd_reportado),
  KEY idx_rpd_resuelto (id_usuario_resuelto),
  KEY idx_rpd_estatus (estatus),
  KEY idx_rpd_fecha (fecha),
  KEY idx_rpd_referencia (referencia),
  CONSTRAINT fk_rpd_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_rpd_resuelto FOREIGN KEY (id_usuario_resuelto) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_rpd_activado_por FOREIGN KEY (activado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Notificaciones de pago/donación para activar reportes personales';
