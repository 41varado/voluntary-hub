<?php
declare(strict_types=1);

/**
 * Una sesión de navegador para las pruebas automatizadas: pide páginas por HTTP con sus cookies,
 * sigue las redirecciones, agrega el token CSRF a los formularios y sube archivos.
 * Usa los flujos de PHP (allow_url_fopen), igual que el envío de correos: no necesita cURL.
 * Responsable: Persona 4.
 */
final class Navegador
{
    /** Código de la primera respuesta (303, 422…) y de la página que se mostró al final. */
    public int $codigoInicial = 0;
    public int $codigo = 0;
    public string $html = '';
    /** Ruta de la página final, sin la subcarpeta de la aplicación: /actividades/… */
    public string $ruta = '';

    /** @var array<string, string> */
    private array $cookies = [];
    private string $csrf = '';

    /**
     * @param string $origen esquema, servidor y puerto: https://su-sitio.cr
     * @param string $base   subcarpeta de la aplicación (APP_BASE), vacía si está en la raíz
     */
    public function __construct(
        private string $origen,
        private string $base,
        public string $nombre = 'sin sesión',
        private bool $verificarCertificado = true,
    ) {
    }

    public function get(string $ruta): self
    {
        return $this->navegar('GET', $this->origen . $this->base . $ruta);
    }

    /**
     * Envía un formulario con el token CSRF de la última página, salvo que $conCsrf sea false.
     * $archivos: [['campo' => 'imagenes[]', 'nombre' => 'foto.png', 'tipo' => 'image/png', 'datos' => '…']].
     */
    public function post(string $ruta, array $campos = [], array $archivos = [], bool $conCsrf = true): self
    {
        if ($conCsrf && !array_key_exists('_csrf', $campos)) {
            if ($this->csrf === '') {
                throw new RuntimeException("{$this->nombre}: no hay token CSRF; abra antes la página del formulario.");
            }
            $campos['_csrf'] = $this->csrf;
        }
        if ($archivos) {
            $limite = '----voluntacr' . bin2hex(random_bytes(8));
            $cuerpo = $this->multipart($limite, $campos, $archivos);
            $tipo = 'multipart/form-data; boundary=' . $limite;
        } else {
            $cuerpo = http_build_query($campos);
            $tipo = 'application/x-www-form-urlencoded';
        }
        return $this->navegar('POST', $this->origen . $this->base . $ruta, $cuerpo, ['Content-Type: ' . $tipo]);
    }

    /* ---------- Lo que muestra la página ---------- */

    /** Mensajes de una sola vez (flash) de la página. */
    public function avisos(): array
    {
        if (!preg_match('#<div class="avisos"[^>]*>(.*?)</div>#s', $this->html, $bloque)) {
            return [];
        }
        preg_match_all('#<p class="aviso[^"]*">(.*?)</p>#s', $bloque[1], $mensajes);
        return array_map([self::class, 'limpiar'], $mensajes[1]);
    }

    /** Errores junto a cada campo del formulario: ['titulo' => 'Escriba un título…']. */
    public function errores(): array
    {
        preg_match_all('#<p class="campo__error" id="error-([\w-]+)">(.*?)</p>#s', $this->html, $m);
        return array_combine($m[1], array_map([self::class, 'limpiar'], $m[2]));
    }

    /** Mensaje de una página de error (403, 404, 419…). */
    public function mensajeError(): string
    {
        return preg_match('#<section class="pagina-error">.*?</h1>\s*<p>(.*?)</p>#s', $this->html, $m) ? self::limpiar($m[1]) : '';
    }

    /** Texto visible de la página, sin etiquetas y con los espacios normalizados. */
    public function texto(): string
    {
        return self::limpiar((string) preg_replace('#<(script|style)\b.*?</\1>#s', ' ', $this->html));
    }

    public function contiene(string $frase): bool
    {
        return str_contains($this->texto(), $frase);
    }

    /** Primer grupo de una expresión regular sobre el HTML, o null si no aparece. */
    public function buscar(string $patron): ?string
    {
        return preg_match($patron, $this->html, $m) ? ($m[1] ?? $m[0]) : null;
    }

    /** Texto del primer medidor de cupos de la página: "2 cupos libres", "Sin cupos". */
    public function medidor(): string
    {
        return self::limpiar((string) $this->buscar('#class="medidor__texto"><strong>(.*?)</strong>#s'));
    }

    public static function limpiar(string $html): string
    {
        $texto = html_entity_decode(strip_tags((string) preg_replace('/</', ' <', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }

    /* ---------- HTTP ---------- */

    private function navegar(string $metodo, string $url, string $cuerpo = '', array $cabeceras = []): self
    {
        $this->codigoInicial = 0;
        for ($saltos = 0; $saltos <= 10; $saltos++) {
            [$codigo, $ubicacion, $html] = $this->pedir($metodo, $url, $cuerpo, $cabeceras);
            if ($this->codigoInicial === 0) {
                $this->codigoInicial = $codigo;
            }
            if ($codigo >= 300 && $codigo < 400 && $ubicacion !== null) {
                // Como el navegador: después de un POST, la redirección se pide con GET.
                $url = match (true) {
                    str_starts_with($ubicacion, 'http')  => $ubicacion,
                    str_starts_with($ubicacion, '/')     => $this->origen . $ubicacion,
                    default                              => $this->origen . $this->base . '/' . $ubicacion,
                };
                [$metodo, $cuerpo, $cabeceras] = ['GET', '', []];
                continue;
            }
            $this->codigo = $codigo;
            $this->html = $html;
            $ruta = (string) parse_url($url, PHP_URL_PATH);
            $this->ruta = ($this->base !== '' && str_starts_with($ruta, $this->base)) ? (substr($ruta, strlen($this->base)) ?: '/') : $ruta;
            if (preg_match('/name="_csrf" value="([^"]+)"/', $html, $m)) {
                $this->csrf = html_entity_decode($m[1]);
            }
            return $this;
        }
        throw new RuntimeException("Demasiadas redirecciones al pedir {$url}.");
    }

    /** @return array{0:int, 1:?string, 2:string} código, Location y cuerpo de la respuesta */
    private function pedir(string $metodo, string $url, string $cuerpo, array $cabeceras): array
    {
        $cabeceras[] = 'User-Agent: VoluntaCR-pruebas';
        if ($this->cookies) {
            $cabeceras[] = 'Cookie: ' . implode('; ', array_map(fn($n, $v) => $n . '=' . $v, array_keys($this->cookies), $this->cookies));
        }
        if ($metodo === 'POST') {
            $cabeceras[] = 'Content-Length: ' . strlen($cuerpo);
        }
        $contexto = stream_context_create([
            'http' => [
                'method'          => $metodo,
                'header'          => implode("\r\n", $cabeceras),
                'content'         => $cuerpo,
                'follow_location' => 0,
                'ignore_errors'   => true,
                'timeout'         => 60,
            ],
            'ssl' => ['verify_peer' => $this->verificarCertificado, 'verify_peer_name' => $this->verificarCertificado],
        ]);
        $respuesta = @file_get_contents($url, false, $contexto);
        $lineas = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
        if ($respuesta === false || !$lineas) {
            throw new RuntimeException("No se pudo abrir {$url}: " . (error_get_last()['message'] ?? 'sin respuesta'));
        }

        $codigo = 0;
        $ubicacion = null;
        foreach ($lineas as $linea) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linea, $m)) {
                $codigo = (int) $m[1];
            } elseif (stripos($linea, 'Location:') === 0) {
                $ubicacion = trim(substr($linea, 9));
            } elseif (stripos($linea, 'Set-Cookie:') === 0) {
                $this->guardarCookie(trim(substr($linea, 11)));
            }
        }
        return [$codigo, $ubicacion, $respuesta];
    }

    private function guardarCookie(string $cabecera): void
    {
        [$par] = explode(';', $cabecera, 2);
        [$nombre, $valor] = array_map('trim', explode('=', $par, 2)) + [1 => ''];
        if ($valor === '' || $valor === 'deleted' || preg_match('/Max-Age=0\b/i', $cabecera)) {
            unset($this->cookies[$nombre]);
            return;
        }
        $this->cookies[$nombre] = $valor;
    }

    private function multipart(string $limite, array $campos, array $archivos): string
    {
        $cuerpo = '';
        foreach ($campos as $nombre => $valor) {
            foreach ((array) $valor as $uno) {
                $campo = is_array($valor) ? $nombre . '[]' : $nombre;
                $cuerpo .= "--{$limite}\r\nContent-Disposition: form-data; name=\"{$campo}\"\r\n\r\n{$uno}\r\n";
            }
        }
        foreach ($archivos as $archivo) {
            $cuerpo .= "--{$limite}\r\nContent-Disposition: form-data; name=\"{$archivo['campo']}\"; filename=\"{$archivo['nombre']}\"\r\n"
                . "Content-Type: {$archivo['tipo']}\r\n\r\n{$archivo['datos']}\r\n";
        }
        return $cuerpo . "--{$limite}--\r\n";
    }
}
