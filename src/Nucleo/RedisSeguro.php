<?php
declare(strict_types=1);

/**
 * Acceso a Redis con tolerancia a fallos.
 *
 * Redis es un acelerador, no la fuente de verdad: si no responde, cada
 * operación devuelve un valor "de respaldo" y la aplicación sigue con MongoDB.
 *
 * Interruptor (circuit breaker): cuando Redis falla se anota en
 * storage/redis_caido_hasta.txt y durante REDIS_REINTENTO_SEG segundos ninguna
 * solicitud vuelve a intentarlo, para no esperar el tiempo límite en cada página.
 *
 * Todas las claves llevan el prefijo REDIS_PREFIX (obligatorio en el Redis
 * compartido del curso: grupoX:). Nunca se usa FLUSHALL ni FLUSHDB.
 */
final class RedisSeguro
{
    private static ?self $instancia = null;

    private ?Redis $redis = null;
    private bool $intentado = false;
    private string $motivo = '';
    private string $prefijo;
    private string $archivoEstado;

    private function __construct()
    {
        $this->prefijo = (string) Config::get('REDIS_PREFIX', 'grupoX:voluntacr:');
        $this->archivoEstado = RAIZ . '/storage/redis_caido_hasta.txt';
    }

    public static function get(): self
    {
        return self::$instancia ??= new self();
    }

    /** Agrega el prefijo del grupo a una clave. */
    public function k(string $clave): string
    {
        return $this->prefijo . $clave;
    }

    public function prefijo(): string
    {
        return $this->prefijo;
    }

    /** Conexión abierta, o null si Redis no está disponible. Nunca lanza excepciones. */
    public function cliente(): ?Redis
    {
        if ($this->redis !== null) {
            return $this->redis;
        }
        if ($this->intentado) {
            return null;
        }
        $this->intentado = true;

        if (Config::bool('REDIS_DESHABILITADO')) {
            $this->motivo = 'Redis está desactivado en la configuración (REDIS_DESHABILITADO=true).';
            return null;
        }
        if (!extension_loaded('redis')) {
            $this->motivo = 'Falta la extensión de PHP "redis".';
            return null;
        }
        $hasta = (int) @file_get_contents($this->archivoEstado);
        if ($hasta > time()) {
            $this->motivo = 'Redis falló hace poco; se volverá a intentar en ' . ($hasta - time()) . ' s.';
            return null;
        }

        $tiempoLimite = Config::float('REDIS_TIMEOUT', 0.5);
        try {
            $redis = new Redis();
            if (!$redis->connect(Config::get('REDIS_HOST', '127.0.0.1'), Config::int('REDIS_PORT', 6379), $tiempoLimite)) {
                throw new RedisException('No se pudo abrir la conexión.');
            }
            $redis->setOption(Redis::OPT_READ_TIMEOUT, $tiempoLimite);
            $clave = Config::get('REDIS_PASSWORD');
            $usuario = Config::get('REDIS_USERNAME');
            if ($clave !== null && !$redis->auth($usuario !== null ? [$usuario, $clave] : $clave)) {
                throw new RedisException('Usuario o contraseña de Redis incorrectos.');
            }
            $baseDatos = Config::int('REDIS_DB', 0);
            if ($baseDatos !== 0) {
                $redis->select($baseDatos);
            }
            $this->redis = $redis;
            if (is_file($this->archivoEstado)) {
                @unlink($this->archivoEstado);
            }
            return $redis;
        } catch (RedisException $error) {
            $this->marcarCaido($error->getMessage());
            return null;
        }
    }

    public function disponible(): bool
    {
        return $this->cliente() !== null;
    }

    public function motivo(): string
    {
        return $this->motivo;
    }

    /**
     * Ejecuta una operación con Redis. Si Redis no está o falla a medio camino,
     * devuelve $siFalla y la aplicación continúa por MongoDB.
     *
     *   $valor = RedisSeguro::get()->usar(fn(Redis $r) => $r->get($clave), false);
     */
    public function usar(callable $operacion, mixed $siFalla = null): mixed
    {
        $redis = $this->cliente();
        if ($redis === null) {
            return $siFalla;
        }
        try {
            return $operacion($redis, $this);
        } catch (RedisException $error) {
            $this->marcarCaido($error->getMessage());
            return $siFalla;
        }
    }

    /** Datos para la página de estado. */
    public function estado(): array
    {
        $redis = $this->cliente();
        if ($redis === null) {
            return ['ok' => false, 'motivo' => $this->motivo];
        }
        try {
            $inicio = hrtime(true);
            $redis->ping();
            $milisegundos = (hrtime(true) - $inicio) / 1e6;
            $info = @$redis->info('server');
            return [
                'ok'      => true,
                'ms'      => round($milisegundos, 2),
                'version' => is_array($info) ? ($info['redis_version'] ?? '?') : '?',
            ];
        } catch (RedisException $error) {
            $this->marcarCaido($error->getMessage());
            return ['ok' => false, 'motivo' => $this->motivo];
        }
    }

    private function marcarCaido(string $detalle): void
    {
        $this->redis = null;
        $this->intentado = true;
        $espera = Config::int('REDIS_REINTENTO_SEG', 30);
        $this->motivo = 'Redis no responde (' . $detalle . '). Modo respaldo con MongoDB durante ' . $espera . ' s.';
        @file_put_contents($this->archivoEstado, (string) (time() + $espera));
        error_log('[voluntacr] ' . $this->motivo);
    }

    /** Solo para scripts que crean procesos hijos o mediciones. */
    public static function reiniciar(): void
    {
        self::$instancia = null;
    }
}
