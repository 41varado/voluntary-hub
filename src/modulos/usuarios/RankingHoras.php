<?php
declare(strict_types=1);

/**
 * Ranking de voluntarios por horas: sorted set "ranking:horas" en Redis (miembro = id del
 * voluntario, puntaje = horas acumuladas).
 * Responsable: Persona 4.
 *
 * MongoDB sigue siendo la fuente de verdad. Si la clave no existe, se reconstruye con una
 * agregación sobre las asistencias; vence cada hora, así cualquier desfase (por ejemplo, una
 * asistencia guardada con Redis caído) dura como máximo eso. Entre reconstrucciones, guardar la
 * asistencia suma o resta horas con ZINCRBY. Si Redis no responde, el puesto se calcula en MongoDB.
 * Se usa EVAL porque el cliente integrado no tiene comandos de sorted set (docs/redis-claves.md).
 */
final class RankingHoras
{
    private const TTL_SEG = 3600;
    private const PRIMEROS = 5;

    // Solo suma si el ranking existe: si venció, la próxima lectura lo reconstruye completo.
    private const LUA_SUMAR = <<<'LUA'
if redis.call('EXISTS', KEYS[1]) == 0 then return 0 end
for i = 1, #ARGV, 2 do
  local horas = tonumber(redis.call('ZINCRBY', KEYS[1], ARGV[i + 1], ARGV[i]))
  if horas <= 0 then redis.call('ZREM', KEYS[1], ARGV[i]) end
end
return 1
LUA;

    // ARGV: vencimiento y después pares (voluntario, horas).
    private const LUA_GUARDAR = <<<'LUA'
redis.call('DEL', KEYS[1])
for i = 2, #ARGV, 2 do
  redis.call('ZADD', KEYS[1], ARGV[i + 1], ARGV[i])
end
redis.call('EXPIRE', KEYS[1], ARGV[1])
return redis.call('ZCARD', KEYS[1])
LUA;

    // Devuelve {-1} si el ranking no existe; si no, {participantes, puesto, horas, primeros con puntaje}.
    // El puesto cuenta cuántos tienen más horas, así los empates comparten puesto.
    private const LUA_LEER = <<<'LUA'
if redis.call('EXISTS', KEYS[1]) == 0 then return {-1} end
local horas = redis.call('ZSCORE', KEYS[1], ARGV[1])
local puesto = 0
if horas then puesto = redis.call('ZCOUNT', KEYS[1], '(' .. horas, '+inf') + 1 end
return {redis.call('ZCARD', KEYS[1]), puesto, horas or '0',
  redis.call('ZREVRANGE', KEYS[1], 0, ARGV[2] - 1, 'WITHSCORES')}
LUA;

    /** Suma (o resta, si es negativo) horas después de guardar la asistencia: [voluntarioId => horas]. */
    public static function sumar(array $horasPorVoluntario): void
    {
        $argumentos = [self::clave()];
        foreach ($horasPorVoluntario as $voluntario => $horas) {
            if ((float) $horas !== 0.0) {
                array_push($argumentos, (string) $voluntario, (string) $horas);
            }
        }
        if (count($argumentos) > 1) {
            RedisSeguro::get()->usar(fn(Redis $r) => $r->eval(self::LUA_SUMAR, $argumentos, 1));
        }
    }

    /**
     * Puesto de un voluntario y los primeros del ranking:
     * ['puesto' => n o null si no tiene horas, 'participantes' => n, 'horas' => h,
     *  'primeros' => [['puesto', 'id', 'nombre', 'horas'], ...], 'origen' => 'redis' | 'mongodb'].
     */
    public static function consultar(string $voluntarioId): array
    {
        $respuesta = RedisSeguro::get()->usar(fn(Redis $r) => $r->eval(self::LUA_LEER, [self::clave(), $voluntarioId, self::PRIMEROS], 1));
        if (is_array($respuesta) && count($respuesta) === 4 && is_array($respuesta[3])) {
            $primeros = [];
            for ($i = 0; $i + 1 < count($respuesta[3]); $i += 2) {
                $primeros[(string) $respuesta[3][$i]] = (float) $respuesta[3][$i + 1];
            }
            return self::resultado((int) $respuesta[1] ?: null, (int) $respuesta[0], (float) $respuesta[2], $primeros, 'redis');
        }

        // Redis no responde, o el ranking no existe o venció: se calcula en MongoDB y, si Redis
        // respondió, se vuelve a guardar.
        $totales = self::totales();
        if (is_array($respuesta)) {
            self::guardar($totales);
        }
        $horas = $totales[$voluntarioId] ?? 0.0;
        $puesto = $horas > 0 ? count(array_filter($totales, fn(float $h) => $h > $horas)) + 1 : null;
        return self::resultado($puesto, count($totales), $horas, array_slice($totales, 0, self::PRIMEROS, true), 'mongodb');
    }

    /** Horas de cada voluntario con asistencia, de mayor a menor: [voluntarioId => horas]. */
    private static function totales(): array
    {
        $filas = Mongo::get()->agregar('inscripciones', [
            ['$match' => ['asistencia.presente' => true]],
            ['$group' => ['_id' => '$voluntarioId', 'horas' => ['$sum' => '$asistencia.horas']]],
            ['$match' => ['horas' => ['$gt' => 0]]],
            // Los empates, como en ZREVRANGE: el id mayor primero.
            ['$sort' => ['horas' => -1, '_id' => -1]],
        ]);
        $totales = [];
        foreach ($filas as $fila) {
            $totales[(string) $fila['_id']] = (float) $fila['horas'];
        }
        return $totales;
    }

    private static function guardar(array $totales): void
    {
        $argumentos = [self::clave(), self::TTL_SEG];
        foreach ($totales as $voluntario => $horas) {
            array_push($argumentos, (string) $voluntario, (string) $horas);
        }
        RedisSeguro::get()->usar(fn(Redis $r) => $r->eval(self::LUA_GUARDAR, $argumentos, 1));
    }

    /** Arma la respuesta; en los primeros, los empates comparten puesto y el nombre se acorta ("María P."). */
    private static function resultado(?int $puesto, int $participantes, float $horas, array $primeros, string $origen): array
    {
        $nombres = [];
        $ids = array_values(array_filter(array_map(fn($id) => Mongo::id((string) $id), array_keys($primeros))));
        if ($ids) {
            foreach (Mongo::get()->buscar('usuarios', ['_id' => ['$in' => $ids]], ['projection' => ['nombre' => 1]]) as $u) {
                $partes = preg_split('/\s+/', trim($u['nombre']));
                $nombres[(string) $u['_id']] = $partes[0] . (isset($partes[1]) ? ' ' . mb_substr($partes[1], 0, 1) . '.' : '');
            }
        }

        $lista = [];
        $anterior = null;
        foreach ($primeros as $id => $horasDe) {
            $lista[] = [
                'puesto' => $horasDe === $anterior ? end($lista)['puesto'] : count($lista) + 1,
                'id'     => (string) $id,
                'nombre' => $nombres[(string) $id] ?? 'Voluntario',
                'horas'  => $horasDe,
            ];
            $anterior = $horasDe;
        }
        return ['puesto' => $puesto, 'participantes' => $participantes, 'horas' => $horas, 'primeros' => $lista, 'origen' => $origen];
    }

    private static function clave(): string
    {
        return RedisSeguro::get()->k('ranking:horas');
    }
}
