<?php
declare(strict_types=1);

/**
 * Confirmación del correo con un enlace que se envía por Mailjet (decisión D23).
 * Responsable: Persona 4.
 *
 * Las cuentas creadas desde la web (registro y administración) empiezan con correoVerificado = false
 * y no pueden iniciar sesión hasta abrir el enlace. El enlace lleva un token al azar de 32 bytes: en
 * MongoDB solo se guarda su SHA-256, vence en 48 horas y sirve una sola vez. Si a una cuenta le falta
 * el campo (las anteriores a esta función), se considera confirmada.
 */
final class VerificacionCorreo
{
    public const HORAS_VIGENCIA = 48;
    public const ESPERA_REENVIO_SEG = 60;

    public static function pendiente(array $doc): bool
    {
        return ($doc['correoVerificado'] ?? true) === false;
    }

    /**
     * Genera un enlace nuevo y lo envía; el anterior deja de servir. Si se envió otro hace menos de un
     * minuto, no hace nada (así se espera entre reenvíos sin depender de Redis).
     * Devuelve ['estado' => 'enviado' | 'fallido' | 'omitido' | 'espera' | 'confirmado',
     *           'motivo' => texto o null, 'ruta' => ruta del enlace o null].
     */
    public static function enviar(array $doc): array
    {
        $token = bin2hex(random_bytes(32));
        $ahora = time();
        // La condición y el cambio van en una sola operación: dos clics seguidos no envían dos correos.
        $actualizado = Mongo::get()->buscarYActualizar('usuarios', [
            '_id'              => $doc['_id'],
            'correoVerificado' => false,
            '$or'              => [
                ['verificacion' => null],
                ['verificacion.enviadaEn' => ['$lt' => Mongo::fecha($ahora - self::ESPERA_REENVIO_SEG)]],
            ],
        ], ['$set' => ['verificacion' => [
            'tokenHash' => hash('sha256', $token),
            'expiraEn'  => Mongo::fecha($ahora + self::HORAS_VIGENCIA * 3600),
            'enviadaEn' => Mongo::fecha($ahora),
        ]]]);
        if ($actualizado === null) {
            $sigue = UsuarioRepositorio::buscar((string) $doc['_id']);
            return ['estado' => $sigue && self::pendiente($sigue) ? 'espera' : 'confirmado', 'motivo' => null, 'ruta' => null];
        }

        $ruta = '/verificar-correo/' . $actualizado['_id'] . '/' . $token;
        $nombreApp = Config::get('APP_NOMBRE', 'VoluntaCR');
        $mensaje = Correo::mensaje('verificacion-' . $actualizado['_id'], $actualizado['correo'], $actualizado['nombre'], 'Confirme su correo en ' . $nombreApp, [
            'titulo'   => 'Confirme su correo',
            'parrafos' => [
                'Hola, ' . $actualizado['nombre'] . '.',
                'Para activar su cuenta en ' . $nombreApp . ', confirme que este correo es suyo. El enlace vence en '
                    . self::HORAS_VIGENCIA . ' horas y sirve una sola vez.',
            ],
            'boton'    => ['texto' => 'Confirmar mi correo', 'url' => url_absoluta($ruta)],
            'nota'     => 'Si usted no creó esta cuenta, ignore este mensaje: sin confirmar el correo, nadie puede usarla.',
        ]);
        return Correo::enviar([$mensaje])[$mensaje['id']] + ['ruta' => $ruta];
    }

    /**
     * Confirma el correo con el token del enlace.
     * Devuelve ['estado' => 'confirmado' | 'ya_estaba' | 'vencido' | 'invalido', 'usuario' => documento o null].
     */
    public static function confirmar(string $id, string $token): array
    {
        $doc = UsuarioRepositorio::buscar($id);
        if ($doc === null || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return ['estado' => 'invalido', 'usuario' => null];
        }
        if (!self::pendiente($doc)) {
            return ['estado' => 'ya_estaba', 'usuario' => $doc];
        }
        $hash = hash('sha256', $token);
        if (!hash_equals((string) ($doc['verificacion']['tokenHash'] ?? ''), $hash)) {
            return ['estado' => 'invalido', 'usuario' => null];
        }
        if ($doc['verificacion']['expiraEn']->toDateTime()->getTimestamp() < time()) {
            return ['estado' => 'vencido', 'usuario' => $doc];
        }
        // La condición repite el hash: si el enlace se abre dos veces a la vez, solo una lo usa.
        $confirmado = Mongo::get()->buscarYActualizar('usuarios',
            ['_id' => $doc['_id'], 'verificacion.tokenHash' => $hash],
            ['$set' => ['correoVerificado' => true, 'correoVerificadoEn' => Mongo::ahora()], '$unset' => ['verificacion' => '']]
        );
        return $confirmado !== null
            ? ['estado' => 'confirmado', 'usuario' => $confirmado]
            : ['estado' => 'ya_estaba', 'usuario' => $doc];
    }

    /** Frase para avisar en pantalla cómo resultó un envío de enviar(). */
    public static function textoResultado(array $envio, string $correo): string
    {
        return match ($envio['estado']) {
            'enviado'    => 'Se envió el enlace de confirmación a ' . $correo . '.',
            'espera'     => 'Ya se envió un enlace hace menos de un minuto. Revise el correo, también la carpeta de correo no deseado, o espere un momento antes de pedir otro.',
            'confirmado' => 'El correo ' . $correo . ' ya estaba confirmado.',
            'omitido'    => $envio['motivo'] === 'dominio_reservado'
                ? 'No se envió el enlace: las direcciones de prueba (.test) no reciben correos.'
                : 'No se envió el enlace: el envío de correos no está configurado (faltan las claves de Mailjet).',
            default      => 'No se pudo enviar el enlace a ' . $correo . ' en este momento. Inténtelo de nuevo en un minuto.',
        };
    }
}
