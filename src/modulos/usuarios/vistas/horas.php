<?php
/** Variables: $usuario, $porCategoria, $totalHoras, $totalActiv, $asistidas, $ranking (ver RankingHoras::consultar) */
$maximo = max(1, ...array_map(fn($f) => (float) $f['horas'], $porCategoria ?: [['horas' => 1]]));
?>
<div class="encabezado-seccion">
    <div>
        <h1>Mis horas de voluntariado</h1>
        <p class="texto-suave"><?= e($usuario['nombre']) ?>. Solo cuentan las actividades donde la organización registró su asistencia.</p>
    </div>
</div>

<div class="cifras">
    <div class="cifra"><strong><?= e(rtrim(rtrim(number_format((float) $totalHoras, 1, ',', ''), '0'), ',')) ?></strong><span>horas acumuladas</span></div>
    <div class="cifra"><strong><?= (int) $totalActiv ?></strong><span><?= $totalActiv === 1 ? 'actividad completada' : 'actividades completadas' ?></span></div>
    <?php if ($ranking['puesto'] !== null): ?>
        <div class="cifra"><strong><?= (int) $ranking['puesto'] ?>.º</strong><span>puesto de <?= (int) $ranking['participantes'] ?> en el ranking</span></div>
    <?php endif; ?>
</div>

<?php if (!$porCategoria): ?>
    <div class="vacio">
        <h2>Aún no hay horas registradas</h2>
        <p>Cuando asista a una actividad y la organización pase lista, sus horas aparecerán aquí.</p>
        <a class="boton" href="<?= e(url('/actividades')) ?>">Buscar actividades</a>
    </div>
<?php else: ?>
    <h2>Por tipo de actividad</h2>
    <div class="barras" role="list">
        <?php foreach ($porCategoria as $fila): ?>
            <div class="barra" role="listitem">
                <span><?= e(Catalogos::categoria((string) $fila['_id'])) ?></span>
                <div class="barra__pista"><div class="barra__relleno" style="width: <?= round((float) $fila['horas'] / $maximo * 100, 1) ?>%"></div></div>
                <strong><?= e(plural((float) $fila['horas'], 'hora', 'horas')) ?></strong>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($ranking['primeros']): ?>
        <h2 style="margin-top:2.5rem">Quienes más horas acumulan</h2>
        <ol class="lista-simple">
            <?php foreach ($ranking['primeros'] as $fila): ?>
                <li>
                    <span><?= (int) $fila['puesto'] ?>. <?= e($fila['nombre']) ?><?= $fila['id'] === $usuario['id'] ? ' (usted)' : '' ?></span>
                    <strong><?= e(plural($fila['horas'], 'hora', 'horas')) ?></strong>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php if (Config::bool('APP_MODO_DEMO')): ?>
            <p class="origen-datos"><?= $ranking['origen'] === 'redis' ? 'Ranking desde Redis (sorted set)' : 'Ranking calculado en MongoDB' ?></p>
        <?php endif; ?>
    <?php endif; ?>

    <h2 style="margin-top:2.5rem">Actividades completadas</h2>
    <div class="tabla-envoltura">
        <table class="tabla">
            <thead><tr><th scope="col">Actividad</th><th scope="col">Organización</th><th scope="col">Fecha</th><th scope="col" class="numero">Horas</th></tr></thead>
            <tbody>
            <?php foreach ($asistidas as $i): ?>
                <tr>
                    <td><?= e($i['actividad']['titulo']) ?></td>
                    <td><?= e($i['actividad']['organizacion']) ?></td>
                    <td><?= e(fecha_cr($i['actividad']['fechaInicio'], 'corta')) ?></td>
                    <td class="numero"><?= e(str_replace('.', ',', (string) (float) $i['asistencia']['horas'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
