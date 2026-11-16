<?php
/** Création / configuration d'un site : client, adresse + carte + périmètre, responsable, consignes, agents. */
require_once __DIR__ . '/_init.php';
ptRequireAdmin('view');
$db = getDB();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$isNew = $id === 0;
if ($isNew) {
    ptRequireAdmin('create');
    $m = ['id' => 0, 'nom' => '', 'client_id' => null, 'client_nom' => '', 'lieu' => '', 'actif' => 1,
          'pt_adresse' => '', 'pt_latitude' => '', 'pt_longitude' => '', 'pt_rayon_m' => (int)getParam('pointage_rayon_defaut_m', '200'),
          'pt_responsable_nom' => '', 'pt_responsable_tel' => '', 'pt_responsable_whatsapp' => '', 'pt_consignes' => ''];
    $mesAgents = [];
} else {
    $m = ptSite($id);
    if (!$m) { ptFlash('danger', 'Site introuvable.'); ptRedirect('admin/missions.php'); }
    $st = $db->prepare("SELECT agent_id FROM mission_agents WHERE mission_id = ?");
    $st->execute([$id]);
    $mesAgents = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}
$clients = $db->query("SELECT id, nom FROM clients ORDER BY nom")->fetchAll();
$agents  = $db->query("SELECT id, nom, prenom, matricule FROM agents
    WHERE actif = 1 AND (statut_inscription IS NULL OR statut_inscription = 'validee') ORDER BY nom, prenom")->fetchAll();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ptRequireAdmin($isNew ? 'create' : 'edit');
    if (!ptCsrfCheck()) $errors[] = 'Session expirée, réessayez.';
    $nom      = trim((string)($_POST['nom'] ?? ''));
    $clientId = (int)($_POST['client_id'] ?? 0) ?: null;
    $nouveauClient = trim((string)($_POST['nouveau_client'] ?? ''));
    $actif    = $isNew ? 1 : (isset($_POST['actif']) ? 1 : 0);
    $adresse  = trim((string)($_POST['pt_adresse'] ?? ''));
    $lat      = trim((string)($_POST['pt_latitude'] ?? ''));
    $lng      = trim((string)($_POST['pt_longitude'] ?? ''));
    $rayon    = max(30, min(2000, (int)($_POST['pt_rayon_m'] ?? 200)));
    $respNom  = trim((string)($_POST['pt_responsable_nom'] ?? ''));
    $respTel  = trim((string)($_POST['pt_responsable_tel'] ?? ''));
    $respWa   = trim((string)($_POST['pt_responsable_whatsapp'] ?? ''));
    $consignes = trim((string)($_POST['pt_consignes'] ?? ''));
    $agentIds = array_map('intval', array_column($agents, 'id'));
    $choisis  = array_values(array_intersect(array_map('intval', (array)($_POST['agents'] ?? [])), $agentIds));

    if ($nom === '') $errors[] = 'Le nom du site est obligatoire.';
    if ($lat !== '' || $lng !== '') {
        if (!is_numeric($lat) || !is_numeric($lng) || abs((float)$lat) > 90 || abs((float)$lng) > 180) {
            $errors[] = 'Position GPS invalide. Recherchez l\'adresse ou cliquez sur la carte.';
        }
    }
    foreach (['du responsable' => $respTel, 'WhatsApp du responsable' => $respWa] as $lib => $num) {
        if ($num !== '' && !preg_match('/^[\d\s+().-]{6,25}$/', $num)) $errors[] = "Numéro $lib invalide.";
    }

    if (!$errors) {
        $db->beginTransaction();
        if ($nouveauClient !== '') {
            $st = $db->prepare("SELECT id FROM clients WHERE nom = ?");
            $st->execute([$nouveauClient]);
            $clientId = (int)$st->fetchColumn() ?: null;
            if (!$clientId) {
                $db->prepare("INSERT INTO clients (nom) VALUES (?)")->execute([$nouveauClient]);
                $clientId = (int)$db->lastInsertId();
            }
        }
        $vals = [$nom, $clientId, $adresse ?: null, $actif, $adresse ?: null, $lat === '' ? null : $lat, $lng === '' ? null : $lng, $rayon,
                 $respNom ?: null, $respTel ?: null, $respWa ?: null, $consignes ?: null];
        if ($isNew) {
            $db->prepare("INSERT INTO missions (nom, client_id, lieu, actif, pt_adresse, pt_latitude, pt_longitude, pt_rayon_m,
                    pt_responsable_nom, pt_responsable_tel, pt_responsable_whatsapp, pt_consignes, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute(array_merge($vals, [ptAdminUser()['id']]));
            $id = (int)$db->lastInsertId();
        } else {
            $db->prepare("UPDATE missions SET nom = ?, client_id = ?, lieu = COALESCE(?, lieu), actif = ?, pt_adresse = ?, pt_latitude = ?,
                    pt_longitude = ?, pt_rayon_m = ?, pt_responsable_nom = ?, pt_responsable_tel = ?, pt_responsable_whatsapp = ?, pt_consignes = ?
                WHERE id = ?")->execute(array_merge($vals, [$id]));
        }
        $freq = (int)($_POST['pt_ronde_freq_min'] ?? 0);
        if (!in_array($freq, [0, 30, 45, 60, 90, 120, 180, 240, 360], true)) $freq = 0;
        $mode = (string)($_POST['pt_ronde_mode'] ?? 'aucune');
        if (!isset(ptModesRonde()[$mode])) $mode = 'aucune';
        $db->prepare("UPDATE missions SET pt_ronde_freq_min = ?, pt_ronde_mode = ?, pt_whatsapp_groupe = ? WHERE id = ?")
           ->execute([$mode === 'aucune' ? 0 : $freq, $mode, ptLienGroupeWhatsapp((string)($_POST['pt_whatsapp_groupe'] ?? '')), $id]);
        // Photo ou validation simple sans point défini : un point unique « Ronde du site »
        if (in_array($mode, ['photo', 'simple'], true) && !ptPointsSite($id)) {
            $db->prepare("INSERT INTO pt_points (mission_id, nom, code, ordre) VALUES (?, 'Ronde du site', ?, 1)")->execute([$id, ptGenererCodePoint()]);
        }

        // Affectations : seuls les agents actifs listés ici sont concernés
        if ($agentIds) {
            $db->prepare("DELETE FROM mission_agents WHERE mission_id = ? AND agent_id IN (" . implode(',', $agentIds) . ")")->execute([$id]);
        }
        $ins = $db->prepare("INSERT IGNORE INTO mission_agents (mission_id, agent_id) VALUES (?, ?)");
        foreach ($choisis as $aid) $ins->execute([$id, $aid]);
        $db->commit();

        ptFlash('success', 'Site « ' . $nom . ' » ' . ($isNew ? 'créé' : 'enregistré') . ' — ' . count($choisis) . ' agent(s) affecté(s).');
        ptRedirect('admin/missions.php');
    }
    $m = array_merge($m, [
        'nom' => $nom, 'client_id' => $clientId, 'actif' => $actif, 'pt_adresse' => $adresse, 'pt_latitude' => $lat, 'pt_longitude' => $lng,
        'pt_rayon_m' => $rayon, 'pt_responsable_nom' => $respNom, 'pt_responsable_tel' => $respTel,
        'pt_responsable_whatsapp' => $respWa, 'pt_consignes' => $consignes,
    ]);
    $mesAgents = $choisis;
}

$rayon = (int)($m['pt_rayon_m'] ?: getParam('pointage_rayon_defaut_m', '200'));
$extraHead = '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">';
ptAdminHead($isNew ? 'Nouveau site' : 'Site — ' . $m['nom'], 'sites', $extraHead);
?>
<div class="pta-title">
    <div>
        <a href="missions.php" class="text-muted small text-decoration-none"><i class="fa fa-arrow-left me-1"></i>Sites</a>
        <h1><?= $isNew ? 'Nouveau site' : h($m['nom']) ?></h1>
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
            <h2><i class="fa fa-building me-1 text-warning"></i> Site et client</h2>
            <div class="row g-2">
                <div class="col-md-6"><label class="form-label small fw-semibold" for="nom">Nom du site *</label>
                    <input class="form-control" id="nom" name="nom" value="<?= h($m['nom']) ?>" required placeholder="Ex. Centre commercial Rivoli — poste PC"></div>
                <div class="col-md-6"><label class="form-label small fw-semibold" for="client">Client</label>
                    <select class="form-select" id="client" name="client_id">
                        <option value="">— Aucun —</option>
                        <?php foreach ($clients as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= (int)$m['client_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input class="form-control form-control-sm mt-1" name="nouveau_client" placeholder="…ou nouveau client : tapez son nom">
                </div>
            </div>
            <?php if (!$isNew): ?>
            <label class="form-check mt-2"><input class="form-check-input" type="checkbox" name="actif" value="1" <?= $m['actif'] ? 'checked' : '' ?>>
                <span class="form-check-label">Site actif <span class="text-muted small">(décocher : plus aucun pointage possible sur ce site)</span></span></label>
            <?php endif; ?>
        </div>

        <div class="pta-card">
            <h2><i class="fa fa-location-dot me-1 text-warning"></i> Position du site</h2>
            <div class="position-relative mb-2">
                <input class="form-control" name="pt_adresse" id="adresse" autocomplete="off"
                       value="<?= h(($m['pt_adresse'] ?? '') ?: ($m['lieu'] ?? '')) ?>" placeholder="Adresse, nom de lieu (gare, zone industrielle…) ou coordonnées GPS">
                <div class="pta-suggest d-none" id="suggest"></div>
            </div>
            <div class="small text-muted mb-2">Choisissez dans la liste, ou cliquez sur la carte. Pas d'adresse ? Collez des coordonnées GPS ou un lien Google Maps.</div>
            <div class="input-group input-group-sm mb-2">
                <span class="input-group-text"><i class="fa fa-crosshairs me-1"></i>GPS</span>
                <input class="form-control" id="coords" inputmode="decimal" autocomplete="off" placeholder="48.856600, 2.352200"
                       value="<?= $m['pt_latitude'] !== '' && $m['pt_latitude'] !== null ? h(rtrim(rtrim((string)$m['pt_latitude'], '0'), '.') . ', ' . rtrim(rtrim((string)$m['pt_longitude'], '0'), '.')) : '' ?>">
                <button class="btn btn-outline-secondary" type="button" id="coordsBtn">Placer</button>
            </div>
            <div class="small text-danger d-none mb-2" id="coordsErr">Coordonnées non reconnues. Exemple : 48.8566, 2.3522</div>
            <div id="ptaMap"></div>
            <div class="d-flex align-items-center gap-3 mt-3">
                <label class="small fw-semibold text-nowrap" for="rayon">Périmètre de pointage</label>
                <input type="range" class="form-range" min="30" max="1000" step="10" name="pt_rayon_m" id="rayon" value="<?= $rayon ?>">
                <span class="fw-bold text-nowrap" style="min-width:64px" id="rayonVal"><?= $rayon ?> m</span>
            </div>
            <div class="small text-muted">Au-delà de ce rayon, le pointage reste possible mais il est signalé « hors zone ».</div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="pta-card">
            <h2><i class="fa fa-users me-1 text-warning"></i> Agents affectés</h2>
            <?php if (!$agents): ?><div class="text-muted small">Aucun agent. <a href="agent.php">Créez un agent</a>.</div><?php endif; ?>
            <?php if (count($agents) > 8): ?>
                <input class="form-control form-control-sm mb-2" placeholder="Filtrer…" oninput="var q=this.value.toLowerCase();document.querySelectorAll('[data-ag]').forEach(function(l){l.style.display=l.dataset.ag.indexOf(q)>-1?'':'none'})">
            <?php endif; ?>
            <div style="max-height:260px;overflow-y:auto">
            <?php foreach ($agents as $ag): ?>
                <label class="d-flex align-items-center gap-2 py-1" data-ag="<?= h(mb_strtolower($ag['nom'] . ' ' . $ag['prenom'] . ' ' . $ag['matricule'])) ?>">
                    <input class="form-check-input m-0" type="checkbox" name="agents[]" value="<?= (int)$ag['id'] ?>" <?= in_array((int)$ag['id'], $mesAgents, true) ? 'checked' : '' ?>>
                    <span class="small"><?= h($ag['nom'] . ' ' . $ag['prenom']) ?> <span class="text-muted"><?= h((string)$ag['matricule']) ?></span></span>
                </label>
            <?php endforeach; ?>
            </div>
        </div>
        <div class="pta-card">
            <h2><i class="fa fa-user-tie me-1 text-warning"></i> Responsable à contacter</h2>
            <label class="form-label small fw-semibold" for="rn">Nom</label>
            <input class="form-control mb-2" id="rn" name="pt_responsable_nom" value="<?= h((string)$m['pt_responsable_nom']) ?>" placeholder="Ex. Chef de poste">
            <label class="form-label small fw-semibold" for="rt">Téléphone</label>
            <input class="form-control mb-2" id="rt" name="pt_responsable_tel" value="<?= h((string)$m['pt_responsable_tel']) ?>" placeholder="06 12 34 56 78">
            <label class="form-label small fw-semibold" for="rw"><i class="fa-brands fa-whatsapp text-success"></i> WhatsApp <span class="text-muted fw-normal">(si différent)</span></label>
            <input class="form-control mb-2" id="rw" name="pt_responsable_whatsapp" value="<?= h((string)($m['pt_responsable_whatsapp'] ?? '')) ?>" placeholder="Même numéro si vide">
            <label class="form-label small fw-semibold" for="rg"><i class="fa-brands fa-whatsapp text-success"></i> Groupe WhatsApp du site <span class="text-muted fw-normal">(facultatif)</span></label>
            <input class="form-control" id="rg" name="pt_whatsapp_groupe" value="<?= h((string)($m['pt_whatsapp_groupe'] ?? '')) ?>" placeholder="https://chat.whatsapp.com/…">
            <div class="small text-muted mt-1">Dans WhatsApp : ouvrez le groupe → nom du groupe → « Inviter via un lien » → copier.</div>
            <div class="small text-muted mt-2">Si vide : contact par défaut <strong><?= h(getParam('pointage_contact_nom', 'Permanence')) ?></strong>
                <?= h(getParam('pointage_contact_tel', '') ?: '(non renseigné)') ?> — <a href="parametres.php">modifier</a>.</div>
        </div>
        <div class="pta-card">
            <h2><i class="fa fa-route me-1 text-warning"></i> Rondes</h2>
            <label class="form-label small fw-semibold" for="rmode">Comment l'agent valide sa ronde</label>
            <select class="form-select mb-2" id="rmode" name="pt_ronde_mode">
                <?php $rm = (string)($m['pt_ronde_mode'] ?? 'aucune');
                foreach (ptModesRonde() as $k => $l): ?><option value="<?= $k ?>" <?= $rm === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
            </select>
            <label class="form-label small fw-semibold" for="freq">Fréquence : une ronde complète</label>
            <select class="form-select mb-2" id="freq" name="pt_ronde_freq_min">
                <?php $fq = (int)($m['pt_ronde_freq_min'] ?? 0);
                foreach ([0 => 'Pas de fréquence imposée', 30 => 'toutes les 30 min', 45 => 'toutes les 45 min', 60 => 'toutes les heures', 90 => 'toutes les 1 h 30',
                          120 => 'toutes les 2 h', 180 => 'toutes les 3 h', 240 => 'toutes les 4 h', 360 => 'toutes les 6 h'] as $k => $l): ?>
                    <option value="<?= $k ?>" <?= $fq === $k ? 'selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
            <div class="small text-muted">En cas de retard : rappel à l'agent, puis alerte au responsable 15 min plus tard.</div>
            <?php if (!$isNew):
                $nbPts = count(ptPointsSite((int)$m['id'])); ?>
                <a class="btn btn-sm btn-outline-secondary w-100 mt-2" href="points.php?mission=<?= (int)$m['id'] ?>"><i class="fa fa-location-crosshairs me-1"></i>Points de ronde (<?= $nbPts ?>) et étiquettes QR</a>
            <?php else: ?>
                <div class="small text-muted mt-2">Vous pourrez ajouter les points de ronde après la création du site.</div>
            <?php endif; ?>
        </div>
        <div class="pta-card">
            <h2><i class="fa fa-clipboard-list me-1 text-warning"></i> Consignes du poste</h2>
            <textarea class="form-control" name="pt_consignes" rows="5" placeholder="Rondes toutes les 2 h, code portail, numéros utiles…"><?= h((string)$m['pt_consignes']) ?></textarea>
        </div>
        <?php if (ptAdminCan($isNew ? 'create' : 'edit')): ?>
        <button class="btn btn-pta w-100 py-2"><i class="fa fa-check me-1"></i><?= $isNew ? 'Créer le site' : 'Enregistrer' ?></button>
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

    // Coordonnées GPS : « 48.8566, 2.3522 », « 48,8566 2,3522 » ou lien Google Maps (@lat,lng / q=lat,lng / !3dlat!4dlng)
    var coordsI = document.getElementById('coords'), coordsErr = document.getElementById('coordsErr');
    function lireCoords(txt) {
        txt = String(txt || '').trim();
        var m = txt.match(/!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/) || txt.match(/@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/)
             || txt.match(/[?&](?:q|query|ll)=(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)/)
             || txt.match(/^(-?\d{1,2}(?:[.,]\d+)?)\s*[,;\s]\s*(-?\d{1,3}(?:[.,]\d+)?)$/);
        if (!m) return null;
        var lat = parseFloat(m[1].replace(',', '.')), lng = parseFloat(m[2].replace(',', '.'));
        if (isNaN(lat) || isNaN(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) return null;
        return [lat, lng];
    }
    function placerCoords() {
        var c = lireCoords(coordsI.value);
        coordsErr.classList.toggle('d-none', !!c || !coordsI.value.trim());
        if (c) place(c[0], c[1], 18);
    }
    document.getElementById('coordsBtn').addEventListener('click', placerCoords);
    coordsI.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); placerCoords(); } });
    coordsI.addEventListener('paste', function () { setTimeout(placerCoords, 0); });
    var placeOrig = place;
    place = function (lat, lng, zoom) { placeOrig(lat, lng, zoom); coordsI.value = lat.toFixed(6) + ', ' + lng.toFixed(6); coordsErr.classList.add('d-none'); };

    // Recherche : coordonnées collées, adresses (Base Adresse Nationale) et lieux (OpenStreetMap)
    function ajouter(label, sous, icone, lat, lng, garderTexte) {
        var b = document.createElement('button');
        b.type = 'button';
        b.innerHTML = '<i class="fa ' + icone + ' me-2 text-muted"></i>';
        b.appendChild(document.createTextNode(label));
        if (sous) { var s2 = document.createElement('small'); s2.className = 'd-block text-muted ms-4'; s2.textContent = sous; b.appendChild(s2); }
        b.addEventListener('click', function () {
            if (!garderTexte) adr.value = label;
            sug.classList.add('d-none');
            place(lat, lng, 18);
        });
        sug.appendChild(b);
    }
    var timer = null, seq = 0;
    adr.addEventListener('input', function () {
        clearTimeout(timer);
        var q = adr.value.trim();
        if (q.length < 3) { sug.classList.add('d-none'); return; }
        var c = lireCoords(q);
        if (c) {
            sug.innerHTML = '';
            ajouter('Placer le point GPS ' + c[0].toFixed(6) + ', ' + c[1].toFixed(6), 'Coordonnées reconnues', 'fa-crosshairs', c[0], c[1], true);
            sug.classList.remove('d-none');
            return;
        }
        var mon = ++seq;
        timer = setTimeout(function () {
            var ban = fetch('https://api-adresse.data.gouv.fr/search/?limit=5&q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); }).then(function (d) { return d.features || []; }).catch(function () { return []; });
            var osm = fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&limit=5&accept-language=fr&countrycodes=fr,be,lu,ch,mc&q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); }).catch(function () { return []; });
            Promise.all([ban, osm]).then(function (res) {
                if (mon !== seq) return;   // une frappe plus récente a relancé la recherche
                sug.innerHTML = '';
                res[0].forEach(function (f) {
                    ajouter(f.properties.label, 'Adresse', 'fa-house', f.geometry.coordinates[1], f.geometry.coordinates[0]);
                });
                (res[1] || []).forEach(function (o) {
                    var parts = String(o.display_name || '').split(', ');
                    ajouter(o.name || parts[0], parts.slice(o.name ? 1 : 1, 4).join(', '), 'fa-map-location-dot', parseFloat(o.lat), parseFloat(o.lon));
                });
                sug.classList.toggle('d-none', !sug.children.length);
            });
        }, 450);
    });
    document.addEventListener('click', function (e) { if (!sug.contains(e.target) && e.target !== adr) sug.classList.add('d-none'); });
    adr.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
})();
</script>
HTML;
ptAdminFoot($scripts);
