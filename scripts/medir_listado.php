<?php
/**
 * Medición: el listado de actividades con la caché de Redis y sin ella (solo MongoDB).
 * Mide lo mismo que hace /actividades antes de dibujar la página: ActividadRepositorio::listarPublicadas().
 *
 *   php scripts/medir_listado.php
 *   php scripts/medir_listado.php --consultas=300 --repeticiones=3 --modo=ambos
 *
 * Cada repetición vacía la caché y pide el listado --consultas veces, repartidas entre filtros típicos
 * del portal. Con Redis, la primera consulta de cada filtro va a MongoDB y las demás salen de la caché;
 * sin Redis (igual que con REDIS_DESHABILITADO=true), todas van a MongoDB.
 * Reporta la mediana de las repeticiones y guarda un CSV en docs/mediciones/.
 *
 * Responsable: Persona 2.
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require RAIZ . '/src/modulos/actividades/ActividadRepositorio.php';

$opciones = getopt('', ['consultas::', 'repeticiones::', 'modo::']);
$consultas = max(10, (int) ($opciones['consultas'] ?? 300));
$repeticiones = max(1, (int) ($opciones['repeticiones'] ?? 3));
$modos = match ($opciones['modo'] ?? 'ambos') {
    'redis'   => ['redis'],
    'mongodb' => ['mongodb'],
    default   => ['redis', 'mongodb'],
};

// Los mismos filtros que arma la ruta /actividades.
$sinFiltros = ['categoria' => '', 'provincia' => '', 'q' => '', 'cupo' => ''];
$filtros = [
    'sin filtros'           => $sinFiltros,
    'categoría'             => array_merge($sinFiltros, ['categoria' => 'ambiente']),
    'provincia'             => array_merge($sinFiltros, ['provincia' => 'San José']),
    'categoría y provincia' => array_merge($sinFiltros, ['categoria' => 'playas', 'provincia' => 'Puntarenas']),
    'solo con cupo'         => array_merge($sinFiltros, ['cupo' => '1']),
];
$filtros = array_values($filtros);

$mediana = function (array $valores): float {
    sort($valores);
    $n = count($valores);
    if ($n === 0) {
        return 0.0;
    }
    return $n % 2 ? (float) $valores[intdiv($n, 2)] : ($valores[$n / 2 - 1] + $valores[$n / 2]) / 2;
};
$percentil = function (array $valores, float $p): float {
    sort($valores);
    return $valores ? (float) $valores[(int) min(count($valores) - 1, floor($p * count($valores)))] : 0.0;
};

/** Activa o desactiva Redis para las consultas siguientes, como REDIS_DESHABILITADO en el .env. */
$usarRedis = function (bool $activo): void {
    putenv('REDIS_DESHABILITADO=' . ($activo ? 'false' : 'true'));
    RedisSeguro::reiniciar();
};

$usarRedis(true);
if (in_array('redis', $modos, true) && !RedisSeguro::get()->disponible()) {
    fwrite(STDERR, "Redis no está disponible: solo se puede medir el modo mongodb.\n");
    $modos = ['mongodb'];
}

$publicadas = Mongo::get()->contar('actividades', ['estado' => 'publicada']);
echo "Actividades publicadas: {$publicadas}  Consultas por repetición: {$consultas}  Repeticiones: {$repeticiones}\n\n";
$filas = [];

foreach ($modos as $modo) {
    $usarRedis($modo === 'redis');
    // Calentamiento: abre las conexiones antes de medir.
    foreach ($filtros as $filtro) {
        ActividadRepositorio::listarPublicadas($filtro, 1);
    }

    for ($rep = 1; $rep <= $repeticiones; $rep++) {
        ActividadRepositorio::invalidarCache(); // cada repetición empieza con la caché vacía
        $todas = [];
        $porOrigen = ['redis' => [], 'mongodb' => []];
        $inicio = hrtime(true);
        for ($i = 0; $i < $consultas; $i++) {
            $t = hrtime(true);
            $resultado = ActividadRepositorio::listarPublicadas($filtros[$i % count($filtros)], 1);
            $ms = (hrtime(true) - $t) / 1e6;
            $todas[] = $ms;
            $porOrigen[$resultado['origen'] === 'redis' ? 'redis' : 'mongodb'][] = $ms;
        }
        $duracion = (hrtime(true) - $inicio) / 1e6;

        $filas[] = [
            'modo'               => $modo,
            'repeticion'         => $rep,
            'consultas'          => $consultas,
            'desde_cache'        => count($porOrigen['redis']),
            'tiempo_ms'          => round($duracion, 1),
            'mediana_ms'         => round($mediana($todas), 3),
            'p95_ms'             => round($percentil($todas, 0.95), 3),
            'mediana_cache_ms'   => $porOrigen['redis'] ? round($mediana($porOrigen['redis']), 3) : null,
            'mediana_mongodb_ms' => $porOrigen['mongodb'] ? round($mediana($porOrigen['mongodb']), 3) : null,
        ];
        $ultima = end($filas);
        printf("  %-8s rep %d: %4d de %d desde la caché, %8.1f ms en total, mediana %7.3f ms, p95 %7.3f ms\n",
            $modo, $rep, $ultima['desde_cache'], $consultas, $duracion, $ultima['mediana_ms'], $ultima['p95_ms']);
    }
}

echo "\nMedianas de las repeticiones:\n";
$medianaPorModo = [];
foreach ($modos as $modo) {
    $delModo = array_filter($filas, fn($f) => $f['modo'] === $modo);
    $medianaPorModo[$modo] = $mediana(array_column($delModo, 'mediana_ms'));
    printf("  %-8s tiempo total %8.1f ms, latencia mediana %7.3f ms, p95 %7.3f ms\n",
        $modo,
        $mediana(array_column($delModo, 'tiempo_ms')),
        $medianaPorModo[$modo],
        $mediana(array_column($delModo, 'p95_ms')));
}
if (count($medianaPorModo) === 2 && $medianaPorModo['redis'] > 0) {
    printf("\nCon la caché, la latencia mediana es %.1f veces menor.\n", $medianaPorModo['mongodb'] / $medianaPorModo['redis']);
}

$carpeta = RAIZ . '/docs/mediciones';
if (!is_dir($carpeta)) {
    mkdir($carpeta, 0775, true);
}
$archivo = $carpeta . '/listado_' . date('Ymd_His') . '.csv';
$csv = fopen($archivo, 'w');
fputcsv($csv, array_keys($filas[0]));
foreach ($filas as $fila) {
    fputcsv($csv, $fila);
}
fclose($csv);
echo "\nResultados guardados en " . substr($archivo, strlen(RAIZ) + 1) . "\n";
