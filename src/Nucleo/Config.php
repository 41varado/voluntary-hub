<?php
declare(strict_types=1);

/**
 * Lee la configuración desde variables de entorno o desde el archivo .env.
 * Las variables del sistema (por ejemplo, las que define Docker) tienen prioridad.
 * Nunca se versiona el .env: en el repositorio solo va .env.example.
 */
final class Config
{
    /** @var array<string, string> */
    private static array $valores = [];

    public static function cargar(string $archivo): void
    {
        if (!is_file($archivo)) {
            return;
        }
        foreach (file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
            $linea = trim($linea);
            if ($linea === '' || str_starts_with($linea, '#') || !str_contains($linea, '=')) {
                continue;
            }
            [$clave, $valor] = array_map('trim', explode('=', $linea, 2));
            $largo = strlen($valor);
            if ($largo >= 2 && ($valor[0] === '"' || $valor[0] === "'") && $valor[$largo - 1] === $valor[0]) {
                $valor = substr($valor, 1, -1);
            }
            self::$valores[$clave] = $valor;
        }
    }

    public static function get(string $clave, ?string $porDefecto = null): ?string
    {
        $entorno = getenv($clave);
        if ($entorno !== false && $entorno !== '') {
            return $entorno;
        }
        $valor = self::$valores[$clave] ?? null;
        return ($valor === null || $valor === '') ? $porDefecto : $valor;
    }

    public static function int(string $clave, int $porDefecto): int
    {
        $valor = self::get($clave);
        return is_numeric($valor) ? (int) $valor : $porDefecto;
    }

    public static function float(string $clave, float $porDefecto): float
    {
        $valor = self::get($clave);
        return is_numeric($valor) ? (float) $valor : $porDefecto;
    }

    public static function bool(string $clave, bool $porDefecto = false): bool
    {
        $valor = self::get($clave);
        if ($valor === null) {
            return $porDefecto;
        }
        return in_array(strtolower($valor), ['1', 'true', 'si', 'sí', 'on', 'yes'], true);
    }
}
