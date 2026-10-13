<?php
/** Variables: $usuario, $actividades (con 'conteos' por estado de inscripción) */
$nombresEstado = ['publicada' => 'Publicada', 'borrador' => 'Borrador', 'cancelada' => 'Cancelada'];
?>
<div class="encabezado-seccion">
    <div>
        <h1><?= e($usuario['organizacion']['nombre']) ?></h1>
        <p class="texto-suave">Actividades de su organización, de la más reciente a la más antigua.</p>
    </div>
    <a class="boton" href="<?= e(url('/panel/actividades/nueva')) ?>">Publicar actividad</a>
</div>

<?php if (!$actividades): ?>
    <div class="vacio">
        <h2>Todavía no hay actividades</h2>
        <p>Publique la primera: indique fecha, lugar y cuántas personas caben. Las imágenes se agregan después.</p>
        <a class="boton" href="<?= e(url('/panel/actividades/nueva')) ?>">Publicar actividad</a>
    </div>
<?php else: ?>
    <div class="tabla-envoltura">
        <table class="tabla">
            <thead>
            <tr>
                <th scope="col">Actividad</th>
                <th scope="col">Fecha</th>
                <th scope="col">Estado</th>
                <th scope="col" class="numero">Confirmadas</th>
                <th scope="col" class="numero">Por confirmar</th>
                <th scope="col" class="numero">Cupos libres</th>
                <th scope="col">Acciones</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($actividades as $a): $id = (string) $a['_id']; ?>
                <tr>
                    <td><a href="<?= e(url('/actividades/' . $id)) ?>"><?= e($a['titulo']) ?></a></td>
                    <td><?= e(fecha_cr($a['fechaInicio'], 'corta')) ?></td>
                    <td><span class="estado estado--<?= e($a['estado']) ?>"><?= e($nombresEstado[$a['estado']] ?? $a['estado']) ?></span></td>
                    <td class="numero"><?= (int) ($a['conteos']['confirmada'] ?? 0) ?></td>
                    <td class="numero"><?= (int) ($a['conteos']['reservada'] ?? 0) ?></td>
                    <td class="numero"><?= (int) $a['cuposDisponibles'] ?> de <?= (int) $a['cupoTotal'] ?></td>
                    <td>
                        <div class="acciones">
                            <a href="<?= e(url('/panel/actividades/' . $id . '/editar')) ?>">Editar</a>
                            <?= Ganchos::html('panel.actividad.acciones', $a) ?>
                            <?php if ($a['estado'] === 'borrador'): ?>
                                <form method="post" action="<?= e(url('/panel/actividades/' . $id . '/estado')) ?>">
                                    <?= csrf_campo() ?><input type="hidden" name="estado" value="publicada">
                                    <button class="boton-enlace" type="submit">Publicar</button>
                                </form>
                            <?php elseif ($a['estado'] === 'publicada'): ?>
                                <form method="post" action="<?= e(url('/panel/actividades/' . $id . '/estado')) ?>" data-confirmar="¿Cancelar esta actividad? Las personas inscritas la verán como cancelada.">
                                    <?= csrf_campo() ?><input type="hidden" name="estado" value="cancelada">
                                    <button class="boton-enlace" type="submit">Cancelar</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
