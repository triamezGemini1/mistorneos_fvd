<?php
// Proxy público para inscribir en sitio desde gestión de inscritos
require_once __DIR__ . '/../lib/FvdAdminGate.php';
FvdAdminGate::rejectPublicScriptIfDisabled('inscribir_sitio_save.php');
require_once __DIR__ . '/../modules/tournament_admin/inscribir_sitio_save.php';



