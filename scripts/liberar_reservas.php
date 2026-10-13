<?php
/**
 * Libera las reservas que no se confirmaron a tiempo y devuelve sus cupos.
 * Pensado para una tarea programada (cron) cada minuto en el servidor:
 *
 *   * * * * * php /ruta/al/proyecto/scripts/liberar_reservas.php >> /ruta/al/proyecto/storage/cron.log 2>&1
 *
 * La aplicación también libera reservas al ver una actividad, así que el cron es un apoyo.
 * Responsable: Persona 3.
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require RAIZ . '/src/modulos/inscripciones/ServicioCupos.php';

// Los cambios de cupo invalidan el listado en caché (lo mismo que hace el módulo de actividades).
Ganchos::agregar('cupos.cambiaron', function (): void {
    $rs = RedisSeguro::get();
    $rs->usar(fn(Redis $r) => $r->incr($rs->k('cache:actividades:version')));
});

$liberadas = ServicioCupos::liberarVencidas();
echo date('Y-m-d H:i:s') . " reservas liberadas: {$liberadas}\n";
