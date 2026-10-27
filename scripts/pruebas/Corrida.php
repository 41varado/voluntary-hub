<?php
declare(strict_types=1);

use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\BulkWriteException;

/**
 * Lo que comparten las pruebas automatizadas: la dirección de la aplicación, una sesión por cuenta
 * de prueba, las actividades creadas (para limpiarlas al final), ayudas para leer las páginas y el
 * acceso directo a MongoDB y Redis cuando este equipo usa la misma base que la aplicación.
 * Responsable: Persona 4.
 */
final class Corrida
{
    // Cuentas de los datos de prueba (scripts/cargar_datos.php).
    public const COORDINADOR = 'coordinador1@voluntacr.test';
    public const VOLUNTARIO1 = 'voluntario1@voluntacr.test';
    public const VOLUNTARIO2 = 'voluntario2@voluntacr.test';
    public const VOLUNTARIO3 = 'voluntario3@voluntacr.test';
    public const DESACTIVADO = 'voluntario60@voluntacr.test';
    public const CLAVE_COORDINACION = 'Coordina2026';
    public const CLAVE_VOLUNTARIO = 'Voluntario2026';

    public const PUBLICADA = 'Actividad publicada. Ahora puede agregarle imágenes.';

    /** Va en el título de cada actividad de prueba: "Prueba CN-01 1026-1530". */
    public readonly string $id;
    /** /estado.json al empezar. */
    public array $estado = [];
    /** true si los avisos dicen qué motor resolvió cada reserva (APP_MODO_DEMO=true). */
    public bool $modoDemo = false;
    /** Datos que una prueba deja para otra, por ejemplo la actividad sin imágenes de DI-02. */
    public array $datos = [];
    /** @var array<string, string> actividades creadas en esta corrida: id => título */
    public array $actividades = [];

    /** @var array<string, array> campos con los que se creó cada actividad */
    private array $campos = [];
    /** @var array<string, Navegador> */
    private array $sesiones = [];
    /** @var array<string, ?string> por qué no hay acceso directo a 'bd' o 'redis' (null: sí hay) */
    private array $sinAcceso = [];

    public function __construct(
        public readonly string $origen,
        public readonly string $base,
        public readonly bool $esperar = true,
        public readonly bool $conservar = false,
        private readonly bool $verificarCertificado = true,
    ) {
        $this->id = date('md-Hi');
    }

    public function url(): string
    {
        return $this->origen . $this->base;
    }

    /* ---------- Sesiones ---------- */

    public function navegador(string $nombre = 'sin sesión'): Navegador
    {
        return new Navegador($this->origen, $this->base, $nombre, $this->verificarCertificado);
    }

    /** Sesión iniciada con una cuenta de prueba. Se reutiliza en todas las pruebas. */
    public function sesion(string $correo): Navegador
    {
        if (isset($this->sesiones[$correo])) {
            return $this->sesiones[$correo];
        }
        $n = $this->navegador($correo);
        $n->get('/ingresar');
        $n->post('/ingresar', ['correo' => $correo, 'clave' => $this->clave($correo)]);
        if ($n->codigoInicial !== 303) {
            $motivo = $n->contiene('Demasiados intentos fallidos')
                ? 'el correo sigue bloqueado por la prueba DI-07 de una corrida anterior (el bloqueo dura 15 minutos)'
                : self::resumen($n);
            throw new RuntimeException("No se pudo iniciar sesión como {$correo}: {$motivo}.");
        }
        return $this->sesiones[$correo] = $n;
    }

    public function clave(string $correo): string
    {
        return str_starts_with($correo, 'coordinador') ? self::CLAVE_COORDINACION : self::CLAVE_VOLUNTARIO;
    }

    /** /estado.json de la aplicación. También comprueba que la dirección sea la de VoluntaCR. */
    public function estadoJson(): array
    {
        $n = $this->navegador();
        $n->get('/estado.json');
        $datos = json_decode($n->html, true);
        if (!is_array($datos) || !isset($datos['modo'])) {
            throw new RuntimeException("{$this->url()}/estado.json no respondió como VoluntaCR (código {$n->codigo}). "
                . 'Revise --url y la subcarpeta (APP_BASE).');
        }
        return $datos;
    }

    /* ---------- Actividades de prueba ---------- */

    public function titulo(string $codigo): string
    {
        return "Prueba {$codigo} {$this->id}";
    }

    /** Campos válidos del formulario de actividad; $cambios reemplaza los que haga falta. */
    public function camposActividad(string $codigo, array $cambios = []): array
    {
        return $cambios + [
            'titulo'      => $this->titulo($codigo),
            'descripcion' => 'Actividad creada por las pruebas automatizadas (scripts/pruebas/correr.php). Al terminar se elimina o se cancela.',
            'requisitos'  => '',
            'categoria'   => 'playas',
            'provincia'   => 'Puntarenas',
            'lugar'       => 'Playa de prueba',
            'fechaInicio' => $this->fecha(75 * 60),
            'horas'       => '3',
            'cupoTotal'   => '2',
            'accion'      => 'publicar',
        ];
    }

    /** Valor para un campo datetime-local, en la zona horaria de la aplicación (APP_ZONA_HORARIA). */
    public function fecha(int $segundosDesdeAhora): string
    {
        return date('Y-m-d\TH:i', time() + $segundosDesdeAhora);
    }

    /**
     * Envía el formulario de nueva actividad como coordinador1. Si la actividad se crea, la anota
     * para limpiarla al final y devuelve su id; si no (datos inválidos), devuelve null.
     */
    public function enviarActividad(array $campos): ?string
    {
        $coordinador = $this->sesion(self::COORDINADOR);
        $coordinador->get('/panel/actividades/nueva');
        $coordinador->post('/panel/actividades', $campos);
        if (!preg_match('#^/panel/actividades/([a-f0-9]{24})/editar$#', $coordinador->ruta, $m)) {
            return null;
        }
        $this->actividades[$m[1]] = $campos['titulo'];
        $this->campos[$m[1]] = $campos;
        return $m[1];
    }

    /** Crea una actividad publicada para una prueba; si no se puede, la prueba se corta. */
    public function crearActividad(string $codigo, array $cambios = []): string
    {
        $campos = $this->camposActividad($codigo, $cambios);
        return $this->enviarActividad($campos) ?? throw new RuntimeException(
            'No se pudo crear la actividad «' . $campos['titulo'] . '»: ' . self::resumen($this->sesion(self::COORDINADOR)));
    }

    /** Guarda la edición de una actividad de la corrida con los mismos datos, salvo $cambios. */
    public function editarActividad(string $id, array $cambios, bool $conCsrf = true): Navegador
    {
        $coordinador = $this->sesion(self::COORDINADOR);
        $coordinador->get("/panel/actividades/{$id}/editar");
        return $coordinador->post("/panel/actividades/{$id}", $cambios + $this->campos[$id], [], $conCsrf);
    }

    /** Reserva un cupo desde el detalle de la actividad, como el botón «Reservar cupo». Devuelve el aviso. */
    public function reservar(Navegador $voluntario, string $actividad): string
    {
        $voluntario->get("/actividades/{$actividad}");
        $voluntario->post("/actividades/{$actividad}/reservar");
        return $voluntario->avisos()[0] ?? '(sin aviso)';
    }

    /** Id de la inscripción en la actividad abierta, sacado de los botones Confirmar o Liberar. */
    public function inscripcion(Navegador $voluntario): string
    {
        return $voluntario->buscar('#/inscripciones/([a-f0-9]{24})/(?:confirmar|cancelar)#')
            ?? throw new RuntimeException("{$voluntario->nombre} no tiene una inscripción en la actividad abierta ({$voluntario->ruta}).");
    }

    /** Una actividad del portal que no es de la organización de coordinador1, o null. */
    public function actividadDeOtraOrganizacion(): ?string
    {
        $coordinador = $this->sesion(self::COORDINADOR);
        $coordinador->get('/panel');
        preg_match_all('#/actividades/([a-f0-9]{24})"#', $coordinador->html, $propias);
        $portal = $this->navegador();
        for ($pagina = 1; $pagina <= 5; $pagina++) {
            $portal->get('/actividades?pagina=' . $pagina);
            preg_match_all('#/actividades/([a-f0-9]{24})"#', $portal->html, $ids);
            $ajenas = array_diff($ids[1], $propias[1]);
            if ($ajenas) {
                return reset($ajenas);
            }
        }
        return null;
    }

    /* ---------- Motor que resolvió una reserva ---------- */

    /** Lo que dice el aviso entre paréntesis: "filtrado en Redis y confirmado en MongoDB". */
    public static function motor(string $aviso): ?string
    {
        return preg_match('/\(((?:filtrado|resuelto)[^()]*), [\d.]+ ms\)$/u', $aviso, $m) ? $m[1] : null;
    }

    /** true si el aviso dice ese motor, o si la aplicación no lo muestra (APP_MODO_DEMO=false). */
    public function motorEs(string $aviso, string $esperado): bool
    {
        $motor = self::motor($aviso);
        return $motor === $esperado || ($motor === null && !$this->modoDemo);
    }

    /* ---------- Acceso directo a MongoDB y Redis ---------- */

    /** Motivo por el que no se puede usar la base de la aplicación desde aquí, o null si se puede. */
    public function sinBd(): ?string
    {
        if (!array_key_exists('bd', $this->sinAcceso)) {
            $this->sinAcceso['bd'] = $this->revisarBd();
        }
        return $this->sinAcceso['bd'];
    }

    public function bd(): Mongo
    {
        if (($motivo = $this->sinBd()) !== null) {
            throw new Omision("necesita la base de la aplicación ({$motivo})");
        }
        return Mongo::get();
    }

    public function sinRedis(): ?string
    {
        if (!array_key_exists('redis', $this->sinAcceso)) {
            $this->sinAcceso['redis'] = $this->revisarRedis();
        }
        return $this->sinAcceso['redis'];
    }

    public function redis(): RedisSeguro
    {
        if (($motivo = $this->sinRedis()) !== null) {
            throw new Omision("necesita el Redis de la aplicación ({$motivo})");
        }
        return RedisSeguro::get();
    }

    private function revisarBd(): ?string
    {
        if (!extension_loaded('mongodb')) {
            return 'este PHP no tiene la extensión mongodb';
        }
        try {
            $bd = Mongo::get();
            $bd->ping();
        } catch (Throwable $error) {
            return 'no se pudo conectar a MongoDB con el .env de este equipo: ' . $error->getMessage();
        }
        if ($bd->nombreBaseDatos() !== ($this->estado['mongo']['base'] ?? null)) {
            return 'este equipo usa la base «' . $bd->nombreBaseDatos() . '» y la aplicación, «' . ($this->estado['mongo']['base'] ?? '?') . '»';
        }
        // El nombre no basta (en Codespaces y en el servidor la base se llama igual): una actividad
        // que muestra la aplicación tiene que estar en esta base.
        $id = array_key_first($this->actividades);
        if ($id === null) {
            $portal = $this->navegador();
            $portal->get('/actividades');
            $id = $portal->buscar('#/actividades/([a-f0-9]{24})"#');
        }
        if ($id === null || $bd->buscarUno('actividades', ['_id' => new ObjectId($id)]) === null) {
            return 'la base de este equipo no es la de la aplicación: no tiene sus actividades';
        }
        return null;
    }

    private function revisarRedis(): ?string
    {
        if (Config::bool('REDIS_DESHABILITADO')) {
            return 'REDIS_DESHABILITADO=true en este equipo';
        }
        $rs = RedisSeguro::get();
        if (!$rs->disponible()) {
            return 'no se pudo conectar a Redis con el .env de este equipo: ' . $rs->motivo();
        }
        if ($rs->prefijo() !== ($this->estado['redis']['prefijo'] ?? null)) {
            return 'este equipo usa el prefijo «' . $rs->prefijo() . '» y la aplicación, «' . ($this->estado['redis']['prefijo'] ?? '?') . '»';
        }
        // /estado escribe la clave "hola" con la hora: si aquí se lee lo mismo, es el mismo Redis.
        $prueba = $this->estadoJson()['redis']['prueba'] ?? null;
        $leido = $rs->usar(fn(Redis $r) => $r->get($rs->k('hola')), false);
        if ($prueba === null || $leido !== $prueba) {
            return 'el Redis de este equipo no es el de la aplicación';
        }
        return null;
    }

    /**
     * Intenta una escritura que JSON Schema debe rechazar. Devuelve [código, mensaje] del error de
     * MongoDB; si la escritura pasa, la deshace enseguida y devuelve [null, …].
     */
    public static function escrituraRechazada(callable $escribir, callable $deshacer): array
    {
        try {
            $escribir();
        } catch (BulkWriteException $error) {
            $detalle = $error->getWriteResult()->getWriteErrors()[0] ?? null;
            return [$detalle ? $detalle->getCode() : $error->getCode(), $detalle ? $detalle->getMessage() : $error->getMessage()];
        }
        $deshacer();
        return [null, 'MongoDB aceptó el cambio (se deshizo enseguida)'];
    }

    /** Corre un script de scripts/ con este mismo PHP. Devuelve [código de salida, salida]. */
    public static function script(array $argumentos): array
    {
        $ini = php_ini_loaded_file();
        $comando = array_merge([PHP_BINARY], $ini ? ['-c', $ini] : [], $argumentos);
        $proceso = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos, RAIZ);
        $salida = stream_get_contents($tubos[1]) . stream_get_contents($tubos[2]);
        fclose($tubos[1]);
        fclose($tubos[2]);
        return [proc_close($proceso), trim($salida)];
    }

    /** Espera hasta $momento (timestamp). Si falta demasiado, la prueba se omite. */
    public function esperarHasta(int $momento, string $para): void
    {
        $faltan = $momento - time();
        if ($faltan > 20 * 60) {
            throw new Omision('habría que esperar ' . (int) ceil($faltan / 60) . " minutos para {$para}");
        }
        if ($faltan > 0) {
            fwrite(STDERR, '  Esperando ' . self::duracion($faltan) . " para {$para}…\n");
            sleep($faltan);
        }
    }

    /* ---------- Limpieza ---------- */

    /**
     * Elimina las actividades de la corrida. Las que tienen inscripciones no se pueden eliminar
     * (la aplicación conserva el historial), así que se cancelan, como lo haría la organización.
     */
    public function limpiar(): string
    {
        if (!$this->actividades) {
            return 'no se crearon actividades.';
        }
        if ($this->conservar) {
            return 'se conservaron las ' . count($this->actividades) . ' actividades de prueba (--conservar).';
        }
        $eliminadas = 0;
        $canceladas = 0;
        $problemas = [];
        foreach ($this->actividades as $id => $titulo) {
            try {
                $coordinador = $this->sesion(self::COORDINADOR);
                $coordinador->get("/panel/actividades/{$id}/editar");
                $coordinador->post("/panel/actividades/{$id}/eliminar");
                if (in_array('Actividad eliminada.', $coordinador->avisos(), true)) {
                    $eliminadas++;
                    continue;
                }
                $coordinador->post("/panel/actividades/{$id}/estado", ['estado' => 'cancelada']);
                $canceladas++;
            } catch (Throwable $error) {
                $problemas[] = "«{$titulo}»: {$error->getMessage()}";
            }
        }
        return "actividades de prueba eliminadas: {$eliminadas}; canceladas porque tienen inscripciones: {$canceladas}."
            . ($problemas ? ' No se pudieron limpiar: ' . implode('; ', $problemas) : '');
    }

    /* ---------- Lectura de páginas ---------- */

    /** Resumen de una respuesta para el informe: código, avisos, errores de los campos y página de error. */
    public static function resumen(Navegador $n): string
    {
        $partes = ['código ' . ($n->codigoInicial !== $n->codigo ? "{$n->codigoInicial} → {$n->codigo}" : $n->codigo)];
        foreach ($n->avisos() as $aviso) {
            $partes[] = self::cita($aviso);
        }
        foreach ($n->errores() as $campo => $error) {
            $partes[] = "{$campo}: " . self::cita($error);
        }
        if ($n->codigo >= 400 && ($mensaje = $n->mensajeError()) !== '') {
            $partes[] = self::cita($mensaje);
        }
        return implode('; ', $partes);
    }

    /** Un mensaje entre comillas, salvo que ya empiece con ellas («foto.jpg» no es una imagen…). */
    public static function cita(string $mensaje): string
    {
        return str_starts_with($mensaje, '«') ? $mensaje : "«{$mensaje}»";
    }

    /** true si la página tiene exactamente esos errores junto a esos campos (puede tener más). */
    public static function tieneErrores(Navegador $n, array $esperados): bool
    {
        $errores = $n->errores();
        foreach ($esperados as $campo => $mensaje) {
            if (($errores[$campo] ?? null) !== $mensaje) {
                return false;
            }
        }
        return true;
    }

    /** Medidor de la tarjeta de una actividad en el listado ("2 cupos libres"), o null si no aparece. */
    public static function tarjeta(Navegador $n, string $titulo): ?string
    {
        foreach (explode('<article class="actividad">', $n->html) as $tarjeta) {
            if (str_contains($tarjeta, '>' . htmlspecialchars($titulo) . '</a>')) {
                return preg_match('#class="medidor__texto"><strong>(.*?)</strong>#s', $tarjeta, $m) ? Navegador::limpiar($m[1]) : '';
            }
        }
        return null;
    }

    /** Cifras destacadas de la página: ['confirmados' => '1', 'horas acumuladas' => '12,5', …]. */
    public static function cifras(Navegador $n): array
    {
        preg_match_all('#<div class="cifra"><strong>(.*?)</strong><span>(.*?)</span></div>#s', $n->html, $m);
        return array_combine(array_map([Navegador::class, 'limpiar'], $m[2]), array_map([Navegador::class, 'limpiar'], $m[1]));
    }

    public static function textoCifras(array $cifras): string
    {
        return implode(', ', array_map(fn($nombre, $valor) => "{$nombre} {$valor}", array_keys($cifras), $cifras));
    }

    /** Horas de una categoría en "Mis horas" (0 si no aparece). */
    public static function horasCategoria(Navegador $n, string $categoria): float
    {
        return preg_match('#<span>' . preg_quote(htmlspecialchars($categoria), '#') . '</span>.*?<strong>([\d,]+) horas?</strong>#s', $n->html, $m)
            ? self::numero($m[1]) : 0.0;
    }

    /** Sección y estado de la inscripción a $titulo en "Mis inscripciones": ['Próximas', 'Confirmada'] o null. */
    public static function enMisInscripciones(Navegador $n, string $titulo): ?array
    {
        $partes = preg_split('#<h2[^>]*>Anteriores y canceladas</h2>#', $n->html, 2);
        foreach (['Próximas' => $partes[0], 'Anteriores y canceladas' => $partes[1] ?? ''] as $seccion => $html) {
            foreach (preg_split('#<(?:li|tr)>#', $html) as $fila) {
                if (str_contains($fila, htmlspecialchars($titulo)) && preg_match('#class="estado estado--[a-z]+">(.*?)</span>#', $fila, $m)) {
                    return [$seccion, Navegador::limpiar($m[1])];
                }
            }
        }
        return null;
    }

    /** Celdas de la fila de $titulo en el panel del coordinador, o null si no aparece. */
    public static function filaPanel(Navegador $n, string $titulo): ?array
    {
        foreach (explode('<tr>', $n->html) as $fila) {
            if (str_contains($fila, '>' . htmlspecialchars($titulo) . '</a>')) {
                preg_match_all('#<td[^>]*>(.*?)</td>#s', $fila, $m);
                return array_map([Navegador::class, 'limpiar'], $m[1]);
            }
        }
        return null;
    }

    /** Cuántas actividades tiene el panel del coordinador. */
    public static function actividadesEnPanel(Navegador $n): int
    {
        preg_match_all('#/panel/actividades/([a-f0-9]{24})/editar"#', $n->html, $m);
        return count(array_unique($m[1]));
    }

    /** Filas de «Últimos eventos de inscripciones» en /estado: [hora, evento, actividad, detalle]. */
    public static function eventos(Navegador $n): array
    {
        $seccion = $n->buscar('#<h2>Últimos eventos de inscripciones</h2>(.*?)</section>#s') ?? '';
        preg_match_all('#<tr>\s*<td>(.*?)</td>\s*<td>(.*?)</td>\s*<td>(.*?)</td>\s*<td class="texto-suave">(.*?)</td>\s*</tr>#s', $seccion, $m, PREG_SET_ORDER);
        return array_map(fn(array $fila) => array_map([Navegador::class, 'limpiar'], array_slice($fila, 1)), $m);
    }

    /* ---------- Números y archivos ---------- */

    /** "12,5" → 12.5 */
    public static function numero(string $texto): float
    {
        return (float) str_replace(',', '.', $texto);
    }

    /** 12.5 → "12,5"; 3.0 → "3" */
    public static function horas(float $horas): string
    {
        return str_replace('.', ',', (string) round($horas, 1));
    }

    public static function duracion(int $segundos): string
    {
        return $segundos >= 60 ? intdiv($segundos, 60) . ' min ' . ($segundos % 60) . ' s' : "{$segundos} s";
    }

    public static function archivo(string $nombre, string $datos, string $tipo = 'image/png'): array
    {
        return ['campo' => 'imagenes[]', 'nombre' => $nombre, 'tipo' => $tipo, 'datos' => $datos];
    }

    /** Una imagen PNG real, sin GD. $sinComprimir sirve para que pese lo que miden sus píxeles. */
    public static function imagenPng(int $ancho, int $alto, bool $sinComprimir = false): string
    {
        $fila = "\0"; // filtro 0 al inicio de cada fila
        for ($x = 0; $x < $ancho; $x++) {
            $fila .= chr(($x * 7) % 256) . chr(120) . chr(200 - $x % 64);
        }
        $trozo = fn(string $tipo, string $datos) => pack('N', strlen($datos)) . $tipo . $datos . pack('N', crc32($tipo . $datos));
        return "\x89PNG\r\n\x1a\n"
            . $trozo('IHDR', pack('NNCCCCC', $ancho, $alto, 8, 2, 0, 0, 0))
            . $trozo('IDAT', gzcompress(str_repeat($fila, $alto), $sinComprimir ? 0 : 6))
            . $trozo('IEND', '');
    }
}
