<?php
declare(strict_types=1);

/* Funciones de apoyo para rutas y plantillas. */

/** Escapa texto para mostrarlo en HTML (evita XSS). */
function e(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Construye una URL interna respetando APP_BASE. */
function url(string $ruta = '/', array $consulta = []): string
{
    $base = rtrim((string) Config::get('APP_BASE', ''), '/');
    $url = $base . '/' . ltrim($ruta, '/');
    $consulta = array_filter($consulta, fn($v) => $v !== '' && $v !== null && $v !== false);
    if ($consulta) {
        $url .= '?' . http_build_query($consulta);
    }
    return $url;
}

function redirigir(string $ruta, array $consulta = []): never
{
    header('Location: ' . url($ruta, $consulta), true, 303);
    exit;
}

/** Valor enviado por formulario (POST), recortado. */
function entrada(string $clave, string $porDefecto = ''): string
{
    $valor = $_POST[$clave] ?? $porDefecto;
    return is_string($valor) ? trim($valor) : $porDefecto;
}

/** Valor de la cadena de consulta (GET), recortado. */
function consulta(string $clave, string $porDefecto = ''): string
{
    $valor = $_GET[$clave] ?? $porDefecto;
    return is_string($valor) ? trim($valor) : $porDefecto;
}

/* ---------- Mensajes de una sola vez (flash) ---------- */

function flash(string $tipo, string $mensaje): void
{
    $_SESSION['flash'][] = ['tipo' => $tipo, 'mensaje' => $mensaje];
}

function flash_pendientes(): array
{
    $mensajes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $mensajes;
}

/* ---------- Protección CSRF para todos los formularios POST ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_campo(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verificar(): void
{
    // Si el formulario supera post_max_size, PHP descarta todo $_POST.
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        abortar(413, 'Los archivos enviados superan el tamaño que permite el servidor. Suba menos imágenes o imágenes más livianas.');
    }
    $enviado = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    if (!is_string($enviado) || !hash_equals(csrf_token(), $enviado)) {
        abortar(419, 'El formulario expiró. Recargue la página y vuelva a intentarlo.');
    }
}

/* ---------- Plantillas ---------- */

/**
 * Busca una plantilla. "actividades/lista" → src/modulos/actividades/vistas/lista.php;
 * "error" → src/vistas/error.php.
 */
function ruta_plantilla(string $plantilla): string
{
    if (str_contains($plantilla, '/')) {
        [$modulo, $nombre] = explode('/', $plantilla, 2);
        $archivo = RAIZ . "/src/modulos/$modulo/vistas/$nombre.php";
        if (is_file($archivo)) {
            return $archivo;
        }
    }
    $archivo = RAIZ . "/src/vistas/$plantilla.php";
    if (is_file($archivo)) {
        return $archivo;
    }
    throw new RuntimeException("No existe la plantilla «{$plantilla}».");
}

/** Devuelve el HTML de una plantilla sin el diseño general. */
function parcial(string $__plantilla, array $__datos = []): string
{
    $__archivo = ruta_plantilla($__plantilla);
    extract($__datos, EXTR_SKIP);
    ob_start();
    require $__archivo;
    return (string) ob_get_clean();
}

/** Muestra una página completa: la plantilla dentro de src/vistas/layout.php. */
function vista(string $plantilla, array $datos = [], string $titulo = '', int $codigo = 200): void
{
    http_response_code($codigo);
    $contenido = parcial($plantilla, $datos + ['tituloPagina' => $titulo]);
    $usuario = Sesion::usuario();
    $menu = array_merge(...array_values(array_filter(Ganchos::ejecutar('menu', $usuario), 'is_array')) ?: [[]]);
    usort($menu, fn($a, $b) => ($a['orden'] ?? 50) <=> ($b['orden'] ?? 50));
    $mensajes = flash_pendientes();
    $tituloPagina = $titulo;
    require RAIZ . '/src/vistas/layout.php';
}

/** Corta la ejecución mostrando una página de error. */
function abortar(int $codigo, string $mensaje): never
{
    $titulos = [
        403 => 'Sin permiso',
        404 => 'Página no encontrada',
        405 => 'Solicitud no permitida',
        413 => 'Archivos demasiado grandes',
        419 => 'El formulario expiró',
        503 => 'Servicio no disponible',
    ];
    vista('error', ['codigo' => $codigo, 'mensaje' => $mensaje], $titulos[$codigo] ?? 'Error', $codigo);
    exit;
}

/* ---------- Fechas en español (zona de Costa Rica) ---------- */

function a_fecha(mixed $valor): DateTimeImmutable
{
    $zona = new DateTimeZone(date_default_timezone_get());
    if ($valor instanceof MongoDB\BSON\UTCDateTime) {
        return DateTimeImmutable::createFromMutable($valor->toDateTime())->setTimezone($zona);
    }
    if (is_int($valor)) {
        return (new DateTimeImmutable('@' . $valor))->setTimezone($zona);
    }
    if ($valor instanceof DateTimeInterface) {
        return DateTimeImmutable::createFromInterface($valor)->setTimezone($zona);
    }
    return new DateTimeImmutable((string) $valor, $zona);
}

function fecha_cr(mixed $valor, string $estilo = 'completa'): string
{
    $fecha = a_fecha($valor);
    $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto',
        'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $dia = $dias[(int) $fecha->format('w')];
    $mes = $meses[(int) $fecha->format('n') - 1];
    $hora = $fecha->format('g:i') . ($fecha->format('A') === 'AM' ? ' a. m.' : ' p. m.');

    return match ($estilo) {
        'corta' => mb_substr($dia, 0, 3) . ' ' . $fecha->format('j') . ' ' . mb_substr($mes, 0, 3) . ', ' . $hora,
        'dia'   => $dia . ' ' . $fecha->format('j') . ' de ' . $mes,
        'hora'  => $hora,
        default => $dia . ' ' . $fecha->format('j') . ' de ' . $mes . ' de ' . $fecha->format('Y') . ', ' . $hora,
    };
}

/** "1 cupo", "3 cupos". */
function plural(int|float $n, string $singular, string $plural): string
{
    $texto = is_float($n) && floor($n) != $n ? number_format($n, 1, ',', '') : (string) (int) $n;
    return $texto . ' ' . ((float) $n === 1.0 ? $singular : $plural);
}

/** Detalle técnico opcional para la demo (APP_MODO_DEMO=true). */
function detalle_demo(string $texto): string
{
    return Config::bool('APP_MODO_DEMO') ? ' (' . $texto . ')' : '';
}
