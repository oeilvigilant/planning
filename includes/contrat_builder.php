<?php
if (!defined('APP_ROOT')) exit;

/**
 * Calcule la période d'essai CDD : 1 jour par semaine complète de contrat.
 */
function calculerPeriodeEssai(string $dateDebut, string $dateFin): string {
    if (!$dateDebut || !$dateFin) return '0 jour';
    if (strpos($dateDebut, '/') !== false) {
        $dDebut = DateTime::createFromFormat('d/m/Y', $dateDebut);
        $dFin   = DateTime::createFromFormat('d/m/Y', $dateFin);
    } else {
        $dDebut = new DateTime($dateDebut);
        $dFin   = new DateTime($dateFin);
    }
    if (!$dDebut || !$dFin || $dFin <= $dDebut) return '0 jour';
    $nbJours    = (int)$dDebut->diff($dFin)->days;
    if ($nbJours < 7) return '0 jour';
    $nbSemaines = (int)floor($nbJours / 7);
    return $nbSemaines . ' jour' . ($nbSemaines > 1 ? 's' : '') . ' travaillé' . ($nbSemaines > 1 ? 's' : '');
}

function _contratCss(string $marginBottom = '0'): string {
    return '
<style>
* { box-sizing: border-box; }
body { font-family: Arial, Helvetica, sans-serif; font-size: 9.5pt; color: #111; margin: 0; padding: 0; line-height: 1.5; }
.page { padding: 15mm 18mm; max-width: 210mm; margin: 0 auto; }
.header { text-align: center; margin-bottom: 20px; border-bottom: 3px solid #c9a84c; padding-bottom: 12px; }
.header img { height: 50px; margin-bottom: 8px; }
.header h1 { font-size: 13pt; color: #1a2332; margin: 4px 0; letter-spacing: 1px; text-transform: uppercase; }
.header .sous-titre { font-size: 10pt; color: #c9a84c; font-weight: bold; letter-spacing: 2px; margin: 3px 0; }
.header .infos { font-size: 7.5pt; color: #666; }
.entre { margin: 16px 0; padding: 12px; background: #f8f9fa; border-left: 3px solid #c9a84c; border-radius: 0 4px 4px 0; font-size: 9pt; }
.entre strong { color: #1a2332; }
.art { margin: 14px 0; }
.art-title { font-size: 10pt; font-weight: bold; color: #1a2332; background: #f0f2f5; padding: 5px 10px; border-left: 4px solid #c9a84c; margin-bottom: 6px; }
.art-body { padding: 0 10px; font-size: 9pt; }
.art-body ul { margin: 4px 0; padding-left: 18px; }
.art-body ul li { margin-bottom: 2px; }
.blank { display: inline-block; min-width: 80px; border-bottom: 1px solid #333; }
.signatures { margin-top: 25px; display: flex; justify-content: space-between; gap: 30px; }
.sig-block { flex: 1; text-align: center; }
.sig-block .sig-title { font-weight: bold; font-size: 9pt; margin-bottom: 5px; }
.sig-block .sig-line { border-top: 1px solid #333; margin-top: 50px; padding-top: 4px; font-size: 8pt; color: #666; }
.sig-block.has-sig .sig-line { margin-top: 8px; }
.sig-img-box { display:inline-block; min-width:130px; min-height:60px; border:1px solid #ddd; border-radius:4px; background:#fff; padding:4px; }
.sig-img-box img { height:56px; max-width:200px; display:block; margin:0 auto; object-fit:contain; mix-blend-mode:multiply; }
.footer { margin-top: 20px; border-top: 1px solid #ddd; padding-top: 6px; font-size: 7pt; color: #999; text-align: center; }
.highlight { background: rgba(201,168,76,0.1); padding: 1px 3px; border-radius: 3px; }
.legal-note { font-size: 7.5pt; color: #666; font-style: italic; margin: 2px 0; }
/* Annexes */
.annexe-page { page-break-before: always; padding: 15mm 18mm; max-width: 210mm; margin: 0 auto; }
.annexe-header { text-align: center; border-bottom: 2px solid #c9a84c; padding-bottom: 10px; margin-bottom: 18px; }
.annexe-header h2 { font-size: 11pt; color: #1a2332; text-transform: uppercase; letter-spacing: 1px; margin: 0 0 4px; }
.annexe-header .sous { font-size: 8.5pt; color: #666; }
.annexe-body { font-size: 9pt; line-height: 1.6; }
.annexe-body p { margin: 8px 0; }
.choix-groupe { margin: 8px 0 12px; }
.choix-item { display: block; margin: 5px 0; padding: 6px 10px 6px 10px; border: 1.2px solid #d1d5db; border-radius: 4px; font-size: 9pt; }
.annexe-sig { margin-top: 28px; }
.annexe-sig .lieu { font-size: 9pt; margin-bottom: 30px; }
.annexe-sig .sig-line { border-top: 1px solid #333; padding-top: 4px; font-size: 8pt; color: #666; width: 50%; }
</style>';
}

function buildContratHtml(array $d, array $p, array $a): string {
    $logoB64 = '';
    $logoFile = APP_ROOT . '/assets/img/' . ($p['logo_principal'] ?? 'logo.png');
    if (file_exists($logoFile)) {
        $ext     = strtolower(pathinfo($logoFile, PATHINFO_EXTENSION));
        $mime    = ($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : 'image/png';
        $logoB64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logoFile));
    }

    $sigPresB64 = '';
    $sigPresFile = APP_ROOT . '/uploads/photos/' . ($p['signature_president'] ?? 'signature-Traore.JPG');
    if (file_exists($sigPresFile)) {
        $ext2 = strtolower(pathinfo($sigPresFile, PATHINFO_EXTENSION));
        $mime2 = ($ext2 === 'jpg' || $ext2 === 'jpeg') ? 'image/jpeg' : 'image/png';
        $sigPresB64 = 'data:' . $mime2 . ';base64,' . base64_encode(file_get_contents($sigPresFile));
    }

    $e          = fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
    $typeCdd    = in_array($d['type_contrat'] ?? 'CDD', ['CDD','CDD Usage','Saisonnier']);
    $isCddUsage = ($d['type_contrat'] ?? 'CDD') === 'CDD Usage';
    $totalH     = trim($d['total_heures_contrat'] ?? '');
    $heuresMois = ($d['heures_unite'] ?? 'periode') === 'mois';
    $calcEssai  = calculerPeriodeEssai($d['date_debut'] ?? '', $d['date_fin'] ?? '');

    ob_start(); ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<?= _contratCss() ?>
</head>
<body>
<div class="page">

<!-- En-tête -->
<div class="header">
  <?php if ($logoB64): ?><img src="<?= $logoB64 ?>"><br><?php endif; ?>
  <h1>Contrat de Travail à Durée <?= $typeCdd ? 'Déterminée' : 'Indéterminée' ?><?= $isCddUsage ? ' d\'Usage' : '' ?></h1>
  <div class="sous-titre"><?= $e($d['poste']) ?></div>
  <div class="infos">
    <?= $e($p['entreprise_nom'] ?? 'OEIL VIGILANT') ?> — SIRET <?= $e($p['entreprise_siret'] ?? '92855270200013') ?><br>
    <?= $e($p['entreprise_adresse'] ?? '58 rue de Monceau') ?>, <?= $e($p['entreprise_cp'] ?? '75008') ?> <?= $e($p['entreprise_ville'] ?? 'Paris') ?>
  </div>
</div>

<!-- Parties -->
<div class="entre">
  <strong>ENTRE :</strong><br>
  La société <strong><?= $e($p['entreprise_nom'] ?? 'OEIL VIGILANT') ?> (SAS)</strong>, dont le siège social est situé au
  <?= $e($p['entreprise_adresse'] ?? '58 rue de Monceau') ?> <?= $e($p['entreprise_cp'] ?? '75008') ?> <?= $e($p['entreprise_ville'] ?? 'Paris') ?>,
  immatriculée au RCS de Paris sous le numéro <?= $e($p['entreprise_siret'] ?? '928 552 702') ?>,<br>
  Autorisation d'exercer : AUT-075-2123-06-21-20240934026,<br>
  représentée par <strong>M. <?= $e($p['entreprise_dirigeant'] ?? 'TRAORE Ibrahim') ?></strong>, en qualité de président,<br>
  Ci-après dénommée <em>« l'Employeur »</em>,<br><br>

  <strong>ET :</strong><br>
  <strong><?= $e($d['civilite']) ?> <?= $e($d['nom_prenom']) ?></strong>,
  demeurant à : <?= $e($d['adresse']) ?>,<br>
  Né(e) le : <strong><?= $e($d['date_naissance']) ?></strong> à <strong><?= $e($d['lieu_naissance']) ?></strong>,<br>
  de nationalité : <strong><?= $e($d['nationalite']) ?></strong>,<br>
  Numéro de sécurité sociale : <strong><?= $e($d['num_secu']) ?></strong>,<br>
  Titulaire d'une carte professionnelle n° : <strong><?= $e($d['num_cnaps']) ?></strong>,<br>
  Ci-après dénommé <em>« le Salarié »</em>.
</div>

<p style="font-size:8.5pt;color:#555;margin:8px 0">
Conformément aux dispositions du Règlement Général sur la Protection des Données (RGPD) et de la loi n°78-17 du 6 janvier 1978 modifiée, le salarié signataire dispose d'un droit d'accès, de rectification et d'effacement des données le concernant.
</p>
<p style="text-align:center;font-weight:bold;font-size:9.5pt;margin:12px 0">Il a été convenu ce qui suit :</p>

<!-- Article 1 -->
<div class="art">
  <div class="art-title">ARTICLE N° 01 — Engagement</div>
  <div class="art-body">
    Le salarié signataire est engagé sous contrat à durée <?= $typeCdd?'déterminée':'indéterminée' ?> en qualité
    de <strong><?= $e($d['poste']) ?></strong> qui relève de la catégorie « <?= $e($d['categorie']) ?> ».<br>
    La déclaration préalable à l'embauche (DPAE) a été transmise à l'URSSAF d'Île-de-France avant la prise de poste effective, conformément à l'article L1221-10 du Code du travail.<br>
    À la date de sa signature, le présent contrat est régi par les dispositions de la Convention Collective Nationale <em>« Des entreprises de prévention et de sécurité du 15 février 1985 »</em> (IDCC n°1351), ainsi que par l'ensemble des dispositions législatives et réglementaires en vigueur.
  </div>
</div>

<!-- Article 2 — Durée et terme -->
<div class="art">
  <div class="art-title">ARTICLE N° 02 — Objet du contrat — Durée et terme</div>
  <div class="art-body">
    <?php if ($typeCdd): ?>
    Le présent contrat est conclu pour une durée déterminée du <strong><?= $e($d['date_debut']) ?></strong>
    au <strong><?= $e($d['date_fin']) ?></strong>
    <?php if ($totalH && $heuresMois): ?>
    pour un volume horaire de <strong class="highlight"><?= $e($totalH) ?> heures par mois</strong>, réparties selon le planning.
    <?php elseif ($totalH): ?>
    pour une durée de travail globale de <strong class="highlight"><?= $e($totalH) ?> heures</strong> pour l'ensemble de la période, réparties selon le planning.
    <?php else: ?>
    .
    <?php endif; ?>
    <br><br>
    <?php if ($isCddUsage): ?>
    Il est conclu sur le fondement de l'article L1242-2-3° du Code du travail, le secteur de la sécurité privée étant expressément reconnu comme secteur d'emploi à caractère par nature temporaire par le décret n°2014-714 du 27 juin 2014. Le présent contrat n'est pas soumis à la durée maximale légale applicable aux CDD standard et peut être renouvelé sans limitation du nombre de renouvellements.<br>
    <?php if (!empty($d['nom_evenement'])): ?>
    Ce contrat est spécifiquement lié à la couverture de l'événement suivant : <strong><?= $e($d['nom_evenement']) ?></strong>.
    <?php endif; ?>
    <?php else: ?>
    Ce contrat est conclu pour faire face à un <strong><?= $e($d['motif_cdd']) ?></strong>, <?= $e($d['description_motif']) ?><br>
    Conformément à l'article L1243-13 du Code du travail, la durée totale du présent contrat, renouvellements éventuels inclus, ne peut excéder <strong>18 mois</strong>.
    <?php endif; ?>
    <br><br>
    Il ne deviendra définitif qu'à l'issue d'une période d'essai de <strong><?= $e($calcEssai) ?></strong>, durant laquelle chacune des parties pourra y mettre fin sans préavis ni indemnité (Art L1243-11 CT).<br>
    <?php if (($d['non_renouvelable'] ?? '1') === '1' && !$isCddUsage): ?>
    Le présent contrat n'est pas renouvelable.
    <?php elseif (!$isCddUsage): ?>
    Le présent contrat pourra être renouvelé une fois, dans la limite de la durée maximale légale, par accord écrit signé avant le terme initial.
    <?php endif; ?>
    <?php else: ?>
    Le présent contrat est conclu à durée indéterminée à compter du <strong><?= $e($d['date_debut']) ?></strong>.<br>
    Il ne deviendra définitif qu'à l'issue d'une période d'essai de <strong><?= $e($calcEssai) ?></strong>, renouvelable une fois avec l'accord du salarié.
    <?php endif; ?>
  </div>
</div>

<!-- Article 3 -->
<div class="art">
  <div class="art-title">ARTICLE N° 03 — Fonctions</div>
  <div class="art-body">
    Le salarié aura pour mission l'accomplissement de l'ensemble des tâches afférentes à un poste de <strong><?= $e($d['poste']) ?></strong>, notamment :
    <ul>
      <li>Surveiller et contrôler l'accès des sites pour garantir la sécurité des biens et des personnes</li>
      <li>Intervenir lors des situations d'urgence et gérer les incidents de sécurité</li>
      <li>Réaliser des rondes de prévention et de détection des anomalies</li>
      <li>Informer et alerter les services compétents (pompiers, forces de l'ordre) en cas de menace sérieuse</li>
      <li>Assurer le respect des règles de sécurité au sein des établissements</li>
      <li>Gérer l'utilisation des équipements de surveillance et d'alarme</li>
    </ul>
    Le salarié sera affecté sur le site : <strong><?= $e($d['site_affectation']) ?></strong>, mais reste rattaché à l'établissement situé <?= $e($p['entreprise_adresse']??'58 RUE DE MONCEAU') ?> à <?= $e($p['entreprise_ville']??'PARIS') ?>.<br><br>
    <strong>Usage du téléphone portable :</strong> Sauf circonstances exceptionnelles d'urgence, l'usage du téléphone personnel pendant les heures de travail est interdit au regard des impératifs de sécurité des missions.<br><br>
    <strong>Stupéfiants et alcool :</strong> Il est formellement interdit d'introduire ou de consommer toute boisson alcoolisée ou tout stupéfiant dans le cadre des fonctions. Tout manquement pourra entraîner une sanction disciplinaire pouvant aller jusqu'au licenciement pour faute grave.
  </div>
</div>

<!-- Article 4 — Horaires -->
<div class="art">
  <div class="art-title">ARTICLE N° 04 — Horaires de travail</div>
  <div class="art-body">
    <?php if ($totalH && $heuresMois): ?>
    La durée de travail est fixée à <strong class="highlight"><?= $e($totalH) ?> heures par mois</strong>, réparties selon le planning.
    <?php elseif ($totalH): ?>
    La durée globale de travail est fixée à <strong class="highlight"><?= $e($totalH) ?> heures</strong> pour la durée du contrat.
    <?php endif; ?>
    <?php if (!empty($d['horaires_vacation'])): ?>
    Les horaires de travail pour cette vacation unique sont fixés de <strong class="highlight"><?= $e($d['horaires_vacation']) ?></strong>. Le salarié s'engage à respecter scrupuleusement les horaires convenus.<br><br>
    <?php else: ?>
    Les horaires de travail seront définis selon le planning communiqué au salarié. Le salarié s'engage à respecter scrupuleusement les vacations prévues.<br><br>
    <?php endif; ?>
    En fonction des nécessités du service, le salarié pourra être amené à effectuer des heures complémentaires. Le volume total de ces heures complémentaires ne pourra en aucun cas excéder le <strong>tiers (1/3)</strong> de la durée <?= $heuresMois ? 'mensuelle' : 'globale' ?> fixée au présent contrat (Art L3123-28 CT).<br><br>
    L'Employeur s'engage à respecter un délai de prévenance de <strong>7 jours</strong> pour toute modification du planning. En cas de circonstances exceptionnelles (remplacement d'un salarié défaillant, urgence client), ce délai pourra être réduit à moins de 3 jours ouvrés, en contrepartie d'un <strong>repos compensateur équivalent à 10%</strong> des heures effectuées sur la vacation modifiée.<br><br>
    L'amplitude horaire sur laquelle le salarié est susceptible de travailler est comprise entre 00h00 et 23h59.<br><br>

    <strong>Clause de non-disposition permanente — Protection contre la requalification à temps plein</strong><br>
    Conformément aux articles <strong>L3121-1</strong> et <strong>L3123-14</strong> du Code du travail, les heures de travail du salarié sont <em>exclusivement</em> celles figurant sur le planning qui lui est communiqué dans les délais prévus ci-dessus. En dehors de ces vacations planifiées, le salarié <strong>n'est en aucun cas tenu de se tenir à la disposition permanente ou partielle de l'Employeur</strong> ; il peut vaquer librement à ses occupations personnelles et organiser son temps comme il l'entend, y compris exercer toute autre activité compatible avec ses obligations légales et conventionnelles.<br><br>

    Il est expressément rappelé que :<br>
    <ul>
      <li>Aucun appel, message ou sollicitation de l'Employeur en dehors des vacations planifiées ne crée d'obligation de disponibilité ou de réponse immédiate, sauf situation d'urgence dûment caractérisée ;</li>
      <li>La variation du nombre d'heures entre plusieurs semaines est inhérente aux contrats à temps partiel dans le secteur de la sécurité privée et ne saurait, à elle seule, caractériser un emploi à temps complet ;</li>
      <li>L'absence de planning préétabli pour une période donnée signifie que le salarié ne travaille pas et n'est soumis à aucune contrainte professionnelle durant cette période ;</li>
      <li>Les parties reconnaissent expressément que le présent contrat ne constitue pas, ni de fait ni de droit, un emploi à temps complet, et que le salarié ne se trouve pas dans l'impossibilité de prévoir à quel rythme il doit travailler.</li>
    </ul>

    Toute demande de disponibilité en dehors des vacations planifiées ne peut intervenir qu'avec l'accord <strong>exprès et préalable</strong> du salarié. L'absence de réponse ou le refus d'une telle demande ne saurait constituer une faute ni un motif de sanction disciplinaire (Cass. Soc. 4 déc. 2013, n°12-22.344 ; Cass. Soc. 25 nov. 2020, n°18-24.272).
  </div>
</div>

<!-- Article 5 — Rémunération -->
<div class="art">
  <div class="art-title">ARTICLE N° 05 — Rémunération</div>
  <div class="art-body">
    Le salarié signataire percevra un salaire <?= $e(($d['type_remuneration']??'Brute')==='Nette' ? 'net' : 'brut') ?> horaire de
    <strong class="highlight"><?= $e($d['salaire_horaire']) ?> €</strong> par heure effective de travail, au moins égal au minimum conventionnel applicable au coefficient <?= $e(preg_match('/[Cc]oefficient\s*(\d+)/', $d['categorie']??'', $m) ? $m[1] : (preg_match('/(\d{3,})/', $d['categorie']??'', $m2) ? $m2[1] : '140')) ?> de la CCN n°1351.<br>
    Majorations applicables : heures de nuit <strong><?= $e($d['majoration_nuit']) ?>%</strong>, dimanche <strong><?= $e($d['majoration_dim']) ?>%</strong>, jours fériés <strong><?= $e($d['majoration_ferie']) ?>%</strong>.<br>
    <?php if ($typeCdd && !$isCddUsage): ?>
    Conformément à l'article L1243-8 du Code du travail, une <strong>prime de précarité de 10%</strong> de la rémunération brute totale sera versée en fin de contrat. Cette indemnité ne sera pas due en cas de requalification en CDI, de faute grave ou de force majeure (Art L1243-10 CT).
    <?php elseif ($isCddUsage): ?>
    <span class="legal-note">Le présent contrat relevant du régime du CDD d'Usage, il ne donne pas droit à l'indemnité de fin de contrat (Art L1243-10-3° CT).</span>
    <?php endif; ?>
  </div>
</div>

<div class="art">
  <div class="art-title">ARTICLE N° 06 — Confidentialité</div>
  <div class="art-body">
    Le salarié s'engage à observer la discrétion la plus stricte sur les informations se rapportant aux activités de la S.A.S <?= $e($p['entreprise_nom']??'OEIL VIGILANT') ?>, à ses clients et à leurs installations, auxquelles il aura accès dans le cadre de ses fonctions. Cette obligation de confidentialité s'applique pendant toute la durée du contrat et se prolonge pendant une durée de 2 ans après sa rupture, quelle qu'en soit la cause.
  </div>
</div>

<div class="art">
  <div class="art-title">ARTICLE N° 07 — Port de l'uniforme et carte professionnelle</div>
  <div class="art-body">
    Dans l'exercice de ses fonctions, le salarié devra : être en possession de sa carte professionnelle en cours de validité (Art L612-16 CSI), porter obligatoirement la tenue vestimentaire réglementaire pendant toute la durée du service, et restituer l'ensemble des équipements et la carte professionnelle à l'Employeur au terme du contrat, sous peine de retenue sur salaire dans les limites légales.
  </div>
</div>

<div class="art">
  <div class="art-title">ARTICLE N° 08 — Absences et arrêts de travail</div>
  <div class="art-body">
    En cas d'absence, le salarié est tenu de prévenir l'Employeur <strong>dès que possible et au plus tard dans l'heure</strong> suivant l'horaire de prise de poste prévu. Un justificatif devra être transmis dans un délai de <strong>48 heures</strong>. En cas d'arrêt maladie, le volet destiné à l'employeur du certificat médical devra être transmis dans les 48 heures.<br>
    Tout abandon de poste non justifié dans le délai imparti pourra faire l'objet d'une procédure disciplinaire.
  </div>
</div>

<div class="art">
  <div class="art-title">ARTICLE N° 09 — Traitement des données personnelles (RGPD)</div>
  <div class="art-body">
    Les données personnelles collectées sont traitées sur le fondement de l'exécution du contrat de travail (Art 6-1-b RGPD) et des obligations légales de l'Employeur (Art 6-1-c RGPD). Elles sont conservées pendant toute la durée du contrat et 5 ans après sa fin. Le salarié peut exercer ses droits (accès, rectification, portabilité, opposition, effacement, limitation) en contactant l'Employeur par écrit.
  </div>
</div>

<div class="art">
  <div class="art-title">ARTICLE N° 10 — Droit à la déconnexion</div>
  <div class="art-body">
    Conformément à l'article L2242-17 du Code du travail (Loi n°2016-1088 du 8 août 2016), le salarié bénéficie d'un <strong>droit à la déconnexion</strong> en dehors de ses heures de travail planifiées, les week-ends, jours fériés et pendant ses congés.<br>
    Sauf situation d'urgence avérée et dûment justifiée par la nature des missions de sécurité, le salarié n'est pas tenu de répondre aux sollicitations professionnelles (appels, SMS, e-mails) en dehors de ses vacations. L'Employeur s'engage à ne pas exercer de pression pour que le salarié se connecte en dehors des plages planifiées.
  </div>
</div>

<div class="art">
  <div class="art-title">ARTICLE N° 11 — Dispositions diverses</div>
  <div class="art-body">
    Le salarié s'engage à aviser l'Employeur de tout changement dans sa situation personnelle susceptible d'avoir une incidence sur l'exécution du contrat (changement d'adresse, d'état civil, de situation vis-à-vis de la sécurité sociale, renouvellement de la carte professionnelle CNAPS). Il sera inscrit au registre unique du personnel dès le premier jour de travail effectif.
  </div>
</div>

<div class="art">
  <div class="art-title">ARTICLE N° 12 — Formation professionnelle</div>
  <div class="art-body">
    Le salarié bénéficie des droits à la formation professionnelle attachés à son contrat de travail, notamment au titre du <strong>Compte Personnel de Formation (CPF)</strong> (Art L6323-1 CT). L'Employeur s'engage à assurer l'adaptation du salarié à son poste de travail et à veiller au maintien de sa capacité à occuper l'emploi.<br>
    Les formations obligatoires liées à l'exercice de l'activité de sécurité privée (recyclage CNAPS, SST, etc.) sont à la charge de l'Employeur et se déroulent pendant le temps de travail.
  </div>
</div>

<div class="art">
  <div class="art-title">ARTICLE N° 13 — Déclaration sur l'honneur</div>
  <div class="art-body">
    Conformément aux articles L612-20 et L612-22 du Code de la Sécurité Intérieure (CSI), le salarié signataire déclare sur l'honneur :<br>
    — ne pas avoir fait l'objet d'une condamnation pénale, d'une incapacité ou déchéance mentionnées à l'article L612-20 du CSI ;<br>
    — ne faire l'objet d'aucune poursuite pénale en cours pouvant entraîner une telle incapacité ;<br>
    — être titulaire d'une carte professionnelle CNAPS en cours de validité.<br>
    Toute fausse déclaration entraînera la nullité du contrat et pourra faire l'objet de poursuites pénales.
  </div>
</div>

<div class="art">
  <div class="art-title">ARTICLE N° 14 — Acceptation des lettres recommandées électroniques</div>
  <div class="art-body">
    Le Salarié accepte expressément l'envoi par voie électronique des courriers recommandés de l'Entreprise relatifs à son Contrat, conformément aux dispositions de l'article L100 du Code des postes et des communications électroniques.
  </div>
</div>

<div class="art">
  <div class="art-title">ARTICLE N° 15 — Droit à l'image</div>
  <div class="art-body">
    Le salarié autorise la Société à utiliser son image, captée dans un cadre strictement professionnel (rapports d'activité, supports de communication internes, site internet de l'entreprise). Cette autorisation ne vaut pas pour une utilisation commerciale de l'image du salarié. Elle peut être révoquée à tout moment par écrit.
  </div>
</div>

<?php if ($typeCdd): ?>
<div class="art">
  <div class="art-title">ARTICLE N° 16 — Fin du contrat</div>
  <div class="art-body">
    Le contrat prendra fin automatiquement au terme fixé, à la dernière vacation planifiée, sauf rupture anticipée pour faute grave, force majeure ou accord écrit des parties (Art L1243-1 CT).<br>
    <?php if (!$isCddUsage): ?>
    Le présent contrat donne lieu au versement d'une <strong>indemnité de fin de contrat égale à 10% de la rémunération brute totale</strong>, versée à l'échéance par l'Employeur, sauf cas d'exclusion légaux (faute grave, force majeure, proposition de CDI refusée — Art L1243-10 CT).
    <?php else: ?>
    Le présent contrat relevant du régime du CDD d'Usage, aucune indemnité de précarité n'est due à son terme (Art L1243-10-3° CT).
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="art">
  <div class="art-title">ARTICLE N° 17 — Mutuelle et prévoyance</div>
  <div class="art-body">
    Conformément à l'accord de branche de la CCN n°1351 et à la loi n°2013-504 du 14 juin 2013, le salarié est affilié au régime de complémentaire santé collectif et obligatoire mis en place par l'Entreprise, sauf justification d'un cas de dispense légal (Art R2421-2 CT). La cotisation est partagée entre l'Employeur et le Salarié selon les modalités en vigueur dans l'Entreprise.
  </div>
</div>

<!-- Article 18 — Documents annexes -->
<div class="art">
  <div class="art-title">ARTICLE N° 18 — Documents annexes obligatoires</div>
  <div class="art-body">
    À la date de signature du présent contrat, le salarié signe les documents annexés à la suite de ce contrat, sans lesquels celui-ci ne peut produire ses effets :
    <ul>
      <li>Une demande expresse de dérogation à la durée minimale légale de travail de 24 heures par semaine (Art L3123-7 CT).</li>
      <li>Une déclaration sur l'honneur relative au cumul d'emplois et au respect des durées maximales de travail.</li>
      <li>Sa demande de dispense d'adhésion à la mutuelle d'entreprise, le cas échéant.</li>
    </ul>
  </div>
</div>

<!-- Badge -->
<div style="margin:14px 0;padding:10px;border:1px solid #e5e7eb;border-radius:6px;font-size:8.5pt">
  <strong>Attestation de remise des équipements :</strong> Je soussigné(e) <?= $e($d['nom_prenom']) ?> atteste avoir reçu l'ensemble des équipements nécessaires à l'exercice de mes fonctions (badge, uniforme, équipements de protection). Je m'engage à les restituer à l'Employeur en fin de mission, en bon état d'usage, sous peine de poursuites disciplinaires et de retenue sur salaire dans les limites légales.
</div>

<!-- Signatures -->
<div style="margin:16px 0;font-size:8.5pt">
  Fait à <strong><?= $e($d['lieu_signature']) ?></strong>, le <strong><?= $e($d['date_signature']) ?></strong>
  &nbsp;&nbsp;(En deux exemplaires originaux dont un a été remis au salarié signataire)<br>
  <em>Signature précédée de la mention manuscrite « Lu et Approuvé - Bon pour accord »</em>
</div>

<div class="signatures">
  <div class="sig-block <?= $sigPresB64 ? 'has-sig' : '' ?>">
    <div class="sig-title">L'Employeur</div>
    <?php if ($sigPresB64): ?>
    <div class="sig-img-box"><img src="<?= $sigPresB64 ?>"></div>
    <?php else: ?>
    <div style="height:60px"></div>
    <?php endif; ?>
    <div class="sig-line">
      M. <?= $e($p['entreprise_dirigeant'] ?? 'TRAORE Ibrahim') ?><br>
      Président — S.A.S <?= $e($p['entreprise_nom'] ?? 'OEIL VIGILANT') ?>
    </div>
  </div>
  <div class="sig-block <?= !empty($a['signature']) ? 'has-sig' : '' ?>">
    <div class="sig-title">Le Salarié</div>
    <?php if (!empty($a['signature'])): ?>
    <div class="sig-img-box"><img src="<?= $a['signature'] ?>"></div>
    <?php else: ?>
    <div style="height:60px"></div>
    <?php endif; ?>
    <div class="sig-line">
      <?= $e($d['civilite']) ?> <?= $e($d['nom_prenom']) ?>
      <?php if (!empty($a['signature_date'])): ?>
      <br><span style="font-size:7pt;color:#888;font-style:italic">Signé électroniquement le <?= date('d/m/Y à H:i', strtotime($a['signature_date'])) ?></span>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="footer">
  Fait à <?= $e($d['lieu_signature']) ?>, le <?= $e($d['date_signature'] ?: date('d/m/Y')) ?> — <?= $e($p['entreprise_nom'] ?? 'OEIL VIGILANT') ?> — Confidentiel
</div>

</div><!-- /page -->

<?php if (($d['inclure_annexe_24h'] ?? '1') === '1'): ?>
<!-- ANNEXE 1 -->
<div class="annexe-page">
  <div class="annexe-header">
    <div class="sous"><?= $e($p['entreprise_nom'] ?? 'OEIL VIGILANT') ?> &nbsp;·&nbsp; SIREN 928 552 702</div>
    <h2>Annexe 1 — Demande de dérogation à la durée minimale de travail</h2>
    <div class="sous">Annexe obligatoire au contrat de travail signé le <?= $e($d['date_signature']) ?></div>
  </div>
  <div class="annexe-body">
    <p>Je soussigné(e) <strong><?= $e($d['nom_prenom']) ?></strong>, demeurant au : <strong><?= $e($d['adresse']) ?></strong>,</p>
    <p>demande expressément et en mon nom propre à la société <strong>OEIL VIGILANT</strong> de déroger à la durée minimale légale de travail de <strong>24 heures par semaine</strong>, conformément à l'article L3123-7 du Code du travail. Je reconnais que cette demande émane exclusivement de ma propre initiative et n'a pas été sollicitée par l'Employeur.</p>
    <p><strong>Cette demande est justifiée par la raison suivante :</strong></p>
    <div style="margin:6px 0;padding:7px 12px;background:#f8f9fa;border-left:3px solid #c9a84c;border-radius:0 4px 4px 0;font-size:9pt">
      Me permettre de faire face à des contraintes personnelles ou de cumuler plusieurs activités afin d'atteindre une durée globale de travail correspondant à un temps plein ou au moins égale à 24 heures par semaine.
    </div>
    <p>J'ai bien noté que mes horaires de travail seront regroupés sur des journées ou des demi-journées régulières ou complètes. Je reconnais avoir été informé(e) de mon droit à revenir sur cette dérogation à tout moment, avec un préavis raisonnable.</p>
    <div class="annexe-sig">
      <div class="lieu">Fait à <?= $e($d['lieu_signature'] ?? 'Paris') ?>, le <?= $e($d['date_signature']) ?></div>
      <?php if (!empty($a['signature'])): ?>
      <div style="margin:4px 0"><img src="<?= $a['signature'] ?>" style="height:44px;max-width:160px;display:block;object-fit:contain;mix-blend-mode:multiply"></div>
      <?php else: ?>
      <div style="height:44px"></div>
      <?php endif; ?>
      <div class="sig-line"><?= $e($d['civilite']) ?> <?= $e($d['nom_prenom']) ?><br><span style="font-style:italic;font-size:7.5pt">Lu et approuvé — Signature</span></div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ANNEXE 2 -->
<div class="annexe-page">
  <div class="annexe-header">
    <div class="sous"><?= $e($p['entreprise_nom'] ?? 'OEIL VIGILANT') ?> &nbsp;·&nbsp; SIREN 928 552 702</div>
    <h2>Annexe 2 — Déclaration sur l'honneur de cumul d'emplois</h2>
    <div class="sous">Annexe obligatoire au contrat de travail signé le <?= $e($d['date_signature']) ?></div>
  </div>
  <div class="annexe-body">
    <p>Je soussigné(e) <strong><?= $e($d['nom_prenom']) ?></strong>, déclare sur l'honneur être informé(e) que la réglementation m'interdit de dépasser les durées maximales de travail autorisées, tous employeurs confondus :</p>
    <ul>
      <li>10 heures par jour (Art L3121-18 CT)</li>
      <li>48 heures par semaine (Art L3121-20 CT)</li>
      <li>44 heures en moyenne sur 12 semaines consécutives (Art L3121-22 CT)</li>
    </ul>
    <p><strong>À ce jour, je déclare me trouver dans l'un des cas suivants :</strong></p>
    <div class="choix-groupe">
      <div class="choix-item">N'exercer aucune autre activité professionnelle rémunérée.</div>
      <div class="choix-item">Exercer une ou plusieurs autres activités professionnelles rémunérées. Je m'engage à ce que le cumul de mes heures chez OEIL VIGILANT et chez tout autre employeur ne dépasse jamais les limites légales susmentionnées, et à informer immédiatement l'Employeur de tout changement de situation.</div>
    </div>
    <p style="font-size:8pt;color:#666;font-style:italic">Je reconnais être informé(e) que toute fausse déclaration m'expose à des sanctions disciplinaires et peut engager ma responsabilité personnelle en cas d'accident du travail imputable à un dépassement des durées maximales.</p>
    <div class="annexe-sig">
      <div class="lieu">Fait à <?= $e($d['lieu_signature'] ?? 'Paris') ?>, le <?= $e($d['date_signature']) ?></div>
      <?php if (!empty($a['signature'])): ?>
      <div style="margin:4px 0"><img src="<?= $a['signature'] ?>" style="height:44px;max-width:160px;display:block;object-fit:contain;mix-blend-mode:multiply"></div>
      <?php else: ?>
      <div style="height:44px"></div>
      <?php endif; ?>
      <div class="sig-line"><?= $e($d['civilite']) ?> <?= $e($d['nom_prenom']) ?><br><span style="font-style:italic;font-size:7.5pt">Lu et approuvé — Signature</span></div>
    </div>
  </div>
</div>

<!-- ANNEXE 3 -->
<div class="annexe-page">
  <div class="annexe-header">
    <div class="sous"><?= $e($p['entreprise_nom'] ?? 'OEIL VIGILANT') ?> &nbsp;·&nbsp; SIREN 928 552 702</div>
    <?php if (($d['mutuelle_choix'] ?? 'dispense') === 'adhesion'): ?>
    <h2>Annexe 3 — Adhésion à la mutuelle d'entreprise</h2>
    <?php else: ?>
    <h2>Annexe 3 — Demande de dispense d'affiliation à la mutuelle</h2>
    <?php endif; ?>
    <div class="sous">Annexe obligatoire au contrat de travail signé le <?= $e($d['date_signature']) ?></div>
  </div>
  <div class="annexe-body">
    <?php if (($d['mutuelle_choix'] ?? 'dispense') === 'adhesion'): ?>
    <p>Je soussigné(e) <strong><?= $e($d['nom_prenom']) ?></strong>, demeurant au : <strong><?= $e($d['adresse']) ?></strong>,</p>
    <p>reconnais ne pas bénéficier d'une couverture complémentaire santé et <strong>demande mon affiliation au régime collectif obligatoire « Frais de Santé »</strong> mis en place par <strong><?= $e($p['entreprise_nom'] ?? 'OEIL VIGILANT') ?></strong>, à compter de ma date d'embauche, conformément à l'accord de branche de la CCN n°1351 et à la loi n°2013-504 du 14 juin 2013.</p>
    <p>Je reconnais avoir été informé(e) des garanties couvertes, du montant de la cotisation salariale et de la prise en charge par l'Employeur selon les modalités en vigueur dans l'Entreprise. Je m'engage à signaler sans délai tout changement de situation susceptible de modifier mon droit à adhésion ou à dispense (acquisition d'une autre couverture, changement de situation familiale, etc.).</p>
    <?php else: ?>
    <p>Je soussigné(e) <strong><?= $e($d['nom_prenom']) ?></strong>, demande à être dispensé(e) d'affiliation au régime de garantie « Frais de Santé » collectif et obligatoire mis en place par <strong><?= $e($p['entreprise_nom'] ?? 'OEIL VIGILANT') ?></strong>, pour l'un des motifs suivants (Art R2421-2 CT) :</p>
    <div class="choix-groupe">
      <div class="choix-item">Je suis titulaire d'un CDD ou contrat de mission de moins de 3 mois, et justifie d'une couverture responsable individuelle (CSS, AMC individuelle).</div>
      <div class="choix-item">Je bénéficie déjà d'une couverture collective et obligatoire en tant qu'ayant droit ou en tant que salarié d'un autre employeur.</div>
      <div class="choix-item">Je bénéficie de la Complémentaire Santé Solidaire (CSS) ou de l'Aide médicale d'État (AME).</div>
    </div>
    <p>Je m'engage à fournir les justificatifs correspondants à l'Employeur et reconnais qu'en cas de dispense, je ne pourrai pas prétendre à la prise en charge employeur des frais de santé. Cette dispense prend fin automatiquement en cas de perte du motif qui la justifie.</p>
    <?php endif; ?>
    <div class="annexe-sig">
      <div class="lieu">Fait à <?= $e($d['lieu_signature'] ?? 'Paris') ?>, le <?= $e($d['date_signature']) ?></div>
      <?php if (!empty($a['signature'])): ?>
      <div style="margin:4px 0"><img src="<?= $a['signature'] ?>" style="height:44px;max-width:160px;display:block;object-fit:contain;mix-blend-mode:multiply"></div>
      <?php else: ?>
      <div style="height:44px"></div>
      <?php endif; ?>
      <div class="sig-line"><?= $e($d['civilite']) ?> <?= $e($d['nom_prenom']) ?><br><span style="font-style:italic;font-size:7.5pt">Lu et approuvé — Signature</span></div>
    </div>
  </div>
</div>

</body>
</html>
<?php
    return ob_get_clean();
}

// ═══════════════════════════════════════════════════════════════════════════════
// AVENANT
// ═══════════════════════════════════════════════════════════════════════════════

function buildAvenantHtml(array $d, array $p, array $a): string {
    $logoB64 = '';
    $logoFile = APP_ROOT . '/assets/img/' . ($p['logo_principal'] ?? 'logo.png');
    if (file_exists($logoFile)) {
        $ext     = strtolower(pathinfo($logoFile, PATHINFO_EXTENSION));
        $mime    = ($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : 'image/png';
        $logoB64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logoFile));
    }

    $sigPresB64 = '';
    $sigPresFile = APP_ROOT . '/uploads/photos/' . ($p['signature_president'] ?? 'signature-Traore.JPG');
    if (file_exists($sigPresFile)) {
        $ext2 = strtolower(pathinfo($sigPresFile, PATHINFO_EXTENSION));
        $mime2 = ($ext2 === 'jpg' || $ext2 === 'jpeg') ? 'image/jpeg' : 'image/png';
        $sigPresB64 = 'data:' . $mime2 . ';base64,' . base64_encode(file_get_contents($sigPresFile));
    }

    $e       = fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
    $types   = $d['types_modification'] ?? [];
    $numAv   = trim($d['avenant_numero'] ?? '1');
    $dateRef = trim($d['date_contrat_reference'] ?? '');

    ob_start(); ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<?= _contratCss() ?>
<style>
.avenant-ref { background:#f0f2f5; border-left:4px solid #c9a84c; padding:8px 12px; font-size:8.5pt; margin:12px 0; border-radius:0 4px 4px 0; }
.mod-block { margin:12px 0; padding:10px; border:1px solid #e5e7eb; border-radius:6px; }
.mod-block-title { font-weight:700; font-size:9pt; color:#1a2332; margin-bottom:6px; border-bottom:1px solid #f0f2f5; padding-bottom:4px; }
.inchange { margin-top:14px; padding:8px 12px; background:#f8f9fa; font-size:8.5pt; font-style:italic; color:#555; border-radius:4px; text-align:center; }
</style>
</head>
<body>
<div class="page">

<div class="header">
  <?php if ($logoB64): ?><img src="<?= $logoB64 ?>"><br><?php endif; ?>
  <h1>Avenant n°<?= $e($numAv) ?> au Contrat de Travail</h1>
  <div class="sous-titre"><?= $e($d['poste'] ?? ($a['poste'] ?? 'Agent de sécurité')) ?></div>
  <div class="infos">
    <?= $e($p['entreprise_nom'] ?? 'OEIL VIGILANT') ?> — SIRET <?= $e($p['entreprise_siret'] ?? '92855270200013') ?><br>
    <?= $e($p['entreprise_adresse'] ?? '58 rue de Monceau') ?>, <?= $e($p['entreprise_cp'] ?? '75008') ?> <?= $e($p['entreprise_ville'] ?? 'Paris') ?>
  </div>
</div>

<div class="entre">
  <strong>ENTRE :</strong><br>
  La société <strong><?= $e($p['entreprise_nom'] ?? 'OEIL VIGILANT') ?> (SAS)</strong>,
  représentée par <strong>M. <?= $e($p['entreprise_dirigeant'] ?? 'TRAORE Ibrahim') ?></strong>, Président,<br>
  Ci-après dénommée <em>« l'Employeur »</em>,<br><br>
  <strong>ET :</strong><br>
  <strong><?= $e($d['civilite'] ?? '') ?> <?= $e($d['nom_prenom']) ?></strong>, demeurant à : <?= $e($d['adresse'] ?? '') ?>,<br>
  Ci-après dénommé <em>« le Salarié »</em>.
</div>

<div class="avenant-ref">
  Le présent avenant modifie et complète le contrat de travail à durée <?= (($d['type_contrat']??'CDD')==='CDI')?'indéterminée':'déterminée' ?>
  conclu entre les parties<?= $dateRef ? ' en date du <strong>'.$e($dateRef).'</strong>' : '' ?>.
  Il prend effet à compter du <strong><?= $e($d['date_effet'] ?? $d['date_signature']) ?></strong>.
  Toutes les dispositions du contrat initial non modifiées par le présent avenant demeurent pleinement applicables.
</div>

<p style="text-align:center;font-weight:bold;font-size:9.5pt;margin:12px 0">Les parties conviennent des modifications suivantes :</p>

<?php if (in_array('site', $types) && !empty($d['nouveau_site'])): ?>
<div class="mod-block">
  <div class="mod-block-title">Modification du site d'affectation</div>
  <p style="font-size:9pt">À compter du <strong><?= $e($d['date_effet'] ?? $d['date_signature']) ?></strong>, le salarié est affecté sur le site : <strong><?= $e($d['nouveau_site']) ?></strong>.</p>
  <?php if (!empty($d['ancien_site'])): ?>
  <p style="font-size:8.5pt;color:#666">Site précédent : <?= $e($d['ancien_site']) ?></p>
  <?php endif; ?>
  <p style="font-size:8.5pt">Cette modification est effectuée dans le cadre du pouvoir de direction de l'Employeur (Art L1121-1 CT), le salarié restant rattaché au même établissement. Le salarié reconnaît que cette affectation entre dans les limites de sa zone géographique habituelle de travail.</p>
</div>
<?php endif; ?>

<?php if (in_array('salaire', $types) && !empty($d['nouveau_salaire'])): ?>
<div class="mod-block">
  <div class="mod-block-title">Modification de la rémunération</div>
  <p style="font-size:9pt">À compter du <strong><?= $e($d['date_effet'] ?? $d['date_signature']) ?></strong>, le salaire horaire <?= $e(strtolower($d['type_remuneration']??'brut')) ?> du salarié est fixé à <strong class="highlight"><?= $e($d['nouveau_salaire']) ?> € / heure</strong>.</p>
  <?php if (!empty($d['ancien_salaire'])): ?>
  <p style="font-size:8.5pt;color:#666">Salaire précédent : <?= $e($d['ancien_salaire']) ?> € / heure</p>
  <?php endif; ?>
  <p style="font-size:8.5pt">Ce nouveau taux est supérieur au minimum conventionnel applicable à la catégorie du salarié (CCN n°1351). Toutes les autres dispositions relatives à la rémunération (majorations, primes) restent inchangées.</p>
</div>
<?php endif; ?>

<?php if (in_array('prolongation', $types) && !empty($d['nouvelle_date_fin'])): ?>
<div class="mod-block">
  <div class="mod-block-title">Prolongation du contrat à durée déterminée</div>
  <p style="font-size:9pt">Le terme du contrat initialement prévu est reporté au <strong><?= $e($d['nouvelle_date_fin']) ?></strong>.</p>
  <?php if (!empty($d['ancienne_date_fin'])): ?>
  <p style="font-size:8.5pt;color:#666">Terme précédent : <?= $e($d['ancienne_date_fin']) ?></p>
  <?php endif; ?>
  <?php if (!empty($d['total_heures_nouveau'])): ?>
  <p style="font-size:9pt">La durée totale de travail pour l'ensemble de la période est portée à <strong><?= $e($d['total_heures_nouveau']) ?> heures</strong>.</p>
  <?php endif; ?>
  <p style="font-size:8.5pt">Cette prolongation intervient conformément aux dispositions de l'article L1243-13 du Code du travail. La durée totale du contrat, renouvellements inclus, reste dans les limites légales.</p>
</div>
<?php endif; ?>

<?php if (in_array('poste', $types) && !empty($d['nouveau_poste'])): ?>
<div class="mod-block">
  <div class="mod-block-title">Modification du poste et des fonctions</div>
  <p style="font-size:9pt">À compter du <strong><?= $e($d['date_effet'] ?? $d['date_signature']) ?></strong>, le salarié exerce les fonctions de <strong><?= $e($d['nouveau_poste']) ?></strong><?= !empty($d['nouvelle_categorie']) ? ', relevant de la catégorie « '.$e($d['nouvelle_categorie']).' »' : '' ?>.</p>
  <?php if (!empty($d['poste_precedent'])): ?>
  <p style="font-size:8.5pt;color:#666">Poste précédent : <?= $e($d['poste_precedent']) ?></p>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (in_array('horaires', $types) && !empty($d['nouveau_total_heures'])): ?>
<div class="mod-block">
  <div class="mod-block-title">Modification de la durée du travail</div>
  <p style="font-size:9pt">À compter du <strong><?= $e($d['date_effet'] ?? $d['date_signature']) ?></strong>, la durée globale de travail est modifiée et fixée à <strong class="highlight"><?= $e($d['nouveau_total_heures']) ?> heures</strong> pour la durée restante du contrat, réparties selon le planning communiqué.</p>
  <?php if (!empty($d['ancien_total_heures'])): ?>
  <p style="font-size:8.5pt;color:#666">Durée précédente : <?= $e($d['ancien_total_heures']) ?> heures</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (in_array('autre', $types) && !empty($d['contenu_autre'])): ?>
<div class="mod-block">
  <div class="mod-block-title"><?= $e($d['titre_autre'] ?? 'Modification diverse') ?></div>
  <div style="font-size:9pt"><?= nl2br($e($d['contenu_autre'])) ?></div>
</div>
<?php endif; ?>

<div class="inchange">
  Toutes les autres clauses et conditions du contrat de travail initial demeurent inchangées et continuent à produire leurs effets.
</div>

<div style="margin:20px 0;font-size:8.5pt">
  Fait à <strong><?= $e($d['lieu_signature'] ?? ($p['entreprise_ville']??'Paris')) ?></strong>, le <strong><?= $e($d['date_signature']) ?></strong>
  &nbsp;&nbsp;(En deux exemplaires originaux dont un remis au salarié)<br>
  <em>Signature précédée de la mention manuscrite « Lu et Approuvé - Bon pour accord »</em>
</div>

<div class="signatures">
  <div class="sig-block <?= $sigPresB64 ? 'has-sig' : '' ?>">
    <div class="sig-title">L'Employeur</div>
    <?php if ($sigPresB64): ?>
    <div class="sig-img-box"><img src="<?= $sigPresB64 ?>"></div>
    <?php else: ?>
    <div style="height:60px"></div>
    <?php endif; ?>
    <div class="sig-line">
      M. <?= $e($p['entreprise_dirigeant'] ?? 'TRAORE Ibrahim') ?><br>
      Président — S.A.S <?= $e($p['entreprise_nom'] ?? 'OEIL VIGILANT') ?>
    </div>
  </div>
  <div class="sig-block">
    <div class="sig-title">Le Salarié</div>
    <div style="height:60px"></div>
    <div class="sig-line">
      <?= $e($d['civilite'] ?? '') ?> <?= $e($d['nom_prenom']) ?>
    </div>
  </div>
</div>

<div class="footer">
  Avenant n°<?= $e($numAv) ?> — Fait à <?= $e($d['lieu_signature'] ?? ($p['entreprise_ville']??'Paris')) ?>, le <?= $e($d['date_signature'] ?: date('d/m/Y')) ?> — <?= $e($p['entreprise_nom'] ?? 'OEIL VIGILANT') ?> — Confidentiel
</div>

</div>
</body>
</html>
<?php
    return ob_get_clean();
}
