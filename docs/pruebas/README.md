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
