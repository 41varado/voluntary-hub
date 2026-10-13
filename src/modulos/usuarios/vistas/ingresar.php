<?php /** Variables: $correo, $error */ ?>
<section class="estrecho">
    <h1>Iniciar sesión</h1>
    <?php if ($error !== ''): ?>
        <p class="aviso aviso--error" role="alert"><?= e($error) ?></p>
    <?php endif; ?>
    <form class="tarjeta formulario" method="post" action="<?= e(url('/ingresar')) ?>">
        <?= csrf_campo() ?>
        <div class="campo">
            <label for="correo">Correo electrónico</label>
            <input type="email" id="correo" name="correo" value="<?= e($correo) ?>" autocomplete="email" required autofocus>
        </div>
        <div class="campo">
            <label for="clave">Contraseña</label>
            <input type="password" id="clave" name="clave" autocomplete="current-password" required>
        </div>
        <button class="boton" type="submit">Iniciar sesión</button>
        <p class="texto-suave">¿No tiene cuenta? <a href="<?= e(url('/registro')) ?>">Cree una de voluntario</a>.
            Las cuentas de coordinación las crea el equipo del proyecto.</p>
    </form>
</section>
