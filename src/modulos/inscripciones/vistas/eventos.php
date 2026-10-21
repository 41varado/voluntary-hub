<?php
/** Sección de /estado. Variables: $ultimos (['total', 'eventos'] o null si Redis no responde), $titulos (id => título) */
$tipos = ['reserva' => 'Reserva', 'confirmacion' => 'Confirmación', 'cancelacion' => 'Cancelación', 'vencimiento' => 'Venció sin confirmar'];
$motores = ['redis+mongodb' => 'filtrada en Redis', 'mongodb' => 'solo MongoDB'];
?>
<section style="margin:2.5rem 0">
    <h2>Últimos eventos de inscripciones</h2>
    <p class="texto-suave">Bitácora en un stream de Redis: reservas, confirmaciones, cancelaciones y vencimientos (los últimos 10 000, como máximo).</p>
    <?php if ($ultimos === null): ?>
        <p class="campo__error">No se puede leer mientras Redis no responda. Las inscripciones siguen funcionando, pero no se anotan.</p>
    <?php elseif (!$ultimos['eventos']): ?>
        <p>Todavía no hay eventos.</p>
    <?php else: ?>
        <div class="tabla-envoltura">
            <table class="tabla">
                <thead><tr><th scope="col">Hora</th><th scope="col">Evento</th><th scope="col">Actividad</th><th scope="col">Detalle</th></tr></thead>
                <tbody>
                <?php foreach ($ultimos['eventos'] as $evento): ?>
                    <tr>
                        <td><?= e(fecha_cr($evento['hora'], 'corta')) ?></td>
                        <td><?= e($tipos[$evento['tipo']] ?? $evento['tipo']) ?></td>
                        <td><?= e($titulos[$evento['actividad']] ?? $evento['actividad']) ?></td>
                        <td class="texto-suave">
                            <?= e($motores[$evento['motor']] ?? '') ?><?= $evento['ms'] !== '' ? e(', ' . $evento['ms'] . ' ms') : '' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="texto-suave">Se muestran <?= count($ultimos['eventos']) ?> de <?= (int) $ultimos['total'] ?> eventos guardados.</p>
    <?php endif; ?>
</section>
