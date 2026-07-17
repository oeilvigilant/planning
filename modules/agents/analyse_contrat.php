<?php
ob_start(); // capture les notices/warnings PHP pour ne pas corrompre les réponses JSON
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/contrat_builder.php';
require_once __DIR__ . '/../../includes/analyse_securite.php';
requireLogin();
requirePerm('agents', 'view');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$stmt = $db->prepare("SELECT * FROM agents WHERE id = ?");
$stmt->execute([$id]);
$a = $stmt->fetch();
if (!$a) { flash('danger', 'Agent introuvable.'); header('Location: index.php'); exit; }

// Chemin du fichier SKILL.md
define('SKILL_MD_PATH', 'C:\\Users\\admin\\.claude\\skills\\contrat-analyzer-skill\\SKILL.md');

function getAnthropicApiKey(): ?string {
    // Priorité 1 : clé configurée dans les paramètres de l'application
    try {
        $db = getDB();
        $key = $db->query("SELECT valeur FROM parametres WHERE cle='anthropic_api_key'")->fetchColumn();
        if ($key && strpos($key, 'sk-ant-api') === 0) return $key;
    } catch (Exception $e) {}
    return null;
}

function getSkillSystemPrompt(): string {
    if (!file_exists(SKILL_MD_PATH)) return '';
    $content = file_get_contents(SKILL_MD_PATH);
    // Supprimer le frontmatter YAML (--- ... ---)
    $content = preg_replace('/^---\s*\n.*?\n---\s*\n/s', '', $content);
    return trim($content);
}

function callAnthropicApi(string $token, string $systemPrompt, string $userMessage): array {
    $payload = json_encode([
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 4096,
        'system'     => $systemPrompt,
        'messages'   => [['role' => 'user', 'content' => $userMessage]],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $token,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr)      return ['ok' => false, 'error' => 'cURL : ' . $curlErr];
    if (!$response)    return ['ok' => false, 'error' => 'Aucune réponse du serveur'];

    $data = json_decode($response, true);
    if ($httpCode !== 200) {
        if ($httpCode === 429) {
            return ['ok' => false, 'error' => "Limite d'utilisation atteinte (429 rate limit). Le token OAuth Claude Code est soumis à des quotas stricts pour l'API directe. Ajoutez une clé API Anthropic (sk-ant-api...) dans Paramètres → API pour lever cette restriction."];
        }
        if (is_array($data)) {
            $msg = $data['error']['message'] ?? (is_string($data['error'] ?? null) ? $data['error'] : json_encode($data));
        } else {
            $msg = substr($response, 0, 300);
        }
        return ['ok' => false, 'error' => "API ($httpCode) : $msg"];
    }

    $text = $data['content'][0]['text'] ?? '';
    return ['ok' => true, 'result' => $text];
}

// Force Python à sortir en UTF-8 (évite le problème d'encodage Windows cp1252)
putenv('PYTHONIOENCODING=utf-8');
putenv('PYTHONUTF8=1');

function runPython(string $cmd): string {
    $result = shell_exec($cmd);
    if ($result === null || $result === '') {
        return '(Erreur : aucun résultat. Vérifiez que Python est accessible depuis Apache/WAMP.)';
    }
    // Convertir en UTF-8 propre si nécessaire
    if (!mb_check_encoding($result, 'UTF-8')) {
        $result = mb_convert_encoding($result, 'UTF-8', 'CP1252');
    }
    return $result;
}

function jsonOut(array $data): void {
    if (ob_get_level() > 0) ob_end_clean(); // supprimer toute notice/warning PHP avant le JSON
    header('Content-Type: application/json; charset=UTF-8');
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        // Fallback : nettoyer les caractères non-UTF-8
        array_walk_recursive($data, function(&$v) {
            if (is_string($v)) $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
        });
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    echo $json;
}

// ── AJAX : pré-analyse structurelle (PHP pur) ────────────────────────────────
if (($_POST['action'] ?? '') === 'run_preanalyse') {
    $text = trim($_POST['contract_text'] ?? '');
    if (!$text) { jsonOut(['ok' => false, 'error' => 'Texte vide']); exit; }
    jsonOut(['ok' => true, 'result' => analyseStructurelle($text)]);
    exit;
}

// ── AJAX : calculateur CCN 1351 (PHP pur) ────────────────────────────────────
if (($_POST['action'] ?? '') === 'calcul_ccn') {
    $salaire = (float)($_POST['salaire'] ?? 0);
    if ($salaire <= 0) { jsonOut(['ok' => false, 'error' => 'Salaire requis']); exit; }
    $result = calculCCN1351([
        'salaire'    => $salaire,
        'coeff'      => (int)  ($_POST['coeff']      ?? 150),
        'heures'     => (float)($_POST['heures']     ?? 151.67),
        'nuit'       => (float)($_POST['nuit']       ?? 0),
        'dimanche'   => (float)($_POST['dimanche']   ?? 0),
        'anciennete' => (float)($_POST['anciennete'] ?? 0),
        'type'       => in_array($_POST['type'] ?? '', ['CDI','CDD']) ? $_POST['type'] : 'CDI',
        'total_cdd'  => (float)($_POST['total_cdd']  ?? 0),
        'taux_hs'    => ($_POST['taux_hs'] ?? '') !== '' ? (float)$_POST['taux_hs'] : null,
    ]);
    jsonOut(['ok' => true, 'result' => $result]);
    exit;
}

// ── AJAX : analyse 4 experts via Claude API ───────────────────────────────────
if (($_POST['action'] ?? '') === 'analyse_experts') {
    $text = trim($_POST['contract_text'] ?? '');
    if (!$text) { jsonOut(['ok' => false, 'error' => 'Texte vide']); exit; }

    $token = getAnthropicApiKey();
    if (!$token) { jsonOut(['ok' => false, 'error' => 'Clé API Anthropic non configurée. Allez dans Paramètres → API pour l\'ajouter (sk-ant-api...).']); exit; }

    $systemPrompt = getSkillSystemPrompt();
    if (!$systemPrompt) { jsonOut(['ok' => false, 'error' => 'SKILL.md introuvable']); exit; }

    $userMsg = "Voici le contrat à analyser. Applique les 8 étapes du processus d'analyse complet.\n\n---\n\n" . $text;
    $result  = callAnthropicApi($token, $systemPrompt, $userMsg);
    jsonOut($result);
    exit;
}

// ── Préparer le texte du contrat pour la pré-analyse ─────────────────────────
$params = getAllParams();

$salaireHoraire = (float)($a['remuneration'] ?? 12.70);
$typeContrat    = $a['type_contrat'] ?? 'CDD';

// Dates du contrat — priorité DB, sinon auto-détection depuis le planning
$dateDebutContrat = $a['date_debut_contrat'] ?? null;
$dateFinContrat   = $a['date_fin_contrat']   ?? null;

if (!$dateDebutContrat || !$dateFinContrat) {
    $stmtD = $db->prepare("
        SELECT MIN(pl.date_travail) AS min_date, MAX(pl.date_travail) AS max_date
        FROM planning_lignes pl
        JOIN planning_versions pv ON pv.id = pl.version_id AND pv.is_current = 1
        WHERE pl.agent_id = ?
    ");
    $stmtD->execute([$id]);
    $planRow = $stmtD->fetch();
    if ($planRow && $planRow['min_date']) {
        if (!$dateDebutContrat) $dateDebutContrat = $planRow['min_date'];
        if (!$dateFinContrat)   $dateFinContrat   = $planRow['max_date'];
    }
}

// Heures réelles depuis le planning pour la période du contrat
$totalHeuresPlanning = 0;
$heuresNuitPlanning  = 0;
$heuresDimPlanning   = 0;
if ($dateDebutContrat && $dateFinContrat) {
    $stmt = $db->prepare("
        SELECT
            COALESCE(SUM(pl.min_normal + pl.min_nuit + pl.min_dimanche + pl.min_nuit_dimanche
                       + pl.min_ferie_normal + pl.min_ferie_nuit), 0) AS total_min,
            COALESCE(SUM(pl.min_nuit + pl.min_nuit_dimanche + pl.min_ferie_nuit), 0) AS nuit_min,
            COALESCE(SUM(pl.min_dimanche + pl.min_ferie_nuit), 0) AS dim_min
        FROM planning_lignes pl
        JOIN planning_versions pv ON pv.id = pl.version_id AND pv.is_current = 1
        WHERE pl.agent_id = ? AND pl.date_travail BETWEEN ? AND ?
    ");
    $stmt->execute([$id, $dateDebutContrat, $dateFinContrat]);
    $row = $stmt->fetch();
    $totalHeuresPlanning = $row['total_min'] > 0 ? round($row['total_min'] / 60, 2) : 0;
    $heuresNuitPlanning  = $row['nuit_min']  > 0 ? round($row['nuit_min']  / 60, 2) : 0;
    $heuresDimPlanning   = $row['dim_min']   > 0 ? round($row['dim_min']   / 60, 2) : 0;
}
// Si heures planning disponibles, utiliser le total contrat ; sinon mensuel théorique
$calcHeures  = $totalHeuresPlanning > 0 ? $totalHeuresPlanning : 151.67;
$calcSalaire = round($salaireHoraire * $calcHeures, 2);
$calcSource  = $totalHeuresPlanning > 0 ? 'planning (' . $dateDebutContrat . ' → ' . $dateFinContrat . ')' : 'valeur théorique 35h/sem';

// Extraire le coefficient de la catégorie ou du champ poste
$calcCoeffDefault = 150;
$categorieStr = $a['categorie_emploi'] ?? $a['poste'] ?? '';
if (preg_match('/[Cc]oefficient\s*(\d{3})/i', $categorieStr, $mCoeff)) {
    $calcCoeffDefault = (int)$mCoeff[1];
}

$defaultData = [
    'civilite'             => 'M.',
    'nom_prenom'           => strtoupper($a['nom']) . ' ' . $a['prenom'],
    'adresse'              => trim(($a['adresse'] ?? '') . ', ' . ($a['cp'] ?? '') . ' ' . ($a['ville'] ?? ''), ', '),
    'date_naissance'       => $a['date_naissance']    ? date('d/m/Y', strtotime($a['date_naissance'])) : '',
    'lieu_naissance'       => $a['lieu_naissance']    ?? '',
    'nationalite'          => $a['nationalite']       ?? '',
    'num_secu'             => $a['num_secu']          ?? '',
    'num_cnaps'            => $a['num_autorisation_cnaps'] ?? '',
    'type_contrat'         => $typeContrat,
    'poste'                => $a['poste']             ?? 'Agent de sécurité',
    'categorie'            => 'Employé - Niveau III - Échelon 2 - Coefficient 140',
    'date_debut'           => $dateDebutContrat ? date('d/m/Y', strtotime($dateDebutContrat)) : '',
    'date_fin'             => $dateFinContrat   ? date('d/m/Y', strtotime($dateFinContrat))   : '',
    'motif_cdd'            => "accroissement temporaire d'activité",
    'description_motif'    => "lié à une demande urgente et imprévisible (Article L1242-2-2° du Code du travail).",
    'periode_essai'        => calculerPeriodeEssai(
                                  $dateDebutContrat ? date('d/m/Y', strtotime($dateDebutContrat)) : '',
                                  $dateFinContrat   ? date('d/m/Y', strtotime($dateFinContrat))   : ''
                              ),
    'total_heures_contrat' => $totalHeuresPlanning > 0 ? (string)round($totalHeuresPlanning, 2) : '',
    'site_affectation'     => $a['lieu_travail']     ?? '',
    'salaire_horaire'      => number_format($salaireHoraire, 2, '.', ''),
    'type_remuneration'    => $a['type_remuneration'] ?? 'Brute',
    'majoration_nuit'      => '10',
    'majoration_dim'       => '10',
    'majoration_ferie'     => '100',
    'date_signature'       => date('d/m/Y'),
    'lieu_signature'       => $params['entreprise_ville'] ?? 'Paris',
    'non_renouvelable'     => '1',
];

$contractHtml = buildContratHtml($defaultData, $params, $a);
// Supprimer les blocs <style> et <script> AVANT strip_tags (sinon leur contenu reste en clair)
$contractHtml = preg_replace('/<style\b[^>]*>.*?<\/style>/si', '', $contractHtml);
$contractHtml = preg_replace('/<script\b[^>]*>.*?<\/script>/si', '', $contractHtml);
// Transformer les balises de structure en sauts de ligne
$contractHtml = str_replace(
    ['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>', '</h1>', '</h2>', '</h3>', '</tr>'],
    "\n", $contractHtml
);
$contractText = html_entity_decode(strip_tags($contractHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$contractText = preg_replace('/[ \t]+/', ' ', $contractText);
$contractText = preg_replace('/\n[ \t]+/', "\n", $contractText);
$contractText = preg_replace('/\n{3,}/', "\n\n", trim($contractText));

$pageTitle  = 'Analyse du contrat';
$pageModule = 'agents';
require_once __DIR__ . '/../../includes/header.php';
?>

<style>
.result-terminal {
    font-size: 12px;
    white-space: pre-wrap;
    background: #1e1e2e;
    color: #cdd6f4;
    padding: 16px;
    border-radius: 8px;
    max-height: 500px;
    overflow-y: auto;
    font-family: 'Consolas', 'Courier New', monospace;
    line-height: 1.5;
}
.skill-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
}
</style>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <a href="view.php?id=<?= $id ?>" class="btn btn-ov-secondary"><i class="fa fa-arrow-left me-1"></i>Retour</a>
    <div>
        <h5 class="mb-0"><i class="fa fa-magnifying-glass-chart me-2" style="color:var(--ov-gold)"></i>Analyse du contrat</h5>
        <div class="text-muted small"><?= h($a['prenom']) ?> <?= h(strtoupper($a['nom'])) ?> — <?= h($a['poste'] ?? '') ?></div>
    </div>
    <div class="ms-auto d-flex gap-2">
        <span class="skill-badge" style="background:rgba(201,168,76,0.12);color:#92400e;border:1px solid rgba(201,168,76,0.3)">
            <i class="fa fa-puzzle-piece"></i> contrat-analyzer-skill v1.0
        </span>
        <span class="skill-badge" style="background:rgba(99,102,241,0.1);color:#4f46e5;border:1px solid rgba(99,102,241,0.2)">
            <i class="fa fa-code"></i> Python 3 · CCN IDCC 1351
        </span>
    </div>
</div>

<ul class="nav nav-tabs mb-3" id="analyseTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-preanalyse" type="button">
            <i class="fa fa-list-check me-1"></i>Analyse structurelle
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-calculateur" type="button">
            <i class="fa fa-calculator me-1"></i>Calculateur CCN 1351
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-experts" type="button">
            <i class="fa fa-users me-1"></i>Analyse 4 experts <span class="badge ms-1" style="background:var(--ov-gold);color:#000;font-size:9px">Claude</span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-about" type="button">
            <i class="fa fa-circle-info me-1"></i>À propos
        </button>
    </li>
</ul>

<div class="tab-content">

<!-- ══════════════ TAB 1 : ANALYSE STRUCTURELLE ══════════════ -->
<div class="tab-pane fade show active" id="tab-preanalyse" role="tabpanel">
    <div class="row g-3">
        <div class="col-lg-5">
            <div class="ov-card h-100">
                <div class="ov-card-body d-flex flex-column" style="height:100%">
                    <h6 class="mb-1"><i class="fa fa-file-lines me-1 text-muted"></i>Texte du contrat</h6>
                    <p class="text-muted small mb-2">Extrait automatiquement depuis les données de l'agent. Modifiable avant analyse.</p>
                    <textarea id="contractText" class="form-control font-monospace flex-grow-1" style="height:380px;font-size:11px;resize:vertical"><?= h($contractText) ?></textarea>
                    <button class="btn btn-ov-primary w-100 mt-3" id="btnRunAnalyse" onclick="runPreanalyse()">
                        <i class="fa fa-magnifying-glass me-1"></i>Lancer l'analyse structurelle (IDCC 1351)
                    </button>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="ov-card h-100">
                <div class="ov-card-body">
                    <h6 class="mb-3"><i class="fa fa-chart-bar me-1 text-muted"></i>Résultats</h6>
                    <div id="analyseLoading" class="d-none text-center py-5">
                        <div class="spinner-border" style="color:var(--ov-gold)" role="status"></div>
                        <div class="text-muted small mt-2">Analyse en cours via Python…</div>
                    </div>
                    <div id="analyseEmpty" class="text-center py-5 text-muted">
                        <i class="fa fa-magnifying-glass-chart fa-3x mb-3 d-block" style="opacity:.15"></i>
                        Cliquez sur "Lancer l'analyse" pour démarrer
                    </div>
                    <pre id="analyseResult" class="d-none result-terminal"></pre>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════ TAB 2 : CALCULATEUR CCN 1351 ══════════════ -->
<div class="tab-pane fade" id="tab-calculateur" role="tabpanel">
    <div class="row g-3">
        <div class="col-lg-4">
            <div class="ov-card">
                <div class="ov-card-body">
                    <h6 class="mb-3"><i class="fa fa-sliders me-1 text-muted"></i>Paramètres</h6>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Salaire brut total (€) <span class="text-danger">*</span></label>
                        <input type="number" id="calcSalaire" class="form-control" step="0.01" value="<?= h(number_format($calcSalaire, 2, '.', '')) ?>" min="1">
                        <div class="form-text">
                            Taux horaire : <?= h(number_format($salaireHoraire, 2, ',', ' ')) ?> €/h —
                            Source : <em><?= h($calcSource) ?></em>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Coefficient CCN 1351 <span class="text-danger">*</span></label>
                        <select id="calcCoeff" class="form-select">
                            <option value="140" <?= $calcCoeffDefault===140?'selected':'' ?>>140 — APS sans expérience</option>
                            <option value="150" <?= $calcCoeffDefault===150?'selected':'' ?>>150 — APS (TFP ou 6 mois exp.)</option>
                            <option value="160">160 — APS expérimenté / Adj. CP</option>
                            <option value="170">170 — Chef de poste</option>
                            <option value="175">175 — Chef de poste exp.</option>
                            <option value="182">182 — Chef de site adj. / AM</option>
                            <option value="190">190 — Chef de site adj. exp.</option>
                            <option value="200">200 — Chef de site</option>
                            <option value="210">210 — Cadre débutant</option>
                            <option value="230">230 — Cadre confirmé</option>
                            <option value="250">250 — Cadre supérieur</option>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Heures (contrat ou mois) <span class="text-danger">*</span></label>
                            <input type="number" id="calcHeures" class="form-control" step="0.5" value="<?= h($calcHeures) ?>" min="1">
                            <div class="form-text"><?= $totalHeuresPlanning > 0 ? 'Heures planning' : '151,67 = 35h/sem' ?></div>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Type de contrat</label>
                            <select id="calcType" class="form-select">
                                <option value="CDI" <?= !in_array($typeContrat,['CDD','CDD Usage','Saisonnier'])?'selected':'' ?>>CDI</option>
                                <option value="CDD" <?= in_array($typeContrat,['CDD','CDD Usage','Saisonnier'])?'selected':'' ?>>CDD</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Ancienneté (années)</label>
                        <input type="number" id="calcAnciennete" class="form-control" step="0.5" value="0" min="0">
                    </div>

                    <hr class="my-3">
                    <p class="text-muted small mb-2 fw-semibold">Heures spéciales / mois</p>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small">Nuit (21h–6h)</label>
                            <input type="number" id="calcNuit" class="form-control form-control-sm" step="0.5" value="<?= $heuresNuitPlanning ?>" min="0">
                        </div>
                        <div class="col-6">
                            <label class="form-label small">Dimanche</label>
                            <input type="number" id="calcDimanche" class="form-control form-control-sm" step="0.5" value="<?= $heuresDimPlanning ?>" min="0">
                        </div>
                    </div>

                    <hr class="my-3">
                    <p class="text-muted small mb-2 fw-semibold">Contrôle des HS (optionnel)</p>
                    <div class="mb-3">
                        <label class="form-label small">Taux HS pratiqué (ex: 0.10 si payé à 10%)</label>
                        <input type="number" id="calcTauxHs" class="form-control form-control-sm" step="0.01" value="" min="0" max="1" placeholder="Laisser vide si conforme">
                    </div>

                    <div class="mb-3" id="blockCdd" style="display:none">
                        <label class="form-label small">Rémunération totale brute CDD (€) — pour prime précarité</label>
                        <input type="number" id="calcTotalCdd" class="form-control form-control-sm" step="0.01" value="<?= in_array($typeContrat,['CDD','CDD Usage','Saisonnier']) ? h($calcSalaire) : '' ?>" min="0">
                    </div>

                    <button class="btn btn-ov-primary w-100" id="btnCalcCcn" onclick="runCalculCCN()">
                        <i class="fa fa-calculator me-1"></i>Calculer &amp; Auditer
                    </button>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="ov-card h-100">
                <div class="ov-card-body">
                    <h6 class="mb-3"><i class="fa fa-chart-line me-1 text-muted"></i>Rapport CCN 1351</h6>
                    <div id="calcLoading" class="d-none text-center py-5">
                        <div class="spinner-border" style="color:var(--ov-gold)" role="status"></div>
                        <div class="text-muted small mt-2">Calcul en cours…</div>
                    </div>
                    <div id="calcEmpty" class="text-center py-5 text-muted">
                        <i class="fa fa-calculator fa-3x mb-3 d-block" style="opacity:.15"></i>
                        Renseignez les paramètres et cliquez sur "Calculer &amp; Auditer"
                    </div>
                    <pre id="calcResult" class="d-none result-terminal"></pre>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════ TAB 3 : ANALYSE 4 EXPERTS ══════════════ -->
<div class="tab-pane fade" id="tab-experts" role="tabpanel">
    <div class="row g-3">
        <div class="col-lg-4">
            <div class="ov-card">
                <div class="ov-card-body">
                    <h6 class="mb-3"><i class="fa fa-users me-1 text-muted"></i>Panel d'experts</h6>
                    <div class="d-flex flex-column gap-2 mb-4">
                        <div class="p-2 rounded small" style="background:rgba(239,68,68,0.08);border-left:3px solid #ef4444">
                            <span class="fw-bold" style="color:#ef4444">⚖️ URSSAF</span><br>
                            <span class="text-muted">Cotisations, Fillon, DPAE, primes non déclarées</span>
                        </div>
                        <div class="p-2 rounded small" style="background:rgba(249,115,22,0.08);border-left:3px solid #f97316">
                            <span class="fw-bold" style="color:#f97316">🏛️ Juriste</span><br>
                            <span class="text-muted">Conformité CCN 1351, CNAPS, clauses, période d'essai</span>
                        </div>
                        <div class="p-2 rounded small" style="background:rgba(139,92,246,0.08);border-left:3px solid #8b5cf6">
                            <span class="fw-bold" style="color:#8b5cf6">🛡️ Pôle Social</span><br>
                            <span class="text-muted">Prévoyance, mutuelle, CPF, portabilité</span>
                        </div>
                        <div class="p-2 rounded small" style="background:rgba(16,185,129,0.08);border-left:3px solid #10b981">
                            <span class="fw-bold" style="color:#10b981">🧮 Expert-Comptable</span><br>
                            <span class="text-muted">Recalcul salaire, HS, primes, cotisations, écarts</span>
                        </div>
                    </div>
                    <div class="alert alert-warning py-2 small mb-3">
                        <i class="fa fa-clock me-1"></i>Durée : <strong>20 à 40 secondes</strong> (analyse approfondie via Claude API)
                    </div>
                    <button class="btn btn-ov-primary w-100" id="btnExperts" onclick="runExpertsAnalysis()">
                        <i class="fa fa-wand-magic-sparkles me-1"></i>Lancer l'analyse complète
                    </button>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="ov-card h-100">
                <div class="ov-card-body">
                    <h6 class="mb-3"><i class="fa fa-file-invoice me-1 text-muted"></i>Rapport complet</h6>
                    <div id="expertsLoading" class="d-none text-center py-5">
                        <div class="spinner-border" style="color:var(--ov-gold);width:2.5rem;height:2.5rem" role="status"></div>
                        <div class="mt-3 fw-semibold">4 experts analysent le contrat…</div>
                        <div class="text-muted small mt-1">URSSAF · Juriste · Pôle Social · Expert-Comptable</div>
                    </div>
                    <div id="expertsEmpty" class="text-center py-5 text-muted">
                        <i class="fa fa-users fa-3x mb-3 d-block" style="opacity:.15"></i>
                        Cliquez sur "Lancer l'analyse complète" pour activer le panel d'experts
                    </div>
                    <div id="expertsResult" class="d-none" style="max-height:560px;overflow-y:auto"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════ TAB 4 : À PROPOS ══════════════ -->
<div class="tab-pane fade" id="tab-about" role="tabpanel">
    <div class="ov-card">
        <div class="ov-card-body">
            <h5 class="mb-4"><i class="fa fa-puzzle-piece me-2" style="color:var(--ov-gold)"></i>Skill installé — Analyseur Contrats Sécurité Privée FR</h5>

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="p-3 rounded h-100" style="background:rgba(201,168,76,0.08);border:1px solid rgba(201,168,76,0.2)">
                        <div class="fw-bold mb-2"><i class="fa fa-brain me-2" style="color:var(--ov-gold)"></i>SKILL.md — Panel 4 experts</div>
                        <p class="small text-muted mb-0">URSSAF · Juriste · Pôle Social · Expert-Comptable — 8 étapes d'analyse, 50+ vérifications, tableau de bord des risques avec chiffrage.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 rounded h-100" style="background:rgba(99,102,241,0.08);border:1px solid rgba(99,102,241,0.2)">
                        <div class="fw-bold mb-2"><i class="fa fa-code me-2" style="color:#818cf8"></i>analyze_contract.py</div>
                        <p class="small text-muted mb-0">Pré-analyse structurelle : 11 mentions obligatoires, 6 clauses sensibles, risque SMIC, période d'essai. Fonctionne hors ligne, sans API.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 rounded h-100" style="background:rgba(16,185,129,0.08);border:1px solid rgba(16,185,129,0.2)">
                        <div class="fw-bold mb-2"><i class="fa fa-code me-2" style="color:#34d399"></i>calculs_securite.py</div>
                        <p class="small text-muted mb-0">Moteur de calcul IDCC 1351 : SMIC, minimum CCN, primes nuit/dimanche, ancienneté, cotisations salariales, réduction Fillon, prime précarité CDD.</p>
                    </div>
                </div>
            </div>

            <div class="alert" style="background:rgba(59,130,246,0.08);border:1px solid rgba(59,130,246,0.25);color:#1e40af">
                <i class="fa fa-lightbulb me-2"></i><strong>Analyse approfondie complète via Claude Code</strong><br>
                Pour l'analyse à 4 experts avec corrections rédigées et chiffrage détaillé, utilisez Claude Code directement.
                Le skill <code>contrat-analyzer-skill</code> est installé globalement et se déclenche automatiquement dès que vous collez un contrat ou mentionnez "IDCC 1351", "APS", "CNAPS" ou "sécurité privée".
            </div>

            <h6 class="mt-3 mb-2">Ce que le skill contrôle automatiquement</h6>
            <div class="row g-2 mb-3">
                <?php
                $checks = [
                    ['URSSAF','Cotisations, exo. Fillon, avantages en nature, DPAE, DSN','#ef4444'],
                    ['Juriste','Mentions obligatoires, CCN 1351, CNAPS, périodes d\'essai, clauses','#f97316'],
                    ['Pôle Social','Prévoyance Malakoff Humanis, mutuelle, CPF, portabilité','#8b5cf6'],
                    ['Expert-Compta','SMIC, minimum CCN, HS, primes, cotisations, prime précarité','#10b981'],
                ];
                foreach ($checks as [$label, $desc, $color]): ?>
                <div class="col-md-6">
                    <div class="p-2 rounded small" style="background:<?= $color ?>18;border:1px solid <?= $color ?>33">
                        <span class="fw-bold" style="color:<?= $color ?>"><?= h($label) ?></span> — <span class="text-muted"><?= h($desc) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <h6 class="mb-2">Fichiers installés</h6>
            <div class="p-3 rounded font-monospace small" style="background:#f8f9fa;border:1px solid #dee2e6;line-height:2">
                <div><i class="fa fa-folder text-warning me-1"></i> C:\Users\admin\.claude\skills\contrat-analyzer-skill\</div>
                <div class="ms-4"><i class="fa fa-file-lines text-primary me-1"></i> SKILL.md</div>
                <div class="ms-4"><i class="fa fa-folder text-warning me-1"></i> references\</div>
                <div class="ms-5"><i class="fa fa-file-lines text-muted me-1"></i> REFERENCE.md</div>
                <div class="ms-4"><i class="fa fa-folder text-warning me-1"></i> scripts\</div>
                <div class="ms-5"><i class="fa fa-file-code text-success me-1"></i> analyze_contract.py</div>
                <div class="ms-5"><i class="fa fa-file-code text-success me-1"></i> calculs_securite.py</div>
            </div>
        </div>
    </div>
</div>

</div><!-- /tab-content -->

<script>
// Afficher/masquer le champ total CDD selon le type sélectionné
document.getElementById('calcType').addEventListener('change', function() {
    document.getElementById('blockCdd').style.display = this.value === 'CDD' ? 'block' : 'none';
});
// Init
if (document.getElementById('calcType').value === 'CDD') {
    document.getElementById('blockCdd').style.display = 'block';
}

function runExpertsAnalysis() {
    // Récupère le texte depuis l'onglet 1 (pré-analyse)
    var text = document.getElementById('contractText').value.trim();
    if (!text) { alert('Le texte du contrat est vide. Allez d\'abord sur l\'onglet "Analyse structurelle".'); return; }
    document.getElementById('expertsEmpty').classList.add('d-none');
    document.getElementById('expertsResult').classList.add('d-none');
    document.getElementById('expertsLoading').classList.remove('d-none');
    document.getElementById('btnExperts').disabled = true;

    var fd = new FormData();
    fd.append('action', 'analyse_experts');
    fd.append('contract_text', text);

    fetch('analyse_contrat.php?id=<?= $id ?>', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            document.getElementById('expertsLoading').classList.add('d-none');
            document.getElementById('btnExperts').disabled = false;
            if (data.ok) {
                document.getElementById('expertsResult').innerHTML = markdownToHtml(data.result);
                document.getElementById('expertsResult').classList.remove('d-none');
            } else {
                alert('Erreur : ' + (data.error || 'inconnue'));
                document.getElementById('expertsEmpty').classList.remove('d-none');
            }
        })
        .catch(e => {
            document.getElementById('expertsLoading').classList.add('d-none');
            document.getElementById('btnExperts').disabled = false;
            document.getElementById('expertsEmpty').classList.remove('d-none');
            alert('Erreur réseau : ' + e.message);
        });
}

function markdownToHtml(md) {
    // Conversion Markdown basique vers HTML lisible
    return md
        // Titres
        .replace(/^### (.+)$/gm, '<h5 class="mt-3 mb-2">$1</h5>')
        .replace(/^## (.+)$/gm,  '<h4 class="mt-4 mb-2 border-bottom pb-1">$1</h4>')
        .replace(/^# (.+)$/gm,   '<h3 class="mt-4 mb-2">$1</h3>')
        // Blocs de code (```)
        .replace(/```[\w]*\n([\s\S]*?)```/gm, '<pre class="result-terminal mt-2">$1</pre>')
        // Gras
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        // Italique
        .replace(/\*(.+?)\*/g, '<em>$1</em>')
        // Lignes séparatrices
        .replace(/^={3,}$/gm, '<hr>')
        .replace(/^-{3,}$/gm, '<hr class="my-1">')
        // Listes à puces
        .replace(/^\s*[-*•] (.+)$/gm, '<li>$1</li>')
        .replace(/(<li>.*<\/li>\n?)+/g, '<ul class="mb-2">$&</ul>')
        // Emojis + badges d'alerte
        .replace(/(🔴\s*\S+-CRITIQUE[^\n]*)/g, '<span class="badge bg-danger me-1">$1</span>')
        .replace(/(🟠\s*\S+-MAJEUR[^\n]*)/g,   '<span class="badge bg-warning text-dark me-1">$1</span>')
        .replace(/(🟡\s*\S+-VIGILANCE[^\n]*)/g,'<span class="badge bg-info text-dark me-1">$1</span>')
        .replace(/(✅[^\n]+)/g, '<span class="text-success">$1</span>')
        // Sauts de ligne
        .replace(/\n\n/g, '</p><p>')
        .replace(/\n/g,   '<br>')
        .replace(/^/, '<p>')
        .replace(/$/, '</p>');
}

function runPreanalyse() {
    var text = document.getElementById('contractText').value.trim();
    if (!text) { alert('Texte vide.'); return; }
    document.getElementById('analyseEmpty').classList.add('d-none');
    document.getElementById('analyseResult').classList.add('d-none');
    document.getElementById('analyseLoading').classList.remove('d-none');
    document.getElementById('btnRunAnalyse').disabled = true;

    var fd = new FormData();
    fd.append('action', 'run_preanalyse');
    fd.append('contract_text', text);

    fetch('analyse_contrat.php?id=<?= $id ?>', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            document.getElementById('analyseLoading').classList.add('d-none');
            document.getElementById('btnRunAnalyse').disabled = false;
            if (data.ok) {
                document.getElementById('analyseResult').textContent = data.result;
                document.getElementById('analyseResult').classList.remove('d-none');
            } else {
                alert('Erreur : ' + (data.error || 'inconnue'));
                document.getElementById('analyseEmpty').classList.remove('d-none');
            }
        })
        .catch(e => {
            document.getElementById('analyseLoading').classList.add('d-none');
            document.getElementById('btnRunAnalyse').disabled = false;
            document.getElementById('analyseEmpty').classList.remove('d-none');
            alert('Erreur : ' + (e.message || 'réponse non-JSON du serveur'));
        });
}

function runCalculCCN() {
    var salaire = parseFloat(document.getElementById('calcSalaire').value);
    if (!salaire || salaire <= 0) { alert('Salaire requis.'); return; }
    document.getElementById('calcEmpty').classList.add('d-none');
    document.getElementById('calcResult').classList.add('d-none');
    document.getElementById('calcLoading').classList.remove('d-none');
    document.getElementById('btnCalcCcn').disabled = true;

    var fd = new FormData();
    fd.append('action',      'calcul_ccn');
    fd.append('salaire',     document.getElementById('calcSalaire').value);
    fd.append('coeff',       document.getElementById('calcCoeff').value);
    fd.append('heures',      document.getElementById('calcHeures').value);
    fd.append('type',        document.getElementById('calcType').value);
    fd.append('nuit',        document.getElementById('calcNuit').value);
    fd.append('dimanche',    document.getElementById('calcDimanche').value);
    fd.append('anciennete',  document.getElementById('calcAnciennete').value);
    var tauxHs = document.getElementById('calcTauxHs').value;
    if (tauxHs) fd.append('taux_hs', tauxHs);
    var totalCdd = document.getElementById('calcTotalCdd').value;
    if (totalCdd) fd.append('total_cdd', totalCdd);

    fetch('analyse_contrat.php?id=<?= $id ?>', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            document.getElementById('calcLoading').classList.add('d-none');
            document.getElementById('btnCalcCcn').disabled = false;
            if (data.ok) {
                document.getElementById('calcResult').textContent = data.result;
                document.getElementById('calcResult').classList.remove('d-none');
            } else {
                alert('Erreur : ' + (data.error || 'inconnue'));
                document.getElementById('calcEmpty').classList.remove('d-none');
            }
        })
        .catch(e => {
            document.getElementById('calcLoading').classList.add('d-none');
            document.getElementById('btnCalcCcn').disabled = false;
            document.getElementById('calcEmpty').classList.remove('d-none');
            alert('Erreur : ' + (e.message || 'réponse non-JSON du serveur'));
        });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
