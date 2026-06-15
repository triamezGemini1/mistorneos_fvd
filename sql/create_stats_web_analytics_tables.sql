-- Estadísticas web locales (BD principal mistorneos_fvd)
-- Detalle diario fino + histórico mensual concentrado por URL

-- 1. DETALLE DIARIO (se vacía cada mes tras consolidar; retiene el día a día fino)
CREATE TABLE IF NOT EXISTS stats_detalle_diario (
    fecha DATE NOT NULL,
    ruta VARCHAR(255) NOT NULL,
    torneo_id INT NOT NULL,
    dispositivo ENUM('mobile', 'desktop') NOT NULL,
    pais CHAR(2) NOT NULL,
    vistas INT DEFAULT 0,
    visitantes_unicos INT DEFAULT 0,
    tiempo_promedio_seg INT DEFAULT 0,
    PRIMARY KEY (fecha, ruta, torneo_id, dispositivo, pais)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. HISTÓRICO MENSUAL POR URL (acumulado del mes por ruta)
CREATE TABLE IF NOT EXISTS stats_historico_mensual_url (
    ano_mes CHAR(7) NOT NULL,
    ruta VARCHAR(255) NOT NULL,
    torneo_id INT NOT NULL,
    total_vistas INT DEFAULT 0,
    total_visitantes INT DEFAULT 0,
    tiempo_medio_seg INT DEFAULT 0,
    PRIMARY KEY (ano_mes, ruta, torneo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
