<?php
/** Variables: $actividad, $confirmados, $pendientes, $abierta */
$id = (string) $actividad['_id'];
$presentes = count(array_filter($confirmados, fn($i) => !empty($i['asistencia']['presente'])));
?>
<div class="encabezado-seccion">
    <div>
        <p class="detalle__migas"><a href="<?= e(url('/panel')) ?>">Panel de mi organización</a></p>
        <h1><?= e($actividad['titulo']) ?></h1>
        <p class="texto-suave"><?= e(fecha_cr($actividad['fechaInicio'])) ?> Cada asistencia suma <?= e(plural((float) $actividad['horas'], 'hora', 'horas')) ?> al voluntario.</p>
    </div>
</div>

<div class="cifras">
    <div class="cifra"><strong><?= count($confirmados) ?></strong><span>confirmados</span></div>
    <div class="cifra"><strong><?= count($pendientes) ?></strong><span>por confirmar</span></div>
    <div class="cifra"><strong><?= $presentes ?></strong><span>presentes</span></div>
    <div class="cifra"><strong><?= (int) $actividad['cuposDisponibles'] ?></strong><span>cupos libres</span></div>
</div>

<?php if (!$confirmados): ?>
    <div class="vacio"><p>Todavía nadie ha confirmado su cupo en esta actividad.</p></div>
<?php else: ?>
    <p class="aviso aviso--aviso" data-asistencia-estado role="status" hidden></p>
    <form method="post" action="<?= e(url('/panel/actividades/' . $id . '/asistencia')) ?>" data-asistencia-sin-conexion="<?= e($id) ?>">
        <?= csrf_campo() ?>
        <div class="tabla-envoltura">
            <table class="tabla">
                <thead><tr><th scope="col">Asistió</th><th scope="col">Voluntario</th><th scope="col">Correo</th><th scope="col">Confirmó</th></tr></thead>
                <tbody>
                <?php foreach ($confirmados as $i): $iid = (string) $i['_id']; ?>
                    <tr>
                        <td>
                            <label class="asistencia-fila">
                                <input type="checkbox" name="presentes[]" value="<?= e($iid) ?>"<?= !empty($i['asistencia']['presente']) ? ' checked' : '' ?><?= $abierta ? '' : ' disabled' ?>>
                                <span class="texto-suave"><?= !empty($i['asistencia']['presente']) ? 'Presente' : 'Ausente' ?></span>
                            </label>
                        </td>
                        <td><?= e($i['voluntario']['nombre']) ?></td>
                        <td><?= e($i['voluntario']['correo'] ?? '') ?></td>
                        <td><?= !empty($i['confirmadaEn']) ? e(fecha_cr($i['confirmadaEn'], 'corta')) : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="acciones" style="margin-top:1rem">
            <?php if ($abierta): ?>
                <button class="boton" type="submit">Guardar asistencia</button>
                <p class="texto-suave">Si no hay señal, las marcas quedan guardadas en este navegador y se envían solas al volver la conexión. Abra esta página antes de llegar al lugar de la actividad.</p>
            <?php else: ?>
                <p class="texto-suave">La asistencia se habilita dos horas antes del inicio de la actividad.</p>
            <?php endif; ?>
        </div>
    </form>
<?php endif; ?>

<?php if ($pendientes): ?>
    <h2 style="margin-top:2.5rem">Reservas sin confirmar</h2>
    <p class="texto-suave">Si no confirman a tiempo, el cupo se libera solo.</p>
    <ul class="lista-simple">
        <?php foreach ($pendientes as $i): ?>
            <li><span><?= e($i['voluntario']['nombre']) ?></span><span class="texto-suave">Vence a las <?= e(fecha_cr($i['expiraEn'], 'hora')) ?></span></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
