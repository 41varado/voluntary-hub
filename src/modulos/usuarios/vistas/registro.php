<?php
/** Variables: $valores, $errores */
$v = fn(string $campo) => e($valores[$campo] ?? '');
$error = fn(string $campo) => isset($errores[$campo]) ? '<p class="campo__error" id="error-' . $campo . '">' . e($errores[$campo]) . '</p>' : '';
$clase = fn(string $campo) => 'campo' . (isset($errores[$campo]) ? ' campo--con-error' : '');
?>
<section class="estrecho">
    <h1>Crear cuenta de voluntario</h1>
    <p class="texto-suave">Con su cuenta puede reservar cupos y llevar el registro de sus horas.</p>

    <form class="tarjeta formulario" method="post" action="<?= e(url('/registro')) ?>" novalidate>
        <?= csrf_campo() ?>
        <div class="<?= $clase('nombre') ?>">
            <label for="nombre">Nombre completo</label>
            <input type="text" id="nombre" name="nombre" value="<?= $v('nombre') ?>" autocomplete="name" required maxlength="80">
            <?= $error('nombre') ?>
        </div>
        <div class="<?= $clase('correo') ?>">
            <label for="correo">Correo electrónico</label>
            <input type="email" id="correo" name="correo" value="<?= $v('correo') ?>" autocomplete="email" required maxlength="120">
            <?= $error('correo') ?>
        </div>
        <div class="<?= $clase('provincia') ?>">
            <label for="provincia">Provincia donde vive (opcional)</label>
            <select id="provincia" name="provincia">
                <option value="">Prefiero no indicarla</option>
                <?php foreach (Catalogos::PROVINCIAS as $provincia): ?>
                    <option<?= ($valores['provincia'] ?? '') === $provincia ? ' selected' : '' ?>><?= e($provincia) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $error('provincia') ?>
        </div>
        <div class="<?= $clase('clave') ?>">
            <label for="clave">Contraseña</label>
            <input type="password" id="clave" name="clave" autocomplete="new-password" required minlength="8">
            <p class="campo__ayuda">Al menos 8 caracteres.</p>
            <?= $error('clave') ?>
        </div>
        <div class="<?= $clase('clave2') ?>">
            <label for="clave2">Repita la contraseña</label>
            <input type="password" id="clave2" name="clave2" autocomplete="new-password" required minlength="8">
            <?= $error('clave2') ?>
        </div>
        <button class="boton" type="submit">Crear cuenta</button>
        <p class="texto-suave">¿Ya tiene cuenta? <a href="<?= e(url('/ingresar')) ?>">Inicie sesión</a>.</p>
    </form>
</section>
