<?php
/** Variables: $valores, $errores */
$v = fn(string $campo) => e($valores[$campo] ?? '');
$clase = fn(string $campo) => 'campo' . (isset($errores[$campo]) ? ' campo--con-error' : '');
$error = fn(string $campo) => isset($errores[$campo]) ? '<p class="campo__error">' . e($errores[$campo]) . '</p>' : '';
?>
<?= parcial('administracion/_pestanas', ['activa' => 'administradores']) ?>

<div class="encabezado-seccion">
    <div>
        <p class="detalle__migas"><a href="<?= e(url('/admin/administradores')) ?>">Administradores</a></p>
        <h1>Agregar administrador</h1>
    </div>
</div>

<?php if ($errores): ?>
    <p class="aviso aviso--error" role="alert">Revise los campos marcados.</p>
<?php endif; ?>

<div class="medio">
<form class="tarjeta formulario" method="post" action="<?= e(url('/admin/administradores')) ?>" novalidate>
    <?= csrf_campo() ?>
    <p class="texto-suave">Un administrador puede crear y desactivar cualquier cuenta, incluidas otras de administración. Agregue solo a personas del equipo responsable.</p>
    <div class="<?= $clase('nombre') ?>">
        <label for="nombre">Nombre completo</label>
        <input type="text" id="nombre" name="nombre" value="<?= $v('nombre') ?>" required maxlength="80">
        <?= $error('nombre') ?>
    </div>
    <div class="<?= $clase('correo') ?>">
        <label for="correo">Correo electrónico</label>
        <input type="email" id="correo" name="correo" value="<?= $v('correo') ?>" required maxlength="120">
        <?= $error('correo') ?>
    </div>
    <div class="formulario__fila">
        <div class="<?= $clase('clave') ?>">
            <label for="clave">Contraseña inicial</label>
            <input type="password" id="clave" name="clave" autocomplete="new-password" required minlength="8">
            <?= $error('clave') ?>
        </div>
        <div class="<?= $clase('clave2') ?>">
            <label for="clave2">Repita la contraseña</label>
            <input type="password" id="clave2" name="clave2" autocomplete="new-password" required minlength="8">
            <?= $error('clave2') ?>
        </div>
    </div>
    <div class="<?= $clase('confirmacion') ?>">
        <label for="confirmacion">Su contraseña, para confirmar</label>
        <input type="password" id="confirmacion" name="confirmacion" autocomplete="current-password" required>
        <?= $error('confirmacion') ?>
    </div>
    <div class="acciones">
        <button class="boton" type="submit">Agregar administrador</button>
        <a class="boton boton--secundario" href="<?= e(url('/admin/administradores')) ?>">Cancelar</a>
    </div>
</form>
</div>
