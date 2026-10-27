<?php
/** Variables: $a (documento de MongoDB), $usuario, $esPropia, $panel (HTML del módulo de inscripciones) */
$id = (string) $a['_id'];
$imagenes = $a['imagenes'] ?? [];
$portada = $a['portada'] ?? null;
// La portada va primero en la galería.
usort($imagenes, fn($x, $y) => ($y['archivo'] === $portada) <=> ($x['archivo'] === $portada));
?>
<p class="detalle__migas"><a href="<?= e(url('/actividades')) ?>">Actividades</a></p>

<div class="detalle">
    <article>
        <p class="detalle__categoria"><?= e(Catalogos::categoria($a['categoria'])) ?>, <?= e($a['provincia']) ?></p>
        <h1><?= e($a['titulo']) ?></h1>

        <?php if ($a['estado'] === 'cancelada'): ?>
            <p class="aviso aviso--error">La organización canceló esta actividad. Ya no admite reservas.</p>
        <?php elseif ($a['estado'] !== 'publicada'): ?>
            <p class="aviso aviso--aviso">Esta actividad está en estado «<?= e($a['estado']) ?>» y solo la ve su organización.</p>
        <?php endif; ?>

        <?php if ($imagenes): ?>
            <div class="galeria" data-galeria>
                <img class="galeria__principal" src="<?= e(Imagenes::url($id, $imagenes[0]['archivo'])) ?>" alt="Fotografía de la actividad">
                <?php if (count($imagenes) > 1): ?>
                    <div class="galeria__miniaturas">
                        <?php foreach ($imagenes as $i => $imagen): ?>
                            <button type="button" data-imagen="<?= e(Imagenes::url($id, $imagen['archivo'])) ?>" aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>" aria-label="Ver imagen <?= $i + 1 ?>">
                                <img src="<?= e(Imagenes::url($id, $imagen['archivo'])) ?>" alt="">
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="galeria"><?= portada_actividad($id, null, $a['categoria'], '', 'marcador marcador--grande') ?></div>
        <?php endif; ?>

        <div class="detalle__texto"><?= e($a['descripcion']) ?></div>

        <?php if (!empty($a['requisitos'])): ?>
            <section class="detalle__seccion">
                <h2>Qué llevar y requisitos</h2>
                <div class="detalle__texto"><?= e($a['requisitos']) ?></div>
            </section>
        <?php endif; ?>
    </article>

    <aside class="ficha" aria-label="Datos de la actividad">
        <dl>
            <dt>Fecha</dt><dd><?= e(fecha_cr($a['fechaInicio'])) ?></dd>
            <dt>Duración</dt><dd><?= e(texto_horas((float) $a['horas'])) ?></dd>
            <dt>Lugar</dt><dd><?= e($a['lugar']) ?>, <?= e($a['provincia']) ?></dd>
            <dt>Organiza</dt><dd><?= e($a['organizacion']['nombre']) ?></dd>
        </dl>
        <div class="ficha__cupos"><?= medidor_cupos((int) $a['cupoTotal'], (int) $a['cuposDisponibles']) ?></div>

        <div class="ficha__inscripcion">
            <?php if ($esPropia): ?>
                <p>Usted coordina esta actividad.</p>
                <div class="acciones">
                    <a class="boton boton--secundario boton--pequeno" href="<?= e(url('/panel/actividades/' . $id . '/editar')) ?>">Editar</a>
                    <?= Ganchos::html('panel.actividad.acciones', $a) ?>
                </div>
            <?php elseif ($panel !== ''): ?>
                <?= $panel ?>
            <?php else: ?>
                <p class="texto-suave">Las inscripciones se habilitan pronto.</p>
            <?php endif; ?>
        </div>
    </aside>
</div>
