/* OV Pointage — ronde : scanner QR intégré (BarcodeDetector ou jsQR), envoi, hors connexion */
(function () {
    'use strict';
    var box = document.querySelector('[data-pt-ronde]');
    var pageP = document.querySelector('[data-pt-passage]');   // page p.php (scan via l'appareil photo du téléphone)
    var root = box || pageP;
    if (!root) return;
    var API = root.getAttribute('data-api');
    var QKEY = 'ovpt_rq_v1_' + root.getAttribute('data-agent');
    var statusEl = root.querySelector('[data-scan-status]');

    function uuid() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) { var r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16); });
    }
    function position() {
        return new Promise(function (resolve) {
            if (!navigator.geolocation) return resolve(null);
            navigator.geolocation.getCurrentPosition(function (p) { resolve(p.coords); }, function () { resolve(null); },
                { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 });
        });
    }
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    function status(html, color) { if (statusEl) { statusEl.innerHTML = html; statusEl.style.color = color || ''; } }
    function post(payload) {
        return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-OV-Pointage': '1' },
            body: JSON.stringify(payload) }).then(function (r) { return r.json(); });
    }
    function queue(payload) {
        var q = [];
        try { q = JSON.parse(localStorage.getItem(QKEY) || '[]'); } catch (e) {}
        payload.hors_ligne = true; q.push(payload);
        try { localStorage.setItem(QKEY, JSON.stringify(q)); } catch (e) {}
    }
    function sync() {
        var q = [];
        try { q = JSON.parse(localStorage.getItem(QKEY) || '[]'); } catch (e) {}
        if (!q.length || !navigator.onLine) return;
        post(q[0]).then(function () {
            q.shift(); localStorage.setItem(QKEY, JSON.stringify(q)); if (q.length) sync();
        }).catch(function () {});
    }
    window.addEventListener('online', sync);
    sync();

    function envoyer(code) {
        var payload = { code: code, uuid: uuid(), ts_appareil: Date.now(), hors_ligne: false };
        status('<i class="fa fa-spinner fa-spin"></i> Enregistrement…');
        if (navigator.vibrate) navigator.vibrate(60);
        return position().then(function (c) {
            if (c) { payload.lat = c.latitude; payload.lng = c.longitude; payload.precision = c.accuracy; }
            if (!navigator.onLine) throw { offline: true };
            return post(payload).catch(function () { throw { offline: true }; });
        }).then(function (res) {
            status((res.ok ? '' : '<i class="fa fa-triangle-exclamation"></i> ') + esc(res.message) + (res.consigne ? '<br><em>' + esc(res.consigne) + '</em>' : ''),
                res.ok ? '#86efac' : '#fca5a5');
            if (res.ok && box) setTimeout(function () { location.reload(); }, 2200);
            return res;
        }).catch(function (e) {
            if (e && e.offline) { queue(payload); status('Pas de réseau : passage gardé sur le téléphone, envoi automatique au retour du réseau.', '#86efac'); }
            else status('Erreur, réessayez.', '#fca5a5');
        });
    }

    // Page p.php : le code arrive dans l'URL
    if (pageP) {
        envoyer(pageP.getAttribute('data-code'));
        return;
    }

    // Scanner intégré
    var video = box.querySelector('[data-video]'), scanBox = box.querySelector('[data-scan-box]');
    var stream = null, detector = null, canvas = document.createElement('canvas'), ctx = canvas.getContext('2d', { willReadFrequently: true });
    function stop() {
        if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
        stream = null; scanBox.hidden = true;
    }
    function done(val) {
        if (!stream) return;
        if (!val) return requestAnimationFrame(tick);
        stop(); envoyer(val);
    }
    function tick() {
        if (!stream) return;
        if (video.readyState < 2) return requestAnimationFrame(tick);
        if (detector) {
            detector.detect(video).then(function (codes) { done(codes.length ? codes[0].rawValue : null); }).catch(function () { done(null); });
        } else if (window.jsQR) {
            canvas.width = video.videoWidth; canvas.height = video.videoHeight;
            ctx.drawImage(video, 0, 0);
            var img = ctx.getImageData(0, 0, canvas.width, canvas.height);
            var found = jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' });
            done(found ? found.data : null);
        } else {
            requestAnimationFrame(tick);
        }
    }
    box.querySelector('[data-scan]').addEventListener('click', function () {
        status('');
        if ('BarcodeDetector' in window) { try { detector = new BarcodeDetector({ formats: ['qr_code'] }); } catch (e) { detector = null; } }
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).then(function (s) {
            stream = s; video.srcObject = s; scanBox.hidden = false; video.play(); requestAnimationFrame(tick);
        }).catch(function () {
            status('Caméra inaccessible. Autorisez la caméra, ou saisissez le code inscrit sous l\'étiquette.', '#fca5a5');
        });
    });
    box.querySelector('[data-scan-stop]').addEventListener('click', stop);
    box.querySelector('[data-code-ok]').addEventListener('click', function () {
        var v = box.querySelector('[data-code-manuel]').value.trim().toUpperCase();
        if (v.length === 16) envoyer(v); else status('Le code fait 16 caractères.', '#fca5a5');
    });
})();
