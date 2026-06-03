-- NUMFVD: valor por defecto 0 en usuarios e inscritos (evita error "no tiene valor por defecto" al insertar).
-- Ejecutar una vez en producción si la columna existe sin DEFAULT.

-- usuarios
SET @db = DATABASE();
SET @has_u = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'numfvd');
SET @sql_u = IF(@has_u > 0,
    'ALTER TABLE `usuarios` MODIFY COLUMN `numfvd` int UNSIGNED NOT NULL DEFAULT 0 COMMENT ''NUMFVD FVD (atletas)''',
    'SELECT 1');
PREPARE st_u FROM @sql_u;
EXECUTE st_u;
DEALLOCATE PREPARE st_u;

-- inscritos
SET @has_i = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'inscritos' AND COLUMN_NAME = 'numfvd');
SET @sql_i = IF(@has_i > 0,
    'ALTER TABLE `inscritos` MODIFY COLUMN `numfvd` int UNSIGNED NOT NULL DEFAULT 0 COMMENT ''NUMFVD en el torneo''',
    'SELECT 1');
PREPARE st_i FROM @sql_i;
EXECUTE st_i;
DEALLOCATE PREPARE st_i;

-- Rellenar usuarios desde atletas (misma cédula)
UPDATE usuarios u
INNER JOIN atletas a ON REPLACE(REPLACE(REPLACE(TRIM(CAST(a.cedula AS CHAR)), '-', ''), '.', ''), ' ', '')
    = REPLACE(REPLACE(REPLACE(TRIM(CAST(u.cedula AS CHAR)), '-', ''), '.', ''), ' ', '')
SET u.numfvd = a.numfvd
WHERE (u.numfvd IS NULL OR u.numfvd = 0) AND a.numfvd > 0;

-- Copiar a inscritos desde usuarios
UPDATE inscritos i
INNER JOIN usuarios u ON u.id = i.id_usuario
SET i.numfvd = u.numfvd
WHERE (i.numfvd IS NULL OR i.numfvd = 0) AND u.numfvd > 0;
