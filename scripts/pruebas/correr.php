<?php
/**
 * Pruebas automatizadas de docs/pruebas: caso normal (CN), error de conexión (EC) y datos
 * inválidos (DI). Prueban la aplicación por HTTP, como un navegador, con las cuentas de los datos de
 * prueba (scripts/cargar_datos.php).
 *
 *   php scripts/pruebas/correr.php                      # contra APP_URL + APP_BASE del .env
 *   php scripts/pruebas/correr.php --url=https://su-sitio.cr/voluntacr
 *   php scripts/pruebas/correr.php --solo=CN-01,DI      # solo esas pruebas o grupos
 *   php scripts/pruebas/correr.php --sin-espera         # sin esperar a que venza la reserva de CN-03
 *   php scripts/pruebas/correr.php --conservar          # deja las actividades de prueba
 *   Codespaces: docker compose exec app php scripts/pruebas/correr.php --url=http://localhost
 *
 * - Conviene correrlo en el servidor, con su .env: así también corren las pruebas que leen o escriben
 *   directo en MongoDB y Redis (EC-03, EC-04, DI-09 y el paso 4 de CN-03 y de EC-06). Desde otro
 *   equipo esas se omiten solas.
 * - Los pasos que necesitan pausar o detener Redis o MongoDB se omiten: se hacen a mano.
 * - CN-03 espera a que venza su reserva (RESERVA_MINUTOS, 10 por defecto) mientras corren las demás.
 * - Crea actividades «Prueba XX-NN mmdd-hhmm» de coordinador1; al final las elimina o, si tienen
 *   inscripciones, las cancela.
 * - Imprime el resultado de cada paso y lo guarda en docs/pruebas/resultados/.
 *
 * Responsable: Persona 4.
 */
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/Navegador.php';
require __DIR__ . '/Prueba.php';
require __DIR__ . '/Corrida.php';

$opciones = getopt('', ['url:', 'solo:', 'sin-espera', 'conservar', 'sin-verificar-certificado']);
$url = rtrim($opciones['url'] ?? rtrim((string) Config::get('APP_URL', 'http://localhost:8080'), '/') . (string) Config::get('APP_BASE', ''), '/');
$partes = parse_url($url);
if (!isset($partes['scheme'], $partes['host'])) {
    fwrite(STDERR, "Dirección inválida: «{$url}». Use --url=https://su-sitio.cr\n");
    exit(2);
}
$corrida = new Corrida(
    $partes['scheme'] . '://' . $partes['host'] . (isset($partes['port']) ? ':' . $partes['port'] : ''),
    rtrim($partes['path'] ?? '', '/'),
    !isset($opciones['sin-espera']),
    isset($opciones['conservar']),
    !isset($opciones['sin-verificar-certificado']),
);

try {
    $corrida->estado = $corrida->estadoJson();
    $portal = $corrida->navegador();
    $portal->get('/actividades');
    $corrida->modoDemo = str_contains($portal->html, 'class="origen-datos"');
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

$definiciones = array_merge(
    require __DIR__ . '/caso_normal.php',
    require __DIR__ . '/error_conexion.php',
    require __DIR__ . '/datos_invalidos.php',
);
$solo = array_filter(array_map('trim', explode(',', strtoupper($opciones['solo'] ?? ''))));
$pruebas = [];
foreach ($definiciones as $id => $definicion) {
    if (!$solo || array_filter($solo, fn(string $s) => str_starts_with($id, $s))) {
        $pruebas[$id] = new Prueba($id, $definicion['nombre'], $definicion['pasos']);
    }
}
if (!$pruebas) {
    fwrite(STDERR, "Ninguna prueba coincide con --solo={$opciones['solo']}.\n");
    exit(2);
}

$correr = function (string $id, string $fase) use ($corrida, $definiciones, $pruebas): void {
    $prueba = $pruebas[$id];
    try {
        $definiciones[$id][$fase]($corrida, $prueba);
    } catch (Omision $omision) {
        $prueba->omitirResto($omision->getMessage() . '.');
    } catch (Throwable $error) {
        $prueba->fallo($error);
    }
};

// CN-03 va primero, para que su reserva venza mientras corren las demás; DI-07, al final, porque
// bloquea 15 minutos el correo de voluntario2, que usan otras pruebas.
$orden = ['CN-03', 'CN-01', 'CN-02', 'EC-01', 'EC-02', 'EC-03', 'EC-04', 'EC-05', 'EC-06',
    'DI-01', 'DI-02', 'DI-03', 'DI-04', 'DI-05', 'DI-06', 'DI-08', 'DI-09', 'DI-07'];
$orden = array_values(array_intersect(array_merge($orden, array_keys($definiciones)), array_keys($pruebas)));
$inicio = time();
foreach (array_unique($orden) as $id) {
    fwrite(STDERR, "{$id} · {$pruebas[$id]->nombre}\n");
    $correr($id, 'correr');
}
foreach (array_unique($orden) as $id) {
    $prueba = $pruebas[$id];
    if (isset($definiciones[$id]['final']) && $prueba->error === null && count($prueba->pasos) < $prueba->totalPasos) {
        fwrite(STDERR, "{$id} · pasos finales\n");
        $correr($id, 'final');
    }
}
// Antes de limpiar: la revisión del acceso directo usa las actividades de la corrida.
$sinBd = $corrida->sinBd();
$sinRedis = $corrida->sinRedis();
fwrite(STDERR, "Limpiando las actividades de prueba…\n");
$limpieza = $corrida->limpiar();

/* ---------- Informe ---------- */

$mongo = $corrida->estado['mongo'] ?? [];
$redis = $corrida->estado['redis'] ?? [];
$lineas = [
    'Pruebas automatizadas de VoluntaCR (docs/pruebas)',
    'Fecha: ' . fecha_cr($inicio) . ' (' . date_default_timezone_get() . '). Duración: ' . Corrida::duracion(time() - $inicio) . '.',
    'Aplicación: ' . $corrida->url() . ', modo ' . ($corrida->estado['modo'] ?? '?') . '. MongoDB ' . ($mongo['version'] ?? '?')
        . ' (base «' . ($mongo['base'] ?? '?') . '»); Redis ' . ($redis['version'] ?? 'sin conexión')
        . ' (' . ($redis['cliente'] ?? '?') . ', prefijo «' . ($redis['prefijo'] ?? '?') . '»).',
    'Avisos con el motor que resolvió cada reserva (APP_MODO_DEMO): ' . ($corrida->modoDemo ? 'sí' : 'no') . '.',
    'Acceso directo a la base de la aplicación: ' . ($sinBd === null ? 'sí' : "no ({$sinBd})") . '.',
    'Acceso directo al Redis de la aplicación: ' . ($sinRedis === null ? 'sí' : "no ({$sinRedis})") . '.',
    'Equipo: ' . php_uname('n') . ', PHP ' . PHP_VERSION . '. Opciones: ' . (implode(' ', array_slice($argv, 1)) ?: 'ninguna') . '.',
    'Actividades de prueba: «Prueba XX-NN ' . $corrida->id . '», de ' . Corrida::COORDINADOR . '.',
    '',
];
foreach ($pruebas as $prueba) {
    $lineas[] = $prueba->informe();
    $lineas[] = '';
}
$conteo = array_count_values(array_map(fn(Prueba $p) => $p->estado(), $pruebas));
$lineas[] = 'Resumen: ' . ($conteo['Pasa'] ?? 0) . ' pasan, ' . ($conteo['Falla'] ?? 0) . ' fallan y '
    . ($conteo['Omitida'] ?? 0) . ' se omitieron, de ' . count($pruebas) . '.';
$lineas[] = '  ' . implode(' · ', array_map(fn(Prueba $p) => "{$p->id} {$p->estado()}", $pruebas));
$lineas[] = 'Limpieza: ' . $limpieza;

$informe = implode("\n", $lineas) . "\n";
echo $informe;

$carpeta = RAIZ . '/docs/pruebas/resultados';
$archivo = $carpeta . '/pruebas_' . date('Ymd_His', $inicio) . '.txt';
if ((is_dir($carpeta) || @mkdir($carpeta, 0775, true)) && @file_put_contents($archivo, $informe) !== false) {
    echo "\nInforme guardado en " . substr($archivo, strlen(RAIZ) + 1) . "\n";
}
exit(isset($conteo['Falla']) ? 1 : 0);
