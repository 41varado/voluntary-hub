<?php
declare(strict_types=1);

use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\BulkWriteException;

/**
 * Reservas de cupo sin sobrecupo, con Redis como filtro rápido y MongoDB como fuente de verdad.
 * Responsable: Persona 3.
 *
 * Reservar un cupo:
 *   1. Candado en Redis (SET NX, 5 s) contra el doble clic.
 *   2. Filtro en Redis: un script Lua resta 1 al contador "cupos:{actividad}" solo si es
 *      mayor que cero. Si la actividad está llena, la solicitud se rechaza aquí y nunca
 *      llega a MongoDB (esto es lo que protege a MongoDB en una avalancha de inscripciones).
 *   3. MongoDB resta el cupo con findAndModify y la condición "cuposDisponibles > 0".
 *      Esta es la operación que garantiza que no haya sobrecupo, con o sin Redis.
 *   4. Se guarda la inscripción en estado "reservada" con vencimiento (expiraEn).
 *
 * Si Redis no está, se omiten los pasos 1 y 2: el resultado es el mismo, solo más lento
 * bajo carga. El contador de Redis es una copia: si se desfasa, se borra y se vuelve a
 * cargar desde MongoDB.
 *
 * El vencimiento de la reserva vive en MongoDB (no en el TTL de Redis) porque debe
 * sobrevivir a una caída de Redis y porque al vencer hay que devolver el cupo.
 */
final class ServicioCupos
{
    private const LUA_TOMAR_CUPO = <<<'LUA'
-- Toma un cupo solo si quedan. Redis ejecuta el script completo sin interrupciones.
local valor = redis.call('GET', KEYS[1])
if not valor then return -2 end
if tonumber(valor) <= 0 then return -1 end
return redis.call('DECR', KEYS[1])
LUA;

    public static function minutosReserva(): int
    {
        return max(1, Config::int('RESERVA_MINUTOS', 10));
    }

    /* ---------- Reservar ---------- */

    public static function reservar(string $actividadId, array $usuario): array
    {
        $inicio = hrtime(true);
        $actividad = Mongo::id($actividadId);
        $voluntario = Mongo::id($usuario['id'] ?? '');
        if ($actividad === null || $voluntario === null) {
            return self::resultado(false, 'Esa actividad no existe.', 'ninguno', $inicio);
        }
        if (($usuario['rol'] ?? '') !== 'voluntario') {
            return self::resultado(false, 'Las reservas son para cuentas de voluntario.', 'ninguno', $inicio);
        }

        $rs = RedisSeguro::get();
        $candado = $rs->k("candado:reserva:{$actividadId}:{$usuario['id']}");
        $libre = $rs->usar(fn(Redis $r) => $r->set($candado, '1', ['nx', 'ex' => 5]), true);
        if ($libre === false) {
            return self::resultado(false, 'Su solicitud anterior todavía se está procesando.', 'redis', $inicio);
        }

        try {
            $filtro = self::filtroRedis($actividad);
            if ($filtro === 'no') {
                return self::resultado(false, 'Ya no quedan cupos en esta actividad.', 'redis', $inicio);
            }
            if ($filtro === 'sin_redis') {
                self::liberarVencidas($actividad);
            }
            return self::reservarEnMongo($actividad, $voluntario, $usuario, $filtro, $inicio);
        } finally {
            $rs->usar(fn(Redis $r) => $r->del($candado));
        }
    }

    /** Devuelve 'si' (tomó un cupo en Redis), 'no' (está llena) o 'sin_redis'. */
    private static function filtroRedis(ObjectId $actividad): string
    {
        $rs = RedisSeguro::get();
        $clave = self::claveCupos((string) $actividad);

        return $rs->usar(function (Redis $r) use ($rs, $clave, $actividad): string {
            $resultado = null;
            for ($intento = 0; $intento < 3; $intento++) {
                $resultado = $r->eval(self::LUA_TOMAR_CUPO, [$clave], 1);
                if ($resultado === -2) {
                    self::cargarContador($r, $clave, $actividad);
                    continue;
                }
                // Llena: antes de rechazar, como máximo cada 15 s se revisa si hay reservas vencidas.
                if ($resultado === -1 && $intento === 0
                    && $r->set($rs->k('liberando:' . $actividad), '1', ['nx', 'ex' => 15])
                    && self::liberarVencidas($actividad) > 0) {
                    continue;
                }
                break;
            }
            if (!is_int($resultado)) {
                // Por ejemplo, si el servidor no permite EVAL: se sigue solo con MongoDB.
                error_log('[voluntacr] El script de cupos no se pudo ejecutar: ' . $r->getLastError());
                $r->clearLastError();
                return 'sin_redis';
            }
            return $resultado >= 0 ? 'si' : 'no';
        }, 'sin_redis');
    }

    /** Carga el contador de Redis desde MongoDB (la fuente de verdad). */
    private static function cargarContador(Redis $r, string $clave, ObjectId $actividad): void
    {
        $doc = Mongo::get()->buscarUno('actividades', ['_id' => $actividad], ['projection' => ['cuposDisponibles' => 1]]);
        $r->set($clave, (string) (int) ($doc['cuposDisponibles'] ?? 0), ['nx', 'ex' => 3600]);
    }

    private static function reservarEnMongo(ObjectId $actividad, ObjectId $voluntario, array $usuario, string $filtro, int $inicio): array
    {
        $bd = Mongo::get();
        $motor = $filtro === 'si' ? 'redis+mongodb' : 'mongodb';

        $previa = $bd->buscarUno('inscripciones', ['actividadId' => $actividad, 'voluntarioId' => $voluntario]);
        if ($previa && $previa['estado'] === 'reservada' && $previa['expiraEn']->toDateTime() < new DateTime()) {
            self::liberarVencidas($actividad);
            $previa = $bd->buscarUno('inscripciones', ['_id' => $previa['_id']]);
        }
        if ($previa && in_array($previa['estado'], ['reservada', 'confirmada'], true)) {
            if ($filtro === 'si') {
                self::invalidarContador((string) $actividad); // devolver el cupo tomado en Redis
            }
            return self::resultado(false, 'Usted ya tiene un cupo en esta actividad.', $motor, $inicio);
        }

        $ahora = Mongo::ahora();
        $doc = $bd->buscarYActualizar(
            'actividades',
            ['_id' => $actividad, 'estado' => 'publicada', 'fechaInicio' => ['$gt' => $ahora], 'cuposDisponibles' => ['$gt' => 0]],
            ['$inc' => ['cuposDisponibles' => -1], '$set' => ['actualizadaEn' => $ahora]]
        );
        if ($doc === null) {
            self::invalidarContador((string) $actividad); // Redis tenía un valor desfasado
            $actual = $bd->buscarUno('actividades', ['_id' => $actividad]);
            $mensaje = match (true) {
                $actual === null || $actual['estado'] !== 'publicada' => 'Esta actividad no está disponible para reservas.',
                $actual['fechaInicio']->toDateTime() <= new DateTime() => 'La actividad ya comenzó; no admite reservas.',
                default => 'Ya no quedan cupos en esta actividad.',
            };
            return self::resultado(false, $mensaje, 'mongodb', $inicio);
        }

        $datos = [
            'estado'       => 'reservada',
            'reservadaEn'  => $ahora,
            'expiraEn'     => Mongo::fecha(time() + self::minutosReserva() * 60),
            'confirmadaEn' => null,
            'canceladaEn'  => null,
            'expiradaEn'   => null,
            'asistencia'   => null,
            'actividad'    => self::copiaActividad($doc),
            'voluntario'   => ['nombre' => $usuario['nombre'], 'correo' => $usuario['correo'] ?? ''],
        ];

        try {
            if ($previa) {
                // Volvió después de cancelar o de que venciera su reserva: se reutiliza el documento.
                $guardado = $bd->actualizar('inscripciones', ['_id' => $previa['_id'], 'estado' => ['$in' => ['cancelada', 'expirada']]], ['$set' => $datos]) > 0;
                $inscripcion = $previa['_id'];
            } else {
                $inscripcion = $bd->insertar('inscripciones', ['actividadId' => $actividad, 'voluntarioId' => $voluntario, 'creadaEn' => $ahora] + $datos);
                $guardado = true;
            }
        } catch (BulkWriteException $error) {
            if (!Mongo::esDuplicado($error)) {
                self::devolverCupo($actividad);
                throw $error;
            }
            $guardado = false; // dos solicitudes simultáneas de la misma persona
        }

        if (!$guardado) {
            self::devolverCupo($actividad);
            return self::resultado(false, 'Usted ya tiene un cupo en esta actividad.', $motor, $inicio);
        }

        Ganchos::ejecutar('cupos.cambiaron', (string) $actividad);
        return self::resultado(true, 'Cupo reservado. Confírmelo en los próximos ' . self::minutosReserva() . ' minutos; si no, se libera para otra persona.', $motor, $inicio, (string) $inscripcion);
    }

    /* ---------- Confirmar y cancelar ---------- */

    public static function confirmar(string $inscripcionId, array $usuario): array
    {
        $inicio = hrtime(true);
        $bd = Mongo::get();
        $id = Mongo::id($inscripcionId);
        $voluntario = Mongo::id($usuario['id']);
        if ($id === null || $voluntario === null) {
            return self::resultado(false, 'Esa inscripción no existe.', 'ninguno', $inicio);
        }

        // Solo confirma si la reserva sigue vigente (la condición y el cambio son una sola operación).
        $confirmadas = $bd->actualizar(
            'inscripciones',
            ['_id' => $id, 'voluntarioId' => $voluntario, 'estado' => 'reservada', 'expiraEn' => ['$gt' => Mongo::ahora()]],
            ['$set' => ['estado' => 'confirmada', 'confirmadaEn' => Mongo::ahora()]]
        );
        if ($confirmadas > 0) {
            return self::resultado(true, 'Cupo confirmado. Lo esperamos el día de la actividad.', 'mongodb', $inicio);
        }

        $doc = $bd->buscarUno('inscripciones', ['_id' => $id, 'voluntarioId' => $voluntario]);
        if ($doc === null) {
            return self::resultado(false, 'Esa inscripción no existe.', 'mongodb', $inicio);
        }
        if ($doc['estado'] === 'confirmada') {
            return self::resultado(true, 'Su cupo ya estaba confirmado.', 'mongodb', $inicio);
        }
        if ($doc['estado'] === 'reservada') {
            self::liberarVencidas($doc['actividadId']);
        }
        return self::resultado(false, 'Su reserva venció y el cupo se liberó. Si todavía hay espacio, puede reservar de nuevo.', 'mongodb', $inicio);
    }

    public static function cancelar(string $inscripcionId, array $usuario): array
    {
        $inicio = hrtime(true);
        $bd = Mongo::get();
        $id = Mongo::id($inscripcionId);
        $voluntario = Mongo::id($usuario['id']);
        $doc = ($id && $voluntario) ? $bd->buscarUno('inscripciones', ['_id' => $id, 'voluntarioId' => $voluntario]) : null;
        if ($doc === null) {
            return self::resultado(false, 'Esa inscripción no existe.', 'ninguno', $inicio);
        }
        $actividad = $bd->buscarUno('actividades', ['_id' => $doc['actividadId']], ['projection' => ['fechaInicio' => 1]]);
        if ($actividad && $actividad['fechaInicio']->toDateTime() <= new DateTime()) {
            return self::resultado(false, 'La actividad ya comenzó; la inscripción no se puede cancelar.', 'mongodb', $inicio);
        }

        $cancelada = $bd->buscarYActualizar(
            'inscripciones',
            ['_id' => $id, 'voluntarioId' => $voluntario, 'estado' => ['$in' => ['reservada', 'confirmada']]],
            ['$set' => ['estado' => 'cancelada', 'canceladaEn' => Mongo::ahora()]]
        );
        if ($cancelada === null) {
            return self::resultado(false, 'Esta inscripción ya no estaba activa.', 'mongodb', $inicio);
        }
        self::devolverCupo($doc['actividadId']);
        return self::resultado(true, 'Inscripción cancelada. Su cupo quedó libre para otra persona.', 'mongodb', $inicio);
    }

    /* ---------- Reservas vencidas ---------- */

    /**
     * Pasa a "expirada" las reservas que no se confirmaron a tiempo y devuelve sus cupos.
     * Se llama al ver una actividad, al reservar sin Redis, y desde scripts/liberar_reservas.php (cron).
     */
    public static function liberarVencidas(?ObjectId $actividad = null): int
    {
        $bd = Mongo::get();
        $filtro = ['estado' => 'reservada', 'expiraEn' => ['$lt' => Mongo::ahora()]];
        if ($actividad !== null) {
            $filtro['actividadId'] = $actividad;
        }
        $vencidas = $bd->buscar('inscripciones', $filtro, ['limit' => 500, 'projection' => ['actividadId' => 1]]);

        $liberadas = 0;
        foreach ($vencidas as $vencida) {
            // La condición se repite: si dos procesos lo intentan a la vez, solo uno la libera.
            $cambio = $bd->buscarYActualizar(
                'inscripciones',
                ['_id' => $vencida['_id'], 'estado' => 'reservada', 'expiraEn' => ['$lt' => Mongo::ahora()]],
                ['$set' => ['estado' => 'expirada', 'expiradaEn' => Mongo::ahora()]]
            );
            if ($cambio !== null) {
                self::devolverCupo($vencida['actividadId']);
                $liberadas++;
            }
        }
        return $liberadas;
    }

    /* ---------- Consistencia entre MongoDB y Redis ---------- */

    private static function devolverCupo(ObjectId $actividad): void
    {
        Mongo::get()->actualizar('actividades', ['_id' => $actividad], ['$inc' => ['cuposDisponibles' => 1]]);
        self::invalidarContador((string) $actividad);
        Ganchos::ejecutar('cupos.cambiaron', (string) $actividad);
    }

    /** Borra el contador de Redis; la próxima reserva lo vuelve a cargar desde MongoDB. */
    public static function invalidarContador(string $actividadId): void
    {
        RedisSeguro::get()->usar(fn(Redis $r) => $r->del(self::claveCupos($actividadId)));
    }

    public static function claveCupos(string $actividadId): string
    {
        return RedisSeguro::get()->k('cupos:' . $actividadId);
    }

    /** Copia de los datos de la actividad que se guarda dentro de la inscripción (para el historial). */
    public static function copiaActividad(array $actividad): array
    {
        return [
            'titulo'       => $actividad['titulo'],
            'fechaInicio'  => $actividad['fechaInicio'],
            'horas'        => $actividad['horas'],
            'categoria'    => $actividad['categoria'],
            'lugar'        => $actividad['lugar'],
            'provincia'    => $actividad['provincia'],
            'organizacion' => $actividad['organizacion']['nombre'],
        ];
    }

    private static function resultado(bool $ok, string $mensaje, string $motor, int $inicio, ?string $inscripcion = null): array
    {
        return [
            'ok'          => $ok,
            'mensaje'     => $mensaje,
            'motor'       => $motor,
            'ms'          => round((hrtime(true) - $inicio) / 1e6, 2),
            'inscripcion' => $inscripcion,
        ];
    }
}
