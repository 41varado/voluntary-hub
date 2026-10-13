<?php /** Variables: $resultado (items), $yo (id del administrador actual) */ ?>
<?= parcial('administracion/_pestanas', ['activa' => 'administradores']) ?>

<div class="encabezado-seccion">
    <div>
        <h1>Administradores</h1>
        <p class="texto-suave">Pueden crear, editar y desactivar cualquier cuenta. Siempre debe quedar al menos uno activo.</p>
    </div>
    <a class="boton" href="<?= e(url('/admin/administradores/nuevo')) ?>">Agregar administrador</a>
</div>

<div class="tabla-envoltura">
    <table class="tabla">
        <thead>
        <tr>
            <th scope="col">Nombre</th>
            <th scope="col">Correo</th>
            <th scope="col">Estado</th>
            <th scope="col">Desde</th>
            <th scope="col">Acciones</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($resultado['items'] as $u): $activo = UsuarioRepositorio::estaActivo($u); $esYo = (string) $u['_id'] === $yo; ?>
            <tr>
                <td><?= e($u['nombre']) ?><?= $esYo ? ' <span class="texto-suave">(usted)</span>' : '' ?></td>
                <td><?= e($u['correo']) ?></td>
                <td><span class="estado estado--<?= $activo ? 'ok' : 'caido' ?>"><?= $activo ? 'Activo' : 'Desactivado' ?></span></td>
                <td><?= e(fecha_cr($u['creadoEn'], 'dia')) ?></td>
                <td>
                    <?php if ($esYo): ?>
                        <a href="<?= e(url('/mi-cuenta')) ?>">Mi cuenta</a>
                    <?php elseif ($activo): ?>
                        <form method="post" action="<?= e(url('/admin/administradores/' . $u['_id'] . '/estado')) ?>" data-confirmar="¿Desactivar a <?= e($u['nombre']) ?>? Ya no podrá iniciar sesión.">
                            <?= csrf_campo() ?><input type="hidden" name="activo" value="0">
                            <button class="boton-enlace" type="submit">Desactivar</button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="<?= e(url('/admin/administradores/' . $u['_id'] . '/estado')) ?>">
                            <?= csrf_campo() ?><input type="hidden" name="activo" value="1">
                            <button class="boton-enlace" type="submit">Activar</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
