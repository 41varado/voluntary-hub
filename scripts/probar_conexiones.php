<?php
/**
 * "Hola mundo" por consola: comprueba la conexión a MongoDB y a Redis.
 *
 *   php scripts/probar_conexiones.php
 *
 * Responsable: Persona 3.
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

echo "PHP " . PHP_VERSION . "\n";
echo "  extensión mongodb: " . (extension_loaded('mongodb') ? phpversion('mongodb') : 'NO INSTALADA') . "\n";
echo "  extensión redis:   " . (extension_loaded('redis') ? phpversion('redis') : 'no instalada; se usa el cliente PHP integrado') . "\n\n";

$todoBien = true;

try {
    $mongo = Mongo::get()->ping();
    $id = Mongo::get()->insertar('hola_mundo', ['mensaje' => 'hola desde VoluntaCR', 'creadoEn' => Mongo::ahora()]);
    $leido = Mongo::get()->buscarUno('hola_mundo', ['_id' => $id]);
    Mongo::get()->eliminar('hola_mundo', ['_id' => $id]);
    echo "✔ MongoDB {$mongo['version']} respondió en {$mongo['ms']} ms (base: " . Mongo::get()->nombreBaseDatos() . ")\n";
    echo "  escribió y leyó: «{$leido['mensaje']}»\n";
} catch (Throwable $error) {
    $todoBien = false;
    echo "✘ MongoDB: {$error->getMessage()}\n";
}

$rs = RedisSeguro::get();
$estado = $rs->estado();
if ($estado['ok']) {
    $valor = $rs->usar(function (Redis $r) use ($rs): string {
        $r->setex($rs->k('hola'), 30, 'mundo');
        return (string) $r->get($rs->k('hola'));
    }, '');
    echo "✔ Redis {$estado['version']} respondió en {$estado['ms']} ms (prefijo: {$rs->prefijo()})\n";
    echo "  SET/GET con TTL de 30 s: «{$valor}»\n";
} else {
    $todoBien = false;
    echo "✘ Redis: {$estado['motivo']}\n";
    echo "  La aplicación seguirá funcionando en modo respaldo, solo con MongoDB.\n";
}

exit($todoBien ? 0 : 1);
