<?php
/**
 * Módulo de inscripciones: reservar, confirmar y cancelar cupos.
 * Responsable: Persona 3.
 */
declare(strict_types=1);

require_once __DIR__ . '/ServicioCupos.php';

return function (Router $router): void {

    Ganchos::agregar('menu', function (?array $usuario): array {
        return ($usuario && $usuario['rol'] === 'voluntario')
            ? [['texto' => 'Mis inscripciones', 'url' => url('/mis-inscripciones'), 'orden' => 30]]
            : [];
    });

    // Antes de mostrar una actividad, se liberan sus reservas vencidas (devuelve cuántas).
    Ganchos::agregar('actividad.antes_de_ver', fn(array $actividad): int => ServicioCupos::liberarVencidas($actividad['_id']));

    // Si el coordinador cambia el cupo o el estado, el contador de Redis se vuelve a cargar.
    Ganchos::agregar('actividad.cupo_cambiado', fn(string $id) => ServicioCupos::invalidarContador($id));

    // Si cambian los datos de la actividad, se actualiza la copia guardada en cada inscripción.
    Ganchos::agregar('actividad.actualizada', function (string $id): void {
        $bd = Mongo::get();
        $actividad = $bd->buscarUno('actividades', ['_id' => Mongo::id($id)]);
        if ($actividad) {
            $bd->actualizar('inscripciones', ['actividadId' => $actividad['_id']], ['$set' => ['actividad' => ServicioCupos::copiaActividad($actividad)]], true);
        }
    });

    // Últimos eventos del stream de Redis en la página de estado (con los títulos, si MongoDB responde).
    Ganchos::agregar('estado.secciones', function (bool $mongoResponde): string {
        $ultimos = ServicioCupos::ultimosEventos(10);
        $titulos = [];
        $ids = array_values(array_filter(array_map(fn(array $e) => Mongo::id($e['actividad']), $ultimos['eventos'] ?? [])));
        if ($ids && $mongoResponde) {
            try {
                foreach (Mongo::get()->buscar('actividades', ['_id' => ['$in' => $ids]], ['projection' => ['titulo' => 1]]) as $actividad) {
                    $titulos[(string) $actividad['_id']] = $actividad['titulo'];
                }
            } catch (Throwable) {
                // MongoDB no responde: se muestran los identificadores.
            }
        }
        return parcial('inscripciones/eventos', ['ultimos' => $ultimos, 'titulos' => $titulos]);
    });

    // Bloque de inscripción dentro del detalle de la actividad.
    Ganchos::agregar('actividad.panel', function (array $actividad, ?array $usuario): string {
        $inscripcion = null;
        if ($usuario && $usuario['rol'] === 'voluntario') {
            $inscripcion = Mongo::get()->buscarUno('inscripciones', [
                'actividadId'  => $actividad['_id'],
                'voluntarioId' => Mongo::id($usuario['id']),
            ]);
        }
        return parcial('inscripciones/panel', ['a' => $actividad, 'usuario' => $usuario, 'inscripcion' => $inscripcion]);
    });

    $responder = function (array $resultado, string $volverA): never {
        $motores = [
            'redis'         => 'resuelto en Redis sin consultar MongoDB',
            'redis+mongodb' => 'filtrado en Redis y confirmado en MongoDB',
            'mongodb'       => 'resuelto solo en MongoDB',
        ];
        $detalle = isset($motores[$resultado['motor']]) ? $motores[$resultado['motor']] . ', ' . $resultado['ms'] . ' ms' : '';
        flash($resultado['ok'] ? 'exito' : 'error', $resultado['mensaje'] . ($detalle !== '' ? detalle_demo($detalle) : ''));
        redirigir($volverA);
    };

    $router->post('/actividades/{id}/reservar', function (string $id) use ($responder): void {
        $usuario = Sesion::requiere('voluntario');
        $responder(ServicioCupos::reservar($id, $usuario), '/actividades/' . $id);
    });

    $router->post('/inscripciones/{id}/confirmar', function (string $id) use ($responder): void {
        $usuario = Sesion::requiere('voluntario');
        $responder(ServicioCupos::confirmar($id, $usuario), entrada('volver') === 'lista' ? '/mis-inscripciones' : '/actividades/' . entrada('actividad'));
    });

    $router->post('/inscripciones/{id}/cancelar', function (string $id) use ($responder): void {
        $usuario = Sesion::requiere('voluntario');
        $responder(ServicioCupos::cancelar($id, $usuario), entrada('volver') === 'lista' ? '/mis-inscripciones' : '/actividades/' . entrada('actividad'));
    });

    $router->get('/mis-inscripciones', function (): void {
        $usuario = Sesion::requiere('voluntario');
        ServicioCupos::liberarVencidas();
        $inscripciones = Mongo::get()->buscar('inscripciones', ['voluntarioId' => Mongo::id($usuario['id'])], [
            'sort' => ['actividad.fechaInicio' => -1],
        ]);
        $ahora = time();
        $proximas = array_filter($inscripciones, fn($i) => in_array($i['estado'], ['reservada', 'confirmada'], true)
            && $i['actividad']['fechaInicio']->toDateTime()->getTimestamp() > $ahora);
        $anteriores = array_diff_key($inscripciones, $proximas);
        vista('inscripciones/mis_inscripciones', [
            'proximas'   => array_reverse($proximas),
            'anteriores' => $anteriores,
        ], 'Mis inscripciones');
    });
};
