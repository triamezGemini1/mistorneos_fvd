<?php
/**
 * Script para migrar y generar slugs para torneos existentes
 * 
 * Uso:
 * php scripts/migrate_tournament_slugs.php
 * 
 * NOTA: Este script es completamente independiente y evita cargar Log.php
 */

// Función helper para generar slug (sin dependencias)
function slugify_tournament(string $text): string {
    // Convertir a minúsculas
    $text = mb_strtolower($text, 'UTF-8');
    
    // Reemplazar caracteres especiales
    $text = str_replace(
        ['á', 'é', 'í', 'ó', 'ú', 'ñ', 'ü'],
        ['a', 'e', 'i', 'o', 'u', 'n', 'u'],
        $text
    );
    
    // Remover caracteres especiales y espacios
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    
    // Remover guiones al inicio y final
    $text = trim($text, '-');
    
    // Limitar longitud
    if (strlen($text) > 100) {
        $text = substr($text, 0, 100);
        $text = rtrim($text, '-');
    }
    
    return $text;
}

// Cargar configuración de base de datos directamente (sin bootstrap)
$config_file = __DIR__ . '/../config/config.php';
if (file_exists($config_file)) {
    $config = require $config_file;
} else {
    // Configuración por defecto
    $config = [
        'db' => [
            'host' => 'localhost',
            'port' => 3306,
            'name' => 'mistorneos_fvd',
            'user' => 'root',
            'pass' => '',
            'charset' => 'utf8mb4'
        ]
    ];
}

// Conectar a la base de datos directamente
try {
    $db_config = $config['db'];
    $dsn = "mysql:host={$db_config['host']};port={$db_config['port']};dbname={$db_config['name']};charset={$db_config['charset']}";
    $pdo = new PDO($dsn, $db_config['user'], $db_config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException $e) {
    echo "❌ Error de conexión a la base de datos: " . $e->getMessage() . "\n";
    exit(1);
}

echo "🚀 Iniciando migración de slugs para torneos...\n\n";

try {
    // Verificar si existe la columna slug
    $stmt = $pdo->query("SHOW COLUMNS FROM tournaments LIKE 'slug'");
    $slug_column_exists = $stmt->rowCount() > 0;
    
    if (!$slug_column_exists) {
        echo "📝 Agregando columna 'slug' a la tabla tournaments...\n";
        try {
            $pdo->exec("
                ALTER TABLE `tournaments` 
                ADD COLUMN `slug` VARCHAR(150) NULL AFTER `nombre`,
                ADD INDEX `idx_slug` (`slug`)
            ");
            echo "✅ Columna 'slug' agregada exitosamente.\n\n";
        } catch (PDOException $e) {
            // Si la columna ya existe o hay otro error
            if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
                echo "ℹ️  La columna 'slug' ya existe.\n\n";
            } else {
                throw $e;
            }
        }
    } else {
        echo "✅ La columna 'slug' ya existe.\n\n";
    }
    
    // Obtener todos los torneos sin slug
    $stmt = $pdo->query("SELECT id, nombre FROM tournaments WHERE slug IS NULL OR slug = ''");
    $torneos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($torneos)) {
        echo "✨ Todos los torneos ya tienen slug asignado.\n";
        exit(0);
    }
    
    echo "📊 Encontrados " . count($torneos) . " torneos sin slug.\n";
    echo "🔄 Generando slugs...\n\n";
    
    $updated = 0;
    $failed = 0;
    
    foreach ($torneos as $torneo) {
        $slug = slugify_tournament($torneo['nombre']);
        
        // Verificar que el slug no esté duplicado
        $check_stmt = $pdo->prepare("SELECT id FROM tournaments WHERE slug = ? AND id != ?");
        $check_stmt->execute([$slug, $torneo['id']]);
        
        if ($check_stmt->rowCount() > 0) {
            // Si hay duplicado, agregar ID al final
            $slug = $slug . '-' . $torneo['id'];
        }
        
        // Actualizar el torneo
        $update_stmt = $pdo->prepare("UPDATE tournaments SET slug = ? WHERE id = ?");
        if ($update_stmt->execute([$slug, $torneo['id']])) {
            $updated++;
            echo "  ✅ Torneo #{$torneo['id']}: '{$torneo['nombre']}' → slug: '{$slug}'\n";
        } else {
            $failed++;
            echo "  ❌ Error actualizando torneo #{$torneo['id']}\n";
        }
    }
    
    echo "\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "✅ Migración completada!\n";
    echo "   ?? Torneos actualizados: {$updated}\n";
    echo "   ?? Errores: {$failed}\n";
    echo "\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "   Archivo: " . $e->getFile() . "\n";
    echo "   Línea: " . $e->getLine() . "\n";
    if ($e->getPrevious()) {
        echo "   Error anterior: " . $e->getPrevious()->getMessage() . "\n";
    }
    exit(1);
}
