<?php
/**
 * Genera datos de prueba sintéticos y reproducibles (misma semilla = mismos datos).
 * Nunca usa datos personales reales: los nombres se combinan al azar y los correos usan
 * el dominio reservado .test.
 *
 *   php scripts/cargar_datos.php --limpiar
 *   php scripts/cargar_datos.php --limpiar --voluntarios=200 --semilla=7
 *
 * --limpiar  borra los documentos de las 5 colecciones, las imágenes subidas y las claves
 *            de Redis con el prefijo del grupo (con SCAN; nunca FLUSHDB ni FLUSHALL).
 *
 * Todas las cuentas quedan con el correo confirmado. Las direcciones .test no reciben correos.
 *
 * Responsable: Persona 4.
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$opciones = getopt('', ['limpiar', 'voluntarios::', 'semilla::', 'por-organizacion::']);
$cantidadVoluntarios = max(10, (int) ($opciones['voluntarios'] ?? 60));
$porOrganizacion = max(2, (int) ($opciones['por-organizacion'] ?? 7));
$semilla = (int) ($opciones['semilla'] ?? 2026);
mt_srand($semilla);

$bd = Mongo::get();
$leer = fn(string $archivo) => json_decode((string) file_get_contents(RAIZ . '/data/' . $archivo), true, 512, JSON_THROW_ON_ERROR);
$cantones = $leer('cantones_cr.json');
$nombres = $leer('nombres_sinteticos.json');
$organizacionesBase = $leer('organizaciones_ficticias.json');
$plantillas = $leer('plantillas_actividades.json');
$azar = fn(array $lista) => $lista[mt_rand(0, count($lista) - 1)];
$ahora = time();

/* ---------- Limpieza ---------- */

if (isset($opciones['limpiar'])) {
    foreach (['notificaciones', 'inscripciones', 'actividades', 'usuarios', 'organizaciones'] as $coleccion) {
        $borrados = $bd->eliminar($coleccion, [], true);
        echo "  - {$coleccion}: {$borrados} documentos borrados\n";
    }
    foreach (glob(RAIZ . '/public/uploads/actividades/*', GLOB_ONLYDIR) as $carpeta) {
        array_map('unlink', glob($carpeta . '/*'));
        @rmdir($carpeta);
    }
    $rs = RedisSeguro::get();
    $claves = $rs->usar(function (Redis $r) use ($rs): int {
        $total = 0;
        $cursor = null;
        do {
            $lote = $r->scan($cursor, $rs->k('*'), 500);
            if (is_array($lote) && $lote) {
                $total += (int) $r->del($lote);
            }
        } while ($cursor > 0);
        return $total;
    }, 0);
    echo "  - Redis: {$claves} claves con el prefijo {$rs->prefijo()} borradas\n\n";
}

/* ---------- Organizaciones y coordinadores ---------- */

$claveVoluntario = password_hash('Voluntario2026', PASSWORD_DEFAULT);
$claveCoordinador = password_hash('Coordina2026', PASSWORD_DEFAULT);
$claveAdmin = password_hash('Admin2026', PASSWORD_DEFAULT);
$nombrePersona = fn() => $azar($nombres['nombres']) . ' ' . $azar($nombres['apellidos']) . ' ' . $azar($nombres['apellidos']);

$organizaciones = [];
$usuarios = [];
$administrador = [
    '_id'              => new MongoDB\BSON\ObjectId(),
    'nombre'           => $nombrePersona(),
    'correo'           => 'admin@voluntacr.test',
    'claveHash'        => $claveAdmin,
    'rol'              => 'admin',
    'provincia'        => null,
    'organizacion'     => null,
    'activo'           => true,
    'correoVerificado' => true,
    'creadoEn'         => Mongo::fecha($ahora - 120 * 86400),
    'creadoPor'        => null,
];
foreach ($organizacionesBase as $n => $base) {
    $id = new MongoDB\BSON\ObjectId();
    $organizaciones[] = [
        '_id'         => $id,
        'nombre'      => $base['nombre'],
        'descripcion' => $base['descripcion'],
        'provincia'   => $base['provincia'],
        'contacto'    => 'contacto' . ($n + 1) . '@voluntacr.test',
        'creadaEn'    => Mongo::fecha($ahora - 90 * 86400),
        'categorias'  => $base['categorias'],
    ];
    $usuarios[] = [
        '_id'              => new MongoDB\BSON\ObjectId(),
        'nombre'           => $nombrePersona(),
        'correo'           => 'coordinador' . ($n + 1) . '@voluntacr.test',
        'claveHash'        => $claveCoordinador,
        'rol'              => 'coordinador',
        'provincia'        => $base['provincia'],
        'organizacion'     => ['id' => $id, 'nombre' => $base['nombre']],
        'activo'           => true,
        'correoVerificado' => true,
        'creadoEn'         => Mongo::fecha($ahora - 90 * 86400),
        'creadoPor'        => $administrador['_id'],
    ];
}

$voluntarios = [];
for ($n = 1; $n <= $cantidadVoluntarios; $n++) {
    $voluntario = [
        '_id'              => new MongoDB\BSON\ObjectId(),
        'nombre'           => $nombrePersona(),
        'correo'           => 'voluntario' . $n . '@voluntacr.test',
        'claveHash'        => $claveVoluntario,
        'rol'              => 'voluntario',
        'provincia'        => $azar(Catalogos::PROVINCIAS),
        'organizacion'     => null,
        // Una cuenta desactivada para la demo de administración.
        'activo'           => $n !== $cantidadVoluntarios,
        'correoVerificado' => true,
        'creadoEn'         => Mongo::fecha($ahora - mt_rand(1, 80) * 86400),
        'creadoPor'        => null,
    ];
    $voluntarios[] = $voluntario;
    $usuarios[] = $voluntario;
}

/* ---------- Actividades e inscripciones ---------- */

$actividades = [];
$inscripciones = [];
$cupos = [10, 12, 15, 20, 20, 25, 30, 40];

foreach ($organizaciones as $o => $organizacion) {
    for ($k = 0; $k < $porOrganizacion; $k++) {
        $categoria = $azar($organizacion['categorias']);
        $plantilla = $azar($plantillas[$categoria]);
        $canton = $azar($cantones[$organizacion['provincia']]);

        // Distribución: ~25 % ya pasaron, una es hoy, el resto en las próximas 6 semanas.
        $dias = match (true) {
            $k < 2   => -mt_rand(2, 30),
            $k === 2 && $o === 0 => 0,
            default  => mt_rand(1, 42),
        };
        $inicioActividad = (new DateTimeImmutable('today'))->modify("{$dias} days")->setTime($azar([7, 8, 9, 13, 14]), $azar([0, 30]));
        if ($dias === 0) {
            $inicioActividad = (new DateTimeImmutable())->modify('+90 minutes')->setTime((int) date('G', $ahora + 5400), 0);
        }
        $cupoTotal = $azar($cupos);
        $estado = ($k === $porOrganizacion - 1) ? 'borrador' : 'publicada';

        $actividad = [
            '_id'              => new MongoDB\BSON\ObjectId(),
            'titulo'           => str_replace('{canton}', $canton, $plantilla['titulo']),
            'descripcion'      => $plantilla['descripcion'],
            'requisitos'       => $plantilla['requisitos'],
            'categoria'        => $categoria,
            'provincia'        => $organizacion['provincia'],
            'lugar'            => str_replace('{canton}', $canton, $plantilla['lugar']),
            'fechaInicio'      => Mongo::fecha($inicioActividad),
            'horas'            => (float) $plantilla['horas'],
            'cupoTotal'        => $cupoTotal,
            'cuposDisponibles' => $cupoTotal,
            'estado'           => $estado,
            'organizacion'     => ['id' => $organizacion['_id'], 'nombre' => $organizacion['nombre']],
            'coordinadorId'    => $usuarios[$o]['_id'],
            'imagenes'         => [],
            'portada'          => null,
            'creadaEn'         => Mongo::fecha($ahora - 40 * 86400),
            'actualizadaEn'    => Mongo::fecha($ahora - 40 * 86400),
        ];

        // Cuántas personas se inscriben: algunas actividades quedan llenas o casi llenas para la demo.
        $inscritos = 0;
        if ($estado === 'publicada') {
            $inscritos = match (true) {
                $k === 3 && $o % 2 === 0 => $cupoTotal,       // llena
                $k === 4 && $o % 2 === 1 => $cupoTotal - 1,   // queda un cupo
                default                  => mt_rand(0, (int) ceil($cupoTotal * 0.7)),
            };
        }
        $yaPaso = $inicioActividad->getTimestamp() < $ahora;
        $elegidos = (array) array_rand($voluntarios, max(1, min($inscritos, count($voluntarios))));

        foreach (array_slice($elegidos, 0, $inscritos) as $indice) {
            $voluntario = $voluntarios[$indice];
            $reservada = Mongo::fecha($inicioActividad->getTimestamp() - mt_rand(2, 20) * 86400);
            $pendiente = !$yaPaso && mt_rand(1, 100) <= 12;
            $presente = $yaPaso && mt_rand(1, 100) <= 80;
            $inscripciones[] = [
                '_id'          => new MongoDB\BSON\ObjectId(),
                'actividadId'  => $actividad['_id'],
                'voluntarioId' => $voluntario['_id'],
                'estado'       => $pendiente ? 'reservada' : 'confirmada',
                'reservadaEn'  => $pendiente ? Mongo::ahora() : $reservada,
                'expiraEn'     => $pendiente ? Mongo::fecha($ahora + mt_rand(3, 10) * 60) : null,
                'confirmadaEn' => $pendiente ? null : $reservada,
                'canceladaEn'  => null,
                'expiradaEn'   => null,
                'asistencia'   => $yaPaso ? [
                    'presente'      => $presente,
                    'horas'         => $presente ? $actividad['horas'] : 0,
                    'registradaEn'  => Mongo::fecha($inicioActividad->getTimestamp() + 4 * 3600),
                    'registradaPor' => $usuarios[$o]['_id'],
                ] : null,
                'actividad'    => [
                    'titulo'       => $actividad['titulo'],
                    'fechaInicio'  => $actividad['fechaInicio'],
                    'horas'        => $actividad['horas'],
                    'categoria'    => $categoria,
                    'lugar'        => $actividad['lugar'],
                    'provincia'    => $actividad['provincia'],
                    'organizacion' => $organizacion['nombre'],
                ],
                'voluntario'   => ['nombre' => $voluntario['nombre'], 'correo' => $voluntario['correo']],
                'creadaEn'     => $reservada,
            ];
            $actividad['cuposDisponibles']--;
        }
        $actividades[] = $actividad;
    }
}

foreach ($organizaciones as &$organizacion) {
    unset($organizacion['categorias']);
}
unset($organizacion);

/* ---------- Avisos de ejemplo ---------- */

// En las dos primeras organizaciones, un mensaje del coordinador a los inscritos de su próxima
// actividad, para que la bandeja y el historial no empiecen vacíos. No usa números al azar, así los
// datos anteriores siguen siendo los mismos con cada semilla.
$notificaciones = [];
foreach (array_slice($organizaciones, 0, 2) as $o => $organizacion) {
    $proximas = array_filter($actividades, fn(array $a) => (string) $a['organizacion']['id'] === (string) $organizacion['_id']
        && $a['estado'] === 'publicada' && $a['fechaInicio']->toDateTime()->getTimestamp() > $ahora + 86400);
    usort($proximas, fn(array $a, array $b) => (int) (string) $a['fechaInicio'] <=> (int) (string) $b['fechaInicio']);
    foreach ($proximas as $actividad) {
        $inscritos = array_values(array_filter($inscripciones, fn(array $i) => (string) $i['actividadId'] === (string) $actividad['_id']
            && in_array($i['estado'], ['reservada', 'confirmada'], true)));
        if (!$inscritos) {
            continue;
        }
        $envioId = new MongoDB\BSON\ObjectId();
        foreach ($inscritos as $k => $inscripcion) {
            $notificaciones[] = [
                'usuarioId'   => $inscripcion['voluntarioId'],
                'envioId'     => $envioId,
                'tipo'        => 'mensaje',
                'titulo'      => 'Punto de encuentro y qué llevar',
                'mensaje'     => 'Nos vemos el ' . fecha_cr($actividad['fechaInicio'], 'dia') . ' en ' . $actividad['lugar']
                    . ". Lleguen 15 minutos antes para organizar los grupos.\n\nTraigan agua y ropa cómoda. Si ya no puede venir, "
                    . 'cancele su inscripción para que otra persona aproveche el cupo.',
                'actividadId' => $actividad['_id'],
                'actividad'   => ['titulo' => $actividad['titulo'], 'fechaInicio' => $actividad['fechaInicio']],
                'enlace'      => '/actividades/' . $actividad['_id'],
                'remitente'   => ['id' => $usuarios[$o]['_id'], 'nombre' => $usuarios[$o]['nombre'], 'organizacion' => $organizacion['nombre']],
                // La mitad ya lo leyó, para que el historial muestre lecturas.
                'leidaEn'     => $k % 2 === 0 ? Mongo::fecha($ahora - 86400) : null,
                'creadaEn'    => Mongo::fecha($ahora - 2 * 86400),
                'correo'      => ['estado' => 'omitido', 'intentos' => 0, 'enviadoEn' => null, 'motivo' => 'dominio_reservado'],
            ];
        }
        break;
    }
}

$usuarios[] = $administrador;

$insertados = [
    'organizaciones' => $bd->insertarVarios('organizaciones', $organizaciones),
    'usuarios'       => $bd->insertarVarios('usuarios', $usuarios),
    'actividades'    => $bd->insertarVarios('actividades', $actividades),
    'inscripciones'  => $bd->insertarVarios('inscripciones', $inscripciones),
    'notificaciones' => $bd->insertarVarios('notificaciones', $notificaciones),
];

// El listado en caché ya no corresponde a los datos nuevos.
RedisSeguro::get()->usar(fn(Redis $r) => $r->incr(RedisSeguro::get()->k('cache:actividades:version')));

foreach ($insertados as $coleccion => $cantidad) {
    echo "  ✔ {$coleccion}: {$cantidad}\n";
}
echo "\nCuentas de prueba (todas sintéticas):\n";
echo "  Administración: admin@voluntacr.test  clave: Admin2026\n";
echo "  Coordinación: coordinador1@voluntacr.test … coordinador" . count($organizaciones) . "@voluntacr.test  clave: Coordina2026\n";
echo "  Voluntarios:  voluntario1@voluntacr.test … voluntario{$cantidadVoluntarios}@voluntacr.test  clave: Voluntario2026\n";
echo "  (voluntario{$cantidadVoluntarios} está desactivada para la demo de administración)\n";
echo "  Todas tienen el correo confirmado; las direcciones .test no reciben correos.\n";
echo "  Semilla: {$semilla}\n";
