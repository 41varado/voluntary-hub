// VoluntaCR: comportamiento mínimo del lado del navegador. Todo funciona también sin JavaScript.
(function () {
    'use strict';

    // Pide confirmación antes de enviar formularios delicados: <form data-confirmar="¿Seguro?">
    document.addEventListener('submit', function (evento) {
        var mensaje = evento.target.getAttribute('data-confirmar');
        if (mensaje && !window.confirm(mensaje)) {
            evento.preventDefault();
        }
    });

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
})();
