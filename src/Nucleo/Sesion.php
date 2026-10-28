<?php
declare(strict_types=1);

/**
 * Sesión del usuario. Se guarda en archivos de PHP (no en Redis) a propósito:
 * si Redis se cae, nadie pierde la sesión. Ver docs/decisiones.md.
 */
final class Sesion
{
    public static function iniciar(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }
        session_name('voluntacr');
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'path'     => (rtrim((string) Config::get('APP_BASE', ''), '/') ?: '') . '/',
        ]);
        session_start();
    }

    /** @return array{id:string,nombre:string,correo:string,rol:string,organizacion:?array}|null */
    public static function usuario(): ?array
    {
        return $_SESSION['usuario'] ?? null;
    }

    public static function iniciarComo(array $usuario): void
    {
        session_regenerate_id(true);
        $_SESSION['usuario'] = $usuario;
        $_SESSION['iniciada_en'] = (int) floor(microtime(true) * 1000); // milisegundos
        $_SESSION['verificado_en'] = time();
    }

    public static function cerrar(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    /** Reemplaza los datos guardados en la sesión (por ejemplo, después de editar el perfil). */
    public static function actualizar(array $usuario): void
    {
        $_SESSION['usuario'] = $usuario;
    }

    /** Exige sesión iniciada (y opcionalmente un rol). Si no, envía a /ingresar. */
    public static function requiere(?string $rol = null): array
    {
        $usuario = self::usuario();
        if ($usuario === null) {
            // Con el rol que pide la página: al iniciar sesión solo se vuelve a ella si la cuenta lo tiene.
            $_SESSION['volver_a'] = ['ruta' => $_SERVER['REQUEST_URI'] ?? '/', 'rol' => $rol];
            flash('aviso', 'Inicie sesión para continuar.');
            redirigir('/ingresar');
        }
        if ($rol !== null && $usuario['rol'] !== $rol) {
            $nombre = class_exists('Catalogos') ? (Catalogos::NOMBRES_ROL[$rol] ?? $rol) : $rol;
            abortar(403, 'Esta sección es solo para cuentas de ' . mb_strtolower($nombre) . '.');
        }
        return $usuario;
    }
}
