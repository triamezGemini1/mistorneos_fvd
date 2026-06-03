-- Copia atletas.categ -> usuarios.posi_rnk emparejando por cédula (solo dígitos).
-- Requiere columna usuarios.posi_rnk. Ejecutar en cada entorno antes del UPDATE.

UPDATE usuarios u
INNER JOIN atletas a
  ON REPLACE(REPLACE(REPLACE(TRIM(u.cedula), '.', ''), '-', ''), ' ', '')
   = REPLACE(REPLACE(REPLACE(TRIM(a.cedula), '.', ''), '-', ''), ' ', '')
SET u.posi_rnk = a.categ
WHERE REPLACE(REPLACE(REPLACE(TRIM(u.cedula), '.', ''), '-', ''), ' ', '') <> '';
