<?php
/**
 * Motor de notificaciones masivas de alta velocidad
 * Cola: Telegram + Web (campanita). Inserción masiva (bulk insert) y envío en segundo plano.
 */
require_once __DIR__ . '/TelegramBot.php';

class NotificationManager {

    /** @var PDO */
    private $pdo;

    /** @var string Token del bot de Telegram (desde .env) */
    private $botToken;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->botToken = (string)($_ENV['TELEGRAM_BOT_TOKEN'] ?? '');
    }

    /**
     * Obtiene una plantilla por su clave (nombre_clave).
     *
     * @param string $clave Ej: 'nueva_ronda', 'resultados'
     * @return array|null Fila de plantillas_notificaciones o null
     */
    public function obtenerPlantilla(string $clave): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM plantillas_notificaciones WHERE nombre_clave = ?");
        $stmt->execute([$clave]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Lista todas las plantillas, opcionalmente filtradas por categoría.
     *
     * @param string|null $categoria 'torneo', 'afiliacion', 'general' o null para todas
     * @return array
     */
    public function listarPlantillas(?string $categoria = null): array {
        if ($categoria !== null) {
            $stmt = $this->pdo->prepare("SELECT * FROM plantillas_notificaciones WHERE categoria = ? ORDER BY titulo_visual");
            $stmt->execute([$categoria]);
        } else {
            $stmt = $this->pdo->query("SELECT * FROM plantillas_notificaciones ORDER BY categoria, titulo_visual");
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Procesa el texto reemplazando variables dinámicas {nombre}, {ronda}, {torneo}, etc.
     *
     * @param string $texto Cuerpo del mensaje con placeholders
     * @param array $datos Asociativo: ['nombre' => 'Juan', 'ronda' => '3']
     * @return string
     */
    public function procesarMensaje(string $texto, array $datos): string {
        foreach ($datos as $llave => $valor) {
            $texto = str_replace('{' . $llave . '}', (string) $valor, $texto);
        }
        return $texto;
    }

    /**
     * Inserción masiva para muchos jugadores en un solo viaje a la BD.
     * Usado al publicar una ronda: cada jugador recibe notificación Web + Telegram (si tiene chat_id).
     * Si se pasa $clave_plantilla, se usa la plantilla y se personaliza por jugador (nombre, ronda, torneo).
     *
     * @param array $jugadores Lista de ['id' => usuario_id, 'nombre' => string, 'telegram_chat_id' => string|null]
     * @param string $titulo Ej: "Torneo Anual"
     * @param int $ronda Número de ronda
     * @param string|null $url_destino Opcional
     * @param string|null $clave_plantilla Opcional, ej: 'nueva_ronda'
     * @param int|null $torneo_id Opcional, para datos estructurados y url_clasificacion
     */
    public function programarRondaMasiva(array $jugadores, string $titulo, int $ronda, ?string $url_destino = null, ?string $clave_plantilla = null, ?int $torneo_id = null): void {
        $url = $url_destino ?? "ver_ronda.php?ronda={$ronda}";

        if ($clave_plantilla !== null && $clave_plantilla !== '') {
            $plantilla = $this->obtenerPlantilla($clave_plantilla);
            if ($plantilla) {
                $items = [];
                foreach ($jugadores as $j) {
                    $uid = (int)($j['id'] ?? 0);
                    if ($uid <= 0) continue;
                    $url_resumen = trim((string)($j['url_resumen'] ?? ''));
                    $url_destino_item = $url_resumen !== '' ? $url_resumen : $url;
                    $url_clasificacion = trim((string)($j['url_clasificacion'] ?? ''));
                    $mensaje = $this->procesarMensaje($plantilla['cuerpo_mensaje'], [
                        'nombre' => (string)($j['nombre'] ?? ''),
                        'ronda' => (string)$ronda,
                        'torneo' => $titulo,
                        'ganados' => (string)($j['ganados'] ?? '0'),
                        'perdidos' => (string)($j['perdidos'] ?? '0'),
                        'efectividad' => (string)($j['efectividad'] ?? '0'),
                        'puntos' => (string)($j['puntos'] ?? '0'),
                        'mesa' => (string)($j['mesa'] ?? '—'),
                        'pareja' => (string)($j['pareja'] ?? '—'),
                        'url_resumen' => $url_resumen !== '' ? $url_resumen : '—',
                    ]);
                    $item = [
                        'id' => $uid,
                        'telegram_chat_id' => trim((string)($j['telegram_chat_id'] ?? '')) ?: null,
                        'mensaje' => $mensaje,
                        'url_destino' => $url_destino_item,
                    ];
                    if ($clave_plantilla === 'nueva_ronda') {
                        $item['datos_json'] = [
                            'tipo' => 'nueva_ronda',
                            'ronda' => (string) $ronda,
                            'mesa' => (string)($j['mesa'] ?? '—'),
                            'usuario_id' => $uid,
                            'nombre' => (string)($j['nombre'] ?? ''),
                            'pareja_id' => (int)($j['pareja_id'] ?? 0),
                            'pareja_nombre' => (string)($j['pareja'] ?? '—'),
                            'posicion' => (string)($j['posicion'] ?? '0'),
                            'ganados' => (string)($j['ganados'] ?? '0'),
                            'perdidos' => (string)($j['perdidos'] ?? '0'),
                            'efectividad' => (string)($j['efectividad'] ?? '0'),
                            'puntos' => (string)($j['puntos'] ?? '0'),
                            'url_resumen' => $url_resumen !== '' ? $url_resumen : '#',
                            'url_clasificacion' => $url_clasificacion !== '' ? $url_clasificacion : '#',
                        ];
                    }
                    $items[] = $item;
                }
                if (!empty($items)) {
                    $this->programarMasivoPersonalizado($items);
                }
                return;
            }
        }

        $mensaje = "¡{$titulo}! La Ronda {$ronda} ya está disponible.";
        $this->insertarEnCola($jugadores, $mensaje, $url);
    }

    /**
     * Encola mensajes para una lista de destinatarios (desde Notificaciones Masivas).
     * Inserta canal 'web' para todos y 'telegram' solo para quienes tengan telegram_chat_id.
     *
     * @param array $destinatarios Lista de ['id' => int, 'telegram_chat_id' => string|null, ...]
     * @param string $mensaje Texto ya personalizado por destinatario (o mismo para todos)
     * @param string $url_destino URL de destino, ej. '#'
     */
    public function programarMasivo(array $destinatarios, string $mensaje, string $url_destino = '#'): void {
        $jugadores = [];
        foreach ($destinatarios as $d) {
            $id = (int)($d['id'] ?? $d['identificador'] ?? 0);
            if ($id <= 0) continue;
            $jugadores[] = [
                'id' => $id,
                'telegram_chat_id' => trim((string)($d['telegram_chat_id'] ?? '')) ?: null,
            ];
        }
        $this->insertarEnCola($jugadores, $mensaje, $url_destino);
    }

    /**
     * Encola mensajes personalizados (un mensaje por destinatario) para Telegram + Web.
     * Opcional 'datos_json' => string (JSON) para tarjeta formateada (ej. nueva_ronda).
     *
     * @param array $items Lista de ['id' => int, 'telegram_chat_id' => string|null, 'mensaje' => string, 'url_destino' => string, 'datos_json' => string|null]
     */
    public function programarMasivoPersonalizado(array $items): void {
        $hasDatosJson = $this->pdo->query("SHOW COLUMNS FROM notifications_queue LIKE 'datos_json'")->rowCount() > 0;
        $cols = $hasDatosJson
            ? "INSERT INTO notifications_queue (usuario_id, canal, mensaje, url_destino, datos_json) VALUES "
            : "INSERT INTO notifications_queue (usuario_id, canal, mensaje, url_destino) VALUES ";
        $parts = [];
        $data = [];

        foreach ($items as $item) {
            $uid = (int)($item['id'] ?? $item['identificador'] ?? 0);
            if ($uid <= 0) continue;
            $mensaje = (string)($item['mensaje'] ?? '');
            $url = (string)($item['url_destino'] ?? '#');
            $datosJson = isset($item['datos_json']) ? (is_string($item['datos_json']) ? $item['datos_json'] : json_encode($item['datos_json'])) : null;

            if ($hasDatosJson) {
                $parts[] = "(?, 'web', ?, ?, ?)";
                $data[] = $uid;
                $data[] = $mensaje;
                $data[] = $url;
                $data[] = $datosJson;
            } else {
                $parts[] = "(?, 'web', ?, ?)";
                $data[] = $uid;
                $data[] = $mensaje;
                $data[] = $url;
            }

            if (!empty($item['telegram_chat_id'])) {
                if ($hasDatosJson) {
                    $parts[] = "(?, 'telegram', ?, ?, NULL)";
                    $data[] = $uid;
                    $data[] = $mensaje;
                    $data[] = $url;
                } else {
                    $parts[] = "(?, 'telegram', ?, ?)";
                    $data[] = $uid;
                    $data[] = $mensaje;
                    $data[] = $url;
                }
            }
        }

        if (!empty($parts)) {
            $sql = $cols . implode(', ', $parts);
            $this->pdo->prepare($sql)->execute($data);
        }
    }

    /**
     * Inserta en notifications_queue: una fila 'web' por jugador y una 'telegram' si tiene chat_id.
     */
    private function insertarEnCola(array $jugadores, string $mensaje, string $url_destino): void {
        $sql = "INSERT INTO notifications_queue (usuario_id, canal, mensaje, url_destino) VALUES ";
        $parts = [];
        $data = [];

        foreach ($jugadores as $j) {
            $uid = (int)($j['id'] ?? 0);
            if ($uid <= 0) continue;

            $parts[] = "(?, 'web', ?, ?)";
            $data[] = $uid;
            $data[] = $mensaje;
            $data[] = $url_destino;

            if (!empty($j['telegram_chat_id'])) {
                $parts[] = "(?, 'telegram', ?, ?)";
                $data[] = $uid;
                $data[] = $mensaje;
                $data[] = $url_destino;
            }
        }

        if (!empty($parts)) {
            $sql .= implode(', ', $parts);
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($data);
        }
    }

    /**
     * Envío individual vía Telegram Bot API.
     *
     * @param string $chat_id Chat ID del usuario en Telegram
     * @param string $texto Mensaje (soporta HTML si parse_mode = HTML)
     * @return bool true si se envió correctamente
     */
    public function enviarTelegram(string $chat_id, string $texto): bool {
        if ($this->botToken === '' || $chat_id === '' || trim($texto) === '') {
            return false;
        }
        $result = TelegramBot::sendMessage($this->botToken, $chat_id, $texto);
        return !empty($result['ok']);
    }

    /**
     * Cuenta notificaciones web pendientes para un usuario (para la campanita).
     */
    public function contarPendientesWeb(int $usuario_id): int {
        require_once __DIR__ . '/TournamentAppScope.php';
        $filtro = TournamentAppScope::sqlExcluirNotificacionesFvd('nq');
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM notifications_queue nq WHERE nq.usuario_id = ? AND nq.canal = 'web' AND nq.estado = 'pendiente'{$filtro}"
        );
        $stmt->execute([$usuario_id]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Marca como vistas/entregadas las notificaciones web pendientes de un usuario.
     */
    public function marcarWebVistas(int $usuario_id): void {
        $stmt = $this->pdo->prepare(
            "UPDATE notifications_queue SET estado = 'enviado' WHERE usuario_id = ? AND canal = 'web' AND estado = 'pendiente'"
        );
        $stmt->execute([$usuario_id]);
    }
}
