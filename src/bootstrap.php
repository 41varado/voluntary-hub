<?php
/**
 * Arranque común para la web (public/index.php) y para los scripts de consola (scripts/).
 * Carga la configuración (.env) y las clases del núcleo.
 */
declare(strict_types=1);

define('RAIZ', dirname(__DIR__));

// Las clases del núcleo se cargan todas; cada integrante agrega las suyas en src/Nucleo/.
foreach (glob(RAIZ . '/src/Nucleo/*.php') as $archivoNucleo) {
    require_once $archivoNucleo;
}

Config::cargar(RAIZ . '/.env');

date_default_timezone_set(Config::get('APP_ZONA_HORARIA', 'America/Costa_Rica'));

if (Config::bool('APP_DEBUG')) {
    error_reporting(E_ALL);
    ini_set('display_errors', PHP_SAPI === 'cli' ? '1' : '0');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('display_errors', '0');
}

/**
 * Carga las rutas de cada módulo (src/modulos/<modulo>/rutas.php).
 * Un módulo que todavía no existe simplemente no se carga: así cada integrante
 * puede integrar su parte por separado sin romper la de los demás.
 */
function cargar_modulos(Router $router): void
{
    foreach (glob(RAIZ . '/src/modulos/*/rutas.php') as $archivoRutas) {
        $registrar = require $archivoRutas;
        $registrar($router);
    }
}
