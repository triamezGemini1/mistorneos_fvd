<?php


require_once __DIR__ . '/../config/db.php';

/**
 * Generador de credenciales para jugadores
 * Genera PDFs en formato tarjeta de cr�dito (8cm x 5cm)
 */
class PlayerCredentialGenerator {
    
    /**
     * Genera credencial PDF para un jugador específico
     */
    public static function generateCredential(int $registrant_id): array {
        try {
            // Obtener datos del jugador
            $player_data = self::getPlayerData($registrant_id);
            if (!$player_data) {
                return ['success' => false, 'error' => 'Jugador no encontrado'];
            }
            
            // Validar que el jugador tenga un identificador asignado
            $identificador = $player_data['identificador'] ?? 0;
            if (false && (empty($identificador) || $identificador == 0)) {
                return [
                    'success' => false,
                    'error' => 'IDENTIFICADOR INV�LIDO. El jugador no tiene un número asignado. Por favor, genere los identificadores del torneo antes de crear las credenciales. Puede hacerlo desde el botón "Numerar por Club" en el módulo de inscritos.'
                ];
            }
            
            // Generar contenido HTML
            $html_content = self::generateCredentialHTML($player_data);
            
            // Generar PDF
            $pdf_path = self::createCredentialPDF($html_content, $player_data);
            
            return [
                'success' => true,
                'pdf_path' => $pdf_path,
                'message' => 'Credencial generada correctamente'
            ];
            
        } catch (Exception $e) {
            error_log("Error generando credencial: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error generando credencial: ' . $e->getMessage()];
        }
    }
    
    /**
     * Genera credenciales para múltiples jugadores de un torneo
     */
    public static function generateBulkCredentials(int $tournament_id, ?int $club_id = null): array {
        try {
            $credentials = [];
            $errors = [];
            
            // Obtener todos los jugadores del torneo (tabla inscritos, opcionalmente filtrado por club)
            $query = "SELECT id FROM inscritos WHERE torneo_id = ? AND (estatus IS NULL OR estatus != 4)";
            $params = [$tournament_id];
            
            if ($club_id !== null) {
                $query .= " AND id_club = ?";
                $params[] = $club_id;
            }
            
            $stmt = DB::pdo()->prepare($query);
            $stmt->execute($params);
            $players = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($players as $player_id) {
                $result = self::generateCredential($player_id);
                if ($result['success']) {
                    $credentials[] = $result['pdf_path'];
                } else {
                    $errors[] = "Jugador ID $player_id: " . $result['error'];
                }
            }
            
            return [
                'success' => count($credentials) > 0,
                'credentials' => $credentials,
                'total' => count($credentials),
                'errors' => $errors,
                'message' => count($credentials) . ' credenciales generadas'
            ];
            
        } catch (Exception $e) {
            error_log("Error generando credenciales en masa: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private static function getBaseUrlForCredential(): string {
        if (class_exists('AppHelpers') && method_exists('AppHelpers', 'getPublicUrl')) {
            return rtrim(AppHelpers::getPublicUrl(), '/');
        }
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = defined('URL_BASE') && URL_BASE !== '' ? rtrim(URL_BASE, '/') : '';
        return $protocol . '://' . $host . $path;
    }
    
    /**
     * Obtiene todos los datos necesarios para la credencial (tabla inscritos + usuarios + tournaments + clubes)
     */
    private static function getPlayerData(int $registrant_id): ?array {
        $pdo = DB::pdo();
        $stmt = $pdo->prepare("
            SELECT 
                i.id, i.id_usuario, i.torneo_id, i.id_club, i.codigo_equipo, i.cedula, i.numero, i.estatus,
                u.nombre, u.photo_path,
                t.nombre as tournament_name,
                t.fechator as tournament_date,
                t.club_responsable as organizer_club_id,
                c.nombre as club_name,
                c.logo as club_logo,
                oc.nombre as organizer_club_name,
                oc.logo as organizer_logo
            FROM inscritos i
            LEFT JOIN usuarios u ON i.id_usuario = u.id
            LEFT JOIN tournaments t ON i.torneo_id = t.id
            LEFT JOIN clubes c ON i.id_club = c.id
            LEFT JOIN clubes oc ON t.club_responsable = oc.id
            WHERE i.id = ?
        ");
        $stmt->execute([$registrant_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['cedula'] = $row['cedula'] ?? '';
        if ($row['cedula'] === '' && !empty($row['id_usuario'])) {
            $st2 = $pdo->prepare("SELECT cedula FROM usuarios WHERE id = ?");
            $st2->execute([$row['id_usuario']]);
            $row['cedula'] = $st2->fetchColumn() ?: '';
        }
        $row['identificador'] = !empty($row['codigo_equipo']) ? $row['codigo_equipo'] : ($row['numero'] ?? 0);
        return $row;
    }
    
    /**
     * Genera el HTML para la credencial en formato tarjeta
     */
    private static function generateCredentialHTML(array $player_data): string {
        $player_name = htmlspecialchars((string)$player_data['nombre']);
        $tournament_name = htmlspecialchars((string)$player_data['tournament_name']);
        $club_name = htmlspecialchars((string)$player_data['club_name']);
        
        // Manejar identificador: si es 0 o vacío, mostrar "N/A"
        $identificador_value = $player_data['identificador'] ?? 0;
        if (empty($identificador_value) || $identificador_value == 0) {
            $identificador = 'N/A';
        } else {
            $identificador = htmlspecialchars((string)$identificador_value);
        }
        $base_url = self::getBaseUrlForCredential();
        $id_usuario = (int)($player_data['id_usuario'] ?? 0);
        $qr_url = $base_url . '/entrar_credencial.php?id=' . $id_usuario;
        $qr_img_src = 'https://api.qrserver.com/v1/create-qr-code/?size=80x80&margin=1&data=' . rawurlencode($qr_url);
        $player_cedula = htmlspecialchars((string)($player_data['cedula'] ?? ''));
        $photo_path = trim((string)($player_data['photo_path'] ?? ''));
        $photo_src = '';
        if ($photo_path !== '') {
            $rel = (strpos($photo_path, 'upload/') === 0) ? $photo_path : 'upload/' . ltrim($photo_path, '/');
            $abs = realpath(__DIR__ . '/../' . $rel);
            $chroot = realpath(__DIR__ . '/..');
            if ($abs !== false && $chroot !== false && strpos($abs, $chroot) === 0 && is_file($abs)) {
                $photo_src = $rel;
            }
        }
        $photo_html = $photo_src !== ''
            ? '<img src="' . htmlspecialchars($photo_src) . '" alt="" class="cred-photo-img" />'
            : '<div class="cred-photo-placeholder">Sin foto</div>';
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        @page { size: 8cm 5cm; margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { width: 8cm; height: 5cm; font-family: Arial, Helvetica, sans-serif; background: #fff; overflow: hidden; }
        /* Diseño carnet: fila superior = Foto (izq) | Nombres (der); bajo foto = CI, luego ID; derecha = QR */
        .card-border {
            position: relative;
            width: 100%; height: 100%;
            border: 2px solid #1565c0;
            border-radius: 4px;
            background: #fff;
            padding: 2mm;
            display: grid;
            grid-template-columns: 22mm 1fr;
            grid-template-rows: 1.4fr 0.6fr 0.6fr;
            gap: 1mm 2mm;
        }
        .cred-photo {
            grid-column: 1;
            grid-row: 1;
            background: #f0f0f0;
            border-radius: 2px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .cred-photo-img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .cred-photo-placeholder { font-size: 6pt; color: #999; text-align: center; padding: 2mm; }
        .cred-top-right { grid-column: 2; grid-row: 1; display: flex; flex-direction: column; justify-content: center; padding-left: 2mm; }
        .cred-tournament { font-size: 6pt; font-weight: bold; color: #0d47a1; text-transform: uppercase; line-height: 1.2; }
        .cred-name { font-size: 8pt; font-weight: bold; color: #212121; line-height: 1.2; margin-top: 0.5mm; }
        .cred-cedula { grid-column: 1; grid-row: 2; font-size: 6pt; color: #555; align-self: end; }
        .cred-id-block { grid-column: 1; grid-row: 3; }
        .cred-id { font-size: 8pt; font-weight: bold; color: #0d47a1; display: block; }
        .cred-club { font-size: 5pt; color: #1565c0; display: block; margin-top: 0.5mm; }
        .cred-qr { grid-column: 2; grid-row: 2 / 4; display: flex; flex-direction: column; align-items: center; justify-content: center; }
        .cred-qr img { width: 16mm; height: 16mm; display: block; }
        .cred-qr-label { font-size: 5pt; color: #666; text-align: center; margin-top: 0.5mm; }
    </style>
</head>
<body>
    <div class="card-border">
        <div class="cred-photo">' . $photo_html . '</div>
        <div class="cred-top-right">
            <div class="cred-tournament">' . $tournament_name . '</div>
            <div class="cred-name">' . $player_name . '</div>
        </div>
        <div class="cred-cedula">C.I. ' . $player_cedula . '</div>
        <div class="cred-id-block">
            <span class="cred-id">#' . $id_usuario . ' &ndash; ' . $identificador . '</span>
            <span class="cred-club">' . $club_name . '</span>
        </div>
        <div class="cred-qr">
            <img src="' . htmlspecialchars($qr_img_src) . '" alt="QR" />
            <span class="cred-qr-label">Escanear para ingresar</span>
        </div>
    </div>
</body>
</html>';
        
        return $html;
    }
    
    /**
     * Crea el PDF de la credencial
     */
    private static function createCredentialPDF(string $html_content, array $player_data): string {
        // Verificar si Dompdf est• disponible
        if (!class_exists('Dompdf\Dompdf')) {
            $dompdf_path = __DIR__ . '/../vendor/autoload.php';
            if (file_exists($dompdf_path)) {
                require_once $dompdf_path;
            } else {
                throw new Exception('Dompdf no est• disponible. Instale Dompdf para generar PDFs.');
            }
        }
        
        // Crear directorio de credenciales si no existe
        $credentials_dir = __DIR__ . '/../upload/credentials/';
        if (!is_dir($credentials_dir)) {
            mkdir($credentials_dir, 0755, true);
        }
        
        // Generar nombre del archivo
        $safe_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($player_data['nombre'] ?? 'jugador'));
        $ident = $player_data['identificador'] ?? 0;
        $filename = 'credencial_' . ($player_data['torneo_id'] ?? 0) . '_' . $ident . '_' . $safe_name . '_' . time() . '.pdf';
        $pdf_path = $credentials_dir . $filename;
        
        // Crear PDF usando Dompdf
        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('chroot', __DIR__ . '/..');
        
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html_content);
        
        // Tama�o: 14cm x 10cm
        // 1cm = 28.3465 puntos
        // 14cm = 396.85 puntos
        // 10cm = 283.46 puntos
        $dompdf->setPaper([0, 0, 226.77, 141.73], 'portrait');
        
        $dompdf->render();
        
        // Guardar PDF
        $output = $dompdf->output();
        file_put_contents($pdf_path, $output);
        
        return 'upload/credentials/' . $filename;
    }
    
    /**
     * Obtiene la ruta absoluta de un logo
     */
    private static function getLogoPath(?string $logo_relative_path): ?string {
        if (empty($logo_relative_path)) {
            return null;
        }
        
        $absolute_path = __DIR__ . '/../' . $logo_relative_path;
        
        if (!file_exists($absolute_path)) {
            error_log("Logo no encontrado: " . $absolute_path);
            return null;
        }
        
        $extension = strtolower(pathinfo($absolute_path, PATHINFO_EXTENSION));
        $valid_extensions = ['jpg', 'jpeg', 'png', 'gif'];
        
        if (!in_array($extension, $valid_extensions)) {
            return null;
        }
        
        return $absolute_path;
    }
    
    /**
     * Obtiene la ruta del logo de La Estación del Domin�
     */
    private static function getEstacionLogoPath(): ?string {
        // Buscar el logo en varias ubicaciones posibles
        $possible_paths = [
            __DIR__ . '/../upload/logos/estacion_domino.png',
            __DIR__ . '/../upload/logos/estacion_domino.jpg',
            __DIR__ . '/../upload/logos/la_estacion.png',
            __DIR__ . '/../upload/logos/la_estacion.jpg',
            __DIR__ . '/../public/assets/img/estacion_domino.png',
            __DIR__ . '/../public/assets/img/estacion_domino.jpg',
        ];
        
        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        
        return null;
    }
    
    /**
     * Descarga directamente la credencial al navegador
     */
    public static function downloadCredential(int $registrant_id): void {
        $result = self::generateCredential($registrant_id);
        
        if (!$result['success']) {
            http_response_code(500);
            echo json_encode($result);
            exit;
        }
        
        $file_path = __DIR__ . '/../' . $result['pdf_path'];
        
        if (!file_exists($file_path)) {
            http_response_code(404);
            echo json_encode(['error' => 'Archivo no encontrado']);
            exit;
        }
        
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="credencial_jugador.pdf"');
        header('Content-Length: ' . filesize($file_path));
        readfile($file_path);
        exit;
    }
}


