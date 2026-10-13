<?php
/**
 * Recalcula los cupos disponibles de cada actividad a partir de sus inscripciones
 * (cupoTotal - reservadas - confirmadas) y borra los contadores de Redis.
 *
 * Sirve como red de seguridad: sin transacciones, si el proceso se corta entre
 * "marcar inscripción como cancelada" y "devolver el cupo", un cupo podría quedar perdido.
 *
 *   php scripts/reconciliar_cupos.php            (solo informa)
 *   php scripts/reconciliar_cupos.php --corregir (corrige las diferencias)
 *
 * Responsable: Persona 3.
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$corregir = in_array('--corregir', $argv, true);
$bd = Mongo::get();
$rs = RedisSeguro::get();

$ocupados = [];
foreach ($bd->agregar('inscripciones', [
    ['$match' => ['estado' => ['$in' => ['reservada', 'confirmada']]]],
    ['$group' => ['_id' => '$actividadId', 'ocupados' => ['$sum' => 1]]],
]) as $fila) {
    $ocupados[(string) $fila['_id']] = (int) $fila['ocupados'];
}

$diferencias = 0;
foreach ($bd->buscar('actividades', [], ['projection' => ['titulo' => 1, 'cupoTotal' => 1, 'cuposDisponibles' => 1]]) as $actividad) {
    $id = (string) $actividad['_id'];
    $esperado = (int) $actividad['cupoTotal'] - ($ocupados[$id] ?? 0);
    if ($esperado === (int) $actividad['cuposDisponibles']) {
        continue;
    }
    $diferencias++;
    echo "  ≠ {$actividad['titulo']}: guardado {$actividad['cuposDisponibles']}, calculado {$esperado}\n";
    if ($corregir) {
        $bd->actualizar('actividades', ['_id' => $actividad['_id']], ['$set' => ['cuposDisponibles' => max(0, $esperado)]]);
        $rs->usar(fn(Redis $r) => $r->del($rs->k('cupos:' . $id)));
    }
}

echo $diferencias === 0
    ? "Todo cuadra: los cupos de MongoDB coinciden con las inscripciones.\n"
    : ($corregir ? "Corregidas {$diferencias} actividades.\n" : "Hay {$diferencias} diferencias. Ejecute con --corregir para arreglarlas.\n");
