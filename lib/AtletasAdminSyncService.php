<?php
declare(strict_types=1);

final class AtletasAdminSyncService
{
    /**
     * Homologa textos a UTF-8 en atletas/usuarios para evitar pérdida de caracteres.
     *
     * @return array{
     *   atletas_revisados:int,
     *   atletas_actualizados:int,
     *   usuarios_revisados:int,
     *   usuarios_actualizados:int
     * }
     */
    public static function homologarUtf8AtletasUsuarios(PDO $pdoMain): array
    {
        $out = [
            'atletas_revisados' => 0,
            'atletas_actualizados' => 0,
            'usuarios_revisados' => 0,
            'usuarios_actualizados' => 0,
        ];

        $pdoMain->beginTransaction();
        try {
            $stA = $pdoMain->query("SELECT id, nombre, email, celular FROM atletas ORDER BY id ASC");
            $upA = $pdoMain->prepare("UPDATE atletas SET nombre = ?, email = ?, celular = ? WHERE id = ?");
            foreach ($stA->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out['atletas_revisados']++;
                $id = (int)($r['id'] ?? 0);
                $nombreOld = (string)($r['nombre'] ?? '');
                $emailOld = (string)($r['email'] ?? '');
                $celOld = (string)($r['celular'] ?? '');

                $nombreNew = self::normalizarTextoUtf8($nombreOld);
                $emailNew = self::normalizarTextoUtf8($emailOld);
                $celNew = self::normalizarTextoUtf8($celOld);

                if ($nombreNew !== $nombreOld || $emailNew !== $emailOld || $celNew !== $celOld) {
                    $upA->execute([$nombreNew, $emailNew, $celNew, $id]);
                    if ($upA->rowCount() > 0) {
                        $out['atletas_actualizados']++;
                    }
                }
            }

            $stU = $pdoMain->query("SELECT id, nombre, username, email, celular FROM usuarios ORDER BY id ASC");
            $upU = $pdoMain->prepare("UPDATE usuarios SET nombre = ?, username = ?, email = ?, celular = ? WHERE id = ?");
            foreach ($stU->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out['usuarios_revisados']++;
                $id = (int)($r['id'] ?? 0);
                $nombreOld = (string)($r['nombre'] ?? '');
                $userOld = (string)($r['username'] ?? '');
                $emailOld = (string)($r['email'] ?? '');
                $celOld = (string)($r['celular'] ?? '');

                $nombreNew = self::normalizarTextoUtf8($nombreOld);
                $userNew = self::normalizarTextoUtf8($userOld);
                $emailNew = self::normalizarTextoUtf8($emailOld);
                $celNew = self::normalizarTextoUtf8($celOld);

                if ($nombreNew !== $nombreOld || $userNew !== $userOld || $emailNew !== $emailOld || $celNew !== $celOld) {
                    $upU->execute([$nombreNew, $userNew, $emailNew, $celNew, $id]);
                    if ($upU->rowCount() > 0) {
                        $out['usuarios_actualizados']++;
                    }
                }
            }

            $pdoMain->commit();
        } catch (Throwable $e) {
            if ($pdoMain->inTransaction()) {
                $pdoMain->rollBack();
            }
            throw $e;
        }

        return $out;
    }

    /**
     * Crea usuarios faltantes para atletas que aún no existan en usuarios (por cédula),
     * usando el procedimiento estándar Security::createUser.
     *
     * @return array{
     *   atletas_procesados:int,
     *   ya_existian:int,
     *   creados:int,
     *   errores:int,
     *   detalle_errores:list<string>
     * }
     */
    public static function incluirAtletasFaltantesComoUsuarios(PDO $pdoMain): array
    {
        require_once __DIR__ . '/security.php';

        $atletas = $pdoMain->query(
            "SELECT id, cedula, sexo, numfvd, asociacion, nombre, celular, email, fechnac
             FROM atletas
             ORDER BY id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $cedulasUsuarios = [];
        $stmtCed = $pdoMain->query("SELECT cedula FROM usuarios WHERE cedula IS NOT NULL AND cedula != ''");
        foreach ($stmtCed->fetchAll(PDO::FETCH_COLUMN) as $ced) {
            $c = self::normalizarCedula((string)$ced);
            if ($c !== '') {
                $cedulasUsuarios[$c] = true;
            }
        }

        $usernamesUsados = [];
        $stmtUsr = $pdoMain->query("SELECT username FROM usuarios");
        foreach ($stmtUsr->fetchAll(PDO::FETCH_COLUMN) as $u) {
            $uu = trim((string)$u);
            if ($uu !== '') {
                $usernamesUsados[$uu] = true;
            }
        }

        $reporte = [
            'atletas_procesados' => count($atletas),
            'ya_existian' => 0,
            'creados' => 0,
            'errores' => 0,
            'detalle_errores' => [],
        ];

        foreach ($atletas as $a) {
            $cedula = self::normalizarCedula((string)($a['cedula'] ?? ''));
            if ($cedula === '') {
                $reporte['errores']++;
                $reporte['detalle_errores'][] = 'Atleta ID ' . (int)($a['id'] ?? 0) . ': cédula vacía o inválida';
                continue;
            }

            if (isset($cedulasUsuarios[$cedula])) {
                $reporte['ya_existian']++;
                continue;
            }

            $idAtleta = (int)($a['id'] ?? 0);
            $numfvd = (int)($a['numfvd'] ?? 0);
            $clubId = (int)($a['asociacion'] ?? 0);
            $entidad = $clubId;
            $organizacionId = self::resolverOrganizacionPorEntidad($pdoMain, $entidad);
            $sexo = self::normalizarSexo($a['sexo'] ?? 'M');
            $nombre = trim((string)($a['nombre'] ?? ''));
            if ($nombre === '') {
                $nombre = 'Atleta ' . $cedula;
            }
            $nombre = mb_substr($nombre, 0, 62);

            $usernameBase = 'user00' . ($numfvd > 0 ? (string)$numfvd : (string)max(1, $idAtleta));
            $username = self::usernameUnico($usernameBase, $usernamesUsados);

            $email = trim((string)($a['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $email = $username . '@atletas.local';
            }

            $password = strlen($cedula) >= 6 ? $cedula : str_pad($cedula, 6, '0', STR_PAD_LEFT);

            require_once __DIR__ . '/AsociacionAdminHelper.php';
            $clubIdResuelto = $clubId > 0
                ? (int) (AsociacionAdminHelper::resolverClubIdDesdeEntidad($pdoMain, $entidad) ?? 0)
                : 0;

            $data = [
                'username' => $username,
                'password' => $password,
                'role' => 'usuario',
                'status' => 0,
                'nombre' => $nombre,
                'cedula' => $cedula,
                'nacionalidad' => 'V',
                'sexo' => $sexo,
                'numfvd' => $numfvd,
                'email' => $email,
                'celular' => self::nullableString($a['celular'] ?? null),
                'fechnac' => self::normalizarFecha($a['fechnac'] ?? null),
                'club_id' => $clubIdResuelto > 0 ? $clubIdResuelto : ($entidad > 0 ? $entidad : null),
                'entidad' => $entidad > 0 ? $entidad : null,
                '_allow_club_for_usuario' => $clubIdResuelto > 0,
            ];
            if (self::usuariosTieneCodOrg($pdoMain)) {
                $data['cod_org'] = $organizacionId;
            }

            $create = Security::createUser($data);
            if (!empty($create['success'])) {
                $newUserId = (int)($create['user_id'] ?? 0);
                if ($newUserId > 0) {
                    if (self::usuariosTieneCodOrg($pdoMain)) {
                        $upd = $pdoMain->prepare('UPDATE usuarios SET numfvd = ?, cod_org = ? WHERE id = ?');
                        $upd->execute([$numfvd, $organizacionId, $newUserId]);
                    } else {
                        $upd = $pdoMain->prepare('UPDATE usuarios SET numfvd = ? WHERE id = ?');
                        $upd->execute([$numfvd, $newUserId]);
                    }
                }
                $reporte['creados']++;
                $cedulasUsuarios[$cedula] = true;
                continue;
            }

            $reporte['errores']++;
            $reporte['detalle_errores'][] = 'Atleta ID ' . $idAtleta . ' (cédula ' . $cedula . '): ' . implode(', ', (array)($create['errors'] ?? ['error desconocido']));
        }

        return $reporte;
    }

    /**
     * Copia completa de tabla atletas: BD secundaria -> BD principal.
     *
     * @return array{copiados:int,columnas:list<string>}
     */
    public static function copiarAtletasDesdeConverma(PDO $pdoMain, PDO $pdoSecondary): array
    {
        $colsMain = self::getColumns($pdoMain, 'atletas');
        $colsSec = self::getColumns($pdoSecondary, 'atletas');
        $columnas = array_values(array_intersect($colsMain, $colsSec));
        if ($columnas === []) {
            throw new RuntimeException('No hay columnas comunes entre atletas (converma/mistorneos).');
        }

        $selectCols = implode(', ', array_map(static fn ($c) => "`{$c}`", $columnas));
        $stmtRead = $pdoSecondary->query("SELECT {$selectCols} FROM atletas ORDER BY id ASC");
        $rows = $stmtRead->fetchAll(PDO::FETCH_ASSOC);

        $pdoMain->beginTransaction();
        try {
            $pdoMain->exec("DELETE FROM atletas");

            if (!empty($rows)) {
                $insertCols = implode(', ', array_map(static fn ($c) => "`{$c}`", $columnas));
                $placeholders = implode(', ', array_fill(0, count($columnas), '?'));
                $stmtInsert = $pdoMain->prepare("INSERT INTO atletas ({$insertCols}) VALUES ({$placeholders})");
                foreach ($rows as $row) {
                    $params = [];
                    foreach ($columnas as $c) {
                        $val = $row[$c] ?? null;
                        if (is_string($val)) {
                            $val = self::normalizarTextoUtf8($val);
                        }
                        $params[] = $val;
                    }
                    $stmtInsert->execute($params);
                }
            }

            $pdoMain->commit();
        } catch (Throwable $e) {
            if ($pdoMain->inTransaction()) {
                $pdoMain->rollBack();
            }
            throw $e;
        }

        return [
            'copiados' => count($rows),
            'columnas' => $columnas,
        ];
    }

    /**
     * Sincroniza usuarios desde atletas por cédula y genera reporte.
     *
     * @return array{
     *   total_atletas:int,
     *   coincidencias:int,
     *   actualizados:int,
     *   sin_cambios:int,
     *   no_encontradas:int,
     *   celulares_actualizados:int,
     *   email_actualizados:int,
     *   fechnac_actualizados:int,
     *   club_id_actualizados:int,
     *   numfvd_actualizados:int,
     *   sexo_actualizados:int,
     *   por_club:array<int,array{total:int,m:int,f:int,o:int}>,
     *   csv_path:string
     * }
     */
    public static function sincronizarUsuariosDesdeAtletas(PDO $pdoMain, string $csvDir): array
    {
        $hasUsuarioCodOrg = self::usuariosTieneCodOrg($pdoMain);
        $selectUsuCols = $hasUsuarioCodOrg
            ? 'id, cedula, sexo, numfvd, club_id, entidad, cod_org, celular, email, fechnac'
            : 'id, cedula, sexo, numfvd, club_id, entidad, celular, email, fechnac';

        $usuarios = $pdoMain->query(
            "SELECT {$selectUsuCols} FROM usuarios"
        )->fetchAll(PDO::FETCH_ASSOC);

        $usuariosPorCedula = [];
        foreach ($usuarios as $u) {
            $ced = self::normalizarCedula((string)($u['cedula'] ?? ''));
            if ($ced !== '') {
                $usuariosPorCedula[$ced] = $u;
            }
        }

        $atletas = $pdoMain->query(
            "SELECT id, cedula, sexo, numfvd, asociacion, celular, email, fechnac FROM atletas"
        )->fetchAll(PDO::FETCH_ASSOC);

        $reporte = [
            'total_atletas' => count($atletas),
            'coincidencias' => 0,
            'actualizados' => 0,
            'sin_cambios' => 0,
            'no_encontradas' => 0,
            'celulares_actualizados' => 0,
            'email_actualizados' => 0,
            'fechnac_actualizados' => 0,
            'club_id_actualizados' => 0,
            'entidad_actualizados' => 0,
            'cod_org_actualizados' => 0,
            'numfvd_actualizados' => 0,
            'sexo_actualizados' => 0,
            'por_club' => [],
            'csv_path' => '',
        ];

        $noEncontradas = [];

        $stmtUpdate = $hasUsuarioCodOrg
            ? $pdoMain->prepare(
                "UPDATE usuarios
             SET sexo = ?, numfvd = ?, club_id = ?, entidad = ?, cod_org = ?, celular = ?, email = ?, fechnac = ?
             WHERE id = ?"
            )
            : $pdoMain->prepare(
                "UPDATE usuarios
             SET sexo = ?, numfvd = ?, club_id = ?, entidad = ?, celular = ?, email = ?, fechnac = ?
             WHERE id = ?"
            );

        $pdoMain->beginTransaction();
        try {
            foreach ($atletas as $a) {
                $ced = self::normalizarCedula((string)($a['cedula'] ?? ''));
                if ($ced === '' || !isset($usuariosPorCedula[$ced])) {
                    $reporte['no_encontradas']++;
                    $noEncontradas[] = [
                        'cedula' => $ced,
                        'atleta_id' => (int)($a['id'] ?? 0),
                        'numfvd' => (string)($a['numfvd'] ?? ''),
                    ];
                    continue;
                }

                $reporte['coincidencias']++;
                $u = $usuariosPorCedula[$ced];
                $uid = (int)($u['id'] ?? 0);

                $nuevoSexo = self::normalizarSexo($a['sexo'] ?? 'M');
                $nuevoNumfvd = (int)($a['numfvd'] ?? 0);
                $nuevaEntidad = (int)($a['asociacion'] ?? 0);
                $nuevoClubId = $nuevaEntidad; // Primera fase: club_id queda con código de entidad
                $nuevaOrganizacionId = self::resolverOrganizacionPorEntidad($pdoMain, $nuevaEntidad);
                $nuevoCel = self::nullableString($a['celular'] ?? null);
                $nuevoEmail = self::nullableString($a['email'] ?? null);
                $nuevaFechnac = self::normalizarFecha($a['fechnac'] ?? null);

                $oldSexo = self::normalizarSexo($u['sexo'] ?? 'M');
                $oldNumfvd = (int)($u['numfvd'] ?? 0);
                $oldClubId = (int)($u['club_id'] ?? 0);
                $oldEntidad = (int)($u['entidad'] ?? 0);
                $oldOrganizacionId = $hasUsuarioCodOrg ? (int)($u['cod_org'] ?? 0) : 0;
                $oldCel = self::nullableString($u['celular'] ?? null);
                $oldEmail = self::nullableString($u['email'] ?? null);
                $oldFechnac = self::normalizarFecha($u['fechnac'] ?? null);

                $huboCambio = false;
                if ($oldSexo !== $nuevoSexo) {
                    $reporte['sexo_actualizados']++;
                    $huboCambio = true;
                }
                if ($oldNumfvd !== $nuevoNumfvd) {
                    $reporte['numfvd_actualizados']++;
                    $huboCambio = true;
                }
                if ($oldClubId !== $nuevoClubId) {
                    $reporte['club_id_actualizados']++;
                    $huboCambio = true;
                }
                if ($oldEntidad !== $nuevaEntidad) {
                    $reporte['entidad_actualizados']++;
                    $huboCambio = true;
                }
                if ($hasUsuarioCodOrg && $oldOrganizacionId !== $nuevaOrganizacionId) {
                    $reporte['cod_org_actualizados']++;
                    $huboCambio = true;
                }
                if ($oldCel !== $nuevoCel) {
                    $reporte['celulares_actualizados']++;
                    $huboCambio = true;
                }
                if ($oldEmail !== $nuevoEmail) {
                    $reporte['email_actualizados']++;
                    $huboCambio = true;
                }
                if ($oldFechnac !== $nuevaFechnac) {
                    $reporte['fechnac_actualizados']++;
                    $huboCambio = true;
                }

                $clubKey = $nuevoClubId;
                if (!isset($reporte['por_club'][$clubKey])) {
                    $reporte['por_club'][$clubKey] = ['total' => 0, 'm' => 0, 'f' => 0, 'o' => 0];
                }
                $reporte['por_club'][$clubKey]['total']++;
                if ($nuevoSexo === 'F') {
                    $reporte['por_club'][$clubKey]['f']++;
                } elseif ($nuevoSexo === 'O') {
                    $reporte['por_club'][$clubKey]['o']++;
                } else {
                    $reporte['por_club'][$clubKey]['m']++;
                }

                if (!$huboCambio) {
                    $reporte['sin_cambios']++;
                    continue;
                }

                if ($hasUsuarioCodOrg) {
                    $stmtUpdate->execute([
                        $nuevoSexo,
                        $nuevoNumfvd,
                        $nuevoClubId,
                        $nuevaEntidad,
                        $nuevaOrganizacionId,
                        $nuevoCel,
                        $nuevoEmail,
                        $nuevaFechnac,
                        $uid,
                    ]);
                } else {
                    $stmtUpdate->execute([
                        $nuevoSexo,
                        $nuevoNumfvd,
                        $nuevoClubId,
                        $nuevaEntidad,
                        $nuevoCel,
                        $nuevoEmail,
                        $nuevaFechnac,
                        $uid,
                    ]);
                }
                if ($stmtUpdate->rowCount() > 0) {
                    $reporte['actualizados']++;
                }
            }

            $pdoMain->commit();
        } catch (Throwable $e) {
            if ($pdoMain->inTransaction()) {
                $pdoMain->rollBack();
            }
            throw $e;
        }

        ksort($reporte['por_club']);
        $reporte['csv_path'] = self::guardarCsvNoEncontradas($csvDir, $noEncontradas);
        return $reporte;
    }

    public static function existeTablaAtletas(PDO $pdo): bool
    {
        try {
            return (bool) $pdo->query("SHOW TABLES LIKE 'atletas'")->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Verifica y opcionalmente sincroniza usuarios desde atletas para cédulas de un archivo de importación.
     *
     * @param list<string> $cedulasImport
     * @return array<string, mixed>
     */
    public static function prepararUsuariosParaImportacion(PDO $pdo, array $cedulasImport, bool $ejecutar = false): array
    {
        require_once __DIR__ . '/security.php';

        $cedulasSet = [];
        foreach ($cedulasImport as $raw) {
            $c = self::normalizarCedula((string) $raw);
            if ($c !== '') {
                $cedulasSet[$c] = true;
            }
        }

        $reporte = [
            'ok' => false,
            'tabla_atletas' => self::existeTablaAtletas($pdo),
            'ejecutado' => $ejecutar,
            'total_cedulas' => count($cedulasSet),
            'en_atletas' => 0,
            'sin_atleta' => [],
            'en_usuarios_inicial' => 0,
            'pendiente_crear' => 0,
            'pendiente_actualizar' => 0,
            'usuarios_creados' => 0,
            'usuarios_actualizados' => 0,
            'numfvd_actualizados' => 0,
            'sin_usuario_final' => [],
            'detalle_cambios' => [],
            'detalle_pendientes' => [],
            'errores' => [],
        ];

        if (!$reporte['tabla_atletas']) {
            $reporte['errores'][] = 'No existe la tabla atletas. Sincronice el padrón de atletas antes de importar.';
            return $reporte;
        }
        if ($cedulasSet === []) {
            $reporte['errores'][] = 'No se encontraron cédulas en el archivo.';
            return $reporte;
        }

        $atletasPorCedula = [];
        $stA = $pdo->query(
            'SELECT id, cedula, sexo, numfvd, asociacion, nombre, celular, email, fechnac FROM atletas'
        );
        while ($a = $stA->fetch(PDO::FETCH_ASSOC)) {
            $c = self::normalizarCedula((string) ($a['cedula'] ?? ''));
            if ($c !== '' && !isset($atletasPorCedula[$c])) {
                $atletasPorCedula[$c] = $a;
            }
        }

        foreach (array_keys($cedulasSet) as $ced) {
            if (!isset($atletasPorCedula[$ced])) {
                $reporte['sin_atleta'][] = $ced;
            } else {
                $reporte['en_atletas']++;
            }
        }

        $hasUsuarioCodOrg = self::usuariosTieneCodOrg($pdo);
        $selectUsuCols = $hasUsuarioCodOrg
            ? 'id, cedula, sexo, numfvd, club_id, entidad, cod_org, celular, email, fechnac, nombre'
            : 'id, cedula, sexo, numfvd, club_id, entidad, celular, email, fechnac, nombre';
        $usuariosPorCedula = [];
        $stU = $pdo->query("SELECT {$selectUsuCols} FROM usuarios");
        while ($u = $stU->fetch(PDO::FETCH_ASSOC)) {
            $c = self::normalizarCedula((string) ($u['cedula'] ?? ''));
            if ($c !== '' && !isset($usuariosPorCedula[$c])) {
                $usuariosPorCedula[$c] = $u;
            }
        }
        $reporte['en_usuarios_inicial'] = count(array_filter(
            array_keys($cedulasSet),
            static fn (string $ced): bool => isset($usuariosPorCedula[$ced])
        ));

        $usernamesUsados = [];
        foreach ($pdo->query('SELECT username FROM usuarios')->fetchAll(PDO::FETCH_COLUMN) as $uu) {
            $t = trim((string) $uu);
            if ($t !== '') {
                $usernamesUsados[$t] = true;
            }
        }

        $stmtUpdate = $hasUsuarioCodOrg
            ? $pdo->prepare(
                'UPDATE usuarios SET sexo = ?, numfvd = ?, club_id = ?, entidad = ?, cod_org = ?, celular = ?, email = ?, fechnac = ?, nombre = COALESCE(NULLIF(?, \'\'), nombre) WHERE id = ?'
            )
            : $pdo->prepare(
                'UPDATE usuarios SET sexo = ?, numfvd = ?, club_id = ?, entidad = ?, celular = ?, email = ?, fechnac = ?, nombre = COALESCE(NULLIF(?, \'\'), nombre) WHERE id = ?'
            );

        if ($ejecutar) {
            $pdo->beginTransaction();
        }

        try {
            foreach (array_keys($cedulasSet) as $ced) {
                if (!isset($atletasPorCedula[$ced])) {
                    continue;
                }
                $a = $atletasPorCedula[$ced];
                $numfvdAtleta = (int) ($a['numfvd'] ?? 0);
                $nombreAtleta = trim((string) ($a['nombre'] ?? ''));

                if (!isset($usuariosPorCedula[$ced])) {
                    $reporte['pendiente_crear']++;
                    if (!$ejecutar) {
                        $reporte['detalle_pendientes'][] = [
                            'cedula' => $ced,
                            'accion' => 'crear',
                            'numfvd_atleta' => $numfvdAtleta,
                            'nombre' => $nombreAtleta,
                        ];
                        continue;
                    }

                    $create = self::crearUsuarioDesdeAtleta($pdo, $a, $usernamesUsados);
                    if (!$create['ok']) {
                        $reporte['errores'][] = 'Cédula ' . $ced . ': ' . ($create['error'] ?? 'No se pudo crear usuario');
                        continue;
                    }
                    $reporte['usuarios_creados']++;
                    $reporte['detalle_cambios'][] = [
                        'cedula' => $ced,
                        'accion' => 'creado',
                        'numfvd' => $numfvdAtleta,
                        'nombre' => $nombreAtleta,
                    ];
                    $stRef = $pdo->prepare("SELECT {$selectUsuCols} FROM usuarios WHERE id = ? LIMIT 1");
                    $stRef->execute([(int) $create['user_id']]);
                    $usuariosPorCedula[$ced] = $stRef->fetch(PDO::FETCH_ASSOC) ?: [];
                    continue;
                }

                $u = $usuariosPorCedula[$ced];
                $cambio = self::diffUsuarioAtleta($u, $a, $hasUsuarioCodOrg);
                if ($cambio === []) {
                    continue;
                }

                $reporte['pendiente_actualizar']++;
                if (!$ejecutar) {
                    $reporte['detalle_pendientes'][] = array_merge(
                        ['cedula' => $ced, 'accion' => 'actualizar', 'nombre' => $nombreAtleta],
                        $cambio
                    );
                    continue;
                }

                $nuevoSexo = self::normalizarSexo($a['sexo'] ?? 'M');
                $nuevoNumfvd = (int) ($a['numfvd'] ?? 0);
                $nuevaEntidad = (int) ($a['asociacion'] ?? 0);
                $nuevoClubId = $nuevaEntidad;
                $nuevaOrganizacionId = self::resolverOrganizacionPorEntidad($pdo, $nuevaEntidad);
                $nuevoCel = self::nullableString($a['celular'] ?? null);
                $nuevoEmail = self::nullableString($a['email'] ?? null);
                $nuevaFechnac = self::normalizarFecha($a['fechnac'] ?? null);
                $nuevoNombre = $nombreAtleta !== '' ? mb_substr($nombreAtleta, 0, 62) : trim((string) ($u['nombre'] ?? ''));

                if ($hasUsuarioCodOrg) {
                    $stmtUpdate->execute([
                        $nuevoSexo, $nuevoNumfvd, $nuevoClubId, $nuevaEntidad, $nuevaOrganizacionId,
                        $nuevoCel, $nuevoEmail, $nuevaFechnac, $nuevoNombre, (int) ($u['id'] ?? 0),
                    ]);
                } else {
                    $stmtUpdate->execute([
                        $nuevoSexo, $nuevoNumfvd, $nuevoClubId, $nuevaEntidad,
                        $nuevoCel, $nuevoEmail, $nuevaFechnac, $nuevoNombre, (int) ($u['id'] ?? 0),
                    ]);
                }

                if ($stmtUpdate->rowCount() > 0) {
                    $reporte['usuarios_actualizados']++;
                    if (isset($cambio['numfvd'])) {
                        $reporte['numfvd_actualizados']++;
                    }
                    $reporte['detalle_cambios'][] = array_merge(
                        ['cedula' => $ced, 'accion' => 'actualizado'],
                        $cambio
                    );
                }
            }

            if ($ejecutar) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ejecutar && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        foreach (array_keys($cedulasSet) as $ced) {
            if (isset($atletasPorCedula[$ced]) && !isset($usuariosPorCedula[$ced])) {
                if ($ejecutar) {
                    $reporte['sin_usuario_final'][] = $ced;
                }
            }
        }

        if (!$ejecutar) {
            $reporte['pendiente_crear'] = count(array_filter(
                $reporte['detalle_pendientes'],
                static fn (array $d): bool => ($d['accion'] ?? '') === 'crear'
            ));
            $reporte['pendiente_actualizar'] = count(array_filter(
                $reporte['detalle_pendientes'],
                static fn (array $d): bool => ($d['accion'] ?? '') === 'actualizar'
            ));
        }

        $reporte['ok'] = $reporte['sin_atleta'] === []
            && $reporte['errores'] === []
            && ($ejecutar
                ? ($reporte['sin_usuario_final'] === [] && $reporte['en_usuarios_inicial'] + $reporte['usuarios_creados'] >= $reporte['en_atletas'])
                : true);

        return $reporte;
    }

    /**
     * Sincroniza todo el padrón atletas → usuarios: crea faltantes y actualiza existentes (numfvd, sexo, entidad…).
     *
     * @return array<string, mixed>
     */
    public static function sincronizarPadronCompletoAtletasUsuarios(PDO $pdo, bool $ejecutar = false): array
    {
        require_once __DIR__ . '/security.php';

        $reporte = [
            'ok' => false,
            'tabla_atletas' => self::existeTablaAtletas($pdo),
            'ejecutado' => $ejecutar,
            'total_atletas' => 0,
            'sin_cedula_valida' => 0,
            'en_usuarios_inicial' => 0,
            'alineados' => 0,
            'pendiente_crear' => 0,
            'pendiente_actualizar' => 0,
            'usuarios_creados' => 0,
            'usuarios_actualizados' => 0,
            'numfvd_actualizados' => 0,
            'detalle_pendientes' => [],
            'detalle_cambios' => [],
            'errores' => [],
        ];

        if (!$reporte['tabla_atletas']) {
            $reporte['errores'][] = 'No existe la tabla atletas.';
            return $reporte;
        }

        $atletas = $pdo->query(
            'SELECT id, cedula, sexo, numfvd, asociacion, nombre, celular, email, fechnac FROM atletas ORDER BY id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        $reporte['total_atletas'] = count($atletas);

        $hasUsuarioCodOrg = self::usuariosTieneCodOrg($pdo);
        $selectUsuCols = $hasUsuarioCodOrg
            ? 'id, cedula, sexo, numfvd, club_id, entidad, cod_org, celular, email, fechnac, nombre'
            : 'id, cedula, sexo, numfvd, club_id, entidad, celular, email, fechnac, nombre';

        $usuariosPorCedula = [];
        $stU = $pdo->query("SELECT {$selectUsuCols} FROM usuarios");
        while ($u = $stU->fetch(PDO::FETCH_ASSOC)) {
            $c = self::normalizarCedula((string) ($u['cedula'] ?? ''));
            if ($c !== '' && !isset($usuariosPorCedula[$c])) {
                $usuariosPorCedula[$c] = $u;
            }
        }

        $usernamesUsados = [];
        foreach ($pdo->query('SELECT username FROM usuarios')->fetchAll(PDO::FETCH_COLUMN) as $uu) {
            $t = trim((string) $uu);
            if ($t !== '') {
                $usernamesUsados[$t] = true;
            }
        }

        $stmtUpdate = $hasUsuarioCodOrg
            ? $pdo->prepare(
                'UPDATE usuarios SET sexo = ?, numfvd = ?, club_id = ?, entidad = ?, cod_org = ?, celular = ?, email = ?, fechnac = ?, nombre = COALESCE(NULLIF(?, \'\'), nombre) WHERE id = ?'
            )
            : $pdo->prepare(
                'UPDATE usuarios SET sexo = ?, numfvd = ?, club_id = ?, entidad = ?, celular = ?, email = ?, fechnac = ?, nombre = COALESCE(NULLIF(?, \'\'), nombre) WHERE id = ?'
            );

        $maxDetalle = 60;

        if ($ejecutar) {
            $pdo->beginTransaction();
        }

        try {
            foreach ($atletas as $a) {
                $ced = self::normalizarCedula((string) ($a['cedula'] ?? ''));
                if ($ced === '') {
                    $reporte['sin_cedula_valida']++;
                    continue;
                }

                $nombreAtleta = trim((string) ($a['nombre'] ?? ''));

                if (!isset($usuariosPorCedula[$ced])) {
                    if (!$ejecutar) {
                        $reporte['pendiente_crear']++;
                        if (count($reporte['detalle_pendientes']) < $maxDetalle) {
                            $reporte['detalle_pendientes'][] = [
                                'cedula' => $ced,
                                'accion' => 'crear',
                                'numfvd_atleta' => (int) ($a['numfvd'] ?? 0),
                                'nombre' => $nombreAtleta,
                            ];
                        }
                        continue;
                    }

                    $create = self::crearUsuarioDesdeAtleta($pdo, $a, $usernamesUsados);
                    if (!$create['ok']) {
                        $reporte['errores'][] = 'Cédula ' . $ced . ': ' . ($create['error'] ?? 'No se pudo crear usuario');
                        continue;
                    }
                    $reporte['usuarios_creados']++;
                    $reporte['detalle_cambios'][] = [
                        'cedula' => $ced,
                        'accion' => 'creado',
                        'numfvd' => (int) ($a['numfvd'] ?? 0),
                        'nombre' => $nombreAtleta,
                    ];
                    $stRef = $pdo->prepare("SELECT {$selectUsuCols} FROM usuarios WHERE id = ? LIMIT 1");
                    $stRef->execute([(int) $create['user_id']]);
                    $usuariosPorCedula[$ced] = $stRef->fetch(PDO::FETCH_ASSOC) ?: [];
                    continue;
                }

                $reporte['en_usuarios_inicial']++;
                $u = $usuariosPorCedula[$ced];
                $cambio = self::diffUsuarioAtleta($u, $a, $hasUsuarioCodOrg);
                if ($cambio === []) {
                    $reporte['alineados']++;
                    continue;
                }

                if (!$ejecutar) {
                    $reporte['pendiente_actualizar']++;
                    if (count($reporte['detalle_pendientes']) < $maxDetalle) {
                        $reporte['detalle_pendientes'][] = array_merge(
                            ['cedula' => $ced, 'accion' => 'actualizar', 'nombre' => $nombreAtleta],
                            $cambio
                        );
                    }
                    continue;
                }

                $nuevoSexo = self::normalizarSexo($a['sexo'] ?? 'M');
                $nuevoNumfvd = (int) ($a['numfvd'] ?? 0);
                $nuevaEntidad = (int) ($a['asociacion'] ?? 0);
                $nuevoClubId = $nuevaEntidad;
                $nuevaOrganizacionId = self::resolverOrganizacionPorEntidad($pdo, $nuevaEntidad);
                $nuevoCel = self::nullableString($a['celular'] ?? null);
                $nuevoEmail = self::nullableString($a['email'] ?? null);
                $nuevaFechnac = self::normalizarFecha($a['fechnac'] ?? null);
                $nuevoNombre = $nombreAtleta !== '' ? mb_substr($nombreAtleta, 0, 62) : trim((string) ($u['nombre'] ?? ''));

                if ($hasUsuarioCodOrg) {
                    $stmtUpdate->execute([
                        $nuevoSexo, $nuevoNumfvd, $nuevoClubId, $nuevaEntidad, $nuevaOrganizacionId,
                        $nuevoCel, $nuevoEmail, $nuevaFechnac, $nuevoNombre, (int) ($u['id'] ?? 0),
                    ]);
                } else {
                    $stmtUpdate->execute([
                        $nuevoSexo, $nuevoNumfvd, $nuevoClubId, $nuevaEntidad,
                        $nuevoCel, $nuevoEmail, $nuevaFechnac, $nuevoNombre, (int) ($u['id'] ?? 0),
                    ]);
                }

                if ($stmtUpdate->rowCount() > 0) {
                    $reporte['usuarios_actualizados']++;
                    if (isset($cambio['numfvd'])) {
                        $reporte['numfvd_actualizados']++;
                    }
                    if (count($reporte['detalle_cambios']) < $maxDetalle) {
                        $reporte['detalle_cambios'][] = array_merge(
                            ['cedula' => $ced, 'accion' => 'actualizado'],
                            $cambio
                        );
                    }
                } else {
                    $reporte['alineados']++;
                }
            }

            if ($ejecutar) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ejecutar && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if (!$ejecutar) {
            // Recalcular totales (detalle_pendientes puede estar truncado)
            $reporte['pendiente_crear'] = 0;
            $reporte['pendiente_actualizar'] = 0;
            $reporte['en_usuarios_inicial'] = 0;
            $reporte['alineados'] = 0;
            foreach ($atletas as $a) {
                $ced = self::normalizarCedula((string) ($a['cedula'] ?? ''));
                if ($ced === '') {
                    continue;
                }
                if (!isset($usuariosPorCedula[$ced])) {
                    $reporte['pendiente_crear']++;
                    continue;
                }
                $reporte['en_usuarios_inicial']++;
                $cambio = self::diffUsuarioAtleta($usuariosPorCedula[$ced], $a, $hasUsuarioCodOrg);
                if ($cambio === []) {
                    $reporte['alineados']++;
                } else {
                    $reporte['pendiente_actualizar']++;
                }
            }
        } else {
            $reporte['pendiente_crear'] = 0;
            $reporte['pendiente_actualizar'] = 0;
        }

        $reporte['ok'] = $reporte['errores'] === [];

        return $reporte;
    }

    /**
     * @param array<string, mixed> $usuario
     * @param array<string, mixed> $atleta
     * @return array<string, mixed>
     */
    private static function diffUsuarioAtleta(array $usuario, array $atleta, bool $hasCodOrg): array
    {
        $diff = [];
        $oldNf = (int) ($usuario['numfvd'] ?? 0);
        $newNf = (int) ($atleta['numfvd'] ?? 0);
        if ($oldNf !== $newNf) {
            $diff['numfvd'] = ['antes' => $oldNf, 'despues' => $newNf];
        }
        $oldSexo = self::normalizarSexo($usuario['sexo'] ?? 'M');
        $newSexo = self::normalizarSexo($atleta['sexo'] ?? 'M');
        if ($oldSexo !== $newSexo) {
            $diff['sexo'] = ['antes' => $oldSexo, 'despues' => $newSexo];
        }
        $oldEnt = (int) ($usuario['entidad'] ?? 0);
        $newEnt = (int) ($atleta['asociacion'] ?? 0);
        if ($oldEnt !== $newEnt) {
            $diff['entidad'] = ['antes' => $oldEnt, 'despues' => $newEnt];
        }

        return $diff;
    }

    /**
     * @param array<string, mixed> $atleta
     * @param array<string, true> $usernamesUsados
     * @return array{ok: bool, user_id?: int, error?: string}
     */
    private static function crearUsuarioDesdeAtleta(PDO $pdo, array $atleta, array &$usernamesUsados): array
    {
        $cedula = self::normalizarCedula((string) ($atleta['cedula'] ?? ''));
        if ($cedula === '') {
            return ['ok' => false, 'error' => 'Cédula inválida'];
        }

        $idAtleta = (int) ($atleta['id'] ?? 0);
        $numfvd = (int) ($atleta['numfvd'] ?? 0);
        $clubId = (int) ($atleta['asociacion'] ?? 0);
        $entidad = $clubId;
        $organizacionId = self::resolverOrganizacionPorEntidad($pdo, $entidad);
        require_once __DIR__ . '/AsociacionAdminHelper.php';
        $clubIdResuelto = $clubId > 0
            ? (int) (AsociacionAdminHelper::resolverClubIdDesdeEntidad($pdo, $entidad) ?? 0)
            : 0;
        $sexo = self::normalizarSexo($atleta['sexo'] ?? 'M');
        $nombre = trim((string) ($atleta['nombre'] ?? ''));
        if ($nombre === '') {
            $nombre = 'Atleta ' . $cedula;
        }
        $nombre = mb_substr($nombre, 0, 62);

        $usernameBase = 'user00' . ($numfvd > 0 ? (string) $numfvd : (string) max(1, $idAtleta));
        $username = self::usernameUnico($usernameBase, $usernamesUsados);

        $email = trim((string) ($atleta['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = $username . '@atletas.local';
        }

        $password = strlen($cedula) >= 6 ? $cedula : str_pad($cedula, 6, '0', STR_PAD_LEFT);

        $data = [
            'username' => $username,
            'password' => $password,
            'role' => 'usuario',
            'status' => 0,
            'nombre' => $nombre,
            'cedula' => $cedula,
            'nacionalidad' => 'V',
            'sexo' => $sexo,
            'numfvd' => $numfvd,
            'email' => $email,
            'celular' => self::nullableString($atleta['celular'] ?? null),
            'fechnac' => self::normalizarFecha($atleta['fechnac'] ?? null),
            'club_id' => $clubIdResuelto > 0 ? $clubIdResuelto : ($entidad > 0 ? $entidad : null),
            'entidad' => $entidad > 0 ? $entidad : null,
            '_allow_club_for_usuario' => $clubIdResuelto > 0,
        ];
        if (self::usuariosTieneCodOrg($pdo)) {
            $data['cod_org'] = $organizacionId;
        }

        $create = Security::createUser($data);
        if (empty($create['success'])) {
            return ['ok' => false, 'error' => implode(', ', (array) ($create['errors'] ?? ['error desconocido']))];
        }

        $newUserId = (int) ($create['user_id'] ?? 0);
        if ($newUserId > 0) {
            if (self::usuariosTieneCodOrg($pdo)) {
                $upd = $pdo->prepare('UPDATE usuarios SET numfvd = ?, cod_org = ? WHERE id = ?');
                $upd->execute([$numfvd, $organizacionId, $newUserId]);
            } else {
                $upd = $pdo->prepare('UPDATE usuarios SET numfvd = ? WHERE id = ?');
                $upd->execute([$numfvd, $newUserId]);
            }
        }

        return ['ok' => true, 'user_id' => $newUserId];
    }

    private static function usuariosTieneCodOrg(PDO $pdo): bool
    {
        static $cached = [];
        $key = spl_object_hash($pdo);
        if (array_key_exists($key, $cached)) {
            return $cached[$key];
        }
        try {
            $cached[$key] = (bool)$pdo->query("SHOW COLUMNS FROM usuarios LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $ignored) {
            $cached[$key] = false;
        }

        return $cached[$key];
    }

    private static function getColumns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $cols = [];
        foreach ($rows as $r) {
            $f = (string)($r['Field'] ?? '');
            if ($f !== '') {
                $cols[] = $f;
            }
        }
        return $cols;
    }

    private static function guardarCsvNoEncontradas(string $dir, array $rows): string
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'cedulas_no_encontradas_' . date('Ymd_His') . '.csv';
        $fh = fopen($path, 'w');
        if ($fh === false) {
            return '';
        }
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['cedula', 'atleta_id', 'numfvd']);
        foreach ($rows as $r) {
            fputcsv($fh, [$r['cedula'] ?? '', $r['atleta_id'] ?? 0, $r['numfvd'] ?? '']);
        }
        fclose($fh);
        return $path;
    }

    private static function normalizarCedula(string $cedula): string
    {
        $s = trim(preg_replace('/\s+/', '', $cedula));
        if ($s === '') {
            return '';
        }
        if (preg_match('/^([VEJPvejp])(\d+)$/', $s, $m)) {
            $s = $m[2];
        } else {
            $s = preg_replace('/\D/', '', $s) ?? '';
        }
        if ($s === '') {
            return '';
        }
        // Forma canónica: 2550415 y 02550415 son la misma persona
        $s = ltrim($s, '0');

        return $s !== '' ? $s : '0';
    }

    private static function normalizarSexo($sexo): string
    {
        $s = strtoupper(trim((string)$sexo));
        if (in_array($s, ['M', 'F', 'O'], true)) {
            return $s;
        }
        if ($s === '2' || strpos($s, 'F') !== false) {
            return 'F';
        }
        if ($s === '3' || strpos($s, 'O') !== false) {
            return 'O';
        }
        return 'M';
    }

    private static function normalizarFecha($fecha): ?string
    {
        $s = trim((string)$fecha);
        if ($s === '') {
            return null;
        }
        $ts = strtotime($s);
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d', $ts);
    }

    private static function nullableString($value): ?string
    {
        $s = self::normalizarTextoUtf8(trim((string)$value));
        return $s === '' ? null : $s;
    }

    /**
     * Convierte texto a UTF-8 válido, quita BOM e intenta Latin-1/Windows-1252.
     */
    private static function normalizarTextoUtf8(string $s): string
    {
        if ($s === '') {
            return '';
        }
        if (strncmp($s, "\xEF\xBB\xBF", 3) === 0) {
            $s = substr($s, 3);
        }
        if (function_exists('mb_check_encoding') && !mb_check_encoding($s, 'UTF-8')) {
            if (function_exists('mb_detect_encoding') && function_exists('mb_convert_encoding')) {
                $enc = mb_detect_encoding($s, ['UTF-8', 'Windows-1252', 'ISO-8859-1', 'ISO-8859-15'], true);
                if ($enc !== false && $enc !== 'UTF-8') {
                    $s = mb_convert_encoding($s, 'UTF-8', $enc);
                } else {
                    $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
                }
            } elseif (function_exists('iconv')) {
                $tmp = @iconv('Windows-1252', 'UTF-8//IGNORE', $s);
                if ($tmp !== false) {
                    $s = $tmp;
                }
            }
        }
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_C);
            if (is_string($n) && $n !== '') {
                $s = $n;
            }
        }
        return trim($s);
    }

    private static function resolverOrganizacionPorEntidad(PDO $pdoMain, int $entidad): int
    {
        if ($entidad <= 0) {
            return 0;
        }
        $stmt = $pdoMain->prepare(
            "SELECT id
             FROM organizaciones
             WHERE entidad = ?
             ORDER BY estatus DESC, id ASC
             LIMIT 1"
        );
        $stmt->execute([$entidad]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    private static function usernameUnico(string $base, array &$usados): string
    {
        $base = preg_replace('/[^a-zA-Z0-9_\.]/', '', $base) ?: 'user00';
        $username = $base;
        $i = 2;
        while (isset($usados[$username])) {
            $username = $base . '_' . $i;
            $i++;
        }
        $usados[$username] = true;
        return $username;
    }
}

