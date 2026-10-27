<?php
/**
 * Bandeja del voluntario. Variables: $bandeja (['items', 'total', 'pagina', 'paginas']),
 * $nuevos (id => ObjectId de los que se ven por primera vez), $otrosSinLeer (sin leer en otras páginas)
 */
?>
<div class="encabezado-seccion">
    <div>
        <h1>Avisos</h1>
        <p class="texto-suave">Cambios y cancelaciones de las actividades en las que tiene una inscripción, horas acreditadas y mensajes de las organizaciones.</p>
    </div>
    <?php if ($otrosSinLeer > 0): ?>
        <form method="post" action="<?= e(url('/avisos/leidos')) ?>">
            <?= csrf_campo() ?>
            <button class="boton boton--secundario" type="submit">Marcar <?= e(plural($otrosSinLeer, 'aviso más', 'avisos más')) ?> como leídos</button>
        </form>
    <?php endif; ?>
</div>

<?php if (!$bandeja['items']): ?>
    <div class="vacio">
        <h2>Todavía no hay avisos</h2>
        <p>Aquí verá si una organización cambia o cancela una actividad en la que tiene una inscripción, o si le envía un mensaje.</p>
    </div>
<?php else: ?>
    <ul class="bandeja">
        <?php foreach ($bandeja['items'] as $aviso): $nuevo = isset($nuevos[(string) $aviso['_id']]); ?>
            <li class="bandeja__aviso<?= $nuevo ? ' bandeja__aviso--nuevo' : '' ?>">
                <p class="bandeja__meta">
                    <?php if ($nuevo): ?><span class="estado estado--reservada">Nuevo</span><?php endif; ?>
                    <span><?= e(Catalogos::TIPOS_AVISO[$aviso['tipo']] ?? $aviso['tipo']) ?></span>
                    <span class="texto-suave"><?= e(fecha_cr($aviso['creadaEn'], 'corta')) ?></span>
                </p>
                <h2 class="bandeja__titulo"><?= e($aviso['titulo']) ?></h2>
                <?php if (!empty($aviso['remitente'])): ?>
                    <p class="texto-suave">
                        De <?= e($aviso['remitente']['nombre']) ?><?= !empty($aviso['remitente']['organizacion']) ? ', ' . e($aviso['remitente']['organizacion']) : '' ?><?= !empty($aviso['actividad']) ? ', sobre «' . e($aviso['actividad']['titulo']) . '»' : '' ?>
                    </p>
                <?php endif; ?>
                <div class="detalle__texto"><?= e($aviso['mensaje']) ?></div>
                <?php if (!empty($aviso['enlace'])): ?>
                    <p class="bandeja__enlace"><a href="<?= e(url($aviso['enlace'])) ?>"><?= $aviso['tipo'] === 'asistencia' ? 'Ver mis horas' : 'Ver la actividad' ?></a></p>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($bandeja['paginas'] > 1): ?>
        <nav class="paginacion" aria-label="Páginas">
            <?php for ($p = 1; $p <= $bandeja['paginas']; $p++): ?>
                <?php if ($p === $bandeja['pagina']): ?>
                    <span aria-current="page"><?= $p ?></span>
                <?php else: ?>
                    <a href="<?= e(url('/avisos', ['pagina' => $p])) ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
