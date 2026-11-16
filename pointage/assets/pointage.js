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

    /* ---------- Localisation partagée (pointage, rondes, signalements) ----------
     * Le GPS est « préchauffé » dès l'ouverture de l'écran : au moment du clic, une
     * position récente est déjà disponible. Sinon : essai haute précision, puis
     * second essai réseau/Wi-Fi (plus rapide à l'intérieur). La raison d'un échec
     * est gardée pour l'expliquer à l'agent. */
    var best = null, geoErr = null, watchId = null;
    function keep(p) {
        var c = { lat: p.coords.latitude, lng: p.coords.longitude, precision: Math.round(p.coords.accuracy), t: Date.now() };
        if (!best || Date.now() - best.t > 120000 || c.precision <= best.precision * 1.5) best = c;
        geoErr = null;
        majIndicateur();
    }
    function errMsg(e) {
        if (!navigator.geolocation) return 'Ce téléphone ne permet pas la localisation.';
        if (e && e.code === 1) return 'Localisation refusée : autorisez la position pour ce site (réglages du navigateur → Autorisations → Position).';
        if (e && e.code === 2) return 'Position introuvable : activez la localisation (GPS) du téléphone.';
        return 'GPS trop lent : rapprochez-vous d\'une fenêtre ou sortez quelques secondes.';
    }
    var indic = document.querySelector('[data-pt-gps]');
    function majIndicateur() {
        if (!indic) return;
        if (best && Date.now() - best.t < 120000) {
            indic.className = 'pt-gps ok';
            indic.innerHTML = '<i class="fa fa-location-dot"></i> Position OK (± ' + best.precision + ' m)';
        } else if (geoErr) {
            indic.className = 'pt-gps err';
            indic.innerHTML = '<i class="fa fa-location-crosshairs"></i> ' + geoErr;
        } else {
            indic.className = 'pt-gps';
            indic.innerHTML = '<i class="fa fa-location-crosshairs fa-beat"></i> Recherche de la position…';
        }
    }
    function demarrer() {
        if (!navigator.geolocation) { geoErr = errMsg(null); majIndicateur(); return; }
        if (watchId !== null) return;
        watchId = navigator.geolocation.watchPosition(keep, function (e) { if (!best) { geoErr = errMsg(e); majIndicateur(); } },
            { enableHighAccuracy: true, maximumAge: 15000, timeout: 30000 });
    }
    function une(opts) {
        return new Promise(function (resolve) {
            navigator.geolocation.getCurrentPosition(function (p) { keep(p); resolve(true); },
                function (e) { geoErr = errMsg(e); resolve(e && e.code === 1 ? 'refus' : false); }, opts);
        });
    }
    function recente(ms) { return best && Date.now() - best.t < ms ? best : null; }
    window.OVPT = window.OVPT || {};
    /** Promise<{lat,lng,precision}|null> ; OVPT.geoErreur() donne la raison d'un échec. */
    window.OVPT.position = function () {
        if (!navigator.geolocation) { geoErr = errMsg(null); return Promise.resolve(null); }
        demarrer();
        if (recente(60000) && best.precision <= 100) return Promise.resolve(best);
        return une({ enableHighAccuracy: true, timeout: 12000, maximumAge: 30000 }).then(function (r) {
            if (r === true) return best;
            if (r === 'refus') return recente(300000);
            return une({ enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 }).then(function () { return recente(300000); });
        }).then(function (p) { majIndicateur(); return p; });
    };
    window.OVPT.geoErreur = function () { return geoErr; };
    if (document.querySelector('[data-pt-geo-auto]')) { majIndicateur(); demarrer(); }

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
