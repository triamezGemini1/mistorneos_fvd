<?php

/**

 * Contexto compartido: ranking_atletas.php y ranking_atletas_pdf.php.

 * Ranking nacional FVD (una sola organización): sin filtro por organización en URL.

 *

 * Define: $genero, $vista, $categoria, $user, $role, $org_nombre_encabezado,

 * $data, $atletas, $criterio, $torneos_matriz

 */

declare(strict_types=1);



require_once __DIR__ . '/../../lib/RankingCategoriaFvdHelper.php';

require_once __DIR__ . '/../../lib/FvdConfig.php';



$genero = isset($_GET['genero']) ? strtoupper((string) $_GET['genero']) : 'F';

if ($genero !== 'M' && $genero !== 'F') {

    $genero = 'F';

}



$vista = isset($_GET['vista']) ? (string) $_GET['vista'] : 'resumen';

if (! in_array($vista, ['resumen', 'detalle', 'matriz'], true)) {

    $vista = 'resumen';

}



$categoria = RankingCategoriaFvdHelper::normalizar((string) ($_GET['categoria'] ?? RankingCategoriaFvdHelper::ABSOLUTO));



$user = Auth::user();

$role = is_array($user) ? (string) ($user['role'] ?? '') : '';



/** Ranking nacional acumulado: sin acotar por organización responsable del torneo. */

$organizacion_id = 0;



$org_nombre_encabezado = FvdConfig::getOrganizacionNombre();



$svc = new RankingAtletasPublicoService($pdo);

$data = $svc->construirRanking($genero, $organizacion_id, $categoria);

$atletas = $data['atletas'];

$criterio = $data['criterio_orden'];



$torneos_matriz = [];

if ($atletas !== []) {

    $metaTorneos = [];

    foreach ($atletas as $a) {

        foreach ($a['detalle_torneos'] as $t) {

            $tid = (int) ($t['torneo_id'] ?? 0);

            if ($tid <= 0) {

                continue;

            }

            if (! isset($metaTorneos[$tid])) {

                $metaTorneos[$tid] = [

                    'torneo_id' => $tid,

                    'nombre' => (string) ($t['nombre'] ?? ''),

                    'fechator' => (string) ($t['fechator'] ?? ''),

                ];

            }

        }

    }

    if ($metaTorneos !== []) {

        $torneos_matriz = array_values($metaTorneos);

        usort($torneos_matriz, static function (array $x, array $y): int {

            return strcmp((string) ($y['fechator'] ?? ''), (string) ($x['fechator'] ?? ''));

        });

    }

}


