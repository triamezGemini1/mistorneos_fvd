<?php


/**
 * Funciones helper para manejo de datos de torneos
 */

class TournamentHelpers {
    
    // Mapeo de valores para tipo
    const TIPO_VALUES = [
        1 => 'masculino',
        2 => 'femenino', 
        3 => 'mixto'
    ];
    
    const TIPO_LABELS = [
        1 => 'Masculino',
        2 => 'Femenino',
        3 => 'Mixto'
    ];
    
    // Mapeo de valores para clase
    const CLASE_VALUES = [
        1 => 'torneo',
        2 => 'campeonato'
    ];
    
    const CLASE_LABELS = [
        1 => 'Torneo',
        2 => 'Campeonato'
    ];
    
    // Mapeo de valores para modalidad
    const MODALIDAD_VALUES = [
        1 => 'individual',
        2 => 'parejas',
        3 => 'equipos'
    ];
    
    const MODALIDAD_LABELS = [
        1 => 'Individual',
        2 => 'Parejas',
        3 => 'Equipos'
    ];

    /** Secuencia partiresul a letra de posición: 1=A, 2=C, 3=B, 4=D (Pareja AC vs Pareja BD) */
    const SECUENCIA_LETRAS = [1 => 'A', 2 => 'C', 3 => 'B', 4 => 'D'];
    
    /**
     * Obtener letra de posición según secuencia en partiresul
     */
    public static function secuenciaALetra(int $secuencia): string {
        return self::SECUENCIA_LETRAS[$secuencia] ?? '';
    }
    
    /**
     * Obtener el valor de tipo por ID
     */
    public static function getTipoValue(int $id): string {
        return self::TIPO_VALUES[$id] ?? 'masculino';
    }
    
    /**
     * Obtener el label de tipo por ID
     */
    public static function getTipoLabel(int $id): string {
        return self::TIPO_LABELS[$id] ?? 'Masculino';
    }
    
    /**
     * Obtener el ID de tipo por valor
     */
    public static function getTipoId(string $value): int {
        $id = array_search($value, self::TIPO_VALUES);
        return $id !== false ? $id : 1;
    }
    
    /**
     * Obtener el valor de clase por ID
     */
    public static function getClaseValue(int $id): string {
        return self::CLASE_VALUES[$id] ?? 'torneo';
    }
    
    /**
     * Obtener el label de clase por ID
     */
    public static function getClaseLabel(int $id): string {
        return self::CLASE_LABELS[$id] ?? 'Torneo';
    }
    
    /**
     * Obtener el ID de clase por valor
     */
    public static function getClaseId(string $value): int {
        $id = array_search($value, self::CLASE_VALUES);
        return $id !== false ? $id : 1;
    }
    
    /**
     * Obtener el valor de modalidad por ID
     */
    public static function getModalidadValue(int $id): string {
        return self::MODALIDAD_VALUES[$id] ?? 'individual';
    }
    
    /**
     * Obtener el label de modalidad por ID
     */
    public static function getModalidadLabel(int $id): string {
        return self::MODALIDAD_LABELS[$id] ?? 'Individual';
    }
    
    /**
     * Obtener el ID de modalidad por valor
     */
    public static function getModalidadId(string $value): int {
        $id = array_search($value, self::MODALIDAD_VALUES);
        return $id !== false ? $id : 1;
    }
    
    /**
     * Obtener todas las opciones de tipo para select
     */
    public static function getTipoOptions(): array {
        $options = [];
        foreach (self::TIPO_LABELS as $id => $label) {
            $options[] = [
                'value' => $id,
                'label' => $label
            ];
        }
        return $options;
    }
    
    /**
     * Obtener todas las opciones de clase para select
     */
    public static function getClaseOptions(): array {
        $options = [];
        foreach (self::CLASE_LABELS as $id => $label) {
            $options[] = [
                'value' => $id,
                'label' => $label
            ];
        }
        return $options;
    }
    
    /**
     * Obtener todas las opciones de modalidad para select
     */
    public static function getModalidadOptions(): array {
        $options = [];
        foreach (self::MODALIDAD_LABELS as $id => $label) {
            $options[] = [
                'value' => $id,
                'label' => $label
            ];
        }
        return $options;
    }
    
    /**
     * Formatear datos de torneo para mostrar
     */
    public static function formatTournamentData(array $tournament): array {
        // Manejar tipo
        $tipo = $tournament['tipo'] ?? 'masculino';
        $tipo_id = self::getTipoId($tipo);
        
        // Manejar clase - puede ser string (ENUM) o int (legacy)
        $clase = $tournament['clase'] ?? 'torneo';
        $clase_id = is_numeric($clase) ? (int)$clase : self::getClaseId($clase);
        $clase_value = self::getClaseValue($clase_id);
        $clase_label = self::getClaseLabel($clase_id);
        
        // Manejar modalidad - puede ser string (ENUM) o int (legacy)
        $modalidad = $tournament['modalidad'] ?? 'individual';
        $modalidad_id = is_numeric($modalidad) ? (int)$modalidad : self::getModalidadId($modalidad);
        $modalidad_value = self::getModalidadValue($modalidad_id);
        $modalidad_label = self::getModalidadLabel($modalidad_id);
        
        return [
            'id' => $tournament['id'],
            'nombre' => $tournament['nombre'],
            'tipo' => $tipo,
            'tipo_id' => $tipo_id,
            'tipo_label' => self::getTipoLabel($tipo_id),
            'clase' => $clase,
            'clase_id' => $clase_id,
            'clase_value' => $clase_value,
            'clase_label' => $clase_label,
            'modalidad' => $modalidad,
            'modalidad_id' => $modalidad_id,
            'modalidad_value' => $modalidad_value,
            'modalidad_label' => $modalidad_label,
            'fechator' => $tournament['fechator'],
            'club_responsable' => $tournament['club_responsable'],
            'club_nombre' => $tournament['club_nombre'] ?? null,
            'costo' => $tournament['costo'],
            'tiempo' => $tournament['tiempo'] ?? 0,
            'puntos' => $tournament['puntos'] ?? 0,
            'rondas' => $tournament['rondas'] ?? 0,
            'ranking' => $tournament['ranking'] ?? 0,
            'pareclub' => $tournament['pareclub'] ?? 0,
            'estatus' => $tournament['estatus'],
            'invitacion' => $tournament['invitacion'] ?? null,
            'normas' => $tournament['normas'] ?? null,
            'afiche' => $tournament['afiche'] ?? null,
            'created_at' => $tournament['created_at'] ?? null,
            'updated_at' => $tournament['updated_at'] ?? null
        ];
    }
    
    /**
     * Validar datos de torneo
     */
    public static function validateTournamentData(array $data): array {
        $errors = [];
        
        // Validar nombre
        if (empty($data['nombre'])) {
            $errors[] = 'El nombre del torneo es requerido';
        }
        
        // Validar tipo
        if (isset($data['tipo']) && !in_array($data['tipo'], array_values(self::TIPO_VALUES))) {
            $errors[] = 'Tipo de torneo inválido';
        }
        
        // Validar clase
        if (isset($data['clase']) && !empty($data['clase'])) {
            $clase = (int)$data['clase'];
            if (!in_array($clase, array_keys(self::CLASE_VALUES))) {
                $errors[] = 'Clase de torneo inválida';
            }
        }
        
        // Validar modalidad
        if (isset($data['modalidad']) && !empty($data['modalidad'])) {
            $modalidad = (int)$data['modalidad'];
            if (!in_array($modalidad, array_keys(self::MODALIDAD_VALUES))) {
                $errors[] = 'Modalidad de torneo inválida';
            }
        }
        
        // Validar fecha
        if (isset($data['fechator']) && !empty($data['fechator'])) {
            $date = DateTime::createFromFormat('Y-m-d', $data['fechator']);
            if (!$date || $date->format('Y-m-d') !== $data['fechator']) {
                $errors[] = 'Formato de fecha inválido';
            }
        }
        
        return $errors;
    }
    
    /**
     * Preparar datos para guardar en BD
     */
    public static function prepareTournamentData(array $data): array {
        $prepared = [];
        
        // Campos b�sicos
        if (isset($data['nombre'])) {
            $prepared['nombre'] = trim($data['nombre']);
        }
        
        if (isset($data['fechator'])) {
            $prepared['fechator'] = $data['fechator'];
        }
        
        if (isset($data['club_responsable'])) {
            $prepared['club_responsable'] = (int)$data['club_responsable'];
        }
        
        if (isset($data['costo'])) {
            $prepared['costo'] = (int)$data['costo'];
        }
        
        // Tipo (convertir de ID a valor ENUM)
        if (isset($data['tipo'])) {
            if (is_numeric($data['tipo'])) {
                $prepared['tipo'] = self::getTipoValue((int)$data['tipo']);
            } else {
                $prepared['tipo'] = $data['tipo'];
            }
        }
        
        // Clase
        if (isset($data['clase'])) {
            $prepared['clase'] = (int)$data['clase'];
        }
        
        // Modalidad
        if (isset($data['modalidad'])) {
            $prepared['modalidad'] = (int)$data['modalidad'];
        }
        
        return $prepared;
    }
    
    /**
     * Obtiene la información de la organización de un torneo
     * @param int $torneo_id ID del torneo
     * @return array|null Array con datos de la organización o null
     */
    public static function getOrganizacionTorneo(int $torneo_id): ?array {
        try {
            require_once __DIR__ . '/../config/db.php';
            $pdo = DB::pdo();
            $hasCodOrg = false;
            try {
                $hasCodOrg = (bool)$pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $ignored) {
                $hasCodOrg = false;
            }
            $orgJoin = $hasCodOrg
                ? "INNER JOIN organizaciones o ON (t.club_responsable = o.id OR t.club_responsable = o.cod_org)"
                : "INNER JOIN organizaciones o ON t.club_responsable = o.id";
            $stmt = DB::pdo()->prepare("
                SELECT o.id, o.nombre, o.responsable, o.telefono, o.email, o.entidad, o.admin_user_id,
                       e.nombre as entidad_nombre
                FROM tournaments t
                {$orgJoin}
                LEFT JOIN entidad e ON o.entidad = e.id
                WHERE t.id = ?
            ");
            $stmt->execute([$torneo_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Obtiene el nombre de la organización de un torneo
     * @param int $torneo_id ID del torneo
     * @return string Nombre de la organización o 'Sin organización'
     */
    public static function getOrganizacionNombre(int $torneo_id): string {
        $org = self::getOrganizacionTorneo($torneo_id);
        return $org['nombre'] ?? 'Sin organización';
    }
    
    /**
     * Verifica si un usuario puede modificar un torneo
     * Solo el admin de la organización y admin_general pueden modificar
     * @param int $torneo_id ID del torneo
     * @param array $user Usuario actual
     * @return bool
     */
    public static function canModifyTorneo(int $torneo_id, array $user): bool {
        // admin_general puede modificar cualquier torneo
        if (($user['role'] ?? '') === 'admin_general') {
            return true;
        }
        
        // admin_club solo puede modificar torneos de su organización
        if (($user['role'] ?? '') === 'admin_club') {
            $org = self::getOrganizacionTorneo($torneo_id);
            if ($org && (int)$org['admin_user_id'] === Auth::id()) {
                return true;
            }
        }
        
        return false;
    }
}
?>
