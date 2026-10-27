<?php
declare(strict_types=1);

use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\BulkWriteException;

/**
 * Cuentas de usuario: registro, inicio de sesión, perfil, contraseña y horas acumuladas.
 * Responsable: Persona 4.
 *
 * Roles: voluntario (se registra solo), coordinador (de una organización) y admin.
 * Las cuentas no se borran: se desactivan (activo = false) para no perder el historial.
 * Las que se crean desde la web no pueden iniciar sesión hasta confirmar su correo (VerificacionCorreo).
 */
final class UsuarioRepositorio
{
    private const INTENTOS_MAXIMOS = 5;
    private const BLOQUEO_SEG = 900;

    /** Datos mínimos que se guardan en la sesión. */
    public static function aSesion(array $doc): array
    {
        return [
            'id'           => (string) $doc['_id'],
            'nombre'       => $doc['nombre'],
            'correo'       => $doc['correo'],
            'rol'          => $doc['rol'],
            'organizacion' => isset($doc['organizacion']['id'])
                ? ['id' => (string) $doc['organizacion']['id'], 'nombre' => $doc['organizacion']['nombre']]
                : null,
        ];
    }

    public static function buscar(string $id): ?array
    {
        $objectId = Mongo::id($id);
        return $objectId ? Mongo::get()->buscarUno('usuarios', ['_id' => $objectId]) : null;
    }

    public static function estaActivo(array $doc): bool
    {
        return ($doc['activo'] ?? true) !== false;
    }

    /**
     * Valida los campos comunes de una cuenta. La usan el registro, "Mi cuenta" y la administración.
     * $campos indica cuáles revisar: nombre, correo, provincia, clave.
     * Devuelve [datos limpios, errores por campo].
     */
    public static function validar(array $entrada, array $campos): array
    {
        $datos = [];
        $errores = [];

        if (in_array('nombre', $campos, true)) {
            $datos['nombre'] = trim((string) ($entrada['nombre'] ?? ''));
            if (mb_strlen($datos['nombre']) < 3 || mb_strlen($datos['nombre']) > 80) {
                $errores['nombre'] = 'Escriba el nombre (3 a 80 caracteres).';
            }
        }
        if (in_array('correo', $campos, true)) {
            $datos['correo'] = mb_strtolower(trim((string) ($entrada['correo'] ?? '')));
            if (!filter_var($datos['correo'], FILTER_VALIDATE_EMAIL) || mb_strlen($datos['correo']) > 120) {
                $errores['correo'] = 'Escriba un correo válido, por ejemplo nombre@correo.com.';
            }
        }
        if (in_array('provincia', $campos, true)) {
            $provincia = (string) ($entrada['provincia'] ?? '');
            if ($provincia !== '' && !in_array($provincia, Catalogos::PROVINCIAS, true)) {
                $errores['provincia'] = 'Elija una provincia de la lista.';
            }
            $datos['provincia'] = $provincia !== '' ? $provincia : null;
        }
        if (in_array('clave', $campos, true)) {
            $clave = (string) ($entrada['clave'] ?? '');
            if (mb_strlen($clave) < 8) {
                $errores['clave'] = 'La contraseña necesita al menos 8 caracteres.';
            } elseif ($clave !== (string) ($entrada['clave2'] ?? '')) {
                $errores['clave2'] = 'Las contraseñas no coinciden.';
            } else {
                $datos['claveHash'] = password_hash($clave, PASSWORD_DEFAULT);
            }
        }
        return [$datos, $errores];
    }

    /** Inserta una cuenta, que queda sin confirmar el correo. Devuelve [documento | null, errores]. */
    public static function insertar(array $datos, string $rol, ?array $organizacion = null, ?string $creadoPor = null): array
    {
        $documento = $datos + [
            'rol'              => $rol,
            'provincia'        => null,
            'organizacion'     => $organizacion,
            'activo'           => true,
            'correoVerificado' => false,
            'creadoEn'         => Mongo::ahora(),
            'creadoPor'        => $creadoPor ? Mongo::id($creadoPor) : null,
        ];
        try {
            $documento['_id'] = Mongo::get()->insertar('usuarios', $documento);
        } catch (BulkWriteException $error) {
            if (Mongo::esDuplicado($error)) {
                return [null, ['correo' => 'Ya existe una cuenta con ese correo.']];
            }
            throw $error;
        }
        return [$documento, []];
    }

    /**
     * Registro público: solo crea voluntarios, sin iniciar sesión (primero deben confirmar el correo).
     * Devuelve [documento | null, errores].
     */
    public static function registrar(array $entrada): array
    {
        [$datos, $errores] = self::validar($entrada, ['nombre', 'correo', 'provincia', 'clave']);
        if ($errores) {
            return [null, $errores];
        }
        [$documento, $errores] = self::insertar($datos, 'voluntario');
        if ($errores) {
            $errores['correo'] .= ' Inicie sesión.';
            return [null, $errores];
        }
        return [$documento, []];
    }

    /**
     * Revisa correo y contraseña. Devuelve [usuario | null, mensaje de error, id de la cuenta | null].
     * El tercer valor es el id cuando la contraseña es correcta pero el correo no está confirmado.
     * Esa revisión va después de la contraseña: así no revela qué correos tienen cuenta.
     * Redis cuenta los intentos fallidos por correo (bloqueo de 15 minutos tras 5 fallos).
     * Si Redis no está, no hay límite de intentos pero el inicio de sesión sigue funcionando.
     */
    public static function autenticar(string $correo, string $clave): array
    {
        $correo = mb_strtolower(trim($correo));
        $rs = RedisSeguro::get();
        $claveIntentos = $rs->k('intentos:login:' . md5($correo));

        $intentos = (int) $rs->usar(fn(Redis $r) => $r->get($claveIntentos), 0);
        if ($intentos >= self::INTENTOS_MAXIMOS) {
            return [null, 'Demasiados intentos fallidos con este correo. Espere 15 minutos e inténtelo de nuevo.', null];
        }

        $doc = Mongo::get()->buscarUno('usuarios', ['correo' => $correo]);
        if ($doc === null || !password_verify($clave, $doc['claveHash'])) {
            $rs->usar(function (Redis $r) use ($claveIntentos): void {
                $r->incr($claveIntentos);
                $r->expire($claveIntentos, self::BLOQUEO_SEG);
            });
            return [null, 'El correo o la contraseña no coinciden.', null];
        }
        $rs->usar(fn(Redis $r) => $r->del($claveIntentos));

        if (!self::estaActivo($doc)) {
            return [null, 'Esta cuenta está desactivada. Si cree que es un error, comuníquese con la administración.', null];
        }
        if (VerificacionCorreo::pendiente($doc)) {
            return [null, 'Confirme su correo antes de iniciar sesión.', (string) $doc['_id']];
        }
        return [self::aSesion($doc), '', null];
    }

    /**
     * Revisa que la cuenta de la sesión siga existiendo, activa y con el correo confirmado, y que su
     * contraseña no se haya restablecido después de iniciar sesión. Devuelve los datos actualizados o
     * null para cerrarla.
     */
    public static function revisarSesion(array $sesion, int $iniciadaEnMs): ?array
    {
        $doc = self::buscar($sesion['id']);
        // El correo vuelve a quedar sin confirmar cuando la administración lo cambia.
        if ($doc === null || !self::estaActivo($doc) || VerificacionCorreo::pendiente($doc)) {
            return null;
        }
        $desde = $doc['sesionesValidasDesde'] ?? null;
        if ($desde instanceof MongoDB\BSON\UTCDateTime && (int) (string) $desde > $iniciadaEnMs) {
            return null;
        }
        return self::aSesion($doc);
    }

    /* ---------- Mi cuenta ---------- */

    /** $conAvisos: guarda también si quiere recibir los avisos por correo (solo voluntarios). */
    public static function actualizarPerfil(ObjectId $id, array $entrada, bool $conAvisos = false): array
    {
        [$datos, $errores] = self::validar($entrada, ['nombre', 'provincia']);
        if ($errores) {
            return [null, $errores];
        }
        if ($conAvisos) {
            $datos['avisosPorCorreo'] = !empty($entrada['avisosPorCorreo']);
        }
        Mongo::get()->actualizar('usuarios', ['_id' => $id], ['$set' => $datos + ['actualizadoEn' => Mongo::ahora(), 'actualizadoPor' => $id]]);
        return [self::aSesion(Mongo::get()->buscarUno('usuarios', ['_id' => $id])), []];
    }

    public static function cambiarClave(ObjectId $id, string $actual, array $entrada): array
    {
        $doc = Mongo::get()->buscarUno('usuarios', ['_id' => $id]);
        if ($doc === null || !password_verify($actual, $doc['claveHash'])) {
            return ['actual' => 'La contraseña actual no es correcta.'];
        }
        [$datos, $errores] = self::validar($entrada, ['clave']);
        if ($errores) {
            return $errores;
        }
        // Cierra las sesiones abiertas en otros dispositivos (ver revisarSesion).
        Mongo::get()->actualizar('usuarios', ['_id' => $id], ['$set' => [
            'claveHash'            => $datos['claveHash'],
            'sesionesValidasDesde' => Mongo::ahora(),
            'actualizadoEn'        => Mongo::ahora(),
            'actualizadoPor'       => $id,
        ]]);
        return [];
    }

    /* ---------- Horas del voluntario (aggregation pipeline) ---------- */

    public static function horasPorCategoria(ObjectId $voluntario): array
    {
        return Mongo::get()->agregar('inscripciones', [
            ['$match' => ['voluntarioId' => $voluntario, 'asistencia.presente' => true]],
            ['$group' => [
                '_id'         => '$actividad.categoria',
                'horas'       => ['$sum' => '$asistencia.horas'],
                'actividades' => ['$sum' => 1],
            ]],
            ['$sort' => ['horas' => -1]],
        ]);
    }

    public static function actividadesAsistidas(ObjectId $voluntario): array
    {
        return Mongo::get()->buscar('inscripciones', ['voluntarioId' => $voluntario, 'asistencia.presente' => true], [
            'sort' => ['actividad.fechaInicio' => -1],
        ]);
    }
}
