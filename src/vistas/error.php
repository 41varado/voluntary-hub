<?php /** Variables: $codigo, $mensaje, $detalle (opcional) */ ?>
<section class="pagina-error">
    <p class="pagina-error__codigo"><?= e($codigo) ?></p>
    <h1><?= e($tituloPagina ?? 'Error') ?></h1>
    <p><?= e($mensaje) ?></p>
    <?php if (!empty($detalle)): ?>
        <p class="pagina-error__detalle"><code><?= e($detalle) ?></code></p>
    <?php endif; ?>
    <p><a class="boton boton--secundario" href="<?= e(url('/')) ?>">Volver al inicio</a></p>
</section>
