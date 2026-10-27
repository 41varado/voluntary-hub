<?php
/**
 * "Hola mundo" por consola: comprueba la conexión a MongoDB y a Redis, y la configuración de Mailjet.
 *
 *   php scripts/probar_conexiones.php
 *
 * El resultado de Mailjet no cambia el código de salida: scripts/codespaces.sh espera a que este
 * script termine bien, y el correo es opcional.
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

// Mailjet en modo de prueba (SandboxMode): revisa las claves y el remitente sin entregar ningún correo.
if (!Correo::configurado()) {
    echo "- Mailjet: sin configurar. Los correos se anotan en storage/correos.log.\n";
} elseif (!ini_get('allow_url_fopen') || !extension_loaded('openssl')) {
    echo "✘ Mailjet: hace falta allow_url_fopen = On y la extensión openssl para llamar a la API.\n";
} else {
    $mailjet = Correo::probar();
    echo $mailjet['ok']
        ? "✔ Mailjet respondió en {$mailjet['ms']} ms (remitente: {$mailjet['remitente']}, modo de prueba: no se envió nada)\n"
        : "✘ Mailjet: {$mailjet['motivo']}\n  Los avisos se guardan igual; los correos se reintentan con scripts/enviar_correos.php.\n";
}

exit($todoBien ? 0 : 1);
