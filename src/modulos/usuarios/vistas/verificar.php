<?php
/**
 * "Confirme su correo". Variables: $doc (cuenta sin confirmar),
 * $enlace (['ruta', 'motivo'] cuando el correo no se pudo enviar y el enlace se muestra aquí; si no, null)
 */
$vence = $doc['verificacion']['expiraEn'] ?? null;
$vigente = $vence !== null && $vence->toDateTime()->getTimestamp() > time();
// Sin Mailjet configurado, o con una dirección de prueba (.test), el correo nunca llega.
$sinCorreo = match (true) {
    $enlace !== null                    => $enlace['motivo'],
    !Correo::configurado()              => 'sin_configurar',
    Correo::esReservado($doc['correo']) => 'dominio_reservado',
    default                             => null,
};
$motivos = [
    'sin_configurar'    => 'El envío de correos no está configurado (faltan las claves de Mailjet)',
    'dominio_reservado' => 'Las direcciones de prueba (.test) no reciben correos',
];
?>
<section class="estrecho">
    <h1>Confirme su correo</h1>
    <div class="tarjeta formulario">
        <p>Para activar la cuenta de <strong><?= e($doc['nombre']) ?></strong>, abra el enlace que enviamos a
            <strong><?= e($doc['correo']) ?></strong>. Hasta entonces no se puede iniciar sesión.</p>

        <?php if ($vigente): ?>
            <p class="texto-suave">El enlace vence el <?= e(fecha_cr($vence)) ?> y sirve una sola vez. Si no lo encuentra,
                revise la carpeta de correo no deseado.</p>
        <?php else: ?>
            <p class="aviso aviso--aviso">No hay un enlace vigente. Pida uno nuevo.</p>
        <?php endif; ?>

        <?php if ($sinCorreo !== null): ?>
            <p class="aviso aviso--aviso">
                <strong>Modo sin correo.</strong> <?= e($motivos[$sinCorreo] ?? 'El correo no se puede enviar') ?>, así que el
                enlace se muestra aquí, solo en este navegador:
                <?php if ($enlace && $vigente): ?>
                    <a href="<?= e(url($enlace['ruta'])) ?>">confirmar mi correo</a>.
                <?php else: ?>
                    pídalo con «Reenviar enlace».
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <form method="post" action="<?= e(url('/verificar-correo/reenviar')) ?>">
            <?= csrf_campo() ?>
            <button class="boton boton--secundario" type="submit">Reenviar enlace</button>
        </form>
        <p class="texto-suave">¿El correo está mal escrito? Cree otra cuenta con la dirección correcta o pida a la
            administración que lo corrija.</p>
    </div>
</section>
