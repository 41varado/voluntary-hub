<?php
declare(strict_types=1);

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;

/**
 * Actividades de voluntariado: consultas a MongoDB y caché del listado en Redis.
 * Responsable: Persona 2.
 *
 * Caché (patrón cache-aside):
 *   1. Se busca el listado en Redis (cache:actividades:v{version}:{hash}).
 *   2. Si no está, se consulta MongoDB y se guarda con TTL de CACHE_TTL_SEG.
 *   3. Cuando algo cambia (crear, editar, reservar...) se incrementa la versión,
 *      y todas las claves anteriores dejan de usarse y expiran solas.
 *   4. Además se guarda una copia de respaldo (24 h) que solo se usa si MongoDB cae.
 */
final class ActividadRepositorio
{
    public const POR_PAGINA = 9;
    private const COLECCION = 'actividades';

    /* ---------- Portal público ---------- */

    public static function listarPublicadas(array $filtros, int $pagina): array
    {
        $inicio = hrtime(true);
        $rs = RedisSeguro::get();
        $huella = md5(json_encode([$filtros, $pagina]));
        $claveCache = $rs->k('cache:actividades:v' . self::versionCache() . ':' . $huella);
        $claveRespaldo = $rs->k('respaldo:actividades:' . $huella);

        $guardado = $rs->usar(fn(Redis $r) => $r->get($claveCache), false);
        if (is_string($guardado)) {
            return json_decode($guardado, true) + ['origen' => 'redis', 'ms' => self::ms($inicio)];
        }

        try {
            $resultado = self::consultarPublicadas($filtros, $pagina);
        } catch (MongoDB\Driver\Exception\ConnectionException $error) {
            // MongoDB no responde: se muestra la última versión conocida, si existe.
            $respaldo = $rs->usar(fn(Redis $r) => $r->get($claveRespaldo), false);
            if (is_string($respaldo)) {
                return json_decode($respaldo, true) + ['origen' => 'respaldo', 'ms' => self::ms($inicio)];
            }
            throw $error;
        }

        $json = json_encode($resultado, JSON_UNESCAPED_UNICODE);
        $ttl = max(5, Config::int('CACHE_TTL_SEG', 60));
        $rs->usar(function (Redis $r) use ($claveCache, $claveRespaldo, $json, $ttl): void {
            $r->setex($claveCache, $ttl, $json);
            $r->setex($claveRespaldo, 86400, $json);
        });

        return $resultado + ['origen' => 'mongodb', 'ms' => self::ms($inicio)];
    }

    private static function consultarPublicadas(array $filtros, int $pagina): array
    {
        $bd = Mongo::get();
        $filtro = [
            'estado'      => 'publicada',
            // Se siguen mostrando las que empezaron hace menos de 3 horas.
            'fechaInicio' => ['$gte' => new UTCDateTime((time() - 3 * 3600) * 1000)],
        ];
        if (isset(Catalogos::CATEGORIAS[$filtros['categoria'] ?? ''])) {
            $filtro['categoria'] = $filtros['categoria'];
        }
        if (in_array($filtros['provincia'] ?? '', Catalogos::PROVINCIAS, true)) {
            $filtro['provincia'] = $filtros['provincia'];
        }
        if (($filtros['q'] ?? '') !== '') {
            $filtro['titulo'] = new Regex(preg_quote($filtros['q']), 'i');
        }
        if (!empty($filtros['cupo'])) {
            $filtro['cuposDisponibles'] = ['$gt' => 0];
        }

        $total = $bd->contar(self::COLECCION, $filtro);
        $paginas = max(1, (int) ceil($total / self::POR_PAGINA));
        $pagina = min(max(1, $pagina), $paginas);
        $documentos = $bd->buscar(self::COLECCION, $filtro, [
            'sort'       => ['fechaInicio' => 1],
            'skip'       => ($pagina - 1) * self::POR_PAGINA,
            'limit'      => self::POR_PAGINA,
            'projection' => ['descripcion' => 0, 'requisitos' => 0],
        ]);

        return [
            'items'   => array_map([self::class, 'aVista'], $documentos),
            'total'   => $total,
            'pagina'  => $pagina,
            'paginas' => $paginas,
        ];
    }

    /** Datos simples (sin tipos BSON) para las plantillas y para guardar en caché como JSON. */
    public static function aVista(array $doc): array
    {
        return [
            'id'               => (string) $doc['_id'],
            'titulo'           => $doc['titulo'],
            'categoria'        => $doc['categoria'],
            'provincia'        => $doc['provincia'],
            'lugar'            => $doc['lugar'],
            'fecha'            => $doc['fechaInicio']->toDateTime()->getTimestamp(),
            'horas'            => (float) $doc['horas'],
            'cupoTotal'        => (int) $doc['cupoTotal'],
            'cuposDisponibles' => (int) $doc['cuposDisponibles'],
            'portada'          => $doc['portada'] ?? null,
            'organizacion'     => $doc['organizacion']['nombre'] ?? '',
            'estado'           => $doc['estado'],
        ];
    }

    public static function buscar(string $id): ?array
    {
        $objectId = Mongo::id($id);
        return $objectId ? Mongo::get()->buscarUno(self::COLECCION, ['_id' => $objectId]) : null;
    }

    /* ---------- Panel del coordinador ---------- */

    public static function esPropia(array $actividad, ?array $usuario): bool
    {
        return $usuario !== null
            && $usuario['rol'] === 'coordinador'
            && isset($usuario['organizacion']['id'])
            && (string) $actividad['organizacion']['id'] === $usuario['organizacion']['id'];
    }

    /** Actividades de una organización con el conteo de inscripciones por estado (aggregation pipeline). */
    public static function deOrganizacion(string $organizacionId): array
    {
        $bd = Mongo::get();
        $actividades = $bd->buscar(self::COLECCION, ['organizacion.id' => new ObjectId($organizacionId)], [
            'sort'       => ['fechaInicio' => -1],
            'projection' => ['descripcion' => 0],
        ]);
        if (!$actividades) {
            return [];
        }
        $conteos = $bd->agregar('inscripciones', [
            ['$match' => ['actividadId' => ['$in' => array_column($actividades, '_id')]]],
            ['$group' => ['_id' => ['actividad' => '$actividadId', 'estado' => '$estado'], 'total' => ['$sum' => 1]]],
        ]);
        $porActividad = [];
        foreach ($conteos as $fila) {
            $porActividad[(string) $fila['_id']['actividad']][$fila['_id']['estado']] = $fila['total'];
        }
        foreach ($actividades as &$actividad) {
            $actividad['conteos'] = $porActividad[(string) $actividad['_id']] ?? [];
        }
        return $actividades;
    }

    /**
     * Valida el formulario. Devuelve [datos limpios, errores por campo].
     * La base de datos vuelve a validar con JSON Schema (scripts/crear_esquema.php).
     */
    public static function validar(array $entrada, ?array $actual = null): array
    {
        $errores = [];
        $datos = [
            'titulo'      => trim((string) ($entrada['titulo'] ?? '')),
            'descripcion' => trim(str_replace("\r\n", "\n", (string) ($entrada['descripcion'] ?? ''))),
            'requisitos'  => trim(str_replace("\r\n", "\n", (string) ($entrada['requisitos'] ?? ''))),
            'categoria'   => (string) ($entrada['categoria'] ?? ''),
            'provincia'   => (string) ($entrada['provincia'] ?? ''),
            'lugar'       => trim((string) ($entrada['lugar'] ?? '')),
        ];

        $largo = fn(string $texto) => mb_strlen($texto);
        if ($largo($datos['titulo']) < 5 || $largo($datos['titulo']) > 120) {
            $errores['titulo'] = 'Escriba un título de 5 a 120 caracteres.';
        }
        if ($largo($datos['descripcion']) < 20 || $largo($datos['descripcion']) > 3000) {
            $errores['descripcion'] = 'Describa la actividad en 20 a 3000 caracteres.';
        }
        if ($largo($datos['requisitos']) > 500) {
            $errores['requisitos'] = 'Los requisitos no pueden pasar de 500 caracteres.';
        }
        if (!isset(Catalogos::CATEGORIAS[$datos['categoria']])) {
            $errores['categoria'] = 'Elija una categoría.';
        }
        if (!in_array($datos['provincia'], Catalogos::PROVINCIAS, true)) {
            $errores['provincia'] = 'Elija una provincia.';
        }
        if ($largo($datos['lugar']) < 3 || $largo($datos['lugar']) > 150) {
            $errores['lugar'] = 'Indique el lugar de encuentro (3 a 150 caracteres).';
        }

        $fecha = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', (string) ($entrada['fechaInicio'] ?? ''), new DateTimeZone(date_default_timezone_get()));
        $fechaCambio = $actual === null || $fecha === false
            || $fecha->getTimestamp() !== $actual['fechaInicio']->toDateTime()->getTimestamp();
        if ($fecha === false) {
            $errores['fechaInicio'] = 'Indique la fecha y la hora de inicio.';
        } elseif ($fechaCambio && $fecha->getTimestamp() < time() + 3600) {
            $errores['fechaInicio'] = 'La actividad debe empezar al menos dentro de una hora.';
        } else {
            $datos['fechaInicio'] = Mongo::fecha($fecha);
        }

        $horas = filter_var($entrada['horas'] ?? '', FILTER_VALIDATE_FLOAT);
        if ($horas === false || $horas < 0.5 || $horas > 24) {
            $errores['horas'] = 'Las horas deben estar entre 0,5 y 24.';
        } else {
            $datos['horas'] = round($horas * 2) / 2;
        }

        $cupo = filter_var($entrada['cupoTotal'] ?? '', FILTER_VALIDATE_INT);
        if ($cupo === false || $cupo < 1 || $cupo > 500) {
            $errores['cupoTotal'] = 'El cupo debe ser un número entre 1 y 500.';
        } else {
            $datos['cupoTotal'] = $cupo;
            if ($actual !== null) {
                $ocupados = (int) $actual['cupoTotal'] - (int) $actual['cuposDisponibles'];
                if ($cupo < $ocupados) {
                    $errores['cupoTotal'] = "Ya hay {$ocupados} cupos ocupados; el cupo total no puede ser menor.";
                }
            }
        }

        return [$datos, $errores];
    }

    public static function crear(array $datos, array $usuario, string $estado): ObjectId
    {
        $ahora = Mongo::ahora();
        $id = Mongo::get()->insertar(self::COLECCION, $datos + [
            'cuposDisponibles' => $datos['cupoTotal'],
            'estado'           => $estado === 'publicada' ? 'publicada' : 'borrador',
            'organizacion'     => ['id' => new ObjectId($usuario['organizacion']['id']), 'nombre' => $usuario['organizacion']['nombre']],
            'coordinadorId'    => new ObjectId($usuario['id']),
            'imagenes'         => [],
            'portada'          => null,
            'creadaEn'         => $ahora,
            'actualizadaEn'    => $ahora,
        ]);
        self::invalidarCache();
        return $id;
    }

    /** Devuelve null si se guardó, o un mensaje de error. */
    public static function actualizar(array $actual, array $datos): ?string
    {
        $diferencia = $datos['cupoTotal'] - (int) $actual['cupoTotal'];
        $cupoTotal = $datos['cupoTotal'];
        unset($datos['cupoTotal']);

        // El cambio de cupo se aplica con $inc y una condición, para no pisar
        // reservas que ocurran mientras el coordinador edita.
        $filtro = ['_id' => $actual['_id'], 'cupoTotal' => $actual['cupoTotal']];
        if ($diferencia < 0) {
            $filtro['cuposDisponibles'] = ['$gte' => -$diferencia];
        }
        $cambios = ['$set' => $datos + ['actualizadaEn' => Mongo::ahora()]];
        if ($diferencia !== 0) {
            $cambios['$inc'] = ['cupoTotal' => $diferencia, 'cuposDisponibles' => $diferencia];
        }

        if (Mongo::get()->actualizar(self::COLECCION, $filtro, $cambios) === 0) {
            return "No se pudo cambiar el cupo a {$cupoTotal}: alguien se inscribió mientras editaba. Revise los datos y vuelva a guardar.";
        }

        Ganchos::ejecutar('actividad.actualizada', (string) $actual['_id']);
        if ($diferencia !== 0) {
            Ganchos::ejecutar('actividad.cupo_cambiado', (string) $actual['_id']);
        }
        self::invalidarCache();
        return null;
    }

    public static function cambiarEstado(array $actual, string $estado): void
    {
        if (!in_array($estado, Catalogos::ESTADOS_ACTIVIDAD, true)) {
            return;
        }
        Mongo::get()->actualizar(self::COLECCION, ['_id' => $actual['_id']], ['$set' => ['estado' => $estado, 'actualizadaEn' => Mongo::ahora()]]);
        Ganchos::ejecutar('actividad.cupo_cambiado', (string) $actual['_id']);
        self::invalidarCache();
    }

    /** Solo se elimina si nadie se ha inscrito nunca; si no, hay que cancelarla. */
    public static function eliminar(array $actual): ?string
    {
        $bd = Mongo::get();
        if ($bd->contar('inscripciones', ['actividadId' => $actual['_id']]) > 0) {
            return 'Esta actividad ya tiene inscripciones. Cancélela en lugar de eliminarla, así se conserva el historial.';
        }
        $bd->eliminar(self::COLECCION, ['_id' => $actual['_id']]);
        Imagenes::borrarCarpeta((string) $actual['_id']);
        Ganchos::ejecutar('actividad.cupo_cambiado', (string) $actual['_id']);
        self::invalidarCache();
        return null;
    }

    /* ---------- Imágenes (metadatos en MongoDB, archivos en public/uploads) ---------- */

    public static function agregarImagenes(array $actual, array $imagenes): void
    {
        if (!$imagenes) {
            return;
        }
        $cambios = ['$push' => ['imagenes' => ['$each' => $imagenes]], '$set' => ['actualizadaEn' => Mongo::ahora()]];
        if (empty($actual['portada'])) {
            $cambios['$set']['portada'] = $imagenes[0]['archivo'];
        }
        Mongo::get()->actualizar(self::COLECCION, ['_id' => $actual['_id']], $cambios);
        self::invalidarCache();
    }

    public static function quitarImagen(array $actual, string $archivo): void
    {
        $restantes = array_values(array_filter($actual['imagenes'], fn($i) => $i['archivo'] !== $archivo));
        $portada = $actual['portada'] === $archivo ? ($restantes[0]['archivo'] ?? null) : $actual['portada'];
        Mongo::get()->actualizar(self::COLECCION, ['_id' => $actual['_id']], [
            '$pull' => ['imagenes' => ['archivo' => $archivo]],
            '$set'  => ['portada' => $portada, 'actualizadaEn' => Mongo::ahora()],
        ]);
        Imagenes::borrar((string) $actual['_id'], $archivo);
        self::invalidarCache();
    }

    public static function elegirPortada(array $actual, string $archivo): void
    {
        if (!in_array($archivo, array_column($actual['imagenes'], 'archivo'), true)) {
            return;
        }
        Mongo::get()->actualizar(self::COLECCION, ['_id' => $actual['_id']], ['$set' => ['portada' => $archivo]]);
        self::invalidarCache();
    }

    /* ---------- Caché ---------- */

    public static function versionCache(): int
    {
        $rs = RedisSeguro::get();
        return (int) $rs->usar(fn(Redis $r) => $r->get($rs->k('cache:actividades:version')), 0);
    }

    /** Invalida todo el listado en caché subiendo el número de versión. */
    public static function invalidarCache(): void
    {
        $rs = RedisSeguro::get();
        $rs->usar(fn(Redis $r) => $r->incr($rs->k('cache:actividades:version')));
    }

    private static function ms(int $inicio): float
    {
        return round((hrtime(true) - $inicio) / 1e6, 2);
    }
}
