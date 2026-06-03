-- Número de mesa en historial_parejas (acceso directo en reportes de parejas repetidas).
-- Ejecutar una sola vez en producción si la tabla ya existe.

ALTER TABLE historial_parejas
    ADD COLUMN mesa INT NOT NULL DEFAULT 0 COMMENT 'Número de mesa de la ronda' AFTER ronda_id;

CREATE INDEX idx_hp_torneo_ronda_mesa ON historial_parejas (torneo_id, ronda_id, mesa);

-- Opcional: rellenar mesa en filas antiguas → php scripts/backfill_historial_parejas.php
