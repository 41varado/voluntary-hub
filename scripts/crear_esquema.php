<?php
/**
 * Crea las colecciones de MongoDB con validación JSON Schema y sus índices.
 * Es el equivalente al "script DDL" de una base relacional. Se puede ejecutar
 * varias veces: si la colección ya existe, actualiza el validador (collMod).
 *
 *   php scripts/crear_esquema.php
 *
 * Responsable: Persona 2 (MongoDB).
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$bd = Mongo::get();
$provincias = Catalogos::PROVINCIAS;
$numero = ['bsonType' => ['int', 'long', 'double', 'decimal']];
$entero = ['bsonType' => ['int', 'long']];
$fechaOpcional = ['bsonType' => ['date', 'null']];

$validadores = [

    'organizaciones' => ['$jsonSchema' => [
        'bsonType' => 'object',
        'required' => ['nombre', 'creadaEn'],
        'properties' => [
            'nombre'      => ['bsonType' => 'string', 'minLength' => 3, 'maxLength' => 120],
            'descripcion' => ['bsonType' => 'string', 'maxLength' => 1000],
            'provincia'   => ['enum' => $provincias],
            'contacto'    => ['bsonType' => 'string', 'maxLength' => 120],
            'creadaEn'    => ['bsonType' => 'date'],
        ],
    ]],

    'usuarios' => ['$jsonSchema' => [
        'bsonType' => 'object',
        'required' => ['nombre', 'correo', 'claveHash', 'rol', 'creadoEn'],
        'properties' => [
            'nombre'       => ['bsonType' => 'string', 'minLength' => 3, 'maxLength' => 80],
            'correo'       => ['bsonType' => 'string', 'pattern' => '^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$', 'maxLength' => 120],
            'claveHash'    => ['bsonType' => 'string', 'minLength' => 20],
            'rol'          => ['enum' => Catalogos::ROLES],
            'provincia'    => ['enum' => array_merge($provincias, [null])],
            'organizacion' => [
                'bsonType'   => ['object', 'null'],
                'required'   => ['id', 'nombre'],
                'properties' => ['id' => ['bsonType' => 'objectId'], 'nombre' => ['bsonType' => 'string']],
            ],
            // Las cuentas no se borran: se desactivan (false). Si falta el campo, la cuenta está activa.
            'activo'       => ['bsonType' => 'bool'],
            'creadoEn'     => ['bsonType' => 'date'],
            'creadoPor'    => ['bsonType' => ['objectId', 'null']],
            'actualizadoEn' => ['bsonType' => 'date'],
            'actualizadoPor' => ['bsonType' => 'objectId'],
            // Las sesiones iniciadas antes de esta fecha se cierran (contraseña restablecida, cuenta desactivada).
            'sesionesValidasDesde' => ['bsonType' => 'date'],
            // Correo confirmado con el enlace de Mailjet (D23). Si falta el campo, la cuenta está confirmada.
            'correoVerificado'   => ['bsonType' => 'bool'],
            'correoVerificadoEn' => ['bsonType' => 'date'],
            // Enlace de confirmación vigente: solo se guarda el SHA-256 del token.
            'verificacion'       => [
                'bsonType'   => ['object', 'null'],
                'required'   => ['tokenHash', 'expiraEn', 'enviadaEn'],
                'properties' => [
                    'tokenHash' => ['bsonType' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
                    'expiraEn'  => ['bsonType' => 'date'],
                    'enviadaEn' => ['bsonType' => 'date'],
                ],
            ],
            // Recibir los avisos también por correo. Si falta el campo, sí.
            'avisosPorCorreo'    => ['bsonType' => 'bool'],
        ],
    ]],

    'actividades' => [
        '$jsonSchema' => [
            'bsonType' => 'object',
            'required' => ['titulo', 'descripcion', 'categoria', 'provincia', 'lugar', 'fechaInicio', 'horas',
                'cupoTotal', 'cuposDisponibles', 'estado', 'organizacion', 'imagenes', 'creadaEn'],
            'properties' => [
                'titulo'           => ['bsonType' => 'string', 'minLength' => 5, 'maxLength' => 120],
                'descripcion'      => ['bsonType' => 'string', 'minLength' => 20, 'maxLength' => 3000],
                'requisitos'       => ['bsonType' => 'string', 'maxLength' => 500],
                'categoria'        => ['enum' => array_keys(Catalogos::CATEGORIAS)],
                'provincia'        => ['enum' => $provincias],
                'lugar'            => ['bsonType' => 'string', 'minLength' => 3, 'maxLength' => 150],
                'fechaInicio'      => ['bsonType' => 'date'],
                'horas'            => $numero + ['minimum' => 0.5, 'maximum' => 24],
                'cupoTotal'        => $entero + ['minimum' => 1, 'maximum' => 500],
                // La base de datos rechaza cualquier escritura que deje cupos negativos.
                'cuposDisponibles' => $entero + ['minimum' => 0, 'maximum' => 500],
                'estado'           => ['enum' => Catalogos::ESTADOS_ACTIVIDAD],
                'organizacion'     => [
                    'bsonType'   => 'object',
                    'required'   => ['id', 'nombre'],
                    'properties' => ['id' => ['bsonType' => 'objectId'], 'nombre' => ['bsonType' => 'string']],
                ],
                'imagenes' => [
                    'bsonType' => 'array',
                    'maxItems' => 6,
                    'items'    => [
                        'bsonType'   => 'object',
                        'required'   => ['archivo', 'tipo', 'bytes'],
                        'properties' => [
                            'archivo' => ['bsonType' => 'string', 'pattern' => '^[a-f0-9]{16}\\.(jpg|png|webp)$'],
                            'tipo'    => ['enum' => ['image/jpeg', 'image/png', 'image/webp']],
                            'bytes'   => $entero + ['minimum' => 1, 'maximum' => 10485760],
                        ],
                    ],
                ],
                'portada'          => ['bsonType' => ['string', 'null']],
                'creadaEn'         => ['bsonType' => 'date'],
            ],
        ],
        // Regla entre dos campos: nunca más cupos libres que el cupo total.
        '$expr' => ['$lte' => ['$cuposDisponibles', '$cupoTotal']],
    ],

    'inscripciones' => ['$jsonSchema' => [
        'bsonType' => 'object',
        'required' => ['actividadId', 'voluntarioId', 'estado', 'creadaEn', 'actividad', 'voluntario'],
        'properties' => [
            'actividadId'  => ['bsonType' => 'objectId'],
            'voluntarioId' => ['bsonType' => 'objectId'],
            'estado'       => ['enum' => Catalogos::ESTADOS_INSCRIPCION],
            'reservadaEn'  => $fechaOpcional,
            'expiraEn'     => $fechaOpcional,
            'confirmadaEn' => $fechaOpcional,
            'canceladaEn'  => $fechaOpcional,
            'expiradaEn'   => $fechaOpcional,
            'actividad'    => ['bsonType' => 'object', 'required' => ['titulo', 'fechaInicio', 'horas']],
            'voluntario'   => ['bsonType' => 'object', 'required' => ['nombre']],
            'asistencia'   => [
                'bsonType'   => ['object', 'null'],
                'properties' => [
                    'presente'      => ['bsonType' => 'bool'],
                    'horas'         => $numero + ['minimum' => 0, 'maximum' => 24],
                    'registradaEn'  => ['bsonType' => 'date'],
                    'registradaPor' => ['bsonType' => 'objectId'],
                ],
            ],
            'creadaEn'     => ['bsonType' => 'date'],
        ],
    ]],

    // Un documento por destinatario (D24): la bandeja de cada persona y su estado de lectura.
    'notificaciones' => ['$jsonSchema' => [
        'bsonType' => 'object',
        'required' => ['usuarioId', 'envioId', 'tipo', 'titulo', 'mensaje', 'leidaEn', 'correo', 'creadaEn'],
        'properties' => [
            'usuarioId'   => ['bsonType' => 'objectId'],
            // Agrupa los documentos de un mismo envío, uno por persona.
            'envioId'     => ['bsonType' => 'objectId'],
            'tipo'        => ['enum' => array_keys(Catalogos::TIPOS_AVISO)],
            'titulo'      => ['bsonType' => 'string', 'minLength' => 3, 'maxLength' => 150],
            'mensaje'     => ['bsonType' => 'string', 'minLength' => 1, 'maxLength' => 2000],
            'actividadId' => ['bsonType' => ['objectId', 'null']],
            'actividad'   => ['bsonType' => ['object', 'null'], 'required' => ['titulo', 'fechaInicio']],
            'enlace'      => ['bsonType' => ['string', 'null'], 'maxLength' => 300],
            // Quién escribió el mensaje; null en los avisos automáticos.
            'remitente'   => [
                'bsonType'   => ['object', 'null'],
                'required'   => ['id', 'nombre'],
                'properties' => [
                    'id'           => ['bsonType' => 'objectId'],
                    'nombre'       => ['bsonType' => 'string'],
                    'organizacion' => ['bsonType' => 'string'],
                ],
            ],
            'leidaEn'     => $fechaOpcional,
            'creadaEn'    => ['bsonType' => 'date'],
            'correo'      => [
                'bsonType'   => 'object',
                'required'   => ['estado', 'intentos'],
                'properties' => [
                    'estado'    => ['enum' => ['pendiente', 'enviado', 'fallido', 'omitido']],
                    'intentos'  => $entero + ['minimum' => 0],
                    'enviadoEn' => $fechaOpcional,
                    'motivo'    => ['bsonType' => ['string', 'null'], 'maxLength' => 300],
                ],
            ],
        ],
    ]],
];

$indices = [
    'organizaciones' => [
        ['key' => ['nombre' => 1], 'name' => 'nombre_unico', 'unique' => true],
    ],
    'usuarios' => [
        ['key' => ['correo' => 1], 'name' => 'correo_unico', 'unique' => true],
        // Pantallas de administración: cuentas por rol, ordenadas por nombre.
        ['key' => ['rol' => 1, 'nombre' => 1], 'name' => 'admin_por_rol'],
    ],
    'actividades' => [
        // Portal: actividades publicadas ordenadas por fecha, con o sin filtros.
        ['key' => ['estado' => 1, 'fechaInicio' => 1], 'name' => 'portal_por_fecha'],
        ['key' => ['estado' => 1, 'categoria' => 1, 'provincia' => 1, 'fechaInicio' => 1], 'name' => 'portal_con_filtros'],
        // Panel del coordinador: actividades de su organización.
        ['key' => ['organizacion.id' => 1, 'fechaInicio' => -1], 'name' => 'panel_organizacion'],
    ],
    'inscripciones' => [
        // Una sola inscripción por persona y actividad (se reutiliza si la cancela y vuelve).
        ['key' => ['actividadId' => 1, 'voluntarioId' => 1], 'name' => 'una_por_persona', 'unique' => true],
        // Liberar reservas vencidas.
        ['key' => ['estado' => 1, 'expiraEn' => 1], 'name' => 'reservas_vencidas'],
        // Lista de inscritos y asistencia de una actividad.
        ['key' => ['actividadId' => 1, 'estado' => 1], 'name' => 'inscritos_actividad'],
        // Historial y horas de un voluntario.
        ['key' => ['voluntarioId' => 1, 'actividad.fechaInicio' => -1], 'name' => 'historial_voluntario'],
    ],
    'notificaciones' => [
        // Bandeja de cada persona, del aviso más reciente al más antiguo.
        ['key' => ['usuarioId' => 1, 'creadaEn' => -1], 'name' => 'bandeja_usuario'],
        // Contador de avisos sin leer de la cabecera.
        ['key' => ['usuarioId' => 1, 'leidaEn' => 1], 'name' => 'sin_leer'],
        // Historial de avisos de una actividad, en el panel del coordinador.
        ['key' => ['actividadId' => 1, 'creadaEn' => -1], 'name' => 'avisos_actividad'],
        // Correos por reintentar (scripts/enviar_correos.php) y conteos de /estado.
        ['key' => ['correo.estado' => 1, 'creadaEn' => 1], 'name' => 'correos_pendientes'],
    ],
];

echo "Base de datos: {$bd->nombreBaseDatos()}\n\n";

$existentes = array_column($bd->comandoLista(['listCollections' => 1, 'nameOnly' => true]), 'name');

foreach ($validadores as $coleccion => $validador) {
    $opciones = ['validator' => $validador, 'validationLevel' => 'strict', 'validationAction' => 'error'];
    try {
        if (in_array($coleccion, $existentes, true)) {
            $bd->comando(['collMod' => $coleccion] + $opciones);
            echo "  ✔ {$coleccion}: validador JSON Schema actualizado\n";
        } else {
            $bd->comando(['create' => $coleccion] + $opciones);
            echo "  ✔ {$coleccion}: creada con validador JSON Schema\n";
        }
    } catch (Throwable $error) {
        // Por ejemplo, un servidor compatible que no implementa validadores.
        echo "  ! {$coleccion}: no se pudo aplicar el validador (" . mb_strimwidth($error->getMessage(), 0, 110, '…') . ")\n";
        if (!in_array($coleccion, $existentes, true)) {
            $bd->comando(['create' => $coleccion]);
            echo "    {$coleccion}: creada sin validador\n";
        }
    }
}

echo "\n";
foreach ($indices as $coleccion => $lista) {
    // Solo se crean los índices que todavía no existen (el script se puede ejecutar varias veces).
    $existentesIdx = array_column($bd->comandoLista(['listIndexes' => $coleccion]), 'name');
    $nuevos = array_values(array_filter($lista, fn($i) => !in_array($i['name'], $existentesIdx, true)));
    if ($nuevos) {
        $bd->comando(['createIndexes' => $coleccion, 'indexes' => $nuevos]);
    }
    echo "  ✔ {$coleccion}: " . implode(', ', array_column($lista, 'name'))
        . ($nuevos ? '' : ' (ya existían)') . "\n";
}

echo "\nEsquema listo.\n";
