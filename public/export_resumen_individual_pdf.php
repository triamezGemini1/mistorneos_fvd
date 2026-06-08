<?php
/**
 * PDF resumen individual. Uso: export_resumen_individual_pdf.php?torneo_id=1&inscrito_id=123
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/session_start_early.php';
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/auth_service.php';
AuthService::requireAuth();
require_once __DIR__ . '/../modules/tournament_admin/resumen_individual_export_pdf.php';
