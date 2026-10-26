<?php
/**
 * Sección de /estado. Variables: $configurado, $remitente (o null),
 * $conteos (avisos de las últimas 24 horas por estado del correo, o null si MongoDB no responde)
 */
?>
<section style="margin:2.5rem 0">
    <h2>Correo con Mailjet</h2>
    <?php if ($configurado): ?>
        <p>Configurado: los correos salen desde <strong><?= e($remitente) ?></strong>. Si Mailjet no responde, los avisos
            quedan igual en el portal y <code>scripts/enviar_correos.php</code> reintenta los correos.</p>
    <?php else: ?>
        <p><strong>Sin configurar.</strong> No sale ningún correo: se anotan en <code>storage/correos.log</code> y la página
            «Confirme su correo» muestra el enlace. Faltan <code>MAILJET_API_KEY</code>, <code>MAILJET_API_SECRET</code> o
            <code>CORREO_REMITENTE</code> en el <code>.env</code>.</p>
    <?php endif; ?>
    <?php if ($conteos !== null): ?>
        <div class="cifras">
            <div class="cifra"><strong><?= (int) $conteos['enviado'] ?></strong><span>enviados</span></div>
            <div class="cifra"><strong><?= (int) $conteos['pendiente'] + (int) $conteos['fallido'] ?></strong><span>pendientes o fallidos</span></div>
            <div class="cifra"><strong><?= (int) $conteos['omitido'] ?></strong><span>no se envían (sin correo confirmado, avisos por correo desactivados o direcciones de prueba)</span></div>
        </div>
        <p class="texto-suave">Correos de los avisos de las últimas 24 horas.</p>
    <?php endif; ?>
</section>
