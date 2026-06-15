<?php

declare(strict_types=1);

require_once __DIR__ . '/ImportacionTorneoExternoService.php';
require_once __DIR__ . '/InscritosHelper.php';
require_once __DIR__ . '/PartiresulJugadorHelper.php';
require_once __DIR__ . '/NumfvdHelper.php';
require_once __DIR__ . '/EntidadFvdCatalogo.php';
require_once __DIR__ . '/AsociacionAdminHelper.php';
require_once __DIR__ . '/CampeonatoTorneoHelper.php';
require_once __DIR__ . '/AtletasAdminSyncService.php';
require_once __DIR__ . '/TorneoCampoNumerico.php';
require_once __DIR__ . '/PartiresulEstatusSql.php';

/**
 * Importación desde exportaciones Access (parejas inscritas, parti2017, clasiequi).
 * Requiere sincronizar atletas → usuarios antes de importar (numfvd, sexo, entidad).
 */
final class ImportacionAccessExternoService
{
    /** @var array<string, int|null> */
    private static array $colCache = [];

    /** @var bool|null */
    private static ?bool $usuariosTieneIsActive = null;

    public static function jugadoresPorUnidad(int $modalidad): int
    {
        if ($modalidad === 2 || $modalidad === 4) {
            return 2;
        }
        if ($modalidad === 3) {
            return 4;
        }

        return 1;
    }

    public static function requiereClasiequi(int $modalidad): bool
    {
        return $modalidad !== 1;
    }

    /**
     * Situación estructurada para UI/API (origen Access, tabla destino, resolución).
     *
     * @param array<string, mixed> $campos
     * @return array<string, mixed>
     */
    public static function situacionImportacion(string $codigo, array $campos = []): array
    {
        return self::armarSituacion($codigo, $campos);
    }

    /**
     * @param list<array<string, mixed>> $lista
     * @param array<string, mixed> $campos
     */
    private static function registrarIncidenciaEjecucion(array &$lista, string $codigo, array $campos, int $max = 200): void
    {
        if (count($lista) >= $max) {
            return;
        }
        $lista[] = self::situacionImportacion($codigo, array_merge(['fase' => 'ejecucion'], $campos));
    }

    /**
     * @param list<array<string, mixed>> $situaciones
     * @return array<string, int>
     */
    private static function resumirIncidenciasEjecucion(array $situaciones): array
    {
        $res = [
            'total' => count($situaciones),
            'jugadores_no_importados' => 0,
            'equipos_no_importados' => 0,
            'partiresul_omitidos' => 0,
            'banca' => 0,
        ];
        foreach ($situaciones as $s) {
            $c = (string) ($s['codigo'] ?? $s['tipo'] ?? '');
            if (in_array($c, ['cedula_sin_usuario', 'sin_numfvd', 'sin_club'], true)) {
                $res['jugadores_no_importados']++;
            } elseif (str_starts_with($c, 'ejec_equipo_')) {
                $res['equipos_no_importados']++;
            } elseif ($c === 'ejec_partiresul_omitido' || $c === 'numfvd_sin_inscrito') {
                $res['partiresul_omitidos']++;
            } elseif (str_starts_with($c, 'banca_')) {
                $res['banca']++;
            }
        }

        return $res;
    }

    /**
     * @param array<string, mixed> $res
     * @param list<list<string>> $parejasRows
     * @return array<string, mixed>
     */
    private static function finalizarResultadoEjecucion(array $res, array $parejasRows): array
    {
        $incidencias = $res['incidencias_ejecucion'] ?? [];
        $bancaSit = $res['reporte_banca']['situaciones_detalle'] ?? [];
        $res['situaciones_detalle'] = array_merge($incidencias, $bancaSit);
        $res['incidencias_resumen'] = self::resumirIncidenciasEjecucion($res['situaciones_detalle']);
        if (count($incidencias) >= 200) {
            $res['incidencias_truncadas'] = true;
        }

        $totalInscritos = ($res['inscritos_insertados'] ?? 0) + ($res['inscritos_actualizados'] ?? 0);
        if ($totalInscritos <= 0 && self::contarFilasParejasValidas($parejasRows) > 0) {
            self::registrarIncidenciaEjecucion($incidencias, 'cedula_sin_usuario', [
                'elemento' => '(ningún inscrito procesado)',
                'explicacion' => 'No se insertó ni actualizó ningún jugador. Revise Paso 0 (atletas), clubes y numfvd en parejas inscritas.',
            ]);
            $res['incidencias_ejecucion'] = $incidencias;
            $res['situaciones_detalle'] = array_merge($incidencias, $bancaSit);
            $res['incidencias_resumen'] = self::resumirIncidenciasEjecucion($res['situaciones_detalle']);
            $res['advertencias'] = ['Ningún jugador fue importado. Vea incidencias detalladas abajo.'];
        }

        return $res;
    }

    /**
     * @return array{rows: list<list<string>>, error: string|null}
     */
    public static function leerArchivo(string $tmpPath, string $originalName): array
    {
        try {
            $rows = ImportacionTorneoExternoService::leerExcelOCsv($tmpPath, $originalName);
            if ($rows === []) {
                return ['rows' => [], 'error' => 'Archivo vacío o formato no legible. Use Excel (.xlsx, .xls) o CSV.'];
            }

            return ['rows' => $rows, 'error' => null];
        } catch (Throwable $e) {
            return ['rows' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * @param list<list<string>> $rows
     * @return array{header: list<string>, data: list<list<string>>, header_row: int}
     */
    public static function separarCabecera(array $rows, array $aliasesRequeridos): array
    {
        $max = min(25, count($rows));
        for ($r = 0; $r < $max; $r++) {
            $hNorm = self::normalizarFilaEncabezados($rows[$r] ?? []);
            $ok = true;
            foreach ($aliasesRequeridos as $group) {
                if (self::indiceColumna($hNorm, $group) < 0) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $data = array_slice($rows, $r + 1);
                return ['header' => $hNorm, 'data' => $data, 'header_row' => $r];
            }
        }

        $hNorm = self::normalizarFilaEncabezados($rows[0] ?? []);

        return ['header' => $hNorm, 'data' => array_slice($rows, 1), 'header_row' => 0];
    }

    /**
     * @param list<list<string>> $rows
     * @return array<string, mixed>
     */
    public static function analizarParejasInscritas(PDO $pdo, int $torneoId, array $rows): array
    {
        $parsed = self::separarCabecera($rows, [['cedula', 'ced']]);
        $h = $parsed['header'];
        $iCed = self::indiceColumna($h, ['cedula', 'ced', 'documento']);
        $iNumfvd = self::indiceColumna($h, ['numfvd', 'num_fvd', 'carnet', 'pareja']);
        $iAsoc = self::indiceColumna($h, ['asociacion', 'entidad', 'id_club']);
        $iTorneo = self::indiceColumna($h, ['torneo', 'id_torneo', 'torneo_id']);
        $iEquipo = self::indiceColumna($h, ['equipo', 'numero_equipo', 'num_equipo']);
        $iCodEq = self::indiceColumna($h, ['codigo_equipo', 'codequipo']);
        $iNombre = self::indiceColumna($h, ['nombre', 'jugador', 'atleta']);

        $stats = [
            'ok' => false,
            'torneo_destino' => $torneoId,
            'campeonato_genero' => false,
            'campeonato_mapa' => null,
            'filas_leidas' => 0,
            'por_asociacion' => [],
            'por_torneo' => [
                1 => ['filas' => 0, 'listos' => 0, 'ya_inscritos' => 0, 'nombre' => ''],
                2 => ['filas' => 0, 'listos' => 0, 'ya_inscritos' => 0, 'nombre' => ''],
            ],
            'total_general' => 0,
            'cedulas_sin_usuario' => [],
            'cedulas_duplicadas_archivo' => [],
            'usuarios_sin_numfvd' => [],
            'sin_club_entidad' => [],
            'numfvd_duplicados_resueltos' => [],
            'numfvd_discrepancia' => [],
            'torneo_archivo_distinto' => [],
            'torneo_archivo_invalido' => [],
            'sexo_no_coincide_torneo' => [],
            'torneo_sin_sexo' => [],
            'divergencias_detalle' => [],
            'resumen_divergencias' => [],
            'situaciones_detalle' => [],
            'ya_inscritos' => 0,
            'listos' => 0,
            'muestra' => [],
            'errores_columnas' => [],
        ];

        if ($iCed < 0) {
            $stats['errores_columnas'][] = 'Falta columna cédula (obligatoria para buscar en usuarios).';
        }

        $mapa = CampeonatoTorneoHelper::mapaImportacionCampeonatoGenero($pdo, $torneoId);
        if ($mapa !== null) {
            $stats['campeonato_genero'] = true;
            $stats['campeonato_mapa'] = [
                1 => ['id' => $mapa['slots'][1]['id'], 'nombre' => $mapa['slots'][1]['nombre']],
                2 => ['id' => $mapa['slots'][2]['id'], 'nombre' => $mapa['slots'][2]['nombre']],
            ];
            $stats['por_torneo'][1]['nombre'] = (string) $mapa['slots'][1]['nombre'];
            $stats['por_torneo'][2]['nombre'] = (string) $mapa['slots'][2]['nombre'];
            if ($iTorneo < 0) {
                $stats['errores_columnas'][] = 'Campeonato por género: columna torneo obligatoria (1 = hombres, 2 = mujeres).';
            }
        }

        if ($stats['errores_columnas'] !== []) {
            $stats['situaciones_detalle'] = self::situacionesDesdeErroresColumnas(
                'parejas_inscritas',
                'Parejas inscritas',
                $stats['errores_columnas']
            );

            return $stats;
        }

        $inscritosTorneo = self::mapaInscritosNumfvd($pdo, $torneoId);
        $inscritosUsuario = self::mapaInscritosUsuario($pdo, $torneoId);
        $inscritosPorSlot = [];
        if ($mapa !== null) {
            foreach ([1, 2] as $slot) {
                $tid = CampeonatoTorneoHelper::torneoIdDesdeSlot($mapa['slots'], $slot);
                $inscritosPorSlot[$slot] = [
                    'numfvd' => self::mapaInscritosNumfvd($pdo, $tid),
                    'usuario' => self::mapaInscritosUsuario($pdo, $tid),
                    'torneo_id' => $tid,
                ];
            }
        }

        $cedulasVistas = [];
        $numfvdVistos = [];
        $numfvdVistosPorSlot = [1 => [], 2 => []];
        $porAsoc = [];

        $ctxCols = [
            'iCed' => $iCed,
            'iNombre' => $iNombre,
            'iAsoc' => $iAsoc,
            'iTorneo' => $iTorneo,
            'iNumfvd' => $iNumfvd,
            'iCodEq' => $iCodEq,
            'iEquipo' => $iEquipo,
            'mapa' => $mapa,
        ];

        foreach ($parsed['data'] as $rowIdx => $row) {
            $filaArchivo = $parsed['header_row'] + 2 + (int) $rowIdx;
            $ced = self::normalizarCedula($iCed >= 0 ? ($row[$iCed] ?? '') : '');
            if ($ced === '') {
                continue;
            }

            $slot = 0;
            $torneoFila = $torneoId;
            if ($mapa !== null) {
                $slot = self::slotDesdeFila($row, $iTorneo);
                if ($slot === 0) {
                    $stats['torneo_archivo_invalido'][] = $ced;
                    $valTor = $iTorneo >= 0 ? trim((string) ($row[$iTorneo] ?? '')) : '';
                    $stats['divergencias_detalle'][] = self::armarDivergenciaPareja(
                        'torneo_invalido',
                        $ced,
                        null,
                        self::metaArchivoPareja($row, $ctxCols, 0),
                        'Valor de columna torneo distinto de 1 (hombres) o 2 (mujeres).',
                        $filaArchivo,
                        'torneo=' . $valTor,
                        'esperado: 1 o 2'
                    );
                    continue;
                }
                $torneoFila = CampeonatoTorneoHelper::torneoIdDesdeSlot($mapa['slots'], $slot);
            } elseif ($iTorneo >= 0) {
                $torneoArch = (int) preg_replace('/\D/', '', (string) ($row[$iTorneo] ?? ''));
                if ($torneoArch > 0 && $torneoArch !== $torneoId) {
                    $stats['torneo_archivo_distinto'][] = (string) $torneoArch;
                }
            }

            $stats['filas_leidas']++;
            if ($mapa !== null && $slot > 0) {
                $stats['por_torneo'][$slot]['filas']++;
            }

            if (isset($cedulasVistas[$ced])) {
                $stats['cedulas_duplicadas_archivo'][] = $ced;
                $stats['divergencias_detalle'][] = self::armarDivergenciaPareja(
                    'cedula_duplicada_archivo',
                    $ced,
                    null,
                    self::metaArchivoPareja($row, $ctxCols, $slot),
                    'La cédula aparece más de una vez en parejas inscritas (fila ' . $filaArchivo . ').',
                    $filaArchivo,
                    'cedula=' . $ced,
                    'única por archivo'
                );
            }
            $cedulasVistas[$ced] = true;

            $usuario = self::resolverUsuarioPorCedula($pdo, $ced);
            if ($usuario === null) {
                $stats['cedulas_sin_usuario'][] = $ced;
                $meta = self::metaArchivoPareja($row, $ctxCols, $slot);
                $stats['divergencias_detalle'][] = self::armarDivergenciaPareja(
                    'cedula_sin_usuario',
                    $ced,
                    null,
                    $meta,
                    'No hay usuario con esta cédula en la plataforma. Debe afiliar o registrar al atleta antes de importar.',
                    $filaArchivo,
                    'cedula=' . $ced,
                    '(sin fila en usuarios)'
                );
                continue;
            }

            $metaArch = self::metaArchivoPareja($row, $ctxCols, $slot, $usuario);

            if ($mapa !== null && $slot > 0) {
                $generoEsperado = (string) ($mapa['slots'][$slot]['genero'] ?? '');
                $sexo = CampeonatoTorneoHelper::sexoNormalizado((string) ($usuario['sexo'] ?? ''));
                if ($sexo === '') {
                    $stats['torneo_sin_sexo'][] = $ced;
                    $stats['divergencias_detalle'][] = self::armarDivergenciaPareja(
                        'sin_sexo',
                        $ced,
                        $usuario,
                        $metaArch,
                        'El usuario no tiene sexo registrado; no puede ubicarse en torneo masculino/femenino.',
                        $filaArchivo,
                        'sexo=(vacío)',
                        'M o F requerido'
                    );
                    continue;
                }
                if ($sexo !== $generoEsperado) {
                    $etiqGen = $generoEsperado === 'M' ? 'masculino' : 'femenino';
                    $stats['sexo_no_coincide_torneo'][] = $ced . ' (torneo ' . $slot . ', sexo ' . $sexo . ')';
                    $stats['divergencias_detalle'][] = self::armarDivergenciaPareja(
                        'sexo_no_coincide',
                        $ced,
                        $usuario,
                        $metaArch,
                        'Archivo indica torneo ' . $slot . ' (' . $etiqGen . ') pero el sexo del usuario es '
                        . ($sexo === 'M' ? 'masculino' : 'femenino') . '.',
                        $filaArchivo,
                        'torneo=' . $slot . ', sexo archivo/usuario=' . $sexo,
                        'torneo requiere ' . $generoEsperado
                    );
                    continue;
                }
            }

            $idUsr = (int) ($usuario['id'] ?? 0);
            $nfUsuario = self::resolverNumfvdUsuario($pdo, $usuario, $ced);

            $numfvdArch = $iNumfvd >= 0 ? (int) preg_replace('/\D/', '', (string) ($row[$iNumfvd] ?? '')) : 0;
            $numfvd = $numfvdArch > 0 ? $numfvdArch : $nfUsuario;

            if ($numfvd <= 0) {
                $stats['usuarios_sin_numfvd'][] = $ced;
                $stats['divergencias_detalle'][] = self::armarDivergenciaPareja(
                    'sin_numfvd',
                    $ced,
                    $usuario,
                    $metaArch,
                    'El usuario no tiene numfvd/carnet FVD asignado ni viene en el archivo.',
                    $filaArchivo,
                    'numfvd=(vacío)',
                    'usuarios.numfvd o columna pareja/numfvd'
                );
                continue;
            }
            if ($numfvdArch > 0 && $nfUsuario > 0 && $numfvdArch !== $nfUsuario) {
                $stats['numfvd_discrepancia'][] = $ced . ' (archivo ' . $numfvdArch . ' ≠ usuario ' . $nfUsuario . ')';
                $stats['divergencias_detalle'][] = self::armarDivergenciaPareja(
                    'numfvd_discrepancia',
                    $ced,
                    $usuario,
                    array_merge($metaArch, ['numfvd_archivo' => $numfvdArch, 'numfvd_usuario' => $nfUsuario]),
                    'El numfvd del archivo (' . $numfvdArch . ') no coincide con el del usuario (' . $nfUsuario . ').',
                    $filaArchivo,
                    'numfvd=' . $numfvdArch,
                    'usuarios.numfvd=' . $nfUsuario
                );
            }
            if ($mapa !== null && $slot > 0) {
                if (isset($numfvdVistosPorSlot[$slot][$numfvd])) {
                    $stats['numfvd_duplicados_resueltos'][] = (string) $numfvd . ' (torneo ' . $slot . ')';
                    $stats['divergencias_detalle'][] = self::armarDivergenciaPareja(
                        'numfvd_duplicado_archivo',
                        $ced,
                        $usuario,
                        $metaArch,
                        'numfvd ' . $numfvd . ' repetido en parejas inscritas (torneo ' . $slot . ', fila ' . $filaArchivo . ').',
                        $filaArchivo,
                        'numfvd=' . $numfvd,
                        'único por sub-torneo'
                    );
                }
                $numfvdVistosPorSlot[$slot][$numfvd] = true;
            } elseif (isset($numfvdVistos[$numfvd])) {
                $stats['numfvd_duplicados_resueltos'][] = (string) $numfvd;
                $stats['divergencias_detalle'][] = self::armarDivergenciaPareja(
                    'numfvd_duplicado_archivo',
                    $ced,
                    $usuario,
                    $metaArch,
                    'numfvd ' . $numfvd . ' repetido en parejas inscritas (fila ' . $filaArchivo . ').',
                    $filaArchivo,
                    'numfvd=' . $numfvd,
                    'único por torneo'
                );
            }
            $numfvdVistos[$numfvd] = true;

            $entidadUsu = (int) ($usuario['entidad'] ?? 0);
            $idClub = self::clubDesdeEntidadUsuario($pdo, $usuario);
            if ($idClub === null || $idClub <= 0) {
                $stats['sin_club_entidad'][] = $ced . ' (entidad ' . $entidadUsu . ')';
                $stats['divergencias_detalle'][] = self::armarDivergenciaPareja(
                    'sin_club',
                    $ced,
                    $usuario,
                    $metaArch,
                    'La entidad ' . $entidadUsu . ' del usuario no tiene club/asociación resoluble en el sistema.',
                    $filaArchivo,
                    'entidad=' . $entidadUsu,
                    'clubes.entidad sin registro'
                );
            }

            $asocArch = $iAsoc >= 0 ? (int) preg_replace('/\D/', '', (string) ($row[$iAsoc] ?? '')) : 0;
            $claveAsoc = $entidadUsu > 0 ? $entidadUsu : $asocArch;
            $etiqAsoc = $claveAsoc > 0
                ? EntidadFvdCatalogo::etiqueta($claveAsoc)
                : 'Sin asociación';
            $porAsoc[$etiqAsoc] = ($porAsoc[$etiqAsoc] ?? 0) + 1;

            if (count($stats['muestra']) < 8) {
                $stats['muestra'][] = [
                    'cedula' => $ced,
                    'id_usuario' => $idUsr,
                    'numfvd' => $numfvd,
                    'id_club' => $idClub,
                    'entidad' => $entidadUsu,
                    'asociacion' => $etiqAsoc,
                    'nombre' => trim((string) ($usuario['nombre'] ?? '')) ?: ($iNombre >= 0 ? trim((string) ($row[$iNombre] ?? '')) : ''),
                    'torneo_id' => $torneoFila,
                    'slot' => $slot > 0 ? $slot : null,
                    'torneo_etiqueta' => self::etiquetaTorneoSlot($mapa, $slot),
                    'estatus_usuario' => self::etiquetaEstatusUsuario($usuario),
                ];
            }

            $insNf = $inscritosTorneo;
            $insUsr = $inscritosUsuario;
            if ($mapa !== null && $slot > 0) {
                $insNf = $inscritosPorSlot[$slot]['numfvd'];
                $insUsr = $inscritosPorSlot[$slot]['usuario'];
            }

            if (isset($insNf[$numfvd]) || isset($insUsr[$idUsr])) {
                $stats['ya_inscritos']++;
                if ($mapa !== null && $slot > 0) {
                    $stats['por_torneo'][$slot]['ya_inscritos']++;
                }
            } else {
                $stats['listos']++;
                if ($mapa !== null && $slot > 0) {
                    $stats['por_torneo'][$slot]['listos']++;
                }
            }
        }

        $stats['por_asociacion'] = $porAsoc;
        $stats['total_general'] = $stats['filas_leidas'];
        $stats['resumen_divergencias'] = self::resumirDivergenciasParejas($stats);
        $stats['situaciones_detalle'] = $stats['divergencias_detalle'];
        foreach ([
            'cedulas_sin_usuario',
            'cedulas_duplicadas_archivo',
            'usuarios_sin_numfvd',
            'sin_club_entidad',
            'numfvd_duplicados_resueltos',
            'numfvd_discrepancia',
            'torneo_archivo_distinto',
            'torneo_archivo_invalido',
            'sexo_no_coincide_torneo',
            'torneo_sin_sexo',
        ] as $k) {
            $stats[$k] = array_values(array_unique($stats[$k]));
        }
        $stats['ok'] = $stats['filas_leidas'] > 0
            && $stats['cedulas_sin_usuario'] === []
            && $stats['cedulas_duplicadas_archivo'] === []
            && $stats['usuarios_sin_numfvd'] === []
            && $stats['sin_club_entidad'] === []
            && $stats['numfvd_duplicados_resueltos'] === []
            && $stats['numfvd_discrepancia'] === []
            && $stats['torneo_archivo_invalido'] === []
            && $stats['sexo_no_coincide_torneo'] === []
            && $stats['torneo_sin_sexo'] === []
            && $stats['errores_columnas'] === [];

        return $stats;
    }

    /**
     * @param list<list<string>> $rows
     * @param list<int>|null $numfvdExtra numfvd válidos del archivo parejas (aún no en BD)
     * @param list<list<string>>|null $parejasRows archivo parejas (campeonato por género)
     * @return array<string, mixed>
     */
    public static function analizarParti2017(PDO $pdo, int $torneoId, array $rows, ?array $numfvdExtra = null, ?array $parejasRows = null): array
    {
        $parsed = self::separarCabecera($rows, [['partida'], ['mesa'], ['secuencia', 'seq'], ['pareja', 'numfvd']]);
        $h = $parsed['header'];
        $iPareja = self::indiceColumna($h, ['pareja', 'numfvd', 'num_fvd']);
        $iPart = self::indiceColumna($h, ['partida', 'ronda']);
        $iMesa = self::indiceColumna($h, ['mesa']);
        $iSeq = self::indiceColumna($h, ['secuencia', 'seq']);
        $iTorneo = self::indiceColumnaTorneo($h);

        $stats = [
            'ok' => false,
            'campeonato_genero' => false,
            'filas_leidas' => 0,
            'numfvd_unicos' => 0,
            'numfvd_sin_inscrito' => [],
            'por_torneo' => [
                1 => ['filas' => 0, 'numfvd_unicos' => 0],
                2 => ['filas' => 0, 'numfvd_unicos' => 0],
            ],
            'errores_columnas' => [],
            'situaciones_detalle' => [],
        ];

        if ($iPareja < 0 || $iPart < 0 || $iMesa < 0 || $iSeq < 0) {
            if ($iPareja < 0) {
                $stats['errores_columnas'][] = 'Falta columna Pareja / numfvd.';
            }
            if ($iPart < 0) {
                $stats['errores_columnas'][] = 'Falta columna Partida.';
            }
            if ($iMesa < 0) {
                $stats['errores_columnas'][] = 'Falta columna Mesa.';
            }
            if ($iSeq < 0) {
                $stats['errores_columnas'][] = 'Falta columna Secuencia.';
            }
            $stats['situaciones_detalle'] = self::situacionesDesdeErroresColumnas(
                'parti2017',
                'parti2017 (resultados)',
                $stats['errores_columnas']
            );

            return $stats;
        }

        $mapa = CampeonatoTorneoHelper::mapaImportacionCampeonatoGenero($pdo, $torneoId);
        $mapaNfSlot = [];
        $extraPorSlot = [1 => [], 2 => []];
        if ($mapa !== null) {
            $stats['campeonato_genero'] = true;
            if ($parejasRows !== null) {
                $mapaNfSlot = self::mapaNumfvdSlotDesdeParejas($pdo, $parejasRows);
                $extraPorSlot = self::extraerNumfvdParejasPorSlot($pdo, $parejasRows);
            } elseif ($numfvdExtra !== null) {
                foreach ($numfvdExtra as $nf) {
                    $extraPorSlot[1][(int) $nf] = true;
                    $extraPorSlot[2][(int) $nf] = true;
                }
            }
            if ($iTorneo < 0 && $mapaNfSlot === []) {
                $stats['errores_columnas'][] = 'Campeonato por género: columna torneo en parti2017 o archivo parejas de referencia.';
                $stats['situaciones_detalle'] = self::situacionesDesdeErroresColumnas(
                    'parti2017',
                    'parti2017 (resultados)',
                    $stats['errores_columnas']
                );

                return $stats;
            }
            $inscritosPorSlot = [];
            foreach ([1, 2] as $slot) {
                $tid = CampeonatoTorneoHelper::torneoIdDesdeSlot($mapa['slots'], $slot);
                $inscritosPorSlot[$slot] = self::mapaInscritosNumfvd($pdo, $tid);
            }
        } else {
            $inscritos = self::mapaInscritosNumfvd($pdo, $torneoId);
            $extra = [];
            if ($numfvdExtra !== null) {
                foreach ($numfvdExtra as $nf) {
                    $extra[(int) $nf] = true;
                }
            }
        }

        $unicos = [];
        $unicosPorSlot = [1 => [], 2 => []];
        $situaciones = [];
        foreach ($parsed['data'] as $rowIdx => $row) {
            $filaArchivo = $parsed['header_row'] + 2 + (int) $rowIdx;
            $nf = (int) preg_replace('/\D/', '', (string) ($row[$iPareja] ?? ''));
            if ($nf <= 0) {
                continue;
            }
            $stats['filas_leidas']++;
            $unicos[$nf] = true;
            $partida = $iPart >= 0 ? trim((string) ($row[$iPart] ?? '')) : '';
            $mesa = $iMesa >= 0 ? trim((string) ($row[$iMesa] ?? '')) : '';

            if ($mapa !== null) {
                $slot = $iTorneo >= 0 ? self::slotDesdeFila($row, $iTorneo) : (int) ($mapaNfSlot[$nf] ?? 0);
                if ($slot !== 1 && $slot !== 2) {
                    $stats['numfvd_sin_inscrito'][] = (string) $nf . ' (torneo no identificado)';
                    $situaciones[] = self::armarSituacion('numfvd_sin_inscrito', [
                        'fila_archivo' => $filaArchivo,
                        'elemento' => 'numfvd=' . $nf,
                        'valor_archivo' => 'pareja/numfvd=' . $nf . ', partida=' . $partida . ', mesa=' . $mesa,
                        'valor_sistema' => 'sin sub-torneo (columna torneo inválida)',
                        'explicacion' => 'numfvd ' . $nf . ' en parti2017 fila ' . $filaArchivo . ': no se identifica torneo 1/2.',
                    ]);
                    continue;
                }
                $stats['por_torneo'][$slot]['filas']++;
                $unicosPorSlot[$slot][$nf] = true;
                $ins = $inscritosPorSlot[$slot];
                $extra = $extraPorSlot[$slot];
                if (!isset($ins[$nf]) && !isset($extra[$nf])) {
                    $stats['numfvd_sin_inscrito'][] = (string) $nf . ' (torneo ' . $slot . ')';
                    $situaciones[] = self::armarSituacion('numfvd_sin_inscrito', [
                        'fila_archivo' => $filaArchivo,
                        'torneo_slot' => $slot,
                        'elemento' => 'numfvd=' . $nf,
                        'valor_archivo' => 'pareja/numfvd=' . $nf . ', partida=' . $partida . ', mesa=' . $mesa,
                        'valor_sistema' => 'sin fila en inscritos (torneo ' . $slot . ')',
                        'explicacion' => 'numfvd ' . $nf . ' en parti2017 no está en parejas inscritas ni en inscritos del sub-torneo ' . $slot . '.',
                    ]);
                }
            } elseif (!isset($inscritos[$nf]) && !isset($extra[$nf])) {
                $stats['numfvd_sin_inscrito'][] = (string) $nf;
                $situaciones[] = self::armarSituacion('numfvd_sin_inscrito', [
                    'fila_archivo' => $filaArchivo,
                    'elemento' => 'numfvd=' . $nf,
                    'valor_archivo' => 'pareja/numfvd=' . $nf . ', partida=' . $partida . ', mesa=' . $mesa,
                    'valor_sistema' => 'sin fila en inscritos',
                    'explicacion' => 'numfvd ' . $nf . ' en parti2017 fila ' . $filaArchivo . ' no tiene inscrito en BD ni en parejas pendientes.',
                ]);
            }
        }

        $stats['numfvd_unicos'] = count($unicos);
        if ($mapa !== null) {
            $stats['por_torneo'][1]['numfvd_unicos'] = count($unicosPorSlot[1]);
            $stats['por_torneo'][2]['numfvd_unicos'] = count($unicosPorSlot[2]);
        }
        $stats['numfvd_sin_inscrito'] = array_values(array_unique($stats['numfvd_sin_inscrito']));
        $stats['situaciones_detalle'] = $situaciones;
        $stats['ok'] = $stats['filas_leidas'] > 0 && $stats['numfvd_sin_inscrito'] === [] && $stats['errores_columnas'] === [];

        return $stats;
    }

    /**
     * @param list<list<string>> $rows
     * @return array<string, mixed>
     */
    public static function analizarClasiequi(PDO $pdo, int $torneoId, array $rows, int $modalidad): array
    {
        $parsed = self::separarCabecera($rows, [['club', 'id_club'], ['nombre', 'nombre_equipo'], ['equipo', 'codigo_equipo']]);
        $h = $parsed['header'];
        $iClub = self::indiceColumna($h, ['club', 'id_club', 'asociacion']);
        $iNom = self::indiceColumna($h, ['nombre', 'nombre_equipo', 'nombre']);
        $iEq = self::indiceColumna($h, ['equipo', 'codigo_equipo', 'codequipo']);
        $iClave = self::indiceColumna($h, ['clave', 'consecutivo', 'consecutivo_club']);
        $iTorneo = self::indiceColumnaTorneo($h);

        $stats = [
            'ok' => false,
            'campeonato_genero' => false,
            'equipos_leidos' => 0,
            'por_asociacion' => [],
            'por_torneo' => [
                1 => ['equipos_leidos' => 0],
                2 => ['equipos_leidos' => 0],
            ],
            'errores_columnas' => [],
            'equipos_incompletos' => [],
        ];

        if ($iClub < 0 || $iNom < 0 || $iEq < 0) {
            $stats['errores_columnas'][] = 'Faltan columnas CLUB, NOMBRE o equipo/codigo_equipo.';
            $stats['situaciones_detalle'] = self::situacionesDesdeErroresColumnas(
                'clasiequi',
                'clasiequi (equipos)',
                $stats['errores_columnas']
            );

            return $stats;
        }

        $mapa = CampeonatoTorneoHelper::mapaImportacionCampeonatoGenero($pdo, $torneoId);
        if ($mapa !== null) {
            $stats['campeonato_genero'] = true;
            if ($iTorneo < 0) {
                $stats['errores_columnas'][] = 'Campeonato por género: columna torneo obligatoria en clasiequi (1 = hombres, 2 = mujeres).';
                $stats['situaciones_detalle'] = self::situacionesDesdeErroresColumnas(
                    'clasiequi',
                    'clasiequi (equipos)',
                    $stats['errores_columnas']
                );

                return $stats;
            }
        }

        $jugadoresReq = self::jugadoresPorUnidad($modalidad);

        foreach ($parsed['data'] as $row) {
            $club = (int) preg_replace('/\D/', '', (string) ($row[$iClub] ?? ''));
            $codEq = self::normalizarCodigoEquipo(trim((string) ($row[$iEq] ?? '')));
            $nombre = trim((string) ($row[$iNom] ?? ''));
            if ($club <= 0 && $codEq === '' && $nombre === '') {
                continue;
            }

            $slot = 0;
            $tidAnalisis = $torneoId;
            if ($mapa !== null) {
                $slot = self::slotDesdeFila($row, $iTorneo);
                if ($slot !== 1 && $slot !== 2) {
                    continue;
                }
                $tidAnalisis = CampeonatoTorneoHelper::torneoIdDesdeSlot($mapa['slots'], $slot);
                $stats['por_torneo'][$slot]['equipos_leidos']++;
            }

            $stats['equipos_leidos']++;
            $etiq = $club > 0 ? EntidadFvdCatalogo::etiqueta($club) : '—';
            $stats['por_asociacion'][$etiq] = ($stats['por_asociacion'][$etiq] ?? 0) + 1;

            if ($codEq !== '' && $jugadoresReq > 1) {
                $conteoInscritos = self::conteoJugadoresPorCodigoEquipo($pdo, $tidAnalisis);
                $n = (int) ($conteoInscritos[$codEq] ?? 0);
                if ($n > 0 && $n !== $jugadoresReq) {
                    $pref = $mapa !== null ? 'T' . $slot . ' ' : '';
                    $stats['equipos_incompletos'][] = $pref . $codEq . ' (' . $n . '/' . $jugadoresReq . ')';
                }
            }
        }

        $stats['equipos_incompletos'] = array_values(array_unique($stats['equipos_incompletos']));
        $stats['ok'] = $stats['equipos_leidos'] > 0 && $stats['errores_columnas'] === [];

        return $stats;
    }

    /**
     * Verifica integridad de equipos vs jugadores requeridos (inscritos + archivo parejas).
     *
     * @param list<list<string>> $parejasRows
     * @return array<string, mixed>
     */
    public static function analizarIntegridadEquipos(PDO $pdo, int $torneoId, int $modalidad, array $parejasRows, array $clasiequiRows): array
    {
        $jugadoresReq = self::jugadoresPorUnidad($modalidad);
        if ($jugadoresReq <= 1) {
            return ['ok' => true, 'equipos_incompletos' => [], 'mensaje' => 'Torneo individual: no aplica verificación de equipos.'];
        }

        $mapa = CampeonatoTorneoHelper::mapaImportacionCampeonatoGenero($pdo, $torneoId);
        if ($mapa !== null) {
            $incompletos = [];
            $porTorneo = [];
            foreach ([1, 2] as $slot) {
                $tid = CampeonatoTorneoHelper::torneoIdDesdeSlot($mapa['slots'], $slot);
                $r = self::analizarIntegridadEquiposUnTorneo(
                    $pdo,
                    $tid,
                    $modalidad,
                    $parejasRows,
                    $clasiequiRows,
                    $slot
                );
                $porTorneo[$slot] = $r;
                if (!$r['ok']) {
                    foreach ($r['equipos_incompletos_detalle'] ?? [] as $det) {
                        $incompletos[] = self::formatearEquipoIncompletoTexto($det, $slot, $mapa);
                    }
                }
            }

            return [
                'ok' => $incompletos === [],
                'campeonato_genero' => true,
                'por_torneo' => $porTorneo,
                'jugadores_requeridos' => $jugadoresReq,
                'equipos_incompletos' => $incompletos,
                'equipos_incompletos_detalle' => self::fusionarDetalleEquiposIncompletos($porTorneo),
                'leyenda_integridad' => self::leyendaIntegridadEquipos($jugadoresReq),
                'reporte_banca' => self::fusionarReporteBancaPorTorneo($porTorneo),
            ];
        }

        $r = self::analizarIntegridadEquiposUnTorneo($pdo, $torneoId, $modalidad, $parejasRows, $clasiequiRows, null);
        $r['leyenda_integridad'] = self::leyendaIntegridadEquipos($jugadoresReq);
        if (!empty($r['equipos_incompletos_detalle'])) {
            $r['equipos_incompletos'] = array_map(
                static fn (array $d): string => self::formatearEquipoIncompletoTexto($d, null, null),
                $r['equipos_incompletos_detalle']
            );
        }

        return $r;
    }

    /**
     * @param list<list<string>> $parejasRows
     * @param list<list<string>> $clasiequiRows
     * @return array<string, mixed>
     */
    private static function analizarIntegridadEquiposUnTorneo(
        PDO $pdo,
        int $torneoId,
        int $modalidad,
        array $parejasRows,
        array $clasiequiRows,
        ?int $soloSlot
    ): array {
        $jugadoresReq = self::jugadoresPorUnidad($modalidad);

        // 1) Parejas inscritas → mapa ordenado por código de equipo (fuente de verdad para plantilla).
        $codigosClasiequi = self::codigosEquipoDesdeClasiequi($clasiequiRows, $soloSlot);
        $plantillaResult = self::mapaPlantillaDesdeParejas($pdo, $parejasRows, $jugadoresReq, $soloSlot, $codigosClasiequi);
        $plantilla = $plantillaResult['plantilla'];
        $reporteBanca = $plantillaResult['reporte_banca'];
        $resumenParejas = self::resumenParejasPorEquipo($plantilla, $jugadoresReq, $codigosClasiequi);

        // 2) clasiequi → verificar que cada equipo declarado tenga la cantidad exacta en parejas.
        $metaEquipo = self::mapaMetaClasiequi($pdo, $clasiequiRows, $soloSlot);

        $parsedC = self::separarCabecera($clasiequiRows, [['equipo', 'codigo_equipo']]);
        $hC = $parsedC['header'];
        $iEqC = self::indiceColumna($hC, ['equipo', 'codigo_equipo', 'codequipo']);
        $iTorneoC = self::indiceColumnaTorneo($hC);
        $incompletos = [];
        $incompletosDetalle = [];
        foreach ($parsedC['data'] as $row) {
            if ($soloSlot !== null) {
                if ($iTorneoC < 0 || self::slotDesdeFila($row, $iTorneoC) !== $soloSlot) {
                    continue;
                }
            }
            $cod = self::normalizarCodigoEquipo($iEqC >= 0 ? trim((string) ($row[$iEqC] ?? '')) : '');
            if ($cod === '') {
                continue;
            }
            $info = $plantilla[$cod] ?? ['titulares' => 0, 'total' => 0, 'jugadores' => []];
            $titulares = (int) ($info['titulares'] ?? 0);
            $total = (int) ($info['total'] ?? 0);
            if ($total < $jugadoresReq || $titulares < $jugadoresReq) {
                $meta = $metaEquipo[$cod] ?? [];
                $det = self::armarDetalleEquipoIncompleto(
                    $cod,
                    $titulares,
                    $total,
                    $jugadoresReq,
                    $meta,
                    $info['jugadores'] ?? [],
                    $soloSlot
                );
                $incompletos[] = $cod . ' → parejas ' . $total . '/' . $jugadoresReq
                    . ' (titulares ' . $titulares . '/' . $jugadoresReq . ')';
                $incompletosDetalle[] = $det;
            }
        }

        return [
            'ok' => $incompletos === [],
            'torneo_id' => $torneoId,
            'slot' => $soloSlot,
            'jugadores_requeridos' => $jugadoresReq,
            'resumen_parejas_por_equipo' => $resumenParejas,
            'equipos_incompletos' => $incompletos,
            'equipos_incompletos_detalle' => $incompletosDetalle,
            'situaciones_detalle' => array_merge($incompletosDetalle, self::situacionesDesdeReporteBanca($reporteBanca)),
            'reporte_banca' => $reporteBanca,
        ];
    }

    /**
     * @param list<list<string>> $parejasRows
     * @param list<list<string>> $partiRows
     * @param list<list<string>>|null $clasiequiRows
     * @return array<string, mixed>
     */
    public static function ejecutarImportacion(
        PDO $pdo,
        int $torneoId,
        int $registradoPor,
        array $parejasRows,
        array $partiRows,
        ?array $clasiequiRows,
        int $modalidad,
        bool $reemplazarPartiresul,
        bool $reemplazarInscripcion = true
    ): array {
        $fechaTorneo = self::fechaTorneo($pdo, $torneoId);
        $mapa = CampeonatoTorneoHelper::mapaImportacionCampeonatoGenero($pdo, $torneoId);

        $cedulasImport = self::extraerCedulasParejas($parejasRows);
        $syncAtletas = self::sincronizarAtletasParaImportacion($pdo, $cedulasImport, true);
        if (!$syncAtletas['ok']) {
            return [
                'ok' => false,
                'error' => 'Sincronización atletas → usuarios incompleta. Revise el paso 0 antes de importar.',
                'sync_atletas' => $syncAtletas,
            ];
        }

        $analisisP = self::analizarParejasInscritas($pdo, $torneoId, $parejasRows);
        $numfvdPendientes = self::extraerNumfvdParejas($pdo, $parejasRows);
        $analisisR = self::analizarParti2017($pdo, $torneoId, $partiRows, $numfvdPendientes, $parejasRows);

        if (!$analisisP['ok'] || !$analisisR['ok']) {
            return [
                'ok' => false,
                'error' => 'Las verificaciones no pasaron. Revise parejas y parti2017 antes de importar.',
                'parejas' => $analisisP,
                'parti' => $analisisR,
            ];
        }

        if (self::requiereClasiequi($modalidad)) {
            if ($clasiequiRows === null || $clasiequiRows === []) {
                return ['ok' => false, 'error' => 'Este torneo requiere archivo clasiequi.'];
            }
            $analisisC = self::analizarClasiequi($pdo, $torneoId, $clasiequiRows, $modalidad);
            $analisisE = self::analizarIntegridadEquipos($pdo, $torneoId, $modalidad, $parejasRows, $clasiequiRows);
            if (!$analisisC['ok'] || !$analisisE['ok']) {
                return [
                    'ok' => false,
                    'error' => 'Verificación de equipos fallida.',
                    'clasiequi' => $analisisC,
                    'integridad' => $analisisE,
                ];
            }
        }

        $res = [
            'ok' => true,
            'campeonato_genero' => $mapa !== null,
            'sync_atletas' => $syncAtletas,
            'inscritos_insertados' => 0,
            'inscritos_actualizados' => 0,
            'inscritos_omitidos' => 0,
            'equipos_insertados' => 0,
            'equipos_actualizados' => 0,
            'equipos_asegurados_parejas' => 0,
            'numeros_sincronizados' => 0,
            'partiresul_insertados' => 0,
            'partiresul_omitidos' => 0,
            'partiresul_reemplazados' => 0,
            'inscritos_banca' => 0,
            'equipos_omitidos' => 0,
            'incidencias_ejecucion' => [],
            'reporte_banca' => ['total' => 0, 'por_asociacion' => [], 'detalle' => []],
            'por_torneo' => [],
        ];

        $torneoIds = $mapa !== null ? array_map('intval', $mapa['torneo_ids']) : [$torneoId];

        $pdo->beginTransaction();
        try {
            if ($reemplazarInscripcion) {
                self::limpiarInscripcionTorneos($pdo, $torneoIds);
            }

            $procesarTorneo = static function (int $tid, array $filasParejas, ?array $filasClas, ?int $slot) use (
                $pdo,
                $modalidad,
                $registradoPor,
                &$res
            ): void {
                $jugadoresReq = self::jugadoresPorUnidad($modalidad);

                if (self::requiereClasiequi($modalidad) && $filasClas !== null && $filasClas !== []) {
                    $eq = self::importarClasiequi($pdo, $tid, $filasClas, $registradoPor, $res['incidencias_ejecucion']);
                    $res['equipos_insertados'] += $eq['insertados'];
                    $res['equipos_actualizados'] += $eq['actualizados'];
                    $res['equipos_omitidos'] += $eq['omitidos'];
                }

                if (self::requiereClasiequi($modalidad) && $filasParejas !== []) {
                    $codigosClasiequi = ($filasClas !== null && $filasClas !== [])
                        ? self::codigosEquipoDesdeClasiequi($filasClas, $slot)
                        : [];
                    $plantillaResult = self::mapaPlantillaDesdeParejas(
                        $pdo,
                        $filasParejas,
                        $jugadoresReq,
                        $slot,
                        $codigosClasiequi
                    );
                    $metaEquipo = ($filasClas !== null && $filasClas !== [])
                        ? self::mapaMetaClasiequi($pdo, $filasClas, $slot)
                        : [];
                    $res['equipos_asegurados_parejas'] += self::asegurarEquiposFaltantesPlantilla(
                        $pdo,
                        $tid,
                        $plantillaResult['plantilla'],
                        $metaEquipo,
                        $registradoPor
                    );
                }

                $ins = self::importarParejasInscritas(
                    $pdo,
                    $tid,
                    $filasParejas,
                    $modalidad,
                    $registradoPor,
                    $filasClas,
                    $slot,
                    $res['incidencias_ejecucion']
                );
                $res['inscritos_insertados'] += $ins['insertados'];
                $res['inscritos_actualizados'] += $ins['actualizados'];
                $res['inscritos_omitidos'] += $ins['omitidos'];
                $res['inscritos_banca'] += $ins['banca'];
                $res['numeros_sincronizados'] += self::sincronizarNumerosInscripcionTorneo($pdo, $tid);
                if ($slot !== null) {
                    $res['por_torneo'][$slot] = $ins;
                }
                foreach ($ins['reporte_banca']['detalle'] ?? [] as $d) {
                    $res['reporte_banca']['detalle'][] = $d;
                }
            };

            if ($mapa !== null) {
                foreach ([1, 2] as $slot) {
                    $tid = CampeonatoTorneoHelper::torneoIdDesdeSlot($mapa['slots'], $slot);
                    $filasP = self::filtrarArchivoPorSlot($parejasRows, $slot);
                    $filasC = ($clasiequiRows !== null && $clasiequiRows !== [] && self::requiereClasiequi($modalidad))
                        ? self::filtrarArchivoPorSlot($clasiequiRows, $slot)
                        : null;
                    $procesarTorneo($tid, $filasP, $filasC, $slot);
                }
            } else {
                $procesarTorneo(
                    $torneoId,
                    $parejasRows,
                    (self::requiereClasiequi($modalidad) ? $clasiequiRows : null),
                    null
                );
            }

            foreach ($res['reporte_banca']['detalle'] ?? [] as $d) {
                $key = (string) ((int) ($d['asociacion_codigo'] ?? 0) ?: '_sin_asoc');
                if (!isset($res['reporte_banca']['por_asociacion'][$key])) {
                    $res['reporte_banca']['por_asociacion'][$key] = [
                        'asociacion_codigo' => (int) ($d['asociacion_codigo'] ?? 0),
                        'asociacion' => (string) ($d['asociacion'] ?? '—'),
                        'sin_clasiequi' => 0,
                        'exceso_plantilla' => 0,
                    ];
                }
                if (($d['motivo'] ?? '') === 'sin_clasiequi') {
                    $res['reporte_banca']['por_asociacion'][$key]['sin_clasiequi']++;
                } else {
                    $res['reporte_banca']['por_asociacion'][$key]['exceso_plantilla']++;
                }
            }
            $res['reporte_banca']['total'] = count($res['reporte_banca']['detalle'] ?? []);
            $res['reporte_banca']['situaciones_detalle'] = self::situacionesDesdeReporteBanca($res['reporte_banca']);

            if ($reemplazarPartiresul) {
                $stDel = $pdo->prepare('DELETE FROM partiresul WHERE id_torneo = ?');
                foreach ($torneoIds as $tidB) {
                    $stDel->execute([(int) $tidB]);
                    $res['partiresul_reemplazados'] += $stDel->rowCount();
                }
            }

            if ($mapa !== null) {
                foreach ([1, 2] as $slot) {
                    $tid = CampeonatoTorneoHelper::torneoIdDesdeSlot($mapa['slots'], $slot);
                    $filas = self::filtrarArchivoPorSlot($partiRows, $slot);
                    $fecha = self::fechaTorneo($pdo, $tid) ?: $fechaTorneo;
                    $partiRes = self::importarParti2017(
                        $pdo,
                        $tid,
                        $filas,
                        $registradoPor,
                        $fecha,
                        $res['incidencias_ejecucion']
                    );
                    $res['partiresul_insertados'] += $partiRes['insertados'];
                    $res['partiresul_omitidos'] += $partiRes['omitidos'];
                }
            } else {
                $partiRes = self::importarParti2017(
                    $pdo,
                    $torneoId,
                    $partiRows,
                    $registradoPor,
                    $fechaTorneo,
                    $res['incidencias_ejecucion']
                );
                $res['partiresul_insertados'] = $partiRes['insertados'];
                $res['partiresul_omitidos'] = $partiRes['omitidos'];
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();

            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'situaciones_detalle' => [
                    self::situacionImportacion('ejec_error_sql', [
                        'elemento' => $e->getMessage(),
                        'explicacion' => 'La importación se revirtió por error: ' . $e->getMessage(),
                    ]),
                ],
            ];
        }

        return self::finalizarResultadoEjecucion($res, $parejasRows);
    }

    /**
     * numfvd pendientes de inscripción (resueltos por cédula → usuarios).
     *
     * @param list<list<string>> $rows
     * @return list<int>
     */
    public static function extraerNumfvdParejas(PDO $pdo, array $rows): array
    {
        $parsed = self::separarCabecera($rows, [['cedula', 'ced']]);
        $h = $parsed['header'];
        $iCed = self::indiceColumna($h, ['cedula', 'ced', 'documento']);
        $iNum = self::indiceColumna($h, ['numfvd', 'num_fvd', 'carnet', 'pareja']);
        $out = [];
        if ($iCed < 0) {
            return $out;
        }
        foreach ($parsed['data'] as $row) {
            $ced = self::normalizarCedula((string) ($row[$iCed] ?? ''));
            if ($ced === '') {
                continue;
            }

            $nf = $iNum >= 0 ? (int) preg_replace('/\D/', '', (string) ($row[$iNum] ?? '')) : 0;
            if ($nf <= 0) {
                $usuario = self::resolverUsuarioPorCedula($pdo, $ced);
                if ($usuario === null) {
                    continue;
                }
                $nf = self::resolverNumfvdUsuario($pdo, $usuario, $ced);
            }
            if ($nf > 0) {
                $out[] = $nf;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param list<list<string>> $rows
     * @return list<string>
     */
    public static function extraerCedulasParejas(array $rows): array
    {
        $parsed = self::separarCabecera($rows, [['cedula', 'ced']]);
        $h = $parsed['header'];
        $iCed = self::indiceColumna($h, ['cedula', 'ced', 'documento']);
        $out = [];
        if ($iCed < 0) {
            return $out;
        }
        foreach ($parsed['data'] as $row) {
            $ced = self::normalizarCedula((string) ($row[$iCed] ?? ''));
            if ($ced !== '') {
                $out[] = $ced;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param list<string> $cedulas
     * @return array<string, mixed>
     */
    public static function sincronizarAtletasParaImportacion(PDO $pdo, array $cedulas, bool $ejecutar = false): array
    {
        $reporte = AtletasAdminSyncService::prepararUsuariosParaImportacion($pdo, $cedulas, $ejecutar);

        return self::enriquecerReportePadron($reporte, 'parejas_inscritas');
    }

    /** Sincroniza todo el padrón atletas → usuarios (crear faltantes + actualizar existentes). */
    public static function sincronizarPadronCompletoAtletas(PDO $pdo, bool $ejecutar = false): array
    {
        $reporte = AtletasAdminSyncService::sincronizarPadronCompletoAtletasUsuarios($pdo, $ejecutar);

        return self::enriquecerReportePadron($reporte, 'padron_atletas');
    }

    /**
     * Agrega situaciones_detalle con origen y corrección lógica por incidencia del Paso 0.
     *
     * @param array<string, mixed> $reporte
     * @return array<string, mixed>
     */
    private static function enriquecerReportePadron(array $reporte, string $contextoOrigen = 'padron_atletas'): array
    {
        $situaciones = $reporte['situaciones_detalle'] ?? [];

        foreach ($reporte['sin_atleta'] ?? [] as $ced) {
            $situaciones[] = self::situacionImportacion('atleta_sin_registro', [
                'origen_archivo' => $contextoOrigen === 'parejas_inscritas' ? 'parejas_inscritas' : 'padron_atletas',
                'origen_tabla_access' => $contextoOrigen === 'parejas_inscritas'
                    ? 'Parejas inscritas (archivo torneo)'
                    : 'Parejas inscritas → atletas',
                'elemento' => 'cedula=' . (string) $ced,
                'valor_archivo' => (string) $ced,
                'valor_sistema' => '(no existe en atletas)',
                'explicacion' => 'Cédula ' . (string) $ced . ' aparece en el torneo pero no está en la tabla atletas.',
            ]);
        }

        foreach ($reporte['detalle_pendientes'] ?? [] as $d) {
            $situaciones = array_merge($situaciones, self::situacionesDesdePendientePadron($d));
        }

        if (($reporte['sin_cedula_valida'] ?? 0) > 0) {
            $n = (int) $reporte['sin_cedula_valida'];
            $situaciones[] = self::situacionImportacion('padron_atleta_sin_cedula', [
                'elemento' => 'atletas.cedula (' . $n . ' filas)',
                'explicacion' => $n . ' atleta(s) en el padrón sin cédula válida; no se pueden vincular a usuarios.',
            ]);
        }

        if (!$reporte['tabla_atletas']) {
            $situaciones[] = self::situacionImportacion('atleta_sin_registro', [
                'elemento' => 'tabla atletas',
                'explicacion' => 'No existe la tabla atletas en la base de datos.',
                'como_resolver' => 'Importe o sincronice el padrón FVD (atletas) antes del Paso 0.',
            ]);
        }

        foreach ($reporte['errores'] ?? [] as $err) {
            $situaciones[] = self::situacionImportacion('atleta_sin_registro', [
                'elemento' => (string) $err,
                'explicacion' => (string) $err,
                'como_resolver' => 'Revise el padrón atletas/usuarios y vuelva a verificar.',
            ]);
        }

        $reporte['situaciones_detalle'] = $situaciones;

        return $reporte;
    }

    /**
     * @param array<string, mixed> $d
     * @return list<array<string, mixed>>
     */
    private static function situacionesDesdePendientePadron(array $d): array
    {
        $out = [];
        $ced = (string) ($d['cedula'] ?? '');
        $nombre = trim((string) ($d['nombre'] ?? ''));
        $accion = (string) ($d['accion'] ?? '');
        $nomTxt = $nombre !== '' ? ' (' . $nombre . ')' : '';

        if ($accion === 'crear') {
            $nf = (int) ($d['numfvd_atleta'] ?? 0);
            $out[] = self::situacionImportacion('padron_usuario_faltante', [
                'cedula' => $ced,
                'nombre' => $nombre,
                'elemento' => 'cedula=' . $ced,
                'valor_archivo' => 'atletas: cédula=' . $ced . ($nf > 0 ? ', numfvd=' . $nf : ''),
                'valor_sistema' => '(sin fila en usuarios)',
                'explicacion' => 'Atleta' . $nomTxt . ' cédula ' . $ced . ' está en atletas pero no tiene cuenta en usuarios.',
            ]);

            return $out;
        }

        if (isset($d['numfvd']) && is_array($d['numfvd'])) {
            $antes = (int) ($d['numfvd']['antes'] ?? 0);
            $despues = (int) ($d['numfvd']['despues'] ?? 0);
            $out[] = self::situacionImportacion('padron_numfvd_desalineado', [
                'cedula' => $ced,
                'nombre' => $nombre,
                'elemento' => 'cedula=' . $ced . ', numfvd',
                'valor_sistema' => 'usuarios.numfvd=' . $antes,
                'valor_archivo' => 'atletas.numfvd=' . $despues,
                'explicacion' => 'Cédula ' . $ced . $nomTxt . ': carnet en usuarios (' . $antes . ') distinto al padrón atletas (' . $despues . ').',
            ]);
        }

        if (isset($d['sexo']) && is_array($d['sexo'])) {
            $out[] = self::situacionImportacion('padron_sexo_desalineado', [
                'cedula' => $ced,
                'nombre' => $nombre,
                'elemento' => 'cedula=' . $ced . ', sexo',
                'valor_sistema' => 'usuarios.sexo=' . (string) ($d['sexo']['antes'] ?? ''),
                'valor_archivo' => 'atletas.sexo=' . (string) ($d['sexo']['despues'] ?? ''),
                'explicacion' => 'Cédula ' . $ced . $nomTxt . ': sexo en usuarios ≠ sexo en atletas.',
            ]);
        }

        if (isset($d['entidad']) && is_array($d['entidad'])) {
            $out[] = self::situacionImportacion('padron_entidad_desalineada', [
                'cedula' => $ced,
                'nombre' => $nombre,
                'elemento' => 'cedula=' . $ced . ', entidad/asociación',
                'valor_sistema' => 'usuarios.entidad=' . (string) ($d['entidad']['antes'] ?? ''),
                'valor_archivo' => 'atletas.asociacion=' . (string) ($d['entidad']['despues'] ?? ''),
                'explicacion' => 'Cédula ' . $ced . $nomTxt . ': asociación en usuarios ≠ asociación en atletas.',
            ]);
        }

        return $out;
    }

    /**
     * @param list<list<string>> $rows
     * @param list<list<string>>|null $clasiequiRows
     * @return array{insertados: int, actualizados: int, omitidos: int, banca: int, reporte_banca: array<string, mixed>}
     */
    private static function importarParejasInscritas(
        PDO $pdo,
        int $torneoId,
        array $rows,
        int $modalidad,
        int $inscritoPor,
        ?array $clasiequiRows = null,
        ?int $soloSlot = null,
        ?array &$incidenciasEjecucion = null
    ): array {
        $parsed = self::separarCabecera($rows, [['cedula', 'ced']]);
        $h = $parsed['header'];
        $iCed = self::indiceColumna($h, ['cedula', 'ced', 'documento']);
        $iNumfvd = self::indiceColumna($h, ['numfvd', 'num_fvd', 'carnet', 'pareja']);
        $iAsoc = self::indiceColumna($h, ['asociacion', 'entidad']);
        $iEqN = self::indiceColumna($h, ['equipo', 'numero_equipo']);
        $iCod = self::indiceColumna($h, ['codigo_equipo', 'codequipo']);
        $iActivo = self::indiceColumnaActivoMesa($h);
        $jugadoresReq = self::jugadoresPorUnidad($modalidad);
        $titularesAuto = [];
        $codigosClasiequi = ($clasiequiRows !== null && $clasiequiRows !== [])
            ? self::codigosEquipoDesdeClasiequi($clasiequiRows, $soloSlot)
            : null;
        $reporteBanca = ['total' => 0, 'por_asociacion' => [], 'detalle' => []];

        $filasPrep = [];
        foreach ($parsed['data'] as $rowIdx => $row) {
            $ced = self::normalizarCedula($iCed >= 0 ? ($row[$iCed] ?? '') : '');
            if ($ced === '') {
                continue;
            }
            $codEq = '000-000';
            if ($modalidad !== 1) {
                $codEq = self::codigoEquipoDesdeFilaPareja($row, $iCod, $iAsoc, $iEqN);
                if ($codEq === '') {
                    $codEq = '000-001';
                }
            }
            $filasPrep[] = [
                'row' => $row,
                'ced' => $ced,
                'cod' => $codEq,
                'fila' => $parsed['header_row'] + 2 + $rowIdx,
            ];
        }
        usort($filasPrep, static function (array $a, array $b): int {
            $cmp = strcmp($a['cod'], $b['cod']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp($a['ced'], $b['ced']);
        });

        $insertados = 0;
        $actualizados = 0;
        $omitidos = 0;
        $banca = 0;

        foreach ($filasPrep as $item) {
            $row = $item['row'];
            $ced = $item['ced'];
            $codEq = $item['cod'];
            $filaArchivo = (int) ($item['fila'] ?? 0);

            $usuario = self::resolverUsuarioPorCedula($pdo, $ced);
            if ($usuario === null) {
                if ($incidenciasEjecucion !== null) {
                    self::registrarIncidenciaEjecucion($incidenciasEjecucion, 'cedula_sin_usuario', [
                        'cedula' => $ced,
                        'elemento' => $ced,
                        'fila_archivo' => $filaArchivo,
                        'codigo_equipo' => $modalidad !== 1 ? $codEq : null,
                        'torneo_id' => $torneoId,
                        'explicacion' => 'No hay usuario con esta cédula. El jugador no fue inscrito al ejecutar la importación.',
                    ]);
                }
                $omitidos++;
                continue;
            }
            $idUsr = (int) $usuario['id'];
            $stDup = $pdo->prepare(
                'SELECT id FROM inscritos WHERE torneo_id = ? AND id_usuario = ? LIMIT 1'
            );
            $stDup->execute([$torneoId, $idUsr]);
            $idInscritoExistente = (int) ($stDup->fetchColumn() ?: 0);

            $nfUsuario = self::resolverNumfvdUsuario($pdo, $usuario, $ced);
            $numfvdArch = $iNumfvd >= 0 ? (int) preg_replace('/\D/', '', (string) ($row[$iNumfvd] ?? '')) : 0;
            $numfvd = $numfvdArch > 0 ? $numfvdArch : $nfUsuario;
            if ($numfvd <= 0) {
                if ($incidenciasEjecucion !== null) {
                    self::registrarIncidenciaEjecucion($incidenciasEjecucion, 'sin_numfvd', [
                        'cedula' => $ced,
                        'nombre' => trim((string) ($usuario['nombre'] ?? '')),
                        'elemento' => $ced,
                        'fila_archivo' => $filaArchivo,
                        'codigo_equipo' => $modalidad !== 1 ? $codEq : null,
                        'torneo_id' => $torneoId,
                        'explicacion' => 'Sin numfvd en archivo ni en usuario. El jugador no fue inscrito.',
                    ]);
                }
                $omitidos++;
                continue;
            }

            $idClub = self::clubDesdeEntidadUsuario($pdo, $usuario);
            $entidadUsu = (int) ($usuario['entidad'] ?? 0);
            if ($idClub === null || $idClub <= 0) {
                if ($incidenciasEjecucion !== null) {
                    self::registrarIncidenciaEjecucion($incidenciasEjecucion, 'sin_club', [
                        'cedula' => $ced,
                        'nombre' => trim((string) ($usuario['nombre'] ?? '')),
                        'elemento' => $ced,
                        'fila_archivo' => $filaArchivo,
                        'entidad' => $entidadUsu,
                        'codigo_equipo' => $modalidad !== 1 ? $codEq : null,
                        'torneo_id' => $torneoId,
                        'explicacion' => 'Usuario sin club resoluble (entidad ' . ($entidadUsu > 0 ? $entidadUsu : '—') . '). El jugador no fue inscrito.',
                    ]);
                }
                $omitidos++;
                continue;
            }

            $activoMesa = InscritosHelper::ACTIVO_MESA_SI;
            if ($modalidad !== 1 && $codEq !== '000-000') {
                $incidencia = null;
                $asocCod = $iAsoc >= 0 ? (int) preg_replace('/\D/', '', (string) ($row[$iAsoc] ?? '')) : 0;
                if ($asocCod <= 0 && preg_match('/^(\d{1,3})-/', $codEq, $mAsoc)) {
                    $asocCod = (int) $mAsoc[1];
                }
                if ($asocCod <= 0) {
                    $asocCod = $entidadUsu;
                }
                $activoMesa = self::resolverActivoMesaFilaPareja(
                    $row,
                    $iActivo,
                    $codEq,
                    $jugadoresReq,
                    $titularesAuto,
                    self::equipoEnClasiequi($codigosClasiequi, $codEq),
                    $incidencia
                );
                if ($incidencia !== null) {
                    self::acumularReporteBanca(
                        $reporteBanca,
                        $incidencia,
                        $ced,
                        trim((string) ($usuario['nombre'] ?? '')),
                        $asocCod,
                        $soloSlot
                    );
                }
            }
            if ($activoMesa === InscritosHelper::ACTIVO_MESA_BANCA) {
                $banca++;
            }

            $datosIns = [
                'id_usuario' => $idUsr,
                'torneo_id' => $torneoId,
                'id_club' => $idClub,
                'estatus' => InscritosHelper::ESTATUS_CONFIRMADO_NUM,
                'inscrito_por' => $inscritoPor,
                'codigo_equipo' => $codEq,
                'cedula' => preg_replace('/\D/', '', (string) ($usuario['cedula'] ?? $ced)),
                'nacionalidad' => $usuario['nacionalidad'] ?? 'V',
                'numfvd' => $numfvd,
                'numero' => $numfvd > 0 ? $numfvd : 0,
                'activo_mesa' => $activoMesa,
            ];
            if ($entidadUsu > 0) {
                $datosIns['entidad_id'] = $entidadUsu;
            }
            if ($idInscritoExistente > 0) {
                self::actualizarInscritoDesdeImportacion($pdo, $idInscritoExistente, $datosIns);
                $actualizados++;
            } else {
                InscritosHelper::insertarInscrito($pdo, $datosIns);
                $insertados++;
            }
        }

        $reporteBanca['situaciones_detalle'] = self::situacionesDesdeReporteBanca($reporteBanca);

        return [
            'insertados' => $insertados,
            'actualizados' => $actualizados,
            'omitidos' => $omitidos,
            'banca' => $banca,
            'reporte_banca' => $reporteBanca,
        ];
    }

    /**
     * @param array<string, mixed> $datosIns
     */
    private static function actualizarInscritoDesdeImportacion(PDO $pdo, int $inscritoId, array $datosIns): void
    {
        require_once __DIR__ . '/InscritosHelper.php';
        $cols = $pdo->query('SHOW COLUMNS FROM inscritos')->fetchAll(PDO::FETCH_COLUMN);
        $have = [];
        foreach ($cols as $c) {
            $have[strtolower((string) $c)] = true;
        }
        $sets = [];
        $params = [];
        $add = static function (string $col, $val) use (&$sets, &$params, $have): void {
            if (!isset($have[strtolower($col)])) {
                return;
            }
            $sets[] = $col . ' = ?';
            $params[] = $val;
        };
        $add('id_club', (int) ($datosIns['id_club'] ?? 0));
        $add('codigo_equipo', (string) ($datosIns['codigo_equipo'] ?? ''));
        $add('estatus', (int) ($datosIns['estatus'] ?? InscritosHelper::ESTATUS_CONFIRMADO_NUM));
        $numfvd = (int) ($datosIns['numfvd'] ?? 0);
        $add('numfvd', $numfvd);
        $add('numero', $numfvd > 0 ? $numfvd : (int) ($datosIns['numero'] ?? 0));
        $add('cedula', (string) ($datosIns['cedula'] ?? ''));
        $add('nacionalidad', (string) ($datosIns['nacionalidad'] ?? 'V'));
        if (array_key_exists('activo_mesa', $datosIns)) {
            $am = (int) $datosIns['activo_mesa'] === InscritosHelper::ACTIVO_MESA_BANCA ? 0 : 1;
            $add('activo_mesa', $am);
        }
        if (isset($datosIns['entidad_id'])) {
            $add('entidad_id', (int) $datosIns['entidad_id']);
        }
        if ($sets === []) {
            return;
        }
        $params[] = $inscritoId;
        $pdo->prepare('UPDATE inscritos SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }

    /**
     * @param list<list<string>> $rows
     */
    private static function contarFilasParejasValidas(array $rows): int
    {
        $parsed = self::separarCabecera($rows, [['cedula', 'ced']]);
        $iCed = self::indiceColumna($parsed['header'], ['cedula', 'ced', 'documento']);
        $n = 0;
        foreach ($parsed['data'] as $row) {
            if (self::normalizarCedula($iCed >= 0 ? ($row[$iCed] ?? '') : '') !== '') {
                $n++;
            }
        }

        return $n;
    }

    /** @param list<int> $torneoIds */
    private static function limpiarInscripcionTorneos(PDO $pdo, array $torneoIds): void
    {
        $stI = $pdo->prepare('DELETE FROM inscritos WHERE torneo_id = ?');
        $stE = $pdo->prepare('DELETE FROM equipos WHERE id_torneo = ?');
        foreach ($torneoIds as $tid) {
            $tid = (int) $tid;
            if ($tid <= 0) {
                continue;
            }
            $stI->execute([$tid]);
            $stE->execute([$tid]);
        }
    }

    private static function resolverIdClubColumnaAccess(PDO $pdo, int $codigoColumna): ?int
    {
        if ($codigoColumna <= 0) {
            return null;
        }
        require_once __DIR__ . '/AsociacionAdminHelper.php';
        $clubId = AsociacionAdminHelper::resolverClubIdDesdeEntidad($pdo, $codigoColumna);

        return $clubId !== null && (int) $clubId > 0 ? (int) $clubId : $codigoColumna;
    }

    /**
     * Alinea inscritos.numero con numfvd (enlace con partiresul.pareja), como carga masiva / homologación.
     */
    private static function sincronizarNumerosInscripcionTorneo(PDO $pdo, int $torneoId): int
    {
        require_once __DIR__ . '/NumfvdHelper.php';
        if (NumfvdHelper::inscritosTieneColumnaNumfvd($pdo)) {
            $st = $pdo->prepare(
                'UPDATE inscritos SET numero = numfvd
                 WHERE torneo_id = ? AND numfvd > 0 AND CAST(estatus AS CHAR) NOT IN (\'4\',\'retirado\')'
            );
        } else {
            $st = $pdo->prepare(
                'UPDATE inscritos i
                 INNER JOIN usuarios u ON u.id = i.id_usuario
                 SET i.numero = u.numfvd
                 WHERE i.torneo_id = ? AND u.numfvd > 0 AND CAST(i.estatus AS CHAR) NOT IN (\'4\',\'retirado\')'
            );
        }
        $st->execute([$torneoId]);

        return $st->rowCount();
    }

    /**
     * Crea filas en equipos para códigos presentes en parejas pero ausentes en clasiequi.
     *
     * @param array<string, array{titulares: int, total: int, jugadores: list<array<string, mixed>>}> $plantilla
     * @param array<string, array<string, mixed>> $metaEquipo
     */
    private static function asegurarEquiposFaltantesPlantilla(
        PDO $pdo,
        int $torneoId,
        array $plantilla,
        array $metaEquipo,
        int $creadoPor
    ): int {
        $insertados = 0;
        foreach ($plantilla as $cod => $info) {
            if ($cod === '' || $cod === '000-000') {
                continue;
            }
            $meta = $metaEquipo[$cod] ?? [];
            $idClub = (int) ($meta['id_club'] ?? 0);
            if ($idClub <= 0 && preg_match('/^(\d{1,3})-/', $cod, $m)) {
                $idClub = (int) (self::resolverIdClubColumnaAccess($pdo, (int) $m[1]) ?? 0);
            }
            if ($idClub <= 0) {
                foreach ($info['jugadores'] ?? [] as $j) {
                    $asoc = (int) ($j['asociacion_codigo'] ?? 0);
                    if ($asoc > 0) {
                        $idClub = (int) (self::resolverIdClubColumnaAccess($pdo, $asoc) ?? 0);
                        break;
                    }
                }
            }
            if ($idClub <= 0) {
                continue;
            }
            $nombre = trim((string) ($meta['nombre_equipo'] ?? ''));
            if ($nombre === '') {
                $nombre = 'Equipo ' . $cod;
            }
            $consec = 1;
            if (preg_match('/-(\d+)$/', $cod, $mEq)) {
                $consec = max(1, (int) $mEq[1]);
            }
            $g = self::guardarEquipoImportacionAccess(
                $pdo,
                $torneoId,
                $idClub,
                $nombre,
                $cod,
                $consec,
                0,
                $creadoPor
            );
            if ($g['insertado']) {
                $insertados++;
            }
        }

        return $insertados;
    }

    /**
     * Inserta o actualiza equipo respetando uk_equipo_torneo_club y uk_codigo_torneo.
     *
     * @return array{insertado: bool, actualizado: bool, id: int}
     */
    private static function guardarEquipoImportacionAccess(
        PDO $pdo,
        int $torneoId,
        int $idClub,
        string $nombreEquipo,
        string $codigoEquipo,
        int $consecutivo,
        int $estatus,
        int $creadoPor
    ): array {
        $nombreEquipo = trim($nombreEquipo);
        $codigoEquipo = self::normalizarCodigoEquipo(trim($codigoEquipo));
        $vacío = ['insertado' => false, 'actualizado' => false, 'id' => 0];
        if ($torneoId <= 0 || $idClub <= 0 || $codigoEquipo === '') {
            return $vacío;
        }
        if ($nombreEquipo === '') {
            $nombreEquipo = 'Equipo ' . $codigoEquipo;
        }
        $consec = max(1, $consecutivo);

        $stCod = $pdo->prepare(
            'SELECT id, id_club, nombre_equipo FROM equipos WHERE id_torneo = ? AND codigo_equipo = ? LIMIT 1'
        );
        $stCod->execute([$torneoId, $codigoEquipo]);
        $porCodigo = $stCod->fetch(PDO::FETCH_ASSOC) ?: null;

        $stNom = $pdo->prepare(
            'SELECT id, codigo_equipo FROM equipos WHERE id_torneo = ? AND id_club = ? AND nombre_equipo = ? LIMIT 1'
        );
        $stNom->execute([$torneoId, $idClub, $nombreEquipo]);
        $porNombre = $stNom->fetch(PDO::FETCH_ASSOC) ?: null;

        $idObjetivo = 0;
        if ($porCodigo !== null) {
            $idObjetivo = (int) $porCodigo['id'];
        } elseif ($porNombre !== null) {
            $idObjetivo = (int) $porNombre['id'];
        }

        if ($idObjetivo > 0) {
            $codigoFinal = $codigoEquipo;
            $stOtro = $pdo->prepare(
                'SELECT id FROM equipos WHERE id_torneo = ? AND codigo_equipo = ? AND id <> ? LIMIT 1'
            );
            $stOtro->execute([$torneoId, $codigoEquipo, $idObjetivo]);
            if ($stOtro->fetch()) {
                $stActual = $pdo->prepare('SELECT codigo_equipo FROM equipos WHERE id = ? LIMIT 1');
                $stActual->execute([$idObjetivo]);
                $codigoFinal = trim((string) ($stActual->fetchColumn() ?: $codigoEquipo));
            }
            $pdo->prepare(
                'UPDATE equipos SET id_club = ?, nombre_equipo = ?, codigo_equipo = ?, consecutivo_club = ?, estatus = ? WHERE id = ?'
            )->execute([$idClub, $nombreEquipo, $codigoFinal, $consec, $estatus, $idObjetivo]);

            return ['insertado' => false, 'actualizado' => true, 'id' => $idObjetivo];
        }

        $stI = $pdo->prepare(
            'INSERT INTO equipos (id_torneo, id_club, nombre_equipo, codigo_equipo, consecutivo_club, estatus, creado_por)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stI->execute([
            $torneoId,
            $idClub,
            $nombreEquipo,
            $codigoEquipo,
            $consec,
            $estatus,
            $creadoPor > 0 ? $creadoPor : null,
        ]);

        return ['insertado' => true, 'actualizado' => false, 'id' => (int) $pdo->lastInsertId()];
    }

    /**
     * @param list<list<string>> $rows
     * @return array{insertados: int, actualizados: int, omitidos: int}
     */
    private static function importarClasiequi(
        PDO $pdo,
        int $torneoId,
        array $rows,
        int $creadoPor,
        ?array &$incidenciasEjecucion = null
    ): array {
        $parsed = self::separarCabecera($rows, [['club'], ['nombre'], ['equipo']]);
        $h = $parsed['header'];
        $iClub = self::indiceColumna($h, ['club', 'id_club']);
        $iNom = self::indiceColumna($h, ['nombre', 'nombre_equipo']);
        $iEq = self::indiceColumna($h, ['equipo', 'codigo_equipo']);
        $iClave = self::indiceColumna($h, ['clave', 'consecutivo', 'consecutivo_club']);
        $iEst = self::indiceColumna($h, ['estatus', 'status']);

        $insertados = 0;
        $actualizados = 0;
        $omitidos = 0;
        foreach ($parsed['data'] as $rowIdx => $row) {
            $filaArchivo = $parsed['header_row'] + 2 + $rowIdx;
            $codEntidad = (int) preg_replace('/\D/', '', (string) ($row[$iClub] ?? ''));
            $idClub = (int) (self::resolverIdClubColumnaAccess($pdo, $codEntidad) ?? 0);
            $nombre = trim((string) ($row[$iNom] ?? ''));
            $codEqRaw = trim((string) ($row[$iEq] ?? ''));
            $codEq = self::normalizarCodigoEquipo($codEqRaw);
            if ($idClub <= 0) {
                if ($incidenciasEjecucion !== null) {
                    self::registrarIncidenciaEjecucion($incidenciasEjecucion, 'ejec_equipo_sin_club', [
                        'fila_archivo' => $filaArchivo,
                        'elemento' => $nombre !== '' ? $nombre : ($codEqRaw !== '' ? $codEqRaw : '(fila ' . $filaArchivo . ')'),
                        'codigo_equipo' => $codEqRaw,
                        'club_access' => $codEntidad,
                        'torneo_id' => $torneoId,
                        'explicacion' => 'Asociación/club ' . ($codEntidad > 0 ? $codEntidad : '—') . ' no resuelve a club en plataforma. Equipo no importado.',
                    ]);
                }
                $omitidos++;
                continue;
            }
            if ($nombre === '') {
                if ($incidenciasEjecucion !== null) {
                    self::registrarIncidenciaEjecucion($incidenciasEjecucion, 'ejec_equipo_sin_nombre', [
                        'fila_archivo' => $filaArchivo,
                        'elemento' => $codEqRaw !== '' ? $codEqRaw : '(fila ' . $filaArchivo . ')',
                        'codigo_equipo' => $codEqRaw,
                        'torneo_id' => $torneoId,
                        'explicacion' => 'Fila clasiequi sin nombre de equipo. Equipo no importado.',
                    ]);
                }
                $omitidos++;
                continue;
            }
            if ($codEq === '') {
                if ($incidenciasEjecucion !== null) {
                    self::registrarIncidenciaEjecucion($incidenciasEjecucion, 'ejec_equipo_sin_codigo', [
                        'fila_archivo' => $filaArchivo,
                        'elemento' => $nombre,
                        'nombre' => $nombre,
                        'torneo_id' => $torneoId,
                        'explicacion' => 'Equipo «' . $nombre . '» sin código (columna equipo). Equipo no importado.',
                    ]);
                }
                $omitidos++;
                continue;
            }
            $consec = $iClave >= 0 ? (int) preg_replace('/\D/', '', (string) ($row[$iClave] ?? '')) : 1;
            $est = $iEst >= 0 ? (int) preg_replace('/\D/', '', (string) ($row[$iEst] ?? '0')) : 0;

            $g = self::guardarEquipoImportacionAccess(
                $pdo,
                $torneoId,
                $idClub,
                $nombre,
                $codEq,
                $consec,
                $est,
                $creadoPor
            );
            if ($g['insertado']) {
                $insertados++;
            } elseif ($g['actualizado']) {
                $actualizados++;
            }
        }

        return ['insertados' => $insertados, 'actualizados' => $actualizados, 'omitidos' => $omitidos];
    }

    /**
     * @param list<list<string>> $rows
     * @return array{insertados: int, omitidos: int}
     */
    private static function importarParti2017(
        PDO $pdo,
        int $torneoId,
        array $rows,
        int $registradoPor,
        string $fechaTorneo,
        ?array &$incidenciasEjecucion = null
    ): array {
        PartiresulJugadorHelper::refrescarEsquemaPartiresul($pdo);
        $parsed = self::separarCabecera($rows, [['partida'], ['mesa'], ['secuencia'], ['pareja']]);
        $h = $parsed['header'];
        $iPart = self::indiceColumna($h, ['partida', 'ronda']);
        $iMesa = self::indiceColumna($h, ['mesa']);
        $iSeq = self::indiceColumna($h, ['secuencia', 'seq']);
        $iPareja = self::indiceColumna($h, ['pareja', 'numfvd']);
        $iR1 = self::indiceColumna($h, ['result1', 'resultado1', 'r1']);
        $iR2 = self::indiceColumna($h, ['result2', 'resultado2', 'r2']);
        $iFf = self::indiceColumna($h, ['ff', 'forfait']);
        $idxSan = self::resolverIndicesSancionParti2017($h);
        $iChan = self::indiceColumna($h, ['chancleta', 'chancletas']);
        $iZap = self::indiceColumna($h, ['zapato', 'zapatos']);
        $iObs = self::indiceColumna($h, ['observ', 'observaciones', 'nota']);

        $mapInsc = self::mapaInscritosNumfvdConUsuario($pdo, $torneoId);
        $cols = PartiresulJugadorHelper::fragmentoColumnasInsertClave($pdo);
        $marks = PartiresulJugadorHelper::fragmentoMarcadoresInsertClave($pdo);

        $sql = 'INSERT INTO partiresul (id_torneo, partida, mesa, secuencia, ' . $cols
            . ', resultado1, resultado2, efectividad, ff, tarjeta, sancion, chancleta, zapato, fecha_partida, registrado_por, observaciones, registrado, estatus)
            VALUES (?, ?, ?, ?, ' . $marks . ', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)';
        $stmt = $pdo->prepare($sql);

        $n = 0;
        $omitidos = 0;
        /** @var array<string, array{partida: int, mesa: int, jugadores: list<array<string, mixed>>, obs: string}> $mesasPorClave */
        $mesasPorClave = [];

        foreach ($parsed['data'] as $rowIdx => $row) {
            $nf = (int) preg_replace('/\D/', '', (string) ($row[$iPareja] ?? ''));
            if ($nf <= 0) {
                continue;
            }
            if (!isset($mapInsc[$nf])) {
                $partida = (int) ($row[$iPart] ?? 0);
                $mesa = (int) ($row[$iMesa] ?? 0);
                if ($incidenciasEjecucion !== null) {
                    self::registrarIncidenciaEjecucion($incidenciasEjecucion, 'ejec_partiresul_omitido', [
                        'elemento' => 'numfvd ' . $nf,
                        'numfvd' => $nf,
                        'fila_archivo' => $parsed['header_row'] + 2 + $rowIdx,
                        'partida' => $partida,
                        'mesa' => $mesa,
                        'torneo_id' => $torneoId,
                        'explicacion' => 'Ronda ' . $partida . ', mesa ' . $mesa . ': numfvd ' . $nf
                            . ' no está inscrito en el torneo. Fila de partiresul omitida.',
                    ]);
                }
                $omitidos++;
                continue;
            }
            $idUsr = (int) $mapInsc[$nf]['id_usuario'];
            $clave = PartiresulJugadorHelper::datosInsertJugador($pdo, $torneoId, $idUsr);
            $valsClave = PartiresulJugadorHelper::valoresInsertClave($clave, $pdo);

            $partida = (int) ($row[$iPart] ?? 0);
            $mesa = (int) ($row[$iMesa] ?? 0);
            $secuencia = (int) ($row[$iSeq] ?? 0);
            $tarjetaVal = self::valorTarjetaParti2017($row, $idxSan);
            $sancionVal = self::valorSancionPuntosParti2017($row, $idxSan);
            $obs = $iObs >= 0 ? trim((string) ($row[$iObs] ?? '')) : '';

            $params = array_merge(
                [$torneoId, $partida, $mesa, $secuencia],
                $valsClave,
                [
                    TorneoCampoNumerico::intEstadistica($row[$iR1] ?? 0),
                    TorneoCampoNumerico::intEstadistica($row[$iR2] ?? 0),
                    0,
                    $iFf >= 0 ? self::parseFfValorParti2017($row[$iFf] ?? 0) : 0,
                    $tarjetaVal,
                    $sancionVal,
                    $iChan >= 0 ? TorneoCampoNumerico::intEstadistica($row[$iChan] ?? 0) : 0,
                    $iZap >= 0 ? TorneoCampoNumerico::intEstadistica($row[$iZap] ?? 0) : 0,
                    $fechaTorneo . ' 00:00:00',
                    $registradoPor,
                    $obs,
                ]
            );
            $stmt->execute($params);
            $n++;

            $claveMesa = $partida . "\0" . $mesa;
            if (!isset($mesasPorClave[$claveMesa])) {
                $mesasPorClave[$claveMesa] = [
                    'partida' => $partida,
                    'mesa' => $mesa,
                    'jugadores' => [],
                    'obs' => '',
                ];
            }
            if ($obs !== '') {
                $mesasPorClave[$claveMesa]['obs'] = $obs;
            }
            $r1Raw = $row[$iR1] ?? 0;
            $r2Raw = $row[$iR2] ?? 0;
            $mesasPorClave[$claveMesa]['jugadores'][] = [
                'id' => 0,
                'id_usuario' => $idUsr,
                'secuencia' => $secuencia,
                'numfvd' => $nf,
                'resultado1' => TorneoCampoNumerico::intEstadistica($r1Raw),
                'resultado2' => TorneoCampoNumerico::intEstadistica($r2Raw),
                'resultado1_origen' => trim((string) $r1Raw),
                'resultado2_origen' => trim((string) $r2Raw),
                'ff' => $iFf >= 0 ? self::parseFfValorParti2017($row[$iFf] ?? 0) : 0,
                'tarjeta' => $tarjetaVal,
                'sancion' => $sancionVal,
                'chancleta' => $iChan >= 0 ? TorneoCampoNumerico::intEstadistica($row[$iChan] ?? 0) : 0,
                'zapato' => $iZap >= 0 ? TorneoCampoNumerico::intEstadistica($row[$iZap] ?? 0) : 0,
            ];
        }

        if ($n > 0 && $mesasPorClave !== []) {
            self::simularIngresoResultadosParti2017($pdo, $torneoId, $mesasPorClave, $registradoPor);
        }

        return ['insertados' => $n, 'omitidos' => $omitidos];
    }

    /**
     * Tras insertar filas PARTI2017, aplica el mismo núcleo que el formulario de resultados
     * (SancionesHelper, efectividad, retiro por negra) mesa a mesa en orden de ronda.
     *
     * @param array<string, array{partida: int, mesa: int, jugadores: list<array<string, mixed>>, obs: string}> $mesasPorClave
     */
    private static function simularIngresoResultadosParti2017(
        PDO $pdo,
        int $torneoId,
        array $mesasPorClave,
        int $registradoPor
    ): void {
        require_once __DIR__ . '/Tournament/Handlers/TournamentActionHandler.php';
        require_once __DIR__ . '/RankingTorneoRecalc.php';

        $lista = array_values($mesasPorClave);
        usort($lista, static function (array $a, array $b): int {
            $cmp = $a['partida'] <=> $b['partida'];

            return $cmp !== 0 ? $cmp : ($a['mesa'] <=> $b['mesa']);
        });

        foreach ($lista as $mesaData) {
            $partida = (int) $mesaData['partida'];
            $mesa = (int) $mesaData['mesa'];
            $jugadores = $mesaData['jugadores'];
            usort($jugadores, static fn (array $x, array $y): int => (int) ($x['secuencia'] ?? 0) <=> (int) ($y['secuencia'] ?? 0));

            try {
                \Tournament\Handlers\TournamentActionHandler::aplicarResultadosMesaCore(
                    $pdo,
                    $torneoId,
                    $partida,
                    $mesa,
                    $jugadores,
                    $registradoPor,
                    (string) ($mesaData['obs'] ?? '')
                );
            } catch (Throwable $e) {
                throw new RuntimeException(
                    self::formatearErrorProcesoMesaParti2017($pdo, $torneoId, $partida, $mesa, $jugadores, $e),
                    0,
                    $e
                );
            }
        }

        \RankingTorneoRecalc::sincronizarEstadisticasPartidas($torneoId);
        \RankingTorneoRecalc::reclasificarSiUltimaRondaTorneoCompleta($torneoId);
    }

    /**
     * @param list<array<string, mixed>> $jugadores
     */
    private static function formatearErrorProcesoMesaParti2017(
        PDO $pdo,
        int $torneoId,
        int $partida,
        int $mesa,
        array $jugadores,
        Throwable $e
    ): string {
        $ids = [];
        foreach ($jugadores as $j) {
            $uid = (int) ($j['id_usuario'] ?? 0);
            if ($uid > 0) {
                $ids[$uid] = true;
            }
        }
        $nombres = self::nombresUsuariosPorId($pdo, array_keys($ids));

        $lineas = [
            sprintf('Error procesando resultados importados — torneo %d, ronda %d, mesa %d.', $torneoId, $partida, $mesa),
            'Detalle: ' . $e->getMessage(),
            'Jugadores en la mesa (' . count($jugadores) . '):',
        ];
        foreach ($jugadores as $i => $j) {
            $uid = (int) ($j['id_usuario'] ?? 0);
            $nom = $nombres[$uid] ?? '(sin nombre)';
            $lineas[] = sprintf(
                '  #%d sec=%d numfvd=%s id_usuario=%d %s | R1_origen=%s R2_origen=%s | R1=%s R2=%s | ff=%s tarjeta=%s sancion=%s',
                $i + 1,
                (int) ($j['secuencia'] ?? 0),
                (string) ($j['numfvd'] ?? '?'),
                $uid,
                $nom,
                (string) ($j['resultado1_origen'] ?? ($j['resultado1'] ?? '?')),
                (string) ($j['resultado2_origen'] ?? ($j['resultado2'] ?? '?')),
                (string) ($j['resultado1'] ?? '?'),
                (string) ($j['resultado2'] ?? '?'),
                (string) ($j['ff'] ?? 0),
                (string) ($j['tarjeta'] ?? 0),
                (string) ($j['sancion'] ?? 0)
            );
        }

        return implode("\n", $lineas);
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private static function nombresUsuariosPorId(PDO $pdo, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT id, nombre FROM usuarios WHERE id IN ({$ph})");
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['id']] = trim((string) ($row['nombre'] ?? ''));
        }

        return $out;
    }

    /** @param mixed $v */
    private static function parseFfValorParti2017($v): int
    {
        if ($v === null || $v === '') {
            return 0;
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        if (is_numeric($v)) {
            return ((int) $v) === 1 ? 1 : 0;
        }
        $s = strtoupper(trim((string) $v));

        return in_array($s, ['1', 'S', 'SI', 'Y', 'YES', 'TRUE', 'FF', 'FORFAIT'], true) ? 1 : 0;
    }

    /**
     * Índices de columnas Sancion / SancionP en PARTI2017 (sin confundir sancion con sancionp).
     *
     * @param list<string> $hNorm
     * @return array{sancion_marca: int, sancion_puntos: int, tarjeta: int, tiene_sancionp: bool}
     */
    public static function resolverIndicesSancionParti2017(array $hNorm): array
    {
        $out = [
            'sancion_marca' => -1,
            'sancion_puntos' => -1,
            'tarjeta' => -1,
            'tiene_sancionp' => false,
        ];

        foreach ($hNorm as $i => $col) {
            $c = (string) $col;
            if (str_contains($c, 'sancionp') || str_contains($c, 'sancion_p')) {
                $out['sancion_puntos'] = $i;
                $out['tiene_sancionp'] = true;
                break;
            }
        }

        if ($out['tiene_sancionp']) {
            foreach ($hNorm as $i => $col) {
                if ((string) $col === 'sancion') {
                    $out['sancion_marca'] = $i;
                    break;
                }
            }
            $out['tarjeta'] = self::indiceColumnaExacta($hNorm, ['tarjeta', 'amarilla', 'roja', 'negra']);
        } else {
            $out['sancion_puntos'] = self::indiceColumnaExacta($hNorm, ['sancionp', 'sancion_p', 'penal', 'penalizacion']);
            if ($out['sancion_puntos'] < 0) {
                $out['sancion_puntos'] = self::indiceColumnaExacta($hNorm, ['sancion', 'sanc']);
            }
            $out['tarjeta'] = self::indiceColumnaExacta($hNorm, ['tarjeta', 'amarilla', 'roja', 'negra']);
            if ($out['sancion_puntos'] < 0 && $out['tarjeta'] < 0) {
                $out['sancion_marca'] = self::indiceColumnaExacta($hNorm, ['sancion', 'sanc']);
            }
        }

        return $out;
    }

    /**
     * PARTI2017.Sancion (marca) → partiresul.tarjeta: solo Access 5/6/8 → FVD 1/3/4.
     * Sancion=0 o 1 (u otro distinto de 5/6/8) no es tarjeta aunque result1=200 y SancionP=0.
     *
     * @param list<string> $row
     * @param array{sancion_marca: int, sancion_puntos: int, tarjeta: int, tiene_sancionp: bool} $idx
     */
    private static function valorTarjetaParti2017(array $row, array $idx): int
    {
        if ($idx['sancion_marca'] >= 0) {
            return self::parseMarcaTarjetaParti2017($row[$idx['sancion_marca']] ?? 0);
        }
        if ($idx['tarjeta'] >= 0) {
            return self::parseMarcaTarjetaParti2017($row[$idx['tarjeta']] ?? 0);
        }

        return 0;
    }

    /**
     * PARTI2017.SancionP (puntos) → partiresul.sancion (0/40/80…), valor numérico idéntico al origen.
     *
     * @param list<string> $row
     * @param array{sancion_marca: int, sancion_puntos: int, tarjeta: int, tiene_sancionp: bool} $idx
     */
    private static function valorSancionPuntosParti2017(array $row, array $idx): int
    {
        if ($idx['sancion_puntos'] < 0) {
            return 0;
        }
        $cel = $row[$idx['sancion_puntos']] ?? 0;
        if (!$idx['tiene_sancionp'] && $idx['tarjeta'] < 0 && $idx['sancion_marca'] < 0) {
            $raw = trim((string) $cel);
            if ($raw !== '' && !is_numeric($raw)) {
                return 0;
            }
        }

        return TorneoCampoNumerico::intEstadistica($cel);
    }

    /** @param mixed $v */
    private static function parseMarcaTarjetaParti2017($v): int
    {
        if ($v === null) {
            return 0;
        }
        if (is_numeric($v) && trim((string) $v) !== '') {
            return TorneoCampoNumerico::codigoTarjetaDesdeAccess($v);
        }
        $s = trim(strtolower((string) $v));
        if ($s === '' || $s === '-' || $s === 'no' || $s === 'ninguna' || $s === 'n' || $s === 'sin') {
            return 0;
        }
        if (str_contains($s, 'negra') || str_contains($s, 'black')) {
            return 4;
        }
        if (str_contains($s, 'roja') || $s === 'r' || str_contains($s, 'red')) {
            return 3;
        }
        if (str_contains($s, 'amar') || $s === 'a' || str_contains($s, 'yellow')) {
            return 1;
        }

        return TorneoCampoNumerico::codigoTarjetaDesdeAccess($v);
    }

    /**
     * Coincidencia exacta de nombre normalizado (evita que «sancion» capture «sancionp»).
     *
     * @param list<string> $hNorm
     * @param list<string> $nombres
     */
    private static function indiceColumnaExacta(array $hNorm, array $nombres): int
    {
        foreach ($nombres as $nombre) {
            $n = strtolower($nombre);
            foreach ($hNorm as $i => $col) {
                if ((string) $col === $n) {
                    return $i;
                }
            }
        }

        return -1;
    }

    /**
     * Documentación del mapeo PARTI2017 → partiresul (para revisión / soporte).
     *
     * @return array<string, mixed>
     */
    public static function mapeoParti2017Partiresul(): array
    {
        return [
            'origen' => 'Exportación Access PARTI2017',
            'destino' => 'tabla partiresul',
            'columnas' => [
                ['access' => 'Partida / Ronda', 'partiresul' => 'partida'],
                ['access' => 'Mesa', 'partiresul' => 'mesa'],
                ['access' => 'Secuencia', 'partiresul' => 'secuencia'],
                ['access' => 'Pareja / numfvd', 'partiresul' => 'id_usuario + numfvd (vía inscritos)'],
                ['access' => 'Result1 / PF', 'partiresul' => 'resultado1'],
                ['access' => 'Result2 / PC', 'partiresul' => 'resultado2'],
                ['access' => 'Efectiv', 'partiresul' => 'efectividad'],
                ['access' => 'FF', 'partiresul' => 'ff'],
                ['access' => 'Sancion', 'partiresul' => 'tarjeta', 'nota' => 'Solo 5=amarilla, 6=roja, 8=negra → FVD 1/3/4. Sancion=0/1 u otros → tarjeta 0. SancionP→sancion si existe.'],
                ['access' => 'SancionP', 'partiresul' => 'sancion', 'nota' => 'Puntos de penalización; traslado numérico idéntico (0/40/80…).'],
                ['access' => 'Tarjeta', 'partiresul' => 'tarjeta', 'nota' => 'Solo si no hay columna Sancion como marca.'],
                ['access' => 'Chancleta', 'partiresul' => 'chancleta'],
                ['access' => 'Zapato', 'partiresul' => 'zapato'],
            ],
            'regla_sancion' => 'Con Sancion + SancionP: Sancion→tarjeta, SancionP→sancion. Sin SancionP: Sancion→sancion (puntos).',
            'proceso_post_insert' => 'Tras INSERT, cada mesa pasa por aplicarResultadosMesaCore (SancionesHelper + efectividad + stats inscritos), igual que ingreso manual.',
        ];
    }

    /** @return array<int, true> */
    private static function mapaInscritosNumfvd(PDO $pdo, int $torneoId): array
    {
        $expr = NumfvdHelper::sqlExprNumfvdInscrito('i', $pdo);
        $st = $pdo->prepare(
            "SELECT {$expr} AS nf FROM inscritos i
             WHERE i.torneo_id = ? AND CAST(i.estatus AS CHAR) NOT IN ('4','retirado')"
        );
        $st->execute([$torneoId]);
        $map = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $nf = (int) ($row['nf'] ?? 0);
            if ($nf > 0) {
                $map[$nf] = true;
            }
        }

        return $map;
    }

    /** @return array<int, array{id_usuario: int}> */
    private static function mapaInscritosNumfvdConUsuario(PDO $pdo, int $torneoId): array
    {
        $expr = NumfvdHelper::sqlExprNumfvdInscrito('i', $pdo);
        $st = $pdo->prepare(
            "SELECT i.id_usuario, {$expr} AS nf FROM inscritos i
             WHERE i.torneo_id = ? AND CAST(i.estatus AS CHAR) NOT IN ('4','retirado')"
        );
        $st->execute([$torneoId]);
        $map = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $nf = (int) ($row['nf'] ?? 0);
            if ($nf > 0) {
                $map[$nf] = ['id_usuario' => (int) $row['id_usuario']];
            }
        }

        return $map;
    }

    /** @return array<string, int> */
    private static function conteoJugadoresPorCodigoEquipo(PDO $pdo, int $torneoId): array
    {
        $st = $pdo->prepare(
            "SELECT codigo_equipo, COUNT(*) AS n FROM inscritos
             WHERE torneo_id = ? AND codigo_equipo IS NOT NULL AND codigo_equipo <> '' AND codigo_equipo <> '000-000'
               AND CAST(estatus AS CHAR) NOT IN ('4','retirado')
             GROUP BY codigo_equipo"
        );
        $st->execute([$torneoId]);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[(string) $row['codigo_equipo']] = (int) ($row['n'] ?? 0);
        }

        return $out;
    }

    private static function fechaTorneo(PDO $pdo, int $torneoId): string
    {
        $st = $pdo->prepare('SELECT fechator FROM tournaments WHERE id = ? LIMIT 1');
        $st->execute([$torneoId]);
        $f = substr((string) ($st->fetchColumn() ?: ''), 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) {
            return $f;
        }

        return date('Y-m-d');
    }

    /** @return array<int, true> */
    private static function mapaInscritosUsuario(PDO $pdo, int $torneoId): array
    {
        $st = $pdo->prepare(
            "SELECT id_usuario FROM inscritos
             WHERE torneo_id = ? AND CAST(estatus AS CHAR) NOT IN ('4','retirado')"
        );
        $st->execute([$torneoId]);
        $map = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $id = (int) ($row['id_usuario'] ?? 0);
            if ($id > 0) {
                $map[$id] = true;
            }
        }

        return $map;
    }

    /** @param array<string, mixed> $usuario */
    private static function resolverNumfvdUsuario(PDO $pdo, array $usuario, string $ced): int
    {
        $idUsr = (int) ($usuario['id'] ?? 0);
        $nf = (int) ($usuario['numfvd'] ?? 0);
        if ($nf <= 0 && $idUsr > 0) {
            $nf = InscritosHelper::resolverNumfvdParaInscripcion($pdo, $idUsr, $ced);
        }

        return $nf;
    }

    /** @return array<string, mixed>|null */
    private static function resolverUsuarioPorCedula(PDO $pdo, string $cedula): ?array
    {
        $ced = self::normalizarCedula($cedula);
        if ($ced === '') {
            return null;
        }

        $st = $pdo->prepare(
            'SELECT ' . self::columnasUsuarioSelect($pdo) . ' FROM usuarios
             WHERE cedula = ?
                OR REPLACE(REPLACE(REPLACE(TRIM(cedula), \'-\', \'\'), \'.\', \'\'), \' \', \'\') = ?
             LIMIT 1'
        );
        $st->execute([$ced, $ced]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }

        $stPref = $pdo->prepare(
            'SELECT ' . self::columnasUsuarioSelect($pdo) . ' FROM usuarios WHERE cedula = ? LIMIT 1'
        );
        foreach (['V', 'E', 'J', 'P'] as $nac) {
            $stPref->execute([$nac . $ced]);
            $row = $stPref->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $usuario */
    private static function clubDesdeEntidadUsuario(PDO $pdo, array $usuario): ?int
    {
        $ent = (int) ($usuario['entidad'] ?? 0);
        if ($ent <= 0) {
            return null;
        }

        return AsociacionAdminHelper::resolverClubIdDesdeEntidad($pdo, $ent);
    }

    private static function normalizarCedula(string $raw): string
    {
        $s = trim(preg_replace('/\s+/', '', $raw));
        if ($s === '') {
            return '';
        }
        if (preg_match('/^([VEJPvejp])(\d+)$/', $s, $m)) {
            return $m[2];
        }

        return preg_replace('/\D/', '', $s) ?? '';
    }

    /**
     * @param list<string> $fila
     * @return list<string>
     */
    private static function normalizarFilaEncabezados(array $fila): array
    {
        return array_map(static function ($x): string {
            $s = trim((string) $x);
            $s = strtolower($s);
            $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
            $s = preg_replace('/[^a-z0-9]+/', '_', $s) ?? $s;

            return trim($s, '_');
        }, $fila);
    }

    /**
     * @param list<string> $hNorm
     * @param list<string> $aliases
     */
    private static function indiceColumna(array $hNorm, array $aliases): int
    {
        $key = md5(json_encode($hNorm) . implode(',', $aliases));
        if (array_key_exists($key, self::$colCache)) {
            return self::$colCache[$key] ?? -1;
        }
        foreach ($aliases as $alias) {
            $a = strtolower($alias);
            foreach ($hNorm as $i => $col) {
                if ($col === $a || str_contains((string) $col, $a)) {
                    self::$colCache[$key] = $i;

                    return $i;
                }
            }
        }
        self::$colCache[$key] = -1;

        return -1;
    }

    /** @param list<string> $hNorm */
    private static function indiceColumnaTorneo(array $hNorm): int
    {
        return self::indiceColumna($hNorm, ['torneo', 'id_torneo', 'torneo_id']);
    }

    private static function columnasUsuarioSelect(PDO $pdo): string
    {
        $cols = 'id, cedula, numfvd, entidad, club_id, nacionalidad, sexo, nombre';
        if (self::$usuariosTieneIsActive === null) {
            self::$usuariosTieneIsActive = (bool) $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'is_active'")->fetchColumn();
        }
        if (self::$usuariosTieneIsActive) {
            $cols .= ', is_active';
        }

        return $cols;
    }

    /** @param array<string, mixed>|null $mapa */
    private static function etiquetaTorneoSlot(?array $mapa, int $slot): string
    {
        if ($slot <= 0) {
            return '';
        }
        if ($mapa !== null && isset($mapa['slots'][$slot])) {
            $grupo = (string) ($mapa['slots'][$slot]['campeonato_grupo'] ?? '');

            return 'Torneo ' . $slot . ($grupo !== '' ? ' — ' . $grupo : '');
        }

        return 'Torneo ' . $slot;
    }

    /**
     * Metadatos de origen/destino para cada código de situación en importación Access.
     *
     * @return array{
     *   origen_archivo: string,
     *   origen_tabla_access: string,
     *   tabla_destino: string,
     *   campo_destino: string,
     *   como_resolver: string
     * }
     */
    private static function metaSituacion(string $codigo): array
    {
        static $cat = [
            'cedula_sin_usuario' => [
                'origen_archivo' => 'parejas_inscritas',
                'origen_tabla_access' => 'Parejas inscritas',
                'tabla_destino' => 'usuarios',
                'campo_destino' => 'cedula',
                'como_resolver' => 'Registre el atleta (Afiliación) o ejecute Paso 0: sincronizar atletas → usuarios antes de importar.',
            ],
            'cedula_duplicada_archivo' => [
                'origen_archivo' => 'parejas_inscritas',
                'origen_tabla_access' => 'Parejas inscritas',
                'tabla_destino' => '(archivo — duplicado)',
                'campo_destino' => 'cedula',
                'como_resolver' => 'Deje una sola fila por cédula en el export de parejas inscritas.',
            ],
            'torneo_invalido' => [
                'origen_archivo' => 'parejas_inscritas',
                'origen_tabla_access' => 'Parejas inscritas',
                'tabla_destino' => 'tournaments',
                'campo_destino' => 'torneo (columna archivo)',
                'como_resolver' => 'Use 1 = hombres y 2 = mujeres en la columna torneo (campeonato por género).',
            ],
            'sexo_no_coincide' => [
                'origen_archivo' => 'parejas_inscritas',
                'origen_tabla_access' => 'Parejas inscritas',
                'tabla_destino' => 'usuarios',
                'campo_destino' => 'sexo',
                'como_resolver' => 'Corrija la columna torneo del archivo o actualice el sexo del usuario en la plataforma.',
            ],
            'sin_sexo' => [
                'origen_archivo' => 'parejas_inscritas',
                'origen_tabla_access' => 'Parejas inscritas',
                'tabla_destino' => 'usuarios',
                'campo_destino' => 'sexo',
                'como_resolver' => 'Complete el sexo del usuario (M/F) en usuarios/atletas antes de importar torneo por género.',
            ],
            'sin_numfvd' => [
                'origen_archivo' => 'parejas_inscritas',
                'origen_tabla_access' => 'Parejas inscritas',
                'tabla_destino' => 'usuarios',
                'campo_destino' => 'numfvd',
                'como_resolver' => 'Asigne numfvd/carnet FVD al usuario o incluya la columna numfvd/pareja en el archivo.',
            ],
            'numfvd_discrepancia' => [
                'origen_archivo' => 'parejas_inscritas',
                'origen_tabla_access' => 'Parejas inscritas',
                'tabla_destino' => 'usuarios',
                'campo_destino' => 'numfvd',
                'como_resolver' => 'Unifique numfvd: corrija el archivo Access o actualice usuarios.numfvd (Paso 0 sync).',
            ],
            'numfvd_duplicado_archivo' => [
                'origen_archivo' => 'parejas_inscritas',
                'origen_tabla_access' => 'Parejas inscritas',
                'tabla_destino' => 'inscritos',
                'campo_destino' => 'numfvd',
                'como_resolver' => 'Cada numfvd debe aparecer una sola vez por sub-torneo en parejas inscritas.',
            ],
            'sin_club' => [
                'origen_archivo' => 'parejas_inscritas',
                'origen_tabla_access' => 'Parejas inscritas',
                'tabla_destino' => 'clubes',
                'campo_destino' => 'entidad / id_club',
                'como_resolver' => 'Cree o vincule un club para la entidad del usuario (clubes.entidad = usuarios.entidad).',
            ],
            'columna_faltante' => [
                'origen_archivo' => 'archivo_importacion',
                'origen_tabla_access' => '(encabezado del archivo)',
                'tabla_destino' => '—',
                'campo_destino' => 'columna requerida',
                'como_resolver' => 'Agregue la columna indicada al export de Access con el nombre esperado.',
            ],
            'numfvd_sin_inscrito' => [
                'origen_archivo' => 'parti2017',
                'origen_tabla_access' => 'parti2017 (resultados)',
                'tabla_destino' => 'inscritos',
                'campo_destino' => 'numfvd / id_usuario',
                'como_resolver' => 'Importe primero parejas inscritas (inscritos) o corrija el numfvd en parti2017.',
            ],
            'equipo_sin_jugadores_parejas' => [
                'origen_archivo' => 'clasiequi',
                'origen_tabla_access' => 'clasiequi (declara equipo) vs Parejas inscritas',
                'tabla_destino' => 'inscritos',
                'campo_destino' => 'codigo_equipo',
                'como_resolver' => 'Agregue en parejas inscritas las filas con el mismo codigo_equipo que en clasiequi.',
            ],
            'equipo_exceso_jugadores' => [
                'origen_archivo' => 'parejas_inscritas',
                'origen_tabla_access' => 'Parejas inscritas (vs clasiequi)',
                'tabla_destino' => 'inscritos',
                'campo_destino' => 'codigo_equipo',
                'como_resolver' => 'Reduzca a la cantidad requerida por modalidad o marque suplentes como banca (activo=0).',
            ],
            'equipo_faltan_jugadores' => [
                'origen_archivo' => 'parejas_inscritas',
                'origen_tabla_access' => 'Parejas inscritas (vs clasiequi)',
                'tabla_destino' => 'inscritos',
                'campo_destino' => 'codigo_equipo',
                'como_resolver' => 'Agregue las filas faltantes en parejas inscritas con el mismo codigo_equipo.',
            ],
            'equipo_titulares_incompletos' => [
                'origen_archivo' => 'parejas_inscritas',
                'origen_tabla_access' => 'Parejas inscritas (columna activo/titular)',
                'tabla_destino' => 'inscritos',
                'campo_destino' => 'activo_mesa',
                'como_resolver' => 'Marque exactamente N titulares (activo=1) por equipo; el resto en banca (activo=0).',
            ],
            'parejas_ref_faltante' => [
                'origen_archivo' => 'clasiequi',
                'origen_tabla_access' => 'clasiequi',
                'tabla_destino' => 'parejas_inscritas (archivo)',
                'campo_destino' => '—',
                'como_resolver' => 'Suba el archivo parejas inscritas al analizar clasiequi para comparar equipos.',
            ],
            'atleta_sin_registro' => [
                'origen_archivo' => 'parejas_inscritas',
                'origen_tabla_access' => 'Parejas inscritas → atletas',
                'tabla_destino' => 'atletas',
                'campo_destino' => 'cedula',
                'como_resolver' => 'Registre la cédula en el padrón atletas antes del Paso 0.',
            ],
            'padron_numfvd_desalineado' => [
                'origen_archivo' => 'padron_atletas',
                'origen_tabla_access' => 'Comparación atletas (padrón FVD) vs usuarios (plataforma)',
                'tabla_destino' => 'usuarios',
                'campo_destino' => 'numfvd',
                'como_resolver' => 'Fuente de verdad: atletas. Si atletas.numfvd es correcto → «Sincronizar todo el padrón». Si usuarios.numfvd es correcto → corrija atletas.numfvd en el padrón y vuelva a verificar.',
            ],
            'padron_sexo_desalineado' => [
                'origen_archivo' => 'padron_atletas',
                'origen_tabla_access' => 'Comparación atletas vs usuarios',
                'tabla_destino' => 'usuarios',
                'campo_destino' => 'sexo',
                'como_resolver' => 'Confirme el sexo correcto (M/F). Si atletas es correcto → sincronice. Si usuarios es correcto → corrija atletas.sexo.',
            ],
            'padron_entidad_desalineada' => [
                'origen_archivo' => 'padron_atletas',
                'origen_tabla_access' => 'Comparación atletas vs usuarios',
                'tabla_destino' => 'usuarios',
                'campo_destino' => 'entidad / club_id',
                'como_resolver' => 'Confirme la asociación correcta. Si atletas.asociacion es correcta → sincronice. Si no → corrija el padrón atletas.',
            ],
            'padron_usuario_faltante' => [
                'origen_archivo' => 'padron_atletas',
                'origen_tabla_access' => 'Tabla atletas (padrón FVD)',
                'tabla_destino' => 'usuarios',
                'campo_destino' => 'cedula',
                'como_resolver' => 'Pulse «Sincronizar todo el padrón» para crear el usuario desde atletas (numfvd, sexo, entidad).',
            ],
            'padron_atleta_sin_cedula' => [
                'origen_archivo' => 'padron_atletas',
                'origen_tabla_access' => 'Tabla atletas',
                'tabla_destino' => 'atletas',
                'campo_destino' => 'cedula',
                'como_resolver' => 'Complete o corrija la cédula en el padrón de atletas; sin cédula válida no se puede vincular a usuarios.',
            ],
            'banca_exceso_equipo' => [
                'origen_archivo' => 'parejas_inscritas',
                'origen_tabla_access' => 'Parejas inscritas (plantilla > requeridos)',
                'tabla_destino' => 'inscritos',
                'campo_destino' => 'activo_mesa',
                'como_resolver' => 'Al importar, los jugadores que excedan los titulares requeridos se inscriben con activo_mesa=0 (banca).',
            ],
            'banca_sin_clasiequi' => [
                'origen_archivo' => 'parejas_inscritas',
                'origen_tabla_access' => 'Parejas inscritas (equipo no declarado en clasiequi)',
                'tabla_destino' => 'inscritos',
                'campo_destino' => 'activo_mesa / codigo_equipo',
                'como_resolver' => 'Al importar se inscriben en banca. Agregue el equipo en clasiequi o corrija codigo_equipo en parejas inscritas.',
            ],
            'ejec_equipo_sin_club' => [
                'origen_archivo' => 'clasiequi',
                'origen_tabla_access' => 'clasiequi (equipos)',
                'tabla_destino' => 'equipos',
                'campo_destino' => 'id_club / club',
                'como_resolver' => 'Corrija la columna club/asociación en clasiequi (debe resolver a un club en la plataforma).',
            ],
            'ejec_equipo_sin_nombre' => [
                'origen_archivo' => 'clasiequi',
                'origen_tabla_access' => 'clasiequi (equipos)',
                'tabla_destino' => 'equipos',
                'campo_destino' => 'nombre_equipo',
                'como_resolver' => 'Complete el nombre del equipo en clasiequi.',
            ],
            'ejec_equipo_sin_codigo' => [
                'origen_archivo' => 'clasiequi',
                'origen_tabla_access' => 'clasiequi (equipos)',
                'tabla_destino' => 'equipos',
                'campo_destino' => 'codigo_equipo',
                'como_resolver' => 'Indique código de equipo (columna equipo) en clasiequi, formato NN-NNN.',
            ],
            'ejec_partiresul_omitido' => [
                'origen_archivo' => 'parti2017',
                'origen_tabla_access' => 'parti2017 (resultados)',
                'tabla_destino' => 'partiresul',
                'campo_destino' => 'pareja / numfvd',
                'como_resolver' => 'El numfvd debe existir en inscritos (importe parejas primero o corrija el carnet en parti2017).',
            ],
            'ejec_error_sql' => [
                'origen_archivo' => 'ejecucion_importacion',
                'origen_tabla_access' => 'Proceso de copia al torneo',
                'tabla_destino' => '—',
                'campo_destino' => '—',
                'como_resolver' => 'Revise el detalle del error, corrija datos en Access y vuelva a ejecutar.',
            ],
        ];

        return $cat[$codigo] ?? [
            'origen_archivo' => 'desconocido',
            'origen_tabla_access' => '—',
            'tabla_destino' => '—',
            'campo_destino' => '—',
            'como_resolver' => 'Revise el detalle de la situación.',
        ];
    }

    /**
     * @param array<string, mixed> $campos
     * @return array<string, mixed>
     */
    private static function armarSituacion(string $codigo, array $campos = []): array
    {
        return array_merge(
            ['codigo' => $codigo, 'tipo' => $codigo],
            self::metaSituacion($codigo),
            $campos
        );
    }

    /**
     * @param list<string> $mensajes
     * @return list<array<string, mixed>>
     */
    private static function situacionesDesdeErroresColumnas(string $origenArchivo, string $tablaAccess, array $mensajes): array
    {
        $out = [];
        foreach ($mensajes as $msg) {
            $out[] = self::armarSituacion('columna_faltante', [
                'origen_archivo' => $origenArchivo,
                'origen_tabla_access' => $tablaAccess,
                'elemento' => (string) $msg,
                'explicacion' => (string) $msg,
            ]);
        }

        return $out;
    }

    /** @param array<string, mixed> $usuario */
    private static function etiquetaEstatusUsuario(array $usuario): string
    {
        if (array_key_exists('is_active', $usuario)) {
            return (int) ($usuario['is_active'] ?? 0) === 1 ? 'Usuario activo' : 'Usuario inactivo';
        }

        return 'Registrado en plataforma';
    }

    private static function etiquetaEstatusEquipo(?int $estatus): string
    {
        if ($estatus === null) {
            return 'Sin dato en clasiequi';
        }

        if ($estatus === 0) {
            return 'Activo (0)';
        }
        if ($estatus === 1) {
            return 'Inactivo (1)';
        }

        return 'Estatus ' . $estatus;
    }

    private static function leyendaIntegridadEquipos(int $jugadoresReq): string
    {
        return 'Se lee parejas inscritas ordenado por equipo. Cada equipo en clasiequi debe tener al menos '
            . $jugadoresReq . ' jugadores y ' . $jugadoresReq . ' titulares. Si hay más jugadores de los requeridos, '
            . 'el excedente se importa en banca (activo_mesa=0). Jugadores de equipos no declarados en clasiequi '
            . 'también van a banca y se reportan por asociación.';
    }

    /**
     * @param list<list<string>> $clasiequiRows
     * @return array<string, true>
     */
    private static function codigosEquipoDesdeClasiequi(array $clasiequiRows, ?int $soloSlot): array
    {
        $parsed = self::separarCabecera($clasiequiRows, [['equipo', 'codigo_equipo']]);
        $h = $parsed['header'];
        $iEq = self::indiceColumna($h, ['equipo', 'codigo_equipo', 'codequipo']);
        $iTorneo = self::indiceColumnaTorneo($h);
        $out = [];
        foreach ($parsed['data'] as $row) {
            if ($soloSlot !== null && ($iTorneo < 0 || self::slotDesdeFila($row, $iTorneo) !== $soloSlot)) {
                continue;
            }
            $cod = self::normalizarCodigoEquipo($iEq >= 0 ? trim((string) ($row[$iEq] ?? '')) : '');
            if ($cod !== '') {
                $out[$cod] = true;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $reporte
     * @param array<string, mixed> $incidencia
     */
    private static function acumularReporteBanca(
        array &$reporte,
        array $incidencia,
        string $cedula,
        string $nombre,
        int $asocCod,
        ?int $slot
    ): void {
        $asocEtiqueta = $asocCod > 0 ? EntidadFvdCatalogo::etiqueta($asocCod) : '—';
        $key = $asocCod > 0 ? (string) $asocCod : '_sin_asoc';
        if (!isset($reporte['por_asociacion'][$key])) {
            $reporte['por_asociacion'][$key] = [
                'asociacion_codigo' => $asocCod,
                'asociacion' => $asocEtiqueta,
                'sin_clasiequi' => 0,
                'exceso_plantilla' => 0,
            ];
        }
        $motivo = (string) ($incidencia['motivo'] ?? '');
        if ($motivo === 'sin_clasiequi') {
            $reporte['por_asociacion'][$key]['sin_clasiequi']++;
        } else {
            $reporte['por_asociacion'][$key]['exceso_plantilla']++;
        }
        $reporte['total'] = (int) ($reporte['total'] ?? 0) + 1;
        $reporte['detalle'][] = array_merge($incidencia, [
            'cedula' => $cedula,
            'nombre' => $nombre,
            'asociacion_codigo' => $asocCod,
            'asociacion' => $asocEtiqueta,
            'slot' => $slot,
        ]);
    }

    /**
     * @param array<string, mixed> $reporte
     * @return list<array<string, mixed>>
     */
    private static function situacionesDesdeReporteBanca(array $reporte): array
    {
        $out = [];
        foreach ($reporte['detalle'] ?? [] as $d) {
            $codSit = ($d['motivo'] ?? '') === 'sin_clasiequi' ? 'banca_sin_clasiequi' : 'banca_exceso_equipo';
            $out[] = self::armarSituacion($codSit, [
                'cedula' => (string) ($d['cedula'] ?? ''),
                'nombre' => (string) ($d['nombre'] ?? ''),
                'codigo_equipo' => (string) ($d['codigo_equipo'] ?? ''),
                'elemento' => 'cedula=' . (string) ($d['cedula'] ?? '') . ', codigo_equipo=' . (string) ($d['codigo_equipo'] ?? ''),
                'valor_archivo' => 'parejas inscritas',
                'valor_sistema' => 'activo_mesa=0 (banca)',
                'explicacion' => (string) ($d['explicacion'] ?? ''),
                'asociacion' => (string) ($d['asociacion'] ?? '—'),
                'slot' => $d['slot'] ?? null,
            ]);
        }

        return $out;
    }

    /** @param array<int, array<string, mixed>> $porTorneo */
    private static function fusionarReporteBancaPorTorneo(array $porTorneo): array
    {
        $fusion = ['total' => 0, 'por_asociacion' => [], 'detalle' => []];
        foreach ($porTorneo as $slot => $r) {
            $rb = $r['reporte_banca'] ?? [];
            if ($rb === []) {
                continue;
            }
            $fusion['total'] += (int) ($rb['total'] ?? 0);
            foreach ($rb['detalle'] ?? [] as $d) {
                $d['slot'] = $d['slot'] ?? $slot;
                $fusion['detalle'][] = $d;
            }
            foreach ($rb['por_asociacion'] ?? [] as $key => $bloque) {
                if (!isset($fusion['por_asociacion'][$key])) {
                    $fusion['por_asociacion'][$key] = $bloque;
                    continue;
                }
                $fusion['por_asociacion'][$key]['sin_clasiequi'] += (int) ($bloque['sin_clasiequi'] ?? 0);
                $fusion['por_asociacion'][$key]['exceso_plantilla'] += (int) ($bloque['exceso_plantilla'] ?? 0);
            }
        }
        $fusion['situaciones_detalle'] = self::situacionesDesdeReporteBanca($fusion);

        return $fusion;
    }

    private static function equipoEnClasiequi(?array $codigosClasiequi, string $cod): bool
    {
        if ($codigosClasiequi === null) {
            return true;
        }

        return isset($codigosClasiequi[$cod]);
    }

    /** @param list<string> $hNorm */
    private static function indiceColumnaActivoMesa(array $hNorm): int
    {
        return self::indiceColumna($hNorm, [
            'activo_mesa',
            'activo',
            'titular',
            'inactivo_mesa',
            'banca',
            'estatus_mesa',
        ]);
    }

    /**
     * @param list<string> $row
     */
    private static function parseActivoMesaCelda(string $raw): int
    {
        $s = strtolower(trim($raw));
        if ($s === '' || $s === '1' || $s === 'si' || $s === 'sí' || $s === 's' || $s === 'true'
            || $s === 'titular' || $s === 'activo' || $s === 'active' || $s === 'a') {
            return InscritosHelper::ACTIVO_MESA_SI;
        }
        if ($s === '0' || $s === 'no' || $s === 'n' || $s === 'false'
            || str_contains($s, 'banca') || str_contains($s, 'inactiv') || str_contains($s, 'supl')
            || $s === 'i') {
            return InscritosHelper::ACTIVO_MESA_BANCA;
        }

        return (int) preg_replace('/\D/', '', $s) === 0
            ? InscritosHelper::ACTIVO_MESA_BANCA
            : InscritosHelper::ACTIVO_MESA_SI;
    }

    /**
     * @param array<string, int> $titularesAsignadosPorCod
     * @param-out array<string, mixed>|null $incidenciaBanca
     */
    private static function resolverActivoMesaFilaPareja(
        array $row,
        int $iActivo,
        string $cod,
        int $jugadoresReq,
        array &$titularesAsignadosPorCod,
        bool $codEnClasiequi = true,
        ?array &$incidenciaBanca = null
    ): int {
        $incidenciaBanca = null;

        if (!$codEnClasiequi) {
            $incidenciaBanca = [
                'motivo' => 'sin_clasiequi',
                'codigo_equipo' => $cod,
                'explicacion' => 'Equipo ' . $cod . ' no está declarado en clasiequi; el jugador se importará en banca.',
            ];

            return InscritosHelper::ACTIVO_MESA_BANCA;
        }

        $n = (int) ($titularesAsignadosPorCod[$cod] ?? 0);

        if ($iActivo >= 0) {
            $desea = self::parseActivoMesaCelda((string) ($row[$iActivo] ?? '1'));
            if ($desea === InscritosHelper::ACTIVO_MESA_SI) {
                if ($n >= $jugadoresReq) {
                    $incidenciaBanca = [
                        'motivo' => 'exceso_plantilla',
                        'codigo_equipo' => $cod,
                        'explicacion' => 'Equipo ' . $cod . ' ya tiene ' . $jugadoresReq . ' titulares; este jugador irá a banca.',
                    ];

                    return InscritosHelper::ACTIVO_MESA_BANCA;
                }
                $titularesAsignadosPorCod[$cod] = $n + 1;

                return InscritosHelper::ACTIVO_MESA_SI;
            }

            return InscritosHelper::ACTIVO_MESA_BANCA;
        }

        $activo = $n < $jugadoresReq ? InscritosHelper::ACTIVO_MESA_SI : InscritosHelper::ACTIVO_MESA_BANCA;
        if ($activo === InscritosHelper::ACTIVO_MESA_SI) {
            $titularesAsignadosPorCod[$cod] = $n + 1;
        } else {
            $incidenciaBanca = [
                'motivo' => 'exceso_plantilla',
                'codigo_equipo' => $cod,
                'explicacion' => 'Equipo ' . $cod . ' supera ' . $jugadoresReq . ' titulares; este jugador irá a banca.',
            ];
        }

        return $activo;
    }

    /**
     * @param list<list<string>> $parejasRows
     * @param array<string, true>|null $codigosClasiequi null = no validar clasiequi
     * @return array{plantilla: array<string, array{titulares: int, total: int, jugadores: list<array<string, mixed>>}>, reporte_banca: array<string, mixed>}
     */
    private static function mapaPlantillaDesdeParejas(
        PDO $pdo,
        array $parejasRows,
        int $jugadoresReq,
        ?int $soloSlot,
        ?array $codigosClasiequi = null
    ): array {
        $parsedP = self::separarCabecera($parejasRows, [['cedula', 'ced']]);
        $hP = $parsedP['header'];
        $iCedP = self::indiceColumna($hP, ['cedula', 'ced', 'documento']);
        $iNomP = self::indiceColumna($hP, ['nombre', 'jugador', 'atleta']);
        $iNumP = self::indiceColumna($hP, ['numfvd', 'num_fvd', 'carnet', 'pareja']);
        $iCod = self::indiceColumna($hP, ['codigo_equipo', 'codequipo']);
        $iAsoc = self::indiceColumna($hP, ['asociacion', 'club', 'entidad']);
        $iEqN = self::indiceColumna($hP, ['equipo', 'numero_equipo', 'num_equipo']);
        $iTorneoP = self::indiceColumnaTorneo($hP);
        $iActivo = self::indiceColumnaActivoMesa($hP);

        $filasOrdenadas = [];
        foreach ($parsedP['data'] as $row) {
            if ($soloSlot !== null && ($iTorneoP < 0 || self::slotDesdeFila($row, $iTorneoP) !== $soloSlot)) {
                continue;
            }
            $cod = self::codigoEquipoDesdeFilaPareja($row, $iCod, $iAsoc, $iEqN);
            if ($cod === '') {
                continue;
            }
            $filasOrdenadas[] = ['cod' => $cod, 'row' => $row];
        }
        usort($filasOrdenadas, static function (array $a, array $b): int {
            $cmp = strcmp($a['cod'], $b['cod']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return 0;
        });

        $map = [];
        $titularesAuto = [];
        $reporteBanca = ['total' => 0, 'por_asociacion' => [], 'detalle' => []];

        foreach ($filasOrdenadas as $item) {
            $cod = $item['cod'];
            $row = $item['row'];
            if (!isset($map[$cod])) {
                $map[$cod] = ['titulares' => 0, 'total' => 0, 'jugadores' => []];
            }
            $incidencia = null;
            $enClasiequi = self::equipoEnClasiequi($codigosClasiequi, $cod);
            $activo = self::resolverActivoMesaFilaPareja(
                $row,
                $iActivo,
                $cod,
                $jugadoresReq,
                $titularesAuto,
                $enClasiequi,
                $incidencia
            );
            $map[$cod]['total']++;
            if ($activo === InscritosHelper::ACTIVO_MESA_SI) {
                $map[$cod]['titulares']++;
            }

            $ced = $iCedP >= 0 ? self::normalizarCedula((string) ($row[$iCedP] ?? '')) : '';
            $asocCod = $iAsoc >= 0 ? (int) preg_replace('/\D/', '', (string) ($row[$iAsoc] ?? '')) : 0;
            if ($asocCod <= 0 && preg_match('/^(\d{1,3})-/', $cod, $mAsoc)) {
                $asocCod = (int) $mAsoc[1];
            }
            $nf = $iNumP >= 0 ? (int) preg_replace('/\D/', '', (string) ($row[$iNumP] ?? '')) : 0;
            $nombre = $iNomP >= 0 ? trim((string) ($row[$iNomP] ?? '')) : '';
            if ($ced !== '' && $nombre === '') {
                $u = self::resolverUsuarioPorCedula($pdo, $ced);
                if ($u !== null) {
                    $nombre = trim((string) ($u['nombre'] ?? ''));
                    if ($asocCod <= 0) {
                        $asocCod = (int) ($u['entidad'] ?? 0);
                    }
                }
            }
            if ($incidencia !== null) {
                self::acumularReporteBanca($reporteBanca, $incidencia, $ced, $nombre, $asocCod, $soloSlot);
            }
            $map[$cod]['jugadores'][] = [
                'cedula' => $ced,
                'nombre' => $nombre,
                'numfvd' => $nf,
                'asociacion_codigo' => $asocCod,
                'asociacion' => $asocCod > 0 ? EntidadFvdCatalogo::etiqueta($asocCod) : '—',
                'activo_mesa' => $activo,
                'rol' => $activo === InscritosHelper::ACTIVO_MESA_SI ? 'Titular' : 'Banca',
                'motivo_banca' => $incidencia['motivo'] ?? null,
            ];
        }

        foreach ($map as &$entry) {
            usort($entry['jugadores'], static function (array $a, array $b): int {
                $na = (int) ($a['numfvd'] ?? 0);
                $nb = (int) ($b['numfvd'] ?? 0);
                if ($na !== $nb) {
                    return $na <=> $nb;
                }

                return strcmp((string) ($a['cedula'] ?? ''), (string) ($b['cedula'] ?? ''));
            });
        }
        unset($entry);
        ksort($map);

        return ['plantilla' => $map, 'reporte_banca' => $reporteBanca];
    }

    /**
     * Resumen parejas inscritas agrupado por código de equipo (ordenado).
     *
     * @param array<string, array{titulares: int, total: int, jugadores: list<array<string, mixed>>}> $plantilla
     * @return list<array<string, mixed>>
     */
    private static function resumenParejasPorEquipo(array $plantilla, int $jugadoresReq, ?array $codigosClasiequi = null): array
    {
        $out = [];
        foreach ($plantilla as $cod => $info) {
            $total = (int) ($info['total'] ?? 0);
            $titulares = (int) ($info['titulares'] ?? 0);
            $banca = max(0, $total - $titulares);
            $enClasiequi = self::equipoEnClasiequi($codigosClasiequi, $cod);
            $out[] = [
                'codigo_equipo' => $cod,
                'total' => $total,
                'titulares' => $titulares,
                'banca' => $banca,
                'requeridos' => $jugadoresReq,
                'en_clasiequi' => $enClasiequi,
                'ok' => $enClasiequi && $total >= $jugadoresReq && $titulares >= $jugadoresReq,
                'aviso_banca' => $banca > 0 || !$enClasiequi,
                'jugadores' => $info['jugadores'] ?? [],
            ];
        }

        return $out;
    }

    /** @return array<string, int> */
    private static function conteoTitularesPorCodigoEquipo(PDO $pdo, int $torneoId): array
    {
        if (InscritosHelper::tieneColumnaActivoMesa($pdo)) {
            $st = $pdo->prepare(
                "SELECT codigo_equipo, COUNT(*) AS n FROM inscritos
                 WHERE torneo_id = ? AND codigo_equipo IS NOT NULL AND codigo_equipo <> '' AND codigo_equipo <> '000-000'
                   AND activo_mesa = 1
                   AND CAST(estatus AS CHAR) NOT IN ('4','retirado')
                 GROUP BY codigo_equipo"
            );
            $st->execute([$torneoId]);
        } else {
            return self::conteoJugadoresPorCodigoEquipo($pdo, $torneoId);
        }
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[(string) $row['codigo_equipo']] = (int) ($row['n'] ?? 0);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $ctxCols
     * @param array<string, mixed>|null $usuario
     * @return array<string, mixed>
     */
    private static function metaArchivoPareja(array $row, array $ctxCols, int $slot, ?array $usuario = null): array
    {
        $iNombre = (int) ($ctxCols['iNombre'] ?? -1);
        $iAsoc = (int) ($ctxCols['iAsoc'] ?? -1);
        $iNumfvd = (int) ($ctxCols['iNumfvd'] ?? -1);
        $iCodEq = (int) ($ctxCols['iCodEq'] ?? -1);
        $iEquipo = (int) ($ctxCols['iEquipo'] ?? -1);
        $mapa = $ctxCols['mapa'] ?? null;

        $asocArch = $iAsoc >= 0 ? (int) preg_replace('/\D/', '', (string) ($row[$iAsoc] ?? '')) : 0;
        $entidadUsu = $usuario !== null ? (int) ($usuario['entidad'] ?? 0) : 0;
        $asocCod = $entidadUsu > 0 ? $entidadUsu : $asocArch;
        $nombreArch = $iNombre >= 0 ? trim((string) ($row[$iNombre] ?? '')) : '';
        $nombre = trim((string) ($usuario['nombre'] ?? '')) ?: $nombreArch;
        $codEq = self::codigoEquipoDesdeFilaPareja($row, $iCodEq, $iAsoc, $iEquipo);

        return [
            'nombre' => $nombre,
            'nombre_archivo' => $nombreArch,
            'asociacion_codigo' => $asocCod,
            'asociacion' => $asocCod > 0 ? EntidadFvdCatalogo::etiqueta($asocCod) : 'Sin asociación',
            'torneo_slot' => $slot > 0 ? $slot : null,
            'torneo_etiqueta' => self::etiquetaTorneoSlot(is_array($mapa) ? $mapa : null, $slot),
            'numfvd_archivo' => $iNumfvd >= 0 ? (int) preg_replace('/\D/', '', (string) ($row[$iNumfvd] ?? '')) : 0,
            'codigo_equipo' => $codEq,
            'sexo' => $usuario !== null
                ? CampeonatoTorneoHelper::sexoNormalizado((string) ($usuario['sexo'] ?? ''))
                : null,
        ];
    }

    /**
     * @param array<string, mixed>|null $usuario
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private static function armarDivergenciaPareja(
        string $tipo,
        string $cedula,
        ?array $usuario,
        array $meta,
        string $explicacion,
        ?int $filaArchivo = null,
        ?string $valorArchivo = null,
        ?string $valorSistema = null
    ): array {
        $elemento = $cedula !== ''
            ? 'cedula=' . $cedula
            : ((string) ($meta['codigo_equipo'] ?? '') !== '' ? 'codigo_equipo=' . $meta['codigo_equipo'] : '—');

        return array_merge(
            self::armarSituacion($tipo, [
                'cedula' => $cedula,
                'nombre' => (string) ($meta['nombre'] ?? ''),
                'asociacion_codigo' => (int) ($meta['asociacion_codigo'] ?? 0),
                'asociacion' => (string) ($meta['asociacion'] ?? '—'),
                'torneo_slot' => $meta['torneo_slot'] ?? null,
                'torneo_etiqueta' => (string) ($meta['torneo_etiqueta'] ?? ''),
                'numfvd_archivo' => (int) ($meta['numfvd_archivo'] ?? 0),
                'numfvd_usuario' => $usuario !== null ? (int) ($usuario['numfvd'] ?? 0) : null,
                'sexo' => $meta['sexo'] ?? null,
                'codigo_equipo' => (string) ($meta['codigo_equipo'] ?? ''),
                'estatus' => $usuario !== null ? self::etiquetaEstatusUsuario($usuario) : 'Sin cuenta en plataforma',
                'fila_archivo' => $filaArchivo,
                'elemento' => $elemento,
                'valor_archivo' => $valorArchivo,
                'valor_sistema' => $valorSistema,
                'explicacion' => $explicacion,
            ])
        );
    }

    /** @param array<string, mixed> $stats */
    private static function resumirDivergenciasParejas(array $stats): array
    {
        $tipos = [
            'cedula_sin_usuario' => ['label' => 'Cédula sin usuario', 'items' => []],
            'cedula_duplicada_archivo' => ['label' => 'Cédula duplicada en archivo', 'items' => []],
            'sexo_no_coincide' => ['label' => 'Sexo ≠ torneo del archivo', 'items' => []],
            'sin_sexo' => ['label' => 'Usuario sin sexo', 'items' => []],
            'sin_numfvd' => ['label' => 'Sin numfvd FVD', 'items' => []],
            'numfvd_discrepancia' => ['label' => 'numfvd archivo ≠ usuario', 'items' => []],
            'numfvd_duplicado_archivo' => ['label' => 'numfvd duplicado en archivo', 'items' => []],
            'sin_club' => ['label' => 'Sin club para entidad', 'items' => []],
            'torneo_invalido' => ['label' => 'Torneo inválido en archivo', 'items' => []],
        ];
        foreach ($stats['divergencias_detalle'] ?? [] as $d) {
            $t = (string) ($d['tipo'] ?? '');
            if (isset($tipos[$t])) {
                $tipos[$t]['items'][] = $d;
            }
        }
        $bloqueados = count($stats['divergencias_detalle'] ?? []);
        $out = [];
        foreach ($tipos as $key => $info) {
            if ($info['items'] === []) {
                continue;
            }
            $out[] = [
                'tipo' => $key,
                'label' => $info['label'],
                'cantidad' => count($info['items']),
                'items' => $info['items'],
            ];
        }

        return [
            'listos' => (int) ($stats['listos'] ?? 0),
            'bloqueados' => $bloqueados,
            'filas_leidas' => (int) ($stats['filas_leidas'] ?? 0),
            'tipos' => $out,
            'nota' => 'Listos para cargar = filas válidas que aún no están inscritas. Cada divergencia indica origen (archivo Access), tabla destino MySQL, elemento y cómo resolver.',
        ];
    }

    /** @param list<string> $row */
    private static function codigoEquipoDesdeFilaPareja(array $row, int $iCod, int $iAsoc, int $iEqN): string
    {
        $cod = $iCod >= 0 ? trim((string) ($row[$iCod] ?? '')) : '';
        if ($cod !== '') {
            return self::normalizarCodigoEquipo($cod);
        }
        if ($iEqN >= 0) {
            $rawEq = trim((string) ($row[$iEqN] ?? ''));
            if ($rawEq !== '' && preg_match('/^\d{1,3}-\d{1,3}$/', $rawEq)) {
                return self::normalizarCodigoEquipo($rawEq);
            }
        }
        if ($iAsoc >= 0 && $iEqN >= 0) {
            $asoc = (int) preg_replace('/\D/', '', (string) ($row[$iAsoc] ?? ''));
            $eqRaw = trim((string) ($row[$iEqN] ?? ''));
            $eq = (int) preg_replace('/\D/', '', $eqRaw);
            if ($asoc > 0 && $eq > 0 && !preg_match('/^\d{1,3}-\d{1,3}$/', $eqRaw)) {
                return self::normalizarCodigoEquipo(sprintf('%d-%d', $asoc, $eq));
            }
        }

        return '';
    }

    private static function normalizarCodigoEquipo(string $cod): string
    {
        $cod = trim($cod);
        if ($cod === '') {
            return '';
        }
        if (preg_match('/^(\d{1,3})-(\d{1,3})$/', $cod, $m)) {
            return sprintf('%02d-%03d', (int) $m[1], (int) $m[2]);
        }

        return $cod;
    }

    /**
     * @param list<list<string>> $clasiequiRows
     * @return array<string, array{nombre_equipo: string, id_club: int, estatus: ?int}>
     */
    private static function mapaMetaClasiequi(PDO $pdo, array $clasiequiRows, ?int $soloSlot): array
    {
        $parsed = self::separarCabecera($clasiequiRows, [['equipo', 'codigo_equipo']]);
        $h = $parsed['header'];
        $iClub = self::indiceColumna($h, ['club', 'id_club', 'asociacion']);
        $iNom = self::indiceColumna($h, ['nombre', 'nombre_equipo']);
        $iEq = self::indiceColumna($h, ['equipo', 'codigo_equipo', 'codequipo']);
        $iEst = self::indiceColumna($h, ['estatus', 'status']);
        $iTorneo = self::indiceColumnaTorneo($h);
        $map = [];
        foreach ($parsed['data'] as $row) {
            if ($soloSlot !== null && ($iTorneo < 0 || self::slotDesdeFila($row, $iTorneo) !== $soloSlot)) {
                continue;
            }
            $cod = self::normalizarCodigoEquipo($iEq >= 0 ? trim((string) ($row[$iEq] ?? '')) : '');
            if ($cod === '') {
                continue;
            }
            $club = $iClub >= 0 ? (int) preg_replace('/\D/', '', (string) ($row[$iClub] ?? '')) : 0;
            $idClubResuelto = $club > 0 ? (int) (self::resolverIdClubColumnaAccess($pdo, $club) ?? 0) : 0;
            $map[$cod] = [
                'nombre_equipo' => $iNom >= 0 ? trim((string) ($row[$iNom] ?? '')) : '',
                'id_club' => $idClubResuelto > 0 ? $idClubResuelto : $club,
                'asociacion' => $club > 0 ? EntidadFvdCatalogo::etiqueta($club) : '—',
                'estatus' => $iEst >= 0 ? (int) preg_replace('/\D/', '', (string) ($row[$iEst] ?? '')) : null,
            ];
        }

        return $map;
    }

    /**
     * @param list<array<string, mixed>> $jugadores
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private static function armarDetalleEquipoIncompleto(
        string $cod,
        int $titulares,
        int $totalPlantilla,
        int $jugadoresReq,
        array $meta,
        array $jugadores,
        ?int $slot
    ): array {
        $asocCod = (int) ($meta['id_club'] ?? 0);
        if ($asocCod <= 0 && preg_match('/^(\d{1,3})-(\d+)/', $cod, $m)) {
            $asocCod = (int) $m[1];
        }
        $diffTotal = $totalPlantilla - $jugadoresReq;
        $diffTit = $titulares - $jugadoresReq;
        if ($totalPlantilla === 0) {
            $codSit = 'equipo_sin_jugadores_parejas';
            $explicacion = 'clasiequi declara equipo ' . $cod . ' pero parejas inscritas no tiene filas con ese codigo_equipo.';
        } elseif ($totalPlantilla > $jugadoresReq && $titulares >= $jugadoresReq) {
            $codSit = '';
            $explicacion = '';
        } elseif ($totalPlantilla > $jugadoresReq) {
            $codSit = 'equipo_titulares_incompletos';
            $explicacion = 'Hay ' . $totalPlantilla . ' jugadores en parejas pero solo ' . $titulares
                . ' titulares (requeridos ' . $jugadoresReq . '). El excedente irá a banca al importar.';
        } elseif ($totalPlantilla < $jugadoresReq) {
            $codSit = 'equipo_faltan_jugadores';
            $explicacion = 'Hay ' . $totalPlantilla . ' jugador(es) en parejas; faltan '
                . abs($diffTotal) . ' para completar el equipo declarado en clasiequi.';
        } elseif ($titulares !== $jugadoresReq) {
            $codSit = 'equipo_titulares_incompletos';
            $explicacion = 'Cantidad en parejas OK (' . $totalPlantilla . '), pero titulares '
                . $titulares . '/' . $jugadoresReq . ' (revise columna activo/titular/banca).';
        } else {
            $codSit = '';
            $explicacion = '';
        }

        $base = [
            'codigo_equipo' => $cod,
            'nombre_equipo' => (string) ($meta['nombre_equipo'] ?? ''),
            'asociacion_codigo' => $asocCod,
            'asociacion' => $asocCod > 0
                ? EntidadFvdCatalogo::etiqueta($asocCod)
                : (string) ($meta['asociacion'] ?? '—'),
            'estatus_equipo' => $meta['estatus'] ?? null,
            'estatus_equipo_etiqueta' => self::etiquetaEstatusEquipo(
                array_key_exists('estatus', $meta) && $meta['estatus'] !== null ? (int) $meta['estatus'] : null
            ),
            'jugadores_asignados' => $titulares,
            'jugadores_plantilla' => $totalPlantilla,
            'jugadores_requeridos' => $jugadoresReq,
            'formato' => $totalPlantilla . '/' . $jugadoresReq,
            'formato_titulares' => $titulares . '/' . $jugadoresReq,
            'formato_plantilla' => $totalPlantilla . ' en parejas',
            'diferencia' => $diffTotal,
            'diferencia_titulares' => $diffTit,
            'explicacion' => $explicacion,
            'jugadores' => $jugadores,
            'slot' => $slot,
            'elemento' => 'codigo_equipo=' . $cod,
            'valor_archivo' => 'clasiequi: equipo=' . $cod . ', nombre=' . (string) ($meta['nombre_equipo'] ?? ''),
            'valor_sistema' => 'parejas: ' . $totalPlantilla . ' filas, ' . $titulares . ' titulares',
        ];

        if ($codSit !== '') {
            return array_merge($base, self::armarSituacion($codSit, []));
        }

        return $base;
    }

    /** @param array<string, mixed> $det */
    private static function formatearEquipoIncompletoTexto(array $det, ?int $slot, ?array $mapa): string
    {
        $pref = '';
        if ($slot !== null && $mapa !== null) {
            $pref = 'Torneo ' . $slot . ' (' . ($mapa['slots'][$slot]['campeonato_grupo'] ?? '') . '): ';
        }
        $nom = trim((string) ($det['nombre_equipo'] ?? ''));
        $nomPart = $nom !== '' ? ' «' . $nom . '»' : '';

        return $pref . (string) ($det['codigo_equipo'] ?? '') . $nomPart
            . ' → parejas ' . (string) ($det['formato'] ?? '')
            . ' (tit. ' . (string) ($det['formato_titulares'] ?? '') . ')';
    }

    /** @param array<int, array<string, mixed>> $porTorneo */
    private static function fusionarDetalleEquiposIncompletos(array $porTorneo): array
    {
        $out = [];
        foreach ($porTorneo as $slot => $r) {
            foreach ($r['equipos_incompletos_detalle'] ?? [] as $det) {
                $det['slot'] = $det['slot'] ?? $slot;
                $out[] = $det;
            }
        }

        return $out;
    }

    /** @param list<string> $row */
    private static function slotDesdeFila(array $row, int $iTorneo): int
    {
        if ($iTorneo < 0) {
            return 0;
        }

        return CampeonatoTorneoHelper::slotTorneoDesdeCelda((string) ($row[$iTorneo] ?? ''));
    }

    /**
     * @param list<list<string>> $rows
     * @return list<list<string>>
     */
    private static function filtrarArchivoPorSlot(array $rows, int $slot): array
    {
        if ($slot <= 0 || $rows === []) {
            return $rows;
        }

        $max = min(25, count($rows));
        $headerRowIdx = 0;
        $hNorm = self::normalizarFilaEncabezados($rows[0] ?? []);
        for ($r = 0; $r < $max; $r++) {
            $hNorm = self::normalizarFilaEncabezados($rows[$r] ?? []);
            if (self::indiceColumnaTorneo($hNorm) >= 0) {
                $headerRowIdx = $r;
                break;
            }
        }

        $iTorneo = self::indiceColumnaTorneo($hNorm);
        if ($iTorneo < 0) {
            return $rows;
        }

        $out = [$rows[$headerRowIdx]];
        for ($i = $headerRowIdx + 1, $n = count($rows); $i < $n; $i++) {
            $row = $rows[$i];
            if (self::slotDesdeFila($row, $iTorneo) === $slot) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param list<list<string>> $rows
     * @return array<int, int> numfvd => slot (1|2)
     */
    private static function mapaNumfvdSlotDesdeParejas(PDO $pdo, array $rows): array
    {
        $parsed = self::separarCabecera($rows, [['cedula', 'ced']]);
        $h = $parsed['header'];
        $iCed = self::indiceColumna($h, ['cedula', 'ced', 'documento']);
        $iNum = self::indiceColumna($h, ['numfvd', 'num_fvd', 'carnet', 'pareja']);
        $iTorneo = self::indiceColumnaTorneo($h);
        $map = [];
        if ($iCed < 0 || $iTorneo < 0) {
            return $map;
        }

        foreach ($parsed['data'] as $row) {
            $ced = self::normalizarCedula((string) ($row[$iCed] ?? ''));
            if ($ced === '') {
                continue;
            }
            $slot = self::slotDesdeFila($row, $iTorneo);
            if ($slot !== 1 && $slot !== 2) {
                continue;
            }
            $nf = $iNum >= 0 ? (int) preg_replace('/\D/', '', (string) ($row[$iNum] ?? '')) : 0;
            if ($nf <= 0) {
                $usuario = self::resolverUsuarioPorCedula($pdo, $ced);
                if ($usuario === null) {
                    continue;
                }
                $nf = self::resolverNumfvdUsuario($pdo, $usuario, $ced);
            }
            if ($nf > 0) {
                $map[$nf] = $slot;
            }
        }

        return $map;
    }

    /**
     * @param list<list<string>> $rows
     * @return array{1: array<int, true>, 2: array<int, true>}
     */
    private static function extraerNumfvdParejasPorSlot(PDO $pdo, array $rows): array
    {
        $out = [1 => [], 2 => []];
        $parsed = self::separarCabecera($rows, [['cedula', 'ced']]);
        $h = $parsed['header'];
        $iCed = self::indiceColumna($h, ['cedula', 'ced', 'documento']);
        $iNum = self::indiceColumna($h, ['numfvd', 'num_fvd', 'carnet', 'pareja']);
        $iTorneo = self::indiceColumnaTorneo($h);
        if ($iCed < 0) {
            return $out;
        }

        foreach ($parsed['data'] as $row) {
            $ced = self::normalizarCedula((string) ($row[$iCed] ?? ''));
            if ($ced === '') {
                continue;
            }
            $slot = $iTorneo >= 0 ? self::slotDesdeFila($row, $iTorneo) : 0;
            if ($slot !== 1 && $slot !== 2) {
                continue;
            }
            $nf = $iNum >= 0 ? (int) preg_replace('/\D/', '', (string) ($row[$iNum] ?? '')) : 0;
            if ($nf <= 0) {
                $usuario = self::resolverUsuarioPorCedula($pdo, $ced);
                if ($usuario === null) {
                    continue;
                }
                $nf = self::resolverNumfvdUsuario($pdo, $usuario, $ced);
            }
            if ($nf > 0) {
                $out[$slot][$nf] = true;
            }
        }

        return $out;
    }
}
