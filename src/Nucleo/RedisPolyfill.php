<?php
declare(strict_types=1);

/**
 * Cliente de Redis en PHP puro, para servidores sin la extensión "redis" (phpredis).
 *
 * Solo se define si la extensión no está instalada. Habla directamente el protocolo de Redis
 * (RESP) por un socket TCP e implementa los mismos métodos de phpredis que usa el proyecto,
 * así el resto del código no cambia. Si la extensión existe, este archivo no hace nada.
 *
 * Métodos: connect, setOption, auth, select, ping, info, get, set, setex, incr, expire,
 * del, eval, scan, getLastError, clearLastError.
 *
 * Responsable: Persona 3.
 */

if (!class_exists('RedisException')) {
    class RedisException extends Exception
    {
    }
}

if (!class_exists('Redis')) {
    class Redis
    {
        public const OPT_READ_TIMEOUT = 3;
        /** Marca para saber que es el cliente integrado y no la extensión. */
        public const CLIENTE_INTEGRADO = true;

        /** @var resource|null */
        private $socket = null;
        private ?string $ultimoError = null;

        public function connect(string $host, int $port = 6379, float $timeout = 0.0): bool
        {
            $socket = @stream_socket_client(
                "tcp://{$host}:{$port}",
                $codigo,
                $mensaje,
                $timeout > 0 ? $timeout : 2.0
            );
            if ($socket === false) {
                throw new RedisException("No se pudo conectar a Redis en {$host}:{$port} ({$mensaje})");
            }
            $this->socket = $socket;
            $this->ajustarEspera($timeout > 0 ? $timeout : 2.0);
            return true;
        }

        public function setOption(int $opcion, mixed $valor): bool
        {
            if ($opcion === self::OPT_READ_TIMEOUT) {
                $this->ajustarEspera((float) $valor);
            }
            return true;
        }

        public function auth(mixed $credenciales): bool
        {
            $args = is_array($credenciales) ? array_values($credenciales) : [$credenciales];
            return $this->comando('AUTH', ...$args) === true;
        }

        public function select(int $baseDatos): bool
        {
            return $this->comando('SELECT', $baseDatos) === true;
        }

        public function ping(): bool
        {
            return $this->comando('PING') !== false;
        }

        public function info(?string $seccion = null): array|false
        {
            $texto = $seccion === null ? $this->comando('INFO') : $this->comando('INFO', $seccion);
            if (!is_string($texto)) {
                return false;
            }
            $datos = [];
            foreach (preg_split('/\r?\n/', $texto) as $linea) {
                if ($linea !== '' && $linea[0] !== '#' && str_contains($linea, ':')) {
                    [$clave, $valor] = explode(':', $linea, 2);
                    $datos[$clave] = $valor;
                }
            }
            return $datos;
        }

        public function get(string $clave): string|false
        {
            $valor = $this->comando('GET', $clave);
            return $valor === null ? false : $valor;
        }

        /** Opciones al estilo phpredis: ['nx', 'ex' => 5], ['xx', 'px' => 100]. */
        public function set(string $clave, mixed $valor, mixed $opciones = null): bool
        {
            $args = ['SET', $clave, (string) $valor];
            if (is_int($opciones)) {
                array_push($args, 'EX', $opciones);
            } elseif (is_array($opciones)) {
                foreach ($opciones as $nombre => $dato) {
                    if (is_int($nombre)) {
                        $args[] = strtoupper((string) $dato);       // 'nx', 'xx'
                    } else {
                        array_push($args, strtoupper($nombre), $dato); // 'ex' => 5
                    }
                }
            }
            return $this->comando(...$args) === true;
        }

        public function setex(string $clave, int $segundos, mixed $valor): bool
        {
            return $this->comando('SETEX', $clave, $segundos, (string) $valor) === true;
        }

        public function incr(string $clave): int|false
        {
            return $this->comando('INCR', $clave);
        }

        public function expire(string $clave, int $segundos): bool
        {
            return $this->comando('EXPIRE', $clave, $segundos) === 1;
        }

        public function del(mixed ...$claves): int|false
        {
            if (count($claves) === 1 && is_array($claves[0])) {
                $claves = $claves[0];
            }
            return $claves ? $this->comando('DEL', ...$claves) : 0;
        }

        public function eval(string $script, array $argumentos = [], int $numClaves = 0): mixed
        {
            return $this->comando('EVAL', $script, $numClaves, ...$argumentos);
        }

        /** Igual que phpredis: $cursor empieza en null y queda en 0 cuando terminó el recorrido. */
        public function scan(?int &$cursor, ?string $patron = null, int $cantidad = 0): array|false
        {
            $args = ['SCAN', $cursor ?? 0];
            if ($patron !== null) {
                array_push($args, 'MATCH', $patron);
            }
            if ($cantidad > 0) {
                array_push($args, 'COUNT', $cantidad);
            }
            $respuesta = $this->comando(...$args);
            if (!is_array($respuesta)) {
                return false;
            }
            $cursor = (int) $respuesta[0];
            return $respuesta[1];
        }

        public function getLastError(): ?string
        {
            return $this->ultimoError;
        }

        public function clearLastError(): bool
        {
            $this->ultimoError = null;
            return true;
        }

        public function close(): bool
        {
            if ($this->socket) {
                fclose($this->socket);
            }
            $this->socket = null;
            return true;
        }

        /* ---------- Protocolo RESP ---------- */

        /**
         * Envía un comando y devuelve la respuesta. Un error de Redis (-ERR ...) devuelve false y
         * queda en getLastError(), como en phpredis. Un problema de red lanza RedisException.
         */
        private function comando(mixed ...$args): mixed
        {
            if (!$this->socket) {
                throw new RedisException('No hay conexión con Redis.');
            }
            $mensaje = '*' . count($args) . "\r\n";
            foreach ($args as $arg) {
                $arg = (string) $arg;
                $mensaje .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
            }
            for ($enviado = 0, $total = strlen($mensaje); $enviado < $total; $enviado += $escrito) {
                $escrito = @fwrite($this->socket, substr($mensaje, $enviado));
                if ($escrito === false || $escrito === 0) {
                    $this->fallo('No se pudo enviar el comando a Redis.');
                }
            }
            $respuesta = $this->leerRespuesta();
            if ($respuesta instanceof ErrorRedisIntegrado) {
                $this->ultimoError = $respuesta->mensaje;
                return false;
            }
            return $respuesta;
        }

        private function leerRespuesta(): mixed
        {
            $linea = $this->leerLinea();
            $tipo = $linea[0] ?? '';
            $resto = substr($linea, 1);
            switch ($tipo) {
                case '+':
                    return $resto === 'OK' ? true : $resto;
                case '-':
                    return new ErrorRedisIntegrado($resto);
                case ':':
                    return (int) $resto;
                case '$':
                    $largo = (int) $resto;
                    if ($largo < 0) {
                        return null;
                    }
                    $datos = '';
                    while (strlen($datos) < $largo + 2) {
                        $parte = @fread($this->socket, $largo + 2 - strlen($datos));
                        if ($parte === false || $parte === '') {
                            $this->fallo('Redis cerró la conexión.');
                        }
                        $datos .= $parte;
                    }
                    return substr($datos, 0, $largo);
                case '*':
                    $cantidad = (int) $resto;
                    if ($cantidad < 0) {
                        return null;
                    }
                    $lista = [];
                    for ($i = 0; $i < $cantidad; $i++) {
                        $elemento = $this->leerRespuesta();
                        $lista[] = $elemento instanceof ErrorRedisIntegrado ? false : $elemento;
                    }
                    return $lista;
                default:
                    $this->fallo('Respuesta de Redis no reconocida.');
            }
        }

        private function leerLinea(): string
        {
            $linea = @fgets($this->socket);
            if ($linea === false) {
                $meta = stream_get_meta_data($this->socket);
                $this->fallo(!empty($meta['timed_out']) ? 'Redis no respondió a tiempo.' : 'Redis cerró la conexión.');
            }
            return rtrim($linea, "\r\n");
        }

        private function ajustarEspera(float $segundos): void
        {
            if ($this->socket && $segundos > 0) {
                $enteros = (int) floor($segundos);
                stream_set_timeout($this->socket, $enteros, (int) (($segundos - $enteros) * 1_000_000));
            }
        }

        private function fallo(string $mensaje): never
        {
            $this->close();
            throw new RedisException($mensaje);
        }
    }

    /** Error devuelto por Redis (respuesta "-ERR ..."). Uso interno del cliente integrado. */
    final class ErrorRedisIntegrado
    {
        public function __construct(public readonly string $mensaje)
        {
        }
    }
}
