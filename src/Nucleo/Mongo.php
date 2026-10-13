<?php
declare(strict_types=1);

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;

/**
 * Acceso a MongoDB, el motor principal (fuente de verdad).
 *
 * Usa directamente la extensión "mongodb" de PHP, sin Composer, para que la
 * instalación en el servidor del curso (Virtualmin) sea solo copiar archivos.
 * Los documentos se devuelven como arreglos de PHP.
 */
final class Mongo
{
    private static ?self $instancia = null;

    private const TIPOS = ['root' => 'array', 'document' => 'array', 'array' => 'array'];

    private Manager $manager;
    private string $baseDatos;

    private function __construct()
    {
        if (!extension_loaded('mongodb')) {
            throw new RuntimeException('Falta la extensión de PHP "mongodb". Revise el README, sección Requisitos.');
        }
        $tiempoLimite = Config::int('MONGO_TIMEOUT_MS', 2500);
        $this->manager = new Manager(
            Config::get('MONGO_URI', 'mongodb://127.0.0.1:27017'),
            ['serverSelectionTimeoutMS' => $tiempoLimite, 'connectTimeoutMS' => $tiempoLimite, 'appname' => 'voluntacr']
        );
        $this->baseDatos = Config::get('MONGO_DB', 'voluntacr');
    }

    public static function get(): self
    {
        return self::$instancia ??= new self();
    }

    public function nombreBaseDatos(): string
    {
        return $this->baseDatos;
    }

    private function ns(string $coleccion): string
    {
        return $this->baseDatos . '.' . $coleccion;
    }

    /* ---------- Lectura ---------- */

    public function buscar(string $coleccion, array $filtro = [], array $opciones = []): array
    {
        $cursor = $this->manager->executeQuery($this->ns($coleccion), new Query((object) $filtro, $opciones));
        $cursor->setTypeMap(self::TIPOS);
        return $cursor->toArray();
    }

    public function buscarUno(string $coleccion, array $filtro, array $opciones = []): ?array
    {
        return $this->buscar($coleccion, $filtro, ['limit' => 1] + $opciones)[0] ?? null;
    }

    public function contar(string $coleccion, array $filtro = []): int
    {
        $resultado = $this->comando(['count' => $coleccion, 'query' => (object) $filtro]);
        return (int) ($resultado['n'] ?? 0);
    }

    /** Ejecuta un aggregation pipeline y devuelve todos los documentos resultantes. */
    public function agregar(string $coleccion, array $pipeline): array
    {
        $cursor = $this->manager->executeCommand(
            $this->baseDatos,
            new Command(['aggregate' => $coleccion, 'pipeline' => $pipeline, 'cursor' => new stdClass()])
        );
        $cursor->setTypeMap(self::TIPOS);
        return $cursor->toArray();
    }

    /* ---------- Escritura ---------- */

    public function insertar(string $coleccion, array $documento): ObjectId
    {
        $documento['_id'] ??= new ObjectId();
        $lote = new BulkWrite();
        $lote->insert($documento);
        $this->manager->executeBulkWrite($this->ns($coleccion), $lote);
        return $documento['_id'];
    }

    public function insertarVarios(string $coleccion, array $documentos): int
    {
        if (!$documentos) {
            return 0;
        }
        $lote = new BulkWrite(['ordered' => false]);
        foreach ($documentos as $documento) {
            $lote->insert($documento);
        }
        return (int) $this->manager->executeBulkWrite($this->ns($coleccion), $lote)->getInsertedCount();
    }

    /** Devuelve cuántos documentos cumplieron el filtro (útil para actualizaciones condicionales). */
    public function actualizar(string $coleccion, array $filtro, array $cambios, bool $varios = false): int
    {
        $lote = new BulkWrite();
        $lote->update((object) $filtro, $cambios, ['multi' => $varios, 'upsert' => false]);
        return (int) $this->manager->executeBulkWrite($this->ns($coleccion), $lote)->getMatchedCount();
    }

    public function eliminar(string $coleccion, array $filtro, bool $varios = false): int
    {
        $lote = new BulkWrite();
        $lote->delete((object) $filtro, ['limit' => $varios ? 0 : 1]);
        return (int) $this->manager->executeBulkWrite($this->ns($coleccion), $lote)->getDeletedCount();
    }

    /**
     * Busca y modifica un documento en una sola operación atómica (findAndModify).
     * Es la pieza que evita el sobrecupo cuando Redis no está: el filtro
     * "cuposDisponibles > 0" y el descuento ocurren juntos en el servidor.
     */
    public function buscarYActualizar(string $coleccion, array $filtro, array $cambios, bool $devolverNuevo = true): ?array
    {
        $resultado = $this->comando([
            'findAndModify' => $coleccion,
            'query'         => (object) $filtro,
            'update'        => $cambios,
            'new'           => $devolverNuevo,
        ]);
        return $resultado['value'] ?? null;
    }

    public function comando(array $comando, ?string $baseDatos = null): array
    {
        $cursor = $this->manager->executeCommand($baseDatos ?? $this->baseDatos, new Command($comando));
        $cursor->setTypeMap(self::TIPOS);
        return $cursor->toArray()[0] ?? [];
    }

    /** Para comandos que devuelven un cursor (listCollections, listIndexes...). */
    public function comandoLista(array $comando, ?string $baseDatos = null): array
    {
        $cursor = $this->manager->executeCommand($baseDatos ?? $this->baseDatos, new Command($comando));
        $cursor->setTypeMap(self::TIPOS);
        return $cursor->toArray();
    }

    /** Para la página de estado ("hola mundo"). */
    public function ping(): array
    {
        $inicio = hrtime(true);
        $this->comando(['ping' => 1], 'admin');
        $milisegundos = (hrtime(true) - $inicio) / 1e6;
        $info = $this->comando(['buildInfo' => 1], 'admin');
        return ['ms' => round($milisegundos, 2), 'version' => $info['version'] ?? '?'];
    }

    /* ---------- Ayudantes ---------- */

    /** Convierte un texto en ObjectId; devuelve null si no es válido. */
    public static function id(string $texto): ?ObjectId
    {
        return preg_match('/^[a-f0-9]{24}$/i', $texto) ? new ObjectId($texto) : null;
    }

    public static function ahora(): UTCDateTime
    {
        return new UTCDateTime();
    }

    public static function fecha(DateTimeInterface|int $valor): UTCDateTime
    {
        return is_int($valor) ? new UTCDateTime($valor * 1000) : new UTCDateTime($valor);
    }

    public static function esDuplicado(Throwable $error): bool
    {
        if (!$error instanceof BulkWriteException) {
            return false;
        }
        foreach ($error->getWriteResult()->getWriteErrors() as $fallo) {
            if ($fallo->getCode() === 11000) {
                return true;
            }
        }
        return false;
    }

    /** Solo para scripts que crean procesos hijos. */
    public static function reiniciar(): void
    {
        self::$instancia = null;
    }
}
