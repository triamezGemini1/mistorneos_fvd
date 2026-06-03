<?php
declare(strict_types=1);

/**
 * Servicio de Importación Masiva para Torneos.
 * Validación previa, procesamiento en cascada (inscritos → usuarios → crear usuario/club), log de errores.
 * Cumple: .cursorruless (PSR-12, type hinting, prepared statements, try-catch).
 */

require_once __DIR__ . '/InscritosHelper.php';
require_once __DIR__ . '/UserActivationHelper.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/CargaMasivaEquiposSitioService.php';
require_once __DIR__ . '/Repository/ClubRepository.php';

use Lib\Repository\ClubRepository;

class ImportacionMasivaService
{
    public const ESTADO_OMITIR = 'omitir';           // AZUL: ya inscrito
    public const ESTADO_INSCRIBIR = 'inscribir';     // AMARILLO: usuario existe, solo inscribir
    public const ESTADO_CREAR_INSCRIBIR = 'crear_inscribir'; // VERDE: todo nuevo
    public const ESTADO_ERROR = 'error';             // ROJO: datos inválidos

    public const TAMANO_LOTE = 20;

    /**
     * Mismo criterio UTF-8/BOM que la carga masiva de equipos (CargaMasivaEquiposSitioService::normalizarTextoUtf8).
     */
    private static function asegurarUtf8(string $s): string
    {
        return CargaMasivaEquiposSitioService::normalizarTextoUtf8($s);
    }

    /**
     * Sexo en BD: M, F, O. Acepta códigos numéricos típicos en planillas (1=masculino, 2=femenino).
     */
    /**
     * Unifica el código de club desde planilla: homologación de celda + entero como id de `clubes` ("27.0" / "27,0" de Excel).
     */
    public static function normalizarReferenciaClubImport(string $s): string
    {
        $s = ClubRepository::sanitizarReferenciaClubImport($s);
        if ($s === '') {
            return '';
        }
        $probe = str_replace(',', '.', $s);
        if (!is_numeric($probe)) {
            return $s;
        }
        $f = (float) $probe;
        if ($f < 1 || $f > PHP_INT_MAX || (int) $f != $f) {
            return $s;
        }

        return (string) (int) $f;
    }

    /**
     * Mensaje claro cuando no se resolvió el club: distingue id numérico inexistente en la BD de la app
     * frente a texto, y detecta inconsistencia si el id sí existe al consultar de nuevo.
     */
    private static function mensajeErrorClubNoResuelto(PDO $pdo, string $refDesdeFilaNormalizada): string
    {
        $limp = ClubRepository::sanitizarReferenciaClubImport(self::asegurarUtf8($refDesdeFilaNormalizada));
        if ($limp === '') {
            return 'Club vacío tras homologar el valor de la celda (revise mapeo de columnas).';
        }
        $soloId = ctype_digit($limp);
        if (!$soloId) {
            return 'Club no encontrado (id numérico en tabla clubes, cod_org o nombre). Valor homologado: ' . $limp;
        }
        $id = (int) $limp;
        $st = $pdo->prepare('SELECT id, nombre FROM clubes WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        if ($st->fetch(PDO::FETCH_ASSOC)) {
            return 'Inconsistencia al resolver club: el id ' . $id . ' existe en clubes en esta conexión, pero la importación no lo reconoció. Recargue (F5) y reintente; si persiste, informe soporte.';
        }

        return 'No existe club con id ' . $id . ' en la tabla clubes de la base de datos que usa esta aplicación (confirme host/base en config y que coincida con donde vio los ids 27/28). Valor homologado desde archivo: ' . $limp . '.';
    }

    private static function normalizarSexoImportacion($valor): string
    {
        if ($valor === null || $valor === '') {
            return 'M';
        }
        $s = strtoupper(trim((string) $valor));
        if ($s === '' || $s === '0') {
            return 'M';
        }
        if (in_array($s, ['M', 'F', 'O'], true)) {
            return $s;
        }
        if (in_array($s, ['1', 'H', 'MASCULINO', 'HOMBRE', 'MALE', 'M.', 'VARON', 'VARÓN'], true)) {
            return 'M';
        }
        if (in_array($s, ['2', 'FEMENINO', 'MUJER', 'FEMALE', 'F.'], true)) {
            return 'F';
        }
        if (in_array($s, ['3', 'OTRO', 'OTHER', 'NB', 'X'], true)) {
            return 'O';
        }

        return 'M';
    }

    /**
     * Normaliza y valida una fila para importación.
     * Acepta 'organizacion' o 'entidad' en entrada; internamente se guarda en columna `entidad`.
     * Cédula: solo dígitos (preg_replace residuos invisibles).
     * @return array{normalized: array, error: string|null}
     */
    public static function normalizarFila(array $fila, int $indiceFila): array
    {
        $trim = static function ($v) {
            $s = is_string($v) ? trim($v) : (string) $v;
            return $s;
        };
        $nacionalidadRaw = $trim($fila['nacionalidad'] ?? $fila['NACIONALIDAD'] ?? '');
        $nacionalidad = strtoupper($nacionalidadRaw);
        if (!in_array($nacionalidad, ['V', 'E', 'J', 'P'], true)) {
            $nacionalidad = 'V';
        }
        $cedulaRaw = $trim($fila['cedula'] ?? $fila['CEDULA'] ?? $fila['Cédula'] ?? '');
        $cedula = preg_replace('/[^0-9]/', '', $cedulaRaw);
        $nombre = self::asegurarUtf8($trim($fila['nombre'] ?? $fila['NOMBRE'] ?? ''));
        $sexo = self::normalizarSexoImportacion($fila['sexo'] ?? $fila['SEXO'] ?? 'M');
        $fechnac = $trim($fila['fecha_nac'] ?? $fila['fechnac'] ?? $fila['FECHA_NAC'] ?? $fila['fecha_nacimiento'] ?? '');
        $telefono = $trim($fila['telefono'] ?? $fila['celular'] ?? $fila['TELEFONO'] ?? '');
        $email = $trim($fila['email'] ?? $fila['EMAIL'] ?? '');
        // Prioridad alineada con el panel (campo "club" / id_club); luego códigos alternos.
        $refClub = $trim($fila['club'] ?? $fila['CLUB'] ?? $fila['id_club'] ?? $fila['ID_CLUB'] ?? $fila['club_id'] ?? $fila['club_nombre'] ?? '');
        if ($refClub === '') {
            $refClub = $trim($fila['codigo_club'] ?? $fila['club_codigo'] ?? $fila['cod_club'] ?? '');
        }
        $refClub = self::normalizarReferenciaClubImport($refClub);
        $organizacion = $fila['organizacion'] ?? $fila['ORGANIZACION'] ?? $fila['organización'] ?? null;
        if ($organizacion === null) {
            $organizacion = $fila['entidad'] ?? $fila['ENTIDAD'] ?? null;
        }
        $organizacionVal = ($organizacion !== null && $organizacion !== '') ? (int) $organizacion : 0;

        $camposObligatorios = [
            'nacionalidad' => $nacionalidadRaw === '' ? 'Nacionalidad' : null,
            'cedula' => ($cedula === '' || strlen($cedula) < 4) ? 'Cedula (min. 4 digitos)' : null,
            'nombre' => ($nombre === '' || strlen($nombre) < 2) ? 'Nombre' : null,
            'club' => $refClub === '' ? 'Club (id en tabla clubes o nombre)' : null,
            'organizacion' => $organizacionVal < 1 ? 'Organizacion' : null,
        ];
        foreach ($camposObligatorios as $campo => $nombreCampo) {
            if ($nombreCampo !== null) {
                return ['normalized' => [], 'error' => 'Campo obligatorio ' . $nombreCampo . ' ausente o invalido'];
            }
        }

        $normalized = [
            'nacionalidad' => $nacionalidad,
            'cedula' => $cedula,
            'nombre' => $nombre,
            'sexo' => $sexo,
            'fechnac' => $fechnac,
            'telefono' => $telefono,
            'email' => $email,
            'club_nombre' => $refClub,
            'entidad' => $organizacionVal,
        ];
        return ['normalized' => $normalized, 'error' => null];
    }

    /**
     * Valida filas y devuelve estado por cada una (semáforo).
     * @param array<int, array> $filas Array de filas con keys normalizados
     * @return list<array{fila: int, estado: string, mensaje: string}>
     */
    public static function validarFilas(PDO $pdo, int $torneoId, array $filas): array
    {
        $resultado = [];
        $torneoId = (int) $torneoId;
        $clubRepo = new ClubRepository($pdo);
        foreach ($filas as $idx => $fila) {
            $filaNum = $idx + 1;
            $norm = self::normalizarFila($fila, $filaNum);
            if ($norm['error'] !== null) {
                $resultado[] = ['fila' => $filaNum, 'estado' => self::ESTADO_ERROR, 'mensaje' => $norm['error']];
                continue;
            }
            $n = $norm['normalized'];
            $cedula = $n['cedula'];
            $nacionalidad = $n['nacionalidad'];

            // a) ¿Ya inscrito?
            $stmt = $pdo->prepare("SELECT id FROM inscritos WHERE torneo_id = ? AND nacionalidad = ? AND cedula = ? AND estatus != 4 LIMIT 1");
            $stmt->execute([$torneoId, $nacionalidad, $cedula]);
            if ($stmt->fetch()) {
                $resultado[] = ['fila' => $filaNum, 'estado' => self::ESTADO_OMITIR, 'mensaje' => 'Ya inscrito en este torneo'];
                continue;
            }

            $refClubVal = self::asegurarUtf8((string) $n['club_nombre']);
            $idClubArchivo = $clubRepo->resolveFromImportReference($refClubVal);
            if ($idClubArchivo === null) {
                $resultado[] = [
                    'fila' => $filaNum,
                    'estado' => self::ESTADO_ERROR,
                    'mensaje' => self::mensajeErrorClubNoResuelto($pdo, (string) $n['club_nombre']),
                ];
                continue;
            }

            // b) ¿Existe en usuarios?
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE cedula = ? LIMIT 1");
            $stmt->execute([$cedula]);
            if ($stmt->fetch()) {
                $resultado[] = ['fila' => $filaNum, 'estado' => self::ESTADO_INSCRIBIR, 'mensaje' => 'Usuario existe; se inscribirá con club del archivo'];
                continue;
            }

            // c) Todo nuevo: crear usuario e inscribir
            if ((int) ($n['entidad'] ?? 0) < 1) {
                $resultado[] = ['fila' => $filaNum, 'estado' => self::ESTADO_ERROR, 'mensaje' => 'Falta Organización (campo obligatorio)'];
                continue;
            }
            $resultado[] = ['fila' => $filaNum, 'estado' => self::ESTADO_CREAR_INSCRIBIR, 'mensaje' => 'Se creará usuario e inscribirá'];
        }
        return $resultado;
    }

    /**
     * Procesa la importación en lotes. Transacciones por lote.
     * Los fallos de validación (cedula, nombre, club, organizacion) se registran en $errores (errores_importacion).
     * @return array{procesados: int, nuevos: int, omitidos: int, usuarios_actualizados: int, errores: list<array{fila: int, cedula: string, motivo: string}>, txt_errores: string}
     */
    public static function procesarImportacion(PDO $pdo, int $torneoId, array $filas, int $inscritoPor): array
    {
        $procesados = 0;
        $nuevos = 0;
        $omitidos = 0;
        $usuariosActualizados = 0;
        /** @var list<array{fila: int, cedula: string, motivo: string}> errores de importación por fila */
        $errores = [];
        $clubRepo = new ClubRepository($pdo);

        $lotes = array_chunk($filas, self::TAMANO_LOTE, true);
        foreach ($lotes as $lote) {
            $pdo->beginTransaction();
            try {
                $stmtUpdateUsuario = $pdo->prepare("UPDATE usuarios SET nombre = ?, sexo = ? WHERE id = ?");
                foreach ($lote as $idx => $fila) {
                    $filaNum = $idx + 1;
                    $norm = self::normalizarFila($fila, $filaNum);
                    if ($norm['error'] !== null) {
                        $cedulaLog = preg_replace('/[^0-9]/', '', (string) ($fila['cedula'] ?? ''));
                        $errores[] = ['fila' => $filaNum, 'cedula' => $cedulaLog, 'motivo' => $norm['error']];
                        continue;
                    }
                    $n = $norm['normalized'];
                    $cedula = $n['cedula'];
                    $nacionalidad = $n['nacionalidad'];

                    // a) Ya inscrito → omitir
                    $stmt = $pdo->prepare("SELECT id FROM inscritos WHERE torneo_id = ? AND nacionalidad = ? AND cedula = ? AND estatus != 4 LIMIT 1");
                    $stmt->execute([$torneoId, $nacionalidad, $cedula]);
                    if ($stmt->fetch()) {
                        $omitidos++;
                        continue;
                    }

                    $refClubArchivo = self::asegurarUtf8((string) ($n['club_nombre'] ?? ''));
                    $idClubTorneo = $clubRepo->resolveFromImportReference($refClubArchivo);
                    if ($idClubTorneo === null) {
                        $errores[] = [
                            'fila' => $filaNum,
                            'cedula' => $cedula,
                            'motivo' => self::mensajeErrorClubNoResuelto($pdo, (string) ($n['club_nombre'] ?? '')),
                        ];
                        continue;
                    }

                    // b) Buscar en usuarios
                    $stmt = $pdo->prepare("SELECT id, club_id, nombre, sexo FROM usuarios WHERE cedula = ? LIMIT 1");
                    $stmt->execute([$cedula]);
                    $rowUser = $stmt->fetch(PDO::FETCH_ASSOC);
                    $idUsuario = $rowUser ? (int) $rowUser['id'] : null;

                    if ($idUsuario === null) {
                        // c) Organización obligatoria; el club viene del archivo (debe existir en BD)
                        $organizacionVal = (int) ($n['entidad'] ?? 0);
                        if ($organizacionVal < 1) {
                            $errores[] = ['fila' => $filaNum, 'cedula' => $cedula, 'motivo' => 'Organización obligatoria ausente'];
                            continue;
                        }

                        $email = $n['email'] !== '' ? $n['email'] : ('user' . $cedula . '@inscrito.local');
                        $username = $nacionalidad . $cedula;
                        $sufijo = '';
                        $idxU = 0;
                        while (true) {
                            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE username = ?");
                            $stmt->execute([$username . $sufijo]);
                            if (!$stmt->fetch()) {
                                break;
                            }
                            $idxU++;
                            $sufijo = '_' . $idxU;
                        }
                        $username = $username . $sufijo;
                        $password = strlen($cedula) >= 6 ? $cedula : str_pad($cedula, 6, '0', STR_PAD_LEFT);
                        $fechnac = $n['fechnac'] !== '' ? $n['fechnac'] : null;

                        $createData = [
                            'username' => $username,
                            'password' => $password,
                            'role' => 'usuario',
                            'nombre' => $n['nombre'],
                            'cedula' => $cedula,
                            'nacionalidad' => $nacionalidad,
                            'sexo' => $n['sexo'],
                            'fechnac' => $fechnac,
                            'email' => $email,
                            'celular' => $n['telefono'],
                            'club_id' => $idClubTorneo,
                            '_allow_club_for_usuario' => true,
                        ];
                        if ($n['entidad'] !== null && $n['entidad'] > 0) {
                            $createData['entidad'] = (int) $n['entidad'];
                        }
                        require_once __DIR__ . '/NumfvdHelper.php';
                        $nfImp = NumfvdHelper::resolverDesdeCedula($pdo, $cedula);
                        if ($nfImp > 0) {
                            $createData['numfvd'] = $nfImp;
                        }
                        $create = Security::createUser($createData);
                        if (!empty($create['errors'])) {
                            $errores[] = ['fila' => $filaNum, 'cedula' => $cedula, 'motivo' => implode(', ', $create['errors'])];
                            continue;
                        }
                        $idUsuario = (int) ($create['user_id'] ?? 0);
                        if ($idUsuario <= 0) {
                            $errores[] = ['fila' => $filaNum, 'cedula' => $cedula, 'motivo' => 'No se pudo crear el usuario'];
                            continue;
                        }
                        UserActivationHelper::activateUser($pdo, $idUsuario);
                        $nuevos++;
                    } else {
                        // Usuario existente: homologar datos clave desde Excel de inscripción masiva.
                        $nombreExcel = self::asegurarUtf8((string)($n['nombre'] ?? ''));
                        $sexoExcel = (string)($n['sexo'] ?? 'M');
                        $nombreActual = self::asegurarUtf8((string)($rowUser['nombre'] ?? ''));
                        $sexoActual = strtoupper(trim((string)($rowUser['sexo'] ?? 'M')));
                        if ($nombreExcel !== '' && ($nombreActual !== $nombreExcel || $sexoActual !== $sexoExcel)) {
                            $stmtUpdateUsuario->execute([$nombreExcel, $sexoExcel, $idUsuario]);
                            if ($stmtUpdateUsuario->rowCount() > 0) {
                                $usuariosActualizados++;
                            }
                        }
                    }

                    try {
                        InscritosHelper::insertarInscrito($pdo, [
                            'id_usuario' => $idUsuario,
                            'torneo_id' => $torneoId,
                            'id_club' => $idClubTorneo,
                            'estatus' => 1,
                            'inscrito_por' => $inscritoPor,
                            'numero' => 0,
                            'nacionalidad' => $nacionalidad,
                            'cedula' => $cedula,
                        ]);
                        $procesados++;
                    } catch (Throwable $e) {
                        $errores[] = ['fila' => $filaNum, 'cedula' => $cedula, 'motivo' => $e->getMessage()];
                    }
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                foreach ($lote as $idx => $fila) {
                    $filaNum = $idx + 1;
                    $cedulaLog = preg_replace('/[^0-9]/', '', (string) ($fila['cedula'] ?? ''));
                    $errores[] = ['fila' => $filaNum, 'cedula' => $cedulaLog, 'motivo' => 'Error en lote: ' . $e->getMessage()];
                }
            }
        }

        $txtErrores = '';
        if (!empty($errores)) {
            $bom = "\xEF\xBB\xBF";
            $lines = [];
            foreach ($errores as $err) {
                $numFila = (int) $err['fila'];
                $cedula = (string) ($err['cedula'] ?? '');
                $cedula = preg_replace('/[^0-9]/', '', $cedula);
                $motivo = str_replace(["\r", "\n"], [' ', ' '], (string) ($err['motivo'] ?? ''));
                $motivo = self::asegurarUtf8($motivo);
                $lines[] = sprintf("Fila %d - Cedula: %s - Motivo: %s", $numFila, $cedula, $motivo);
            }
            $txtErrores = $bom . implode("\n", $lines) . "\n";
            if (!mb_check_encoding($txtErrores, 'UTF-8')) {
                $txtErrores = mb_convert_encoding($txtErrores, 'UTF-8', mb_detect_encoding($txtErrores, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true) ?: 'UTF-8');
            }
        }

        return [
            'procesados' => $procesados,
            'nuevos' => $nuevos,
            'omitidos' => $omitidos,
            'usuarios_actualizados' => $usuariosActualizados,
            'errores' => $errores,
            'txt_errores' => $txtErrores,
        ];
    }
}
