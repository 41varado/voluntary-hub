<?php
declare(strict_types=1);

/**
 * Puntos de extensión entre módulos.
 * Un módulo "escucha" un gancho y otro lo "dispara" sin conocerse entre sí.
 * Ejemplos: 'menu' (enlaces del menú), 'actividad.panel' (bloque de inscripción
 * en el detalle de una actividad), 'actividad.cupo_cambiado' (invalidar Redis).
 */
final class Ganchos
{
    /** @var array<string, array<int, array{0:int,1:callable}>> */
    private static array $ganchos = [];

    public static function agregar(string $nombre, callable $funcion, int $orden = 50): void
    {
        self::$ganchos[$nombre][] = [$orden, $funcion];
        usort(self::$ganchos[$nombre], fn($a, $b) => $a[0] <=> $b[0]);
    }

    /** Ejecuta todas las funciones del gancho y devuelve sus resultados. */
    public static function ejecutar(string $nombre, mixed ...$argumentos): array
    {
        $resultados = [];
        foreach (self::$ganchos[$nombre] ?? [] as [, $funcion]) {
            $resultados[] = $funcion(...$argumentos);
        }
        return $resultados;
    }

    /** Igual que ejecutar(), pero une los resultados como HTML. */
    public static function html(string $nombre, mixed ...$argumentos): string
    {
        return implode('', array_filter(self::ejecutar($nombre, ...$argumentos), 'is_string'));
    }

    public static function existe(string $nombre): bool
    {
        return !empty(self::$ganchos[$nombre]);
    }
}
