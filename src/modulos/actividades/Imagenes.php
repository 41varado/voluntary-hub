<?php
declare(strict_types=1);

/**
 * Subida de imágenes de una actividad.
 * Los archivos van a public/uploads/actividades/{id}/ con un nombre aleatorio;
 * en MongoDB solo se guardan los metadatos (archivo, tipo, tamaño, medidas).
 * Responsable: Persona 2.
 */
final class Imagenes
{
    public const TIPOS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    public const MAXIMO_POR_ACTIVIDAD = 6;

    public static function maximoBytes(): int
    {
        return (int) (Config::float('SUBIDAS_MAX_MB', 2) * 1024 * 1024);
    }

    private static function carpeta(string $actividadId): string
    {
        return RAIZ . '/public/uploads/actividades/' . $actividadId;
    }

    public static function url(string $actividadId, string $archivo): string
    {
        return url('/uploads/actividades/' . $actividadId . '/' . $archivo);
    }

    /**
     * Valida y guarda las imágenes recibidas en $_FILES[$campo].
     * Devuelve [metadatos de las guardadas, mensajes de error].
     */
    public static function recibir(string $campo, string $actividadId, int $yaTiene): array
    {
        $archivos = self::normalizar($_FILES[$campo] ?? null);
        $guardadas = [];
        $errores = [];
        $espacio = self::MAXIMO_POR_ACTIVIDAD - $yaTiene;
        $maximo = self::maximoBytes();

        if (!$archivos) {
            return [[], ['Elija al menos una imagen.']];
        }
        if (count($archivos) > $espacio) {
            $errores[] = 'Cada actividad admite hasta ' . self::MAXIMO_POR_ACTIVIDAD . " imágenes; solo se procesarán {$espacio}.";
            $archivos = array_slice($archivos, 0, max(0, $espacio));
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        foreach ($archivos as $archivo) {
            $nombre = mb_strimwidth(basename((string) $archivo['name']), 0, 60, '…');
            if ($archivo['error'] === UPLOAD_ERR_INI_SIZE || $archivo['error'] === UPLOAD_ERR_FORM_SIZE || $archivo['size'] > $maximo) {
                $errores[] = "«{$nombre}» pesa más de " . Config::get('SUBIDAS_MAX_MB', '2') . ' MB.';
                continue;
            }
            if ($archivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) {
                $errores[] = "«{$nombre}» no se pudo subir. Intente de nuevo.";
                continue;
            }
            // El tipo se revisa por el contenido real del archivo, no por la extensión.
            $tipo = $finfo->file($archivo['tmp_name']);
            $medidas = @getimagesize($archivo['tmp_name']);
            if (!isset(self::TIPOS[$tipo]) || $medidas === false) {
                $errores[] = "«{$nombre}» no es una imagen JPG, PNG o WebP.";
                continue;
            }
            if ($medidas[0] > 8000 || $medidas[1] > 8000) {
                $errores[] = "«{$nombre}» es demasiado grande (máximo 8000 píxeles por lado).";
                continue;
            }

            $carpeta = self::carpeta($actividadId);
            if (!is_dir($carpeta) && !mkdir($carpeta, 0775, true) && !is_dir($carpeta)) {
                $errores[] = 'No se pudo crear la carpeta de imágenes. Revise los permisos de public/uploads.';
                break;
            }
            $destino = bin2hex(random_bytes(8)) . '.' . self::TIPOS[$tipo];
            if (!move_uploaded_file($archivo['tmp_name'], $carpeta . '/' . $destino)) {
                $errores[] = "«{$nombre}» no se pudo guardar. Revise los permisos de public/uploads.";
                continue;
            }
            $guardadas[] = [
                'archivo'  => $destino,
                'tipo'     => $tipo,
                'bytes'    => (int) $archivo['size'],
                'ancho'    => (int) $medidas[0],
                'alto'     => (int) $medidas[1],
                'original' => $nombre,
                'subidaEn' => Mongo::ahora(),
            ];
        }
        return [$guardadas, $errores];
    }

    public static function borrar(string $actividadId, string $archivo): void
    {
        if (preg_match('/^[a-f0-9]{16}\.(jpg|png|webp)$/', $archivo)) {
            @unlink(self::carpeta($actividadId) . '/' . $archivo);
        }
    }

    public static function borrarCarpeta(string $actividadId): void
    {
        $carpeta = self::carpeta($actividadId);
        if (!is_dir($carpeta)) {
            return;
        }
        foreach (glob($carpeta . '/*') as $archivo) {
            @unlink($archivo);
        }
        @rmdir($carpeta);
    }

    /** Convierte la estructura de $_FILES con varios archivos en una lista simple. */
    private static function normalizar(?array $entrada): array
    {
        if (!$entrada || !isset($entrada['name'])) {
            return [];
        }
        if (!is_array($entrada['name'])) {
            return $entrada['error'] === UPLOAD_ERR_NO_FILE ? [] : [$entrada];
        }
        $lista = [];
        foreach (array_keys($entrada['name']) as $i) {
            if ($entrada['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $lista[] = [
                'name'     => $entrada['name'][$i],
                'type'     => $entrada['type'][$i],
                'tmp_name' => $entrada['tmp_name'][$i],
                'error'    => $entrada['error'][$i],
                'size'     => $entrada['size'][$i],
            ];
        }
        return $lista;
    }
}
