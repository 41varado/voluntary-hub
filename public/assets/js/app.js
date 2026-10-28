// VoluntaCR: comportamiento mínimo del lado del navegador. Todo funciona también sin JavaScript.
(function () {
    'use strict';

    // Pide confirmación antes de enviar formularios delicados, en un diálogo de la página (no con
    // window.confirm): <form data-confirmar="¿Pregunta? Detalle opcional.">. El botón que confirma
    // dice lo de data-confirmar-boton o, si falta, lo mismo que el botón del formulario. Es rojo,
    // salvo con data-confirmar-estilo="principal". Sin <dialog>, el formulario se envía sin preguntar.
    (function () {
        var dialogo = document.createElement('dialog');
        if (typeof dialogo.showModal !== 'function') {
            return;
        }
        dialogo.className = 'dialogo';
        dialogo.setAttribute('role', 'alertdialog');
        dialogo.setAttribute('aria-labelledby', 'dialogo-titulo');
        dialogo.setAttribute('aria-describedby', 'dialogo-detalle');
        dialogo.innerHTML = '<div class="dialogo__cuerpo">'
            + '<h2 class="dialogo__titulo" id="dialogo-titulo"></h2>'
            + '<p class="dialogo__detalle" id="dialogo-detalle"></p>'
            + '<div class="acciones dialogo__acciones">'
            + '<button class="boton boton--secundario" type="button" data-dialogo-volver autofocus>Volver</button>'
            + '<button type="button" data-dialogo-confirmar></button>'
            + '</div></div>';
        document.body.appendChild(dialogo);
        var titulo = dialogo.querySelector('.dialogo__titulo');
        var detalle = dialogo.querySelector('.dialogo__detalle');
        var confirmar = dialogo.querySelector('[data-dialogo-confirmar]');
        var pendiente = null;  // formulario y botón de la última vez que se abrió el diálogo
        var confirmado = null; // formulario que ya se confirmó: su envío pasa sin preguntar de nuevo

        document.addEventListener('submit', function (evento) {
            var formulario = evento.target;
            var mensaje = formulario.getAttribute('data-confirmar');
            if (!mensaje || formulario === confirmado) {
                return;
            }
            evento.preventDefault();
            var boton = evento.submitter || formulario.querySelector('[type="submit"]');
            // La pregunta va de título y lo que sigue, debajo.
            var partes = mensaje.match(/^([^?]*\?)\s*([\s\S]*)$/) || [mensaje, mensaje, ''];
            titulo.textContent = partes[1];
            detalle.textContent = partes[2];
            detalle.hidden = partes[2] === '';
            confirmar.textContent = formulario.getAttribute('data-confirmar-boton')
                || (boton ? boton.textContent.trim() : '') || 'Continuar';
            confirmar.className = formulario.getAttribute('data-confirmar-estilo') === 'principal'
                ? 'boton' : 'boton boton--peligro';
            pendiente = { formulario: formulario, boton: boton };
            dialogo.showModal();
        });

        // Se decide en el clic y no en el evento close, que llega después: si el diálogo se cierra y
        // se vuelve a abrir enseguida, ese close atrasado no debe afectar la nueva pregunta.
        confirmar.addEventListener('click', function () {
            var envio = pendiente;
            dialogo.close();
            confirmado = envio.formulario;
            if (envio.formulario.requestSubmit) {
                envio.formulario.requestSubmit(envio.boton || undefined);
            } else {
                envio.formulario.submit();
            }
            confirmado = null;
        });
        // «Volver», Esc (lo hace el navegador) o un clic en el fondo oscuro cierran sin enviar.
        dialogo.querySelector('[data-dialogo-volver]').addEventListener('click', function () {
            dialogo.close();
        });
        dialogo.addEventListener('click', function (evento) {
            if (evento.target === dialogo) {
                dialogo.close();
            }
        });
    })();

    // Galería del detalle: las miniaturas cambian la imagen principal.
    document.querySelectorAll('[data-galeria]').forEach(function (galeria) {
        var principal = galeria.querySelector('.galeria__principal');
        galeria.querySelectorAll('[data-imagen]').forEach(function (boton) {
            boton.addEventListener('click', function () {
                principal.src = boton.getAttribute('data-imagen');
                galeria.querySelectorAll('[data-imagen]').forEach(function (b) {
                    b.setAttribute('aria-pressed', b === boton ? 'true' : 'false');
                });
            });
        });
    });

    // Formulario de usuario: la organización solo se pide para cuentas de coordinación.
    document.querySelectorAll('[data-formulario-rol]').forEach(function (formulario) {
        var bloque = formulario.querySelector('[data-solo-coordinacion]');
        function actualizar() {
            var elegido = formulario.querySelector('input[name="rol"]:checked');
            bloque.hidden = !elegido || elegido.value !== 'coordinador';
        }
        formulario.querySelectorAll('input[name="rol"]').forEach(function (opcion) {
            opcion.addEventListener('change', actualizar);
        });
        actualizar();
    });

    // Cuenta regresiva de una reserva: <span data-expira="segundos-unix">
    document.querySelectorAll('[data-expira]').forEach(function (elemento) {
        var expira = parseInt(elemento.getAttribute('data-expira'), 10) * 1000;
        function actualizar() {
            var restante = Math.max(0, Math.round((expira - Date.now()) / 1000));
            var minutos = Math.floor(restante / 60);
            var segundos = restante % 60;
            elemento.textContent = minutos + ':' + (segundos < 10 ? '0' : '') + segundos;
            if (restante === 0) {
                clearInterval(intervalo);
                var aviso = document.querySelector('[data-al-vencer]');
                if (aviso) { aviso.hidden = false; }
            }
        }
        var intervalo = setInterval(actualizar, 1000);
        actualizar();
    });

    // Asistencia sin conexión: <form data-asistencia-sin-conexion="{actividad}">.
    // Las marcas se guardan en este navegador antes de enviarlas; si no hay señal, se vuelven a
    // intentar al volver la conexión, al recargar la página y cada 30 s. Cada envío lleva la lista
    // completa de presentes, así que repetirlo no duplica nada.
    document.querySelectorAll('[data-asistencia-sin-conexion]').forEach(function (formulario) {
        var clave = 'voluntacr:asistencia:' + formulario.getAttribute('data-asistencia-sin-conexion');
        var estado = document.querySelector('[data-asistencia-estado]');
        var enviando = false;

        // El almacenamiento puede no existir o estar bloqueado (por ejemplo, en navegación privada).
        function leerPendiente() {
            try { return JSON.parse(window.localStorage.getItem(clave) || 'null'); } catch (e) { return null; }
        }
        function guardarPendiente(pendiente) {
            try { window.localStorage.setItem(clave, JSON.stringify(pendiente)); return true; } catch (e) { return false; }
        }
        function borrarPendiente() {
            try { window.localStorage.removeItem(clave); } catch (e) { /* no había nada guardado */ }
        }
        function mostrar(texto, tipo) {
            if (estado) {
                estado.textContent = texto;
                estado.className = 'aviso aviso--' + (tipo || 'aviso');
                estado.hidden = !texto;
            }
        }
        function casillas() {
            return Array.prototype.slice.call(formulario.querySelectorAll('input[name="presentes[]"]'));
        }
        function hora(ms) {
            return new Date(ms).toLocaleTimeString('es-CR', { hour: 'numeric', minute: '2-digit' });
        }

        // diferida: son marcas guardadas antes (al recargar la página o al volver la señal).
        function enviar(pendiente, diferida, guardada) {
            if (enviando) { return; }
            enviando = true;
            casillas().forEach(function (casilla) {
                casilla.checked = pendiente.presentes.indexOf(casilla.value) !== -1;
            });
            mostrar(diferida ? 'Enviando las marcas de las ' + hora(pendiente.guardadaEn) + '…' : 'Guardando la asistencia…');
            fetch(formulario.action, { method: 'POST', body: new FormData(formulario), credentials: 'same-origin' })
                .then(function (respuesta) {
                    enviando = false;
                    // El servidor responde redirigiendo a esta misma lista. Cualquier otra cosa
                    // (formulario vencido, sesión cerrada) deja las marcas guardadas para después.
                    if (!respuesta.ok || respuesta.url.split('#')[0] !== formulario.action) {
                        mostrar('No se pudo guardar la asistencia (código ' + respuesta.status + '). Las marcas siguen en este navegador: recargue la página y, si se lo pide, inicie sesión de nuevo.', 'error');
                        return;
                    }
                    var masNueva = leerPendiente();
                    if (masNueva && masNueva.guardadaEn !== pendiente.guardadaEn) {
                        enviar(masNueva, true, true); // se marcó algo más mientras se enviaba
                        return;
                    }
                    borrarPendiente();
                    try {
                        window.sessionStorage.setItem(clave, diferida
                            ? 'Se enviaron las marcas de las ' + hora(pendiente.guardadaEn) + ' que estaban pendientes.'
                            : 'Asistencia guardada.');
                    } catch (e) { /* solo es el aviso */ }
                    window.location.reload();
                })
                .catch(function () {
                    enviando = false;
                    mostrar(guardada
                        ? 'Sin conexión: las marcas de las ' + hora(pendiente.guardadaEn) + ' quedaron guardadas en este navegador y se enviarán solas al volver la señal.'
                        : 'Sin conexión, y este navegador no permite guardar las marcas. No cierre esta página e intente de nuevo cuando haya señal.', 'error');
                });
        }

        function reintentar() {
            var pendiente = leerPendiente();
            if (pendiente) { enviar(pendiente, true, true); }
        }

        formulario.addEventListener('submit', function (evento) {
            evento.preventDefault();
            var pendiente = {
                presentes: casillas().filter(function (c) { return c.checked; }).map(function (c) { return c.value; }),
                guardadaEn: Date.now()
            };
            enviar(pendiente, false, guardarPendiente(pendiente));
        });
        window.addEventListener('online', reintentar);
        setInterval(reintentar, 30000);

        var aviso = null;
        try {
            aviso = window.sessionStorage.getItem(clave);
            window.sessionStorage.removeItem(clave);
        } catch (e) { /* sin aviso */ }
        if (leerPendiente()) {
            reintentar();
        } else if (aviso) {
            mostrar(aviso, 'exito');
        }
    });
})();
