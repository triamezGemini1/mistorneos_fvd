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

        foreach ($parsed['data'] as $row) {
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
                    $stats['divergencias_detalle'][] = self::armarDivergenciaPareja(
                        'torneo_invalido',
                        $ced,
                        null,
                        self::metaArchivoPareja($row, $ctxCols, 0),
                        'Valor de columna torneo distinto de 1 (hombres) o 2 (mujeres).'
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
                    'No hay usuario con esta cédula en la plataforma. Debe afiliar o registrar al atleta antes de importar.'
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
                        'El usuario no tiene sexo registrado; no puede ubicarse en torneo masculino/femenino.'
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
                        . ($sexo === 'M' ? 'masculino' : 'femenino') . '.'
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
                    'El usuario no tiene numfvd/carnet FVD asignado ni viene en el archivo.'
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
                    'El numfvd del archivo (' . $numfvdArch . ') no coincide con el del usuario (' . $nfUsuario . ').'
                );
            }
            if ($mapa !== null && $slot > 0) {
                if (isset($numfvdVistosPorSlot[$slot][$numfvd])) {
                    $stats['numfvd_duplicados_resueltos'][] = (string) $numfvd . ' (torneo ' . $slot . ')';
                }
                $numfvdVistosPorSlot[$slot][$numfvd] = true;
            } elseif (isset($numfvdVistos[$numfvd])) {
                $stats['numfvd_duplicados_resueltos'][] = (string) $numfvd;
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
                    'La entidad ' . $entidadUsu . ' del usuario no tiene club/asociación resoluble en el sistema.'
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
        foreach ($parsed['data'] as $row) {
            $nf = (int) preg_replace('/\D/', '', (string) ($row[$iPareja] ?? ''));
            if ($nf <= 0) {
                continue;
            }
            $stats['filas_leidas']++;
            $unicos[$nf] = true;

            if ($mapa !== null) {
                $slot = $iTorneo >= 0 ? self::slotDesdeFila($row, $iTorneo) : (int) ($mapaNfSlot[$nf] ?? 0);
                if ($slot !== 1 && $slot !== 2) {
                    $stats['numfvd_sin_inscrito'][] = (string) $nf . ' (torneo no identificado)';
                    continue;
                }
                $stats['por_torneo'][$slot]['filas']++;
                $unicosPorSlot[$slot][$nf] = true;
                $ins = $inscritosPorSlot[$slot];
                $extra = $extraPorSlot[$slot];
                if (!isset($ins[$nf]) && !isset($extra[$nf])) {
                    $stats['numfvd_sin_inscrito'][] = (string) $nf . ' (torneo ' . $slot . ')';
                }
            } elseif (!isset($inscritos[$nf]) && !isset($extra[$nf])) {
                $stats['numfvd_sin_inscrito'][] = (string) $nf;
            }
        }

        $stats['numfvd_unicos'] = count($unicos);
        if ($mapa !== null) {
            $stats['por_torneo'][1]['numfvd_unicos'] = count($unicosPorSlot[1]);
            $stats['por_torneo'][2]['numfvd_unicos'] = count($unicosPorSlot[2]);
        }
        $stats['numfvd_sin_inscrito'] = array_values(array_unique($stats['numfvd_sin_inscrito']));
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
            return $stats;
        }

        $mapa = CampeonatoTorneoHelper::mapaImportacionCampeonatoGenero($pdo, $torneoId);
        if ($mapa !== null) {
            $stats['campeonato_genero'] = true;
            if ($iTorneo < 0) {
                $stats['errores_columnas'][] = 'Campeonato por género: columna torneo obligatoria en clasiequi (1 = hombres, 2 = mujeres).';
                return $stats;
            }
        }

        $jugadoresReq = self::jugadoresPorUnidad($modalidad);

        foreach ($parsed['data'] as $row) {
            $club = (int) preg_replace('/\D/', '', (string) ($row[$iClub] ?? ''));
            $codEq = trim((string) ($row[$iEq] ?? ''));
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

        $plantilla = self::mapaPlantillaDesdeParejas($pdo, $parejasRows, $jugadoresReq, $soloSlot);
        foreach (self::conteoTitularesPorCodigoEquipo($pdo, $torneoId) as $cod => $n) {
            if (!isset($plantilla[$cod])) {
                $plantilla[$cod] = ['titulares' => 0, 'total' => 0, 'jugadores' => []];
            }
            $plantilla[$cod]['titulares'] = max($plantilla[$cod]['titulares'], $n);
        }

        $metaEquipo = self::mapaMetaClasiequi($clasiequiRows, $soloSlot);

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
            $cod = $iEqC >= 0 ? trim((string) ($row[$iEqC] ?? '')) : '';
            if ($cod === '') {
                continue;
            }
            $info = $plantilla[$cod] ?? ['titulares' => 0, 'total' => 0, 'jugadores' => []];
            $titulares = (int) ($info['titulares'] ?? 0);
            $total = (int) ($info['total'] ?? 0);
            if ($titulares !== $jugadoresReq) {
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
                $incompletos[] = $cod . ' → titulares ' . $titulares . '/' . $jugadoresReq
                    . ($total > $titulares ? ' (plantilla ' . $total . ')' : '');
                $incompletosDetalle[] = $det;
            }
        }

        return [
            'ok' => $incompletos === [],
            'torneo_id' => $torneoId,
            'slot' => $soloSlot,
            'jugadores_requeridos' => $jugadoresReq,
            'equipos_incompletos' => $incompletos,
            'equipos_incompletos_detalle' => $incompletosDetalle,
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
        bool $reemplazar
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
            'inscritos_omitidos' => 0,
            'equipos_insertados' => 0,
            'partiresul_insertados' => 0,
            'partiresul_reemplazados' => 0,
            'por_torneo' => [],
        ];

        $pdo->beginTransaction();
        try {
            if (self::requiereClasiequi($modalidad) && $clasiequiRows !== null) {
                if ($mapa !== null) {
                    foreach ([1, 2] as $slot) {
                        $tid = CampeonatoTorneoHelper::torneoIdDesdeSlot($mapa['slots'], $slot);
                        $filas = self::filtrarArchivoPorSlot($clasiequiRows, $slot);
                        $res['equipos_insertados'] += self::importarClasiequi($pdo, $tid, $filas, $registradoPor);
                    }
                } else {
                    $res['equipos_insertados'] = self::importarClasiequi($pdo, $torneoId, $clasiequiRows, $registradoPor);
                }
            }

            if ($mapa !== null) {
                foreach ([1, 2] as $slot) {
                    $tid = CampeonatoTorneoHelper::torneoIdDesdeSlot($mapa['slots'], $slot);
                    $filas = self::filtrarArchivoPorSlot($parejasRows, $slot);
                    $ins = self::importarParejasInscritas($pdo, $tid, $filas, $modalidad, $registradoPor);
                    $res['inscritos_insertados'] += $ins['insertados'];
                    $res['inscritos_omitidos'] += $ins['omitidos'];
                    $res['por_torneo'][$slot] = $ins;
                }
            } else {
                $ins = self::importarParejasInscritas($pdo, $torneoId, $parejasRows, $modalidad, $registradoPor);
                $res['inscritos_insertados'] = $ins['insertados'];
                $res['inscritos_omitidos'] = $ins['omitidos'];
            }

            if ($reemplazar) {
                $torneosBorrar = $mapa !== null ? $mapa['torneo_ids'] : [$torneoId];
                $stDel = $pdo->prepare('DELETE FROM partiresul WHERE id_torneo = ?');
                foreach ($torneosBorrar as $tidB) {
                    $stDel->execute([(int) $tidB]);
                    $res['partiresul_reemplazados'] += $stDel->rowCount();
                }
            }

            if ($mapa !== null) {
                foreach ([1, 2] as $slot) {
                    $tid = CampeonatoTorneoHelper::torneoIdDesdeSlot($mapa['slots'], $slot);
                    $filas = self::filtrarArchivoPorSlot($partiRows, $slot);
                    $fecha = self::fechaTorneo($pdo, $tid) ?: $fechaTorneo;
                    $res['partiresul_insertados'] += self::importarParti2017(
                        $pdo,
                        $tid,
                        $filas,
                        $registradoPor,
                        $fecha
                    );
                }
            } else {
                $res['partiresul_insertados'] = self::importarParti2017(
                    $pdo,
                    $torneoId,
                    $partiRows,
                    $registradoPor,
                    $fechaTorneo
                );
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return $res;
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
        return AtletasAdminSyncService::prepararUsuariosParaImportacion($pdo, $cedulas, $ejecutar);
    }

    /** Sincroniza todo el padrón atletas → usuarios (crear faltantes + actualizar existentes). */
    public static function sincronizarPadronCompletoAtletas(PDO $pdo, bool $ejecutar = false): array
    {
        return AtletasAdminSyncService::sincronizarPadronCompletoAtletasUsuarios($pdo, $ejecutar);
    }

    /**
     * @param list<list<string>> $rows
     * @return array{insertados: int, omitidos: int}
     */
    private static function importarParejasInscritas(PDO $pdo, int $torneoId, array $rows, int $modalidad, int $inscritoPor): array
    {
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

        $insertados = 0;
        $omitidos = 0;

        foreach ($parsed['data'] as $row) {
            $ced = self::normalizarCedula($iCed >= 0 ? ($row[$iCed] ?? '') : '');
            if ($ced === '') {
                continue;
            }

            $usuario = self::resolverUsuarioPorCedula($pdo, $ced);
            if ($usuario === null) {
                continue;
            }
            $idUsr = (int) $usuario['id'];
            $stDup = $pdo->prepare('SELECT id FROM inscritos WHERE torneo_id = ? AND id_usuario = ? LIMIT 1');
            $stDup->execute([$torneoId, $idUsr]);
            if ($stDup->fetch()) {
                $omitidos++;
                continue;
            }

            $nfUsuario = self::resolverNumfvdUsuario($pdo, $usuario, $ced);
            $numfvdArch = $iNumfvd >= 0 ? (int) preg_replace('/\D/', '', (string) ($row[$iNumfvd] ?? '')) : 0;
            $numfvd = $numfvdArch > 0 ? $numfvdArch : $nfUsuario;
            if ($numfvd <= 0) {
                continue;
            }

            $idClub = self::clubDesdeEntidadUsuario($pdo, $usuario);
            $entidadUsu = (int) ($usuario['entidad'] ?? 0);
            if ($idClub === null || $idClub <= 0) {
                continue;
            }

            $codEq = '000-000';
            if ($modalidad !== 1) {
                $codEq = $iCod >= 0 ? trim((string) ($row[$iCod] ?? '')) : '';
                if ($codEq === '' && $iAsoc >= 0 && $iEqN >= 0) {
                    $asoc = (int) preg_replace('/\D/', '', (string) ($row[$iAsoc] ?? ''));
                    if ($asoc <= 0 && $entidadUsu > 0) {
                        $asoc = $entidadUsu;
                    }
                    $eq = (int) preg_replace('/\D/', '', (string) ($row[$iEqN] ?? ''));
                    if ($asoc > 0 && $eq > 0) {
                        $codEq = sprintf('%03d-%03d', $asoc, $eq);
                    }
                }
                if ($codEq === '') {
                    $codEq = '000-001';
                }
            }

            $activoMesa = InscritosHelper::ACTIVO_MESA_SI;
            if ($modalidad !== 1 && $codEq !== '000-000') {
                $activoMesa = self::resolverActivoMesaFilaPareja($row, $iActivo, $codEq, $jugadoresReq, $titularesAuto);
            }

            $datosIns = [
                'id_usuario' => $idUsr,
                'torneo_id' => $torneoId,
                'id_club' => $idClub,
                'estatus' => InscritosHelper::ESTATUS_CONFIRMADO_NUM,
                'inscrito_por' => $inscritoPor,
                'numero' => 0,
                'codigo_equipo' => $codEq,
                'cedula' => preg_replace('/\D/', '', (string) ($usuario['cedula'] ?? $ced)),
                'nacionalidad' => $usuario['nacionalidad'] ?? 'V',
                'numfvd' => $numfvd,
                'activo_mesa' => $activoMesa,
            ];
            if ($entidadUsu > 0) {
                $datosIns['entidad_id'] = $entidadUsu;
            }
            InscritosHelper::insertarInscrito($pdo, $datosIns);
            $insertados++;
        }

        return ['insertados' => $insertados, 'omitidos' => $omitidos];
    }

    /**
     * @param list<list<string>> $rows
     */
    private static function importarClasiequi(PDO $pdo, int $torneoId, array $rows, int $creadoPor): int
    {
        $parsed = self::separarCabecera($rows, [['club'], ['nombre'], ['equipo']]);
        $h = $parsed['header'];
        $iClub = self::indiceColumna($h, ['club', 'id_club']);
        $iNom = self::indiceColumna($h, ['nombre', 'nombre_equipo']);
        $iEq = self::indiceColumna($h, ['equipo', 'codigo_equipo']);
        $iClave = self::indiceColumna($h, ['clave', 'consecutivo', 'consecutivo_club']);
        $iEst = self::indiceColumna($h, ['estatus', 'status']);

        $insertados = 0;
        foreach ($parsed['data'] as $row) {
            $idClub = (int) preg_replace('/\D/', '', (string) ($row[$iClub] ?? ''));
            $nombre = trim((string) ($row[$iNom] ?? ''));
            $codEq = trim((string) ($row[$iEq] ?? ''));
            if ($idClub <= 0 || $nombre === '' || $codEq === '') {
                continue;
            }
            $st = $pdo->prepare('SELECT id FROM equipos WHERE id_torneo = ? AND codigo_equipo = ? LIMIT 1');
            $st->execute([$torneoId, $codEq]);
            if ($st->fetch()) {
                continue;
            }
            $consec = $iClave >= 0 ? (int) preg_replace('/\D/', '', (string) ($row[$iClave] ?? '')) : 1;
            $est = $iEst >= 0 ? (int) preg_replace('/\D/', '', (string) ($row[$iEst] ?? '0')) : 0;
            $stI = $pdo->prepare(
                'INSERT INTO equipos (id_torneo, id_club, nombre_equipo, codigo_equipo, consecutivo_club, estatus, creado_por)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stI->execute([$torneoId, $idClub, $nombre, $codEq, max(1, $consec), $est, $creadoPor > 0 ? $creadoPor : null]);
            $insertados++;
        }

        return $insertados;
    }

    /**
     * @param list<list<string>> $rows
     */
    private static function importarParti2017(PDO $pdo, int $torneoId, array $rows, int $registradoPor, string $fechaTorneo): int
    {
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
        /** @var array<string, array{partida: int, mesa: int, jugadores: list<array<string, mixed>>, obs: string}> $mesasPorClave */
        $mesasPorClave = [];

        foreach ($parsed['data'] as $row) {
            $nf = (int) preg_replace('/\D/', '', (string) ($row[$iPareja] ?? ''));
            if ($nf <= 0 || !isset($mapInsc[$nf])) {
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

        return $n;
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
        return 'Formato titulares/' . $jugadoresReq . ': titulares = jugadores activos para mesas (activo_mesa=1); '
            . 'puede haber suplentes en banca (activo_mesa=0) con el mismo código de equipo. '
            . 'Se requieren exactamente ' . $jugadoresReq . ' titulares por equipo.';
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
            'mesa',
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
     */
    private static function resolverActivoMesaFilaPareja(
        array $row,
        int $iActivo,
        string $cod,
        int $jugadoresReq,
        array &$titularesAsignadosPorCod
    ): int {
        if ($iActivo >= 0) {
            return self::parseActivoMesaCelda((string) ($row[$iActivo] ?? '1'));
        }
        $n = (int) ($titularesAsignadosPorCod[$cod] ?? 0);
        $activo = $n < $jugadoresReq ? InscritosHelper::ACTIVO_MESA_SI : InscritosHelper::ACTIVO_MESA_BANCA;
        if ($activo === InscritosHelper::ACTIVO_MESA_SI) {
            $titularesAsignadosPorCod[$cod] = $n + 1;
        }

        return $activo;
    }

    /**
     * @param list<list<string>> $parejasRows
     * @return array<string, array{titulares: int, total: int, jugadores: list<array<string, mixed>>}>
     */
    private static function mapaPlantillaDesdeParejas(
        PDO $pdo,
        array $parejasRows,
        int $jugadoresReq,
        ?int $soloSlot
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

        $map = [];
        $titularesAuto = [];

        foreach ($parsedP['data'] as $row) {
            if ($soloSlot !== null && ($iTorneoP < 0 || self::slotDesdeFila($row, $iTorneoP) !== $soloSlot)) {
                continue;
            }
            $cod = self::codigoEquipoDesdeFilaPareja($row, $iCod, $iAsoc, $iEqN);
            if ($cod === '') {
                continue;
            }
            if (!isset($map[$cod])) {
                $map[$cod] = ['titulares' => 0, 'total' => 0, 'jugadores' => []];
            }
            $activo = self::resolverActivoMesaFilaPareja($row, $iActivo, $cod, $jugadoresReq, $titularesAuto);
            $map[$cod]['total']++;
            if ($activo === InscritosHelper::ACTIVO_MESA_SI) {
                $map[$cod]['titulares']++;
            }

            $ced = $iCedP >= 0 ? self::normalizarCedula((string) ($row[$iCedP] ?? '')) : '';
            $asocCod = $iAsoc >= 0 ? (int) preg_replace('/\D/', '', (string) ($row[$iAsoc] ?? '')) : 0;
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
            $map[$cod]['jugadores'][] = [
                'cedula' => $ced,
                'nombre' => $nombre,
                'numfvd' => $nf,
                'asociacion_codigo' => $asocCod,
                'asociacion' => $asocCod > 0 ? EntidadFvdCatalogo::etiqueta($asocCod) : '—',
                'activo_mesa' => $activo,
                'rol' => $activo === InscritosHelper::ACTIVO_MESA_SI ? 'Titular' : 'Banca',
            ];
        }

        return $map;
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
        string $explicacion
    ): array {
        return [
            'tipo' => $tipo,
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
            'explicacion' => $explicacion,
        ];
    }

    /** @param array<string, mixed> $stats */
    private static function resumirDivergenciasParejas(array $stats): array
    {
        $tipos = [
            'cedula_sin_usuario' => ['label' => 'Cédula sin usuario', 'items' => []],
            'sexo_no_coincide' => ['label' => 'Sexo ≠ torneo del archivo', 'items' => []],
            'sin_sexo' => ['label' => 'Usuario sin sexo', 'items' => []],
            'sin_numfvd' => ['label' => 'Sin numfvd FVD', 'items' => []],
            'numfvd_discrepancia' => ['label' => 'numfvd archivo ≠ usuario', 'items' => []],
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
            'nota' => 'Listos para cargar = filas válidas que aún no están inscritas. Las divergencias impiden importar hasta corregirlas.',
        ];
    }

    /** @param list<string> $row */
    private static function codigoEquipoDesdeFilaPareja(array $row, int $iCod, int $iAsoc, int $iEqN): string
    {
        $cod = $iCod >= 0 ? trim((string) ($row[$iCod] ?? '')) : '';
        if ($cod === '' && $iAsoc >= 0 && $iEqN >= 0) {
            $asoc = (int) preg_replace('/\D/', '', (string) ($row[$iAsoc] ?? ''));
            $eq = (int) preg_replace('/\D/', '', (string) ($row[$iEqN] ?? ''));
            if ($asoc > 0 && $eq > 0) {
                $cod = sprintf('%03d-%03d', $asoc, $eq);
            }
        }

        return $cod;
    }

    /**
     * @param list<list<string>> $clasiequiRows
     * @return array<string, array{nombre_equipo: string, id_club: int, estatus: ?int}>
     */
    private static function mapaMetaClasiequi(array $clasiequiRows, ?int $soloSlot): array
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
            $cod = $iEq >= 0 ? trim((string) ($row[$iEq] ?? '')) : '';
            if ($cod === '') {
                continue;
            }
            $club = $iClub >= 0 ? (int) preg_replace('/\D/', '', (string) ($row[$iClub] ?? '')) : 0;
            $map[$cod] = [
                'nombre_equipo' => $iNom >= 0 ? trim((string) ($row[$iNom] ?? '')) : '',
                'id_club' => $club,
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
        $diff = $titulares - $jugadoresReq;
        if ($titulares > $jugadoresReq) {
            $explicacion = 'Hay ' . $titulares . ' titulares (activos para mesas); deben ser exactamente '
                . $jugadoresReq . '. Plantilla total: ' . $totalPlantilla . ' jugadores.';
        } elseif ($titulares < $jugadoresReq) {
            $explicacion = 'Hay ' . $titulares . ' titulares; faltan ' . abs($diff)
                . ' para completar el equipo. Plantilla total: ' . $totalPlantilla . '.';
        } else {
            $explicacion = '';
        }

        return [
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
            'formato' => $titulares . '/' . $jugadoresReq,
            'formato_plantilla' => $totalPlantilla . ' en plantilla',
            'diferencia' => $diff,
            'explicacion' => $explicacion,
            'jugadores' => $jugadores,
            'slot' => $slot,
        ];
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
            . ' → ' . (string) ($det['formato'] ?? '') . ' jugadores';
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
