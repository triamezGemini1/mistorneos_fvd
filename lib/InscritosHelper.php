<?php
/**
 * Helper para manejo de estatus de inscritos
 *
 * Estatus en uso (numérico, FVD):
 * - 0 pendiente: debe pagar (inscripción nueva)
 * - 1 pagado: pago validado / confirmado para jugar
 * - 9 retirado: retirado del torneo (legacy: 4)
 * Legacy: 2 se trata como pagado (migración a 1).
 *
 * Los valores legacy 'solvente' y 'no_solvente' se obvian; migrar a 'confirmado' si existen.
 */

class InscritosHelper {

    /** Estatus vigentes (uso en SQL y persistencia) */
    const ESTATUS_PENDIENTE = 'pendiente';
    const ESTATUS_CONFIRMADO = 'confirmado';
    const ESTATUS_RETIRADO = 'retirado';

    const ESTATUS_PENDIENTE_NUM = 0;
    /** Pagado / confirmado para participar. */
    const ESTATUS_CONFIRMADO_NUM = 1;
    /** Alias explícito del estatus pagado. */
    const ESTATUS_PAGADO_NUM = 1;

    /** Valor numérico para retirado (columna INT). */
    const ESTATUS_RETIRADO_NUM = 9;

    /** Legacy: retirado guardado como 4 en datos antiguos. */
    const ESTATUS_RETIRADO_NUM_LEGACY = 4;

    /** Titular para asignación de mesas (torneos por equipos). */
    const ACTIVO_MESA_SI = 1;

    /** Banca / suplente: conserva codigo_equipo y estadísticas, no entra a mesas. */
    const ACTIVO_MESA_BANCA = 0;

    /**
     * Condición SQL: solo inscritos confirmados (cuentan para participar en el torneo).
     * Compatible con columna INT y ENUM.
     */
    const SQL_WHERE_SOLO_CONFIRMADO = "(estatus = 1 OR estatus = 2 OR estatus = 'confirmado' OR estatus IN ('solvente'))";

    /**
     * Misma condición que SQL_WHERE_SOLO_CONFIRMADO con alias de tabla.
     */
    public static function sqlWhereSoloConfirmadoConAlias($alias = '')
    {
        $e = $alias ? $alias . '.' : '';
        return "(" . $e . "estatus = 1 OR " . $e . "estatus = 2 OR " . $e . "estatus = 'confirmado' OR " . $e . "estatus = 'solvente')";
    }

    public static function sqlWhereNoRetiradoConAlias(string $alias = ''): string
    {
        $e = $alias !== '' ? $alias . '.' : '';

        return '(CAST(' . $e . 'estatus AS CHAR) NOT IN (\'4\', \'9\', \'retirado\'))';
    }

    public static function tieneColumnaActivoMesa(PDO $pdo): bool
    {
        static $cache = null;
        if ($cache === null) {
            $cache = (bool) $pdo->query("SHOW COLUMNS FROM inscritos LIKE 'activo_mesa'")->fetchColumn();
        }

        return $cache;
    }

    public static function sqlExprActivoMesa(string $alias, PDO $pdo): string
    {
        if (!self::tieneColumnaActivoMesa($pdo)) {
            return '1';
        }

        return 'COALESCE(' . $alias . '.activo_mesa, 1)';
    }

    public static function sqlWhereActivoMesaConAlias(PDO $pdo, string $alias = 'i'): string
    {
        if (!self::tieneColumnaActivoMesa($pdo)) {
            return '1=1';
        }
        $e = $alias !== '' ? $alias . '.' : '';

        return '(' . $e . 'activo_mesa IS NULL OR ' . $e . 'activo_mesa = 1)';
    }

    /**
     * Condición SQL: inscrito activo (no retirado). Usar en WHERE.
     * Compatible con columna INT (retirado = 4) y VARCHAR/ENUM ('retirado').
     * CAST evita 1292 en MySQL estricto: no comparar literal 'retirado' contra INT.
     * Con estatus NULL, CAST da NULL y NOT IN no coincide (mismo efecto práctico que el != anterior).
     */
    const SQL_WHERE_NO_RETIRADO = "(CAST(estatus AS CHAR) NOT IN ('4', '9', 'retirado'))";

    /**
     * Condición SQL: inscrito activo para conteo y rondas (no retirado).
     * Incluye pendiente (0), pagado (1) y legacy (2); excluye retirado (4).
     *
     * Importante (MySQL modo estricto): NO mezclar `estatus IN (0,1,2,3) OR estatus IN ('pendiente',...)`
     * cuando la columna es INT/TINYINT: MySQL puede convertir 'pendiente' a DOUBLE y disparar 1292.
     * Misma estrategia que Parejas ({@see MesaAsignacionParejasFijasService::SQL_ESTATUS_ACTIVO_ALIAS_I}):
     * comparar siempre vía CAST a CHAR.
     */
    const SQL_WHERE_ACTIVO = "(CAST(estatus AS CHAR) IN ('0','1','2','3','pendiente','confirmado','solvente','no_solvente'))";

    /**
     * Misma condición que SQL_WHERE_ACTIVO con alias de tabla (ej: 'i' → "i.estatus ...").
     * @param string $alias Alias de la tabla inscritos (ej: 'i'). Vacío = sin alias.
     */
    public static function sqlWhereActivoConAlias($alias = '')
    {
        $e = $alias ? $alias . '.' : '';
        return '(CAST(' . $e . 'estatus AS CHAR) IN (\'0\',\'1\',\'2\',\'3\',\'pendiente\',\'confirmado\',\'solvente\',\'no_solvente\'))';
    }

    /**
     * Condición SQL: inscrito confirmado (cuenta para rondas: pago verificado o inscripción en sitio).
     */
    const SQL_WHERE_CONFIRMADO = "estatus = 'confirmado'";

    /**
     * Mapeo numérico (API/legacy) a enum. 0, 1, 4 en uso.
     */
    const ESTATUS_MAP = [
        0 => 'pendiente',
        1 => 'confirmado',
        9 => 'retirado',
    ];

    /** Para lectura legacy: solvente/no_solvente se muestran como confirmado */
    const ESTATUS_MAP_LEGACY = [
        2 => 'confirmado',
        3 => 'confirmado'
    ];

    /**
     * Mapeo inverso: texto a número
     */
    const ESTATUS_REVERSE_MAP = [
        'pendiente' => 0,
        'confirmado' => 1,
        'pagado' => 1,
        'retirado' => 9,
    ];

    /**
     * Expresión SQL (MySQL): columna de inscritos como DECIMAL; texto no numérico (p. ej. 'pendiente') → 0.
     * Evita SQLSTATE 22007/1292 con sql_mode estricto en SUM/CAST/ORDER BY.
     *
     * @param string $col Nombre calificado, p. ej. "ganados", "i.puntos"
     */
    public static function sqlExprColumnaNumerica(string $col): string
    {
        if ($col === '' || !preg_match('/^[a-zA-Z0-9_.]+$/', $col)) {
            throw new InvalidArgumentException('sqlExprColumnaNumerica: columna inválida');
        }
        return 'IF(CAST(' . $col . ' AS CHAR) REGEXP \'^-?[0-9]+(\\.[0-9]+)?$\', CAST(' . $col . ' AS DECIMAL(18,4)), 0)';
    }

    /**
     * partiresul: comparación segura resultado1 mayor que resultado2 (ganador de fila).
     * Misma lógica que en estadísticas agregadas: no comparar DOUBLE con texto ('pendiente').
     *
     * @param string $alias Alias de tabla partiresul (ej. pr1)
     */
    public static function sqlExprPartiresulResultado1MayorQueResultado2(string $alias = 'pr'): string
    {
        if ($alias === '' || ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
            throw new \InvalidArgumentException('sqlExprPartiresulResultado1MayorQueResultado2: alias inválido');
        }
        $r1 = self::sqlExprColumnaNumerica($alias . '.resultado1');
        $r2 = self::sqlExprColumnaNumerica($alias . '.resultado2');

        return "(({$r1}) > ({$r2}))";
    }
    
    /**
     * Obtiene el texto del estatus a partir del valor numérico
     * 
     * @param int $estatus_num Valor numérico del estatus
     * @return string Texto del estatus o 'desconocido' si no existe
     */
    public static function getEstatusTexto(int $estatus_num): string {
        if ($estatus_num === self::ESTATUS_RETIRADO_NUM_LEGACY) {
            return self::ESTATUS_RETIRADO;
        }
        return self::ESTATUS_MAP[$estatus_num] ?? self::ESTATUS_MAP_LEGACY[$estatus_num] ?? 'desconocido';
    }
    
    /**
     * Obtiene el valor numérico del estatus a partir del texto
     * 
     * @param string $estatus_texto Texto del estatus
     * @return int Valor numérico del estatus o 0 (pendiente) por defecto
     */
    public static function getEstatusNumero(string $estatus_texto): int {
        if (isset(self::ESTATUS_REVERSE_MAP[$estatus_texto])) {
            return self::ESTATUS_REVERSE_MAP[$estatus_texto];
        }
        if (in_array($estatus_texto, ['solvente', 'no_solvente'], true)) {
            return self::ESTATUS_CONFIRMADO_NUM;
        }
        return 0;
    }
    
    /**
     * Obtiene todos los estatus disponibles como array [numero => texto]
     * 
     * @return array Array asociativo con número => texto
     */
    public static function getEstatusOptions(): array {
        return self::ESTATUS_MAP;
    }
    
    /**
     * Obtiene todos los estatus disponibles para usar en formularios HTML
     * 
     * @return array Array con formato ['value' => numero, 'text' => texto, 'label' => texto_formateado]
     */
    /** Solo los 3 estatus vigentes para formularios */
    public static function getEstatusFormOptions(): array {
        $options = [];
        foreach (self::ESTATUS_MAP as $num => $texto) {
            $options[] = [
                'value' => $num,
                'text' => $texto,
                'label' => ucfirst(str_replace('_', ' ', $texto))
            ];
        }
        return $options;
    }
    
    /**
     * Valida si un valor numérico de estatus es válido
     * 
     * @param int $estatus_num Valor numérico a validar
     * @return bool True si es válido, False si no
     */
    public static function isValidEstatus(int $estatus_num): bool {
        return isset(self::ESTATUS_MAP[$estatus_num]);
    }

    /**
     * Valor listo para UPDATE/INSERT según tipo real de inscritos.estatus (INT o ENUM/varchar).
     *
     * @return int|string
     */
    public static function valorEstatusParaColumna(PDO $pdo, int $estatusNum)
    {
        static $tipoColumna = null;
        if ($tipoColumna === null) {
            try {
                $st = $pdo->query("SHOW COLUMNS FROM inscritos LIKE 'estatus'");
                $row = $st->fetch(PDO::FETCH_ASSOC);
                $tipoColumna = strtolower((string) ($row['Type'] ?? 'int'));
            } catch (Throwable $e) {
                $tipoColumna = 'int';
            }
        }
        if (str_contains($tipoColumna, 'enum') || str_contains($tipoColumna, 'varchar') || str_contains($tipoColumna, 'char')) {
            $txt = self::getEstatusTexto($estatusNum);
            if ($txt !== 'desconocido') {
                return $txt;
            }
            if ($estatusNum === self::ESTATUS_PAGADO_NUM) {
                return self::ESTATUS_CONFIRMADO;
            }

            return (string) $estatusNum;
        }

        return $estatusNum;
    }

    /** Indica si el valor (string o int) es un estatus confirmado (puede jugar). */
    public static function esConfirmado($estatus): bool {
        if (is_numeric($estatus)) {
            $n = (int) $estatus;

            return $n === self::ESTATUS_CONFIRMADO_NUM || $n === 2;
        }

        return $estatus === self::ESTATUS_CONFIRMADO || $estatus === 'solvente' || $estatus === 'pagado';
    }

    public static function esPendiente($estatus): bool
    {
        if (is_numeric($estatus)) {
            return (int) $estatus === self::ESTATUS_PENDIENTE_NUM;
        }

        return $estatus === self::ESTATUS_PENDIENTE;
    }

    public static function esRetirado($estatus): bool
    {
        if (is_numeric($estatus)) {
            $n = (int) $estatus;
            return $n === self::ESTATUS_RETIRADO_NUM || $n === self::ESTATUS_RETIRADO_NUM_LEGACY;
        }

        return $estatus === self::ESTATUS_RETIRADO;
    }

    /** Condición SQL: inscrito retirado (9, legacy 4 o texto). */
    public static function sqlWhereRetiradoConAlias(string $alias = ''): string
    {
        $e = $alias !== '' ? $alias . '.' : '';
        return '(CAST(' . $e . 'estatus AS CHAR) IN (\'4\', \'9\', \'retirado\'))';
    }

    public static function sqlWherePendienteConAlias(string $alias = ''): string
    {
        $e = $alias !== '' ? $alias . '.' : '';

        return '(CAST(' . $e . 'estatus AS CHAR) IN (\'0\', \'pendiente\'))';
    }

    public static function sqlWhereConfirmadoConAlias(string $alias = ''): string
    {
        $e = $alias !== '' ? $alias . '.' : '';

        return '(CAST(' . $e . 'estatus AS CHAR) IN (\'1\', \'2\', \'3\', \'confirmado\', \'solvente\', \'pagado\'))';
    }

    /**
     * Elimina la inscripción del torneo (libera al atleta para disponibles / nueva inscripción).
     */
    public static function eliminarInscripcionPorUsuarioTorneo(PDO $pdo, int $torneoId, int $idUsuario): bool
    {
        if ($torneoId <= 0 || $idUsuario <= 0) {
            return false;
        }
        $stmt = $pdo->prepare('DELETE FROM inscritos WHERE torneo_id = ? AND id_usuario = ?');
        $stmt->execute([$torneoId, $idUsuario]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Elimina una fila de inscritos por id (mismo torneo).
     */
    public static function eliminarInscripcionPorId(PDO $pdo, int $torneoId, int $inscripcionId): bool
    {
        if ($torneoId <= 0 || $inscripcionId <= 0) {
            return false;
        }
        $stmt = $pdo->prepare('DELETE FROM inscritos WHERE id = ? AND torneo_id = ?');
        $stmt->execute([$inscripcionId, $torneoId]);

        return $stmt->rowCount() > 0;
    }

    /** @deprecated Cancelado no se usa en FVD (0/1/4). */
    public static function esCancelado($estatus): bool
    {
        return false;
    }
    
    /**
     * Obtiene el texto formateado del estatus (con mayúsculas y espacios)
     * 
     * @param int $estatus_num Valor numérico del estatus
     * @return string Texto formateado (ej: "No Solvente")
     */
    public static function getEstatusFormateado(int $estatus_num): string {
        $texto = self::getEstatusTexto($estatus_num);
        return ucfirst(str_replace('_', ' ', $texto));
    }
    
    /**
     * Obtiene la clase CSS para el estatus (útil para badges)
     * 
     * @param int $estatus_num Valor numérico del estatus
     * @return string Clase CSS (ej: "badge-warning", "badge-success")
     */
    public static function getEstatusClaseCSS(int $estatus_num): string {
        $clases = [
            0 => 'bg-warning text-dark',
            1 => 'bg-success text-white',
            2 => 'bg-success text-white',
            4 => 'bg-dark text-white',
            9 => 'bg-dark text-white',
        ];
        if ($estatus_num === self::ESTATUS_RETIRADO_NUM_LEGACY) {
            return $clases[9];
        }
        return $clases[$estatus_num] ?? $clases[0];
    }
    
    /**
     * Convierte un array de inscritos agregando el campo estatus_texto
     * 
     * @param array $inscritos Array de inscritos con estatus numérico
     * @return array Array con campo adicional estatus_texto
     */
    public static function agregarEstatusTexto(array $inscritos): array {
        return array_map(function($inscrito) {
            if (isset($inscrito['estatus'])) {
                $num = is_numeric($inscrito['estatus']) ? (int)$inscrito['estatus'] : self::getEstatusNumero((string)$inscrito['estatus']);
                $inscrito['estatus_texto'] = self::getEstatusTexto($num);
                $inscrito['estatus_formateado'] = self::getEstatusFormateado($num);
                $inscrito['estatus_clase'] = self::getEstatusClaseCSS($num);
            }
            return $inscrito;
        }, $inscritos);
    }
    
    /**
     * Función centralizada para insertar inscripción en tabla inscritos
     * Valida todos los campos obligatorios y asegura que todos los campos tengan valores
     * 
     * @param PDO $pdo Conexión a la base de datos
     * @param array $datos Datos de la inscripción:
     *   - id_usuario (int, requerido)
     *   - torneo_id (int, requerido)
     *   - id_club (int|null, opcional)
     *   - estatus (int, opcional, default=1)
     *   - inscrito_por (int|null, opcional)
     *   - numero (int|null, opcional, default=0)
     *   - codigo_equipo (string|null, opcional)
     * @return int ID del registro insertado
     * @throws Exception Si los datos son inválidos o hay error al insertar
     */
    public static function insertarInscrito(PDO $pdo, array $datos): int {
        // Validar campos obligatorios
        $id_usuario = (int)($datos['id_usuario'] ?? 0);
        $torneo_id = (int)($datos['torneo_id'] ?? 0);
        
        if ($id_usuario <= 0) {
            throw new Exception('ID de usuario es requerido y debe ser mayor a 0');
        }
        
        if ($torneo_id <= 0) {
            throw new Exception('ID de torneo es requerido y debe ser mayor a 0');
        }

        require_once __DIR__ . '/CampeonatoTorneoHelper.php';
        $errEleg = CampeonatoTorneoHelper::mensajeErrorElegibilidad($pdo, $torneo_id, $id_usuario);
        if ($errEleg !== null) {
            throw new Exception($errEleg);
        }

        // id_usuario en inscritos se mantiene como id interno de usuario para todos los torneos.
        $id_usuario_guardar = $id_usuario;
        
        $estatusRaw = $datos['estatus'] ?? self::ESTATUS_PENDIENTE_NUM;
        if (is_numeric($estatusRaw)) {
            $estatusNum = (int) $estatusRaw;
            $estatus = self::ESTATUS_MAP[$estatusNum] ?? (in_array($estatusNum, [2], true) ? self::ESTATUS_CONFIRMADO : self::ESTATUS_PENDIENTE);
        } else {
            $estatus = in_array($estatusRaw, [self::ESTATUS_PENDIENTE, self::ESTATUS_CONFIRMADO, self::ESTATUS_RETIRADO], true)
                ? $estatusRaw : self::ESTATUS_PENDIENTE;
        }
        
        // Campos opcionales con valores por defecto
        $id_club = !empty($datos['id_club']) ? (int)$datos['id_club'] : null;
        $inscrito_por = !empty($datos['inscrito_por']) ? (int)$datos['inscrito_por'] : null;
        // numero: Si no se especifica, usar 0 (no NULL) para evitar constraint violation
        // El campo numero se asigna después en equipos, pero debe tener un valor inicial
        $numero = isset($datos['numero']) && $datos['numero'] !== null ? (int)$datos['numero'] : 0;
        // clasiequi: Clasificación de equipo (INT), valor por defecto 0
        $clasiequi = isset($datos['clasiequi']) && $datos['clasiequi'] !== null ? (int)$datos['clasiequi'] : 0;
        /* Individual en sitio no trae equipo: la columna suele ser NOT NULL → placeholder reservado (no es un equipo real) */
        $codigo_equipo = isset($datos['codigo_equipo']) ? trim((string)$datos['codigo_equipo']) : '';
        if ($codigo_equipo === '') {
            $codigo_equipo = '000-000';
        }
        // nacionalidad y cedula en inscritos (obligatorios para búsqueda NIVEL 1)
        $nacionalidad_inscrito = isset($datos['nacionalidad']) ? strtoupper(trim((string)$datos['nacionalidad'])) : 'V';
        if (!in_array($nacionalidad_inscrito, ['V', 'E', 'J', 'P'], true)) {
            $nacionalidad_inscrito = 'V';
        }
        $cedula_inscrito = isset($datos['cedula']) ? preg_replace('/\D/', '', (string)$datos['cedula']) : '';
        if ($cedula_inscrito === '' || $nacionalidad_inscrito === 'V') {
            $identidadUsuario = self::obtenerIdentidadUsuario($pdo, $id_usuario);
            if ($cedula_inscrito === '') {
                $cedula_inscrito = $identidadUsuario['cedula'];
            }
            if (($nacionalidad_inscrito === 'V' || $nacionalidad_inscrito === '') && $identidadUsuario['nacionalidad'] !== '') {
                $nacionalidad_inscrito = $identidadUsuario['nacionalidad'];
            }
        }
        if ($cedula_inscrito === '') {
            throw new Exception('No se pudo determinar la cédula del jugador para registrar la inscripción.');
        }
        $numfvd_inscrito = isset($datos['numfvd']) ? (int)$datos['numfvd'] : 0;
        if ($numfvd_inscrito <= 0) {
            $numfvd_inscrito = self::resolverNumfvdParaInscripcion($pdo, $id_usuario, $cedula_inscrito);
        }
        
        // Verificar que no esté ya inscrito (excluir retirados)
        $stmt = $pdo->prepare("SELECT id FROM inscritos WHERE id_usuario = ? AND torneo_id = ? AND " . self::SQL_WHERE_NO_RETIRADO);
        $stmt->execute([$id_usuario_guardar, $torneo_id]);
        if ($stmt->fetch()) {
            throw new Exception('Este usuario ya está inscrito en el torneo');
        }
        
        // estatus numérico: 0 pendiente, 1 pagado, 4 retirado
        $estatus_for_db = is_numeric($estatus) && (isset(self::ESTATUS_MAP[(int)$estatus]) || (int)$estatus === 2)
            ? ((int)$estatus === 2 ? self::ESTATUS_CONFIRMADO_NUM : (int)$estatus)
            : (int) self::getEstatusNumero(is_string($estatus) ? $estatus : 'pendiente');

        // INSERT alineado al esquema real (evita 1136 si faltan/sobran columnas vs VALUES fijos)
        $colNames = $pdo->query('SHOW COLUMNS FROM inscritos')->fetchAll(PDO::FETCH_COLUMN);
        $have = [];
        foreach ($colNames as $c) {
            $have[strtolower((string)$c)] = $c;
        }
        $H = static function (string $n) use ($have): bool {
            return isset($have[strtolower($n)]);
        };
        $insertCols = [];
        $insertVals = [];
        $params = [];
        $push = static function (string $col, string $sql, $param = null) use (&$insertCols, &$insertVals, &$params, $have): void {
            $k = strtolower($col);
            if (!isset($have[$k])) {
                return;
            }
            $insertCols[] = '`' . str_replace('`', '``', $have[$k]) . '`';
            $insertVals[] = $sql;
            if ($sql === '?') {
                $params[] = $param;
            }
        };
        /* Orden alineado a esquemas habituales: nac/cédula, usuario/torneo/club/código, stats, fecha, inscrito, número, notas, clasiequi, estatus */
        if ($H('nacionalidad')) {
            $push('nacionalidad', '?', $nacionalidad_inscrito);
        }
        if ($H('cedula')) {
            $push('cedula', '?', $cedula_inscrito);
        }
        if ($H('numfvd')) {
            $push('numfvd', '?', $numfvd_inscrito);
        }
        $push('id_usuario', '?', $id_usuario_guardar);
        $push('torneo_id', '?', $torneo_id);
        if ($H('id_club')) {
            $push('id_club', '?', $id_club);
        }
        if ($H('codigo_equipo')) {
            $push('codigo_equipo', '?', $codigo_equipo);
        }
        if ($H('activo_mesa')) {
            $am = array_key_exists('activo_mesa', $datos) ? (int) $datos['activo_mesa'] : self::ACTIVO_MESA_SI;
            $push('activo_mesa', '?', $am === self::ACTIVO_MESA_BANCA ? 0 : 1);
        }
        foreach (['posicion', 'ganados', 'perdidos', 'efectividad', 'puntos', 'ptosrnk', 'sancion', 'chancletas', 'zapatos', 'tarjeta'] as $c) {
            if ($H($c)) {
                $insertCols[] = '`' . $have[strtolower($c)] . '`';
                $insertVals[] = '0';
            }
        }
        if ($H('fecha_inscripcion')) {
            $insertCols[] = '`' . $have['fecha_inscripcion'] . '`';
            $insertVals[] = 'NOW()';
        }
        if ($H('inscrito_por')) {
            $push('inscrito_por', '?', $inscrito_por);
        }
        if ($H('numero')) {
            $push('numero', '?', $numero);
        }
        if ($H('notas')) {
            $push('notas', '?', '');
        }
        if ($H('clasiequi')) {
            $push('clasiequi', '?', $clasiequi);
        }
        $push('estatus', '?', $estatus_for_db);
        if ($H('entidad_id')) {
            $ent = isset($datos['entidad_id']) ? (int)$datos['entidad_id'] : 0;
            if ($ent <= 0 && class_exists('DB', false) && method_exists('DB', 'getEntidadId')) {
                $ent = (int) DB::getEntidadId();
            }
            if ($ent <= 0 && defined('DESKTOP_ENTIDAD_ID')) {
                $ent = (int) constant('DESKTOP_ENTIDAD_ID');
            }
            if ($ent > 0) {
                $push('entidad_id', '?', $ent);
            }
        }
        if ($insertCols === []) {
            throw new Exception('Tabla inscritos sin columnas reconocidas');
        }
        if (count($insertCols) !== count($insertVals)) {
            throw new Exception('INSERT inscritos: columnas=' . count($insertCols) . ' valores=' . count($insertVals) . ' (error interno; avisar soporte)');
        }
        if (count($params) !== substr_count(implode(',', $insertVals), '?')) {
            throw new Exception('INSERT inscritos: placeholders no coinciden con parámetros');
        }
        $sql = 'INSERT INTO inscritos (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $insertVals) . ')';
        $stmt = $pdo->prepare($sql);
        $resultado = $stmt->execute($params);
        
        if (!$resultado) {
            $error_info = $stmt->errorInfo();
            $driverMsg = $error_info[2] ?? 'Error desconocido';
            throw new Exception('Error al insertar la inscripción. Columna estatus: valor enviado=' . var_export($estatus_for_db, true) . ' (tipo ' . gettype($estatus_for_db) . '). SQL: ' . $driverMsg);
        }
        
        return (int)$pdo->lastInsertId();
    }

    /**
     * @return array{cedula:string,nacionalidad:string}
     */
    private static function obtenerIdentidadUsuario(PDO $pdo, int $usuarioId): array
    {
        if ($usuarioId <= 0) {
            return ['cedula' => '', 'nacionalidad' => ''];
        }
        try {
            $stmt = $pdo->prepare('SELECT cedula, nacionalidad FROM usuarios WHERE id = ? LIMIT 1');
            $stmt->execute([$usuarioId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $ced = preg_replace('/\D/', '', (string)($row['cedula'] ?? ''));
            $nac = strtoupper(trim((string)($row['nacionalidad'] ?? '')));
            if (!in_array($nac, ['V', 'E', 'J', 'P'], true)) {
                $nac = 'V';
            }
            return ['cedula' => $ced, 'nacionalidad' => $nac];
        } catch (Throwable $e) {
            return ['cedula' => '', 'nacionalidad' => ''];
        }
    }

    /**
     * NUMFVD para inscripción (compatible con NumfvdHelper antiguo en producción).
     */
    public static function resolverNumfvdParaInscripcion(PDO $pdo, int $usuarioId, string $cedula = ''): int
    {
        require_once __DIR__ . '/NumfvdHelper.php';
        if (class_exists('NumfvdHelper', false) && method_exists('NumfvdHelper', 'resolverParaUsuario')) {
            return NumfvdHelper::resolverParaUsuario($pdo, $usuarioId, $cedula);
        }
        $ced = preg_replace('/\D/', '', $cedula);
        if ($usuarioId > 0) {
            try {
                $st = $pdo->prepare('SELECT COALESCE(NULLIF(numfvd, 0), 0) AS nf, cedula FROM usuarios WHERE id = ? LIMIT 1');
                $st->execute([$usuarioId]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $nf = (int) ($row['nf'] ?? 0);
                    if ($nf > 0) {
                        return $nf;
                    }
                    if ($ced === '') {
                        $ced = preg_replace('/\D/', '', (string) ($row['cedula'] ?? ''));
                    }
                }
            } catch (Throwable $e) {
            }
        }
        if ($ced !== '' && class_exists('NumfvdHelper', false) && method_exists('NumfvdHelper', 'resolverDesdeCedula')) {
            $nf = NumfvdHelper::resolverDesdeCedula($pdo, $ced);

            return $nf > 0 ? $nf : 0;
        }
        if ($ced !== '') {
            return self::resolverNumfvdAtletasPorCedula($pdo, $ced);
        }

        return 0;
    }

    /** NUMFVD desde cédula (tabla atletas o fallback). */
    public static function resolverNumfvdDesdeCedula(PDO $pdo, string $cedula): int
    {
        return self::resolverNumfvdParaInscripcion($pdo, 0, $cedula);
    }

    private static function resolverNumfvdAtletasPorCedula(PDO $pdo, string $cedula): int
    {
        $ced = preg_replace('/\D/', '', trim($cedula));
        if ($ced === '') {
            return 0;
        }
        try {
            $st = $pdo->query("SHOW TABLES LIKE 'atletas'");
            if (!$st || !$st->fetchColumn()) {
                return 0;
            }
            $st = $pdo->prepare(
                'SELECT COALESCE(NULLIF(numfvd, 0), 0) AS nf FROM atletas
                 WHERE cedula = ? OR REPLACE(REPLACE(REPLACE(TRIM(CAST(cedula AS CHAR)), \'-\', \'\'), \'.\', \'\'), \' \', \'\') = ?
                 ORDER BY id DESC LIMIT 1'
            );
            $st->execute([$ced, $ced]);
            $nf = (int) ($st->fetchColumn() ?: 0);

            return $nf > 0 ? $nf : 0;
        } catch (Throwable $e) {
            return 0;
        }
    }

    private static function obtenerNumfvdDesdeUsuario(PDO $pdo, int $usuarioId): int
    {
        return self::resolverNumfvdParaInscripcion($pdo, $usuarioId);
    }
    
    /**
     * Obtiene el código de equipo para inscripción en sitio en torneo individual o parejas.
     * Formato: código club (2 dígitos) + '-' + consecutivo por club en el torneo (3 dígitos).
     * Ejemplo: club id=2 con 4 jugadores ya inscritos → "02-005" para el quinto.
     *
     * @param \PDO $pdo Conexión a la base de datos
     * @param int $torneo_id ID del torneo
     * @param int|null $id_club ID del club (si null se devuelve '000-000')
     * @param int $modalidad Modalidad del torneo (1=individual, 2=parejas; otro valor devuelve '000-000')
     * @return string Código equipo ej. "02-001" o "000-000"
     */
    public static function codigoEquipoParaInscripcionSitioIndividual(PDO $pdo, int $torneo_id, ?int $id_club, int $modalidad): string {
        if (!$id_club || ($modalidad !== 1 && $modalidad !== 2)) {
            return '000-000';
        }
        $codigo_club = str_pad((string)$id_club, 2, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM inscritos WHERE torneo_id = ? AND id_club = ? AND estatus != 4");
        $stmt->execute([$torneo_id, $id_club]);
        $consecutivo = (int)$stmt->fetchColumn() + 1;
        return $codigo_club . '-' . str_pad((string)$consecutivo, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Verifica si un usuario puede inscribirse en línea en eventos masivos (es_evento_masivo = 2)
     * 
     * Un usuario NO puede inscribirse en línea si:
     * - Tiene 2 o más inscripciones consecutivas previas en eventos con es_evento_masivo = 2
     * - En esas inscripciones no asistió al evento (ganados = 0 AND perdidos = 0)
     * 
     * NOTA: El pago NO es obligatorio para inscribirse en línea, solo se verifica la asistencia.
     * 
     * @param PDO $pdo Conexión a la base de datos
     * @param int $id_usuario ID del usuario
     * @return array ['puede_inscribirse' => bool, 'razon' => string|null]
     */
    public static function puedeInscribirseEnLinea(PDO $pdo, int $id_usuario): array {
        try {
            // Buscar inscripciones previas en eventos masivos tipo 2 (inscripción en línea de administradores)
            // Ordenadas por fecha descendente para verificar las más recientes primero
            $stmt = $pdo->prepare("
                SELECT 
                    i.id,
                    i.estatus,
                    i.ganados,
                    i.perdidos,
                    t.fechator,
                    t.costo,
                    t.nombre as torneo_nombre
                FROM inscritos i
                INNER JOIN tournaments t ON i.torneo_id = t.id
                WHERE i.id_usuario = ?
                  AND t.es_evento_masivo IN (2, 3)
                  AND t.fechator < CURDATE()
                ORDER BY t.fechator DESC
            ");
            $stmt->execute([$id_usuario]);
            $inscripciones_previas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Contar no presentaciones consecutivas (más recientes primero)
            $no_presentaciones_consecutivas = 0;
            $torneos_problema = [];
            
            foreach ($inscripciones_previas as $inscripcion) {
                $ganados = (int)$inscripcion['ganados'];
                $perdidos = (int)$inscripcion['perdidos'];
                
                // Verificar si NO asistió (no jugó ninguna partida)
                $no_asistio = ($ganados == 0 && $perdidos == 0);
                
                if ($no_asistio) {
                    // Si no asistió, incrementar contador de no presentaciones consecutivas
                    $no_presentaciones_consecutivas++;
                    $torneos_problema[] = $inscripcion['torneo_nombre'];
                } else {
                    // Si asistió a alguna partida, rompe la cadena de no presentaciones consecutivas
                    // Por lo tanto, se detiene el conteo
                    break;
                }
            }
            
            // Si tiene 2 o más no presentaciones consecutivas, no puede inscribirse en línea
            if ($no_presentaciones_consecutivas >= 2) {
                return [
                    'puede_inscribirse' => false,
                    'razon' => 'Has tenido 2 o más no presentaciones consecutivas en eventos anteriores. Debes inscribirte presencialmente.',
                    'no_presentaciones_consecutivas' => $no_presentaciones_consecutivas,
                    'torneos_problema' => $torneos_problema
                ];
            }
            
            return [
                'puede_inscribirse' => true,
                'razon' => null,
                'no_presentaciones_consecutivas' => $no_presentaciones_consecutivas
            ];
        } catch (Exception $e) {
            error_log("Error verificando si usuario puede inscribirse en línea: " . $e->getMessage());
            // En caso de error, permitir la inscripción pero registrar el error
            return [
                'puede_inscribirse' => true,
                'razon' => null,
                'error' => 'Error al verificar historial. Se permite la inscripción.'
            ];
        }
    }
    
    /**
     * Verifica si un usuario debe pagar para un torneo antes de inscribirse en línea
     * 
     * @param PDO $pdo Conexión a la base de datos
     * @param int $torneo_id ID del torneo
     * @param int $id_usuario ID del usuario
     * @return array ['debe_pagar' => bool, 'costo' => float, 'ya_pago' => bool, 'mensaje' => string|null]
     */
    public static function validarPagoAntesInscripcion(PDO $pdo, int $torneo_id, int $id_usuario): array {
        try {
            // Obtener información del torneo
            $stmt = $pdo->prepare("SELECT costo FROM tournaments WHERE id = ?");
            $stmt->execute([$torneo_id]);
            $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$torneo) {
                return [
                    'debe_pagar' => false,
                    'costo' => 0,
                    'ya_pago' => false,
                    'mensaje' => 'Torneo no encontrado'
                ];
            }
            
            $costo = (float)$torneo['costo'];
            $debe_pagar = $costo > 0;
            
            if (!$debe_pagar) {
                return [
                    'debe_pagar' => false,
                    'costo' => 0,
                    'ya_pago' => true,
                    'mensaje' => null
                ];
            }
            
            // Verificar si ya está inscrito y si ya pagó
            $stmt = $pdo->prepare("SELECT estatus FROM inscritos WHERE torneo_id = ? AND id_usuario = ?");
            $stmt->execute([$torneo_id, $id_usuario]);
            $inscripcion = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($inscripcion) {
                $estatusVal = $inscripcion['estatus'];
                $ya_pago = ($estatusVal === self::ESTATUS_CONFIRMADO || $estatusVal === 'solvente');
                
                if ($ya_pago) {
                    return [
                        'debe_pagar' => true,
                        'costo' => $costo,
                        'ya_pago' => true,
                        'mensaje' => 'Ya has pagado tu inscripción'
                    ];
                } else {
                    return [
                        'debe_pagar' => true,
                        'costo' => $costo,
                        'ya_pago' => false,
                        'mensaje' => 'Debes pagar el costo de inscripción (' . number_format($costo, 2) . ') antes de poder inscribirte en línea'
                    ];
                }
            }
            
            // Si no está inscrito y debe pagar, debe pagar primero
            return [
                'debe_pagar' => true,
                'costo' => $costo,
                'ya_pago' => false,
                'mensaje' => 'Debes pagar el costo de inscripción (' . number_format($costo, 2) . ') antes de poder inscribirte en línea'
            ];
        } catch (Exception $e) {
            error_log("Error validando pago antes de inscripción: " . $e->getMessage());
            return [
                'debe_pagar' => false,
                'costo' => 0,
                'ya_pago' => false,
                'mensaje' => 'Error al validar pago'
            ];
        }
    }

    /**
     * Contadores para badges/resumen: inscritos totales, jugadores confirmados y equipos activos.
     * Equipos solo aplica a modalidades con tabla equipos (2=Parejas, 3=Equipos, 4=Parejas fijas).
     *
     * @return array{inscritos_total:int,jugadores_confirmados:int,equipos_activos:int}
     */
    public static function contadoresResumenInscripcionTorneo(\PDO $pdo, int $torneoId, ?int $modalidad = null): array
    {
        $torneoId = max(0, $torneoId);
        if ($torneoId <= 0) {
            return ['inscritos_total' => 0, 'jugadores_confirmados' => 0, 'equipos_activos' => 0];
        }
        if ($modalidad === null) {
            $st = $pdo->prepare('SELECT modalidad FROM tournaments WHERE id = ?');
            $st->execute([$torneoId]);
            $modalidad = (int) ($st->fetchColumn() ?: 0);
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM inscritos WHERE torneo_id = ?');
        $st->execute([$torneoId]);
        $inscritosTotal = (int) $st->fetchColumn();

        $st = $pdo->prepare('SELECT COUNT(*) FROM inscritos WHERE torneo_id = ? AND ' . self::SQL_WHERE_SOLO_CONFIRMADO);
        $st->execute([$torneoId]);
        $jugadoresConf = (int) $st->fetchColumn();

        $equipos = 0;
        if (in_array($modalidad, [2, 3, 4], true)) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM equipos WHERE id_torneo = ? AND estatus = 0');
            $st->execute([$torneoId]);
            $equipos = (int) $st->fetchColumn();
        }

        return [
            'inscritos_total' => $inscritosTotal,
            'jugadores_confirmados' => $jugadoresConf,
            'equipos_activos' => $equipos,
        ];
    }
}

