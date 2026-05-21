<?php
if (!defined('APP_ROOT')) exit;

/**
 * Calcule la période d'essai CDD : 1 jour par semaine complète de contrat.
 * - Moins de 7 jours : 0 jour
 * - 1 semaine = 1 jour travaillé, 2 semaines = 2 jours, etc.
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

function buildContratHtml(array $d, array $p, array $a): string {
    $logoB64 = '';
    $logoFile = APP_ROOT . '/assets/img/' . ($p['logo_principal'] ?? 'logo.png');
    if (file_exists($logoFile)) {
        $ext  = strtolower(pathinfo($logoFile, PATHINFO_EXTENSION));
        $mime = ($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : 'image/png';
        $logoB64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logoFile));
    }

    $e        = fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
    $typeCdd  = in_array($d['type_contrat'] ?? 'CDD', ['CDD','CDD Usage','Saisonnier']);
    $totalH   = trim($d['total_heures_contrat'] ?? '');
    $calcEssai = calculerPeriodeEssai($d['date_debut'] ?? '', $d['date_fin'] ?? '');

    ob_start(); ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
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
.partie { margin: 10px 0; }
.partie .label { font-weight: bold; }
.art { margin: 14px 0; }
.art-title { font-size: 10pt; font-weight: bold; color: #1a2332; background: #f0f2f5; padding: 5px 10px; border-left: 4px solid #c9a84c; margin-bottom: 6px; }
.art-body { padding: 0 10px; font-size: 9pt; }
.art-body ul { margin: 4px 0; padding-left: 18px; }
.art-body ul li { margin-bottom: 2px; }
.blank { display: inline-block; min-width: 80px; border-bottom: 1px solid #333; }
.blank-long { display: inline-block; min-width: 200px; border-bottom: 1px solid #333; }
.signatures { margin-top: 25px; display: flex; justify-content: space-between; gap: 30px; }
.sig-block { flex: 1; text-align: center; }
.sig-block .sig-title { font-weight: bold; font-size: 9pt; margin-bottom: 5px; }
.sig-block .sig-line { border-top: 1px solid #333; margin-top: 50px; padding-top: 4px; font-size: 8pt; color: #666; }
.footer { margin-top: 20px; border-top: 1px solid #ddd; padding-top: 6px; font-size: 7pt; color: #999; text-align: center; }
.highlight { background: rgba(201,168,76,0.1); padding: 1px 3px; border-radius: 3px; }
/* Annexes */
.annexe-page { page-break-before: always; padding: 15mm 18mm; max-width: 210mm; margin: 0 auto; }
.annexe-header { text-align: center; border-bottom: 2px solid #c9a84c; padding-bottom: 10px; margin-bottom: 18px; }
.annexe-header h2 { font-size: 11pt; color: #1a2332; text-transform: uppercase; letter-spacing: 1px; margin: 0 0 4px; }
.annexe-header .sous { font-size: 8.5pt; color: #666; }
.annexe-body { font-size: 9pt; line-height: 1.6; }
.annexe-body p { margin: 8px 0; }
.check-row { margin: 8px 0 8px 10px; display: table; width: 100%; font-size: 9pt; }
.check-box { display: table-cell; width: 16px; vertical-align: top; padding-top: 1px; }
.check-box-inner { display: inline-block; width: 11px; height: 11px; border: 1.5px solid #333; background: #fff; }
.check-label { display: table-cell; padding-left: 6px; vertical-align: top; }
.annexe-sig { margin-top: 28px; }
.annexe-sig .lieu { font-size: 9pt; margin-bottom: 30px; }
.annexe-sig .sig-line { border-top: 1px solid #333; padding-top: 4px; font-size: 8pt; color: #666; width: 50%; }
</style>
</head>
<body>
<div class="page">

<!-- En-tête -->
<div class="header">
  <?php if ($logoB64): ?>
  <img src="<?= $logoB64 ?>"><br>
  <?php endif; ?>
  <h1>Contrat de Travail à Durée <?= $typeCdd ? 'Déterminée' : 'Indéterminée' ?></h1>
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
  <strong><?= $e($d['civilite']) ?> : <?= $e($d['nom_prenom']) ?></strong>,
  demeurant à : <?= $e($d['adresse']) ?>,<br>
  Né(e) le : <strong><?= $e($d['date_naissance']) ?></strong> à <strong><?= $e($d['lieu_naissance']) ?></strong>,<br>
  de nationalité : <strong><?= $e($d['nationalite']) ?></strong>,<br>
  Numéro de sécurité sociale : <strong><?= $e($d['num_secu']) ?></strong>,<br>
  Titulaire d'une carte professionnelle n° : <strong><?= $e($d['num_cnaps']) ?></strong>,<br>
  Ci-après dénommé <em>« le Salarié »</em>.
</div>

<p style="font-size:8.5pt;color:#555;margin:8px 0">
Conformément aux dispositions de la loi n° 078-17 du 06 janvier 1978, relative à l'informatique, le salarié signataire dispose d'un droit d'accès et de rectification quant aux données enregistrées dans le fichier informatisé de l'organisme social.
</p>
<p style="text-align:center;font-weight:bold;font-size:9.5pt;margin:12px 0">Il a été convenu ce qui suit :</p>

<!-- Article 1 -->
<div class="art">
  <div class="art-title">ARTICLE N° 01 — Engagement</div>
  <div class="art-body">
    Le salarié signataire est engagé sous contrat à durée <?= $typeCdd?'déterminée':'indéterminée' ?> en qualité
    de <strong><?= $e($d['poste']) ?></strong> qui relève de la catégorie « <?= $e($d['categorie']) ?> ».<br>
    La déclaration nominative préalable à l'embauche a été remise à l'URSSAF d'Île-de-France auprès de laquelle la S.A.S <?= $e($p['entreprise_nom']??'OEIL VIGILANT') ?> est immatriculée.<br>
    À la date de sa signature, le présent contrat est régi par les dispositions de la Convention Collective Nationale <em>« Des entreprises de prévention et de sécurité du 15 février 1985 »</em> (IDCC n°1351).
  </div>
</div>

<!-- Article 2 — Durée et période d'essai (dynamique) -->
<div class="art">
  <div class="art-title">ARTICLE N° 02 — Objet du contrat — Durée et terme</div>
  <div class="art-body">
    <?php if ($typeCdd): ?>
    Le présent contrat est conclu pour une durée déterminée du <strong><?= $e($d['date_debut']) ?></strong>
    au <strong><?= $e($d['date_fin']) ?></strong>
    <?php if ($totalH): ?>
    pour une durée de travail globale de <strong class="highlight"><?= $e($totalH) ?> heures</strong> pour l'ensemble de la période, réparties selon le planning.
    <?php else: ?>
    .<br>
    <?php endif; ?>
    <br>
    Ce contrat est conclu pour faire face à un <strong><?= $e($d['motif_cdd']) ?></strong>.
    <?= $e($d['description_motif']) ?><br><br>
    Il ne deviendra définitif qu'à l'issue d'une période d'essai de <strong><?= $e($calcEssai) ?></strong>, durant laquelle chacune des parties pourra y mettre fin sans préavis.<br>
    <?php if (($d['non_renouvelable'] ?? '1') === '1'): ?>
    Le présent contrat n'est pas renouvelable, sauf accord écrit des deux parties, dans la limite autorisée par la législation en vigueur.
    <?php else: ?>
    Le présent contrat pourra être renouvelé une fois par accord écrit des deux parties.
    <?php endif; ?>
    <?php else: ?>
    Le présent contrat est conclu à durée indéterminée à compter du <strong><?= $e($d['date_debut']) ?></strong>.<br>
    Il ne deviendra définitif qu'à l'issue d'une période d'essai de <strong><?= $e($calcEssai) ?></strong>.
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
    <strong>Usage du téléphone portable :</strong> Sauf circonstances exceptionnelles revêtant un caractère d'urgence, l'usage du téléphone portable personnel pendant les heures de travail est formellement interdit compte tenu de la nature des missions.<br><br>
    <strong>Stupéfiants et alcool :</strong> Compte tenu des fonctions exercées, il est formellement interdit d'introduire ou de consommer toute boisson alcoolisée ou tout stupéfiant dans le cadre des fonctions exercées.
  </div>
</div>

<!-- Article 4 — Horaires et heures complémentaires (mis à jour) -->
<div class="art">
  <div class="art-title">ARTICLE N° 04 — Horaires de travail</div>
  <div class="art-body">
    <?php if ($totalH): ?>
    La durée globale de travail est fixée à <strong class="highlight"><?= $e($totalH) ?> heures</strong> pour la durée du contrat.
    <?php endif; ?>
    Les horaires de travail seront définis selon le planning communiqué au salarié. Le salarié s'engage à respecter scrupuleusement les vacations prévues.<br><br>
    En fonction des nécessités du service, le salarié pourra être amené à effectuer des heures complémentaires. Le volume total de ces heures complémentaires ne pourra en aucun cas excéder le <strong>tiers (1/3)</strong> de la durée globale fixée au présent contrat.<br><br>
    L'Employeur s'engage à respecter un délai de prévenance de <strong>7 jours</strong> pour toute modification du planning. En cas de circonstances exceptionnelles (remplacement d'un salarié défaillant, urgence client), ce délai pourra être réduit à moins de 3 jours ouvrés, en contrepartie d'un <strong>repos compensateur équivalent à 10%</strong> des heures effectuées sur la vacation modifiée.<br><br>
    L'amplitude horaire sur laquelle le salarié est susceptible de travailler est comprise entre 00h00 et 23h59.
  </div>
</div>

<!-- Article 5 -->
<div class="art">
  <div class="art-title">ARTICLE N° 05 — Rémunération</div>
  <div class="art-body">
    Le salarié signataire percevra un salaire <?= $e(strtolower($d['type_remuneration']??'brut')) ?> horaire de
    <strong class="highlight"><?= $e($d['salaire_horaire']) ?> €</strong> par heure effective de travail.<br>
    Une majoration concernant les heures de nuit de <strong><?= $e($d['majoration_nuit']) ?>%</strong>,
    dimanche de <strong><?= $e($d['majoration_dim']) ?>%</strong>
    et jours fériés de <strong><?= $e($d['majoration_ferie']) ?>%</strong>.<br>
    <?php if ($typeCdd): ?>
    Une prime de précarité de 10% du salaire brut sera versée en fin de contrat, conformément aux dispositions légales.
    <?php endif; ?>
  </div>
</div>

<div class="art">
  <div class="art-title">ARTICLE N° 06 — Confidentialité</div>
  <div class="art-body">
    Le salarié s'engage à observer la discrétion la plus stricte sur les informations se rapportant aux activités de la S.A.S <?= $e($p['entreprise_nom']??'OEIL VIGILANT') ?> auxquelles il aura accès dans le cadre de ses fonctions. Cette obligation s'applique pendant toute la durée du contrat et se prolonge après la rupture de celui-ci.
  </div>
</div>

<div class="art">
  <div class="art-title">ARTICLE N° 07 — Port de l'uniforme et carte professionnelle</div>
  <div class="art-body">
    Dans l'exercice de ses fonctions, le salarié devra : toujours être en possession de sa carte professionnelle, porter obligatoirement l'uniforme pendant toute la durée du service, et restituer l'uniforme et la carte professionnelle au terme du contrat.
  </div>
</div>

<div class="art">
  <div class="art-title">ARTICLE N° 08 — Absences</div>
  <div class="art-body">
    En cas d'absence, le salarié est tenu de prévenir immédiatement la S.A.S <?= $e($p['entreprise_nom']??'OEIL VIGILANT') ?> et devra transmettre dans un délai de 48 heures un justificatif. En cas d'arrêt maladie, un certificat médical devra être transmis dans les 48 heures.
  </div>
</div>

<div class="art">
  <div class="art-title">ARTICLE N° 09 — Traitement des données personnelles (RGPD)</div>
  <div class="art-body">
    Les données personnelles collectées sont strictement nécessaires à la gestion du contrat de travail. Elles sont traitées dans le respect du RGPD. Le salarié peut exercer ses droits (accès, rectification, opposition, effacement) auprès du référent RGPD.
  </div>
</div>

<div class="art">
  <div class="art-title">ARTICLE N° 10 — Dispositions diverses</div>
  <div class="art-body">
    Le salarié s'engage à aviser la société de tout changement dans sa situation personnelle. Il sera inscrit au registre unique du personnel dès le jour de son embauche.
  </div>
</div>

<div class="art">
  <div class="art-title">ARTICLE N° 11 — Déclaration sur l'honneur</div>
  <div class="art-body">
    Conformément aux dispositions des articles 6 et 18 de la loi 83-629 du 12 juillet 1983, le salarié signataire déclare sur l'honneur ne pas avoir fait l'objet d'une condamnation non amnistiée et n'être l'objet d'aucune poursuite pénale en cours.
  </div>
</div>

<div class="art">
  <div class="art-title">ARTICLE N° 12 — Acceptation des lettres recommandées électroniques</div>
  <div class="art-body">
    Le Salarié accepte l'envoi par voie électronique des courriers recommandés de l'Entreprise relatifs à son Contrat.
  </div>
</div>

<div class="art">
  <div class="art-title">ARTICLE N° 13 — Droit à l'image</div>
  <div class="art-body">
    Le salarié accepte et donne son accord à la Société pour capter et diffuser son image dans un cadre professionnel, pour les supports de communication de l'entreprise.
  </div>
</div>

<?php if ($typeCdd): ?>
<div class="art">
  <div class="art-title">ARTICLE N° 14 — Fin du contrat</div>
  <div class="art-body">
    Le contrat prendra fin automatiquement à la dernière vacation prévue au planning, sauf rupture anticipée pour faute grave, cas de force majeure ou accord des parties. Il donne lieu au versement d'une indemnité de fin de contrat égale à 10% de la rémunération brute totale.
  </div>
</div>
<?php endif; ?>

<div class="art">
  <div class="art-title">ARTICLE N° 15 — Mutuelle</div>
  <div class="art-body">
    Conformément à la réglementation, le salarié bénéficie de la couverture santé collective obligatoire, sauf s'il justifie d'un droit à dispense.
  </div>
</div>

<!-- Article 16 — Documents annexes obligatoires -->
<div class="art">
  <div class="art-title">ARTICLE N° 16 — Documents annexes obligatoires</div>
  <div class="art-body">
    À la date de signature du présent contrat, le salarié valide et signe électroniquement les documents annexés à la suite de ce contrat, sans lesquels celui-ci ne peut produire ses effets :
    <ul>
      <li>Une demande expresse de dérogation à la durée minimale légale de travail de 24 heures par semaine.</li>
      <li>Une attestation sur l'honneur de cumul d'emplois.</li>
      <li>Sa demande de dispense d'adhésion à la mutuelle d'entreprise.</li>
    </ul>
  </div>
</div>

<!-- Badge -->
<div style="margin:14px 0;padding:10px;border:1px solid #e5e7eb;border-radius:6px;font-size:8.5pt">
  <strong>Badge professionnel :</strong> J'atteste sur l'honneur avoir reçu mon badge professionnel et mes équipements, je m'engage à les remettre à l'employeur en fin de mission sous peine de poursuites disciplinaires et pénales.
</div>

<!-- Signatures contrat principal -->
<div style="margin:16px 0;font-size:8.5pt">
  Fait à <strong><?= $e($d['lieu_signature']) ?></strong>, le <strong><?= $e($d['date_signature']) ?></strong>
  &nbsp;&nbsp;(En deux exemplaires dont l'un a été remis au salarié signataire)<br>
  <em>Signature précédée de la mention manuscrite « Lu et Approuvé - Bon pour accord »</em>
</div>

<div class="signatures">
  <div class="sig-block">
    <div class="sig-title">L'Employeur</div>
    <div class="sig-line">
      M. <?= $e($p['entreprise_dirigeant'] ?? 'TRAORE Ibrahim') ?><br>
      Président — S.A.S <?= $e($p['entreprise_nom'] ?? 'OEIL VIGILANT') ?>
    </div>
  </div>
  <div class="sig-block">
    <div class="sig-title">Le Salarié</div>
    <div class="sig-line">
      <?= $e($d['civilite']) ?> <?= $e($d['nom_prenom']) ?>
    </div>
  </div>
</div>

<div class="footer">
  Document généré le <?= date('d/m/Y à H:i') ?> — <?= $e($p['entreprise_nom'] ?? 'OEIL VIGILANT') ?> — Usage interne confidentiel
</div>

</div><!-- /page -->

<!-- ═══════════════════════════════════════════════════════════════════════════
     ANNEXE 1 — Dérogation durée minimale de travail
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="annexe-page">
  <div class="annexe-header">
    <div class="sous"><?= $e($p['entreprise_nom'] ?? 'OEIL VIGILANT') ?> &nbsp;·&nbsp; SIREN 928 552 702</div>
    <h2>Annexe 1 — Demande de dérogation à la durée minimale de travail</h2>
    <div class="sous">Annexe obligatoire au contrat de travail signé le <?= $e($d['date_signature']) ?></div>
  </div>
  <div class="annexe-body">
    <p>Je soussigné(e) <strong><?= $e($d['nom_prenom']) ?></strong>, demeurant au : <strong><?= $e($d['adresse']) ?></strong>,</p>
    <p>demande expressément et formellement à la société <strong>OEIL VIGILANT</strong> de déroger à la durée minimale légale de travail de <strong>24 heures par semaine</strong> (Article L3123-7 du Code du travail).</p>
    <p><strong>Cette demande est justifiée par la raison suivante :</strong></p>
    <div class="check-row">
      <span class="check-box"><span class="check-box-inner"></span></span>
      <span class="check-label">Me permettre de faire face à des contraintes personnelles ou de cumuler plusieurs activités afin d'atteindre une durée globale de travail correspondant à un temps plein ou au moins égale à 24 heures par semaine.</span>
    </div>
    <p>J'ai bien noté que mes horaires de travail seront regroupés sur des journées ou des demi-journées régulières ou complètes.</p>
    <div class="annexe-sig">
      <div class="lieu">Fait à Paris, le <?= $e($d['date_signature']) ?></div>
      <div class="sig-line"><?= $e($d['civilite']) ?> <?= $e($d['nom_prenom']) ?><br><span style="font-style:italic;font-size:7.5pt">Lu et approuvé — Signature</span></div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     ANNEXE 2 — Déclaration sur l'honneur de cumul d'emplois
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="annexe-page">
  <div class="annexe-header">
    <div class="sous"><?= $e($p['entreprise_nom'] ?? 'OEIL VIGILANT') ?> &nbsp;·&nbsp; SIREN 928 552 702</div>
    <h2>Annexe 2 — Déclaration sur l'honneur de cumul d'emplois</h2>
    <div class="sous">Annexe obligatoire au contrat de travail signé le <?= $e($d['date_signature']) ?></div>
  </div>
  <div class="annexe-body">
    <p>Je soussigné(e) <strong><?= $e($d['nom_prenom']) ?></strong>, déclare sur l'honneur être informé(e) que la réglementation m'interdit de dépasser la durée maximale de travail autorisée, tous emplois confondus (10h/jour, 48h/semaine, 44h en moyenne sur 12 semaines).</p>
    <p><strong>À ce jour, je déclare :</strong></p>
    <div class="check-row">
      <span class="check-box"><span class="check-box-inner"></span></span>
      <span class="check-label">N'exercer aucune autre activité professionnelle rémunérée.</span>
    </div>
    <div class="check-row">
      <span class="check-box"><span class="check-box-inner"></span></span>
      <span class="check-label">Exercer une ou plusieurs autres activités professionnelles rémunérées. Je m'engage à ce que le cumul de mes heures chez OEIL VIGILANT et ailleurs ne dépasse jamais les limites légales.</span>
    </div>
    <div class="annexe-sig">
      <div class="lieu">Fait à Paris, le <?= $e($d['date_signature']) ?></div>
      <div class="sig-line"><?= $e($d['civilite']) ?> <?= $e($d['nom_prenom']) ?><br><span style="font-style:italic;font-size:7.5pt">Lu et approuvé — Signature</span></div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     ANNEXE 3 — Demande de dispense d'affiliation à la mutuelle
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="annexe-page">
  <div class="annexe-header">
    <div class="sous"><?= $e($p['entreprise_nom'] ?? 'OEIL VIGILANT') ?> &nbsp;·&nbsp; SIREN 928 552 702</div>
    <h2>Annexe 3 — Demande de dispense d'affiliation à la mutuelle</h2>
    <div class="sous">Annexe obligatoire au contrat de travail signé le <?= $e($d['date_signature']) ?></div>
  </div>
  <div class="annexe-body">
    <p>Je soussigné(e) <strong><?= $e($d['nom_prenom']) ?></strong>, demande à être dispensé(e) d'affiliation au régime de garantie « Frais de Santé » obligatoire mis en place par <strong>OEIL VIGILANT</strong>, pour le motif suivant :</p>
    <div class="check-row">
      <span class="check-box"><span class="check-box-inner"></span></span>
      <span class="check-label">Je suis titulaire d'un CDD très court et je justifie d'une couverture santé solidaire (CSS) ou d'un contrat individuel.</span>
    </div>
    <div class="check-row">
      <span class="check-box"><span class="check-box-inner"></span></span>
      <span class="check-label">Je bénéficie déjà d'une couverture collective et obligatoire par ailleurs (ex : autre employeur).</span>
    </div>
    <p>Je m'engage à fournir les justificatifs correspondants et note qu'en refusant l'adhésion, je ne pourrai pas prétendre aux remboursements de frais de santé par l'entreprise.</p>
    <div class="annexe-sig">
      <div class="lieu">Fait à Paris, le <?= $e($d['date_signature']) ?></div>
      <div class="sig-line"><?= $e($d['civilite']) ?> <?= $e($d['nom_prenom']) ?><br><span style="font-style:italic;font-size:7.5pt">Lu et approuvé — Signature</span></div>
    </div>
  </div>
</div>

</body>
</html>
<?php
    return ob_get_clean();
}
