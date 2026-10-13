<?php
/** Variables: $resultado (items, total, pagina, paginas, origen, ms), $filtros */
$hayFiltros = $filtros['categoria'] !== '' || $filtros['provincia'] !== '' || $filtros['q'] !== '' || $filtros['cupo'] !== '';
$origenes = ['redis' => 'caché de Redis', 'mongodb' => 'MongoDB', 'respaldo' => 'copia de respaldo en Redis (MongoDB no responde)'];
?>
<section class="portada">
    <div>
        <h1>Voluntariado con cupo en Costa Rica</h1>
        <p class="portada__bajada">Elija una actividad, reserve su lugar y confírmelo en los minutos siguientes. Así nadie se queda sin espacio por una inscripción que no iba a llegar.</p>
    </div>

    <form class="filtros" method="get" action="<?= e(url('/actividades')) ?>" role="search">
        <div class="campo">
            <label for="q">Buscar</label>
            <input type="search" id="q" name="q" value="<?= e($filtros['q']) ?>" placeholder="Playa, árboles, tutorías…" maxlength="60">
        </div>
        <div class="campo">
            <label for="categoria">Tipo de actividad</label>
            <select id="categoria" name="categoria">
                <option value="">Todas</option>
                <?php foreach (Catalogos::CATEGORIAS as $clave => [$nombre]): ?>
                    <option value="<?= e($clave) ?>"<?= $filtros['categoria'] === $clave ? ' selected' : '' ?>><?= e($nombre) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="campo">
            <label for="provincia">Provincia</label>
            <select id="provincia" name="provincia">
                <option value="">Todo el país</option>
                <?php foreach (Catalogos::PROVINCIAS as $provincia): ?>
                    <option<?= $filtros['provincia'] === $provincia ? ' selected' : '' ?>><?= e($provincia) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <label class="filtros__casilla">
            <input type="checkbox" name="cupo" value="1"<?= $filtros['cupo'] ? ' checked' : '' ?>>
            Solo con cupo libre
        </label>
        <button class="boton" type="submit">Buscar actividades</button>
    </form>
</section>

<div class="resumen-resultados">
    <p><?= e(plural($resultado['total'], 'actividad encontrada', 'actividades encontradas')) ?>.
        <?php if ($hayFiltros): ?><a href="<?= e(url('/actividades')) ?>">Quitar filtros</a><?php endif; ?></p>
    <?php if (Config::bool('APP_MODO_DEMO')): ?>
        <p class="origen-datos">Datos desde <?= e($origenes[$resultado['origen']] ?? $resultado['origen']) ?> en <?= e($resultado['ms']) ?> ms</p>
    <?php endif; ?>
</div>

<?php if (!$resultado['items']): ?>
    <div class="vacio">
        <h2>No hay actividades con esos filtros</h2>
        <p>Pruebe con otra provincia o tipo de actividad. Las organizaciones publican actividades nuevas cada semana.</p>
        <a class="boton boton--secundario" href="<?= e(url('/actividades')) ?>">Ver todas las actividades</a>
    </div>
<?php else: ?>
    <div class="rejilla">
        <?php foreach ($resultado['items'] as $a): ?>
            <article class="actividad">
                <?= portada_actividad($a['id'], $a['portada'], $a['categoria']) ?>
                <div class="actividad__cuerpo">
                    <p class="actividad__fecha"><?= e(fecha_cr($a['fecha'], 'corta')) ?></p>
                    <h3><a href="<?= e(url('/actividades/' . $a['id'])) ?>"><?= e($a['titulo']) ?></a></h3>
                    <p class="actividad__dato"><?= e($a['organizacion']) ?></p>
                    <p class="actividad__dato"><?= e($a['lugar']) ?>, <?= e($a['provincia']) ?>. <?= e(texto_horas($a['horas'])) ?></p>
                    <div class="actividad__cupos"><?= medidor_cupos($a['cupoTotal'], $a['cuposDisponibles']) ?></div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?php if ($resultado['paginas'] > 1): ?>
        <nav class="paginacion" aria-label="Páginas">
            <?php for ($p = 1; $p <= $resultado['paginas']; $p++): ?>
                <?php if ($p === $resultado['pagina']): ?>
                    <span aria-current="page"><?= $p ?></span>
                <?php else: ?>
                    <a href="<?= e(url('/actividades', $filtros + ['pagina' => $p])) ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
