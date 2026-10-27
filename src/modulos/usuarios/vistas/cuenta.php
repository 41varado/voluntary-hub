<?php
/** Variables: $doc (usuario en MongoDB), $valores, $erroresPerfil, $erroresClave */
$clase = fn(array $errores, string $campo) => 'campo' . (isset($errores[$campo]) ? ' campo--con-error' : '');
$error = fn(array $errores, string $campo) => isset($errores[$campo]) ? '<p class="campo__error">' . e($errores[$campo]) . '</p>' : '';
?>
<div class="encabezado-seccion">
    <div>
        <h1>Mi cuenta</h1>
        <p class="texto-suave"><?= e(Catalogos::NOMBRES_ROL[$doc['rol']] ?? $doc['rol']) ?><?= !empty($doc['organizacion']['nombre']) ? ', ' . e($doc['organizacion']['nombre']) : '' ?></p>
    </div>
</div>

<div class="medio">
    <form class="tarjeta formulario" method="post" action="<?= e(url('/mi-cuenta')) ?>" novalidate>
        <?= csrf_campo() ?>
        <h2>Datos personales</h2>
        <div class="campo">
            <span class="campo__etiqueta">Correo electrónico</span>
            <span><?= e($doc['correo']) ?></span>
            <p class="campo__ayuda">Es su usuario para iniciar sesión. Si necesita cambiarlo, pídalo a la administración.</p>
        </div>
        <div class="<?= $clase($erroresPerfil, 'nombre') ?>">
            <label for="nombre">Nombre completo</label>
            <input type="text" id="nombre" name="nombre" value="<?= e($valores['nombre'] ?? '') ?>" autocomplete="name" required maxlength="80">
            <?= $error($erroresPerfil, 'nombre') ?>
        </div>
        <div class="<?= $clase($erroresPerfil, 'provincia') ?>">
            <label for="provincia">Provincia (opcional)</label>
            <select id="provincia" name="provincia">
                <option value="">Prefiero no indicarla</option>
                <?php foreach (Catalogos::PROVINCIAS as $provincia): ?>
                    <option<?= ($valores['provincia'] ?? '') === $provincia ? ' selected' : '' ?>><?= e($provincia) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $error($erroresPerfil, 'provincia') ?>
        </div>
        <?php if ($doc['rol'] === 'voluntario'): ?>
            <div class="campo">
                <span class="campo__etiqueta">Avisos de las actividades</span>
                <label class="opcion">
                    <input type="checkbox" name="avisosPorCorreo" value="1"<?= !empty($valores['avisosPorCorreo']) ? ' checked' : '' ?>>
                    Recibirlos también por correo
                </label>
                <p class="campo__ayuda">Cancelaciones, cambios, horas acreditadas y mensajes de las organizaciones. Siempre quedan en «Avisos».</p>
            </div>
        <?php endif; ?>
        <div><button class="boton" type="submit">Guardar datos</button></div>
    </form>

    <form class="tarjeta formulario" method="post" action="<?= e(url('/mi-cuenta/clave')) ?>" novalidate>
        <?= csrf_campo() ?>
        <h2>Cambiar contraseña</h2>
        <p class="texto-suave">Al cambiarla se cierran las sesiones abiertas en otros dispositivos.</p>
        <div class="<?= $clase($erroresClave, 'actual') ?>">
            <label for="actual">Contraseña actual</label>
            <input type="password" id="actual" name="actual" autocomplete="current-password" required>
            <?= $error($erroresClave, 'actual') ?>
        </div>
        <div class="<?= $clase($erroresClave, 'clave') ?>">
            <label for="clave">Contraseña nueva</label>
            <input type="password" id="clave" name="clave" autocomplete="new-password" required minlength="8">
            <p class="campo__ayuda">Al menos 8 caracteres.</p>
            <?= $error($erroresClave, 'clave') ?>
        </div>
        <div class="<?= $clase($erroresClave, 'clave2') ?>">
            <label for="clave2">Repita la contraseña nueva</label>
            <input type="password" id="clave2" name="clave2" autocomplete="new-password" required minlength="8">
            <?= $error($erroresClave, 'clave2') ?>
        </div>
        <div><button class="boton boton--secundario" type="submit">Cambiar contraseña</button></div>
    </form>
</div>
