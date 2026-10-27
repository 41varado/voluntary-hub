<?php
declare(strict_types=1);

/**
 * Resultado de una prueba documentada (CN-01, EC-03…): cómo terminó cada paso y qué se vio.
 * Los números de paso son los del documento de docs/pruebas.
 * Responsable: Persona 4.
 */
final class Prueba
{
    /** @var array<int, array{0:string, 1:string}> número de paso => [✔ | ✘ | – | ·, detalle] */
    public array $pasos = [];
    /** Diferencias con el documento y datos para interpretar el resultado. */
    public array $notas = [];
    public ?string $error = null;

    public function __construct(public string $id, public string $nombre, public int $totalPasos)
    {
    }

    /** Anota un paso con lo que se obtuvo. Devuelve $ok. */
    public function paso(int $numero, bool $ok, string $obtenido): bool
    {
        $this->pasos[$numero] = [$ok ? '✔' : '✘', $obtenido];
        return $ok;
    }

    public function omitir(int|array $numeros, string $motivo): void
    {
        foreach ((array) $numeros as $numero) {
            $this->pasos[$numero] = ['–', 'Omitido: ' . $motivo];
        }
    }

    /** Omite los pasos que todavía no tienen resultado. */
    public function omitirResto(string $motivo): void
    {
        for ($n = 1; $n <= $this->totalPasos; $n++) {
            $this->pasos[$n] ??= ['–', 'Omitido: ' . $motivo];
        }
    }

    public function nota(string $texto): void
    {
        $this->notas[] = $texto;
    }

    /** La prueba se cortó por un error: el primer paso sin resultado falla y los demás no se corren. */
    public function fallo(Throwable $error): void
    {
        $this->error = $error->getMessage();
        $primero = true;
        for ($n = 1; $n <= $this->totalPasos; $n++) {
            if (!isset($this->pasos[$n])) {
                $this->pasos[$n] = $primero ? ['✘', 'Error: ' . $this->error] : ['·', 'No se corrió por el error anterior.'];
                $primero = false;
            }
        }
    }

    /** Pasa, Falla u Omitida. Si algunos pasos se omitieron y los demás pasaron, la prueba pasa. */
    public function estado(): string
    {
        $marcas = array_column($this->pasos, 0);
        if ($this->error !== null || in_array('✘', $marcas, true) || count($this->pasos) < $this->totalPasos) {
            return 'Falla';
        }
        return in_array('✔', $marcas, true) ? 'Pasa' : 'Omitida';
    }

    public function informe(): string
    {
        ksort($this->pasos);
        $omitidos = array_keys(array_filter($this->pasos, fn(array $p) => $p[0] === '–'));
        $estado = mb_strtoupper($this->estado());
        if ($estado === 'PASA' && $omitidos) {
            $estado .= ' (pasos omitidos: ' . implode(', ', $omitidos) . ')';
        }
        $lineas = ["[{$estado}] {$this->id} · {$this->nombre}"];
        for ($n = 1; $n <= $this->totalPasos; $n++) {
            [$marca, $detalle] = $this->pasos[$n] ?? ['✘', 'Sin resultado: el script no anotó este paso.'];
            $lineas[] = "  {$marca} {$n}. {$detalle}";
        }
        foreach ($this->notas as $nota) {
            $lineas[] = "  Nota: {$nota}";
        }
        return implode("\n", $lineas);
    }
}

/** Corta una prueba que no se puede correr desde aquí; sus pasos pendientes quedan omitidos. */
final class Omision extends RuntimeException
{
}
