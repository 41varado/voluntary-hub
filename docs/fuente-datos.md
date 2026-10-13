# Fuente de datos

El proyecto no usa datos personales reales. Todo lo que hay en la base se genera con
`scripts/cargar_datos.php` a partir de los archivos de `data/`.

| Archivo | Origen | Contenido |
|---|---|---|
| `data/cantones_cr.json` | Dato público: División Territorial Administrativa de Costa Rica | Las 7 provincias y sus 84 cantones. Se usan para generar lugares. |
| `data/nombres_sinteticos.json` | Elaborado por el equipo | Nombres y apellidos comunes que se combinan al azar. |
| `data/organizaciones_ficticias.json` | Elaborado por el equipo | 6 organizaciones inventadas con su provincia y tipos de actividad. |
| `data/plantillas_actividades.json` | Elaborado por el equipo | 2 plantillas de actividad por categoría (título, lugar, descripción, requisitos, horas). |

## Generador

```bash
php scripts/cargar_datos.php --limpiar                       # datos por defecto
php scripts/cargar_datos.php --limpiar --voluntarios=500     # más volumen
php scripts/cargar_datos.php --limpiar --semilla=7           # otro conjunto reproducible
```

- **Reproducible:** con la misma semilla (`--semilla`, por defecto 2026) se generan los mismos
  nombres, actividades e inscripciones. Las fechas se calculan a partir del día en que se ejecuta.
- **Correos con el dominio reservado `.test`** (RFC 2606): no existen ni pueden recibir correo.
- **Datos por defecto:** 1 administrador, 6 organizaciones, 6 coordinadores, 60 voluntarios, 42 actividades
  (una parte ya realizadas, una el mismo día, una en borrador por organización) y unas 340
  inscripciones con asistencia registrada en las actividades pasadas. Algunas actividades quedan
  llenas o con un solo cupo para la demo.

## Cuentas de prueba

| Rol | Correo | Contraseña |
|---|---|---|
| Administración | `admin@voluntacr.test` | `Admin2026` |
| Coordinación | `coordinador1@voluntacr.test` … `coordinador6@voluntacr.test` | `Coordina2026` |
| Voluntario | `voluntario1@voluntacr.test` … `voluntario60@voluntacr.test` (la 60 está desactivada) | `Voluntario2026` |

Estas credenciales son solo para la demo y se pueden publicar.
