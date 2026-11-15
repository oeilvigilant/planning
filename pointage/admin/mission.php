<?php
/** Configuration terrain d'une mission : adresse + carte + périmètre + responsable + consignes. */
require_once __DIR__ . '/_init.php';
ptRequireAdmin('view');
$db = getDB();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $db->prepare("SELECT m.*, c.nom AS client_nom FROM missions m LEFT JOIN clients c ON c.id = m.client_id WHERE m.id = ?");
$st->execute([$id]);
$m = $st->fetch();
if (!$m) { ptFlash('danger', 'Mission introuvable.'); ptRedirect('admin/missions.php'); }

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ptRequireAdmin('edit');
    if (!ptCsrfCheck()) $errors[] = 'Session expirée, réessayez.';
    $adresse = trim((string)($_POST['pt_adresse'] ?? ''));
    $lat = trim((string)($_POST['pt_latitude'] ?? ''));
    $lng = trim((string)($_POST['pt_longitude'] ?? ''));
    $rayon = (int)($_POST['pt_rayon_m'] ?? 200);
    $respNom = trim((string)($_POST['pt_responsable_nom'] ?? ''));
    $respTel = trim((string)($_POST['pt_responsable_tel'] ?? ''));
    $consignes = trim((string)($_POST['pt_consignes'] ?? ''));

    if ($lat !== '' || $lng !== '') {
        if (!is_numeric($lat) || !is_numeric($lng) || abs((float)$lat) > 90 || abs((float)$lng) > 180) {
            $errors[] = 'Position GPS invalide. Recherchez l\'adresse ou cliquez sur la carte.';
        }
    }
    $rayon = max(30, min(2000, $rayon));
    if ($respTel !== '' && !preg_match('/^[\d\s+().-]{6,25}$/', $respTel)) $errors[] = 'Téléphone du responsable invalide.';

    if (!$errors) {
        $db->prepare("UPDATE missions SET pt_adresse = ?, pt_latitude = ?, pt_longitude = ?, pt_rayon_m = ?,
                pt_responsable_nom = ?, pt_responsable_tel = ?, pt_consignes = ? WHERE id = ?")
           ->execute([$adresse ?: null, $lat === '' ? null : $lat, $lng === '' ? null : $lng, $rayon,
                      $respNom ?: null, $respTel ?: null, $consignes ?: null, $id]);
        ptFlash('success', 'Site « ' . $m['nom'] . ' » enregistré.');
        ptRedirect('admin/missions.php');
    }
    $m = array_merge($m, [
        'pt_adresse' => $adresse, 'pt_latitude' => $lat, 'pt_longitude' => $lng, 'pt_rayon_m' => $rayon,
        'pt_responsable_nom' => $respNom, 'pt_responsable_tel' => $respTel, 'pt_consignes' => $consignes,
    ]);
}

$rayon = (int)($m['pt_rayon_m'] ?: getParam('pointage_rayon_defaut_m', '200'));
$extraHead = '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">';
ptAdminHead('Site — ' . $m['nom'], 'sites', $extraHead);
?>
<div class="pta-title">
    <div>
        <a href="missions.php" class="text-muted small text-decoration-none"><i class="fa fa-arrow-left me-1"></i>Sites</a>
        <h1><?= h($m['nom']) ?></h1>
        <div class="text-muted small"><?= h($m['client_nom'] ?: 'Sans client') ?><?= $m['lieu'] ? ' · ' . h($m['lieu']) : '' ?></div>
    </div>
</div>

<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= h($e) ?></div><?php endforeach; ?>

<form method="post" class="row g-3">
    <?= ptCsrfField() ?>
    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
    <input type="hidden" name="pt_latitude" id="lat" value="<?= h((string)$m['pt_latitude']) ?>">
    <input type="hidden" name="pt_longitude" id="lng" value="<?= h((string)$m['pt_longitude']) ?>">

    <div class="col-lg-8">
        <div class="pta-card">
            <h2><i class="fa fa-location-dot me-1 text-warning"></i> Position du site</h2>
            <div class="position-relative mb-2">
                <input class="form-control" name="pt_adresse" id="adresse" autocomplete="off"
                       value="<?= h($m['pt_adresse'] ?: $m['lieu'] ?: '') ?>" placeholder="Tapez l'adresse du site…">
                <div class="pta-suggest d-none" id="suggest"></div>
            </div>
            <div class="small text-muted mb-2">Choisissez l'adresse dans la liste, puis ajustez le repère sur la carte si besoin (entrée du site, poste de garde…).</div>
            <div id="ptaMap"></div>
            <div class="d-flex align-items-center gap-3 mt-3">
                <label class="small fw-semibold text-nowrap" for="rayon">Périmètre de pointage</label>
                <input type="range" class="form-range" min="30" max="1000" step="10" name="pt_rayon_m" id="rayon" value="<?= $rayon ?>">
                <span class="fw-bold text-nowrap" style="min-width:64px" id="rayonVal"><?= $rayon ?> m</span>
            </div>
            <div class="small text-muted">Au-delà de ce rayon, le pointage reste possible mais il est signalé « hors zone » au responsable.</div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="pta-card">
            <h2><i class="fa fa-user-tie me-1 text-warning"></i> Responsable à contacter</h2>
            <label class="form-label small fw-semibold" for="rn">Nom</label>
            <input class="form-control mb-3" id="rn" name="pt_responsable_nom" value="<?= h((string)$m['pt_responsable_nom']) ?>" placeholder="Ex. Chef de poste">
            <label class="form-label small fw-semibold" for="rt">Téléphone</label>
            <input class="form-control" id="rt" name="pt_responsable_tel" value="<?= h((string)$m['pt_responsable_tel']) ?>" placeholder="06 12 34 56 78">
            <div class="small text-muted mt-2">Si vide, l'agent appellera la permanence (<?= h(getParam('entreprise_tel', 'non renseignée')) ?>).</div>
        </div>
        <div class="pta-card">
            <h2><i class="fa fa-clipboard-list me-1 text-warning"></i> Consignes du poste</h2>
            <textarea class="form-control" name="pt_consignes" rows="7" placeholder="Rondes toutes les 2 h, code portail, numéros utiles…"><?= h((string)$m['pt_consignes']) ?></textarea>
            <div class="small text-muted mt-2">Affichées à l'agent dans l'application pendant sa vacation.</div>
        </div>
        <?php if (ptAdminCan('edit')): ?>
        <button class="btn btn-pta w-100 py-2"><i class="fa fa-check me-1"></i>Enregistrer</button>
        <?php endif; ?>
    </div>
</form>

<?php
$scripts = <<<'HTML'
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script>
(function () {
    var latI = document.getElementById('lat'), lngI = document.getElementById('lng');
    var rayonI = document.getElementById('rayon'), rayonV = document.getElementById('rayonVal');
    var adr = document.getElementById('adresse'), sug = document.getElementById('suggest');
    var start = latI.value ? [parseFloat(latI.value), parseFloat(lngI.value)] : [48.8566, 2.3522];
    var map = L.map('ptaMap').setView(start, latI.value ? 17 : 11);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
    var marker = null, circle = null;

    function place(lat, lng, zoom) {
        latI.value = lat.toFixed(7); lngI.value = lng.toFixed(7);
        if (!marker) {
            marker = L.marker([lat, lng], { draggable: true }).addTo(map);
            marker.on('dragend', function () { var p = marker.getLatLng(); place(p.lat, p.lng); });
            circle = L.circle([lat, lng], { radius: +rayonI.value, color: '#c9a84c', fillOpacity: 0.12 }).addTo(map);
        } else {
            marker.setLatLng([lat, lng]); circle.setLatLng([lat, lng]);
        }
        if (zoom) map.setView([lat, lng], zoom);
    }
    if (latI.value) place(start[0], start[1]);
    map.on('click', function (e) { place(e.latlng.lat, e.latlng.lng); });
    rayonI.addEventListener('input', function () { rayonV.textContent = rayonI.value + ' m'; if (circle) circle.setRadius(+rayonI.value); });

    // Recherche d'adresse : Base Adresse Nationale (api-adresse.data.gouv.fr)
    var timer = null;
    adr.addEventListener('input', function () {
        clearTimeout(timer);
        var q = adr.value.trim();
        if (q.length < 4) { sug.classList.add('d-none'); return; }
        timer = setTimeout(function () {
            fetch('https://api-adresse.data.gouv.fr/search/?limit=6&q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    sug.innerHTML = '';
                    (d.features || []).forEach(function (f) {
                        var b = document.createElement('button');
                        b.type = 'button';
                        b.textContent = f.properties.label;
                        b.addEventListener('click', function () {
                            adr.value = f.properties.label;
                            sug.classList.add('d-none');
                            place(f.geometry.coordinates[1], f.geometry.coordinates[0], 18);
                        });
                        sug.appendChild(b);
                    });
                    sug.classList.toggle('d-none', !(d.features || []).length);
                }).catch(function () {});
        }, 300);
    });
    document.addEventListener('click', function (e) { if (!sug.contains(e.target) && e.target !== adr) sug.classList.add('d-none'); });
    adr.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
})();
</script>
HTML;
ptAdminFoot($scripts);
