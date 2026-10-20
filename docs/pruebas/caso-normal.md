# Pruebas · Caso normal

Responsable: Jafet Alvarado Barboza (Persona 1). Formato y reglas en [README.md](README.md).

| Prueba | Qué comprueba | Estado |
|---|---|---|
| CN-01 | Flujo principal: publicar, reservar, confirmar, pasar lista y ver las horas | Pendiente |
| CN-02 | Sin sobrecupo: con la actividad llena se rechaza la reserva, y un cupo liberado se puede volver a tomar | Pendiente |
| CN-03 | Una reserva sin confirmar vence y devuelve el cupo | Pendiente |

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
| 1 | Ventana B: abrir **Mis horas** y anotar las horas acumuladas. | Muestra las horas de los datos de prueba, o "Aún no hay horas registradas". | |
| 2 | Ventana A: **Panel de mi organización** → **Publicar actividad**. Título "Prueba CN-01", una descripción de al menos 20 caracteres, categoría *Limpieza de playas y ríos*, provincia *Puntarenas*, un lugar, inicio dentro de 1 h 15 min, 3 horas, cupo 2. Clic en **Publicar actividad**. | Mensaje "Actividad publicada. Ahora puede agregarle imágenes." y se abre la edición de la actividad. | |
| 3 | Ventana A: en **Imágenes**, subir una imagen JPG, PNG o WebP de menos de 2 MB. | Mensaje "1 imagen agregada." y la imagen aparece en la actividad. | |
| 4 | Ventana B: **Actividades**, buscar "Prueba CN-01". | La actividad aparece en el listado con "2 cupos libres". | |
| 5 | Ventana B: abrir la actividad y clic en **Reservar cupo**. | Mensaje "Cupo reservado. Confírmelo en los próximos 10 minutos; si no, se libera para otra persona. (filtrado en Redis y confirmado en MongoDB, … ms)". El bloque dice "Cupo apartado" con una cuenta regresiva, y el medidor, "1 cupo libre". | |
| 6 | Ventana B: clic en **Confirmar cupo**. | Mensaje "Cupo confirmado. Lo esperamos el día de la actividad. (resuelto solo en MongoDB, … ms)" y el bloque dice "Cupo confirmado". | |
| 7 | Ventana B: abrir **Mis inscripciones**. | "Prueba CN-01" aparece en **Próximas** con el estado "Confirmada". | |
| 8 | Ventana A: **Panel de mi organización** → **Inscritos y asistencia** de "Prueba CN-01". | Cifras: confirmados 1, por confirmar 0, presentes 0, cupos libres 1. La casilla de `voluntario1@voluntacr.test` está habilitada. | |
| 9 | Ventana A: marcar esa casilla y clic en **Guardar asistencia**. | Mensaje "Asistencia guardada (1 cambio)." y la cifra de presentes pasa a 1. | |
| 10 | Ventana B: abrir **Mis horas**. | Las horas acumuladas suben exactamente 3 respecto del paso 1, la fila "Limpieza de playas y ríos" sube 3 horas y "Prueba CN-01" aparece en **Actividades completadas**. | |

- **Evidencia:** `img/CN-01-paso-5.png` (reserva con el motor que la resolvió), `img/CN-01-paso-9.png`
  y `img/CN-01-paso-10.png`.
- **Estado:** Pendiente
- **Fecha y entorno:**

### CN-02 · Sin sobrecupo: actividad llena y cupo liberado

- **Objetivo:** comprobar que con la actividad llena la reserva se rechaza en Redis, sin descontar
  nada en MongoDB, y que un cupo liberado lo puede tomar otra persona.
- **Precondiciones:** CN-01 terminada.

| # | Paso | Resultado esperado | Resultado obtenido |
|---|---|---|---|
| 1 | Ventana A: publicar "Prueba CN-02" igual que en CN-01, pero con **cupo 1**. | Mensaje "Actividad publicada. Ahora puede agregarle imágenes." | |
| 2 | Ventana A: **Salir**, entrar como `voluntario2@voluntacr.test` y abrir "Prueba CN-02". No recargar la página hasta el paso 4. | Se ve el botón **Reservar cupo** y "1 cupo libre". | |
| 3 | Ventana B (voluntario1): abrir "Prueba CN-02" y clic en **Reservar cupo**. | Mensaje "Cupo reservado. …" con "(filtrado en Redis y confirmado en MongoDB, … ms)". El medidor dice "Sin cupos". | |
| 4 | Ventana A (voluntario2): clic en **Reservar cupo**, en la página que quedó abierta. | Mensaje "Ya no quedan cupos en esta actividad. (resuelto en Redis sin consultar MongoDB, … ms)" y el bloque dice "Sin cupos disponibles." | |
| 5 | Ventana B (voluntario1): clic en **Liberar cupo**. | Mensaje "Inscripción cancelada. Su cupo quedó libre para otra persona. (resuelto solo en MongoDB, … ms)" y el medidor vuelve a "1 cupo libre". | |
| 6 | Ventana A (voluntario2): recargar, clic en **Reservar cupo** y después en **Confirmar cupo**. | La reserva se acepta "(filtrado en Redis y confirmado en MongoDB, … ms)" y queda confirmada. El medidor dice "Sin cupos". | |
| 7 | Ventana B: **Actividades**, buscar "Prueba CN-02"; después marcar **Solo con cupo libre**. | Sin el filtro, la actividad aparece con "Sin cupos"; con el filtro, no aparece. | |

- **Evidencia:** `img/CN-02-paso-4.png` (rechazo resuelto en Redis) y `img/CN-02-paso-7.png`.
- **Estado:** Pendiente
- **Fecha y entorno:**

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
| 1 | Ventana A: entrar como coordinador1 (con **Salir** antes, si sigue abierta la sesión de voluntario2) y publicar "Prueba CN-03" con **cupo 1**. | Mensaje "Actividad publicada. Ahora puede agregarle imágenes." | |
| 2 | Ventana B: con la sesión de voluntario1 (entrar de nuevo si se cerró), abrir "Prueba CN-03" y clic en **Reservar cupo**. No confirmar. | Mensaje "Cupo reservado. …", cuenta regresiva de un minuto o menos y el medidor dice "Sin cupos". | |
| 3 | Ventana B: esperar a que la cuenta regresiva llegue a 0:00, sin recargar. | Aparece "El tiempo terminó. Recargue la página para ver si todavía hay cupo." | |
| 4 | En una terminal, correr `php scripts/liberar_reservas.php` (en Codespaces, con `docker compose exec app`). | Imprime la fecha y la hora, y "reservas liberadas: 1". Si da más, son reservas de los datos de prueba que vencieron mientras tanto. | |
| 5 | Ventana B: recargar la actividad. | El medidor vuelve a "1 cupo libre" y el bloque dice "Su inscripción anterior quedó vencida. Puede reservar de nuevo." | |
| 6 | Ventana B: abrir **Mis inscripciones**. | "Prueba CN-03" aparece en **Anteriores y canceladas** con el estado "Venció sin confirmar". | |

- **Evidencia:** `img/CN-03-paso-4.png` (salida del script) y `img/CN-03-paso-5.png`.
- **Estado:** Pendiente
- **Fecha y entorno:**
