<?php
/** Carte en direct : sites, agents en poste (position au pointage), alertes SOS. */
require_once __DIR__ . '/_init.php';
ptRequireAdmin('view');
$extraHead = '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<style>
#ptaLive { height: calc(100vh - 230px); min-height: 420px; border-radius: 14px; border: 1px solid #e6e8ec; }
.pta-live-list { max-height: calc(100vh - 230px); overflow-y: auto; }
.pta-pin { width: 18px; height: 18px; border-radius: 50%; border: 3px solid #fff; box-shadow: 0 0 0 2px rgba(0,0,0,.25); }
.pta-pin.agent { background: #16a34a; }
.pta-pin.sos { background: #dc2626; animation: ptaPulse 1s infinite; }
@keyframes ptaPulse { 50% { box-shadow: 0 0 0 10px rgba(220,38,38,.25); } }
</style>';
ptAdminHead('Carte en direct', 'carte', $extraHead);
?>
<div class="pta-title">
    <div><h1>Carte en direct</h1>
        <div class="text-muted small">Position relevée au moment du pointage (pas de suivi continu) · mise à jour <span id="liveHeure">—</span></div></div>
    <div class="d-flex gap-2 small">
        <span class="pta-badge ok" id="liveNbAgents">0 en poste</span>
        <span class="pta-badge ko d-none" id="liveNbSos">0 SOS</span>
        <a class="pta-badge wait text-decoration-none d-none" id="liveNbInc" href="evenements.php?type=incident&statut=ouvert">0 incident</a>
    </div>
</div>
<div class="row g-3">
    <div class="col-lg-8"><div id="ptaLive"></div></div>
    <div class="col-lg-4"><div class="pta-card pta-live-list" id="liveList"><div class="text-muted small">Chargement…</div></div></div>
</div>
<?php
$liveUrl = h(ptUrl('admin/live.php'));
$scripts = <<<HTML
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script>
(function () {
    var map = L.map('ptaLive').setView([48.8566, 2.3522], 11);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
    var layer = L.layerGroup().addTo(map), first = true;
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    function pin(cls) { return L.divIcon({ className: '', html: '<div class="pta-pin ' + cls + '"></div>', iconSize: [18, 18], iconAnchor: [9, 9] }); }
    function load() {
        fetch('$liveUrl', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d.ok) return;
            layer.clearLayers();
            var bounds = [];
            d.sites.forEach(function (s) {
                L.circle([s.lat, s.lng], { radius: s.rayon, color: '#c9a84c', weight: 2, fillOpacity: s.en_poste ? 0.18 : 0.06 }).addTo(layer)
                    .bindTooltip(esc(s.nom) + ' · ' + s.en_poste + ' en poste');
                bounds.push([s.lat, s.lng]);
            });
            d.agents.forEach(function (a) {
                if (a.lat === null) return;
                L.marker([a.lat, a.lng], { icon: pin('agent') }).addTo(layer)
                    .bindPopup('<strong>' + esc(a.nom) + '</strong><br>' + esc(a.site) + '<br>depuis ' + esc(a.depuis) + ' (' + esc(a.duree) + ')'
                        + (a.tel ? '<br><a href="tel:' + esc(a.tel) + '">' + esc(a.tel) + '</a>' : ''));
                bounds.push([a.lat, a.lng]);
            });
            d.sos.forEach(function (s) {
                if (s.lat === null) return;
                L.marker([s.lat, s.lng], { icon: pin('sos'), zIndexOffset: 1000 }).addTo(layer)
                    .bindPopup('<strong>SOS — ' + esc(s.nom) + '</strong><br>' + esc(s.site) + ' · ' + esc(s.heure) + '<br><a href="evenements.php?id=' + s.id + '">Traiter</a>');
                bounds.push([s.lat, s.lng]);
            });
            if (first && bounds.length) { map.fitBounds(bounds, { padding: [40, 40], maxZoom: 16 }); first = false; }

            var h = '';
            d.sos.forEach(function (s) {
                h += '<a href="evenements.php?id=' + s.id + '" class="d-block alert alert-danger py-2 mb-2 text-decoration-none"><strong><i class="fa fa-bell"></i> SOS — ' + esc(s.nom)
                    + '</strong><br><span class="small">' + esc(s.site) + ' · ' + esc(s.heure) + '</span></a>';
            });
            h += '<h2 class="mt-1">En poste (' + d.agents.length + ')</h2>';
            if (!d.agents.length) h += '<div class="text-muted small">Aucun agent en service.</div>';
            d.agents.forEach(function (a) {
                h += '<div class="py-2 border-top"><div class="fw-semibold">' + esc(a.nom) + '</div><div class="small text-muted">' + esc(a.site)
                    + ' · depuis ' + esc(a.depuis) + ' (' + esc(a.duree) + ')</div>'
                    + a.alertes.map(function (x) { return '<span class="pta-badge ko me-1">' + esc(x) + '</span>'; }).join('') + '</div>';
            });
            document.getElementById('liveList').innerHTML = h;
            document.getElementById('liveHeure').textContent = d.heure;
            document.getElementById('liveNbAgents').textContent = d.agents.length + ' en poste';
            var ns = document.getElementById('liveNbSos'); ns.textContent = d.sos.length + ' SOS'; ns.classList.toggle('d-none', !d.sos.length);
            var ni = document.getElementById('liveNbInc'); ni.textContent = d.incidents + ' incident(s) ouvert(s)'; ni.classList.toggle('d-none', !d.incidents);
        }).catch(function () {});
    }
    load();
    setInterval(load, 30000);
})();
</script>
HTML;
ptAdminFoot($scripts);
