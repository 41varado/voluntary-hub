<?php
/**
 * Diseño de los correos. Lo usa Correo::mensaje().
 * Variables: $titulo, $parrafos (lista de textos), $boton (['texto', 'url'] o null), $nota (texto o null)
 * Los estilos van en línea porque muchos programas de correo ignoran las hojas de estilo.
 */
$nombreApp = Config::get('APP_NOMBRE', 'VoluntaCR');
?><!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo) ?></title>
</head>
<body style="margin:0;padding:0;background:#f5f6f1;color:#1a2320;font-family:'Segoe UI',Arial,sans-serif;">
<div style="max-width:560px;margin:0 auto;padding:24px 16px;">
    <div style="background:#17473a;color:#ffffff;padding:14px 20px;border-radius:10px 10px 0 0;font-size:20px;font-weight:bold;"><?= e($nombreApp) ?></div>
    <div style="background:#ffffff;padding:24px 20px;border:1px solid #d6ddd4;border-top:0;border-radius:0 0 10px 10px;">
        <h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;color:#0f3329;"><?= e($titulo) ?></h1>
        <?php foreach ($parrafos as $parrafo): ?>
            <p style="margin:0 0 14px;font-size:16px;line-height:1.55;"><?= nl2br(e($parrafo)) ?></p>
        <?php endforeach; ?>
        <?php if ($boton): ?>
            <p style="margin:22px 0 14px;">
                <a href="<?= e($boton['url']) ?>" style="display:inline-block;background:#f2b705;color:#1a2320;text-decoration:none;font-weight:bold;padding:12px 20px;border-radius:6px;"><?= e($boton['texto']) ?></a>
            </p>
            <p style="margin:0;font-size:13px;line-height:1.5;color:#56675b;">Si el botón no funciona, copie esta dirección en el navegador:<br><?= e($boton['url']) ?></p>
        <?php endif; ?>
    </div>
    <?php if ($nota): ?>
        <p style="margin:14px 4px 0;font-size:13px;line-height:1.5;color:#56675b;"><?= e($nota) ?></p>
    <?php endif; ?>
</div>
</body>
</html>
