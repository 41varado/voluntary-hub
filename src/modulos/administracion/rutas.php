<?php
/**
 * Módulo de administración: pantalla de usuarios (voluntarios y coordinadores) y
 * pantalla de administradores. Solo para cuentas con rol "admin".
 * Responsable: Persona 1.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/usuarios/UsuarioRepositorio.php';
require_once dirname(__DIR__) . '/usuarios/VerificacionCorreo.php';
require_once __DIR__ . '/AdminUsuarios.php';

return function (Router $router): void {

    Ganchos::agregar('menu', function (?array $usuario): array {
        return ($usuario && $usuario['rol'] === 'admin')
            ? [['texto' => 'Administración', 'url' => url('/admin'), 'orden' => 20]]
            : [];
    });

    /** Carga una cuenta regular (no admin) o corta con 404. */
    $regular = function (string $id): array {
        $doc = UsuarioRepositorio::buscar($id);
        if ($doc === null || $doc['rol'] === 'admin') {
            abortar(404, 'Esa cuenta no existe o se administra desde la pantalla de administradores.');
        }
        return $doc;
    };

    /** Envía el enlace de confirmación a una cuenta nueva o con un correo nuevo y explica qué pasó. */
    $enviarEnlace = function (array $doc): string {
        $envio = VerificacionCorreo::enviar($doc);
        return VerificacionCorreo::textoResultado($envio, $doc['correo'])
            . ($envio['estado'] === 'enviado' ? ' La persona debe abrirlo antes de iniciar sesión.' : ' Cuando intente iniciar sesión, la persona podrá pedirlo de nuevo.');
    };

    $formulario = function (?array $doc, array $valores, array $errores, string $titulo, int $codigo = 200): void {
        vista('administracion/usuario', [
            'doc'             => $doc,
            'valores'         => $valores,
            'errores'         => $errores,
            'organizaciones'  => AdminUsuarios::organizaciones(),
        ], $titulo, $codigo);
    };

    $router->get('/admin', function (): void {
        Sesion::requiere('admin');
        redirigir('/admin/usuarios');
    });

    /* ---------- Usuarios regulares ---------- */

    $router->get('/admin/usuarios', function (): void {
        Sesion::requiere('admin');
        $filtros = [
            'q'      => mb_substr(consulta('q'), 0, 60),
            'rol'    => consulta('rol'),
            'estado' => consulta('estado'),
        ];
        vista('administracion/usuarios', [
            'resultado' => AdminUsuarios::listar($filtros, max(1, (int) consulta('pagina', '1')), false),
            'filtros'   => $filtros,
        ], 'Usuarios');
    });

    $router->get('/admin/usuarios/nuevo', function () use ($formulario): void {
        Sesion::requiere('admin');
        $formulario(null, ['rol' => 'voluntario'], [], 'Crear usuario');
    });

    $router->post('/admin/usuarios', function () use ($formulario, $enviarEnlace): void {
        $admin = Sesion::requiere('admin');
        [$doc, $errores] = AdminUsuarios::crearRegular($_POST, $admin);
        if ($errores) {
            $formulario(null, $_POST, $errores, 'Crear usuario', 422);
            return;
        }
        flash('exito', 'Cuenta creada para ' . $doc['nombre'] . '. ' . $enviarEnlace($doc) . ' Compártale la contraseña inicial por un medio seguro.');
        redirigir('/admin/usuarios');
    });

    $router->get('/admin/usuarios/{id}', function (string $id) use ($regular, $formulario): void {
        Sesion::requiere('admin');
        $doc = $regular($id);
        $formulario($doc, [
            'nombre'         => $doc['nombre'],
            'correo'         => $doc['correo'],
            'provincia'      => $doc['provincia'] ?? '',
            'rol'            => $doc['rol'],
            'organizacionId' => isset($doc['organizacion']['id']) ? (string) $doc['organizacion']['id'] : '',
        ], [], 'Editar usuario');
    });

    $router->post('/admin/usuarios/{id}', function (string $id) use ($regular, $formulario, $enviarEnlace): void {
        $admin = Sesion::requiere('admin');
        $doc = $regular($id);
        $errores = AdminUsuarios::actualizarRegular($doc, $_POST, $admin);
        if ($errores) {
            $formulario($doc, $_POST, $errores, 'Editar usuario', 422);
            return;
        }
        $actualizado = UsuarioRepositorio::buscar($id);
        if ($actualizado !== null && $actualizado['correo'] !== $doc['correo']) {
            flash('exito', 'Cambios guardados. El correo nuevo tiene que confirmarse y la sesión abierta se cierra. ' . $enviarEnlace($actualizado));
        } else {
            flash('exito', 'Cambios guardados. Se aplican la próxima vez que esa persona use el sistema.');
        }
        redirigir('/admin/usuarios/' . $id);
    });

    $router->post('/admin/usuarios/{id}/verificacion', function (string $id) use ($regular): void {
        Sesion::requiere('admin');
        $doc = $regular($id);
        $envio = VerificacionCorreo::enviar($doc);
        flash($envio['estado'] === 'enviado' ? 'exito' : 'aviso', VerificacionCorreo::textoResultado($envio, $doc['correo']));
        redirigir('/admin/usuarios/' . $id);
    });

    $router->post('/admin/usuarios/{id}/estado', function (string $id) use ($regular): void {
        $admin = Sesion::requiere('admin');
        $doc = $regular($id);
        $activar = entrada('activo') === '1';
        $error = AdminUsuarios::cambiarEstado($doc, $activar, $admin);
        flash($error ? 'error' : 'exito', $error ?? ($activar ? 'Cuenta activada.' : 'Cuenta desactivada. Ya no puede iniciar sesión y su sesión abierta se cerró.'));
        redirigir('/admin/usuarios/' . $id);
    });

    $router->post('/admin/usuarios/{id}/clave', function (string $id) use ($regular): void {
        $admin = Sesion::requiere('admin');
        $doc = $regular($id);
        $errores = AdminUsuarios::restablecerClave($doc, $_POST, $admin);
        if ($errores) {
            flash('error', reset($errores));
        } else {
            flash('exito', 'Contraseña restablecida. Compártala por un medio seguro; la persona puede cambiarla en "Mi cuenta".');
        }
        redirigir('/admin/usuarios/' . $id);
    });

    /* ---------- Administradores ---------- */

    $router->get('/admin/administradores', function (): void {
        $admin = Sesion::requiere('admin');
        vista('administracion/administradores', [
            'resultado' => AdminUsuarios::listar([], 1, true),
            'yo'        => $admin['id'],
        ], 'Administradores');
    });

    $router->get('/admin/administradores/nuevo', function (): void {
        Sesion::requiere('admin');
        vista('administracion/administrador_nuevo', ['valores' => [], 'errores' => []], 'Agregar administrador');
    });

    $router->post('/admin/administradores', function () use ($enviarEnlace): void {
        $admin = Sesion::requiere('admin');
        [$doc, $errores] = AdminUsuarios::crearAdministrador($_POST, $admin);
        if ($errores) {
            vista('administracion/administrador_nuevo', ['valores' => $_POST, 'errores' => $errores], 'Agregar administrador', 422);
            return;
        }
        flash('exito', $doc['nombre'] . ' ya es administrador. ' . $enviarEnlace($doc));
        redirigir('/admin/administradores');
    });

    $router->post('/admin/administradores/{id}/estado', function (string $id): void {
        $admin = Sesion::requiere('admin');
        $doc = UsuarioRepositorio::buscar($id);
        if ($doc === null || $doc['rol'] !== 'admin') {
            abortar(404, 'Ese administrador no existe.');
        }
        $activar = entrada('activo') === '1';
        $error = AdminUsuarios::cambiarEstado($doc, $activar, $admin);
        flash($error ? 'error' : 'exito', $error ?? ($activar ? 'Administrador activado.' : 'Administrador desactivado.'));
        redirigir('/admin/administradores');
    });
};
