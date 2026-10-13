<?php
/**
 * Crea una cuenta de administración desde la consola. Se usa para el primer administrador
 * de una instalación nueva; los siguientes se agregan desde la pantalla Administradores.
 *
 *   php scripts/crear_admin.php "Nombre Apellido" correo@ejemplo.com "Clave segura"
 *
 * Responsable: Persona 1.
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

if ($argc < 4) {
    fwrite(STDERR, "Uso: php scripts/crear_admin.php \"Nombre Apellido\" correo \"clave (8+ caracteres)\"\n");
    exit(1);
}
[, $nombre, $correo, $clave] = $argv;
$correo = mb_strtolower(trim($correo));
$nombre = trim($nombre);
if (mb_strlen($nombre) < 3 || !filter_var($correo, FILTER_VALIDATE_EMAIL) || mb_strlen($clave) < 8) {
    fwrite(STDERR, "Revise los datos: nombre de 3 caracteres o más, correo válido y clave de 8 caracteres o más.\n");
    exit(1);
}

try {
    Mongo::get()->insertar('usuarios', [
        'nombre'       => $nombre,
        'correo'       => $correo,
        'claveHash'    => password_hash($clave, PASSWORD_DEFAULT),
        'rol'          => 'admin',
        'provincia'    => null,
        'organizacion' => null,
        'activo'       => true,
        'creadoEn'     => Mongo::ahora(),
        'creadoPor'    => null,
    ]);
} catch (Throwable $error) {
    fwrite(STDERR, Mongo::esDuplicado($error) ? "Ya existe una cuenta con el correo {$correo}.\n" : $error->getMessage() . "\n");
    exit(1);
}
echo "Cuenta de administración creada: {$correo}\n";
