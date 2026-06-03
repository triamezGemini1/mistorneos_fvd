-- Corrige identificación asociación código 6 (debe ser ANZOATEGUI, no BARINAS).
-- Barinas corresponde al código 20 en el catálogo FVD.
-- Ejecutar una vez en producción y luego: php scripts/reparar_nombres_asociaciones_fvd.php

UPDATE entidad
SET nombre = 'ANZOATEGUI'
WHERE id = 6
  AND (
    nombre IS NULL
    OR TRIM(nombre) = ''
    OR UPPER(TRIM(nombre)) LIKE '%BARINAS%'
    OR UPPER(TRIM(nombre)) NOT LIKE '%ANZO%'
  );

UPDATE entidad
SET nombre = 'BARINAS'
WHERE id = 20
  AND (
    nombre IS NULL
    OR TRIM(nombre) = ''
    OR UPPER(TRIM(nombre)) LIKE '%ANZO%'
  );

UPDATE clubes
SET nombre = 'ANZOATEGUI', entidad = 6
WHERE id = 6;

UPDATE clubes
SET nombre = 'BARINAS', entidad = 20
WHERE id = 20;
