<?php
require_once __DIR__ . '/../config/session_start_early.php';
/**
 * Página independiente del Administrador de Torneos
 * Patrón en bloque: conexión única → seguridad → validación inmediata → interfaz (layout incluye header).
 */
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/auth_service.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/TournamentAdminAccess.php';
require_once __DIR__ . '/../lib/FvdInstitutionalScope.php';
AuthService::requireAuth();
$user = Auth::user();

FvdInstitutionalScope::rejectStandaloneOperationalEntry();
TournamentAdminAccess::requireTorneoPanelAccess();

// Obtener acción
$action = $_GET['action'] ?? 'index';
$torneo_id = isset($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : null;

// Incluir el módulo de gestión de torneos
require_once __DIR__ . '/../modules/torneo_gestion.php';

