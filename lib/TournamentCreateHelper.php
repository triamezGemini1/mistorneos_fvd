<?php
/**
 * Inserción de torneos con detección de columnas del esquema.
 */
declare(strict_types=1);

final class TournamentCreateHelper
{
    public static function normalizeHoraTorneo(?string $raw): string
    {
        $hora = trim((string) ($raw ?? ''));
        if ($hora === '') {
            return '00:00:00';
        }
        if (preg_match('/^\d{1,2}:\d{2}$/', $hora)) {
            return $hora . ':00';
        }
        if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $hora)) {
            return $hora;
        }

        return '00:00:00';
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function create(PDO $pdo, array $data): int
    {
        $cols = $pdo->query('SHOW COLUMNS FROM tournaments')->fetchAll(PDO::FETCH_COLUMN);
        $flags = [
            'cod_org' => in_array('cod_org', $cols, true),
            'owner_user_id' => in_array('owner_user_id', $cols, true),
            'entidad' => in_array('entidad', $cols, true),
            'permite_inscripcion_linea' => in_array('permite_inscripcion_linea', $cols, true),
            'publicar_landing' => in_array('publicar_landing', $cols, true),
            'hora_torneo' => in_array('hora_torneo', $cols, true),
            'tipo_torneo' => in_array('tipo_torneo', $cols, true),
            'parent_event_id' => in_array('parent_event_id', $cols, true),
            'genero_requerido' => in_array('genero_requerido', $cols, true),
            'edad_maxima' => in_array('edad_maxima', $cols, true),
            'campeonato_grupo' => in_array('campeonato_grupo', $cols, true),
        ];

        $insCols = 'nombre, fechator, lugar, clase, modalidad, tiempo, puntos, rondas, costo, ranking, pareclub, estatus, es_evento_masivo, club_responsable';
        $insVals = ':nombre, :fechator, :lugar, :clase, :modalidad, :tiempo, :puntos, :rondas, :costo, :ranking, :pareclub, :estatus, :es_evento_masivo, :club_responsable';

        if ($flags['cod_org']) {
            $insCols .= ', cod_org';
            $insVals .= ', :cod_org';
        }
        if ($flags['owner_user_id']) {
            $insCols .= ', owner_user_id';
            $insVals .= ', :owner_user_id';
        }
        if ($flags['entidad']) {
            $insCols .= ', entidad';
            $insVals .= ', :entidad';
        }
        $insCols .= ', cuenta_id, invitacion, normas, afiche';
        $insVals .= ', :cuenta_id, \'\', \'\', \'\'';

        if ($flags['permite_inscripcion_linea']) {
            $insCols .= ', permite_inscripcion_linea';
            $insVals .= ', :permite_inscripcion_linea';
        }
        if ($flags['publicar_landing']) {
            $insCols .= ', publicar_landing';
            $insVals .= ', :publicar_landing';
        }
        if ($flags['hora_torneo']) {
            $insCols .= ', hora_torneo';
            $insVals .= ', :hora_torneo';
        }
        if ($flags['tipo_torneo']) {
            $insCols .= ', tipo_torneo';
            $insVals .= ', :tipo_torneo';
        }
        if ($flags['genero_requerido']) {
            $insCols .= ', genero_requerido';
            $insVals .= ', :genero_requerido';
        }
        if ($flags['edad_maxima']) {
            $insCols .= ', edad_maxima';
            $insVals .= ', :edad_maxima';
        }
        if ($flags['campeonato_grupo']) {
            $insCols .= ', campeonato_grupo';
            $insVals .= ', :campeonato_grupo';
        }
        if ($flags['parent_event_id']) {
            $insCols .= ', parent_event_id';
            $insVals .= ', :parent_event_id';
        }

        $stmt = $pdo->prepare("INSERT INTO tournaments ({$insCols}) VALUES ({$insVals})");

        $tipoTorneo = $data['tipo_torneo'] ?? null;
        $params = [
            ':nombre' => $data['nombre'],
            ':fechator' => $data['fechator'],
            ':lugar' => $data['lugar'],
            ':clase' => (int) $data['clase'],
            ':modalidad' => (int) $data['modalidad'],
            ':tiempo' => (int) $data['tiempo'],
            ':puntos' => (int) $data['puntos'],
            ':rondas' => (int) $data['rondas'],
            ':costo' => (float) $data['costo'],
            ':ranking' => (int) $data['ranking'],
            ':pareclub' => (int) $data['pareclub'],
            ':estatus' => (int) $data['estatus'],
            ':es_evento_masivo' => (int) $data['es_evento_masivo'],
            ':club_responsable' => $data['club_responsable'],
            ':cuenta_id' => $data['cuenta_id'],
        ];
        if ($flags['cod_org']) {
            $params[':cod_org'] = $data['organizacion_id'];
        }
        if ($flags['owner_user_id']) {
            $params[':owner_user_id'] = (int) $data['owner_user_id'];
        }
        if ($flags['entidad']) {
            $params[':entidad'] = (int) $data['entidad'];
        }
        if ($flags['permite_inscripcion_linea']) {
            $params[':permite_inscripcion_linea'] = (int) $data['permite_inscripcion_linea'];
        }
        if ($flags['publicar_landing']) {
            $params[':publicar_landing'] = (int) $data['publicar_landing'];
        }
        if ($flags['hora_torneo']) {
            $params[':hora_torneo'] = self::normalizeHoraTorneo($data['hora_torneo'] ?? null);
        }
        if ($flags['tipo_torneo']) {
            $params[':tipo_torneo'] = $tipoTorneo === null ? 0 : (int) $tipoTorneo;
        }
        if ($flags['genero_requerido']) {
            $gr = $data['genero_requerido'] ?? null;
            $params[':genero_requerido'] = ($gr === 'M' || $gr === 'F') ? $gr : null;
        }
        if ($flags['edad_maxima']) {
            $em = (int) ($data['edad_maxima'] ?? 0);
            $params[':edad_maxima'] = $em > 0 ? $em : null;
        }
        if ($flags['campeonato_grupo']) {
            $cg = trim((string) ($data['campeonato_grupo'] ?? ''));
            $params[':campeonato_grupo'] = $cg !== '' ? $cg : null;
        }
        if ($flags['parent_event_id']) {
            $params[':parent_event_id'] = (int) ($data['parent_event_id'] ?? 0);
        }

        $stmt->execute($params);

        return (int) $pdo->lastInsertId();
    }
}
