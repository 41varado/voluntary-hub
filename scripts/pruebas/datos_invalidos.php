<?php
/**
 * Pruebas de docs/pruebas/datos-invalidos.md (DI-01 a DI-09). Las corre scripts/pruebas/correr.php.
 * Lo que el documento hace con F12 (cambiar un campo antes de enviarlo) aquí se envía directo por
 * HTTP: el servidor recibe lo mismo. DI-09 necesita la base de la aplicación.
 */
declare(strict_types=1);

use MongoDB\BSON\ObjectId;

$obligatorios = [
    'titulo'      => 'Escriba un título de 5 a 120 caracteres.',
    'categoria'   => 'Elija una categoría.',
    'fechaInicio' => 'Indique la fecha y la hora de inicio.',
    'provincia'   => 'Elija una provincia.',
    'lugar'       => 'Indique el lugar de encuentro (3 a 150 caracteres).',
    'descripcion' => 'Describa la actividad en 20 a 3000 caracteres.',
];
$fueraDeRango = [
    'horas'     => 'Las horas deben estar entre 0,5 y 24.',
    'cupoTotal' => 'El cupo debe ser un número entre 1 y 500.',
];

/** Texto para el informe de una escritura que MongoDB debía rechazar. */
$rechazo = fn(?int $codigo, string $mensaje): string => $codigo === null ? "{$mensaje}." : "Error {$codigo}: «{$mensaje}».";

return [
    'DI-01' => [
        'nombre' => 'Una actividad incompleta o con valores fuera de rango no se guarda',
        'pasos'  => 4,
        'correr' => function (Corrida $c, Prueba $p) use ($obligatorios, $fueraDeRango): void {
            $coordinador = $c->sesion(Corrida::COORDINADOR);
            $coordinador->get('/panel');
            $antes = Corrida::actividadesEnPanel($coordinador);
            $vacio = ['titulo' => '', 'descripcion' => '', 'requisitos' => '', 'categoria' => '', 'provincia' => '',
                'lugar' => '', 'fechaInicio' => '', 'horas' => '3', 'cupoTotal' => '20', 'accion' => 'publicar'];

            // 1. Sin llenar nada (las horas y el cupo traen 3 y 20).
            $c->enviarActividad($vacio);
            $p->paso(1, $coordinador->codigoInicial === 422 && Corrida::tieneErrores($coordinador, $obligatorios)
                && !array_intersect_key($coordinador->errores(), $fueraDeRango), Corrida::resumen($coordinador));

            // 2. Valores cortos y en cero; lo escrito se conserva.
            $cortos = ['titulo' => 'Hola', 'descripcion' => 'Muy corta', 'lugar' => 'ab', 'horas' => '0', 'cupoTotal' => '0'] + $vacio;
            $c->enviarActividad($cortos);
            $conserva = str_contains($coordinador->html, 'name="titulo" value="Hola"') && str_contains($coordinador->html, '>Muy corta</textarea>')
                && str_contains($coordinador->html, 'name="lugar" value="ab"');
            $p->paso(2, $coordinador->codigoInicial === 422 && Corrida::tieneErrores($coordinador, $obligatorios + $fueraDeRango) && $conserva,
                Corrida::resumen($coordinador) . ($conserva ? '; lo escrito se conserva en el formulario.' : '; lo escrito no se conserva.'));

            // 3. Horas 25 y cupo 501.
            $c->enviarActividad(['horas' => '25', 'cupoTotal' => '501'] + $cortos);
            $p->paso(3, $coordinador->codigoInicial === 422 && Corrida::tieneErrores($coordinador, $fueraDeRango), Corrida::resumen($coordinador));

            // 4. No se creó nada.
            $coordinador->get('/panel');
            $despues = Corrida::actividadesEnPanel($coordinador);
            $p->paso(4, $despues === $antes, "Actividades en el panel: {$antes} antes y {$despues} después.");
        },
    ],

    'DI-02' => [
        'nombre' => 'La fecha de inicio debe estar al menos una hora en el futuro',
        'pasos'  => 3,
        'correr' => function (Corrida $c, Prueba $p): void {
            $coordinador = $c->sesion(Corrida::COORDINADOR);
            $mensaje = 'La actividad debe empezar al menos dentro de una hora.';

            // 1 y 2. Dentro de 30 minutos y ayer.
            foreach ([1 => 30 * 60, 2 => -86400] as $paso => $segundos) {
                $id = $c->enviarActividad($c->camposActividad('DI-02', ['fechaInicio' => $c->fecha($segundos)]));
                $p->paso($paso, $id === null && $coordinador->codigoInicial === 422 && ($coordinador->errores()['fechaInicio'] ?? '') === $mensaje,
                    'Inicio ' . $c->fecha($segundos) . ': ' . Corrida::resumen($coordinador));
            }

            // 3. Dentro de 2 horas.
            $id = $c->enviarActividad($c->camposActividad('DI-02', ['fechaInicio' => $c->fecha(2 * 3600)]));
            $p->paso(3, $id !== null && in_array(Corrida::PUBLICADA, $coordinador->avisos(), true), 'Inicio ' . $c->fecha(2 * 3600) . ': ' . Corrida::resumen($coordinador));
            $c->datos['sinImagenes'] = $id;
        },
    ],

    'DI-03' => [
        'nombre' => 'Categoría, provincia y números se validan aunque se manipule el formulario',
        'pasos'  => 3,
        'correr' => function (Corrida $c, Prueba $p): void {
            $coordinador = $c->sesion(Corrida::COORDINADOR);
            $casos = [
                1 => [['categoria' => 'piratas', 'provincia' => 'Narnia'], ['categoria' => 'Elija una categoría.', 'provincia' => 'Elija una provincia.']],
                2 => [['titulo' => str_repeat('a', 121)], ['titulo' => 'Escriba un título de 5 a 120 caracteres.']],
                3 => [['cupoTotal' => 'abc'], ['cupoTotal' => 'El cupo debe ser un número entre 1 y 500.']],
            ];
            foreach ($casos as $paso => [$cambios, $errores]) {
                $id = $c->enviarActividad($c->camposActividad('DI-03', $cambios));
                $p->paso($paso, $id === null && $coordinador->codigoInicial === 422 && Corrida::tieneErrores($coordinador, $errores),
                    Corrida::resumen($coordinador) . ($id === null ? '; no se creó la actividad.' : '; la actividad se creó.'));
            }
            $p->nota('Los valores se enviaron directo por HTTP en lugar de cambiarlos con F12: el servidor recibe lo mismo.');
        },
    ],

    'DI-04' => [
        'nombre' => 'El cupo no puede bajar de los lugares ya ocupados',
        'pasos'  => 2,
        'correr' => function (Corrida $c, Prueba $p): void {
            $coordinador = $c->sesion(Corrida::COORDINADOR);
            $titulo = $c->titulo('DI-04');
            $id = $c->crearActividad('DI-04', ['cupoTotal' => '10']);
            foreach ([Corrida::VOLUNTARIO1, Corrida::VOLUNTARIO3] as $correo) {
                $aviso = $c->reservar($c->sesion($correo), $id);
                if (!str_starts_with($aviso, 'Cupo reservado.')) {
                    throw new RuntimeException("{$correo} no pudo reservar en «{$titulo}»: «{$aviso}»");
                }
            }
            $c->datos['conReservas'] = $id;
            $p->nota('Se usó una actividad creada por el script con 2 reservas (voluntario1 y voluntario3) en lugar de una de los datos de prueba.');

            // 1. Cupo 1 con 2 ocupados.
            $c->editarActividad($id, ['cupoTotal' => '1']);
            $p->paso(1, $coordinador->codigoInicial === 422
                && ($coordinador->errores()['cupoTotal'] ?? '') === 'Ya hay 2 cupos ocupados; el cupo total no puede ser menor.',
                Corrida::resumen($coordinador));

            // 2. El cupo no cambió.
            $coordinador->get('/panel');
            $libres = Corrida::filaPanel($coordinador, $titulo)[5] ?? 'la actividad no aparece';
            $p->paso(2, $libres === '8 de 10', "Cupos libres en el panel: «{$libres}».");
        },
    ],

    'DI-05' => [
        'nombre' => 'Solo se aceptan imágenes reales, de hasta 2 MB y hasta 6 por actividad',
        'pasos'  => 4,
        'correr' => function (Corrida $c, Prueba $p): void {
            $coordinador = $c->sesion(Corrida::COORDINADOR);
            $id = $c->datos['sinImagenes'] ?? $c->crearActividad('DI-05');
            $subir = function (array $archivos) use ($coordinador, $id): int {
                $coordinador->get("/panel/actividades/{$id}/editar");
                $coordinador->post("/panel/actividades/{$id}/imagenes", [], $archivos);
                return preg_match_all('#<img src="[^"]*/uploads/actividades/' . $id . '/[a-f0-9]{16}\.(?:jpg|png|webp)"#', $coordinador->html);
            };

            // 1. Un texto con extensión .jpg.
            $imagenes = $subir([Corrida::archivo('foto.jpg', "Esto es un archivo de texto, no una imagen.\n", 'image/jpeg')]);
            $p->paso(1, in_array('«foto.jpg» no es una imagen JPG, PNG o WebP.', $coordinador->avisos(), true) && $imagenes === 0,
                Corrida::resumen($coordinador) . "; imágenes en la actividad: {$imagenes}.");

            // 2. PHP con extensión .png.
            $imagenes = $subir([Corrida::archivo('prueba.png', "<?php phpinfo();\n")]);
            $p->paso(2, in_array('«prueba.png» no es una imagen JPG, PNG o WebP.', $coordinador->avisos(), true) && $imagenes === 0,
                Corrida::resumen($coordinador) . "; imágenes en la actividad: {$imagenes}.");

            // 3. Una imagen real de más de 2 MB.
            $grande = Corrida::imagenPng(900, 900, true);
            $imagenes = $subir([Corrida::archivo('grande.png', $grande)]);
            $p->paso(3, in_array('«grande.png» pesa más de 2 MB.', $coordinador->avisos(), true) && $imagenes === 0,
                'PNG de 900 × 900 que pesa ' . round(strlen($grande) / 1048576, 2) . ' MB: ' . Corrida::resumen($coordinador) . "; imágenes en la actividad: {$imagenes}.");

            // 4. Siete imágenes válidas a la vez.
            $imagenes = $subir(array_map(fn(int $i) => Corrida::archivo("imagen-{$i}.png", Corrida::imagenPng(40 + $i, 30)), range(1, 7)));
            $avisos = $coordinador->avisos();
            $p->paso(4, in_array('Cada actividad admite hasta 6 imágenes; solo se procesarán 6.', $avisos, true)
                && in_array('6 imágenes agregadas.', $avisos, true) && $imagenes === 6,
                Corrida::resumen($coordinador) . "; imágenes en la actividad: {$imagenes}.");
        },
    ],

    'DI-06' => [
        'nombre' => 'El registro rechaza datos inválidos y correos repetidos',
        'pasos'  => 3,
        'correr' => function (Corrida $c, Prueba $p): void {
            $n = $c->navegador();
            $registrar = function (array $campos) use ($n): void {
                $n->get('/registro');
                $n->post('/registro', $campos + ['provincia' => '']);
            };

            // 1. Nombre, correo y contraseña inválidos.
            $registrar(['nombre' => 'Al', 'correo' => 'no-es-correo', 'clave' => 'corta', 'clave2' => 'corta']);
            $p->paso(1, $n->codigoInicial === 422 && Corrida::tieneErrores($n, [
                'nombre' => 'Escriba el nombre (3 a 80 caracteres).',
                'correo' => 'Escriba un correo válido, por ejemplo nombre@correo.com.',
                'clave'  => 'La contraseña necesita al menos 8 caracteres.',
            ]), Corrida::resumen($n));

            // 2. Datos válidos con la confirmación distinta.
            $correo = 'prueba-di06-' . str_replace('-', '', $c->id) . '@voluntacr.test';
            $registrar(['nombre' => 'Persona de prueba', 'correo' => $correo, 'clave' => 'Prueba2026', 'clave2' => 'Prueba2027']);
            $p->paso(2, $n->codigoInicial === 422 && ($n->errores()['clave2'] ?? '') === 'Las contraseñas no coinciden.', Corrida::resumen($n));

            // 3. Un correo que ya tiene cuenta.
            $registrar(['nombre' => 'Persona de prueba', 'correo' => Corrida::VOLUNTARIO1, 'clave' => 'Prueba2026', 'clave2' => 'Prueba2026']);
            $p->paso(3, $n->codigoInicial === 422 && ($n->errores()['correo'] ?? '') === 'Ya existe una cuenta con ese correo. Inicie sesión.', Corrida::resumen($n));
        },
    ],

    'DI-07' => [
        'nombre' => 'Inicio de sesión: datos incorrectos, cuenta desactivada y límite de intentos',
        'pasos'  => 5,
        'correr' => function (Corrida $c, Prueba $p): void {
            $n = $c->navegador();
            $intentar = function (string $correo, string $clave) use ($n): string {
                $n->get('/ingresar');
                $n->post('/ingresar', ['correo' => $correo, 'clave' => $clave]);
                return Navegador::limpiar((string) $n->buscar('#<p class="aviso aviso--error" role="alert">(.*?)</p>#s'));
            };
            $noCoinciden = 'El correo o la contraseña no coinciden.';

            // 1. Contraseña incorrecta.
            $error = $intentar(Corrida::VOLUNTARIO2, 'Incorrecta-1');
            $p->paso(1, $n->codigoInicial === 401 && $error === $noCoinciden, "código {$n->codigoInicial}; «{$error}»");

            // 2. Un correo que no existe.
            $error = $intentar('nadie@voluntacr.test', 'Incorrecta-1');
            $p->paso(2, $n->codigoInicial === 401 && $error === $noCoinciden, "código {$n->codigoInicial}; «{$error}»");

            // 3. Cuenta desactivada con su contraseña correcta.
            $error = $intentar(Corrida::DESACTIVADO, Corrida::CLAVE_VOLUNTARIO);
            $p->paso(3, $error === 'Esta cuenta está desactivada. Si cree que es un error, comuníquese con la administración.',
                "código {$n->codigoInicial}; «{$error}»");

            // 4. Cuatro fallos más (5 en total) y después la contraseña correcta.
            $fallos = [];
            for ($i = 2; $i <= 5; $i++) {
                $fallos[] = $intentar(Corrida::VOLUNTARIO2, "Incorrecta-{$i}") === $noCoinciden;
            }
            $error = $intentar(Corrida::VOLUNTARIO2, Corrida::CLAVE_VOLUNTARIO);
            $p->paso(4, !in_array(false, $fallos, true) && $error === 'Demasiados intentos fallidos con este correo. Espere 15 minutos e inténtelo de nuevo.',
                'Intentos 2 a 5: ' . (in_array(false, $fallos, true) ? 'alguno no dijo que no coinciden' : '«no coinciden»')
                . "; con la contraseña correcta: código {$n->codigoInicial}, «{$error}»");

            // 5. Otro correo entra sin problema.
            $intentar(Corrida::VOLUNTARIO3, Corrida::CLAVE_VOLUNTARIO);
            $hola = array_filter($n->avisos(), fn(string $a) => str_starts_with($a, 'Hola, '));
            $p->paso(5, $n->codigoInicial === 303 && $hola, Corrida::resumen($n) . ($hola ? '; entró.' : '; no entró.'));

            // Para no dejar a voluntario2 bloqueado 15 minutos.
            if ($c->sinRedis() === null) {
                $rs = $c->redis();
                $rs->usar(fn(Redis $r) => $r->del($rs->k('intentos:login:' . md5(Corrida::VOLUNTARIO2)), $rs->k('intentos:login:' . md5('nadie@voluntacr.test'))));
                $p->nota('Al terminar se borró en Redis el bloqueo de voluntario2, para que la cuenta se pueda usar enseguida.');
            } else {
                $p->nota('voluntario2@voluntacr.test queda bloqueado 15 minutos: sin acceso a Redis desde aquí no se puede quitar el bloqueo.');
            }
        },
    ],

    'DI-08' => [
        'nombre' => 'Direcciones y formularios manipulados: identificadores inválidos, otra organización y CSRF',
        'pasos'  => 4,
        'correr' => function (Corrida $c, Prueba $p): void {
            // 1. Identificador inválido en el portal.
            $anonimo = $c->navegador();
            $anonimo->get('/actividades/123');
            $p->paso(1, $anonimo->codigo === 404 && $anonimo->mensajeError() === 'Esa actividad no existe o ya no está publicada.', Corrida::resumen($anonimo));

            // 2. Identificador inválido en el panel.
            $coordinador = $c->sesion(Corrida::COORDINADOR);
            $coordinador->get('/panel/actividades/abc/editar');
            $p->paso(2, $coordinador->codigo === 404 && $coordinador->mensajeError() === 'Esa actividad no existe.', Corrida::resumen($coordinador));

            // 3. Editar la actividad de otra organización.
            $ajena = $c->actividadDeOtraOrganizacion();
            if ($ajena === null) {
                $p->omitir(3, 'no hay actividades de otra organización en el portal.');
            } else {
                $coordinador->get("/panel/actividades/{$ajena}/editar");
                $p->paso(3, $coordinador->codigo === 403 && $coordinador->mensajeError() === 'Solo la organización que publicó la actividad puede modificarla.',
                    Corrida::resumen($coordinador) . " (actividad {$ajena}).");
            }

            // 4. Guardar una actividad propia sin el campo _csrf.
            $id = $c->datos['conReservas'] ?? $c->crearActividad('DI-08');
            $titulo = $c->actividades[$id];
            $c->editarActividad($id, ['titulo' => $titulo . ' editado'], false);
            $resultado = Corrida::resumen($coordinador);
            $rechazado = $coordinador->codigo === 419 && $coordinador->mensajeError() === 'El formulario expiró. Recargue la página y vuelva a intentarlo.';
            $coordinador->get("/panel/actividades/{$id}/editar");
            $igual = str_contains($coordinador->html, 'name="titulo" value="' . htmlspecialchars($titulo) . '"');
            $p->paso(4, $rechazado && $igual, $resultado . ($igual ? '; el título no cambió.' : '; el título cambió.'));
        },
    ],

    'DI-09' => [
        'nombre' => 'MongoDB rechaza con JSON Schema los datos inválidos aunque no pasen por la aplicación',
        'pasos'  => 6,
        'correr' => function (Corrida $c, Prueba $p) use ($rechazo): void {
            $bd = $c->bd();

            // 1. Campos obligatorios del validador de actividades.
            $info = $bd->comandoLista(['listCollections' => 1, 'filter' => ['name' => 'actividades']]);
            $obligatorios = $info[0]['options']['validator']['$jsonSchema']['required'] ?? [];
            $p->paso(1, in_array('cuposDisponibles', $obligatorios, true), 'required: ' . implode(', ', $obligatorios) . '.');

            // Solo se escribe sobre documentos de esta corrida, nunca sobre los de otra persona.
            if (!isset($c->datos['conReservas'])) {
                $c->datos['conReservas'] = $c->crearActividad('DI-09');
                $c->reservar($c->sesion(Corrida::VOLUNTARIO1), $c->datos['conReservas']);
            }
            $actividad = new ObjectId($c->datos['conReservas']);
            $antes = $bd->buscarUno('actividades', ['_id' => $actividad]);
            $restaurar = fn() => $bd->actualizar('actividades', ['_id' => $actividad], ['$set' => ['cuposDisponibles' => $antes['cuposDisponibles']]]);
            $libres = fn() => $bd->buscarUno('actividades', ['_id' => $actividad])['cuposDisponibles'];

            // 2. Cupos negativos.
            [$codigo, $mensaje] = Corrida::escrituraRechazada(
                fn() => $bd->actualizar('actividades', ['_id' => $actividad, 'estado' => 'publicada'], ['$set' => ['cuposDisponibles' => -1]]), $restaurar);
            $p->paso(2, $codigo === 121 && $libres() === $antes['cuposDisponibles'], $rechazo($codigo, $mensaje) . " cuposDisponibles sigue en {$libres()}.");

            // 3. Más cupos libres que el cupo total (regla $expr).
            [$codigo, $mensaje] = Corrida::escrituraRechazada(
                fn() => $bd->actualizar('actividades', ['_id' => $actividad], [['$set' => ['cuposDisponibles' => ['$add' => ['$cupoTotal', 1]]]]]), $restaurar);
            $p->paso(3, $codigo === 121 && $libres() === $antes['cuposDisponibles'], $rechazo($codigo, $mensaje) . " cuposDisponibles sigue en {$libres()}.");

            // 4. Una actividad sin los campos obligatorios.
            [$codigo, $mensaje] = Corrida::escrituraRechazada(
                fn() => $bd->insertar('actividades', ['titulo' => 'Hola']),
                fn() => $bd->eliminar('actividades', ['titulo' => 'Hola', 'estado' => ['$exists' => false]]));
            $p->paso(4, $codigo === 121, $rechazo($codigo, $mensaje));

            // 5. Un estado de inscripción que no existe.
            $inscripcion = $bd->buscarUno('inscripciones', ['actividadId' => $actividad]) ?? throw new RuntimeException('La actividad de prueba no tiene inscripciones.');
            [$codigo, $mensaje] = Corrida::escrituraRechazada(
                fn() => $bd->actualizar('inscripciones', ['_id' => $inscripcion['_id']], ['$set' => ['estado' => 'inventado']]),
                fn() => $bd->actualizar('inscripciones', ['_id' => $inscripcion['_id']], ['$set' => ['estado' => $inscripcion['estado']]]));
            $p->paso(5, $codigo === 121, $rechazo($codigo, $mensaje));

            // 6. Un correo sin @ (en la cuenta de voluntario1; si se guardara, se devuelve enseguida).
            [$codigo, $mensaje] = Corrida::escrituraRechazada(
                fn() => $bd->actualizar('usuarios', ['correo' => Corrida::VOLUNTARIO1, 'rol' => 'voluntario'], ['$set' => ['correo' => 'sin-arroba']]),
                fn() => $bd->actualizar('usuarios', ['correo' => 'sin-arroba'], ['$set' => ['correo' => Corrida::VOLUNTARIO1]]));
            $p->paso(6, $codigo === 121, $rechazo($codigo, $mensaje));

            $p->nota('Las escrituras se hicieron sobre documentos de esta corrida (la actividad y una inscripción de DI-04) y sobre la cuenta de voluntario1, '
                . 'en lugar del primer documento que encuentre mongosh; si MongoDB hubiera aceptado alguna, se deshacía enseguida.');
        },
    ],
];
