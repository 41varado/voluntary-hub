<section class="medio">
    <h1>Acerca de <?= e(Config::get('APP_NOMBRE', 'VoluntaCR')) ?></h1>
    <p>Las organizaciones comunales y las ONG publican actividades de voluntariado (limpiezas de playa,
        reforestaciones, tutorías, campañas) con un cupo limitado. Hoy lo coordinan con formularios y chats:
        se inscribe más gente de la que cabe, nadie sabe quién llegó y las horas de cada voluntario se pierden.</p>
    <p>Este portal publica las actividades, controla los cupos sin sobrecupo aunque muchas personas se inscriban
        al mismo tiempo, registra la asistencia y acumula las horas de cada voluntario.</p>
    <h2>Cómo usa las bases de datos</h2>
    <p><strong>MongoDB</strong> guarda todo lo permanente: organizaciones, usuarios, actividades con sus imágenes,
        inscripciones y asistencia. Es la fuente de verdad.</p>
    <p><strong>Redis</strong> acelera: filtra las inscripciones cuando una actividad se llena, evita dobles envíos
        y guarda en caché el listado. Si Redis se cae, el sistema sigue funcionando solo con MongoDB.</p>
    <p>El código es abierto (licencia MIT) para que cualquier organización lo pueda instalar y mejorar.</p>
</section>
