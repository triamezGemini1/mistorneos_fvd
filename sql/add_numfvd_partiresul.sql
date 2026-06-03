-- Columna numfvd en partiresul (clave pública del atleta en el torneo).
-- Ejecutar una vez en cada entorno antes de desplegar el código que la usa.

-- Si la columna ya existe, omitir esta línea o usar scripts/migrate_partiresul_numfvd.php
ALTER TABLE `partiresul`
  ADD COLUMN `numfvd` int UNSIGNED DEFAULT NULL
    COMMENT 'NUMFVD del inscrito en el torneo (clave pública; ver inscritos.numfvd)'
  AFTER `id_usuario`;

-- Rellenar desde inscritos (mismo torneo + id_usuario interno)
UPDATE partiresul pr
INNER JOIN inscritos i
  ON i.torneo_id = pr.id_torneo AND i.id_usuario = pr.id_usuario
SET pr.numfvd = NULLIF(i.numfvd, 0)
WHERE pr.numfvd IS NULL OR pr.numfvd = 0;

-- Filas legadas donde id_usuario guardaba el NUMFVD (club 7 u otros)
UPDATE partiresul pr
INNER JOIN inscritos i
  ON i.torneo_id = pr.id_torneo AND i.numfvd = pr.id_usuario
SET pr.numfvd = i.numfvd,
    pr.id_usuario = i.id_usuario
WHERE (pr.numfvd IS NULL OR pr.numfvd = 0)
  AND i.numfvd > 0
  AND pr.id_usuario = i.numfvd
  AND pr.id_usuario <> i.id_usuario;

-- Respaldo: copiar id_usuario si sigue vacío
UPDATE partiresul
SET numfvd = id_usuario
WHERE numfvd IS NULL OR numfvd = 0;

ALTER TABLE `partiresul`
  ADD KEY `idx_partiresul_torneo_numfvd_partida` (`id_torneo`, `numfvd`, `partida`);
