<?php
/**
 * Módulo de avisos: bandeja de cada voluntario, mensajes de la organización a todos los inscritos
 * de una actividad y avisos automáticos cuando la actividad cambia o se registra la asistencia.
 * Responsable: Persona 1.
 */
declare(strict_types=1);

require_once __DIR__ . '/Notificaciones.php';

return function (Router $router): void {

    // "Avisos" en el menú, con los que faltan por leer.
    Ganchos::agregar('menu', function (?array $usuario): array {
        if (!$usuario || $usuario['rol'] !== 'voluntario') {
            return [];
        }
        $enlace = ['texto' => 'Avisos', 'url' => url('/avisos'), 'orden' => 35];
        // El menú también se dibuja en las páginas de error, por ejemplo cuando MongoDB no responde:
        // ahí no se cuenta, para no esperar otra vez el tiempo límite de la conexión.
        if (http_response_code() < 500) {
            try {
                $enlace['contador'] = Notificaciones::sinLeer(Mongo::id($usuario['id']));
            } catch (Throwable) {
                // Sin contador: el enlace sigue sirviendo.
            }
        }
        return [$enlace];
    });

    Ganchos::agregar('panel.actividad.acciones', function (array $actividad): string {
        return '<a href="' . e(url('/panel/actividades/' . $actividad['_id'] . '/avisos')) . '">Avisos</a>';
    }, 60);

    /*
     * Avisos automáticos. La actividad ya se guardó cuando llegan aquí: si el aviso falla, no se
     * deshace el cambio; se anota el error y se le cuenta al coordinador.
     */
    $avisar = function (callable $crear): void {
        try {
            $resultado = $crear();
        } catch (Throwable $error) {
            error_log('[voluntacr] No se pudieron crear los avisos: ' . $error);
            flash('error', 'El cambio se guardó, pero no se pudo avisar a las personas inscritas.');
            return;
        }
        if ($resultado && $resultado['personas'] > 0) {
            flash('exito', 'Se avisó a ' . plural($resultado['personas'], 'persona inscrita', 'personas inscritas')
                . ($resultado['correos'] > 0 ? ' (' . $resultado['correos'] . ' también por correo)' : '') . '.');
        }
    };

    // Cancelación, retiro del portal o nueva publicación ($actividad: antes del cambio).
    Ganchos::agregar('actividad.estado_cambiado', function (array $actividad, string $estado) use ($avisar): void {
        $avisar(fn() => Notificaciones::avisarEstado($actividad, $estado));
    });

    // Cambios de fecha, lugar, horas o requisitos ($anterior: la actividad antes de guardar).
    Ganchos::agregar('actividad.actualizada', function (string $id, ?array $anterior = null) use ($avisar): void {
        if ($anterior === null) {
            return;
        }
        $avisar(function () use ($id, $anterior): ?array {
            $nueva = Mongo::get()->buscarUno('actividades', ['_id' => Mongo::id($id)]);
            return $nueva ? Notificaciones::avisarCambios($anterior, $nueva) : null;
        });
    });

    // Horas acreditadas o restadas al guardar la asistencia.
    Ganchos::agregar('asistencia.guardada', function (array $actividad, array $horasPorVoluntario) use ($avisar): void {
        $avisar(fn() => Notificaciones::avisarAsistencia($actividad, $horasPorVoluntario));
    });

    // Correo y avisos en la página de estado. No llama a Mailjet: solo cuenta lo que hay en MongoDB.
    Ganchos::agregar('estado.secciones', function (bool $mongoResponde): string {
        $conteos = null;
        if ($mongoResponde) {
            try {
                $conteos = Notificaciones::conteoCorreos();
            } catch (Throwable) {
                // MongoDB dejó de responder: se muestra solo la configuración.
            }
        }
        return parcial('notificaciones/estado', [
            'configurado' => Correo::configurado(),
            'remitente'   => Correo::remitente(),
            'conteos'     => $conteos,
        ]);
    });

    /* ---------- Bandeja del voluntario ---------- */

    $router->get('/avisos', function (): void {
        $usuario = Sesion::requiere('voluntario');
        $id = Mongo::id($usuario['id']);
        $bandeja = Notificaciones::bandeja($id, max(1, (int) consulta('pagina', '1')));
        // Los que se muestran por primera vez quedan leídos; en la página se resaltan como nuevos.
        $nuevos = [];
        foreach ($bandeja['items'] as $aviso) {
            if ($aviso['leidaEn'] === null) {
                $nuevos[(string) $aviso['_id']] = $aviso['_id'];
            }
        }
        Notificaciones::marcarLeidas($id, $nuevos);
        vista('notificaciones/bandeja', [
            'bandeja'      => $bandeja,
            'nuevos'       => $nuevos,
            'otrosSinLeer' => Notificaciones::sinLeer($id),
        ], 'Avisos');
    });

    $router->post('/avisos/leidos', function (): void {
        $usuario = Sesion::requiere('voluntario');
        Notificaciones::marcarLeidas(Mongo::id($usuario['id']));
        flash('exito', 'Todos los avisos quedaron como leídos.');
        redirigir('/avisos');
    });

    /* ---------- Mensajes de la organización a los inscritos de una actividad ---------- */

    /** Carga una actividad de la organización del coordinador o corta con 404/403. */
    $propia = function (string $id): array {
        $usuario = Sesion::requiere('coordinador');
        $actividad = Mongo::id($id) ? Mongo::get()->buscarUno('actividades', ['_id' => Mongo::id($id)]) : null;
        if ($actividad === null) {
            abortar(404, 'Esa actividad no existe.');
        }
        if ((string) $actividad['organizacion']['id'] !== ($usuario['organizacion']['id'] ?? '')) {
            abortar(403, 'Solo la organización que publicó la actividad puede enviar avisos a sus inscritos.');
        }
        return [$actividad, $usuario];
    };

    $mostrar = function (array $actividad, array $valores = [], array $errores = [], int $codigo = 200): void {
        vista('notificaciones/actividad', [
            'actividad' => $actividad,
            'inscritos' => count(Notificaciones::inscritos($actividad['_id'])),
            'historial' => Notificaciones::historialActividad($actividad['_id']),
            'valores'   => $valores,
            'errores'   => $errores,
        ], 'Avisos a los inscritos', $codigo);
    };

    $router->get('/panel/actividades/{id}/avisos', function (string $id) use ($propia, $mostrar): void {
        [$actividad] = $propia($id);
        $mostrar($actividad);
    });

    $router->post('/panel/actividades/{id}/avisos', function (string $id) use ($propia, $mostrar): void {
        [$actividad, $usuario] = $propia($id);
        [$datos, $errores] = Notificaciones::validarMensaje($_POST);
        $destinatarios = $errores ? [] : Notificaciones::inscritos($actividad['_id']);
        if (!$errores && !$destinatarios) {
            $errores['mensaje'] = 'Todavía no hay personas inscritas en esta actividad.';
        }
        if ($errores) {
            $mostrar($actividad, $_POST, $errores, 422);
            return;
        }

        // Candado contra el doble clic: un mensaje por actividad cada 10 s. No se borra al terminar,
        // así un segundo envío que llega justo después también se rechaza. Sin Redis no hay candado.
        $rs = RedisSeguro::get();
        $libre = $rs->usar(fn(Redis $r) => $r->set($rs->k('candado:aviso:' . $id), '1', ['nx', 'ex' => 10]), true);
        if ($libre === false) {
            flash('error', 'Ese aviso ya se envió hace unos segundos. Revise el historial antes de mandarlo otra vez.');
            redirigir('/panel/actividades/' . $id . '/avisos');
        }

        $resultado = Notificaciones::crear($destinatarios, $datos + [
            'tipo'      => 'mensaje',
            'actividad' => $actividad,
            // Un borrador solo lo ve su organización, así que no hay página que enlazar.
            'enlace'    => $actividad['estado'] === 'borrador' ? null : '/actividades/' . $actividad['_id'],
            'remitente' => [
                'id'           => Mongo::id($usuario['id']),
                'nombre'       => $usuario['nombre'],
                'organizacion' => $usuario['organizacion']['nombre'],
            ],
        ]);
        flash('exito', 'Aviso enviado a ' . plural($resultado['personas'], 'persona', 'personas')
            . ($resultado['correos'] > 0 ? ' (' . $resultado['correos'] . ' también por correo)' : '') . '.');
        redirigir('/panel/actividades/' . $id . '/avisos');
    });
};
