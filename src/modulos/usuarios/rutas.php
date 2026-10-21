<?php
/**
 * Módulo de usuarios: registro, inicio de sesión, "Mi cuenta", horas del voluntario y
 * registro de asistencia por parte del coordinador.
 * Responsable: Persona 4.
 */
declare(strict_types=1);

require_once __DIR__ . '/UsuarioRepositorio.php';
require_once __DIR__ . '/RankingHoras.php';

return function (Router $router): void {

    Ganchos::agregar('menu', function (?array $usuario): array {
        return ($usuario && $usuario['rol'] === 'voluntario')
            ? [['texto' => 'Mis horas', 'url' => url('/mis-horas'), 'orden' => 40]]
            : [];
    });

    Ganchos::agregar('menu.cuenta', function (?array $usuario): string {
        if ($usuario === null) {
            return '<a href="' . e(url('/ingresar')) . '">Iniciar sesión</a>'
                . '<a class="boton boton--pequeno" href="' . e(url('/registro')) . '">Crear cuenta</a>';
        }
        $detalle = Catalogos::NOMBRES_ROL[$usuario['rol']] ?? $usuario['rol'];
        if ($usuario['organizacion']) {
            $detalle .= ', ' . $usuario['organizacion']['nombre'];
        }
        return '<a class="cuenta__nombre" href="' . e(url('/mi-cuenta')) . '" title="' . e($detalle) . '">' . e($usuario['nombre']) . '</a>'
            . '<form method="post" action="' . e(url('/salir')) . '">' . csrf_campo()
            . '<button class="boton-enlace" type="submit">Salir</button></form>';
    });

    Ganchos::agregar('panel.actividad.acciones', function (array $actividad): string {
        return '<a href="' . e(url('/panel/actividades/' . $actividad['_id'] . '/asistencia')) . '">Inscritos y asistencia</a>';
    });

    /*
     * En cada solicitud, como máximo cada SESION_VERIFICAR_SEG segundos, se revisa en MongoDB
     * que la cuenta siga activa y que la contraseña no se haya restablecido. Así, si la
     * administración desactiva una cuenta o le cambia el rol, el cambio se aplica enseguida.
     */
    Ganchos::agregar('solicitud.inicio', function (): void {
        $usuario = Sesion::usuario();
        if ($usuario === null || time() - (int) ($_SESSION['verificado_en'] ?? 0) < Config::int('SESION_VERIFICAR_SEG', 60)) {
            return;
        }
        try {
            $actualizado = UsuarioRepositorio::revisarSesion($usuario, (int) ($_SESSION['iniciada_en'] ?? 0));
        } catch (MongoDB\Driver\Exception\Exception $error) {
            return; // MongoDB no responde: se mantiene la sesión y se revisa en la próxima solicitud
        }
        if ($actualizado === null) {
            Sesion::cerrar();
            flash('aviso', 'Su sesión se cerró porque la cuenta fue desactivada o su contraseña cambió. Vuelva a iniciar sesión.');
            return;
        }
        Sesion::actualizar($actualizado);
        $_SESSION['verificado_en'] = time();
    });

    /** Solo acepta direcciones internas para volver después de iniciar sesión. */
    $destinoSeguro = function (?string $ruta): string {
        $ruta = (string) $ruta;
        $base = rtrim((string) Config::get('APP_BASE', ''), '/');
        if ($base !== '' && str_starts_with($ruta, $base)) {
            $ruta = substr($ruta, strlen($base));
        }
        return (str_starts_with($ruta, '/') && !str_starts_with($ruta, '//')) ? $ruta : '/';
    };

    $inicioPorRol = ['voluntario' => '/actividades', 'coordinador' => '/panel', 'admin' => '/admin/usuarios'];

    /* ---------- Registro e inicio de sesión ---------- */

    $router->get('/registro', function (): void {
        vista('usuarios/registro', ['valores' => [], 'errores' => []], 'Crear cuenta');
    });

    $router->post('/registro', function (): void {
        [$usuario, $errores] = UsuarioRepositorio::registrar($_POST);
        if ($errores) {
            vista('usuarios/registro', ['valores' => $_POST, 'errores' => $errores], 'Crear cuenta', 422);
            return;
        }
        Sesion::iniciarComo($usuario);
        flash('exito', 'Bienvenido, ' . $usuario['nombre'] . '. Ya puede reservar cupos.');
        redirigir('/actividades');
    });

    $router->get('/ingresar', function () use ($destinoSeguro): void {
        if (consulta('volver') !== '') {
            $_SESSION['volver_a'] = $destinoSeguro(consulta('volver'));
        }
        vista('usuarios/ingresar', ['correo' => '', 'error' => ''], 'Iniciar sesión');
    });

    $router->post('/ingresar', function () use ($destinoSeguro, $inicioPorRol): void {
        [$usuario, $error] = UsuarioRepositorio::autenticar(entrada('correo'), (string) ($_POST['clave'] ?? ''));
        if ($usuario === null) {
            vista('usuarios/ingresar', ['correo' => entrada('correo'), 'error' => $error], 'Iniciar sesión', 401);
            return;
        }
        $volver = $destinoSeguro($_SESSION['volver_a'] ?? null);
        unset($_SESSION['volver_a']);
        Sesion::iniciarComo($usuario);
        flash('exito', 'Hola, ' . $usuario['nombre'] . '.');
        redirigir($volver !== '/' ? $volver : ($inicioPorRol[$usuario['rol']] ?? '/'));
    });

    $router->post('/salir', function (): void {
        Sesion::cerrar();
        flash('exito', 'Sesión cerrada.');
        redirigir('/actividades');
    });

    /* ---------- Mi cuenta (todos los roles) ---------- */

    $mostrarCuenta = function (array $erroresPerfil = [], array $erroresClave = [], ?array $valores = null, int $codigo = 200): void {
        $usuario = Sesion::requiere();
        $doc = UsuarioRepositorio::buscar($usuario['id']);
        vista('usuarios/cuenta', [
            'doc'          => $doc,
            'valores'      => $valores ?? ['nombre' => $doc['nombre'], 'provincia' => $doc['provincia'] ?? ''],
            'erroresPerfil' => $erroresPerfil,
            'erroresClave' => $erroresClave,
        ], 'Mi cuenta', $codigo);
    };

    $router->get('/mi-cuenta', function () use ($mostrarCuenta): void {
        $mostrarCuenta();
    });

    $router->post('/mi-cuenta', function () use ($mostrarCuenta): void {
        $usuario = Sesion::requiere();
        [$sesion, $errores] = UsuarioRepositorio::actualizarPerfil(Mongo::id($usuario['id']), $_POST);
        if ($errores) {
            $mostrarCuenta($errores, [], $_POST, 422);
            return;
        }
        Sesion::actualizar($sesion);
        flash('exito', 'Datos guardados.');
        redirigir('/mi-cuenta');
    });

    $router->post('/mi-cuenta/clave', function () use ($mostrarCuenta): void {
        $usuario = Sesion::requiere();
        $errores = UsuarioRepositorio::cambiarClave(Mongo::id($usuario['id']), (string) ($_POST['actual'] ?? ''), $_POST);
        if ($errores) {
            $mostrarCuenta([], $errores, null, 422);
            return;
        }
        Sesion::iniciarComo($usuario); // nuevo identificador de sesión
        flash('exito', 'Contraseña actualizada.');
        redirigir('/mi-cuenta');
    });

    /* ---------- Horas del voluntario ---------- */

    $router->get('/mis-horas', function (): void {
        $usuario = Sesion::requiere('voluntario');
        $id = Mongo::id($usuario['id']);
        $porCategoria = UsuarioRepositorio::horasPorCategoria($id);
        $asistidas = UsuarioRepositorio::actividadesAsistidas($id);
        vista('usuarios/horas', [
            'usuario'      => $usuario,
            'porCategoria' => $porCategoria,
            'totalHoras'   => array_sum(array_column($porCategoria, 'horas')),
            'totalActiv'   => count($asistidas),
            'asistidas'    => $asistidas,
            'ranking'      => RankingHoras::consultar($usuario['id']),
        ], 'Mis horas');
    });

    /* ---------- Asistencia (coordinador) ---------- */

    $cargarActividad = function (string $id): array {
        $usuario = Sesion::requiere('coordinador');
        $actividad = Mongo::id($id) ? Mongo::get()->buscarUno('actividades', ['_id' => Mongo::id($id)]) : null;
        if ($actividad === null) {
            abortar(404, 'Esa actividad no existe.');
        }
        if ((string) $actividad['organizacion']['id'] !== ($usuario['organizacion']['id'] ?? '')) {
            abortar(403, 'Solo la organización que publicó la actividad puede ver sus inscritos.');
        }
        return [$actividad, $usuario];
    };

    // La asistencia se puede registrar desde 2 horas antes del inicio.
    $asistenciaAbierta = fn(array $actividad): bool => $actividad['fechaInicio']->toDateTime()->getTimestamp() - 7200 <= time();

    $router->get('/panel/actividades/{id}/asistencia', function (string $id) use ($cargarActividad, $asistenciaAbierta): void {
        [$actividad] = $cargarActividad($id);
        $inscritos = Mongo::get()->buscar('inscripciones', [
            'actividadId' => $actividad['_id'],
            'estado'      => ['$in' => ['confirmada', 'reservada']],
        ], ['sort' => ['voluntario.nombre' => 1]]);
        vista('usuarios/asistencia', [
            'actividad'   => $actividad,
            'confirmados' => array_values(array_filter($inscritos, fn($i) => $i['estado'] === 'confirmada')),
            'pendientes'  => array_values(array_filter($inscritos, fn($i) => $i['estado'] === 'reservada')),
            'abierta'     => $asistenciaAbierta($actividad),
        ], 'Inscritos y asistencia');
    });

    $router->post('/panel/actividades/{id}/asistencia', function (string $id) use ($cargarActividad, $asistenciaAbierta): void {
        [$actividad, $usuario] = $cargarActividad($id);
        if (!$asistenciaAbierta($actividad)) {
            flash('error', 'La asistencia se registra el día de la actividad, desde dos horas antes del inicio.');
            redirigir('/panel/actividades/' . $id . '/asistencia');
        }
        $presentes = array_flip(array_filter((array) ($_POST['presentes'] ?? []), 'is_string'));
        $bd = Mongo::get();
        $confirmados = $bd->buscar('inscripciones', ['actividadId' => $actividad['_id'], 'estado' => 'confirmada'], ['projection' => ['asistencia' => 1, 'voluntarioId' => 1]]);
        $cambios = 0;
        $horasPorVoluntario = [];
        foreach ($confirmados as $inscripcion) {
            $marcado = isset($presentes[(string) $inscripcion['_id']]);
            $estaba = !empty($inscripcion['asistencia']['presente']);
            if ($marcado === $estaba) {
                continue;
            }
            $guardado = $bd->actualizar('inscripciones', ['_id' => $inscripcion['_id'], 'estado' => 'confirmada'], ['$set' => ['asistencia' => [
                'presente'      => $marcado,
                'horas'         => $marcado ? $actividad['horas'] : 0,
                'registradaEn'  => Mongo::ahora(),
                'registradaPor' => Mongo::id($usuario['id']),
            ]]]);
            if ($guardado > 0) {
                // Al desmarcar se restan las horas que se habían guardado, no las actuales de la actividad.
                $voluntario = (string) $inscripcion['voluntarioId'];
                $horasPorVoluntario[$voluntario] = ($horasPorVoluntario[$voluntario] ?? 0)
                    + ($marcado ? (float) $actividad['horas'] : -(float) ($inscripcion['asistencia']['horas'] ?? 0));
            }
            $cambios += $guardado;
        }
        RankingHoras::sumar($horasPorVoluntario);
        flash('exito', $cambios > 0 ? 'Asistencia guardada (' . plural($cambios, 'cambio', 'cambios') . ').' : 'No había cambios que guardar.');
        redirigir('/panel/actividades/' . $id . '/asistencia');
    });
};
