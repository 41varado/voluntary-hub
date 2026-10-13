<?php
/**
 * Módulo base: página de inicio y "acerca de".
 * Responsable: Persona 1 (plataforma y despliegue).
 */
declare(strict_types=1);

return function (Router $router): void {

    Ganchos::agregar('menu', fn() => [['texto' => 'Acerca', 'url' => url('/acerca'), 'orden' => 90]]);

    // El inicio es el portal de actividades; mientras ese módulo no exista, se muestra el estado.
    $router->get('/', function () use ($router): void {
        foreach (['/actividades', '/estado', '/acerca'] as $destino) {
            if ($router->existe('GET', $destino)) {
                redirigir($destino);
            }
        }
    });

    $router->get('/acerca', function (): void {
        vista('acerca', [], 'Acerca del proyecto');
    });
};
