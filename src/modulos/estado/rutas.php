<?php
/**
 * Página de estado: el "hola mundo" del seguimiento 1.
 * Comprueba que la aplicación se conecta a MongoDB y a Redis, y muestra en qué
 * modo está funcionando (normal, respaldo sin Redis o crítico sin MongoDB).
 * Responsable: Persona 3 (Redis y tolerancia a fallos).
 */
declare(strict_types=1);

return function (Router $router): void {

    $revisar = function (): array {
        // MongoDB
        $mongo = ['ok' => false, 'motivo' => 'La clase Mongo todavía no está en el proyecto.'];
        if (class_exists('Mongo')) {
            try {
                $bd = Mongo::get();
                $mongo = ['ok' => true] + $bd->ping();
                $mongo['base'] = $bd->nombreBaseDatos();
                $mongo['colecciones'] = [];
                foreach (['organizaciones', 'usuarios', 'actividades', 'inscripciones', 'notificaciones'] as $coleccion) {
                    $mongo['colecciones'][$coleccion] = $bd->contar($coleccion);
                }
            } catch (Throwable $error) {
                $mongo = ['ok' => false, 'motivo' => $error->getMessage()];
            }
        }

        // Redis
        $redis = ['ok' => false, 'motivo' => 'La clase RedisSeguro todavía no está en el proyecto.'];
        if (class_exists('RedisSeguro')) {
            $rs = RedisSeguro::get();
            $redis = $rs->estado();
            $redis['prefijo'] = $rs->prefijo();
            if ($redis['ok']) {
                // Prueba de escritura y lectura con expiración (TTL de 30 s).
                $redis['prueba'] = $rs->usar(function (Redis $r) use ($rs): string {
                    $r->setex($rs->k('hola'), 30, 'mundo ' . date('H:i:s'));
                    return (string) $r->get($rs->k('hola'));
                }, 'falló');
                // Cuántas claves tiene el grupo (SCAN, nunca KEYS * en un Redis compartido).
                $redis['claves'] = $rs->usar(function (Redis $r) use ($rs): int {
                    $total = 0;
                    $cursor = null;
                    do {
                        $lote = $r->scan($cursor, $rs->k('*'), 200);
                        $total += is_array($lote) ? count($lote) : 0;
                    } while ($cursor > 0 && $total < 10000);
                    return $total;
                }, 0);
            }
        }

        $modo = match (true) {
            $mongo['ok'] && $redis['ok'] => 'normal',
            $mongo['ok']                => 'respaldo',
            default                     => 'critico',
        };

        return ['mongo' => $mongo, 'redis' => $redis, 'modo' => $modo, 'hora' => date('c')];
    };

    $router->get('/estado', function () use ($revisar): void {
        vista('estado/estado', $revisar(), 'Estado del sistema');
    });

    // Versión para monitoreo o pruebas automáticas.
    $router->get('/estado.json', function () use ($revisar): void {
        $datos = $revisar();
        http_response_code($datos['modo'] === 'critico' ? 503 : 200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    });
};
