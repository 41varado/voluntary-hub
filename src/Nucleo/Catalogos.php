<?php
declare(strict_types=1);

/**
 * Listas fijas que comparten la aplicación, el esquema de MongoDB y el generador de datos.
 */
final class Catalogos
{
    /** Clave => [nombre visible, color de la imagen de reemplazo] */
    public const CATEGORIAS = [
        'ambiente'  => ['Ambiente y reforestación', '#2f6b4f'],
        'playas'    => ['Limpieza de playas y ríos', '#2c6e8f'],
        'educacion' => ['Educación y tutorías', '#8a6a2f'],
        'salud'     => ['Salud y bienestar', '#9c3d4f'],
        'animales'  => ['Rescate animal', '#6a5a8c'],
        'comunidad' => ['Comunidad y emergencias', '#3e5c76'],
    ];

    public const PROVINCIAS = ['San José', 'Alajuela', 'Cartago', 'Heredia', 'Guanacaste', 'Puntarenas', 'Limón'];

    public const ESTADOS_ACTIVIDAD = ['borrador', 'publicada', 'cancelada'];

    public const ESTADOS_INSCRIPCION = ['reservada', 'confirmada', 'cancelada', 'expirada'];

    public const ROLES = ['voluntario', 'coordinador', 'admin'];

    public const NOMBRES_ROL = ['voluntario' => 'Voluntario', 'coordinador' => 'Coordinación', 'admin' => 'Administración'];

    public static function categoria(string $clave): string
    {
        return self::CATEGORIAS[$clave][0] ?? $clave;
    }

    public static function color(string $clave): string
    {
        return self::CATEGORIAS[$clave][1] ?? '#17473a';
    }
}
