<?php
/**
 * Reintenta los correos de los avisos que fallaron (Mailjet no respondió o rechazó el envío) y los
 * que quedaron pendientes. Pensado para una tarea programada (cron) cada 5 minutos:
 *
 *   0-59/5 * * * * php /ruta/al/proyecto/scripts/enviar_correos.php >> /ruta/al/proyecto/storage/cron.log 2>&1
 *
 * Los avisos ya están en el portal: el cron solo se encarga del correo. Cada aviso se intenta hasta
 * 3 veces, durante 48 horas.
 * Responsable: Persona 3.
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require RAIZ . '/src/modulos/notificaciones/Notificaciones.php';

$conteo = Notificaciones::reintentarCorreos();
echo date('Y-m-d H:i:s') . " correos: {$conteo['enviado']} enviados, {$conteo['fallido']} fallidos, {$conteo['omitido']} omitidos\n";
