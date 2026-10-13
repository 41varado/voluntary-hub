<?php
/**
 * Medición: muchas personas intentan inscribirse al mismo tiempo en una actividad con cupo limitado.
 * Compara el control de cupos con Redis + MongoDB contra solo MongoDB y verifica que nunca haya sobrecupo.
 *
 *   php scripts/medir_cupos.php
 *   php scripts/medir_cupos.php --solicitudes=500 --cupos=50 --procesos=20 --repeticiones=3 --modo=ambos
 *
 * Cada repetición crea una actividad temporal, lanza los procesos en paralelo, revisa el resultado
 * en MongoDB y la borra. Reporta la mediana de las repeticiones y guarda un CSV en docs/mediciones/.
 *
 * Responsable: Persona 3.
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require RAIZ . '/src/modulos/inscripciones/ServicioCupos.php';

use MongoDB\BSON\ObjectId;

$opciones = getopt('', ['trabajador', 'actividad:', 'cantidad:', 'inicio:', 'solicitudes::', 'cupos::', 'procesos::', 'repeticiones::', 'modo::']);

/* ---------- Proceso trabajador: hace N reservas con personas distintas ---------- */

if (isset($opciones['trabajador'])) {
    $inicio = (float) $opciones['inicio'];
    while (microtime(true) < $inicio) {
        usleep(1000); // todos los procesos arrancan a la vez
    }
    $aceptadas = 0;
    $latencias = [];
    for ($i = 0; $i < (int) $opciones['cantidad']; $i++) {
        $usuario = ['id' => (string) new ObjectId(), 'nombre' => 'Persona de medición', 'correo' => '', 'rol' => 'voluntario'];
        $t = hrtime(true);
        $resultado = ServicioCupos::reservar($opciones['actividad'], $usuario);
        $latencias[] = (hrtime(true) - $t) / 1e6;
        $aceptadas += $resultado['ok'] ? 1 : 0;
    }
    echo json_encode(['aceptadas' => $aceptadas, 'latencias' => $latencias]);
    exit(0);
}

/* ---------- Proceso principal ---------- */

$solicitudes = max(1, (int) ($opciones['solicitudes'] ?? 500));
$cupos = max(1, (int) ($opciones['cupos'] ?? 50));
$procesos = max(1, min(64, (int) ($opciones['procesos'] ?? 20)));
$repeticiones = max(1, (int) ($opciones['repeticiones'] ?? 3));
$modos = match ($opciones['modo'] ?? 'ambos') {
    'redis'   => ['redis'],
    'mongodb' => ['mongodb'],
    default   => ['redis', 'mongodb'],
};

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

$bd = Mongo::get();
$rs = RedisSeguro::get();
if (in_array('redis', $modos, true) && !$rs->disponible()) {
    fwrite(STDERR, "Redis no está disponible: solo se puede medir el modo mongodb.\n");
    $modos = ['mongodb'];
}

echo "Solicitudes: {$solicitudes}  Cupos: {$cupos}  Procesos en paralelo: {$procesos}  Repeticiones: {$repeticiones}\n\n";
$filas = [];

foreach ($modos as $modo) {
    for ($rep = 1; $rep <= $repeticiones; $rep++) {
        $actividad = $bd->insertar('actividades', [
            'titulo'           => 'Medición de concurrencia',
            'descripcion'      => 'Actividad temporal creada por scripts/medir_cupos.php.',
            'categoria'        => 'comunidad',
            'provincia'        => 'San José',
            'lugar'            => 'Prueba',
            'fechaInicio'      => Mongo::fecha(time() + 7 * 86400),
            'horas'            => 1.0,
            'cupoTotal'        => $cupos,
            'cuposDisponibles' => $cupos,
            'estado'           => 'publicada',
            'organizacion'     => ['id' => new ObjectId(), 'nombre' => 'Medición'],
            'imagenes'         => [],
            'portada'          => null,
            'creadaEn'         => Mongo::ahora(),
        ]);
        $id = (string) $actividad;

        $entorno = getenv();
        $entorno['REDIS_DESHABILITADO'] = $modo === 'mongodb' ? 'true' : 'false';
        $arranque = microtime(true) + 0.8;
        $hijos = [];
        for ($p = 0; $p < $procesos; $p++) {
            $cantidad = intdiv($solicitudes, $procesos) + ($p < $solicitudes % $procesos ? 1 : 0);
            $comando = [PHP_BINARY, __FILE__, '--trabajador', '--actividad=' . $id, '--cantidad=' . $cantidad, '--inicio=' . $arranque];
            $proceso = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias, RAIZ, $entorno);
            $hijos[] = [$proceso, $tuberias];
        }

        $aceptadas = 0;
        $latencias = [];
        foreach ($hijos as [$proceso, $tuberias]) {
            $salida = stream_get_contents($tuberias[1]);
            $errores = stream_get_contents($tuberias[2]);
            fclose($tuberias[1]);
            fclose($tuberias[2]);
            proc_close($proceso);
            $datos = json_decode((string) $salida, true);
            if (!is_array($datos)) {
                fwrite(STDERR, "Un proceso falló: " . trim($errores ?: (string) $salida) . "\n");
                continue;
            }
            $aceptadas += $datos['aceptadas'];
            array_push($latencias, ...$datos['latencias']);
        }
        $duracion = (microtime(true) - $arranque) * 1000;

        // Verificación en MongoDB, la fuente de verdad.
        $inscritos = $bd->contar('inscripciones', ['actividadId' => $actividad, 'estado' => 'reservada']);
        $final = $bd->buscarUno('actividades', ['_id' => $actividad]);
        $filas[] = [
            'modo'          => $modo,
            'repeticion'    => $rep,
            'solicitudes'   => $solicitudes,
            'cupos'         => $cupos,
            'aceptadas'     => $aceptadas,
            'inscritos_bd'  => $inscritos,
            'cupos_libres'  => (int) $final['cuposDisponibles'],
            'sobrecupo'     => max(0, $inscritos - $cupos),
            'tiempo_ms'     => round($duracion, 1),
            'mediana_ms'    => round($mediana($latencias), 2),
            'p95_ms'        => round($percentil($latencias, 0.95), 2),
        ];
        $ultima = end($filas);
        printf("  %-8s rep %d: %4d aceptadas, %4d en MongoDB, sobrecupo %d, %8.1f ms en total, mediana %6.2f ms, p95 %7.2f ms\n",
            $modo, $rep, $aceptadas, $inscritos, $ultima['sobrecupo'], $duracion, $ultima['mediana_ms'], $ultima['p95_ms']);

        $bd->eliminar('inscripciones', ['actividadId' => $actividad], true);
        $bd->eliminar('actividades', ['_id' => $actividad]);
        $rs->usar(fn(Redis $r) => $r->del($rs->k('cupos:' . $id), $rs->k('liberando:' . $id)));
    }
}

echo "\nMedianas de las repeticiones:\n";
foreach ($modos as $modo) {
    $delModo = array_filter($filas, fn($f) => $f['modo'] === $modo);
    printf("  %-8s tiempo total %8.1f ms, latencia mediana %6.2f ms, p95 %7.2f ms, sobrecupo máximo %d\n",
        $modo,
        $mediana(array_column($delModo, 'tiempo_ms')),
        $mediana(array_column($delModo, 'mediana_ms')),
        $mediana(array_column($delModo, 'p95_ms')),
        max(array_column($delModo, 'sobrecupo')));
}

$carpeta = RAIZ . '/docs/mediciones';
if (!is_dir($carpeta)) {
    mkdir($carpeta, 0775, true);
}
$archivo = $carpeta . '/cupos_' . date('Ymd_His') . '.csv';
$csv = fopen($archivo, 'w');
fputcsv($csv, array_keys($filas[0]));
foreach ($filas as $fila) {
    fputcsv($csv, $fila);
}
fclose($csv);
echo "\nResultados guardados en " . substr($archivo, strlen(RAIZ) + 1) . "\n";
