-- Imágenes de afiliación (opcional; el formulario también usa photo_path si existe).
ALTER TABLE `usuarios`
  ADD COLUMN IF NOT EXISTS `urlimgfoto` VARCHAR(500) NULL COMMENT 'Foto carnet FVD' AFTER `photo_path`,
  ADD COLUMN IF NOT EXISTS `urlimgcedula` VARCHAR(500) NULL COMMENT 'Imagen cédula FVD' AFTER `urlimgfoto`;
