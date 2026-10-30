/**
 * Crea las colecciones de MongoDB con validación JSON Schema y sus índices, con mongosh.
 * Es el mismo esquema de scripts/crear_esquema.php, para crear o revisar la base sin PHP (por
 * ejemplo, desde el servidor o contra Atlas). Si cambia uno, cambie también el otro (D26).
 * Se puede ejecutar varias veces: si la colección ya existe, actualiza el validador (collMod).
 *
 *   mongosh --nodb --quiet scripts/mongosh/crear_esquema.js
 *
 * La URI se lee igual que en la aplicación: primero la variable de entorno MONGO_URI y, si no está,
 * el .env de la raíz del proyecto (también MONGO_DB y MONGO_TIMEOUT_MS). Con --nodb, mongosh no se
 * conecta solo: el script abre la conexión, así la URI y su contraseña nunca se escriben en la línea
 * de comandos, en el historial de la terminal ni en el repositorio.
 *
 * Responsable: Persona 2 (MongoDB).
 */
'use strict';

const fs = require('fs');
const path = require('path');

/* ---------- Configuración: como Config::cargar y Config::get ---------- */

// La raíz del proyecto, a dos carpetas de este archivo. Sin __dirname, la carpeta actual.
const raiz = typeof __dirname === 'string' ? path.resolve(__dirname, '..', '..') : process.cwd();

function leerEnv(archivo) {
    const valores = {};
    if (!fs.existsSync(archivo)) {
        return valores;
    }
    for (const lineaOriginal of fs.readFileSync(archivo, 'utf8').split('\n')) {
        const linea = lineaOriginal.trim();
        if (linea === '' || linea.startsWith('#') || !linea.includes('=')) {
            continue;
        }
        const igual = linea.indexOf('=');
        const clave = linea.slice(0, igual).trim();
        let valor = linea.slice(igual + 1).trim();
        if (valor.length >= 2 && (valor[0] === '"' || valor[0] === "'") && valor[valor.length - 1] === valor[0]) {
            valor = valor.slice(1, -1);
        }
        valores[clave] = valor;
    }
    return valores;
}

const valoresEnv = leerEnv(path.join(raiz, '.env'));

// Las variables del sistema tienen prioridad sobre el .env, igual que en la aplicación.
function config(clave, porDefecto) {
    const entorno = process.env[clave];
    if (entorno !== undefined && entorno !== '') {
        return entorno;
    }
    const valor = valoresEnv[clave];
    return (valor === undefined || valor === '') ? porDefecto : valor;
}

// Agrega a la URI las opciones que no traiga, con los mismos valores que src/Nucleo/Mongo.php.
function conOpciones(uri, opciones) {
    let resultado = uri;
    for (const [nombre, valor] of Object.entries(opciones)) {
        if (new RegExp('[?&]' + nombre + '=', 'i').test(resultado)) {
            continue;
        }
        const separador = resultado.includes('?') ? '&' : (resultado.split('://')[1].includes('/') ? '?' : '/?');
        resultado += separador + nombre + '=' + encodeURIComponent(valor);
    }
    return resultado;
}

const tiempoLimite = parseInt(config('MONGO_TIMEOUT_MS', '2500'), 10) || 2500;
const uri = conOpciones(config('MONGO_URI', 'mongodb://127.0.0.1:27017'), {
    serverSelectionTimeoutMS: tiempoLimite,
    connectTimeoutMS: tiempoLimite,
    appname: 'voluntacr',
});

let bd;
try {
    bd = new Mongo(uri).getDB(config('MONGO_DB', 'voluntacr'));
    bd.runCommand({ ping: 1 });
} catch (error) {
    // El mensaje del controlador no incluye la contraseña de la URI.
    print('✘ MongoDB: ' + error.message);
    quit(1);
}

/* ---------- Esquema: los mismos valores que scripts/crear_esquema.php ---------- */

// Listas de src/Nucleo/Catalogos.php.
const provincias = ['San José', 'Alajuela', 'Cartago', 'Heredia', 'Guanacaste', 'Puntarenas', 'Limón'];
const roles = ['voluntario', 'coordinador', 'admin'];
const categorias = ['ambiente', 'playas', 'educacion', 'salud', 'animales', 'comunidad'];
const estadosActividad = ['borrador', 'publicada', 'cancelada'];
const estadosInscripcion = ['reservada', 'confirmada', 'cancelada', 'expirada'];
const tiposAviso = ['mensaje', 'cancelada', 'cambios', 'retirada', 'publicada', 'asistencia'];

const numero = { bsonType: ['int', 'long', 'double', 'decimal'] };
const entero = { bsonType: ['int', 'long'] };
const fechaOpcional = { bsonType: ['date', 'null'] };

const validadores = {

    organizaciones: { $jsonSchema: {
        bsonType: 'object',
        required: ['nombre', 'creadaEn'],
        properties: {
            nombre:      { bsonType: 'string', minLength: 3, maxLength: 120 },
            descripcion: { bsonType: 'string', maxLength: 1000 },
            provincia:   { enum: provincias },
            contacto:    { bsonType: 'string', maxLength: 120 },
            creadaEn:    { bsonType: 'date' },
        },
    } },

    usuarios: { $jsonSchema: {
        bsonType: 'object',
        required: ['nombre', 'correo', 'claveHash', 'rol', 'creadoEn'],
        properties: {
            nombre:       { bsonType: 'string', minLength: 3, maxLength: 80 },
            correo:       { bsonType: 'string', pattern: '^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$', maxLength: 120 },
            claveHash:    { bsonType: 'string', minLength: 20 },
            rol:          { enum: roles },
            provincia:    { enum: [...provincias, null] },
            organizacion: {
                bsonType:   ['object', 'null'],
                required:   ['id', 'nombre'],
                properties: { id: { bsonType: 'objectId' }, nombre: { bsonType: 'string' } },
            },
            // Las cuentas no se borran: se desactivan (false). Si falta el campo, la cuenta está activa.
            activo:         { bsonType: 'bool' },
            creadoEn:       { bsonType: 'date' },
            creadoPor:      { bsonType: ['objectId', 'null'] },
            actualizadoEn:  { bsonType: 'date' },
            actualizadoPor: { bsonType: 'objectId' },
            // Las sesiones iniciadas antes de esta fecha se cierran (contraseña restablecida, cuenta desactivada).
            sesionesValidasDesde: { bsonType: 'date' },
            // Correo confirmado con el enlace de Mailjet (D23). Si falta el campo, la cuenta está confirmada.
            correoVerificado:   { bsonType: 'bool' },
            correoVerificadoEn: { bsonType: 'date' },
            // Enlace de confirmación vigente: solo se guarda el SHA-256 del token.
            verificacion: {
                bsonType:   ['object', 'null'],
                required:   ['tokenHash', 'expiraEn', 'enviadaEn'],
                properties: {
                    tokenHash: { bsonType: 'string', pattern: '^[a-f0-9]{64}$' },
                    expiraEn:  { bsonType: 'date' },
                    enviadaEn: { bsonType: 'date' },
                },
            },
            // Recibir los avisos también por correo. Si falta el campo, sí.
            avisosPorCorreo: { bsonType: 'bool' },
        },
    } },

    actividades: {
        $jsonSchema: {
            bsonType: 'object',
            required: ['titulo', 'descripcion', 'categoria', 'provincia', 'lugar', 'fechaInicio', 'horas',
                'cupoTotal', 'cuposDisponibles', 'estado', 'organizacion', 'imagenes', 'creadaEn'],
            properties: {
                titulo:           { bsonType: 'string', minLength: 5, maxLength: 120 },
                descripcion:      { bsonType: 'string', minLength: 20, maxLength: 3000 },
                requisitos:       { bsonType: 'string', maxLength: 500 },
                categoria:        { enum: categorias },
                provincia:        { enum: provincias },
                lugar:            { bsonType: 'string', minLength: 3, maxLength: 150 },
                fechaInicio:      { bsonType: 'date' },
                horas:            { ...numero, minimum: 0.5, maximum: 24 },
                cupoTotal:        { ...entero, minimum: 1, maximum: 500 },
                // La base de datos rechaza cualquier escritura que deje cupos negativos.
                cuposDisponibles: { ...entero, minimum: 0, maximum: 500 },
                estado:           { enum: estadosActividad },
                organizacion:     {
                    bsonType:   'object',
                    required:   ['id', 'nombre'],
                    properties: { id: { bsonType: 'objectId' }, nombre: { bsonType: 'string' } },
                },
                imagenes: {
                    bsonType: 'array',
                    maxItems: 6,
                    items:    {
                        bsonType:   'object',
                        required:   ['archivo', 'tipo', 'bytes'],
                        properties: {
                            archivo: { bsonType: 'string', pattern: '^[a-f0-9]{16}\\.(jpg|png|webp)$' },
                            tipo:    { enum: ['image/jpeg', 'image/png', 'image/webp'] },
                            bytes:   { ...entero, minimum: 1, maximum: 10485760 },
                        },
                    },
                },
                portada:          { bsonType: ['string', 'null'] },
                creadaEn:         { bsonType: 'date' },
            },
        },
        // Regla entre dos campos: nunca más cupos libres que el cupo total.
        $expr: { $lte: ['$cuposDisponibles', '$cupoTotal'] },
    },

    inscripciones: { $jsonSchema: {
        bsonType: 'object',
        required: ['actividadId', 'voluntarioId', 'estado', 'creadaEn', 'actividad', 'voluntario'],
        properties: {
            actividadId:  { bsonType: 'objectId' },
            voluntarioId: { bsonType: 'objectId' },
            estado:       { enum: estadosInscripcion },
            reservadaEn:  fechaOpcional,
            expiraEn:     fechaOpcional,
            confirmadaEn: fechaOpcional,
            canceladaEn:  fechaOpcional,
            expiradaEn:   fechaOpcional,
            actividad:    { bsonType: 'object', required: ['titulo', 'fechaInicio', 'horas'] },
            voluntario:   { bsonType: 'object', required: ['nombre'] },
            asistencia:   {
                bsonType:   ['object', 'null'],
                properties: {
                    presente:      { bsonType: 'bool' },
                    horas:         { ...numero, minimum: 0, maximum: 24 },
                    registradaEn:  { bsonType: 'date' },
                    registradaPor: { bsonType: 'objectId' },
                },
            },
            creadaEn:     { bsonType: 'date' },
        },
    } },

    // Un documento por destinatario (D24): la bandeja de cada persona y su estado de lectura.
    notificaciones: { $jsonSchema: {
        bsonType: 'object',
        required: ['usuarioId', 'envioId', 'tipo', 'titulo', 'mensaje', 'leidaEn', 'correo', 'creadaEn'],
        properties: {
            usuarioId:   { bsonType: 'objectId' },
            // Agrupa los documentos de un mismo envío, uno por persona.
            envioId:     { bsonType: 'objectId' },
            tipo:        { enum: tiposAviso },
            titulo:      { bsonType: 'string', minLength: 3, maxLength: 150 },
            mensaje:     { bsonType: 'string', minLength: 1, maxLength: 2000 },
            actividadId: { bsonType: ['objectId', 'null'] },
            actividad:   { bsonType: ['object', 'null'], required: ['titulo', 'fechaInicio'] },
            enlace:      { bsonType: ['string', 'null'], maxLength: 300 },
            // Quién escribió el mensaje; null en los avisos automáticos.
            remitente:   {
                bsonType:   ['object', 'null'],
                required:   ['id', 'nombre'],
                properties: {
                    id:           { bsonType: 'objectId' },
                    nombre:       { bsonType: 'string' },
                    organizacion: { bsonType: 'string' },
                },
            },
            leidaEn:     fechaOpcional,
            creadaEn:    { bsonType: 'date' },
            correo:      {
                bsonType:   'object',
                required:   ['estado', 'intentos'],
                properties: {
                    estado:    { enum: ['pendiente', 'enviado', 'fallido', 'omitido'] },
                    intentos:  { ...entero, minimum: 0 },
                    enviadoEn: fechaOpcional,
                    motivo:    { bsonType: ['string', 'null'], maxLength: 300 },
                },
            },
        },
    } },
};

const indices = {
    organizaciones: [
        { key: { nombre: 1 }, name: 'nombre_unico', unique: true },
    ],
    usuarios: [
        { key: { correo: 1 }, name: 'correo_unico', unique: true },
        // Pantallas de administración: cuentas por rol, ordenadas por nombre.
        { key: { rol: 1, nombre: 1 }, name: 'admin_por_rol' },
    ],
    actividades: [
        // Portal: actividades publicadas ordenadas por fecha, con o sin filtros.
        { key: { estado: 1, fechaInicio: 1 }, name: 'portal_por_fecha' },
        { key: { estado: 1, categoria: 1, provincia: 1, fechaInicio: 1 }, name: 'portal_con_filtros' },
        // Panel del coordinador: actividades de su organización.
        { key: { 'organizacion.id': 1, fechaInicio: -1 }, name: 'panel_organizacion' },
    ],
    inscripciones: [
        // Una sola inscripción por persona y actividad (se reutiliza si la cancela y vuelve).
        { key: { actividadId: 1, voluntarioId: 1 }, name: 'una_por_persona', unique: true },
        // Liberar reservas vencidas.
        { key: { estado: 1, expiraEn: 1 }, name: 'reservas_vencidas' },
        // Lista de inscritos y asistencia de una actividad.
        { key: { actividadId: 1, estado: 1 }, name: 'inscritos_actividad' },
        // Historial y horas de un voluntario.
        { key: { voluntarioId: 1, 'actividad.fechaInicio': -1 }, name: 'historial_voluntario' },
    ],
    notificaciones: [
        // Bandeja de cada persona, del aviso más reciente al más antiguo.
        { key: { usuarioId: 1, creadaEn: -1 }, name: 'bandeja_usuario' },
        // Contador de avisos sin leer de la cabecera.
        { key: { usuarioId: 1, leidaEn: 1 }, name: 'sin_leer' },
        // Historial de avisos de una actividad, en el panel del coordinador.
        { key: { actividadId: 1, creadaEn: -1 }, name: 'avisos_actividad' },
        // Correos por reintentar (scripts/enviar_correos.php) y conteos de /estado.
        { key: { 'correo.estado': 1, creadaEn: 1 }, name: 'correos_pendientes' },
    ],
};

/* ---------- Aplicar ---------- */

// runCommand puede devolver ok: 0 sin lanzar un error; así se trata igual que en PHP.
function comando(documento) {
    const respuesta = bd.runCommand(documento);
    if (respuesta.ok !== 1) {
        throw new Error(respuesta.errmsg || JSON.stringify(respuesta));
    }
    return respuesta;
}

const recortar = (texto, largo) => (texto.length > largo ? texto.slice(0, largo - 1) + '…' : texto);

print('Base de datos: ' + bd.getName() + '\n');

const existentes = bd.getCollectionNames();

for (const [coleccion, validador] of Object.entries(validadores)) {
    const opciones = { validator: validador, validationLevel: 'strict', validationAction: 'error' };
    try {
        if (existentes.includes(coleccion)) {
            comando({ collMod: coleccion, ...opciones });
            print('  ✔ ' + coleccion + ': validador JSON Schema actualizado');
        } else {
            comando({ create: coleccion, ...opciones });
            print('  ✔ ' + coleccion + ': creada con validador JSON Schema');
        }
    } catch (error) {
        // Por ejemplo, un servidor compatible que no implementa validadores.
        print('  ! ' + coleccion + ': no se pudo aplicar el validador (' + recortar(error.message, 110) + ')');
        if (!existentes.includes(coleccion)) {
            comando({ create: coleccion });
            print('    ' + coleccion + ': creada sin validador');
        }
    }
}

print('');
for (const [coleccion, lista] of Object.entries(indices)) {
    // Solo se crean los índices que todavía no existen (el script se puede ejecutar varias veces).
    const existentesIdx = bd.getCollection(coleccion).getIndexes().map((indice) => indice.name);
    const nuevos = lista.filter((indice) => !existentesIdx.includes(indice.name));
    if (nuevos.length > 0) {
        comando({ createIndexes: coleccion, indexes: nuevos });
    }
    print('  ✔ ' + coleccion + ': ' + lista.map((indice) => indice.name).join(', ')
        + (nuevos.length > 0 ? '' : ' (ya existían)'));
}

print('\nEsquema listo.');
