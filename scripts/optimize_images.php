<?php
/**
 * Script CLI para optimizar imágenes existentes
 * 
 * Uso:
 * php scripts/optimize_images.php [directorio] [--recursive] [--quality=85] [--create-webp]
 * 
 * Ejemplos:
 * php scripts/optimize_images.php upload/tournaments --recursive --create-webp
 * php scripts/optimize_images.php upload/logos --quality=90
 */

// Cargar solo lo necesario sin bootstrap completo (evita conflicto con Log.php)
define('APP_BOOTSTRAPPED', true);

// Cargar configuración básica
require_once __DIR__ . '/../lib/Env.php';
Env::load(__DIR__ . '/../.env');

require_once __DIR__ . '/../config/environment.php';
$GLOBALS['APP_CONFIG'] = Environment::getConfig();

// Cargar helpers necesarios
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/ImageOptimizer.php';

// Parsear argumentos de línea de comandos
$options = [
    'quality' => 85,
    'png_quality' => 9,
    'max_width' => 1920,
    'max_height' => 1080,
    'create_webp' => true,
    'webp_quality' => 80
];

$recursive = false;
$directory = null;

foreach ($argv as $arg) {
    if ($arg === '--recursive') {
        $recursive = true;
    } elseif (strpos($arg, '--quality=') === 0) {
        $options['quality'] = (int)substr($arg, 10);
    } elseif (strpos($arg, '--webp-quality=') === 0) {
        $options['webp_quality'] = (int)substr($arg, 16);
    } elseif (strpos($arg, '--max-width=') === 0) {
        $options['max_width'] = (int)substr($arg, 12);
    } elseif (strpos($arg, '--max-height=') === 0) {
        $options['max_height'] = (int)substr($arg, 13);
    } elseif ($arg === '--no-webp') {
        $options['create_webp'] = false;
    } elseif ($arg !== $argv[0] && !strpos($arg, '--')) {
        $directory = $arg;
    }
}

// Si no se especifica directorio, usar uploads por defecto
if (!$directory) {
    $directory = __DIR__ . '/../upload';
}

// Verificar que el directorio existe
if (!is_dir($directory)) {
    echo "❌ Error: El directorio '$directory' no existe.\n";
    exit(1);
}

echo "🚀 Iniciando optimización de imágenes...\n";
echo "📁 Directorio: $directory\n";
echo "🔄 Recursivo: " . ($recursive ? 'Sí' : 'No') . "\n";
echo "⚙️  Calidad JPEG: {$options['quality']}\n";
echo "⚙️  Calidad WebP: {$options['webp_quality']}\n";
echo "⚙️  Crear WebP: " . ($options['create_webp'] ? 'Sí' : 'No') . "\n";
echo "📏 Tamaño máximo: {$options['max_width']}x{$options['max_height']}\n";
echo "\n";

// Optimizar
$start_time = microtime(true);
$result = ImageOptimizer::optimizeDirectory($directory, $options, $recursive);
$end_time = microtime(true);
$duration = round($end_time - $start_time, 2);

if (!$result['success']) {
    echo "❌ Error: {$result['error']}\n";
    exit(1);
}

// Mostrar resultados
echo "\n";
echo "✅ Optimización completada!\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "📊 Estadísticas:\n";
echo "   ?? Archivos procesados: {$result['processed']}\n";
echo "   ?? Archivos optimizados: {$result['optimized']}\n";
echo "   ?? Archivos fallidos: {$result['failed']}\n";
echo "   ?? Espacio ahorrado: {$result['total_savings_mb']} MB\n";
echo "   ?? Tiempo transcurrido: {$duration} segundos\n";
echo "\n";

if (!empty($result['files'])) {
    echo "📝 Detalles por archivo:\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    
    foreach ($result['files'] as $file) {
        $original_mb = round($file['original_size'] / 1024 / 1024, 2);
        $optimized_mb = round($file['optimized_size'] / 1024 / 1024, 2);
        $savings_mb = round($file['savings'] / 1024 / 1024, 2);
        
        echo "📄 " . basename($file['file']) . "\n";
        echo "   Original: {$original_mb} MB → Optimizado: {$optimized_mb} MB\n";
        echo "   Ahorro: {$savings_mb} MB ({$file['savings_percent']}%)\n";
        if ($file['webp_created']) {
            echo "   ✅ Versión WebP creada\n";
        }
        echo "\n";
    }
}

echo "✨ ¡Proceso finalizado exitosamente!\n";

