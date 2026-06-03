<?php
// Módulo de Solicitudes de Afiliación
// Este archivo maneja tanto la vista como las acciones POST

require_once __DIR__ . '/../lib/FvdAdminGate.php';

FvdAdminGate::rejectPageIfDisabled('affiliate_requests');

require_once __DIR__ . '/affiliate_requests/list.php';


