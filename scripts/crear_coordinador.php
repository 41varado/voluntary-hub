<?php
/**
 * Crea una cuenta de coordinación para una organización (la crea si no existe).
 * También se puede hacer desde la pantalla Usuarios con una cuenta de administración.
 *
 *   php scripts/crear_coordinador.php "Nombre de la organización" "Nombre Apellido" correo@ejemplo.com "Clave segura"
 *
 * Responsable: Persona 4.
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

if ($argc < 5) {
    fwrite(STDERR, "Uso: php scripts/crear_coordinador.php \"Organización\" \"Nombre\" correo \"clave (8+ caracteres)\"\n");
    exit(1);
}
[, $nombreOrganizacion, $nombre, $correo, $clave] = $argv;
$correo = mb_strtolower(trim($correo));
if (!filter_var($correo, FILTER_VALIDATE_EMAIL) || mb_strlen($clave) < 8 || mb_strlen(trim($nombreOrganizacion)) < 3) {
    fwrite(STDERR, "Revise los datos: correo válido, clave de 8 caracteres o más y nombre de organización.\n");
    exit(1);
}

$bd = Mongo::get();
$organizacion = $bd->buscarUno('organizaciones', ['nombre' => $nombreOrganizacion]);
if ($organizacion === null) {
    $id = $bd->insertar('organizaciones', ['nombre' => $nombreOrganizacion, 'creadaEn' => Mongo::ahora()]);
    $organizacion = ['_id' => $id, 'nombre' => $nombreOrganizacion];
    echo "Organización creada: {$nombreOrganizacion}\n";
}

try {
    $bd->insertar('usuarios', [
        'nombre'       => $nombre,
        'correo'       => $correo,
        'claveHash'    => password_hash($clave, PASSWORD_DEFAULT),
        'rol'          => 'coordinador',
        'provincia'    => null,
        'organizacion' => ['id' => $organizacion['_id'], 'nombre' => $organizacion['nombre']],
        'activo'       => true,
        'creadoEn'     => Mongo::ahora(),
        'creadoPor'    => null,
    ]);
} catch (Throwable $error) {
    fwrite(STDERR, Mongo::esDuplicado($error) ? "Ya existe una cuenta con el correo {$correo}.\n" : $error->getMessage() . "\n");
    exit(1);
}
echo "Cuenta de coordinación creada: {$correo}\n";
