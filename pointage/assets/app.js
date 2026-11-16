/* OV Pointage — prise / fin de service, GPS, selfie, file d'attente hors connexion */
(function () {
    'use strict';
    var box = document.querySelector('[data-pt-pointage]');
    if (!box) return;

    var API = box.getAttribute('data-api');
    var QKEY = 'ovpt_queue_v1_' + box.getAttribute('data-agent');   // une file par agent (téléphone partagé)
    var btn = box.querySelector('[data-pt-btn]');
    var btnLabel = box.querySelector('[data-pt-btn-label]');
    var statusEl = box.querySelector('[data-pt-status]');
    var chronoEl = box.querySelector('[data-pt-chrono]');
    var enServiceEl = box.querySelector('[data-en-service]');
    var horsServiceEls = box.querySelectorAll('[data-hors-service]');
    var camera = box.querySelector('[data-pt-camera]');
    var selfieOpt = box.querySelector('[data-pt-selfie-opt]');
    var queueEl = document.querySelector('[data-pt-queue]');
    var selfieMode = box.getAttribute('data-selfie');
    var etat = box.getAttribute('data-etat');
    var debutIso = box.getAttribute('data-debut');
    var busy = false;

    /* ---------- File d'attente (pointages faits sans réseau) ---------- */
    function readQueue() {
        try { return JSON.parse(localStorage.getItem(QKEY) || '[]'); } catch (e) { return []; }
    }
    function writeQueue(q) {
        try { localStorage.setItem(QKEY, JSON.stringify(q)); } catch (e) {
            // Stockage plein (photos) : on garde le pointage sans la photo
            q.forEach(function (it) { delete it.photo; });
            try { localStorage.setItem(QKEY, JSON.stringify(q)); } catch (e2) {}
        }
        renderQueue();
    }
    function renderQueue() {
        if (!queueEl) return;
        var n = readQueue().length;
        queueEl.style.display = n ? 'block' : 'none';
        queueEl.innerHTML = '<i class="fa fa-cloud-arrow-up"></i> ' + n + ' pointage' + (n > 1 ? 's' : '') +
            ' en attente d\'envoi. Ils partiront automatiquement dès le retour du réseau.';
    }

    /* ---------- Affichage de l'état ---------- */
    function setEtat(nouvel, debut) {
        etat = nouvel;
        var enService = etat === 'en_service';
        if (btn) {
            btn.classList.toggle('stop', enService);
            btn.classList.toggle('start', !enService);
            btn.querySelector('i').className = 'fa ' + (enService ? 'fa-right-from-bracket' : 'fa-fingerprint');
            btnLabel.textContent = enService ? 'Fin de service' : 'Prise de service';
        }
        if (enServiceEl) enServiceEl.hidden = !enService;
        horsServiceEls.forEach(function (el) { el.hidden = enService; });
        debutIso = enService ? (debut || debutIso || new Date().toISOString()) : '';
        tickChrono();
    }
    function tickChrono() {
        if (!chronoEl || !debutIso) return;
        var s = Math.max(0, Math.floor((Date.now() - new Date(debutIso).getTime()) / 1000));
        var hh = Math.floor(s / 3600), mm = Math.floor(s % 3600 / 60), ss = s % 60;
        chronoEl.textContent = hh + ':' + (mm < 10 ? '0' : '') + mm + ':' + (ss < 10 ? '0' : '') + ss;
    }
    setInterval(tickChrono, 1000);

    function status(txt, cls) {
        if (!statusEl) return;
        statusEl.innerHTML = txt || '';
        statusEl.style.color = cls === 'err' ? '#fca5a5' : (cls === 'ok' ? '#86efac' : '');
    }
    function toast(msg, type) {
        var t = document.createElement('div');
        t.className = 'pt-toast pt-toast-' + (type || 'success');
        t.setAttribute('role', 'status');
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function () { t.remove(); }, 6500);
        if (navigator.vibrate) navigator.vibrate(type === 'danger' ? [80, 60, 80] : 60);
    }

    /* ---------- Outils ---------- */
    function uuid() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16);
        });
    }
    function getPosition() {
        return window.OVPT && OVPT.position ? OVPT.position() : Promise.resolve(null);
    }
    function takePhoto() {
        return new Promise(function (resolve) {
            if (!camera) return resolve(null);
            var done = false;
            function finish(v) { if (!done) { done = true; resolve(v); } }
            camera.value = '';
            camera.onchange = function () {
                var f = camera.files && camera.files[0];
                if (!f) return finish(null);
                compress(f).then(finish, function () { finish(null); });
            };
            camera.addEventListener('cancel', function () { finish(null); }, { once: true });
            camera.click();
        });
    }
    function compress(file) {
        return new Promise(function (resolve, reject) {
            var img = new Image();
            img.onload = function () {
                var max = 800, w = img.width, h = img.height, r = Math.min(1, max / Math.max(w, h));
                var c = document.createElement('canvas');
                c.width = Math.round(w * r); c.height = Math.round(h * r);
                c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
                URL.revokeObjectURL(img.src);
                resolve(c.toDataURL('image/jpeg', 0.7));
            };
            img.onerror = reject;
            img.src = URL.createObjectURL(file);
        });
    }
    function send(payload) {
        return fetch(API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-OV-Pointage': '1' },
            body: JSON.stringify(payload)
        }).then(function (r) {
            return r.json().catch(function () { return { ok: false, message: 'Réponse illisible.' }; })
                .then(function (data) { data._http = r.status; return data; });
        });
    }

    var sansGps = false;
    /* ---------- Pointer ---------- */
    function pointer() {
        if (busy) return;
        var action = etat === 'en_service' ? 'fin' : 'debut';
        var payload = { action: action, uuid: uuid(), ts_appareil: Date.now(), hors_ligne: false };

        if (action === 'debut') {
            var choix = box.querySelector('input[name="pt_site"]:checked');
            var radios = box.querySelectorAll('input[name="pt_site"]');
            if (radios.length && !choix) { status('Choisissez d\'abord votre site.', 'err'); return; }
            payload.mission_id = choix ? parseInt(choix.value, 10) : (parseInt(box.getAttribute('data-site'), 10) || null);
        } else if (!confirm('Confirmer la fin de service ?')) {
            return;
        }

        var wantPhoto = selfieMode === 'obligatoire' || (selfieMode === 'optionnel' && selfieOpt && selfieOpt.checked);
        busy = true;
        btn.classList.add('busy');
        var photoP = wantPhoto ? takePhoto() : Promise.resolve(null);   // la caméra doit s'ouvrir au clic

        photoP.then(function (photo) {
            if (wantPhoto && !photo && selfieMode === 'obligatoire') throw { userMsg: 'Photo annulée : le pointage n\'a pas été fait.' };
            if (photo) payload.photo = photo;
            status('<i class="fa fa-location-crosshairs fa-beat"></i> Localisation…');
            return getPosition();
        }).then(function (pos) {
            sansGps = !pos;
            if (pos) { payload.lat = pos.lat; payload.lng = pos.lng; payload.precision = pos.precision; }
            status('<i class="fa fa-spinner fa-spin"></i> Envoi…');
            if (!navigator.onLine) throw { offline: true };
            return send(payload).catch(function () { throw { offline: true }; });
        }).then(function (res) {
            if (res.ok) {
                status(sansGps && OVPT.geoErreur && OVPT.geoErreur() ? '<i class="fa fa-location-crosshairs"></i> ' + OVPT.geoErreur() : '', 'err');
                toast(res.message, (res.alertes || []).some(function (a) { return a !== 'hors_ligne'; }) ? 'info' : 'success');
                setEtat(res.etat, action === 'debut' ? new Date().toISOString() : '');
                setTimeout(function () { location.reload(); }, sansGps ? 6000 : 2500);
            } else if (res._http === 401 && res.relogin) {
                location.href = document.body.getAttribute('data-base') + '/login.php';
            } else if (res._http === 403 && res.rgpd) {
                location.href = document.body.getAttribute('data-base') + '/rgpd.php';
            } else {
                status(res.message || 'Pointage refusé.', 'err');
                toast(res.message || 'Pointage refusé.', 'danger');
                if (res.etat) setEtat(res.etat);
            }
        }).catch(function (e) {
            if (e && e.offline) {
                payload.hors_ligne = true;
                var q = readQueue(); q.push(payload); writeQueue(q);
                setEtat(action === 'debut' ? 'en_service' : 'hors_service', new Date(payload.ts_appareil).toISOString());
                status('Pas de réseau : pointage gardé sur le téléphone (' + new Date(payload.ts_appareil).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) + ').', 'ok');
                toast('Pointage enregistré sur le téléphone. Envoi automatique dès le retour du réseau.', 'info');
            } else {
                status((e && e.userMsg) || 'Erreur inattendue, réessayez.', 'err');
            }
        }).then(function () {
            busy = false;
            btn.classList.remove('busy');
        });
    }
    if (btn) btn.addEventListener('click', pointer);

    /* ---------- SOS (appui long 2 s) ---------- */
    var sosBtn = document.querySelector('[data-pt-sos]');
    if (sosBtn) {
        var sosTimer = null, sosSent = false;
        var startSos = function (e) {
            e.preventDefault();
            if (sosSent) return;
            sosBtn.classList.add('holding');
            sosTimer = setTimeout(function () {
                sosSent = true;
                sosBtn.classList.remove('holding');
                sosBtn.classList.add('sent');
                if (navigator.vibrate) navigator.vibrate([200, 100, 200]);
                var label = sosBtn.querySelector('.pt-sos-label');
                label.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Envoi de l\'alerte…';
                var payload = { uuid: uuid(), type: 'sos', ts_appareil: Date.now(), hors_ligne: false };
                getPosition().then(function (pos) {
                    if (pos) { payload.lat = pos.lat; payload.lng = pos.lng; payload.precision = pos.precision; }
                    return fetch(sosBtn.getAttribute('data-api'), { method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'X-OV-Pointage': '1' }, body: JSON.stringify(payload) })
                        .then(function (r) { return r.json(); });
                }).then(function (res) {
                    label.innerHTML = '<i class="fa fa-check"></i> ' + (res.ok ? 'Alerte envoyée à vos responsables' : (res.message || 'Échec'));
                }).catch(function () {
                    label.innerHTML = '<i class="fa fa-triangle-exclamation"></i> Pas de réseau : APPELEZ votre responsable';
                }).then(function () {
                    var tel = sosBtn.getAttribute('data-tel');
                    if (tel) setTimeout(function () { if (confirm('Appeler votre responsable maintenant ?')) location.href = 'tel:' + tel; }, 600);
                });
            }, 2000);
        };
        var stopSos = function () { clearTimeout(sosTimer); sosBtn.classList.remove('holding'); };
        sosBtn.addEventListener('pointerdown', startSos);
        ['pointerup', 'pointerleave', 'pointercancel'].forEach(function (ev) { sosBtn.addEventListener(ev, stopSos); });
        sosBtn.addEventListener('contextmenu', function (e) { e.preventDefault(); });
    }

    /* ---------- Notifications push ---------- */
    var pushBox = document.querySelector('[data-pt-push]');
    function b64ToUint8(b64) {
        var pad = '='.repeat((4 - b64.length % 4) % 4), raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
        var arr = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
        return arr;
    }
    if (pushBox && 'serviceWorker' in navigator) {
        var pushApi = pushBox.getAttribute('data-api');
        var supported = 'PushManager' in window && 'Notification' in window;
        var isIos = /iphone|ipad|ipod/i.test(navigator.userAgent);
        var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
        if (!supported && isIos && !standalone) {
            pushBox.hidden = false;
            pushBox.querySelector('[data-pt-push-btn]').hidden = true;
            pushBox.querySelector('[data-pt-push-ios]').hidden = false;
        } else if (supported) {
            navigator.serviceWorker.ready.then(function (reg) {
                return reg.pushManager.getSubscription().then(function (sub) {
                    if (!sub || Notification.permission !== 'granted') pushBox.hidden = false;
                    if (sub && Notification.permission === 'granted') {
                        // Réenregistre l'abonnement (si l'agent a changé de téléphone ou de compte)
                        fetch(pushApi, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-OV-Pointage': '1' },
                            body: JSON.stringify({ qui: 'agent', action: 'enregistrer', subscription: sub.toJSON() }) });
                    }
                });
            });
            pushBox.querySelector('[data-pt-push-btn]').addEventListener('click', function () {
                var btnP = this;
                btnP.disabled = true;
                Notification.requestPermission().then(function (perm) {
                    if (perm !== 'granted') throw new Error('Notifications refusées. Autorisez-les dans les réglages du navigateur.');
                    return fetch(pushApi, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
                }).then(function (k) {
                    return navigator.serviceWorker.ready.then(function (reg) {
                        return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToUint8(k.publicKey) });
                    });
                }).then(function (sub) {
                    return fetch(pushApi, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-OV-Pointage': '1' },
                        body: JSON.stringify({ qui: 'agent', action: 'abonner', subscription: sub.toJSON() }) }).then(function (r) { return r.json(); });
                }).then(function (res) {
                    toast(res.message, res.ok ? 'success' : 'info');
                    if (res.ok) pushBox.hidden = true;
                }).catch(function (e) {
                    toast(e.message || 'Impossible d\'activer les notifications.', 'danger');
                }).then(function () { btnP.disabled = false; });
            });
        }
    }

    /* ---------- Envoi des signalements faits hors connexion ---------- */
    var EVQ = 'ovpt_evq_v1_' + box.getAttribute('data-agent');
    function syncEvenements() {
        var q;
        try { q = JSON.parse(localStorage.getItem(EVQ) || '[]'); } catch (e) { q = []; }
        if (!q.length || !navigator.onLine) return;
        var item = q[0];
        fetch(box.getAttribute('data-api').replace('pointer.php', 'evenement.php'), { method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-OV-Pointage': '1' }, body: JSON.stringify(item) })
            .then(function (r) {
                if (r.status === 401 || r.status === 403 || r.status >= 500) return;
                q.shift();
                try { localStorage.setItem(EVQ, JSON.stringify(q)); } catch (e) {}
                if (q.length) syncEvenements(); else toast('Signalements hors connexion envoyés.', 'success');
            }).catch(function () {});
    }
    window.addEventListener('online', syncEvenements);
    syncEvenements();

    /* ---------- Envoi de la file d'attente ---------- */
    var syncing = false;
    function sync() {
        var q = readQueue();
        if (syncing || !q.length || !navigator.onLine) return;
        syncing = true;
        var item = q[0];
        send(item).then(function (res) {
            if (res.ok || (res._http >= 400 && res._http < 500 && res._http !== 401 && res._http !== 403)) {
                q.shift(); writeQueue(q);
                if (!res.ok) toast('Un pointage hors connexion a été refusé : ' + res.message, 'danger');
                syncing = false;
                if (q.length) sync(); else { toast('Pointages hors connexion envoyés.', 'success'); setTimeout(function () { location.reload(); }, 2000); }
            } else {
                syncing = false;
            }
        }).catch(function () { syncing = false; });
    }

    // Au chargement : l'état réel tient compte des pointages pas encore envoyés
    var pending = readQueue();
    if (pending.length) {
        var last = pending[pending.length - 1];
        setEtat(last.action === 'debut' ? 'en_service' : 'hors_service', new Date(last.ts_appareil).toISOString());
    } else {
        setEtat(etat, debutIso);
    }
    renderQueue();
    window.addEventListener('online', sync);
    setInterval(sync, 30000);
    sync();

    // Déconnexion : on vide la copie locale de la page (téléphone partagé)
    var logout = document.querySelector('[data-pt-logout]');
    if (logout) logout.addEventListener('submit', function () {
        if (window.caches) caches.delete('ovpt-pages');
    });
})();
