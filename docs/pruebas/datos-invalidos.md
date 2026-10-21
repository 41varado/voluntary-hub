# Pruebas · Datos inválidos

Responsable: Luis Aguilar Monge (Persona 2). Formato y reglas en [README.md](README.md).

| Prueba | Qué comprueba | Estado |
|---|---|---|
| DI-01 | Una actividad incompleta o con valores fuera de rango no se guarda | Pendiente |
| DI-02 | La fecha de inicio debe estar al menos una hora en el futuro | Pendiente |
| DI-03 | Categoría, provincia y números se validan aunque se manipule el formulario | Pendiente |
| DI-04 | El cupo no puede bajar de los lugares ya ocupados | Pendiente |
| DI-05 | Solo se aceptan imágenes reales, de hasta 2 MB y hasta 6 por actividad | Pendiente |
| DI-06 | El registro rechaza datos inválidos y correos repetidos | Pendiente |
| DI-07 | Inicio de sesión: datos incorrectos, cuenta desactivada y límite de intentos | Pendiente |
| DI-08 | Direcciones y formularios manipulados: identificadores inválidos, otra organización y CSRF | Pendiente |
| DI-09 | MongoDB rechaza con JSON Schema los datos inválidos aunque no pasen por la aplicación | Pendiente |

## Preparación

1. Datos de prueba cargados: en Codespaces, `bash scripts/codespaces.sh`; en local,
   `php scripts/cargar_datos.php --limpiar`.
2. Dos ventanas del navegador:
   - **Ventana A:** `coordinador1@voluntacr.test`, contraseña `Coordina2026`.
   - **Ventana B (privada):** sin sesión.
3. Algunas pruebas usan las herramientas del navegador (F12) para cambiar un campo antes de enviarlo,
   como haría alguien que manipula el formulario. En la pestaña **Red** de F12 se ven los códigos HTTP
   (422, 401…); las páginas de error (403, 404, 419) muestran el código en pantalla.

Los formularios de actividad y de registro no tienen validación del navegador (`novalidate`): todo lo
revisa el servidor.

### DI-01 · Actividad incompleta o con valores fuera de rango

- **Objetivo:** comprobar que el servidor rechaza una actividad incompleta, explica cada error junto a
  su campo y no guarda nada.
- **Precondiciones:** Ventana A en **Panel de mi organización** → **Publicar actividad**.

| # | Paso | Resultado esperado | Resultado obtenido |
|---|---|---|---|
| 1 | Sin llenar nada (las horas y el cupo ya traen 3 y 20), clic en **Publicar actividad**. | Vuelve el formulario (código 422) con "Escriba un título de 5 a 120 caracteres.", "Elija una categoría.", "Indique la fecha y la hora de inicio.", "Elija una provincia.", "Indique el lugar de encuentro (3 a 150 caracteres)." y "Describa la actividad en 20 a 3000 caracteres." | |
| 2 | Título "Hola", descripción "Muy corta", lugar "ab", horas 0, cupo 0, y **Publicar actividad**. | Además de los anteriores: "Las horas deben estar entre 0,5 y 24." y "El cupo debe ser un número entre 1 y 500." Lo escrito se conserva en el formulario. | |
| 3 | Horas 25 y cupo 501. | "Las horas deben estar entre 0,5 y 24." y "El cupo debe ser un número entre 1 y 500." | |
| 4 | Volver a **Panel de mi organización**. | No se creó ninguna actividad. | |

- **Evidencia:** `img/DI-01-paso-2.png`
- **Estado:** Pendiente
- **Fecha y entorno:**

### DI-02 · Fecha de inicio demasiado próxima o pasada

- **Objetivo:** comprobar que una actividad nueva debe empezar al menos una hora después de crearla.
- **Precondiciones:** Ventana A en **Publicar actividad**, con todos los demás campos bien llenos.

| # | Paso | Resultado esperado | Resultado obtenido |
|---|---|---|---|
| 1 | Inicio dentro de 30 minutos y **Publicar actividad**. | "La actividad debe empezar al menos dentro de una hora." | |
| 2 | Inicio ayer. | El mismo mensaje. | |
| 3 | Inicio dentro de 2 horas. | Mensaje "Actividad publicada. Ahora puede agregarle imágenes." | |

- **Evidencia:** `img/DI-02-paso-1.png`
- **Estado:** Pendiente
- **Fecha y entorno:**

### DI-03 · Formulario manipulado: valores fuera de las listas

- **Objetivo:** comprobar que categoría, provincia, título y cupo se validan en el servidor aunque
  alguien cambie el formulario en el navegador.
- **Precondiciones:** Ventana A en **Publicar actividad**, con los demás campos bien llenos.

| # | Paso | Resultado esperado | Resultado obtenido |
|---|---|---|---|
| 1 | Con F12, cambiar el `value` de una opción de **Tipo de actividad** a `piratas` y elegirla; cambiar una opción de **Provincia** para que diga `Narnia` y elegirla. **Publicar actividad**. | "Elija una categoría." y "Elija una provincia." No se crea la actividad. | |
| 2 | Con F12, quitar `maxlength` del campo **Título** y escribir un título de 121 caracteres. | "Escriba un título de 5 a 120 caracteres." | |
| 3 | Con F12, cambiar el `type` del campo **Cupo** de `number` a `text` y escribir `abc`. | "El cupo debe ser un número entre 1 y 500." | |

- **Evidencia:** `img/DI-03-paso-1.png`
- **Estado:** Pendiente
- **Fecha y entorno:**

### DI-04 · El cupo no puede bajar de los lugares ocupados

- **Objetivo:** comprobar que al editar una actividad no se puede dejar un cupo menor que las
  inscripciones que ya tiene.
- **Precondiciones:** en **Panel de mi organización**, una actividad con al menos 2 lugares ocupados
  ("Cupos libres" muestra, por ejemplo, "3 de 10"). Los datos de prueba traen varias.

| # | Paso | Resultado esperado | Resultado obtenido |
|---|---|---|---|
| 1 | **Editar** esa actividad, poner cupo 1 y **Guardar cambios**. | "Ya hay N cupos ocupados; el cupo total no puede ser menor.", con N = cupo total menos cupos libres. | |
| 2 | Volver a **Panel de mi organización**. | El cupo de la actividad no cambió. | |

- **Evidencia:** `img/DI-04-paso-1.png`
- **Estado:** Pendiente
- **Fecha y entorno:**

### DI-05 · Imágenes inválidas

- **Objetivo:** comprobar que solo se aceptan imágenes reales (el tipo se revisa por el contenido, no
  por la extensión), de hasta 2 MB y hasta 6 por actividad.
- **Precondiciones:** Ventana A en la edición de una actividad propia sin imágenes, por ejemplo una
  creada en DI-02.

| # | Paso | Resultado esperado | Resultado obtenido |
|---|---|---|---|
| 1 | Crear un archivo de texto, renombrarlo `foto.jpg` y subirlo en **Imágenes**. | "«foto.jpg» no es una imagen JPG, PNG o WebP." No se agrega nada. | |
| 2 | Crear `prueba.php` con el texto `<?php phpinfo();`, renombrarlo `prueba.png` y subirlo. | "«prueba.png» no es una imagen JPG, PNG o WebP." | |
| 3 | Subir una imagen real de más de 2 MB. | "«nombre del archivo» pesa más de 2 MB." | |
| 4 | Elegir 7 imágenes válidas de una vez. | "Cada actividad admite hasta 6 imágenes; solo se procesarán 6." y "6 imágenes agregadas." | |

- **Evidencia:** `img/DI-05-paso-1.png` y `img/DI-05-paso-4.png`
- **Estado:** Pendiente
- **Fecha y entorno:**

### DI-06 · Registro con datos inválidos

- **Objetivo:** comprobar que el registro público rechaza datos inválidos y correos que ya existen.
- **Precondiciones:** Ventana B en **Crear cuenta**.

| # | Paso | Resultado esperado | Resultado obtenido |
|---|---|---|---|
| 1 | Nombre "Al", correo "no-es-correo", contraseña "corta" en los dos campos, y **Crear cuenta**. | Vuelve el formulario (código 422) con "Escriba el nombre (3 a 80 caracteres).", "Escriba un correo válido, por ejemplo nombre@correo.com." y "La contraseña necesita al menos 8 caracteres." | |
| 2 | Datos válidos, pero la confirmación de la contraseña distinta. | "Las contraseñas no coinciden." | |
| 3 | Datos válidos con el correo `voluntario1@voluntacr.test`. | "Ya existe una cuenta con ese correo. Inicie sesión." No se crea otra cuenta. | |

- **Evidencia:** `img/DI-06-paso-1.png`
- **Estado:** Pendiente
- **Fecha y entorno:**

### DI-07 · Inicio de sesión: datos incorrectos, cuenta desactivada y límite de intentos

- **Objetivo:** comprobar los mensajes de un inicio de sesión fallido y el bloqueo después de 5
  intentos (decisión D13).
- **Precondiciones:** Ventana B en **Iniciar sesión**. Redis funcionando, porque el límite de intentos
  vive en Redis. El bloqueo dura 15 minutos; para repetir la prueba antes, recargar los datos de
  prueba, que borra las claves del grupo.

| # | Paso | Resultado esperado | Resultado obtenido |
|---|---|---|---|
| 1 | `voluntario2@voluntacr.test` con una contraseña incorrecta. | Código 401 y "El correo o la contraseña no coinciden." | |
| 2 | Un correo que no existe, `nadie@voluntacr.test`. | El mismo mensaje: no revela si el correo existe. | |
| 3 | `voluntario60@voluntacr.test` con su contraseña correcta, `Voluntario2026`. | "Esta cuenta está desactivada. Si cree que es un error, comuníquese con la administración." | |
| 4 | Fallar 4 veces más con `voluntario2@voluntacr.test` (5 en total) y después intentar con su contraseña correcta. | "Demasiados intentos fallidos con este correo. Espere 15 minutos e inténtelo de nuevo.", aunque la contraseña sea correcta. | |
| 5 | `voluntario3@voluntacr.test` con su contraseña correcta. | Entra: el bloqueo es solo para el correo que falló. | |

- **Evidencia:** `img/DI-07-paso-4.png`
- **Estado:** Pendiente
- **Fecha y entorno:**

### DI-08 · Direcciones y formularios manipulados

- **Objetivo:** comprobar que un identificador inválido, la actividad de otra organización o un
  formulario sin token CSRF no pasan.
- **Precondiciones:** las de la preparación.

| # | Paso | Resultado esperado | Resultado obtenido |
|---|---|---|---|
| 1 | Ventana B: abrir `/actividades/123`. | Página 404: "Esa actividad no existe o ya no está publicada." | |
| 2 | Ventana A: abrir `/panel/actividades/abc/editar`. | Página 404: "Esa actividad no existe." | |
| 3 | Ventana A: en **Actividades**, abrir una actividad de otra organización, copiar el identificador de la dirección y abrir `/panel/actividades/{identificador}/editar`. | Página 403: "Solo la organización que publicó la actividad puede modificarla." | |
| 4 | Ventana A: en la edición de una actividad propia, con F12 borrar el campo oculto `_csrf` del formulario principal y **Guardar cambios**. | Página 419: "El formulario expiró. Recargue la página y vuelva a intentarlo." No se guarda nada. | |

- **Evidencia:** `img/DI-08-paso-3.png` y `img/DI-08-paso-4.png`
- **Estado:** Pendiente
- **Fecha y entorno:**

### DI-09 · JSON Schema: MongoDB rechaza datos inválidos

- **Objetivo:** comprobar que la base de datos rechaza datos inválidos aunque se escriban sin pasar
  por la aplicación (validadores de `scripts/crear_esquema.php`).
- **Precondiciones:** abrir `mongosh` sobre la base. En Codespaces:
  `docker compose exec mongo mongosh voluntacr`. En el servidor: `mongosh` con la URI del `.env`.

| # | Paso | Resultado esperado | Resultado obtenido |
|---|---|---|---|
| 1 | `db.getCollectionInfos({ name: "actividades" })[0].options.validator.$jsonSchema.required` | La lista de campos obligatorios de una actividad. | |
| 2 | `db.actividades.updateOne({ estado: "publicada" }, { $set: { cuposDisponibles: -1 } })` | Error `Document failed validation` (código 121). El documento no cambia. | |
| 3 | `db.actividades.updateOne({ estado: "publicada" }, [{ $set: { cuposDisponibles: { $add: ["$cupoTotal", 1] } } }])` | Error `Document failed validation`: más cupos libres que el cupo total (regla `$expr`). | |
| 4 | `db.actividades.insertOne({ titulo: "Hola" })` | Error `Document failed validation`: faltan campos obligatorios y el título es demasiado corto. | |
| 5 | `db.inscripciones.updateOne({}, { $set: { estado: "inventado" } })` | Error `Document failed validation`: ese estado no está en la lista. | |
| 6 | `db.usuarios.updateOne({ rol: "voluntario" }, { $set: { correo: "sin-arroba" } })` | Error `Document failed validation`: el correo no cumple el patrón. | |

- **Evidencia:** `img/DI-09-pasos-2-a-6.png`
- **Estado:** Pendiente
- **Fecha y entorno:**
