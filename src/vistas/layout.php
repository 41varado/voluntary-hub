<?php
/**
 * Diseño general de todas las páginas.
 * Variables: $contenido, $tituloPagina, $usuario, $menu, $mensajes
 */
$nombreApp = Config::get('APP_NOMBRE', 'VoluntaCR');
$rutaActual = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';
?><!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($tituloPagina ? $tituloPagina . ' | ' . $nombreApp : $nombreApp) ?></title>
    <meta name="description" content="Actividades de voluntariado en Costa Rica con control de cupos.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible:ital,wght@0,400;0,700;1,400&family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700;12..96,800&display=swap">
    <link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>">
    <link rel="icon" href="<?= e(url('/assets/img/icono.svg')) ?>" type="image/svg+xml">
</head>
<body>
<a class="saltar" href="#principal">Ir al contenido</a>

<header class="cabecera">
    <div class="cabecera__interior">
        <a class="marca" href="<?= e(url('/')) ?>">
            <svg class="marca__simbolo" viewBox="0 0 32 32" aria-hidden="true"><path d="M16 29c-7-5-12-9.5-12-15.5A6.5 6.5 0 0 1 16 9a6.5 6.5 0 0 1 12 4.5C28 19.5 23 24 16 29z"/><path d="M16 23V13m0 0c-2-3-5-3.5-7-2m7 2c2-3 5-3.5 7-2" fill="none"/></svg>
            <span><?= e($nombreApp) ?></span>
        </a>
        <nav class="menu" aria-label="Principal">
            <?php foreach ($menu as $enlace): ?>
                <?php $activo = $rutaActual === rtrim($enlace['url'], '/') || ($enlace['url'] !== url('/') && str_starts_with($rutaActual, rtrim($enlace['url'], '/') . '/')); ?>
                <a href="<?= e($enlace['url']) ?>"<?= $activo ? ' aria-current="page"' : '' ?>><?= e($enlace['texto']) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="cuenta">
            <?= Ganchos::html('menu.cuenta', $usuario) ?>
        </div>
    </div>
</header>

<?php if ($usuario && in_array($usuario['rol'], ['coordinador', 'admin'], true) && class_exists('RedisSeguro') && RedisSeguro::get()->cliente() === null): ?>
    <div class="franja-respaldo" role="status">
        Modo respaldo: Redis no responde. Los cupos se controlan directamente en MongoDB y no hay caché.
        <a href="<?= e(url('/estado')) ?>">Ver estado</a>
    </div>
<?php endif; ?>

<main id="principal" class="principal">
    <?php if ($mensajes): ?>
        <div class="avisos" aria-live="polite">
            <?php foreach ($mensajes as $m): ?>
                <p class="aviso aviso--<?= e($m['tipo']) ?>"><?= e($m['mensaje']) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?= $contenido ?>
</main>

<footer class="pie">
    <div class="pie__interior">
        <p><?= e($nombreApp) ?> es software libre (licencia MIT), hecho para el curso Tópicos Avanzados de Bases de Datos de la Universidad Nacional.</p>
        <p><a href="<?= e(url('/estado')) ?>">Estado del sistema</a> <a href="<?= e(Config::get('APP_REPOSITORIO', 'https://github.com/')) ?>" rel="noopener">Código en GitHub</a></p>
    </div>
</footer>
<script src="<?= e(url('/assets/js/app.js')) ?>" defer></script>
</body>
</html>
