<?php
/** Variables: $actividad (null si es nueva), $valores, $errores */
$esNueva = $actividad === null;
$id = $esNueva ? '' : (string) $actividad['_id'];
$v = fn(string $campo) => e($valores[$campo] ?? '');
$error = function (string $campo) use ($errores): string {
    return isset($errores[$campo]) ? '<p class="campo__error" id="error-' . $campo . '">' . e($errores[$campo]) . '</p>' : '';
};
$clase = fn(string $campo) => 'campo' . (isset($errores[$campo]) ? ' campo--con-error' : '');
$describe = fn(string $campo) => isset($errores[$campo]) ? ' aria-describedby="error-' . $campo . '" aria-invalid="true"' : '';
?>
<div class="encabezado-seccion">
    <div>
        <p class="detalle__migas"><a href="<?= e(url('/panel')) ?>">Panel de mi organización</a></p>
        <h1><?= $esNueva ? 'Nueva actividad' : e($actividad['titulo']) ?></h1>
    </div>
    <?php if (!$esNueva): ?>
        <span class="estado estado--<?= e($actividad['estado']) ?>"><?= e(ucfirst($actividad['estado'])) ?></span>
    <?php endif; ?>
</div>

<?php if ($errores): ?>
    <p class="aviso aviso--error" role="alert">Revise los campos marcados antes de guardar.</p>
<?php endif; ?>

<form class="tarjeta formulario" method="post" action="<?= e(url($esNueva ? '/panel/actividades' : '/panel/actividades/' . $id)) ?>" novalidate>
    <?= csrf_campo() ?>

    <div class="<?= $clase('titulo') ?>">
        <label for="titulo">Título</label>
        <input type="text" id="titulo" name="titulo" value="<?= $v('titulo') ?>" maxlength="120" required<?= $describe('titulo') ?>>
        <?= $error('titulo') ?>
    </div>

    <div class="formulario__fila">
        <div class="<?= $clase('categoria') ?>">
            <label for="categoria">Tipo de actividad</label>
            <select id="categoria" name="categoria" required<?= $describe('categoria') ?>>
                <option value="">Elija una opción</option>
                <?php foreach (Catalogos::CATEGORIAS as $clave => [$nombre]): ?>
                    <option value="<?= e($clave) ?>"<?= ($valores['categoria'] ?? '') === $clave ? ' selected' : '' ?>><?= e($nombre) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $error('categoria') ?>
        </div>
        <div class="<?= $clase('fechaInicio') ?>">
            <label for="fechaInicio">Fecha y hora de inicio</label>
            <input type="datetime-local" id="fechaInicio" name="fechaInicio" value="<?= $v('fechaInicio') ?>" required<?= $describe('fechaInicio') ?>>
            <?= $error('fechaInicio') ?>
        </div>
    </div>

    <div class="formulario__fila">
        <div class="<?= $clase('provincia') ?>">
            <label for="provincia">Provincia</label>
            <select id="provincia" name="provincia" required<?= $describe('provincia') ?>>
                <option value="">Elija una provincia</option>
                <?php foreach (Catalogos::PROVINCIAS as $provincia): ?>
                    <option<?= ($valores['provincia'] ?? '') === $provincia ? ' selected' : '' ?>><?= e($provincia) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $error('provincia') ?>
        </div>
        <div class="<?= $clase('lugar') ?>">
            <label for="lugar">Lugar de encuentro</label>
            <input type="text" id="lugar" name="lugar" value="<?= $v('lugar') ?>" maxlength="150" placeholder="Ej.: Playa Tárcoles, frente al muelle" required<?= $describe('lugar') ?>>
            <?= $error('lugar') ?>
        </div>
    </div>

    <div class="formulario__fila">
        <div class="<?= $clase('horas') ?>">
            <label for="horas">Horas que acredita</label>
            <input type="number" id="horas" name="horas" value="<?= $v('horas') ?>" min="0.5" max="24" step="0.5" required<?= $describe('horas') ?>>
            <?= $error('horas') ?>
        </div>
        <div class="<?= $clase('cupoTotal') ?>">
            <label for="cupoTotal">Cupo total</label>
            <input type="number" id="cupoTotal" name="cupoTotal" value="<?= $v('cupoTotal') ?>" min="1" max="500" step="1" required<?= $describe('cupoTotal') ?>>
            <?php if (!$esNueva): ?>
                <p class="campo__ayuda">Ocupados ahora: <?= (int) $actividad['cupoTotal'] - (int) $actividad['cuposDisponibles'] ?>. El cupo no puede quedar por debajo.</p>
            <?php endif; ?>
            <?= $error('cupoTotal') ?>
        </div>
    </div>

    <div class="<?= $clase('descripcion') ?>">
        <label for="descripcion">Descripción</label>
        <textarea id="descripcion" name="descripcion" maxlength="3000" required<?= $describe('descripcion') ?>><?= $v('descripcion') ?></textarea>
        <p class="campo__ayuda">Qué se va a hacer, para quién y cómo se organiza el día.</p>
        <?= $error('descripcion') ?>
    </div>

    <div class="<?= $clase('requisitos') ?>">
        <label for="requisitos">Qué llevar y requisitos (opcional)</label>
        <textarea id="requisitos" name="requisitos" maxlength="500" rows="3"<?= $describe('requisitos') ?>><?= $v('requisitos') ?></textarea>
        <?= $error('requisitos') ?>
    </div>

    <div class="acciones">
        <?php if ($esNueva): ?>
            <button class="boton" type="submit" name="accion" value="publicar">Publicar actividad</button>
            <button class="boton boton--secundario" type="submit" name="accion" value="borrador">Guardar borrador</button>
        <?php else: ?>
            <button class="boton" type="submit">Guardar cambios</button>
            <a class="boton boton--secundario" href="<?= e(url('/actividades/' . $id)) ?>">Ver como voluntario</a>
        <?php endif; ?>
    </div>
</form>

<?php if (!$esNueva): ?>
    <section class="tarjeta">
        <h2>Imágenes</h2>
        <p class="texto-suave">Hasta <?= Imagenes::MAXIMO_POR_ACTIVIDAD ?> imágenes JPG, PNG o WebP de <?= e(Config::get('SUBIDAS_MAX_MB', '2')) ?> MB como máximo. La portada es la que aparece en el listado.</p>

        <?php if ($actividad['imagenes']): ?>
            <div class="imagenes-admin">
                <?php foreach ($actividad['imagenes'] as $imagen): $esPortada = ($actividad['portada'] ?? null) === $imagen['archivo']; ?>
                    <figure>
                        <img src="<?= e(Imagenes::url($id, $imagen['archivo'])) ?>" alt="<?= e($imagen['original'] ?? 'Imagen de la actividad') ?>">
                        <figcaption>
                            <?php if ($esPortada): ?>
                                <span class="estado estado--publicada">Portada</span>
                            <?php else: ?>
                                <form method="post" action="<?= e(url('/panel/actividades/' . $id . '/imagenes/portada')) ?>">
                                    <?= csrf_campo() ?><input type="hidden" name="archivo" value="<?= e($imagen['archivo']) ?>">
                                    <button class="boton-enlace" type="submit">Usar de portada</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="<?= e(url('/panel/actividades/' . $id . '/imagenes/quitar')) ?>" data-confirmar="¿Eliminar esta imagen?">
                                <?= csrf_campo() ?><input type="hidden" name="archivo" value="<?= e($imagen['archivo']) ?>">
                                <button class="boton-enlace" type="submit">Eliminar</button>
                            </form>
                        </figcaption>
                    </figure>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (count($actividad['imagenes']) < Imagenes::MAXIMO_POR_ACTIVIDAD): ?>
            <form class="formulario" method="post" enctype="multipart/form-data" action="<?= e(url('/panel/actividades/' . $id . '/imagenes')) ?>" novalidate>
                <?= csrf_campo() ?>
                <div class="campo">
                    <label for="imagenes">Agregar imágenes</label>
                    <input type="file" id="imagenes" name="imagenes[]" accept="image/jpeg,image/png,image/webp" multiple required>
                </div>
                <div><button class="boton boton--secundario" type="submit">Subir imágenes</button></div>
            </form>
        <?php endif; ?>
    </section>

    <section class="tarjeta">
        <h2>Estado de la actividad</h2>
        <div class="acciones">
            <?php if ($actividad['estado'] !== 'publicada'): ?>
                <form method="post" action="<?= e(url('/panel/actividades/' . $id . '/estado')) ?>">
                    <?= csrf_campo() ?><input type="hidden" name="estado" value="publicada">
                    <button class="boton" type="submit">Publicar en el portal</button>
                </form>
            <?php endif; ?>
            <?php if ($actividad['estado'] === 'publicada'): ?>
                <form method="post" action="<?= e(url('/panel/actividades/' . $id . '/estado')) ?>">
                    <?= csrf_campo() ?><input type="hidden" name="estado" value="borrador">
                    <button class="boton boton--secundario" type="submit">Volver a borrador</button>
                </form>
                <form method="post" action="<?= e(url('/panel/actividades/' . $id . '/estado')) ?>" data-confirmar="¿Cancelar esta actividad? Las personas inscritas la verán como cancelada.">
                    <?= csrf_campo() ?><input type="hidden" name="estado" value="cancelada">
                    <button class="boton boton--peligro" type="submit">Cancelar actividad</button>
                </form>
            <?php endif; ?>
            <form method="post" action="<?= e(url('/panel/actividades/' . $id . '/eliminar')) ?>" data-confirmar="¿Eliminar la actividad? Solo es posible si nadie se ha inscrito.">
                <?= csrf_campo() ?>
                <button class="boton boton--peligro" type="submit">Eliminar</button>
            </form>
        </div>
    </section>
<?php endif; ?>
