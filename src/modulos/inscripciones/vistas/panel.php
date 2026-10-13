<?php
/** Bloque de inscripción en el detalle. Variables: $a (actividad), $usuario, $inscripcion (o null) */
$id = (string) $a['_id'];
$yaEmpezo = $a['fechaInicio']->toDateTime()->getTimestamp() <= time();
$estado = $inscripcion['estado'] ?? null;
$vence = $estado === 'reservada' ? $inscripcion['expiraEn']->toDateTime()->getTimestamp() : null;
?>
<?php if ($a['estado'] === 'cancelada'): ?>
    <p><strong>La organización canceló esta actividad.</strong></p>

<?php elseif ($usuario === null): ?>
    <p>Para reservar un cupo necesita una cuenta de voluntario.</p>
    <div class="acciones">
        <a class="boton" href="<?= e(url('/ingresar', ['volver' => '/actividades/' . $id])) ?>">Iniciar sesión</a>
        <a class="boton boton--secundario" href="<?= e(url('/registro')) ?>">Crear cuenta</a>
    </div>

<?php elseif ($usuario['rol'] !== 'voluntario'): ?>
    <p class="texto-suave">Las reservas son para cuentas de voluntario.</p>

<?php elseif ($estado === 'confirmada'): ?>
    <p><span class="estado estado--confirmada">Cupo confirmado</span></p>
    <p>Lo esperamos el <?= e(fecha_cr($a['fechaInicio'], 'dia')) ?>.</p>
    <?php if (!$yaEmpezo): ?>
        <form method="post" action="<?= e(url('/inscripciones/' . $inscripcion['_id'] . '/cancelar')) ?>" data-confirmar="¿Cancelar su inscripción? Su cupo quedará libre para otra persona.">
            <?= csrf_campo() ?><input type="hidden" name="actividad" value="<?= e($id) ?>">
            <button class="boton boton--secundario boton--ancho" type="submit">Cancelar mi inscripción</button>
        </form>
    <?php endif; ?>

<?php elseif ($estado === 'reservada' && $vence > time()): ?>
    <p><span class="estado estado--reservada">Cupo apartado</span></p>
    <p>Confírmelo antes de que termine el tiempo:</p>
    <p class="cuenta-regresiva"><span data-expira="<?= (int) $vence ?>"><?= e(fecha_cr($vence, 'hora')) ?></span></p>
    <p class="aviso aviso--aviso" data-al-vencer hidden>El tiempo terminó. Recargue la página para ver si todavía hay cupo.</p>
    <div class="acciones">
        <form method="post" action="<?= e(url('/inscripciones/' . $inscripcion['_id'] . '/confirmar')) ?>">
            <?= csrf_campo() ?><input type="hidden" name="actividad" value="<?= e($id) ?>">
            <button class="boton" type="submit">Confirmar cupo</button>
        </form>
        <form method="post" action="<?= e(url('/inscripciones/' . $inscripcion['_id'] . '/cancelar')) ?>">
            <?= csrf_campo() ?><input type="hidden" name="actividad" value="<?= e($id) ?>">
            <button class="boton boton--secundario" type="submit">Liberar cupo</button>
        </form>
    </div>

<?php elseif ($yaEmpezo): ?>
    <p class="texto-suave">Esta actividad ya comenzó y no admite reservas.</p>

<?php elseif ((int) $a['cuposDisponibles'] <= 0): ?>
    <p><strong>Sin cupos disponibles.</strong></p>
    <p class="texto-suave">Si alguien cancela o no confirma su reserva, el cupo vuelve a quedar libre. Revise más tarde.</p>

<?php else: ?>
    <?php if (in_array($estado, ['cancelada', 'expirada'], true)): ?>
        <p class="texto-suave">Su inscripción anterior quedó <?= $estado === 'cancelada' ? 'cancelada' : 'vencida' ?>. Puede reservar de nuevo.</p>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/actividades/' . $id . '/reservar')) ?>">
        <?= csrf_campo() ?>
        <button class="boton boton--ancho" type="submit">Reservar cupo</button>
    </form>
    <p class="campo__ayuda" style="margin-top:.6rem">Tendrá <?= ServicioCupos::minutosReserva() ?> minutos para confirmarlo.</p>
<?php endif; ?>
