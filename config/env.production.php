<?php
/**
 * ============================================
 * CONFIGURACIÓN DE PRODUCCIÓN
 * La Estación del Dominó - MisTorneos
 * ============================================
 * 
 * INSTRUCCIONES:
 * 1. Copiar este archivo a la raíz como .env (sin .php)
 * 2. O usar directamente con: require 'config/env.production.php';
 */

return [
    // ============================================
    // ENTORNO
    // ============================================
    'APP_ENV' => 'production',
    'APP_DEBUG' => false,
    'APP_URL' => 'https://laestaciondeldominohoy.com/mistorneos_fvd',
    'BASE_PATH' => '/mistorneos_fvd/public/',
    
    // ============================================
    // BASE DE DATOS PRINCIPAL - MISTORNEOS
    // Contiene: Torneos, usuarios, inscripciones, resultados
    // ============================================
    'DB_HOST' => 'localhost',
    'DB_PORT' => '3306',
    'DB_DATABASE' => 'mistorneos_fvd',
    'DB_USERNAME' => 'TU_USUARIO_AQUI',      // ⚠️ CAMBIAR
    'DB_PASSWORD' => 'TU_PASSWORD_AQUI',     // ⚠️ CAMBIAR
    'DB_CHARSET' => 'utf8mb4',
    
    // ============================================
    // BASE DE DATOS SECUNDARIA - FVDADMIN
    // Contiene: Datos de apoyo para búsquedas
    // ============================================
    'DB_SECONDARY_HOST' => 'localhost',
    'DB_SECONDARY_PORT' => '3306',
    'DB_SECONDARY_DATABASE' => 'fvdadmin',
    'DB_SECONDARY_USERNAME' => 'TU_USUARIO_AQUI',  // ⚠️ CAMBIAR
    'DB_SECONDARY_PASSWORD' => 'TU_PASSWORD_AQUI', // ⚠️ CAMBIAR
    'DB_SECONDARY_CHARSET' => 'utf8mb4',
    
    // ============================================
    // CONFIGURACIÓN DE CORREO (SMTP)
    // ============================================
    'MAIL_MAILER' => 'smtp',
    'MAIL_HOST' => 'smtp.tuproveedor.com',
    'MAIL_PORT' => '587',
    'MAIL_USERNAME' => 'correo@laestaciondeldominohoy.com',
    'MAIL_PASSWORD' => 'TU_PASSWORD_CORREO',
    'MAIL_ENCRYPTION' => 'tls',
    'MAIL_FROM_ADDRESS' => 'noreply@laestaciondeldominohoy.com',
    'MAIL_FROM_NAME' => 'La Estación del Dominó',
    
    // ============================================
    // SEGURIDAD
    // ============================================
    'APP_KEY' => 'cambiar_esta_clave_por_una_segura_de_64_caracteres',
    'SESSION_LIFETIME' => 120,
    'CSRF_TOKEN_LIFETIME' => 3600,
    
    // ============================================
    // LOGS Y MONITOREO
    // ============================================
    'LOG_LEVEL' => 'error',
    'LOG_PATH' => 'logs/app.log',

    // ============================================
    // ANALÍTICA WEB — UMAMI CLOUD
    // ============================================
    'UMAMI_API_KEY' => '',
    'UMAMI_SHARE_URL' => '',
    'UMAMI_SCRIPT_URL' => 'https://cloud.umami.is/script.js',
    'UMAMI_WEBSITE_ID' => '1e64a2c9-7c79-49f0-b6a2-6d8c761893d3',
];











