<?php
/**
 * Avisos de una actividad, para su organización. Variables: $actividad, $inscritos (personas con
 * inscripción activa), $historial (un elemento por envío), $valores, $errores
 */
$id = (string) $actividad['_id'];
$v = fn(string $campo) => e($valores[$campo] ?? '');
$clase = fn(string $campo) => 'campo' . (isset($errores[$campo]) ? ' campo--con-error' : '');
$error = fn(string $campo) => isset($errores[$campo]) ? '<p class="campo__error" id="error-' . $campo . '">' . e($errores[$campo]) . '</p>' : '';
$describe = fn(string $campo) => isset($errores[$campo]) ? ' aria-describedby="error-' . $campo . '" aria-invalid="true"' : '';
?>
<div class="encabezado-seccion">
    <div>
        <p class="detalle__migas"><a href="<?= e(url('/panel')) ?>">Panel de mi organización</a></p>
        <h1><?= e($actividad['titulo']) ?></h1>
        <p class="texto-suave"><?= e(fecha_cr($actividad['fechaInicio'])) ?>. <?= e(plural($inscritos, 'persona inscrita', 'personas inscritas')) ?>, contando las reservas por confirmar.</p>
    </div>
</div>

<div class="medio">
    <form class="tarjeta formulario" method="post" action="<?= e(url('/panel/actividades/' . $id . '/avisos')) ?>" novalidate
          data-confirmar="¿Enviar este aviso a <?= e(plural($inscritos, 'persona', 'personas')) ?>?" data-confirmar-estilo="principal">
        <?= csrf_campo() ?>
        <h2>Enviar un aviso a las personas inscritas</h2>
        <p class="texto-suave">Les llega a todas en «Avisos», dentro del portal, y por correo a quienes no desactivaron
            los avisos por correo. No se puede escribir a una sola persona.</p>
        <div class="<?= $clase('titulo') ?>">
            <label for="titulo">Asunto</label>
            <input type="text" id="titulo" name="titulo" value="<?= $v('titulo') ?>" maxlength="120" required<?= $describe('titulo') ?>>
            <?= $error('titulo') ?>
        </div>
        <div class="<?= $clase('mensaje') ?>">
            <label for="mensaje">Mensaje</label>
            <textarea id="mensaje" name="mensaje" maxlength="2000" required<?= $describe('mensaje') ?>><?= $v('mensaje') ?></textarea>
            <p class="campo__ayuda">Por ejemplo: el punto de encuentro, qué llevar o un recordatorio. Hasta 2000 caracteres.</p>
            <?= $error('mensaje') ?>
        </div>
        <div class="acciones">
            <button class="boton" type="submit"<?= $inscritos === 0 ? ' disabled' : '' ?>>Enviar aviso</button>
            <?php if ($inscritos === 0): ?>
                <span class="texto-suave">Todavía no hay personas inscritas a quienes enviarlo.</span>
            <?php endif; ?>
        </div>
    </form>
</div>

<h2 style="margin-top:2.5rem">Avisos enviados</h2>
<p class="texto-suave">Los mensajes de su organización y los avisos automáticos: cancelación, cambios, retiro del portal, nueva publicación y horas de asistencia.</p>
<?php if (!$historial): ?>
    <div class="vacio"><p>Todavía no se ha enviado ningún aviso sobre esta actividad.</p></div>
<?php else: ?>
    <div class="tabla-envoltura">
        <table class="tabla">
            <thead>
            <tr>
                <th scope="col">Fecha</th>
                <th scope="col">Tipo</th>
                <th scope="col">Aviso</th>
                <th scope="col" class="numero">Personas</th>
                <th scope="col" class="numero">Lo leyeron</th>
                <th scope="col" class="numero">Por correo</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($historial as $envio): ?>
                <tr>
                    <td><?= e(fecha_cr($envio['creadaEn'], 'corta')) ?></td>
                    <td>
                        <?= e(Catalogos::TIPOS_AVISO[$envio['tipo']] ?? $envio['tipo']) ?>
                        <?php if (!empty($envio['remitente'])): ?><br><span class="texto-suave"><?= e($envio['remitente']) ?></span><?php endif; ?>
                    </td>
                    <td><strong><?= e($envio['titulo']) ?></strong><br><span class="texto-suave"><?= e(mb_strimwidth($envio['mensaje'], 0, 160, '…')) ?></span></td>
                    <td class="numero"><?= (int) $envio['personas'] ?></td>
                    <td class="numero"><?= (int) $envio['leidas'] ?></td>
                    <td class="numero"><?= (int) $envio['correos'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
