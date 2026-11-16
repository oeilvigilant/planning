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
        return window.OVPT && OVPT.position ? OVPT.position() : Promise.resolve(null);
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

    function envoyer(code, extra) {
        var payload = { code: code || '', uuid: uuid(), ts_appareil: Date.now(), hors_ligne: false };
        if (extra) Object.keys(extra).forEach(function (k) { payload[k] = extra[k]; });
        status('<i class="fa fa-spinner fa-spin"></i> Enregistrement…');
        if (navigator.vibrate) navigator.vibrate(60);
        return position().then(function (c) {
            if (c) { payload.lat = c.lat; payload.lng = c.lng; payload.precision = c.precision; }
            if (!navigator.onLine) throw { offline: true };
            return post(payload).catch(function () { throw { offline: true }; });
        }).then(function (res) {
            var gpsInfo = !payload.lat && OVPT.geoErreur && OVPT.geoErreur() ? '<br><small><i class="fa fa-location-crosshairs"></i> ' + esc(OVPT.geoErreur()) + '</small>' : '';
            status((res.ok ? '' : '<i class="fa fa-triangle-exclamation"></i> ') + esc(res.message) + (res.consigne ? '<br><em>' + esc(res.consigne) + '</em>' : '') + gpsInfo,
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

    // Modes « photo » et « validation simple » : un bouton par point
    var photoInput = box.querySelector('[data-photo-point]'), pointEnCours = null;
    function compress(file) {
        return new Promise(function (resolve, reject) {
            var img = new Image();
            img.onload = function () {
                var r = Math.min(1, 1280 / Math.max(img.width, img.height)), c = document.createElement('canvas');
                c.width = Math.round(img.width * r); c.height = Math.round(img.height * r);
                var x = c.getContext('2d'); x.drawImage(img, 0, 0, c.width, c.height);
                x.font = Math.max(14, Math.round(c.width / 40)) + 'px sans-serif';
                x.fillStyle = 'rgba(0,0,0,.55)'; x.fillRect(0, c.height - Math.round(c.width / 22), c.width, Math.round(c.width / 22));
                x.fillStyle = '#fff'; x.fillText(new Date().toLocaleString('fr-FR'), 10, c.height - Math.round(c.width / 70));
                URL.revokeObjectURL(img.src); resolve(c.toDataURL('image/jpeg', 0.72));
            };
            img.onerror = reject; img.src = URL.createObjectURL(file);
        });
    }
    document.querySelectorAll('[data-point]').forEach(function (b) {
        b.addEventListener('click', function () {
            pointEnCours = parseInt(b.getAttribute('data-point'), 10);
            if (box.getAttribute('data-mode') === 'photo' && photoInput) { photoInput.value = ''; photoInput.click(); }
            else { b.disabled = true; envoyer('', { point_id: pointEnCours }); }
        });
    });
    if (photoInput) photoInput.addEventListener('change', function () {
        var f = photoInput.files && photoInput.files[0];
        if (!f || !pointEnCours) return;
        status('<i class="fa fa-spinner fa-spin"></i> Préparation de la photo…');
        compress(f).then(function (ph) { envoyer('', { point_id: pointEnCours, photo: ph }); });
    });
    if (!box.querySelector('[data-scan]')) return;

    // Scanner intégré (mode QR)
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
