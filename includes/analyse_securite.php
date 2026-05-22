<?php
/**
 * analyse_securite.php — Port PHP des scripts Python du contrat-analyzer-skill
 * Analyse structurelle + calculateur CCN 1351 (IDCC 1351)
 * Constantes 2025 — mettre à jour annuellement
 */

const AS_SMIC_HORAIRE   = 11.88;
const AS_HEURES_LEGALES = 151.67;
const AS_SMIC_MENSUEL   = 1801.84;   // 11.88 × 151.67
const AS_PMSS           = 3925.00;

const AS_CCN_GRILLE = [
    120 => ['label' => 'Employé administratif débutant',       'min' => 1801.84],
    130 => ['label' => 'Employé administratif',                'min' => 1801.84],
    140 => ['label' => 'APS sans expérience',                  'min' => 1801.84],
    150 => ['label' => 'APS (TFP ou 6 mois expérience)',       'min' => 1820.00],
    160 => ['label' => 'APS expérimenté / Adj. chef de poste', 'min' => 1865.00],
    170 => ['label' => 'Chef de poste',                        'min' => 1930.00],
    175 => ['label' => 'Chef de poste expérimenté',            'min' => 1975.00],
    182 => ['label' => 'Chef de site adjoint / AM',            'min' => 2025.00],
    190 => ['label' => 'Chef de site adjoint exp.',            'min' => 2085.00],
    200 => ['label' => 'Chef de site',                         'min' => 2155.00],
    210 => ['label' => 'Cadre débutant / Resp. secteur',       'min' => 2400.00],
    230 => ['label' => 'Cadre confirmé',                       'min' => 2700.00],
    250 => ['label' => 'Cadre supérieur',                      'min' => 3000.00],
];

const AS_ANCIENNETE = [
    [0,  3,  0.00],
    [3,  6,  0.03],
    [6,  9,  0.06],
    [9,  12, 0.09],
    [12, 15, 0.12],
    [15, 999,0.15],
];

const AS_COTISATIONS = [
    'CSG déductible'        => ['assiette' => 0.9825, 'taux' => 0.0680, 'plaf' => false],
    'CSG non déductible'    => ['assiette' => 0.9825, 'taux' => 0.0240, 'plaf' => false],
    'CRDS'                  => ['assiette' => 0.9825, 'taux' => 0.0050, 'plaf' => false],
    'Vieillesse plafonnée'  => ['assiette' => 1.0000, 'taux' => 0.0690, 'plaf' => true ],
    'Vieillesse déplafonnée'=> ['assiette' => 1.0000, 'taux' => 0.0040, 'plaf' => false],
    'AGIRC-ARRCO T1'        => ['assiette' => 1.0000, 'taux' => 0.0315, 'plaf' => true ],
];

// ── Helpers ───────────────────────────────────────────────────────────────────

function as_norm(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $from = ['à','á','â','ã','ä','å','è','é','ê','ë','ì','í','î','ï','ò','ó','ô','õ','ö','ù','ú','û','ü','ý','ÿ','ñ','ç'];
    $to   = ['a','a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','y','y','n','c'];
    return str_replace($from, $to, $s);
}

function as_contains(string $haystack, array $needles): bool {
    foreach ($needles as $n) {
        if (str_contains($haystack, as_norm($n))) return true;
    }
    return false;
}

function as_sep(string $c = '=', int $n = 62): string { return str_repeat($c, $n); }
function as_ok(string $t): string  { return "  ✅ $t"; }
function as_err(string $t): string { return "  ❌ $t"; }
function as_warn(string $t): string{ return "  ⚠️  $t"; }

function as_ccnMin(int $coeff): array {
    if (isset(AS_CCN_GRILLE[$coeff])) return AS_CCN_GRILLE[$coeff];
    $lower = null;
    foreach (AS_CCN_GRILLE as $c => $v) {
        if ($c <= $coeff) $lower = $v;
    }
    return $lower ?? ['label' => 'Non répertorié', 'min' => AS_SMIC_MENSUEL];
}

function as_tauxAnciennete(float $ans): float {
    foreach (AS_ANCIENNETE as [$debut, $fin, $taux]) {
        if ($ans >= $debut && $ans < $fin) return $taux;
    }
    return 0.15;
}

// ══════════════════════════════════════════════════════════════════════════════
// ANALYSE STRUCTURELLE
// ══════════════════════════════════════════════════════════════════════════════

function analyseStructurelle(string $text): string {
    $n = as_norm($text);
    $out = [];

    $out[] = as_sep('=');
    $out[] = '   PRÉ-ANALYSE STRUCTURELLE DU CONTRAT';
    $out[] = '   (Complétée par l\'analyse approfondie 4 experts)';
    $out[] = as_sep('=');
    $out[] = '';
    $out[] = '📚 Référentiel CCN : IDCC 1351 — Prévention et Sécurité';
    $out[] = '';

    // ── 0. Valeurs extraites du texte ─────────────────────────────────────────
    $out[] = as_sep('-');
    $out[] = '   📊 VALEURS EXTRAITES DU CONTRAT';
    $out[] = as_sep('-');

    // Type de contrat
    $typeDetecte = '⚠️  Non détecté';
    if (preg_match('/\b(contrat\s+)?CDD\s+d[\'' ]?usage\b/i', $text)) $typeDetecte = 'CDD d\'Usage ✅';
    elseif (preg_match('/\b(contrat\s+[àa]\s+dur[ée]{1,2}e?\s+)?d[ée]termin[ée]e?\b/i', $text)) $typeDetecte = 'CDD ✅';
    elseif (preg_match('/\b(contrat\s+[àa]\s+dur[ée]{1,2}e?\s+)?ind[ée]termin[ée]e?\b/i', $text)) $typeDetecte = 'CDI ✅';
    elseif (preg_match('/\bCDI\b/', $text)) $typeDetecte = 'CDI ✅';
    elseif (preg_match('/\bCDD\b/', $text)) $typeDetecte = 'CDD ✅';
    $out[] = '  Type de contrat      : ' . $typeDetecte;

    // Dates du contrat
    $datesDetectees = '⚠️  Non détectées';
    if (preg_match('/du\s+(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4})\s+au\s+(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4})/i', $text, $m)) {
        $datesDetectees = $m[1] . ' → ' . $m[2] . ' ✅';
    } elseif (preg_match('/[àa]\s+compter\s+du\s+(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4})/i', $text, $m)) {
        $datesDetectees = 'À compter du ' . $m[1] . ' ✅';
    }
    $out[] = '  Période              : ' . $datesDetectees;

    // Période d'essai
    $peDetectee = '⚠️  Non détectée';
    if (preg_match('/p[eé]riode\s+d[\'']essai\s+de\s+(\d+)\s*(jours?|semaines?|mois)/i', $text, $m)) {
        $peDetectee = $m[1] . ' ' . $m[2] . ' ✅';
    } elseif (preg_match('/(\d+)\s*(jours?\s+travaill[ée]s?)/i', $text, $m) && str_contains(as_norm($text), 'essai')) {
        $peDetectee = $m[1] . ' ' . $m[2] . ' ✅';
    } elseif (str_contains($n, 'pas de periode d essai') || str_contains($n, 'sans periode d essai') || str_contains($n, '0 jour')) {
        $peDetectee = 'Aucune (contrat < 7 jours) ✅';
    }
    $out[] = '  Période d\'essai      : ' . $peDetectee;

    // Taux horaire / salaire
    $salaireDetecte = '⚠️  Non détecté';
    if (preg_match('/(\d{1,2}[,\.]\d{2})\s*[€E]?\s*(?:\/h|par\s+heure|horaire)/i', $text, $m)) {
        $salaireDetecte = str_replace('.', ',', $m[1]) . ' €/h ✅';
    }
    $out[] = '  Taux horaire         : ' . $salaireDetecte;

    // Total heures contrat
    $totalHDetecte = '⚠️  Non détecté';
    if (preg_match('/(\d+(?:[,\.]\d+)?)\s*heures?\s+(?:pour|globale|total|sur\s+la\s+p[eé]riode)/i', $text, $m)) {
        $totalHDetecte = str_replace('.', ',', $m[1]) . ' h ✅';
    } elseif (preg_match('/dur[ée]e?\s+globale\s+de\s+travail.*?(\d+(?:[,\.]\d+)?)\s*heures?/i', $text, $m)) {
        $totalHDetecte = str_replace('.', ',', $m[1]) . ' h ✅';
    }
    $out[] = '  Total heures contrat : ' . $totalHDetecte;

    // Coefficient / classification
    $coeffDetecte = '⚠️  Non détecté';
    if (preg_match('/[Cc]oefficient\s+(\d{3})/i', $text, $m)) {
        $coeffDetecte = $m[1];
        $ccnInfo = as_ccnMin((int)$m[1]);
        $coeffDetecte .= ' — ' . $ccnInfo['label'] . ' ✅';
    } elseif (preg_match('/[Cc]oeff\.?\s*:?\s*(\d{3})/i', $text, $m)) {
        $coeffDetecte = $m[1] . ' ✅';
    }
    $out[] = '  Coefficient CCN      : ' . $coeffDetecte;

    // Qualification
    $qualifDetectee = '⚠️  Non détectée';
    if (preg_match('/\b(SSIAP\s*\d?|APS|Agent\s+de\s+(?:pr[ée]vention|s[ée]curit[ée])|Chef\s+de\s+poste|Chef\s+de\s+site|Agent\s+cynophile|Rondier)\b/i', $text, $m)) {
        $qualifDetectee = $m[1] . ' ✅';
    }
    $out[] = '  Qualification        : ' . $qualifDetectee;

    // CNAPS
    $cnapsDetecte = '⚠️  Absent du texte';
    if (preg_match('/[A-Z]{3}-\d{3}-\d{3}-\d{3}/i', $text, $m)) {
        $cnapsDetecte = $m[0] . ' ✅';
    } elseif (str_contains($n, 'cnaps') || str_contains($n, 'carte professionnelle')) {
        $cnapsDetecte = 'Mentionné ✅ (numéro à vérifier)';
    }
    $out[] = '  N° autorisation CNAPS: ' . $cnapsDetecte;

    // Lieu / site
    $lieuDetecte = '⚠️  Non détecté';
    if (preg_match('/[Ll]ieu\s+de\s+travail\s*[:\.]\s*(.{3,60}?)(?:\n|\.)/s', $text, $m)) {
        $lieuDetecte = trim($m[1]) . ' ✅';
    } elseif (preg_match('/[Ss]ite\s+d\'affectation\s*[:\.]\s*(.{3,60}?)(?:\n|\.)/s', $text, $m)) {
        $lieuDetecte = trim($m[1]) . ' ✅';
    }
    $out[] = '  Lieu / site          : ' . $lieuDetecte;

    $out[] = '';

    // ── 1. Mentions obligatoires ──────────────────────────────────────────────
    $mentions = [
        'Identité des parties'          => ['employeur','salarie','societe','siret','siren','rcs','sas','sarl',' sa ','m.','mme'],
        'Type de contrat'               => ['contrat a duree indeterminee','cdi','contrat a duree determinee','cdd','temps partiel','temps plein'],
        'Date de début / prise de poste'=> ['prend effet','a compter du','date d entree','date de debut','embauche'],
        'Période d\'essai'              => ['periode d essai','essai','periode probatoire'],
        'Poste / Classification / Coeff'=> ['poste','emploi','fonction','classification','coefficient','niveau','echelon'],
        'Convention collective'         => ['convention collective','ccn','idcc','accord de branche','branche professionnelle'],
        'Lieu de travail'               => ['lieu de travail','etablissement','site','locaux'],
        'Durée du travail'              => ['heures par semaine','35 heures','duree hebdomadaire','temps de travail','151,67','151.67'],
        'Rémunération'                  => ['salaire','remuneration','brut','mensuel','euros','€'],
        'Congés payés'                  => ['conges payes','conge','repos'],
        'Préavis / Rupture'             => ['preavis','rupture','demission','licenciement','delai de prevenance'],
    ];

    $out[] = '📋 MENTIONS OBLIGATOIRES';
    $out[] = '';
    $score = 0; $missing = [];
    foreach ($mentions as $label => $kw) {
        $found = as_contains($n, $kw);
        if ($found) { $score++; $out[] = as_ok($label); }
        else        { $missing[] = $label; $out[] = as_err($label); }
    }
    $pct   = (int)round($score / count($mentions) * 100);
    $emoji = $pct >= 80 ? '🟢' : ($pct >= 50 ? '🟡' : '🔴');
    $out[] = '';
    $out[] = "  $emoji Score : $score/" . count($mentions) . " mentions détectées ($pct%)";

    // ── 2. Période d'essai ────────────────────────────────────────────────────
    $out[] = '';
    $out[] = '⏱  PÉRIODE D\'ESSAI';
    if (preg_match('/(\d+)\s*(mois|semaines?|jours?)\s*(?:d[e\']?\s*)?p[eé]riode\s*d[\'e]essai|p[eé]riode\s*d[\'e]essai[^.]*?(\d+)\s*(mois|semaines?|jours?)/ui', $text, $m)) {
        $val = ($m[1] ?: $m[3]) . ' ' . ($m[2] ?: $m[4]);
        $out[] = "  Valeur détectée : $val";
        if (str_contains(strtolower($m[2] ?: $m[4]), 'mois') && (int)($m[1] ?: $m[3]) > 4) {
            $out[] = as_warn("Durée > 4 mois : vérifier la catégorie (max 2 mois employés, 4 mois AM, 6 mois cadres CCN 1351)");
        } else {
            $out[] = as_ok("Durée dans les limites courantes");
        }
    } else {
        $out[] = as_warn("Aucune durée explicite détectée — à vérifier manuellement");
    }

    // ── 3. Vérification rémunération ──────────────────────────────────────────
    $out[] = '';
    $out[] = '💶 VÉRIFICATION RÉMUNÉRATION';
    preg_match_all('/(\d[\d\s]*(?:[.,]\d+)?)\s*(?:euros?|€)\s*(?:bruts?|mensuel)?/ui', $text, $amounts);
    $salaireTrouve = false;
    foreach ($amounts[1] as $raw) {
        $val = (float)str_replace([' ', ','], ['', '.'], $raw);
        if ($val > 100 && $val < 1800) {
            $out[] = as_warn("Montant détecté : " . number_format($val, 2, ',', ' ') . " € — potentiellement sous le SMIC (" . number_format(AS_SMIC_MENSUEL, 2, ',', ' ') . " €/mois)");
            $salaireTrouve = true;
        }
    }
    if (!$salaireTrouve) $out[] = as_ok("Aucun montant manifestement sous le SMIC détecté");

    // ── 4. Vérifications spécifiques sécurité ─────────────────────────────────
    $out[] = '';
    $out[] = '🔐 VÉRIFICATIONS SPÉCIFIQUES SÉCURITÉ PRIVÉE';
    $out[] = '';
    $checks = [
        ['Carte professionnelle CNAPS mentionnée',       ['cnaps','carte professionnelle','carte pro']],
        ['Habilitation CNAPS / agrément',                ['habilitation','agrement','autorisation cnaps']],
        ['Qualification APS / SSIAP mentionnée',         ['aps','ssiap','tfp','qualification','agent de prevention']],
        ['Mention aptitude médicale',                    ['aptitude medicale','visite medicale','service de sante','medecin du travail']],
        ['Obligation casier judiciaire vierge',          ['casier judiciaire','b3','mention de condamnation']],
        ['Tenue de travail / uniforme',                  ['tenue','uniforme','vetement','equipement']],
    ];
    foreach ($checks as [$label, $kw]) {
        $out[] = (as_contains($n, $kw) ? as_ok($label) : as_warn("$label — absent (vérifier)"));
    }

    // ── 5. Clauses sensibles ──────────────────────────────────────────────────
    $out[] = '';
    $out[] = '⚡ CLAUSES SENSIBLES';
    $out[] = '';
    $clauses = [
        'Clause de non-concurrence' => [
            'kw'  => ['non-concurrence','non concurrence','concurrence'],
            'msg' => 'Vérifier : contrepartie financière obligatoire, zone géographique précise (Cass. Soc. 10/07/2002)',
        ],
        'Clause de mobilité' => [
            'kw'  => ['mobilite','mutation','zone geographique'],
            'msg' => 'Vérifier : zone définie précisément (pas "France entière" — Cass. Soc. 23/05/2006)',
        ],
        'Clause de confidentialité' => [
            'kw'  => ['confidentialite','secret professionnel','informations confidentielles'],
            'msg' => 'Vérifier : durée limitée, ne pas interdire de témoigner en justice',
        ],
        'Clause d\'exclusivité' => [
            'kw'  => ['exclusivite','activite exclusive','tout emploi'],
            'msg' => 'Vérifier : justification légitime et proportionnée',
        ],
    ];
    $nbSensible = 0;
    foreach ($clauses as $label => $def) {
        if (as_contains($n, $def['kw'])) {
            $nbSensible++;
            $out[] = "  🔍 $label";
            $out[] = "     → {$def['msg']}";
            $out[] = '';
        }
    }
    if ($nbSensible === 0) $out[] = as_ok("Aucune clause sensible particulière détectée");

    // ── 6. Mentions manquantes ────────────────────────────────────────────────
    if ($missing) {
        $out[] = '';
        $out[] = '❌ MENTIONS POTENTIELLEMENT ABSENTES';
        $out[] = '';
        foreach ($missing as $m) $out[] = "  • $m";
    }

    // ── 7. Résumé ─────────────────────────────────────────────────────────────
    $out[] = '';
    $out[] = as_sep('=');
    $out[] = '   RÉSUMÉ';
    $out[] = as_sep('=');
    $out[] = '';
    $out[] = "  $emoji Taux de mentions présentes : $pct%";
    $out[] = "  📊 Clauses sensibles détectées : $nbSensible";
    $out[] = '';
    $out[] = '  ➡️  Utilisez l\'onglet "Analyse 4 experts" pour l\'audit approfondi.';
    $out[] = as_sep('=');

    return implode("\n", $out);
}

// ══════════════════════════════════════════════════════════════════════════════
// CALCULATEUR CCN 1351
// ══════════════════════════════════════════════════════════════════════════════

function calculCCN1351(array $p): string {
    $salaire    = (float)$p['salaire'];
    $coeff      = (int)  $p['coeff'];
    $heures     = (float)$p['heures'];
    $nuit       = (float)($p['nuit']       ?? 0);
    $dimanche   = (float)($p['dimanche']   ?? 0);
    $anciennete = (float)($p['anciennete'] ?? 0);
    $type       = strtoupper($p['type']    ?? 'CDI');
    $totalCdd   = (float)($p['total_cdd'] ?? 0);
    $tauxHs     = isset($p['taux_hs']) && $p['taux_hs'] > 0 ? (float)$p['taux_hs'] : null;

    $tauxHoraire = $heures > 0 ? $salaire / $heures : 0;
    $ccnInfo     = as_ccnMin($coeff);
    $alertes     = [];
    $out         = [];

    $SEP = as_sep('=');
    $sep = as_sep('-');

    $out[] = '';
    $out[] = $SEP;
    $out[] = '   RAPPORT D\'AUDIT — CCN PRÉVENTION-SÉCURITÉ IDCC 1351';
    $out[] = $SEP;
    $out[] = '';
    $out[] = '📋 DONNÉES DU CONTRAT ANALYSÉ';
    $out[] = $sep;
    $contratCourt = $heures < AS_HEURES_LEGALES; // CDD court ou temps partiel
    $modeLabel    = $contratCourt
        ? '(total contrat ou période — pas mensuel)'
        : '(mensuel — 35h/semaine)';

    $out[] = '  Salaire brut             : ' . number_format($salaire, 2, ',', ' ') . ' € ' . $modeLabel;
    $out[] = '  Coefficient CCN          : ' . $coeff;
    $out[] = '  Qualification            : ' . $ccnInfo['label'];
    $out[] = '  Heures contractuelles    : ' . number_format($heures, 2, ',', ' ') . ' h ' . $modeLabel;
    $out[] = '  Taux horaire calculé     : ' . number_format($tauxHoraire, 4, ',', ' ') . ' €/h';
    $out[] = '  Type de contrat          : ' . $type;
    if ($anciennete > 0) $out[] = '  Ancienneté               : ' . $anciennete . ' an(s)';
    if ($contratCourt) {
        $out[] = '';
        $out[] = '  ℹ️  Contrat court / temps partiel détecté (' . number_format($heures, 2, ',', ' ') . ' h < 151,67 h)';
        $out[] = '     Vérification basée sur le taux horaire — pas sur le salaire mensuel brut.';
    }

    // ── Conformité SMIC ────────────────────────────────────────────────────────
    $out[] = '';
    $out[] = $SEP;
    $out[] = '   VÉRIFICATIONS DE CONFORMITÉ';
    $out[] = $SEP;
    $out[] = '';

    if ($contratCourt) {
        // Pour un contrat court : vérifier le taux horaire vs SMIC horaire uniquement
        $thSmicOk = $tauxHoraire >= AS_SMIC_HORAIRE;
        $out[] = ($thSmicOk ? '  ✅' : '  🔴') . ' Taux horaire ≥ SMIC horaire (' . AS_SMIC_HORAIRE . ' €/h)';
        if (!$thSmicOk) {
            $ecartH = AS_SMIC_HORAIRE - $tauxHoraire;
            $alertes[] = ['CRITIQUE','COMPTA','Taux horaire inférieur au SMIC',
                'Taux ' . number_format($tauxHoraire, 4) . ' €/h < SMIC horaire ' . AS_SMIC_HORAIRE . ' €/h',
                round($ecartH * $heures, 2), 'Porter le taux à ' . AS_SMIC_HORAIRE . ' €/h minimum'];
        }
        // Vérifier aussi le taux horaire vs minimum CCN
        $minCCN      = $ccnInfo['min'];
        $thMinCCN    = $minCCN / AS_HEURES_LEGALES;
        $thCcnOk     = $tauxHoraire >= $thMinCCN;
        $out[] = ($thCcnOk ? '  ✅' : '  🔴') . ' Taux horaire ≥ minimum CCN coeff ' . $coeff . ' (' . number_format($thMinCCN, 4, ',', ' ') . ' €/h)';
        if (!$thCcnOk) {
            $ecartH = $thMinCCN - $tauxHoraire;
            $alertes[] = ['CRITIQUE','COMPTA','Taux horaire inférieur au minimum CCN 1351',
                'Taux ' . number_format($tauxHoraire, 4) . ' €/h < minimum CCN coeff ' . $coeff . ' (' . number_format($thMinCCN, 4) . ' €/h)',
                round($ecartH * $heures, 2), 'Porter le taux horaire à ' . number_format($thMinCCN, 4) . ' €/h minimum'];
        }
        $out[] = '  ℹ️  Salaire total contrat : ' . number_format($salaire, 2, ',', ' ') . ' € (pour ' . number_format($heures, 2, ',', ' ') . ' h)';
    } else {
        // Contrat temps plein mensuel : vérifier le salaire mensuel
        $smicOk = $salaire >= AS_SMIC_MENSUEL;
        $out[] = ($smicOk ? '  ✅' : '  🔴') . ' SMIC mensuel (' . number_format(AS_SMIC_MENSUEL, 2, ',', ' ') . ' €)';
        if (!$smicOk) {
            $ecart = AS_SMIC_MENSUEL - $salaire;
            $out[]    = '     → Écart : ' . number_format($ecart, 2, ',', ' ') . ' € manquants';
            $alertes[] = ['CRITIQUE','COMPTA','Salaire inférieur au SMIC',
                'Salaire ' . number_format($salaire, 2) . '€ < SMIC ' . number_format(AS_SMIC_MENSUEL, 2) . '€',
                $ecart, 'Porter le salaire à ' . number_format(AS_SMIC_MENSUEL, 2) . ' €/mois minimum'];
        }

        $minCCN = $ccnInfo['min'];
        $ccnOk  = $salaire >= $minCCN;
        $out[] = ($ccnOk ? '  ✅' : '  🔴') . ' Minimum CCN coeff ' . $coeff . ' (' . number_format($minCCN, 2, ',', ' ') . ' €/mois)';
        if (!$ccnOk) {
            $ecart = $minCCN - $salaire;
            $out[]    = '     → Écart : ' . number_format($ecart, 2, ',', ' ') . ' € manquants';
            $alertes[] = ['CRITIQUE','COMPTA','Salaire inférieur au minimum CCN 1351',
                'Coeff ' . $coeff . ' → minimum ' . number_format($minCCN, 2) . ' € — salaire ' . number_format($salaire, 2) . ' €',
                $ecart, 'Porter le salaire à ' . number_format($minCCN, 2) . ' €/mois (min CCN coeff ' . $coeff . ')'];
        }
    }

    $thOk = $tauxHoraire >= AS_SMIC_HORAIRE;
    if (!$contratCourt) {
        $out[] = ($thOk ? '  ✅' : '  🔴') . ' Taux horaire ≥ SMIC (' . AS_SMIC_HORAIRE . ' €/h)';
    }

    // ── Heures supplémentaires ─────────────────────────────────────────────────
    if ($heures > AS_HEURES_LEGALES) {
        $hsTotal   = $heures - AS_HEURES_LEGALES;
        $seuil50   = 8 * (52 / 12);   // ~34,67 h/mois
        $hs25      = min($hsTotal, $seuil50);
        $hs50      = max(0, $hsTotal - $seuil50);
        $mont25    = $hs25 * $tauxHoraire * 1.25;
        $mont50    = $hs50 * $tauxHoraire * 1.50;
        $montTotal = $mont25 + $mont50;

        $out[] = '';
        $out[] = '  📊 HEURES SUPPLÉMENTAIRES (' . number_format($hsTotal, 2, ',', ' ') . ' h)';
        $out[] = '     HS à 25% : ' . number_format($hs25, 2, ',', ' ') . ' h → ' . number_format($mont25, 2, ',', ' ') . ' €';
        $out[] = '     HS à 50% : ' . number_format($hs50, 2, ',', ' ') . ' h → ' . number_format($mont50, 2, ',', ' ') . ' €';
        $out[] = '     Total HS  : ' . number_format($montTotal, 2, ',', ' ') . ' €/mois';

        if ($tauxHs !== null && $tauxHs < 0.25) {
            $manque = $hsTotal * $tauxHoraire * (0.25 - $tauxHs);
            $alertes[] = ['CRITIQUE','COMPTA','Taux HS insuffisant',
                'Taux contractuel ' . ($tauxHs * 100) . '% < 25% légal minimum (art. L.3121-33 C. trav.)',
                $manque, 'Appliquer minimum 25% pour les 8 premières HS/semaine'];
        }
    }

    // ── Prime de nuit ──────────────────────────────────────────────────────────
    if ($nuit > 0) {
        $primeNuit = $nuit * $tauxHoraire * 0.10;
        $out[] = '';
        $out[] = '  🌙 PRIME DE NUIT (' . $nuit . ' h de nuit/mois)';
        $out[] = '     Montant minimum CCN (10%) : ' . number_format($primeNuit, 2, ',', ' ') . ' €/mois';
        $out[] = '     Calcul : ' . $nuit . ' h × ' . number_format($tauxHoraire, 4, ',', ' ') . ' €/h × 10%';
    }

    // ── Prime dimanche ─────────────────────────────────────────────────────────
    if ($dimanche > 0) {
        $primeDim = $dimanche * $tauxHoraire * 0.10;
        $out[] = '';
        $out[] = '  ☀️  PRIME DIMANCHE (' . $dimanche . ' h dimanche/mois)';
        $out[] = '     Montant indicatif (10% CCN) : ' . number_format($primeDim, 2, ',', ' ') . ' €/mois';
    }

    // ── Prime d'ancienneté ─────────────────────────────────────────────────────
    if ($anciennete >= 3) {
        $taux       = as_tauxAnciennete($anciennete);
        $primeAnc   = $minCCN * $taux;
        $out[] = '';
        $out[] = '  ⭐ PRIME D\'ANCIENNETÉ (' . $anciennete . ' an(s) → ' . ($taux * 100) . '%)';
        $out[] = '     Base : ' . number_format($minCCN, 2, ',', ' ') . ' € (min CCN coeff ' . $coeff . ')';
        $out[] = '     Prime mensuelle : ' . number_format($primeAnc, 2, ',', ' ') . ' €';
    }

    // ── Cotisations salariales ─────────────────────────────────────────────────
    $totalCot = 0.0;
    $lignesCot = [];
    foreach (AS_COTISATIONS as $nom => $c) {
        $base = $c['plaf'] ? min($salaire, AS_PMSS) : ($salaire * $c['assiette']);
        $mont = $base * $c['taux'];
        $totalCot += $mont;
        $lignesCot[] = '     ' . str_pad($nom, 26) . ' : -' . number_format($mont, 2, ',', ' ') . ' €';
    }
    $netImp   = $salaire - $totalCot + ($salaire * 0.9825 * 0.0240); // net imposable (CSG ND réintégrée)
    $netPayer = $salaire - $totalCot;

    $out[] = '';
    $out[] = '  💶 SIMULATION COTISATIONS SALARIALES';
    $out[] = '     Salaire brut               : ' . number_format($salaire, 2, ',', ' ') . ' €';
    foreach ($lignesCot as $l) $out[] = $l;
    $out[] = '     ' . str_repeat('─', 38);
    $out[] = '     Total cotisations sal.     : -' . number_format($totalCot, 2, ',', ' ') . ' €';
    $out[] = '     Net imposable (estimé)     :  ' . number_format($netImp, 2, ',', ' ') . ' €';
    $out[] = '     Net à payer (estimé)       :  ' . number_format($netPayer, 2, ',', ' ') . ' €';

    // ── Réduction Fillon ───────────────────────────────────────────────────────
    $smic1_6 = AS_SMIC_MENSUEL * 1.6;
    if ($salaire <= $smic1_6) {
        $remuAn  = $salaire * 12;
        $smicAn  = AS_SMIC_MENSUEL * 12;
        $coefF   = min(0.3205, (0.3205 / 0.6) * (1.6 * $smicAn / $remuAn - 1));
        $redAn   = $remuAn * $coefF;
        $redMois = $redAn / 12;
        $out[] = '';
        $out[] = '  📉 RÉDUCTION FILLON';
        $out[] = '     Éligible : OUI (salaire ≤ 1,6 × SMIC = ' . number_format($smic1_6, 2, ',', ' ') . ' €)';
        $out[] = '     Réduction patronale estimée : ' . number_format($redMois, 2, ',', ' ') . ' €/mois';
    }

    // ── Prime de précarité CDD ─────────────────────────────────────────────────
    if ($type === 'CDD' && $totalCdd > 0) {
        $precarite = $totalCdd * 0.10;
        $out[] = '';
        $out[] = '  📄 PRIME DE PRÉCARITÉ CDD';
        $out[] = '     Base : ' . number_format($totalCdd, 2, ',', ' ') . ' € de rémunération totale';
        $out[] = '     Prime (10%) : ' . number_format($precarite, 2, ',', ' ') . ' €';
    }

    // ── Alertes ────────────────────────────────────────────────────────────────
    if ($alertes) {
        $out[] = '';
        $out[] = $SEP;
        $out[] = '   ⚠️  ANOMALIES DÉTECTÉES (' . count($alertes) . ')';
        $out[] = $SEP;
        foreach ($alertes as [$niveau, $expert, $titre, $detail, $montant, $correction]) {
            $emoji = $niveau === 'CRITIQUE' ? '🔴' : '🟠';
            $out[] = '';
            $out[] = "$emoji [$expert] $titre";
            $out[] = '  Détail     : ' . $detail;
            if ($montant > 0) $out[] = '  Impact €   : ' . number_format($montant, 2, ',', ' ') . ' €';
            if ($correction)  $out[] = '  Correction : ' . $correction;
        }
    } else {
        $out[] = '';
        $out[] = '✅ AUCUNE ANOMALIE DÉTECTÉE — Contrat conforme sur les points vérifiés';
    }

    // ── Résumé financier ───────────────────────────────────────────────────────
    $nbCrit  = count(array_filter($alertes, fn($a) => $a[0] === 'CRITIQUE'));
    $nbMaj   = count(array_filter($alertes, fn($a) => $a[0] === 'MAJEUR'));
    $score   = max(0, 100 - $nbCrit * 20 - $nbMaj * 10);
    $emoji   = $score >= 80 ? '🟢' : ($score >= 50 ? '🟡' : '🔴');
    $manqueTot = array_sum(array_column(array_filter($alertes, fn($a) => in_array($a[0], ['CRITIQUE','MAJEUR'])), 4));

    $out[] = '';
    $out[] = $SEP;
    $out[] = '   RÉSUMÉ FINANCIER';
    $out[] = $SEP;
    $out[] = "$emoji Score de conformité estimé : $score/100";
    $out[] = '🔴 Alertes critiques : ' . $nbCrit;
    $out[] = '🟠 Alertes majeures  : ' . $nbMaj;
    if ($manqueTot > 0) {
        $out[] = '💶 Manque à gagner salarié : ' . number_format($manqueTot, 2, ',', ' ') . ' €/mois (' . number_format($manqueTot * 12, 2, ',', ' ') . ' €/an)';
    }
    $out[] = '';
    $out[] = $SEP;
    $out[] = '  ⚠️  Résultats fournis à titre indicatif.';
    $out[] = '      Vérifier les grilles CCN sur legifrance.gouv.fr';
    $out[] = '      Consulter un expert-comptable pour décision engageante.';
    $out[] = $SEP;

    return implode("\n", $out);
}
