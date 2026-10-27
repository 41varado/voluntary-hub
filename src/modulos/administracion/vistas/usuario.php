<?php
/** Crear o editar un usuario regular. Variables: $doc (null si es nuevo), $valores, $errores, $organizaciones */
$esNuevo = $doc === null;
$v = fn(string $campo) => e($valores[$campo] ?? '');
$clase = fn(string $campo) => 'campo' . (isset($errores[$campo]) ? ' campo--con-error' : '');
$error = fn(string $campo) => isset($errores[$campo]) ? '<p class="campo__error">' . e($errores[$campo]) . '</p>' : '';
$rolElegido = $valores['rol'] ?? 'voluntario';
?>
<?= parcial('administracion/_pestanas', ['activa' => 'usuarios']) ?>

<div class="encabezado-seccion">
    <div>
        <p class="detalle__migas"><a href="<?= e(url('/admin/usuarios')) ?>">Usuarios</a></p>
        <h1><?= $esNuevo ? 'Crear usuario' : e($doc['nombre']) ?></h1>
    </div>
    <?php if (!$esNuevo): $activo = UsuarioRepositorio::estaActivo($doc); ?>
        <span class="estado estado--<?= $activo ? 'ok' : 'caido' ?>"><?= $activo ? 'Cuenta activa' : 'Cuenta desactivada' ?></span>
    <?php endif; ?>
</div>

<?php if ($errores): ?>
    <p class="aviso aviso--error" role="alert">Revise los campos marcados.</p>
<?php endif; ?>

<div class="medio">
<form class="tarjeta formulario" method="post" action="<?= e(url($esNuevo ? '/admin/usuarios' : '/admin/usuarios/' . $doc['_id'])) ?>" novalidate data-formulario-rol>
    <?= csrf_campo() ?>

    <fieldset class="<?= $clase('rol') ?> grupo-opciones">
        <legend class="campo__etiqueta">Tipo de cuenta</legend>
        <?php foreach (AdminUsuarios::ROLES_REGULARES as $rol): ?>
            <label class="opcion">
                <input type="radio" name="rol" value="<?= e($rol) ?>"<?= $rolElegido === $rol ? ' checked' : '' ?>>
                <?= e(Catalogos::NOMBRES_ROL[$rol]) ?>
            </label>
        <?php endforeach; ?>
        <p class="campo__ayuda">Los administradores se crean en la pestaña Administradores.</p>
        <?= $error('rol') ?>
    </fieldset>

    <div class="<?= $clase('nombre') ?>">
        <label for="nombre">Nombre completo</label>
        <input type="text" id="nombre" name="nombre" value="<?= $v('nombre') ?>" required maxlength="80">
        <?= $error('nombre') ?>
    </div>
    <div class="formulario__fila">
        <div class="<?= $clase('correo') ?>">
            <label for="correo">Correo electrónico</label>
            <input type="email" id="correo" name="correo" value="<?= $v('correo') ?>" required maxlength="120">
            <?= $error('correo') ?>
        </div>
        <div class="<?= $clase('provincia') ?>">
            <label for="provincia">Provincia (opcional)</label>
            <select id="provincia" name="provincia">
                <option value="">Sin indicar</option>
                <?php foreach (Catalogos::PROVINCIAS as $provincia): ?>
                    <option<?= ($valores['provincia'] ?? '') === $provincia ? ' selected' : '' ?>><?= e($provincia) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $error('provincia') ?>
        </div>
    </div>

    <div class="<?= $clase('organizacion') ?>" data-solo-coordinacion>
        <div class="formulario__fila">
            <div class="campo">
                <label for="organizacionId">Organización</label>
                <select id="organizacionId" name="organizacionId">
                    <option value="">Elija una organización</option>
                    <?php foreach ($organizaciones as $organizacion): ?>
                        <option value="<?= e($organizacion['_id']) ?>"<?= ($valores['organizacionId'] ?? '') === (string) $organizacion['_id'] ? ' selected' : '' ?>><?= e($organizacion['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label for="organizacionNueva">O cree una nueva</label>
                <input type="text" id="organizacionNueva" name="organizacionNueva" value="<?= $v('organizacionNueva') ?>" maxlength="120" placeholder="Nombre de la organización">
            </div>
        </div>
        <?= $error('organizacion') ?>
    </div>

    <?php if ($esNuevo): ?>
        <div class="formulario__fila">
            <div class="<?= $clase('clave') ?>">
                <label for="clave">Contraseña inicial</label>
                <input type="password" id="clave" name="clave" autocomplete="new-password" required minlength="8">
                <p class="campo__ayuda">Al menos 8 caracteres. La persona puede cambiarla en "Mi cuenta".</p>
                <?= $error('clave') ?>
            </div>
            <div class="<?= $clase('clave2') ?>">
                <label for="clave2">Repita la contraseña</label>
                <input type="password" id="clave2" name="clave2" autocomplete="new-password" required minlength="8">
                <?= $error('clave2') ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="acciones">
        <button class="boton" type="submit"><?= $esNuevo ? 'Crear cuenta' : 'Guardar cambios' ?></button>
        <a class="boton boton--secundario" href="<?= e(url('/admin/usuarios')) ?>">Volver a la lista</a>
    </div>
</form>

<?php if (!$esNuevo): ?>
    <form class="tarjeta formulario" method="post" action="<?= e(url('/admin/usuarios/' . $doc['_id'] . '/clave')) ?>">
        <?= csrf_campo() ?>
        <h2>Restablecer contraseña</h2>
        <p class="texto-suave">Úselo si la persona olvidó su contraseña. Sus sesiones abiertas se cierran.</p>
        <div class="formulario__fila">
            <div class="campo">
                <label for="nueva">Contraseña nueva</label>
                <input type="password" id="nueva" name="clave" autocomplete="new-password" required minlength="8">
            </div>
            <div class="campo">
                <label for="nueva2">Repita la contraseña</label>
                <input type="password" id="nueva2" name="clave2" autocomplete="new-password" required minlength="8">
            </div>
        </div>
        <div><button class="boton boton--secundario" type="submit">Restablecer contraseña</button></div>
    </form>

    <section class="tarjeta">
        <h2>Correo</h2>
        <?php if (VerificacionCorreo::pendiente($doc)): ?>
            <p><span class="estado estado--reservada">Sin confirmar</span></p>
            <p class="texto-suave">La persona no puede iniciar sesión hasta abrir el enlace que se envió a <?= e($doc['correo']) ?>. El enlace vence en <?= VerificacionCorreo::HORAS_VIGENCIA ?> horas; al enviar otro, el anterior deja de servir.</p>
            <form method="post" action="<?= e(url('/admin/usuarios/' . $doc['_id'] . '/verificacion')) ?>">
                <?= csrf_campo() ?>
                <button class="boton boton--secundario" type="submit">Reenviar enlace</button>
            </form>
        <?php else: ?>
            <p><span class="estado estado--confirmada">Confirmado</span>
                <?php if (!empty($doc['correoVerificadoEn'])): ?><span class="texto-suave">el <?= e(fecha_cr($doc['correoVerificadoEn'])) ?></span><?php endif; ?></p>
        <?php endif; ?>
    </section>

    <section class="tarjeta">
        <h2>Estado de la cuenta</h2>
        <?php if (UsuarioRepositorio::estaActivo($doc)): ?>
            <p class="texto-suave">Al desactivarla, la persona no puede iniciar sesión y su sesión abierta se cierra. Su historial de inscripciones y horas se conserva.</p>
            <form method="post" action="<?= e(url('/admin/usuarios/' . $doc['_id'] . '/estado')) ?>" data-confirmar="¿Desactivar la cuenta de <?= e($doc['nombre']) ?>?">
                <?= csrf_campo() ?><input type="hidden" name="activo" value="0">
                <button class="boton boton--peligro" type="submit">Desactivar cuenta</button>
            </form>
        <?php else: ?>
            <p class="texto-suave">La cuenta está desactivada y no puede iniciar sesión.</p>
            <form method="post" action="<?= e(url('/admin/usuarios/' . $doc['_id'] . '/estado')) ?>">
                <?= csrf_campo() ?><input type="hidden" name="activo" value="1">
                <button class="boton" type="submit">Activar cuenta</button>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>
</div>
