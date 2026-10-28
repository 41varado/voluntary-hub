<?php
/** Variables: $proximas, $anteriores (documentos de inscripciones) */
$nombres = ['reservada' => 'Por confirmar', 'confirmada' => 'Confirmada', 'cancelada' => 'Cancelada', 'expirada' => 'Venció sin confirmar'];
?>
<div class="encabezado-seccion">
    <h1>Mis inscripciones</h1>
    <a class="boton boton--secundario" href="<?= e(url('/actividades')) ?>">Buscar actividades</a>
</div>

<h2>Próximas</h2>
<?php if (!$proximas): ?>
    <div class="vacio">
        <p>No tiene actividades próximas. Busque una con cupo libre y reserve su lugar.</p>
    </div>
<?php else: ?>
    <ul class="lista-simple">
        <?php foreach ($proximas as $i): $act = $i['actividad']; ?>
            <li>
                <div>
                    <a href="<?= e(url('/actividades/' . $i['actividadId'])) ?>"><strong><?= e($act['titulo']) ?></strong></a><br>
                    <span class="texto-suave"><?= e(fecha_cr($act['fechaInicio'], 'corta')) ?>, <?= e($act['lugar']) ?>, <?= e($act['provincia']) ?></span>
                </div>
                <div class="acciones">
                    <span class="estado estado--<?= e($i['estado']) ?>"><?= e($nombres[$i['estado']]) ?></span>
                    <?php if ($i['estado'] === 'reservada'): ?>
                        <form method="post" action="<?= e(url('/inscripciones/' . $i['_id'] . '/confirmar')) ?>">
                            <?= csrf_campo() ?><input type="hidden" name="volver" value="lista">
                            <button class="boton boton--pequeno" type="submit">Confirmar cupo</button>
                        </form>
                    <?php endif; ?>
                    <form method="post" action="<?= e(url('/inscripciones/' . $i['_id'] . '/cancelar')) ?>" data-confirmar="¿Cancelar su inscripción? Su cupo quedará libre para otra persona." data-confirmar-boton="Cancelar inscripción">
                        <?= csrf_campo() ?><input type="hidden" name="volver" value="lista">
                        <button class="boton-enlace" type="submit">Cancelar</button>
                    </form>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($anteriores): ?>
    <h2 style="margin-top:2.5rem">Anteriores y canceladas</h2>
    <div class="tabla-envoltura">
        <table class="tabla">
            <thead><tr><th scope="col">Actividad</th><th scope="col">Fecha</th><th scope="col">Estado</th><th scope="col">Asistencia</th></tr></thead>
            <tbody>
            <?php foreach ($anteriores as $i): $act = $i['actividad']; ?>
                <tr>
                    <td><?= e($act['titulo']) ?><br><span class="texto-suave"><?= e($act['organizacion']) ?></span></td>
                    <td><?= e(fecha_cr($act['fechaInicio'], 'corta')) ?></td>
                    <td><span class="estado estado--<?= e($i['estado']) ?>"><?= e($nombres[$i['estado']]) ?></span></td>
                    <td><?php if (!empty($i['asistencia']['presente'])): ?>Asistió, <?= e(plural((float) $i['asistencia']['horas'], 'hora', 'horas')) ?><?php elseif ($i['estado'] === 'confirmada'): ?><span class="texto-suave">Sin registrar</span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
