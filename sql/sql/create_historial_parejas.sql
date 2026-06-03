-- Tabla para historial de parejas (compañeros) por torneo y ronda
-- Regla: siempre guardar id_menor-id_mayor en jugador_1_id, jugador_2_id y llave
-- Permite consulta con una sola búsqueda: WHERE torneo_id = ? AND llave = '123-456'
CREATE TABLE IF NOT EXISTS historial_parejas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    torneo_id INT NOT NULL,
    ronda_id INT NOT NULL,
    mesa INT NOT NULL DEFAULT 0 COMMENT 'Número de mesa de la ronda',
    jugador_1_id INT NOT NULL COMMENT 'Siempre id menor',
    jugador_2_id INT NOT NULL COMMENT 'Siempre id mayor',
    llave VARCHAR(32) NOT NULL COMMENT 'id_menor-id_mayor, ej: 123-456',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_torneo_ronda_llave (torneo_id, ronda_id, llave),
    INDEX idx_torneo (torneo_id),
    INDEX idx_torneo_llave (torneo_id, llave),
    INDEX idx_torneo_ronda_mesa (torneo_id, ronda_id, mesa),
    INDEX idx_busqueda (jugador_1_id, jugador_2_id, torneo_id),
    CONSTRAINT fk_hp_torneo FOREIGN KEY (torneo_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    CONSTRAINT fk_hp_j1 FOREIGN KEY (jugador_1_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_hp_j2 FOREIGN KEY (jugador_2_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
