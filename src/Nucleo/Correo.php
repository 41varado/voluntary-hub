<?php
declare(strict_types=1);

/**
 * Envío de correos con la Send API v3.1 de Mailjet.
 * Responsable: Persona 3.
 *
 * El correo es un servicio de apoyo, como Redis: si Mailjet no responde, lo que hizo el usuario se
 * guarda igual en MongoDB y el aviso queda en el portal. Los correos que fallan se reintentan con
 * scripts/enviar_correos.php (decisión D25).
 *
 * La llamada HTTP usa los streams de PHP (file_get_contents con un contexto), sin Composer ni la
 * extensión curl: alcanza con allow_url_fopen y la extensión openssl.
 *
 * Sin MAILJET_API_KEY, MAILJET_API_SECRET o CORREO_REMITENTE no sale ningún correo: se anotan en
 * storage/correos.log para poder probar en desarrollo. Tampoco se envía nunca a un dominio reservado
 * (.test, .example...), como los de las cuentas de prueba.
 */
final class Correo
{
    private const URL_ENVIO = 'https://api.mailjet.com/v3.1/send';
    /** Mensajes por llamada a la API. */
    private const POR_LLAMADA = 50;
    /** Dominios reservados (RFC 2606 y 6761): ninguna dirección con ellos existe. */
    private const DOMINIOS_RESERVADOS = ['test', 'example', 'invalid', 'localhost'];
    private const EJEMPLOS_RESERVADOS = ['example.com', 'example.net', 'example.org'];

    public static function configurado(): bool
    {
        return Config::get('MAILJET_API_KEY') !== null
            && Config::get('MAILJET_API_SECRET') !== null
            && Config::get('CORREO_REMITENTE') !== null;
    }

    public static function remitente(): ?string
    {
        return Config::get('CORREO_REMITENTE');
    }

    /** true si el correo es de un dominio reservado, por ejemplo voluntario1@voluntacr.test. */
    public static function esReservado(string $correo): bool
    {
        $dominio = strtolower(substr(strrchr($correo, '@') ?: '', 1));
        $partes = explode('.', $dominio);
        return in_array(end($partes), self::DOMINIOS_RESERVADOS, true)
            || in_array($dominio, self::EJEMPLOS_RESERVADOS, true);
    }

    /**
     * Arma un mensaje con el diseño de src/vistas/correo.php y su versión en texto plano.
     * $contenido: titulo, parrafos (lista de textos), boton (['texto', 'url'] o null), nota (texto o null).
     * $nombreRemitente cambia el nombre que ve quien lo recibe (por ejemplo, el de la organización).
     */
    public static function mensaje(string $id, string $correo, string $nombre, string $asunto, array $contenido, ?string $nombreRemitente = null): array
    {
        $contenido += ['titulo' => $asunto, 'parrafos' => [], 'boton' => null, 'nota' => null];

        $texto = $contenido['titulo'] . "\n\n" . implode("\n\n", $contenido['parrafos']);
        if ($contenido['boton']) {
            $texto .= "\n\n" . $contenido['boton']['texto'] . ': ' . $contenido['boton']['url'];
        }
        if ($contenido['nota']) {
            $texto .= "\n\n--\n" . $contenido['nota'];
        }

        return [
            'id'        => $id,
            'correo'    => $correo,
            'nombre'    => $nombre,
            'asunto'    => $asunto,
            'texto'     => $texto,
            'html'      => parcial('correo', $contenido),
            'remitente' => $nombreRemitente,
        ];
    }

    /**
     * Envía mensajes armados con mensaje(), en lotes de POR_LLAMADA.
     * Devuelve [id => ['estado' => 'enviado' | 'fallido' | 'omitido', 'motivo' => texto o null]].
     */
    public static function enviar(array $mensajes): array
    {
        $resultados = [];
        $porEnviar = [];
        foreach ($mensajes as $mensaje) {
            if (self::esReservado($mensaje['correo'])) {
                $resultados[$mensaje['id']] = ['estado' => 'omitido', 'motivo' => 'dominio_reservado'];
            } else {
                $porEnviar[] = $mensaje;
            }
        }
        if (!$porEnviar) {
            return $resultados;
        }

        if (!self::configurado()) {
            self::anotar($porEnviar);
            foreach ($porEnviar as $mensaje) {
                $resultados[$mensaje['id']] = ['estado' => 'omitido', 'motivo' => 'sin_configurar'];
            }
            return $resultados;
        }

        $sinRespuesta = null;
        foreach (array_chunk($porEnviar, self::POR_LLAMADA) as $lote) {
            // Si Mailjet no respondió a un lote, los demás no se intentan en esta solicitud: así nadie
            // espera MAILJET_TIMEOUT por cada lote. Quedan como fallidos y el cron los reintenta.
            if ($sinRespuesta === null) {
                [$respuesta, $error] = self::llamar(['Messages' => array_map([self::class, 'aMailjet'], $lote)]);
                if (!is_array($respuesta['Messages'] ?? null)) {
                    $sinRespuesta = $error ?? self::error($respuesta);
                    error_log('[voluntacr] Mailjet: ' . $sinRespuesta);
                }
            }
            foreach ($lote as $i => $mensaje) {
                if ($sinRespuesta !== null) {
                    $resultados[$mensaje['id']] = ['estado' => 'fallido', 'motivo' => $sinRespuesta];
                    continue;
                }
                // Mailjet responde un resultado por mensaje, en el mismo orden en que se enviaron.
                $resultado = $respuesta['Messages'][$i] ?? null;
                if (($resultado['Status'] ?? '') === 'success') {
                    $resultados[$mensaje['id']] = ['estado' => 'enviado', 'motivo' => null];
                } else {
                    $motivo = self::error($resultado);
                    error_log('[voluntacr] Mailjet rechazó el correo para ' . $mensaje['correo'] . ': ' . $motivo);
                    $resultados[$mensaje['id']] = ['estado' => 'fallido', 'motivo' => $motivo];
                }
            }
        }
        return $resultados;
    }

    /**
     * Prueba las claves y el remitente con SandboxMode: Mailjet revisa el mensaje pero no lo entrega.
     * Lo usa scripts/probar_conexiones.php.
     */
    public static function probar(): array
    {
        if (!self::configurado()) {
            return ['ok' => false, 'motivo' => 'Mailjet no está configurado (faltan MAILJET_API_KEY, MAILJET_API_SECRET o CORREO_REMITENTE). Los correos se anotan en storage/correos.log.'];
        }
        $remitente = (string) self::remitente();
        $mensaje = self::mensaje('prueba', $remitente, self::nombreRemitente(), 'Prueba de ' . self::nombreRemitente(), [
            'parrafos' => ['Prueba de conexión con Mailjet. En modo de prueba (SandboxMode) este correo no se entrega.'],
        ]);
        $inicio = hrtime(true);
        [$respuesta, $error] = self::llamar(['SandboxMode' => true, 'Messages' => [self::aMailjet($mensaje)]]);
        $ms = round((hrtime(true) - $inicio) / 1e6, 2);
        if (($respuesta['Messages'][0]['Status'] ?? '') === 'success') {
            return ['ok' => true, 'ms' => $ms, 'remitente' => $remitente];
        }
        return ['ok' => false, 'motivo' => $error ?? self::error($respuesta['Messages'][0] ?? $respuesta)];
    }

    private static function nombreRemitente(): string
    {
        return (string) Config::get('CORREO_REMITENTE_NOMBRE', Config::get('APP_NOMBRE', 'VoluntaCR'));
    }

    /** Un mensaje en el formato de la Send API v3.1. */
    private static function aMailjet(array $mensaje): array
    {
        return [
            'From'     => ['Email' => self::remitente(), 'Name' => $mensaje['remitente'] ?? self::nombreRemitente()],
            'To'       => [['Email' => $mensaje['correo'], 'Name' => $mensaje['nombre']]],
            'Subject'  => $mensaje['asunto'],
            'TextPart' => $mensaje['texto'],
            'HTMLPart' => $mensaje['html'],
            'CustomID' => $mensaje['id'],
        ];
    }

    /** POST a la API. Devuelve [respuesta JSON decodificada | null, mensaje de error | null]. */
    private static function llamar(array $cuerpo): array
    {
        $credenciales = Config::get('MAILJET_API_KEY') . ':' . Config::get('MAILJET_API_SECRET');
        $contexto = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nAuthorization: Basic " . base64_encode($credenciales),
            'content'       => json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            // Límite para conectar y para cada lectura.
            'timeout'       => Config::float('MAILJET_TIMEOUT', 5),
            // Así también se lee el cuerpo de las respuestas 4xx y 5xx, que explican el error.
            'ignore_errors' => true,
        ]]);
        $respuesta = @file_get_contents(self::URL_ENVIO, false, $contexto);
        if ($respuesta === false) {
            return [null, 'Mailjet no respondió (' . (error_get_last()['message'] ?? 'sin detalle') . ').'];
        }
        $json = json_decode($respuesta, true);
        return is_array($json) ? [$json, null] : [null, 'Mailjet respondió algo que no es JSON.'];
    }

    /** Texto del error que explica Mailjet, en un resultado de mensaje o en la respuesta completa (401, 429...). */
    private static function error(?array $respuesta): string
    {
        $error = $respuesta['Errors'][0] ?? $respuesta ?? [];
        $texto = trim((string) ($error['ErrorMessage'] ?? 'respuesta inesperada'));
        if (isset($error['StatusCode'])) {
            $texto = 'HTTP ' . $error['StatusCode'] . ': ' . $texto;
        }
        return mb_strimwidth($texto, 0, 250, '…');
    }

    /** Sin Mailjet configurado, los correos se anotan aquí para poder revisarlos en desarrollo. */
    private static function anotar(array $mensajes): void
    {
        $texto = '';
        foreach ($mensajes as $mensaje) {
            $texto .= '=== ' . date('Y-m-d H:i:s') . ' · Para: ' . $mensaje['nombre'] . ' <' . $mensaje['correo'] . ">\n"
                . 'Asunto: ' . $mensaje['asunto'] . "\n\n" . $mensaje['texto'] . "\n\n";
        }
        @file_put_contents(RAIZ . '/storage/correos.log', $texto, FILE_APPEND | LOCK_EX);
    }
}
