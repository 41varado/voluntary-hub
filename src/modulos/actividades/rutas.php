<?php
/**
 * Módulo de actividades: portal público, detalle y panel del coordinador
 * (crear, editar, publicar, cancelar, eliminar y subir imágenes).
 * Responsable: Persona 2.
 */
declare(strict_types=1);

require_once __DIR__ . '/ActividadRepositorio.php';
require_once __DIR__ . '/Imagenes.php';
require_once __DIR__ . '/ayudantes.php';

return function (Router $router): void {

    Ganchos::agregar('menu', function (?array $usuario): array {
        $enlaces = [['texto' => 'Actividades', 'url' => url('/actividades'), 'orden' => 10]];
        if ($usuario && $usuario['rol'] === 'coordinador') {
            $enlaces[] = ['texto' => 'Panel de mi organización', 'url' => url('/panel'), 'orden' => 20];
        }
        return $enlaces;
    });

    // Cuando otro módulo cambia los cupos (reservas, cancelaciones), el listado en caché queda viejo.
    Ganchos::agregar('cupos.cambiaron', fn() => ActividadRepositorio::invalidarCache());

    /** Carga una actividad de la organización del coordinador o corta con 404/403. */
    $propia = function (string $id): array {
        $usuario = Sesion::requiere('coordinador');
        $actividad = ActividadRepositorio::buscar($id);
        if ($actividad === null) {
            abortar(404, 'Esa actividad no existe.');
        }
        if (!ActividadRepositorio::esPropia($actividad, $usuario)) {
            abortar(403, 'Solo la organización que publicó la actividad puede modificarla.');
        }
        return $actividad;
    };

    /* ---------- Portal público ---------- */

    $router->get('/actividades', function (): void {
        $filtros = [
            'categoria' => consulta('categoria'),
            'provincia' => consulta('provincia'),
            'q'         => mb_substr(consulta('q'), 0, 60),
            'cupo'      => consulta('cupo') === '1' ? '1' : '',
        ];
        $resultado = ActividadRepositorio::listarPublicadas($filtros, max(1, (int) consulta('pagina', '1')));
        vista('actividades/lista', ['resultado' => $resultado, 'filtros' => $filtros], 'Actividades de voluntariado');
    });

    $router->get('/actividades/{id}', function (string $id): void {
        $actividad = ActividadRepositorio::buscar($id);
        $usuario = Sesion::usuario();
        // Los borradores solo los ve su organización. Las canceladas no salen en el portal, pero se
        // pueden abrir por enlace, como el del aviso de cancelación, y dicen que se cancelaron.
        if ($actividad === null || ($actividad['estado'] === 'borrador' && !ActividadRepositorio::esPropia($actividad, $usuario))) {
            abortar(404, 'Esa actividad no existe o ya no está publicada.');
        }
        // Otros módulos pueden actualizar datos antes de mostrarla (p. ej., liberar reservas vencidas).
        if (array_sum(array_map('intval', Ganchos::ejecutar('actividad.antes_de_ver', $actividad))) > 0) {
            $actividad = ActividadRepositorio::buscar($id);
        }
        $esPropia = ActividadRepositorio::esPropia($actividad, $usuario);
        // Las visitas de la organización que la publicó no cuentan.
        if (!$esPropia) {
            ActividadRepositorio::registrarVisita((string) $actividad['_id']);
        }
        vista('actividades/detalle', [
            'a'          => $actividad,
            'usuario'    => $usuario,
            'esPropia'   => $esPropia,
            'panel'      => Ganchos::html('actividad.panel', $actividad, $usuario),
        ], $actividad['titulo']);
    });

    /* ---------- Panel del coordinador ---------- */

    $router->get('/panel', function (): void {
        $usuario = Sesion::requiere('coordinador');
        $actividades = ActividadRepositorio::deOrganizacion($usuario['organizacion']['id']);
        vista('actividades/panel', [
            'usuario'     => $usuario,
            'actividades' => $actividades,
            'visitas'     => ActividadRepositorio::visitas(array_map(fn(array $a) => (string) $a['_id'], $actividades)),
        ], 'Panel de mi organización');
    });

    $router->get('/panel/actividades/nueva', function (): void {
        Sesion::requiere('coordinador');
        vista('actividades/formulario', ['actividad' => null, 'valores' => ['horas' => '3', 'cupoTotal' => '20'], 'errores' => []], 'Nueva actividad');
    });

    $router->post('/panel/actividades', function (): void {
        $usuario = Sesion::requiere('coordinador');
        [$datos, $errores] = ActividadRepositorio::validar($_POST);
        if ($errores) {
            vista('actividades/formulario', ['actividad' => null, 'valores' => $_POST, 'errores' => $errores], 'Nueva actividad', 422);
            return;
        }
        $estado = entrada('accion') === 'publicar' ? 'publicada' : 'borrador';
        $id = ActividadRepositorio::crear($datos, $usuario, $estado);
        flash('exito', $estado === 'publicada'
            ? 'Actividad publicada. Ahora puede agregarle imágenes.'
            : 'Borrador guardado. Agregue imágenes y publíquelo cuando esté listo.');
        redirigir('/panel/actividades/' . $id . '/editar');
    });

    $router->get('/panel/actividades/{id}/editar', function (string $id) use ($propia): void {
        $actividad = $propia($id);
        $valores = [
            'titulo'      => $actividad['titulo'],
            'descripcion' => $actividad['descripcion'],
            'requisitos'  => $actividad['requisitos'] ?? '',
            'categoria'   => $actividad['categoria'],
            'provincia'   => $actividad['provincia'],
            'lugar'       => $actividad['lugar'],
            'fechaInicio' => a_fecha($actividad['fechaInicio'])->format('Y-m-d\TH:i'),
            'horas'       => (string) $actividad['horas'],
            'cupoTotal'   => (string) $actividad['cupoTotal'],
        ];
        vista('actividades/formulario', ['actividad' => $actividad, 'valores' => $valores, 'errores' => []], 'Editar actividad');
    });

    $router->post('/panel/actividades/{id}', function (string $id) use ($propia): void {
        $actividad = $propia($id);
        [$datos, $errores] = ActividadRepositorio::validar($_POST, $actividad);
        if (!$errores && ($error = ActividadRepositorio::actualizar($actividad, $datos)) !== null) {
            $errores['cupoTotal'] = $error;
        }
        if ($errores) {
            vista('actividades/formulario', ['actividad' => $actividad, 'valores' => $_POST, 'errores' => $errores], 'Editar actividad', 422);
            return;
        }
        flash('exito', 'Cambios guardados.');
        redirigir('/panel/actividades/' . $id . '/editar');
    });

    $router->post('/panel/actividades/{id}/estado', function (string $id) use ($propia): void {
        $actividad = $propia($id);
        $estado = entrada('estado');
        ActividadRepositorio::cambiarEstado($actividad, $estado);
        $mensajes = [
            'publicada' => 'Actividad publicada en el portal.',
            'borrador'  => 'La actividad volvió a borrador y ya no se ve en el portal.',
            'cancelada' => 'Actividad cancelada. Las personas inscritas la verán como cancelada.',
        ];
        flash('exito', $mensajes[$estado] ?? 'Estado actualizado.');
        redirigir('/panel');
    });

    $router->post('/panel/actividades/{id}/eliminar', function (string $id) use ($propia): void {
        $actividad = $propia($id);
        $error = ActividadRepositorio::eliminar($actividad);
        flash($error ? 'error' : 'exito', $error ?? 'Actividad eliminada.');
        redirigir('/panel');
    });

    /* ---------- Imágenes ---------- */

    $router->post('/panel/actividades/{id}/imagenes', function (string $id) use ($propia): void {
        $actividad = $propia($id);
        [$guardadas, $errores] = Imagenes::recibir('imagenes', $id, count($actividad['imagenes']));
        ActividadRepositorio::agregarImagenes($actividad, $guardadas);
        if ($guardadas) {
            flash('exito', plural(count($guardadas), 'imagen agregada', 'imágenes agregadas') . '.');
        }
        foreach ($errores as $error) {
            flash('error', $error);
        }
        redirigir('/panel/actividades/' . $id . '/editar');
    });

    $router->post('/panel/actividades/{id}/imagenes/quitar', function (string $id) use ($propia): void {
        ActividadRepositorio::quitarImagen($propia($id), entrada('archivo'));
        flash('exito', 'Imagen eliminada.');
        redirigir('/panel/actividades/' . $id . '/editar');
    });

    $router->post('/panel/actividades/{id}/imagenes/portada', function (string $id) use ($propia): void {
        ActividadRepositorio::elegirPortada($propia($id), entrada('archivo'));
        flash('exito', 'Portada actualizada.');
        redirigir('/panel/actividades/' . $id . '/editar');
    });
};
