<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('produits', 'create');

$db = getDB();
ensureProduitsSchema();

$familles = produitFamilles();
$unites   = produitUnites();

$report = null; // ['created'=>int,'updated'=>int,'errors'=>[['line'=>n,'msg'=>...]]]

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['fichier']['tmp_name']) && is_uploaded_file($_FILES['fichier']['tmp_name'])) {
    $path = $_FILES['fichier']['tmp_name'];
    $raw  = file_get_contents($path);
    // Retire un éventuel BOM UTF-8
    if (substr($raw, 0, 3) === chr(0xEF).chr(0xBB).chr(0xBF)) $raw = substr($raw, 3);
    $tmp = fopen('php://temp', 'r+');
    fwrite($tmp, $raw);
    rewind($tmp);

    // Détection du délimiteur sur la première ligne (';' par défaut, ',' sinon)
    $firstLine = fgets($tmp);
    rewind($tmp);
    $delim = (substr_count($firstLine, ';') >= substr_count($firstLine, ',')) ? ';' : ',';

    $headerRow = fgetcsv($tmp, 0, $delim);
    if ($headerRow === false) {
        $report = ['created'=>0, 'updated'=>0, 'errors'=>[['line'=>1,'msg'=>'Fichier vide ou illisible.']]];
    } else {
        $header = array_map(fn($h) => strtolower(trim($h)), $headerRow);
        $idx = array_flip($header);

        if (!isset($idx['code']) || !isset($idx['designation_courte'])) {
            $report = ['created'=>0, 'updated'=>0, 'errors'=>[['line'=>1,'msg'=>"Colonnes obligatoires manquantes : 'code' et 'designation_courte' (en-tête attendu, séparateur ';')."]]];
        } else {
            $created = 0; $updated = 0; $errors = [];
            $stmtFind = $db->prepare("SELECT id FROM produits WHERE code = ?");
            $stmtIns  = $db->prepare("INSERT INTO produits
                (code, famille, qualification, type_heure, designation_courte, designation_longue, unite, prix_defaut, tva_taux, actif, notes, ordre)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmtUpd  = $db->prepare("UPDATE produits SET famille=?, qualification=?, type_heure=?, designation_courte=?,
                designation_longue=?, unite=?, prix_defaut=?, tva_taux=?, actif=?, notes=?, ordre=? WHERE code=?");

            $line = 1;
            while (($row = fgetcsv($tmp, 0, $delim)) !== false) {
                $line++;
                if (count($row) === 1 && trim($row[0] ?? '') === '') continue; // ligne vide

                $get = fn($key, $default = '') => isset($idx[$key], $row[$idx[$key]]) ? trim($row[$idx[$key]]) : $default;

                $code   = strtoupper($get('code'));
                $courte = $get('designation_courte');
                if ($code === '' || $courte === '') {
                    $errors[] = ['line'=>$line, 'msg'=>'Ligne ignorée : code ou désignation courte manquant.'];
                    continue;
                }

                $famille = $get('famille', 'divers');
                if (!isset($familles[$famille])) $famille = 'divers';
                $unite = $get('unite', 'heure');
                if (!isset($unites[$unite])) $unite = 'heure';
                $qualif    = $get('qualification') ?: null;
                $typeHeure = $get('type_heure') ?: null;
                $longue    = $get('designation_longue', $courte);
                $prix      = (float)str_replace(',', '.', $get('prix_defaut', '0'));
                $tva       = (float)str_replace(',', '.', $get('tva_taux', '20'));
                $actifVal  = $get('actif', '1');
                $actif     = in_array(strtolower($actifVal), ['0','false','non','inactif'], true) ? 0 : 1;
                $notes     = $get('notes');
                $ordre     = (int)$get('ordre', '0');

                $stmtFind->execute([$code]);
                $existing = $stmtFind->fetch();

                try {
                    if ($existing) {
                        $stmtUpd->execute([$famille, $qualif, $typeHeure, $courte, $longue, $unite, $prix, $tva, $actif, $notes, $ordre, $code]);
                        $updated++;
                    } else {
                        $stmtIns->execute([$code, $famille, $qualif, $typeHeure, $courte, $longue, $unite, $prix, $tva, $actif, $notes, $ordre]);
                        $created++;
                    }
                } catch (Exception $e) {
                    $errors[] = ['line'=>$line, 'msg'=>'Erreur sur le code ' . h($code) . ' : ' . $e->getMessage()];
                }
            }
            $report = ['created'=>$created, 'updated'=>$updated, 'errors'=>$errors];
            if ($created || $updated) {
                flash('success', $created . ' produit(s) créé(s), ' . $updated . ' mis à jour.');
            }
        }
    }
    fclose($tmp);
}

$pageTitle     = 'Importer des produits';
$currentModule = 'produits';
$topbarActions = '<a href="index.php" class="btn btn-ov-secondary btn-sm"><i class="fa fa-arrow-left me-1"></i> Retour</a>';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row g-3">
<div class="col-lg-6">
<div class="ov-card">
    <div class="ov-card-header">
        <h2 class="ov-card-title"><i class="fa fa-file-import me-2" style="color:var(--ov-gold)"></i>Importer un fichier CSV</h2>
    </div>
    <div class="ov-card-body">
        <form method="POST" enctype="multipart/form-data">
            <div class="mb-3">
                <label class="form-label">Fichier CSV</label>
                <input type="file" name="fichier" accept=".csv,text/csv" class="form-control" required>
            </div>
            <p class="text-muted" style="font-size:0.85rem">
                Séparateur point-virgule ou virgule, en-tête sur la première ligne.
                Colonnes reconnues : <code>code</code>, <code>famille</code>, <code>qualification</code>,
                <code>type_heure</code>, <code>designation_courte</code>, <code>designation_longue</code>,
                <code>unite</code>, <code>prix_defaut</code>, <code>tva_taux</code>, <code>actif</code>, <code>notes</code>, <code>ordre</code>.
                Seuls <code>code</code> et <code>designation_courte</code> sont obligatoires.
                Un produit dont le <strong>code</strong> existe déjà est mis à jour, sinon il est créé.
            </p>
            <button type="submit" class="btn btn-ov-primary"><i class="fa fa-upload me-2"></i>Importer</button>
        </form>
        <hr>
        <a href="export.php?statut=tous" class="btn btn-ov-secondary btn-sm">
            <i class="fa fa-file-export me-1"></i> Télécharger le catalogue actuel comme modèle
        </a>
    </div>
</div>
</div>

<div class="col-lg-6">
<?php if ($report !== null): ?>
<div class="ov-card">
    <div class="ov-card-header">
        <h2 class="ov-card-title"><i class="fa fa-clipboard-check me-2" style="color:var(--ov-gold)"></i>Résultat de l'import</h2>
    </div>
    <div class="ov-card-body">
        <div class="d-flex gap-3 mb-3">
            <span class="badge bg-success">Créés : <?= (int)$report['created'] ?></span>
            <span class="badge bg-primary">Mis à jour : <?= (int)$report['updated'] ?></span>
            <span class="badge <?= $report['errors'] ? 'bg-danger' : 'bg-secondary' ?>">Erreurs : <?= count($report['errors']) ?></span>
        </div>
        <?php if ($report['errors']): ?>
        <div class="table-responsive" style="max-height:320px;overflow-y:auto">
        <table class="ov-table" style="font-size:0.8rem">
            <thead><tr><th style="width:60px">Ligne</th><th>Erreur</th></tr></thead>
            <tbody>
            <?php foreach ($report['errors'] as $e): ?>
            <tr><td><?= (int)$e['line'] ?></td><td><?= h($e['msg']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
        <a href="index.php" class="btn btn-ov-primary btn-sm mt-2"><i class="fa fa-list me-1"></i>Voir le catalogue</a>
    </div>
</div>
<?php endif; ?>
</div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
