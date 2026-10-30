# Pruebas

Pruebas documentadas del seguimiento 3: el caso normal, los errores de conexión y los datos
inválidos. Cada archivo tiene una sola persona responsable, que es la única que lo edita.

| Archivo | Qué prueba | Identificadores | Responsable |
|---|---|---|---|
| [caso-normal.md](caso-normal.md) | El flujo principal de punta a punta | `CN-01`, `CN-02`… | Jafet |
| [error-conexion.md](error-conexion.md) | Qué hace la aplicación con Redis caído y con MongoDB caído | `EC-01`… | Manuel |
| [datos-invalidos.md](datos-invalidos.md) | Formularios con datos incorrectos, imagen falsa y validación JSON Schema | `DI-01`… | Luis |

## Dónde se corren

En el Codespace de cada quien (`bash scripts/codespaces.sh` levanta todo y carga los datos de
prueba) o en el servidor del curso cuando esté desplegado. Las cuentas están en la sección
[Datos de prueba](../../README.md#datos-de-prueba) del README.

Nunca se corre `cargar_datos.php --limpiar` en el servidor mientras otra persona prueba: borra la
base y todas las claves del grupo en Redis.

## Pruebas automatizadas

`scripts/pruebas/correr.php` sigue los pasos de los tres documentos por HTTP, como un navegador, con
las cuentas de los datos de prueba, y anota lo que obtuvo en cada paso:

```bash
php scripts/pruebas/correr.php                                                # en el servidor, con su .env
docker compose exec app php scripts/pruebas/correr.php --url=http://localhost   # en Codespaces
```

- En el servidor (o en Codespaces contra su propia aplicación) también corren las pruebas que leen o
  escriben directo en MongoDB y Redis: EC-03, EC-04, DI-09 y el paso 4 de CN-03 y de EC-06. Desde
  otro equipo, con `--url=https://…`, esas se omiten solas.
- Los pasos que necesitan pausar o detener un motor (EC-01 pasos 2 a 6, EC-02, EC-05 y EC-06 pasos 2
  y 3) se omiten: se prueban a mano.
- Tarda unos 10 minutos porque CN-03 espera a que venza su reserva (`RESERVA_MINUTOS`) mientras
  corren las demás. Con `--sin-espera` termina en un par de minutos, sin los pasos 4 a 6 de CN-03.
  `--solo=CN-01,DI` corre solo esas pruebas o grupos.
- Crea actividades «Prueba XX-NN mmdd-hhmm» de coordinador1 y al final las elimina, o las cancela si
  tienen inscripciones (`--conservar` las deja). DI-07 bloquea 15 minutos el correo de voluntario2;
  si el script tiene acceso al Redis de la aplicación, quita el bloqueo al terminar.
- El informe queda en `docs/pruebas/resultados/` y sirve de evidencia para la columna «Resultado
  obtenido».

## Cómo documentar una prueba

Copie este bloque en su archivo y complételo. La columna "Resultado obtenido" se llena al correr la
prueba: si coincide con lo esperado, basta con "Igual"; si no, se describe lo que pasó.

```markdown
### XX-NN · Nombre corto de la prueba

- **Objetivo:** qué se comprueba.
- **Precondiciones:** entorno, datos y configuración que hacen falta.

| # | Paso | Resultado esperado | Resultado obtenido |
|---|---|---|---|
| 1 | … | … | |

- **Evidencia:** `img/XX-NN-paso-N.png`
- **Estado:** Pendiente / Pasa / Falla
- **Fecha y entorno:** AAAA-MM-DD, Codespaces o servidor del curso
```

Las capturas van en `docs/pruebas/img/` y su nombre empieza con el identificador de la prueba, así
nadie reemplaza las de otra persona.

## Si una prueba falla

Se anota **Falla** con lo que pasó y se avisa a la persona responsable del archivo donde está el
error. En `src/modulos/` lo indica el comentario "Responsable" al inicio de cada archivo, y los
nombres de cada persona están en la [bitácora S2](../seguimientos/S2.md). Quien prueba no corrige
código de otro módulo. Cuando llega el arreglo, se vuelve a correr la prueba y se anota el enlace
al pull request.
