<?php
/**
 * Medición: la consulta del portal con índice y sin índice, con explain("executionStats").
 *
 *   php scripts/medir_indice.php
 *   php scripts/medir_indice.php --volumen=50000 --repeticiones=3 --ejecuciones=20
 *
 * Agrega --volumen actividades sintéticas de una organización temporal, mide tres consultas típicas
 * del portal con cada plan y al final borra todo lo que agregó. Los planes se fuerzan con hint, así
 * que no hace falta borrar índices (también sirve en una base compartida):
 *   - sin índice          {$natural: 1}: recorre toda la colección (COLLSCAN) y ordena en memoria
 *   - portal_por_fecha    {estado, fechaInicio}
 *   - portal_con_filtros  {estado, categoria, provincia, fechaInicio}
 *   - automático          sin hint: el plan que elige MongoDB, el mismo que usa la aplicación
 * La consulta tiene el mismo filtro, orden y límite que el portal (ActividadRepositorio::filtroPortal).
 * Reporta claves y documentos examinados, y la mediana del tiempo; guarda un CSV en docs/mediciones/.
 *
 * Responsable: Persona 2.
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require RAIZ . '/src/modulos/actividades/ActividadRepositorio.php';

use MongoDB\BSON\ObjectId;

const ORGANIZACION_MEDICION = 'Medición de índices';

$opciones = getopt('', ['volumen::', 'repeticiones::', 'ejecuciones::', 'semilla::']);
$volumen = max(0, (int) ($opciones['volumen'] ?? 20000));
$repeticiones = max(1, (int) ($opciones['repeticiones'] ?? 3));
$ejecuciones = max(1, (int) ($opciones['ejecuciones'] ?? 20));
mt_srand((int) ($opciones['semilla'] ?? 2026));

$mediana = function (array $valores): float {
    sort($valores);
    $n = count($valores);
    if ($n === 0) {
        return 0.0;
    }
    return $n % 2 ? (float) $valores[intdiv($n, 2)] : ($valores[$n / 2 - 1] + $valores[$n / 2]) / 2;
};

/** Etapas del plan ganador, de afuera hacia adentro: "LIMIT → FETCH → IXSCAN portal_con_filtros". */
function etapas(array $plan): array
{
    $plan = $plan['queryPlan'] ?? $plan; // con el motor SBE (MongoDB 7) el plan viene anidado
    if (!isset($plan['stage'])) {
        return [];
    }
    $etapa = $plan['stage'] . (isset($plan['indexName']) ? ' ' . $plan['indexName'] : '');
    $hijos = isset($plan['inputStage']) ? [$plan['inputStage']] : ($plan['inputStages'] ?? []);
    return array_merge([$etapa], ...array_map('etapas', $hijos));
}

$bd = Mongo::get();
$indices = array_column($bd->comandoLista(['listIndexes' => 'actividades']), 'name');
foreach (['portal_por_fecha', 'portal_con_filtros'] as $indice) {
    if (!in_array($indice, $indices, true)) {
        fwrite(STDERR, "Falta el índice {$indice}. Corra antes: php scripts/crear_esquema.php\n");
        exit(1);
    }
}

// Si una corrida anterior se interrumpió, se borra lo que haya quedado.
$restos = $bd->eliminar('actividades', ['organizacion.nombre' => ORGANIZACION_MEDICION], true);
if ($restos > 0) {
    echo "Se borraron {$restos} actividades sintéticas de una corrida anterior.\n";
}

$casos = [
    'sin filtros'           => [],
    'solo categoría'        => ['categoria' => 'ambiente'],
    'categoría y provincia' => ['categoria' => 'playas', 'provincia' => 'Puntarenas'],
];
$planes = [
    'sin índice'         => ['$natural' => 1],
    'portal_por_fecha'   => 'portal_por_fecha',
    'portal_con_filtros' => 'portal_con_filtros',
    'automático'         => null,
];

$explicar = function (array $filtro, array|string|null $hint) use ($bd): array {
    $consulta = ['find' => 'actividades', 'filter' => $filtro, 'sort' => ActividadRepositorio::ORDEN_PORTAL, 'limit' => ActividadRepositorio::POR_PAGINA];
    if ($hint !== null) {
        $consulta['hint'] = $hint;
    }
    $explain = $bd->comando(['explain' => $consulta, 'verbosity' => 'executionStats']);
    $estadisticas = $explain['executionStats'] ?? [];
    return [
        'etapas'                => implode(' → ', etapas($explain['queryPlanner']['winningPlan'] ?? [])),
        'claves_examinadas'     => (int) ($estadisticas['totalKeysExamined'] ?? 0),
        'documentos_examinados' => (int) ($estadisticas['totalDocsExamined'] ?? 0),
        'devueltos'             => (int) ($estadisticas['nReturned'] ?? 0),
        'explain_ms'            => (int) ($estadisticas['executionTimeMillis'] ?? 0),
    ];
};

$medirTiempo = function (array $filtro, array|string|null $hint) use ($bd, $ejecuciones, $mediana): float {
    $opcionesConsulta = ['sort' => ActividadRepositorio::ORDEN_PORTAL, 'limit' => ActividadRepositorio::POR_PAGINA];
    if ($hint !== null) {
        $opcionesConsulta['hint'] = $hint;
    }
    $tiempos = [];
    for ($i = 0; $i < $ejecuciones; $i++) {
        $t = hrtime(true);
        $bd->buscar('actividades', $filtro, $opcionesConsulta);
        $tiempos[] = (hrtime(true) - $t) / 1e6;
    }
    return $mediana($tiempos);
};

$organizacion = ['id' => new ObjectId(), 'nombre' => ORGANIZACION_MEDICION];
$categorias = array_keys(Catalogos::CATEGORIAS);
$filas = [];

try {
    /* ---------- Datos sintéticos ---------- */

    // 80 % publicadas, del día -60 al +120 (un tercio ya pasó), con categoría y provincia al azar.
    echo "Agregando {$volumen} actividades sintéticas...\n";
    $ahora = Mongo::ahora();
    for ($desde = 0; $desde < $volumen; $desde += 1000) {
        $lote = [];
        for ($i = $desde; $i < min($volumen, $desde + 1000); $i++) {
            $cupo = mt_rand(5, 60);
            $lote[] = [
                'titulo'           => 'Actividad de medición ' . ($i + 1),
                'descripcion'      => 'Actividad sintética creada por scripts/medir_indice.php.',
                'categoria'        => $categorias[mt_rand(0, count($categorias) - 1)],
                'provincia'        => Catalogos::PROVINCIAS[mt_rand(0, count(Catalogos::PROVINCIAS) - 1)],
                'lugar'            => 'Prueba',
                'fechaInicio'      => Mongo::fecha(time() + mt_rand(-60 * 86400, 120 * 86400)),
                'horas'            => (float) mt_rand(1, 8),
                'cupoTotal'        => $cupo,
                'cuposDisponibles' => mt_rand(0, $cupo),
                'estado'           => mt_rand(1, 10) <= 8 ? 'publicada' : (mt_rand(0, 1) ? 'borrador' : 'cancelada'),
                'organizacion'     => $organizacion,
                'imagenes'         => [],
                'portada'          => null,
                'creadaEn'         => $ahora,
            ];
        }
        $bd->insertarVarios('actividades', $lote);
    }

    $totalActividades = $bd->contar('actividades');
    $version = $bd->ping()['version'];
    echo "MongoDB {$version}. Actividades en la colección: {$totalActividades}"
        . "  Repeticiones: {$repeticiones}  Ejecuciones por plan: {$ejecuciones}\n\n";

    /* ---------- Medición ---------- */

    for ($rep = 1; $rep <= $repeticiones; $rep++) {
        foreach ($casos as $caso => $filtrosCaso) {
            $filtro = ActividadRepositorio::filtroPortal($filtrosCaso);
            foreach ($planes as $plan => $hint) {
                $filas[] = ['repeticion' => $rep, 'caso' => $caso, 'plan' => $plan, 'volumen' => $totalActividades]
                    + $explicar($filtro, $hint)
                    + ['mediana_ms' => round($medirTiempo($filtro, $hint), 3)];
            }
        }
        echo "  repetición {$rep} de {$repeticiones} lista\n";
    }
} finally {
    $borradas = $bd->eliminar('actividades', ['organizacion.id' => $organizacion['id']], true);
    ActividadRepositorio::invalidarCache(); // por si el listado en caché alcanzó a incluirlas
    echo "Se borraron las {$borradas} actividades sintéticas.\n";
}

/* ---------- Resultados ---------- */

echo "\nMedianas de las repeticiones (claves y documentos examinados, y tiempo de la consulta):\n";
foreach ($casos as $caso => $filtrosCaso) {
    echo "\n  {$caso}\n";
    foreach ($planes as $plan => $hint) {
        $delPlan = array_values(array_filter($filas, fn($f) => $f['caso'] === $caso && $f['plan'] === $plan));
        $ultima = end($delPlan);
        printf("    %-19s %7d claves %7d documentos %3d devueltos  explain %4d ms  mediana %8.3f ms  %s\n",
            $plan,
            $ultima['claves_examinadas'],
            $ultima['documentos_examinados'],
            $ultima['devueltos'],
            (int) $mediana(array_column($delPlan, 'explain_ms')),
            $mediana(array_column($delPlan, 'mediana_ms')),
            $ultima['etapas']);
    }
}

$carpeta = RAIZ . '/docs/mediciones';
if (!is_dir($carpeta)) {
    mkdir($carpeta, 0775, true);
}
$archivo = $carpeta . '/indice_' . date('Ymd_His') . '.csv';
$csv = fopen($archivo, 'w');
fputcsv($csv, array_keys($filas[0]));
foreach ($filas as $fila) {
    fputcsv($csv, $fila);
}
fclose($csv);
echo "\nResultados guardados en " . substr($archivo, strlen(RAIZ) + 1) . "\n";
