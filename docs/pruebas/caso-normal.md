# Pruebas · Caso normal

Responsable: Jafet Alvarado Barboza (Persona 1). Formato y reglas en [README.md](README.md).

| Prueba | Qué comprueba | Estado |
|---|---|---|
| CN-01 | Flujo principal: publicar, reservar, confirmar, pasar lista y ver las horas | Pasa |
| CN-02 | Sin sobrecupo: con la actividad llena se rechaza la reserva, y un cupo liberado se puede volver a tomar | Pasa |
| CN-03 | Una reserva sin confirmar vence y devuelve el cupo | Pasa |

Resultados del 26 de octubre de 2026 en el servidor del curso (https://grupo3.crnube.net/proyecto),
con las pruebas automatizadas de `scripts/pruebas/correr.php` ([cómo se corren](README.md#pruebas-automatizadas)).
El informe completo está en [resultados/pruebas_20261026_201329.txt](resultados/pruebas_20261026_201329.txt).
El script sigue los pasos por HTTP, con una sesión por cuenta, y al título de cada actividad de
prueba le agrega la fecha y la hora de la corrida («Prueba CN-01 1026-2013»). El paso 3 de CN-03,
que pasa en el navegador, se comprobó en Chrome el 28 de octubre.

## Preparación

Vale para las tres pruebas, que se corren en orden y en la misma sesión:

1. Datos de prueba cargados: en Codespaces, `bash scripts/codespaces.sh`; en local,
   `php scripts/cargar_datos.php --limpiar`.
2. `APP_MODO_DEMO=true` en el `.env` (viene así en `.env.example`), para que cada reserva diga qué
   motor la resolvió y en cuánto tiempo.
3. `/estado` dice **Funcionamiento normal**.
4. Dos ventanas del navegador, una normal y una privada, para tener dos sesiones abiertas a la vez:
   - **Ventana A:** `coordinador1@voluntacr.test`, contraseña `Coordina2026`.
   - **Ventana B:** `voluntario1@voluntacr.test`, contraseña `Voluntario2026`.

   Para cambiar de cuenta en una ventana: **Salir** y entrar con la otra.

### CN-01 · Flujo principal: de publicar a ver las horas

- **Objetivo:** comprobar que una actividad nueva recorre el flujo completo y que las horas le
  llegan al voluntario.
- **Precondiciones:** las de la preparación. La actividad se crea para dentro de 1 h 15 min: debe
  empezar al menos una hora después de crearla, y la asistencia se habilita 2 horas antes del
  inicio, así que todo el flujo cabe en una sola sesión.

| # | Paso | Resultado esperado | Resultado obtenido |
|---|---|---|---|
| 1 | Ventana B: abrir **Mis horas** y anotar las horas acumuladas. | Muestra las horas de los datos de prueba, o "Aún no hay horas registradas". | Igual: 2 horas acumuladas, 0 en Limpieza de playas y ríos. |
| 2 | Ventana A: **Panel de mi organización** → **Publicar actividad**. Título "Prueba CN-01", una descripción de al menos 20 caracteres, categoría *Limpieza de playas y ríos*, provincia *Puntarenas*, un lugar, inicio dentro de 1 h 15 min, 3 horas, cupo 2. Clic en **Publicar actividad**. | Mensaje "Actividad publicada. Ahora puede agregarle imágenes." y se abre la edición de la actividad. | Igual. |
| 3 | Ventana A: en **Imágenes**, subir una imagen JPG, PNG o WebP de menos de 2 MB. | Mensaje "1 imagen agregada." y la imagen aparece en la actividad. | Igual. |
| 4 | Ventana B: **Actividades**, buscar "Prueba CN-01". | La actividad aparece en el listado con "2 cupos libres". | Igual. |
| 5 | Ventana B: abrir la actividad y clic en **Reservar cupo**. | Mensaje "Cupo reservado. Confírmelo en los próximos 10 minutos; si no, se libera para otra persona. (filtrado en Redis y confirmado en MongoDB, … ms)". El bloque dice "Cupo apartado" con una cuenta regresiva, y el medidor, "1 cupo libre". | Igual (filtrado en Redis y confirmado en MongoDB, 2.88 ms). |
| 6 | Ventana B: clic en **Confirmar cupo**. | Mensaje "Cupo confirmado. Lo esperamos el día de la actividad. (resuelto solo en MongoDB, … ms)" y el bloque dice "Cupo confirmado". | Igual (resuelto solo en MongoDB, 1.36 ms). |
| 7 | Ventana B: abrir **Mis inscripciones**. | "Prueba CN-01" aparece en **Próximas** con el estado "Confirmada". | Igual. |
| 8 | Ventana A: **Panel de mi organización** → **Inscritos y asistencia** de "Prueba CN-01". | Cifras: confirmados 1, por confirmar 0, presentes 0, cupos libres 1. La casilla de `voluntario1@voluntacr.test` está habilitada. | Igual. |
| 9 | Ventana A: marcar esa casilla y clic en **Guardar asistencia**. | Mensaje "Asistencia guardada (1 cambio)." y la cifra de presentes pasa a 1. | Igual, y además el aviso «Se avisó a 1 persona inscrita.» del módulo de avisos. |
| 10 | Ventana B: abrir **Mis horas**. | Las horas acumuladas suben exactamente 3 respecto del paso 1, la fila "Limpieza de playas y ríos" sube 3 horas y "Prueba CN-01" aparece en **Actividades completadas**. | Igual: de 2 a 5 horas acumuladas, y Limpieza de playas y ríos de 0 a 3. |

- **Evidencia:** [informe de la corrida](resultados/pruebas_20261026_201329.txt), sección CN-01.
- **Estado:** Pasa
- **Fecha y entorno:** 2026-10-26, servidor del curso, con `scripts/pruebas/correr.php`

### CN-02 · Sin sobrecupo: actividad llena y cupo liberado

- **Objetivo:** comprobar que con la actividad llena la reserva se rechaza en Redis, sin descontar
  nada en MongoDB, y que un cupo liberado lo puede tomar otra persona.
- **Precondiciones:** CN-01 terminada.

| # | Paso | Resultado esperado | Resultado obtenido |
|---|---|---|---|
| 1 | Ventana A: publicar "Prueba CN-02" igual que en CN-01, pero con **cupo 1**. | Mensaje "Actividad publicada. Ahora puede agregarle imágenes." | Igual. |
| 2 | Ventana A: **Salir**, entrar como `voluntario2@voluntacr.test` y abrir "Prueba CN-02". No recargar la página hasta el paso 4. | Se ve el botón **Reservar cupo** y "1 cupo libre". | Igual. |
| 3 | Ventana B (voluntario1): abrir "Prueba CN-02" y clic en **Reservar cupo**. | Mensaje "Cupo reservado. …" con "(filtrado en Redis y confirmado en MongoDB, … ms)". El medidor dice "Sin cupos". | Igual (filtrado en Redis y confirmado en MongoDB, 2.57 ms). |
| 4 | Ventana A (voluntario2): clic en **Reservar cupo**, en la página que quedó abierta. | Mensaje "Ya no quedan cupos en esta actividad. (resuelto en Redis sin consultar MongoDB, … ms)" y el bloque dice "Sin cupos disponibles." | Igual (resuelto en Redis sin consultar MongoDB, 2.04 ms). |
| 5 | Ventana B (voluntario1): clic en **Liberar cupo**. | Mensaje "Inscripción cancelada. Su cupo quedó libre para otra persona. (resuelto solo en MongoDB, … ms)" y el medidor vuelve a "1 cupo libre". | Igual (resuelto solo en MongoDB, 2.63 ms). |
| 6 | Ventana A (voluntario2): recargar, clic en **Reservar cupo** y después en **Confirmar cupo**. | La reserva se acepta "(filtrado en Redis y confirmado en MongoDB, … ms)" y queda confirmada. El medidor dice "Sin cupos". | Igual: la reserva, filtrada en Redis y confirmada en MongoDB (2.63 ms); la confirmación, resuelta solo en MongoDB (1.33 ms). |
| 7 | Ventana B: **Actividades**, buscar "Prueba CN-02"; después marcar **Solo con cupo libre**. | Sin el filtro, la actividad aparece con "Sin cupos"; con el filtro, no aparece. | Igual. |

- **Evidencia:** [informe de la corrida](resultados/pruebas_20261026_201329.txt), sección CN-02.
- **Estado:** Pasa
- **Fecha y entorno:** 2026-10-26, servidor del curso, con `scripts/pruebas/correr.php`

### CN-03 · Una reserva sin confirmar vence y devuelve el cupo

- **Objetivo:** comprobar que una reserva que no se confirma a tiempo pasa a vencida y devuelve el
  cupo, con el mismo script que corre el cron del servidor.
- **Precondiciones:**
  - `RESERVA_MINUTOS=1` en el `.env`. En Codespaces o Docker hay que recrear el contenedor después
    de cambiarlo, porque Docker lee el `.env` al crearlo: `docker compose up -d --force-recreate app`.
    Eso cierra las sesiones abiertas, así que hay que volver a entrar en las dos ventanas. En el
    servidor basta con guardar el `.env`.
  - Correr una vez `php scripts/liberar_reservas.php` (en Codespaces,
    `docker compose exec app php scripts/liberar_reservas.php`). Así se liberan las reservas que los
    datos de prueba dejan sin confirmar, y el paso 4 cuenta solo la de esta prueba.
  - **Al terminar:** volver a `RESERVA_MINUTOS=10` (y recrear el contenedor).

| # | Paso | Resultado esperado | Resultado obtenido |
|---|---|---|---|
| 1 | Ventana A: entrar como coordinador1 (con **Salir** antes, si sigue abierta la sesión de voluntario2) y publicar "Prueba CN-03" con **cupo 1**. | Mensaje "Actividad publicada. Ahora puede agregarle imágenes." | Igual. |
| 2 | Ventana B: con la sesión de voluntario1 (entrar de nuevo si se cerró), abrir "Prueba CN-03" y clic en **Reservar cupo**. No confirmar. | Mensaje "Cupo reservado. …", cuenta regresiva de un minuto o menos y el medidor dice "Sin cupos". | Igual, pero la cuenta regresiva fue de 10 minutos (el `RESERVA_MINUTOS` del servidor). |
| 3 | Ventana B: esperar a que la cuenta regresiva llegue a 0:00, sin recargar. | Aparece "El tiempo terminó. Recargue la página para ver si todavía hay cupo." | Igual: con `RESERVA_MINUTOS=1`, la cuenta empezó en 1:00 y al minuto llegó a 0:00 y apareció el aviso, sin recargar. |
| 4 | En una terminal, correr `php scripts/liberar_reservas.php` (en Codespaces, con `docker compose exec app`). | Imprime la fecha y la hora, y "reservas liberadas: 1". Si da más, son reservas de los datos de prueba que vencieron mientras tanto. | Igual: «2026-10-26 20:23:32 reservas liberadas: 1»; la inscripción quedó «expirada» en MongoDB. |
| 5 | Ventana B: recargar la actividad. | El medidor vuelve a "1 cupo libre" y el bloque dice "Su inscripción anterior quedó vencida. Puede reservar de nuevo." | Igual. |
| 6 | Ventana B: abrir **Mis inscripciones**. | "Prueba CN-03" aparece en **Anteriores y canceladas** con el estado "Venció sin confirmar". | Igual. |

- **Evidencia:** [informe de la corrida](resultados/pruebas_20261026_201329.txt), sección CN-03; paso 3:
  [img/CN-03-paso-3.png](img/CN-03-paso-3.png).
- **Estado:** Pasa
- **Fecha y entorno:** 2026-10-26, servidor del curso, con `scripts/pruebas/correr.php`; paso 3:
  2026-10-28, instalación local, en Chrome.
- **Notas:** No se cambió `RESERVA_MINUTOS` a 1 en el `.env` del servidor: la reserva duró 10 minutos y el script esperó a que venciera mientras corrían las demás pruebas. El paso 3 se hizo en la instalación local de la prueba de instalación desde cero (S4), con `RESERVA_MINUTOS=1`.
