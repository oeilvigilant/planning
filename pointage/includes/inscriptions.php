<?php
/**
 * Liens d'inscription : le responsable crée un lien (général ou pour un site),
 * l'agent l'ouvre, saisit son identité et choisit son PIN. Selon le lien, le
 * compte est actif tout de suite ou attend la validation du responsable.
 */

/** Lien par son jeton (32 caractères hexadécimaux), avec le nom du site. */
function ptInscriptionParToken(string $token): ?array {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    $st = getDB()->prepare("SELECT i.*, m.nom AS site_nom, m.actif AS site_actif FROM pt_inscriptions i
        LEFT JOIN missions m ON m.id = i.mission_id WHERE i.token = ?");
    $st->execute([$token]);
    return $st->fetch() ?: null;
}

/** '' si le lien est utilisable, sinon la raison à afficher. */
function ptInscriptionRefus(?array $i): string {
    if (!$i || !(int)$i['actif']) return 'Ce lien d\'inscription n\'est pas valide ou a été désactivé.';
    if ($i['expire_le'] && strtotime($i['expire_le']) < time()) return 'Ce lien d\'inscription a expiré.';
    if ($i['max_util'] !== null && (int)$i['nb_util'] >= (int)$i['max_util']) return 'Ce lien a atteint son nombre maximum d\'inscriptions.';
    if ($i['mission_id'] && !(int)$i['site_actif']) return 'Le site de ce lien n\'est plus actif.';
    return '';
}

function ptUrlInscription(array $i): string {
    return ptUrl('inscription.php?i=' . $i['token']);
}

/** Un agent (actif ou en attente) utilise-t-il déjà ce numéro ? */
function ptTelephoneDejaUtilise(string $tel): bool {
    $cherche = ptNormaliserTel($tel);
    $st = getDB()->query("SELECT telephone FROM agents WHERE telephone IS NOT NULL AND telephone <> ''
        AND (actif = 1 OR statut_inscription = 'en_attente')");
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if (ptNormaliserTel((string)$t) === $cherche) return true;
    }
    return false;
}

/**
 * Vérifie le formulaire et crée l'agent. $d : prenom, nom, sexe, telephone, email, pin, pin2, rgpd.
 * Retourne ['ok'=>true, 'agent_id', 'matricule', 'attente'] ou ['ok'=>false, 'errors'=>[...]].
 */
function ptInscrireAgent(array $i, array $d): array {
    $prenom = trim(preg_replace('/\s+/u', ' ', (string)($d['prenom'] ?? '')));
    $nom    = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', (string)($d['nom'] ?? ''))));
    $sexe   = ($d['sexe'] ?? '') === 'F' ? 'F' : 'M';
    $tel    = trim((string)($d['telephone'] ?? ''));
    $email  = trim((string)($d['email'] ?? ''));
    $pin    = (string)($d['pin'] ?? '');
    $pin2   = (string)($d['pin2'] ?? '');

    $errors = [];
    if ($refus = ptInscriptionRefus($i)) return ['ok' => false, 'errors' => [$refus]];
    if ($prenom === '' || mb_strlen($prenom) > 100) $errors[] = 'Indiquez votre prénom.';
    if ($nom === '' || mb_strlen($nom) > 100)       $errors[] = 'Indiquez votre nom.';
    $chiffres = ptNormaliserTel($tel);
    if (strlen($chiffres) < 9 || strlen($chiffres) > 15 || strlen($tel) > 20) $errors[] = 'Numéro de téléphone invalide.';
    elseif (ptTelephoneDejaUtilise($tel)) $errors[] = 'Un compte existe déjà avec ce numéro. Connectez-vous, ou demandez à votre responsable de vous renvoyer votre lien.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Adresse e-mail invalide.';
    if (empty($d['rgpd'])) $errors[] = 'Merci de confirmer avoir lu l\'information sur vos données.';
    if (!preg_match('/^\d{4}$/', $pin))  $errors[] = 'Le code PIN doit contenir 4 chiffres.';
    elseif (ptPinTropSimple($pin))       $errors[] = 'Ce code PIN est trop simple. Choisissez-en un autre.';
    elseif ($pin !== $pin2)              $errors[] = 'Les deux codes PIN ne correspondent pas.';
    if ($errors) return ['ok' => false, 'errors' => $errors];

    $db = getDB();
    $attente = (int)$i['validation'] === 1;
    $version = ptRgpdVersion();
    $propre = !$db->inTransaction();   // transaction de l'appelant éventuelle (tests)
    if ($propre) $db->beginTransaction();
    try {
        // verrou sur le lien : compteur fiable même si plusieurs agents s'inscrivent en même temps
        $st = $db->prepare("SELECT nb_util, max_util FROM pt_inscriptions WHERE id = ? FOR UPDATE");
        $st->execute([$i['id']]);
        $l = $st->fetch();
        if ($l['max_util'] !== null && (int)$l['nb_util'] >= (int)$l['max_util']) {
            if ($propre) $db->rollBack();
            return ['ok' => false, 'errors' => ['Ce lien a atteint son nombre maximum d\'inscriptions.']];
        }
        $matricule = ptGenererMatricule($sexe);
        $db->prepare("INSERT INTO agents (nom, prenom, sexe, telephone, email, matricule, actif, statut_inscription, pt_inscription_id)
            VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([$nom, $prenom, $sexe, $tel, $email ?: null, $matricule, $attente ? 0 : 1, $attente ? 'en_attente' : 'validee', $i['id']]);
        $agentId = (int)$db->lastInsertId();
        if ($i['mission_id']) {
            $db->prepare("INSERT IGNORE INTO mission_agents (mission_id, agent_id) VALUES (?, ?)")->execute([$i['mission_id'], $agentId]);
        }
        $db->prepare("INSERT INTO pt_acces (agent_id, pin_hash, active_le, rgpd_version, rgpd_accepte_le) VALUES (?,?,NOW(),?,NOW())")
           ->execute([$agentId, password_hash($pin, PASSWORD_DEFAULT), $version]);
        $db->prepare("INSERT INTO pt_rgpd_log (agent_id, version, accepte_le, ip, user_agent) VALUES (?,?,NOW(),?,?)")
           ->execute([$agentId, $version, ptClientIp(), ptUserAgent()]);
        $db->prepare("UPDATE pt_inscriptions SET nb_util = nb_util + 1 WHERE id = ?")->execute([$i['id']]);
        if ($propre) $db->commit();
    } catch (Throwable $e) {
        if ($propre && $db->inTransaction()) $db->rollBack();
        error_log('OV Pointage — inscription : ' . $e->getMessage());
        return ['ok' => false, 'errors' => ['Erreur serveur, réessayez dans un instant.']];
    }

    try {
        ptNotifierResponsables($attente ? 'Inscription à valider' : 'Nouvel agent inscrit',
            $prenom . ' ' . $nom . ' s\'est inscrit' . ($i['site_nom'] ? ' (site ' . $i['site_nom'] . ')' : '') . ' via « ' . $i['libelle'] . ' ».'
            . ($attente ? ' Validez son compte.' : ''), 'admin/inscriptions.php', false, $attente);
    } catch (Throwable $e) { error_log('OV Pointage — notif inscription : ' . $e->getMessage()); }

    return ['ok' => true, 'agent_id' => $agentId, 'matricule' => $matricule, 'attente' => $attente];
}

/** Agents inscrits par lien qui attendent la validation. */
function ptInscriptionsEnAttente(): array {
    return getDB()->query("SELECT a.id, a.nom, a.prenom, a.sexe, a.telephone, a.email, a.matricule, a.created_at,
            i.libelle AS lien, m.nom AS site_nom
        FROM agents a LEFT JOIN pt_inscriptions i ON i.id = a.pt_inscription_id LEFT JOIN missions m ON m.id = i.mission_id
        WHERE a.statut_inscription = 'en_attente' AND a.actif = 0 ORDER BY a.created_at")->fetchAll();
}
