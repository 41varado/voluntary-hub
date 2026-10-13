<?php
/** Variables: $mongo, $redis, $modo, $hora */
$textosModo = [
    'normal'   => ['Funcionamiento normal', 'MongoDB guarda los datos y Redis acelera los cupos y el listado.'],
    'respaldo' => ['Modo respaldo', 'Redis no responde. Las inscripciones siguen funcionando: el cupo se controla directamente en MongoDB, sin caché.'],
    'critico'  => ['MongoDB no responde', 'No se pueden guardar reservas ni cambios. El listado se muestra solo si estaba en la caché de Redis.'],
];
[$tituloModo, $explicacionModo] = $textosModo[$modo];
?>
<div class="encabezado-seccion">
    <h1>Estado del sistema</h1>
    <p class="texto-suave">Revisado el <?= e(fecha_cr($hora)) ?></p>
</div>

<div class="modo <?= $modo === 'respaldo' ? 'modo--respaldo' : ($modo === 'critico' ? 'modo--critico' : '') ?>" role="status">
    <strong><?= e($tituloModo) ?></strong>
    <?= e($explicacionModo) ?>
</div>

<div class="motores">
    <section class="motor <?= $mongo['ok'] ? 'motor--ok' : 'motor--caido' ?>">
        <h2>MongoDB <span class="estado estado--<?= $mongo['ok'] ? 'ok' : 'caido' ?>"><?= $mongo['ok'] ? 'Conectado' : 'Sin conexión' ?></span></h2>
        <p class="texto-suave">Fuente de verdad: organizaciones, usuarios, actividades, imágenes, inscripciones y asistencia.</p>
        <?php if ($mongo['ok']): ?>
            <dl>
                <dt>Versión</dt><dd><?= e($mongo['version']) ?></dd>
                <dt>Respuesta</dt><dd><?= e($mongo['ms']) ?> ms</dd>
                <dt>Base de datos</dt><dd><?= e($mongo['base']) ?></dd>
                <?php foreach ($mongo['colecciones'] as $coleccion => $cantidad): ?>
                    <dt><?= e($coleccion) ?></dt><dd><?= e(plural($cantidad, 'documento', 'documentos')) ?></dd>
                <?php endforeach; ?>
            </dl>
        <?php else: ?>
            <p class="campo__error"><?= e($mongo['motivo']) ?></p>
        <?php endif; ?>
    </section>

    <section class="motor <?= $redis['ok'] ? 'motor--ok' : 'motor--caido' ?>">
        <h2>Redis <span class="estado estado--<?= $redis['ok'] ? 'ok' : 'caido' ?>"><?= $redis['ok'] ? 'Conectado' : 'Sin conexión' ?></span></h2>
        <p class="texto-suave">Acelerador: contador de cupos, candado contra doble envío y caché del listado.</p>
        <?php if ($redis['ok']): ?>
            <dl>
                <dt>Versión</dt><dd><?= e($redis['version']) ?></dd>
                <dt>Respuesta</dt><dd><?= e($redis['ms']) ?> ms</dd>
                <dt>Prefijo</dt><dd><?= e($redis['prefijo']) ?></dd>
                <dt>Claves del grupo</dt><dd><?= e($redis['claves'] ?? 0) ?></dd>
                <dt>Prueba SET/GET</dt><dd><?= e($redis['prueba'] ?? '') ?></dd>
            </dl>
        <?php else: ?>
            <p class="campo__error"><?= e($redis['motivo']) ?></p>
        <?php endif; ?>
    </section>
</div>

<p class="texto-suave">Esta misma información está en formato JSON en <a href="<?= e(url('/estado.json')) ?>">/estado.json</a>.</p>
