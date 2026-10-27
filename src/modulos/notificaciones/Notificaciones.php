<?php
declare(strict_types=1);

use MongoDB\BSON\ObjectId;

/**
 * Avisos a las personas inscritas: los automáticos (cancelación, cambios, retiro del portal, nueva
 * publicación y horas de asistencia) y los mensajes que una organización envía a todos los inscritos
 * de una actividad. No hay mensajes a una sola persona.
 * Responsable: Persona 2.
 *
 * Cada aviso se guarda como un documento por destinatario en "notificaciones" (decisión D24): así
 * cada persona tiene su bandeja y su estado de lectura, y el historial de una actividad sale de una
 * agregación, sin contadores que se puedan desfasar.
 *
 * El correo es un canal más (D25): se intenta enseguida con Mailjet y lo que falla se reintenta con
 * scripts/enviar_correos.php. El aviso del portal no depende del correo.
 */
final class Notificaciones
{
    public const POR_PAGINA = 20;
    private const COLECCION = 'notificaciones';
    private const INTENTOS_MAXIMOS = 3;

    /* ---------- Crear y enviar ---------- */

    /**
     * Crea el aviso para cada persona, todos en una sola escritura, y les envía el correo.
     * $datos: tipo, titulo, mensaje y, si corresponde, actividad (documento), enlace y remitente.
     * Devuelve ['personas' => n, 'correos' => cuántos salieron por correo].
     */
    public static function crear(array $usuarioIds, array $datos): array
    {
        $actividad = $datos['actividad'] ?? null;
        $envioId = new ObjectId();
        $ahora = Mongo::ahora();
        $avisos = [];
        foreach ($usuarioIds as $usuarioId) {
            $usuarioId = $usuarioId instanceof ObjectId ? $usuarioId : new ObjectId((string) $usuarioId);
            // La clave evita que una persona reciba dos veces el mismo aviso.
            $avisos[(string) $usuarioId] = [
                '_id'         => new ObjectId(),
                'usuarioId'   => $usuarioId,
                'envioId'     => $envioId,
                'tipo'        => $datos['tipo'],
                'titulo'      => mb_strimwidth($datos['titulo'], 0, 150, '…'),
                'mensaje'     => mb_strimwidth($datos['mensaje'], 0, 2000, '…'),
                'actividadId' => $actividad['_id'] ?? null,
                'actividad'   => $actividad ? ['titulo' => $actividad['titulo'], 'fechaInicio' => $actividad['fechaInicio']] : null,
                'enlace'      => $datos['enlace'] ?? null,
                'remitente'   => $datos['remitente'] ?? null,
                'leidaEn'     => null,
                'creadaEn'    => $ahora,
                'correo'      => ['estado' => 'pendiente', 'intentos' => 0, 'enviadoEn' => null, 'motivo' => null],
            ];
        }
        if (!$avisos) {
            return ['personas' => 0, 'correos' => 0];
        }
        $avisos = array_values($avisos);
        Mongo::get()->insertarVarios(self::COLECCION, $avisos);
        return ['personas' => count($avisos), 'correos' => self::enviarCorreos($avisos)['enviado']];
    }

    /** Aviso para todas las personas con una inscripción activa (reservada o confirmada) en la actividad. */
    public static function aInscritos(array $actividad, array $datos): array
    {
        return self::crear(self::inscritos($actividad['_id']), $datos + ['actividad' => $actividad]);
    }

    /** Ids de las personas con una inscripción activa en la actividad. */
    public static function inscritos(ObjectId $actividadId): array
    {
        $inscripciones = Mongo::get()->buscar('inscripciones', [
            'actividadId' => $actividadId,
            'estado'      => ['$in' => ['reservada', 'confirmada']],
        ], ['projection' => ['voluntarioId' => 1]]);
        return array_column($inscripciones, 'voluntarioId');
    }

    /**
     * Envía por correo los avisos dados (documentos de "notificaciones") y guarda cómo resultó cada uno.
     * Devuelve cuántos quedaron en cada estado: ['enviado' => n, 'fallido' => n, 'omitido' => n].
     */
    public static function enviarCorreos(array $avisos): array
    {
        $conteo = ['enviado' => 0, 'fallido' => 0, 'omitido' => 0];
        if (!$avisos) {
            return $conteo;
        }

        // Los datos de contacto se leen al enviar, no se copian en el aviso: valen el correo y la
        // preferencia actuales de cada persona.
        $ids = [];
        foreach ($avisos as $aviso) {
            $ids[(string) $aviso['usuarioId']] = $aviso['usuarioId'];
        }
        $personas = [];
        foreach (Mongo::get()->buscar('usuarios', ['_id' => ['$in' => array_values($ids)]], [
            'projection' => ['nombre' => 1, 'correo' => 1, 'activo' => 1, 'correoVerificado' => 1, 'avisosPorCorreo' => 1],
        ]) as $persona) {
            $personas[(string) $persona['_id']] = $persona;
        }

        $resultados = [];
        $mensajes = [];
        foreach ($avisos as $aviso) {
            $persona = $personas[(string) $aviso['usuarioId']] ?? null;
            $motivo = match (true) {
                $persona === null || ($persona['activo'] ?? true) === false => 'cuenta_inactiva',
                ($persona['correoVerificado'] ?? true) === false            => 'sin_confirmar',
                ($persona['avisosPorCorreo'] ?? true) === false             => 'prefiere_no',
                default                                                     => null,
            };
            if ($motivo !== null) {
                $resultados[(string) $aviso['_id']] = ['estado' => 'omitido', 'motivo' => $motivo];
            } else {
                $mensajes[] = self::mensajeCorreo($aviso, $persona);
            }
        }
        $resultados += Correo::enviar($mensajes);

        self::guardarResultados($resultados);
        foreach ($resultados as $resultado) {
            $conteo[$resultado['estado']]++;
        }
        return $conteo;
    }

    /**
     * Para scripts/enviar_correos.php: reintenta los correos que fallaron y los que quedaron pendientes
     * hace más de 10 minutos (la solicitud que los creó no terminó de enviarlos), de las últimas 48 horas.
     */
    public static function reintentarCorreos(int $limite = 200): array
    {
        $ahora = time();
        $avisos = Mongo::get()->buscar(self::COLECCION, [
            'creadaEn' => ['$gte' => Mongo::fecha($ahora - 48 * 3600)],
            '$or'      => [
                ['correo.estado' => 'fallido', 'correo.intentos' => ['$lt' => self::INTENTOS_MAXIMOS]],
                ['correo.estado' => 'pendiente', 'creadaEn' => ['$lt' => Mongo::fecha($ahora - 600)]],
            ],
        ], ['sort' => ['creadaEn' => 1], 'limit' => $limite]);
        return self::enviarCorreos($avisos);
    }

    private static function mensajeCorreo(array $aviso, array $persona): array
    {
        $nombreApp = Config::get('APP_NOMBRE', 'VoluntaCR');
        $parrafos = preg_split('/\n{2,}/', $aviso['mensaje']) ?: [$aviso['mensaje']];
        $nombreRemitente = null;
        if (!empty($aviso['remitente'])) {
            $remitente = $aviso['remitente'];
            $organizacion = $remitente['organizacion'] ?? '';
            $contexto = $remitente['nombre'] . ($organizacion !== '' ? ', de ' . $organizacion . ',' : '') . ' escribió a las personas inscritas';
            if (!empty($aviso['actividad'])) {
                $contexto .= ' en «' . $aviso['actividad']['titulo'] . '» (' . fecha_cr($aviso['actividad']['fechaInicio'], 'corta') . ')';
            }
            array_unshift($parrafos, $contexto . ':');
            $nombreRemitente = ($organizacion !== '' ? $organizacion : $remitente['nombre']) . ' (' . $nombreApp . ')';
        }
        $boton = empty($aviso['enlace']) ? null : [
            'texto' => $aviso['tipo'] === 'asistencia' ? 'Ver mis horas' : 'Ver la actividad',
            'url'   => url_absoluta($aviso['enlace']),
        ];
        return Correo::mensaje((string) $aviso['_id'], $persona['correo'], $persona['nombre'], $aviso['titulo'], [
            'titulo'   => $aviso['titulo'],
            'parrafos' => $parrafos,
            'boton'    => $boton,
            'nota'     => 'Le llega porque tiene una inscripción en una actividad de ' . $nombreApp . '. Todos los avisos quedan en '
                . url_absoluta('/avisos') . '. Para no recibirlos por correo, cámbielo en Mi cuenta: ' . url_absoluta('/mi-cuenta') . '.',
        ], $nombreRemitente);
    }

    /** Guarda el resultado de cada correo con una escritura por cada resultado distinto, no una por persona. */
    private static function guardarResultados(array $resultados): void
    {
        $grupos = [];
        foreach ($resultados as $id => $resultado) {
            $grupos[$resultado['estado'] . '|' . ($resultado['motivo'] ?? '')][] = new ObjectId((string) $id);
        }
        $bd = Mongo::get();
        foreach ($grupos as $clave => $ids) {
            [$estado, $motivo] = explode('|', $clave, 2);
            $cambios = ['$set' => [
                'correo.estado'    => $estado,
                'correo.motivo'    => $motivo !== '' ? mb_strimwidth($motivo, 0, 300, '…') : null,
                'correo.enviadoEn' => $estado === 'enviado' ? Mongo::ahora() : null,
            ]];
            if ($estado !== 'omitido') {
                $cambios['$inc'] = ['correo.intentos' => 1];
            }
            $bd->actualizar(self::COLECCION, ['_id' => ['$in' => $ids]], $cambios, true);
        }
    }

    /* ---------- Avisos automáticos ---------- */

    /** Cancelación, retiro del portal o nueva publicación (gancho actividad.estado_cambiado). */
    public static function avisarEstado(array $actividad, string $estadoNuevo): ?array
    {
        $titulo = $actividad['titulo'];
        $organizacion = $actividad['organizacion']['nombre'];
        $fecha = fecha_cr($actividad['fechaInicio']);
        $datos = match (true) {
            $estadoNuevo === 'cancelada' => [
                'tipo'    => 'cancelada',
                'titulo'  => 'Se canceló «' . $titulo . '»',
                'mensaje' => "{$organizacion} canceló la actividad del {$fecha}. Si la vuelve a publicar, le avisaremos.",
                'enlace'  => '/actividades/' . $actividad['_id'],
            ],
            $actividad['estado'] === 'publicada' && $estadoNuevo === 'borrador' => [
                'tipo'    => 'retirada',
                'titulo'  => '«' . $titulo . '» salió del portal',
                'mensaje' => "{$organizacion} retiró del portal la actividad del {$fecha}. Su inscripción se mantiene; le avisaremos si se vuelve a publicar o si se cancela.",
                // Un borrador solo lo ve su organización, así que no hay página que enlazar.
                'enlace'  => null,
            ],
            $estadoNuevo === 'publicada' => [
                'tipo'    => 'publicada',
                'titulo'  => '«' . $titulo . '» está de nuevo en el portal',
                'mensaje' => "{$organizacion} volvió a publicar la actividad. Su inscripción sigue vigente: {$fecha}, en {$actividad['lugar']}, {$actividad['provincia']}.",
                'enlace'  => '/actividades/' . $actividad['_id'],
            ],
            default => null,
        };
        return $datos === null ? null : self::aInscritos($actividad, $datos);
    }

    /**
     * Cambios de fecha, lugar, horas o requisitos (gancho actividad.actualizada). Un solo aviso dice
     * todo lo que cambió. Solo para actividades publicadas que todavía no empiezan.
     */
    public static function avisarCambios(array $anterior, array $nueva): ?array
    {
        if ($nueva['estado'] !== 'publicada' || $nueva['fechaInicio']->toDateTime()->getTimestamp() <= time()) {
            return null;
        }
        $horas = fn(array $a): string => plural((float) $a['horas'], 'hora', 'horas');
        $cambios = [];
        if ($anterior['fechaInicio']->toDateTime()->getTimestamp() !== $nueva['fechaInicio']->toDateTime()->getTimestamp()) {
            $cambios[] = 'Fecha: ahora es el ' . fecha_cr($nueva['fechaInicio']) . ' (antes, el ' . fecha_cr($anterior['fechaInicio']) . ').';
        }
        if ($anterior['lugar'] !== $nueva['lugar'] || $anterior['provincia'] !== $nueva['provincia']) {
            $cambios[] = 'Lugar de encuentro: ' . $nueva['lugar'] . ', ' . $nueva['provincia']
                . ' (antes, ' . $anterior['lugar'] . ', ' . $anterior['provincia'] . ').';
        }
        if ((float) $anterior['horas'] !== (float) $nueva['horas']) {
            $cambios[] = 'Horas que acredita: ' . $horas($nueva) . ' (antes, ' . $horas($anterior) . ').';
        }
        $requisitos = trim((string) ($nueva['requisitos'] ?? ''));
        if (trim((string) ($anterior['requisitos'] ?? '')) !== $requisitos) {
            $cambios[] = $requisitos !== ''
                ? "Qué llevar y requisitos:\n" . $requisitos
                : 'Ya no hay requisitos ni nada especial que llevar.';
        }
        if (!$cambios) {
            return null;
        }
        return self::aInscritos($nueva, [
            'tipo'    => 'cambios',
            'titulo'  => 'Cambios en «' . $nueva['titulo'] . '»',
            'mensaje' => $nueva['organizacion']['nombre'] . " cambió datos de la actividad:\n\n" . implode("\n\n", $cambios),
            'enlace'  => '/actividades/' . $nueva['_id'],
        ]);
    }

    /**
     * Horas acreditadas o restadas al guardar la asistencia (gancho asistencia.guardada).
     * $horasPorVoluntario: [id del voluntario => horas sumadas (positivas) o restadas (negativas)].
     */
    public static function avisarAsistencia(array $actividad, array $horasPorVoluntario): ?array
    {
        // Un envío por cada cantidad de horas: casi siempre uno para los presentes y, si se corrigió
        // la lista, otro para quienes dejaron de figurar como presentes.
        $grupos = [];
        foreach ($horasPorVoluntario as $voluntario => $horas) {
            if ((float) $horas !== 0.0) {
                $grupos[(string) (float) $horas][] = (string) $voluntario;
            }
        }
        $total = ['personas' => 0, 'correos' => 0];
        foreach ($grupos as $horas => $voluntarios) {
            $horas = (float) $horas;
            $texto = plural(abs($horas), 'hora', 'horas');
            $resultado = self::crear($voluntarios, ($horas > 0 ? [
                'tipo'    => 'asistencia',
                'titulo'  => 'Se registró su asistencia en «' . $actividad['titulo'] . '»',
                'mensaje' => "Gracias por participar. Se le acreditaron {$texto}, que ya aparecen en Mis horas.",
            ] : [
                'tipo'    => 'asistencia',
                'titulo'  => 'Se corrigió su asistencia en «' . $actividad['titulo'] . '»',
                'mensaje' => "La organización corrigió la lista de asistencia: ya no figura como presente y se restaron {$texto} de Mis horas. Si cree que es un error, comuníquese con la organización.",
            ]) + ['actividad' => $actividad, 'enlace' => '/mis-horas']);
            $total['personas'] += $resultado['personas'];
            $total['correos'] += $resultado['correos'];
        }
        return $total;
    }

    /* ---------- Mensaje de la organización a sus inscritos ---------- */

    /** Valida el formulario del coordinador. Devuelve [['titulo', 'mensaje'], errores por campo]. */
    public static function validarMensaje(array $entrada): array
    {
        // El asunto va en una sola línea.
        $titulo = trim((string) preg_replace('/\s+/u', ' ', (string) ($entrada['titulo'] ?? '')));
        $mensaje = trim(str_replace("\r\n", "\n", (string) ($entrada['mensaje'] ?? '')));
        $errores = [];
        if (mb_strlen($titulo) < 3 || mb_strlen($titulo) > 120) {
            $errores['titulo'] = 'Escriba un asunto de 3 a 120 caracteres.';
        }
        if (mb_strlen($mensaje) < 10 || mb_strlen($mensaje) > 2000) {
            $errores['mensaje'] = 'Escriba el mensaje (10 a 2000 caracteres).';
        }
        return [['titulo' => $titulo, 'mensaje' => $mensaje], $errores];
    }

    /* ---------- Bandeja de cada persona ---------- */

    /** Avisos de una persona, del más reciente al más antiguo: ['items', 'total', 'pagina', 'paginas']. */
    public static function bandeja(ObjectId $usuarioId, int $pagina): array
    {
        $bd = Mongo::get();
        $filtro = ['usuarioId' => $usuarioId];
        $total = $bd->contar(self::COLECCION, $filtro);
        $paginas = max(1, (int) ceil($total / self::POR_PAGINA));
        $pagina = min(max(1, $pagina), $paginas);
        $items = $bd->buscar(self::COLECCION, $filtro, [
            'sort'       => ['creadaEn' => -1],
            'skip'       => ($pagina - 1) * self::POR_PAGINA,
            'limit'      => self::POR_PAGINA,
            'projection' => ['correo' => 0],
        ]);
        return ['items' => $items, 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
    }

    public static function sinLeer(ObjectId $usuarioId): int
    {
        return Mongo::get()->contar(self::COLECCION, ['usuarioId' => $usuarioId, 'leidaEn' => null]);
    }

    /** Marca como leídos los avisos dados, o todos los de la persona si $ids es null. */
    public static function marcarLeidas(ObjectId $usuarioId, ?array $ids = null): int
    {
        $filtro = ['usuarioId' => $usuarioId, 'leidaEn' => null];
        if ($ids !== null) {
            if (!$ids) {
                return 0;
            }
            $filtro['_id'] = ['$in' => array_values($ids)];
        }
        return Mongo::get()->actualizar(self::COLECCION, $filtro, ['$set' => ['leidaEn' => Mongo::ahora()]], true);
    }

    /* ---------- Historial y estado ---------- */

    /**
     * Avisos que recibieron los inscritos de una actividad, uno por envío (aggregation pipeline): tipo,
     * título, fecha, a cuántas personas llegó, cuántas lo leyeron y cuántas lo recibieron por correo.
     */
    public static function historialActividad(ObjectId $actividadId): array
    {
        return Mongo::get()->agregar(self::COLECCION, [
            ['$match' => ['actividadId' => $actividadId]],
            ['$group' => [
                '_id'       => '$envioId',
                'tipo'      => ['$first' => '$tipo'],
                'titulo'    => ['$first' => '$titulo'],
                'mensaje'   => ['$first' => '$mensaje'],
                'remitente' => ['$first' => '$remitente.nombre'],
                'creadaEn'  => ['$first' => '$creadaEn'],
                'personas'  => ['$sum' => 1],
                'leidas'    => ['$sum' => ['$cond' => [['$ne' => ['$leidaEn', null]], 1, 0]]],
                'correos'   => ['$sum' => ['$cond' => [['$eq' => ['$correo.estado', 'enviado']], 1, 0]]],
            ]],
            ['$sort' => ['creadaEn' => -1]],
            ['$limit' => 50],
        ]);
    }

    /** Correos de las últimas 24 horas por estado, para /estado. */
    public static function conteoCorreos(): array
    {
        $estados = ['pendiente', 'enviado', 'fallido', 'omitido'];
        $filas = Mongo::get()->agregar(self::COLECCION, [
            ['$match' => ['correo.estado' => ['$in' => $estados], 'creadaEn' => ['$gte' => Mongo::fecha(time() - 86400)]]],
            ['$group' => ['_id' => '$correo.estado', 'total' => ['$sum' => 1]]],
        ]);
        return array_column($filas, 'total', '_id') + array_fill_keys($estados, 0);
    }
}
