# Despliegue en el servidor del curso (Virtualmin)

Virtualmin es el panel del hosting. Lo que importa es que el servidor tenga PHP con las
extensiones `mongodb` y `redis`, y acceso a un MongoDB (local o Atlas) y a un Redis.

## 1. Revisar el servidor

Por SSH:

```bash
php -v                          # 8.1 o superior
php -m | grep -E "mongodb|redis" # deben aparecer las dos
php -m | grep openssl            # para llamar a Mailjet por HTTPS
php -r 'var_dump((bool) ini_get("allow_url_fopen"));'   # true: la llamada a Mailjet usa file_get_contents
```

Sin SSH: subir un archivo `info.php` con `<?php phpinfo();`, abrirlo, buscar las secciones
`mongodb` y `redis`, y **borrarlo enseguida** (expone la configuración del servidor).

Si falta alguna extensión, hay que pedirla al profesor o al administrador del servidor.

## 2. Copiar el código

Lo recomendado es clonar el repositorio **fuera** de `public_html` y que la raíz del sitio
apunte a `public/`:

```bash
cd ~
git clone https://github.com/USUARIO/voluntacr.git
```

En Virtualmin: *Web Configuration → Website Options → Document directory* = `voluntacr/public`.

Si no se puede cambiar la raíz del sitio, se puede clonar dentro de `public_html`: el `.htaccess`
de la raíz envía todo a `public/` y bloquea `src/`, `scripts/`, `data/`, `docs/`, `storage/` y `.env`.
Después de subirlo, compruebe que `https://SU-SITIO/.env` responda 403.

## 3. Configurar

```bash
cd ~/voluntacr
cp .env.example .env
nano .env
chmod -R u+rwX,g+rwX storage public/uploads
```

- `MONGO_URI`: la del MongoDB del servidor (opción principal, por ejemplo
  `mongodb://USUARIO:CLAVE@127.0.0.1:27017/?authSource=voluntacr`).
  Solo si se decide usar Atlas: clúster en **AWS Oregon (us-west-2)**, en *Network Access* agregar
  **solo la IP del servidor** (nunca `0.0.0.0/0`), y la URI solo en este `.env`.
- `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`: los del Redis compartido.
- `REDIS_PREFIX=grupoX:voluntacr:` con el número real del grupo.
- `APP_DEBUG=false` en el servidor.
- `APP_BASE`: vacío si el sitio está en la raíz del dominio; `/voluntacr` si está en una subcarpeta.
- `APP_URL`: la dirección pública del sitio sin la subcarpeta (por ejemplo `https://su-sitio.cr`).
  Es la base de los enlaces de los correos; si queda mal, los enlaces de confirmación no abren.
- `MAILJET_API_KEY` y `MAILJET_API_SECRET`: en Mailjet, *Account settings > API Key Management*.
- `CORREO_REMITENTE`: una dirección validada en Mailjet (*Account settings > Senders & Domains*).
  Con un dominio propio conviene configurar también SPF y DKIM ahí mismo, para que los correos no
  terminen en correo no deseado.

Sin las claves de Mailjet nadie recibe el enlace de confirmación, y las cuentas nuevas solo se
pueden confirmar con el enlace que la página muestra en modo sin correo (D23). En el servidor
tienen que estar configuradas.

## 4. Crear el esquema y cargar datos

```bash
php scripts/probar_conexiones.php          # también prueba Mailjet, sin enviar ningún correo
php scripts/crear_esquema.php
php scripts/cargar_datos.php --limpiar     # datos de demo, incluye admin@voluntacr.test
```

Para una instalación real sin datos de prueba, crear solo el primer administrador:

```bash
php scripts/crear_admin.php "Nombre Apellido" correo@ejemplo.com "Clave segura"
```

## 5. Tarea programada

En Virtualmin: *System → Scheduled Cron Jobs* (o `crontab -e`):

```
* * * * * php /home/USUARIO/voluntacr/scripts/liberar_reservas.php >> /home/USUARIO/voluntacr/storage/cron.log 2>&1
*/5 * * * * php /home/USUARIO/voluntacr/scripts/enviar_correos.php >> /home/USUARIO/voluntacr/storage/cron.log 2>&1
```

La segunda línea reintenta los correos de los avisos que fallaron (Mailjet no respondió o rechazó el
envío). Los avisos ya están en el portal; el cron solo se encarga del correo.

## 6. Límites de subida

Para subir varias imágenes de 2 MB, en *PHP Options* del dominio: `upload_max_filesize = 4M` y
`post_max_size = 20M`.

## 7. Actualizar después de cada release

```bash
cd ~/voluntacr && git pull && php scripts/crear_esquema.php
```
