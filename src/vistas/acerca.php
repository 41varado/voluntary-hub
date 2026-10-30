<?php
$repositorio = Config::get('APP_REPOSITORIO', 'https://github.com/41varado/voluntary-hub');
// Integrantes del equipo, en orden de apellido: nombre => usuario de GitHub.
$equipo = [
    'Luis Aguilar Monge'     => 'Luis9628',
    'Jafet Alvarado Barboza' => '41varado',
    'Manuel Mora Sandi'      => 'Manuel01011',
    'Derek Rojas Mendoza'    => 'DerekJRM',
];
?>
<section class="medio">
    <h1>Acerca de <?= e(Config::get('APP_NOMBRE', 'VoluntaCR')) ?></h1>
    <p>Las organizaciones comunales y las ONG publican actividades de voluntariado (limpiezas de playa,
        reforestaciones, tutorías, campañas) con un cupo limitado. Hoy lo coordinan con formularios y chats:
        se inscribe más gente de la que cabe, nadie sabe quién llegó y las horas de cada voluntario se pierden.</p>
    <p>Este portal publica las actividades, controla los cupos sin sobrecupo aunque muchas personas se inscriban
        al mismo tiempo, avisa a las personas inscritas cuando algo cambia, registra la asistencia y acumula las
        horas de cada voluntario.</p>
    <h2>Cómo usa las bases de datos</h2>
    <p><strong>MongoDB</strong> guarda todo lo permanente: organizaciones, usuarios, actividades con sus imágenes,
        inscripciones, asistencia y los avisos de cada persona. Es la fuente de verdad.</p>
    <p><strong>Redis</strong> acelera: filtra las inscripciones cuando una actividad se llena, evita dobles envíos
        y guarda en caché el listado. Si Redis se cae, el sistema sigue funcionando solo con MongoDB.</p>
    <p>Los correos, para confirmar la cuenta y para los avisos, se envían con <strong>Mailjet</strong>. Si Mailjet
        no responde, el aviso queda igual en el portal y el correo se reintenta después.</p>

    <h2>Equipo</h2>
    <p>Proyecto final del curso Tópicos Avanzados de Bases de Datos de la Universidad Nacional (II-2026).</p>
    <ul class="lista-simple">
        <?php foreach ($equipo as $nombre => $usuarioGithub): ?>
            <li>
                <span><?= e($nombre) ?></span>
                <a href="https://github.com/<?= e($usuarioGithub) ?>" rel="noopener">@<?= e($usuarioGithub) ?></a>
            </li>
        <?php endforeach; ?>
    </ul>

    <h2 style="margin-top:2rem">Código fuente</h2>
    <p>El código es abierto (licencia MIT) para que cualquier organización lo pueda instalar y mejorar. Está en
        <a href="<?= e($repositorio) ?>" rel="noopener"><?= e(preg_replace('#^https?://#', '', $repositorio)) ?></a>.</p>
</section>
