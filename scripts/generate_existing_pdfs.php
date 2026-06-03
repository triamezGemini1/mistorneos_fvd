<?php
/**
 * Script para generar PDFs de invitación para clubes y torneos existentes
 * Ejecutar una vez para generar todos los PDFs faltantes
 */

// Cargar variables de entorno
if (file_exists(__DIR__ . '/../.env')) {
    $env_file = file_get_contents(__DIR__ . '/../.env');
    $lines = explode("\n", $env_file);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (!empty($key)) {
                $_ENV[$key] = $value;
            }
        }
    }
}

// Conectar directamente a la base de datos
$db_host = $_ENV['DB_HOST'] ?? 'localhost';
$db_name = $_ENV['DB_NAME'] ?? $_ENV['DB_DATABASE'] ?? 'mistorneos_fvd';
$db_user = $_ENV['DB_USER'] ?? 'root';
$db_pass = $_ENV['DB_PASS'] ?? '';

try {
    $pdo = new PDO(
        "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (PDOException $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage() . "\n");
}

// Crear una clase DB simple para el script
class DB {
    private static $pdo;
    
    public static function setPDO($pdo) {
        self::$pdo = $pdo;
    }
    
    public static function pdo() {
        return self::$pdo;
    }
}

DB::setPDO($pdo);

// Cargar la clase InvitationPDFGenerator (que usa DB::pdo())
require_once __DIR__ . '/../lib/InvitationPDFGenerator.php';

echo "========================================\n";
echo "Generador de PDFs de Invitación\n";
echo "========================================\n\n";

$stats = [
    'clubes' => ['total' => 0, 'generados' => 0, 'errores' => 0, 'existentes' => 0],
    'torneos' => ['total' => 0, 'generados' => 0, 'errores' => 0, 'existentes' => 0]
];

try {
    // ============================================
    // GENERAR PDFs PARA CLUBES
    // ============================================
    echo "📋 Generando PDFs para clubes...\n";
    echo "----------------------------------------\n";
    
    $stmt = $pdo->query("SELECT id, nombre FROM clubes WHERE estatus = 1 ORDER BY id");
    $clubes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stats['clubes']['total'] = count($clubes);
    
    foreach ($clubes as $club) {
        $club_id = (int)$club['id'];
        $club_nombre = $club['nombre'];
        
        echo "  ?? Club #{$club_id}: {$club_nombre}... ";
        
        try {
            // Verificar si ya existe PDF
            $pdf_existente = InvitationPDFGenerator::getClubPDFPath($club_id);
            
            if ($pdf_existente) {
                // Verificar si el archivo existe físicamente
                $ruta_fisica = __DIR__ . '/../' . $pdf_existente;
                if (file_exists($ruta_fisica)) {
                    echo "✓ Ya existe\n";
                    $stats['clubes']['existentes']++;
                    continue;
                } else {
                    echo "⚠ PDF en BD pero archivo no encontrado, regenerando... ";
                }
            }
            
            // Generar PDF
            $result = InvitationPDFGenerator::generateClubInvitationPDF($club_id);
            
            if ($result['success']) {
                echo "✓ Generado: {$result['pdf_path']}\n";
                $stats['clubes']['generados']++;
            } else {
                echo "✗ Error: " . ($result['error'] ?? 'Error desconocido') . "\n";
                $stats['clubes']['errores']++;
            }
            
        } catch (Exception $e) {
            echo "✗ Excepción: " . $e->getMessage() . "\n";
            $stats['clubes']['errores']++;
        }
    }
    
    echo "\n";
    
    // ============================================
    // GENERAR PDFs PARA TORNEOS
    // ============================================
    echo "🏆 Generando PDFs para torneos...\n";
    echo "----------------------------------------\n";
    
    $stmt = $pdo->query("SELECT id, nombre FROM tournaments WHERE estatus = 1 ORDER BY id");
    $torneos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stats['torneos']['total'] = count($torneos);
    
    foreach ($torneos as $torneo) {
        $torneo_id = (int)$torneo['id'];
        $torneo_nombre = $torneo['nombre'];
        
        echo "  ?? Torneo #{$torneo_id}: {$torneo_nombre}... ";
        
        try {
            // Verificar si ya existe PDF
            $pdf_existente = InvitationPDFGenerator::getTournamentPDFPath($torneo_id);
            
            if ($pdf_existente) {
                // Verificar si el archivo existe físicamente
                $ruta_fisica = __DIR__ . '/../' . $pdf_existente;
                if (file_exists($ruta_fisica)) {
                    echo "✓ Ya existe\n";
                    $stats['torneos']['existentes']++;
                    continue;
                } else {
                    echo "⚠ PDF en BD pero archivo no encontrado, regenerando... ";
                }
            }
            
            // Generar PDF
            $result = InvitationPDFGenerator::generateTournamentInvitationPDF($torneo_id);
            
            if ($result['success']) {
                echo "✓ Generado: {$result['pdf_path']}\n";
                $stats['torneos']['generados']++;
            } else {
                echo "✗ Error: " . ($result['error'] ?? 'Error desconocido') . "\n";
                $stats['torneos']['errores']++;
            }
            
        } catch (Exception $e) {
            echo "✗ Excepción: " . $e->getMessage() . "\n";
            $stats['torneos']['errores']++;
        }
    }
    
    echo "\n";
    
    // ============================================
    // RESUMEN
    // ============================================
    echo "========================================\n";
    echo "RESUMEN\n";
    echo "========================================\n\n";
    
    echo "📋 CLUBES:\n";
    echo "   Total encontrados: {$stats['clubes']['total']}\n";
    echo "   PDFs generados: {$stats['clubes']['generados']}\n";
    echo "   Ya existían: {$stats['clubes']['existentes']}\n";
    echo "   Errores: {$stats['clubes']['errores']}\n\n";
    
    echo "🏆 TORNEOS:\n";
    echo "   Total encontrados: {$stats['torneos']['total']}\n";
    echo "   PDFs generados: {$stats['torneos']['generados']}\n";
    echo "   Ya existían: {$stats['torneos']['existentes']}\n";
    echo "   Errores: {$stats['torneos']['errores']}\n\n";
    
    $total_generados = $stats['clubes']['generados'] + $stats['torneos']['generados'];
    $total_errores = $stats['clubes']['errores'] + $stats['torneos']['errores'];
    
    echo "========================================\n";
    echo "TOTAL:\n";
    echo "   PDFs generados exitosamente: {$total_generados}\n";
    echo "   Errores: {$total_errores}\n";
    echo "========================================\n";
    
    if ($total_errores > 0) {
        echo "\n⚠ ADVERTENCIA: Hubo algunos errores. Revisa los mensajes arriba.\n";
        echo "   Asegúrate de que Dompdf o TCPDF estén instalados.\n";
    } else {
        echo "\n✓ ¡Proceso completado exitosamente!\n";
    }
    
} catch (Exception $e) {
    echo "\n✗ ERROR CRÍTICO: " . $e->getMessage() . "\n";
    echo "   Stack trace:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n";
