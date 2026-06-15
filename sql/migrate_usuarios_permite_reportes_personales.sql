-- Permite habilitar por usuario la descarga de reportes PDF personales (ranking e historial).
-- Ejecutar una vez en cada entorno.

ALTER TABLE usuarios
    ADD COLUMN permite_reportes_personales TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = puede ver botón y descargar PDF solo de su propia información'
        AFTER status;
