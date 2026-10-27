<?php
/**
 * Módulo de usuarios: registro, confirmación del correo, inicio de sesión, "Mi cuenta", horas del
 * voluntario y registro de asistencia por parte del coordinador.
 * Responsable: Persona 4.
 */
declare(strict_types=1);

require_once __DIR__ . '/UsuarioRepositorio.php';
require_once __DIR__ . '/VerificacionCorreo.php';
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
     * que la cuenta siga activa, con el correo confirmado, y que la contraseña no se haya
     * restablecido. Así, si la administración desactiva una cuenta, le cambia el rol o el correo,
     * el cambio se aplica enseguida.
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
            flash('aviso', 'Su sesión se cerró porque la cuenta fue desactivada, su contraseña cambió o su correo tiene que confirmarse de nuevo. Vuelva a iniciar sesión.');
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

    /*
     * Cuenta que todavía no confirmó su correo. Se recuerda en la sesión de este navegador, donde se
     * creó o donde se escribió bien su contraseña, para mostrarle "Confirme su correo" y dejarle pedir
     * otro enlace sin escribir ningún correo en un formulario.
     */
    $recordarPendiente = function (string $id, ?array $envio = null): void {
        if (($_SESSION['verificacion_pendiente'] ?? null) !== $id) {
            unset($_SESSION['verificacion_enlace']);
        }
        $_SESSION['verificacion_pendiente'] = $id;
        // Si el correo no se puede enviar (sin Mailjet o a una dirección .test), el enlace se muestra
        // en la página, solo en este navegador.
        if ($envio !== null && $envio['estado'] === 'omitido') {
            $_SESSION['verificacion_enlace'] = ['ruta' => $envio['ruta'], 'motivo' => $envio['motivo']];
        }
    };

    /* ---------- Registro e inicio de sesión ---------- */

    $router->get('/registro', function (): void {
        vista('usuarios/registro', ['valores' => [], 'errores' => []], 'Crear cuenta');
    });

    $router->post('/registro', function () use ($recordarPendiente): void {
        [$doc, $errores] = UsuarioRepositorio::registrar($_POST);
        if ($errores) {
            vista('usuarios/registro', ['valores' => $_POST, 'errores' => $errores], 'Crear cuenta', 422);
            return;
        }
        $envio = VerificacionCorreo::enviar($doc);
        $recordarPendiente((string) $doc['_id'], $envio);
        if ($envio['estado'] === 'fallido') {
            flash('error', VerificacionCorreo::textoResultado($envio, $doc['correo']));
        }
        redirigir('/verificar-correo');
    });

    $router->get('/ingresar', function () use ($destinoSeguro): void {
        if (consulta('volver') !== '') {
            $_SESSION['volver_a'] = $destinoSeguro(consulta('volver'));
        }
        vista('usuarios/ingresar', ['correo' => '', 'error' => ''], 'Iniciar sesión');
    });

    $router->post('/ingresar', function () use ($destinoSeguro, $inicioPorRol, $recordarPendiente): void {
        [$usuario, $error, $sinConfirmar] = UsuarioRepositorio::autenticar(entrada('correo'), (string) ($_POST['clave'] ?? ''));
        if ($sinConfirmar !== null) {
            $recordarPendiente($sinConfirmar);
            flash('aviso', 'Confirme su correo antes de iniciar sesión: abra el enlace que le enviamos o pida uno nuevo.');
            redirigir('/verificar-correo');
        }
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

    /* ---------- Confirmación del correo ---------- */

    $router->get('/verificar-correo', function (): void {
        $doc = UsuarioRepositorio::buscar((string) ($_SESSION['verificacion_pendiente'] ?? ''));
        if ($doc === null || !VerificacionCorreo::pendiente($doc)) {
            unset($_SESSION['verificacion_pendiente'], $_SESSION['verificacion_enlace']);
            if ($doc !== null) {
                flash('exito', 'Su correo ya está confirmado. Inicie sesión.');
            }
            redirigir('/ingresar');
        }
        vista('usuarios/verificar', ['doc' => $doc, 'enlace' => $_SESSION['verificacion_enlace'] ?? null], 'Confirme su correo');
    });

    $router->post('/verificar-correo/reenviar', function () use ($recordarPendiente): void {
        $doc = UsuarioRepositorio::buscar((string) ($_SESSION['verificacion_pendiente'] ?? ''));
        if ($doc === null) {
            redirigir('/ingresar');
        }
        $envio = VerificacionCorreo::enviar($doc);
        $recordarPendiente((string) $doc['_id'], $envio);
        $tipo = ['enviado' => 'exito', 'fallido' => 'error'][$envio['estado']] ?? 'aviso';
        flash($tipo, VerificacionCorreo::textoResultado($envio, $doc['correo']));
        redirigir('/verificar-correo');
    });

    $router->get('/verificar-correo/{id}/{token}', function (string $id, string $token) use ($inicioPorRol): void {
        $resultado = VerificacionCorreo::confirmar($id, $token);
        // Solo se entra directo si el enlace se abre en el navegador donde se creó la cuenta o se
        // escribió su contraseña: así nadie queda dentro de una cuenta que otra persona creó con su correo.
        $mismoNavegador = ($_SESSION['verificacion_pendiente'] ?? null) === $id;

        if ($resultado['estado'] === 'vencido' || $resultado['estado'] === 'invalido') {
            $problema = $resultado['estado'] === 'vencido' ? 'El enlace venció.' : 'El enlace no es válido o ya se usó.';
            if ($mismoNavegador) {
                flash('error', $problema . ' Use el del correo más reciente o pida uno nuevo.');
                redirigir('/verificar-correo');
            }
            flash('error', $problema . ' Si todavía no confirmó su correo, inicie sesión para pedir uno nuevo.');
            redirigir('/ingresar');
        }

        if ($mismoNavegador) {
            unset($_SESSION['verificacion_pendiente'], $_SESSION['verificacion_enlace']);
        }
        $doc = $resultado['usuario'];
        if ($resultado['estado'] === 'confirmado' && $mismoNavegador && UsuarioRepositorio::estaActivo($doc)) {
            $usuario = UsuarioRepositorio::aSesion($doc);
            Sesion::iniciarComo($usuario);
            flash('exito', 'Correo confirmado. Bienvenido, ' . $usuario['nombre'] . '.');
            redirigir($inicioPorRol[$usuario['rol']] ?? '/');
        }
        flash('exito', $resultado['estado'] === 'confirmado' ? 'Correo confirmado. Ya puede iniciar sesión.' : 'Su correo ya estaba confirmado. Inicie sesión.');
        redirigir('/ingresar');
    });

    /* ---------- Mi cuenta (todos los roles) ---------- */

    $mostrarCuenta = function (array $erroresPerfil = [], array $erroresClave = [], ?array $valores = null, int $codigo = 200): void {
        $usuario = Sesion::requiere();
        $doc = UsuarioRepositorio::buscar($usuario['id']);
        vista('usuarios/cuenta', [
            'doc'          => $doc,
            'valores'      => $valores ?? [
                'nombre'          => $doc['nombre'],
                'provincia'       => $doc['provincia'] ?? '',
                'avisosPorCorreo' => ($doc['avisosPorCorreo'] ?? true) !== false,
            ],
            'erroresPerfil' => $erroresPerfil,
            'erroresClave' => $erroresClave,
        ], 'Mi cuenta', $codigo);
    };

    $router->get('/mi-cuenta', function () use ($mostrarCuenta): void {
        $mostrarCuenta();
    });

    $router->post('/mi-cuenta', function () use ($mostrarCuenta): void {
        $usuario = Sesion::requiere();
        [$sesion, $errores] = UsuarioRepositorio::actualizarPerfil(Mongo::id($usuario['id']), $_POST, $usuario['rol'] === 'voluntario');
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
        // Por ejemplo, el módulo de notificaciones avisa a cada persona las horas que se le sumaron o restaron.
        Ganchos::ejecutar('asistencia.guardada', $actividad, $horasPorVoluntario);
        flash('exito', $cambios > 0 ? 'Asistencia guardada (' . plural($cambios, 'cambio', 'cambios') . ').' : 'No había cambios que guardar.');
        redirigir('/panel/actividades/' . $id . '/asistencia');
    });
};
