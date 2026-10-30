<?php
declare(strict_types=1);

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\Driver\Exception\BulkWriteException;

/**
 * Administración de cuentas: usuarios regulares (voluntarios y coordinadores) y administradores.
 * Responsable: Persona 1.
 *
 * Reglas:
 *   - Las cuentas no se borran, se desactivan (se conserva su historial).
 *   - Los administradores solo se crean desde su propia pantalla y con la contraseña del
 *     administrador que los crea; la pantalla de usuarios no puede convertir a nadie en admin.
 *   - Nadie puede desactivarse a sí mismo y siempre queda al menos un administrador activo.
 *   - Desactivar una cuenta o restablecer su contraseña cierra sus sesiones abiertas.
 *   - Las cuentas nuevas, y las que cambian de correo, no pueden iniciar sesión hasta confirmarlo (D23).
 */
final class AdminUsuarios
{
    public const POR_PAGINA = 25;
    public const ROLES_REGULARES = ['voluntario', 'coordinador'];

    /* ---------- Consultas ---------- */

    public static function listar(array $filtros, int $pagina, bool $administradores): array
    {
        $bd = Mongo::get();
        $filtro = $administradores
            ? ['rol' => 'admin']
            : ['rol' => in_array($filtros['rol'] ?? '', self::ROLES_REGULARES, true) ? $filtros['rol'] : ['$in' => self::ROLES_REGULARES]];

        if (($filtros['estado'] ?? '') === 'activos') {
            $filtro['activo'] = ['$ne' => false];
        } elseif (($filtros['estado'] ?? '') === 'desactivados') {
            $filtro['activo'] = false;
        }
        if (($filtros['q'] ?? '') !== '') {
            $patron = new Regex(preg_quote($filtros['q']), 'i');
            $filtro['$or'] = [['nombre' => $patron], ['correo' => $patron]];
        }

        $total = $bd->contar('usuarios', $filtro);
        $paginas = max(1, (int) ceil($total / self::POR_PAGINA));
        $pagina = min(max(1, $pagina), $paginas);
        $usuarios = $bd->buscar('usuarios', $filtro, [
            'sort'       => ['nombre' => 1],
            'skip'       => ($pagina - 1) * self::POR_PAGINA,
            'limit'      => self::POR_PAGINA,
            'projection' => ['claveHash' => 0],
        ]);
        return ['items' => $usuarios, 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
    }

    public static function organizaciones(): array
    {
        return Mongo::get()->buscar('organizaciones', [], ['sort' => ['nombre' => 1], 'projection' => ['nombre' => 1]]);
    }

    public static function contarAdminsActivos(): int
    {
        return Mongo::get()->contar('usuarios', ['rol' => 'admin', 'activo' => ['$ne' => false]]);
    }

    /* ---------- Crear y editar ---------- */

    /** Crea un voluntario o coordinador (pantalla de usuarios). Devuelve [documento | null, errores]. */
    public static function crearRegular(array $entrada, array $admin): array
    {
        $rol = (string) ($entrada['rol'] ?? '');
        [$datos, $errores] = UsuarioRepositorio::validar($entrada, ['nombre', 'correo', 'provincia', 'clave']);
        if (!in_array($rol, self::ROLES_REGULARES, true)) {
            $errores['rol'] = 'Elija si la cuenta es de voluntario o de coordinación.';
        }
        $organizacion = null;
        if ($rol === 'coordinador') {
            [$organizacion, $errorOrganizacion] = self::resolverOrganizacion($entrada);
            if ($errorOrganizacion) {
                $errores['organizacion'] = $errorOrganizacion;
            }
        }
        if ($errores) {
            return [null, $errores];
        }
        return UsuarioRepositorio::insertar($datos, $rol, $organizacion, $admin['id']);
    }

    /** Crea un administrador, confirmando la contraseña de quien lo crea. */
    public static function crearAdministrador(array $entrada, array $admin): array
    {
        [$datos, $errores] = UsuarioRepositorio::validar($entrada, ['nombre', 'correo', 'clave']);
        if (!self::claveCorrecta($admin, (string) ($entrada['confirmacion'] ?? ''))) {
            $errores['confirmacion'] = 'Su contraseña no es correcta.';
        }
        if ($errores) {
            return [null, $errores];
        }
        return UsuarioRepositorio::insertar($datos, 'admin', null, $admin['id']);
    }

    /** Edita un voluntario o coordinador. Devuelve los errores (vacío si se guardó). */
    public static function actualizarRegular(array $doc, array $entrada, array $admin): array
    {
        [$datos, $errores] = UsuarioRepositorio::validar($entrada, ['nombre', 'correo', 'provincia']);
        $rol = (string) ($entrada['rol'] ?? '');
        if (!in_array($rol, self::ROLES_REGULARES, true)) {
            $errores['rol'] = 'Elija si la cuenta es de voluntario o de coordinación.';
        }
        $datos['rol'] = $rol;
        $datos['organizacion'] = null;
        if ($rol === 'coordinador') {
            [$datos['organizacion'], $errorOrganizacion] = self::resolverOrganizacion($entrada);
            if ($errorOrganizacion) {
                $errores['organizacion'] = $errorOrganizacion;
            }
        }
        if ($errores) {
            return $errores;
        }
        // Un correo nuevo hay que confirmarlo; la sesión abierta se cierra en la siguiente revisión (D12).
        if ($datos['correo'] !== $doc['correo']) {
            $datos['correoVerificado'] = false;
            $datos['verificacion'] = null;
        }
        try {
            Mongo::get()->actualizar('usuarios', ['_id' => $doc['_id'], 'rol' => ['$ne' => 'admin']], ['$set' => $datos + [
                'actualizadoEn'  => Mongo::ahora(),
                'actualizadoPor' => Mongo::id($admin['id']),
            ]]);
        } catch (BulkWriteException $error) {
            if (Mongo::esDuplicado($error)) {
                return ['correo' => 'Ya existe otra cuenta con ese correo.'];
            }
            throw $error;
        }
        return [];
    }

    /**
     * Usa la organización elegida en la lista o crea una nueva con el nombre escrito.
     * Devuelve [['id' => ObjectId, 'nombre' => string] | null, mensaje de error | null].
     */
    public static function resolverOrganizacion(array $entrada): array
    {
        $bd = Mongo::get();
        $nueva = trim((string) ($entrada['organizacionNueva'] ?? ''));
        if ($nueva !== '') {
            if (mb_strlen($nueva) < 3 || mb_strlen($nueva) > 120) {
                return [null, 'El nombre de la organización debe tener entre 3 y 120 caracteres.'];
            }
            $existente = $bd->buscarUno('organizaciones', ['nombre' => $nueva]);
            if ($existente === null) {
                try {
                    $bd->insertar('organizaciones', ['nombre' => $nueva, 'creadaEn' => Mongo::ahora()]);
                } catch (BulkWriteException $error) {
                    if (!Mongo::esDuplicado($error)) {
                        throw $error;
                    }
                }
                $existente = $bd->buscarUno('organizaciones', ['nombre' => $nueva]);
            }
            return [['id' => $existente['_id'], 'nombre' => $existente['nombre']], null];
        }

        $id = Mongo::id((string) ($entrada['organizacionId'] ?? ''));
        $organizacion = $id ? $bd->buscarUno('organizaciones', ['_id' => $id]) : null;
        if ($organizacion === null) {
            return [null, 'Elija la organización o escriba el nombre de una nueva.'];
        }
        return [['id' => $organizacion['_id'], 'nombre' => $organizacion['nombre']], null];
    }

    /* ---------- Estado y contraseña ---------- */

    /** Activa o desactiva una cuenta. Devuelve un mensaje de error o null. */
    public static function cambiarEstado(array $doc, bool $activo, array $admin): ?string
    {
        if (!$activo && (string) $doc['_id'] === $admin['id']) {
            return 'No puede desactivar su propia cuenta.';
        }
        if (!$activo && $doc['rol'] === 'admin' && UsuarioRepositorio::estaActivo($doc) && self::contarAdminsActivos() <= 1) {
            return 'Debe quedar al menos un administrador activo.';
        }
        $cambios = ['activo' => $activo, 'actualizadoEn' => Mongo::ahora(), 'actualizadoPor' => Mongo::id($admin['id'])];
        if (!$activo) {
            $cambios['sesionesValidasDesde'] = Mongo::ahora();
        }
        Mongo::get()->actualizar('usuarios', ['_id' => $doc['_id']], ['$set' => $cambios]);
        return null;
    }

    /** Asigna una contraseña temporal. Devuelve los errores (vacío si se guardó). */
    public static function restablecerClave(array $doc, array $entrada, array $admin): array
    {
        [$datos, $errores] = UsuarioRepositorio::validar($entrada, ['clave']);
        if ($errores) {
            return $errores;
        }
        Mongo::get()->actualizar('usuarios', ['_id' => $doc['_id']], ['$set' => [
            'claveHash'            => $datos['claveHash'],
            'sesionesValidasDesde' => Mongo::ahora(),
            'actualizadoEn'        => Mongo::ahora(),
            'actualizadoPor'       => Mongo::id($admin['id']),
        ]]);
        return [];
    }

    private static function claveCorrecta(array $admin, string $clave): bool
    {
        $doc = UsuarioRepositorio::buscar($admin['id']);
        return $doc !== null && $clave !== '' && password_verify($clave, $doc['claveHash']);
    }
}
