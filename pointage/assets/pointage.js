/* OV Pointage — JS commun de l'application agent */
(function () {
    'use strict';
    var base = document.body.getAttribute('data-base') || '';

    // Service worker (application installable + mode hors connexion)
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register(base + '/sw.js').catch(function () {});
        });
    }

    // Horloge en direct
    var clock = document.querySelector('[data-pt-clock]');
    var dateEl = document.querySelector('[data-pt-date]');
    function tick() {
        var d = new Date();
        if (clock) clock.textContent = d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        if (dateEl) dateEl.textContent = d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' });
    }
    if (clock || dateEl) { tick(); setInterval(tick, 1000); }

    // Champs PIN : chiffres uniquement, 4 maximum
    document.querySelectorAll('input[data-pin]').forEach(function (el) {
        el.addEventListener('input', function () {
            el.value = el.value.replace(/\D/g, '').slice(0, 4);
        });
    });

    // Aide à l'installation sur l'écran d'accueil
    var installBox = document.querySelector('[data-pt-install]');
    var isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    var deferredPrompt = null;
    if (installBox && !isStandalone) {
        var isIos = /iphone|ipad|ipod/i.test(navigator.userAgent);
        var iosHelp = installBox.querySelector('[data-ios]');
        var btn = installBox.querySelector('[data-install-btn]');
        if (isIos && iosHelp) {
            iosHelp.style.display = 'block';
            installBox.classList.add('show');
        }
        window.addEventListener('beforeinstallprompt', function (e) {
            e.preventDefault();
            deferredPrompt = e;
            if (btn) btn.style.display = 'flex';
            installBox.classList.add('show');
        });
        if (btn) btn.addEventListener('click', function () {
            if (!deferredPrompt) return;
            deferredPrompt.prompt();
            deferredPrompt.userChoice.finally(function () { installBox.classList.remove('show'); deferredPrompt = null; });
        });
    }
})();
