<?php
/**
 * Punto de entrada único de la aplicación web.
 *   Desarrollo:  php -S localhost:8080 -t public public/index.php
 *   Servidor:    Apache con public/ como raíz del sitio (ver docs/despliegue-virtualmin.md)
 */
declare(strict_types=1);

// Con el servidor integrado de PHP, los archivos estáticos se sirven tal cual.
if (PHP_SAPI === 'cli-server') {
    $archivoPedido = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($archivoPedido !== __DIR__ . '/' && is_file($archivoPedido)) {
        return false;
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

Sesion::iniciar();

$router = new Router();
cargar_modulos($router);

try {
    // Por ejemplo, el módulo de usuarios revisa aquí si la cuenta sigue activa.
    Ganchos::ejecutar('solicitud.inicio');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        csrf_verificar();
    }
    $router->despachar($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
} catch (MongoDB\Driver\Exception\ConnectionException $error) {
    // MongoDB (fuente de verdad) no responde: no se puede escribir nada.
    error_log('[voluntacr] MongoDB no responde: ' . $error->getMessage());
    vista('error', [
        'codigo'  => 503,
        'mensaje' => 'La base de datos principal no responde en este momento. Las reservas y los cambios '
            . 'quedan en pausa; el listado de actividades sigue disponible si estaba en caché.',
        'detalle' => Config::bool('APP_DEBUG') ? $error->getMessage() : null,
    ], 'Servicio no disponible', 503);
} catch (Throwable $error) {
    error_log('[voluntacr] ' . $error);
    vista('error', [
        'codigo'  => 500,
        'mensaje' => 'Ocurrió un error inesperado. Si se repite, avise al equipo con la hora exacta.',
        'detalle' => Config::bool('APP_DEBUG') ? $error->getMessage() . ' en ' . basename($error->getFile()) . ':' . $error->getLine() : null,
    ], 'Error', 500);
}
