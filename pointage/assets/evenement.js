/* OV Pointage — signalement d'événement / main courante (photos, GPS, hors connexion) */
(function () {
    'use strict';
    var form = document.querySelector('[data-pt-evenement]');
    if (!form) return;
    var API = form.getAttribute('data-api');
    var QKEY = 'ovpt_evq_v1_' + form.getAttribute('data-agent');
    var photosBox = form.querySelector('[data-photos]');
    var input = form.querySelector('[data-photo-input]');
    var statusEl = form.querySelector('[data-status]');
    var submit = form.querySelector('[data-submit]');
    var photos = [];

    function compress(file) {
        return new Promise(function (resolve, reject) {
            var img = new Image();
            img.onload = function () {
                var max = 1280, r = Math.min(1, max / Math.max(img.width, img.height));
                var c = document.createElement('canvas');
                c.width = Math.round(img.width * r); c.height = Math.round(img.height * r);
                var ctx = c.getContext('2d');
                ctx.drawImage(img, 0, 0, c.width, c.height);
                // Horodatage incrusté (preuve)
                var txt = new Date().toLocaleString('fr-FR');
                ctx.font = Math.max(14, Math.round(c.width / 40)) + 'px sans-serif';
                ctx.fillStyle = 'rgba(0,0,0,0.55)';
                ctx.fillRect(0, c.height - Math.round(c.width / 22), c.width, Math.round(c.width / 22));
                ctx.fillStyle = '#fff';
                ctx.fillText(txt, 10, c.height - Math.round(c.width / 70));
                URL.revokeObjectURL(img.src);
                resolve(c.toDataURL('image/jpeg', 0.72));
            };
            img.onerror = reject;
            img.src = URL.createObjectURL(file);
        });
    }
    function renderPhotos() {
        photosBox.innerHTML = '';
        photos.forEach(function (p, i) {
            var d = document.createElement('div');
            d.className = 'pt-photo';
            d.innerHTML = '<img alt=""><button type="button" aria-label="Retirer">&times;</button>';
            d.querySelector('img').src = p;
            d.querySelector('button').onclick = function () { photos.splice(i, 1); renderPhotos(); };
            photosBox.appendChild(d);
        });
    }
    input.addEventListener('change', function () {
        var files = Array.prototype.slice.call(input.files || []).slice(0, 4 - photos.length);
        Promise.all(files.map(compress)).then(function (list) { photos = photos.concat(list).slice(0, 4); renderPhotos(); });
        input.value = '';
    });

    function uuid() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) { var r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16); });
    }
    function position() {
        return new Promise(function (resolve) {
            if (!navigator.geolocation) return resolve(null);
            navigator.geolocation.getCurrentPosition(function (p) { resolve(p.coords); }, function () { resolve(null); },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
        });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var fd = new FormData(form);
        if (form.querySelector('select[name="mission_id"]') && !fd.get('mission_id')) { statusEl.textContent = 'Choisissez le site.'; return; }
        if (!String(fd.get('description') || '').trim() && !photos.length) { statusEl.textContent = 'Décrivez l\'événement ou ajoutez une photo.'; return; }
        var payload = {
            uuid: uuid(), type: form.getAttribute('data-type'), categorie: fd.get('categorie'), gravite: fd.get('gravite') || 'info',
            description: fd.get('description'), photos: photos, mission_id: parseInt(fd.get('mission_id'), 10) || null,
            ts_appareil: Date.now(), hors_ligne: false
        };
        submit.disabled = true;
        statusEl.innerHTML = '<i class="fa fa-location-crosshairs fa-beat"></i> Localisation…';
        position().then(function (c) {
            if (c) { payload.lat = c.latitude; payload.lng = c.longitude; payload.precision = c.accuracy; }
            statusEl.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Envoi…';
            if (!navigator.onLine) throw { offline: true };
            return fetch(API, { method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-OV-Pointage': '1' }, body: JSON.stringify(payload) })
                .catch(function () { throw { offline: true }; })
                .then(function (r) { return r.json(); });
        }).then(function (res) {
            if (res.ok) {
                statusEl.style.color = '#86efac';
                statusEl.textContent = res.message;
                // Partage WhatsApp : l'agent choisit le groupe (WhatsApp ne permet pas d'écrire directement dans un groupe)
                var cats = form.querySelector('input[name="categorie"]:checked');
                var txt = (payload.type === 'main_courante' ? '📒 Main courante' : '⚠️ Incident') + ' — '
                    + (cats ? cats.parentElement.textContent.trim() : '') + '\n' + new Date().toLocaleString('fr-FR')
                    + (payload.lat ? '\nhttps://maps.google.com/?q=' + payload.lat + ',' + payload.lng : '') + '\n' + (payload.description || '');
                submit.outerHTML = '<a class="pt-btn pt-btn-green" target="_blank" rel="noopener" href="https://wa.me/?text=' + encodeURIComponent(txt)
                    + '"><i class="fa-brands fa-whatsapp"></i> Partager dans un groupe WhatsApp</a>'
                    + '<a class="pt-btn pt-btn-ghost" style="margin-top:10px" href="' + location.pathname + location.search + '">Terminé</a>';
            } else {
                statusEl.style.color = '#fca5a5';
                statusEl.textContent = res.message || 'Envoi refusé.';
                submit.disabled = false;
            }
        }).catch(function (err) {
            if (err && err.offline) {
                payload.hors_ligne = true;
                var q = [];
                try { q = JSON.parse(localStorage.getItem(QKEY) || '[]'); } catch (x) {}
                q.push(payload);
                try { localStorage.setItem(QKEY, JSON.stringify(q)); } catch (x) {
                    delete payload.photos; q[q.length - 1] = payload;
                    try { localStorage.setItem(QKEY, JSON.stringify(q)); } catch (y) {}
                }
                statusEl.style.color = '#86efac';
                statusEl.textContent = 'Pas de réseau : signalement gardé sur le téléphone, envoi automatique au retour du réseau.';
                setTimeout(function () { location.href = document.body.getAttribute('data-base') + '/app.php'; }, 2500);
            } else {
                statusEl.textContent = 'Erreur inattendue, réessayez.';
                submit.disabled = false;
            }
        });
    });
})();
