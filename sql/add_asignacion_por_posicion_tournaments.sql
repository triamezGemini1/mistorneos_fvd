-- Ronda 1 individual: emparejar por posición (ranking inicial) en lugar de dispersión por club.
-- Por defecto activo; desactivar para usar el algoritmo clásico (V1–V4 por club).

-- Ejecutar una vez. Si la columna ya existe, omitir el error o comentar esta línea.
ALTER TABLE tournaments
    ADD COLUMN asignacion_por_posicion TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Individual R1: 1=parejas por posición; 0=clásico por club'
        AFTER ranking;
