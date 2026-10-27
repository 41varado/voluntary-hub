<?php
/**
 * Pruebas de docs/pruebas/error-conexion.md (EC-01 a EC-06). Las corre scripts/pruebas/correr.php.
 * Los pasos que necesitan pausar o detener Redis o MongoDB se omiten: el script no controla los
 * motores del servidor. EC-03, EC-04 y el paso 4 de EC-06 necesitan la base y el Redis de la
 * aplicación, así que solo corren en el servidor (o en Codespaces contra su propia aplicación).
 */
declare(strict_types=1);

$motores = 'requiere pausar o detener los motores, y el script no los controla en el servidor.';

return [
    'EC-01' => [
        'nombre' => '/estado en los tres modos: normal, respaldo (sin Redis) y crítico (sin MongoDB)',
        'pasos'  => 6,
        'correr' => function (Corrida $c, Prueba $p) use ($motores): void {
            $n = $c->navegador();
            $n->get('/estado');
            $modo = Navegador::limpiar((string) $n->buscar('#<div class="modo[^"]*" role="status">\s*<strong>(.*?)</strong>#s'));
            $mongo = Navegador::limpiar((string) $n->buscar('#<h2>MongoDB <span[^>]*>(.*?)</span>#'));
            $redis = Navegador::limpiar((string) $n->buscar('#<h2>Redis <span[^>]*>(.*?)</span>#'));
            $p->paso(1, $n->codigo === 200 && $modo === 'Funcionamiento normal' && $mongo === 'Conectado' && $redis === 'Conectado',
                "«{$modo}»; MongoDB: «{$mongo}»; Redis: «{$redis}».");
            $p->omitir([2, 3, 4, 5, 6], $motores);
        },
    ],

    'EC-02' => [
        'nombre' => 'Con Redis caído, el portal, las reservas y el inicio de sesión siguen funcionando',
        'pasos'  => 5,
        'correr' => fn(Corrida $c, Prueba $p) => $p->omitirResto($motores),
    ],

    'EC-03' => [
        'nombre' => 'Sin Redis no hay sobrecupo, ni con 500 solicitudes simultáneas',
        'pasos'  => 1,
        'correr' => function (Corrida $c, Prueba $p): void {
            $c->bd(); // medir_cupos.php escribe en la base de este equipo: tiene que ser la de la aplicación
            fwrite(STDERR, "  EC-03: 500 reservas simultáneas sin Redis, 3 repeticiones…\n");
            [$codigo, $salida] = Corrida::script(['scripts/medir_cupos.php', '--modo=mongodb', '--solicitudes=500', '--cupos=50', '--procesos=20', '--repeticiones=3']);
            preg_match_all('/rep (\d+):\s+(\d+) aceptadas,\s+(\d+) en MongoDB, sobrecupo (\d+)/', $salida, $reps, PREG_SET_ORDER);
            $todas = count($reps) === 3 && !array_filter($reps, fn(array $r) => [$r[2], $r[3], $r[4]] !== ['50', '50', '0']);
            $p->paso(1, $codigo === 0 && $todas, $reps
                ? implode('; ', array_map(fn(array $r) => "repetición {$r[1]}: {$r[2]} aceptadas, {$r[3]} en MongoDB, sobrecupo {$r[4]}", $reps)) . '.'
                : "Salida de medir_cupos.php: {$salida}");
            if (preg_match('/Resultados guardados en (\S+)/', $salida, $csv)) {
                $p->nota("medir_cupos.php guardó {$csv[1]} (solo el modo sin Redis).");
            }
        },
    ],

    'EC-04' => [
        'nombre' => 'Cuando Redis vuelve, un contador de cupos desfasado se corrige solo',
        'pasos'  => 4,
        'correr' => function (Corrida $c, Prueba $p): void {
            $rs = $c->redis();
            $id = $c->crearActividad('EC-04');
            $clave = $rs->k('cupos:' . $id);

            // 1. voluntario1 reserva (cupo 2).
            $uno = $c->sesion(Corrida::VOLUNTARIO1);
            $aviso = $c->reservar($uno, $id);
            $p->paso(1, str_starts_with($aviso, 'Cupo reservado.') && $c->motorEs($aviso, 'filtrado en Redis y confirmado en MongoDB')
                && $uno->medidor() === '1 cupo libre', "«{$aviso}»; medidor: «{$uno->medidor()}».");

            // 2. El contador queda como después de una cancelación con Redis caído: 0, pero MongoDB tiene 1.
            $guardado = $rs->usar(fn(Redis $r) => $r->set($clave, '0'), false);
            $p->paso(2, $guardado === true, "SET {$clave} 0 → " . ($guardado === true ? 'OK' : 'falló') . '.');

            // 3. voluntario2 reserva: el contador se corrige en lugar de rechazarla.
            $aviso = $c->reservar($c->sesion(Corrida::VOLUNTARIO2), $id);
            $p->paso(3, str_starts_with($aviso, 'Cupo reservado.') && $c->motorEs($aviso, 'filtrado en Redis y confirmado en MongoDB'), "«{$aviso}».");

            // 4. El contador se volvió a cargar desde MongoDB (1) y bajó con la reserva.
            $valor = $rs->usar(fn(Redis $r) => $r->get($clave), false);
            $p->paso(4, $valor === '0', "GET {$clave} → " . var_export($valor, true) . '.');
        },
    ],

    'EC-05' => [
        'nombre' => 'Con MongoDB caído, el listado sale de la caché y lo demás muestra una página 503',
        'pasos'  => 6,
        'correr' => fn(Corrida $c, Prueba $p) => $p->omitirResto($motores),
    ],

    'EC-06' => [
        'nombre' => 'La bitácora de eventos con Redis caído',
        'pasos'  => 4,
        'correr' => function (Corrida $c, Prueba $p) use ($motores): void {
            $titulo = $c->titulo('EC-06');
            $id = $c->crearActividad('EC-06');

            // 1. Reservar, confirmar y cancelar; los eventos aparecen en /estado.
            $uno = $c->sesion(Corrida::VOLUNTARIO1);
            $c->reservar($uno, $id);
            $inscripcion = $c->inscripcion($uno);
            $uno->post("/inscripciones/{$inscripcion}/confirmar", ['actividad' => $id]);
            $uno->post("/inscripciones/{$inscripcion}/cancelar", ['actividad' => $id]);
            $estado = $c->navegador();
            $estado->get('/estado');
            $primeros = array_slice(Corrida::eventos($estado), 0, 3);
            $esperados = [['Cancelación', $titulo], ['Confirmación', $titulo], ['Reserva', $titulo]];
            $detalleReserva = $primeros[2][3] ?? '';
            $p->paso(1, array_map(fn(array $e) => [$e[1], $e[2]], $primeros) === $esperados
                && str_starts_with($detalleReserva, 'filtrada en Redis, ') && str_ends_with($detalleReserva, ' ms'),
                'Últimos eventos: ' . implode('; ', array_map(fn(array $e) => "{$e[1]} de «{$e[2]}»" . ($e[3] !== '' ? " ({$e[3]})" : ''), $primeros)) . '.');
            $p->omitir([2, 3], $motores);

            // 4. XLEN y TTL del stream.
            $rs = $c->redis();
            $estado->get('/estado');
            $total = (int) $estado->buscar('#Se muestran \d+ de (\d+) eventos guardados#');
            [$largo, $vence] = $rs->usar(fn(Redis $r) => $r->eval("return {redis.call('XLEN', KEYS[1]), redis.call('TTL', KEYS[1])}", [$rs->k('eventos')], 1), [null, null]);
            $p->paso(4, $largo === $total && $vence > 2590000, "XLEN = {$largo} (la página dice {$total} eventos guardados); TTL = {$vence} s.");
        },
    ],
];
