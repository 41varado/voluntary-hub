<?php
declare(strict_types=1);

/**
 * Enrutador mínimo: asocia "MÉTODO /ruta/{parametro}" con una función.
 * Los parámetros de la ruta llegan a la función por nombre.
 */
final class Router
{
    /** @var array<int, array{0:string,1:string,2:string,3:callable}> */
    private array $rutas = [];

    public function get(string $patron, callable $accion): void
    {
        $this->agregar('GET', $patron, $accion);
    }

    public function post(string $patron, callable $accion): void
    {
        $this->agregar('POST', $patron, $accion);
    }

    public function agregar(string $metodo, string $patron, callable $accion): void
    {
        $patron = rtrim($patron, '/') ?: '/';
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $patron) . '$#';
        $this->rutas[] = [$metodo, $patron, $regex, $accion];
    }

    public function existe(string $metodo, string $patron): bool
    {
        $patron = rtrim($patron, '/') ?: '/';
        foreach ($this->rutas as [$m, $p]) {
            if ($m === $metodo && $p === $patron) {
                return true;
            }
        }
        return false;
    }

    public function despachar(string $metodo, string $uri): void
    {
        $metodo = $metodo === 'HEAD' ? 'GET' : $metodo;
        $ruta = parse_url($uri, PHP_URL_PATH) ?: '/';

        // Permite instalar la aplicación en una subcarpeta (APP_BASE=/voluntacr).
        $base = rtrim((string) Config::get('APP_BASE', ''), '/');
        if ($base !== '' && str_starts_with($ruta, $base)) {
            $ruta = substr($ruta, strlen($base)) ?: '/';
        }
        $ruta = rtrim(rawurldecode($ruta), '/') ?: '/';

        $otroMetodo = false;
        foreach ($this->rutas as [$m, , $regex, $accion]) {
            if (!preg_match($regex, $ruta, $coincidencias)) {
                continue;
            }
            if ($m !== $metodo) {
                $otroMetodo = true;
                continue;
            }
            $parametros = array_filter($coincidencias, 'is_string', ARRAY_FILTER_USE_KEY);
            $accion(...$parametros);
            return;
        }

        if ($otroMetodo) {
            abortar(405, 'Esa dirección existe, pero no acepta este tipo de solicitud.');
        }
        abortar(404, 'No encontramos esa página. Revise la dirección o vuelva al inicio.');
    }
}
