-- Campos para campeonatos simultáneos (género / categoría SUB) y restricciones de inscripción
ALTER TABLE tournaments
    ADD COLUMN IF NOT EXISTS genero_requerido CHAR(1) NULL DEFAULT NULL
        COMMENT 'M o F: restricción de sexo en inscripción' AFTER modalidad;

ALTER TABLE tournaments
    ADD COLUMN IF NOT EXISTS edad_maxima SMALLINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Edad máxima inclusive (12, 15, 18) para categorías SUB' AFTER genero_requerido;

ALTER TABLE tournaments
    ADD COLUMN IF NOT EXISTS campeonato_grupo VARCHAR(30) NULL DEFAULT NULL
        COMMENT 'Etiqueta del sub-torneo: MASCULINO, FEMENINO, SUB 12, etc.' AFTER edad_maxima;
