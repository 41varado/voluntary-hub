<?php
declare(strict_types=1);

/* Fragmentos de HTML reutilizados por las vistas de actividades (y por otros módulos). */

/**
 * Medidor de cupos: una barra donde cada segmento es un cupo.
 * Gris = ocupado, amarillo = libre. Con más de 60 cupos la barra es continua.
 */
function medidor_cupos(int $total, int $libres, string $fondo = 'var(--blanco)'): string
{
    $total = max(1, $total);
    $libres = max(0, min($libres, $total));
    $ocupadoPct = round(($total - $libres) / $total * 100, 2);
    $clases = 'medidor' . ($total <= 60 ? ' medidor--segmentado' : '') . ($libres === 0 ? ' medidor--lleno' : '');
    $texto = $libres === 0 ? 'Sin cupos' : plural($libres, 'cupo libre', 'cupos libres');

    return '<div class="' . $clases . '" role="img" aria-label="' . e($texto . ' de ' . $total) . '" '
        . 'style="--total:' . $total . ';--ocupado-pct:' . $ocupadoPct . '%;--fondo-medidor:' . e($fondo) . '"></div>'
        . '<div class="medidor__texto"><strong>' . e($texto) . '</strong><span>de ' . $total . '</span></div>';
}

/** Ícono simple por categoría para las imágenes de reemplazo. */
function icono_categoria(string $categoria): string
{
    $trazos = [
        'ambiente'  => '<path d="M5 19c0-8 6-14 14-14 0 8-6 14-14 14z"/><path d="M5 19l8-8"/>',
        'playas'    => '<path d="M3 15c2 0 2-2 4.5-2s2.5 2 4.5 2 2-2 4.5-2 2.5 2 4.5 2"/><path d="M3 19c2 0 2-2 4.5-2s2.5 2 4.5 2 2-2 4.5-2 2.5 2 4.5 2"/><circle cx="16" cy="7" r="2.6"/>',
        'educacion' => '<path d="M4 5h6a2 2 0 0 1 2 2v12a2 2 0 0 0-2-2H4z"/><path d="M20 5h-6a2 2 0 0 0-2 2v12a2 2 0 0 1 2-2h6z"/>',
        'salud'     => '<path d="M12 20s-7-4.5-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.5-7 10-7 10z"/>',
        'animales'  => '<circle cx="6.5" cy="10" r="1.8"/><circle cx="10" cy="6" r="1.8"/><circle cx="14.5" cy="6" r="1.8"/><circle cx="18" cy="10" r="1.8"/><path d="M8 17c0-3 1.8-5 4.2-5s4.3 2 4.3 5c0 1.6-1.4 2.1-2.9 1.6a4.2 4.2 0 0 0-2.8 0C9.4 19.1 8 18.6 8 17z"/>',
        'comunidad' => '<path d="M4 11l8-6 8 6"/><path d="M6 10v9h12v-9"/><path d="M10 19v-5h4v5"/>',
    ];
    return '<svg viewBox="0 0 24 24" aria-hidden="true">' . ($trazos[$categoria] ?? $trazos['comunidad']) . '</svg>';
}

/** Portada de la actividad o, si no tiene imágenes, una imagen de reemplazo por categoría. */
function portada_actividad(string $id, ?string $portada, string $categoria, string $clase = 'actividad__imagen', string $clasePlaca = 'marcador'): string
{
    if ($portada) {
        return '<img class="' . e($clase) . '" src="' . e(Imagenes::url($id, $portada)) . '" alt="" loading="lazy">';
    }
    return '<div class="' . e($clasePlaca) . '" style="--tono:' . e(Catalogos::color($categoria)) . '">' . icono_categoria($categoria) . '</div>';
}

function texto_horas(float $horas): string
{
    return plural($horas, 'hora', 'horas');
}
