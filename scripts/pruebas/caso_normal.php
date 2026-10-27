<?php
/**
 * Pruebas de docs/pruebas/caso-normal.md (CN-01 a CN-03). Las corre scripts/pruebas/correr.php.
 * Los números de paso son los del documento.
 */
declare(strict_types=1);

use MongoDB\BSON\ObjectId;

return [
    'CN-01' => [
        'nombre' => 'Flujo principal: de publicar a ver las horas',
        'pasos'  => 10,
        'correr' => function (Corrida $c, Prueba $p): void {
            $voluntario = $c->sesion(Corrida::VOLUNTARIO1);
            $coordinador = $c->sesion(Corrida::COORDINADOR);
            $titulo = $c->titulo('CN-01');
            $categoria = 'Limpieza de playas y ríos';

            // 1. Horas antes de empezar.
            $voluntario->get('/mis-horas');
            $total = Corrida::numero(Corrida::cifras($voluntario)['horas acumuladas'] ?? '0');
            $enCategoria = Corrida::horasCategoria($voluntario, $categoria);
            $p->paso(1, $voluntario->codigo === 200, 'Horas acumuladas: ' . Corrida::horas($total) . "; {$categoria}: " . Corrida::horas($enCategoria) . '.'
                . ($voluntario->contiene('Aún no hay horas registradas') ? ' La página dice «Aún no hay horas registradas».' : ''));

            // 2. Publicar: Puntarenas, playas, dentro de 1 h 15 min, 3 horas, cupo 2.
            $id = $c->crearActividad('CN-01');
            $p->paso(2, in_array(Corrida::PUBLICADA, $coordinador->avisos(), true) && $coordinador->contiene('Subir imágenes'),
                Corrida::resumen($coordinador) . '; se abrió la edición de la actividad.');

            // 3. Subir una imagen.
            $coordinador->post("/panel/actividades/{$id}/imagenes", [], [Corrida::archivo('cn-01.png', Corrida::imagenPng(64, 48))]);
            $seVe = preg_match('#/uploads/actividades/' . $id . '/[a-f0-9]{16}\.png"#', $coordinador->html) === 1;
            $p->paso(3, in_array('1 imagen agregada.', $coordinador->avisos(), true) && $seVe,
                Corrida::resumen($coordinador) . ($seVe ? '; la imagen aparece en la actividad.' : '; la imagen no aparece en la actividad.'));

            // 4. Aparece en el listado.
            $voluntario->get('/actividades?' . http_build_query(['q' => $titulo]));
            $tarjeta = Corrida::tarjeta($voluntario, $titulo);
            $p->paso(4, $tarjeta === '2 cupos libres', $tarjeta === null ? 'La actividad no aparece al buscarla.' : "Aparece con «{$tarjeta}».");

            // 5. Reservar.
            $aviso = $c->reservar($voluntario, $id);
            $apartado = $voluntario->contiene('Cupo apartado') && $voluntario->buscar('#data-expira="(\d+)"#') !== null;
            $p->paso(5, str_starts_with($aviso, 'Cupo reservado.') && $c->motorEs($aviso, 'filtrado en Redis y confirmado en MongoDB')
                && $apartado && $voluntario->medidor() === '1 cupo libre',
                "«{$aviso}»; el bloque " . ($apartado ? 'dice «Cupo apartado» con la cuenta regresiva' : 'no dice «Cupo apartado»')
                . "; medidor: «{$voluntario->medidor()}».");
            $inscripcion = $c->inscripcion($voluntario);

            // 6. Confirmar.
            $voluntario->post("/inscripciones/{$inscripcion}/confirmar", ['actividad' => $id]);
            $aviso = $voluntario->avisos()[0] ?? '(sin aviso)';
            $confirmado = str_contains($voluntario->html, 'estado--confirmada">Cupo confirmado</span>');
            $p->paso(6, str_starts_with($aviso, 'Cupo confirmado. Lo esperamos el día de la actividad.')
                && $c->motorEs($aviso, 'resuelto solo en MongoDB') && $confirmado,
                "«{$aviso}»; el bloque " . ($confirmado ? 'dice «Cupo confirmado».' : 'no dice «Cupo confirmado».'));

            // 7. Mis inscripciones.
            $voluntario->get('/mis-inscripciones');
            $fila = Corrida::enMisInscripciones($voluntario, $titulo);
            $p->paso(7, $fila === ['Próximas', 'Confirmada'], $fila ? "Aparece en {$fila[0]} con el estado «{$fila[1]}»." : 'No aparece.');

            // 8. Inscritos y asistencia.
            $coordinador->get("/panel/actividades/{$id}/asistencia");
            $cifras = Corrida::cifras($coordinador);
            $casilla = $coordinador->buscar('#name="presentes\[\]" value="' . $inscripcion . '"([^>]*)>#');
            $habilitada = $casilla !== null && !str_contains($casilla, 'disabled');
            $p->paso(8, [$cifras['confirmados'] ?? '', $cifras['por confirmar'] ?? '', $cifras['presentes'] ?? '', $cifras['cupos libres'] ?? ''] === ['1', '0', '0', '1']
                && $habilitada && $coordinador->contiene(Corrida::VOLUNTARIO1),
                'Cifras: ' . Corrida::textoCifras($cifras) . '; la casilla de ' . Corrida::VOLUNTARIO1
                . ($casilla === null ? ' no aparece.' : ($habilitada ? ' está habilitada.' : ' está deshabilitada.')));

            // 9. Guardar la asistencia.
            $coordinador->post("/panel/actividades/{$id}/asistencia", ['presentes' => [$inscripcion]]);
            $presentes = Corrida::cifras($coordinador)['presentes'] ?? '?';
            $p->paso(9, in_array('Asistencia guardada (1 cambio).', $coordinador->avisos(), true) && $presentes === '1',
                Corrida::resumen($coordinador) . "; presentes: {$presentes}.");

            // 10. Las horas suben 3.
            $voluntario->get('/mis-horas');
            $totalDespues = Corrida::numero(Corrida::cifras($voluntario)['horas acumuladas'] ?? '0');
            $categoriaDespues = Corrida::horasCategoria($voluntario, $categoria);
            $completada = str_contains($voluntario->html, '<td>' . htmlspecialchars($titulo) . '</td>');
            $p->paso(10, abs($totalDespues - $total - 3) < 0.01 && abs($categoriaDespues - $enCategoria - 3) < 0.01 && $completada,
                'Horas acumuladas: ' . Corrida::horas($total) . ' → ' . Corrida::horas($totalDespues)
                . "; {$categoria}: " . Corrida::horas($enCategoria) . ' → ' . Corrida::horas($categoriaDespues)
                . "; «{$titulo}» " . ($completada ? 'aparece' : 'no aparece') . ' en Actividades completadas.');
        },
    ],

    'CN-02' => [
        'nombre' => 'Sin sobrecupo: actividad llena y cupo liberado',
        'pasos'  => 7,
        'correr' => function (Corrida $c, Prueba $p): void {
            $uno = $c->sesion(Corrida::VOLUNTARIO1);
            $dos = $c->sesion(Corrida::VOLUNTARIO2);
            $coordinador = $c->sesion(Corrida::COORDINADOR);
            $titulo = $c->titulo('CN-02');

            // 1. Actividad con cupo 1.
            $id = $c->crearActividad('CN-02', ['cupoTotal' => '1']);
            $p->paso(1, in_array(Corrida::PUBLICADA, $coordinador->avisos(), true), Corrida::resumen($coordinador));

            // 2. voluntario2 abre la actividad y deja la página abierta.
            $dos->get("/actividades/{$id}");
            $boton = $dos->contiene('Reservar cupo');
            $p->paso(2, $boton && $dos->medidor() === '1 cupo libre',
                'Botón «Reservar cupo» ' . ($boton ? 'visible' : 'no aparece') . "; medidor: «{$dos->medidor()}».");

            // 3. voluntario1 toma el único cupo.
            $aviso = $c->reservar($uno, $id);
            $p->paso(3, str_starts_with($aviso, 'Cupo reservado.') && $c->motorEs($aviso, 'filtrado en Redis y confirmado en MongoDB')
                && $uno->medidor() === 'Sin cupos', "«{$aviso}»; medidor: «{$uno->medidor()}».");
            $inscripcionUno = $c->inscripcion($uno);

            // 4. voluntario2 intenta reservar desde la página que dejó abierta.
            $dos->post("/actividades/{$id}/reservar");
            $aviso = $dos->avisos()[0] ?? '(sin aviso)';
            $sinCupos = $dos->contiene('Sin cupos disponibles.');
            $p->paso(4, str_starts_with($aviso, 'Ya no quedan cupos en esta actividad.') && $c->motorEs($aviso, 'resuelto en Redis sin consultar MongoDB') && $sinCupos,
                "«{$aviso}»; el bloque " . ($sinCupos ? 'dice «Sin cupos disponibles.»' : 'no dice «Sin cupos disponibles.»'));

            // 5. voluntario1 libera su cupo.
            $uno->post("/inscripciones/{$inscripcionUno}/cancelar", ['actividad' => $id]);
            $aviso = $uno->avisos()[0] ?? '(sin aviso)';
            $p->paso(5, str_starts_with($aviso, 'Inscripción cancelada. Su cupo quedó libre para otra persona.')
                && $c->motorEs($aviso, 'resuelto solo en MongoDB') && $uno->medidor() === '1 cupo libre',
                "«{$aviso}»; medidor: «{$uno->medidor()}».");

            // 6. voluntario2 recarga, reserva y confirma.
            $avisoReserva = $c->reservar($dos, $id);
            $inscripcionDos = $c->inscripcion($dos);
            $dos->post("/inscripciones/{$inscripcionDos}/confirmar", ['actividad' => $id]);
            $avisoConfirmacion = $dos->avisos()[0] ?? '(sin aviso)';
            $p->paso(6, str_starts_with($avisoReserva, 'Cupo reservado.') && $c->motorEs($avisoReserva, 'filtrado en Redis y confirmado en MongoDB')
                && str_starts_with($avisoConfirmacion, 'Cupo confirmado.') && $dos->medidor() === 'Sin cupos',
                "«{$avisoReserva}»; después «{$avisoConfirmacion}»; medidor: «{$dos->medidor()}».");

            // 7. Listado sin filtro y con «Solo con cupo libre».
            $uno->get('/actividades?' . http_build_query(['q' => $titulo]));
            $sinFiltro = Corrida::tarjeta($uno, $titulo);
            $uno->get('/actividades?' . http_build_query(['q' => $titulo, 'cupo' => '1']));
            $conFiltro = Corrida::tarjeta($uno, $titulo);
            $p->paso(7, $sinFiltro === 'Sin cupos' && $conFiltro === null,
                'Sin el filtro: ' . ($sinFiltro === null ? 'no aparece' : "aparece con «{$sinFiltro}»")
                . '; con «Solo con cupo libre»: ' . ($conFiltro === null ? 'no aparece.' : "aparece con «{$conFiltro}»."));
        },
    ],

    'CN-03' => [
        'nombre' => 'Una reserva sin confirmar vence y devuelve el cupo',
        'pasos'  => 6,
        // Pasos 1 y 2 al empezar la corrida; los demás, cuando vence la reserva ('final').
        'correr' => function (Corrida $c, Prueba $p): void {
            $voluntario = $c->sesion(Corrida::VOLUNTARIO1);
            $coordinador = $c->sesion(Corrida::COORDINADOR);

            // 1. Actividad con cupo 1.
            $id = $c->crearActividad('CN-03', ['cupoTotal' => '1']);
            $p->paso(1, in_array(Corrida::PUBLICADA, $coordinador->avisos(), true), Corrida::resumen($coordinador));

            // 2. Reservar sin confirmar.
            $aviso = $c->reservar($voluntario, $id);
            $vence = (int) $voluntario->buscar('#data-expira="(\d+)"#');
            $minutos = round(($vence - time()) / 60, 1);
            $p->paso(2, str_starts_with($aviso, 'Cupo reservado.') && $vence > time() && $voluntario->medidor() === 'Sin cupos',
                "«{$aviso}»; cuenta regresiva hasta las " . date('H:i:s', $vence) . " ({$minutos} min); medidor: «{$voluntario->medidor()}».");

            $avisoOculto = str_contains($voluntario->html, 'data-al-vencer hidden>El tiempo terminó. Recargue la página para ver si todavía hay cupo.');
            $c->datos['CN-03'] = ['id' => $id, 'vence' => $vence, 'avisoOculto' => $avisoOculto];
            $p->nota("La reserva duró {$minutos} min, lo que indica RESERVA_MINUTOS en la aplicación (el documento usa 1). "
                . 'No se cambió el .env: el script esperó a que venciera mientras corrían las demás pruebas.');
        },
        'final' => function (Corrida $c, Prueba $p): void {
            ['id' => $id, 'vence' => $vence, 'avisoOculto' => $avisoOculto] = $c->datos['CN-03'];
            $voluntario = $c->sesion(Corrida::VOLUNTARIO1);

            // 3. Lo hace JavaScript en el navegador.
            $p->omitir(3, 'la cuenta regresiva y el aviso «El tiempo terminó…» los muestra JavaScript en el navegador.'
                . ($avisoOculto ? ' La página sí trae ese aviso, oculto hasta que la cuenta llega a 0:00.' : ' La página no trae ese aviso.'));
            if (!$c->esperar) {
                throw new Omision('se corrió con --sin-espera, sin esperar a que venciera la reserva');
            }
            $c->esperarHasta($vence + 3, 'que venza la reserva de CN-03');

            // 4. El script del cron.
            if (($motivo = $c->sinBd()) !== null) {
                $p->omitir(4, "scripts/liberar_reservas.php tiene que correr con la base de la aplicación ({$motivo}). "
                    . 'El cron del servidor y abrir la actividad (D3) hacen lo mismo; el paso 5 lo comprueba.');
            } else {
                [$codigo, $salida] = Corrida::script(['scripts/liberar_reservas.php']);
                $estado = $c->bd()->buscarUno('inscripciones', ['actividadId' => new ObjectId($id)])['estado'] ?? '?';
                $ninguna = preg_match('/reservas liberadas: 0\b/', $salida) === 1;
                $p->paso(4, $codigo === 0 && $estado === 'expirada', "Salida: «{$salida}»; la inscripción quedó «{$estado}» en MongoDB."
                    . ($ninguna && $estado === 'expirada' ? ' El cron del servidor ya la había liberado.' : ''));
            }

            // 5. Recargar la actividad.
            $voluntario->get("/actividades/{$id}");
            $vencida = $voluntario->contiene('Su inscripción anterior quedó vencida. Puede reservar de nuevo.');
            $p->paso(5, $voluntario->medidor() === '1 cupo libre' && $vencida,
                "Medidor: «{$voluntario->medidor()}»; el bloque " . ($vencida ? 'dice «Su inscripción anterior quedó vencida. Puede reservar de nuevo.»' : 'no dice que la inscripción venció.'));

            // 6. Mis inscripciones.
            $voluntario->get('/mis-inscripciones');
            $fila = Corrida::enMisInscripciones($voluntario, $c->titulo('CN-03'));
            $p->paso(6, $fila === ['Anteriores y canceladas', 'Venció sin confirmar'], $fila ? "Aparece en {$fila[0]} con el estado «{$fila[1]}»." : 'No aparece.');
        },
    ],
];
